<?php

declare(strict_types=1);

namespace Engelking\Webtrees\PortalApi\Services;

use Fisharebest\Webtrees\Auth;
use Fisharebest\Webtrees\Family;
use Fisharebest\Webtrees\I18N;
use Fisharebest\Webtrees\Individual;
use Fisharebest\Webtrees\Services\RelationshipService;

use function array_filter;
use function array_key_exists;
use function array_keys;
use function array_shift;
use function array_values;
use function asort;
use function implode;
use function in_array;
use function count;
use function str_contains;
use function trim;
use function usort;

/**
 * "Ihre Cousine", "Ihr Urgroßvater" — how a member is related to someone.
 *
 * webtrees can name a relationship from a path through the tree, and this uses
 * its `RelationshipService::nameFromPath()` for exactly that: the names are
 * webtrees', translated by webtrees, in the member's language.
 *
 * What is **not** used is `getCloseRelationshipName()`, which would be the
 * obvious call. It walks the tree through `Auth::PRIV_HIDE` at all six of its
 * traversal points — deliberately, because it exists to serve webtrees' own
 * pages where the answer is already gated. Handing its result to a member
 * would leak: a relationship name encodes the shape of the path that produced
 * it, so "your cousin" tells the reader that a shared grandparent exists even
 * when every record along the way is one they may not see.
 *
 * So the walk is done here instead, at the member's own access level. It is
 * webtrees' algorithm — breadth-first through parents, siblings, spouses and
 * children — with the one substitution that matters. A path is named only if
 * every person and family on it is visible to this member; otherwise there is
 * no answer, which is the same thing the portal says about a hidden person
 * anywhere else.
 */
class RelationshipNamer
{
    /**
     * How far to look. Four steps covers what a family calls a relationship —
     * up to first cousins, great-grandparents, nieces and nephews.
     *
     * It is also a bound on the work: the search is breadth-first over a graph
     * that can be large, and this runs on a page a member may open often.
     */
    private const int MAX_STEPS = 4;

    /**
     * The walk, once per reader.
     *
     * Cached because of where this is now called from. Naming one relationship
     * on one record was a single question; naming one on every card of a list
     * of search results is the same question twenty-five times, and the walk
     * that answers it does not depend on which card is asking — it depends
     * only on where the reader stands. So the reader's neighbourhood is walked
     * once and every card is answered from it.
     *
     * Keyed by the reader *and* the access level, because a walk is only valid
     * for the eyes it was made for. Nothing in a request changes either, so in
     * practice this holds one entry.
     *
     * @var array<string,array<string,array<int,Individual|Family>>>
     */
    private array $reach = [];

    /** The reader's own archive numbers, looked up once. @var array<string,array<int,string>> */
    private array $own_numbers = [];

    public function __construct(
        private readonly RelationshipService $relationships,
        private readonly SackRelationship $sack,
    ) {
    }

    /**
     * How $viewer is related to $target, in words, or null.
     *
     * **Every way they are related, not only the nearest.** In a family that
     * has married within itself for three hundred years, two people are
     * routinely related twice over, and a card that names one of the two is a
     * card that quietly picks. The tree's answer — the shortest path within
     * four steps, which is also the one a family would actually say aloud —
     * comes first, and every distinct answer the archive numbers give follows
     * it, nearest first.
     *
     * Null covers every case where there is nothing safe or useful to say: no
     * viewer, no path within reach, no numbers to compare, or a path that runs
     * through someone this member may not see.
     */
    public function name(Individual|null $viewer, Individual $target, int $access_level): string|null
    {
        if ($viewer === null || $viewer->xref() === $target->xref()) {
            return null;
        }

        $names = [];

        $path = $this->reach($viewer, $access_level)[$target->xref()] ?? [];

        if ($path !== []) {
            $name = $this->relationships->nameFromPath($path, I18N::language());

            if ($name !== '') {
                $names[] = $name;
            }
        }

        foreach ($this->fromNumbers($viewer, $target, $access_level) as $name) {
            if (!in_array($name, $names, true)) {
                $names[] = $name;
            }
        }

        if ($names === []) {
            return null;
        }

        return $this->phrase($names);
    }

    /**
     * One line naming every way the two are related, nearest first.
     *
     * Joined here rather than handed over as a list, because the names
     * themselves are already made and translated on this side — webtrees'
     * `nameFromPath()` and the family's own calculator both answer in the
     * request's language — and a client that had to assemble the sentence
     * would be the only place the wording lived in two languages twice. It
     * also means a portal that predates this reads the longer line without
     * knowing anything changed.
     *
     * @param array<int,string> $names At least one.
     */
    private function phrase(array $names): string
    {
        $first = array_shift($names);

        if ($names === []) {
            return $first;
        }

        /* I18N: A person related in more than one way — "your cousin · also your second cousin" */
        return $first . ' · ' . I18N::translate('also %s', implode(', ', $names));
    }

