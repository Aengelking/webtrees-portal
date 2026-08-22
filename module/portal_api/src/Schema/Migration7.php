<?php

declare(strict_types=1);

namespace Engelking\Webtrees\PortalApi\Schema;

use Fisharebest\Webtrees\DB;
use Fisharebest\Webtrees\Schema\MigrationInterface;
use Illuminate\Database\Schema\Blueprint;

/**
 * A connection link: the same handshake as the code on the screen, for the
 * people who are not in the room.
 *
 * The code in `portal_connection_code` answers "we are standing here
 * together": it lives for a quarter of an hour, anybody who can see the
 * screen may use it, and it is meant to be used by several of them at one
 * gathering. Almost every one of those properties is wrong for something
 * sent by e-mail, which is why this is a second table rather than a column
 * on the first.
 *
 * **It lives for a week**, because a message sent on Tuesday is read on
 * Thursday. **It works once**, because it travels through somebody else's
 * inbox and out the far side: a link that had been forwarded, quoted in a
 * reply, or left in a chat that a new phone still syncs is a link that must
 * already be spent. And **a member may have several outstanding at a time**,
 * one per person they wrote to, which is why nothing here is unique per
 * member.
 *
 * `redeemed_by` is kept rather than merely nulling the row, so that "who did
 * this link reach?" has an answer for as long as the row does. That is a
 * question the member who sent it will ask — they sent five of them — and it
 * names nobody the two of them do not already know: the connection it made
 * is on both their screens.
 */
class Migration7 implements MigrationInterface
{
    public function upgrade(): void
    {
        if (DB::schema()->hasTable('portal_connection_link')) {
            return;
        }

        DB::schema()->create('portal_connection_link', static function (Blueprint $table): void {
            $table->integer('id', true);

            // Not unique: one link per person written to, several at a time.
            $table->integer('wt_user_id');

            // Hashed, exactly like an invitation and like the code on the
            // screen. This one travels through an inbox that is not the
            // member's, so it is the least private of the three and the least
            // excusable to store in the open.
            $table->string('token_hash', 64);

            $table->integer('created_at');
            $table->integer('expires_at');

            // Set the moment it is spent. The row stays: a member wants to
            // see that the link they sent on Tuesday was used, and by whom.
            $table->integer('redeemed_at')->nullable();
            $table->integer('redeemed_by')->nullable();

            $table->index(['wt_user_id', 'expires_at']);

            $table->foreign('wt_user_id')
                ->references('user_id')
                ->on('user')
                ->onDelete('CASCADE')
                ->onUpdate('CASCADE');

            // Deliberately not a foreign key to `user`: this is a note about
            // what happened, and a deleted account should not take the fact
            // that the link was spent with it — a spent link must stay spent.
        });
    }
}
