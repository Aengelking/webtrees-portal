<?php

declare(strict_types=1);

namespace Engelking\Webtrees\PortalApi\Schema;

use Fisharebest\Webtrees\DB;
use Fisharebest\Webtrees\Schema\MigrationInterface;
use Illuminate\Database\Schema\Blueprint;

/**
 * Which messages a member has already read.
 *
 * webtrees' own `message` table has no such column — it stores a sender, a
 * subject, a body and a timestamp, and that is all. Its own inbox block shows
 * every message the same way, which is reasonable for a page somebody opens
 * on purpose and useless for a portal that wants to say "two new messages" on
 * the navigation bar.
 *
 * So the state lives here rather than in core, which webtrees is not to be
 * patched for (§2 of the handoff). A row means "read"; no row means unread,
 * which makes an arriving message unread without anything having to write to
 * this table when it arrives.
 *
 * The foreign key cascades: a deleted message takes its read state with it,
 * whether it was deleted from the portal or from webtrees.
 */
class Migration4 implements MigrationInterface
{
    public function upgrade(): void
    {
        if (DB::schema()->hasTable('portal_message_read')) {
            return;
        }

        DB::schema()->create('portal_message_read', static function (Blueprint $table): void {
            $table->integer('id', true);
            $table->integer('wt_user_id');
            $table->integer('message_id');
            $table->integer('read_at');

            // One row per member per message. The member id is part of it
            // because `message.user_id` is the recipient and this table is
            // only ever written by that same person — but a unique key on
            // both is what makes "mark as read" idempotent.
            $table->unique(['wt_user_id', 'message_id']);

            $table->foreign('message_id')
                ->references('message_id')
                ->on('message')
                ->onDelete('CASCADE')
                ->onUpdate('CASCADE');

            $table->foreign('wt_user_id')
                ->references('user_id')
                ->on('user')
                ->onDelete('CASCADE')
                ->onUpdate('CASCADE');
        });
    }
}
