<?php

declare(strict_types=1);

namespace Engelking\Webtrees\PortalApi;

use Engelking\Webtrees\PortalApi\Http\Middleware\ApiEnvelope;
use Engelking\Webtrees\PortalApi\Http\Middleware\RequireAuthentication;
use Engelking\Webtrees\PortalApi\Http\Middleware\RequireCsrfToken;
use Engelking\Webtrees\PortalApi\Http\Middleware\RequireProxySecret;
use Engelking\Webtrees\PortalApi\Http\Middleware\UsePortalLanguage;
use Engelking\Webtrees\PortalApi\Http\RequestHandlers\AncestorsRead;
use Engelking\Webtrees\PortalApi\Http\RequestHandlers\CsrfTokenRead;
use Engelking\Webtrees\PortalApi\Http\RequestHandlers\IndividualRead;
use Engelking\Webtrees\PortalApi\Http\RequestHandlers\IndividualUpdate;
use Engelking\Webtrees\PortalApi\Http\RequestHandlers\InvitationAccept;
use Engelking\Webtrees\PortalApi\Http\RequestHandlers\InvitationRead;
use Engelking\Webtrees\PortalApi\Http\RequestHandlers\MeRead;
use Engelking\Webtrees\PortalApi\Http\RequestHandlers\MediaRead;
use Engelking\Webtrees\PortalApi\Http\RequestHandlers\MemberList;
use Engelking\Webtrees\PortalApi\Http\RequestHandlers\MemberRead;
use Engelking\Webtrees\PortalApi\Http\RequestHandlers\PasswordRequestCreate;
use Engelking\Webtrees\PortalApi\Http\RequestHandlers\PasswordResetCreate;
use Engelking\Webtrees\PortalApi\Http\RequestHandlers\ProfileUpdate;
use Engelking\Webtrees\PortalApi\Http\RequestHandlers\SessionCreate;
use Engelking\Webtrees\PortalApi\Http\RequestHandlers\SessionDelete;
use Engelking\Webtrees\PortalApi\Services\AncestorTree;
use Engelking\Webtrees\PortalApi\Services\GedcomEditor;
use Engelking\Webtrees\PortalApi\Services\InvitationService;
use Engelking\Webtrees\PortalApi\Services\LoginRateLimiter;
use Engelking\Webtrees\PortalApi\Services\MeAssembler;
use Engelking\Webtrees\PortalApi\Services\MemberService;
use Engelking\Webtrees\PortalApi\Services\PendingChanges;
use Engelking\Webtrees\PortalApi\Services\PhotoPresenter;
use Engelking\Webtrees\PortalApi\Services\PortalTreeService;
use Engelking\Webtrees\PortalApi\Services\RecordPresenter;
use Engelking\Webtrees\PortalApi\Services\RelationshipNamer;
use Fisharebest\Webtrees\Auth;
use Fisharebest\Webtrees\FlashMessages;
use Fisharebest\Webtrees\Http\ViewResponseTrait;
use Fisharebest\Webtrees\I18N;
use Fisharebest\Webtrees\Module\AbstractModule;
use Fisharebest\Webtrees\Module\ModuleConfigInterface;
use Fisharebest\Webtrees\Module\ModuleConfigTrait;
use Fisharebest\Webtrees\Module\ModuleCustomInterface;
use Fisharebest\Webtrees\Module\ModuleCustomTrait;
use Fisharebest\Webtrees\Individual;
use Fisharebest\Webtrees\Registry;
use Fisharebest\Webtrees\Services\EmailService;
use Fisharebest\Webtrees\Services\MigrationService;
use Fisharebest\Webtrees\Services\ModuleService;
use Fisharebest\Webtrees\Services\RateLimitService;
use Fisharebest\Webtrees\Services\RelationshipService;
use Fisharebest\Webtrees\Services\TreeService;
use Fisharebest\Webtrees\Services\UserService;
use Fisharebest\Webtrees\Session;
use Fisharebest\Webtrees\Validator;
use Fisharebest\Webtrees\View;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Throwable;

