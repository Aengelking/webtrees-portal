<?php

declare(strict_types=1);

namespace Engelking\Webtrees\PortalApi\Tests;

use Engelking\Webtrees\PortalApi\PortalApiModule;
use Engelking\Webtrees\PortalApi\Services\PortalTreeService;
use Engelking\Webtrees\PortalApi\Services\SpouseMarker;
use Fig\Http\Message\RequestMethodInterface;
use Fisharebest\Webtrees\Auth;
use Fisharebest\Webtrees\Contracts\UserInterface;
use Fisharebest\Webtrees\DB;
use Fisharebest\Webtrees\Individual;
use Fisharebest\Webtrees\Registry;
use Fisharebest\Webtrees\User;
use PHPUnit\Framework\Attributes\CoversNothing;
use ReflectionClass;

use function array_fill;
use function array_filter;
use function file_get_contents;
use function is_string;
use function str_contains;

/**
 * Putting the `!` back on the number of a partner who married in.
 *
 * The one thing in this corner that writes, so the questions are about what
 * it writes and what it refuses. Ida Angeheiratet carries `24/911`, the same
 * number as her husband, and has no parents in the archive — see
 * `FamilyMarriagesTest` for how that is read.
 */
#[CoversNothing]
class SpouseMarkerTest extends PortalTestCase
{
    private const string IDA = 'X30';

    /** Rudolf and Berta, who share `24/922` and whom the records do not decide. */
    private const string RUDOLF = 'X31';
    private const string BERTA = 'X32';

    private function marker(): SpouseMarker
    {
        return Registry::container()->get(SpouseMarker::class);
    }

    private function ida(): Individual
    {
        $tree       = Registry::container()->get(PortalTreeService::class)->tree();
        $individual = Registry::individualFactory()->make(self::IDA, $tree);

        self::assertInstanceOf(Individual::class, $individual);

        return $individual;
    }

    /** The record as it is queued, which is not the record as it stands. */
    private function queued(string $xref): string|null
    {
        $row = DB::table('change')
            ->where('gedcom_id', '=', $this->tree->id())
            ->where('xref', '=', $xref)
            ->where('status', '=', 'pending')
            ->orderBy('change_id', 'desc')
            ->first();

        return $row === null ? null : $row->new_gedcom;
    }

    /** The record as the tree stores it, pending changes not applied. */
    private function stored(string $xref): string
    {
        return (string) DB::table('individuals')
            ->where('i_file', '=', $this->tree->id())
            ->where('i_id', '=', $xref)
            ->value('i_gedcom');
    }

    private function signInAsManager(bool $auto_accept = false): User
    {
        $manager = $this->createUser('chef', 'Die Chefin', 'correct-horse', UserInterface::ROLE_MEMBER);

        $this->tree->setUserPreference($manager, UserInterface::PREF_TREE_ROLE, UserInterface::ROLE_MANAGER);
        $manager->setPreference(UserInterface::PREF_AUTO_ACCEPT_EDITS, $auto_accept ? '1' : '0');

        Auth::login($manager);

        return $manager;
    }

    // -----------------------------------------------------------------
    // What it writes
    // -----------------------------------------------------------------

    public function testTheMarkIsAddedToTheNumberItWasAskedAbout(): void
    {
        $this->signInAsManager();

        self::assertSame(SpouseMarker::MARKED, $this->marker()->mark(self::IDA, '24/911'));

        $queued = (string) $this->queued(self::IDA);

        self::assertStringContainsString("1 REFN 24/911!\n", $queued . "\n");
    }

    /**
     * **It queues; it does not apply.** An administrator sees the before and
     * the after in webtrees and decides. A mark that went straight in would
     * change who a person is descended from with nobody having looked.
     *
     * Asserted against the stored record and not against `gedcom()`, which
     * for somebody who may approve changes already shows the pending version
     * — the first draft of this test read that back and would have passed
     * against a tool that wrote straight through.
     */
    public function testTheRecordItselfIsNotChanged(): void
    {
        $this->signInAsManager();

        $this->marker()->mark(self::IDA, '24/911');

        self::assertStringNotContainsString('24/911!', $this->stored(self::IDA));
        self::assertNotNull($this->queued(self::IDA));
    }

    /**
     * **The other end of webtrees' edit path, which the first draft of this
     * class quietly promised would never happen.**
     *
     * `updateRecord()` queues the change *and then accepts it on the spot* if
     * the signed-in user has "automatically accept changes" set. So a manager
     * with that switch on sees the mark go live immediately, and a screen
     * telling them it is waiting for approval would be describing somebody
     * else's account. Both ends are pinned here because only one of them was
     * ever going to be exercised by accident.
     */
    public function testAManagerWhoAcceptsTheirOwnChangesGetsThemAtOnce(): void
    {
        $this->signInAsManager(true);

        self::assertSame(SpouseMarker::APPLIED, $this->marker()->mark(self::IDA, '24/911'));
        self::assertStringContainsString('24/911!', $this->stored(self::IDA));
    }

