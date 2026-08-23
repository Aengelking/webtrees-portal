<?php

declare(strict_types=1);

namespace Engelking\Webtrees\PortalApi\Services;

use Engelking\Webtrees\PortalApi\PortalApiModule;

use function array_key_exists;
use function explode;
use function preg_match;
use function str_contains;
use function str_replace;
use function strlen;
use function count;
use function str_starts_with;
use function strtolower;
use function strpos;
use function substr;
use function trim;
use function usort;

/**
 * The SB number, read as what it actually is: a path.
 *
 * The family archive numbers everybody as a position in one descent. Expanded,
 * `24/b521.12` is the line prefix for line 24 followed by the rest of the
 * number with its dots removed — one character per generation, each saying
 * which child. That makes the number a **complete ancestral path**, and it
 * means the relationship between any two members of the family is a property
 * of two strings. No tree, no dates, no records: the longest common prefix is
 * the nearest shared ancestor, and the two remaining lengths are the rest of
 * the answer.
 *
 * This class is the reading half — turning what somebody typed, or what a
 * record carries in its `REFN`, into that path. `SackRelationship` does the
 * arithmetic on it.
 *
 * **Ported, not re-derived.** The rules here come from the family's own
 * calculator (Amos Engelking, 2009-2011), including the parts that look odd:
 * `0` is not a valid child character, the line prefix is a lookup rather than
 * something the number states, and married couples inside the family need the
 * fix-up below. Where the original and a cleaner idea disagreed, the original
 * won — it has been right about this family for fifteen years.
 *
 * **The two tables are family data, not code.** A new line in the archive or a
 * marriage between two Sacks is an evening's news, not a release, so both live
 * in the module's settings where the family can edit them. What is shipped
 * here is what they were in 2011.
 */
class SackNumbers
{
    /** The setting holding the line table. */
    public const string SETTING_LINES = 'sack_lines';

    /** The setting holding the marriage table. */
    public const string SETTING_MARRIAGES = 'sack_marriages';

    /**
     * Line number to the path prefix it stands for.
     *
     * Lines 1-31 are the Ernestinian line, 32-35 the Wilhelminian, 36 the
     * Cramer line. The prefixes are positions in the same descent, which is
     * why two people from different lines still have a common ancestor and a
     * computable relationship.
     */
    public const string DEFAULT_LINES = <<<'TEXT'
1 = 724
2 = 727
3 = 731
4 = 733
5 = 744
6 = 747
7 = 749
8 = 752
9 = 754
10 = 755
11 = 759
12 = 75a
13 = 75b
14 = 75c
15 = 791
16 = 794
17 = 795
18 = 796
19 = 797
20 = 7c2
21 = 7c7
22 = 7d1
23 = 7d2
24 = 7d3
25 = 7d4
26 = 7d5
27 = 7d6
28 = 7d7
29 = 7d9
30 = 7db
31 = 7dc
32 = 351
33 = 352
34 = 353
35 = 357
36 = 761
TEXT;

