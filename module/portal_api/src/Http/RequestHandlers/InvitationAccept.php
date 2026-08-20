<?php

declare(strict_types=1);

namespace Engelking\Webtrees\PortalApi\Http\RequestHandlers;

use Engelking\Webtrees\PortalApi\Http\ApiException;
use Engelking\Webtrees\PortalApi\Http\Json;
use Engelking\Webtrees\PortalApi\PortalApiModule;
use Engelking\Webtrees\PortalApi\Services\Invitation;
use Engelking\Webtrees\PortalApi\Services\InvitationService;
use Engelking\Webtrees\PortalApi\Services\LoginRateLimiter;
use Engelking\Webtrees\PortalApi\Services\MeAssembler;
use Engelking\Webtrees\PortalApi\Services\PortalTreeService;
use Fig\Http\Message\StatusCodeInterface;
use Fisharebest\Webtrees\Auth;
use Fisharebest\Webtrees\Contracts\UserInterface;
use Fisharebest\Webtrees\I18N;
use Fisharebest\Webtrees\Individual;
use Fisharebest\Webtrees\Log;
use Fisharebest\Webtrees\Registry;
use Fisharebest\Webtrees\Services\MessageService;
use Fisharebest\Webtrees\Services\UserService;
use Fisharebest\Webtrees\Site;
use Fisharebest\Webtrees\Tree;
use Fisharebest\Webtrees\User;
use Fisharebest\Webtrees\Validator;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Throwable;

use function error_log;
use function filter_var;
use function mb_strlen;
use function preg_match;
use function str_contains;
use function time;
use function trim;

use const FILTER_VALIDATE_EMAIL;

/**
 * POST /api/v1/invitation/accept — turn an invitation into an account.
 *
 * The account is a plain webtrees user account, created through webtrees'
 * own `UserService`. There is no second user store (§4 of the handoff), and
 * nothing here is portal-specific except that the account arrives already
 * verified and already approved: an administrator picked this person out of
 * the family tree by hand and sent them a link, which is a stronger check
 * than the email round-trip and the approval queue exist to provide.
 *
 * The order of operations matters and is not the obvious one:
 *
 *  1. everything that can be checked without changing anything is checked;
 *  2. the invitation is claimed, atomically, so a second request loses;
 *  3. the account is created;
 *  4. if step 3 fails, the claim is released, because burning an invitation
 *     over a failure that produced no account would lock out the very person
 *     it was sent to.
 */
class InvitationAccept implements RequestHandlerInterface
{
    /** Matches webtrees' own minimum, so a password set here also works there. */
    private const int MINIMUM_PASSWORD_LENGTH = 8;

    /** The widths of `user.user_name`, `user.real_name` and `user.email`. */
    private const int MAX_USERNAME_LENGTH  = 32;
    private const int MAX_REAL_NAME_LENGTH = 64;
    private const int MAX_EMAIL_LENGTH     = 64;

    private const int MIN_USERNAME_LENGTH = 3;

    public function __construct(
        private readonly PortalApiModule $module,
        private readonly PortalTreeService $trees,
        private readonly InvitationService $invitations,
        private readonly UserService $user_service,
        private readonly LoginRateLimiter $rate_limiter,
        private readonly MeAssembler $me,
    ) {
    }

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        $body  = Json::body($request);
        $token = Json::requiredString($body, 'token');
        $ip    = Validator::attributes($request)->string('client-ip', '');
        $tree  = $this->trees->tree();

        if (!$this->rate_limiter->allows($ip, InvitationService::limiterKey($token))) {
            Log::addAuthenticationLog('Portal invitation acceptance rate-limited from ' . $ip);

            throw ApiException::invalidInvitation();
        }

        $invitation = $this->invitations->findUsable($token, $tree);

        if (!$invitation instanceof Invitation) {
            $this->rate_limiter->recordFailure($ip, InvitationService::limiterKey($token));

            throw ApiException::invalidInvitation();
        }

        $username  = $this->username($body);
        $real_name = $this->realName($body, $invitation);
        $email     = $this->email($body);
        $password  = $this->password($body);

