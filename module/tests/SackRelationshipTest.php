<?php

declare(strict_types=1);

namespace Engelking\Webtrees\PortalApi\Tests;

use Engelking\Webtrees\PortalApi\Http\RequestHandlers\IndividualRead;
use Engelking\Webtrees\PortalApi\Http\RequestHandlers\RelationshipRead;
use Engelking\Webtrees\PortalApi\Services\SackNumbers;
use Fisharebest\Webtrees\Contracts\UserInterface;
use Fisharebest\Webtrees\I18N;
use PHPUnit\Framework\Attributes\CoversNothing;

/**
 * The archive number as a calculation.
 *
 * An SB number is an ancestral path — see `SackNumbers` — so the relationship
 * between any two members of the family is a property of two strings. This is
 * the family's own calculator, which has existed since 2009, ported into the
 * module; the port was checked against the original over every pair drawn from
 * its marriage table, and agreed on all 15,876 of them.
 *
 * What is asserted here is the behaviour a member sees. The wording is in
 * English because the test harness initialises `en-US`; the German table is
 * exercised by the two tests that switch the language, and the two are the
 * same code path.
 */
#[CoversNothing]
class SackRelationshipTest extends PortalTestCase
{
    private function signIn(string $xref = 'X1'): void
    {
        $this->login($this->createUser('anna', 'Anna Beispiel', 'geheim', UserInterface::ROLE_MEMBER, $xref));
    }

    /**
     * @return array<string,mixed>
     */
    private function ask(string $a, string $b): array
    {
        $response = $this->api(RelationshipRead::class, query: ['a' => $a, 'b' => $b]);

        self::assertSame(200, $response->getStatusCode());

        return $this->json($response);
    }

    private function named(string $a, string $b): string|null
    {
        return $this->ask($a, $b)['relationship'];
    }

    // -----------------------------------------------------------------
    // The six shapes
    // -----------------------------------------------------------------

    public function testTwoChildrenOfOneCoupleAreSiblings(): void
    {
        $this->signIn();

        self::assertSame('brother/sister', $this->named('24/11', '24/12'));
    }

    public function testTheLineUpwardsIsNamedByItsDistance(): void
    {
        $this->signIn();

        self::assertSame('father/mother', $this->named('24/11', '24/1'));
        self::assertSame('grandfather/grandmother', $this->named('24/111', '24/1'));
        self::assertSame('great-grandfather/great-grandmother', $this->named('24/1111', '24/1'));
        self::assertSame('2x great-grandfather/2x great-grandmother', $this->named('24/11111', '24/1'));
    }

    public function testTheLineDownwardsIsNamedTheSameWayRound(): void
    {
        $this->signIn();

        self::assertSame('son/daughter', $this->named('24/1', '24/11'));
        self::assertSame('grandson/granddaughter', $this->named('24/1', '24/111'));
        self::assertSame('great-grandson/great-granddaughter', $this->named('24/1', '24/1111'));
    }

    public function testASiblingsChildIsANephew(): void
    {
        $this->signIn();

        self::assertSame('nephew/niece', $this->named('24/312', '24/3111'));
        self::assertSame('grand-nephew/grand-niece', $this->named('24/312', '24/31111'));
    }

    public function testAParentsSiblingIsAnUncle(): void
    {
        $this->signIn();

        self::assertSame('uncle/aunt', $this->named('24/3111', '24/312'));
        self::assertSame('great-uncle/great-aunt', $this->named('24/31111', '24/312'));
    }

    /**
     * The degree, which is what a family actually argues about.
     *
     * A first cousin is just "cousin" — the family does not say "first
     * degree" — and everything beyond it counts.
     */
    public function testCousinsAreCountedByHowFarBackTheyShare(): void
    {
        $this->signIn();

        self::assertSame('cousin', $this->named('24/3111', '24/3121'));
        self::assertSame('cousin (second degree)', $this->named('24/31111', '24/31211'));
        self::assertSame('cousin (third degree)', $this->named('24/311111', '24/312111'));
    }

    /**
     * Two people from different lines still have an answer.
     *
     * The lines are positions in one descent, so the calculation simply runs
     * out further. It is the property that makes this worth having: nothing in
     * the tree could name this relationship, and two strings can.
     */
    public function testTwoDifferentLinesAreStillRelated(): void
    {
        $this->signIn();

        self::assertNotNull($this->named('1/11', '32/11'));
    }

    // -----------------------------------------------------------------
    // Marriages inside the family
    // -----------------------------------------------------------------

    /**
     * Without the marriage table this pair are a nephew twice removed; with
     * it they are siblings, which is what they are.
     *
     * The shipped table records that the children of 24/313 and 24/b6 are
     * filed under one number only. A child of that couple and a child of
     * 24/313 by itself have the same parents.
     */
    public function testAMarriageInsideTheFamilyJoinsTheTwoDescents(): void
    {
        $this->signIn();

        self::assertSame('brother/sister', $this->named('24/b61', '24/3132'));
    }

