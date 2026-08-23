<?php

declare(strict_types=1);

namespace Engelking\Webtrees\PortalApi\Services;

use Fisharebest\Webtrees\Individual;

use function array_key_exists;

/**
 * A person's ancestors, several generations at a time.
 *
 * The portal shows these as indented columns rather than a drawn chart, and it
 * needs them in one response: fifteen separate round trips to build four
 * generations would be slow on a phone and would hammer the host.
 *
 * Positions are Ahnentafel numbers — the root is 1, a father is 2n and a
 * mother is 2n+1 — which is the oldest and simplest way to write a pedigree
 * down flat. The client indexes by number and never has to walk a nested
 * structure.
 *
 * **The privacy rule is the traversal itself.** A person the member may not
 * see is not in the list, and the walk stops there: their parents are not
 * looked up either. So the result is exactly the set of people the member
 * could have reached by tapping from relative to relative, in one request
 * rather than fifteen. It cannot show more than that, which is the point —
 * a pedigree is a shape, and a shape with a hole in it still says something
 * about what fills the hole. Here there is no hole, because the branch simply
 * ends, exactly as it does on the profile screen.
 */
class AncestorTree
{
    /** More than this and the response stops being something a phone renders. */
    public const int MAX_GENERATIONS = 6;

    public const int DEFAULT_GENERATIONS = 4;

    public function __construct(private readonly RecordPresenter $presenter)
    {
    }

    /**
     * @param int             $generations How many generations *above* the root to include.
     * @param Individual|null $viewer      The reader's own record, so each
     *                                     rung can say how they stand to it.
     *
     * @return array<int,array<string,mixed>> One entry per visible ancestor,
     *                                        the root included, in Ahnentafel
     *                                        order.
     */
    public function build(
        Individual $root,
        int $access_level,
        int $generations,
        Individual|null $viewer = null
    ): array
    {
        $generations = max(1, min($generations, self::MAX_GENERATIONS));

        $people  = [];
        $pending = [1 => $root];

        for ($generation = 0; $generation <= $generations; $generation++) {
            $next = [];

            foreach ($pending as $position => $individual) {
                $ref = $this->presenter->individualRef($individual, $access_level, $viewer);

                if ($ref === null) {
                    // Not visible: no entry, and no going further up this
                    // branch. Both halves matter.
                    continue;
                }

                $people[] = ['position' => $position, 'generation' => $generation] + $ref;

                if ($generation === $generations) {
                    continue;
                }

                foreach ($this->parents($individual, $access_level) as $offset => $parent) {
                    $next[$position * 2 + $offset] = $parent;
                }
            }

            $pending = $next;
        }

        return $people;
    }

    /**
     * The father at offset 0 and the mother at offset 1, where each is visible.
     *
     * Only the first child-family is followed. A record can have several — an
     * adoptive family beside a birth family — and webtrees puts the primary one
     * first; picking one keeps the Ahnentafel numbering meaningful, which is
     * the whole reason for using it.
     *
     * @return array<int,Individual>
     */
    private function parents(Individual $individual, int $access_level): array
    {
        $family = $individual->childFamilies($access_level)->first();

        if ($family === null) {
            return [];
        }

        $parents = [];

        foreach ($family->spouses($access_level) as $parent) {
            $offset = $parent->sex() === 'F' ? 1 : 0;

            // Two fathers, two mothers, or a parent whose sex is not recorded:
            // put whoever comes second in the free slot rather than dropping
            // them. The numbering stays valid; only "2 is the father" stops
            // being a promise, and the portal does not make that promise.
            if (array_key_exists($offset, $parents)) {
                $offset = $offset === 0 ? 1 : 0;
            }

            if (!array_key_exists($offset, $parents)) {
                $parents[$offset] = $parent;
            }
        }

        return $parents;
    }
}
