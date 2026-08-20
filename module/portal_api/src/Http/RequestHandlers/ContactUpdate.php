<?php

declare(strict_types=1);

namespace Engelking\Webtrees\PortalApi\Http\RequestHandlers;

use Engelking\Webtrees\PortalApi\Http\ApiException;
use Engelking\Webtrees\PortalApi\Http\Json;
use Engelking\Webtrees\PortalApi\Services\ContactDetails;
use Fisharebest\Webtrees\Auth;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;

use function is_array;

/**
 * PATCH /api/v1/me/contact — change what I share.
 *
 * A kind that is absent from the body is left alone; a kind that arrives with
 * an empty value, or with nobody to share it with, is deleted. Clearing the
 * field and withdrawing consent are the same act, deliberately.
 */
class ContactUpdate implements RequestHandlerInterface
{
    public function __construct(private readonly ContactDetails $contacts)
    {
    }

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        $body    = Json::body($request);
        $changes = $body['contact'] ?? null;

        if (!is_array($changes)) {
            throw ApiException::badRequest();
        }

        return Json::response([
            'enabled' => $this->contacts->enabled(),
            'contact' => $this->contacts->update(Auth::user(), $changes),
        ]);
    }
}
