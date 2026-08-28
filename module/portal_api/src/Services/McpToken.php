<?php

declare(strict_types=1);

namespace Engelking\Webtrees\PortalApi\Services;

use function time;
use function trim;

/**
 * One row of `portal_mcp_token`, as an object rather than a `stdClass`.
 *
 * The token itself is deliberately absent. It exists once, when it is issued,
 * and is never read back from anywhere — see Schema/Migration16.php.
 */
final class McpToken
{
    public function __construct(
        public readonly int $id,
        public readonly string $name,
        public readonly int $wt_user_id,
        public readonly int|null $created_by,
        public readonly int $created_at,
        public readonly int $expires_at,
        public readonly int|null $revoked_at,
        public readonly int|null $last_used_at,
        public readonly int $uses,
    ) {
    }

    public static function fromRow(object $row): self
    {
        return new self(
            id: (int) $row->id,
            name: trim((string) $row->name),
            wt_user_id: (int) $row->wt_user_id,
            created_by: isset($row->created_by) ? (int) $row->created_by : null,
            created_at: (int) $row->created_at,
            expires_at: (int) $row->expires_at,
            revoked_at: isset($row->revoked_at) ? (int) $row->revoked_at : null,
            last_used_at: isset($row->last_used_at) ? (int) $row->last_used_at : null,
            uses: (int) ($row->uses ?? 0),
        );
    }

    public function isRevoked(): bool
    {
        return $this->revoked_at !== null;
    }

    public function hasExpired(): bool
    {
        return $this->expires_at <= time();
    }

    public function isUsable(): bool
    {
        return !$this->isRevoked() && !$this->hasExpired();
    }
}
