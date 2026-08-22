<?php

declare(strict_types=1);

namespace Engelking\Webtrees\PortalApi\Tests;

use Engelking\Webtrees\PortalApi\Http\RequestHandlers\IndividualUpdate;
use Engelking\Webtrees\PortalApi\Http\RequestHandlers\MeRead;
use Fig\Http\Message\RequestMethodInterface;
use Fig\Http\Message\StatusCodeInterface;
use Fisharebest\Webtrees\Auth;
use Fisharebest\Webtrees\Contracts\UserInterface;
use Fisharebest\Webtrees\DB;
use Fisharebest\Webtrees\Registry;
use Fisharebest\Webtrees\User;
use PHPUnit\Framework\Attributes\CoversNothing;

use function array_column;

/**
 * Phase 2: a member proposing changes to their own record.
 *
 * The two things most worth proving are that an edit cannot reach anyone
 * else's record, and that it cannot destroy data the member was never allowed
 * to see. The fixture gives Anna (X1) an `EVEN` marked
 * `2 RESN confidential`, which she cannot read at member access level — if an
 * edit of hers ever rebuilt the record from what she *can* see, that fact
 * would vanish and nothing would say so.
 */
#[CoversNothing]
class EditTest extends PortalTestCase
{
    private User $member;

    protected function setUp(): void
    {
        parent::setUp();

        $this->member = $this->createUser('anna', 'Anna Beispiel', 'pw', UserInterface::ROLE_MEMBER, 'X1');
        $this->login($this->member);
    }

    /**
     * @param array<string,mixed> $body
     */
    private function put(array $body): mixed
    {
        return $this->api(
            IndividualUpdate::class,
            RequestMethodInterface::METHOD_PUT,
            body: $body,
            headers: $this->csrfHeader(),
        );
    }

    /** The GEDCOM an editor would be asked to approve. */
    private function proposedGedcom(string $xref = 'X1'): string
    {
        return (string) DB::table('change')
            ->where('gedcom_id', '=', $this->tree->id())
            ->where('xref', '=', $xref)
            ->where('status', '=', 'pending')
            ->orderByDesc('change_id')
            ->value('new_gedcom');
    }

    private function approvedGedcom(string $xref = 'X1'): string
    {
        return (string) DB::table('individuals')
            ->where('i_file', '=', $this->tree->id())
            ->where('i_id', '=', $xref)
            ->value('i_gedcom');
    }

    // -----------------------------------------------------------------
    // The change is proposed, never applied
    // -----------------------------------------------------------------

    public function testAnEditBecomesAPendingChangeAndNotALiveOne(): void
    {
        $response = $this->put(['occupation' => 'Möbelrestauratorin']);

        self::assertSame(StatusCodeInterface::STATUS_ACCEPTED, $response->getStatusCode());
        self::assertSame('pending_approval', $this->json($response)['status']);

        self::assertStringContainsString('Möbelrestauratorin', $this->proposedGedcom());
        self::assertStringNotContainsString('Möbelrestauratorin', $this->approvedGedcom());
        self::assertStringContainsString('Tischlerin', $this->approvedGedcom());
    }

    public function testTheResponseShowsTheApprovedRecordNotTheProposedOne(): void
    {
        $body = $this->json($this->put(['occupation' => 'Möbelrestauratorin']));

        self::assertTrue($body['pending_change']);
        self::assertContains('Tischlerin', array_column($body['individual']['events'], 'value'));
        self::assertNotContains('Möbelrestauratorin', array_column($body['individual']['events'], 'value'));
    }

    public function testTheChangeIsAttributedToTheMember(): void
    {
        $this->put(['occupation' => 'Möbelrestauratorin']);

        // webtrees' own CHAN entry, so the change log names who asked for it.
        self::assertStringContainsString('_WT_USER anna', $this->proposedGedcom());

        $row = DB::table('change')->where('xref', '=', 'X1')->orderByDesc('change_id')->first();
        self::assertSame($this->member->id(), (int) $row->user_id);
    }

    // -----------------------------------------------------------------
    // Nothing is lost
    // -----------------------------------------------------------------

