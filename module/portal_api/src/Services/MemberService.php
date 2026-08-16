<?php

declare(strict_types=1);

namespace Engelking\Webtrees\PortalApi\Services;

use Fisharebest\Webtrees\Contracts\UserInterface;
use Fisharebest\Webtrees\DB;
use Fisharebest\Webtrees\Services\UserService;
use Fisharebest\Webtrees\User;
use Illuminate\Support\Collection;

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
