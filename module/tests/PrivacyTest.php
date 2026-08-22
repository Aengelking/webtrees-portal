<?php

declare(strict_types=1);

namespace Engelking\Webtrees\PortalApi\Tests;

use Engelking\Webtrees\PortalApi\Http\RequestHandlers\IndividualRead;
use Engelking\Webtrees\PortalApi\Http\RequestHandlers\MeRead;
use Engelking\Webtrees\PortalApi\Http\RequestHandlers\MemberList;
use Engelking\Webtrees\PortalApi\Http\RequestHandlers\MemberRead;
use Fig\Http\Message\StatusCodeInterface;
use Fisharebest\Webtrees\Auth;
use Fisharebest\Webtrees\Contracts\UserInterface;
use Fisharebest\Webtrees\User;
use PHPUnit\Framework\Attributes\CoversNothing;

use function array_column;

/**
 * The privacy assertions from the handoff, §7.
 *
 * The fixture (tests/data/portal.ged) contains:
 *
 *   X1 Anna    living   — the member's own record
 *   X2 Bertha  dead     — visible to everyone; has one OCCU marked
 *                         "2 RESN confidential"
 *   X3 Clara   living   — "1 RESN confidential": managers only
 *   X4 Dieter  living   — no restriction: members but not visitors
 *   X5 Emil    dead     — visible to everyone
 *
 * Account A is a member and must never see X3 in any shape. Account B is a
 * manager and must see it, which is what makes the assertions about A
 * meaningful rather than vacuous.
 */
#[CoversNothing]
class PrivacyTest extends PortalTestCase
{
    private User $member;
    private User $manager;
    private User $hidden_member;
    private int $hidden_member_profile_id;

    protected function setUp(): void
    {
        parent::setUp();

        $this->member  = $this->createUser('anna', 'Anna Beispiel', 'correct-horse', UserInterface::ROLE_MEMBER, 'X1');
        $this->manager = $this->createUser('mia', 'Mia Verwalterin', 'correct-horse', UserInterface::ROLE_MANAGER, 'X5');

        // A member whose linked record is the confidential one.
        $this->hidden_member = $this->createUser('clara', 'Clara Beispiel', 'correct-horse', UserInterface::ROLE_MEMBER, 'X3');

        $this->createProfile($this->member, true);
        $this->createProfile($this->manager, true);
        $this->hidden_member_profile_id = $this->createProfile($this->hidden_member, true);
    }

    // -----------------------------------------------------------------
    // Unauthenticated
    // -----------------------------------------------------------------

    public function testUnauthenticatedRequestsAreRejectedWithNoContent(): void
    {
        foreach ([MeRead::class, MemberList::class] as $route) {
            $response = $this->api($route);

            self::assertSame(StatusCodeInterface::STATUS_UNAUTHORIZED, $response->getStatusCode());
            self::assertSame(['error' => 'unauthenticated', 'message' => 'Please sign in.'], $this->json($response));
        }

        $response = $this->api(IndividualRead::class, attributes: ['xref' => 'X2']);

        self::assertSame(StatusCodeInterface::STATUS_UNAUTHORIZED, $response->getStatusCode());
        self::assertStringNotContainsString('Bertha', $this->raw($response));
        self::assertStringNotContainsString('X2', $this->raw($response));
    }

    // -----------------------------------------------------------------
    // Record-level privacy
    // -----------------------------------------------------------------

    public function testAMemberCannotReadAConfidentialIndividual(): void
    {
        $this->login($this->member);

        $response = $this->api(IndividualRead::class, attributes: ['xref' => 'X3']);

        self::assertSame(StatusCodeInterface::STATUS_NOT_FOUND, $response->getStatusCode());
        self::assertSame('not_found', $this->json($response)['error']);
        self::assertStringNotContainsString('Clara', $this->raw($response));
    }

    public function testAManagerCanReadTheSameConfidentialIndividual(): void
    {
        $this->login($this->manager);

        $response = $this->api(IndividualRead::class, attributes: ['xref' => 'X3']);

        self::assertSame(StatusCodeInterface::STATUS_OK, $response->getStatusCode());
        self::assertSame('Clara Beispiel', $this->json($response)['name']);
    }

    public function testAMissingRecordAndAHiddenRecordAreIndistinguishable(): void
    {
        $this->login($this->member);

        $hidden  = $this->api(IndividualRead::class, attributes: ['xref' => 'X3']);
        $missing = $this->api(IndividualRead::class, attributes: ['xref' => 'X999']);

        self::assertSame($missing->getStatusCode(), $hidden->getStatusCode());
        self::assertSame($this->json($missing), $this->json($hidden));
    }

