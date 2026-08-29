<?php

declare(strict_types=1);

namespace Engelking\Webtrees\PortalApi\Tests;

use Engelking\Webtrees\PortalApi\Http\RequestHandlers\InvitationAccept;
use Engelking\Webtrees\PortalApi\Http\RequestHandlers\InvitationRead;
use Engelking\Webtrees\PortalApi\Http\RequestHandlers\SessionCreate;
use Engelking\Webtrees\PortalApi\Services\InvitationService;
use Engelking\Webtrees\PortalApi\Services\MemberService;
use Engelking\Webtrees\PortalApi\Services\SackNumbers;
use Fig\Http\Message\RequestMethodInterface;
use Fig\Http\Message\StatusCodeInterface;
use Fisharebest\Webtrees\Auth;
use Fisharebest\Webtrees\Contracts\UserInterface;
use Fisharebest\Webtrees\DB;
use Fisharebest\Webtrees\Registry;
use Fisharebest\Webtrees\Services\TreeService;
use Fisharebest\Webtrees\Services\UserService;
use Fisharebest\Webtrees\User;
use PHPUnit\Framework\Attributes\CoversNothing;
use Psr\Http\Message\ResponseInterface;

use function hash;
use function str_repeat;
use function time;

/**
 * Phase 5: how a member gets an account in the first place.
 *
 * The thing these tests are really about is that an invitation is a
 * *credential*. It has all the properties a password has — it is enough on
 * its own to become somebody, it travels through email, and it is written
 * down in more places than anyone intends — and so it is treated like one:
 * never stored, usable once, and dead after a fixed time.
 */
#[CoversNothing]
class InvitationTest extends PortalTestCase
{
    private InvitationService $invitations;

    protected function setUp(): void
    {
        parent::setUp();

        $this->invitations = new InvitationService();
    }

    // -----------------------------------------------------------------
    // Helpers
    // -----------------------------------------------------------------

    private function invite(string $xref = 'X1', string $name = 'Anna Beispiel', string $email = 'anna@example.test'): string
    {
        return $this->invitations->create($this->tree, $xref, $name, $email, null);
    }

    private function preview(string $token): ResponseInterface
    {
        return $this->api(
            InvitationRead::class,
            RequestMethodInterface::METHOD_POST,
            body: ['token' => $token],
            headers: $this->csrfHeader(),
        );
    }

    /**
     * @param array<string,mixed> $overrides
     */
    private function accept(string $token, array $overrides = []): ResponseInterface
    {
        return $this->api(
            InvitationAccept::class,
            RequestMethodInterface::METHOD_POST,
            body: $overrides + [
                'token'     => $token,
                'username'  => 'anna',
                'real_name' => 'Anna Beispiel',
                'email'     => 'anna@example.test',
                'password'  => 'correct-horse',
            ],
            headers: $this->csrfHeader(),
        );
    }

    private function createdUser(string $username): User
    {
        $user = Registry::container()->get(UserService::class)->findByUserName($username);

        self::assertInstanceOf(User::class, $user, 'No account named "' . $username . '" was created.');

        return $user;
    }

    private function preference(User $user, string $name): string
    {
        return (string) DB::table('user_setting')
            ->where('user_id', '=', $user->id())
            ->where('setting_name', '=', $name)
            ->value('setting_value');
    }

    // -----------------------------------------------------------------
    // The token is a credential
    // -----------------------------------------------------------------

    /**
     * The single most important assertion in this file. A database dump is
     * read by backup software, by hosting support and by whoever inherits the
     * server; a stored token is a working account for every one of them.
     */
    public function testTheTokenItselfIsNeverStored(): void
    {
        $token = $this->invite();

        $row = DB::table(InvitationService::TABLE)->first();

        self::assertNotNull($row);
        self::assertSame(hash('sha256', $token), $row->token_hash);

        foreach ((array) $row as $column => $value) {
            self::assertNotSame($token, (string) $value, 'The raw token is stored in column ' . $column . '.');
        }
    }

    public function testAnInvitationCanBeRedeemedOnlyOnce(): void
    {
        $token = $this->invite();

        self::assertSame(StatusCodeInterface::STATUS_CREATED, $this->accept($token)->getStatusCode());

        $second = $this->accept($token, ['username' => 'anna2', 'email' => 'anna2@example.test']);

        self::assertSame(StatusCodeInterface::STATUS_BAD_REQUEST, $second->getStatusCode());
        self::assertSame('invalid_token', $this->json($second)['error']);
        self::assertNull(
            Registry::container()->get(UserService::class)->findByUserName('anna2'),
            'A spent invitation created a second account.'
        );
    }