    public function testAnEditPreservesAFactTheMemberCannotSee(): void
    {
        // Anna cannot read this fact at member access level.
        $me = $this->json($this->api(MeRead::class));
        self::assertStringNotContainsString('Vertraulich', $this->rawWithoutCsrfToken($this->api(MeRead::class)));
        self::assertNotContains('Vertrauliche Notiz zur Person', array_column($me['individual']['events'], 'value'));

        $this->put(['occupation' => 'Möbelrestauratorin']);

        // ...and editing her occupation must not have deleted it.
        self::assertStringContainsString('Vertrauliche Notiz zur Person', $this->proposedGedcom());
        self::assertStringContainsString('2 RESN confidential', $this->proposedGedcom());
    }

    public function testAnEditPreservesStructuralFacts(): void
    {
        $this->put(['occupation' => 'Möbelrestauratorin']);

        $proposed = $this->proposedGedcom();

        self::assertStringContainsString('1 FAMC @F1@', $proposed, 'The link to her parents must survive.');
        self::assertStringContainsString('1 SEX F', $proposed);
        self::assertStringContainsString('0 @X1@ INDI', $proposed);
    }

    public function testEditingOneFieldLeavesTheOthersAlone(): void
    {
        $this->put(['occupation' => 'Möbelrestauratorin']);

        $proposed = $this->proposedGedcom();

        self::assertStringContainsString('1 NAME Anna /Beispiel/', $proposed);
        self::assertStringContainsString('2 DATE 12 MAR 1985', $proposed);
        self::assertStringContainsString('2 PLAC Hannover, Niedersachsen, Deutschland', $proposed);
    }

    // -----------------------------------------------------------------
    // Authorisation
    // -----------------------------------------------------------------

    public function testAnEditCannotBeAimedAtAnotherRecord(): void
    {
        // There is no parameter for it, so the closest an attacker can get is
        // to send fields that look like one.
        $this->put(['occupation' => 'Möbelrestauratorin', 'xref' => 'X2']);

        self::assertSame('', $this->proposedGedcom('X2'));
    }

    public function testAFieldThatIsNotOffered(): void
    {
        $response = $this->put(['sex' => 'M']);

        self::assertSame(StatusCodeInterface::STATUS_BAD_REQUEST, $response->getStatusCode());
        self::assertSame('', $this->proposedGedcom());
    }

    public function testAnAccountWithNoLinkedRecordCannotEdit(): void
    {
        $this->login($this->createUser('nora', 'Nora Ohnesatz', 'pw', UserInterface::ROLE_MEMBER));

        $response = $this->put(['occupation' => 'Tischlerin']);

        self::assertSame(StatusCodeInterface::STATUS_NOT_FOUND, $response->getStatusCode());
        self::assertSame('no_linked_record', $this->json($response)['error']);
    }

    public function testEditingRequiresACsrfToken(): void
    {
        $response = $this->api(
            IndividualUpdate::class,
            RequestMethodInterface::METHOD_PUT,
            body: ['occupation' => 'Möbelrestauratorin'],
        );

        self::assertSame(StatusCodeInterface::STATUS_FORBIDDEN, $response->getStatusCode());
        self::assertSame('', $this->proposedGedcom());
    }

    // -----------------------------------------------------------------
    // Injection
    // -----------------------------------------------------------------

    public function testAValueCannotSmuggleInAnotherFact(): void
    {
        $this->put(['occupation' => "Tischlerin\n1 DEAT\n2 DATE 1 JAN 2020"]);

        $proposed = $this->proposedGedcom();

        self::assertStringNotContainsString("\n1 DEAT", $proposed, 'A newline in a value must not create a fact.');
        self::assertStringContainsString('1 OCCU Tischlerin 1 DEAT 2 DATE 1 JAN 2020', $proposed);
    }

    public function testANameCannotSmuggleInASurname(): void
    {
        $response = $this->put(['given_names' => 'Anna /Schmidt/']);

        self::assertSame(StatusCodeInterface::STATUS_BAD_REQUEST, $response->getStatusCode());
        self::assertSame('', $this->proposedGedcom());
    }

    public function testAnUnparseableDateIsRefused(): void
    {
        $response = $this->put(['birth_date' => 'irgendwann im Sommer']);

        self::assertSame(StatusCodeInterface::STATUS_BAD_REQUEST, $response->getStatusCode());
        self::assertSame('', $this->proposedGedcom());
    }

    public function testAnApproximateDateIsAccepted(): void
    {
        $response = $this->put(['birth_date' => 'ABT 1985']);

        self::assertSame(StatusCodeInterface::STATUS_ACCEPTED, $response->getStatusCode());
        self::assertStringContainsString('2 DATE ABT 1985', $this->proposedGedcom());
    }

