<?php

declare(strict_types=1);

namespace Engelking\Webtrees\PortalApi\Schema;

use Fisharebest\Webtrees\DB;
use Fisharebest\Webtrees\Schema\MigrationInterface;
use Illuminate\Database\Schema\Blueprint;

/**
 * What Exchange said was on each list, and when it said it.
 *
 * `portal_list_subscription` holds decisions members made here. This holds the
 * one thing only Exchange knows: who is *actually* on a list, including
 * everybody who was put there years before this portal existed and has never
 * touched a switch. Without it the settings screen answered "not subscribed"
 * for those people — not a cautious answer but a wrong one, and about most of
 * the family.
 *
 * **One row per list, not per member.** There is no cmdlet for "which lists is
 * this address on", so the question has to be asked list by list; asking it
 * three times every time somebody opens their settings would put an Exchange
 * round trip in front of a screen that mostly gets read. One answer serves
 * every member who looks in the next few minutes.
 *
 * **The addresses are hashed.** What is useful here is "is *this* address on
 * that list", which a SHA-256 answers as well as the address does. What is not
 * wanted is a copy of the family's mailing list sitting in a second database —
 * the roster is Exchange's to keep, this portal only needs to recognise one
 * address at a time. A hash of a known address is guessable by anybody who
 * already knows it, so this is not secrecy; it is not keeping what there is no
 * reason to keep.
 *
 * **It is a cache and nothing depends on it.** An empty or stale snapshot
 * costs a wrong-looking switch until the next refresh, never a lost decision:
 * a member's own answer lives in the other table and is applied from there.
 * Truncating this table is a safe thing to do.
 */
class Migration13 implements MigrationInterface
{
    public function upgrade(): void
    {
        if (DB::schema()->hasTable('portal_list_snapshot')) {
            return;
        }

        DB::schema()->create('portal_list_snapshot', static function (Blueprint $table): void {
            $table->integer('id', true);

            // The same identity `portal_list_subscription` uses: SHA-256 of
            // the list's address, lower-cased.
            $table->string('list_hash', 64);

            // One SHA-256 per member address, newline separated. A family list
            // is hundreds of people at the outside, so a column beats a table
            // with a row per person and a join to go with it.
            $table->longText('members');

            // When Exchange was asked. The only thing that decides whether the
            // answer is still worth having.
            $table->integer('fetched_at');

            $table->unique('list_hash');
        });
    }
}
