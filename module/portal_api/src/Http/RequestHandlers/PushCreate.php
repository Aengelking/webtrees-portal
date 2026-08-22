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
 * POST /api/v1/push — remember this device.
 *
 * Only the endpoint is stored. The `p256dh` and `auth` keys a browser also
 * offers are for encrypting a payload, and this portal sends none.
 */
class PushCreate implements RequestHandlerInterface
{
    public function __construct(private readonly PushSubscriptions $push)
    {
    }

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        $user = Auth::user();
        $body = Json::body($request);

        $this->push->subscribe($user, Json::requiredString($body, 'endpoint'));

        return Json::response([
            'available'  => $this->push->available(),
            'public_key' => $this->push->publicKey(),
            'subscribed' => $this->push->subscribed($user),
        ], 201);
    }
}
