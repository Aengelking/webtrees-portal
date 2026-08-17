<?php

declare(strict_types=1);

namespace Engelking\Webtrees\PortalApi\Services;

use Fisharebest\Webtrees\DB;
use Fisharebest\Webtrees\GedcomRecord;

/**
 * Does this record have an edit waiting for an editor?
 *
 * webtrees itself will not tell us. `AbstractGedcomRecordFactory::pendingChanges()`
 * returns an empty collection unless the viewer is an editor, so for a member
 * every record reads as the approved version and `isPendingAddition()` is
 * always false. That is the right default for *display* — a member should see
 * what has been approved — but the portal needs the answer for two other
 * reasons, so it asks the `change` table directly.
 *
 * This is not a privacy shortcut. `change` is webtrees' edit queue, not a
 * genealogy table; no record content is read from it, only whether a row
 * exists. Record assembly still goes through GedcomRecord in RecordPresenter,
 * and nowhere else.
 */
class PendingChanges
{
    /**
     * Used for two things:
     *
     * - telling the member their change was received but is not live yet;
     * - refusing a second edit while the first is unapproved. A second edit
     *   would be built from the approved record, since that is all a member
     *   can see, so accepting it would silently discard the first once an
     *   editor applied them in order.
     */
    public function existsFor(GedcomRecord $record): bool
    {
        return DB::table('change')
            ->where('gedcom_id', '=', $record->tree()->id())
            ->where('xref', '=', $record->xref())
            ->where('status', '=', 'pending')
            ->exists();
    }
}
