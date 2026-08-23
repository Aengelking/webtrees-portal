<?php

declare(strict_types=1);

namespace Engelking\Webtrees\PortalApi\Http\Middleware;

use Fisharebest\Webtrees\Validator;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

use function gmdate;
use function implode;
use function is_string;
use function parse_url;
use function time;

use const PHP_URL_SCHEME;

/**
 * The one place a `Set-Cookie` is written.
 *
 * The portal sets exactly one cookie of its own — the remembered device — and
 * it is a credential, so the attributes are not a detail to be retyped at each
 * call site. They are here, once:
 *
 *  - **HttpOnly**, because no script in the portal has any business reading
 *    it, and a script that could read it could send it somewhere;
 *  - **SameSite=Lax**, so it does not travel on a request some other site
 *    made — the portal is a same-origin application and never needs it to;
 *  - **Secure**, wherever webtrees itself is reached over https. Mirrored from
 *    `base_url` exactly as `Session::start()` does, so a local development
 *    install over http still works and a real one cannot be talked out of it;
 *  - **Path=/**, which the Worker would force anyway (`edge/proxy.ts`) and
 *    which is right here: the cookie is for the portal, not for /api.
 *
 * Deliberately not `Domain=`. A host-only cookie belongs to the portal's own
 * origin and to nothing beside it, which is the same property the session
 * cookie gets from the Worker rewriting it.
 */
final class CookieJar
{
    /**
     * What the browser is offering under this name, or an empty string.
     *
     * Guarded, because `$_COOKIE` is not a map of strings: a client that sends
     * `PORTAL_REMEMBER[x]=y` gets an array put there by PHP's own parsing.
     * Under `strict_types` that is a `TypeError` the moment it reaches
     * anything typed `string` — `RememberedDevices::forget()`, on sign-in and
     * sign-out — and a `TypeError` is a 500 that one stranger can produce with
     * one request. Every reader goes through here so the assumption is made
     * once, and in a place that states it.
     */
    public static function read(ServerRequestInterface $request, string $name): string
    {
        $value = $request->getCookieParams()[$name] ?? '';

        return is_string($value) ? $value : '';
    }

    public static function set(
        ResponseInterface $response,
        ServerRequestInterface $request,
        string $name,
        string $value,
        int $lifetime,
    ): ResponseInterface {
        return $response->withAddedHeader('Set-Cookie', self::header(
            $request,
            $name,
            $value,
            time() + $lifetime,
            $lifetime,
        ));
    }

    /**
     * Ask the browser to drop it.
     *
     * An expiry in the past and an empty value, which is the only way to
     * delete a cookie: there is no verb for it. The attributes have to match
     * the ones it was set with or the browser keeps the original, which is why
     * this goes through the same builder.
     */
    public static function clear(
        ResponseInterface $response,
        ServerRequestInterface $request,
        string $name,
    ): ResponseInterface {
        return $response->withAddedHeader('Set-Cookie', self::header($request, $name, '', 0, 0));
    }

    private static function header(
        ServerRequestInterface $request,
        string $name,
        string $value,
        int $expires,
        int $max_age,
    ): string {
        $url    = Validator::attributes($request)->string('base_url', '');
        $secure = parse_url($url, PHP_URL_SCHEME) === 'https';

        $parts = [
            $name . '=' . $value,
            'Path=/',
            // Both, on purpose. Max-Age is what every current browser uses;
            // Expires is what the handful that ignore it use, and a cookie
            // meant to outlive the browser must not quietly become a session
            // cookie in one of them.
            'Max-Age=' . $max_age,
            'Expires=' . gmdate('D, d M Y H:i:s \G\M\T', $expires),
            'HttpOnly',
            'SameSite=Lax',
        ];

        if ($secure) {
            $parts[] = 'Secure';
        }

        return implode('; ', $parts);
    }
}
