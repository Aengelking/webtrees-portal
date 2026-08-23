<?php

declare(strict_types=1);

namespace Engelking\Webtrees\PortalApi\Http\Middleware;

use Engelking\Webtrees\PortalApi\Http\ApiException;
use Engelking\Webtrees\PortalApi\Http\Json;
use Engelking\Webtrees\PortalApi\Services\RememberedDevices;
use Fisharebest\Webtrees\Auth;
use Fisharebest\Webtrees\Contracts\UserInterface;
use Fisharebest\Webtrees\Log;
use Fisharebest\Webtrees\Registry;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;

use function time;

/**
 * The member who ticked "Angemeldet bleiben", coming back a fortnight later.
 *
 * Runs before `RequireAuthentication` and does nothing at all to a request
 * that already has a session — which is every request but the first of a
 * visit. When there is no session and the browser offers a remember cookie,
 * this is what turns one into the other.
 *
 * **Only on the portal's own routes.** This middleware is listed on the
 * module's route map and nowhere else, so a remember cookie is never a way
 * into webtrees' own pages: it opens the portal API, and the portal API is
 * the only thing that looks at it. That the cookie is scoped to the portal's
 * origin by the Worker (`edge/proxy.ts`) makes the same point from the other
 * end — the webtrees host never sees it.
 *
 * **The token is spent by being used**, so the replacement has to reach the
 * browser or that device is locked out of its next visit. That is why the
 * refusals downstream are caught here (see `answer()`) rather than left to
 * `ApiEnvelope`: a member who asked for a record they may not see gets a 404,
 * and must still get their next cookie with it.
 */
class ResumeRememberedSession implements MiddlewareInterface
{
    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        if (Auth::check()) {
            return $handler->handle($request);
        }

        $offered = CookieJar::read($request, RememberedDevices::COOKIE);

        if ($offered === '') {
            return $handler->handle($request);
        }

        $devices = Registry::container()->get(RememberedDevices::class);
        $device  = $devices->resume($offered);

        if ($device === null) {
            // Unusable, for any of the reasons this cannot tell apart. Clear
            // it: a cookie the portal will refuse for the next thirty days is
            // a cookie that should stop being sent.
            return CookieJar::clear($this->answer($request, $handler), $request, RememberedDevices::COOKIE);
        }

        // `Auth::login()` regenerates the session id itself, which is what
        // keeps this from being session fixation: the id a request arrived
        // with, unauthenticated, is not the id it leaves signed in under.
        Auth::login($device->user);

        Log::addAuthenticationLog('Portal: resumed a remembered session for ' . $device->user->userName());

        $device->user->setPreference(UserInterface::PREF_TIMESTAMP_ACTIVE, (string) time());

        return CookieJar::set(
            $this->answer($request, $handler),
            $request,
            RememberedDevices::COOKIE,
            $device->cookie,
            $devices->lifetime(),
        );
    }

    /**
     * The handler's response, or the refusal it threw, as a response.
     *
     * `ApiEnvelope` sits outside this middleware and would turn that refusal
     * into exactly this — an `ApiException` is a refusal the module worded on
     * purpose, and the envelope deliberately records none of them — but it
     * would do so where a `Set-Cookie` can no longer be attached. An exception
     * carries no headers, and this middleware has one it must send: either the
     * replacement for a token it has just spent, or the instruction to drop a
     * cookie that will never work again.
     *
     * Only `ApiException`. Anything else is a bug, and belongs to the
     * envelope, which records it and hands the member a reference — a cookie
     * is not worth catching that to attach.
     */
    private function answer(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        try {
            return $handler->handle($request);
        } catch (ApiException $exception) {
            return Json::error($exception);
        }
    }
}
