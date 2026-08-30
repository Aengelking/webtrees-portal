<?php

declare(strict_types=1);

namespace Engelking\Webtrees\PortalApi\Services;

use Fisharebest\Webtrees\I18N;

use function abs;
use function array_key_exists;
use function array_keys;
use function array_map;
use function array_values;
use function asort;
use function implode;
use function min;
use function str_repeat;
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
        return $this->relations($first, $second)[0] ?? null;
    }

    /**
     * Every way these two are related, nearest first.
     *
     * **A number is one line of descent, and a person can have several.** The
     * archive files the children of an in-family marriage under one parent, so
     * somebody whose ancestors married within the family has more than one
     * true number — and each one measures a different distance to anybody
     * else. `SackNumbers::writings()` enumerates them; this crosses the two
     * sets and keeps the distinct answers.
     *
     * Until §2.94 only the stored writing was measured, which meant the
     * calculator named the relationship of whichever number the archive
     * happened to file the person under. Not a second answer missing: often
     * the *wrong* answer, because the other writing is frequently the nearer
     * one — `24/3133.42` and `24/B521.12` read as fifth cousins one way and
     * third cousins once removed the other, and the second is the truth a
     * family would tell you.
     *
     * Nearest first, measured as the walk up to the shared ancestor plus the
     * walk back down.
     *
     * @return array<int,array{kind:string,generations:int,distance:int,degree:int|null}>
     */
    public function relations(string $first, string $second): array
    {
        $a = $this->numbers->path($first);
        $b = $this->numbers->path($second);

        if ($a === null || $b === null) {
            return [];
        }

        $found = [];
        $steps = [];

        foreach ($this->pairs($a, $b) as [$one, $other]) {
            // Marriages inside the family are folded in before anything is
            // measured — see SackNumbers::merge(). Both paths may change.
            $this->numbers->merge($one, $other);

            if ($one === $other) {
                // The same person, by whichever pair of writings got here.
                // Nothing else these two numbers could say is worth more.
                return [['kind' => 'self', 'generations' => 0, 'distance' => 0, 'degree' => null]];
            }

            $common      = $this->commonPrefix($one, $other);
            $generations = strlen($one) - strlen($other);
            $distance    = strlen($one) - strlen($common);

            $relation = $this->classify($generations, $distance);
            $key      = implode('/', [$relation['kind'], $generations, $distance]);

            if (array_key_exists($key, $found)) {
                continue;
            }

            $found[$key] = $relation;
            $steps[$key] = 2 * $distance - $generations;
        }

        asort($steps);

        return array_values(array_map(static fn (string $key): array => $found[$key], array_keys($steps)));
    }

    /**
     * The pairs of paths worth measuring — **never two alternatives at once.**
     *
     * An alternative writing is one person re-rooted into the other parent's
     * branch, and the join character `-` replaces the child index to keep the
     * join from lining up with one of that parent's own children (see
     * `SackNumbers::merge()`). Re-rooting *both* sides at the same marriage
     * therefore erases what told the two people apart, and they arrive at the
     * identical string: two siblings measured that way come out as one person.
     * That is not a hypothetical — it is what the shipped table does to
     * `24/b61` and `24/3132`, who are siblings.
     *
     * So one side is always the number as the archive stores it, and `merge()`
     * does its own aligning on top. Every genuine second descent is still
     * reached, because it takes only one side to be re-rooted to measure it.
     *
     * @return array<int,array{0:string,1:string}>
     */
    private function pairs(string $a, string $b): array
    {
        $pairs = [];

        foreach ($this->numbers->writings($a) as $one) {
            $pairs[] = [$one, $b];
        }

        foreach ($this->numbers->writings($b) as $other) {
            $pairs[] = [$a, $other];
        }

        return $pairs;
    }

    /**
     * @param array{kind:string,generations:int,distance:int,degree:int|null} $relation
     */
    public function describe(array $relation, string $sex = 'U'): string
    {
        // The tag is a full one — "de", "de-AT", "en-US" — so the test is on
        // the language and not on the exact string. Everything that is not
        // German gets the English rules, which are the module's fallback
        // everywhere else too.
        return str_starts_with(I18N::languageTag(), 'de')
            ? $this->german($relation, $sex)
            : $this->english($relation, $sex);
    }

    /**
     * @param array{kind:string,generations:int,distance:int,degree:int|null} $relation
     */
    private function german(array $relation, string $sex): string
    {
        $steps = abs($relation['generations']);

        $forms = match ($relation['kind']) {
            'self'       => $this->forms('self', true),
            'sibling'    => $this->forms('sibling', true),
            'ancestor'   => $this->generational($steps, 'parent', 'grandparent', true),
            'descendant' => $this->generational($steps, 'child', 'grandchild', true),
            'nephew'     => $this->generational($steps, 'nephew', 'grandnephew', true),
            'uncle'      => $this->generational($steps, 'uncle', 'granduncle', true),
            default      => $this->forms('cousin', true),
        };

        $name = $this->pick($forms, $sex);

        if ($relation['degree'] === null || $relation['degree'] < 2) {
            return $name;
        }

        // "Cousine 2. Grades", "Neffe 3. Grades" — the family's own way of
        // saying how far out a collateral relative sits.
        return $name . ' ' . $relation['degree'] . '. Grades';
    }

    /**
     * **English is not translated German here, it is a different system.**
     *
     * German counts a collateral relative by degree and keeps the near word:
     * *Großneffe 2. Grades*, *Onkel 3. Grades*. English has no such phrase.
     * Everything that is not a plain nephew or a plain uncle becomes a
     * **cousin**, counted by how far back the shared ancestor is and by how
     * many generations the two people sit apart: *second cousin once removed*.
     *
     * Translating word for word produced sentences that are not English at
     * all — "nephew (second degree)", "cousin (third degree)" — which is what
     * a member reported. So the two languages branch here rather than sharing
     * a shape, and this side follows the family's own calculator
     * (`rechner.php`, `$relation_en`) rather than the German above it.
     *
     * @param array{kind:string,generations:int,distance:int,degree:int|null} $relation
     */
    private function english(array $relation, string $sex): string
    {
        // A degree is exactly what the German would have counted, and it is
        // the signal that English stops using the near word. A cousin is one
        // whether or not it carries a degree: "cousin", "second cousin".
        if ($relation['kind'] === 'cousin' || $relation['degree'] !== null) {
            return $this->cousin($relation);
        }

        $steps = abs($relation['generations']);

        $forms = match ($relation['kind']) {
            'self'       => $this->forms('self', false),
            'sibling'    => $this->forms('sibling', false),
            'ancestor'   => $this->greats($steps, 'parent', 'grandparent'),
            'descendant' => $this->greats($steps, 'child', 'grandchild'),
            'nephew'     => $this->greats($steps, 'nephew', 'grandnephew'),
            default      => $this->greats($steps, 'uncle', 'granduncle'),
        };

        return $this->pick($forms, $sex);
    }

    /**
     * "second cousin once removed", and the rules behind it.
     *
     * How far back the shared ancestor is names the cousin — distance two is a
     * plain cousin, three a second cousin — and how many generations apart the
     * two of them sit is the removal. Neither word has a female form, which is
     * why this returns a string where the rest of the class returns a pair.
     *
     * @param array{kind:string,generations:int,distance:int,degree:int|null} $relation
     */
    private function cousin(array $relation): string
    {
        // The nearer of the two, for the reason in `classify()`: an ordinal
        // counted from the reader makes one pair two relationships.
        $nearest = min($relation['distance'], $relation['distance'] - $relation['generations']);

        $name = $nearest > 2
            ? $this->ordinal($nearest - 1) . ' cousin'
            : 'cousin';

        $steps = abs($relation['generations']);

        return $steps === 0 ? $name : $name . ' ' . $this->times($steps) . ' removed';
    }

    /**
     * The English prefix, which repeats where the German counts.
     *
     * *great-great-grandfather*, not *2x great-grandfather*: English says the
     * word again. That is long by the tenth generation and it is still what
     * the language does, and what the family's calculator writes.
     *
     * @return array{0:string,1:string}
     */
    private function greats(int $steps, string $near, string $grand): array
    {
        if ($steps <= 1) {
            return $this->forms($near, false);
        }

        $prefix = str_repeat('great-', $steps - 2);
        [$male, $female] = $this->forms($grand, false);

        return [$prefix . $male, $prefix . $female];
    }

    /** once, twice, thrice — how far apart two cousins stand. */
    private function times(int $steps): string
    {
        return self::TIMES[$steps] ?? $steps . ' times';
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

        // **How far out a collateral relative sits is the *elder's* distance,
        // not the reader's.** `$distance` is measured from the reader, so the
        // other person stands `$distance - $generations` away and the elder of
        // the two is whichever is nearer the common ancestor. Counting from
        // the reader instead makes the same pair two different relationships
        // depending on who is asking. See §2.99.
        $nearest = min($distance, $distance - $generations);

        if ($generations < 0) {
            // Their line branched off below the reader's: nephews and nieces,
            // and further out, nephews of some degree.
            return $shape('nephew', $nearest > 1 ? $nearest : null);
        }

        if ($generations === 0) {
            // Cousins. Distance two is a first cousin and carries no degree —
            // the family says "Cousine", not "Cousine 1. Grades". Every one of
            // these scales starts its counting at the plain word, and the
            // plain cousin stands two steps from the shared ancestor where the
            // plain uncle stands one. That is why this one subtracts and the
            // two around it do not; they are not the same scale.
            return $shape('cousin', $distance > 2 ? $distance - 1 : null);
        }

        // Uncles and aunts, and further out, uncles of some degree.
        return $shape('uncle', $nearest > 1 ? $nearest : null);
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
            // "grand-uncle", not "great-uncle": both are English, and this is
            // the one the family's calculator writes, so the prefixes above it
            // stack the same way as everywhere else — great-grand-uncle.
            'granduncle'   => ['grand-uncle', 'grand-aunt'],
            'cousin'       => ['cousin', 'cousin'],
        ],
    ];

    /** @var array<int,string> */
    private const array TIMES = [
        1 => 'once',
        2 => 'twice',
        3 => 'thrice',
        4 => 'four times',
        5 => 'five times',
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
