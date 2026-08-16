<?php

declare(strict_types=1);

namespace Engelking\Webtrees\PortalApi\Services;

use Fisharebest\Webtrees\User;

use function mb_stripos;

/**
 * A member of the directory.
 *
 * `display_name` is portal data the member consented to publish, not GEDCOM
 * data. It is deliberately not derived from the linked individual record:
 * appearing in the directory is a decision about oneself, and must not turn
 * into a way to read genealogy data that privacy rules would otherwise hide.
 */
final class Member
{
    public function __construct(
        public readonly int $id,
        public readonly User $user,
        public readonly string $display_name,
    ) {
    }

    public function matches(string $query): bool
    {
        if ($query === '') {
            return true;
        }

        return mb_stripos($this->display_name, $query) !== false;
    }
}
