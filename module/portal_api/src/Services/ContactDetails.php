<?php

declare(strict_types=1);

namespace Engelking\Webtrees\PortalApi\Services;

use Engelking\Webtrees\PortalApi\PortalApiModule;
use Fisharebest\Webtrees\Contracts\UserInterface;
use Fisharebest\Webtrees\DB;
use Fisharebest\Webtrees\Individual;
use Fisharebest\Webtrees\Registry;
use Fisharebest\Webtrees\Tree;

use function array_key_exists;
use function date;
use function in_array;
use function mb_substr;
use function trim;

/**
 * What a member shares, and with whom.
 *
 * Three rules, and the whole class is them.
 *
 * **Nothing here comes from the family tree.** See Schema/Migration3.php.
 *
 * **Every entry carries its own audience.** "My email may go to the whole
 * family" and "my address is for my brother" are different decisions.
 *
 * **"My contacts" is an audience the member built themselves.** Close family
 * is decided by the tree and the whole membership by the directory; the
 * people a member connected with are neither, and every one of them agreed
 * to it. When the family switches connections off, that audience shares
 * nothing — see `Connections::disclosableUserIds()`.
 *
 * **The narrowest answer is the default, everywhere.** An unknown audience, a
 * missing row, a viewer with no linked record, a subject with no linked
 * record — every one of them resolves to "not shared" rather than to an
 * assumption.
 */
class ContactDetails
{
    public const string TABLE = 'portal_contact_detail';

    public const string AUDIENCE_NOBODY       = 'nobody';
    public const string AUDIENCE_CLOSE_FAMILY = 'close_family';
    public const string AUDIENCE_CONNECTIONS  = 'connections';
    public const string AUDIENCE_MEMBERS      = 'members';

    /** The kinds a member may share. Closed, and decided here rather than by the client. */
    public const array KINDS = ['email', 'phone', 'address'];

    private const array AUDIENCES = [
        self::AUDIENCE_NOBODY,
        self::AUDIENCE_CLOSE_FAMILY,
        self::AUDIENCE_CONNECTIONS,
        self::AUDIENCE_MEMBERS,
    ];

    private const int MAX_VALUE_LENGTH = 255;

    public function __construct(
        private readonly PortalApiModule $module,
        private readonly CloseFamily $close_family,
        private readonly Connections $connections,
    ) {
    }

    /**
     * Whether the family shares contact details through the portal at all.
     *
     * Checked where the disclosure happens rather than where the data is
     * written: switching this off must silence entries that already exist,
     * and a member must still be able to see and clear their own.
     */
    public function enabled(): bool
    {
        return $this->module->getPreference(PortalApiModule::SETTING_MEMBER_CONTACT, '1') === '1';
    }

    /**
     * A member's own entries, whatever their audience.
     *
     * Only ever for the member themselves — this is the settings screen's
     * view, and it deliberately ignores the audience, because you can always
     * see what you have chosen to share.
     *
     * @return array<string,array{value:string,audience:string}>
     */
    public function forMember(UserInterface $user): array
    {
        $entries = [];

        foreach (DB::table(self::TABLE)->where('wt_user_id', '=', $user->id())->get() as $row) {
            if (in_array($row->kind, self::KINDS, true)) {
                $entries[$row->kind] = [
                    'value'    => (string) $row->value,
                    'audience' => $this->audience((string) $row->audience),
                ];
            }
        }

        return $entries;
    }