    public function testWithoutTheTableTheSamePairLooksLikeSomethingElse(): void
    {
        $this->signIn();

        // One row, which does not mention either of them.
        $this->module()->setPreference(SackNumbers::SETTING_MARRIAGES, '09/23 = 09/32');

        self::assertSame('nephew/niece (second degree)', $this->named('24/b61', '24/3132'));
    }

    /**
     * A partner who married *in* keeps their own descent.
     *
     * `02/1!` marks the spouse in that pair, so asking about them is asking
     * about where they came from, not about the family they joined.
     */
    public function testThePartnerWhoMarriedInIsLeftAlone(): void
    {
        $this->signIn();

        self::assertSame('brother/sister', $this->named('02/1', '02/2'));
    }

    // -----------------------------------------------------------------
    // Numbers that belong to no line
    // -----------------------------------------------------------------

    /**
     * `GS/` says "no line — what follows is already the path".
     *
     * The lines are branches, and not everybody sits inside one: the ancestors
     * *above* them have no line to belong to, and neither does a branch that
     * was numbered and then died out. Line 24 is `7d3`, so `GS/7d3` is the same
     * person written the other way — and that is the cheapest way to say so.
     */
    public function testAWholeTreeNumberIsThePathItself(): void
    {
        $this->signIn();

        self::assertSame($this->named('24/11', '24/1'), $this->named('GS/7d311', 'GS/7d31'));
        self::assertSame('father/mother', $this->named('GS/7d311', 'GS/7d31'));
    }

    public function testTheTwoWaysOfWritingOneNumberAreOneNumber(): void
    {
        $this->signIn();

        self::assertSame('identical', $this->ask('24/1', 'GS/7d31')['problem']);
    }

    /**
     * The reason it was asked for: the deep ancestors.
     *
     * `7d` is above every line in the Ernestinian branch, so nothing else in
     * the archive can name it — and every one of those lines descends from it.
     */
    public function testAnAncestorAboveTheLinesIsReachable(): void
    {
        $this->signIn();

        self::assertSame('grandfather/grandmother', $this->named('24/1', 'GS/7d'));

        // And further up still — `7` is above the whole archive.
        self::assertSame('great-grandfather/great-grandmother', $this->named('24/1', 'GS/7'));
    }

    /** Case is how it happens to be written, not part of the number. */
    public function testAWholeTreeNumberIsReadInEitherCase(): void
    {
        $this->signIn();

        self::assertSame($this->named('24/11', 'GS/7D3'), $this->named('24/11', 'gs/7d3'));
    }

    /**
     * A bare line number is the head of that line.
     *
     * The archive writes both "24" and "24/" for the person every number in
     * line 24 descends from, and neither is a typo. The oblique is optional
     * only when nothing follows it — "24b6" is not offered, because a
     * two-digit line makes it ambiguous.
     */
    public function testABareLineNumberIsTheHeadOfThatLine(): void
    {
        $this->signIn();

        self::assertSame('identical', $this->ask('24', '24/')['problem']);
        self::assertSame('identical', $this->ask('24', 'GS/7d3')['problem']);
        self::assertSame('father/mother', $this->named('24/1', '24'));
    }

    public function testANumberWithNoObliqueAndSomethingAfterItIsNotANumber(): void
    {
        $this->signIn();

        self::assertSame('invalid_a', $this->ask('24b6', '24/1')['problem']);
    }

    /**
     * `GS` with nothing after it is the progenitor.
     *
     * The empty path is the one person every number in the archive descends
     * from, and he is as much a person as anybody else in it — the arithmetic
     * works on him unchanged, and this is the only way to name him at all.
     */
    public function testTheProgenitorIsANumberLikeAnyOther(): void
    {
        $this->signIn();

        // Line 24 is "7d3", so its head is three generations below the root.
        self::assertSame('great-grandfather/great-grandmother', $this->named('24', 'GS'));
        self::assertSame($this->named('24', 'GS'), $this->named('24', 'GS/'));

        // And the other way round, which is the shape the deep ancestors of
        // this archive are usually asked about.
        self::assertSame('great-grandson/great-granddaughter', $this->named('GS', '24'));
    }

    // -----------------------------------------------------------------
    // What is not a number
    // -----------------------------------------------------------------

    public function testSomethingThatIsNotANumberSaysWhichFieldIsWrong(): void
    {
        $this->signIn();

        self::assertSame('invalid_a', $this->ask('Bertha', '24/11')['problem']);
        self::assertSame('invalid_b', $this->ask('24/11', 'Bertha')['problem']);
        self::assertNull($this->ask('Bertha', '24/11')['relationship']);
    }

    /** Line 99 is not in the table, so a number in it is not a number. */
    public function testANumberInALineThatDoesNotExistIsNotANumber(): void
    {
        $this->signIn();

        self::assertSame('invalid_a', $this->ask('99/11', '24/11')['problem']);
    }

