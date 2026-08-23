<?php

declare(strict_types=1);

namespace Engelking\Webtrees\PortalApi\Http\RequestHandlers;

use Engelking\Webtrees\PortalApi\Http\ApiException;
use Engelking\Webtrees\PortalApi\Http\Json;
use Engelking\Webtrees\PortalApi\Http\Middleware\UsePortalLanguage;
use Engelking\Webtrees\PortalApi\Services\MemberService;
use Fisharebest\Webtrees\Auth;
use Fisharebest\Webtrees\Contracts\UserInterface;
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
 *
 * **The language is the one field here that is not the portal's own.** It is
 * webtrees' account preference, the same one the account's own settings page
 * sets, and it is written rather than copied for exactly that reason: a member
 * has one language, not one per application and one per device. Keeping it on
 * the account is also what makes it follow them to the next telephone, and
 * what makes the message webtrees sends them arrive in the language they read.
 */
class ProfileUpdate implements RequestHandlerInterface
{
    private const int MAX_DISPLAY_NAME = 128;

    public function __construct(
        private readonly MemberService $members,
        private readonly UsePortalLanguage $languages,
    ) {
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

        $language = array_key_exists('language', $body) ? $this->language($body['language']) : null;

        if ($changes === [] && $language === null) {
            throw ApiException::badRequest(I18N::translate('There was nothing to change.'));
        }

        if ($language !== null) {
            $user->setPreference(UserInterface::PREF_LANGUAGE, $language);

            // And this response, so that whatever the client renders from it
            // is already in the language the member just chose rather than in
            // the one they sent the request in.
            UsePortalLanguage::apply($language);
        }

        return Json::response($this->members->updateProfile($user, $changes));
    }

    /**
     * A language the portal asked for, as a tag this site actually has.
     *
     * The portal knows "de" and "en"; a webtrees site has whatever languages
     * its administrator left enabled, under tags like "de" and "en-US". The
     * negotiation is the middleware's, unchanged — this is the same question
     * asked about a deliberate choice rather than about a request header.
     *
     * A language this site does not have is refused rather than stored. An
     * unusable preference sits on the account for ever, and everything that
     * reads it later — a notification e-mail, the next sign-in — has to guess
     * around it.
     */
    private function language(mixed $value): string
    {
        if (!is_string($value) || trim($value) === '') {
            throw ApiException::badRequest();
        }

        $tag = $this->languages->negotiate($value);

        if ($tag === null) {
            throw ApiException::badRequest(I18N::translate('This language is not available on this site.'));
        }

        return $tag;
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
