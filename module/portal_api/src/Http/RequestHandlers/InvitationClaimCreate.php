<?php

declare(strict_types=1);

namespace Engelking\Webtrees\PortalApi\Http\RequestHandlers;

use Engelking\Webtrees\PortalApi\Http\Json;
use Engelking\Webtrees\PortalApi\Services\InvitationCampaigns;
use Fig\Http\Message\StatusCodeInterface;
use Fisharebest\Webtrees\Http\Exceptions\HttpTooManyRequestsException;
use Fisharebest\Webtrees\Services\RateLimitService;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Throwable;

use function error_log;
use function is_string;
use function random_int;
use function usleep;

/**
 * POST /api/v1/invitation/claim — "the letter said to enter my address here".
 *
 * Unauthenticated by necessity, like `/password/request`: the whole point is
 * that this person has no account yet. The campaign token in the body is not a
 * credential and grants nothing — see `Services/InvitationCampaigns.php` — it
 * only says which letter this is answering, so that one can be called off
 * without calling off the others.
 *
 * **One answer, always.** On a list, on no list, already has an account, mail
 * server down, campaign expired: the same sentence and a broadly similar
 * delay. Anything else and this becomes a way of asking whether a person is in
 * this family, which is the question the portal exists to keep quiet about.
 * The rate limiter's refusal is swallowed for the same reason.
 */
class InvitationClaimCreate implements RequestHandlerInterface
{
    private const int RATE_LIMIT_REQUESTS = 10;
    private const int RATE_LIMIT_SECONDS  = 300;

    public function __construct(
        private readonly InvitationCampaigns $campaigns,
        private readonly RateLimitService $rate_limiter,
    ) {
    }

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        $body     = Json::body($request);
        $campaign = $body['campaign'] ?? '';
        $email    = $body['email'] ?? '';

        if (is_string($campaign) && is_string($email)) {
            $this->attempt($request, $campaign, $email);
        }

        // Deliberately not 201: nothing was necessarily created, and a status
        // that varied with the outcome would say what the body refuses to.
        return Json::response(['status' => 'sent'], StatusCodeInterface::STATUS_ACCEPTED);
    }

    private function attempt(ServerRequestInterface $request, string $campaign, string $email): void
    {
        try {
            $this->rate_limiter->limitRateForSite(self::RATE_LIMIT_REQUESTS, self::RATE_LIMIT_SECONDS, 'rate-limit-portal-claim');
        } catch (HttpTooManyRequestsException) {
            return;
        }

        try {
            $this->campaigns->claim($campaign, $email);
        } catch (Throwable $exception) {
            // Never reported. A failure here is this portal's problem and
            // telling the caller about it would tell them something about the
            // address they typed.
            error_log('portal_api: an invitation claim failed. ' . $exception::class . ': ' . $exception->getMessage());
        }

        // The work above takes a different length of time depending on what
        // was found — a mail sent, an Exchange lookup, or nothing at all. A
        // little noise on top is not a defence on its own, and it is cheap.
        usleep(random_int(50_000, 150_000));
    }
}
