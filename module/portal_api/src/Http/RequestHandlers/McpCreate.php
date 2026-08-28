<?php

declare(strict_types=1);

namespace Engelking\Webtrees\PortalApi\Http\RequestHandlers;

use Engelking\Webtrees\PortalApi\Mcp\McpException;
use Engelking\Webtrees\PortalApi\Mcp\Server;
use Fig\Http\Message\StatusCodeInterface;
use Fisharebest\Webtrees\Registry;
use JsonException;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;

use function array_is_list;
use function is_array;
use function json_decode;

use const JSON_THROW_ON_ERROR;

/**
 * POST /api/mcp — the whole of the Model Context Protocol endpoint.
 *
 * **Why it is not under `/api/v1`.** Everything there is the contract in
 * `openapi.yaml`: REST, versioned by this portal, spoken by this portal's own
 * client and nothing else. This is a different protocol with a version of its
 * own, negotiated per connection, and its callers are other people's programs.
 * Putting it beside the portal's endpoints would suggest the two versions move
 * together, and they do not. It still begins `/api/`, which is what the
 * Cloudflare Worker proxies, so an assistant reaches it at the portal's own
 * address like everything else.
 *
 * **The status codes.** A message this server could not parse or could not
 * recognise as JSON-RPC is answered 400 with a JSON-RPC error in the body,
 * which is what the transport specification asks for. Everything after that —
 * an unknown method, bad arguments, a tool that found nothing — is a
 * successfully handled request and comes back 200, with the failure described
 * inside the body where the client, or the model, can act on it. A
 * notification, which has no id, is answered 202 with nothing in it.
 *
 * **`MCP-Protocol-Version` is read and not enforced.** The specification lets a
 * server refuse a header naming a revision it does not implement. This one does
 * not, deliberately: it is stateless and tools-only, it behaves identically
 * under every revision it has been shown, and refusing a client for announcing
 * a newer number than this file knows about would break it for no benefit to
 * anybody. The version that governs the conversation is the one agreed in
 * `initialize`.
 */
class McpCreate implements RequestHandlerInterface
{
    public function __construct(private readonly Server $server)
    {
    }

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        try {
            $message = $this->decode($request);
        } catch (McpException $exception) {
            return $this->json(
                ['jsonrpc' => '2.0', 'id' => null, 'error' => ['code' => $exception->rpc_code, 'message' => $exception->getMessage()]],
                StatusCodeInterface::STATUS_BAD_REQUEST,
            );
        }

        // A batch, which the 2025-03-26 revision allowed and the one after it
        // removed. Answered rather than refused: a client that sends one is
        // asking for the same work in one request, and the answers are the
        // same answers.
        if (is_array($message) && $message !== [] && array_is_list($message)) {
            $answers = [];

            foreach ($message as $one) {
                $answer = $this->server->handle($one);

                if ($answer !== null) {
                    $answers[] = $answer;
                }
            }

            return $answers === [] ? $this->accepted() : $this->json($answers);
        }

        $answer = $this->server->handle($message);

        return $answer === null ? $this->accepted() : $this->json($answer);
    }

    /**
     * The body, as JSON.
     *
     * Read from the stream rather than through `Json::body()`, because that
     * one insists on an object and a JSON-RPC batch is a list — and because a
     * body this endpoint cannot parse has to become a JSON-RPC parse error
     * rather than the portal's own `bad_request`.
     */
    private function decode(ServerRequestInterface $request): mixed
    {
        $stream = $request->getBody();

        if ($stream->isSeekable()) {
            $stream->rewind();
        }

        $raw = $stream->getContents();

        if ($raw === '') {
            throw McpException::invalidRequest('The request body is empty.');
        }

        try {
            return json_decode($raw, true, 64, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            throw McpException::parseError();
        }
    }

    /**
     * @param array<mixed> $payload
     */
    private function json(array $payload, int $status = StatusCodeInterface::STATUS_OK): ResponseInterface
    {
        return Registry::responseFactory()->response($payload, $status);
    }

    /**
     * A notification: accepted, nothing to say about it.
     *
     * The content type is set explicitly because the response factory reads an
     * empty body as HTML, and a client that has been promised JSON should not
     * be told otherwise even when there is none.
     */
    private function accepted(): ResponseInterface
    {
        return Registry::responseFactory()->response(
            '',
            StatusCodeInterface::STATUS_ACCEPTED,
            ['content-type' => 'application/json'],
        );
    }
}