    /**
     * Replace the member's entries with what they just submitted.
     *
     * An empty value deletes the row. That is the point rather than a
     * shortcut: clearing the field and withdrawing consent should be the same
     * act, because a member who deletes their telephone number has plainly
     * finished sharing it, and leaving a hidden copy behind would be a way of
     * not listening.
     *
     * @param array<string,array{value?:mixed,audience?:mixed}> $changes
     *
     * @return array<string,array{value:string,audience:string}>
     */
    public function update(UserInterface $user, array $changes): array
    {
        $now = date('Y-m-d H:i:s');

        foreach (self::KINDS as $kind) {
            if (!array_key_exists($kind, $changes)) {
                continue;
            }

            $submitted = $changes[$kind];
            $value     = mb_substr(trim((string) ($submitted['value'] ?? '')), 0, self::MAX_VALUE_LENGTH);
            $audience  = $this->audience((string) ($submitted['audience'] ?? self::AUDIENCE_NOBODY));

            // Nothing to share, or nobody to share it with. Either way the
            // row goes, so there is nothing left to leak later.
            if ($value === '' || $audience === self::AUDIENCE_NOBODY) {
                DB::table(self::TABLE)
                    ->where('wt_user_id', '=', $user->id())
                    ->where('kind', '=', $kind)
                    ->delete();

                continue;
            }

            DB::table(self::TABLE)->updateOrInsert(
                ['wt_user_id' => $user->id(), 'kind' => $kind],
                ['value' => $value, 'audience' => $audience, 'updated_at' => $now, 'created_at' => $now]
            );
        }

        return $this->forMember($user);
    }

    /**
     * The entries `$viewer` may see of `$subject`.
     *
     * Deliberately one member at a time. Deciding "close family" means
     * walking the tree, and doing that once per row of the directory would
     * turn a list into a page-load nobody waits for — so the directory
     * carries no contact details at all and this is only ever asked on the
     * screen for one person. `ContactTest::testTheDirectoryListCarriesNoContactDetails`
     * pins that.
     *
     * @return array<string,string> kind => value, for the kinds this viewer may see.
     */
    public function visibleTo(
        UserInterface $subject,
        UserInterface $viewer,
        Tree $tree,
        int $access_level,
        int $steps
    ): array {
        if (!$this->enabled()) {
            return [];
        }

        $entries = $this->forMember($subject);

        if ($entries === []) {
            return [];
        }

        // Both computed once, and only if some entry actually needs the
        // answer: one walks the tree and the other reads a table.
        $close     = null;
        $connected = null;

        $visible = [];

        foreach ($entries as $kind => $entry) {
            if ($entry['audience'] === self::AUDIENCE_MEMBERS) {
                $visible[$kind] = $entry['value'];

                continue;
            }

            if ($entry['audience'] === self::AUDIENCE_CONNECTIONS) {
                $connected ??= in_array($subject->id(), $this->connections->disclosableUserIds($viewer), true);

                if ($connected) {
                    $visible[$kind] = $entry['value'];
                }

                continue;
            }

            if ($entry['audience'] !== self::AUDIENCE_CLOSE_FAMILY) {
                continue;
            }

            $close ??= $this->isCloseFamily($subject, $viewer, $tree, $access_level, $steps);

            if ($close) {
                $visible[$kind] = $entry['value'];
            }
        }

        return $visible;
    }

    /**
     * Is the subject within `$steps` of the viewer?
     *
     * Measured from the *viewer*, at the *viewer's* access level, which is the
     * same walk and the same rule as everywhere else in this module. Either
     * side missing a linked record answers no: the portal has no way to place
     * an unlinked account in the family, and "I could not work it out" must
     * not resolve to "share it".
     */
    private function isCloseFamily(
        UserInterface $subject,
        UserInterface $viewer,
        Tree $tree,
        int $access_level,
        int $steps
    ): bool {
        $viewer_record  = $this->linked($tree, $viewer);
        $subject_record = $this->linked($tree, $subject);

        if (!$viewer_record instanceof Individual || !$subject_record instanceof Individual) {
            return false;
        }

        if ($viewer_record->xref() === $subject_record->xref()) {
            return true;
        }

        return array_key_exists(
            $subject_record->xref(),
            $this->close_family->within($viewer_record, $access_level, $steps)
        );
    }

    private function linked(Tree $tree, UserInterface $user): Individual|null
    {
        $xref = $tree->getUserPreference($user, UserInterface::PREF_TREE_ACCOUNT_XREF);

        return $xref === '' ? null : Registry::individualFactory()->make($xref, $tree);
    }

    /** Anything unrecognised becomes the narrowest audience there is. */
    private function audience(string $audience): string
    {
        return in_array($audience, self::AUDIENCES, true) ? $audience : self::AUDIENCE_NOBODY;
    }
}