    /**
     * Marriages between two people who both have a number.
     *
     * The archive files the children of such a couple under one of the two
     * numbers only. That makes the other parent's descent invisible in the
     * children's numbers, and a calculation that ignored it would report the
     * relationship on one side of the family and miss the other. Each entry
     * says: descendants recorded under the right-hand number are also
     * descendants of the left-hand one.
     *
     * A `!` on the left-hand number marks the partner who married *in* to that
     * position — see `merge()`, which then leaves the pair alone rather than
     * folding one into the other.
     */
    public const string DEFAULT_MARRIAGES = <<<'TEXT'
01/3215.1 = 01/3213.2
01/3233.62 = 01/3234.21
01/3234.21 = 01/3233.62
01/3513 = 01/3253
01/4123 = 01/4111
02/1! = 02/6
02/313 = 02/111
02/33! = 02/32
02/361! = 02/365
06/2243.51! = 06/2243.52
06/4213! = 06/4215
06/4511 = 06/4334
09/23 = 09/32
09/43 = 09/22
12/22 = 12/31
12/26 = 12/32
12/3164.1 = 12/3163.1
12/3182! = 12/3183
12/4514 = 34/2131.6
12/5124.3 = 12/5123.5
12/54 = 12/44
12/55 = 12/23
13/21 = 10/111
14/3 = 34/13
14/6 = 10/13
22/122 = 22/115
22/123 = 33/432
22/134 = 22/1a2
22/1814! = 22/1813
22/1816.21 = 22/1811.22
23/1321 = 23/1314
23/1422.5! = 23/1422.2
23/1428.2 = 23/1423.1
24/313 = 24/b6  # SB p. 204 & 267
24/4113 = 24/944
24/411a = 24/9234
24/5! = 24/8
24/64! = 24/62
24/846 = 24/5111.1
24/b6 = 24/313
25/51 = 27/9
26/642 = 26/614
27/8 = 22/13
27/b2 = 27/51
27/c4 = 27/99
29/1 = 24/2
29/2 = 27/2
29/2 = 24/6
29/3252.1! = 29/3252.2
29/35 = 24/93
30/1 = 29/3
30/7 = 24/a
# 30/7224! = 30/7222  # keine Kinder
34/131 = 34/133
34/214 = 12/48
35/52 = 35/39
36/11! = 36/12
36/1242.41 = 36/1245.1
36/1243.3 = 35/3241
36/1245.23! = 36/1245.11
36/141 = 12/51
TEXT;

    /** @var array<int,string>|null */
    private array|null $lines = null;

    /** @var array<int,array{left:string,right:string,married_in:bool}>|null */
    private array|null $marriages = null;

    public function __construct(private readonly PortalApiModule $module)
    {
    }

    /**
     * The full path an SB number stands for, or null if it is not one.
     *
     * @return string|null lower-cased, dots and spaces gone, line prefix applied.
     */
    public function path(string $number): string|null
    {
        $cleaned = strtolower(str_replace(['.', ' '], '', trim($number)));
        $matches = [];

        // Deliberately no `0`: the archive counts children from 1 and then
        // runs on into the alphabet, so a zero means the string is not one of
        // these numbers at all.
        if (preg_match('/^([0-9]{1,2})\/([1-9a-z]*)$/', $cleaned, $matches) !== 1) {
            return null;
        }

        $line = (int) $matches[1];

        if (!array_key_exists($line, $this->lines())) {
            return null;
        }

        return $this->lines()[$line] . $matches[2];
    }

    /**
     * Fold a marriage between two family members into one line of descent.
     *
     * Both arguments are paths, and both may be rewritten. The problem it
     * solves: when two people who each have a number marry, their children are
     * numbered under one parent only. Somebody descending from those children
     * is just as much a descendant of the other parent, and nothing in their
     * number says so.
     *
     * So where one of the pair descends from the recorded side and the other
     * descends from the unrecorded side, the first is re-rooted into the
     * second's branch. The character where the two trees are joined becomes
     * `-`, which cannot occur in a real number — without it the join would
     * accidentally line up with one of the other parent's own children and
     * name the wrong relationship.
     *
     * Ported from the original calculator, including the order: longest
     * right-hand number first, so a marriage deep in the tree is applied
     * before one near the root.
     */
    public function merge(string &$a, string &$b): void
    {
        foreach ($this->marriages() as ['left' => $left, 'right' => $other, 'married_in' => $married_in]) {
            $right_length = strlen($other);

            if (($a === $left && $b === $other) || ($a === $other && $b === $left)) {
                // The couple themselves. Their relationship is the marriage,
                // not a descent, and folding one into the other would make
                // them look like siblings.
                continue;
            }

            if ($married_in && ($a === $left || $b === $left)) {
                // One of the two *is* the partner who married in. Nothing to
                // fold: their own descent is what their number already says.
                continue;
            }

            if ($this->startsWith($a, $other) && $this->startsWith($b, $left)) {
                $a = $this->reroot($a, $left, $right_length);
            } elseif ($this->startsWith($b, $other) && $this->startsWith($a, $left)) {
                $b = $this->reroot($b, $left, $right_length);
            } elseif ($this->startsWith($other, $a) && $this->startsWith($b, $left)) {
                // The first is an *ancestor* of the recorded side, so the
                // equivalent position on the other side is the same depth
                // down the left-hand number.
                $a = substr($left, 0, strlen($a));
            } elseif ($this->startsWith($other, $b) && $this->startsWith($a, $left)) {
                $b = substr($left, 0, strlen($b));
            }
        }
    }

