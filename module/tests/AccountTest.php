<?php

declare(strict_types=1);

namespace Engelking\Webtrees\PortalApi\Tests;

use Engelking\Webtrees\PortalApi\Http\RequestHandlers\MeRead;
use Engelking\Webtrees\PortalApi\Http\RequestHandlers\PasswordRequestCreate;
use Engelking\Webtrees\PortalApi\Http\RequestHandlers\PasswordResetCreate;
use Engelking\Webtrees\PortalApi\Http\RequestHandlers\ProfileUpdate;
use Engelking\Webtrees\PortalApi\PortalApiModule;
use Engelking\Webtrees\PortalApi\Services\MemberService;
use Fig\Http\Message\RequestMethodInterface;
use Fig\Http\Message\StatusCodeInterface;
use Fisharebest\Webtrees\Auth;
use Fisharebest\Webtrees\Contracts\UserInterface;
use Fisharebest\Webtrees\DB;
use Fisharebest\Webtrees\User;
use PHPUnit\Framework\Attributes\CoversNothing;

use function time;

/**
 * Phase 2: the member's own settings, and getting back in without a password.
 */
#[CoversNothing]
class AccountTest extends PortalTestCase
{
    private User $member;

    protected function setUp(): void
    {
        parent::setUp();

        $this->member = $this->createUser('anna', 'Anna Beispiel', 'correct-horse', UserInterface::ROLE_MEMBER, 'X1');
        $this->module()->setPreference(PortalApiModule::SETTING_PORTAL_URL, 'https://portal.example.test');
    }

    /**
     * @param array<string,mixed> $body
     */
    private function patchProfile(array $body): mixed
    {
        return $this->api(
            ProfileUpdate::class,
            RequestMethodInterface::METHOD_PATCH,
            body: $body,
            headers: $this->csrfHeader(),
        );
    }

    /**
     * Read a user preference straight from the database.
     *
     * `User::getPreference()` answers from a per-instance cache filled when
     * the object was created, so the `User` this test holds cannot see what a
     * different instance inside the handler wrote.
     */
    private function preference(string $name): string
    {
        return (string) DB::table('user_setting')
            ->where('user_id', '=', $this->member->id())
            ->where('setting_name', '=', $name)
            ->value('setting_value');
    }

    private function profileRow(): object|null
    {
        return DB::table(MemberService::TABLE)->where('wt_user_id', '=', $this->member->id())->first();
    }

    // -----------------------------------------------------------------
    // Directory visibility is a consent record
    // -----------------------------------------------------------------

    public function testAMemberCanListThemselvesAndTheMomentIsRecorded(): void
    {
        $this->login($this->member);

        $before   = time();
        $response = $this->patchProfile(['visible_in_directory' => true]);
        $body     = $this->json($response);

        self::assertSame(StatusCodeInterface::STATUS_OK, $response->getStatusCode());
        self::assertTrue($body['visible_in_directory']);
        self::assertNotNull($body['consent_recorded_at']);
        self::assertGreaterThanOrEqual($before, strtotime($body['consent_recorded_at']));
    }

    public function testWithdrawingConsentClearsTheRecordOfIt(): void
    {
        $this->login($this->member);

        $this->patchProfile(['visible_in_directory' => true]);
        $body = $this->json($this->patchProfile(['visible_in_directory' => false]));

        self::assertFalse($body['visible_in_directory']);

        // Consent that has been withdrawn should not leave behind a timestamp
        // saying it was given. The column answers "listed since when", and for
        // someone who is not listed the answer is "they are not".
        self::assertNull($body['consent_recorded_at']);
    }

    public function testTheConsentTimestampSurvivesAnUnrelatedChange(): void
    {
        $this->login($this->member);

        $first = $this->json($this->patchProfile(['visible_in_directory' => true]));
        $then  = $this->json($this->patchProfile(['display_name_override' => 'Anna B.']));

        self::assertSame($first['consent_recorded_at'], $then['consent_recorded_at']);
        self::assertTrue($then['visible_in_directory']);
    }

