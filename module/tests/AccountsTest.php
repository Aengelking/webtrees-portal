<?php

declare(strict_types=1);

namespace Engelking\Webtrees\PortalApi\Tests;

use Engelking\Webtrees\PortalApi\Services\AccountOverview;
use Engelking\Webtrees\PortalApi\Services\PortalAccount;
use Fisharebest\Webtrees\Contracts\UserInterface;
use Fisharebest\Webtrees\Registry;
use Fisharebest\Webtrees\Services\UserService;
use Illuminate\Support\Collection;
use PHPUnit\Framework\Attributes\CoversNothing;

/**
 * The administrator's answer to "why can this person not sign in?".
 *
 * The screen was built after an afternoon spent on exactly that question. A
 * member could not get in, the portal said "not configured", and the cause was
 * the account's role on the family tree still being `visitor` — webtrees'
 * default for an account made by hand in the control panel. Nothing anywhere
 * said so.
 *
 * So the assertions worth having are about the verdict rather than the markup:
 * that the role webtrees actually applies is the one reported, and that an
 * account which will be turned away is named as such before somebody spends an
 * afternoon on it.
 */
#[CoversNothing]
class AccountsTest extends PortalTestCase
{
    private AccountOverview $overview;

    protected function setUp(): void
    {
        parent::setUp();

        $this->overview = Registry::container()->get(AccountOverview::class);
    }

    // -----------------------------------------------------------------
    // The role, as webtrees means it
    // -----------------------------------------------------------------

    public function testEveryRoleIsReportedInWebtreesOwnWords(): void
    {
        $this->createUser('mona', 'Mona Mitglied', 'pw-long-enough-here', UserInterface::ROLE_MEMBER);
        $this->createUser('edda', 'Edda Editor', 'pw-long-enough-here', UserInterface::ROLE_EDITOR);
        $this->createUser('mika', 'Mika Moderator', 'pw-long-enough-here', UserInterface::ROLE_MODERATOR);
        $this->createUser('vera', 'Vera Verwalter', 'pw-long-enough-here', UserInterface::ROLE_MANAGER);
        $this->createUser('bert', 'Bert Besucher', 'pw-long-enough-here', UserInterface::ROLE_VISITOR);

        $roles = $this->byUsername()->map(static fn (PortalAccount $a): string => $a->role_name);

        self::assertSame('Member', $roles['mona']);
        self::assertSame('Editor', $roles['edda']);
        self::assertSame('Moderator', $roles['mika']);
        self::assertSame('Manager', $roles['vera']);
        self::assertSame('Visitor', $roles['bert']);
    }

    /**
     * An account nobody ever gave a role behaves exactly like a visitor — in
     * webtrees' query it is a NULL that fails the comparison — but saying
     * "Visitor" would tell an administrator that somebody chose that. Nobody
     * did.
     */
    public function testAnAccountWithNoRoleAtAllSaysSoRatherThanVisitor(): void
    {
        $user = Registry::container()->get(UserService::class)
            ->create('niko', 'Niko Neu', 'niko@example.test', 'pw-long-enough-here');

        $user->setPreference(UserInterface::PREF_IS_EMAIL_VERIFIED, '1');
        $user->setPreference(UserInterface::PREF_IS_ACCOUNT_APPROVED, '1');

        self::assertSame('No role', $this->byUsername()['niko']->role_name);
    }

    // -----------------------------------------------------------------
    // The verdict, which is the reason the screen exists
    // -----------------------------------------------------------------

    /**
     * The afternoon this was built for: a tree that requires authentication
     * and an account still on `visitor`. The password is right, the sign-in
     * gets past the password, and then the tree cannot be found.
     */
    public function testAVisitorOnATreeThatRequiresAuthenticationIsNamedAsBlocked(): void
    {
        $this->tree->setPreference('REQUIRE_AUTHENTICATION', '1');
        $this->createUser('bert', 'Bert Besucher', 'pw-long-enough-here', UserInterface::ROLE_VISITOR);

        $bert = $this->byUsername()['bert'];

        self::assertFalse($bert->canUsePortal());
        self::assertStringContainsString('Visitor', (string) $bert->blocked);
        self::assertStringContainsString('Member', (string) $bert->blocked, 'The row should say what to do about it.');
    }

    public function testTheSameAccountIsFineWhereTheTreeDoesNotRequireAuthentication(): void
    {
        $this->tree->setPreference('REQUIRE_AUTHENTICATION', '0');
        $this->createUser('bert', 'Bert Besucher', 'pw-long-enough-here', UserInterface::ROLE_VISITOR);

        self::assertTrue($this->byUsername()['bert']->canUsePortal());
    }

    public function testAMemberIsFineEitherWay(): void
    {
        $this->tree->setPreference('REQUIRE_AUTHENTICATION', '1');
        $this->createUser('mona', 'Mona Mitglied', 'pw-long-enough-here', UserInterface::ROLE_MEMBER);

        self::assertTrue($this->byUsername()['mona']->canUsePortal());
    }

    /** An administrator sees every tree whatever their role on it says. */
    public function testAnAdministratorIsNeverBlockedByARole(): void
    {
        $this->tree->setPreference('REQUIRE_AUTHENTICATION', '1');

        $admin = $this->createUser('adam', 'Adam Admin', 'pw-long-enough-here', UserInterface::ROLE_VISITOR);
        $admin->setPreference(UserInterface::PREF_IS_ADMINISTRATOR, '1');

        $account = $this->byUsername()['adam'];

        self::assertTrue($account->is_administrator);
        self::assertTrue($account->canUsePortal());
    }