    /**
     * Every way the two archive numbers say these people are related.
     *
     * **This used to run only where the walk had failed, and now runs
     * always.** The reason is the case it was written blind to: a record
     * carrying several numbers is a record that descends from the family by
     * several lines, and the near relationship the tree found says nothing
     * about the far one the numbers know. Stopping at the tree's answer
     * dropped it. The cost is a handful of string comparisons per card on a
     * record that is already loaded and being read.
     *
     * **The walk still goes first.** The tree knows things the numbering
     * system cannot: a wife, a stepfather, an adopted child. An SB number
     * describes one thing — descent — and describes it perfectly. So the
     * tree's answer leads and these follow it.
     *
     * **And why this is allowed to cross what the walk refuses to.** §2.25
     * will not name a relationship through somebody the reader may not see,
     * because the name would betray that they exist. This crosses that ground
     * freely, and it is not a hole in the rule: an SB number *is* the
     * ancestral path, both numbers are already printed on both cards, and
     * anybody holding the two of them can do this arithmetic on the back of an
     * envelope. Computing it here discloses nothing the two numbers did not.
     *
     * A number the reader may not see is a different matter, and `facts()`
     * settles it — a confidential `REFN` is not in the list, so it cannot be
     * used, and the answer is silence.
     */
    private function fromNumbers(Individual $viewer, Individual $target, int $access_level): array
    {
        $found = [];

        foreach ($this->ownNumbers($viewer, $access_level) as $mine) {
            foreach ($this->trusted($this->numbersOf($target, $access_level)) as $theirs) {
                $relation = $this->sack->between($mine, $theirs);

                if ($relation === null || $relation['kind'] === 'self') {
                    continue;
                }

                $name = $this->sack->describe($relation, $target->sex());

                // Distinct *answers*, not distinct pairs of numbers. Two
                // numbers on one record often descend from the same ancestor
                // by two routes of equal length and name the same cousin
                // twice; what a member wants to read is the ways they are
                // related, and there is one of those here, not two.
                if ($name === '' || array_key_exists($name, $found)) {
                    continue;
                }

                // Both halves of the walk between them: up to the shared
                // ancestor, then down again. `distance` counts the way up and
                // `generations` the difference in depth, so the way down is
                // `distance - generations` — see `SackRelationship::between()`.
                $found[$name] = 2 * $relation['distance'] - $relation['generations'];
            }
        }

        // Nearest first. A member who is both a brother-in-law and a fourth
        // cousin is read as the first of those; the rest is the footnote.
        asort($found);

        return array_keys($found);
    }

    /**
     * Every archive number the reader's own record carries, looked up once.
     *
     * **All of them, and this is the whole of the change.** A person with two
     * numbers is a person who descends from the family twice, which is exactly
     * the case this exists to name — taking only the first (which is what this
     * did) answered one of the two questions and silently dropped the other.
     *
     * @return array<int,string>
     */
    private function ownNumbers(Individual $viewer, int $access_level): array
    {
        $key = $viewer->xref() . '@' . $access_level;

        return $this->own_numbers[$key] ??= $this->trusted($this->numbersOf($viewer, $access_level));
    }

    /**
     * The numbers on a record that may be crossed with another record's.
     *
     * **A bare number is read, but never as a second opinion.** Two digits
     * with no oblique are the head of a line — and are also what the archive's
     * older, unrelated numbering looks like once it reaches two digits (§2.57).
     * Taking only one number per record made that harmless: the sort put the
     * explicit ones first and the ambiguous one was never reached. Crossing
     * every number with every number takes that protection away, and the first
     * thing it produced was a confident "also their great-grand-nephew" out of
     * a bare `9` that may not be a path at all.
     *
     * So where a record carries an explicit number, only those are compared. A
     * record carrying nothing else is still read, exactly as before — one
     * doubtful answer is better than none, and it was the only answer then
     * too. What is refused is a doubtful answer standing *beside* a sound one,
     * where it reads as corroboration.
     *
     * @param array<int,string> $numbers
     *
     * @return array<int,string>
     */
    private function trusted(array $numbers): array
    {
        $explicit = array_values(array_filter($numbers, static fn (string $n): bool => str_contains($n, '/')));

        return $explicit === [] ? $numbers : $explicit;
    }

