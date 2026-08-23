<?php

declare(strict_types=1);

namespace Engelking\Webtrees\PortalApi\Tests;

use Engelking\Webtrees\PortalApi\Http\RequestHandlers\CsrfTokenRead;
use Engelking\Webtrees\PortalApi\Http\RequestHandlers\IndividualRead;
use Engelking\Webtrees\PortalApi\Http\RequestHandlers\MeRead;
use Engelking\Webtrees\PortalApi\Http\RequestHandlers\PasswordResetCreate;
use Engelking\Webtrees\PortalApi\Http\RequestHandlers\SessionCreate;
use Engelking\Webtrees\PortalApi\Http\RequestHandlers\SessionDelete;
use Engelking\Webtrees\PortalApi\PortalApiModule;
use Engelking\Webtrees\PortalApi\Services\RememberedDevices;
use Fig\Http\Message\RequestMethodInterface;
use Fig\Http\Message\StatusCodeInterface;
use Fisharebest\Webtrees\Auth;
use Fisharebest\Webtrees\Contracts\UserInterface;
use Fisharebest\Webtrees\DB;
use Fisharebest\Webtrees\User;
use PHPUnit\Framework\Attributes\CoversNothing;
use Psr\Http\Message\ResponseInterface;

use function explode;
use function str_contains;
use function time;

/**
 * "Angemeldet bleiben".
 *
 * A second credential, kept on a device, that lets somebody back in without a
 * password. Everything worth asserting about it is about what it must *not*
 * do: outlive a sign-out, survive a password reset, work twice, or exist at
 * all where the family has not asked for it.
 */
#[CoversNothing]
class RememberTest extends PortalTestCase
{
    private const string PASSWORD = 'correct-horse-battery-staple';

    private User $member;

    protected function setUp(): void
    {
        parent::setUp();

        $this->member = $this->createUser('anna', 'Anna Beispiel', self::PASSWORD, UserInterface::ROLE_MEMBER, 'X1');
        $this->module()->setPreference(PortalApiModule::SETTING_REMEMBER_DAYS, '30');
    }

    // -----------------------------------------------------------------
    // Being offered it at all
    // -----------------------------------------------------------------

    /**
     * The login screen has no session and therefore no `/me` to ask, so the
     * one endpoint it does call before signing in has to carry the answer.
     */
    public function testTheCsrfEndpointSaysHowManyDaysAreOnOffer(): void
    {
        self::assertSame(30, $this->json($this->api(CsrfTokenRead::class))['remember_days']);
    }

    public function testZeroDaysMeansTheOfferIsNotMade(): void
    {
        $this->module()->setPreference(PortalApiModule::SETTING_REMEMBER_DAYS, '0');

        self::assertSame(0, $this->json($this->api(CsrfTokenRead::class))['remember_days']);
    }

    /**
     * Not "offered and quietly ignored": a portal with this switched off must
     * hand out no cookie even to a request that asks for one, because the
     * request could come from anywhere.
     */
    public function testAskingToBeRememberedDoesNothingWhenTheFamilyHasNotAllowedIt(): void
    {
        $this->module()->setPreference(PortalApiModule::SETTING_REMEMBER_DAYS, '0');

        $response = $this->signIn(remember: true);

        self::assertSame(StatusCodeInterface::STATUS_OK, $response->getStatusCode());
        self::assertNull($this->cookie($response));
        self::assertSame(0, DB::table(RememberedDevices::TABLE)->count());
    }

    // -----------------------------------------------------------------
    // Getting one
    // -----------------------------------------------------------------

    public function testSigningInWithoutTheBoxTickedLeavesNoCookie(): void
    {
        $response = $this->signIn(remember: false);

        // Cleared, not merely absent — a member who ticked it last week and
        // did not this week has changed their mind, and the old cookie is
        // still in the browser.
        self::assertNotNull($this->cookie($response));
        self::assertSame('', $this->cookie($response));
        self::assertSame(0, DB::table(RememberedDevices::TABLE)->count());
    }

