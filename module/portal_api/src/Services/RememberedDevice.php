<?php

declare(strict_types=1);

namespace Engelking\Webtrees\PortalApi\Services;

use Fisharebest\Webtrees\User;

/**
 * A device that was remembered, and the cookie value that replaces the one it
 * arrived with.
 *
 * Two things travel together because they must not be separated: resuming a
 * session spends the token that resumed it, so a caller that signs the member
 * in without also sending the replacement back has just locked that device out
 * of its own next visit.
 */
final readonly class RememberedDevice
{
    public function __construct(
        public User $user,
        /** `series:token`, to be written back into the cookie. */
        public string $cookie,
    ) {
    }
}