    /**
     * The line table, as the family maintains it.
     *
     * @return array<int,string>
     */
    public function lines(): array
    {
        if ($this->lines !== null) {
            return $this->lines;
        }

        $table = [];

        foreach ($this->rows(self::SETTING_LINES, self::DEFAULT_LINES) as [$key, $value]) {
            if (preg_match('/^[0-9]{1,2}$/', $key) === 1 && preg_match('/^[1-9a-z]+$/', $value) === 1) {
                $table[(int) $key] = $value;
            }
        }

        return $this->lines = $table;
    }

    /**
     * The marriage table, as paths, deepest recorded side first.
     *
     * **A list, not a map keyed by the number.** A path that happens to be all
     * digits — "7243215" is one — becomes an *integer* array key in PHP, and
     * every string operation downstream then has an int where it wants a
     * string. The original calculator keyed by the number and survived on
     * PHP's coercion; this does not rely on it.
     *
     * @return array<int,array{left:string,right:string,married_in:bool}>
     */
    public function marriages(): array
    {
        if ($this->marriages !== null) {
            return $this->marriages;
        }

        $table = [];

        foreach ($this->rows(self::SETTING_MARRIAGES, self::DEFAULT_MARRIAGES) as [$key, $value]) {
            $married_in = str_contains($key, '!');
            $left       = $this->path(str_replace('!', '', $key));
            $right      = $this->path($value);

            if ($left === null || $right === null) {
                // A number naming a line that no longer exists, or a typo.
                // Skipped rather than fatal: one bad row in a table the family
                // edits by hand must not take the whole calculator down.
                continue;
            }

            $table[] = ['left' => $left, 'right' => $right, 'married_in' => $married_in];
        }

        // By the length of the recorded side, longest first — the original's
        // order, and the one that applies the deepest marriage before the
        // shallowest.
        usort(
            $table,
            static fn (array $x, array $y): int => strlen($y['right']) <=> strlen($x['right'])
        );

        return $this->marriages = $table;
    }

    /**
     * Move a path from the branch it was recorded under into the other
     * parent's branch, marking the join.
     */
    private function reroot(string $path, string $left, int $right_length): string
    {
        if (strlen($path) <= $right_length) {
            return $left;
        }

        return $left . '-' . substr($path, $right_length + 1);
    }

    private function startsWith(string $path, string $prefix): bool
    {
        return strpos($path, $prefix) === 0;
    }

    /**
     * `key = value` a line, `#` a comment, blank lines ignored.
     *
     * @return array<int,array{0:string,1:string}>
     */
    private function rows(string $setting, string $default): array
    {
        $text = trim($this->module->getPreference($setting, ''));

        if ($text === '') {
            $text = $default;
        }

        $rows = [];

        foreach (explode("\n", str_replace("\r", '', $text)) as $line) {
            $line = trim($line);

            if ($line === '' || str_starts_with($line, '#')) {
                continue;
            }

            // A trailing note, so the family can say why a row is there.
            $hash = strpos($line, '#');

            if ($hash !== false) {
                $line = trim(substr($line, 0, $hash));
            }

            $parts = explode('=', $line, 2);

            if (count($parts) !== 2) {
                continue;
            }

            $rows[] = [trim($parts[0]), trim($parts[1])];
        }

        return $rows;
    }
}
