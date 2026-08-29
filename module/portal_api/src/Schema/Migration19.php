<?php

declare(strict_types=1);

namespace Engelking\Webtrees\PortalApi\Schema;

use Fisharebest\Webtrees\DB;
use Fisharebest\Webtrees\Schema\MigrationInterface;
use Illuminate\Database\Schema\Blueprint;

/**
 * Asking for a way in, when there is no letter to answer.
 *
 * §2.71's campaign works because the family's mailing list already answers the
 * only hard question: *is this person one of us?* Being on it is the asking,
 * and it happened years ago. A notice in the family magazine reaches further
 * than any list does — it goes to people the portal has never had an address
 * for — and for them the campaign page is a dead end by design: an address on
 * no list gets the same silence as one that was never family, because the page
 * must not become a way of asking who is.
 *
 * So this table is the other half. Somebody who cannot be recognised
 * automatically writes down who they are and asks; **nothing is created and
 * nothing is sent**; and an administrator, who can answer the question the
 * software cannot, turns it into an invitation or into nothing. §1.3's rule is
 * intact — no account exists that a person did not decide on — and the one
 * thing that changes is that the deciding no longer has to start with an email
 * out of the blue.
 *
 * **The address is in the clear here, and that is the difference from
 * `portal_invitation_claim`.** A claim hashes it because what that table needs
 * is "this one, again?", and a roster of who did not answer a letter is nobody's
 * business to keep. A request is the opposite: it is a message somebody
 * deliberately sent, addressed to the family, and the address *is* the point —
 * it is where the invitation would go, and an administrator cannot read a hash
 * and decide. What is stored is what the sender typed and nothing else: this
 * table never learns whether the address is real, whether the number matches a
 * record, or whether the person is in the tree at all.
 *
 * **It is a queue and not an archive.** Handled rows are kept for a while,
 * because "who let this person in, and on what grounds" is asked afterwards;
 * see `AccessRequests::RETAIN_DAYS` for how long, and `prune()` for where it
 * happens. Unanswered ones are kept until somebody answers them — a request
 * that quietly expired would leave a person waiting for a reply that nobody
 * ever decided against.
 */
class Migration19 implements MigrationInterface
{
    public function upgrade(): void
    {
        if (DB::schema()->hasTable('portal_access_request')) {
            return;
        }

        DB::schema()->create('portal_access_request', static function (Blueprint $table): void {
            $table->integer('id', true);

            // What they call themselves. Not checked against anything: the
            // whole point of this row is that the software cannot check.
            $table->string('name', 128);

            // The archive number as it was typed, where they knew one. It is
            // printed beside every name in the family magazine, so a reader
            // usually has theirs to hand — and where it names exactly one
            // record, the administrator's screen shows which, so that an
            // invitation can arrive already linked.
            $table->string('reference', 64)->nullable();

            // Where an invitation would go, if one is issued.
            $table->string('email', 128);

            // Two sentences on how they belong, for the human who decides.
            $table->string('note', 500)->nullable();

            $table->integer('created_at');

            // Asked again. The row is updated rather than duplicated, so a
            // second attempt is one entry with a newer date, not two.
            $table->integer('updated_at');

            // Null while it is waiting. Set together with `outcome` and
            // `handled_by`, so "open" is one question with one answer.
            $table->integer('handled_at')->nullable();
            $table->integer('handled_by')->nullable();

            // 'invited' or 'declined'. Which administrator, and what they
            // decided — the two things asked afterwards.
            $table->string('outcome', 16)->nullable();

            // The queue is read newest-first and deduplicated by address.
            $table->index(['handled_at', 'created_at']);
            $table->index(['email']);
        });
    }
}