    public function testAProfileIsCreatedOnFirstUse(): void
    {
        $this->login($this->member);

        self::assertNull($this->profileRow());
        self::assertNull($this->json($this->api(MeRead::class))['profile']);

        $this->patchProfile(['visible_in_directory' => true]);

        self::assertNotNull($this->profileRow());
        self::assertTrue($this->json($this->api(MeRead::class))['profile']['visible_in_directory']);
    }

    public function testADisplayNameCanBeSetAndCleared(): void
    {
        $this->login($this->member);

        self::assertSame('Anna B.', $this->json($this->patchProfile(['display_name_override' => 'Anna B.']))['display_name_override']);
        self::assertNull($this->json($this->patchProfile(['display_name_override' => null]))['display_name_override']);
        self::assertNull($this->json($this->patchProfile(['display_name_override' => '   ']))['display_name_override']);
    }

    public function testAMemberCannotChangeAnotherMembersProfile(): void
    {
        $other = $this->createUser('dieter', 'Dieter Beispiel', 'pw', UserInterface::ROLE_MEMBER, 'X4');
        $other_id = $this->createProfile($other, false);

        $this->login($this->member);

        // There is no parameter naming whose profile to change; the closest an
        // attacker can do is send fields that look like one.
        $this->patchProfile(['visible_in_directory' => true, 'id' => $other_id, 'wt_user_id' => $other->id()]);

        $other_row = DB::table(MemberService::TABLE)->where('id', '=', $other_id)->first();
        self::assertSame(0, (int) $other_row->visible_in_directory);
    }

    public function testChangingSettingsRequiresASessionAndACsrfToken(): void
    {
        $without_session = $this->patchProfile(['visible_in_directory' => true]);
        self::assertSame(StatusCodeInterface::STATUS_UNAUTHORIZED, $without_session->getStatusCode());

        $this->login($this->member);

        $without_token = $this->api(
            ProfileUpdate::class,
            RequestMethodInterface::METHOD_PATCH,
            body: ['visible_in_directory' => true],
        );
        self::assertSame(StatusCodeInterface::STATUS_FORBIDDEN, $without_token->getStatusCode());
        self::assertNull($this->profileRow());
    }

    public function testAnEmptyPatchIsRejected(): void
    {
        $this->login($this->member);

        self::assertSame(StatusCodeInterface::STATUS_BAD_REQUEST, $this->patchProfile([])->getStatusCode());
    }

    public function testVisibilityMustBeABoolean(): void
    {
        $this->login($this->member);

        self::assertSame(StatusCodeInterface::STATUS_BAD_REQUEST, $this->patchProfile(['visible_in_directory' => 'yes'])->getStatusCode());
    }

    // -----------------------------------------------------------------
    // Password reset
    // -----------------------------------------------------------------

    private function requestReset(string $email): mixed
    {
        return $this->api(
            PasswordRequestCreate::class,
            RequestMethodInterface::METHOD_POST,
            body: ['email' => $email],
            headers: $this->csrfHeader(),
        );
    }

    /**
     * @param array<string,mixed> $body
     */
    private function reset(array $body): mixed
    {
        return $this->api(
            PasswordResetCreate::class,
            RequestMethodInterface::METHOD_POST,
            body: $body,
            headers: $this->csrfHeader(),
        );
    }

    public function testARequestForAnUnknownAddressLooksExactlyLikeOneForAKnownAddress(): void
    {
        $known   = $this->requestReset('anna@example.test');
        $unknown = $this->requestReset('nobody@example.test');

        self::assertSame(StatusCodeInterface::STATUS_ACCEPTED, $known->getStatusCode());
        self::assertSame($known->getStatusCode(), $unknown->getStatusCode());
        self::assertSame($this->json($known), $this->json($unknown));
    }

    public function testARequestIssuesATokenThatExpires(): void
    {
        $this->requestReset('anna@example.test');

        self::assertNotSame('', $this->preference('password-token'));
        self::assertGreaterThan(time(), (int) $this->preference('password-token-expire'));
    }

    public function testAMalformedRequestSaysNothingEither(): void
    {
        $response = $this->requestReset('');

        self::assertSame(StatusCodeInterface::STATUS_ACCEPTED, $response->getStatusCode());
        self::assertSame('', $this->preference('password-token'));
    }

