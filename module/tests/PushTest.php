<?php

declare(strict_types=1);

namespace Engelking\Webtrees\PortalApi\Tests;

use Engelking\Webtrees\PortalApi\Http\RequestHandlers\PushCreate;
use Engelking\Webtrees\PortalApi\Http\RequestHandlers\PushDelete;
use Engelking\Webtrees\PortalApi\Http\RequestHandlers\PushRead;
use Engelking\Webtrees\PortalApi\PortalApiModule;
use Engelking\Webtrees\PortalApi\Services\WebPush;
use Fig\Http\Message\RequestMethodInterface;
use Fig\Http\Message\StatusCodeInterface;
use Fisharebest\Webtrees\Contracts\UserInterface;
use Fisharebest\Webtrees\DB;
use Fisharebest\Webtrees\User;
use PHPUnit\Framework\Attributes\CoversNothing;
use ReflectionMethod;

use function base64_decode;
use function explode;
use function json_decode;
use function openssl_pkey_get_details;
use function openssl_pkey_get_private;
use function openssl_sign;
use function openssl_verify;
use function str_pad;
use function strlen;
use function strtr;

use const STR_PAD_LEFT;

/**
 * Phase 13: notifications.
 *
 * The feature was built under one condition — **nothing about the message may
 * reach a lock screen** — and the way it keeps that promise is by sending a
 * push with no payload at all. So the first thing tested here is the absence:
 * no keys stored, nothing to encrypt, nothing that could name a person.
 *
 * The rest is the part a hand-written web push gets wrong. A VAPID token is a
 * JWT signed with ES256, and `openssl_sign()` hands back a DER structure where
 * JWS wants sixty-four raw bytes. Getting that conversion wrong produces a
 * token every push service rejects with a bare 401 — which is why it is
 * checked here against a real signature rather than trusted.
 */
#[CoversNothing]
class PushTest extends PortalTestCase
{
    private User $anna;
    private User $dieter;

    protected function setUp(): void
    {
        parent::setUp();

        $this->anna   = $this->createUser('anna', 'Anna Beispiel', 'correct-horse', UserInterface::ROLE_MEMBER, 'X1');
        $this->dieter = $this->createUser('dieter', 'Dieter Beispiel', 'anderes-pferd', UserInterface::ROLE_MEMBER, 'X4');

        // VAPID needs a contact for whoever runs the sender, and this portal's
        // is its own address. Without one there is nothing honest to claim.
        $this->module()->setPreference(PortalApiModule::SETTING_PORTAL_URL, 'https://portal.example.test');

        $this->login($this->anna);
    }

    // -----------------------------------------------------------------
    // What is stored, and what deliberately is not
    // -----------------------------------------------------------------

    public function testSubscribingStoresTheAddressAndNothingElse(): void
    {
        $response = $this->subscribe('https://push.example.test/abc');

        self::assertSame(StatusCodeInterface::STATUS_CREATED, $response->getStatusCode());

        $row = DB::table('portal_push_subscription')->first();

        self::assertNotNull($row);
        self::assertSame('https://push.example.test/abc', (string) $row->endpoint);
        self::assertSame($this->anna->id(), (int) $row->wt_user_id);

        // The columns a payload would need are not merely empty — they do not
        // exist. There is no place to put a name even by accident.
        self::assertFalse(DB::schema()->hasColumn('portal_push_subscription', 'p256dh'));
        self::assertFalse(DB::schema()->hasColumn('portal_push_subscription', 'auth'));
    }

    /**
     * A browser re-subscribes the same device on its own whenever its push
     * service rotates the address. That must update a row, not collect them.
     */
    public function testSubscribingTheSameDeviceTwiceKeepsOneRow(): void
    {
        $this->subscribe('https://push.example.test/abc');
        $this->subscribe('https://push.example.test/abc');

        self::assertSame(1, DB::table('portal_push_subscription')->count());
    }

    public function testAMemberMayHaveSeveralDevices(): void
    {
        $this->subscribe('https://push.example.test/phone');
        $this->subscribe('https://push.example.test/tablet');

        self::assertSame(2, DB::table('portal_push_subscription')->count());
    }

    /** A shared tablet that changes hands moves; two people never share one address. */
    public function testADeviceThatChangesHandsMovesRatherThanDuplicating(): void
    {
        $this->subscribe('https://push.example.test/tablet');

        $this->login($this->dieter);
        $this->subscribe('https://push.example.test/tablet');

        self::assertSame(1, DB::table('portal_push_subscription')->count());
        self::assertSame(
            $this->dieter->id(),
            (int) DB::table('portal_push_subscription')->value('wt_user_id'),
        );
    }

    public function testAnAddressThatIsNotHttpsIsRefused(): void
    {
        $response = $this->subscribe('http://push.example.test/abc');

        self::assertSame(StatusCodeInterface::STATUS_BAD_REQUEST, $response->getStatusCode());
        self::assertSame(0, DB::table('portal_push_subscription')->count());
    }

