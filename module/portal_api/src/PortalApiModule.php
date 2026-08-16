<?php

declare(strict_types=1);

namespace Engelking\Webtrees\PortalApi;

use Engelking\Webtrees\PortalApi\Http\Middleware\ApiEnvelope;
use Engelking\Webtrees\PortalApi\Http\Middleware\RequireAuthentication;
use Engelking\Webtrees\PortalApi\Http\Middleware\RequireCsrfToken;
use Engelking\Webtrees\PortalApi\Http\Middleware\RequireProxySecret;
use Engelking\Webtrees\PortalApi\Http\RequestHandlers\CsrfTokenRead;
use Engelking\Webtrees\PortalApi\Http\RequestHandlers\IndividualRead;
use Engelking\Webtrees\PortalApi\Http\RequestHandlers\MeRead;
use Engelking\Webtrees\PortalApi\Http\RequestHandlers\MemberList;
use Engelking\Webtrees\PortalApi\Http\RequestHandlers\MemberRead;
use Engelking\Webtrees\PortalApi\Http\RequestHandlers\SessionCreate;
use Engelking\Webtrees\PortalApi\Http\RequestHandlers\SessionDelete;
use Engelking\Webtrees\PortalApi\Services\LoginRateLimiter;
use Engelking\Webtrees\PortalApi\Services\MeAssembler;
use Engelking\Webtrees\PortalApi\Services\MemberService;
use Engelking\Webtrees\PortalApi\Services\PortalTreeService;
use Engelking\Webtrees\PortalApi\Services\RecordPresenter;
use Fisharebest\Webtrees\FlashMessages;
use Fisharebest\Webtrees\Http\ViewResponseTrait;
use Fisharebest\Webtrees\I18N;
use Fisharebest\Webtrees\Module\AbstractModule;
use Fisharebest\Webtrees\Module\ModuleConfigInterface;
use Fisharebest\Webtrees\Module\ModuleConfigTrait;
use Fisharebest\Webtrees\Module\ModuleCustomInterface;
use Fisharebest\Webtrees\Module\ModuleCustomTrait;
use Fisharebest\Webtrees\Registry;
use Fisharebest\Webtrees\Services\MigrationService;
use Fisharebest\Webtrees\Services\TreeService;
use Fisharebest\Webtrees\Services\UserService;
use Fisharebest\Webtrees\Validator;
use Fisharebest\Webtrees\View;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * A read-only JSON API for the member portal.
 *
 * Everything the portal needs lives here. webtrees core is not modified, and
 * webtrees' own /login page is left alone so that editors and administrators
 * keep a way in when the portal is broken.
 */
class PortalApiModule extends AbstractModule implements ModuleCustomInterface, ModuleConfigInterface
{
    use ModuleCustomTrait;
    use ModuleConfigTrait;
    use ViewResponseTrait;

    public const string CUSTOM_VERSION = '1.0.0';

    /** Bumped when src/Schema/MigrationN.php classes are added. */
    private const int SCHEMA_VERSION = 1;

    private const string SCHEMA_SETTING_NAME = 'PORTAL_API_SCHEMA_VERSION';

    /** Module settings. */
    public const string SETTING_TREE                = 'portal_tree';
    public const string SETTING_PROXY_SECRET        = 'proxy_secret';
    public const string SETTING_RATE_LIMIT_IP       = 'rate_limit_ip';
    public const string SETTING_RATE_LIMIT_USER     = 'rate_limit_user';
    public const string SETTING_RATE_LIMIT_WINDOW   = 'rate_limit_window';

    public const int DEFAULT_RATE_LIMIT_IP     = 30;
    public const int DEFAULT_RATE_LIMIT_USER   = 5;
    public const int DEFAULT_RATE_LIMIT_WINDOW = 900;

    /** The API is mounted here. The Cloudflare Pages Function proxies /api/* onto it. */
    private const string ROUTE_PREFIX = '/api/v1';

    public function title(): string
    {
        return I18N::translate('Member portal API');
    }