    /**
     * The `2 TYPE SB` under the number says what kind of number it is. The
     * value is replaced, not the fact rebuilt, so that line stays put — a
     * correction that quietly dropped it would be a worse edit than the one
     * it fixed.
     */
    public function testWhatHangsUnderTheNumberSurvives(): void
    {
        $this->signInAsManager();

        $this->marker()->mark(self::IDA, '24/911');

        self::assertStringContainsString("1 REFN 24/911!\n2 TYPE SB", (string) $this->queued(self::IDA));
    }

    // -----------------------------------------------------------------
    // What it refuses
    // -----------------------------------------------------------------

    /**
     * The screen was rendered from a scan that may be minutes old. A mark
     * written against a number that is no longer on the record would land on
     * whatever is in that slot now.
     */
    public function testANumberTheRecordNoLongerCarriesIsRefused(): void
    {
        $this->signInAsManager();

        self::assertSame(SpouseMarker::NO_NUMBER, $this->marker()->mark(self::IDA, '24/999'));
        self::assertNull($this->queued(self::IDA));
    }

    public function testAPersonWhoIsNotThereIsRefused(): void
    {
        $this->signInAsManager();

        self::assertSame(SpouseMarker::NO_PERSON, $this->marker()->mark('X999', '24/911'));
    }

    /**
     * Twice is not an error, and it is not a second `!` either.
     *
     * The sequence this is really about: the mark was accepted, and somebody
     * opens the screen they still had loaded and presses the button again. It
     * is applied rather than queued here — an auto-accepting manager — because
     * a mark still sitting in the queue is refused earlier, as `PENDING`.
     */
    public function testANumberThatAlreadyCarriesTheMarkIsLeftAlone(): void
    {
        $this->signInAsManager(true);

        self::assertSame(SpouseMarker::APPLIED, $this->marker()->mark(self::IDA, '24/911'));
        self::assertSame(SpouseMarker::ALREADY_MARKED, $this->marker()->mark(self::IDA, '24/911'));
    }

    /**
     * A second queued change would be built from the approved record and would
     * discard the first once an editor applied them in order. The same
     * reasoning as `GedcomEditor`, and the same answer.
     */
    public function testARecordWithAChangeAlreadyWaitingIsRefused(): void
    {
        $this->signInAsManager();

        self::assertSame(SpouseMarker::MARKED, $this->marker()->mark(self::IDA, '24/911'));
        self::assertSame(SpouseMarker::PENDING, $this->marker()->mark(self::IDA, '24/911'));
    }

    /** `RESN locked` is an administrator's decision, not this tool's to undo. */
    public function testALockedRecordIsRefused(): void
    {
        $this->signInAsManager();

        $ida = $this->ida();
        $ida->updateRecord($ida->gedcom() . "\n1 RESN locked", false);

        self::assertSame(SpouseMarker::LOCKED, $this->marker()->mark(self::IDA, '24/911'));
    }

    // -----------------------------------------------------------------
    // All of them at once
    // -----------------------------------------------------------------

    /**
     * **A refusal stops that one and nothing else.**
     *
     * The archive holds a hundred and twenty-six of these. A run that gave up
     * because the third record was locked would be useless in exactly the
     * archive the button was asked for — so the first entry here is a person
     * who is not in the tree, and the second still has to be written.
     */
    public function testARefusalDoesNotStopTheRestOfTheRun(): void
    {
        $this->signInAsManager(true);

        $this->marker()->markEvery([
            ['xref' => 'X999', 'number' => '24/911'],
            ['xref' => self::IDA, 'number' => '24/911'],
        ]);

        self::assertStringContainsString('24/911!', $this->stored(self::IDA));
    }

    /**
     * The tally is what the screen says out loud, so it has to be true.
     *
     * "126 corrected" and "125 corrected, one record locked" are different
     * things to have been told, and only the second sends anybody to look.
     */
    public function testTheTallySaysWhatBecameOfEachOne(): void
    {
        $this->signInAsManager(true);

        self::assertSame(
            [
                'done' => [SpouseMarker::NO_PERSON => 1, SpouseMarker::APPLIED => 1],
                'left' => 0,
            ],
            $this->marker()->markEvery([
                ['xref' => 'X999', 'number' => '24/911'],
                ['xref' => self::IDA, 'number' => '24/911'],
            ])
        );
    }

