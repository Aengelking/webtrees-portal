<?php

declare(strict_types=1);

namespace Engelking\Webtrees\PortalApi\Schema;

use Fisharebest\Webtrees\DB;
use Fisharebest\Webtrees\Schema\MigrationInterface;
use Illuminate\Database\Schema\Blueprint;

/**
 * An office, in the other languages the family reads the portal in.
 *
 * §2.82 gave the foundation a place to name its officers and, without meaning
 * to, gave every one of them exactly one language. The portal answers in
 * whatever language the member is reading — fact labels, dates, branch names
 * (§2.17) — and the office alone stayed in the words it was typed in.
 *
 * The notation is the one the branch table already uses, because it is the
 * same problem: a phrase the *family* wrote, which cannot live in the portal's
 * translation files (a statute that renames a body must not need a
 * deployment) and must not be stuck in one language either. `TranslatedText`
 * is now the one parser for both.
 *
 * A separate column rather than a wider `title`, for two reasons. Adding a
 * column is the additive change every other migration here makes; altering a
 * type is the one that goes differently on different databases. And the
 * written name keeps a field of its own, which is what lets the control panel
 * show the office an administrator typed without first taking a sentence
 * apart.
 *
 * Nullable, and every existing row keeps its `title` and simply answers with
 * it in every language — which is exactly what it did before this ran.
 */
class Migration18 implements MigrationInterface
{
    public function upgrade(): void
    {
        if (DB::schema()->hasColumn('portal_office', 'translations')) {
            return;
        }

        DB::schema()->table('portal_office', static function (Blueprint $table): void {
            // `en: Chair of the board | fr: Président du conseil`. Text
            // rather than a short string: this is one field holding as many
            // languages as the family cares to write.
            $table->text('translations')->nullable();
        });
    }
}
