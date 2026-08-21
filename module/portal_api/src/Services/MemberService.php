<?php

declare(strict_types=1);

namespace Engelking\Webtrees\PortalApi\Services;

use Fisharebest\Webtrees\Auth;
use Fisharebest\Webtrees\Contracts\UserInterface;
use Fisharebest\Webtrees\DB;
use Fisharebest\Webtrees\Services\UserService;
use Fisharebest\Webtrees\Tree;
use Fisharebest\Webtrees\User;
use Illuminate\Support\Collection;
use RuntimeException;

use function array_key_exists;
use function date;
use function in_array;
use function trim;

/**
 * The portal's own member records, in `portal_member_profile`.
 *
 * This table holds only portal-native data: whether the member consented to
 * appear in the directory, and an optional display name. Identity is the
 * webtrees user account, and the link to a genealogy record is webtrees'
 * own per-tree user setting. There is no second user store.
 */
class MemberService
{
    public const string TABLE = 'portal_member_profile';

    public function __construct(private readonly UserService $user_service)
    {
    }

    /**
     * The portal profile for a webtrees user, or null if none exists yet.
     *
     * @return array<string,mixed>|null
     */
    public function profileForUser(UserInterface $user): array|null
    {
        $row = DB::table(self::TABLE)
            ->where('wt_user_id', '=', $user->id())
            ->first();

        return $row === null ? null : $this->profile($row);
    }

    /**
     * One directory-visible member, by portal member id.
     *
     * Members who are not visible in the directory are reported as missing,
     * so that the endpoint cannot be used to confirm an account exists.
     */
    public function visibleMember(int $id): Member|null
    {
        $row = DB::table(self::TABLE)
            ->where('id', '=', $id)
            ->where('visible_in_directory', '=', 1)
            ->first();

        if ($row === null) {
            return null;
        }

        $user = $this->user_service->find((int) $row->wt_user_id);

        return $user instanceof User ? $this->member($row, $user) : null;
    }

    /**
     * One member this viewer is entitled to open, by portal member id.
     *
     * The directory is one way to be entitled: listing oneself is consent to
     * be found by every member. A connection is the other, and a narrower
     * one — it is consent given to one person, by both of them — so a member
     * who stayed out of the directory is still readable by the people they
     * connected with, and by nobody else.
     *
     * @param array<int,int> $connected_user_ids webtrees user ids, from Connections.
     */
    public function readableMember(int $id, array $connected_user_ids): Member|null
    {
        $member = $this->visibleMember($id);

        if ($member instanceof Member) {
            return $member;
        }

        if ($connected_user_ids === []) {
            return null;
        }

        $row = DB::table(self::TABLE)->where('id', '=', $id)->first();

        if ($row === null || !in_array((int) $row->wt_user_id, $connected_user_ids, true)) {
            return null;
        }

        $user = $this->user_service->find((int) $row->wt_user_id);

        return $user instanceof User ? $this->member($row, $user) : null;
    }

    /**
     * Every directory-visible member, unpaged.
     *
     * For the searches that are not the directory's own — looking a member up
     * by the reference number on their record, which has to consider all of
     * them and pages none of them.
     *
     * @return Collection<int,Member>
     */
    public function allVisible(): Collection
    {
        return DB::table(self::TABLE)
            ->where('visible_in_directory', '=', 1)
            ->get()
            ->map(function (object $row): Member|null {
                $user = $this->user_service->find((int) $row->wt_user_id);

                return $user instanceof User ? $this->member($row, $user) : null;
            })
            ->filter()
            ->values();
    }

    /**
     * Every account this portal knows that the tree has a record for.
     *
     * Wider than the directory on purpose, and used for exactly one thing:
     * looking somebody up by the reference number on their record. A member
     * who stayed out of the directory is still *reachable* — see
     * `Connections::requestByReference()`, which answers so that finding them
     * and finding nobody cannot be told apart.
     *
     * @return Collection<int,User>
     */
    public function linkedAccounts(Tree $tree): Collection
    {
        return $this->user_service->all()
            ->filter(static fn (User $user): bool => $tree->getUserPreference($user, UserInterface::PREF_TREE_ACCOUNT_XREF) !== '')
            ->values();
    }