    // -----------------------------------------------------------------
    // Nested relative lists
    // -----------------------------------------------------------------

    public function testAHiddenSiblingIsAbsentFromARelativeList(): void
    {
        $this->login($this->member);

        $response = $this->api(IndividualRead::class, attributes: ['xref' => 'X1']);
        $body     = $this->json($response);

        self::assertSame(StatusCodeInterface::STATUS_OK, $response->getStatusCode());
        self::assertSame(['X4'], array_column($body['siblings'], 'xref'), 'Clara (X3) must not appear as a sibling.');
        // Without the CSRF token: it is thirty random characters, and about
        // one run in a hundred contains "X3" by chance.
        self::assertStringNotContainsString('X3', $this->rawWithoutCsrfToken($response));
        self::assertStringNotContainsString('Clara', $this->rawWithoutCsrfToken($response));
    }

    public function testAManagerSeesTheHiddenSibling(): void
    {
        $this->login($this->manager);

        $body = $this->json($this->api(IndividualRead::class, attributes: ['xref' => 'X1']));

        self::assertEqualsCanonicalizing(['X3', 'X4'], array_column($body['siblings'], 'xref'));
    }

    public function testTheHiddenIndividualIsAbsentFromTheMembersOwnRecord(): void
    {
        $this->login($this->member);

        $response = $this->api(MeRead::class);
        $body     = $this->json($response);

        self::assertSame('X1', $body['individual']['xref']);
        self::assertStringNotContainsString('X3', $this->raw($response));
        self::assertStringNotContainsString('Clara', $this->raw($response));
    }

    // -----------------------------------------------------------------
    // Fact-level privacy
    // -----------------------------------------------------------------

    public function testAConfidentialFactIsAbsentForAMember(): void
    {
        $this->login($this->member);

        $response = $this->api(IndividualRead::class, attributes: ['xref' => 'X2']);
        $body     = $this->json($response);
        $values   = array_column($body['events'], 'value');

        self::assertContains('Hebamme', $values, 'The unrestricted occupation should still be there.');
        self::assertNotContains('Geheimniskraemerin', $values);
        self::assertStringNotContainsString('Geheimniskraemerin', $this->raw($response));
    }

    public function testAConfidentialFactIsPresentForAManager(): void
    {
        $this->login($this->manager);

        $body = $this->json($this->api(IndividualRead::class, attributes: ['xref' => 'X2']));

        self::assertContains('Geheimniskraemerin', array_column($body['events'], 'value'));
    }

    // -----------------------------------------------------------------
    // Directory results
    // -----------------------------------------------------------------

    public function testTheDirectoryDoesNotLeakAHiddenIndividual(): void
    {
        $this->login($this->member);

        $response = $this->api(MemberList::class);
        $body     = $this->json($response);

        // The member consented to be listed, so their chosen display name is
        // published. Their genealogy record is not.
        $names = array_column($body['items'], 'display_name');
        self::assertContains('Clara Beispiel', $names);

        $entry = $this->entryFor($body['items'], $this->hidden_member_profile_id);
        self::assertNull($entry['individual'], 'The hidden individual must not be attached to the directory entry.');

        self::assertStringNotContainsString('X3', $this->raw($response));
    }

    public function testTheDirectoryShowsTheIndividualToAManager(): void
    {
        $this->login($this->manager);

        $body  = $this->json($this->api(MemberList::class));
        $entry = $this->entryFor($body['items'], $this->hidden_member_profile_id);

        self::assertSame('X3', $entry['individual']['xref']);
    }

    public function testAMemberDetailDoesNotLeakAHiddenIndividual(): void
    {
        $this->login($this->member);

        $response = $this->api(MemberRead::class, attributes: ['id' => $this->hidden_member_profile_id]);
        $body     = $this->json($response);

        self::assertSame(StatusCodeInterface::STATUS_OK, $response->getStatusCode());
        self::assertNull($body['individual']);
        self::assertNull($body['individual_detail']);
        self::assertStringNotContainsString('X3', $this->raw($response));
    }

    public function testAMemberWhoOptedOutIsNotInTheDirectory(): void
    {
        $opted_out = $this->createUser('otto', 'Otto Zurueckhaltend', 'correct-horse', UserInterface::ROLE_MEMBER);
        $profile   = $this->createProfile($opted_out, false);

        $this->login($this->member);

        $body = $this->json($this->api(MemberList::class));
        self::assertNotContains('Otto Zurueckhaltend', array_column($body['items'], 'display_name'));

        $response = $this->api(MemberRead::class, attributes: ['id' => $profile]);
        self::assertSame(StatusCodeInterface::STATUS_NOT_FOUND, $response->getStatusCode());
    }

