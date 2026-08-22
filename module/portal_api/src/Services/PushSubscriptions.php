<?php

declare(strict_types=1);

namespace Engelking\Webtrees\PortalApi\Services;

use Engelking\Webtrees\PortalApi\Http\ApiException;
use Engelking\Webtrees\PortalApi\PortalApiModule;
use Fisharebest\Webtrees\Contracts\UserInterface;
use Fisharebest\Webtrees\DB;
use Fisharebest\Webtrees\I18N;
use Throwable;

use function error_log;
use function hash;
use function mb_strlen;
use function str_starts_with;
use function time;

/**
 * Which devices to knock on, and the knocking.
 *
 * A subscription is an address a browser hands out for one device. It is not
 * personal data in the ordinary sense — an opaque URL at Google or Mozilla —
 * but it is a way to reach a person, so it is owned by an account, deleted
 * with it, and never shown to anybody.
 *
 * **Nothing about the message travels.** See `Schema/Migration8.php`: the push
 * carries no payload, the service worker shows a sentence it already knows,
 * and a lock screen therefore says that something arrived and not what or from
 * whom. That was the condition this feature was built under and it is the
 * reason it can exist in a portal about living people at all.
 */
class PushSubscriptions
{
    /** Long, but an endpoint is not unbounded and a database row should not be either. */
    private const int MAX_ENDPOINT_LENGTH = 1000;

    public function __construct(
        private readonly PortalApiModule $module,
        private readonly WebPush $push,
    ) {
    }

    /**
     * Whether a member can be offered this at all.
     *
     * Three things have to hold: the family has not switched it off, keys
     * exist, and the portal knows its own address — VAPID requires a contact
     * for whoever runs the sender, and this portal's is its URL. Without one
     * there is nothing honest to put in the token, so there is no push.
     */
    public function available(): bool
    {
        return $this->module->getPreference(PortalApiModule::SETTING_PUSH, '1') === '1'
            && $this->subject() !== ''
            && $this->push->configured();
    }

    public function publicKey(): string
    {
        return $this->available() ? $this->push->publicKey() : '';
    }

    /**
     * Remember a device.
     *
     * Idempotent by endpoint: a browser that re-subscribes the same device —
     * which it does on its own, whenever its push service rotates the
     * address — updates the row rather than adding one.
     */
    public function subscribe(UserInterface $user, string $endpoint): void
    {
        if (!$this->available()) {
            throw new ApiException(
                'not_allowed',
                403,
                I18N::translate('This family tree does not send notifications.')
            );
        }

        // http:// endpoints and anything that is not a URL are refused rather
        // than stored: a row that can never be knocked on is a row that will
        // be puzzled over later.
        if (!str_starts_with($endpoint, 'https://') || mb_strlen($endpoint) > self::MAX_ENDPOINT_LENGTH) {
            throw ApiException::badRequest();
        }

        $hash     = self::hash($endpoint);
        $existing = DB::table('portal_push_subscription')->where('endpoint_hash', '=', $hash)->first();

        if ($existing !== null) {
            // The same device, now somebody else's — a shared tablet, an
            // account handed over. The row moves; two people are never
            // subscribed to one address.
            DB::table('portal_push_subscription')
                ->where('id', '=', (int) $existing->id)
                ->update(['wt_user_id' => $user->id(), 'created_at' => time()]);

            return;
        }

        DB::table('portal_push_subscription')->insert([
            'wt_user_id'    => $user->id(),
            'endpoint'      => $endpoint,
            'endpoint_hash' => $hash,
            'created_at'    => time(),
        ]);
    }

    /** Forget one device. Silent when it was not there: the member wanted it gone. */
    public function unsubscribe(UserInterface $user, string $endpoint): void
    {
        DB::table('portal_push_subscription')
            ->where('wt_user_id', '=', $user->id())
            ->where('endpoint_hash', '=', self::hash($endpoint))
            ->delete();
    }

    /** Whether this member has any device subscribed. */
    public function subscribed(UserInterface $user): bool
    {
        return DB::table('portal_push_subscription')
            ->where('wt_user_id', '=', $user->id())
            ->exists();
    }

    /**
     * Knock on every device this member has.
     *
     * Never throws. A push is a courtesy on top of a message that is already
     * stored and already readable — see `Conversations::tell()` for the same
     * reasoning about e-mail. A push service having a bad day must not turn
     * into a member being told their message failed.
     */
    public function knock(int $user_id): void
    {
        if (!$this->available()) {
            return;
        }

        $subject = $this->subject();

        try {
            $rows = DB::table('portal_push_subscription')
                ->where('wt_user_id', '=', $user_id)
                ->get();

            foreach ($rows as $row) {
                if (!$this->push->send((string) $row->endpoint, $subject)) {
                    // The push service says this device is gone for good.
                    DB::table('portal_push_subscription')->where('id', '=', (int) $row->id)->delete();
                }
            }
        } catch (Throwable $exception) {
            error_log('portal_api: could not send a notification: ' . $exception->getMessage());
        }
    }

    /**
     * What the portal tells a push service about itself.
     *
     * The portal's own address — a person reading a complaint at Google or
     * Mozilla can get from it to the site and to whoever runs it, which is the
     * whole purpose of the field.
     */
    private function subject(): string
    {
        return $this->module->getPreference(PortalApiModule::SETTING_PORTAL_URL, '');
    }

    private static function hash(string $endpoint): string
    {
        return hash('sha256', $endpoint);
    }
}
