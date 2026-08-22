<?php

declare(strict_types=1);

namespace Engelking\Webtrees\PortalApi\Services;

use Engelking\Webtrees\PortalApi\PortalApiModule;

use function base64_encode;
use function chr;
use function curl_close;
use function curl_exec;
use function curl_getinfo;
use function curl_init;
use function curl_setopt_array;
use function json_encode;
use function ltrim;
use function openssl_pkey_export;
use function openssl_pkey_get_details;
use function openssl_pkey_get_private;
use function openssl_pkey_new;
use function openssl_sign;
use function ord;
use function parse_url;
use function rtrim;
use function str_pad;
use function strtr;
use function substr;
use function time;

use const CURLINFO_HTTP_CODE;
use const CURLOPT_CONNECTTIMEOUT;
use const CURLOPT_CUSTOMREQUEST;
use const CURLOPT_HTTPHEADER;
use const CURLOPT_RETURNTRANSFER;
use const CURLOPT_TIMEOUT;
use const OPENSSL_ALGO_SHA256;
use const OPENSSL_KEYTYPE_EC;
use const PHP_URL_HOST;
use const PHP_URL_SCHEME;
use const STR_PAD_LEFT;

/**
 * Knocking on a browser's push service, with no payload and therefore no
 * cryptography beyond a signature.
 *
 * Two halves to a web push. **VAPID** is how a server identifies itself to a
 * push service — a signed token saying "this is who is sending, here is where
 * to complain" — and it is required. **Payload encryption** (RFC 8291) is how
 * the *content* is hidden from that push service, and it is required only when
 * there is content. This portal sends none (see `Schema/Migration8.php`), so
 * the entire ECDH-and-AES half of web push is absent here: a POST with an
 * empty body and one `Authorization` header.
 *
 * That is worth stating plainly, because a hand-written web push library is
 * usually a bad idea and this one is the exception that proves the rule. What
 * is left is a JWT signed with ES256, and the only fiddly part of it is that
 * `openssl_sign()` produces a DER structure where JWS wants sixty-four raw
 * bytes.
 */
class WebPush
{
    /** How long a push service should hold the knock for a phone that is off. */
    private const int TTL = 86400;

    /** Seconds. A family message must not wait on somebody else's server. */
    private const int TIMEOUT = 5;

    public function __construct(private readonly PortalApiModule $module)
    {
    }

    /** Whether keys exist. Without them nothing here can be done at all. */
    public function configured(): bool
    {
        return $this->module->getPreference(PortalApiModule::SETTING_VAPID_PRIVATE, '') !== ''
            && $this->publicKey() !== '';
    }

    /** The key a browser needs in order to subscribe at all. */
    public function publicKey(): string
    {
        return $this->module->getPreference(PortalApiModule::SETTING_VAPID_PUBLIC, '');
    }

    /**
     * Make a key pair, once, and keep it.
     *
     * The pair is the portal's identity to every push service its members'
     * browsers use. Replacing it invalidates every subscription ever made
     * against the old one, which is why this only ever fills an empty setting
     * and never overwrites.
     */
    public function ensureKeys(): void
    {
        if ($this->configured()) {
            return;
        }

        $key = openssl_pkey_new([
            'curve_name'       => 'prime256v1',
            'private_key_type' => OPENSSL_KEYTYPE_EC,
        ]);

        if ($key === false) {
            return;
        }

        $details = openssl_pkey_get_details($key);

        if ($details === false || !isset($details['ec']['x'], $details['ec']['y'])) {
            return;
        }

        openssl_pkey_export($key, $pem);

        // The uncompressed point: 0x04, then x and y, 32 bytes each. This is
        // what `applicationServerKey` wants in the browser.
        $point = chr(4)
            . str_pad($details['ec']['x'], 32, "\0", STR_PAD_LEFT)
            . str_pad($details['ec']['y'], 32, "\0", STR_PAD_LEFT);

        $this->module->setPreference(PortalApiModule::SETTING_VAPID_PRIVATE, $pem);
        $this->module->setPreference(PortalApiModule::SETTING_VAPID_PUBLIC, self::base64url($point));
    }

