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
 * DELETE /api/v1/push — forget this device.
 *
 * Silent when there was nothing to forget. The member asked for it to be gone
 * and it is gone; which of those two states it was in beforehand is not
 * something they should have to care about.
 */
class PushDelete implements RequestHandlerInterface
{
    public function __construct(private readonly PushSubscriptions $push)
    {
    }

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        $user = Auth::user();
        $body = Json::body($request);

        $this->push->unsubscribe($user, Json::requiredString($body, 'endpoint'));

        return Json::response([
            'available'  => $this->push->available(),
            'public_key' => $this->push->publicKey(),
            'subscribed' => $this->push->subscribed($user),
        ]);
    }
}
