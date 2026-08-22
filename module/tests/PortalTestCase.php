<?php

declare(strict_types=1);

namespace Engelking\Webtrees\PortalApi\Tests;

use Aura\Router\Route;
use Aura\Router\RouterContainer;
use Engelking\Webtrees\PortalApi\PortalApiModule;
use Engelking\Webtrees\PortalApi\Services\MemberService;
use Fig\Http\Message\RequestMethodInterface;
use Fisharebest\Webtrees\Auth;
use Fisharebest\Webtrees\Contracts\UserInterface;
use Fisharebest\Webtrees\DB;
use Fisharebest\Webtrees\Http\Middleware\RequestHandler;
use Fisharebest\Webtrees\Http\RequestHandlers\GedcomLoad;
use Fisharebest\Webtrees\Registry;
use Fisharebest\Webtrees\Services\GedcomImportService;
use Fisharebest\Webtrees\Services\ModuleService;
use Fisharebest\Webtrees\Services\TimeoutService;
use Fisharebest\Webtrees\Services\TreeService;
use Fisharebest\Webtrees\Services\UserService;
use Fisharebest\Webtrees\Session;
use Fisharebest\Webtrees\TestCase;
use Fisharebest\Webtrees\Tree;
use Fisharebest\Webtrees\User;
use Middleland\Dispatcher;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Message\StreamFactoryInterface;
use ReflectionProperty;

use function json_decode;
use function json_encode;
use function preg_replace;
use function time;

use const JSON_THROW_ON_ERROR;

/**
 * Boots a real webtrees with the module installed, and dispatches requests
 * through the module's own routes and middleware.
 *
 * The point of going through the route table rather than calling handlers
 * directly is that the middleware — authentication, CSRF, the no-store header
 * — is part of what these tests are asserting about. A handler that returns
 * the right JSON behind middleware that lets anyone in is not correct.
 */
abstract class PortalTestCase extends TestCase
{
    protected static bool $uses_database = true;

    protected Tree $tree;

    protected function setUp(): void
    {
        parent::setUp();

        $this->correctTheRouterBasePath();

        $this->tree = $this->importPortalTree();

        // The module serves exactly one tree; point it at this one.
        $this->module()->setPreference(PortalApiModule::SETTING_TREE, $this->tree->name());

        // Privacy settings the fixture assumes. These are webtrees' own
        // defaults spelled out, so that a change of default does not silently
        // change what these tests prove.
        $this->tree->setPreference('HIDE_LIVE_PEOPLE', '1');
        $this->tree->setPreference('SHOW_DEAD_PEOPLE', '2');
        $this->tree->setPreference('SHOW_LIVING_NAMES', '1');
        $this->tree->setPreference('KEEP_ALIVE_YEARS_BIRTH', '0');
        $this->tree->setPreference('KEEP_ALIVE_YEARS_DEATH', '0');
    }

    protected function module(): PortalApiModule
    {
        $module = Registry::container()->get(ModuleService::class)->findByName('_portal_api_');

        self::assertInstanceOf(PortalApiModule::class, $module, 'The portal_api module is not installed in the webtrees checkout under test.');

        return $module;
    }

    // -----------------------------------------------------------------
    // Fixtures
    // -----------------------------------------------------------------

    private function importPortalTree(): Tree
    {
        $gedcom_import_service = new GedcomImportService();
        $tree_service          = new TreeService($gedcom_import_service);
        $tree                  = $tree_service->create('portal', 'Portal test tree');
        $stream                = Registry::container()
            ->get(StreamFactoryInterface::class)
            ->createStreamFromFile(__DIR__ . '/data/portal.ged');

        $tree_service->importGedcomFile($tree, $stream, 'portal.ged', '');

        $controller = new GedcomLoad($gedcom_import_service, new TimeoutService());
        $request    = self::createRequest()->withAttribute('tree', $tree);

        do {
            $controller->handle($request);
        } while (!$tree->getPreference('imported'));

        return $tree;
    }

    /**
     * A webtrees user account that can sign in to the portal.
     */
    protected function createUser(string $username, string $real_name, string $password, string $role, string $xref = ''): User
    {
        $user = Registry::container()
            ->get(UserService::class)
            ->create($username, $real_name, $username . '@example.test', $password);

        $user->setPreference(UserInterface::PREF_IS_EMAIL_VERIFIED, '1');
        $user->setPreference(UserInterface::PREF_IS_ACCOUNT_APPROVED, '1');

        $this->tree->setUserPreference($user, UserInterface::PREF_TREE_ROLE, $role);

        if ($xref !== '') {
            $this->tree->setUserPreference($user, UserInterface::PREF_TREE_ACCOUNT_XREF, $xref);
        }

        return $user;
    }

