<?php

declare(strict_types=1);

namespace Engelking\Webtrees\PortalApi\Tests;

use Engelking\Webtrees\PortalApi\Http\RequestHandlers\AncestorsRead;
use Engelking\Webtrees\PortalApi\Http\RequestHandlers\IndividualRead;
use Fisharebest\Webtrees\Auth;
use Fisharebest\Webtrees\Contracts\UserInterface;
use PHPUnit\Framework\Attributes\CoversNothing;

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
 */
#[CoversNothing]
class TreeTest extends PortalTestCase
{
    private function signInAsAnna(): void
    {
        $this->login($this->createUser('anna', 'Anna Beispiel', 'geheim', UserInterface::ROLE_MEMBER, 'X1'));
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
            $names[$person['position']] = $person['name'];
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
     * The one that matters. A confidential ancestor is not in the response —
     * not as an entry, not as a placeholder, and not by name anywhere in it.
     */
    public function testAConfidentialAncestorIsAbsentEntirely(): void
    {
        $this->signInAsAnna();

        $response = $this->api(AncestorsRead::class, attributes: ['xref' => 'X1']);
        $names    = $this->byPosition($this->json($response)['people']);

        self::assertArrayNotHasKey(7, $names);
        self::assertStringNotContainsString('Ida', $this->raw($response));
        self::assertStringNotContainsString('X9', $this->raw($response));
    }

    /**
     * And the walk stops there rather than reaching around. If Ida had parents
     * in the fixture, positions 14 and 15 would be the give-away.
     */
    public function testTheWalkDoesNotReachPastAHiddenAncestor(): void
    {
        $this->signInAsAnna();

        foreach ($this->ancestors('X1', 6) as $person) {
            self::assertNotSame(7, $person['position'], 'The hidden ancestor is in the response.');
            self::assertLessThan(14, (int) $person['position'], 'The walk went past a hidden ancestor.');
        }
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
