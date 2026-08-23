<?php

declare(strict_types=1);

namespace Engelking\Webtrees\PortalApi\Services;

use RuntimeException;

/**
 * Exchange refused, and whether asking again could ever help.
 *
 * The distinction is the whole reason this class exists. A timeout, a 429 and
 * a 503 are all "not now" — the member's wish stands, the row stays
 * outstanding, and the next visit tries again. A 401, a misspelled list
 * address or a tenant that has never heard of this application are "not ever":
 * retrying only makes the same call to the same effect, and the thing that has
 * to happen is that an administrator reads the message.
 *
 * Nothing in here reaches a member. Exchange's wording is about a tenant, an
 * application registration and a cmdlet, none of which are things a family
 * member has any business being shown.
 */
class ExchangeFailure extends RuntimeException
{
    public function __construct(string $message, public readonly bool $permanent = false)
    {
        parent::__construct($message);
    }
}
