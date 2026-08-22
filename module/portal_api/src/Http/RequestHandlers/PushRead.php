<?php

declare(strict_types=1);

namespace Engelking\Webtrees\PortalApi\Http\RequestHandlers;

use Engelking\Webtrees\PortalApi\Http\Json;
use Engelking\Webtrees\PortalApi\Services\PushSubscriptions;
use Fisharebest\Webtrees\Auth;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * GET /api/v1/push — can this portal notify, and is this device signed up?
 *
 * The key is public by design: it is what a browser needs in order to
 * subscribe at all, and it identifies the sender rather than authorising
 * anything. `subscribed` answers about the *account*, not this device — the
 * browser knows about its own subscription and the portal cannot see it, so
 * the screen combines the two.
 */
class PushRead implements RequestHandlerInterface
{
    public function __construct(private readonly PushSubscriptions $push)
    {
    }

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        return Json::response([
            'available'  => $this->push->available(),
            'public_key' => $this->push->publicKey(),
            'subscribed' => $this->push->subscribed(Auth::user()),
        ]);
    }
}
