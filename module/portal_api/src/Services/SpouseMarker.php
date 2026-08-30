<?php

declare(strict_types=1);

namespace Engelking\Webtrees\PortalApi\Services;

use Fisharebest\Webtrees\Auth;
use Fisharebest\Webtrees\Elements\RestrictionNotice;
use Fisharebest\Webtrees\Individual;
use Fisharebest\Webtrees\Registry;

use function explode;
use function implode;
use function preg_match;
use function str_starts_with;
use function trim;

/**
 * Put the `!` back on the number of a partner who married in.
 *
 * `FamilyMarriages` finds the couples missing it and works out which of the
 * two it belongs to; this is the one thing that writes. Kept apart from the
 * scan on purpose — a class that both reads a tree and edits it invites the
 * next person to make the scan "just fix it while it is there", and the whole
 * value of that screen is that looking at it changes nothing.
 *
 * **It goes through webtrees' own edit path, and that path has two ends.**
 * `updateRecord()` writes the change to the pending queue — where an
 * administrator sees the before and the after and accepts or rejects it — and
 * then applies it straight away *if that administrator has "automatically
 * accept changes" switched on for themselves*. So this does not promise to
 * wait, and it must not say that it does: for a manager with auto-accept the
 * mark is live the moment they press the button, exactly as any edit they
 * make inside webtrees is. What is true either way is that it is written as
 * an edit — logged, attributed, and reversible from webtrees' change log —
 * rather than reached round the side into the database. `mark()` reports
 * which of the two happened so the screen can say the true thing.
 *
 * Why it matters that a person sees these at all: the mark decides whether
 * somebody is read as a descendant of a line or as somebody who married into
 * it, and the tell it is derived from — no parents in the archive — is a fact
 * about how complete the records are, not a proof.
 *
 * **The number is passed in and checked.** The screen was rendered from a
 * scan that may be minutes old, and the record may have been edited since. A
 * mark written against a number that is no longer there would be a `!` on
 * whatever happens to be in that slot now.
 */
class SpouseMarker
{
    /** What happened, in words the caller turns into a sentence. */
    public const string MARKED = 'marked';
    public const string APPLIED = 'applied';
    public const string NO_PERSON = 'no_person';
    public const string NO_NUMBER = 'no_number';
    public const string ALREADY_MARKED = 'already_marked';
    public const string LOCKED = 'locked';
    public const string PENDING = 'pending';

    public function __construct(
        private readonly PortalTreeService $trees,
        private readonly PendingChanges $pending_changes,
    ) {
    }

    /**
     * Append `!` to one `REFN` on one record, as a pending change.
     *
     * @return string one of the constants above
     */
    public function mark(string $xref, string $number): string
    {
        $number = trim($number);

        $individual = Registry::individualFactory()->make($xref, $this->trees->tree());

        if (!$individual instanceof Individual) {
            return self::NO_PERSON;
        }

        if ($this->isLocked($individual)) {
            return self::LOCKED;
        }

        // A second queued change would be built from the approved record and
        // would discard the first once an editor applied them in order. The
        // same reasoning as `GedcomEditor`, and the same answer.
        if ($this->pending_changes->existsFor($individual)) {
            return self::PENDING;
        }

        $lines  = explode("\n", $individual->gedcom());
        $marked = false;

        foreach ($lines as $index => $line) {
            $match = [];

            if (preg_match('/^1 REFN (.*)$/', $line, $match) !== 1) {
                continue;
            }

            $value = trim($match[1]);

            if ($value === $number . '!') {
                return self::ALREADY_MARKED;
            }

            if ($value !== $number || $marked) {
                continue;
            }

            // Only this line. Its subordinate lines — `2 TYPE SB` — belong to
            // the number and stay where they are, which is why the value is
            // replaced rather than the fact rebuilt.
            $lines[$index] = '1 REFN ' . $number . '!';
            $marked        = true;
        }

        if (!$marked) {
            return self::NO_NUMBER;
        }

        // webtrees writes it to the `change` table, adds a `CHAN` naming
        // whoever is signed in, and logs it — and then accepts it on the spot
        // if this user has auto-accept on. Asked afterwards rather than
        // assumed, because the answer belongs to their account and not to us.
        $individual->updateRecord(implode("\n", $lines), true);

        return $this->pending_changes->existsFor($individual) ? self::MARKED : self::APPLIED;
    }

    /**
     * A record carrying `RESN locked` is one an administrator has deliberately
     * closed to editing, and this is not the tool that overrides that.
     */
    private function isLocked(Individual $individual): bool
    {
        $match = [];

        if (preg_match('/\n1 RESN (.+)/', $individual->gedcom(), $match) !== 1) {
            return false;
        }

        return str_starts_with(
            (new RestrictionNotice(''))->canonical($match[1]),
            RestrictionNotice::VALUE_LOCKED
        );
    }

    /** Whether the signed-in user may queue a change against the tree at all. */
    public function permitted(): bool
    {
        return Auth::isManager($this->trees->tree());
    }
}
