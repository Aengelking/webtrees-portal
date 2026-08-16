<?php

declare(strict_types=1);

namespace Engelking\Webtrees\PortalApi\Http\Middleware;

use Engelking\Webtrees\PortalApi\Http\ApiException;
use Engelking\Webtrees\PortalApi\PortalApiModule;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;

use function hash_equals;

/**
 * Reject API traffic that did not come through the portal's proxy.
 *
 * The Cloudflare Worker that serves the portal sends a shared secret in
 * `X-Portal-Proxy-Secret`. This is a second lock, not the lock: the API is
 * still authenticated and privacy-filtered without it.
 *
 * Off by default, so that local development needs no configuration.
 */
class RequireProxySecret implements MiddlewareInterface
{
    public const string HEADER = 'X-Portal-Proxy-Secret';

    public function __construct(private readonly PortalApiModule $module)
    {
    }

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        $expected = $this->module->getPreference(PortalApiModule::SETTING_PROXY_SECRET, '');

        if ($expected === '') {
            return $handler->handle($request);
        }

        if (!hash_equals($expected, $request->getHeaderLine(self::HEADER))) {
            throw ApiException::proxySecretInvalid();
        }

        return $handler->handle($request);
    }
}
