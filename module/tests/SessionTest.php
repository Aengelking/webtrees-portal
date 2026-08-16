<?php

declare(strict_types=1);

namespace Engelking\Webtrees\PortalApi\Tests;

use Engelking\Webtrees\PortalApi\Http\Middleware\RequireProxySecret;
use Engelking\Webtrees\PortalApi\Http\RequestHandlers\CsrfTokenRead;
use Engelking\Webtrees\PortalApi\Http\RequestHandlers\MeRead;
use Engelking\Webtrees\PortalApi\Http\RequestHandlers\SessionCreate;
use Engelking\Webtrees\PortalApi\Http\RequestHandlers\SessionDelete;
use Engelking\Webtrees\PortalApi\PortalApiModule;
use Fig\Http\Message\RequestMethodInterface;
use Fig\Http\Message\StatusCodeInterface;
use Fisharebest\Webtrees\Auth;
use Fisharebest\Webtrees\Contracts\UserInterface;
use Fisharebest\Webtrees\User;
use PHPUnit\Framework\Attributes\CoversNothing;

/**
 * Session authentication: §5 of the handoff.
 *
 * The recurring assertion is that every kind of failure looks the same from
 * outside. An error that distinguishes "wrong password" from "no such user"
 * turns the login form into an account-enumeration tool.
 */
#[CoversNothing]
class SessionTest extends PortalTestCase
{
    private const string PASSWORD = 'correct-horse-battery-staple';

    private User $member;

    protected function setUp(): void
    {
        parent::setUp();

        $this->member = $this->createUser('anna', 'Anna Beispiel', self::PASSWORD, UserInterface::ROLE_MEMBER, 'X1');
    }

    // -----------------------------------------------------------------
    // Signing in
    // -----------------------------------------------------------------

    public function testAValidPasswordSignsIn(): void
    {
        $response = $this->postSession('anna', self::PASSWORD);
        $body     = $this->json($response);

        self::assertSame(StatusCodeInterface::STATUS_OK, $response->getStatusCode());
        self::assertSame('anna', $body['user']['username']);
        self::assertSame('member', $body['user']['role']);
        self::assertSame('X1', $body['individual']['xref']);
        self::assertNotSame('', $body['csrf_token']);
        self::assertSame($this->member->id(), Auth::id());
    }

    public function testAnEmailAddressAlsoWorksAsAnIdentifier(): void
    {
        $response = $this->postSession('anna@example.test', self::PASSWORD);

        self::assertSame(StatusCodeInterface::STATUS_OK, $response->getStatusCode());
    }

    public function testTheSessionResponseMatchesTheMeEndpoint(): void
    {
        $login = $this->json($this->postSession('anna', self::PASSWORD));
        $me    = $this->json($this->api(MeRead::class));

        unset($login['csrf_token'], $me['csrf_token']);

        self::assertSame($login, $me);
    }

    public function testNoGenealogyDataLeaksIntoAFailedLogin(): void
    {
        $response = $this->postSession('anna', 'wrong');

        self::assertStringNotContainsString('Beispiel', $this->raw($response));
        self::assertStringNotContainsString('X1', $this->raw($response));
    }

    // -----------------------------------------------------------------
    // Failures are indistinguishable
    // -----------------------------------------------------------------

    public function testEveryKindOfFailureLooksTheSame(): void
    {
        $this->createUser('unverified', 'Unverified', self::PASSWORD, UserInterface::ROLE_MEMBER)
            ->setPreference(UserInterface::PREF_IS_EMAIL_VERIFIED, '0');

        $this->createUser('unapproved', 'Unapproved', self::PASSWORD, UserInterface::ROLE_MEMBER)
            ->setPreference(UserInterface::PREF_IS_ACCOUNT_APPROVED, '0');

        $attempts = [
            'wrong password'    => ['anna', 'wrong'],
            'no such user'      => ['nobody', self::PASSWORD],
            'unverified email'  => ['unverified', self::PASSWORD],
            'unapproved by admin' => ['unapproved', self::PASSWORD],
        ];

        $seen = [];

        foreach ($attempts as $label => [$username, $password]) {
            $response = $this->postSession($username, $password);

            self::assertSame(StatusCodeInterface::STATUS_UNAUTHORIZED, $response->getStatusCode(), $label);
            self::assertFalse(Auth::check(), $label);

            $seen[] = $this->json($response);
        }

        self::assertCount(1, array_unique(array_map('serialize', $seen)), 'All four failures must produce the same body.');
        self::assertSame('invalid_credentials', $seen[0]['error']);
    }

    public function testAnIncompleteBodyIsRejected(): void
    {
        $response = $this->api(SessionCreate::class, RequestMethodInterface::METHOD_POST, body: ['username' => 'anna'], headers: $this->csrfHeader());

        self::assertSame(StatusCodeInterface::STATUS_BAD_REQUEST, $response->getStatusCode());
        self::assertSame('bad_request', $this->json($response)['error']);
    }

    // -----------------------------------------------------------------
    // Rate limiting
    // -----------------------------------------------------------------

    public function testRepeatedFailuresLockOutEvenTheCorrectPassword(): void
    {
        $limit = PortalApiModule::DEFAULT_RATE_LIMIT_USER;

        for ($i = 0; $i < $limit; $i++) {
            self::assertSame(StatusCodeInterface::STATUS_UNAUTHORIZED, $this->postSession('anna', 'wrong')->getStatusCode());
        }

        $response = $this->postSession('anna', self::PASSWORD);

        self::assertSame(StatusCodeInterface::STATUS_UNAUTHORIZED, $response->getStatusCode());
        self::assertFalse(Auth::check(), 'The rate limiter must refuse the login, not merely delay it.');

        // The lock-out must not be distinguishable from a wrong password,
        // or it becomes a way to confirm that a username exists.
        self::assertSame($this->json($this->postSession('nobody', 'wrong')), $this->json($response));
    }

