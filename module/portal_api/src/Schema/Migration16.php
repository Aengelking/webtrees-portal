<?php

declare(strict_types=1);

namespace Engelking\Webtrees\PortalApi\Schema;

use Fisharebest\Webtrees\DB;
use Fisharebest\Webtrees\Schema\MigrationInterface;
use Illuminate\Database\Schema\Blueprint;

/**
 * The credentials an assistant reads the family archive with.
 *
 * Everything else in this module is opened by a person: a member types a
 * password, or a browser presents a cookie that stands for having typed one.
 * The MCP server is opened by a *program* — Claude on somebody's laptop, an
 * agent on a server — and a program has no way to be asked. So it carries a
 * token, and a token is the whole of its authority.
 *
 * Three things follow from that, and they are why this table looks the way it
 * does.
 *
 * **The token is stored as a SHA-256 and never as itself**, like every other
 * credential here. It is shown once, when it is issued, and after that the
 * only copy in the world is in whatever configuration file the administrator
 * pasted it into. A token that cannot be recovered is a token that a database
 * dump does not hand out.
 *
 * **A token is not an identity of its own.** `wt_user_id` names the webtrees
 * account it reads as, and every record it can reach is filtered at *that*
 * account's access level, by webtrees' own privacy code, exactly as if the
 * person were signed in. There is no second permission system to keep in step
 * with the first — and taking the account away, or changing its role on the
 * tree, changes what the token can see at once. The foreign key means
 * deleting the account deletes the token with it.
 *
 * **It expires, and it can be called off.** `expires_at` is not nullable on
 * purpose: a credential that lives in a configuration file on somebody's
 * laptop, for a family archive, should have an end date whether or not anybody
 * remembers to set one. `revoked_at` is the answer to a laptop that was lost
 * this morning, and the row is kept rather than deleted so that the screen can
 * say the token was withdrawn rather than quietly forgetting it existed.
 *
 * `last_used_at` and `uses` are the only record this module keeps of what an
 * assistant did, and deliberately the least of one: when, and how often. Not
 * what was asked, and not about whom — a log of the questions somebody's
 * assistant asked about their family is a far more sensitive thing than the
 * archive it was asking about, and this portal has no business keeping one.
 */
class Migration16 implements MigrationInterface
{
    public function upgrade(): void
    {
        if (DB::schema()->hasTable('portal_mcp_token')) {
            return;
        }

        DB::schema()->create('portal_mcp_token', static function (Blueprint $table): void {
            $table->integer('id', true);

            // SHA-256 of the token the client sends. Never the token.
            $table->string('token_hash', 64);

            // What the administrator calls it — "Claude on the study Mac".
            // For their screen, so that revoking the right one is possible.
            $table->string('name', 128);

            // The account this token reads the archive as. See the class note:
            // the token grants nothing of its own.
            $table->integer('wt_user_id');

            $table->integer('created_by')->nullable();
            $table->integer('created_at');
            $table->integer('expires_at');
            $table->integer('revoked_at')->nullable();

            // When it was last presented, and how many times in total. The
            // whole of the audit trail — see the class note for why it stops
            // there.
            $table->integer('last_used_at')->nullable();
            $table->integer('uses')->default(0);

            $table->unique('token_hash');
            $table->index('wt_user_id');

            $table->foreign('wt_user_id')
                ->references('user_id')
                ->on('user')
                ->onDelete('CASCADE');
        });
    }
}
