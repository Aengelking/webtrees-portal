<?php

declare(strict_types=1);

namespace Engelking\Webtrees\PortalApi\Http\RequestHandlers;

use Engelking\Webtrees\PortalApi\Services\PortalTreeService;
use Fisharebest\Webtrees\Auth;
use Fisharebest\Webtrees\Http\RequestHandlers\HomePage;
use Fisharebest\Webtrees\Http\RequestHandlers\IndividualPage;
use Fisharebest\Webtrees\Http\RequestHandlers\LoginPage;
use Fisharebest\Webtrees\Validator;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;

use function redirect;
use function route;

/**
 * GET /portal/individual/{xref} — the way from the portal into webtrees.
 *
 * A browser-facing redirect, not an API endpoint. It exists because the two
 * obvious links each work in exactly one of the two states a visitor can be
 * in, and there is no way to know which state they are in before they click.
 *
 * **Linking straight at the record** works when the reader already has a
 * webtrees session. Without one, what happens depends on the tree's settings:
 * on a tree that requires authentication webtrees redirects to its login page
 * carrying the address as `url` — but that address has to survive
 * `Validator::isLocalUrl()`, which compares scheme, host, port and path prefix
 * against `base_url`, and behind a proxy or a misconfigured `base_url` it
 * silently loses. On a tree that does not require authentication there is no
 * login prompt at all: the visitor is simply told the record is not there.
 * Either way they end up somewhere other than the person they clicked on.
 *
 * **Linking at the login page** with `url` set works when the reader is
 * signed out — and does the wrong thing when they are not: `LoginPage`
 * answers an authenticated request with a redirect to their own user page,
 * discarding `url` entirely. So a reader who is already signed in gets thrown
 * to a page they did not ask for.
 *
 * This route asks the question at the moment it can be answered.
 *
 * Two things make it safe. It takes an XREF and never a URL, so it cannot be
 * pointed at another site; and it builds the target with `route()`, from the
 * same `base_url` that `isLocalUrl()` validates against, so the address it
 * hands to the login page cannot fail that check.
 *
 * It grants nothing. The record page enforces its own privacy on arrival,
 * exactly as it does for an address typed by hand.
 */
class IndividualLink implements RequestHandlerInterface
{
    public function __construct(private readonly PortalTreeService $trees)
    {
    }

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        // Deliberately not `PortalTreeService::tree()`, which resolves through
        // an access-filtered list and therefore reports the tree as missing
        // for exactly the reader this route exists for. See
        // `configuredTreeName()` for the whole of that story.
        $tree = $this->trees->configuredTreeName();

        if ($tree === '') {
            // Nothing configured at all. Not this route's problem to explain —
            // the Diagnosis screen says it properly — but a link that
            // dead-ends in a stack trace is worse than one that lands on the
            // front page.
            return redirect(route(HomePage::class));
        }

        $target = route(IndividualPage::class, [
            'tree' => $tree,
            'xref' => Validator::attributes($request)->string('xref', ''),
        ]);

        if (Auth::check()) {
            return redirect($target);
        }

        return redirect(route(LoginPage::class, ['tree' => $tree, 'url' => $target]));
    }
}
