<?php

declare(strict_types=1);

namespace Engelking\Webtrees\PortalApi\Tests;

use Engelking\Webtrees\PortalApi\Http\RequestHandlers\IndividualLink;
use Engelking\Webtrees\PortalApi\Http\RequestHandlers\IndividualRead;
use Fisharebest\Webtrees\Auth;
use Fisharebest\Webtrees\Contracts\UserInterface;
use Fisharebest\Webtrees\Http\RequestHandlers\IndividualPage;
use Fisharebest\Webtrees\Http\RequestHandlers\LoginPage;
use Fisharebest\Webtrees\User;
use Fisharebest\Webtrees\Validator;
use PHPUnit\Framework\Attributes\CoversNothing;
use Psr\Http\Message\ResponseInterface;

use function parse_str;
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
    // The portal points at it
    // -----------------------------------------------------------------

    public function testTheApiHandsOutThisLinkRatherThanTheBareRecordAddress(): void
    {
        $this->login($this->anna);

        $body = $this->json($this->api(IndividualRead::class, attributes: ['xref' => 'X1']));

        self::assertSame(route(IndividualLink::class, ['xref' => 'X1']), $body['webtrees_url']);
    }
}
