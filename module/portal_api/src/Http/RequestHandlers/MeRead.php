<?php

declare(strict_types=1);

namespace Engelking\Webtrees\PortalApi\Http\RequestHandlers;

use Engelking\Webtrees\PortalApi\Http\Json;
use Engelking\Webtrees\PortalApi\Services\MeAssembler;
use Fisharebest\Webtrees\Auth;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * GET /api/v1/me — the authenticated member and their own record.
 */
class MeRead implements RequestHandlerInterface
{
    public function __construct(private readonly MeAssembler $me)
    {
    }

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        return Json::response($this->me->assemble(Auth::user()));
    }
}
