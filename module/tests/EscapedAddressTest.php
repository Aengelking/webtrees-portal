<?php

declare(strict_types=1);

namespace Engelking\Webtrees\PortalApi\Tests;

use Engelking\Webtrees\PortalApi\Http\RequestHandlers\SessionCreate;
use Fig\Http\Message\RequestMethodInterface;
use Fig\Http\Message\StatusCodeInterface;
use Fisharebest\Webtrees\Auth;
use Fisharebest\Webtrees\Contracts\UserInterface;
use PHPUnit\Framework\Attributes\CoversNothing;

/**
 * An e-mail address that reaches the module written as `@`.
 *
 * **The half of a workaround that lives in the other language.** Something in
 * front of the family server refuses any request whose body contains an
 * e-mail address — see `serialise()` in `portal/src/api/client.ts` and §2.104
 * — so the portal writes the `@` as the JSON escape for the same character.
 *
 * Nothing in the module was changed for that, and this is what says so out
 * loud. `Json::body()` hands the payload to `json_decode`, which resolves the
 * escape; the handler never learns that anything was encoded. That is a
 * property of PHP rather than of this module, which is exactly why it is
 * worth a test: the two halves of this arrangement are written in different
 * languages, and nothing else makes them meet.
 *
 * A member cannot sign in at all if this stops holding, so it should fail
 * loudly rather than be rediscovered on a Sunday.
 */
#[CoversNothing]
class EscapedAddressTest extends PortalTestCase
{
    /** The address as it is typed, and as the portal now puts it on the wire. */
    private const string ADDRESS = 'anna@example.test';
    private const string ESCAPED = 'anna\u0040example.test';

    private function signInWith(string $raw_body): int
    {
        Auth::logout();

        return $this->api(
            SessionCreate::class,
            RequestMethodInterface::METHOD_POST,
            headers: $this->csrfHeader() + ['CONTENT_TYPE' => 'application/json'],
            raw_body: $raw_body,
        )->getStatusCode();
    }

    private function member(): void
    {
        $user = $this->createUser('anna', 'Anna Beispiel', 'correct-horse', UserInterface::ROLE_MEMBER);

        $user->setEmail(self::ADDRESS);
        $user->setPreference(UserInterface::PREF_IS_EMAIL_VERIFIED, '1');
        $user->setPreference(UserInterface::PREF_IS_ACCOUNT_APPROVED, '1');
    }

    /**
     * The address arrives escaped, and the member is signed in. If this ever
     * goes red, every member whose username is an e-mail address is locked
     * out — which is the state this workaround was written to end.
     */
    public function testAnAddressWrittenAsAnEscapeSignsTheMemberIn(): void
    {
        $this->member();

        $body = '{"username":"' . self::ESCAPED . '","password":"correct-horse","remember":false}';

        // The wire carries no `@` at all: that is the whole point of it.
        self::assertStringNotContainsString('@', $body);

        self::assertSame(StatusCodeInterface::STATUS_OK, $this->signInWith($body));
    }

    /** And the plain form still works, for anything that predates the change. */
    public function testThePlainAddressStillWorks(): void
    {
        $this->member();

        $body = '{"username":"' . self::ADDRESS . '","password":"correct-horse","remember":false}';

        self::assertSame(StatusCodeInterface::STATUS_OK, $this->signInWith($body));
    }

    /**
     * The escape resolves to the address itself and not to something merely
     * similar — a wrong password is still refused, so this is not passing
     * because the handler stopped looking.
     */
    public function testTheEscapeIsNotAWayPastThePassword(): void
    {
        $this->member();

        $body = '{"username":"' . self::ESCAPED . '","password":"wrong-horse","remember":false}';

        self::assertSame(StatusCodeInterface::STATUS_UNAUTHORIZED, $this->signInWith($body));
    }
}