    // -----------------------------------------------------------------
    // Removing, renaming, refusing
    // -----------------------------------------------------------------

    public function testNullRemovesAFact(): void
    {
        $this->put(['occupation' => null]);

        self::assertStringNotContainsString('1 OCCU', $this->proposedGedcom());
        self::assertStringContainsString('1 NAME Anna /Beispiel/', $this->proposedGedcom());
    }

    public function testChangingANameKeepsNameAndIndexFieldsInStep(): void
    {
        $this->put(['surname' => 'Beispiel-Schmidt']);

        $proposed = $this->proposedGedcom();

        self::assertStringContainsString('1 NAME Anna /Beispiel-Schmidt/', $proposed);
        self::assertStringContainsString('2 GIVN Anna', $proposed);
        self::assertStringContainsString('2 SURN Beispiel-Schmidt', $proposed);
    }

    public function testContactDetailsCanBeSetAndAreVisibleOnTheOwnRecordOnly(): void
    {
        $response = $this->put(['email' => 'anna@example.test', 'phone' => '+49 511 123456']);
        self::assertSame(StatusCodeInterface::STATUS_ACCEPTED, $response->getStatusCode());

        $proposed = $this->proposedGedcom();
        self::assertStringContainsString('1 EMAIL anna@example.test', $proposed);
        self::assertStringContainsString('1 PHON +49 511 123456', $proposed);
    }

    public function testALockedRecordRefusesEdits(): void
    {
        $this->login($this->createUser('fritz', 'Fritz Beispiel', 'pw', UserInterface::ROLE_MEMBER, 'X6'));

        $response = $this->put(['occupation' => 'Tischler']);

        self::assertSame(StatusCodeInterface::STATUS_LOCKED, $response->getStatusCode());
        self::assertSame('record_locked', $this->json($response)['error']);
        self::assertSame('', $this->proposedGedcom('X6'));
    }

    public function testASecondEditIsRefusedWhileTheFirstIsUnapproved(): void
    {
        self::assertSame(StatusCodeInterface::STATUS_ACCEPTED, $this->put(['occupation' => 'Möbelrestauratorin'])->getStatusCode());

        $second = $this->put(['surname' => 'Beispiel-Schmidt']);

        // Accepting it would silently discard the first: a member cannot see
        // pending changes, so the second would be built from the approved
        // record and would overwrite the first when an editor applied them.
        self::assertSame(StatusCodeInterface::STATUS_CONFLICT, $second->getStatusCode());
        self::assertSame('change_pending', $this->json($second)['error']);

        self::assertSame(1, DB::table('change')->where('xref', '=', 'X1')->where('status', '=', 'pending')->count());
    }

    public function testTheMemberIsToldTheirChangeIsWaiting(): void
    {
        self::assertFalse($this->json($this->api(MeRead::class))['individual']['pending_change']);

        $this->put(['occupation' => 'Möbelrestauratorin']);

        self::assertTrue($this->json($this->api(MeRead::class))['individual']['pending_change']);
    }

    public function testAnEmptyBodyChangesNothing(): void
    {
        $response = $this->put([]);

        self::assertSame(StatusCodeInterface::STATUS_BAD_REQUEST, $response->getStatusCode());
        self::assertSame('', $this->proposedGedcom());
    }

    public function testTheRecordStillParsesAfterAnEdit(): void
    {
        $this->put(['surname' => 'Beispiel-Schmidt', 'birth_place' => 'Celle, Niedersachsen, Deutschland']);

        // Round-trip the proposed GEDCOM through webtrees itself: if the
        // rewrite produced something malformed, the name would not come back.
        $individual = Registry::individualFactory()->new('X1', $this->proposedGedcom(), null, $this->tree);

        self::assertSame('Anna Beispiel-Schmidt', $this->text($individual->fullName()));
        self::assertSame('Celle, Niedersachsen, Deutschland', $individual->getBirthPlace()->gedcomName());
        self::assertSame('12 Mar 1985', $individual->getBirthDate()->minimumDate()->format('%j %M %Y'));
    }

    private function text(string $html): string
    {
        return trim(html_entity_decode(strip_tags($html), ENT_QUOTES | ENT_HTML5, 'UTF-8'));
    }

