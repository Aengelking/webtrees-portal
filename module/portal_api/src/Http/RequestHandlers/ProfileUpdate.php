<?php

declare(strict_types=1);

namespace Engelking\Webtrees\PortalApi\Http\RequestHandlers;

use Engelking\Webtrees\PortalApi\Http\ApiException;
use Engelking\Webtrees\PortalApi\Http\Json;
use Engelking\Webtrees\PortalApi\Services\MemberService;
use Fisharebest\Webtrees\Auth;
use Fisharebest\Webtrees\I18N;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;

use function array_key_exists;
use function is_bool;
use function is_string;
use function mb_strlen;
use function preg_replace;
use function trim;

/**
 * PATCH /api/v1/me/profile — the member's own portal settings.
 *
 * Portal-native data only. Nothing here touches GEDCOM, so nothing here needs
 * the pending-changes queue: these are facts about how the member wants the
 * portal to behave, not claims about the family.
 */
class ProfileUpdate implements RequestHandlerInterface
{
    private const int MAX_DISPLAY_NAME = 128;

    public function __construct(private readonly MemberService $members)
    {
    }

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        $body    = Json::body($request);
        $user    = Auth::user();
        $changes = [];

        if (array_key_exists('visible_in_directory', $body)) {
            if (!is_bool($body['visible_in_directory'])) {
                throw ApiException::badRequest();
            }

            $changes['visible_in_directory'] = $body['visible_in_directory'];
        }

        if (array_key_exists('display_name_override', $body)) {
            $changes['display_name_override'] = $this->displayName($body['display_name_override']);
        }

        if ($changes === []) {
            throw ApiException::badRequest(I18N::translate('There was nothing to change.'));
        }

        return Json::response($this->members->updateProfile($user, $changes));
    }

    private function displayName(mixed $value): string|null
    {
        if ($value === null) {
            return null;
        }

        if (!is_string($value)) {
            throw ApiException::badRequest();
        }

        $value = trim((string) preg_replace('/[\x00-\x1F\x7F]+|\s+/u', ' ', $value));

        if ($value === '') {
            return null;
        }

        if (mb_strlen($value) > self::MAX_DISPLAY_NAME) {
            throw ApiException::badRequest(I18N::translate('That name is too long.'));
        }

        return $value;
    }
}
