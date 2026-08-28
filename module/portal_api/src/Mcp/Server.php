<?php

declare(strict_types=1);

namespace Engelking\Webtrees\PortalApi\Mcp;

use Engelking\Webtrees\PortalApi\PortalApiModule;
use stdClass;
use Throwable;

use function array_key_exists;
use function error_log;
use function in_array;
use function is_array;
use function is_int;
use function is_string;

/**
 * The Model Context Protocol, as much of it as a read-only server needs.
 *
 * MCP over HTTP is JSON-RPC 2.0 in a request body, and this class is the
 * dispatcher: one message in, one message out, or nothing at all when the
 * message was a notification. It knows five methods and refuses the rest.
 *
 * **Stateless, and that is a decision.** The transport allows a server to hand
 * out an `Mcp-Session-Id` and keep state behind it. This one does not, so
 * every request stands alone: it carries its own credential, is answered from
 * the database, and leaves nothing behind. That rules out server-initiated
 * messages — no subscriptions, no progress, no sampling — which is exactly
 * right for something whose whole job is to answer questions about people who
 * died a hundred years ago, and it means the server survives being restarted
 * mid-conversation without anybody noticing.
 *
 * **Version negotiation is the specification's own.** A client asks for a
 * protocol version; if it is one we speak, that is the answer, and if it is
 * not — a newer one we have not seen, or an older one we have dropped — the
 * answer names the newest we do speak and the client decides whether to go on.
 * Answering with a version we do not implement would be worse than refusing.
 *
 * **Capabilities are advertised honestly.** Tools, and nothing else. A client
 * that asks for resources or prompts anyway is told there is no such method,
 * which is the truth and is a thing clients handle; inventing empty lists for
 * them would make an unimplemented feature look implemented.
 */
final class Server
{
    /** The newest revision this server implements. */
    public const string LATEST_PROTOCOL = '2025-06-18';

    /**
     * Every revision it will agree to speak.
     *
     * All three describe the same handful of calls this server answers. What
     * changed between them — batching, the `MCP-Protocol-Version` header,
     * elicitation — is either not used here or handled the same way whichever
     * revision is in force.
     *
     * @var array<int,string>
     */
    public const array SUPPORTED_PROTOCOLS = ['2025-06-18', '2025-03-26', '2024-11-05'];

    /**
     * How the server names itself to a client.
     *
     * Not the family's name. It appears in the client's list of connected
     * servers, on somebody's laptop, next to whatever else they have
     * connected — a family name there would be the one piece of this whole
     * design that published something without being asked.
     */
    public const string NAME = 'webtrees-family-archive';

    public function __construct(
        private readonly ArchiveTools $tools,
        private readonly PortalApiModule $module,
    ) {
    }

    /**
     * Answer one JSON-RPC message.
     *
     * @param mixed $message As decoded from the body. Anything at all: this is
     *                       the first thing that has looked at it.
     *
     * @return array<string,mixed>|null null when the message was a
     *                                  notification, which is answered with an
     *                                  empty 202 rather than a body.
     */
    public function handle(mixed $message): array|null
    {
        if (!is_array($message) || $message === []) {
            return $this->failure(null, McpException::invalidRequest('A JSON-RPC message must be an object.'));
        }

        $id     = $this->id($message);
        $method = $message['method'] ?? null;

        if (($message['jsonrpc'] ?? null) !== '2.0' || !is_string($method) || $method === '') {
            return $this->failure($id, McpException::invalidRequest('A JSON-RPC message needs "jsonrpc": "2.0" and a "method".'));
        }

        // A notification has no id and gets no answer, whatever it says. The
        // one that matters is `notifications/initialized`; the rest — a
        // cancellation, a client telling us its roots changed — are things
        // this server has nothing to do about, and silence is the correct
        // response to all of them.
        if ($id === null) {
            return null;
        }

        $params = $message['params'] ?? [];

        if (!is_array($params)) {
            return $this->failure($id, McpException::invalidParams('"params" must be an object.'));
        }

        try {
            return $this->success($id, $this->dispatch($method, $params));
        } catch (McpException $exception) {
            return $this->failure($id, $exception);
        } catch (Throwable $exception) {
            // A bug. The client is told nothing about it; the server's log is
            // told everything, exactly as `ApiEnvelope` does for the portal.
            error_log('portal_api: mcp: ' . $exception::class . ': ' . $exception->getMessage() . ' in ' . $exception->getFile() . ':' . $exception->getLine());

            return $this->failure($id, new McpException(McpException::INTERNAL_ERROR, 'The archive could not answer that.'));
        }
    }

