<?php

declare(strict_types=1);

namespace Engelking\Webtrees\PortalApi\Services;

use Fisharebest\Webtrees\Contracts\UserInterface;
use Fisharebest\Webtrees\DB;
use Fisharebest\Webtrees\Services\UserService;
use Fisharebest\Webtrees\User;
use Illuminate\Support\Collection;
use RuntimeException;

use function array_key_exists;
use function date;
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
