<?php

declare(strict_types=1);

namespace Engelking\Webtrees\PortalApi\Http\Middleware;

use Engelking\Webtrees\PortalApi\Http\ApiException;
use Engelking\Webtrees\PortalApi\Http\Json;
use Fig\Http\Message\StatusCodeInterface;
use Fisharebest\Webtrees\I18N;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Throwable;

use function error_log;

/**
 * The outermost middleware on every API route.
 *
 * It does two things that must be true of *every* API response:
 *
 *  - errors are JSON, never a webtrees HTML error page. webtrees' own
 *    HandleExceptions middleware renders HTML, which a JSON client cannot
 *    use, and an unhandled exception's message can disclose internals;
 *  - the response is never cached. A cached authenticated response would
 *    show one member another member's relatives.
 */
class ApiEnvelope implements MiddlewareInterface
{
    /**
     * A handler sets this to the number of seconds a *browser* may keep the
     * response. It is translated into a `Cache-Control` header and removed.
     *
     * Only photographs use it. Everything else in this API is small JSON about
     * one member, where re-fetching costs nothing and keeping a copy is how
     * one member ends up looking at another's relatives. Making the exception
     * explicit, and spelled `private`, is the point: no handler can widen it
     * to a shared cache by accident, because the word `public` never appears.
     */
    public const string PRIVATE_CACHE_HEADER = 'X-Portal-Private-Cache';

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        try {
            $response = $handler->handle($request);
        } catch (ApiException $exception) {
            $response = Json::error($exception);
        } catch (Throwable $exception) {
            // Log the real error; tell the client nothing about it.
            error_log('portal_api: ' . $exception::class . ': ' . $exception->getMessage() . ' in ' . $exception->getFile() . ':' . $exception->getLine());

            $response = Json::error(new ApiException(
                'server_error',
                StatusCodeInterface::STATUS_INTERNAL_SERVER_ERROR,
                I18N::translate('An error occurred. Please try again later.')
            ));
        }

        $seconds = $response->getHeaderLine(self::PRIVATE_CACHE_HEADER);

        $cache_control = $seconds === ''
            ? 'private, no-store'
            : 'private, max-age=' . (int) $seconds;

        return $response
            ->withoutHeader(self::PRIVATE_CACHE_HEADER)
            ->withHeader('Cache-Control', $cache_control)
            ->withHeader('Pragma', 'no-cache')
            ->withHeader('Vary', 'Cookie, Accept-Language')
            ->withHeader('X-Content-Type-Options', 'nosniff')
            ->withHeader('X-Robots-Tag', 'noindex, nofollow');
    }
}
