<?php

declare(strict_types=1);

namespace Engelking\Webtrees\PortalApi\Http\Middleware;

use Engelking\Webtrees\PortalApi\Http\ApiException;
use Engelking\Webtrees\PortalApi\Http\Json;
use Engelking\Webtrees\PortalApi\Mcp\Server;
use Engelking\Webtrees\PortalApi\PortalApiModule;
use Engelking\Webtrees\PortalApi\Services\McpTokens;
use Fisharebest\Webtrees\Auth;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;

use function preg_match;

/**
 * The lock on the MCP endpoint.
 *
 * Nothing else in this module authenticates this way, and the reason is that
 * nothing else is opened by a program. A member has a browser, so the portal
 * can use a session cookie and a CSRF token and all the machinery that assumes
 * somebody is sitting there. An assistant has a configuration file, so it
 * carries a bearer token — `Authorization: Bearer wtmcp_…` — on every request
 * and holds no session at all.
 *
 * **This is not a second permission system.** The token says which webtrees
 * account to read as; `Auth::login()` makes that account the signed-in user
 * for the rest of the request, and from there every record goes through
 * webtrees' own privacy code at that account's access level, exactly as it
 * would for a person. The MCP server then narrows *that* to the dead — see
 * `Services\DeceasedOnly`. Three gates, in that order, and this middleware is
 * only the first.
 *
 * **The proxy secret still applies**, because this sits behind it in the
 * chain. An assistant is pointed at the portal's own address, the Cloudflare
 * Worker forwards the `Authorization` header untouched and adds the secret on
 * the way through, so the MCP server is reachable exactly where the portal is
 * and nowhere else. There is no CSRF check and there should not be: a CSRF
 * token defends a browser that carries a cookie automatically, and there is no
 * browser and no cookie here.
 *
 * **A session is created and immediately abandoned.** `Auth::login()` writes
 * to webtrees' session, so each MCP request leaves one short-lived session
 * behind for webtrees' own garbage collection to clear. That is the price of
 * using webtrees' privacy code rather than reimplementing it, and it is the
 * right way round: a second opinion about who may see what is the one thing
 * this module must never grow.
 */
class RequireMcpToken implements MiddlewareInterface
{
    public function __construct(
        private readonly PortalApiModule $module,
        private readonly McpTokens $tokens,
    ) {
    }

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        // Off unless an administrator switched it on. A portal that has never
        // heard of MCP should not have an endpoint that answers questions
        // about the family to whoever finds a token.
        if ($this->module->getPreference(PortalApiModule::SETTING_MCP, '0') !== '1') {
            throw ApiException::mcpDisabled();
        }

        $user = $this->tokens->authenticate($this->offered($request));

        if ($user === null) {
            // Built here rather than thrown, because a 401 has to carry a
            // `WWW-Authenticate` header saying what would have worked, and an
            // exception cannot carry a header. Unknown, expired, revoked and
            // absent are one answer, as everywhere else in this module.
            return Json::error(ApiException::unauthenticated())
                ->withHeader('WWW-Authenticate', 'Bearer realm="' . Server::NAME . '"');
        }

        // From here on this request *is* that account, as far as webtrees is
        // concerned. Nothing below this line knows a token was involved.
        Auth::login($user);

        return $handler->handle($request);
    }

    /**
     * The token the client is offering, or an empty string.
     *
     * `Bearer` only. The other schemes a client might reach for — Basic, an
     * `?access_token=` query parameter — are deliberately not accepted: the
     * first invites somebody to put a password where a token belongs, and the
     * second puts a credential in the webserver's access log.
     */
    private function offered(ServerRequestInterface $request): string
    {
        $header = $request->getHeaderLine('Authorization');

        if (preg_match('/^Bearer[ \t]+(\S+)$/i', $header, $match) === 1) {
            return $match[1];
        }

        return '';
    }
}
