<?php

declare(strict_types=1);

namespace Engelking\Webtrees\PortalApi\Http\Middleware;

use Engelking\Webtrees\PortalApi\Http\ApiException;
use Engelking\Webtrees\PortalApi\Http\Json;
use Engelking\Webtrees\PortalApi\Services\ErrorLog;
use Fig\Http\Message\StatusCodeInterface;
use Fisharebest\Webtrees\I18N;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Throwable;

use function error_log;
use function is_object;
use function is_string;
use function property_exists;

/**
 * The outermost middleware on every API route.
 *
 * It does three things that must be true of *every* API response:
 *
 *  - errors are JSON, never a webtrees HTML error page. webtrees' own
 *    HandleExceptions middleware renders HTML, which a JSON client cannot
 *    use, and an unhandled exception's message can disclose internals;
 *  - a failure that is the portal's own fault is recorded where an
 *    administrator will see it, and the member is given a reference to quote;
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

    public function __construct(private readonly ErrorLog $errors)
    {
    }

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        try {
            $response = $handler->handle($request);
        } catch (ApiException $exception) {
            // Deliberately not recorded, including the 503 for a portal that
            // is not configured. An ApiException is a refusal this module
            // wrote on purpose and worded for the member; recording every
            // 404 for a record somebody may not see would bury the real
            // failures under ordinary traffic. Misconfiguration has a better
            // home in the diagnosis screen, which says what to fix rather
            // than that something went wrong — and an uptime monitor polling
            // a half-configured install would otherwise fill the table.
            $response = Json::error($exception);
        } catch (Throwable $exception) {
            // Log the real error; tell the client nothing about it.
            error_log('portal_api: ' . $exception::class . ': ' . $exception->getMessage() . ' in ' . $exception->getFile() . ':' . $exception->getLine());

            // Anything that reaches here is a bug: nothing in the module
            // meant to produce it, and the member cannot act on it. This is
            // what the error log is for.
            $reference = $this->recordError($request, $exception, StatusCodeInterface::STATUS_INTERNAL_SERVER_ERROR);

            $response = Json::error(new ApiException(
                'server_error',
                StatusCodeInterface::STATUS_INTERNAL_SERVER_ERROR,
                I18N::translate('An error occurred. Please try again later.')
            ), $reference);
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

    /**
     * Never allowed to make things worse. `ErrorLog` already swallows its own
     * failures; this catch is the second belt, for anything thrown on the way
     * to calling it.
     */
    private function recordError(ServerRequestInterface $request, Throwable $exception, int $status): string
    {
        try {
            return $this->errors->record(
                $exception,
                $status,
                $this->routeName($request),
                $request->getMethod()
            );
        } catch (Throwable $failure) {
            error_log('portal_api: could not record an error: ' . $failure->getMessage());

            return '';
        }
    }

    /**
     * The route's name, which in this module is the handler's class name.
     *
     * Deliberately not the request path: `/individuals/X123` names a record,
     * and the error log is not a place to accumulate those. See
     * Schema/Migration2.php.
     */
    private function routeName(ServerRequestInterface $request): string
    {
        $route = $request->getAttribute('route');

        if (is_object($route) && property_exists($route, 'name') && is_string($route->name)) {
            return $route->name;
        }

        return '';
    }
}