    // -----------------------------------------------------------------
    // Caching
    // -----------------------------------------------------------------

    /**
     * A reference number is not an event, and it is not part of a name.
     *
     * X1 carries "1 REFN 4711 / 2 TYPE SB" — the archive's own numbering, which
     * the Vesta module shows as a badge in front of the name in webtrees. Here
     * it belongs in `references` and nowhere else.
     */
    public function testAReferenceNumberIsItsOwnFieldAndNotAnEvent(): void
    {
        $this->login($this->member);

        $individual = $this->json($this->api(MeRead::class))['individual'];
        $tags       = [];

        foreach ($individual['events'] as $event) {
            $tags[] = $event['tag'];
        }

        self::assertNotContains('INDI:REFN', $tags);
        self::assertSame([['number' => '4711', 'type' => 'SB']], $individual['references']);
        self::assertSame('Anna Beispiel', $individual['name']);
    }

    /**
     * The number travels with every *mention* of a person, not only with the
     * full record: this family has more than one Dieter Beispiel, and a card
     * that does not say which is a card that has to be opened to be sure.
     *
     * It is the same list, from the same facts at the same access level, so a
     * card discloses nothing the record one tap away did not.
     */
    public function testTheReferenceNumberTravelsWithEveryMention(): void
    {
        $this->login($this->member);

        $mother = $this->json($this->api(IndividualRead::class, attributes: ['xref' => 'X2']));
        $child  = null;

        foreach ($mother['children'] as $candidate) {
            if ($candidate['xref'] === 'X1') {
                $child = $candidate;
            }
        }

        self::assertNotNull($child, 'Anna is one of her mother’s children.');
        self::assertSame([['number' => '4711', 'type' => 'SB']], $child['references']);
    }

    /** And the filtering follows it there. A confidential number is on neither shape. */
    public function testAConfidentialReferenceNumberIsNotOnACardEither(): void
    {
        $this->login($this->member);

        $response = $this->api(IndividualRead::class, attributes: ['xref' => 'X1']);

        foreach ($this->json($response)['parents'] as $parent) {
            if ($parent['xref'] === 'X2') {
                self::assertSame(
                    [
                        ['number' => '4712', 'type' => null],
                        ['number' => '47C12', 'type' => null],
                    ],
                    $parent['references'],
                );
            }
        }

        self::assertStringNotContainsString('9999', $this->raw($response));
    }

    /**
     * And it is filtered like everything else. X2 has three: two plain ones —
     * one of which carries a letter, because the family's numbers do — and a
     * confidential one.
     */
    public function testAConfidentialReferenceNumberIsNotPublished(): void
    {
        $this->login($this->member);

        $response = $this->api(IndividualRead::class, attributes: ['xref' => 'X2']);

        self::assertSame(
            [
                ['number' => '4712', 'type' => null],
                ['number' => '47C12', 'type' => null],
            ],
            $this->json($response)['references']
        );
        self::assertStringNotContainsString('9999', $this->raw($response));

        // A manager may see the tree's confidential facts, so they see all of
        // them.
        Auth::logout();
        $this->login($this->manager);

        self::assertSame(
            [
                ['number' => '4712', 'type' => null],
                ['number' => '47C12', 'type' => null],
                ['number' => '9999', 'type' => 'Intern'],
            ],
            $this->json($this->api(IndividualRead::class, attributes: ['xref' => 'X2']))['references']
        );
    }

    public function testEveryResponseForbidsCaching(): void
    {
        $this->login($this->member);

        foreach ([MeRead::class, MemberList::class] as $route) {
            self::assertSame('private, no-store', $this->api($route)->getHeaderLine('Cache-Control'));
        }

        // Including error responses, which are just as account-specific.
        self::assertSame('private, no-store', $this->api(IndividualRead::class, attributes: ['xref' => 'X3'])->getHeaderLine('Cache-Control'));
    }

    /**
     * @param array<int,array<string,mixed>> $items
     *
     * @return array<string,mixed>
     */
    private function entryFor(array $items, int $id): array
    {
        foreach ($items as $item) {
            if ($item['id'] === $id) {
                return $item;
            }
        }

        self::fail('No directory entry with id ' . $id);
    }
}