    public function description(): string
    {
        return I18N::translate('A read-only JSON API that serves the member portal.');
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
        View::registerNamespace($this->name(), $this->resourcesFolder() . 'views/');

        Registry::container()
            ->get(MigrationService::class)
            ->updateSchema('\\' . __NAMESPACE__ . '\\Schema', self::SCHEMA_SETTING_NAME, self::SCHEMA_VERSION);

        $this->registerServices();
        $this->registerRoutes();
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
        $presenter      = new RecordPresenter();
        $members        = new MemberService($user_service);
        $rate_limiter   = new LoginRateLimiter($this);
        $me             = new MeAssembler($portal_trees, $presenter, $members);

        $container->set(PortalTreeService::class, $portal_trees);
        $container->set(RecordPresenter::class, $presenter);
        $container->set(MemberService::class, $members);
        $container->set(LoginRateLimiter::class, $rate_limiter);
        $container->set(MeAssembler::class, $me);

        $container->set(ApiEnvelope::class, new ApiEnvelope());
        $container->set(RequireProxySecret::class, new RequireProxySecret($this));
        $container->set(RequireCsrfToken::class, new RequireCsrfToken());
        $container->set(RequireAuthentication::class, new RequireAuthentication());

        $container->set(CsrfTokenRead::class, new CsrfTokenRead());
        $container->set(SessionCreate::class, new SessionCreate($user_service, $rate_limiter, $me));
        $container->set(SessionDelete::class, new SessionDelete());
        $container->set(MeRead::class, new MeRead($me));
        $container->set(IndividualRead::class, new IndividualRead($portal_trees, $presenter));
        $container->set(MemberList::class, new MemberList($portal_trees, $presenter, $members));
        $container->set(MemberRead::class, new MemberRead($portal_trees, $presenter, $members));
    }

    private function registerRoutes(): void
    {
        $map = Registry::routeFactory()->routeMap();

        // Applied to every route below. Order matters: the envelope is
        // outermost, so that it can turn anything thrown further in into a
        // JSON error and stamp the no-store header onto every response.
        $public = [
            ApiEnvelope::class,
            RequireProxySecret::class,
        ];

        $unsafe = [
            ApiEnvelope::class,
            RequireProxySecret::class,
            RequireCsrfToken::class,
        ];

        $private = [
            ApiEnvelope::class,
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

        $map->get(MemberList::class, self::ROUTE_PREFIX . '/members', MemberList::class)
            ->extras(['middleware' => $private]);

        $map->get(MemberRead::class, self::ROUTE_PREFIX . '/members/{id}', MemberRead::class)
            ->tokens(['id' => '\d+'])
            ->extras(['middleware' => $private]);
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
            'proxy_secret'      => $this->getPreference(self::SETTING_PROXY_SECRET),
            'rate_limit_ip'     => $this->getPreference(self::SETTING_RATE_LIMIT_IP, (string) self::DEFAULT_RATE_LIMIT_IP),
            'rate_limit_user'   => $this->getPreference(self::SETTING_RATE_LIMIT_USER, (string) self::DEFAULT_RATE_LIMIT_USER),
            'rate_limit_window' => $this->getPreference(self::SETTING_RATE_LIMIT_WINDOW, (string) self::DEFAULT_RATE_LIMIT_WINDOW),
        ]);
    }

    public function postAdminAction(ServerRequestInterface $request): ResponseInterface
    {
        $body = Validator::parsedBody($request);

        $this->setPreference(self::SETTING_TREE, $body->string(self::SETTING_TREE, ''));
        $this->setPreference(self::SETTING_PROXY_SECRET, trim($body->string(self::SETTING_PROXY_SECRET, '')));
        $this->setPreference(self::SETTING_RATE_LIMIT_IP, (string) max(0, $body->integer(self::SETTING_RATE_LIMIT_IP, self::DEFAULT_RATE_LIMIT_IP)));
        $this->setPreference(self::SETTING_RATE_LIMIT_USER, (string) max(0, $body->integer(self::SETTING_RATE_LIMIT_USER, self::DEFAULT_RATE_LIMIT_USER)));
        $this->setPreference(self::SETTING_RATE_LIMIT_WINDOW, (string) max(60, $body->integer(self::SETTING_RATE_LIMIT_WINDOW, self::DEFAULT_RATE_LIMIT_WINDOW)));

        FlashMessages::addMessage(I18N::translate('The preferences for the module “%s” have been updated.', $this->title()), 'success');

        return redirect($this->getConfigLink());
    }
}
