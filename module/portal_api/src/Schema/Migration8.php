<?php

declare(strict_types=1);

namespace Engelking\Webtrees\PortalApi\Schema;

use Fisharebest\Webtrees\DB;
use Fisharebest\Webtrees\Schema\MigrationInterface;
use Illuminate\Database\Schema\Blueprint;

/**
 * Where to knock, so a member finds out a message arrived without opening the
 * app to look.
 *
 * **One column of substance, and that is the point.** A push subscription
 * normally carries two more — `p256dh` and `auth`, the keys that encrypt a
 * payload — and this table deliberately does not store them, because this
 * portal sends **no payload at all**.
 *
 * A push with no payload is a knock: the browser wakes the service worker,
 * which shows a sentence it already knows. Nothing about the message travels
 * — not the sender, not a word of it, not even that it is from a person
 * rather than the administrator. On a lock screen, which anybody who picks the
 * phone up can read, that is the only version of this feature worth having in
 * a portal about living people. It also means there is no encryption to get
 * wrong: RFC 8291's ECDH-and-AES dance exists purely to protect a payload
 * there is none of.
 *
 * Storing the keys "in case" would be storing material for a capability nobody
 * has decided to want. If a later phase does want payloads, the browser can
 * hand them over again at any time — `pushManager.getSubscription()` still has
 * them — and that phase can weigh what it means to put a name on a lock
 * screen. This one has already answered that question with no.
 */
class Migration8 implements MigrationInterface
{
    public function upgrade(): void
    {
        if (DB::schema()->hasTable('portal_push_subscription')) {
            return;
        }

        DB::schema()->create('portal_push_subscription', static function (Blueprint $table): void {
            $table->integer('id', true);

            // One row per device, so a member reading on a phone and a tablet
            // is knocked on both.
            $table->integer('wt_user_id');

            // The URL the browser's own push service gave out. Long, opaque,
            // and the only address this portal has for that device.
            $table->text('endpoint');

            // Endpoints run to hundreds of characters and databases differ on
            // how much of a text column they will index, so uniqueness hangs
            // on a hash of it rather than on the column itself. Re-subscribing
            // the same device must update a row, not add one.
            $table->string('endpoint_hash', 64);

            $table->integer('created_at');

            $table->unique('endpoint_hash');
            $table->index('wt_user_id');

            $table->foreign('wt_user_id')
                ->references('user_id')
                ->on('user')
                ->onDelete('CASCADE');
        });
    }
}
