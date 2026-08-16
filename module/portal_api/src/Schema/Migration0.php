<?php

declare(strict_types=1);

namespace Engelking\Webtrees\PortalApi\Schema;

use Fisharebest\Webtrees\DB;
use Fisharebest\Webtrees\Schema\MigrationInterface;
use Illuminate\Database\Schema\Blueprint;

/**
 * Create the portal's own tables.
 *
 * A GEDCOM XREF is never a key here: XREFs are rewritten on re-import. The
 * link between a member and an individual record is webtrees' own per-tree
 * user setting (`gedcomid`), read through core APIs, not duplicated here.
 */
class Migration0 implements MigrationInterface
{
    public function upgrade(): void
    {
        if (!DB::schema()->hasTable('portal_member_profile')) {
            DB::schema()->create('portal_member_profile', static function (Blueprint $table): void {
                $table->integer('id', true);
                $table->integer('wt_user_id');
                $table->boolean('visible_in_directory')->default(false);
                $table->timestamp('consent_recorded_at')->nullable();
                $table->string('display_name_override', 128)->nullable();
                $table->timestamp('created_at')->nullable();
                $table->timestamp('updated_at')->nullable();

                $table->unique('wt_user_id');
            });

            DB::schema()->table('portal_member_profile', static function (Blueprint $table): void {
                $table->foreign('wt_user_id')
                    ->references('user_id')
                    ->on('user')
                    ->onDelete('CASCADE')
                    ->onUpdate('CASCADE');
            });
        }

        // Login attempts, for rate-limiting POST /session. Usernames are
        // stored as a hash: the portal must not build a second list of who
        // has an account, and this table is only ever read by equality.
        if (!DB::schema()->hasTable('portal_login_attempt')) {
            DB::schema()->create('portal_login_attempt', static function (Blueprint $table): void {
                $table->integer('id', true);
                $table->string('ip_address', 45);
                $table->string('username_hash', 64);
                $table->integer('attempted_at');

                $table->index(['ip_address', 'attempted_at']);
                $table->index(['username_hash', 'attempted_at']);
            });
        }
    }
}
