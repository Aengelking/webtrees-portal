<?php

declare(strict_types=1);

namespace Engelking\Webtrees\PortalApi;

use Engelking\Webtrees\PortalApi\Http\Middleware\ApiEnvelope;
use Engelking\Webtrees\PortalApi\Http\Middleware\RequireAuthentication;
use Engelking\Webtrees\PortalApi\Http\Middleware\RequireCsrfToken;
use Engelking\Webtrees\PortalApi\Http\Middleware\RequireProxySecret;
use Engelking\Webtrees\PortalApi\Http\Middleware\UsePortalLanguage;
use Engelking\Webtrees\PortalApi\Http\RequestHandlers\AncestorsRead;
use Engelking\Webtrees\PortalApi\Http\RequestHandlers\ConnectionCodeCreate;
use Engelking\Webtrees\PortalApi\Http\RequestHandlers\ConnectionCodeDelete;
use Engelking\Webtrees\PortalApi\Http\RequestHandlers\ConnectionCreate;
use Engelking\Webtrees\PortalApi\Http\RequestHandlers\ConnectionDelete;
use Engelking\Webtrees\PortalApi\Http\RequestHandlers\ConnectionList;
use Engelking\Webtrees\PortalApi\Http\RequestHandlers\ConnectionUpdate;
use Engelking\Webtrees\PortalApi\Http\RequestHandlers\ContactRead;
use Engelking\Webtrees\PortalApi\Http\RequestHandlers\ContactUpdate;
use Engelking\Webtrees\PortalApi\Http\RequestHandlers\CsrfTokenRead;
use Engelking\Webtrees\PortalApi\Http\RequestHandlers\HealthRead;
use Engelking\Webtrees\PortalApi\Http\RequestHandlers\InboxDelete;
use Engelking\Webtrees\PortalApi\Http\RequestHandlers\InboxList;
use Engelking\Webtrees\PortalApi\Http\RequestHandlers\InboxUpdate;
use Engelking\Webtrees\PortalApi\Http\RequestHandlers\IndividualLink;
use Engelking\Webtrees\PortalApi\Http\RequestHandlers\IndividualRead;
use Engelking\Webtrees\PortalApi\Http\RequestHandlers\IndividualUpdate;
use Engelking\Webtrees\PortalApi\Http\RequestHandlers\InvitationAccept;
use Engelking\Webtrees\PortalApi\Http\RequestHandlers\InvitationRead;
use Engelking\Webtrees\PortalApi\Http\RequestHandlers\MemberInvitationCreate;
use Engelking\Webtrees\PortalApi\Http\RequestHandlers\MemberInvitationDelete;
use Engelking\Webtrees\PortalApi\Http\RequestHandlers\MemberInvitationList;
use Engelking\Webtrees\PortalApi\Http\RequestHandlers\MessageCreate;
use Engelking\Webtrees\PortalApi\Http\RequestHandlers\MeRead;
use Engelking\Webtrees\PortalApi\Http\RequestHandlers\MediaRead;
use Engelking\Webtrees\PortalApi\Http\RequestHandlers\MemberList;
use Engelking\Webtrees\PortalApi\Http\RequestHandlers\MemberRead;
use Engelking\Webtrees\PortalApi\Http\RequestHandlers\PasswordRequestCreate;
use Engelking\Webtrees\PortalApi\Http\RequestHandlers\PasswordResetCreate;
use Engelking\Webtrees\PortalApi\Http\RequestHandlers\ProfileUpdate;
use Engelking\Webtrees\PortalApi\Http\RequestHandlers\ReplyCreate;
use Engelking\Webtrees\PortalApi\Http\RequestHandlers\SessionCreate;
use Engelking\Webtrees\PortalApi\Http\RequestHandlers\SessionDelete;
use Engelking\Webtrees\PortalApi\Services\AncestorTree;
use Engelking\Webtrees\PortalApi\Services\CloseFamily;
use Engelking\Webtrees\PortalApi\Services\Connections;
use Engelking\Webtrees\PortalApi\Services\ContactDetails;
use Engelking\Webtrees\PortalApi\Services\Diagnosis;
use Engelking\Webtrees\PortalApi\Services\ErrorLog;
use Engelking\Webtrees\PortalApi\Services\GedcomEditor;
use Engelking\Webtrees\PortalApi\Services\Inbox;
use Engelking\Webtrees\PortalApi\Services\InvitationService;
use Engelking\Webtrees\PortalApi\Services\LoginRateLimiter;
use Engelking\Webtrees\PortalApi\Services\MeAssembler;
use Engelking\Webtrees\PortalApi\Services\MemberInvitations;
use Engelking\Webtrees\PortalApi\Services\MemberMessages;
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
use Fisharebest\Webtrees\Services\MessageService;
use Fisharebest\Webtrees\Services\MigrationService;
use Fisharebest\Webtrees\Services\ModuleService;
use Fisharebest\Webtrees\Services\RateLimitService;
use Fisharebest\Webtrees\Services\RelationshipService;
use Fisharebest\Webtrees\Services\TreeService;
use Fisharebest\Webtrees\Services\UserService;
use Fisharebest\Webtrees\Session;
use Fisharebest\Webtrees\Site;
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

    public const string CUSTOM_VERSION = '1.1.1';

    /** Bumped when src/Schema/MigrationN.php classes are added. */
    private const int SCHEMA_VERSION = 6;

    private const string SCHEMA_SETTING_NAME = 'PORTAL_API_SCHEMA_VERSION';

    /**
     * The schema version this code expects.
     *
     * Read by the health endpoint and the diagnosis screen, so that a
     * deployment can tell "the new files are there" from "the new files are
     * there and their migrations have run" — which are different things, and
     * only the second one means the portal works.
     */
    public function schemaVersion(): int
    {
        return self::SCHEMA_VERSION;
    }

    /** What the database actually has, which may lag the constant above. */
    public function installedSchemaVersion(): int
    {
        return (int) Site::getPreference(self::SCHEMA_SETTING_NAME);
    }

    /** Module settings. */
    public const string SETTING_TREE                = 'portal_tree';
    public const string SETTING_PORTAL_URL          = 'portal_url';
    public const string SETTING_PROXY_SECRET        = 'proxy_secret';
    public const string SETTING_RATE_LIMIT_IP       = 'rate_limit_ip';
    public const string SETTING_RATE_LIMIT_USER     = 'rate_limit_user';
    public const string SETTING_RATE_LIMIT_WINDOW   = 'rate_limit_window';
    public const string SETTING_INVITATION_DAYS     = 'invitation_days';
    public const string SETTING_MEMBER_INVITES      = 'member_invites';
    public const string SETTING_MEMBER_INVITE_STEPS = 'member_invite_steps';
    public const string SETTING_MEMBER_INVITE_QUOTA = 'member_invite_quota';
    public const string SETTING_MEMBER_PATH_LENGTH  = 'member_path_length';
    public const string SETTING_MEMBER_CONTACT      = 'member_contact';
    public const string SETTING_MEMBER_MESSAGES     = 'member_messages';
    public const string SETTING_MESSAGE_LIMIT       = 'message_limit';
    public const string SETTING_MEMBER_CONNECTIONS  = 'member_connections';
    public const string SETTING_CONNECTION_CODE_MINUTES = 'connection_code_minutes';

    /**
     * How far a member may *see*, as opposed to how far they may invite.
     *
     * Zero means "do not restrict", which is webtrees' own default and means
     * a member sees every living person in the tree. Anything above zero is
     * written into webtrees' per-user `RELATIONSHIP_PATH_LENGTH` and counts
     * the same steps this module counts elsewhere.
     */
    public const int MAX_PATH_LENGTH = 4;

    public const int DEFAULT_RATE_LIMIT_IP     = 30;
    public const int DEFAULT_RATE_LIMIT_USER   = 5;
    public const int DEFAULT_RATE_LIMIT_WINDOW = 900;

    /** The API is mounted here. The portal's Cloudflare Worker proxies /api/* onto it. */
    private const string ROUTE_PREFIX = '/api/v1';

    /**
     * Where the browser-facing routes live.
     *
     * Separate from the API prefix on purpose: these are followed by a person
     * rather than by the portal's client, so they carry none of the API
     * middleware — no proxy secret (the browser has none), no JSON envelope,
     * no authentication requirement (being signed out is the case they exist
     * to handle).
     */
    private const string LINK_PREFIX = '/portal';

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
        $errors         = new ErrorLog();
        $close_family   = new CloseFamily($container->get(RelationshipService::class), $user_service);
        $member_invites = new MemberInvitations($this, $portal_trees, $invitations, $close_family, $presenter);
        $connections    = new Connections($this, $portal_trees, $members, $presenter, $user_service);
        $contacts       = new ContactDetails($this, $close_family, $connections);
        $inbox          = new Inbox($user_service);
        $member_msgs    = new MemberMessages($this, $container->get(MessageService::class), $container->get(RateLimitService::class), $members, $inbox, $connections);
        $me             = new MeAssembler($portal_trees, $presenter, $members, $inbox, $connections);

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
        $container->set(ErrorLog::class, $errors);
        $container->set(CloseFamily::class, $close_family);
        $container->set(MemberInvitations::class, $member_invites);
        $container->set(Connections::class, $connections);
        $container->set(ContactDetails::class, $contacts);
        $container->set(Inbox::class, $inbox);
        $container->set(MemberMessages::class, $member_msgs);
        $container->set(Diagnosis::class, new Diagnosis($this, $portal_trees, $members, $errors));

        $container->set(ApiEnvelope::class, new ApiEnvelope($errors));
        $container->set(UsePortalLanguage::class, new UsePortalLanguage($container->get(ModuleService::class)));
        $container->set(RequireProxySecret::class, new RequireProxySecret($this));
        $container->set(RequireCsrfToken::class, new RequireCsrfToken());
        $container->set(RequireAuthentication::class, new RequireAuthentication());

        $container->set(CsrfTokenRead::class, new CsrfTokenRead());
        $container->set(HealthRead::class, new HealthRead($this, $portal_trees));
        $container->set(SessionCreate::class, new SessionCreate($user_service, $rate_limiter, $me));
        $container->set(SessionDelete::class, new SessionDelete());
        $container->set(MeRead::class, new MeRead($me));
        $container->set(IndividualRead::class, new IndividualRead($portal_trees, $presenter));
        $container->set(AncestorsRead::class, new AncestorsRead($portal_trees, $ancestors));
        $container->set(MediaRead::class, new MediaRead($portal_trees, $photos));
        $container->set(MemberList::class, new MemberList($portal_trees, $presenter, $members, $connections));
        $container->set(MemberRead::class, new MemberRead($portal_trees, $presenter, $members, $contacts, $member_msgs, $member_invites, $connections));
        $container->set(ContactRead::class, new ContactRead($contacts, $connections));
        $container->set(ContactUpdate::class, new ContactUpdate($contacts, $connections));
        $container->set(MessageCreate::class, new MessageCreate($member_msgs));
        $container->set(InboxList::class, new InboxList($inbox));
        $container->set(InboxUpdate::class, new InboxUpdate($inbox));
        $container->set(InboxDelete::class, new InboxDelete($inbox));
        $container->set(ReplyCreate::class, new ReplyCreate($member_msgs));
        $container->set(IndividualLink::class, new IndividualLink($portal_trees));

        // Phase 11 — members connecting with each other.
        $container->set(ConnectionList::class, new ConnectionList($connections));
        $container->set(ConnectionCreate::class, new ConnectionCreate($connections));
        $container->set(ConnectionUpdate::class, new ConnectionUpdate($connections));
        $container->set(ConnectionDelete::class, new ConnectionDelete($connections));
        $container->set(ConnectionCodeCreate::class, new ConnectionCodeCreate($connections));
        $container->set(ConnectionCodeDelete::class, new ConnectionCodeDelete($connections));

        $container->set(MemberInvitationList::class, new MemberInvitationList($member_invites));
        $container->set(MemberInvitationCreate::class, new MemberInvitationCreate($member_invites));
        $container->set(MemberInvitationDelete::class, new MemberInvitationDelete($member_invites));

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
        $container->set(InvitationAccept::class, new InvitationAccept($this, $portal_trees, $invitations, $user_service, $rate_limiter, $me));
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

        // Unauthenticated on purpose: a health check that needs credentials
        // is a health check nobody runs. The proxy secret still applies, and
        // the payload says nothing worth having.
        $map->get(HealthRead::class, self::ROUTE_PREFIX . '/health', HealthRead::class)
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

        // Phase 9 — contact details, and messages between members.
        $map->get(ContactRead::class, self::ROUTE_PREFIX . '/me/contact', ContactRead::class)
            ->extras(['middleware' => $private]);

        $map->patch(ContactUpdate::class, self::ROUTE_PREFIX . '/me/contact', ContactUpdate::class)
            ->extras(['middleware' => $unsafe_private]);

        $map->post(MessageCreate::class, self::ROUTE_PREFIX . '/members/{id}/message', MessageCreate::class)
            ->tokens(['id' => '\d+'])
            ->extras(['middleware' => $unsafe_private]);

        // Phase 10 — the member's own inbox.
        $map->get(InboxList::class, self::ROUTE_PREFIX . '/messages', InboxList::class)
            ->extras(['middleware' => $private]);

        $map->patch(InboxUpdate::class, self::ROUTE_PREFIX . '/messages/{id}', InboxUpdate::class)
            ->tokens(['id' => '\d+'])
            ->extras(['middleware' => $unsafe_private]);

        $map->delete(InboxDelete::class, self::ROUTE_PREFIX . '/messages/{id}', InboxDelete::class)
            ->tokens(['id' => '\d+'])
            ->extras(['middleware' => $unsafe_private]);

        $map->post(ReplyCreate::class, self::ROUTE_PREFIX . '/messages/{id}/reply', ReplyCreate::class)
            ->tokens(['id' => '\d+'])
            ->extras(['middleware' => $unsafe_private]);

        // Phase 11 — connections. Reading my own list is safe; everything
        // that changes one is an unsafe method and CSRF-checked, including
        // asking for a code, which is a write: it invalidates the one before
        // it.
        $map->get(ConnectionList::class, self::ROUTE_PREFIX . '/connections', ConnectionList::class)
            ->extras(['middleware' => $private]);

        $map->post(ConnectionCreate::class, self::ROUTE_PREFIX . '/connections', ConnectionCreate::class)
            ->extras(['middleware' => $unsafe_private]);

        $map->patch(ConnectionUpdate::class, self::ROUTE_PREFIX . '/connections/{id}', ConnectionUpdate::class)
            ->tokens(['id' => '\d+'])
            ->extras(['middleware' => $unsafe_private]);

        $map->delete(ConnectionDelete::class, self::ROUTE_PREFIX . '/connections/{id}', ConnectionDelete::class)
            ->tokens(['id' => '\d+'])
            ->extras(['middleware' => $unsafe_private]);

        $map->post(ConnectionCodeCreate::class, self::ROUTE_PREFIX . '/me/connection-code', ConnectionCodeCreate::class)
            ->extras(['middleware' => $unsafe_private]);

        $map->delete(ConnectionCodeDelete::class, self::ROUTE_PREFIX . '/me/connection-code', ConnectionCodeDelete::class)
            ->extras(['middleware' => $unsafe_private]);

        // The one route a person follows rather than the portal's client.
        // No middleware at all: webtrees' own stack — session, language,
        // theme — is what this needs, and the module's API middleware is what
        // it must not have. See `IndividualLink` for why it exists.
        $map->get(IndividualLink::class, self::LINK_PREFIX . '/individual/{xref}', IndividualLink::class)
            ->tokens(['xref' => '[A-Za-z0-9_.\-]{1,20}'])
            ->extras(['middleware' => []]);

        // Phase 7 — a member invites their own close family. Reading the
        // candidate list is safe (it is the walk their own page already does);
        // issuing and withdrawing are unsafe methods and CSRF-checked.
        $map->get(MemberInvitationList::class, self::ROUTE_PREFIX . '/invitations', MemberInvitationList::class)
            ->extras(['middleware' => $private]);

        $map->post(MemberInvitationCreate::class, self::ROUTE_PREFIX . '/invitations', MemberInvitationCreate::class)
            ->extras(['middleware' => $unsafe_private]);

        $map->delete(MemberInvitationDelete::class, self::ROUTE_PREFIX . '/invitations/{id}', MemberInvitationDelete::class)
            ->tokens(['id' => '\d+'])
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
            'member_invites'    => $this->getPreference(self::SETTING_MEMBER_INVITES, '1'),
            'member_invite_steps' => $this->getPreference(self::SETTING_MEMBER_INVITE_STEPS, (string) CloseFamily::DEFAULT_STEPS),
            'member_invite_quota' => $this->getPreference(self::SETTING_MEMBER_INVITE_QUOTA, (string) MemberInvitations::DEFAULT_QUOTA),
            'member_path_length'  => $this->getPreference(self::SETTING_MEMBER_PATH_LENGTH, '0'),
            'member_contact'      => $this->getPreference(self::SETTING_MEMBER_CONTACT, '1'),
            'member_messages'     => $this->getPreference(self::SETTING_MEMBER_MESSAGES, '1'),
            'message_limit'       => $this->getPreference(self::SETTING_MESSAGE_LIMIT, (string) MemberMessages::DEFAULT_DAILY_LIMIT),
            'member_connections'  => $this->getPreference(self::SETTING_MEMBER_CONNECTIONS, '1'),
            'connection_code_minutes' => $this->getPreference(self::SETTING_CONNECTION_CODE_MINUTES, (string) Connections::DEFAULT_CODE_MINUTES),
            'invitations_url'   => $this->invitationsUrl(),
            'diagnosis_url'     => $this->diagnosisUrl(),
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
        $this->setPreference(self::SETTING_MEMBER_INVITES, $body->boolean(self::SETTING_MEMBER_INVITES, false) ? '1' : '0');
        $this->setPreference(self::SETTING_MEMBER_INVITE_STEPS, (string) max(1, min(CloseFamily::MAX_STEPS, $body->integer(self::SETTING_MEMBER_INVITE_STEPS, CloseFamily::DEFAULT_STEPS))));
        $this->setPreference(self::SETTING_MEMBER_INVITE_QUOTA, (string) max(0, min(MemberInvitations::MAX_QUOTA, $body->integer(self::SETTING_MEMBER_INVITE_QUOTA, MemberInvitations::DEFAULT_QUOTA))));
        $this->setPreference(self::SETTING_MEMBER_PATH_LENGTH, (string) $this->pathLength($body->integer(self::SETTING_MEMBER_PATH_LENGTH, 0)));
        $this->setPreference(self::SETTING_MEMBER_CONTACT, $body->boolean(self::SETTING_MEMBER_CONTACT, false) ? '1' : '0');
        $this->setPreference(self::SETTING_MEMBER_MESSAGES, $body->boolean(self::SETTING_MEMBER_MESSAGES, false) ? '1' : '0');
        $this->setPreference(self::SETTING_MESSAGE_LIMIT, (string) max(0, min(MemberMessages::MAX_DAILY_LIMIT, $body->integer(self::SETTING_MESSAGE_LIMIT, MemberMessages::DEFAULT_DAILY_LIMIT))));
        $this->setPreference(self::SETTING_MEMBER_CONNECTIONS, $body->boolean(self::SETTING_MEMBER_CONNECTIONS, false) ? '1' : '0');
        $this->setPreference(self::SETTING_CONNECTION_CODE_MINUTES, (string) max(1, min(Connections::MAX_CODE_MINUTES, $body->integer(self::SETTING_CONNECTION_CODE_MINUTES, Connections::DEFAULT_CODE_MINUTES))));

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
            'issuers'     => $this->issuerNames(),
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

    /**
     * Who issued each invitation, by user id.
     *
     * Since Phase 7 an invitation can come from a member as well as from an
     * administrator, and "who let this person in" is the first question about
     * one that did not work out.
     *
     * @return array<int,string>
     */
    private function issuerNames(): array
    {
        $names = [];

        foreach (Registry::container()->get(UserService::class)->all() as $user) {
            $names[$user->id()] = $user->realName() . ' (' . $user->userName() . ')';
        }

        return $names;
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

    /** Is anything wrong, and what should be done about it? */
    public function getAdminDiagnosisAction(ServerRequestInterface $request): ResponseInterface
    {
        $this->layout = 'layouts/administration';

        $container = Registry::container();
        $diagnosis = $container->get(Diagnosis::class);
        $errors    = $container->get(ErrorLog::class);
        $checks    = $diagnosis->run();

        return $this->viewResponse($this->name() . '::diagnosis', [
            'title'        => I18N::translate('Diagnosis'),
            'module'       => $this,
            'checks'       => $checks,
            'worst'        => $diagnosis->worst($checks),
            'errors'       => $errors->recent(),
            'error_count'  => $errors->count(),
            'path_length'  => $this->memberPathLength(),
            'numbers'      => $diagnosis->directoryNumbers(),
            'settings_url' => $this->getConfigLink(),
        ]);
    }

    /** Two things can be posted here: clear the errors, or apply the limit. */
    public function postAdminDiagnosisAction(ServerRequestInterface $request): ResponseInterface
    {
        $action = Validator::parsedBody($request)->string('diagnosis_action', 'clear_errors');

        if ($action === 'apply_path_length') {
            $this->applyPathLength();
        } else {
            Registry::container()->get(ErrorLog::class)->clear();

            FlashMessages::addMessage(I18N::translate('The error log has been cleared.'), 'success');
        }

        return redirect($this->diagnosisUrl());
    }

    /**
     * Write the configured visibility limit onto every member account.
     *
     * Deliberately a button rather than something that happens on its own.
     * It changes what people who are already signed in can see, which is not
     * a thing to do behind an administrator's back — and it is reversible
     * only by choosing a different number and pressing it again.
     */
    private function applyPathLength(): void
    {
        $steps = $this->memberPathLength();

        if ($steps === 0) {
            FlashMessages::addMessage(I18N::translate('Choose a limit above “No limit” first, then apply it.'), 'danger');

            return;
        }

        $container = Registry::container();
        $tree      = $container->get(PortalTreeService::class)->tree();
        $changed   = $container->get(MemberService::class)->applyPathLength($tree, $steps);

        FlashMessages::addMessage(
            I18N::plural('%s member account was updated.', '%s member accounts were updated.', $changed, I18N::number($changed)),
            'success'
        );
    }

    private function diagnosisUrl(): string
    {
        return route('module', ['module' => $this->name(), 'action' => 'AdminDiagnosis']);
    }

    /** The configured visibility limit, clamped. Zero means "do not restrict". */
    public function memberPathLength(): int
    {
        return $this->pathLength((int) $this->getPreference(self::SETTING_MEMBER_PATH_LENGTH, '0'));
    }

    private function pathLength(int $steps): int
    {
        return max(0, min(self::MAX_PATH_LENGTH, $steps));
    }

    private function validityDays(int $days): int
    {
        return max(1, min(InvitationService::MAX_VALIDITY_DAYS, $days));
    }
}