    public function testTheCookieIsNotReadableByAScriptAndNotSentToOtherSites(): void
    {
        $header = $this->setCookieHeader($this->signIn(remember: true));

        self::assertStringContainsString('HttpOnly', $header);
        self::assertStringContainsString('SameSite=Lax', $header);
        self::assertStringContainsString('Secure', $header);
        self::assertStringContainsString('Path=/', $header);
        self::assertStringContainsString('Max-Age=' . 30 * 86400, $header);

        // A cookie for the portal's own origin and nothing beside it.
        self::assertStringNotContainsString('Domain=', $header);
    }

    /** The database gets a hash. What is in the browser exists nowhere else. */
    public function testTheTokenItselfIsNeverStored(): void
    {
        $cookie = $this->cookie($this->signIn(remember: true));
        [, $token] = explode(':', (string) $cookie, 2);

        $row = DB::table(RememberedDevices::TABLE)->first();

        self::assertNotNull($row);
        self::assertNotSame($token, $row->token_hash);
        self::assertSame(hash('sha256', $token), $row->token_hash);
    }

    // -----------------------------------------------------------------
    // Coming back
    // -----------------------------------------------------------------

    public function testAWeekLaterTheCookieGetsBackInWithoutAPassword(): void
    {
        $cookie = $this->cookie($this->signIn(remember: true));
        $this->forgetTheSession();

        $response = $this->api(MeRead::class, cookies: [RememberedDevices::COOKIE => (string) $cookie]);

        self::assertSame(StatusCodeInterface::STATUS_OK, $response->getStatusCode());
        self::assertSame('anna', $this->json($response)['user']['username']);
        self::assertSame($this->member->id(), Auth::id());
    }

    public function testNoCookieIsStillA401(): void
    {
        $this->forgetTheSession();

        self::assertSame(
            StatusCodeInterface::STATUS_UNAUTHORIZED,
            $this->api(MeRead::class)->getStatusCode()
        );
    }

    public function testNonsenseIsRefusedWithoutTouchingTheDatabase(): void
    {
        $this->signIn(remember: true);
        $this->forgetTheSession();

        $response = $this->api(MeRead::class, cookies: [RememberedDevices::COOKIE => 'not-a-cookie']);

        self::assertSame(StatusCodeInterface::STATUS_UNAUTHORIZED, $response->getStatusCode());
        self::assertSame(1, DB::table(RememberedDevices::TABLE)->count());
    }

    /**
     * `$_COOKIE` is not a map of strings. Send `PORTAL_REMEMBER[x]=y` and PHP
     * puts an array there — which under `strict_types` is a `TypeError` at
     * the first thing typed `string`, and a `TypeError` is a 500 that one
     * stranger can produce with one request.
     *
     * Signing in and signing out are where it would fire, because they are
     * what hand the value to `forget()`. Reading is asserted alongside, so
     * that no route is left assuming a shape.
     */
    public function testACookieThatIsNotEvenAStringIsRefusedRatherThanFatal(): void
    {
        $malformed = [RememberedDevices::COOKIE => ['x' => 'y']];

        self::assertSame(
            StatusCodeInterface::STATUS_OK,
            $this->api(
                SessionCreate::class,
                RequestMethodInterface::METHOD_POST,
                body: ['username' => 'anna', 'password' => self::PASSWORD, 'remember' => false],
                headers: $this->csrfHeader(),
                cookies: $malformed,
            )->getStatusCode()
        );

        self::assertSame(
            StatusCodeInterface::STATUS_OK,
            $this->api(
                SessionDelete::class,
                RequestMethodInterface::METHOD_DELETE,
                headers: $this->csrfHeader(),
                cookies: $malformed,
            )->getStatusCode()
        );

        $this->forgetTheSession();

        self::assertSame(
            StatusCodeInterface::STATUS_UNAUTHORIZED,
            $this->api(MeRead::class, cookies: $malformed)->getStatusCode()
        );
    }