use function error_log;
use function is_string;
use function max;
use function min;
use function rawurlencode;
use function rtrim;
use function trim;

/**
 * The JSON API for the member portal.
 *
 * Everything the portal needs lives here. webtrees core is not modified, and
 * webtrees' own /login page is left alone so that editors and administrators
 * keep a way in when the portal is broken.
 *
 * Members can propose changes to their own record. Those never write to the
 * tree: they go through webtrees' pending changes queue, so an editor approves
 * them exactly as they would any other edit.
 */
class PortalApiModule extends AbstractModule implements ModuleCustomInterface, ModuleConfigInterface
{
    use ModuleCustomTrait;
    use ModuleConfigTrait;
    use ViewResponseTrait;

    public const string CUSTOM_VERSION = '1.0.0';

    /** Bumped when src/Schema/MigrationN.php classes are added. */
    private const int SCHEMA_VERSION = 2;

    private const string SCHEMA_SETTING_NAME = 'PORTAL_API_SCHEMA_VERSION';

    /** Module settings. */
    public const string SETTING_TREE                = 'portal_tree';
    public const string SETTING_PORTAL_URL          = 'portal_url';
    public const string SETTING_PROXY_SECRET        = 'proxy_secret';
    public const string SETTING_RATE_LIMIT_IP       = 'rate_limit_ip';
    public const string SETTING_RATE_LIMIT_USER     = 'rate_limit_user';
    public const string SETTING_RATE_LIMIT_WINDOW   = 'rate_limit_window';
    public const string SETTING_INVITATION_DAYS     = 'invitation_days';

    public const int DEFAULT_RATE_LIMIT_IP     = 30;
    public const int DEFAULT_RATE_LIMIT_USER   = 5;
    public const int DEFAULT_RATE_LIMIT_WINDOW = 900;

    /** The API is mounted here. The portal's Cloudflare Worker proxies /api/* onto it. */
    private const string ROUTE_PREFIX = '/api/v1';

    public function title(): string
    {
        return I18N::translate('Member portal API');
    }

    public function description(): string
    {
        return I18N::translate('A JSON API that serves the member portal. Members can view the family tree and propose changes to their own record; those changes go to the pending changes list for approval.');
    }

    public function customModuleAuthorName(): string
    {
        return 'Engelking';
    }

    public function customModuleVersion(): string
    {
        return self::CUSTOM_VERSION;
    }

    public function resourcesFolder(): string
    {
        return __DIR__ . '/../resources/';
    }

    /**
     * Runs on every request, before routing. Create our tables if they are
     * missing, then register the API routes.
     */
    public function boot(): void
    {
        // Every custom module's boot() runs inside the same PHP request as
        // webtrees itself, on *every* page of the site. An exception thrown
        // here does not break the portal API — it breaks the family's
        // genealogy site, for everyone, including the administrator who would
        // have to go and fix it. The portal is the newer and less important of
        // the two, so it is the one that fails.
        //
        // The real error goes to the server's log, where an administrator can
        // find it. What a member sees is an API that is not there, which the
        // portal already knows how to say.
        try {
            View::registerNamespace($this->name(), $this->resourcesFolder() . 'views/');

            Registry::container()
                ->get(MigrationService::class)
                ->updateSchema('\\' . __NAMESPACE__ . '\\Schema', self::SCHEMA_SETTING_NAME, self::SCHEMA_VERSION);

            $this->registerServices();
            $this->registerRoutes();
        } catch (Throwable $exception) {
            error_log(
                'portal_api: the module could not start, so its API is not available. webtrees itself is unaffected. '
                . $exception::class . ': ' . $exception->getMessage()
                . ' in ' . $exception->getFile() . ':' . $exception->getLine()
            );
        }
    }

