<?php

declare(strict_types=1);

namespace Engelking\Webtrees\PortalApi\Schema;

use Fisharebest\Webtrees\DB;
use Fisharebest\Webtrees\Schema\MigrationInterface;
use Illuminate\Database\Schema\Blueprint;

/**
 * Somewhere for an administrator to see that the portal is broken.
 *
 * Until now an unhandled error went to `error_log()` and nowhere else. On
 * shared hosting that file is somewhere between hard to find and not readable
 * at all, so in practice nobody ever saw one. The member got a polite "please
 * try again later" and that was the end of it: a portal can be broken for one
 * person for weeks without anyone knowing.
 *
 * What is deliberately **not** in this table:
 *
 *  - the request body, which for `PUT /me/individual` is somebody's date of
 *    birth;
 *  - the query string, which for the directory is whatever they searched for;
 *  - the request path, which for `/individuals/X123` names a record.
 *
 * The route's *name* is stored instead — the handler class, which says which
 * endpoint failed without saying who or what it was asked about. That is
 * enough to find the bug, and it is the difference between a diagnostic table
 * and a second copy of the family's data.
 *
 * `error_log()` still gets the full message as well, for anyone who does have
 * the server log. This table is the copy that is actually reachable.
 */
class Migration2 implements MigrationInterface
{
    public function upgrade(): void
    {
        if (DB::schema()->hasTable('portal_error')) {
            return;
        }

        DB::schema()->create('portal_error', static function (Blueprint $table): void {
            $table->integer('id', true);

            // Short, and shown to the member. It is how "it did not work"
            // becomes a row an administrator can actually find.
            $table->string('reference', 12);

            $table->integer('occurred_at');
            $table->integer('status');

            // The route's name — a handler class — not the request path.
            $table->string('route', 100)->nullable();
            $table->string('method', 10)->nullable();

            $table->string('error_class', 191)->nullable();
            $table->string('message', 500)->nullable();
            $table->string('file', 255)->nullable();
            $table->integer('line')->nullable();

            // Who hit it, when there was a session. Null for an error thrown
            // before or without one, which is most of the interesting cases.
            $table->integer('wt_user_id')->nullable();

            $table->index('reference');
            $table->index('occurred_at');

            $table->foreign('wt_user_id')
                ->references('user_id')
                ->on('user')
                ->onDelete('SET NULL')
                ->onUpdate('CASCADE');
        });
    }
}