    public function testAnExpiredCookieIsRefusedAndTheRowGoes(): void
    {
        $cookie = $this->cookie($this->signIn(remember: true));
        $this->forgetTheSession();

        DB::table(RememberedDevices::TABLE)->update(['expires_at' => time() - 1]);

        $response = $this->api(MeRead::class, cookies: [RememberedDevices::COOKIE => (string) $cookie]);

        self::assertSame(StatusCodeInterface::STATUS_UNAUTHORIZED, $response->getStatusCode());
        self::assertSame(0, DB::table(RememberedDevices::TABLE)->count());

        // And the browser is told to stop sending it. The refusal is thrown
        // rather than returned, so this only works because the middleware
        // catches it — see `ResumeRememberedSession::answer()`.
        self::assertSame('', $this->cookie($response));
    }

    /**
     * The token was spent the moment the cookie was read, so its replacement
     * has to travel even on a reply that refuses the request. Without it, a
     * member who asked for a record they may not see would be locked out of
     * their own next visit by the 404.
     */
    public function testARefusedRequestStillCarriesTheNextCookie(): void
    {
        $first = (string) $this->cookie($this->signIn(remember: true));
        $this->forgetTheSession();

        $response = $this->api(
            IndividualRead::class,
            attributes: ['xref' => 'X404'],
            cookies: [RememberedDevices::COOKIE => $first],
        );

        self::assertSame(StatusCodeInterface::STATUS_NOT_FOUND, $response->getStatusCode());

        $second = (string) $this->cookie($response);

        self::assertNotSame('', $second);
        self::assertNotSame($first, $second);

        $this->forgetTheSession();

        self::assertSame(
            StatusCodeInterface::STATUS_OK,
            $this->api(MeRead::class, cookies: [RememberedDevices::COOKIE => $second])->getStatusCode()
        );
    }

    /**
     * The token is spent by being used, so the reply has to carry its
     * replacement. A resume that signs the member in and says nothing has
     * locked that device out of its own next visit.
     */
    public function testResumingHandsBackANewCookie(): void
    {
        $first = (string) $this->cookie($this->signIn(remember: true));
        $this->forgetTheSession();

        $response = $this->api(MeRead::class, cookies: [RememberedDevices::COOKIE => $first]);
        $second   = (string) $this->cookie($response);

        self::assertNotSame('', $second);
        self::assertNotSame($first, $second);

        // The same device throughout: the series is what says so.
        self::assertSame(explode(':', $first)[0], explode(':', $second)[0]);
        self::assertSame(1, DB::table(RememberedDevices::TABLE)->count());
    }

    /**
     * Two requests that left one telephone together arrive apart, and the
     * second carries a token the first has already replaced. Treating that as
     * theft would sign a member out of everything for having a poor
     * connection.
     */
    public function testTheTokenJustReplacedStillWorksForAMoment(): void
    {
        $first = (string) $this->cookie($this->signIn(remember: true));
        $this->forgetTheSession();

        $this->api(MeRead::class, cookies: [RememberedDevices::COOKIE => $first]);
        $this->forgetTheSession();

        $response = $this->api(MeRead::class, cookies: [RememberedDevices::COOKIE => $first]);

        self::assertSame(StatusCodeInterface::STATUS_OK, $response->getStatusCode());
        self::assertSame(1, DB::table(RememberedDevices::TABLE)->count());
    }

    /**
     * The assertion this design exists for. A cookie copied off a device is
     * used later, after the member has been back — so it presents a token
     * that was spent long ago. One of the two holders is not the member and
     * there is no telling which, so neither is trusted again.
     */
    public function testACookieUsedLongAfterItWasSpentForgetsEveryDevice(): void
    {
        $stolen = (string) $this->cookie($this->signIn(remember: true));
        $this->forgetTheSession();

        // The member, back on their own telephone.
        $this->api(MeRead::class, cookies: [RememberedDevices::COOKIE => $stolen]);
        $this->forgetTheSession();

        // Long enough ago that this is not two requests crossing.
        DB::table(RememberedDevices::TABLE)->update(['rotated_at' => time() - 3600]);

        $response = $this->api(MeRead::class, cookies: [RememberedDevices::COOKIE => $stolen]);

        self::assertSame(StatusCodeInterface::STATUS_UNAUTHORIZED, $response->getStatusCode());
        self::assertSame(0, DB::table(RememberedDevices::TABLE)->count());
    }

