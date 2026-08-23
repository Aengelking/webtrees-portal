<?php

declare(strict_types=1);

namespace Engelking\Webtrees\PortalApi\Http\RequestHandlers;

use Engelking\Webtrees\PortalApi\Http\ApiException;
use Engelking\Webtrees\PortalApi\Http\Json;
use Engelking\Webtrees\PortalApi\Services\Invitation;
use Engelking\Webtrees\PortalApi\Services\InvitationService;
use Engelking\Webtrees\PortalApi\Services\LoginRateLimiter;
use Engelking\Webtrees\PortalApi\Services\PortalTreeService;
use Fisharebest\Webtrees\Log;
use Fisharebest\Webtrees\Validator;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;

use function gmdate;

/**
 * POST /api/v1/invitation/preview — what does this invitation open?
 *
 * A read, done with `POST`, on purpose. The token has to travel in the body:
 * a `GET /invitation/{token}` would write the credential into the webserver's
 * access log, into any proxy in front of it, and into the `Referer` of every
 * request the resulting page makes. Password resets already work this way
 * (`PasswordResetCreate`), for the same reason.
 *
 * What it answers with is small and deliberate: the family tree's title, and
 * the name the invitation was issued for as it read at the time. No XREF, no
 * genealogy record, no dates — the person reading this is not signed in, and
 * all they need is enough to recognise that the invitation is meant for them.
 *
 * The name is a snapshot stored on the invitation, not a lookup. Nothing here
 * reads the family tree.
 */
class InvitationRead implements RequestHandlerInterface
{
    public function __construct(
        private readonly PortalTreeService $trees,
        private readonly InvitationService $invitations,
        private readonly LoginRateLimiter $rate_limiter,
    ) {
    }

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        $body  = Json::body($request);
        $token = Json::requiredString($body, 'token');
        $ip    = Validator::attributes($request)->string('client-ip', '');
        // Not `tree()`: this endpoint runs for somebody with no account, and
        // `tree()` resolves through a list filtered by who is asking. On a
        // tree that requires authentication that list is empty for a visitor,
        // so every invitation would be refused before its token was read. See
        // `PortalTreeService::configuredTree()`.
        $tree  = $this->trees->configuredTree();

        // Fails closed, like the login limiter: an unreachable attempt store
        // is not a reason to let someone work through the token space.
        if (!$this->rate_limiter->allows($ip, InvitationService::limiterKey($token))) {
            Log::addAuthenticationLog('Portal invitation lookup rate-limited from ' . $ip);

            throw ApiException::invalidInvitation();
        }

        $invitation = $this->invitations->findUsable($token, $tree);

        if (!$invitation instanceof Invitation) {
            $this->rate_limiter->recordFailure($ip, InvitationService::limiterKey($token));

            throw ApiException::invalidInvitation();
        }

        return Json::response([
            'tree' => [
                'name'  => $tree->name(),
                'title' => $tree->title(),
            ],
            'invited_name' => $invitation->invited_name,
            'email'        => $invitation->email,
            'expires_at'   => gmdate('c', $invitation->expires_at),
        ]);
    }
}