    /**
     * A page of directory-visible members, ordered by display name.
     *
     * Sorting and filtering happen in PHP rather than SQL because the
     * displayed name may come from either the portal table or the webtrees
     * user record, and only one of those is in this query.
     *
     * @return array{items: Collection<int,Member>, total: int}
     */
    public function visibleMembers(string $query, int $page, int $per_page): array
    {
        $rows = DB::table(self::TABLE)
            ->where('visible_in_directory', '=', 1)
            ->get();

        $members = $rows
            ->map(function (object $row): Member|null {
                $user = $this->user_service->find((int) $row->wt_user_id);

                return $user instanceof User ? $this->member($row, $user) : null;
            })
            ->filter()
            ->filter(static fn (Member $member): bool => $member->matches($query))
            ->sortBy(static fn (Member $member): string => mb_strtolower($member->display_name))
            ->values();

        return [
            'items' => $members->slice(($page - 1) * $per_page, $per_page)->values(),
            'total' => $members->count(),
        ];
    }

    /**
     * Create or update the member's own portal profile.
     *
     * `consent_recorded_at` is written when the member becomes visible in the
     * directory, and cleared when they stop being visible. Consent that has
     * been withdrawn should not leave behind a record saying it was given —
     * the column answers "since when is this person listed", and the answer
     * for an unlisted person is "they are not".
     *
     * @param array<string,mixed> $changes
     *
     * @return array<string,mixed>
     */
    public function updateProfile(UserInterface $user, array $changes): array
    {
        $now      = date('Y-m-d H:i:s');
        $existing = DB::table(self::TABLE)->where('wt_user_id', '=', $user->id())->first();

        $update = ['updated_at' => $now];

        if (array_key_exists('visible_in_directory', $changes)) {
            $visible = (bool) $changes['visible_in_directory'];
            $was_visible = $existing !== null && (bool) $existing->visible_in_directory;

            $update['visible_in_directory'] = $visible ? 1 : 0;

            if ($visible !== $was_visible) {
                $update['consent_recorded_at'] = $visible ? $now : null;
            }
        }

        if (array_key_exists('display_name_override', $changes)) {
            $update['display_name_override'] = $changes['display_name_override'];
        }

        if ($existing === null) {
            DB::table(self::TABLE)->insert($update + [
                'wt_user_id' => $user->id(),
                'created_at' => $now,
            ] + ['visible_in_directory' => 0]);
        } else {
            DB::table(self::TABLE)->where('id', '=', $existing->id)->update($update);
        }

        $row = DB::table(self::TABLE)->where('wt_user_id', '=', $user->id())->first();

        if ($row === null) {
            throw new RuntimeException('portal_member_profile row vanished immediately after writing it');
        }

        return $this->profile($row);
    }

    /**
     * Make sure this member has a portal profile row, and return its id.
     *
     * Called when two members connect, for both of them. A connection needs
     * something to point at — the portal member id is what a screen links to
     * — and the row a member gets by having agreed to know somebody says
     * nothing and shows nobody anything: `visible_in_directory` stays off,
     * which is the default and the narrower answer. Only the member
     * themselves can change that, in their own settings.
     */
    public function ensureProfile(UserInterface $user): int
    {
        $row = DB::table(self::TABLE)->where('wt_user_id', '=', $user->id())->first();

        if ($row !== null) {
            return (int) $row->id;
        }

        $now = date('Y-m-d H:i:s');

        DB::table(self::TABLE)->insert([
            'wt_user_id'           => $user->id(),
            'visible_in_directory' => 0,
            'created_at'           => $now,
            'updated_at'           => $now,
        ]);

        $row = DB::table(self::TABLE)->where('wt_user_id', '=', $user->id())->first();

        if ($row === null) {
            throw new RuntimeException('portal_member_profile row vanished immediately after writing it');
        }

        return (int) $row->id;
    }

