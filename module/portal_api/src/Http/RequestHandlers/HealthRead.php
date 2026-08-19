<?php

declare(strict_types=1);

namespace Engelking\Webtrees\PortalApi\Http\RequestHandlers;

use Engelking\Webtrees\PortalApi\Http\Json;
use Engelking\Webtrees\PortalApi\PortalApiModule;
use Engelking\Webtrees\PortalApi\Services\PortalTreeService;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * GET /api/v1/health — is the chain working?
 *
 * Answering this at all takes a request through the Cloudflare Worker, its
 * proxy secret, whatever URL form webtrees needs, PHP, webtrees' bootstrap,
 * this module's `boot()`, the database and the tree configuration. One
 * request either proves all of that or names the first thing that is wrong,
 * which is what a deployment and an uptime monitor each want and neither had.
 *
 * **What it says is deliberately dull.** No tree name, no member count, no
 * host details — nothing that would make this endpoint worth finding. The
 * proxy secret guards it in a normal installation, but an endpoint whose
 * payload is boring is safe even when it is not guarded, and that is the
 * property worth having.
 *
 * The one thing it does disclose is the module's version, and that is on
 * purpose: it is what turns "the upload reported success" into "the new code
 * is actually running". An upload that silently left the old files in place
 * is the deployment failure that is otherwise invisible.
 */
class HealthRead implements RequestHandlerInterface
{
    public function __construct(
        private readonly PortalApiModule $module,
        private readonly PortalTreeService $trees,
    ) {
    }

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        // Throws the same not-configured 503 every other endpoint would, so
        // a monitor sees the failure the members are seeing. `ApiEnvelope`
        // does not record it: a monitor polling a half-configured install
        // would otherwise fill the error log with rows saying what the
        // diagnosis screen already says better.
        $this->trees->tree();

        return Json::response([
            'status'         => 'ok',
            'version'        => PortalApiModule::CUSTOM_VERSION,
            'schema_version' => $this->module->schemaVersion(),
        ]);
    }
}