    public function testTheSamePersonTwiceIsSaidRatherThanNamed(): void
    {
        $this->signIn();

        $answer = $this->ask('24/b521.12', '24/b52112');

        self::assertSame('identical', $answer['problem']);
        self::assertNull($answer['relationship']);
    }

    public function testAnEmptyFieldIsNotAnError(): void
    {
        $this->signIn();

        self::assertSame('incomplete', $this->ask('', '')['problem']);
    }

    /** Dots and spaces are how the number is written, not part of it. */
    public function testTheNumberIsReadAsTheFamilyWritesIt(): void
    {
        $this->signIn();

        self::assertSame($this->named('24/b521.12', '24/b1'), $this->named('24/ b52112', '24/b1'));
    }

    public function testTheCalculatorNeedsASession(): void
    {
        self::assertSame(401, $this->api(RelationshipRead::class, query: ['a' => '24/11', 'b' => '24/12'])->getStatusCode());
    }

    // -----------------------------------------------------------------
    // The words
    // -----------------------------------------------------------------

    public function testTheGermanNamesAreTheFamilysOwn(): void
    {
        $this->signIn();

        I18N::init('de');

        try {
            self::assertSame('Bruder/Schwester', $this->named('24/11', '24/12'));
            self::assertSame('Urgroßvater/Urgroßmutter', $this->named('24/1111', '24/1'));
            self::assertSame('Urgroßonkel/Urgroßtante', $this->named('24/311111', '24/312'));
            self::assertSame('2. Urgroßonkel/2. Urgroßtante', $this->named('24/3111111', '24/312'));
            self::assertSame('Cousin/Cousine 2. Grades', $this->named('24/31111', '24/31211'));
        } finally {
            I18N::init('en-US');
        }
    }

    /**
     * "Urgroßvater", not "Urgroßvater/großmutter".
     *
     * The prefix belongs to each form, not to the pair. Written the other way
     * the answer reads as neither of the two things it is trying to say.
     */
    public function testBothFormsCarryThePrefix(): void
    {
        $this->signIn();

        I18N::init('de');

        try {
            self::assertSame('Großvater/Großmutter', $this->named('24/111', '24/1'));
        } finally {
            I18N::init('en-US');
        }
    }

    // -----------------------------------------------------------------
    // On the card
    // -----------------------------------------------------------------

    /**
     * The case the whole thing is for.
     *
     * Otto (X12) is Dieter's great-grandfather, and the tree cannot say so:
     * the path runs through Ida, who is confidential, and `RelationshipNamer`
     * refuses to name a relationship through somebody the reader may not see
     * (§2.25). Both men carry an archive number, and the number *is* the path,
     * so the answer comes from two strings that were already on both cards.
     *
     * Otto's is a `GS/` number, because he belongs to no line — which is also
     * how a record carrying one is proved to be read at all.
     */
    public function testACardNamesWhatTheTreeWalkCannotReach(): void
    {
        $this->signIn('X4');

        $response = $this->api(IndividualRead::class, attributes: ['xref' => 'X12']);

        self::assertSame(200, $response->getStatusCode());
        self::assertSame('great-grandfather', $this->json($response)['relationship']);
    }

    /**
     * And the tree still answers first where it can.
     *
     * Bertha is Dieter's mother and both have numbers; the answer is webtrees'
     * own, because the tree knows things a number cannot — a stepmother, an
     * adoption, a second marriage.
     */
    public function testTheTreeIsStillAskedFirst(): void
    {
        $this->signIn('X4');

        $response = $this->api(IndividualRead::class, attributes: ['xref' => 'X2']);

        self::assertSame('mother', $this->json($response)['relationship']);
    }

    /**
     * Where a record carries both kinds, the explicit one is used.
     *
     * A bare two-digit number is the head of a line — and it is also what an
     * older, unrelated numbering looks like once it reaches two digits. The
     * one that says out loud what it is wins; a record with only the bare form
     * is still read.
     */
    public function testAnExplicitNumberOutranksABareOneOnTheSameRecord(): void
    {
        $this->signIn('X4');

        $response = $this->api(IndividualRead::class, attributes: ['xref' => 'X12']);

        // Dieter's record carries a bare "9" *before* his "10/1335.21" — the
        // fixture is written that way on purpose. Reading them in document
        // order would make Otto a grand-nephew of the head of line 9.
        self::assertSame('great-grandfather', $this->json($response)['relationship']);
    }

    /** A reader with no number of their own gets the silence they had before. */
    public function testAReaderWithoutANumberIsToldNothing(): void
    {
        // Anna's "4711" is the archive's older numbering and is not a path.
        $this->signIn('X1');

        $response = $this->api(IndividualRead::class, attributes: ['xref' => 'X12']);

        self::assertNull($this->json($response)['relationship']);
    }
}
