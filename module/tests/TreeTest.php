<?php

declare(strict_types=1);

namespace Engelking\Webtrees\PortalApi\Tests;

use Engelking\Webtrees\PortalApi\Http\RequestHandlers\AncestorsRead;
use Engelking\Webtrees\PortalApi\Http\RequestHandlers\IndividualRead;
use Engelking\Webtrees\PortalApi\Http\RequestHandlers\MemberAncestorsRead;
use Engelking\Webtrees\PortalApi\Http\RequestHandlers\MemberRead;
use Fisharebest\Webtrees\Auth;
use Fisharebest\Webtrees\Contracts\UserInterface;
use Fisharebest\Webtrees\DB;
use Fisharebest\Webtrees\User;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\RunInSeparateProcess;

/**
 * Phase 3: walking the tree inside the portal.
 *
 * The fixture's ancestry above Anna (X1):
 *
 *   1 Anna
 *   ├─ 2 Emil ────── 4 Gustav ── 8 Ludwig
 *   │                5 Helene
 *   └─ 3 Bertha ──── 6 Konrad
 *                    7 Ida       <- "1 RESN confidential"
 *
 * Ida is the reason this file exists: everything here is about what happens
 * at the edge of what a member may see.
 *
 * Since §2.75 that edge is no longer a wall. A rung the member may not read
 * comes back as a placeholder — a position and nothing else — and the walk
 * carries on above it, which is how Otto (X12) is reachable at all. What these
 * tests pin is that the placeholder really is empty, that no reason for it is
 * disclosed, and that the one thing which can be attached to it is the
 * person's own decision to be listed in the member directory.
 */
#[CoversNothing]
class TreeTest extends PortalTestCase
{
    private function signInAsAnna(): User
    {
        $anna = $this->createUser('anna', 'Anna Beispiel', 'geheim', UserInterface::ROLE_MEMBER, 'X1');

        $this->login($anna);

        return $anna;
    }

    /**
     * Anna, with the archive's living-person rule actually biting.
     *
     * Two settings, because the fixture has no living ancestors and cannot
     * grow one: everybody above Anna died before 1980, and a person's dates
     * are what makes them ancestors of somebody born in 1985.
     *
     * `KEEP_ALIVE_YEARS_DEATH` is webtrees' own answer to that — it goes on
     * treating somebody as living for N years after their death — and a
     * relationship path length is the limit this module's Phase 8 puts on
     * member accounts. Together they produce exactly the shape a real
     * installation has: parents visible, everyone further up not.
     *
     * **Every test that calls this needs `#[RunInSeparateProcess]`.**
     * `Individual::isRelated()` keeps its walk in a function-level `static`
     * that is keyed by neither user nor tree, and compares with `in_array(…,
     * true)` — so a second test in the same process is answered from the
     * first one's cache, against object identities that no longer exist, and
     * everybody comes out unrelated. `VisibilityTest` hit this first and says
     * the same thing.
     */
    private function signInAsAnnaWithALimit(): User
    {
        $anna = $this->createUser('anna', 'Anna Beispiel', 'geheim', UserInterface::ROLE_MEMBER, 'X1');

        $this->tree->setPreference('KEEP_ALIVE_YEARS_DEATH', '200');
        $this->tree->setUserPreference($anna, UserInterface::PREF_TREE_PATH_LENGTH, '1');

        $this->login($anna);

        return $anna;
    }

    /**
     * Put a restriction notice on a family record.
     *
     * Written into the row rather than through an editing screen, because what
     * is under test is how the walk reads a `RESN` and not how one gets there.
     * Call it before signing in: `login()` installs a fresh cache, which is
     * what makes the edited record the one the request reads.
     */
    private function restrictFamily(string $xref, string $restriction): void
    {
        $gedcom = (string) DB::table('families')
            ->where('f_id', '=', $xref)
            ->where('f_file', '=', $this->tree->id())
            ->value('f_gedcom');

        self::assertNotSame('', $gedcom, 'No such family in the fixture: ' . $xref);

        DB::table('families')
            ->where('f_id', '=', $xref)
            ->where('f_file', '=', $this->tree->id())
            ->update(['f_gedcom' => str_replace(
                '0 @' . $xref . '@ FAM' . "\n",
                '0 @' . $xref . '@ FAM' . "\n" . '1 RESN ' . $restriction . "\n",
                $gedcom
            )]);
    }

