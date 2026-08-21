<?php

declare(strict_types=1);

namespace Engelking\Webtrees\PortalApi\Tests;

use Engelking\Webtrees\PortalApi\Http\RequestHandlers\IndividualLink;
use Engelking\Webtrees\PortalApi\Http\RequestHandlers\IndividualRead;
use Fisharebest\Webtrees\Auth;
use Fisharebest\Webtrees\Contracts\UserInterface;
use Fisharebest\Webtrees\Http\Middleware\Router;
use Fisharebest\Webtrees\Http\RequestHandlers\IndividualPage;
use Fisharebest\Webtrees\Http\RequestHandlers\LoginPage;
use Fisharebest\Webtrees\Registry;
use Fisharebest\Webtrees\User;
use Fisharebest\Webtrees\Validator;
use Fig\Http\Message\RequestMethodInterface;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;

use function html_entity_decode;
use function parse_str;
use function preg_match;
use function parse_url;
use function route;

/**
 * The way from the portal into webtrees.
 *
 * The portal and webtrees are separate origins — the session cookie is
 * first-party to the portal and does not travel — so a member following a
 * link out is always a signed-out visitor on the other side, and an editor
 * reading in two tabs may well be signed in. Both have to land on the person
 * they clicked.
 *
 * Neither of the two obvious links manages that on its own, which is what
 * these tests are really about.
 */
#[CoversNothing]
class LinkTest extends PortalTestCase
{
    private User $anna;

    protected function setUp(): void
    {
        parent::setUp();

        $this->anna = $this->createUser('anna', 'Anna Beispiel', 'correct-horse', UserInterface::ROLE_MEMBER, 'X1');
    }

    private function follow(string $xref = 'X1'): ResponseInterface
    {
        return $this->api(IndividualLink::class, attributes: ['xref' => $xref]);
    }

    private function location(ResponseInterface $response): string
    {
        return $response->getHeaderLine('Location');
    }

    /** The query parameters of a URL, as an array. */
    private function query(string $url): array
    {
        parse_str(parse_url($url, PHP_URL_QUERY) ?? '', $parameters);

        return $parameters;
    }

    // -----------------------------------------------------------------
    // Signed out: the login page, carrying the destination
    // -----------------------------------------------------------------

    /**
     * The bug this route shipped with, and the reason the other tests here
     * missed it for a day.
     *
     * A family portal's tree almost always has `REQUIRE_AUTHENTICATION` on —
     * that is the point of it. And `TreeService::all()` is filtered by the
     * current user: for a **visitor** on such a tree the collection is empty,
     * so `PortalTreeService::tree()` reported the configured tree as missing
     * and this handler fell back to the home page. webtrees then sent the
     * visitor from the home page to the login page with no destination at
     * all, and signing in landed them on their own user page. Which is exactly
     * what was reported: `?route=/login&url=` and then `/my-page`.
     *
     * Every other test in this file passes with the bug present, because the
     * fixture tree is public and `all()` therefore returns it to a visitor
     * too. The tree setting is the whole test.
     */
    public function testAVisitorReachesSignInOnATreeThatRequiresAuthentication(): void
    {
        $this->tree->setPreference('REQUIRE_AUTHENTICATION', '1');

        Auth::logout();

        $location = $this->location($this->follow());

        self::assertSame(
            route(LoginPage::class, [
                'tree' => $this->tree->name(),
                'url'  => route(IndividualPage::class, ['tree' => $this->tree->name(), 'xref' => 'X1']),
            ]),
            $location,
            'A visitor was not offered the sign-in page with the record to come back to.'
        );

        self::assertStringContainsString('X1', $location, 'The destination was lost on the way to sign-in.');
    }

    public function testAVisitorIsSentToSignInAndKeepsTheirDestination(): void
    {
        Auth::logout();

        $response = $this->follow();

        self::assertSame(302, $response->getStatusCode());

        self::assertSame(
            route(LoginPage::class, [
                'tree' => $this->tree->name(),
                'url'  => route(IndividualPage::class, ['tree' => $this->tree->name(), 'xref' => 'X1']),
            ]),
            $this->location($response)
        );

        // Spelled out as well as compared, so that a change to either side of
        // that equality still has to explain itself: it is webtrees' login
        // page, and the person they clicked on is the destination it carries.
        $query = $this->query($this->location($response));

        self::assertStringContainsString('/login', $query['route'] ?? '');
        self::assertStringContainsString('X1', $query['url'] ?? '');
    }

    /**
     * The regression this route exists for.
     *
     * `LoginPage` runs its `url` through `Validator::isLocalUrl()`, which
     * compares scheme, host, port and path prefix against `base_url` and
     * silently falls back to the front page when any of them differ. Because
     * the destination here is built with `route()` — from that same
     * `base_url` — it cannot fail that check. This asserts it with webtrees'
     * own validator rather than by eye.
     */
    public function testTheDestinationSurvivesWebtreesOwnLocalUrlCheck(): void
    {
        Auth::logout();

        $url = $this->query($this->location($this->follow()))['url'] ?? '';

        $request = self::createRequest()->withQueryParams(['url' => $url]);

        self::assertNotSame('', $url);
        self::assertSame($url, Validator::queryParams($request)->isLocalUrl()->string('url', ''));
    }

    // -----------------------------------------------------------------
    // Signed in: straight there
    // -----------------------------------------------------------------

