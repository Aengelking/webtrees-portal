<?php

declare(strict_types=1);

namespace Engelking\Webtrees\PortalApi\Tests;

use Engelking\Webtrees\PortalApi\Http\RequestHandlers\IndividualRead;
use Engelking\Webtrees\PortalApi\Services\SackNumbers;
use Fisharebest\Webtrees\Contracts\UserInterface;
use PHPUnit\Framework\Attributes\CoversNothing;

use function array_column;

/**
 * The branch, which is the part of the number a member actually says.
 *
 * The archive files people by line — 36 of them — but the family talks in
 * branches: lines 8 to 14 together are the Cleve branch, 21 to 31 the
 * Rothenhof one. "Zweig Rothenhof" is the answer to where somebody comes
 * from; "line 27" is the drawer it is filed in.
 *
 * The name is a reading of the number and of nothing else, which is what makes
 * it safe: it discloses no record, and a number the reader may not see is not
 * in the response to be read.
 */
#[CoversNothing]
class SackBranchTest extends PortalTestCase
{
    private function numbers(): SackNumbers
    {
        return new SackNumbers($this->module());
    }

    // -----------------------------------------------------------------
    // Reading a number
    // -----------------------------------------------------------------

    public function testALineNumberIsNamedByTheBranchItSitsIn(): void
    {
        $numbers = $this->numbers();

        self::assertSame('Ernestinische Linie – Zweig Mansfeld', $numbers->branch('1/3215.1'));
        self::assertSame('Ernestinische Linie – Zweig Pasewalk', $numbers->branch('4/12'));
        self::assertSame('Ernestinische Linie – Zweig Dessau', $numbers->branch('7/22.9'));
        self::assertSame('Ernestinische Linie – Zweig Cleve', $numbers->branch('10/1335.21'));
        self::assertSame('Ernestinische Linie – Zweig Glogau', $numbers->branch('19/1'));
        self::assertSame('Ernestinische Linie – Zweig Lübeck', $numbers->branch('20/1'));
        self::assertSame('Ernestinische Linie – Zweig Rothenhof', $numbers->branch('24/b6'));
        self::assertSame('Wilhelminische Linie', $numbers->branch('34/2131.6'));
        self::assertSame('Cramer-Linie', $numbers->branch('36/141'));
    }

    /** The archive zero-pads in places, and the case is nobody's to remember. */
    public function testHowTheNumberIsWrittenDoesNotChangeItsBranch(): void
    {
        $numbers = $this->numbers();

        self::assertSame($numbers->branch('7/22.9'), $numbers->branch('07/22.9'));
        self::assertSame($numbers->branch('24/b6'), $numbers->branch('24/B6'));
        self::assertSame($numbers->branch('24/b6'), $numbers->branch(' 24 / b6 '));
    }

    /**
     * A number is the head of its line, and that is still somebody in the
     * branch — the oblique with nothing after it is how the archive writes it.
     */
    public function testTheHeadOfALineIsInThatLinesBranch(): void
    {
        self::assertSame('Ernestinische Linie – Zweig Rothenhof', $this->numbers()->branch('24/'));
    }

    /**
     * `GS` and `HS` are heads of their own, not lines.
     *
     * `HS` is a numbering the relationship calculator does not read at all —
     * which is exactly why the branch is taken from what is *written* rather
     * than from a resolved path. A member carrying one still gets told where
     * they are from.
     */
    public function testTheTwoHeadsThatAreNotLinesAreNamedToo(): void
    {
        $numbers = $this->numbers();

        self::assertSame('Nachkommen von Georg Sack', $numbers->branch('GS/755133'));
        self::assertSame('Nachkommen von Heinrich Sack', $numbers->branch('HS/12'));
        self::assertNull($numbers->path('HS/12'), 'HS is not a path the calculator reads.');
    }

    /**
     * **A number without an oblique gets no branch**, and that is the point.
     *
     * A bare two-digit number is also what the archive's older, unrelated
     * numbering looks like once it reaches two digits — see §2.57 — so reading
     * "24" as line 24 would print a branch on records that have nothing to do
     * with it. Naming the wrong branch on somebody's own record is a worse
     * failure than naming none.
     */
    public function testANumberWithoutAnObliqueIsNotRead(): void
    {
        $numbers = $this->numbers();

        self::assertNull($numbers->branch('24'), 'Could be line 24, could be the old numbering.');
        self::assertNull($numbers->branch('9'));
        self::assertNull($numbers->branch('4711'));
        self::assertNull($numbers->branch(''));

        // Three digits in front of the oblique is not a line either.
        self::assertNull($numbers->branch('101/335.21'));

        // A line that no branch covers, which is what an unmaintained table
        // looks like from here.
        self::assertNull($numbers->branch('99/1'));
    }

    /**
     * The marker on the end says "the spouse of this number", and the archive
     * files that person in that branch. webtrees says the same, and the portal
     * agreeing with the back office is worth more here than a finer reading.
     */
    public function testTheSpouseMarkerDoesNotChangeTheBranch(): void
    {
        self::assertSame(
            'Ernestinische Linie – Zweig Cleve',
            $this->numbers()->branch('10/1335.21!')
        );
    }

    // -----------------------------------------------------------------
    // The table is the family's, not the software's
    // -----------------------------------------------------------------

    public function testTheFamilyCanRenameAndRegroupItsBranches(): void
    {
        $this->module()->setPreference(SackNumbers::SETTING_BRANCHES, "8-9 = Zweig Kleve\n10-14 = Zweig Wesel");

        $numbers = $this->numbers();

        self::assertSame('Zweig Kleve', $numbers->branch('9/1'));
        self::assertSame('Zweig Wesel', $numbers->branch('10/1335.21'));

        // The rest of the table went with it, because the table *is* the list.
        self::assertNull($numbers->branch('24/b6'));
    }

    /** One bad row is a typo in an evening's news, not a broken portal. */
    public function testARowThatMakesNoSenseIsDroppedRatherThanFatal(): void
    {
        $this->module()->setPreference(
            SackNumbers::SETTING_BRANCHES,
            "# a note\nnonsense\n14-8 = backwards\n8-14 = Zweig Cleve\n= nameless\n20 =\n"
        );

        $numbers = $this->numbers();

        self::assertSame('Zweig Cleve', $numbers->branch('10/1335.21'));
        self::assertNull($numbers->branch('20/1'));
    }

    // -----------------------------------------------------------------
    // What a member sees
    // -----------------------------------------------------------------

    /**
     * X4 carries four numbers, two of which are paths — and they are in two
     * different branches, because the archive numbered him twice. Both are
     * true, so the record says both; the other two say nothing.
     */
    public function testTheBranchTravelsWithTheNumberOnTheRecord(): void
    {
        $this->login($this->createUser('anna', 'Anna Beispiel', 'geheim', UserInterface::ROLE_MEMBER, 'X1'));

        $references = $this->json($this->api(IndividualRead::class, attributes: ['xref' => 'X4']))['references'];

        self::assertSame(['9', '4714', '10/1335.21', '7/22.9'], array_column($references, 'number'));
        self::assertSame(
            [
                null,
                null,
                'Ernestinische Linie – Zweig Cleve',
                'Ernestinische Linie – Zweig Dessau',
            ],
            array_column($references, 'branch')
        );
    }
}
