<?php

declare(strict_types=1);

namespace Engelking\Webtrees\PortalApi\Schema;

use Fisharebest\Webtrees\DB;
use Fisharebest\Webtrees\Schema\MigrationInterface;
use Illuminate\Database\Schema\Blueprint;

/**
 * Who put a photograph there — the one fact webtrees does not record.
 *
 * A GEDCOM media object says what the file is and what it is called. It does
 * not say who uploaded it, because in webtrees that question has one answer:
 * whoever keeps the tree. In a portal used by the people *in* the tree it has
 * a second answer, and the difference between the two is the whole of this
 * table.
 *
 * **The rule it exists for.** A photograph of a living person is shown in the
 * portal only if that person put it there themselves. The argument is the one
 * the portal already makes about contact details: what the family tree happens
 * to hold about somebody is not something they consented to publish. A face is
 * the least deniable thing on a record, and a member who never asked to be
 * shown to a hundred relatives has not agreed to it by existing in a GEDCOM.
 *
 * Photographs of the dead are untouched. Nobody can consent on their behalf,
 * and nobody needs to: consent is a question about people who can be harmed by
 * the answer, and the family archive is what a portal like this is *for*.
 *
 * **A row is the consent, so losing the row withdraws it.** The foreign key
 * cascades: an account that is deleted takes its uploads' provenance with it,
 * and the photographs stop being shown. They are not deleted from the tree —
 * that is the family's record and not the portal's to prune — they simply stop
 * being something the portal claims permission for.
 */
class Migration9 implements MigrationInterface
{
    public function upgrade(): void
    {
        if (DB::schema()->hasTable('portal_photo')) {
            return;
        }

        DB::schema()->create('portal_photo', static function (Blueprint $table): void {
            $table->integer('id', true);

            // Who uploaded it, which in this table means: whose consent this is.
            $table->integer('wt_user_id');

            // The webtrees media record. Not a foreign key: `media` is keyed by
            // (xref, gedcom_id) and a portal serves one tree, so the xref is
            // the whole identity here — and a media record removed in webtrees
            // must leave this row behind harmlessly rather than fail a delete.
            $table->string('media_xref', 20);

            $table->integer('created_at');

            // One uploader per photograph. Two rows claiming the same picture
            // would be two people consenting to one thing.
            $table->unique('media_xref');
            $table->index('wt_user_id');

            $table->foreign('wt_user_id')
                ->references('user_id')
                ->on('user')
                ->onDelete('CASCADE');
        });
    }
}