    public function testAValidTokenSetsThePasswordAndSignsIn(): void
    {
        $this->requestReset('anna@example.test');
        $token = $this->preference('password-token');

        $response = $this->reset(['token' => $token, 'password' => 'ein-neues-passwort']);
        $body     = $this->json($response);

        self::assertSame(StatusCodeInterface::STATUS_OK, $response->getStatusCode());
        self::assertSame('anna', $body['user']['username']);
        self::assertSame($this->member->id(), Auth::id());
        self::assertTrue($this->member->checkPassword('ein-neues-passwort'));
    }

    public function testATokenCannotBeUsedTwice(): void
    {
        $this->requestReset('anna@example.test');
        $token = $this->preference('password-token');

        self::assertSame(StatusCodeInterface::STATUS_OK, $this->reset(['token' => $token, 'password' => 'ein-neues-passwort'])->getStatusCode());

        $second = $this->reset(['token' => $token, 'password' => 'noch-ein-passwort']);

        self::assertSame(StatusCodeInterface::STATUS_BAD_REQUEST, $second->getStatusCode());
        self::assertSame('invalid_token', $this->json($second)['error']);
        self::assertTrue($this->member->checkPassword('ein-neues-passwort'), 'The first password must still stand.');
    }

    public function testAnExpiredTokenIsRefused(): void
    {
        $this->requestReset('anna@example.test');
        $token = $this->preference('password-token');

        DB::table('user_setting')
            ->where('user_id', '=', $this->member->id())
            ->where('setting_name', '=', 'password-token-expire')
            ->update(['setting_value' => (string) (time() - 1)]);

        $response = $this->reset(['token' => $token, 'password' => 'ein-neues-passwort']);

        self::assertSame(StatusCodeInterface::STATUS_BAD_REQUEST, $response->getStatusCode());
        self::assertSame('invalid_token', $this->json($response)['error']);
        self::assertTrue($this->member->checkPassword('correct-horse'), 'The old password must still stand.');
    }

    public function testAnInventedTokenIsRefused(): void
    {
        $response = $this->reset(['token' => 'not-a-real-token', 'password' => 'ein-neues-passwort']);

        self::assertSame(StatusCodeInterface::STATUS_BAD_REQUEST, $response->getStatusCode());
        self::assertSame('invalid_token', $this->json($response)['error']);
        self::assertFalse(Auth::check());
    }

    public function testAShortPasswordIsRefusedBeforeTheTokenIsSpent(): void
    {
        $this->requestReset('anna@example.test');
        $token = $this->preference('password-token');

        self::assertSame(StatusCodeInterface::STATUS_BAD_REQUEST, $this->reset(['token' => $token, 'password' => 'kurz'])->getStatusCode());

        // A rejected password must not burn the token — the member should be
        // able to try again with a longer one rather than start over.
        self::assertSame(StatusCodeInterface::STATUS_OK, $this->reset(['token' => $token, 'password' => 'ein-langes-passwort'])->getStatusCode());
    }

    public function testResettingRequiresACsrfToken(): void
    {
        $this->requestReset('anna@example.test');
        $token = $this->preference('password-token');

        $response = $this->api(
            PasswordResetCreate::class,
            RequestMethodInterface::METHOD_POST,
            body: ['token' => $token, 'password' => 'ein-neues-passwort'],
        );

        self::assertSame(StatusCodeInterface::STATUS_FORBIDDEN, $response->getStatusCode());
        self::assertTrue($this->member->checkPassword('correct-horse'));
    }

    public function testRepeatedRequestsAreRateLimitedWithoutSayingSo(): void
    {
        $bodies = [];

        for ($i = 0; $i < 8; $i++) {
            $response = $this->requestReset('anna@example.test');
            self::assertSame(StatusCodeInterface::STATUS_ACCEPTED, $response->getStatusCode());
            $bodies[] = $this->json($response);
        }

        // Every answer identical, whether or not the limiter refused it.
        self::assertCount(1, array_unique(array_map('serialize', $bodies)));
    }
}
