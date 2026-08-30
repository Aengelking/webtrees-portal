<?php

declare(strict_types=1);

namespace Engelking\Webtrees\PortalApi\Tests;

use Engelking\Webtrees\PortalApi\Http\RequestHandlers\IndividualRead;
use Engelking\Webtrees\PortalApi\Http\RequestHandlers\RelationshipRead;
use Engelking\Webtrees\PortalApi\Services\SackNumbers;
use Engelking\Webtrees\PortalApi\Services\SackRelationship;
use Fisharebest\Webtrees\Contracts\UserInterface;
use Fisharebest\Webtrees\I18N;
use Fisharebest\Webtrees\Registry;
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

    /** @return array<int,string> */
    private function allNamed(string $a, string $b): array
    {
        return $this->ask($a, $b)['relationships'];
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
        // English repeats the word where German counts the "Ur"s.
        self::assertSame('great-great-grandfather/great-great-grandmother', $this->named('24/11111', '24/1'));
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
        self::assertSame('grand-uncle/grand-aunt', $this->named('24/31111', '24/312'));
    }

    /**
     * The degree, which is what a family actually argues about.
     *
     * A first cousin is just "cousin" in both languages — neither says "first"
     * — and everything beyond it counts. Where German appends the degree
     * ("Cousine 2. Grades"), English puts an ordinal in front of the word
     * itself, which is the same fact said in the shape the language has for
     * it.
     */
    public function testCousinsAreCountedByHowFarBackTheyShare(): void
    {
        $this->signIn();

        self::assertSame('cousin', $this->named('24/3111', '24/3121'));
        self::assertSame('second cousin', $this->named('24/31111', '24/31211'));
        self::assertSame('third cousin', $this->named('24/311111', '24/312111'));
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

    /**
     * **A number is one line of descent, and a person can have several.**
     *
     * `24/313` and `24/b6` married, so a person filed under his number is
     * filed under hers too: `24/3133.42` is equally `24/b63.42`. Measured the
     * stored way against `24/B521.12` they are fifth cousins; measured the
     * other way they are third cousins once removed — and the second is not a
     * missing extra, it is the *nearer* and truer answer. The calculator has
     * been naming the relationship of whichever writing the archive happened
     * to file, which for this pair is the wrong one.
     */
    public function testEveryWritingOfANumberIsMeasured(): void
    {
        $this->signIn();

        self::assertSame(
            ['third cousin once removed', 'fifth cousin'],
            $this->allNamed('24/3133.42', '24/B521.12'),
        );
    }

    /** And the nearest of them is the one answer a caller asking for one gets. */
    public function testTheNearestWritingIsTheAnswer(): void
    {
        $this->signIn();

        self::assertSame('third cousin once removed', $this->named('24/3133.42', '24/B521.12'));
    }

    /**
     * A pair with no marriage above either of them has exactly one answer, and
     * it is the one it always had.
     */
    public function testAPairUntouchedByAnyMarriageIsUnchanged(): void
    {
        $this->signIn();

        self::assertSame(['nephew/niece'], $this->allNamed('24/312', '24/3111'));
    }

    /**
     * The trap this walked into once, kept because it is the whole reason the
     * pairing rule exists.
     *
     * An alternative writing replaces the child index with the join character
     * `-`, so re-rooting *both* people at the same marriage erases exactly
     * what told them apart and they arrive at the same string. `24/b61` and
     * `24/3132` are siblings; crossed alternative-against-alternative they
     * came out as one person. One side is therefore always the stored number.
     */
    public function testTwoDescendantsOfOneCoupleAreNotOnePerson(): void
    {
        $this->signIn();

        self::assertSame('brother/sister', $this->named('24/b61', '24/3132'));
    }

    /**
     * How far the expansion can run. Measured against the family's own table
     * of sixty marriages the worst case is six writings, so the cap is a guard
     * against a table edited into a cycle rather than a limit anybody meets.
     */
    public function testTheExpansionStaysSmallOnTheFamilysOwnTable(): void
    {
        $numbers = new SackNumbers($this->module());
        $worst   = 0;

        foreach ($numbers->marriages() as ['right' => $right]) {
            foreach (['1', '21', '321'] as $tail) {
                $worst = max($worst, count($numbers->writings($right . $tail)));
            }
        }

        self::assertGreaterThan(1, $worst, 'The table should produce alternatives at all.');
        self::assertLessThanOrEqual(8, $worst);
    }

    public function testWithoutTheTableTheSamePairLooksLikeSomethingElse(): void
    {
        $this->signIn();

        // One row, which does not mention either of them.
        $this->module()->setPreference(SackNumbers::SETTING_MARRIAGES, '09/23 = 09/32');

        // And in English a nephew of some degree is not a nephew at all:
        // the language turns him into a cousin, counted and removed.
        self::assertSame('second cousin once removed', $this->named('24/b61', '24/3132'));
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
     *
     * Dieter descends from the family by more than one line, so since §2.87
     * the card names the other way they are related too. What is pinned here
     * is the answer this test was written for, and that it leads.
     */
    public function testACardNamesWhatTheTreeWalkCannotReach(): void
    {
        $this->signIn('X4');

        $response = $this->api(IndividualRead::class, attributes: ['xref' => 'X12']);

        self::assertSame(200, $response->getStatusCode());
        self::assertStringStartsWith('great-grandfather', $this->json($response)['relationship']);
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
        //
        // Since §2.87 a card names every way two people are related, so this
        // asserts what it always meant rather than what it could get away with
        // while only one number was ever read: the explicit number leads, and
        // the doubtful one is not heard from at all.
        $relationship = $this->json($response)['relationship'];

        self::assertStringStartsWith('great-grandfather', $relationship);
        self::assertStringNotContainsString('nephew', $relationship);
    }

    /** A reader with no number of their own gets the silence they had before. */
    public function testAReaderWithoutANumberIsToldNothing(): void
    {
        // Anna's "4711" is the archive's older numbering and is not a path.
        $this->signIn('X1');

        $response = $this->api(IndividualRead::class, attributes: ['xref' => 'X12']);

        self::assertNull($this->json($response)['relationship']);
    }

    // -----------------------------------------------------------------
    // English is a different system, not a translation
    // -----------------------------------------------------------------

    /**
     * **The family's own calculator, run beside this one over every shape.**
     *
     * German counts a collateral relative by degree and keeps the near word —
     * *Großneffe 2. Grades*, *Onkel 3. Grades*. English has no such phrase: it
     * turns both into cousins, counted by how far back the shared ancestor is
     * and how many generations apart the two people stand. Translating word
     * for word produced "nephew (second degree)", which is not English, and a
     * member said so.
     *
     * So the rule is not read off the German. It is `rechner.php`'s
     * `$relation_en`, transcribed here and run against the module over a grid
     * of every generation distance the classifier can produce. The shapes are
     * read back out of `between()` rather than assumed, so the comparison is
     * of two namings of the same measured relationship and not of two guesses.
     *
     * Compared in the male form only: the calculator writes its pairs
     * inconsistently ("father/mother" but "grandson/daughter"), and pair
     * formatting is not what this is about.
     */
    public function testEveryShapeIsNamedAsTheFamilysCalculatorNamesIt(): void
    {
        $this->signIn();

        $sack     = Registry::container()->get(SackRelationship::class);
        $compared = 0;

        for ($generations = -4; $generations <= 4; $generations++) {
            for ($distance = max(0, $generations); $distance <= 6; $distance++) {
                $a = '24/' . '1' . ($distance >= 1 ? '2' . str_repeat('1', $distance - 1) : '');
                $b = '24/' . '1' . ($distance - $generations >= 1
                    ? '3' . str_repeat('1', $distance - $generations - 1)
                    : '');

                $shape = $sack->between($a, $b);

                if ($shape === null || $shape['kind'] === 'self') {
                    continue;
                }

                self::assertSame(
                    $this->calculator($shape['generations'], $shape['distance']),
                    $sack->describe($shape, 'M'),
                    $a . ' → ' . $b . ' (' . $shape['kind'] . ', generations '
                        . $shape['generations'] . ', distance ' . $shape['distance'] . ')'
                );

                $compared++;
            }
        }

        self::assertGreaterThan(30, $compared, 'The grid produced almost nothing to compare.');
    }

    /**
     * `rechner.php`, `$relation_en`, in the male form.
     *
     * Transcribed rather than imported: the calculator is one PHP file with a
     * form in it, and what is worth keeping is the rule, not the page.
     */
    private function calculator(int $generations, int $distance): string
    {
        $removed = false;

        if ($generations === 0 && $distance === 1) {
            $name = 'brother';
        } elseif ($distance === $generations) {
            $name = ($generations > 2 ? str_repeat('great-', $generations - 2) : '')
                . ($generations > 1 ? 'grand' : '') . 'father';
        } elseif ($distance === 0) {
            $name = $generations === -1
                ? 'son'
                : ($generations < -2 ? str_repeat('great-', -$generations - 2) : '') . 'grandson';
        } elseif ($generations < 0) {
            $name = ($generations < -2 ? str_repeat('great-', -$generations - 2) : '')
                . ($generations < -1 ? 'grand-' : '') . 'nephew';
            $removed = $distance > 1;
        } elseif ($generations === 0) {
            $name = $distance > 2 ? $this->countName($distance - 1) . ' cousin' : 'cousin';
        } else {
            $name = ($generations > 2 ? str_repeat('great-', $generations - 2) : '')
                . ($generations > 1 ? 'grand-' : '') . 'uncle';
            $removed = $distance - $generations > 1;
        }

        if ($removed) {
            $name = ($distance > 2 ? $this->countName($distance - 1) . ' ' : '') . 'cousin';

            if (abs($generations) > 0) {
                $name .= ' ' . $this->multiName(abs($generations)) . ' removed';
            }
        }

        return $name;
    }

    private function countName(int $number): string
    {
        // "fith" in the original is a typo, and copying a typo into a portal
        // is not fidelity.
        $words = ['first', 'second', 'third', 'fourth', 'fifth', 'sixth', 'seventh', 'eighth', 'ninth', 'tenth'];

        return $words[$number - 1] ?? $number . 'th';
    }

    private function multiName(int $number): string
    {
        $words = ['once', 'twice', 'thrice', 'four times', 'five times'];

        return $words[$number - 1] ?? $number . ' times';
    }
}