    /**
     * Our handlers and middleware need the module instance, to read module
     * settings. webtrees' container auto-wires constructor arguments by type,
     * and it has no way to supply that, so we build them here.
     */
    private function registerServices(): void
    {
        $container = Registry::container();

        $tree_service   = $container->get(TreeService::class);
        $user_service   = $container->get(UserService::class);
        $portal_trees   = new PortalTreeService($this, $tree_service);
        $pending        = new PendingChanges();
        $relationships  = new RelationshipNamer($container->get(RelationshipService::class));
        $photos         = new PhotoPresenter();
        $presenter      = new RecordPresenter($pending, $relationships, $photos);
        $ancestors      = new AncestorTree($presenter);
        $members        = new MemberService($user_service);
        $rate_limiter   = new LoginRateLimiter($this);
        $gedcom_editor  = new GedcomEditor($pending);
        $invitations    = new InvitationService();
        $me             = new MeAssembler($portal_trees, $presenter, $members);

        $container->set(PortalTreeService::class, $portal_trees);
        $container->set(RecordPresenter::class, $presenter);
        $container->set(RelationshipNamer::class, $relationships);
        $container->set(PhotoPresenter::class, $photos);
        $container->set(AncestorTree::class, $ancestors);
        $container->set(MemberService::class, $members);
        $container->set(LoginRateLimiter::class, $rate_limiter);
        $container->set(MeAssembler::class, $me);
        $container->set(PendingChanges::class, $pending);
        $container->set(GedcomEditor::class, $gedcom_editor);
        $container->set(InvitationService::class, $invitations);

        $container->set(ApiEnvelope::class, new ApiEnvelope());
        $container->set(UsePortalLanguage::class, new UsePortalLanguage($container->get(ModuleService::class)));
        $container->set(RequireProxySecret::class, new RequireProxySecret($this));
        $container->set(RequireCsrfToken::class, new RequireCsrfToken());
        $container->set(RequireAuthentication::class, new RequireAuthentication());

        $container->set(CsrfTokenRead::class, new CsrfTokenRead());
        $container->set(SessionCreate::class, new SessionCreate($user_service, $rate_limiter, $me));
        $container->set(SessionDelete::class, new SessionDelete());
        $container->set(MeRead::class, new MeRead($me));
        $container->set(IndividualRead::class, new IndividualRead($portal_trees, $presenter));
        $container->set(AncestorsRead::class, new AncestorsRead($portal_trees, $ancestors));
        $container->set(MediaRead::class, new MediaRead($portal_trees, $photos));
        $container->set(MemberList::class, new MemberList($portal_trees, $presenter, $members));
        $container->set(MemberRead::class, new MemberRead($portal_trees, $presenter, $members));

        $container->set(ProfileUpdate::class, new ProfileUpdate($members));
        $container->set(IndividualUpdate::class, new IndividualUpdate($portal_trees, $presenter, $gedcom_editor, $pending));
        $container->set(PasswordRequestCreate::class, new PasswordRequestCreate(
            $this,
            $user_service,
            $container->get(EmailService::class),
            $container->get(RateLimitService::class),
        ));
        $container->set(PasswordResetCreate::class, new PasswordResetCreate($user_service, $me));

        // Phase 5 — invitations. Unauthenticated by necessity: the whole
        // point is that the person holding the link does not have an account
        // yet.
        $container->set(InvitationRead::class, new InvitationRead($portal_trees, $invitations, $rate_limiter));
        $container->set(InvitationAccept::class, new InvitationAccept($portal_trees, $invitations, $user_service, $rate_limiter, $me));
    }