    /**
     * One press writes at most a fixed number, and says how many are left.
     *
     * Not because more would be wrong — each is checked on its own — but
     * because a request that runs past the webserver's patience leaves a
     * person with a page that never came back and no idea how far it got.
     * Built from a reference nobody has, so this counts rather than writes.
     */
    public function testARunStopsAtTheLimitAndSaysHowManyAreLeft(): void
    {
        $this->signInAsManager(true);

        $marks = array_fill(0, SpouseMarker::MAX_AT_ONCE + 3, ['xref' => 'X999', 'number' => '24/911']);

        self::assertSame(
            [
                'done' => [SpouseMarker::NO_PERSON => SpouseMarker::MAX_AT_ONCE],
                'left' => 3,
            ],
            $this->marker()->markEvery($marks)
        );
    }

    /**
     * **The couples the records do not decide are not in the bulk run either.**
     *
     * This is the whole reason the button is allowed to exist. Rudolf and
     * Berta both carry `24/922` and neither has parents in the archive, so
     * which of them married in is not something the records say — and a `!` on
     * the wrong one does not fail, it quietly makes each of them the other.
     * Pressing "do them all" must not decide that by volume.
     */
    public function testThePressThatDoesThemAllLeavesTheUndecidedAlone(): void
    {
        $this->signInAsManager(true);

        $this->markAll();

        self::assertStringContainsString('24/911!', $this->stored(self::IDA));
        self::assertStringNotContainsString('!', $this->stored(self::RUDOLF));
        self::assertStringNotContainsString('!', $this->stored(self::BERTA));
    }

    /**
     * **Which records are written is read here, not posted.**
     *
     * The form sends one field saying "all of them"; the list it means is
     * worked out from a fresh scan on this side. If the xrefs came from the
     * form, anything that could open this screen could name its own records —
     * and the run would act on a list that was true when the page was drawn.
     * So a body naming Rudolf, whom the records do not decide, changes nothing
     * about him.
     */
    public function testTheFormCannotNameTheRecordsToWrite(): void
    {
        $this->signInAsManager(true);

        $this->markAll(['xref' => self::RUDOLF, 'number' => '24/922']);

        self::assertStringNotContainsString('!', $this->stored(self::RUDOLF));
    }

    /**
     * Every outcome the marker can report has a sentence waiting for it.
     *
     * The screen matches on the outcomes with no `default` arm, on purpose: a
     * `default` would give a new outcome an old sentence and sound just as
     * sure about it. Without one PHP refuses, loudly — and this is what makes
     * that refusal happen at a commit rather than in front of an
     * administrator who has just had two hundred records written.
     */
    public function testEveryOutcomeHasSomethingToSay(): void
    {
        $outcomes = array_filter(
            (new ReflectionClass(SpouseMarker::class))->getConstants(),
            static fn (mixed $value): bool => is_string($value)
        );

        $said = (new ReflectionClass(PortalApiModule::class))->getConstant('MARK_OUTCOMES');

        self::assertIsArray($said);

        foreach ($outcomes as $name => $value) {
            self::assertContains(
                $value,
                $said,
                'SpouseMarker::' . $name . ' has no sentence in PortalApiModule::MARK_OUTCOMES, '
                    . 'so a bulk run that produced it would end in an unhandled match.'
            );
        }
    }

    /** Press the button that does them all, as the screen posts it. */
    private function markAll(array $extra = []): void
    {
        $this->module()->postAdminMarriagesAction(
            self::createRequest(RequestMethodInterface::METHOD_POST, [], ['mark_all' => '1'] + $extra)
        );
    }

    // -----------------------------------------------------------------
    // Who may
    // -----------------------------------------------------------------

    /**
     * The screen sits in the control panel, which webtrees has already let
     * this reader open — but opening a screen and editing the family tree are
     * two permissions, and only one of them is being exercised here.
     */
    public function testAnOrdinaryMemberMayNotMark(): void
    {
        $member = $this->createUser('leser', 'Der Leser', 'correct-horse', UserInterface::ROLE_MEMBER);

        Auth::login($member);

        self::assertFalse($this->marker()->permitted());
    }

    public function testAManagerMay(): void
    {
        $this->signInAsManager();

        self::assertTrue($this->marker()->permitted());
    }

    /** A guard that is only in the screen is a guard the next caller skips. */
    public function testTheGuardIsNotOnlyInTheScreen(): void
    {
        self::assertTrue(
            str_contains(
                (string) file_get_contents(__DIR__ . '/../portal_api/src/PortalApiModule.php'),
                '$marker->permitted()'
            ),
            'The action no longer checks who is asking.'
        );
    }
}
