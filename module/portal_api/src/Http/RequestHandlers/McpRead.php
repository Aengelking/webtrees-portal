<?php

declare(strict_types=1);

namespace Engelking\Webtrees\PortalApi\Http\RequestHandlers;

use Fig\Http\Message\StatusCodeInterface;
use Fisharebest\Webtrees\Registry;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * GET and DELETE on /api/mcp — the two things this server does not do.
 *
 * The MCP transport lets a client open a `GET` and keep it open, so that the
 * server can start conversations of its own: progress reports, log lines,
 * requests for the model to do something. This server never has anything to
 * say unprompted — see `Mcp\Server` on why it is stateless — so the stream
 * would be an open connection that never carried a message, and the honest
 * answer to the request is that the method is not allowed.
 *
 * `DELETE` ends a session, and there are no sessions here for the same reason.
 *
 * Both are registered rather than left to fall through, because what falls
 * through is webtrees' own 404 page in HTML, and a client that reads "405,
 * Allow: POST" understands what has happened and carries on. The specification
 * names this exact response for a server that does not offer the stream.
 */
class McpRead implements RequestHandlerInterface
{
    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        return Registry::responseFactory()
            ->response(
                [
                    'jsonrpc' => '2.0',
                    'id'      => null,
                    'error'   => [
                        'code'    => -32000,
                        'message' => 'This server answers POST only. It opens no stream and keeps no session.',
                    ],
                ],
                StatusCodeInterface::STATUS_METHOD_NOT_ALLOWED,
            )
            ->withHeader('Allow', 'POST');
    }
}