    /**
     * The case the login link gets wrong: `LoginPage` answers an
     * authenticated request by redirecting to the reader's own user page and
     * discarding `url` altogether, so somebody already signed in is thrown to
     * a page they did not ask for.
     */
    public function testSomebodyAlreadySignedInGoesStraightToTheRecord(): void
    {
        $this->login($this->anna);

        $response = $this->follow();

        self::assertSame(302, $response->getStatusCode());
        self::assertSame(
            route(IndividualPage::class, ['tree' => $this->tree->name(), 'xref' => 'X1']),
            $this->location($response)
        );
    }

    // -----------------------------------------------------------------
    // What it will not do
    // -----------------------------------------------------------------

    /**
     * It takes an XREF, never a URL. There is nothing to point at another
     * site, whatever the query string says.
     */
    public function testItCannotBeTalkedIntoRedirectingElsewhere(): void
    {
        Auth::logout();

        $response = $this->api(
            IndividualLink::class,
            query: ['url' => 'https://evil.example/', 'route' => 'https://evil.example/'],
            attributes: ['xref' => 'X1'],
        );

        self::assertStringNotContainsString('evil.example', $this->location($response));
    }

    /**
     * It grants nothing: the record page enforces its own privacy on arrival,
     * exactly as it does for an address typed by hand. So an XREF that the
     * reader may not see — or that does not exist — still redirects, and
     * webtrees answers. Anything else would make this route a way of asking
     * whether a record exists without being signed in.
     */
    public function testAnUnknownXrefIsNotAnOracle(): void
    {
        Auth::logout();

        $known   = $this->follow('X1');
        $unknown = $this->follow('X999');

        self::assertSame($known->getStatusCode(), $unknown->getStatusCode());
    }

    // -----------------------------------------------------------------
    // The whole way, in one test
    // -----------------------------------------------------------------

    /**
     * Click, sign in, arrive — through webtrees' real router, its real
     * middleware, its own login page and its own login handler.
     *
     * The other tests here check one hop each. This one is the only thing
     * that proves the hops fit together, and it is the claim a member would
     * make: *I was not signed in, I clicked a person, and I ended up on that
     * person.* Three things had to be right that no single-hop test sees —
     * that the router matches the route from an ugly URL, that webtrees' login
     * form carries the destination in a hidden field, and that `LoginAction`
     * redirects to it rather than to the front page.
     *
     * Two things the harness needs spelled out, both of which cost an hour of
     * a wrong diagnosis when they were missing. `doLogin()` refuses outright
     * when `$_COOKIE` is empty, so the browser has to look like one that
     * accepts cookies; and `CheckCsrf` runs after routing for *every* POST, so
     * the form's token has to be posted with it.
     */
    #[RunInSeparateProcess]
    public function testTheWholeWayFromTheLinkToTheRecord(): void
    {
        // A language, because webtrees' own login throws on an account
        // without one — `I18N::init('')` reaches `Locale::create('')`. That is
        // the same trap §2.26 records for messages, in webtrees' own sign-in.
        $this->anna->setPreference(UserInterface::PREF_LANGUAGE, 'de');

        // The setting a family portal actually runs with, and the one that
        // hid the bug above: with it off, a visitor can still see the tree in
        // `TreeService::all()` and the broken path never runs.
        $this->tree->setPreference('REQUIRE_AUTHENTICATION', '1');

        Auth::logout();

        $_COOKIE = ['WT_SESSION' => 'irrelevant, but not empty'];

        // 1. The visitor follows the link the portal drew.
        $clicked = $this->routed(RequestMethodInterface::METHOD_GET, '/portal/individual/X1');

        self::assertSame(302, $clicked->getStatusCode());

        $parameters = $this->query($this->location($clicked));
        $login      = $parameters['route'];

        unset($parameters['route']);

        // 2. webtrees' login page, which must carry the destination onward.
        $form = $this->routed(RequestMethodInterface::METHOD_GET, $login, $parameters);
        $form->getBody()->rewind();
        $html = $form->getBody()->getContents();

        self::assertSame(200, $form->getStatusCode());
        self::assertMatchesRegularExpression('/name="url" value="[^"]*X1[^"]*"/', $html);

        // 3. Signing in.
        $signed_in = $this->routed(RequestMethodInterface::METHOD_POST, $login, [], [
            'username' => 'anna',
            'password' => 'correct-horse',
            'url'      => $this->field($html, 'url'),
            '_csrf'    => $this->field($html, '_csrf'),
        ]);

        self::assertSame(
            route(IndividualPage::class, ['tree' => $this->tree->name(), 'xref' => 'X1']),
            $this->location($signed_in),
            'Signing in landed somewhere other than the person that was clicked.'
        );
    }

    /** Dispatch through webtrees' own router, as an ugly URL would arrive. */
    private function routed(string $method, string $path, array $query = [], array $post = []): ResponseInterface
    {
        $request = self::createRequest($method, ['route' => $path] + $query, $post);

        Registry::container()->set(ServerRequestInterface::class, $request);

        $not_found = new class implements RequestHandlerInterface {
            public function handle(ServerRequestInterface $request): ResponseInterface
            {
                return Registry::responseFactory()->response('no route matched', 404);
            }
        };

        return Registry::container()->get(Router::class)->process($request, $not_found);
    }

    private function field(string $html, string $name): string
    {
        preg_match('/name="' . $name . '" value="([^"]*)"/', $html, $matches);

        return html_entity_decode($matches[1] ?? '');
    }

    // -----------------------------------------------------------------
    // The portal points at it
    // -----------------------------------------------------------------

    public function testTheApiHandsOutThisLinkRatherThanTheBareRecordAddress(): void
    {
        $this->login($this->anna);

        $body = $this->json($this->api(IndividualRead::class, attributes: ['xref' => 'X1']));

        self::assertSame(route(IndividualLink::class, ['xref' => 'X1']), $body['webtrees_url']);
    }
}