    public function testAnExpiredInvitationIsRefused(): void
    {
        $token = $this->invite();

        DB::table(InvitationService::TABLE)->update(['expires_at' => time() - 1]);

        self::assertSame(StatusCodeInterface::STATUS_BAD_REQUEST, $this->preview($token)->getStatusCode());
        self::assertSame(StatusCodeInterface::STATUS_BAD_REQUEST, $this->accept($token)->getStatusCode());
    }

    public function testAWithdrawnInvitationIsRefused(): void
    {
        $token = $this->invite();
        $id    = (int) DB::table(InvitationService::TABLE)->value('id');

        $this->invitations->revoke($id, $this->tree);

        self::assertSame(StatusCodeInterface::STATUS_BAD_REQUEST, $this->accept($token)->getStatusCode());
    }

    /**
     * Unknown, expired and spent are one answer. Not for the reason the login
     * endpoint has — a token names nobody — but because there is one thing
     * the reader can do about any of them.
     */
    public function testEveryUnusableTokenLooksTheSame(): void
    {
        $expired = $this->invite();
        DB::table(InvitationService::TABLE)->update(['expires_at' => time() - 1]);

        $unknown = $this->raw($this->preview(str_repeat('a', 64)));
        $stale   = $this->raw($this->preview($expired));
        $rubbish = $this->raw($this->preview('not-a-token'));

        self::assertSame($unknown, $stale);
        self::assertSame($unknown, $rubbish);
    }

    // -----------------------------------------------------------------
    // The invitee is a visitor
    // -----------------------------------------------------------------

    /**
     * The whole point of an invitation is that its holder has no account, so
     * nothing on this path may ask what they are allowed to see *before* it
     * reads the token.
     *
     * It did. `PortalTreeService::tree()` resolves the portal's tree through
     * `TreeService::all()`, which webtrees filters by whoever is asking: on a
     * tree with `REQUIRE_AUTHENTICATION` a signed-out reader's list is empty,
     * the configured tree looks deleted, and both endpoints refused with 503
     * before the token was looked at. Every invitation on a private family
     * tree — which is every invitation this portal is for — arrived at the
     * invitee as "this invitation is no longer valid".
     *
     * The fixture leaves the setting off, which is why the tests above never
     * saw it. This one turns it on, because that is how the portal is run.
     */
    public function testAnInvitationOpensOnATreeThatRequiresAuthentication(): void
    {
        $this->requireAuthentication();

        $token   = $this->invite();
        $preview = $this->preview($token);

        self::assertSame(StatusCodeInterface::STATUS_OK, $preview->getStatusCode());
        self::assertSame('Anna Beispiel', $this->json($preview)['invited_name']);
        self::assertSame('Portal test tree', $this->json($preview)['tree']['title']);
    }

    /**
     * And accepting it works too — including the part after the account
     * exists, which reads the tree again as the member it has just made.
     */
    public function testAnInvitationIsAcceptedOnATreeThatRequiresAuthentication(): void
    {
        $this->requireAuthentication();

        $response = $this->accept($this->invite());
        $body     = $this->json($response);

        self::assertSame(StatusCodeInterface::STATUS_CREATED, $response->getStatusCode());
        self::assertSame('anna', $body['user']['username']);
        self::assertSame('member', $body['user']['role']);
        self::assertSame('Portal test tree', $body['tree']['title']);
        self::assertNotNull($body['individual'], 'The new member cannot read their own record.');
    }

    /**
     * What the fix must not do: hand the tree to somebody without a usable
     * invitation. The tree is resolved before the token is read, so the
     * refusal has to come from the token and nothing else.
     */
    public function testATreeThatRequiresAuthenticationStillRefusesAnUnknownToken(): void
    {
        $this->requireAuthentication();

        $response = $this->preview(str_repeat('a', 64));

        self::assertSame(StatusCodeInterface::STATUS_BAD_REQUEST, $response->getStatusCode());
        self::assertSame('invalid_token', $this->json($response)['error']);
    }

    // -----------------------------------------------------------------
    // What the preview discloses
    // -----------------------------------------------------------------

    public function testThePreviewNamesThePersonAndTheTree(): void
    {
        $response = $this->preview($this->invite());
        $body     = $this->json($response);

        self::assertSame(StatusCodeInterface::STATUS_OK, $response->getStatusCode());
        self::assertSame('Anna Beispiel', $body['invited_name']);
        self::assertSame('anna@example.test', $body['email']);
        self::assertSame('Portal test tree', $body['tree']['title']);
    }

