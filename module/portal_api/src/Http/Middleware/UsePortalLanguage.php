<?php

declare(strict_types=1);

namespace Engelking\Webtrees\PortalApi\Http\Middleware;

use Fisharebest\Webtrees\Gedcom;
use Fisharebest\Webtrees\I18N;
use Fisharebest\Webtrees\Module\ModuleLanguageInterface;
use Fisharebest\Webtrees\Registry;
use Fisharebest\Webtrees\Services\ModuleService;
use Fisharebest\Webtrees\Session;
use Fisharebest\Webtrees\Site;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Throwable;

use function array_key_exists;
use function explode;
use function in_array;
use function str_contains;
use function stripos;
use function strcasecmp;
use function strtolower;
use function trim;
use function uasort;

/**
 * Make the API answer in the language the portal is being read in.
 *
 * Most of what the portal shows is translated in the browser, but not all of
 * it: GEDCOM fact labels ("Birth", "Occupation"), formatted dates
 * ("12 March 1985"), and the placeholders webtrees uses where a name may not
 * be shown all come out of webtrees' own translations, server-side. Without
 * this the portal reads German with English fact labels scattered through it.
 *
 * The portal sends `Accept-Language` on every request. That is deliberately
 * the standard header rather than something invented: a browser sends it
 * anyway, so even a request made before the portal has loaded its own
 * preference lands in a sensible language.
 *
 * Why this cannot simply call `I18N::init()` and stop there: element labels
 * are translated **once**, when webtrees' `RegisterGedcomTags` middleware
 * builds the element factory — which happens before routing, and therefore
 * before any module middleware runs. Re-initialising I18N afterwards changes
 * `I18N::translate()` but leaves every already-built label in the language of
 * the request as webtrees first understood it. So the tags are registered
 * again, which is neither a hack nor expensive: it is the same call webtrees
 * itself makes, once more, against the now-correct translations.
 */
class UsePortalLanguage implements MiddlewareInterface
{
    /**
     * Set on the request when the client asked for a language we could honour.
     *
     * Handlers use it to know that the client has an opinion — SessionCreate
     * otherwise replaces it with the member's webtrees preference.
     */
    public const string REQUEST_ATTRIBUTE = 'portal-language';

    public function __construct(private readonly ModuleService $module_service)
    {
    }

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        $language = $this->negotiate($request->getHeaderLine('Accept-Language'));

        if ($language !== null) {
            self::apply($language);

            $request = $request->withAttribute(self::REQUEST_ATTRIBUTE, $language);
        }

        return $handler->handle($request);
    }

    /**
     * Switch this request into $language, labels and all.
     *
     * @param string $language A webtrees language tag, e.g. "de" or "en-US".
     */
    public static function apply(string $language): void
    {
        if ($language === '' || $language === I18N::locale()->languageTag()) {
            return;
        }

        try {
            I18N::init($language);
        } catch (Throwable) {
            // A language that is no longer installed — an old preference on an
            // account, say. Staying in the current language is the right
            // answer; failing the request is not.
            return;
        }

        // Also for the rest of the webtrees session, so that a member who
        // follows the "open in webtrees" link does not land in a different
        // language than the one they were just reading.
        Session::put('language', $language);

        // Element labels were translated when they were registered, before
        // routing. Register them again, now that I18N has moved.
        Registry::container()->get(Gedcom::class)->registerTags(Registry::elementFactory(), true);
    }

    /**
     * The best match between what the client asked for and what this webtrees
     * actually has enabled, or null for "leave the language alone".
     */
    private function negotiate(string $header): string|null
    {
        if (trim($header) === '') {
            return null;
        }

        $available = $this->module_service
            ->findByInterface(ModuleLanguageInterface::class, true)
            ->map(static fn (ModuleLanguageInterface $module): string => $module->locale()->languageTag())
            ->all();

        foreach ($this->preferences($header) as $code) {
            // An exact tag first — "de" for de, "en-GB" for en-GB.
            foreach ($available as $tag) {
                if (strcasecmp($tag, $code) === 0) {
                    return $tag;
                }
            }

            // Then the language without its region: the portal offers "en",
            // and what webtrees has enabled is "en-AU", "en-GB" and "en-US".
            // Where several fit, the one this site is configured in wins —
            // "English" on a site set up in en-US should not arrive as en-AU
            // because that sorts first.
            $regional = [];

            foreach ($available as $tag) {
                if (stripos($tag, $code . '-') === 0) {
                    $regional[] = $tag;
                }
            }

            if ($regional !== []) {
                $site_default = Site::getPreference('LANGUAGE');

                return in_array($site_default, $regional, true) ? $site_default : $regional[0];
            }
        }

        return null;
    }

    /**
     * The codes in an Accept-Language header, best first.
     *
     * "de-DE,de;q=0.9,en;q=0.8" becomes ["de-DE", "de", "en"] — each tag is
     * followed by its bare language, so a request for a regional variant we do
     * not have still finds the plain one.
     *
     * @return array<int,string>
     */
    private function preferences(string $header): array
    {
        $weights  = [];
        $sequence = 0;

        foreach (explode(',', $header) as $part) {
            $pieces = explode(';', $part);
            $code   = strtolower(trim($pieces[0] ?? ''));

            if ($code === '' || $code === '*') {
                continue;
            }

            $quality = 1.0;

            foreach ($pieces as $piece) {
                $piece = strtolower(trim($piece));

                if (str_contains($piece, 'q=')) {
                    $quality = (float) explode('=', $piece)[1];
                }
            }

            if ($quality <= 0.0 || array_key_exists($code, $weights)) {
                continue;
            }

            // The sequence number keeps the header's own order among equal
            // weights, which is what a client means by listing them in order.
            $weights[$code] = [$quality, --$sequence];
        }

        uasort($weights, static fn (array $a, array $b): int => $b <=> $a);

        $codes = [];

        foreach ($weights as $code => $_) {
            $codes[] = $code;

            [$language] = explode('-', $code);

            if ($language !== $code && !array_key_exists($language, $weights)) {
                $codes[] = $language;
            }
        }

        return $codes;
    }
}
