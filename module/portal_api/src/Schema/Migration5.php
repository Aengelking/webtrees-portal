<?php

declare(strict_types=1);

namespace Engelking\Webtrees\PortalApi\Schema;

use Fisharebest\Webtrees\DB;
use Fisharebest\Webtrees\Schema\MigrationInterface;
use Illuminate\Database\Schema\Blueprint;

/**
 * Who is in touch with whom, and the short-lived codes that arrange it.
 *
 * The directory answers "who is in this family". It does not answer "whom do
 * I actually know", and the two are not the same question: a member scrolling
 * two hundred names is looking for the eight people they met at the last
 * family gathering. A connection is that answer, kept by the member.
 *
 * **Both sides said yes, always.** A row is only `accepted` because the other
 * person did something: confirmed a request, or showed the code that was
 * scanned. Nothing here can be arranged by one member alone, which is what
 * makes it safe to hang a disclosure on — `portal_contact_detail` gains an
 * audience of "my contacts", and a contact may be written to and looked at
 * even when they stayed out of the directory.
 *
 * **A connection is not a relationship.** It says nothing about the family
 * tree and is not derived from it. Two cousins who never met are not
 * connected; a member and their neighbour's grandmother in the tree may be.
 * It is address-book data, and it belongs to the two people in the row.
 *
 * **Either side may end it, at any time, without asking.** Deleting the row
 * is the whole of it: there is no "blocked" state and no archive of who used
 * to know whom, because neither would be information this portal has any
 * business keeping.
 */
class Migration5 implements MigrationInterface
{
    public function upgrade(): void
    {
        if (!DB::schema()->hasTable('portal_connection')) {
            DB::schema()->create('portal_connection', static function (Blueprint $table): void {
                $table->integer('id', true);

                // Who asked, and who was asked. Kept apart after the answer
                // as well: an accepted connection is symmetric, but "you
                // asked me" is what a member needs in order to recognise the
                // row a week later.
                $table->integer('requested_by');
                $table->integer('requested_of');

                // 'pending' or 'accepted'. There is no 'declined': a refusal
                // deletes the row, so that nothing keeps a record of a member
                // having said no to somebody.
                $table->string('status', 20)->default('pending');

                // 'code' or 'reference' — how the two found each other. Kept
                // because it is the difference between "we were in the same
                // room" and "somebody typed my number", and a member deciding
                // whether to keep a connection may want to know which.
                $table->string('source', 20)->default('reference');

                $table->integer('created_at');
                $table->integer('decided_at')->nullable();

                // One row per ordered pair. The reverse pair is prevented in
                // code rather than by a key — a request that crosses one
                // coming the other way is treated as the answer to it, which
                // is what the two people plainly meant.
                $table->unique(['requested_by', 'requested_of']);
                $table->index(['requested_of', 'status']);

                $table->foreign('requested_by')
                    ->references('user_id')
                    ->on('user')
                    ->onDelete('CASCADE')
                    ->onUpdate('CASCADE');

                $table->foreign('requested_of')
                    ->references('user_id')
                    ->on('user')
                    ->onDelete('CASCADE')
                    ->onUpdate('CASCADE');
            });
        }

        if (DB::schema()->hasTable('portal_connection_code')) {
            return;
        }

        DB::schema()->create('portal_connection_code', static function (Blueprint $table): void {
            $table->integer('id', true);

            // One live code per member. Asking for another replaces it, so
            // the code on the screen is the only one that works.
            $table->integer('wt_user_id')->unique();

            // Hashed, exactly like an invitation token: the code is shown on
            // a screen and photographed by whoever is standing there, so it
            // is a credential for the few minutes it lives, and a database
            // dump should not contain a usable one.
            $table->string('token_hash', 64);

            $table->integer('created_at');
            $table->integer('expires_at');

            $table->foreign('wt_user_id')
                ->references('user_id')
                ->on('user')
                ->onDelete('CASCADE')
                ->onUpdate('CASCADE');
        });
    }
}
