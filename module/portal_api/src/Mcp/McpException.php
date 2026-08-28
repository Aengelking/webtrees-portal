<?php

declare(strict_types=1);

namespace Engelking\Webtrees\PortalApi\Mcp;

use RuntimeException;

/**
 * A JSON-RPC error, as opposed to a disappointing answer.
 *
 * The distinction is the one that matters in MCP and it is easy to get
 * backwards. A request that was malformed, named a method nobody has, or
 * carried arguments of the wrong shape is a **protocol** failure: it goes back
 * as a JSON-RPC `error`, the client sees it, and the model never does. A tool
 * that ran and found nothing is a **result** — `isError: true` with a sentence
 * in it — because the model is the one who has to do something about that, and
 * an error the model cannot see is an error the model will repeat.
 *
 * Sibling to `Http\ApiException`, and deliberately not the same class: that one
 * carries an HTTP status and a message worded for a member reading a screen.
 * This one carries a JSON-RPC code and a message worded for whoever is writing
 * the client.
 */
final class McpException extends RuntimeException
{
    /** JSON-RPC 2.0's own codes. */
    public const int PARSE_ERROR      = -32700;
    public const int INVALID_REQUEST  = -32600;
    public const int METHOD_NOT_FOUND = -32601;
    public const int INVALID_PARAMS   = -32602;
    public const int INTERNAL_ERROR   = -32603;

    /**
     * @param array<string,mixed>|null $data
     */
    public function __construct(
        public readonly int $rpc_code,
        string $message,
        public readonly array|null $data = null
    ) {
        parent::__construct($message);
    }

    public static function parseError(): self
    {
        return new self(self::PARSE_ERROR, 'The request body is not valid JSON.');
    }

    public static function invalidRequest(string $why): self
    {
        return new self(self::INVALID_REQUEST, $why);
    }

    public static function methodNotFound(string $method): self
    {
        return new self(self::METHOD_NOT_FOUND, 'This server has no method "' . $method . '".');
    }

    public static function invalidParams(string $why): self
    {
        return new self(self::INVALID_PARAMS, $why);
    }

    /**
     * An unknown tool is invalid *params*, not an unknown method: the method
     * was `tools/call`, which exists, and `name` was wrong. The MCP
     * specification says so, and a client that has cached a stale tool list
     * needs to be able to tell the two apart.
     */
    public static function unknownTool(string $name): self
    {
        return new self(self::INVALID_PARAMS, 'This server has no tool "' . $name . '".');
    }
}
