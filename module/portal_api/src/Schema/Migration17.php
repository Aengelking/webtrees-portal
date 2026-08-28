<?php

declare(strict_types=1);

namespace Engelking\Webtrees\PortalApi\Schema;

use Fisharebest\Webtrees\DB;
use Fisharebest\Webtrees\Schema\MigrationInterface;
use Illuminate\Database\Schema\Blueprint;

/**
 * Who holds an office in the foundation.
 *
 * **Why this is not a fact on the record.** An office is something the
 * *foundation* says about a person; a GEDCOM fact is something the *archive*
 * says about them, and the portal takes that distinction seriously enough to
 * have built two services around it. A fact would follow the record's privacy:
 * for a living member outside the reader's few steps of the tree the record is
 * withheld whole (§2.25), so the office would vanish for exactly the members
 * who are looking for somebody to write to. Putting it here beside the display
 * name — portal data, not genealogy (§2.7) — is what lets a chairwoman stay
 * legible to a relative who may not read her record.
 *
 * **Keyed by the person in the tree, not by an account.** An office is held by
 * a person, and not every officer signs in: a Stiftungsrat who has never
 * opened the portal still belongs on the card of the person the family is
 * looking at. One key also reaches both screens — the directory finds a
 * member's xref through webtrees' own `gedcomid` user setting, so the office
 * survives a closed record there too.
 *
 * Not a foreign key, for the reason `portal_photo` gives: `individuals` is
 * keyed by (xref, gedcom_id), a portal serves one tree (§1.2), and a record
 * removed in webtrees must leave this row behind harmlessly rather than fail
 * a delete. A row whose person is gone simply names nobody.
 *
 * **One office per person.** The card has one line for it and the honest thing
 * is for there to be one answer. A person who genuinely holds two is written
 * as one title; the day that stops being true, this unique index is the thing
 * to lift, and `sort_order` is already here to give the list a shape when it
 * happens.
 */
class Migration17 implements MigrationInterface
{
    public function upgrade(): void
    {
        if (DB::schema()->hasTable('portal_office')) {
            return;
        }

        DB::schema()->create('portal_office', static function (Blueprint $table): void {
            $table->integer('id', true);

            // The person in the family tree who holds it.
            $table->string('xref', 20);

            // What it is called, in the foundation's own words. Free text
            // rather than a list of permitted offices: a list is never the
            // rule, and a statute that renames a body should not need a
            // deployment.
            $table->string('title', 128);

            // Vorsitz before Beisitz. Nothing reads it yet — the cards show
            // one office each — but an order that is only decided the day a
            // list needs one gets decided by whoever typed fastest.
            $table->integer('sort_order')->default(0);

            $table->integer('created_at');
            $table->integer('updated_at');

            $table->unique('xref');
            $table->index('sort_order');
        });
    }
}
