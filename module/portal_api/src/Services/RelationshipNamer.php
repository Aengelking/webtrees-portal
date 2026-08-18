<?php

declare(strict_types=1);

namespace Engelking\Webtrees\PortalApi\Services;

use Fisharebest\Webtrees\Auth;
use Fisharebest\Webtrees\Family;
use Fisharebest\Webtrees\I18N;
use Fisharebest\Webtrees\Individual;
use Fisharebest\Webtrees\Services\RelationshipService;

use function array_filter;
use function array_values;
use function count;

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

    public function __construct(private readonly RelationshipService $relationships)
    {
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

        $path = $this->path($viewer, $target, $access_level);

        if ($path === []) {
            return null;
        }

        $name = $this->relationships->nameFromPath($path, I18N::language());

        return $name === '' ? null : $name;
    }

    /**
     * A visible path between two people, as the alternating list of
     * individuals and families that `nameFromPath()` expects.
     *
     * @return array<int,Individual|Family> Empty when there is no such path.
     */
    private function path(Individual $from, Individual $to, int $access_level): array
    {
        // Every record on the path is fetched at $access_level, so a person or
        // a family the member may not see is simply not there to walk through.
        // That is the whole difference from webtrees' own version.
        $visited = [$from->xref() => true];
        $paths   = [[$from]];
        $steps   = self::MAX_STEPS;

        while ($steps >= 0) {
            $steps--;

            foreach ($paths as $i => $path) {
                $last = $path[count($path) - 1];

                if (!$last instanceof Individual) {
                    continue;
                }

                foreach ($this->families($last, $access_level) as $family) {
                    $visited[$family->xref()] = true;

                    foreach ($this->members($family, $access_level) as $relative) {
                        if (isset($visited[$relative->xref()])) {
                            continue;
                        }

                        $extended   = $path;
                        $extended[] = $family;
                        $extended[] = $relative;

                        if ($relative->xref() === $to->xref()) {
                            return $extended;
                        }

                        $paths[]                    = $extended;
                        $visited[$relative->xref()] = true;
                    }
                }

                unset($paths[$i]);
            }
        }

        return [];
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