        // Checked before the invitation is claimed, so that a name somebody
        // else already has costs the invitee nothing but a second attempt.
        $this->refuseDuplicates($username, $email);

        if (!$this->invitations->claim($invitation)) {
            throw ApiException::invalidInvitation();
        }

        try {
            $user = $this->createAccount($tree, $invitation, $username, $real_name, $email, $password);
        } catch (Throwable $exception) {
            $this->invitations->release($invitation);

            error_log('portal_api: could not create an account from an invitation: ' . $exception->getMessage());

            throw $exception;
        }

        $this->invitations->recordRedeemer($invitation, $user);

        Auth::login($user);
        Log::addAuthenticationLog('Portal invitation accepted: ' . $user->userName() . '/' . $user->realName());

        $user->setPreference(UserInterface::PREF_TIMESTAMP_ACTIVE, (string) time());

        return Json::response($this->me->assemble($user), StatusCodeInterface::STATUS_CREATED);
    }

    /**
     * Build the account, exactly as webtrees' own registration does, with two
     * deliberate differences: it is verified and approved on arrival, and it
     * is linked to the individual the invitation named.
     */
    private function createAccount(
        Tree $tree,
        Invitation $invitation,
        string $username,
        string $real_name,
        string $email,
        #[\SensitiveParameter] string $password
    ): User {
        $user = $this->user_service->create($username, $real_name, $email, $password);

        $user->setPreference(UserInterface::PREF_LANGUAGE, I18N::languageTag());
        $user->setPreference(UserInterface::PREF_TIME_ZONE, Site::getPreference('TIMEZONE'));
        $user->setPreference(UserInterface::PREF_TIMESTAMP_REGISTERED, (string) time());
        $user->setPreference(UserInterface::PREF_CONTACT_METHOD, MessageService::CONTACT_METHOD_INTERNAL_AND_EMAIL);
        $user->setPreference(UserInterface::PREF_IS_VISIBLE_ONLINE, '1');
        $user->setPreference(UserInterface::PREF_TIMESTAMP_ACTIVE, '0');

        // An invitation is an administrator vouching for this person by name.
        // The verification email and the approval queue both exist to answer
        // "is this a real person we meant to let in", and that is already
        // answered — so asking again would only be a step to get stuck on.
        $user->setPreference(UserInterface::PREF_IS_EMAIL_VERIFIED, '1');
        $user->setPreference(UserInterface::PREF_IS_ACCOUNT_APPROVED, '1');

        // Spelled out rather than left to default. These are the three
        // preferences that decide how much damage the account can do, and an
        // empty string is what webtrees reads as "no". A member's edits go to
        // the pending-changes queue, and that is the whole design of Phase 2;
        // an account that could accept its own edits would walk around it.
        $user->setPreference(UserInterface::PREF_IS_ADMINISTRATOR, '');
        $user->setPreference(UserInterface::PREF_AUTO_ACCEPT_EDITS, '');
        $user->setPreference(UserInterface::PREF_NEW_ACCOUNT_COMMENT, '');

        // Member, never editor: the portal's write path is "propose a change
        // to your own record", and an editor role would let this account
        // change anybody's, in webtrees, bypassing all of it.
        $tree->setUserPreference($user, UserInterface::PREF_TREE_ROLE, UserInterface::ROLE_MEMBER);

        $this->linkIndividual($tree, $user, $invitation);

        return $user;
    }

    /**
     * Point the account at the individual the invitation was issued for.
     *
     * The XREF is re-resolved rather than trusted: it was written down when
     * the invitation was created and a re-import between then and now will
     * have renumbered the tree. When it no longer resolves, the account is
     * still created — locking somebody out of the portal because the
     * genealogist reloaded the GEDCOM would be a strange thing to do — and
     * the administrator's list of accounts without a record picks it up.
     */
    private function linkIndividual(Tree $tree, User $user, Invitation $invitation): void
    {
        if ($invitation->xref === null) {
            return;
        }

        $individual = Registry::individualFactory()->make($invitation->xref, $tree);

        if (!$individual instanceof Individual) {
            error_log(
                'portal_api: invitation ' . $invitation->id . ' named ' . $invitation->xref
                . ', which no longer exists in the tree "' . $tree->name()
                . '". The account was created without a linked record.'
            );

            return;
        }

        $tree->setUserPreference($user, UserInterface::PREF_TREE_ACCOUNT_XREF, $individual->xref());

        // Set together with the link, and only when there is one, because
        // webtrees measures the limit *from* that record — `UserEditAction`
        // forces the value back to zero for an account without one, and so
        // does this module rather than storing a number that does nothing.
        //
        // Zero means the administrator has chosen not to restrict, which is
        // webtrees' own default and means this member will see every living
        // person in the tree. The diagnosis screen says so.
        $path_length = $this->module->memberPathLength();

        if ($path_length > 0) {
            $tree->setUserPreference($user, UserInterface::PREF_TREE_PATH_LENGTH, (string) $path_length);
        }
    }

    // -----------------------------------------------------------------
    // Validation
    // -----------------------------------------------------------------

    /**
     * @param array<string,mixed> $body
     */
    private function username(array $body): string
    {
        $username = trim(Json::requiredString($body, 'username'));

        if (mb_strlen($username) < self::MIN_USERNAME_LENGTH || mb_strlen($username) > self::MAX_USERNAME_LENGTH) {
            throw ApiException::badRequest(I18N::translate(
                'The username must be between %1$s and %2$s characters long.',
                I18N::number(self::MIN_USERNAME_LENGTH),
                I18N::number(self::MAX_USERNAME_LENGTH)
            ));
        }

        // No "@": webtrees signs people in with `findByIdentifier()`, which
        // matches a username *or* an email address in one query. A username
        // shaped like an address could therefore stand in front of somebody
        // else's account at the login form.
        //
        // No whitespace or control characters either: a name with a trailing
        // space is a name its owner cannot reliably retype.
        if (str_contains($username, '@') || preg_match('/[\s\p{C}]/u', $username) === 1) {
            throw ApiException::badRequest(I18N::translate(
                'A username may not contain spaces or the “@” character.'
            ));
        }

        return $username;
    }

    /**
     * @param array<string,mixed> $body
     */
    private function realName(array $body, Invitation $invitation): string
    {
        $real_name = trim(Json::requiredString($body, 'real_name'));

        if (mb_strlen($real_name) > self::MAX_REAL_NAME_LENGTH) {
            throw ApiException::badRequest(I18N::translate(
                'The name must be no more than %s characters long.',
                I18N::number(self::MAX_REAL_NAME_LENGTH)
            ));
        }

        // Only used if the form somehow sent nothing usable; the portal
        // prefills it with the invited name.
        return $real_name === '' ? (string) $invitation->invited_name : $real_name;
    }

    /**
     * @param array<string,mixed> $body
     */
    private function email(array $body): string
    {
        $email = trim(Json::requiredString($body, 'email'));

        if (mb_strlen($email) > self::MAX_EMAIL_LENGTH || filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
            throw ApiException::badRequest(I18N::translate('This is not a valid email address.'));
        }

        // Note what is *not* checked: the address does not have to match the
        // one the invitation was sent to. It is a contact address, and a
        // member who reads family mail at one address but prefers another for
        // an account is not doing anything wrong. The credential is the token.
        return $email;
    }

    /**
     * @param array<string,mixed> $body
     */
    private function password(array $body): string
    {
        $password = Json::requiredString($body, 'password');

        if (mb_strlen($password) < self::MINIMUM_PASSWORD_LENGTH) {
            throw ApiException::badRequest(I18N::translate(
                'The password must be at least %s characters long.',
                I18N::number(self::MINIMUM_PASSWORD_LENGTH)
            ));
        }

        return $password;
    }

    private function refuseDuplicates(string $username, string $email): void
    {
        if ($this->user_service->findByUserName($username) !== null) {
            throw ApiException::conflict(
                'username_taken',
                I18N::translate('Duplicate username. A user with that username already exists. Please choose another username.')
            );
        }

        if ($this->user_service->findByEmail($email) !== null) {
            throw ApiException::conflict(
                'email_taken',
                I18N::translate('Duplicate email address. A user with that email already exists.')
            );
        }
    }
}