    // -----------------------------------------------------------------
    // Ending it
    // -----------------------------------------------------------------

    public function testSigningOutStopsTheCookieWorking(): void
    {
        $cookie = (string) $this->cookie($this->signIn(remember: true));

        $response = $this->api(
            SessionDelete::class,
            RequestMethodInterface::METHOD_DELETE,
            headers: $this->csrfHeader(),
            cookies: [RememberedDevices::COOKIE => $cookie],
        );

        self::assertSame('', $this->cookie($response));
        self::assertSame(0, DB::table(RememberedDevices::TABLE)->count());

        // The reply is a `CsrfToken`, and the screen it lands on is the login
        // screen — which needs both halves of that body.
        self::assertSame(30, $this->json($response)['remember_days']);

        $this->forgetTheSession();

        self::assertSame(
            StatusCodeInterface::STATUS_UNAUTHORIZED,
            $this->api(MeRead::class, cookies: [RememberedDevices::COOKIE => $cookie])->getStatusCode()
        );
    }

    /** One telephone signing out says nothing about the tablet. */
    public function testSigningOutOnOneDeviceLeavesTheOtherRemembered(): void
    {
        $phone = (string) $this->cookie($this->signIn(remember: true));
        $this->forgetTheSession();
        $tablet = (string) $this->cookie($this->signIn(remember: true));

        $this->api(
            SessionDelete::class,
            RequestMethodInterface::METHOD_DELETE,
            headers: $this->csrfHeader(),
            cookies: [RememberedDevices::COOKIE => $phone],
        );

        $this->forgetTheSession();

        self::assertSame(
            StatusCodeInterface::STATUS_OK,
            $this->api(MeRead::class, cookies: [RememberedDevices::COOKIE => $tablet])->getStatusCode()
        );
    }

    /**
     * Somebody resetting a password often believes another person is in their
     * account. A new password that leaves a month-old cookie working would
     * answer that with nothing.
     */
    public function testResettingAPasswordForgetsEveryRememberedDevice(): void
    {
        $cookie = (string) $this->cookie($this->signIn(remember: true));
        $this->forgetTheSession();

        $this->member->setPreference('password-token', 'reset-token');
        $this->member->setPreference('password-token-expire', (string) (time() + 3600));

        $response = $this->api(
            PasswordResetCreate::class,
            RequestMethodInterface::METHOD_POST,
            body: ['token' => 'reset-token', 'password' => 'a-brand-new-password'],
            headers: $this->csrfHeader(),
        );

        self::assertSame(StatusCodeInterface::STATUS_OK, $response->getStatusCode());
        self::assertSame(0, DB::table(RememberedDevices::TABLE)->count());
        self::assertSame('', $this->cookie($response));

        $this->forgetTheSession();

        self::assertSame(
            StatusCodeInterface::STATUS_UNAUTHORIZED,
            $this->api(MeRead::class, cookies: [RememberedDevices::COOKIE => $cookie])->getStatusCode()
        );
    }

    // -----------------------------------------------------------------

    private function signIn(bool $remember): ResponseInterface
    {
        return $this->api(
            SessionCreate::class,
            RequestMethodInterface::METHOD_POST,
            body: ['username' => 'anna', 'password' => self::PASSWORD, 'remember' => $remember],
            headers: $this->csrfHeader(),
        );
    }

    /** What the browser would do when the session cookie dies with it. */
    private function forgetTheSession(): void
    {
        Auth::logout();
    }

    /** The cookie's value, or null when the response set none. */
    private function cookie(ResponseInterface $response): string|null
    {
        $header = $this->setCookieHeader($response);

        if ($header === '') {
            return null;
        }

        return explode(';', explode('=', $header, 2)[1])[0];
    }

    private function setCookieHeader(ResponseInterface $response): string
    {
        foreach ($response->getHeader('Set-Cookie') as $header) {
            if (str_contains($header, RememberedDevices::COOKIE . '=')) {
                return $header;
            }
        }

        return '';
    }
}