    private function registerRoutes(): void
    {
        $map = Registry::routeFactory()->routeMap();

        // Applied to every route below. Order matters: the envelope is
        // outermost, so that it can turn anything thrown further in into a
        // JSON error and stamp the no-store header onto every response. The
        // language comes next, so that even the refusals further in — a bad
        // proxy secret, a stale CSRF token — are worded in the member's own
        // language.
        $public = [
            ApiEnvelope::class,
            UsePortalLanguage::class,
            RequireProxySecret::class,
        ];

        $unsafe = [
            ApiEnvelope::class,
            UsePortalLanguage::class,
            RequireProxySecret::class,
            RequireCsrfToken::class,
        ];

        $private = [
            ApiEnvelope::class,
            UsePortalLanguage::class,
            RequireProxySecret::class,
            RequireAuthentication::class,
        ];

        $map->get(CsrfTokenRead::class, self::ROUTE_PREFIX . '/csrf', CsrfTokenRead::class)
            ->extras(['middleware' => $public]);

        $map->post(SessionCreate::class, self::ROUTE_PREFIX . '/session', SessionCreate::class)
            ->extras(['middleware' => $unsafe]);

        $map->delete(SessionDelete::class, self::ROUTE_PREFIX . '/session', SessionDelete::class)
            ->extras(['middleware' => $unsafe]);

        $map->get(MeRead::class, self::ROUTE_PREFIX . '/me', MeRead::class)
            ->extras(['middleware' => $private]);

        $map->get(IndividualRead::class, self::ROUTE_PREFIX . '/individuals/{xref}', IndividualRead::class)
            ->tokens(['xref' => '[A-Za-z0-9_.\-]{1,20}'])
            ->extras(['middleware' => $private]);

        $map->get(AncestorsRead::class, self::ROUTE_PREFIX . '/individuals/{xref}/ancestors', AncestorsRead::class)
            ->tokens(['xref' => '[A-Za-z0-9_.\-]{1,20}'])
            ->extras(['middleware' => $private]);

        // Two routes, one handler: the `size` token is what tells them apart.
        $map->get(MediaRead::class, self::ROUTE_PREFIX . '/media/{xref}/{fact}/{size}', MediaRead::class)
            ->tokens([
                'xref' => '[A-Za-z0-9_.\-]{1,20}',
                'fact' => '[0-9a-f]{32}',
                'size' => 'thumbnail|image',
            ])
            ->extras(['middleware' => $private]);

        $map->get(MemberList::class, self::ROUTE_PREFIX . '/members', MemberList::class)
            ->extras(['middleware' => $private]);

        $map->get(MemberRead::class, self::ROUTE_PREFIX . '/members/{id}', MemberRead::class)
            ->tokens(['id' => '\d+'])
            ->extras(['middleware' => $private]);

        // Phase 2 — writes. Authenticated *and* CSRF-checked: these are the
        // routes the CSRF machinery was built for in Phase 1.
        $unsafe_private = [
            ApiEnvelope::class,
            UsePortalLanguage::class,
            RequireProxySecret::class,
            RequireCsrfToken::class,
            RequireAuthentication::class,
        ];

        $map->patch(ProfileUpdate::class, self::ROUTE_PREFIX . '/me/profile', ProfileUpdate::class)
            ->extras(['middleware' => $unsafe_private]);

        $map->put(IndividualUpdate::class, self::ROUTE_PREFIX . '/me/individual', IndividualUpdate::class)
            ->extras(['middleware' => $unsafe_private]);

        // Unauthenticated by necessity — someone who cannot sign in is the
        // whole point — but still behind CSRF and the proxy secret.
        $map->post(PasswordRequestCreate::class, self::ROUTE_PREFIX . '/password/request', PasswordRequestCreate::class)
            ->extras(['middleware' => $unsafe]);

        $map->post(PasswordResetCreate::class, self::ROUTE_PREFIX . '/password/reset', PasswordResetCreate::class)
            ->extras(['middleware' => $unsafe]);

        // Both are POST, including the one that only reads: the token must
        // travel in the body rather than in a path that a webserver log, a
        // proxy or a `Referer` header would keep. See InvitationRead.
        $map->post(InvitationRead::class, self::ROUTE_PREFIX . '/invitation/preview', InvitationRead::class)
            ->extras(['middleware' => $unsafe]);

        $map->post(InvitationAccept::class, self::ROUTE_PREFIX . '/invitation/accept', InvitationAccept::class)
            ->extras(['middleware' => $unsafe]);
    }

