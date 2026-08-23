<?php

declare(strict_types=1);

namespace Engelking\Webtrees\PortalApi\Schema;

use Fisharebest\Webtrees\DB;
use Fisharebest\Webtrees\Schema\MigrationInterface;
use Illuminate\Database\Schema\Blueprint;

/**
 * Staying signed in — one row per device that was told to remember.
 *
 * webtrees' own session cookie has `lifetime => 0` (`Session::start()`), so it
 * dies when the browser does, and the session row behind it is reaped by PHP's
 * ordinary garbage collection some minutes after the member stops using the
 * portal. For a family portal read a few times a month on a telephone, that
 * means typing a password nearly every visit. This table is the second
 * credential that answers "and it is still me".
 *
 * **The token is not in it**, for the same reason `portal_invitation` does not
 * store its token: a database is backed up, dumped and read by more people
 * than were ever handed the credential. Only a SHA-256 is kept. The raw value
 * exists in one browser's cookie jar and nowhere else on earth.
 *
 * **A series, and a token that changes under it.** The cookie carries both.
 * The series identifies the device and lives as long as the member stays
 * signed in on it; the token is replaced every time it is used. That is what
 * makes a stolen cookie *detectable* rather than merely time-limited: whoever
 * uses it second presents a token that has already been spent, and since only
 * one of the two is entitled to be there, the honest answer is to stop
 * trusting both — every row for that member goes, and everybody signs in
 * again. A silent theft that works for thirty days is the alternative.
 *
 * **`previous_hash` is what stops that firing at the wrong people.** Two
 * requests that leave a phone together — a retry, a double tap, a service
 * worker replaying — both carry the token that was current when they were
 * sent, and the second of them arrives after the first has already rotated it.
 * Without a grace period that is indistinguishable from theft, and the
 * punishment for a flaky connection would be being signed out of every device.
 * So the token one step back is accepted for a minute after it is replaced,
 * and only something older than that is treated as a stolen cookie.
 *
 * Nothing here is a second identity. The row names a `user_id` and proves
 * possession of a device; who that user *is*, what they may see and whether
 * their account still exists are all still webtrees' answers, asked afresh on
 * every request.
 */
class Migration10 implements MigrationInterface
{
    public function upgrade(): void
    {
        if (DB::schema()->hasTable('portal_remember_token')) {
            return;
        }

        DB::schema()->create('portal_remember_token', static function (Blueprint $table): void {
            $table->integer('id', true);

            $table->integer('wt_user_id');

            // The device. Stable while the member stays signed in on it, so
            // that rotating the token does not lose track of which device is
            // which — and so that one telephone signing out does not disturb
            // the tablet.
            $table->string('series', 32);

            // SHA-256 of the current token, hex encoded, and of the one it
            // replaced. `rotated_at` says when that replacement happened, and
            // is the only thing that makes the previous one time-limited.
            $table->string('token_hash', 64);
            $table->string('previous_hash', 64)->nullable();
            $table->integer('rotated_at')->nullable();

            $table->integer('created_at');
            $table->integer('expires_at');

            // Only ever moves forward, and only to whole days: this is what
            // the administrator's list of devices would show, not an audit
            // trail of every request the member made.
            $table->integer('used_at');

            $table->unique('series');
            $table->index('wt_user_id');
            $table->index('expires_at');

            $table->foreign('wt_user_id')
                ->references('user_id')
                ->on('user')
                ->onDelete('CASCADE');
        });
    }
}