    /**
     * The reader is not signed in. The name is the whole point — it is how
     * they recognise that the invitation is theirs — but nothing that would
     * let them ask the API for a record has any business being here.
     */
    public function testThePreviewDisclosesNoRecord(): void
    {
        $body = $this->raw($this->preview($this->invite()));

        self::assertStringNotContainsString('X1', $body);
        self::assertStringNotContainsString('xref', $body);
        self::assertStringNotContainsString('Tischlerin', $body);
    }

    /**
     * The name comes from the snapshot on the invitation, not from a lookup,
     * so nothing on this path reads the family tree for somebody who has not
     * signed in.
     */
    public function testThePreviewDoesNotReadTheTree(): void
    {
        $token = $this->invitations->create($this->tree, 'X1', 'Anna B. (as invited)', '', null);

        self::assertSame('Anna B. (as invited)', $this->json($this->preview($token))['invited_name']);
    }

    // -----------------------------------------------------------------
    // The account that comes out
    // -----------------------------------------------------------------

    public function testAcceptingCreatesAnAccountThatCanSignInImmediately(): void
    {
        $response = $this->accept($this->invite());

        self::assertSame(StatusCodeInterface::STATUS_CREATED, $response->getStatusCode());

        $user = $this->createdUser('anna');

        // Verified and approved: an administrator picked this person by hand.
        self::assertSame('1', $this->preference($user, UserInterface::PREF_IS_EMAIL_VERIFIED));
        self::assertSame('1', $this->preference($user, UserInterface::PREF_IS_ACCOUNT_APPROVED));

        Auth::logout();

        $login = $this->api(
            SessionCreate::class,
            RequestMethodInterface::METHOD_POST,
            body: ['username' => 'anna', 'password' => 'correct-horse'],
            headers: $this->csrfHeader(),
        );

        self::assertSame(StatusCodeInterface::STATUS_OK, $login->getStatusCode());
    }

    public function testTheNewAccountIsLinkedToTheInvitedIndividual(): void
    {
        $body = $this->json($this->accept($this->invite()));

        self::assertSame('X1', $body['individual']['xref']);
        self::assertSame('Anna Beispiel', $body['individual']['name']);
        self::assertSame('member', $body['user']['role']);
    }

    /**
     * The three preferences that decide how much damage the account can do.
     * `auto_accept` matters most: an account that accepted its own edits
     * would walk straight around the pending-changes queue that the whole of
     * Phase 2 is built on.
     */
    public function testTheNewAccountHasNoPrivilegeItWasNotGiven(): void
    {
        $this->accept($this->invite());

        $user = $this->createdUser('anna');

        self::assertNotSame('1', $this->preference($user, UserInterface::PREF_IS_ADMINISTRATOR));
        self::assertNotSame('1', $this->preference($user, UserInterface::PREF_AUTO_ACCEPT_EDITS));
        self::assertFalse(Auth::isAdmin($user));
        self::assertFalse(Auth::isEditor($this->tree, $user));
        self::assertTrue(Auth::isMember($this->tree, $user));
    }

    /**
     * A re-import renumbers the tree, and an invitation issued before it
     * names an XREF that has moved. Refusing would lock out the person it was
     * sent to over something they did not do; the account is created without
     * a link, and the administrator's list of unlinked accounts shows it.
     */
    public function testAnInvitationForAVanishedRecordStillCreatesTheAccount(): void
    {
        $token = $this->invitations->create($this->tree, 'X999', 'Wer Auchimmer', '', null);

        $response = $this->accept($token, ['username' => 'wer', 'email' => 'wer@example.test']);
        $body     = $this->json($response);

        self::assertSame(StatusCodeInterface::STATUS_CREATED, $response->getStatusCode());
        self::assertNull($body['individual']);
        self::assertSame('wer', $body['user']['username']);
    }

    // -----------------------------------------------------------------
    // Refusals that must not cost the invitee their invitation
    // -----------------------------------------------------------------

    public function testATakenUsernameIsRefusedWithoutBurningTheInvitation(): void
    {
        $this->createUser('taken', 'Schon Da', 'irgendwas', UserInterface::ROLE_MEMBER);

        $token    = $this->invite();
        $response = $this->accept($token, ['username' => 'taken']);

        self::assertSame(StatusCodeInterface::STATUS_CONFLICT, $response->getStatusCode());
        self::assertSame('username_taken', $this->json($response)['error']);

        // The same invitation still works with a different name.
        self::assertSame(StatusCodeInterface::STATUS_CREATED, $this->accept($token)->getStatusCode());
    }

