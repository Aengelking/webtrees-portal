<?php

declare(strict_types=1);

namespace Engelking\Webtrees\PortalApi\Schema;

use Fisharebest\Webtrees\DB;
use Fisharebest\Webtrees\Schema\MigrationInterface;
use Illuminate\Database\Schema\Blueprint;

/**
 * Inviting a mailing list, which cannot be done the obvious way.
 *
 * The obvious way is to put a personal invitation link in the round-robin
 * letter. It does not work, and the reason is worth stating before the tables
 * are: a distribution list is one address that fans out to three hundred
 * people, so one letter carries one link. An invitation in this portal is a
 * credential — one-time, hashed, and in `portal_invitation` it names a person.
 * Three hundred copies of one credential is not three hundred invitations; it
 * is one, and whoever opens the letter first spends it on somebody else's
 * account.
 *
 * So the letter carries something that is **not** a credential. A campaign
 * link opens a page with one field on it: your own address. If that address is
 * on one of the lists the campaign names, the personal invitation is made
 * there and then and sent to *that address* — so the thing that proves who you
 * are is the one thing a round-robin letter cannot forward, which is access to
 * your own mailbox.
 *
 * That keeps §1.3's rule intact. Nobody registers who was not asked: being on
 * the family's mailing list is the asking, and it happened years ago.
 *
 * **`portal_invitation_campaign`** is the letter's half. It holds no
 * addresses — which lists it covers, how long it is good for, and whether it
 * has been called off. The token is a SHA-256 like every other token here; the
 * raw value exists in whatever the administrator pasted into Outlook.
 *
 * **`portal_invitation_claim`** is the reply's half, and it exists for three
 * jobs at once. It stops one address being mailed repeatedly by somebody
 * pressing the button; it is how an administrator sees whether the letter did
 * anything; and its `outcome` is the only place that records what happened to
 * an address without recording the address. A hash again: what is needed is
 * "this one, again?", which a hash answers, and the roster of who did not
 * respond is not this portal's business to keep.
 */
class Migration15 implements MigrationInterface
{
    public function upgrade(): void
    {
        if (!DB::schema()->hasTable('portal_invitation_campaign')) {
            DB::schema()->create('portal_invitation_campaign', static function (Blueprint $table): void {
                $table->integer('id', true);

                // SHA-256 of the token in the link. Never the token.
                $table->string('token_hash', 64);

                // What the administrator calls it, for their own screen.
                $table->string('name', 128);

                // The lists this campaign will accept an address from, by the
                // same hash `portal_list_subscription` uses. Newline
                // separated: a campaign covers a handful at the outside.
                $table->text('lists');

                $table->integer('created_by')->nullable();
                $table->integer('created_at');
                $table->integer('expires_at');

                // Called off early. Kept rather than deleted, because the
                // letter is out in the world and "why does my link say no"
                // deserves an answer.
                $table->integer('revoked_at')->nullable();

                $table->unique('token_hash');
            });
        }

        if (DB::schema()->hasTable('portal_invitation_claim')) {
            return;
        }

        DB::schema()->create('portal_invitation_claim', static function (Blueprint $table): void {
            $table->integer('id', true);

            $table->integer('campaign_id');

            // SHA-256 of the address, lower-cased. See the class note.
            $table->string('address_hash', 64);

            // What became of it: `invited`, `existing` (there was already an
            // account), or `unknown` (the address is on no list this campaign
            // covers). The last one is recorded because a letter that produces
            // nothing but `unknown` is a letter sent to the wrong list, and
            // an administrator has no other way to see that.
            $table->string('outcome', 16);

            // The invitation that was made, where one was. Lets an
            // administrator follow a claim through to `portal_invitation`
            // without this table naming anybody.
            $table->integer('invitation_id')->nullable();

            $table->integer('claimed_at');

            $table->unique(['campaign_id', 'address_hash']);
            $table->index('campaign_id');

            $table->foreign('campaign_id')
                ->references('id')
                ->on('portal_invitation_campaign')
                ->onDelete('CASCADE');
        });
    }
}