    public function testUnsubscribingRemovesOnlyThisMembersRow(): void
    {
        $this->subscribe('https://push.example.test/hers');

        $this->login($this->dieter);
        $this->subscribe('https://push.example.test/his');

        $this->login($this->anna);
        $this->api(
            PushDelete::class,
            RequestMethodInterface::METHOD_DELETE,
            body: ['endpoint' => 'https://push.example.test/his'],
            headers: $this->csrfHeader(),
        );

        // Not hers to delete, and still there.
        self::assertSame(2, DB::table('portal_push_subscription')->count());

        $this->api(
            PushDelete::class,
            RequestMethodInterface::METHOD_DELETE,
            body: ['endpoint' => 'https://push.example.test/hers'],
            headers: $this->csrfHeader(),
        );

        self::assertSame(1, DB::table('portal_push_subscription')->count());
    }

    // -----------------------------------------------------------------
    // What the family may switch off
    // -----------------------------------------------------------------

    public function testTheKeyIsOfferedOnlyWhenNotificationsAreAvailable(): void
    {
        $available = $this->json($this->api(PushRead::class));

        self::assertTrue($available['available']);
        self::assertNotSame('', $available['public_key']);

        $this->module()->setPreference(PortalApiModule::SETTING_PUSH, '0');

        $off = $this->json($this->api(PushRead::class));

        self::assertFalse($off['available']);
        self::assertSame('', $off['public_key'], 'no key means no browser can subscribe');
    }

    public function testSwitchingNotificationsOffRefusesNewDevices(): void
    {
        $this->module()->setPreference(PortalApiModule::SETTING_PUSH, '0');

        self::assertSame(
            StatusCodeInterface::STATUS_FORBIDDEN,
            $this->subscribe('https://push.example.test/abc')->getStatusCode(),
        );
    }

    /**
     * A portal that does not know its own address cannot fill VAPID's contact
     * field, so it does not pretend it can send.
     */
    public function testNoPortalAddressMeansNoNotifications(): void
    {
        $this->module()->setPreference(PortalApiModule::SETTING_PORTAL_URL, '');

        self::assertFalse($this->json($this->api(PushRead::class))['available']);
    }

    // -----------------------------------------------------------------
    // The signature, which is the part that fails silently
    // -----------------------------------------------------------------

    /**
     * JWS wants r and s as thirty-two bytes each; DER gives them as INTEGERs
     * that may carry a leading zero or be short. A hundred signatures, because
     * the short cases only turn up now and then and a token that is wrong one
     * time in fifty is worse than one that never works.
     */
    public function testEverySignatureBecomesSixtyFourBytes(): void
    {
        $method = new ReflectionMethod(WebPush::class, 'rawSignature');
        $key    = openssl_pkey_new(['curve_name' => 'prime256v1', 'private_key_type' => OPENSSL_KEYTYPE_EC]);

        self::assertNotFalse($key);

        for ($i = 0; $i < 100; $i++) {
            openssl_sign('portal-' . $i, $der, $key, OPENSSL_ALGO_SHA256);

            self::assertSame(64, strlen($method->invoke(null, $der)), 'signature ' . $i);
        }
    }

    /** The key pair is made once and never replaced — that would orphan every device. */
    public function testKeysAreMadeOnceAndKept(): void
    {
        $push = new WebPush($this->module());

        $first = $this->module()->getPreference(PortalApiModule::SETTING_VAPID_PRIVATE, '');

        self::assertNotSame('', $first, 'the module makes them when it boots');

        $push->ensureKeys();

        self::assertSame($first, $this->module()->getPreference(PortalApiModule::SETTING_VAPID_PRIVATE, ''));
    }

    /**
     * The public key is the private one's point, not an unrelated string. If
     * these two ever drift apart every push is rejected and nothing says why.
     *
     * **The coordinates must be padded, and this test used not to.** OpenSSL
     * hands back `x` and `y` as big-endian integers with no leading zeroes, so
     * roughly one key in 128 has a coordinate 31 bytes long instead of 32. An
     * uncompressed point is fixed-width by definition — `0x04` and two 32-byte
     * halves, which is what a browser's `applicationServerKey` requires — so
     * `WebPush::ensureKeys()` pads, correctly. This test did not, and on those
     * keys it failed: about one CI run in 128, always by accusing code that
     * was right. The length assertion below says the rule out loud, so that
     * the padding cannot quietly go missing on either side again.
     */
    public function testThePublicKeyBelongsToThePrivateOne(): void
    {
        $push  = new WebPush($this->module());
        $pem   = $this->module()->getPreference(PortalApiModule::SETTING_VAPID_PRIVATE, '');
        $key   = openssl_pkey_get_private($pem);

        self::assertNotFalse($key);

        $details = openssl_pkey_get_details($key);
        $point   = "\x04"
            . str_pad($details['ec']['x'], 32, "\0", STR_PAD_LEFT)
            . str_pad($details['ec']['y'], 32, "\0", STR_PAD_LEFT);

        self::assertSame(65, strlen($point), 'An uncompressed point is 0x04 and two 32-byte halves.');

        self::assertSame(
            $push->publicKey(),
            rtrim(strtr(base64_encode($point), '+/', '-_'), '='),
        );
    }

    private function subscribe(string $endpoint): \Psr\Http\Message\ResponseInterface
    {
        return $this->api(
            PushCreate::class,
            RequestMethodInterface::METHOD_POST,
            body: ['endpoint' => $endpoint],
            headers: $this->csrfHeader(),
        );
    }
}
