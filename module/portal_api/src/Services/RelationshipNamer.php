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
use function array_values;
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

    /** The reader's own archive number, looked up once. @var array<string,string|null> */
    private array $own_numbers = [];

    public function __construct(
        private readonly RelationshipService $relationships,
        private readonly SackRelationship $sack,
    ) {
    }

    /**
     * The name of the relationship from $viewer to $target, or null.
     *
     * Null covers every case where there is nothing safe or useful to say: no
     * viewer, no path within reach, or a path that runs through someone this
     * member may not see.
     */
    public function name(Individual|null $viewer, Individual $target, int $access_level): string|null
    {
        if ($viewer === null || $viewer->xref() === $target->xref()) {
            return null;
        }

        $path = $this->reach($viewer, $access_level)[$target->xref()] ?? [];

        if ($path !== []) {
            $name = $this->relationships->nameFromPath($path, I18N::language());

            if ($name !== '') {
                return $name;
            }
        }

        return $this->fromNumbers($viewer, $target, $access_level);
    }

    /**
     * The answer the walk could not give, from the two archive numbers.
     *
     * **Why the walk goes first.** The tree knows things the numbering system
     * cannot: a wife, a stepfather, an adopted child. An SB number describes
     * one thing — descent — and describes it perfectly. So the tree answers
     * wherever it can, and this fills in what is left, which is nearly always
     * the same case: two people too far apart for four steps to reach.
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
    private function fromNumbers(Individual $viewer, Individual $target, int $access_level): string|null
    {
        $mine = $this->ownNumber($viewer, $access_level);

        if ($mine === null) {
            return null;
        }

        foreach ($this->numbersOf($target, $access_level) as $theirs) {
            $name = $this->sack->name($mine, $theirs, $target->sex());

            if ($name !== null) {
                return $name;
            }
        }

        return null;
    }

    private function ownNumber(Individual $viewer, int $access_level): string|null
    {
        $key = $viewer->xref() . '@' . $access_level;

        if (!array_key_exists($key, $this->own_numbers)) {
            $this->own_numbers[$key] = $this->numbersOf($viewer, $access_level)[0] ?? null;
        }

        return $this->own_numbers[$key];
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