    /**
     * The reference numbers on a record that are archive numbers.
     *
     * Not filtered by `TYPE`: the archive writes `2 TYPE SB` on most of them
     * and on some older records on none, and a number that parses as one of
     * these paths is one — nothing else in the tree looks like `24/b521.12`.
     *
     * @return array<int,string>
     */
    private function numbersOf(Individual $individual, int $access_level): array
    {
        $numbers = [];

        foreach ($individual->facts(['REFN'], false, $access_level) as $fact) {
            $value = trim($fact->value());

            if ($value !== '' && $this->sack->isNumber($value)) {
                $numbers[] = $value;
            }
        }

        // A number carrying an oblique first.
        //
        // A bare "24" is the head of line 24, and the archive does write it
        // that way — but so does an older, unrelated numbering that happens to
        // have reached two digits. Where a record has both, the one that says
        // out loud what it is should win, and a record with only the bare form
        // is still read.
        usort(
            $numbers,
            static fn (string $a, string $b): int => (str_contains($b, '/') ? 1 : 0) <=> (str_contains($a, '/') ? 1 : 0)
        );

        return $numbers;
    }

    /**
     * Every person within reach of this one, with the path that gets there.
     *
     * @return array<string,array<int,Individual|Family>> keyed by xref
     */
    private function reach(Individual $from, int $access_level): array
    {
        $key = $from->xref() . '@' . $access_level;

        return $this->reach[$key] ??= $this->walk($from, $access_level);
    }

    /**
     * A breadth-first walk outwards, as the alternating lists of individuals
     * and families that `nameFromPath()` expects.
     *
     * Every record on the path is fetched at $access_level, so a person or a
     * family the member may not see is simply not there to walk through. That
     * is the whole difference from webtrees' own version.
     *
     * Breadth-first and marked on arrival, so the path kept for somebody is
     * the shortest one — which is the one that names the relationship a family
     * would actually use.
     *
     * @return array<string,array<int,Individual|Family>> keyed by xref
     */
    private function walk(Individual $from, int $access_level): array
    {
        $visited = [$from->xref() => true];
        $paths   = [[$from]];
        $found   = [];

        for ($step = 0; $step <= self::MAX_STEPS; $step++) {
            $next = [];

            foreach ($paths as $path) {
                $last = $path[count($path) - 1];

                if (!$last instanceof Individual) {
                    continue;
                }

                foreach ($this->families($last, $access_level) as $family) {
                    foreach ($this->members($family, $access_level) as $relative) {
                        if (isset($visited[$relative->xref()])) {
                            continue;
                        }

                        $visited[$relative->xref()] = true;

                        $extended   = $path;
                        $extended[] = $family;
                        $extended[] = $relative;

                        $found[$relative->xref()] = $extended;
                        $next[]                   = $extended;
                    }
                }
            }

            if ($next === []) {
                break;
            }

            $paths = $next;
        }

        return $found;
    }

    /**
     * The families this member may route a relationship through.
     *
     * Deliberately **not** `$family->canShow()`. webtrees hides a family
     * record when *any* of its members is private, which is right for
     * rendering a family page and far too strict here: one confidential
     * cousin would make it impossible to say "your mother". The people on the
     * path are filtered individually below, which is where the disclosure
     * actually lives.
     *
     * What is checked is whether the family declares a restriction of its own.
     * A `RESN` on a FAM record is somebody saying that *this connection* is
     * confidential — not the people, the fact that they are joined — and
     * naming a relationship through it would say exactly the thing that was
     * asked to stay quiet. Any restriction at all is enough to refuse; this is
     * not the place to be clever about which.
     *
     * @return array<int,Family>
     */
    private function families(Individual $individual, int $access_level): array
    {
        $families = [
            ...$individual->childFamilies($access_level)->all(),
            ...$individual->spouseFamilies($access_level)->all(),
        ];

        return array_values(array_filter(
            $families,
            static fn (Family $family): bool => $family->facts(['RESN'], false, Auth::PRIV_HIDE)->isEmpty()
        ));
    }

    /**
     * The people in a family this member may see.
     *
     * The `canShow()` filter is not belt and braces, it is the point. Passing
     * an access level to `Family::children()` does **not** mean "only people
     * this level may see": it filters on `canShowName()`, which is true for a
     * member whenever the tree shows living people's names at all, and it
     * quietly escalates to `Auth::PRIV_HIDE` when the tree has
     * `SHOW_PRIVATE_RELATIONSHIPS` switched on. Both are reasonable for
     * webtrees' own pages, where a hidden relative appears as a placeholder in
     * a family list. Here it would mean naming a relationship through someone
     * this member may not know exists.
     *
     * @return array<int,Individual>
     */
    private function members(Family $family, int $access_level): array
    {
        $people = [
            ...$family->spouses($access_level)->all(),
            ...$family->children($access_level)->all(),
        ];

        return array_values(array_filter(
            $people,
            static fn (Individual $individual): bool => $individual->canShow($access_level)
        ));
    }
}
