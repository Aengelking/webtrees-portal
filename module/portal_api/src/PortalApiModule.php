<?php

declare(strict_types=1);

namespace Engelking\Webtrees\PortalApi;

use Engelking\Webtrees\PortalApi\Http\Middleware\ApiEnvelope;
use Engelking\Webtrees\PortalApi\Http\Middleware\RequireAuthentication;
use Engelking\Webtrees\PortalApi\Http\Middleware\RequireCsrfToken;
use Engelking\Webtrees\PortalApi\Http\Middleware\RequireProxySecret;
use Engelking\Webtrees\PortalApi\Http\Middleware\ResumeRememberedSession;
use Engelking\Webtrees\PortalApi\Http\Middleware\UsePortalLanguage;
use Engelking\Webtrees\PortalApi\Http\RequestHandlers\AncestorsRead;
use Engelking\Webtrees\PortalApi\Http\RequestHandlers\ConnectionCodeCreate;
use Engelking\Webtrees\PortalApi\Http\RequestHandlers\ConnectionCodeDelete;
use Engelking\Webtrees\PortalApi\Http\RequestHandlers\ConnectionCreate;
use Engelking\Webtrees\PortalApi\Http\RequestHandlers\ConnectionDelete;
use Engelking\Webtrees\PortalApi\Http\RequestHandlers\ConnectionLinkCreate;
use Engelking\Webtrees\PortalApi\Http\RequestHandlers\ConnectionLinkDelete;
use Engelking\Webtrees\PortalApi\Http\RequestHandlers\ConnectionList;
use Engelking\Webtrees\PortalApi\Http\RequestHandlers\ConnectionUpdate;
use Engelking\Webtrees\PortalApi\Http\RequestHandlers\ContactRead;
use Engelking\Webtrees\PortalApi\Http\RequestHandlers\ConversationCreate;
use Engelking\Webtrees\PortalApi\Http\RequestHandlers\ConversationDelete;
use Engelking\Webtrees\PortalApi\Http\RequestHandlers\ConversationList;
use Engelking\Webtrees\PortalApi\Http\RequestHandlers\ConversationMessageCreate;
use Engelking\Webtrees\PortalApi\Http\RequestHandlers\ConversationMessageDelete;
use Engelking\Webtrees\PortalApi\Http\RequestHandlers\ConversationRead;
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
use Engelking\Webtrees\PortalApi\Http\RequestHandlers\InvitationClaimCreate;
use Engelking\Webtrees\PortalApi\Http\RequestHandlers\InvitationRead;
use Engelking\Webtrees\PortalApi\Http\RequestHandlers\MailingListRead;
use Engelking\Webtrees\PortalApi\Http\RequestHandlers\MailingListUpdate;
use Engelking\Webtrees\PortalApi\Http\RequestHandlers\MemberInvitationCreate;
use Engelking\Webtrees\PortalApi\Http\RequestHandlers\MemberInvitationDelete;
use Engelking\Webtrees\PortalApi\Http\RequestHandlers\MemberInvitationList;
use Engelking\Webtrees\PortalApi\Http\RequestHandlers\MessageCreate;
use Engelking\Webtrees\PortalApi\Http\RequestHandlers\MeRead;
use Engelking\Webtrees\PortalApi\Http\RequestHandlers\MediaRead;
use Engelking\Webtrees\PortalApi\Http\RequestHandlers\IndexRead;
use Engelking\Webtrees\PortalApi\Http\RequestHandlers\MemberList;
use Engelking\Webtrees\PortalApi\Http\RequestHandlers\MemberAncestorsRead;
use Engelking\Webtrees\PortalApi\Http\RequestHandlers\MemberRead;
use Engelking\Webtrees\PortalApi\Http\RequestHandlers\PasswordRequestCreate;
use Engelking\Webtrees\PortalApi\Http\RequestHandlers\PasswordResetCreate;
use Engelking\Webtrees\PortalApi\Http\RequestHandlers\ProfileUpdate;
use Engelking\Webtrees\PortalApi\Http\RequestHandlers\PhotoCreate;
use Engelking\Webtrees\PortalApi\Http\RequestHandlers\PhotoDelete;
use Engelking\Webtrees\PortalApi\Http\RequestHandlers\PushCreate;
use Engelking\Webtrees\PortalApi\Http\RequestHandlers\PushDelete;
use Engelking\Webtrees\PortalApi\Http\RequestHandlers\PushRead;
use Engelking\Webtrees\PortalApi\Http\RequestHandlers\ReplyCreate;
use Engelking\Webtrees\PortalApi\Http\RequestHandlers\RelationshipRead;
use Engelking\Webtrees\PortalApi\Http\RequestHandlers\SearchList;
use Engelking\Webtrees\PortalApi\Http\RequestHandlers\SessionCreate;
use Engelking\Webtrees\PortalApi\Http\RequestHandlers\SessionDelete;
use Engelking\Webtrees\PortalApi\Services\AccountOverview;
use Engelking\Webtrees\PortalApi\Services\AncestorTree;
use Engelking\Webtrees\PortalApi\Services\CloseFamily;
use Engelking\Webtrees\PortalApi\Services\Connections;
use Engelking\Webtrees\PortalApi\Services\Conversations;
use Engelking\Webtrees\PortalApi\Services\PushSubscriptions;
use Engelking\Webtrees\PortalApi\Services\WebPush;
use Engelking\Webtrees\PortalApi\Services\ContactDetails;
use Engelking\Webtrees\PortalApi\Services\Diagnosis;
use Engelking\Webtrees\PortalApi\Services\DistributionLists;
use Engelking\Webtrees\PortalApi\Services\ExchangeOnline;
use Engelking\Webtrees\PortalApi\Services\ErrorLog;
use Engelking\Webtrees\PortalApi\Services\GedcomEditor;
use Engelking\Webtrees\PortalApi\Services\Inbox;
use Engelking\Webtrees\PortalApi\Services\InvitationCampaigns;
use Engelking\Webtrees\PortalApi\Services\InvitationService;
use Engelking\Webtrees\PortalApi\Services\LoginRateLimiter;
use Engelking\Webtrees\PortalApi\Services\MeAssembler;
use Engelking\Webtrees\PortalApi\Services\MemberInvitations;
use Engelking\Webtrees\PortalApi\Services\MemberMessages;
use Engelking\Webtrees\PortalApi\Services\MemberService;
use Engelking\Webtrees\PortalApi\Services\PendingChanges;
use Engelking\Webtrees\PortalApi\Services\PhotoPresenter;
use Engelking\Webtrees\PortalApi\Services\Photos;
use Engelking\Webtrees\PortalApi\Services\PortalTreeService;
use Engelking\Webtrees\PortalApi\Services\Recognition;
use Engelking\Webtrees\PortalApi\Services\RecordPresenter;
use Engelking\Webtrees\PortalApi\Services\RememberedDevices;
use Engelking\Webtrees\PortalApi\Services\SackNumbers;
use Engelking\Webtrees\PortalApi\Services\SackRelationship;
use Engelking\Webtrees\PortalApi\Services\SearchConsent;
use Engelking\Webtrees\PortalApi\Services\TreeSearch;
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
use Fisharebest\Webtrees\Services\SearchService;
use Fisharebest\Webtrees\Services\TreeService;
use Fisharebest\Webtrees\Services\UserService;
use Fisharebest\Webtrees\Session;
use Fisharebest\Webtrees\Site;
use Fisharebest\Webtrees\Validator;
use Fisharebest\Webtrees\View;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Throwable;

