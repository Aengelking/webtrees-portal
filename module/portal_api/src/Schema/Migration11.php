<?php

declare(strict_types=1);

namespace Engelking\Webtrees\PortalApi\Schema;

use Fisharebest\Webtrees\DB;
use Fisharebest\Webtrees\Schema\MigrationInterface;
use Illuminate\Database\Schema\Blueprint;

/**
 * Who wants the family's round-robin letters — one row per member per list.
 *
 * The list itself lives in Exchange and is not mirrored here. What this table
 * holds is the *wish*: a member said yes to this list, or said no to it, at a
 * moment that is recorded. Exchange is then made to agree, which is a separate
 * thing that can fail, be slow, or be undone by an administrator working in
 * the admin centre — so the two are kept apart deliberately, and `applied_at`
 * is the only place where they meet.
 *
 * **A "no" is a row, not a missing row.** Deleting on unsubscribe would lose
 * the two things worth keeping. The first is practical: an unsubscribe is
 * itself an instruction that still has to reach Exchange, and an instruction
 * with nowhere to live cannot be retried. The second is the reason it is
 * phrased as consent — "has never been asked" and "was asked and declined" are
 * different states, and only the second is an answer. Withdrawal is recorded
 * as carefully as agreement, which is the point of recording either.
 *
 * **`list_hash` is the identity, not the address.** A list is identified to
 * this table, and to the portal's API, by the SHA-256 of its address rather
 * than by the address itself. Two reasons, and the second is the real one.
 * The lesser: a `varchar` long enough for an address is an awkward thing to
 * put in a composite unique key. The greater: it is what lets the portal offer
 * a member a list without telling every member's browser where the family's
 * distribution lists live. A member subscribes to *the family news*, and the
 * address that stands behind it is the administrator's business.
 *
 * Renaming a list's address in Exchange therefore makes a new list here, and
 * the old subscriptions go quiet rather than following it. That is the honest
 * behaviour: nobody consented to be on an address they were never shown.
 *
 * **`address` is what was actually written to Exchange**, which is not
 * necessarily the member's address today. It is kept per row so that a member
 * who changes their email can be taken off the list under the old address and
 * put back under the new one — without it, the change would leave a stranger's
 * former address on a family list and nothing would know.
 */
class Migration11 implements MigrationInterface
{
    public function upgrade(): void
    {
        if (DB::schema()->hasTable('portal_list_subscription')) {
            return;
        }

        DB::schema()->create('portal_list_subscription', static function (Blueprint $table): void {
            $table->integer('id', true);

            $table->integer('wt_user_id');

            // SHA-256 of the list's address, lower-cased, hex encoded. Not a
            // secret and not treated as one — a hash of a known address is
            // guessable by anybody who knows the address. It is an identifier
            // that happens not to disclose, which is all that is wanted here.
            $table->string('list_hash', 64);

            // The address behind that hash, so that this row can be acted on
            // without the module's settings having to agree with it.
            $table->string('list_address', 255);

            // The member's address as it was written to Exchange.
            $table->string('address', 255);

            // The wish. False is a decision, not an absence — see above.
            $table->boolean('subscribed');

            // When the member last decided. This column is the consent record
            // and is never overwritten by anything the connector does.
            $table->integer('decided_at');

            // When Exchange was last made to agree with the column above.
            // Null means the instruction is still outstanding.
            $table->integer('applied_at')->nullable();

            // How many times the connector has tried since the last decision,
            // when it last did, and what it was told the last time it failed.
            // All three are cleared on success. `last_error` is shown to an
            // administrator and never to the member: it is Exchange's wording,
            // in Exchange's language, about a tenant the member knows nothing
            // about.
            //
            // `attempted_at` is what keeps a portal whose Exchange is down
            // from being a portal that takes ten seconds to open the settings
            // screen. An outstanding row is retried when a member visits, but
            // not more often than every few minutes, and only ever a few times
            // before it waits for somebody to look at it.
            $table->integer('attempts')->default(0);
            $table->integer('attempted_at')->nullable();
            $table->string('last_error', 500)->nullable();

            $table->unique(['wt_user_id', 'list_hash']);
            $table->index('list_hash');

            // Outstanding work, cheaply: the connector asks for rows where
            // this is null and nothing else.
            $table->index('applied_at');

            $table->foreign('wt_user_id')
                ->references('user_id')
                ->on('user')
                ->onDelete('CASCADE');
        });
    }
}