    public function testATakenEmailAddressIsRefusedWithoutBurningTheInvitation(): void
    {
        $this->createUser('other', 'Schon Da', 'irgendwas', UserInterface::ROLE_MEMBER);

        $token    = $this->invite();
        $response = $this->accept($token, ['username' => 'neu', 'email' => 'other@example.test']);

        self::assertSame(StatusCodeInterface::STATUS_CONFLICT, $response->getStatusCode());
        self::assertSame('email_taken', $this->json($response)['error']);
        self::assertSame(StatusCodeInterface::STATUS_CREATED, $this->accept($token)->getStatusCode());
    }

    public function testAShortPasswordIsRefusedWithoutBurningTheInvitation(): void
    {
        $token = $this->invite();

        self::assertSame(
            StatusCodeInterface::STATUS_BAD_REQUEST,
            $this->accept($token, ['password' => 'kurz'])->getStatusCode()
        );

        self::assertSame(StatusCodeInterface::STATUS_CREATED, $this->accept($token)->getStatusCode());
    }

    /**
     * webtrees signs people in with `findByIdentifier()`, which matches a
     * username *or* an email address in one query. A username shaped like an
     * address could therefore stand in front of somebody else's account at
     * the login form.
     */
    public function testAUsernameMayNotLookLikeAnEmailAddress(): void
    {
        $response = $this->accept($this->invite(), ['username' => 'someone@example.test']);

        self::assertSame(StatusCodeInterface::STATUS_BAD_REQUEST, $response->getStatusCode());
        self::assertNull(Registry::container()->get(UserService::class)->findByUserName('someone@example.test'));
    }

    public function testAUsernameMayNotContainSpaces(): void
    {
        self::assertSame(
            StatusCodeInterface::STATUS_BAD_REQUEST,
            $this->accept($this->invite(), ['username' => 'anna beispiel'])->getStatusCode()
        );
    }

    // -----------------------------------------------------------------
    // The administrator's side
    // -----------------------------------------------------------------

    public function testOutstandingInvitationsExcludeSpentAndExpiredOnes(): void
    {
        $live  = $this->invite('X1', 'Anna Beispiel');
        $spent = $this->invitations->create($this->tree, 'X2', 'Bertha Beispiel', '', null);

        $this->accept($spent, ['username' => 'bertha', 'email' => 'bertha@example.test']);

        $outstanding = $this->invitations->outstanding($this->tree);

        self::assertCount(1, $outstanding);
        self::assertSame('Anna Beispiel', $outstanding->first()->invited_name);

        // ...and the live one is still the live one.
        self::assertSame(StatusCodeInterface::STATUS_OK, $this->preview($live)->getStatusCode());
    }

    public function testAccountsWithoutALinkedRecordAreListed(): void
    {
        $this->createUser('linked', 'Mit Datensatz', 'irgendwas', UserInterface::ROLE_MEMBER, 'X1');
        $this->createUser('lonely', 'Ohne Datensatz', 'irgendwas', UserInterface::ROLE_MEMBER);

        $members  = Registry::container()->get(MemberService::class);
        $unlinked = $members->accountsWithoutRecord($this->tree)->map(static fn (User $user): string => $user->userName());

        self::assertContains('lonely', $unlinked->all());
        self::assertNotContains('linked', $unlinked->all());
    }

    /**
     * Spent and long-expired invitations are cleared out, but a fresh one is
     * never swept up with them.
     */
    public function testPruningKeepsLiveInvitations(): void
    {
        $live = $this->invite();
        $this->invitations->create($this->tree, 'X2', 'Bertha Beispiel', '', null);

        DB::table(InvitationService::TABLE)
            ->where('xref', '=', 'X2')
            ->update(['expires_at' => time() - 365 * 86400]);

        $this->invitations->prune();

        self::assertSame(1, DB::table(InvitationService::TABLE)->count());
        self::assertSame(StatusCodeInterface::STATUS_OK, $this->preview($live)->getStatusCode());
    }

    /**
     * The administrator's screen is a `.phtml` template, which nothing else
     * compiles: a renamed variable or a helper that does not exist there
     * shows up as a blank control panel page and a line in the server log,
     * never as a failing build. Rendering it here is the only thing that
     * would say so.
     */
    public function testTheAdministratorsScreenRenders(): void
    {
        $this->invite();
        $this->createUser('lonely', 'Ohne Datensatz', 'irgendwas', UserInterface::ROLE_MEMBER);

        $html = view('_portal_api_::invitations', [
            'title'        => 'Invitations',
            'module'       => $this->module(),
            'tree'         => $this->tree,
            'invitations'  => $this->invitations->outstanding($this->tree),
            'unlinked'     => Registry::container()->get(MemberService::class)->accountsWithoutRecord($this->tree),
            'issuers'      => [],
            'valid_days'   => InvitationService::DEFAULT_VALIDITY_DAYS,
            'new_link'     => 'https://portal.example.test/invitation?token=abc',
            'portal_url'   => 'https://portal.example.test',
            'settings_url' => '/settings',
        ]);

        self::assertStringContainsString('Anna Beispiel', $html);
        self::assertStringContainsString('lonely', $html);
        self::assertStringContainsString('https://portal.example.test/invitation?token=abc', $html);
    }

