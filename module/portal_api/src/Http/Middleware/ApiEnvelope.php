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

        return $response
            ->withHeader('Cache-Control', 'private, no-store')
            ->withHeader('Pragma', 'no-cache')
            ->withHeader('Vary', 'Cookie')
            ->withHeader('X-Content-Type-Options', 'nosniff')
            ->withHeader('X-Robots-Tag', 'noindex, nofollow');
    }
}
