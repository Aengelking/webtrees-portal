<?php

declare(strict_types=1);

namespace Engelking\Webtrees\PortalApi\Services;

use Fisharebest\Webtrees\I18N;

use function abs;
use function min;
use function str_starts_with;
use function strlen;
use function substr;

/**
 * How two people are related, worked out from their archive numbers alone.
 *
 * `SackNumbers` explains why this is possible: an SB number is a path, one
 * character per generation. So the whole calculation is three integers.
 *
 * - **common** — the longest prefix the two paths share. That is the nearest
 *   ancestor they both descend from.
 * - **generations** — how many generations apart they are. Positive when the
 *   second is the elder.
 * - **distance** — how far the first stands from that common ancestor.
 *
 * Everything a family says about relatives falls out of those three. If the
 * distance equals the generations, the second is a direct ancestor of the
 * first. If the distance is nought, the second is a descendant. Same
 * generation and distance one: siblings. Same generation, further apart:
 * cousins, and how far apart says of what degree.
 *
 * **This reaches where the tree walk cannot.** `RelationshipNamer` gives up
 * after four steps, and refuses on principle to name a path that runs through
 * somebody the reader may not see. This needs neither: two numbers, no records
 * in between. A member and their fourth cousin twice removed get a real answer
 * from two strings that were already printed on both their cards.
 *
 * **The wording is a table, not a translation catalogue.** These are the
 * family's own terms for its own numbering system, and the module has no
 * gettext catalogue of its own — a string put through `I18N::translate()` here
 * would reach a German member in English. Two tables, one per language, are
 * what an honest version of this looks like.
 */
class SackRelationship
{
    public function __construct(private readonly SackNumbers $numbers)
    {
    }

    /**
     * The relationship between two SB numbers, named.
     *
     * `$sex` is the sex of the *second* person — the one being described — so
     * that a card can say "Ihre Schwester" rather than "Bruder/Schwester". `U`
     * where it is not known, which is the calculator's normal case: two typed
     * numbers say nothing about anybody's sex.
     *
     * Null when either number is not one, or when they are the same person.
     */
    public function name(string $first, string $second, string $sex = 'U'): string|null
    {
        $relation = $this->between($first, $second);

        if ($relation === null || $relation['kind'] === 'self') {
            return null;
        }

        return $this->describe($relation, $sex);
    }

    /** Whether this string is an archive number at all. */
    public function isNumber(string $number): bool
    {
        return $this->numbers->path($number) !== null;
    }

    /**
     * The relationship as numbers, before anybody puts words on it.
     *
     * @return array{kind:string,generations:int,distance:int,degree:int|null}|null
     */
    public function between(string $first, string $second): array|null
    {
        $a = $this->numbers->path($first);
        $b = $this->numbers->path($second);

        if ($a === null || $b === null) {
            return null;
        }

        // Marriages inside the family are folded in before anything is
        // measured — see SackNumbers::merge(). Both paths may change.
        $this->numbers->merge($a, $b);

        if ($a === $b) {
            return ['kind' => 'self', 'generations' => 0, 'distance' => 0, 'degree' => null];
        }

        $common      = $this->commonPrefix($a, $b);
        $generations = strlen($a) - strlen($b);
        $distance    = strlen($a) - strlen($common);

        return $this->classify($generations, $distance);
    }

    /**
     * @param array{kind:string,generations:int,distance:int,degree:int|null} $relation
     */
    public function describe(array $relation, string $sex = 'U'): string
    {
        // The tag is a full one — "de", "de-AT", "en-US" — so the test is on
        // the language and not on the exact string. Everything that is not
        // German gets the English table, which is the module's fallback
        // everywhere else too.
        $german = str_starts_with(I18N::languageTag(), 'de');
        $steps  = abs($relation['generations']);

        $forms = match ($relation['kind']) {
            'self'       => $this->forms('self', $german),
            'sibling'    => $this->forms('sibling', $german),
            'ancestor'   => $this->generational($steps, 'parent', 'grandparent', $german),
            'descendant' => $this->generational($steps, 'child', 'grandchild', $german),
            'nephew'     => $this->generational($steps, 'nephew', 'grandnephew', $german),
            'uncle'      => $this->generational($steps, 'uncle', 'granduncle', $german),
            default      => $this->forms('cousin', $german),
        };

        $name = $this->pick($forms, $sex);

        if ($relation['degree'] === null || $relation['degree'] < 2) {
            return $name;
        }

        // "Cousine 2. Grades", "Neffe 3. Grades" — the family's own way of
        // saying how far out a collateral relative sits.
        return $german
            ? $name . ' ' . $relation['degree'] . '. Grades'
            : $name . ' (' . $this->ordinal($relation['degree']) . ' degree)';
    }

