<?php

declare(strict_types=1);

namespace Engelking\Webtrees\PortalApi\Services;

use function time;
use function trim;

/**
 * One row of `portal_invitation`, as an object rather than a `stdClass`.
 *
 * The token is deliberately absent. It exists once, when the invitation is
 * created, and is never read back from anywhere — see Schema/Migration1.php.
 */
final class Invitation
{
    public function __construct(
        public readonly int $id,
        public readonly int $gedcom_id,
        public readonly string|null $xref,
        public readonly string|null $invited_name,
        public readonly string|null $email,
        public readonly int|null $created_by,
        public readonly int $created_at,
        public readonly int $expires_at,
        public readonly int|null $redeemed_at,
        public readonly int|null $redeemed_user_id,
    ) {
    }

    public static function fromRow(object $row): self
    {
        return new self(
            id: (int) $row->id,
            gedcom_id: (int) $row->gedcom_id,
            xref: self::nullableString($row->xref ?? null),
            invited_name: self::nullableString($row->invited_name ?? null),
            email: self::nullableString($row->email ?? null),
            created_by: isset($row->created_by) ? (int) $row->created_by : null,
            created_at: (int) $row->created_at,
            expires_at: (int) $row->expires_at,
            redeemed_at: isset($row->redeemed_at) ? (int) $row->redeemed_at : null,
            redeemed_user_id: isset($row->redeemed_user_id) ? (int) $row->redeemed_user_id : null,
        );
    }

    public function isRedeemed(): bool
    {
        return $this->redeemed_at !== null;
    }

    public function hasExpired(): bool
    {
        return $this->expires_at <= time();
    }

    private static function nullableString(mixed $value): string|null
    {
        if ($value === null) {
            return null;
        }

        $value = trim((string) $value);

        return $value === '' ? null : $value;
    }
}
