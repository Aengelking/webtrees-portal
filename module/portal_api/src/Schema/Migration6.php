<?php

declare(strict_types=1);

namespace Engelking\Webtrees\PortalApi\Schema;

use Fisharebest\Webtrees\DB;
use Fisharebest\Webtrees\Schema\MigrationInterface;
use Illuminate\Database\Schema\Blueprint;

/**
 * A conversation, which is the thing webtrees' `message` table cannot hold.
 *
 * That table stores exactly one row per message and it belongs to the
 * *recipient*. Nothing is kept for the sender — §2.28 says so on the screen,
 * because it was the honest thing to say about what existed. It also means a
 * transcript is not merely hard to assemble but impossible: half of any
 * conversation, the half a member wrote themselves, was never written down.
 *
 * Three further things that table cannot do, each of which this one must:
 * there is no key tying two messages together (only the string "RE: " in a
 * subject); `sender` is an e-mail address rather than an account, so the link
 * back to a person can fail in three ordinary ways; and `body` is a rendered
 * e-mail template rather than what somebody typed.
 *
 * So: a store of its own, next to webtrees' rather than instead of it. The old
 * inbox keeps doing what only it can — messages from webtrees' contact form,
 * an administrator's broadcast, anything that did not come from the portal.
 *
 * **Two people, and the pair is the identity.** Not a group chat: the smaller
 * webtrees user id is always `user_one`, so a pair has exactly one row and the
 * unique key can say so. Groups would need a membership table and a different
 * answer to every question below — who may read, who may leave, what "read"
 * means when there are five of you.
 */
class Migration6 implements MigrationInterface
{
    public function upgrade(): void
    {
        if (!DB::schema()->hasTable('portal_conversation')) {
            DB::schema()->create('portal_conversation', static function (Blueprint $table): void {
                $table->integer('id', true);

                // Normalised: the smaller user id is always user_one. The
                // ordering carries no meaning — it is what lets one pair have
                // one row, so that two members writing to each other at the
                // same moment cannot create two conversations.
                $table->integer('user_one');
                $table->integer('user_two');

                $table->integer('created_at');

                $table->unique(['user_one', 'user_two']);
                $table->index('user_two');

                $table->foreign('user_one')
                    ->references('user_id')
                    ->on('user')
                    ->onDelete('CASCADE');

                $table->foreign('user_two')
                    ->references('user_id')
                    ->on('user')
                    ->onDelete('CASCADE');
            });
        }

        if (!DB::schema()->hasTable('portal_message')) {
            DB::schema()->create('portal_message', static function (Blueprint $table): void {
                $table->integer('id', true);
                $table->integer('conversation_id');

                // An account, not an address. This is the whole reason the
                // table exists: `message.sender` is whatever e-mail the sender
                // happened to have at the time, and a transcript cannot be
                // built on something that stops resolving when somebody
                // changes their address.
                $table->integer('sender_id');

                // What was typed. Not a rendered template with a greeting and
                // sixty hyphens around it.
                $table->text('body');

                $table->integer('created_at');

                // When the *other* one read it. One column is enough because
                // there are exactly two people: every message has exactly one
                // recipient, and it is whichever of the pair did not send it.
                $table->integer('read_at')->nullable();

                // Deleting is for oneself only. Two columns rather than a
                // second table, again because the participants are fixed: one
                // per side of the pair, named after the conversation's own
                // ordering. A row hidden by both is deleted outright — the
                // portal does not keep what nobody can see.
                $table->integer('hidden_one_at')->nullable();
                $table->integer('hidden_two_at')->nullable();

                // Every read is "the messages of this conversation, newest
                // last", so the pair is the index.
                $table->index(['conversation_id', 'id']);

                $table->foreign('conversation_id')
                    ->references('id')
                    ->on('portal_conversation')
                    ->onDelete('CASCADE');

                $table->foreign('sender_id')
                    ->references('user_id')
                    ->on('user')
                    ->onDelete('CASCADE');
            });
        }
    }
}