    /**
     * webtrees applies an edit immediately, with no pending change, when the
     * acting user has `auto_accept` — which editors and administrators usually
     * do and members do not. The portal has to say which of the two happened.
     *
     * Getting this wrong is not a data problem, it is a trust problem: an
     * administrator trying the portal out would be told their change was
     * waiting for review, and would then go looking for it in a list it is
     * not in.
     */
    public function testAnEditThatWentStraightThroughIsNotCalledPending(): void
    {
        Auth::logout();

        $user = $this->createUser('mia', 'Mia Verwalterin', 'geheim', UserInterface::ROLE_MANAGER, 'X1');
        $user->setPreference(UserInterface::PREF_AUTO_ACCEPT_EDITS, '1');

        $this->login($user);

        $response = $this->api(
            IndividualUpdate::class,
            RequestMethodInterface::METHOD_PUT,
            body: ['occupation' => 'Möbelrestauratorin'],
            headers: $this->csrfHeader(),
        );

        $payload = $this->json($response);

        self::assertSame('applied', $payload['status']);
        self::assertFalse($payload['pending_change']);
    }

    public function testAMembersEditIsStillQueued(): void
    {
        // setUp() already signed in as Anna, a plain member.
        $payload = $this->json($this->api(
            IndividualUpdate::class,
            RequestMethodInterface::METHOD_PUT,
            body: ['occupation' => 'Möbelrestauratorin'],
            headers: $this->csrfHeader(),
        ));

        self::assertSame('pending_approval', $payload['status']);
        self::assertTrue($payload['pending_change']);
    }

    /**
     * A real GEDCOM export puts more under a birth than a date and a place:
     *
     *   1 BIRT
     *   2 DATE 19 MAR 1978
     *   3 SOUR Geburtsurkunde
     *   2 PLAC Reutlingen
     *   3 MAP
     *   4 LATI N48.4919
     *   2 ADDR
     *   3 CITY Reutlingen
     *   3 STAE Baden-Württemberg
     *   3 CTRY Germany
     *
     * A member editing one of the two fields the portal offers must not cost
     * the family any of the rest. This is the test that says so.
     */
    public function testEditingTheBirthDateKeepsEverythingUnderThePlace(): void
    {
        $this->api(
            IndividualUpdate::class,
            RequestMethodInterface::METHOD_PUT,
            body: ['birth_date' => '13 MAR 1985'],
            headers: $this->csrfHeader(),
        );

        $gedcom = $this->proposedGedcom();

        self::assertStringContainsString('2 DATE 13 MAR 1985', $gedcom, 'The date is the one thing that changed.');

        // The place, its coordinates, and the address block beside it.
        self::assertStringContainsString('2 PLAC Hannover, Niedersachsen, Deutschland', $gedcom);
        self::assertStringContainsString('3 MAP', $gedcom);
        self::assertStringContainsString('4 LATI N52.3759', $gedcom);
        self::assertStringContainsString('4 LONG E9.7320', $gedcom);
        self::assertStringContainsString('2 ADDR', $gedcom);
        self::assertStringContainsString('3 CITY Hannover', $gedcom);
        self::assertStringContainsString('3 STAE Niedersachsen', $gedcom);
        self::assertStringContainsString('3 CTRY Germany', $gedcom);
    }

    public function testEditingTheBirthPlaceKeepsEverythingUnderTheDate(): void
    {
        $this->api(
            IndividualUpdate::class,
            RequestMethodInterface::METHOD_PUT,
            body: ['birth_place' => 'Reutlingen'],
            headers: $this->csrfHeader(),
        );

        $gedcom = $this->proposedGedcom();

        self::assertStringContainsString('2 PLAC Reutlingen', $gedcom);
        self::assertStringContainsString('2 DATE 12 MAR 1985', $gedcom);
        self::assertStringContainsString('3 SOUR Geburtsurkunde', $gedcom, 'The date kept its source citation.');

        // The old coordinates went with the old place, which is right: they
        // described Hannover, and this is now Reutlingen.
        self::assertStringNotContainsString('N52.3759', $gedcom);

        // The address block is not part of the place line, so it stays. It is
        // now stale, which is a thing for an editor to notice when approving —
        // not something the portal should quietly delete.
        self::assertStringContainsString('3 CITY Hannover', $gedcom);
    }
}
