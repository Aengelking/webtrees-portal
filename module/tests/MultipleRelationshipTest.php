<?php

declare(strict_types=1);

namespace Engelking\Webtrees\PortalApi\Tests;

use Engelking\Webtrees\PortalApi\Http\RequestHandlers\IndividualRead;
use Fisharebest\Webtrees\Contracts\UserInterface;
use Fisharebest\Webtrees\User;
use PHPUnit\Framework\Attributes\CoversNothing;

/**
 * People who are related to each other more than once.
 *
 * A family that has married within itself for three hundred years is full of
 * them, and the portal used to pick one: the tree's shortest path if it found
 * one, otherwise the first archive number that produced an answer. Both
 * choices were arbitrary about the thing a member most wants to know — *how*
 * they are related, which can honestly be two things at once.
 *
 * Dieter (X4) is the subject because he carries three usable archive numbers,
 * which is what a person who descends from the family by three lines looks
 * like. Anna (X1) makes the point from the other side: her `REFN 4711` is not
 * an SB path at all, so she has no numbers to cross and reads exactly what she
 * read before.
 */
#[CoversNothing]
class MultipleRelationshipTest extends PortalTestCase
{
    private User $dieter;

    protected function setUp(): void
    {
        parent::setUp();

        $this->dieter = $this->createUser('dieter', 'Dieter Beispiel', 'correct-horse', UserInterface::ROLE_MEMBER, 'X4');

        $this->createProfile($this->dieter, true);
        $this->login($this->dieter);
    }

    /** The relationship on a record, as Dieter reads it, in $language. */
    private function relationshipTo(string $xref, string $language = 'de'): string|null
    {
        $record = $this->json(
            $this->api(IndividualRead::class, attributes: ['xref' => $xref], headers: ['Accept-Language' => $language])
        );

        return $record['relationship'] ?? null;
    }

    // -----------------------------------------------------------------
    // More than one way
    // -----------------------------------------------------------------

    /**
     * The case this exists for. Otto (X12) is Dieter's great-grandfather down
     * one of Dieter's lines and his fourth cousin down another. Both are true,
     * and only the first used to be said.
     *
     * Otto is beyond the four-step walk, so both come from the numbers. That
     * is the ordinary case for a relationship worth being surprised by: what
     * the tree can reach, a family already knows.
     */
    public function testEveryWayTwoPeopleAreRelatedIsNamed(): void
    {
        $relationship = $this->relationshipTo('X12');

        self::assertNotNull($relationship);
        self::assertStringContainsString('Urgroßvater', $relationship);
        self::assertStringContainsString('Cousin 4. Grades', $relationship);
    }

    /**
     * And a doubtful number does not get to corroborate a sound one.
     *
     * Dieter's record carries a bare `9` before his `10/1335.21`, on purpose:
     * two digits with no oblique are the head of a line, and are also what the
     * archive's older, unrelated numbering looks like at two digits (§2.57).
     * Crossed with Otto it yields a confident "great-grand-nephew" that may
     * rest on nothing. One number per record used to make that unreachable;
     * naming every way they are related had to earn it back deliberately.
     */
    public function testABareNumberIsNotReadBesideAnExplicitOne(): void
    {
        self::assertStringNotContainsString('Urgroßneffe', (string) $this->relationshipTo('X12'));
    }

    /**
     * The nearest leads. A member glancing at a list reads the first words,
     * and the first words should be the closest of the answers rather than
     * whichever number happened to sort first — three generations up beats
     * four down and beats a fourth cousin.
     */
    public function testTheNearestOneComesFirst(): void
    {
        $relationship = (string) $this->relationshipTo('X12');

        self::assertStringStartsWith('Urgroßvater', $relationship);
    }

    /**
     * The rest are marked as further answers rather than run together with the
     * first — "Urgroßvater, Urgroßneffe" reads like one muddled title.
     */
    public function testTheFurtherWaysAreMarkedAsSuch(): void
    {
        self::assertStringContainsString(' · auch ', (string) $this->relationshipTo('X12'));

        // And the module says it in the reader's language rather than leaving
        // its own word in English beside webtrees' translated names.
        self::assertStringContainsString(' · also ', (string) $this->relationshipTo('X12', 'en'));
    }

    // -----------------------------------------------------------------
    // And where there is only one way, nothing changes
    // -----------------------------------------------------------------

    /** A plain relative reads exactly as before: no separator, no footnote. */
    public function testOneRelationshipIsStillOneWord(): void
    {
        self::assertSame('ältere Schwester', $this->relationshipTo('X1'));
        self::assertSame('Mutter', $this->relationshipTo('X2'));
    }

    /**
     * The tree still leads where the two disagree in kind. It knows wives,
     * stepfathers and adopted children; an archive number knows descent and
     * nothing else, so its answer is never allowed to displace the tree's.
     */
    public function testTheTreesAnswerLeads(): void
    {
        self::assertStringStartsWith('Großvater väterlicherseits', (string) $this->relationshipTo('X7'));
    }

    /**
     * A reader whose own record carries no archive number reads what they
     * always read. Anna's `4711` is not an SB path, so there is nothing to
     * cross and the walk is the whole answer.
     *
     * Otto (X12) is the same record Dieter reads three relationships on, and
     * Anna — who can see it perfectly well; it is a man who died in 1899 — is
     * told nothing, because there is nothing to tell: no path within four
     * steps and no numbers to compare. Silence rather than a guess, and this
     * change does not invent one.
     */
    public function testAReaderWithoutANumberIsUnaffected(): void
    {
        $anna = $this->createUser('anna', 'Anna Beispiel', 'anderes-pferd', UserInterface::ROLE_MEMBER, 'X1');

        $this->createProfile($anna, true);
        $this->login($anna);

        self::assertSame('jüngerer Bruder', $this->relationshipTo('X4'));
        self::assertNull($this->relationshipTo('X12'));
    }

    /** Nobody is related to themselves, however many numbers they carry. */
    public function testNobodyIsRelatedToThemselves(): void
    {
        self::assertNull($this->relationshipTo('X4'));
    }
}
