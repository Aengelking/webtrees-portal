<?php

declare(strict_types=1);

namespace Engelking\Webtrees\PortalApi\Services;

use Fisharebest\Webtrees\Auth;
use Fisharebest\Webtrees\Contracts\UserInterface;
use Fisharebest\Webtrees\I18N;
use Fisharebest\Webtrees\Services\UserService;
use Fisharebest\Webtrees\Tree;
use Fisharebest\Webtrees\User;
use Illuminate\Support\Collection;

use function in_array;
use function mb_strtolower;

/**
 * Who has an account, what they are on the tree, and whether the portal will
 * actually let them in.
 *
 * This screen exists because of a real afternoon. A member could not sign in,
 * the portal answered "not configured", and the cause was none of the things
 * that phrase suggests: the account's role on the family tree was still
 * `visitor`, which is webtrees' default for an account created by hand in the
 * control panel. A tree with `REQUIRE_AUTHENTICATION` hides itself from a
 * visitor, `TreeService::all()` came back empty, and the tree looked deleted
 * to code that had every reason to believe it.
 *
 * Nothing about that was visible anywhere. webtrees' own user list shows the
 * role, but not what the *portal* makes of it; the diagnosis screen checks the
 * installation, not the people. So: one table, every account, and the one
 * column that could not be looked up before — will this person get in.
 *
 * **It reports; it does not repair.** Every row links to webtrees' own user
 * editor, which is where a role is changed and where the audit trail for that
 * belongs. A screen in this module that quietly rewrote roles would be a
 * second place where account permissions are decided, and one is enough.
 */
class AccountOverview
{
    public function __construct(
        private readonly UserService $user_service,
        private readonly PortalTreeService $trees,
    ) {
    }

    /**
     * Every account, worst news first.
     *
     * Sorted so that the accounts somebody has to do something about are at
     * the top: this is a screen people open when something is wrong, and a
     * list that buries the one broken row among forty working ones has
     * answered the wrong question. Alphabetical within each group, so it is
     * still a list you can find a name in.
     *
     * @return Collection<int,PortalAccount>
     */
    public function all(Tree $tree): Collection
    {
        // Through `PortalTreeService`, not `getPreference()`: webtrees 2.2.6
        // moved this into a column, and reading it as a preference works there
        // only through a deprecation shim.
        $requires_authentication = $this->trees->requiresAuthentication($tree);

        return $this->user_service->all()
            ->map(fn (User $user): PortalAccount => $this->describe($user, $tree, $requires_authentication))
            // One key rather than two, because `sortBy()` with an array of
            // bare closures does not sort by the second of them — it groups
            // correctly and then leaves the names in whatever order they
            // arrived, which is exactly the sort of "nearly right" a test had
            // to catch.
            ->sortBy(static fn (PortalAccount $account): string =>
                ($account->canUsePortal() ? '1' : '0') . mb_strtolower($account->user->realName()))
            ->values();
    }

    /** How many accounts the portal would turn away as they stand. */
    public function blocked(Collection $accounts): int
    {
        return $accounts->reject(static fn (PortalAccount $a): bool => $a->canUsePortal())->count();
    }

    private function describe(User $user, Tree $tree, bool $requires_authentication): PortalAccount
    {
        $role     = $tree->getUserPreference($user, UserInterface::PREF_TREE_ROLE);
        $xref     = $tree->getUserPreference($user, UserInterface::PREF_TREE_ACCOUNT_XREF);
        $is_admin = Auth::isAdmin($user);

        return new PortalAccount(
            user: $user,
            role: $role,
            role_name: self::roleName($role),
            xref: $xref === '' ? null : $xref,
            is_administrator: $is_admin,
            is_approved: $user->getPreference(UserInterface::PREF_IS_ACCOUNT_APPROVED) === '1',
            is_verified: $user->getPreference(UserInterface::PREF_IS_EMAIL_VERIFIED) === '1',
            blocked: $this->blockedReason($user, $role, $is_admin, $requires_authentication),
        );
    }

    /**
     * The first thing that would stop this account, in the order the portal
     * meets them.
     *
     * `SessionCreate` checks the two account flags before the password is even
     * compared, and answers all of its failures with the same 401 — so an
     * administrator cannot tell "not approved" from "wrong password" by trying
     * it, which is the point of that endpoint and the reason this column has
     * to exist somewhere else.
     *
     * The tree role comes last because it fails later: the sign-in succeeds,
     * and then `MeAssembler` asks for the tree and cannot have it.
     */
    private function blockedReason(
        User $user,
        string $role,
        bool $is_administrator,
        bool $requires_authentication,
    ): string|null {
        if ($user->getPreference(UserInterface::PREF_IS_ACCOUNT_APPROVED) !== '1') {
            return I18N::translate('The account is waiting for an administrator to approve it.');
        }

        if ($user->getPreference(UserInterface::PREF_IS_EMAIL_VERIFIED) !== '1') {
            return I18N::translate('The email address has not been verified.');
        }

        // An administrator sees every tree whatever their role on it, so the
        // rest of this cannot apply to them.
        if ($is_administrator) {
            return null;
        }

        // Mirrors the query in webtrees' `TreeService::all()`, which is what
        // actually decides this: a manager always, anybody at all on a tree
        // that does not require authentication, and on a tree that does,
        // anybody whose role is something other than visitor. An account that
        // was never given a role reads as '' here and is refused for the same
        // reason a visitor is — in the query it is a NULL that fails the
        // comparison.
        //
        // `imported` is the query's other condition and is not repeated here:
        // a tree that was never imported serves nobody at all, which is the
        // diagnosis screen's business rather than one account's.
        if ($role === UserInterface::ROLE_MANAGER || !$requires_authentication) {
            return null;
        }

        if (in_array($role, ['', UserInterface::ROLE_VISITOR], true)) {
            return I18N::translate('This family tree requires authentication, and the account’s role on it is “Visitor”. Sign-in succeeds and then fails: give the account the role “Member”.');
        }

        return null;
    }

    /**
     * webtrees' own words for a role, so that this screen and the user editor
     * an administrator is about to open say the same thing.
     */
    private static function roleName(string $role): string
    {
        return match ($role) {
            UserInterface::ROLE_MEMBER    => I18N::translate('Member'),
            UserInterface::ROLE_EDITOR    => I18N::translate('Editor'),
            UserInterface::ROLE_MODERATOR => I18N::translate('Moderator'),
            UserInterface::ROLE_MANAGER   => I18N::translate('Manager'),
            UserInterface::ROLE_VISITOR   => I18N::translate('Visitor'),
            // Never set. Deliberately not shown as "Visitor": it behaves the
            // same, and an administrator looking for why should see that the
            // account has no role rather than one that was chosen.
            default                       => I18N::translate('No role'),
        };
    }
}