    /**
     * The same, for the preferences page, which keeps growing variables.
     * Rendering the template with what the action actually passes is the only
     * thing that would notice one of them being dropped.
     */
    public function testThePreferencesScreenRenders(): void
    {
        $module = $this->module();

        $html = view('_portal_api_::settings', [
            'title'             => $module->title(),
            'module'            => $module,
            'trees'             => Registry::container()->get(TreeService::class)->all(),
            'tree'              => $this->tree->name(),
            'portal_url'        => 'https://portal.example.test',
            'proxy_secret'      => '',
            'rate_limit_ip'     => '30',
            'rate_limit_user'   => '5',
            'rate_limit_window' => '900',
            'invitation_days'   => '14',
            'invitations_url'   => '/invitations',
            'campaigns_url'     => '/campaigns',
            'requests_url'      => '/access-requests',
            'requests_open'     => 2,
            'diagnosis_url'     => '/diagnosis',
            'accounts_url'      => '/accounts',
            'offices_url'       => '/offices',
            'member_invites'      => '1',
            'member_invite_steps' => '2',
            'member_invite_quota' => '3',
            'member_path_length'  => '2',
            'member_show_number'  => '0',
            'member_contact'      => '1',
            'member_messages'     => '1',
            'message_limit'       => '20',
            'push'                => '1',
            'member_connections'  => '1',
            'connection_code_minutes' => '15',
            'remember_days'       => '30',
            'mcp'                     => '1',
            'mcp_notes'               => '1',
            'mcp_photos'              => '0',
            'mcp_url'                 => 'https://portal.example.test/api/mcp',
            'mcp_tokens_url'          => '/mcp',
            'mailing_lists'           => '1',
            'mailing_list_addresses'  => 'familie@example.de | Familiennachrichten',
            'exchange_tenant'         => 'example.onmicrosoft.com',
            'exchange_client_id'      => '00000000-0000-0000-0000-000000000000',
            'exchange_client_secret'  => '',
            'exchange_hide_contacts'  => '1',
            'sack_lines'              => '',
            'sack_marriages'          => '',
            'sack_branches'           => '',
            'sack_lines_default'      => SackNumbers::DEFAULT_LINES,
            'sack_marriages_default'  => SackNumbers::DEFAULT_MARRIAGES,
            'sack_branches_default'   => SackNumbers::DEFAULT_BRANCHES,
        ]);

        self::assertStringContainsString('invitation_days', $html);

        // Asserted rather than left to the render succeeding: an undefined
        // variable in a `.phtml` is a warning and an empty string, so the
        // page would still render — just without the link.
        self::assertStringContainsString('/invitations', $html);
        self::assertStringContainsString('/diagnosis', $html);
        self::assertStringContainsString('/accounts', $html);
        self::assertStringContainsString('/offices', $html);
        self::assertStringContainsString('member_invite_steps', $html);
        self::assertStringContainsString('member_path_length', $html);
        self::assertStringContainsString('member_show_number', $html);
        self::assertStringContainsString('message_limit', $html);
        self::assertStringContainsString('push_notifications', $html);
        self::assertStringContainsString('remember_days', $html);
        self::assertStringContainsString('mcp_server', $html);
        self::assertStringContainsString('mcp_notes', $html);
        self::assertStringContainsString('mcp_photos', $html);
        self::assertStringContainsString('https://portal.example.test/api/mcp', $html);
        self::assertStringContainsString('/mcp', $html);

        // The three tables the family maintains, pre-filled with what is
        // shipped: an empty box would look like "we have no lines".
        self::assertStringContainsString(SackNumbers::SETTING_LINES, $html);
        self::assertStringContainsString('24 = 7d3', $html);
        self::assertStringContainsString(SackNumbers::SETTING_MARRIAGES, $html);
        self::assertStringContainsString(SackNumbers::SETTING_BRANCHES, $html);
        self::assertStringContainsString('Zweig Rothenhof', $html);
    }
}