    /**
     * `SessionCreate` refuses these before the password is compared, and
     * answers every failure with the same 401 — so trying it tells an
     * administrator nothing. This column is the only place the difference is
     * visible.
     */
    public function testAnAccountWaitingForApprovalIsNamedAsBlocked(): void
    {
        $user = $this->createUser('wanda', 'Wanda Wartend', 'pw-long-enough-here', UserInterface::ROLE_MEMBER);
        $user->setPreference(UserInterface::PREF_IS_ACCOUNT_APPROVED, '0');

        $account = $this->byUsername()['wanda'];

        self::assertFalse($account->canUsePortal());
        self::assertFalse($account->is_approved);
        self::assertStringContainsString('approve', (string) $account->blocked);
    }

    public function testAnUnverifiedEmailAddressIsNamedAsBlocked(): void
    {
        $user = $this->createUser('uwe', 'Uwe Unbestätigt', 'pw-long-enough-here', UserInterface::ROLE_MEMBER);
        $user->setPreference(UserInterface::PREF_IS_EMAIL_VERIFIED, '0');

        $account = $this->byUsername()['uwe'];

        self::assertFalse($account->canUsePortal());
        self::assertFalse($account->is_verified);
        self::assertStringContainsString('verified', (string) $account->blocked);
    }

    // -----------------------------------------------------------------
    // The list itself
    // -----------------------------------------------------------------

    /**
     * People open this screen because something is wrong. A list that buries
     * the one broken row among forty working ones has answered a different
     * question.
     */
    public function testTheAccountsThatCannotGetInComeFirst(): void
    {
        $this->tree->setPreference('REQUIRE_AUTHENTICATION', '1');

        $this->createUser('anna', 'Anna Aktiv', 'pw-long-enough-here', UserInterface::ROLE_MEMBER);
        $this->createUser('bert', 'Bert Besucher', 'pw-long-enough-here', UserInterface::ROLE_VISITOR);
        $this->createUser('cara', 'Cara Aktiv', 'pw-long-enough-here', UserInterface::ROLE_MEMBER);

        $accounts = $this->overview->all($this->tree);

        self::assertFalse($accounts->first()->canUsePortal());
        self::assertSame('bert', $accounts->first()->user->userName());
        self::assertSame(1, $this->overview->blocked($accounts));

        // Alphabetical within each group, so it is still a list you can find
        // a name in.
        $working = $accounts->reject(static fn (PortalAccount $a): bool => !$a->canUsePortal())->values();

        self::assertSame('Anna Aktiv', $working[0]->user->realName());
        self::assertSame('Cara Aktiv', $working[1]->user->realName());
    }

    public function testTheLinkedRecordIsReported(): void
    {
        $this->createUser('anna', 'Anna Beispiel', 'pw-long-enough-here', UserInterface::ROLE_MEMBER, 'X1');
        $this->createUser('lore', 'Lore Lose', 'pw-long-enough-here', UserInterface::ROLE_MEMBER);

        $accounts = $this->byUsername();

        self::assertSame('X1', $accounts['anna']->xref);
        self::assertNull($accounts['lore']->xref);
    }

    /**
     * Every account, not only the members: an administrator asking "who has an
     * account" is asking about all of them, and the editors are exactly the
     * people whose role somebody wants to check.
     */
    public function testEditorsAndManagersAreListedToo(): void
    {
        $this->createUser('edda', 'Edda Editor', 'pw-long-enough-here', UserInterface::ROLE_EDITOR);
        $this->createUser('vera', 'Vera Verwalter', 'pw-long-enough-here', UserInterface::ROLE_MANAGER);

        self::assertArrayHasKey('edda', $this->byUsername()->all());
        self::assertArrayHasKey('vera', $this->byUsername()->all());
    }

    /**
     * The screen renders with what the action actually passes, which is the
     * only thing that would notice a variable being dropped — the same guard
     * `InvitationTest` keeps over the preferences page.
     */
    public function testTheScreenRenders(): void
    {
        $this->tree->setPreference('REQUIRE_AUTHENTICATION', '1');
        $this->createUser('bert', 'Bert Besucher', 'pw-long-enough-here', UserInterface::ROLE_VISITOR);
        $this->createUser('anna', 'Anna Beispiel', 'pw-long-enough-here', UserInterface::ROLE_MEMBER, 'X1');

        $accounts = $this->overview->all($this->tree);

        $html = view('_portal_api_::accounts', [
            'title'                   => 'Accounts',
            'module'                  => $this->module(),
            'tree'                    => $this->tree,
            'accounts'                => $accounts,
            'blocked'                 => $this->overview->blocked($accounts),
            'requires_authentication' => true,
            'settings_url'            => '/settings',
        ]);

        self::assertStringContainsString('Bert Besucher', $html);
        self::assertStringContainsString('Anna Beispiel', $html);
        self::assertStringContainsString('Visitor', $html);
        self::assertStringContainsString('Member', $html);

        // The blocked row is marked, not merely present among the others.
        self::assertStringContainsString('table-warning', $html);
    }

    // -----------------------------------------------------------------

    /** @return Collection<string,PortalAccount> */
    private function byUsername(): Collection
    {
        return $this->overview->all($this->tree)
            ->keyBy(static fn (PortalAccount $account): string => $account->user->userName());
    }
}
