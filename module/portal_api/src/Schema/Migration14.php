<?php

declare(strict_types=1);

namespace Engelking\Webtrees\PortalApi\Schema;

use Fisharebest\Webtrees\DB;
use Fisharebest\Webtrees\Schema\MigrationInterface;
use Illuminate\Database\Schema\Blueprint;

/**
 * When a list was last *read*, as against when it was last *asked*.
 *
 * `Migration13` had one column for both, and conflating them was a bug with
 * teeth. A list that could not be read got a row holding no members and a
 * fresh timestamp — which the screen could not tell apart from "read, and
 * nobody is on this list". Every member of that list was told they were not
 * subscribed, and it stayed that way, because each further failure only moved
 * the timestamp along.
 *
 * Separating them makes both jobs possible at once. `fetched_at` is the last
 * attempt and is what holds a failing list off for a few minutes, so an
 * Exchange outage is not felt as a portal that will not open. `read_at` is the
 * last answer, and a row without one is not an answer at all: the screen falls
 * back to what the portal itself recorded rather than asserting an emptiness
 * nobody established.
 *
 * Existing rows get `read_at` set from `fetched_at`, because until now a row
 * only ever existed after a read that was believed to have worked. That is
 * true of every row written by a version that could tell — and for the rows
 * written by the version that could not, the next refresh replaces them.
 */
class Migration14 implements MigrationInterface
{
    public function upgrade(): void
    {
        if (!DB::schema()->hasTable('portal_list_snapshot')) {
            return;
        }

        if (DB::schema()->hasColumn('portal_list_snapshot', 'read_at')) {
            return;
        }

        DB::schema()->table('portal_list_snapshot', static function (Blueprint $table): void {
            $table->integer('read_at')->nullable();
        });

        // Rows that predate the distinction. An empty one is exactly the case
        // this migration exists for, so it is left without a `read_at` and
        // will be read again rather than believed.
        DB::table('portal_list_snapshot')
            ->where('members', '<>', '')
            ->update(['read_at' => DB::raw('fetched_at')]);
    }
}
