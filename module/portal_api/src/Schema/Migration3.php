<?php

declare(strict_types=1);

namespace Engelking\Webtrees\PortalApi\Schema;

use Fisharebest\Webtrees\DB;
use Fisharebest\Webtrees\Schema\MigrationInterface;
use Illuminate\Database\Schema\Blueprint;

/**
 * Contact details a member chose to share, and with whom.
 *
 * **These do not come from the family tree.** `RecordPresenter` still refuses
 * to publish `ADDR`, `EMAIL`, `PHON` and `WWW` on anybody's record but their
 * own (NOTES §2.6, §2.0.1), and that does not change here. GEDCOM contact
 * data is maintained by whoever keeps the tree, not by the person it
 * describes — so consenting to publish "whatever my record happens to say"
 * is not informed consent, and the content can change without the member ever
 * knowing. What is shared here is what the member typed, into this table,
 * about themselves.
 *
 * That is the same line §2.7 already draws for the directory: portal data is
 * consent, GEDCOM is genealogy, and the two are not mixed.
 *
 * **One row is one decision.** A row carries a value *and* the audience for
 * that value, because "my email may go to the whole family" and "my address
 * is for my brother" are different decisions and a single switch cannot hold
 * both. A row that does not exist is not shared, which makes deleting the
 * value and withdrawing consent the same act.
 */
class Migration3 implements MigrationInterface
{
    public function upgrade(): void
    {
        if (DB::schema()->hasTable('portal_contact_detail')) {
            return;
        }

        DB::schema()->create('portal_contact_detail', static function (Blueprint $table): void {
            $table->integer('id', true);
            $table->integer('wt_user_id');

            // 'email', 'phone', 'address'. A column rather than a table of
            // its own: the list is short, closed, and decided in code.
            $table->string('kind', 20);

            $table->string('value', 255);

            // 'nobody', 'close_family' or 'members'. Stored per row, and
            // never defaulted to anything but the narrowest.
            $table->string('audience', 20)->default('nobody');

            $table->timestamp('created_at')->nullable();
            $table->timestamp('updated_at')->nullable();

            // One entry of each kind per member. Sharing two telephone
            // numbers with different audiences is a question nobody has
            // asked, and answering it would double this screen.
            $table->unique(['wt_user_id', 'kind']);

            $table->foreign('wt_user_id')
                ->references('user_id')
                ->on('user')
                ->onDelete('CASCADE')
                ->onUpdate('CASCADE');
        });
    }
}