use function array_key_exists;
use function error_log;
use function is_string;
use function max;
use function min;
use function rawurlencode;
use function rtrim;
use function str_replace;
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

    public const string CUSTOM_VERSION = '1.3.0';

    /** Bumped when src/Schema/MigrationN.php classes are added. */
    private const int SCHEMA_VERSION = 16;

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
    public const string SETTING_MEMBER_SHOW_NUMBER  = 'member_show_number';
    public const string SETTING_MEMBER_CONTACT      = 'member_contact';
    public const string SETTING_MEMBER_MESSAGES     = 'member_messages';
    public const string SETTING_MESSAGE_LIMIT       = 'message_limit';

    /**
     * Notifications, and the key pair that identifies this portal to the
     * browsers' push services.
     *
     * The private key is generated once and never overwritten: replacing it
     * would invalidate every subscription any member ever made. It lives in
     * the module's settings rather than in a file because that is where this
     * module keeps everything a deployment must not lose.
     */
    public const string SETTING_PUSH                = 'push_notifications';
    public const string SETTING_VAPID_PUBLIC        = 'vapid_public_key';
    public const string SETTING_VAPID_PRIVATE       = 'vapid_private_key';
    public const string SETTING_MEMBER_CONNECTIONS  = 'member_connections';
    public const string SETTING_CONNECTION_CODE_MINUTES = 'connection_code_minutes';
    public const string SETTING_REMEMBER_DAYS       = 'remember_days';

    // Phase 14 — the family's mailing lists, which live in Exchange Online.
    // The secret is stored the way webtrees stores every other module secret,
    // which is in the clear in `module_setting`. That is worth knowing rather
    // than glossing: whoever can read the database can send mail as this
    // application. It buys the ability to manage a distribution list at all —
    // Microsoft Graph will not — and it is why the application registration
    // behind it should hold the one Exchange role it needs and nothing else.
    public const string SETTING_MAILING_LISTS           = 'mailing_lists';
    public const string SETTING_MAILING_LIST_ADDRESSES  = 'mailing_list_addresses';
    public const string SETTING_EXCHANGE_TENANT         = 'exchange_tenant';
    public const string SETTING_EXCHANGE_CLIENT_ID      = 'exchange_client_id';
    public const string SETTING_EXCHANGE_SECRET         = 'exchange_client_secret';
    public const string SETTING_EXCHANGE_HIDE_CONTACTS  = 'exchange_hide_contacts';

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
        $sack_numbers   = new SackNumbers($this);
        $sack           = new SackRelationship($sack_numbers);
        $relationships  = new RelationshipNamer($container->get(RelationshipService::class), $sack);
        $photo_store    = new Photos($portal_trees, $pending);
        $photos         = new PhotoPresenter($photo_store);
        $presenter      = new RecordPresenter($pending, $relationships, $photos, $sack_numbers);
        $members        = new MemberService($user_service);
        $ancestors      = new AncestorTree($presenter, $members);
        $search_consent = new SearchConsent($members, $portal_trees);
        $tree_search    = new TreeSearch($portal_trees, $container->get(SearchService::class), $search_consent);
        $rate_limiter   = new LoginRateLimiter($this);
        $gedcom_editor  = new GedcomEditor($pending);
        $invitations    = new InvitationService();
        $errors         = new ErrorLog();
        $close_family   = new CloseFamily($container->get(RelationshipService::class), $user_service);
        $member_invites = new MemberInvitations($this, $portal_trees, $invitations, $close_family, $presenter);
        $recognition    = new Recognition($this, $portal_trees, $photos, $sack_numbers);
        $connections    = new Connections($this, $portal_trees, $members, $presenter, $user_service, $recognition);
        $contacts       = new ContactDetails($this, $close_family, $connections);
        $inbox          = new Inbox($user_service);
        $member_msgs    = new MemberMessages($this, $container->get(MessageService::class), $container->get(RateLimitService::class), $members, $inbox, $connections, $container->get(EmailService::class));
        $web_push       = new WebPush($this);

        // Once, on the first boot after this module is installed or upgraded.
        // A key pair is the portal's identity to every push service its
        // members' browsers use, so it is made here rather than asked of an
        // administrator — and never replaced, because replacing it would
        // silently invalidate every subscription anybody ever made.
        $web_push->ensureKeys();

        $push           = new PushSubscriptions($this, $web_push);
        $conversations  = new Conversations($members, $connections, $member_msgs, $user_service, $push);
        $me             = new MeAssembler($portal_trees, $presenter, $members, $inbox, $connections, $conversations);
        $exchange       = new ExchangeOnline($this);
        $mailing_lists  = new DistributionLists($this, $exchange);
        $campaigns      = new InvitationCampaigns(
            $this,
            $portal_trees,
            $mailing_lists,
            $exchange,
            $invitations,
            $tree_search,
            $user_service,
            $container->get(EmailService::class),
        );
        $devices        = new RememberedDevices($this, $user_service);

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
        $container->set(Recognition::class, $recognition);
        $container->set(ContactDetails::class, $contacts);
        $container->set(Inbox::class, $inbox);
        $container->set(Conversations::class, $conversations);
        $container->set(PushSubscriptions::class, $push);
        $container->set(ExchangeOnline::class, $exchange);
        $container->set(DistributionLists::class, $mailing_lists);
        $container->set(InvitationCampaigns::class, $campaigns);
        $container->set(InvitationClaimCreate::class, new InvitationClaimCreate($campaigns, $container->get(RateLimitService::class)));
        $container->set(MailingListRead::class, new MailingListRead($mailing_lists));
        $container->set(MailingListUpdate::class, new MailingListUpdate($mailing_lists));
        $container->set(PushRead::class, new PushRead($push));
        $container->set(PhotoCreate::class, new PhotoCreate($photo_store, $photos, $portal_trees));
        $container->set(PhotoDelete::class, new PhotoDelete($photo_store, $photos, $portal_trees));
        $container->set(PushCreate::class, new PushCreate($push));
        $container->set(PushDelete::class, new PushDelete($push));
        $container->set(MemberMessages::class, $member_msgs);
        $container->set(Diagnosis::class, new Diagnosis($this, $portal_trees, $members, $errors, $mailing_lists));
        $container->set(RememberedDevices::class, $devices);

        $container->set(ApiEnvelope::class, new ApiEnvelope($errors));
        $container->set(UsePortalLanguage::class, new UsePortalLanguage($container->get(ModuleService::class)));
        $container->set(RequireProxySecret::class, new RequireProxySecret($this));
        $container->set(RequireCsrfToken::class, new RequireCsrfToken());
        $container->set(RequireAuthentication::class, new RequireAuthentication());
        $container->set(ResumeRememberedSession::class, new ResumeRememberedSession());

        $container->set(CsrfTokenRead::class, new CsrfTokenRead($devices));
        $container->set(HealthRead::class, new HealthRead($this, $portal_trees));
        $container->set(SessionCreate::class, new SessionCreate($user_service, $rate_limiter, $me, $devices));
        $container->set(SessionDelete::class, new SessionDelete($devices));
        $container->set(MeRead::class, new MeRead($me));
        $container->set(IndividualRead::class, new IndividualRead($portal_trees, $presenter, $member_invites));
        $container->set(AncestorsRead::class, new AncestorsRead($portal_trees, $ancestors));
        $container->set(MediaRead::class, new MediaRead($portal_trees, $photos, $photo_store));
        $container->set(MemberList::class, new MemberList($portal_trees, $presenter, $members, $connections, $recognition));
        $container->set(SearchList::class, new SearchList($portal_trees, $presenter, $tree_search));
        $container->set(IndexRead::class, new IndexRead($portal_trees, $tree_search));
        $container->set(RelationshipRead::class, new RelationshipRead($sack));
        $container->set(MemberRead::class, new MemberRead($portal_trees, $presenter, $members, $contacts, $member_msgs, $member_invites, $connections, $recognition, $ancestors));
        $container->set(MemberAncestorsRead::class, new MemberAncestorsRead($portal_trees, $members, $connections, $ancestors));
        $container->set(ContactRead::class, new ContactRead($contacts, $connections));
        $container->set(ContactUpdate::class, new ContactUpdate($contacts, $connections));
        $container->set(MessageCreate::class, new MessageCreate($member_msgs));
        $container->set(ConversationList::class, new ConversationList($conversations));
        $container->set(ConversationCreate::class, new ConversationCreate($conversations));
        $container->set(ConversationRead::class, new ConversationRead($conversations));
        $container->set(ConversationMessageCreate::class, new ConversationMessageCreate($conversations));
        $container->set(ConversationMessageDelete::class, new ConversationMessageDelete($conversations));
        $container->set(ConversationDelete::class, new ConversationDelete($conversations));
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
        $container->set(ConnectionLinkCreate::class, new ConnectionLinkCreate($connections));
        $container->set(ConnectionLinkDelete::class, new ConnectionLinkDelete($connections));

        $container->set(MemberInvitationList::class, new MemberInvitationList($member_invites));
        $container->set(MemberInvitationCreate::class, new MemberInvitationCreate($member_invites));
        $container->set(MemberInvitationDelete::class, new MemberInvitationDelete($member_invites));

        $container->set(ProfileUpdate::class, new ProfileUpdate($members, $container->get(UsePortalLanguage::class)));
        $container->set(IndividualUpdate::class, new IndividualUpdate($portal_trees, $presenter, $gedcom_editor, $pending));
        $container->set(PasswordRequestCreate::class, new PasswordRequestCreate(
            $this,
            $user_service,
            $container->get(EmailService::class),
            $container->get(RateLimitService::class),
        ));
        $container->set(PasswordResetCreate::class, new PasswordResetCreate($user_service, $me, $devices));

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

        // The resume sits immediately in front of the authentication check,
        // and only on the chains that have one: it is the answer to "there is
        // no session", so everywhere that does not mind a missing session has
        // no use for it either.
        $private = [
            ApiEnvelope::class,
            UsePortalLanguage::class,
            RequireProxySecret::class,
            ResumeRememberedSession::class,
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

        // The pedigree of somebody whose record the reader may not open. Keyed
        // by member id rather than XREF on purpose — see the handler.
        $map->get(MemberAncestorsRead::class, self::ROUTE_PREFIX . '/members/{id}/ancestors', MemberAncestorsRead::class)
            ->tokens(['id' => '\d+'])
            ->extras(['middleware' => $private]);

        // Phase 16 — looking through the tree rather than walking it.
        $map->get(SearchList::class, self::ROUTE_PREFIX . '/search', SearchList::class)
            ->extras(['middleware' => $private]);

        $map->get(IndexRead::class, self::ROUTE_PREFIX . '/index', IndexRead::class)
            ->extras(['middleware' => $private]);

        $map->get(RelationshipRead::class, self::ROUTE_PREFIX . '/relationship', RelationshipRead::class)
            ->extras(['middleware' => $private]);

        // Phase 2 — writes. Authenticated *and* CSRF-checked: these are the
        // routes the CSRF machinery was built for in Phase 1.
        $unsafe_private = [
            ApiEnvelope::class,
            UsePortalLanguage::class,
            RequireProxySecret::class,
            RequireCsrfToken::class,
            ResumeRememberedSession::class,
            RequireAuthentication::class,
        ];

        $map->post(PhotoCreate::class, self::ROUTE_PREFIX . '/photos', PhotoCreate::class)
            ->extras(['middleware' => $unsafe_private]);

        $map->delete(PhotoDelete::class, self::ROUTE_PREFIX . '/photos/{xref}', PhotoDelete::class)
            ->tokens(['xref' => '[A-Za-z0-9_.\-]{1,20}'])
            ->extras(['middleware' => $unsafe_private]);


        $map->patch(ProfileUpdate::class, self::ROUTE_PREFIX . '/me/profile', ProfileUpdate::class)
            ->extras(['middleware' => $unsafe_private]);

        // Phase 9 — contact details, and messages between members.
        $map->get(ContactRead::class, self::ROUTE_PREFIX . '/me/contact', ContactRead::class)
            ->extras(['middleware' => $private]);

        $map->patch(ContactUpdate::class, self::ROUTE_PREFIX . '/me/contact', ContactUpdate::class)
            ->extras(['middleware' => $unsafe_private]);

        // Phase 14 — the family's mailing lists. Read and written only about
        // the signed-in member; there is no route here that names anybody else.
        $map->get(MailingListRead::class, self::ROUTE_PREFIX . '/me/mailing-lists', MailingListRead::class)
            ->extras(['middleware' => $private]);

        $map->patch(MailingListUpdate::class, self::ROUTE_PREFIX . '/me/mailing-lists', MailingListUpdate::class)
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

        // Phase 13 — notifications. Subscribing and unsubscribing both write,
        // so both are CSRF-checked; reading says only what this portal can do
        // and whether this account has any device signed up.
        $map->get(PushRead::class, self::ROUTE_PREFIX . '/push', PushRead::class)
            ->extras(['middleware' => $private]);

        $map->post(PushCreate::class, self::ROUTE_PREFIX . '/push', PushCreate::class)
            ->extras(['middleware' => $unsafe_private]);

        $map->delete(PushDelete::class, self::ROUTE_PREFIX . '/push', PushDelete::class)
            ->extras(['middleware' => $unsafe_private]);

        // Phase 12 — conversations. Opening one is a write: it is the step
        // the directory rule guards, and it creates a row.
        $map->get(ConversationList::class, self::ROUTE_PREFIX . '/conversations', ConversationList::class)
            ->extras(['middleware' => $private]);

        $map->post(ConversationCreate::class, self::ROUTE_PREFIX . '/conversations', ConversationCreate::class)
            ->extras(['middleware' => $unsafe_private]);

        $map->get(ConversationRead::class, self::ROUTE_PREFIX . '/conversations/{id}', ConversationRead::class)
            ->tokens(['id' => '\d+'])
            ->extras(['middleware' => $private]);

        $map->delete(ConversationDelete::class, self::ROUTE_PREFIX . '/conversations/{id}', ConversationDelete::class)
            ->tokens(['id' => '\d+'])
            ->extras(['middleware' => $unsafe_private]);

        $map->post(
            ConversationMessageCreate::class,
            self::ROUTE_PREFIX . '/conversations/{id}/messages',
            ConversationMessageCreate::class,
        )
            ->tokens(['id' => '\d+'])
            ->extras(['middleware' => $unsafe_private]);

        $map->delete(
            ConversationMessageDelete::class,
            self::ROUTE_PREFIX . '/conversations/{id}/messages/{message}',
            ConversationMessageDelete::class,
        )
            ->tokens(['id' => '\d+', 'message' => '\d+'])
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

        // The same handshake, for somebody who is not in the room. A write
        // like the code above — it issues a credential — and CSRF-checked.
        $map->post(ConnectionLinkCreate::class, self::ROUTE_PREFIX . '/me/connection-link', ConnectionLinkCreate::class)
            ->extras(['middleware' => $unsafe_private]);

        $map->delete(ConnectionLinkDelete::class, self::ROUTE_PREFIX . '/me/connection-links/{id}', ConnectionLinkDelete::class)
            ->tokens(['id' => '\d+'])
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

        // Phase 15 — answering the letter that went to a mailing list. Grants
        // nothing on its own; what it can do is send a personal invitation to
        // an address that is already on one of the family's lists.
        $map->post(InvitationClaimCreate::class, self::ROUTE_PREFIX . '/invitation/claim', InvitationClaimCreate::class)
            ->extras(['middleware' => $public]);

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
            'member_show_number'  => $this->getPreference(self::SETTING_MEMBER_SHOW_NUMBER, '0'),
            'member_contact'      => $this->getPreference(self::SETTING_MEMBER_CONTACT, '1'),
            'member_messages'     => $this->getPreference(self::SETTING_MEMBER_MESSAGES, '1'),
            'message_limit'       => $this->getPreference(self::SETTING_MESSAGE_LIMIT, (string) MemberMessages::DEFAULT_DAILY_LIMIT),
            'push'                => $this->getPreference(self::SETTING_PUSH, '1'),
            'member_connections'  => $this->getPreference(self::SETTING_MEMBER_CONNECTIONS, '1'),
            'connection_code_minutes' => $this->getPreference(self::SETTING_CONNECTION_CODE_MINUTES, (string) Connections::DEFAULT_CODE_MINUTES),
            'remember_days'       => $this->getPreference(self::SETTING_REMEMBER_DAYS, (string) RememberedDevices::DEFAULT_DAYS),
            'mailing_lists'          => $this->getPreference(self::SETTING_MAILING_LISTS, '0'),
            'mailing_list_addresses' => $this->getPreference(self::SETTING_MAILING_LIST_ADDRESSES, ''),
            'exchange_tenant'        => $this->getPreference(self::SETTING_EXCHANGE_TENANT, ''),
            'exchange_client_id'     => $this->getPreference(self::SETTING_EXCHANGE_CLIENT_ID, ''),
            'exchange_client_secret' => $this->getPreference(self::SETTING_EXCHANGE_SECRET, ''),
            'exchange_hide_contacts' => $this->getPreference(self::SETTING_EXCHANGE_HIDE_CONTACTS, '1'),
            'sack_lines'        => $this->getPreference(SackNumbers::SETTING_LINES, ''),
            'sack_marriages'    => $this->getPreference(SackNumbers::SETTING_MARRIAGES, ''),
            'sack_branches'     => $this->getPreference(SackNumbers::SETTING_BRANCHES, ''),
            'sack_lines_default'     => SackNumbers::DEFAULT_LINES,
            'sack_marriages_default' => SackNumbers::DEFAULT_MARRIAGES,
            'sack_branches_default'  => SackNumbers::DEFAULT_BRANCHES,
            'invitations_url'   => $this->invitationsUrl(),
            'campaigns_url'     => $this->campaignsUrl(),
            'diagnosis_url'     => $this->diagnosisUrl(),
            'accounts_url'      => $this->accountsUrl(),
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
        $this->setPreference(self::SETTING_MEMBER_SHOW_NUMBER, $body->boolean(self::SETTING_MEMBER_SHOW_NUMBER, false) ? '1' : '0');
        $this->setPreference(self::SETTING_MEMBER_CONTACT, $body->boolean(self::SETTING_MEMBER_CONTACT, false) ? '1' : '0');
        $this->setPreference(self::SETTING_MEMBER_MESSAGES, $body->boolean(self::SETTING_MEMBER_MESSAGES, false) ? '1' : '0');
        $this->setPreference(self::SETTING_MESSAGE_LIMIT, (string) max(0, min(MemberMessages::MAX_DAILY_LIMIT, $body->integer(self::SETTING_MESSAGE_LIMIT, MemberMessages::DEFAULT_DAILY_LIMIT))));
        $this->setPreference(self::SETTING_PUSH, $body->boolean(self::SETTING_PUSH, false) ? '1' : '0');
        $this->setPreference(self::SETTING_MEMBER_CONNECTIONS, $body->boolean(self::SETTING_MEMBER_CONNECTIONS, false) ? '1' : '0');
        $this->setPreference(self::SETTING_CONNECTION_CODE_MINUTES, (string) max(1, min(Connections::MAX_CODE_MINUTES, $body->integer(self::SETTING_CONNECTION_CODE_MINUTES, Connections::DEFAULT_CODE_MINUTES))));
        $this->setPreference(self::SETTING_REMEMBER_DAYS, (string) max(0, min(RememberedDevices::MAX_DAYS, $body->integer(self::SETTING_REMEMBER_DAYS, RememberedDevices::DEFAULT_DAYS))));

        $this->setPreference(self::SETTING_MAILING_LISTS, $body->boolean(self::SETTING_MAILING_LISTS, false) ? '1' : '0');
        $this->setPreference(self::SETTING_MAILING_LIST_ADDRESSES, trim(str_replace("\r\n", "\n", $body->string(self::SETTING_MAILING_LIST_ADDRESSES, ''))));
        $this->setPreference(self::SETTING_EXCHANGE_TENANT, trim($body->string(self::SETTING_EXCHANGE_TENANT, '')));
        $this->setPreference(self::SETTING_EXCHANGE_CLIENT_ID, trim($body->string(self::SETTING_EXCHANGE_CLIENT_ID, '')));
        $this->setPreference(self::SETTING_EXCHANGE_SECRET, trim($body->string(self::SETTING_EXCHANGE_SECRET, '')));
        $this->setPreference(self::SETTING_EXCHANGE_HIDE_CONTACTS, $body->boolean(self::SETTING_EXCHANGE_HIDE_CONTACTS, false) ? '1' : '0');

        // Left empty on purpose when it matches what is shipped: an empty
        // setting means "whatever the module was built with", so a later
        // correction to the archive's own tables reaches an installation that
        // never edited them.
        $this->setPreference(SackNumbers::SETTING_LINES, $this->sackTable($body->string(SackNumbers::SETTING_LINES, ''), SackNumbers::DEFAULT_LINES));
        $this->setPreference(SackNumbers::SETTING_MARRIAGES, $this->sackTable($body->string(SackNumbers::SETTING_MARRIAGES, ''), SackNumbers::DEFAULT_MARRIAGES));
        $this->setPreference(SackNumbers::SETTING_BRANCHES, $this->sackTable($body->string(SackNumbers::SETTING_BRANCHES, ''), SackNumbers::DEFAULT_BRANCHES));

        FlashMessages::addMessage(I18N::translate('The preferences for the module “%s” have been updated.', $this->title()), 'success');

        return redirect($this->getConfigLink());
    }

    /** @see postAdminAction() for why an unchanged table is stored as nothing. */
    private function sackTable(string $submitted, string $default): string
    {
        $normalised = trim(str_replace("\r\n", "\n", $submitted));

        return $normalised === trim($default) ? '' : $normalised;
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

    // -----------------------------------------------------------------
    // Inviting a mailing list (administrators only)
    //
    // Same access rule as the invitations screen above: webtrees refuses any
    // action whose name contains "Admin" to anybody who is not one, before the
    // method is called. The names must keep the word.
    // -----------------------------------------------------------------

    /** Start a campaign, see what has come of the ones already sent. */
    public function getAdminCampaignsAction(ServerRequestInterface $request): ResponseInterface
    {
        $this->layout = 'layouts/administration';

        $container = Registry::container();
        $campaigns = $container->get(InvitationCampaigns::class);

        // Shown once and then forgotten, like an invitation link. Through the
        // session rather than the redirect, because a token in a URL is a
        // token in the webserver's access log — even one that grants nothing.
        $new_link = Session::get('portal_api_new_campaign', '');
        $new_link = is_string($new_link) ? $new_link : '';
        Session::forget('portal_api_new_campaign');

        return $this->viewResponse($this->name() . '::campaigns', [
            'title'        => I18N::translate('Invite a mailing list'),
            'module'       => $this,
            'campaigns'    => $campaigns->all(),
            'lists'        => $container->get(DistributionLists::class)->configured(),
            'new_link'     => $new_link,
            'letter'       => $new_link === '' ? '' : $this->suggestedLetter($new_link),
            'portal_url'   => $this->getPreference(self::SETTING_PORTAL_URL, ''),
            'settings_url' => $this->getConfigLink(),
        ]);
    }

    /** Create one, or call one off, then redirect back to the screen. */
    public function postAdminCampaignsAction(ServerRequestInterface $request): ResponseInterface
    {
        $body = Validator::parsedBody($request);

        if ($body->string('campaign_action', '') === 'revoke') {
            Registry::container()->get(InvitationCampaigns::class)->revoke($body->integer('campaign_id', 0));

            FlashMessages::addMessage(I18N::translate('The campaign has been called off. The link in the letter no longer does anything.'), 'success');

            return redirect($this->campaignsUrl());
        }

        $this->createCampaign($body->string('campaign_name', ''), $body->array('campaign_lists'), $body->integer('campaign_days', InvitationCampaigns::DEFAULT_VALIDITY_DAYS));

        return redirect($this->campaignsUrl());
    }

    /**
     * @param array<string,mixed> $ticked
     */
    private function createCampaign(string $name, array $ticked, int $days): void
    {
        $container  = Registry::container();
        $campaigns  = $container->get(InvitationCampaigns::class);
        $configured = $container->get(DistributionLists::class)->configured();

        // Only lists that are still configured, and only ones that were
        // ticked. A campaign naming a list nobody can be on would accept
        // nobody and say nothing about why.
        $lists = [];

        foreach ($ticked as $hash => $on) {
            if ($on && array_key_exists($hash, $configured)) {
                $lists[] = (string) $hash;
            }
        }

        if ($lists === []) {
            FlashMessages::addMessage(I18N::translate('Tick at least one list, or the campaign can invite nobody.'), 'danger');

            return;
        }

        if ($this->getPreference(self::SETTING_PORTAL_URL, '') === '') {
            FlashMessages::addMessage(I18N::translate('The portal address is not set, so a link cannot be built. Set it in the module preferences first.'), 'danger');

            return;
        }

        Session::put('portal_api_new_campaign', $campaigns->link($campaigns->create($name, $lists, $days, Auth::user())));
    }

    /**
     * A letter an administrator can paste into their own mail programme.
     *
     * Offered rather than sent. A family distribution list usually refuses
     * anything posted by an application that is not a member of it, and a
     * letter that comes from a person reads better than one from a portal —
     * so the module writes the words and a human presses send.
     */
    private function suggestedLetter(string $link): string
    {
        return I18N::translate('Dear family,') . "\n\n"
            . I18N::translate('our family tree now has a portal: your own record, the tree around it, and who else of us is in there. It is not open to the public — only people on this list can get in.') . "\n\n"
            . I18N::translate('If you would like an account, open this page and enter the address this letter reached you at:') . "\n\n"
            . $link . "\n\n"
            . I18N::translate('Your personal invitation is then sent to that address. The link in it is yours alone and works once, so please do not pass it on.') . "\n\n"
            . I18N::translate('If you would rather not, there is nothing to do.') . "\n";
    }

    private function campaignsUrl(): string
    {
        return route('module', ['module' => $this->name(), 'action' => 'AdminCampaigns']);
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
            'lists_enabled' => $container->get(DistributionLists::class)->enabled(),
            'lists_waiting' => (int) $container->get(DistributionLists::class)->overview()['outstanding'],
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
        } elseif ($action === 'retry_lists') {
            $this->retryMailingLists();
        } elseif ($action === 'check_exchange') {
            $this->checkMailingLists();
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

    /**
     * Let every outstanding subscription try again, now.
     *
     * The counterpart to giving up after three attempts. Something was wrong,
     * an administrator has just put it right, and this is how they say so —
     * without which the only way back would be asking every member to press
     * their switch a second time.
     */
    private function retryMailingLists(): void
    {
        $woken = Registry::container()->get(DistributionLists::class)->retryAll();

        FlashMessages::addMessage(
            $woken === 0
                ? I18N::translate('There was nothing outstanding.')
                : I18N::plural('%s change will be applied the next time the member opens the portal.', '%s changes will be applied the next time those members open the portal.', $woken, I18N::number($woken)),
            'success'
        );
    }

    /**
     * Ask Exchange, once per list, whether this configuration can see it.
     *
     * The one place in the module that contacts Exchange on an administrator's
     * behalf rather than a member's, and it exists because the alternative way
     * to find out that a tenant, an application secret and a list address all
     * agree is to ask a member to press a switch and then read a log.
     */
    private function checkMailingLists(): void
    {
        $container = Registry::container();
        $lists     = $container->get(DistributionLists::class);
        $exchange  = $container->get(ExchangeOnline::class);
        $configured = $lists->configured();

        if ($configured === []) {
            FlashMessages::addMessage(I18N::translate('No lists are configured.'), 'danger');

            return;
        }

        foreach ($configured as $list) {
            $error = $exchange->check($list['address']);

            FlashMessages::addMessage(
                $error === ''
                    ? I18N::translate('“%s” was found in Exchange.', $list['name'])
                    : I18N::translate('“%1$s” could not be read: %2$s', $list['name'], $error),
                $error === '' ? 'success' : 'danger'
            );
        }
    }

    private function diagnosisUrl(): string
    {
        return route('module', ['module' => $this->name(), 'action' => 'AdminDiagnosis']);
    }

    // -----------------------------------------------------------------
    // Accounts (administrators only)
    //
    // Same access rule as the screens above: webtrees refuses any action
    // whose name contains "Admin" to anybody who is not one, before the
    // method is called. The name must keep the word.
    // -----------------------------------------------------------------

    /** Who has an account, and whether the portal will let them in. */
    public function getAdminAccountsAction(ServerRequestInterface $request): ResponseInterface
    {
        $this->layout = 'layouts/administration';

        $container = Registry::container();
        $overview  = $container->get(AccountOverview::class);
        $tree      = $container->get(PortalTreeService::class)->tree();
        $accounts  = $overview->all($tree);

        return $this->viewResponse($this->name() . '::accounts', [
            'title'                   => I18N::translate('Accounts'),
            'module'                  => $this,
            'tree'                    => $tree,
            'accounts'                => $accounts,
            'blocked'                 => $overview->blocked($accounts),
            'requires_authentication' => $tree->getPreference('REQUIRE_AUTHENTICATION') === '1',
            'settings_url'            => $this->getConfigLink(),
        ]);
    }

    private function accountsUrl(): string
    {
        return route('module', ['module' => $this->name(), 'action' => 'AdminAccounts']);
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
