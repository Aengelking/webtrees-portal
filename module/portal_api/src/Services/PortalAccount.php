<?php

declare(strict_types=1);

namespace Engelking\Webtrees\PortalApi\Services;

use Fisharebest\Webtrees\User;

/**
 * One account, as the administrator's overview needs to see it.
 *
 * Not a member: an *account*. The portal's own idea of a member is somebody
 * with the `member` role and a profile row, and the whole reason this screen
 * exists is the accounts that are not that and were expected to be.
 */
final readonly class PortalAccount
{
    /**
     * @param string      $role      webtrees' role on the portal's tree, or '' when
     *                               the account has never been given one.
     * @param string      $role_name The same, in the words webtrees' own user
     *                               editor uses.
     * @param string|null $xref      The record this account is linked to.
     * @param string|null $blocked   Why the portal will refuse this account, or
     *                               null when it will not.
     */
    public function __construct(
        public User $user,
        public string $role,
        public string $role_name,
        public string|null $xref,
        public bool $is_administrator,
        public bool $is_approved,
        public bool $is_verified,
        public string|null $blocked,
    ) {
    }

    public function canUsePortal(): bool
    {
        return $this->blocked === null;
    }
}
