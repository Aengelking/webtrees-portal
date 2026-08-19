<?php

declare(strict_types=1);

namespace Engelking\Webtrees\PortalApi\Schema;

use Fisharebest\Webtrees\DB;
use Fisharebest\Webtrees\Schema\MigrationInterface;
use Illuminate\Database\Schema\Blueprint;

/**
 * Invitations — the way a member gets an account.
 *
 * Phase 1 left this open (NOTES §1.3): an administrator had to create every
 * account by hand in webtrees and then remember to link it to the right
 * genealogy record. Self-registration is the other obvious answer and is the
 * wrong one for a family portal — it puts a form on the internet that anybody
 * can fill in, and no software can answer "is this person really family".
 *
 * So: an administrator issues an invitation for one individual in the tree,
 * sends the link to that person, and the person sets their own username and
 * password. Nobody registers who was not asked.
 *
 * Two things about this table are deliberate.
 *
 * **The token is not in it.** Only a SHA-256 of the token is stored. The
 * database is backed up, dumped and read by more people than the invitation
 * was ever sent to, and a stored token is a usable credential to every one of
 * them. The raw value exists once, in the administrator's browser, at the
 * moment it is created.
 *
 * **The XREF is a payload, not a key** (NOTES §2.8). XREFs are rewritten on
 * re-import, so nothing joins on this column: it is re-resolved through the
 * record factory when the invitation is redeemed, and an invitation whose
 * record has since moved simply produces an account with no link — which the
 * administrator's list of unlinked accounts then shows. `invited_name` is a
 * snapshot taken when the invitation was issued, so that redeeming one never
 * has to read genealogy data for somebody who is not yet signed in.
 */
class Migration1 implements MigrationInterface
{
    public function upgrade(): void
    {
        if (DB::schema()->hasTable('portal_invitation')) {
            return;
        }

        DB::schema()->create('portal_invitation', static function (Blueprint $table): void {
            $table->integer('id', true);

            // SHA-256 of the token, hex encoded. Unique, so that the lookup
            // is an index seek and two invitations can never collide.
            $table->string('token_hash', 64);

            // Which tree the invitation is for. An invitation outlives
            // neither its tree nor its purpose.
            $table->integer('gedcom_id');

            // The individual the new account will be linked to, and their
            // name as it read when the invitation was issued. Both may be
            // empty: an invitation that links to nothing is still useful for
            // somebody who is in the family but not yet in the tree.
            $table->string('xref', 20)->nullable();
            $table->string('invited_name', 255)->nullable();

            // Where the administrator sent it. Used only to prefill the form,
            // never to authenticate — holding the token is the credential.
            $table->string('email', 64)->nullable();

            $table->integer('created_by')->nullable();
            $table->integer('created_at');
            $table->integer('expires_at');

            // Set once, by a conditional update, so a token cannot be
            // redeemed twice even by two requests arriving together.
            $table->integer('redeemed_at')->nullable();
            $table->integer('redeemed_user_id')->nullable();

            $table->unique('token_hash');
            $table->index(['gedcom_id', 'expires_at']);

            // Declared inside create() rather than in a later ALTER: SQLite,
            // which the test suite runs on, cannot add a constraint to a
            // table that already exists.
            $table->foreign('gedcom_id')
                ->references('gedcom_id')
                ->on('gedcom')
                ->onDelete('CASCADE')
                ->onUpdate('CASCADE');

            // A departing administrator does not take the invitations they
            // issued with them, and a member who is later deleted does not
            // erase the record that they were once invited.
            $table->foreign('created_by')
                ->references('user_id')
                ->on('user')
                ->onDelete('SET NULL')
                ->onUpdate('CASCADE');

            $table->foreign('redeemed_user_id')
                ->references('user_id')
                ->on('user')
                ->onDelete('SET NULL')
                ->onUpdate('CASCADE');
        });
    }
}