    /**
     * @param array<int,array<string,mixed>> $people
     *
     * @return array<string,mixed>
     */
    private function at(array $people, int $position): array
    {
        foreach ($people as $person) {
            if ($person['position'] === $position) {
                return $person;
            }
        }

        self::fail('No rung at position ' . $position . '.');
    }

    /**
     * @return array<int,array<string,mixed>>
     */
    private function ancestors(string $xref, int|null $generations = null): array
    {
        $query = $generations === null ? [] : ['generations' => (string) $generations];

        $response = $this->api(AncestorsRead::class, query: $query, attributes: ['xref' => $xref]);

        self::assertSame(200, $response->getStatusCode());

        return $this->json($response)['people'];
    }

    /**
     * @param array<int,array<string,mixed>> $people
     *
     * @return array<int,string>
     */
    private function byPosition(array $people): array
    {
        $names = [];

        foreach ($people as $person) {
            $names[$person['position']] = $person['name'] ?? null;
        }

        return $names;
    }

    // -----------------------------------------------------------------
    // The pedigree
    // -----------------------------------------------------------------

    public function testAncestorsComeBackInAhnentafelOrder(): void
    {
        $this->signInAsAnna();

        $names = $this->byPosition($this->ancestors('X1'));

        self::assertSame('Anna Beispiel', $names[1]);
        self::assertSame('Emil Beispiel', $names[2]);
        self::assertSame('Bertha "Betty" Beispiel', $names[3]);
        self::assertSame('Gustav Beispiel', $names[4]);
        self::assertSame('Helene Beispiel', $names[5]);
        self::assertSame('Konrad Beispiel', $names[6]);
        self::assertSame('Ludwig Beispiel', $names[8]);
    }

    /**
     * The one that matters. A rung the member may not read is a position and
     * nothing else: no name, no dates, no picture, no archive number, and no
     * XREF to ask this API anything further with.
     */
    public function testAHiddenAncestorIsAPlaceholderAndNothingElse(): void
    {
        $this->signInAsAnna();

        $response = $this->api(AncestorsRead::class, attributes: ['xref' => 'X1']);
        $ida      = $this->at($this->json($response)['people'], 7);

        self::assertTrue($ida['private']);
        self::assertSame(['position', 'generation', 'private', 'member'], array_keys($ida));
        self::assertNull($ida['member']);

        // Nothing of the record travelled, by name or by number.
        self::assertStringNotContainsString('Ida', $this->raw($response));
        self::assertStringNotContainsString('X9', $this->raw($response));
        self::assertStringNotContainsString('1860', $this->raw($response));
    }

    /**
     * And the line carries on above it, which is the whole point of §2.75.
     *
     * Otto (X12) is Ida's father. He died in 1899, carries no restriction of
     * his own, and used to be unreachable from here for no better reason than
     * that his daughter's record is shut. The archive's dead are what a family
     * portal is for; they are not the confidential thing.
     */
    public function testTheWalkCarriesOnAboveAHiddenAncestor(): void
    {
        $this->signInAsAnna();

        $names = $this->byPosition($this->ancestors('X1', 6));

        self::assertNull($names[7], 'Ida herself stays shut.');
        self::assertSame('Otto Fernab', $names[14], 'Her father is dead and unrestricted.');
    }

    /**
     * **The regression that shipped.** A `RESN locked` on the parents' family
     * emptied the whole pedigree: nine rungs became one, and the screen said
     * "Keine Vorfahren hinterlegt" about an archive that has the line back to
     * 1780.
     *
     * `locked` forbids editing a record, not reading it, and this archive uses
     * it — Fritz (X6) carries one. The first version of §2.75 refused on any
     * `RESN` at all, copying `RelationshipNamer::families()`, which is right
     * where the cost of being wrong is a disclosure and exactly wrong here,
     * where the cost is the pedigree.
     */
    public function testALockedFamilyDoesNotTruncateThePedigree(): void
    {
        $this->restrictFamily('F1', 'locked');
        $this->signInAsAnna();

        $names = $this->byPosition($this->ancestors('X1'));

        self::assertSame('Emil Beispiel', $names[2], 'A locked family hid the parents.');
        self::assertSame('Gustav Beispiel', $names[4], 'And everybody above them.');
    }

    /**
     * And a family whose connection really is confidential still ends the
     * branch, with no placeholder in the position it names — because the
     * placeholder would say the one thing that was asked to stay quiet.
     */
    public function testAConfidentialFamilyEndsTheBranchForAMember(): void
    {
        $this->restrictFamily('F1', 'confidential');
        $this->signInAsAnna();

        $people = $this->ancestors('X1');

        self::assertCount(1, $people, 'Only the root: the connection itself is confidential.');
        self::assertSame(1, $people[0]['position']);
    }

