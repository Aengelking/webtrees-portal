<?php

declare(strict_types=1);

namespace Engelking\Webtrees\PortalApi\Http;

use Fig\Http\Message\StatusCodeInterface;
use Fisharebest\Webtrees\Registry;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

use function is_array;
use function is_string;
use function json_decode;

use const JSON_THROW_ON_ERROR;

/**
 * JSON in, JSON out.
 */
final class Json
{
    /**
     * @param array<string,mixed> $payload
     */
    public static function response(array $payload, int $status = StatusCodeInterface::STATUS_OK): ResponseInterface
    {
        // ResponseFactory::response() turns an empty body into 204, which is
        // not what we want for an empty object, so always pass a non-empty
        // array or accept the 204.
        return Registry::responseFactory()->response($payload, $status);
    }

    public static function error(ApiException $exception): ResponseInterface
    {
        return self::response([
            'error'   => $exception->error,
            'message' => $exception->getMessage(),
        ], $exception->status);
    }

    /**
     * Read a JSON request body.
     *
     * webtrees' request pipeline only parses form-encoded bodies, so the
     * portal's `application/json` bodies have to be decoded here.
     *
     * @return array<string,mixed>
     */
    public static function body(ServerRequestInterface $request): array
    {
        // A form-encoded body, or one already parsed by core middleware.
        $parsed = $request->getParsedBody();

        if (is_array($parsed) && $parsed !== []) {
            return $parsed;
        }

        $stream = $request->getBody();

        if ($stream->isSeekable()) {
            $stream->rewind();
        }

        $raw = $stream->getContents();

        if ($raw === '') {
            return [];
        }

        try {
            $decoded = json_decode($raw, true, 32, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            throw ApiException::badRequest();
        }

        if (!is_array($decoded)) {
            throw ApiException::badRequest();
        }

        return $decoded;
    }

    /**
     * A required, non-empty string from a JSON body.
     *
     * @param array<string,mixed> $body
     */
    public static function requiredString(array $body, string $key): string
    {
        $value = $body[$key] ?? null;

        if (!is_string($value) || $value === '') {
            throw ApiException::badRequest();
        }

        return $value;
    }
}
