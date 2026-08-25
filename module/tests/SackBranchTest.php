<?php

declare(strict_types=1);

namespace Engelking\Webtrees\PortalApi\Tests;

use Engelking\Webtrees\PortalApi\Http\RequestHandlers\IndividualRead;
use Engelking\Webtrees\PortalApi\Services\SackNumbers;
use Fisharebest\Webtrees\Contracts\UserInterface;
use Fisharebest\Webtrees\I18N;
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
    protected function setUp(): void
    {
        parent::setUp();

        // The name now follows the language the request is answered in, and
        // `I18N` is static: without this, whichever language the previous test
        // asked for decides what this one reads. The bootstrap's own is en-US.
        I18N::init('en-US');
    }

    private function numbers(): SackNumbers
    {
        return new SackNumbers($this->module());
    }

    /**
     * The branch as a reader of one language sees it.
     *
     * Said out loud in every one of these rather than left to whatever the
     * harness initialised: the language is half of what is being asserted.
     */
    private function branch(string $number, string $language = 'de'): string|null
    {
        return $this->numbers()->branch($number, $language);
    }

    // -----------------------------------------------------------------
    // Reading a number
    // -----------------------------------------------------------------

    public function testALineNumberIsNamedByTheBranchItSitsIn(): void
    {
        self::assertSame('Ernestinische Linie – Zweig Mansfeld', $this->branch('1/3215.1'));
        self::assertSame('Ernestinische Linie – Zweig Pasewalk', $this->branch('4/12'));
        self::assertSame('Ernestinische Linie – Zweig Dessau', $this->branch('7/22.9'));
        self::assertSame('Ernestinische Linie – Zweig Cleve', $this->branch('10/1335.21'));
        self::assertSame('Ernestinische Linie – Zweig Glogau', $this->branch('19/1'));
        self::assertSame('Ernestinische Linie – Zweig Lübeck', $this->branch('20/1'));
        self::assertSame('Ernestinische Linie – Zweig Rothenhof', $this->branch('24/b6'));
        self::assertSame('Wilhelminische Linie', $this->branch('34/2131.6'));
        self::assertSame('Cramer-Linie', $this->branch('36/141'));
    }

    /** The archive zero-pads in places, and the case is nobody's to remember. */
    public function testHowTheNumberIsWrittenDoesNotChangeItsBranch(): void
    {
        self::assertSame($this->branch('7/22.9'), $this->branch('07/22.9'));
        self::assertSame($this->branch('24/b6'), $this->branch('24/B6'));
        self::assertSame($this->branch('24/b6'), $this->branch(' 24 / b6 '));
    }

    // -----------------------------------------------------------------
    // The name, in the language it is being read in
    // -----------------------------------------------------------------

    /**
     * A branch name is a phrase, not only a place.
     *
     * "Zweig Rothenhof" against "Rothenhof Branch": the place in the middle
     * does not change, and everything around it does. So the family writes
     * both, in one row, and the reader gets the one they can read — §2.17, the
     * rule the fact labels and the dates already follow.
     */
    public function testTheNameFollowsTheLanguageItIsReadIn(): void
    {
        self::assertSame('Ernestinische Linie – Zweig Rothenhof', $this->branch('24/b6', 'de'));
        self::assertSame('Ernestine Line – Rothenhof Branch', $this->branch('24/b6', 'en'));

        self::assertSame('Wilhelminische Linie', $this->branch('34/2131.6', 'de'));
        self::assertSame('Wilhelmine Line', $this->branch('34/2131.6', 'en'));

        self::assertSame('Nachkommen von Georg Sack', $this->branch('GS/755133', 'de'));
        self::assertSame('Descendants of Georg Sack', $this->branch('GS/755133', 'en'));
    }

    /**
     * webtrees has four Englishes and one German, and the family is not going
     * to write a name for each. A tag matches the language, and the country
     * after it only has to be answered where somebody wrote one.
     */
    public function testACountrysEnglishGetsTheEnglishName(): void
    {
        self::assertSame('Ernestine Line – Cleve Branch', $this->branch('10/1335.21', 'en-GB'));
        self::assertSame('Ernestine Line – Cleve Branch', $this->branch('10/1335.21', 'en-US'));
        self::assertSame('Ernestine Line – Cleve Branch', $this->branch('10/1335.21', 'EN'));
    }

    /**
     * **A half-translated table still answers everybody.**
     *
     * A branch added on a Tuesday evening has one name until somebody writes
     * the other, and a reader of the second language must get *that* name
     * rather than nothing — a missing branch reads as "we do not know where
     * you are from", which is a worse thing to say than saying it in German.
     */
    public function testALanguageWithNoNameOfItsOwnGetsTheOneThatWasWritten(): void
    {
        $this->module()->setPreference(
            SackNumbers::SETTING_BRANCHES,
            "8-14 = Zweig Cleve\n21-31 = Zweig Rothenhof | en: Rothenhof Branch"
        );

        self::assertSame('Zweig Cleve', $this->branch('10/1335.21', 'de'));
        self::assertSame('Zweig Cleve', $this->branch('10/1335.21', 'en'));

        // French is nobody's portal language, and answering in German is still
        // better than answering not at all.
        self::assertSame('Rothenhof Branch', $this->branch('24/b6', 'en'));
        self::assertSame('Zweig Rothenhof', $this->branch('24/b6', 'fr'));
    }

    /**
     * A part after the first that names no language is dropped rather than
     * shown: it would otherwise turn up as somebody's branch in a language
     * they are not reading, which is how a table like this quietly goes wrong.
     */
    public function testAnUntaggedSecondNameIsNotAName(): void
    {
        $this->module()->setPreference(
            SackNumbers::SETTING_BRANCHES,
            "8-14 = Zweig Cleve | Cleve Branch | zz: Zweig Zett | en: Cleve Branch"
        );

        self::assertSame('Zweig Cleve', $this->branch('10/1335.21', 'de'));
        self::assertSame('Cleve Branch', $this->branch('10/1335.21', 'en'));

        // "zz" is shaped like a language tag and is not one webtrees has; it
        // simply never matches anybody.
        self::assertSame('Zweig Cleve', $this->branch('10/1335.21', 'fr'));
    }

    /**
     * A number is the head of its line, and that is still somebody in the
     * branch — the oblique with nothing after it is how the archive writes it.
     */
    public function testTheHeadOfALineIsInThatLinesBranch(): void
    {
        self::assertSame('Ernestinische Linie – Zweig Rothenhof', $this->branch('24/'));
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

        self::assertSame('Nachkommen von Georg Sack', $this->branch('GS/755133'));
        self::assertSame('Nachkommen von Heinrich Sack', $this->branch('HS/12'));
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
        self::assertNull($this->branch('24'), 'Could be line 24, could be the old numbering.');
        self::assertNull($this->branch('9'));
        self::assertNull($this->branch('4711'));
        self::assertNull($this->branch(''));

        // Three digits in front of the oblique is not a line either.
        self::assertNull($this->branch('101/335.21'));

        // A line that no branch covers, which is what an unmaintained table
        // looks like from here.
        self::assertNull($this->branch('99/1'));
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
            $this->branch('10/1335.21!')
        );
    }

    // -----------------------------------------------------------------
    // The table is the family's, not the software's
    // -----------------------------------------------------------------

    public function testTheFamilyCanRenameAndRegroupItsBranches(): void
    {
        $this->module()->setPreference(SackNumbers::SETTING_BRANCHES, "8-9 = Zweig Kleve\n10-14 = Zweig Wesel");

        self::assertSame('Zweig Kleve', $this->branch('9/1'));
        self::assertSame('Zweig Wesel', $this->branch('10/1335.21'));

        // The rest of the table went with it, because the table *is* the list.
        self::assertNull($this->branch('24/b6'));
    }

    /** One bad row is a typo in an evening's news, not a broken portal. */
    public function testARowThatMakesNoSenseIsDroppedRatherThanFatal(): void
    {
        $this->module()->setPreference(
            SackNumbers::SETTING_BRANCHES,
            "# a note\nnonsense\n14-8 = backwards\n8-14 = Zweig Cleve\n= nameless\n20 =\n"
        );

        self::assertSame('Zweig Cleve', $this->branch('10/1335.21'));
        self::assertNull($this->branch('20/1'));
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
        $references = $this->referencesOfX4('de');

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

    /**
     * And the whole way through, not only in the service.
     *
     * The header is what the portal sends on every request, so this is the
     * same path a member reading the portal in English actually takes.
     */
    public function testTheBranchOnTheRecordIsInTheLanguageAsked(): void
    {
        self::assertSame(
            [null, null, 'Ernestine Line – Cleve Branch', 'Ernestine Line – Dessau Branch'],
            array_column($this->referencesOfX4('en'), 'branch')
        );
    }

    /**
     * X4 is the one with four numbers, two of them paths in two different
     * branches — the archive numbered him twice.
     *
     * @return array<int,array<string,string|null>>
     */
    private function referencesOfX4(string $language): array
    {
        $this->login($this->createUser('anna', 'Anna Beispiel', 'geheim', UserInterface::ROLE_MEMBER, 'X1'));

        $response = $this->api(
            IndividualRead::class,
            attributes: ['xref' => 'X4'],
            headers: ['Accept-Language' => $language],
        );

        return $this->json($response)['references'];
    }
}
