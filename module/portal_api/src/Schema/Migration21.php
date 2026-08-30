<?php

declare(strict_types=1);

namespace Engelking\Webtrees\PortalApi\Schema;

use Fisharebest\Webtrees\DB;
use Fisharebest\Webtrees\Schema\MigrationInterface;
use Illuminate\Database\Schema\Blueprint;

/**
 * That a member asked — which is not the same as that somebody was asked.
 *
 * The offer to connect from a person's page answers the same way whether or
 * not there is an account behind the record: that is the whole point of it,
 * and §2.98 explains why. It left one thing broken, and it broke it exactly
 * where the feature is most used — close family, who are the least likely to
 * be in the directory. A member asked, got the careful sentence, came back the
 * next day and found the button again, with nothing anywhere to say they had
 * already pressed it.
 *
 * The reason is that for those two cases there is nothing to read back.
 * `portal_connection` can only hold a row where the other side is an account,
 * and an unanswered row to somebody unlisted must not be reported anyway —
 * reporting it would say that an account is there.
 *
 * So this table records the **member's own act**, and nothing else: I pressed
 * connect on this record, at this moment. It is true for a record with an
 * account behind it and for one without, so a page built on it says the same
 * thing in both cases — which is what the disclosure rule needs — and it says
 * something the member already knows, to the only person who can see it.
 *
 * Deliberately *not* what it might look like:
 *
 * - It is not a second request queue. Nothing is delivered from here and
 *   nothing is answered here; `portal_connection` remains the only place a
 *   request lives.
 * - It is not a record of interest to be kept. Rows are pruned after
 *   `Connections::RETAIN_ATTEMPT_DAYS`, and the pair is unique, so asking
 *   twice leaves one row rather than a history.
 * - It is not readable by anybody else. The only query on it is "did *this*
 *   member ask about this record", asked by that member's own screen.
 *
 * The XREF is stored rather than a record id because that is what the page
 * has, and because a record that is later deleted should leave a row that
 * simply never matches again.
 */
class Migration21 implements MigrationInterface
{
    public function upgrade(): void
    {
        if (DB::schema()->hasTable('portal_connection_attempt')) {
            return;
        }

        DB::schema()->create('portal_connection_attempt', static function (Blueprint $table): void {
            $table->integer('id', true);
            $table->integer('wt_user_id');
            $table->string('xref', 20);
            $table->integer('created_at');

            // One row per member and record: this answers "have I asked?",
            // which has no plural.
            $table->unique(['wt_user_id', 'xref']);
        });

        DB::schema()->table('portal_connection_attempt', static function (Blueprint $table): void {
            $table->foreign('wt_user_id')
                ->references('user_id')
                ->on('user')
                ->onDelete('CASCADE')
                ->onUpdate('CASCADE');
        });
    }
}
