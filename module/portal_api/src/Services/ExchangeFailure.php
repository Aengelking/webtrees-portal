<?php

declare(strict_types=1);

namespace Engelking\Webtrees\PortalApi\Services;

use RuntimeException;

/**
 * Exchange refused, and what kind of refusal it was.
 *
 * **`permanent`** is whether asking again could ever help, and it is the whole
 * first reason this class exists. A timeout, a 429 and a 503 are all "not
 * now" — the member's wish stands, the row stays outstanding, and the next
 * visit tries again. A misspelled list address or a tenant that has never
 * heard of this application are "not ever": retrying only makes the same call
 * to the same effect, and the thing that has to happen is that an
 * administrator reads the message.
 *
 * **`denied`** is narrower and was added after it was needed. It marks the
 * refusals that are about permission — a token that was not accepted, a cmdlet
 * this application may not run — and it exists because a refusal to act is not
 * evidence about the world. `ExchangeOnline` recovers from a failed write by
 * reading the state back and treating "it already says what was wanted" as
 * success; that is right for "already a member" and badly wrong for "you may
 * not touch this list", where the state saying the right thing only means
 * somebody else put it there. Without this flag a tenant whose application had
 * no rights at all reported every subscription as applied, for as long as
 * reality happened to agree.
 *
 * Nothing in here reaches a member. Exchange's wording is about a tenant, an
 * application registration and a cmdlet, none of which are things a family
 * member has any business being shown.
 */
class ExchangeFailure extends RuntimeException
{
    public function __construct(
        string $message,
        public readonly bool $permanent = false,
        public readonly bool $denied = false,
    ) {
        parent::__construct($message);
    }
}