    protected function createProfile(User $user, bool $visible, string|null $display_name = null): int
    {
        DB::table(MemberService::TABLE)->insert([
            'wt_user_id'            => $user->id(),
            'visible_in_directory'  => $visible ? 1 : 0,
            'display_name_override' => $display_name,
            'consent_recorded_at'   => $visible ? '2026-01-01 00:00:00' : null,
            'created_at'            => '2026-01-01 00:00:00',
            'updated_at'            => '2026-01-01 00:00:00',
        ]);

        return (int) DB::lastInsertId();
    }

    protected function login(User $user): void
    {
        Auth::login($user);
        $user->setPreference(UserInterface::PREF_TIMESTAMP_ACTIVE, (string) time());
    }

    // -----------------------------------------------------------------
    // Dispatching
    // -----------------------------------------------------------------

    /**
     * Make `route()` in the harness produce what it produces in production.
     *
     * webtrees' own `TestCase` builds its router with a base path of `'/'`.
     * Production builds it with `parse_url($base_url, PHP_URL_PATH)`, which is
     * `null` for an installation at a domain root — and the harness's
     * `base_url` *is* a domain root, `https://webtrees.test`.
     *
     * Aura prefixes that base path to every generated path, so `/login/portal`
     * comes out as `//login/portal`. That is a protocol-relative URL:
     * `parse_url()` reads `login` as the **host** and `/portal` as the path,
     * and webtrees' `route()` builds its ugly URL from that path. Every
     * generated URL in the harness silently loses its first segment.
     *
     * Nothing noticed until this module generated a URL and looked at it.
     * Left in place it would make the `IndividualLink` tests prove something
     * other than what they claim, so the base path is corrected here rather
     * than worked around in each assertion. The generator goes with it,
     * because Aura builds it once and then caches it.
     */
    private function correctTheRouterBasePath(): void
    {
        $router = Registry::container()->get(RouterContainer::class);

        foreach (['basepath', 'generator'] as $property) {
            (new ReflectionProperty($router, $property))->setValue($router, null);
        }
    }

    /**
     * Send a request through the module's real route, with its real middleware.
     *
     * @param class-string        $route_name
     * @param array<string,mixed> $query
     * @param array<string,mixed> $attributes  Route tokens, e.g. ['xref' => 'X1'].
     * @param array<string,mixed> $body        JSON request body.
     * @param array<string,string> $headers
     */
    protected function api(
        string $route_name,
        string $method = RequestMethodInterface::METHOD_GET,
        array $query = [],
        array $attributes = [],
        array|null $body = null,
        array $headers = []
    ): ResponseInterface {
        $route = Registry::routeFactory()->routeMap()->getRoute($route_name);

        $request = self::createRequest($method, $query)
            ->withAttribute('route', $route)
            ->withAttribute('client-ip', '203.0.113.7');

        foreach ($attributes as $key => $value) {
            $request = $request->withAttribute($key, $value);
        }

        foreach ($headers as $name => $value) {
            $request = $request->withHeader($name, $value);
        }

        if ($body !== null) {
            $stream = Registry::container()
                ->get(StreamFactoryInterface::class)
                ->createStream(json_encode($body, JSON_THROW_ON_ERROR));

            // Mirror a real JSON request: a body that core's form-decoding
            // middleware would not have parsed.
            $request = $request->withParsedBody(null)->withBody($stream);
        }

        Registry::container()->set(ServerRequestInterface::class, $request);

        $middleware = [...$route->extras['middleware'], RequestHandler::class];

        return (new Dispatcher($middleware, Registry::container()))->dispatch($request);
    }

    /**
     * @return array<string,mixed>
     */
    protected function json(ResponseInterface $response): array
    {
        $response->getBody()->rewind();

        return json_decode($response->getBody()->getContents(), true, 32, JSON_THROW_ON_ERROR);
    }

    /**
     * The whole response as a string — used to assert that an XREF appears
     * nowhere at all, including in places a structured assertion would miss.
     */
    protected function raw(ResponseInterface $response): string
    {
        $response->getBody()->rewind();

        return $response->getBody()->getContents();
    }

    /**
     * The whole response as a string, minus the CSRF token.
     *
     * `raw()` exists so that a test can assert an XREF appears *nowhere* —
     * including places a structured assertion would miss. The token is thirty
     * random characters in the middle of that string, so roughly one run in a
     * hundred contains "X3" or any other two-character needle by chance, and
     * the test that fails is never the one that is broken.
     *
     * It is a credential rather than payload, so removing it takes nothing
     * away from what these assertions are for.
     */
    protected function rawWithoutCsrfToken(ResponseInterface $response): string
    {
        return (string) preg_replace('/"csrf_token":"[^"]*"/', '"csrf_token":""', $this->raw($response));
    }

    protected function csrfHeader(): array
    {
        return ['X-CSRF-TOKEN' => Session::getCsrfToken()];
    }
}
