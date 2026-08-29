<?php

declare(strict_types=1);

namespace Engelking\Webtrees\PortalApi\Services;

use Fisharebest\Webtrees\I18N;

use function array_shift;
use function explode;
use function implode;
use function preg_match;
use function strtolower;
use function trim;

/**
 * A phrase the family wrote, in whatever languages they wrote it in.
 *
 * Some of what the portal shows is neither genealogy nor interface: the name
 * of a branch of the family, the name of an office in the foundation. Neither
 * can live in the portal's own translation files — a statute that renames a
 * body, or a family that names a new branch, must not need a deployment — and
 * neither should be stuck in one language on a portal that answers in two.
 *
 * So the family writes them, and writes the translations beside them:
 *
 *     Ernestinische Linie | en: Ernestine Line | fr: Ligne ernestine
 *
 * The first part is the name as written and always answers. The rest are
 * tagged, and a part that carries no tag — or a tag that is not a language
 * tag — is dropped rather than guessed at: a name with nobody to read it is
 * worse than no name, because it would surface in a language somebody is not
 * reading.
 *
 * **The fallback is exact tag, then bare language, then the name as written.**
 * "en-GB" takes an `en-GB:` phrase where one was written and an `en:` phrase
 * otherwise, because the difference between two Englishes is not what this is
 * for. The untagged name answering last and always is what keeps a row the
 * family has only half-translated from showing a reader nothing at all.
 *
 * Two callers now — `SackNumbers` for branch names, `Offices` for offices —
 * which is exactly why this is one class. They were one notation the moment
 * the second one existed; being one *parser* as well is what stops them
 * drifting into two notations that are nearly the same.
 */
class TranslatedText
{
    /**
     * Split a written phrase into the name and its tagged translations.
     *
     * @return array{text:string,translations:array<string,string>}
     */
    public static function parse(string $value): array
    {
        $parts = explode('|', $value);
        $text  = trim(array_shift($parts));

        return ['text' => $text, 'translations' => self::tags(implode('|', $parts))];
    }

    /**
     * Just the tagged parts, for a caller that keeps the name in a field of
     * its own — `en: Chair of the board | fr: President du conseil`.
     *
     * Separate from `parse()` because where the two live in one column the
     * name is the first part, and where they live in two there is no first
     * part to skip. Same notation either way, which is the point.
     *
     * @return array<string,string>
     */
    public static function tags(string $list): array
    {
        $translations = [];
        $matches      = [];

        foreach (explode('|', $list) as $part) {
            if (preg_match('/^\s*([a-z]{2}(?:-[a-z]{2,3})?)\s*:\s*(\S.*)$/i', $part, $matches) === 1) {
                $translations[strtolower($matches[1])] = trim($matches[2]);
            }
        }

        return $translations;
    }

    /**
     * The phrase in one language, falling back as described above.
     *
     * @param array<string,string> $translations
     * @param string|null          $language     A language tag; the request's own when null.
     */
    public static function pick(string $text, array $translations, string|null $language = null): string
    {
        $tag     = strtolower($language ?? I18N::locale()->languageTag());
        $primary = explode('-', $tag)[0];

        return $translations[$tag] ?? $translations[$primary] ?? $text;
    }
}