    public function testARateLimitOfZeroDisablesTheLimit(): void
    {
        $this->module()->setPreference(PortalApiModule::SETTING_RATE_LIMIT_USER, '0');
        $this->module()->setPreference(PortalApiModule::SETTING_RATE_LIMIT_IP, '0');

        for ($i = 0; $i < PortalApiModule::DEFAULT_RATE_LIMIT_USER + 2; $i++) {
            $this->postSession('anna', 'wrong');
        }

        self::assertSame(StatusCodeInterface::STATUS_OK, $this->postSession('anna', self::PASSWORD)->getStatusCode());
    }

    public function testASuccessfulLoginClearsEarlierFailures(): void
    {
        $this->postSession('anna', 'wrong');
        $this->postSession('anna', 'wrong');
        $this->postSession('anna', self::PASSWORD);

        // Back to a full budget of attempts.
        for ($i = 0; $i < PortalApiModule::DEFAULT_RATE_LIMIT_USER; $i++) {
            self::assertSame(StatusCodeInterface::STATUS_UNAUTHORIZED, $this->postSession('anna', 'wrong')->getStatusCode());
        }
    }

    // -----------------------------------------------------------------
    // CSRF
    // -----------------------------------------------------------------

    public function testAnUnsafeRequestWithoutACsrfTokenIsRejected(): void
    {
        $response = $this->api(SessionCreate::class, RequestMethodInterface::METHOD_POST, body: ['username' => 'anna', 'password' => self::PASSWORD]);

        self::assertSame(StatusCodeInterface::STATUS_FORBIDDEN, $response->getStatusCode());
        self::assertSame('csrf_token_invalid', $this->json($response)['error']);
        self::assertFalse(Auth::check());
    }

    public function testAnUnsafeRequestWithTheWrongCsrfTokenIsRejected(): void
    {
        $response = $this->api(
            SessionCreate::class,
            RequestMethodInterface::METHOD_POST,
            body: ['username' => 'anna', 'password' => self::PASSWORD],
            headers: ['X-CSRF-TOKEN' => 'not-the-token'],
        );

        self::assertSame(StatusCodeInterface::STATUS_FORBIDDEN, $response->getStatusCode());
        self::assertFalse(Auth::check());
    }

    public function testTheCsrfEndpointIsAvailableWithoutSigningIn(): void
    {
        $response = $this->api(CsrfTokenRead::class);
        $body     = $this->json($response);

        self::assertSame(StatusCodeInterface::STATUS_OK, $response->getStatusCode());
        self::assertNotSame('', $body['csrf_token']);
        self::assertSame('private, no-store', $response->getHeaderLine('Cache-Control'));
    }

    // -----------------------------------------------------------------
    // Signing out
    // -----------------------------------------------------------------

    public function testLoggingOutEndsTheSession(): void
    {
        $this->postSession('anna', self::PASSWORD);
        self::assertTrue(Auth::check());

        $response = $this->api(SessionDelete::class, RequestMethodInterface::METHOD_DELETE, headers: $this->csrfHeader());

        self::assertSame(StatusCodeInterface::STATUS_OK, $response->getStatusCode());
        self::assertFalse(Auth::check());
        self::assertNotSame('', $this->json($response)['csrf_token']);

        // And the endpoints behind authentication are shut again.
        self::assertSame(StatusCodeInterface::STATUS_UNAUTHORIZED, $this->api(MeRead::class)->getStatusCode());
    }

    public function testLoggingOutRequiresACsrfToken(): void
    {
        $this->postSession('anna', self::PASSWORD);

        $response = $this->api(SessionDelete::class, RequestMethodInterface::METHOD_DELETE);

        self::assertSame(StatusCodeInterface::STATUS_FORBIDDEN, $response->getStatusCode());
        self::assertTrue(Auth::check(), 'A cross-site logout must not succeed.');
    }

    // -----------------------------------------------------------------
    // Proxy secret
    // -----------------------------------------------------------------

    public function testTheProxySecretIsIgnoredWhenNotConfigured(): void
    {
        self::assertSame(StatusCodeInterface::STATUS_OK, $this->api(CsrfTokenRead::class)->getStatusCode());
    }

    public function testTheProxySecretIsRequiredWhenConfigured(): void
    {
        $this->module()->setPreference(PortalApiModule::SETTING_PROXY_SECRET, 's3cret');

        $without = $this->api(CsrfTokenRead::class);
        self::assertSame(StatusCodeInterface::STATUS_FORBIDDEN, $without->getStatusCode());
        self::assertSame('proxy_secret_invalid', $this->json($without)['error']);

        $wrong = $this->api(CsrfTokenRead::class, headers: [RequireProxySecret::HEADER => 'guess']);
        self::assertSame(StatusCodeInterface::STATUS_FORBIDDEN, $wrong->getStatusCode());

        $with = $this->api(CsrfTokenRead::class, headers: [RequireProxySecret::HEADER => 's3cret']);
        self::assertSame(StatusCodeInterface::STATUS_OK, $with->getStatusCode());
    }

    private function postSession(string $username, string $password): mixed
    {
        return $this->api(
            SessionCreate::class,
            RequestMethodInterface::METHOD_POST,
            body: ['username' => $username, 'password' => $password],
            headers: $this->csrfHeader(),
        );
    }
}
