<?php

declare(strict_types=1);

namespace Engelking\Webtrees\PortalApi\Schema;

use Fisharebest\Webtrees\DB;
use Fisharebest\Webtrees\Schema\MigrationInterface;
use Illuminate\Database\Schema\Blueprint;

/**
 * An address is four answers, not one line.
 *
 * "Musterstraße 12, 29223 Celle" typed into a single box is a string; street,
 * postcode, town and country are fields. The difference shows up the moment
 * anybody wants to do anything with it — sort by town, find who else lives in
 * the same place, hand it to a maps application, print it on an envelope in
 * the order that country uses. It also shows up while it is being *typed*: a
 * phone offers a numeric keyboard for a postcode field and its autofill knows
 * what a street is, and neither is true of a box labelled "Adresse".
 *
 * **The parts live beside the value rather than instead of it.** `value` stays
 * exactly what it was — the whole address as one readable piece of text — and
 * it is what every reader still gets (`ContactDetails::visibleTo()`), because
 * a member looking at a relative's page wants an address to read, not four
 * fields to reassemble. `parts` is the same address in the shape the member
 * typed it, so the form can put every answer back in the box it came out of.
 *
 * That is deliberately a little redundant, and it buys two things: nothing on
 * the reading side had to change to accommodate this, and a row written by an
 * older module — one line, no parts — is still a perfectly good address. It is
 * read back with `ContactDetails::partsFrom()`, which makes the best sense it
 * can of the text; the member's next save replaces the guess with their own
 * answers.
 *
 * Only the address has parts. An e-mail address and a telephone number are
 * genuinely one value each, and inventing structure for them would be
 * structure nobody asked for.
 */
class Migration11 implements MigrationInterface
{
    public function upgrade(): void
    {
        if (!DB::schema()->hasTable('portal_contact_detail')) {
            return;
        }

        if (DB::schema()->hasColumn('portal_contact_detail', 'parts')) {
            return;
        }

        DB::schema()->table('portal_contact_detail', static function (Blueprint $table): void {
            // JSON as text, and nullable, because most rows have no parts at
            // all: a text column is the same thing in every database this
            // module has to run on, and nothing here is ever queried by it.
            $table->text('parts')->nullable();
        });
    }
}