    /** The administrator's settings page. */
    public function getAdminAction(ServerRequestInterface $request): ResponseInterface
    {
        $this->layout = 'layouts/administration';

        $tree_service = Registry::container()->get(TreeService::class);

        return $this->viewResponse($this->name() . '::settings', [
            'title'             => $this->title(),
            'module'            => $this,
            'trees'             => $tree_service->all(),
            'tree'              => $this->getPreference(self::SETTING_TREE),
            'portal_url'        => $this->getPreference(self::SETTING_PORTAL_URL),
            'proxy_secret'      => $this->getPreference(self::SETTING_PROXY_SECRET),
            'rate_limit_ip'     => $this->getPreference(self::SETTING_RATE_LIMIT_IP, (string) self::DEFAULT_RATE_LIMIT_IP),
            'rate_limit_user'   => $this->getPreference(self::SETTING_RATE_LIMIT_USER, (string) self::DEFAULT_RATE_LIMIT_USER),
            'rate_limit_window' => $this->getPreference(self::SETTING_RATE_LIMIT_WINDOW, (string) self::DEFAULT_RATE_LIMIT_WINDOW),
            'invitation_days'   => $this->getPreference(self::SETTING_INVITATION_DAYS, (string) InvitationService::DEFAULT_VALIDITY_DAYS),
            'invitations_url'   => $this->invitationsUrl(),
        ]);
    }

    public function postAdminAction(ServerRequestInterface $request): ResponseInterface
    {
        $body = Validator::parsedBody($request);

        $this->setPreference(self::SETTING_TREE, $body->string(self::SETTING_TREE, ''));
        $this->setPreference(self::SETTING_PORTAL_URL, rtrim(trim($body->string(self::SETTING_PORTAL_URL, '')), '/'));
        $this->setPreference(self::SETTING_PROXY_SECRET, trim($body->string(self::SETTING_PROXY_SECRET, '')));
        $this->setPreference(self::SETTING_RATE_LIMIT_IP, (string) max(0, $body->integer(self::SETTING_RATE_LIMIT_IP, self::DEFAULT_RATE_LIMIT_IP)));
        $this->setPreference(self::SETTING_RATE_LIMIT_USER, (string) max(0, $body->integer(self::SETTING_RATE_LIMIT_USER, self::DEFAULT_RATE_LIMIT_USER)));
        $this->setPreference(self::SETTING_RATE_LIMIT_WINDOW, (string) max(60, $body->integer(self::SETTING_RATE_LIMIT_WINDOW, self::DEFAULT_RATE_LIMIT_WINDOW)));
        $this->setPreference(self::SETTING_INVITATION_DAYS, (string) $this->validityDays($body->integer(self::SETTING_INVITATION_DAYS, InvitationService::DEFAULT_VALIDITY_DAYS)));

        FlashMessages::addMessage(I18N::translate('The preferences for the module “%s” have been updated.', $this->title()), 'success');

        return redirect($this->getConfigLink());
    }

    // -----------------------------------------------------------------
    // Invitations (administrators only)
    //
    // webtrees' own ModuleAction handler refuses any action whose name
    // contains "Admin" to anyone who is not an administrator, before the
    // method is called. That is the access check for everything below; there
    // is no second one, and the names must keep the word.
    // -----------------------------------------------------------------