    /**
     * **The second regression, and the same mistake as the first.** A `RESN` is
     * a level, not a flag. `privacy` means "members may see this"; reading it
     * as "somebody restricted this record" cut the line for every member —
     * that is, for everybody the portal has.
     */
    public function testAPrivacyNoticeOnAFamilyDoesNotEndTheBranchForAMember(): void
    {
        $this->restrictFamily('F1', 'privacy');
        $this->signInAsAnna();

        $names = $this->byPosition($this->ancestors('X1'));

        self::assertSame('Emil Beispiel', $names[2], 'webtrees shows a “privacy” family to members.');
        self::assertSame('Gustav Beispiel', $names[4]);
    }

    /**
     * And `confidential` means "managers may see this" — so a manager keeps
     * the line that a member loses. Two readers, one archive, one rule, taken
     * from `GedcomRecord::canShowRecord()` rather than invented here.
     */
    public function testAManagerKeepsTheBranchAConfidentialFamilyTakesFromAMember(): void
    {
        $this->restrictFamily('F1', 'confidential');

        $this->login($this->createUser('mia', 'Mia Verwalterin', 'geheim', UserInterface::ROLE_MANAGER, 'X1'));

        $names = $this->byPosition($this->ancestors('X1'));

        self::assertSame('Emil Beispiel', $names[2], 'A manager may see past a confidential connection.');
        self::assertSame('Ida Beispiel', $names[7], 'And past a confidential person.');
    }

    /**
     * The case the family actually has: a living person, hidden by the
     * relationship path length rather than by anything on their record.
     *
     * It produces the identical entry to Ida's. That is deliberate — the
     * response says that somebody stands there and never why they are not
     * shown, so "hidden" cannot be read as "alive".
     */
    #[RunInSeparateProcess]
    public function testALivingAncestorOutsideTheMembersReachIsAPlaceholderToo(): void
    {
        $this->signInAsAnnaWithALimit();

        $people = $this->ancestors('X1');

        self::assertFalse($this->at($people, 2)['private'], 'A parent is one step away.');
        self::assertTrue($this->at($people, 4)['private'], 'A grandparent is two.');
        self::assertSame(
            ['position', 'generation', 'private', 'member'],
            array_keys($this->at($people, 4)),
            'A living rung must look exactly like a restricted one.'
        );
        self::assertNull($this->at($people, 4)['member']);

        // And the line still reaches the top of the fixture.
        self::assertTrue($this->at($people, 8)['private']);
    }

    /**
     * The exception, and it is the person's own doing.
     *
     * A member who put themselves in the directory has already agreed that
     * every other member may read that name and open that page. Saying it
     * again on a rung of a pedigree adds where they stand and nothing else:
     * the record stays shut, and the name comes from `portal_member_profile`
     * rather than from the family tree — which is what the deliberately
     * un-genealogical display name here proves.
     */
    #[RunInSeparateProcess]
    public function testAListedMemberIsNamedFromTheDirectoryAndNotFromTheRecord(): void
    {
        $gustav = $this->createUser('gustav', 'Gustav Beispiel', 'geheim', UserInterface::ROLE_MEMBER, 'X7');
        $id     = $this->createProfile($gustav, true, 'Opa im Verzeichnis');

        $this->signInAsAnnaWithALimit();

        $response = $this->api(AncestorsRead::class, attributes: ['xref' => 'X1']);
        $rung     = $this->at($this->json($response)['people'], 4);

        self::assertTrue($rung['private'], 'His record is still shut.');
        self::assertSame(['id' => $id, 'display_name' => 'Opa im Verzeichnis'], $rung['member']);

        // The consent names him; it does not open the record.
        self::assertArrayNotHasKey('xref', $rung);
        self::assertArrayNotHasKey('lifespan', $rung);
        self::assertStringNotContainsString('X7', $this->raw($response));
        self::assertStringNotContainsString('1850', $this->raw($response));
    }

    /**
     * And a switch in the portal does not answer a question the archive asked.
     *
     * `1 RESN confidential` is somebody who keeps the tree saying that *this
     * record* is not to be shown. Ida may list herself in the directory — she
     * is then in the directory, where every member can read her name — but the
     * rung she stands on stays a bare placeholder.
     */
    public function testAnExplicitRestrictionOutranksTheDirectory(): void
    {
        $ida = $this->createUser('ida', 'Ida Beispiel', 'geheim', UserInterface::ROLE_MEMBER, 'X9');
        $this->createProfile($ida, true, 'Ida im Verzeichnis');

        $this->signInAsAnna();

        $response = $this->api(AncestorsRead::class, attributes: ['xref' => 'X1']);

        self::assertNull($this->at($this->json($response)['people'], 7)['member']);
        self::assertStringNotContainsString('Ida im Verzeichnis', $this->raw($response));
    }

