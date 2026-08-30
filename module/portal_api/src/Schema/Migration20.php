<?php

declare(strict_types=1);

namespace Engelking\Webtrees\PortalApi\Schema;

use Fisharebest\Webtrees\DB;
use Fisharebest\Webtrees\Schema\MigrationInterface;
use Illuminate\Database\Schema\Blueprint;

/**
 * When was this member actually asked about the directory?
 *
 * `visible_in_directory` is off by default and has to be switched on by the
 * member themselves (§1.3, and `MemberService::ensureProfile`). That is the
 * right default and it has one flaw in practice: nothing ever asked. The
 * switch sits in the settings, most members never open the settings, and a
 * directory that nobody is in makes the contacts screen useless for everyone
 * — including the members who would gladly have said yes.
 *
 * The portal now asks, once, on the member's own profile. Which needs a
 * column, because the question is "has this person decided?" and neither
 * existing column answers it: `visible_in_directory = 0` is both "no thank
 * you" and "never asked", and `consent_recorded_at` is deliberately cleared
 * again when somebody leaves the directory (`MemberService::updateProfile`) —
 * withdrawn consent must not leave a record saying it was given.
 *
 * So: a separate timestamp, set the moment a member answers either way, and
 * never cleared. It records that a question was answered, not what the answer
 * was — the answer is `visible_in_directory`, which the member may change as
 * often as they like without being asked again.
 *
 * **The backfill is the point of doing this in the schema rather than in the
 * browser.** A device-local "already asked" flag would ask the same person
 * again on their next telephone and would never reach the members who signed
 * in months ago — who are exactly the ones this is for. Rows that are visible
 * in the directory, or that carry a consent timestamp, plainly decided: they
 * are marked as such and see nothing. Everybody else is asked once, which
 * includes the handful who switched the directory off again by hand. Being
 * asked once more is a small cost; the alternative is asking nobody.
 */
class Migration20 implements MigrationInterface
{
    public function upgrade(): void
    {
        if (!DB::schema()->hasColumn('portal_member_profile', 'directory_decided_at')) {
            DB::schema()->table('portal_member_profile', static function (Blueprint $table): void {
                $table->timestamp('directory_decided_at')->nullable();
            });
        }

        // Whoever is listed, or has a consent timestamp, has answered this
        // question already. `consent_recorded_at` first, because that is when
        // they answered it; `updated_at` for a row that lost the consent
        // timestamp along the way but is still listed.
        //
        // `whereNull` rather than the column check above as the guard, so that
        // this can be run twice without touching a row somebody has since
        // answered — and so that it can be run at all in a test, where the
        // column exists before the migration does.
        DB::table('portal_member_profile')
            ->whereNull('directory_decided_at')
            ->where(static function ($query): void {
                $query->where('visible_in_directory', '=', 1)
                    ->orWhereNotNull('consent_recorded_at');
            })
            ->update([
                'directory_decided_at' => DB::raw('COALESCE(consent_recorded_at, updated_at, created_at)'),
            ]);
    }
}
