<?php

declare(strict_types=1);

namespace Engelking\Webtrees\PortalApi\Tests;

use Engelking\Webtrees\PortalApi\Services\FamilyMarriages;
use Engelking\Webtrees\PortalApi\Services\SackNumbers;
use Fisharebest\Webtrees\Registry;
use PHPUnit\Framework\Attributes\CoversNothing;

use function array_column;
use function array_combine;

/**
 * The table of in-family marriages, checked against the tree that it is about.
 *
 * §2.94 made this table matter more than it did. It used to decide whether one
 * calculation came out right; it now decides whether *every* descendant of a
 * couple has a second archive number, and therefore whether the portal names
 * the near relationship or a more distant one. A row that is missing gives no
 * warning at all — it produces a confident answer that is simply too far.
 *
 * So the scan reads the tree and says where the two disagree. What it must get
 * right is which of the two parents the children were filed under, because a
 * row written the other way round matches nobody and does nothing, while
 * looking for all the world like it is there.
 *
 * The fixture carries three couples for this and nothing else uses them:
 * *Doppelt* (`24/313` × `24/b6`, child `24/3133`), *Zweifach* (`24/511` ×
 * `24/c2`, child `24/5113`) and *Stumm*, who have no children at all.
 *
 * Two more for the missing `!` (§2.101), where both partners carry one
 * number: *Angeheiratet*, where Gerhard has a father in the tree and Ida has
 * nobody, so the mark is hers; and *Unklar*, where neither has parents and
 * the records do not say.
 */
#[CoversNothing]
class FamilyMarriagesTest extends PortalTestCase
{
    private function scan(): FamilyMarriages
    {
        return Registry::container()->get(FamilyMarriages::class);
    }

    /** The state of every couple the scan found, by family xref. */
    private function states(): array
    {
        $rows = $this->scan()->scan()['rows'];

        return array_combine(array_column($rows, 'xref'), array_column($rows, 'state'));
    }

    /** @return array<string,mixed> */
    private function rowFor(string $xref): array
    {
        foreach ($this->scan()->scan()['rows'] as $row) {
            if ($row['xref'] === $xref) {
                return $row;
            }
        }

        self::fail('The scan has no ' . $xref . '.');
    }

    private function table(string $text): void
    {
        $this->module()->setPreference(SackNumbers::SETTING_MARRIAGES, $text);
    }

    // -----------------------------------------------------------------
    // What the scan is looking for
    // -----------------------------------------------------------------

    /**
     * Both partners have to carry a number. A couple where only one does has
     * nothing hidden: their children have one number and it says everything.
     */
    public function testOnlyCouplesWhereBothHaveANumberAreListed(): void
    {
        $found = $this->states();

        self::assertArrayHasKey('F6', $found);
        self::assertArrayHasKey('F7', $found);

        // Anna's parents are in the tree with no readable archive number
        // between them, so their family is not one of these marriages.
        self::assertArrayNotHasKey('F1', $found);
    }

    // -----------------------------------------------------------------
    // What the table makes of them
    // -----------------------------------------------------------------

    /**
     * A row pointing the way the records do. `24/b6 = 24/313` says
     * "descendants under 313 also descend from b6", and Wilhelm is `24/3133`,
     * so that is exactly right.
     */
    public function testARowThatPointsTheWayTheRecordsDoIsRight(): void
    {
        $this->table('24/b6 = 24/313');

        self::assertSame('recorded', $this->states()['F6']);
    }

    /**
     * The finding that would otherwise hide in plain sight: the same pair,
     * written the other way round. It reads like the marriage is recorded and
     * it will never match a single descendant.
     */
    public function testTheSamePairWrittenBackwardsIsNotRecorded(): void
    {
        $this->table('24/313 = 24/b6');

        self::assertSame('wrong_way', $this->states()['F6']);
    }

    public function testAMarriageTheTableDoesNotKnowIsMissing(): void
    {
        $this->table('24/b6 = 24/313');

        self::assertSame('missing', $this->states()['F7']);
    }

    /**
     * The row to paste, in the order the table is written: the parent the
     * children are *not* filed under, then the one they are.
     */
    public function testTheSuggestionNamesTheOtherParentFirst(): void
    {
        $this->table('24/b6 = 24/313');

        foreach ($this->scan()->scan()['rows'] as $row) {
            if ($row['xref'] === 'F7') {
                self::assertSame('24/c2 = 24/511', $row['suggestion']);
                self::assertSame('24/511', $row['filed_under']);

                return;
            }
        }

        self::fail('The Zweifach family is not in the scan.');
    }

    /**
     * Where no child carries a number there is nothing to read, and the scan
     * says so instead of choosing a side. Guessing here would write a row that
     * silently does nothing, which is the failure this screen exists to find.
     */
    public function testACoupleWithoutNumberedChildrenCannotBeRead(): void
    {
        $this->table('24/b6 = 24/313');

        self::assertSame('unclear', $this->states()['F8']);
    }

    // -----------------------------------------------------------------
    // A couple sharing one number
    // -----------------------------------------------------------------

    /**
     * The finding that matters most, because it is not a marriage at all.
     *
     * Both partners carry `24/911`. That is one person's number written on
     * two people, and until the `!` goes back on, Ida is read as a descendant
     * of a line she married into.
     */
    public function testACoupleSharingOneNumberIsNotReadAsAMarriage(): void
    {
        self::assertSame('unmarked', $this->states()['F9']);
    }

    /** Ida has no parents in the archive, so the mark is hers. */
    public function testTheMarkGoesOnTheOneWithNoParents(): void
    {
        $marks = $this->rowFor('F9')['marks'];

        self::assertSame('Ida Angeheiratet', $marks['name']);
        self::assertSame('24/911', $marks['number']);
    }

    /**
     * Rudolf and Berta share a number and neither has parents recorded, so
     * which of them married in is not something the records say. Guessing
     * would not fail — it would quietly make each of them the other.
     */
    public function testWhereTheRecordsDoNotSayNobodyIsMarked(): void
    {
        self::assertSame('unmarked_stuck', $this->states()['F11']);
        self::assertNull($this->rowFor('F11')['marks']);
    }

    /**
     * A properly marked spouse is not a finding. `10/1335.21!` is not a path
     * — the `!` is the whole point — so the couple never reaches this scan.
     */
    public function testACoupleAlreadyMarkedIsNotListed(): void
    {
        self::assertArrayNotHasKey('F2', $this->states());
    }

    // -----------------------------------------------------------------
    // The screen
    // -----------------------------------------------------------------

    /** Rendered with what the action passes, so a dropped variable is noticed. */
    public function testTheScreenRenders(): void
    {
        $this->table('24/b6 = 24/313');

        $scan = $this->scan()->scan();

        $html = view('_portal_api_::marriages', [
            'title'         => 'Marriages inside the family',
            'module'        => $this->module(),
            'tree'          => $this->tree,
            'rows'          => $scan['rows'],
            'truncated'     => $scan['truncated'],
            'counts'        => ['unmarked' => 1, 'unmarked_stuck' => 1, 'recorded' => 1, 'wrong_way' => 0, 'missing' => 1, 'unclear' => 1],
            'may_mark'      => true,
            'settings_url'  => '/settings',
        ]);

        self::assertStringContainsString('24/c2 = 24/511', $html);
        self::assertStringContainsString('Doppelt', $html);

        // The correction, and the number it would be written against.
        self::assertStringContainsString('Ida Angeheiratet', $html);
        self::assertStringContainsString('24/911!', $html);

        // The pair the records cannot decide gets no button at all.
        self::assertStringContainsString('Rudolf Unklar', $html);
    }
}