    /**
     * Accounts that can sign in to the portal but are not linked to anybody
     * in the family tree.
     *
     * Such an account works — it just shows the member nothing about
     * themselves, because `/me` has no record to attach. Before invitations
     * existed this was the normal outcome of creating an account by hand and
     * forgetting the second step, and it is invisible from webtrees' own user
     * list. It is worth an administrator being able to see it in one place.
     *
     * Administrators are excluded: an administrator's account is usually not
     * a member's account, and listing it every time would train the reader to
     * ignore the list.
     *
     * @return Collection<int,User>
     */
    public function accountsWithoutRecord(Tree $tree): Collection
    {
        return $this->user_service->all()
            ->reject(static fn (User $user): bool => Auth::isAdmin($user))
            ->filter(static fn (User $user): bool => $tree->getUserPreference($user, UserInterface::PREF_TREE_ACCOUNT_XREF) === '')
            ->sortBy(static fn (User $user): string => mb_strtolower($user->realName()))
            ->values();
    }

    /**
     * The accounts this module considers "a member" of the portal's tree.
     *
     * Members only — never editors, moderators, managers or administrators.
     * Whatever limits are put on what a member may see, the people who
     * maintain the tree have to be able to see all of it to do that work.
     *
     * @return Collection<int,User>
     */
    public function memberAccounts(Tree $tree): Collection
    {
        return $this->user_service->all()
            ->reject(static fn (User $user): bool => Auth::isAdmin($user))
            ->filter(static fn (User $user): bool => $tree->getUserPreference($user, UserInterface::PREF_TREE_ROLE) === UserInterface::ROLE_MEMBER)
            ->values();
    }

    /**
     * Member accounts that can see every living person in the tree.
     *
     * webtrees applies relationship privacy only when a user has both a
     * linked record and a `RELATIONSHIP_PATH_LENGTH` above zero. Miss either
     * and `Individual::canShowByType()` falls through to its last line —
     * "show living people to members only" — which for a signed-in member
     * means everybody. Neither is set by default, so this list starts out
     * holding every account.
     *
     * @return Collection<int,User>
     */
    public function accountsWithUnlimitedVisibility(Tree $tree): Collection
    {
        return $this->memberAccounts($tree)
            ->filter(static function (User $user) use ($tree): bool {
                $linked = $tree->getUserPreference($user, UserInterface::PREF_TREE_ACCOUNT_XREF) !== '';
                $length = (int) $tree->getUserPreference($user, UserInterface::PREF_TREE_PATH_LENGTH);

                return !$linked || $length <= 0;
            })
            ->values();
    }

    /**
     * Apply a relationship limit to every member account that can carry one.
     *
     * Accounts with no linked record are skipped rather than set: webtrees
     * forces the value back to zero for them anyway (`UserEditAction` does
     * the same), because the limit is measured from the account's own record
     * and there is nothing to measure from. Those accounts appear in the
     * "no linked record" list instead, which is where they can be fixed.
     *
     * @return int How many accounts were changed.
     */
    public function applyPathLength(Tree $tree, int $steps): int
    {
        $changed = 0;

        foreach ($this->memberAccounts($tree) as $user) {
            if ($tree->getUserPreference($user, UserInterface::PREF_TREE_ACCOUNT_XREF) === '') {
                continue;
            }

            if ((int) $tree->getUserPreference($user, UserInterface::PREF_TREE_PATH_LENGTH) === $steps) {
                continue;
            }

            $tree->setUserPreference($user, UserInterface::PREF_TREE_PATH_LENGTH, (string) $steps);
            $changed++;
        }

        return $changed;
    }

    /**
     * @return array<string,mixed>
     */
    private function profile(object $row): array
    {
        return [
            'id'                    => (int) $row->id,
            'visible_in_directory'  => (bool) $row->visible_in_directory,
            'display_name_override' => $this->nullableString($row->display_name_override ?? null),
            'consent_recorded_at'   => $this->nullableString($row->consent_recorded_at ?? null),
        ];
    }

    private function member(object $row, User $user): Member
    {
        $override = $this->nullableString($row->display_name_override ?? null);

        return new Member(
            id: (int) $row->id,
            user: $user,
            display_name: $override ?? $user->realName(),
        );
    }

    private function nullableString(mixed $value): string|null
    {
        if ($value === null) {
            return null;
        }

        $value = trim((string) $value);

        return $value === '' ? null : $value;
    }
}