    /**
     * @param array<mixed> $params
     *
     * @return array<string,mixed>
     */
    private function dispatch(string $method, array $params): array
    {
        return match ($method) {
            'initialize' => $this->initialize($params),
            'ping'       => [],
            'tools/list' => ['tools' => $this->tools->definitions()],
            'tools/call' => $this->callTool($params),
            default      => throw McpException::methodNotFound($method),
        };
    }

    /**
     * @param array<mixed> $params
     *
     * @return array<string,mixed>
     */
    private function initialize(array $params): array
    {
        $asked = $params['protocolVersion'] ?? null;

        $version = is_string($asked) && in_array($asked, self::SUPPORTED_PROTOCOLS, true)
            ? $asked
            : self::LATEST_PROTOCOL;

        return [
            'protocolVersion' => $version,
            'capabilities'    => [
                // No `listChanged`: the tools are in the source code and the
                // source code does not change while a request is in flight.
                //
                // An object, spelled out, because PHP cannot tell an empty map
                // from an empty list and `json_encode` guesses `[]`. The
                // specification's schema says object here, and a client that
                // validates against it — mcp-remote does — rejects the whole
                // handshake over the difference. It cost an evening.
                'tools' => new stdClass(),
            ],
            'serverInfo'      => [
                'name'    => self::NAME,
                'title'   => 'Family archive',
                'version' => PortalApiModule::CUSTOM_VERSION,
            ],
            'instructions'    => $this->instructions(),
        ];
    }

    /**
     * What the client puts in front of the model before it asks anything.
     *
     * Worth as much as the tool descriptions and easy to leave out. Two things
     * have to be said or the model will get them wrong on its own: that the
     * archive contains only the dead, so an empty answer is not evidence that
     * somebody never existed; and that this is a family's record of itself
     * rather than a reference work, so an answer it cannot find in the archive
     * is one it should say it cannot find rather than fill in.
     */
    private function instructions(): string
    {
        return <<<'TEXT'
        This server reads one family's genealogical archive, kept in webtrees.

        It holds only people who have died. Living people are never named, never
        listed and never counted individually — where a record has living
        relatives, a "withheld" count says how many were left out. So an empty
        result means "not among the archive's dead", never "no such person", and
        a short list of children is not evidence of a small family.

        Names, places and notes are in German. "Notes" are the prose the family
        wrote into the archive — occupations, migrations, wartime service, why a
        date is uncertain — and are usually where an interesting answer is;
        search_notes reaches them.

        This is one family's own record of itself, not a reference work. It can
        be wrong, and it is often incomplete. Answer from what the tools return,
        say plainly when the archive does not contain something, and do not fill
        a gap from general knowledge without saying that is what you are doing.
        TEXT;
    }

    /**
     * @param array<mixed> $params
     *
     * @return array<string,mixed>
     */
    private function callTool(array $params): array
    {
        $name = $params['name'] ?? null;

        if (!is_string($name) || $name === '') {
            throw McpException::invalidParams('"name" is required and must name a tool.');
        }

        $arguments = $params['arguments'] ?? [];

        if (!is_array($arguments)) {
            throw McpException::invalidParams('"arguments" must be an object.');
        }

        return $this->tools->call($name, $arguments);
    }

    /**
     * The message's id, or null for a notification.
     *
     * JSON-RPC allows a string or a number and forbids anything else. A
     * message carrying `"id": {...}` is malformed, and is answered as a
     * notification would be — with nothing — because there is no id to put in
     * the reply.
     */
    private function id(array $message): string|int|null
    {
        if (!array_key_exists('id', $message)) {
            return null;
        }

        $id = $message['id'];

        return is_string($id) || is_int($id) ? $id : null;
    }

    /**
     * @param array<string,mixed> $result
     *
     * @return array<string,mixed>
     */
    private function success(string|int $id, array $result): array
    {
        // Every MCP result is an object, including the empty one `ping`
        // answers with. PHP would encode that as `[]` and a client validating
        // the reply would refuse it, so the empty case says `{}` out loud.
        return ['jsonrpc' => '2.0', 'id' => $id, 'result' => $result === [] ? new stdClass() : $result];
    }

    /**
     * @return array<string,mixed>
     */
    private function failure(string|int|null $id, McpException $exception): array
    {
        $error = ['code' => $exception->rpc_code, 'message' => $exception->getMessage()];

        if ($exception->data !== null) {
            $error['data'] = $exception->data;
        }

        return ['jsonrpc' => '2.0', 'id' => $id, 'error' => $error];
    }
}