    /** The screen where invitations are issued, listed and withdrawn. */
    public function getAdminInvitationsAction(ServerRequestInterface $request): ResponseInterface
    {
        $this->layout = 'layouts/administration';

        $container   = Registry::container();
        $invitations = $container->get(InvitationService::class);
        $members     = $container->get(MemberService::class);
        $tree        = $container->get(PortalTreeService::class)->tree();

        // Shown once and then forgotten. It travels through the session
        // rather than through the redirect, because a token in a URL is a
        // token in the webserver's access log.
        $new_link = Session::get('portal_api_new_invitation', '');
        Session::forget('portal_api_new_invitation');

        return $this->viewResponse($this->name() . '::invitations', [
            'title'       => I18N::translate('Invitations'),
            'module'      => $this,
            'tree'        => $tree,
            'invitations' => $invitations->outstanding($tree),
            'unlinked'    => $members->accountsWithoutRecord($tree),
            'valid_days'  => (int) $this->getPreference(self::SETTING_INVITATION_DAYS, (string) InvitationService::DEFAULT_VALIDITY_DAYS),
            'new_link'    => is_string($new_link) ? $new_link : '',
            'portal_url'  => $this->getPreference(self::SETTING_PORTAL_URL, ''),
            'settings_url' => $this->getConfigLink(),
        ]);
    }

    /** Issue or withdraw one invitation, then redirect back to the screen. */
    public function postAdminInvitationsAction(ServerRequestInterface $request): ResponseInterface
    {
        $body = Validator::parsedBody($request);

        if ($body->string('invitation_action', '') === 'revoke') {
            $this->revokeInvitation($body->integer('invitation_id', 0));
        } else {
            $this->issueInvitation($body->string('xref', ''), $body->string('email', ''));
        }

        return redirect($this->invitationsUrl());
    }

    private function issueInvitation(string $xref, string $email): void
    {
        $container   = Registry::container();
        $invitations = $container->get(InvitationService::class);
        $tree        = $container->get(PortalTreeService::class)->tree();

        // The select sends "@X123@" when the tree uses the "at" form, and
        // "X123" otherwise. Either way the XREF is what is between them.
        $xref       = trim($xref, '@ ');
        $individual = $xref === '' ? null : Registry::individualFactory()->make($xref, $tree);

        if ($xref !== '' && !$individual instanceof Individual) {
            FlashMessages::addMessage(I18N::translate('This individual does not exist.'), 'danger');

            return;
        }

        if ($this->getPreference(self::SETTING_PORTAL_URL, '') === '') {
            FlashMessages::addMessage(I18N::translate('The portal address is not set, so an invitation link cannot be built. Set it in the module preferences first.'), 'danger');

            return;
        }

        $name = $individual instanceof Individual
            ? $container->get(RecordPresenter::class)->plainName($individual)
            : '';

        $token = $invitations->create(
            $tree,
            $individual?->xref() ?? '',
            $name,
            $email,
            Auth::user(),
            $this->validityDays((int) $this->getPreference(self::SETTING_INVITATION_DAYS, (string) InvitationService::DEFAULT_VALIDITY_DAYS))
        );

        // Old invitations are cleared out here rather than by a scheduled
        // job: this is the one page where somebody is thinking about
        // invitations anyway, and there is nothing else to hang a cron on.
        $invitations->prune();

        Session::put('portal_api_new_invitation', $this->invitationLink($token));
    }

    private function revokeInvitation(int $id): void
    {
        if ($id <= 0) {
            return;
        }

        $container = Registry::container();
        $container->get(InvitationService::class)->revoke($id, $container->get(PortalTreeService::class)->tree());

        FlashMessages::addMessage(I18N::translate('The invitation has been withdrawn.'), 'success');
    }

    /** Where the invited person goes. The portal, never webtrees. */
    private function invitationLink(string $token): string
    {
        $base = rtrim($this->getPreference(self::SETTING_PORTAL_URL, ''), '/');

        return $base . '/invitation?token=' . rawurlencode($token);
    }

    private function invitationsUrl(): string
    {
        return route('module', ['module' => $this->name(), 'action' => 'AdminInvitations']);
    }

    private function validityDays(int $days): int
    {
        return max(1, min(InvitationService::MAX_VALIDITY_DAYS, $days));
    }
}
