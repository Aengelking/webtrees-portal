<?php

declare(strict_types=1);

namespace Engelking\Webtrees\PortalApi\Services;

/**
 * The rungs of one pedigree, and whether they are all of them.
 *
 * A pair rather than a bare list, because "here are 400 people" and "here are
 * the first 400 of more" are different answers and a screen that cannot tell
 * them apart will say the family ends where the cap does. `AncestorTree` is the
 * only thing that knows which it produced, so it is the thing that says.
 *
 * @param array<int,array<string,mixed>> $people
 */
final class Pedigree
{
    public function __construct(
        /** @var array<int,array<string,mixed>> */
        public readonly array $people,
        public readonly bool $truncated,
    ) {
    }
}
