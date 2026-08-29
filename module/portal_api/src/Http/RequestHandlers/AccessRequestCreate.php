<?php

declare(strict_types=1);

namespace Engelking\Webtrees\PortalApi\Http\RequestHandlers;

use Engelking\Webtrees\PortalApi\Http\Json;
use Engelking\Webtrees\PortalApi\Services\AccessRequests;
use Fig\Http\Message\StatusCodeInterface;
use Fisharebest\Webtrees\Http\Exceptions\HttpTooManyRequestsException;
use Fisharebest\Webtrees\Services\RateLimitService;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Throwable;

use function error_log;
use function is_string;

/**
 * POST /api/v1/access-request — "I read about the portal, and I belong here."
 *
 * Unauthenticated by necessity, like `/invitation/claim` and
 * `/password/request`: the person writing has no account, and in this case is
 * not on any list either — a notice in the family magazine reaches further
 * than the mailing list does.
 *
 * **Nothing is created and nothing is sent.** This writes a line into a queue
 * that an administrator reads. That is the whole design: whether somebody
 * belongs to this family is the one question the portal will not let software
 * answer (§1.3), and a form that could talk itself into an account would be
 * exactly that.
 *
 * **One answer, always**, for `InvitationClaimCreate`'s reason. Accepted,
 * ignored for want of an address, rate-limited, database unreachable: the same
 * sentence. A form that answered differently would tell a stranger which of
 * their guesses about this family were right — and the most valuable guess is
 * an archive number, which the magazine prints beside every name.
 */
class AccessRequestCreate implements RequestHandlerInterface
{
    /**
     * Site-wide, and generous.
     *
     * A queue an administrator reads is spoiled by volume rather than by
     * speed, so this is set where a jammed key does nothing and a family
     * reading the same magazine over one weekend is not stopped.
     */
    private const int RATE_LIMIT_REQUESTS = 20;
    private const int RATE_LIMIT_SECONDS  = 300;

    public function __construct(
        private readonly AccessRequests $requests,
        private readonly RateLimitService $rate_limiter,
    ) {
    }

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        $body = Json::body($request);

        $name      = $body['name'] ?? '';
        $email     = $body['email'] ?? '';
        $reference = $body['reference'] ?? '';
        $note      = $body['note'] ?? '';

        if (is_string($name) && is_string($email) && is_string($reference) && is_string($note)) {
            $this->attempt($name, $email, $reference, $note);
        }

        // Deliberately not 201. Nothing was necessarily created, and a status
        // that varied with the outcome would say what the body refuses to.
        return Json::response(['status' => 'received'], StatusCodeInterface::STATUS_ACCEPTED);
    }

    private function attempt(string $name, string $email, string $reference, string $note): void
    {
        try {
            $this->rate_limiter->limitRateForSite(self::RATE_LIMIT_REQUESTS, self::RATE_LIMIT_SECONDS, 'rate-limit-portal-access-request');
        } catch (HttpTooManyRequestsException) {
            return;
        }

        try {
            $this->requests->record($name, $email, $reference, $note);
        } catch (Throwable $exception) {
            // Never reported. A queue this portal cannot write to is this
            // portal's problem, and saying so here would make the form answer
            // differently on a bad day than on a good one.
            error_log('portal_api: an access request could not be recorded. ' . $exception::class . ': ' . $exception->getMessage());
        }
    }
}
