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
use Fisharebest\Webtrees\Factories\CacheFactory;
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
use Fisharebest\Webtrees\Http\Dispatcher as WebtreesDispatcher;
use Middleland\Dispatcher as MiddlelandDispatcher;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Message\StreamFactoryInterface;
use ReflectionProperty;

use function json_decode;
use function json_encode;
use function preg_replace;
use function class_exists;
use function ini_set;
use function sys_get_temp_dir;
use function property_exists;
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

        // Again here, not only in the bootstrap: webtrees' own `TestCase`
        // re-bootstraps the program for every test, and PHPUnit restores ini
        // settings around each one. The module's diagnostics belong in a file
        // either way — on the runner's output they are counted as unexpected
        // output, and this suite fails on risky tests by design.
        ini_set('error_log', sys_get_temp_dir() . '/portal_api-tests.log');

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

    /**
     * Import the fixture the way a real installation imports a GEDCOM.
     *
     * **As an administrator**, and that is not a detail. webtrees builds the
     * `name` search index with `Individual::getAllNames()`, which is privacy
     * filtered: with nobody signed in, every living person is indexed under
     * the literal name "Private" and can never be found by searching. In
     * production the import is done by whoever is signed in to the control
     * panel, so the index holds real names — and a harness that imports as
     * nobody quietly builds a different database from the one under test.
     *
     * The account is dropped again afterwards, so no test inherits a session
     * or an extra user it did not ask for.
     */
    private function importPortalTree(): Tree
    {
        $importer = Registry::container()
            ->get(UserService::class)
            ->create('fixture-import', 'Fixture import', 'fixture-import@example.test', 'geheim');

        $importer->setPreference(UserInterface::PREF_IS_ADMINISTRATOR, '1');

        Auth::login($importer);

        try {
            return $this->loadPortalTree();
        } finally {
            Auth::logout();
            Registry::container()->get(UserService::class)->delete($importer);
        }
    }

    private function loadPortalTree(): Tree
    {
        // Resolved from the container rather than constructed, because their
        // constructors are not this harness's business: `TimeoutService` grew
        // a `PhpService` argument in webtrees 2.2.6, and a `new` here pins the
        // suite to one release of a program the module has to work across.
        $container             = Registry::container();
        $gedcom_import_service = $container->get(GedcomImportService::class);
        $tree_service          = $container->get(TreeService::class);
        $tree                  = $tree_service->create('portal', 'Portal test tree');
        $stream                = $container
            ->get(StreamFactoryInterface::class)
            ->createStreamFromFile(__DIR__ . '/data/portal.ged');

        $tree_service->importGedcomFile($tree, $stream, 'portal.ged', '');

        $controller = new GedcomLoad($gedcom_import_service, $container->get(TimeoutService::class));
        $request    = self::createRequest()->withAttribute('tree', $tree);

        do {
            $controller->handle($request);
        } while (!$this->imported($tree));

        return $tree;
    }

    /**
     * Has the import finished?
     *
     * Asked of the database rather than of the `Tree`, and that is not
     * fussiness. In 2.2.6 the flag moved out of `gedcom_setting` into a
     * column of `gedcom`, and the object's copy of it is fixed at
     * construction — so a tree object made before the import says "not
     * imported" forever, and this loop would never end.
     */
    private function imported(Tree $tree): bool
    {
        $row = DB::table('gedcom')->where('gedcom_id', '=', $tree->id())->first();

        if ($row !== null && property_exists($row, 'imported')) {
            return (bool) $row->imported;
        }

        return (string) DB::table('gedcom_setting')
            ->where('gedcom_id', '=', $tree->id())
            ->where('setting_name', '=', 'imported')
            ->value('setting_value') !== '';
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

    /**
     * Sign somebody in — which in these tests also means "and this is a new
     * request".
     *
     * The second half is the reason for the cache reset. webtrees caches
     * privacy answers in `Registry::cache()->array()` under a key of record,
     * tree and access level — **and not of user** (`GedcomRecord::canShow()`),
     * which is sound in production, where that cache lives and dies with one
     * request. Here the process outlives the sign-in, so a `true` computed for
     * the member looking at their own record is handed to the next member who
     * asks about it, at the same access level, and a test about privacy is
     * answered from somebody else's answer.
     *
     * Found while pinning that a confidential record does not travel with a
     * connection request: it did not, and the test said it did.
     */
    /**
     * Make the fixture tree the thing this portal is built for: members only.
     *
     * Written where this version of webtrees keeps it. In 2.2.6 the flag moved
     * out of `gedcom_setting` into a column of `gedcom`, and the compatibility
     * shim left behind both raises a notice — which a test that turns notices
     * into failures cannot ignore — and writes to *every* tree in the table
     * rather than this one.
     */
    protected function requireAuthentication(): void
    {
        $row = DB::table('gedcom')->where('gedcom_id', '=', $this->tree->id())->first();

        if ($row !== null && property_exists($row, 'private')) {
            DB::table('gedcom')->where('gedcom_id', '=', $this->tree->id())->update(['private' => 1]);

            // And read it back, because in 2.2.6 a `Tree` is hydrated from its
            // row: the flag is a property of the object, not a lookup it makes
            // when asked. `$this->tree` was built before this update and would
            // go on answering `private() === false` for the rest of the test —
            // which is a test asserting the opposite of what it set up, and
            // passes only where nothing looks at the object. Anything that
            // goes through the API re-resolves the tree per request and never
            // noticed; anything handed `$this->tree` directly did.
            $this->tree = Tree::fromDB(
                DB::table('gedcom')->where('gedcom_id', '=', $this->tree->id())->first()
            );

            return;
        }

        // 2.2.5 and earlier read their preferences lazily, so the object in
        // hand picks this up on its own.
        $this->tree->setPreference('REQUIRE_AUTHENTICATION', '1');
    }

    protected function login(User $user): void
    {
        Registry::cache(new CacheFactory());

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
     * @param array<string,mixed>  $files       Uploads, as PSR-7 sees them.
     * @param array<string,string> $cookies     What the browser is offering.
     */
    protected function api(
        string $route_name,
        string $method = RequestMethodInterface::METHOD_GET,
        array $query = [],
        array $attributes = [],
        array|null $body = null,
        array $headers = [],
        array $files = [],
        array $cookies = []
    ): ResponseInterface {
        $route = Registry::routeFactory()->routeMap()->getRoute($route_name);

        // A request in production gets a fresh array cache, and
        // `TreeService::all()` caches the tree list in it **filtered by
        // whoever is asking**. Keeping one cache across the several requests
        // a test makes would answer a visitor out of an administrator's list
        // — and the fixture is imported by an administrator, so that is
        // precisely what it would do. The invitation endpoints run for a
        // visitor, so this is the difference between testing them and testing
        // something else.
        Registry::cache()->array()->forget('all-trees');

        $request = self::createRequest($method, $query)
            ->withAttribute('route', $route)
            ->withAttribute('client-ip', '203.0.113.7');

        foreach ($attributes as $key => $value) {
            $request = $request->withAttribute($key, $value);
        }

        foreach ($headers as $name => $value) {
            $request = $request->withHeader($name, $value);
        }

        if ($cookies !== []) {
            // A real server request has these parsed for it. Set rather than
            // merged: a test says what the browser is offering, in full.
            $request = $request->withCookieParams($cookies);
        }

        if ($body !== null) {
            $stream = Registry::container()
                ->get(StreamFactoryInterface::class)
                ->createStream(json_encode($body, JSON_THROW_ON_ERROR));

            // Mirror a real JSON request: a body that core's form-decoding
            // middleware would not have parsed.
            $request = $request->withParsedBody(null)->withBody($stream);
        }

        if ($files !== []) {
            $request = $request->withUploadedFiles($files);
        }

        Registry::container()->set(ServerRequestInterface::class, $request);

        $middleware = [...$route->extras['middleware'], RequestHandler::class];

        // Whichever dispatcher this webtrees runs its own middleware through.
        // 2.2.6 dropped `oscarotero/middleland` for a static dispatcher of its
        // own, so a harness that names one of them is a harness that only
        // works on one side of that release.
        if (class_exists(WebtreesDispatcher::class)) {
            return WebtreesDispatcher::dispatch($middleware, $request);
        }

        return (new MiddlelandDispatcher($middleware, Registry::container()))->dispatch($request);
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