    /** A member who stayed out of the directory is not named either. */
    #[RunInSeparateProcess]
    public function testAMemberWhoIsNotListedIsNotNamed(): void
    {
        $gustav = $this->createUser('gustav', 'Gustav Beispiel', 'geheim', UserInterface::ROLE_MEMBER, 'X7');
        $this->createProfile($gustav, false, 'Opa im Verzeichnis');

        $this->signInAsAnnaWithALimit();

        $response = $this->api(AncestorsRead::class, attributes: ['xref' => 'X1']);

        self::assertNull($this->at($this->json($response)['people'], 4)['member']);
        self::assertStringNotContainsString('Opa im Verzeichnis', $this->raw($response));
    }

    public function testTheDepthIsBoundedAndAskable(): void
    {
        $this->signInAsAnna();

        // One generation: the person and their parents, nothing above.
        $names = $this->byPosition($this->ancestors('X1', 1));

        self::assertArrayHasKey(2, $names);
        self::assertArrayNotHasKey(4, $names);

        // A silly number is clamped rather than refused, and says what it did.
        $response = $this->api(AncestorsRead::class, query: ['generations' => '99'], attributes: ['xref' => 'X1']);

        self::assertSame(6, $this->json($response)['generations']);
    }

    public function testAHiddenRootIsANotFound(): void
    {
        $this->signInAsAnna();

        // X3 carries "1 RESN confidential".
        $response = $this->api(AncestorsRead::class, attributes: ['xref' => 'X3']);

        self::assertSame(404, $response->getStatusCode());

        // Byte-identical to a record that does not exist at all.
        $missing = $this->api(AncestorsRead::class, attributes: ['xref' => 'X999']);

        self::assertSame(404, $missing->getStatusCode());
        self::assertSame($this->raw($missing), $this->raw($response));
    }

    public function testAncestorsNeedASession(): void
    {
        self::assertSame(401, $this->api(AncestorsRead::class, attributes: ['xref' => 'X1'])->getStatusCode());
    }

    // -----------------------------------------------------------------
    // The other door: a member whose record is shut (§2.77)
    // -----------------------------------------------------------------

    /**
     * The case this door was cut for.
     *
     * Clara (X3) carries `1 RESN confidential` and is alive. A member looking
     * at her page in Kontakte sees a name, no record — and until §2.77, no way
     * at all into the family above her, though her parents are Emil and
     * Bertha, dead since 1961 and 1976 and restricted by nobody.
     */
    public function testAListedMembersPedigreeOpensThoughHerRecordDoesNot(): void
    {
        $clara = $this->createUser('clara', 'Clara Beispiel', 'geheim', UserInterface::ROLE_MEMBER, 'X3');
        $id    = $this->createProfile($clara, true);

        $this->signInAsAnna();

        $response = $this->api(MemberAncestorsRead::class, attributes: ['id' => $id]);

        self::assertSame(200, $response->getStatusCode());

        $people = $this->json($response)['people'];
        $names  = $this->byPosition($people);

        self::assertTrue($this->at($people, 1)['private'], 'Her own record is still shut.');
        self::assertSame('Emil Beispiel', $names[2]);
        self::assertSame('Bertha "Betty" Beispiel', $names[3]);

        // The record stayed shut, and nothing of it travelled with the answer.
        self::assertStringNotContainsString('X3', $this->raw($response));
        self::assertStringNotContainsString('1987', $this->raw($response));
    }

    /**
     * And the door is the member page's own, not a new one.
     *
     * A member who stayed out of the directory and connected with nobody is a
     * 404 here exactly as they are there — and so is a member the tree has no
     * record for. Which of the two it is, is the sentence §2.66 keeps to
     * itself, so the two refusals are byte-identical.
     */
    public function testTheDoorIsTheMemberPagesOwn(): void
    {
        $unlisted = $this->createUser('ulf', 'Ulf Unsichtbar', 'geheim', UserInterface::ROLE_MEMBER, 'X3');
        $hidden   = $this->createProfile($unlisted, false);

        $recordless = $this->createUser('nora', 'Nora Ohnesatz', 'geheim', UserInterface::ROLE_MEMBER);
        $listed     = $this->createProfile($recordless, true);

        $this->signInAsAnna();

        $a = $this->api(MemberAncestorsRead::class, attributes: ['id' => $hidden]);
        $b = $this->api(MemberAncestorsRead::class, attributes: ['id' => $listed]);

        self::assertSame(404, $a->getStatusCode(), 'Not listed, not connected.');
        self::assertSame(404, $b->getStatusCode(), 'Listed, but the tree has no record for them.');
        self::assertSame($this->raw($a), $this->raw($b));
    }