    /**
     * Which of the six shapes this is, and how far out.
     *
     * The order of the tests is the original calculator's and matters: a
     * sibling is also "same generation, distance one", and a direct ancestor is
     * also "distance equals generations". Each test claims its case before the
     * broader ones below get to see it.
     *
     * @return array{kind:string,generations:int,distance:int,degree:int|null}
     */
    private function classify(int $generations, int $distance): array
    {
        $shape = static fn (string $kind, int|null $degree): array => [
            'kind'        => $kind,
            'generations' => $generations,
            'distance'    => $distance,
            'degree'      => $degree,
        ];

        if ($generations === 0 && $distance === 1) {
            return $shape('sibling', null);
        }

        if ($distance === $generations) {
            return $shape('ancestor', null);
        }

        if ($distance === 0) {
            return $shape('descendant', null);
        }

        if ($generations < 0) {
            // Their line branched off below the reader's: nephews and nieces,
            // and further out, nephews of some degree.
            return $shape('nephew', $distance > 1 ? $distance - 1 : null);
        }

        if ($generations === 0) {
            // Cousins. Distance two is a first cousin and carries no degree —
            // the family says "Cousine", not "Cousine 1. Grades".
            return $shape('cousin', $distance > 2 ? $distance - 1 : null);
        }

        // Uncles and aunts, and further out, uncles of some degree. Here the
        // degree is measured from *their* side of the common ancestor.
        return $shape('uncle', $distance - $generations > 1 ? $distance - $generations : null);
    }

    /**
     * "Urgroßvater", "2. Urgroßonkel", "Enkelin".
     *
     * One step is the near word — father, child, nephew, uncle. Two is the
     * "grand" word. Three puts "Ur" in front of it, and every step after that
     * counts the "Ur"s rather than repeating them, which is how the family
     * writes it and how anybody can read it at a glance.
     *
     * The prefix goes on **both** forms rather than on the pair. Otherwise a
     * relationship of unknown sex reads "Urgroßvater/großmutter", which is
     * neither of the two things it is trying to say.
     *
     * @return array{0:string,1:string}
     */
    private function generational(int $steps, string $near, string $grand, bool $german): array
    {
        if ($steps <= 1) {
            return $this->forms($near, $german);
        }

        $prefix = $this->prefix($steps, $german);
        [$male, $female] = $this->forms($grand, $german, $german && $steps > 2);

        return [$prefix . $male, $prefix . $female];
    }

    private function prefix(int $steps, bool $german): string
    {
        if ($steps <= 2) {
            return '';
        }

        if ($german) {
            return $steps === 3 ? 'Ur' : ($steps - 2) . '. Ur';
        }

        return $steps === 3 ? 'great-' : ($steps - 2) . 'x great-';
    }

    /**
     * The two forms of a word.
     *
     * `$lowered` is for the German compounds: "Ur" + "großvater", not "Ur" +
     * "Großvater".
     *
     * @return array{0:string,1:string}
     */
    private function forms(string $kind, bool $german, bool $lowered = false): array
    {
        [$male, $female] = self::WORDS[$german ? 'de' : 'en'][$kind];

        return $lowered ? [$this->lower($male), $this->lower($female)] : [$male, $female];
    }

    /**
     * One form, or both where the sex is not known.
     *
     * "Bruder/Schwester" is the original calculator's answer and the right one
     * to a question asked with two bare numbers: nothing in an archive number
     * says whose it is.
     *
     * @param array{0:string,1:string} $forms
     */
    private function pick(array $forms, string $sex): string
    {
        return match ($sex) {
            'M'     => $forms[0],
            'F'     => $forms[1],
            default => $forms[0] === $forms[1] ? $forms[0] : $forms[0] . '/' . $forms[1],
        };
    }

    /** Only the first letter, and only for the German compounds. */
    private function lower(string $word): string
    {
        return I18N::strtolower(substr($word, 0, 1)) . substr($word, 1);
    }

    private function ordinal(int $degree): string
    {
        return self::ORDINALS[$degree] ?? $degree . 'th';
    }

    private function commonPrefix(string $a, string $b): string
    {
        $length = min(strlen($a), strlen($b));

        for ($i = 0; $i < $length; $i++) {
            if ($a[$i] !== $b[$i]) {
                return substr($a, 0, $i);
            }
        }

        return substr($a, 0, $length);
    }

    /** @var array<string,array<string,array{0:string,1:string}>> */
    private const array WORDS = [
        'de' => [
            'self'         => ['dieselbe Person', 'dieselbe Person'],
            'sibling'      => ['Bruder', 'Schwester'],
            'parent'       => ['Vater', 'Mutter'],
            'grandparent'  => ['Großvater', 'Großmutter'],
            'child'        => ['Sohn', 'Tochter'],
            'grandchild'   => ['Enkel', 'Enkelin'],
            'nephew'       => ['Neffe', 'Nichte'],
            'grandnephew'  => ['Großneffe', 'Großnichte'],
            'uncle'        => ['Onkel', 'Tante'],
            'granduncle'   => ['Großonkel', 'Großtante'],
            'cousin'       => ['Cousin', 'Cousine'],
        ],
        'en' => [
            'self'         => ['the same person', 'the same person'],
            'sibling'      => ['brother', 'sister'],
            'parent'       => ['father', 'mother'],
            'grandparent'  => ['grandfather', 'grandmother'],
            'child'        => ['son', 'daughter'],
            'grandchild'   => ['grandson', 'granddaughter'],
            'nephew'       => ['nephew', 'niece'],
            'grandnephew'  => ['grand-nephew', 'grand-niece'],
            'uncle'        => ['uncle', 'aunt'],
            'granduncle'   => ['great-uncle', 'great-aunt'],
            'cousin'       => ['cousin', 'cousin'],
        ],
    ];

    /** @var array<int,string> */
    private const array ORDINALS = [
        2 => 'second',
        3 => 'third',
        4 => 'fourth',
        5 => 'fifth',
        6 => 'sixth',
        7 => 'seventh',
        8 => 'eighth',
        9 => 'ninth',
        10 => 'tenth',
    ];
}