    /**
     * Knock once.
     *
     * @return bool False when the subscription is gone and should be forgotten.
     *              Any other failure — a push service having a bad day, a
     *              timeout — is not the member's problem and is reported as
     *              success, because the message itself is already delivered.
     */
    public function send(string $endpoint, string $subject): bool
    {
        $token = $this->token($endpoint, $subject);

        if ($token === null) {
            return true;
        }

        $handle = curl_init($endpoint);

        if ($handle === false) {
            return true;
        }

        curl_setopt_array($handle, [
            CURLOPT_CUSTOMREQUEST  => 'POST',
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => self::TIMEOUT,
            CURLOPT_CONNECTTIMEOUT => self::TIMEOUT,
            CURLOPT_HTTPHEADER     => [
                'TTL: ' . self::TTL,
                // Urgency and Topic are not set. Urgency would be a claim
                // about somebody else's family news, and Topic would let a
                // push service collapse two messages into one — which is a
                // decision about what a member gets told, made in the wrong
                // place.
                'Content-Length: 0',
                'Authorization: vapid t=' . $token . ', k=' . $this->publicKey(),
            ],
        ]);

        $response = curl_exec($handle);
        $status   = (int) curl_getinfo($handle, CURLINFO_HTTP_CODE);

        curl_close($handle);

        if ($response === false) {
            return true;
        }

        // 404 and 410 are the push service saying this device is gone for
        // good. Anything else — including 429 and 5xx — may be temporary and
        // is not grounds for throwing away an address.
        return $status !== 404 && $status !== 410;
    }

    /**
     * The VAPID token: a JWT saying who is sending and to which push service.
     *
     * `aud` is the origin of the endpoint and nothing more — a push service
     * rejects a token minted for a different one, which is what stops a token
     * captured in transit from being replayed elsewhere.
     */
    private function token(string $endpoint, string $subject): string|null
    {
        $scheme = parse_url($endpoint, PHP_URL_SCHEME);
        $host   = parse_url($endpoint, PHP_URL_HOST);

        if (!is_string($scheme) || !is_string($host)) {
            return null;
        }

        $pem = $this->module->getPreference(PortalApiModule::SETTING_VAPID_PRIVATE, '');
        $key = $pem === '' ? false : openssl_pkey_get_private($pem);

        if ($key === false) {
            return null;
        }

        $header = self::base64url((string) json_encode(['typ' => 'JWT', 'alg' => 'ES256']));

        $claims = self::base64url((string) json_encode([
            'aud' => $scheme . '://' . $host,
            // Twelve hours. The specification allows a day; shorter costs
            // nothing, since a token is minted per push.
            'exp' => time() + 43200,
            // Who to complain to. A push service that finds this portal
            // misbehaving should be able to reach a person.
            'sub' => $subject,
        ]));

        if (!openssl_sign($header . '.' . $claims, $der, $key, OPENSSL_ALGO_SHA256)) {
            return null;
        }

        return $header . '.' . $claims . '.' . self::base64url(self::rawSignature($der));
    }

    /**
     * DER to the sixty-four bytes JWS wants.
     *
     * `openssl_sign()` returns an ASN.1 SEQUENCE of two INTEGERs, each of
     * which may carry a leading zero byte (so it is not read as negative) or
     * be shorter than 32 bytes. JWS wants r and s as fixed 32-byte values, so
     * each is stripped and padded back. Getting this wrong produces a token
     * every push service rejects with a 401 and no explanation.
     */
    private static function rawSignature(string $der): string
    {
        // SEQUENCE, then two INTEGERs. The sequence's own length is in short
        // form for a P-256 signature (70-ish bytes), but the long form is
        // handled so this cannot become a silent misread on some other curve.
        $position = 1;
        $length   = ord($der[$position]);
        $position += $length > 0x80 ? $length - 0x80 + 1 : 1;

        $position++;                             // the INTEGER tag
        $size      = ord($der[$position]);
        $position++;
        $r         = substr($der, $position, $size);
        $position += $size;

        $position++;                             // the second INTEGER tag
        $size      = ord($der[$position]);
        $position++;
        $s         = substr($der, $position, $size);

        return self::pad($r) . self::pad($s);
    }

    private static function pad(string $number): string
    {
        $number = ltrim($number, "\0");

        return str_pad($number, 32, "\0", STR_PAD_LEFT);
    }

    private static function base64url(string $raw): string
    {
        return rtrim(strtr(base64_encode($raw), '+/', '-_'), '=');
    }
}