    /**
     * The member page says whether the door leads anywhere, so that the button
     * is not offered onto an empty room.
     */
    public function testTheMemberPageSaysWhetherThereIsAPedigreeToOpen(): void
    {
        $clara  = $this->createProfile(
            $this->createUser('clara', 'Clara Beispiel', 'geheim', UserInterface::ROLE_MEMBER, 'X3'),
            true
        );
        // Fritz (X6) is in the tree and has no parents recorded at all.
        $fritz  = $this->createProfile(
            $this->createUser('fritz', 'Fritz Beispiel', 'geheim', UserInterface::ROLE_MEMBER, 'X6'),
            true
        );

        $this->signInAsAnna();

        $hers = $this->json($this->api(MemberRead::class, attributes: ['id' => $clara]));
        $his  = $this->json($this->api(MemberRead::class, attributes: ['id' => $fritz]));

        self::assertNull($hers['individual_detail'], 'Her record is confidential.');
        self::assertTrue($hers['ancestors'], 'And her parents are in the archive.');

        self::assertFalse($his['ancestors'], 'Nobody stands above him.');
    }

    public function testAMembersPedigreeNeedsASession(): void
    {
        self::assertSame(401, $this->api(MemberAncestorsRead::class, attributes: ['id' => 1])->getStatusCode());
    }

    // -----------------------------------------------------------------
    // "How am I related to this person?"
    // -----------------------------------------------------------------

    public function testARecordSaysHowTheMemberIsRelatedToIt(): void
    {
        $this->signInAsAnna();

        $mother = $this->json($this->api(IndividualRead::class, attributes: ['xref' => 'X2']));

        self::assertNotNull($mother['relationship']);
        self::assertNotSame('', $mother['relationship']);
    }

    public function testTheMembersOwnRecordHasNoRelationshipToItself(): void
    {
        $this->signInAsAnna();

        $self = $this->json($this->api(IndividualRead::class, attributes: ['xref' => 'X1']));

        self::assertNull($self['relationship']);
    }

    /**
     * The reason this module does not call webtrees' own
     * `getCloseRelationshipName()`: that walks the tree at `Auth::PRIV_HIDE`,
     * so it would name a relationship whose path runs through someone the
     * member may not see — and the name itself then discloses that the hidden
     * person, and the connection through them, exist.
     *
     * Otto (X12) is Ida's father, and Ida (X9) is confidential. Anna can see
     * Otto's record — he is long dead and carries no restriction — but every
     * path from her to him runs through Ida. So the record opens and says
     * nothing about how they are related, which is the honest answer.
     */
    public function testNoRelationshipIsNamedThroughSomeoneHidden(): void
    {
        $this->signInAsAnna();

        $response = $this->api(IndividualRead::class, attributes: ['xref' => 'X12']);

        self::assertSame(200, $response->getStatusCode(), 'Otto himself is visible.');
        self::assertNull($this->json($response)['relationship']);

        // A manager, who may see Ida, is told — which is what proves the
        // difference is the access level and not the distance.
        Auth::logout();
        $this->login($this->createUser('mia', 'Mia Verwalterin', 'geheim', UserInterface::ROLE_MANAGER, 'X1'));

        $named = $this->json($this->api(IndividualRead::class, attributes: ['xref' => 'X12']))['relationship'];

        self::assertNotNull($named, 'A manager sees the whole path, so it can be named.');
    }

    public function testAnUnlinkedAccountIsToldNothingAboutRelationships(): void
    {
        // No XREF: the account is not linked to a record, so there is no
        // "you" for a relationship to be relative to.
        $this->login($this->createUser('nora', 'Nora Ohnesatz', 'geheim', UserInterface::ROLE_MEMBER));

        $response = $this->api(IndividualRead::class, attributes: ['xref' => 'X2']);

        self::assertSame(200, $response->getStatusCode());
        self::assertNull($this->json($response)['relationship']);
    }
}
