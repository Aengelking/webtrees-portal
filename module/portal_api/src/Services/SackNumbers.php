<?php

declare(strict_types=1);

namespace Engelking\Webtrees\PortalApi\Services;

use Engelking\Webtrees\PortalApi\PortalApiModule;
use Fisharebest\Webtrees\I18N;

use function array_shift;
use function explode;
use function in_array;
use function preg_match;
use function str_contains;
use function str_replace;
use function count;
use function strlen;
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
    /**
     * The number that says "no line — this is already the whole path".
     *
     * The lines are branches, and not everybody in the archive sits inside
     * one. The ancestors *above* the lines have no line to belong to, and
     * neither do the branches that were numbered and then died out — `7d8` sits
     * between lines 28 and 29 and is nobody's line. Those records carry
     * `GS/` and then the path itself, already expanded.
     *
     * Which makes `GS` the escape hatch the system needs to be complete: with
     * it, every position in the tree can be written down, and the calculator
     * reaches the deep ancestors it was previously blind to.
     *
     * **`GS` with nothing after it is the progenitor.** The empty path is the
     * one person every number descends from — the root the whole archive is
     * measured against — and he is as much a person as anybody else in it.
     */
    private const string WHOLE_TREE = 'gs';

    /** The setting holding the line table. */
    public const string SETTING_LINES = 'sack_lines';

    /** The setting holding the marriage table. */
    public const string SETTING_MARRIAGES = 'sack_marriages';

    /** The setting holding the branch table. */
    public const string SETTING_BRANCHES = 'sack_branches';

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
     * What the family calls the group of lines a number sits in.
     *
     * A line is not the coarsest division in the archive. Lines 8 to 14 are
     * together the Cleve branch, 21 to 31 the Rothenhof one, and a member
     * saying where they come from says *that* name, not "line 12" — the line
     * number is bookkeeping, the branch is where the family sat.
     *
     * The archive is one descent, so the groups are contiguous ranges of line
     * numbers and nothing overlaps. `GS` and `HS` are not ranges but heads of
     * their own, and they are the reason this is keyed by what is *written* in
     * front of the oblique rather than by the resolved path: `HS` is a
     * numbering the calculator does not read at all, and a member carrying one
     * should still be told which branch they are in.
     *
     * **A row may carry the name in more than one language**, separated by
     * `|`, each after the first tagged with the language it is in. The portal
     * is read in two languages and the API answers in the one it is being read
     * in (§2.17) — a branch name is a phrase, "Ernestinische Linie – Zweig
     * Cleve" against "Ernestine Line – Cleve Branch", and half of it is
     * grammar rather than a proper noun. The place *inside* it does not
     * change, which is why this is a second name and not a second table: the
     * family writes both, the way it says both.
     *
     * The untagged name comes first and is what a reader gets when their
     * language has no row of its own. So a family that adds a branch and
     * writes one name is not broken — every reader gets that name — and the
     * English can follow in its own evening.
     *
     * Ported from the webtrees badge the family already sees, so the portal
     * says the same thing as the back office.
     */
    public const string DEFAULT_BRANCHES = <<<'TEXT'
1-2   = Ernestinische Linie – Zweig Mansfeld  | en: Ernestine Line – Mansfeld Branch
3-4   = Ernestinische Linie – Zweig Pasewalk  | en: Ernestine Line – Pasewalk Branch
5-7   = Ernestinische Linie – Zweig Dessau    | en: Ernestine Line – Dessau Branch
8-14  = Ernestinische Linie – Zweig Cleve     | en: Ernestine Line – Cleve Branch
15-19 = Ernestinische Linie – Zweig Glogau    | en: Ernestine Line – Glogau Branch
20    = Ernestinische Linie – Zweig Lübeck    | en: Ernestine Line – Lübeck Branch
21-31 = Ernestinische Linie – Zweig Rothenhof | en: Ernestine Line – Rothenhof Branch
32-35 = Wilhelminische Linie                  | en: Wilhelmine Line
36    = Cramer-Linie                          | en: Cramer Line
GS    = Nachkommen von Georg Sack             | en: Descendants of Georg Sack
HS    = Nachkommen von Heinrich Sack          | en: Descendants of Heinrich Sack
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
    /**
     * How many equivalent writings of one number are ever enumerated.
     *
     * Marriages compound: two on a path give four writings, three give eight.
     * Measured against the family's own table the deepest number reaches a
     * handful, so this is a guard against a table edited into a cycle rather
     * than a limit anybody should meet. Reaching it means the nearest
     * relationship *might* be missed — never that a wrong one is named.
     */
    private const int MAX_WRITINGS = 64;

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

    /** @var array<int,array{head:string|null,low:int,high:int,name:string,translations:array<string,string>}>|null */
    private array|null $branches = null;

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
        // The oblique is optional only when nothing follows it. "24" and
        // "24/" are the head of line 24 and the archive writes both; "24b6"
        // is not offered, because a two-digit line makes it ambiguous — line
        // 24 descent "b6", or line 2 descent "4b6"?
        if (preg_match('/^(' . self::WHOLE_TREE . '|[0-9]{1,2})(?:\/([1-9a-z]*))?$/', $cleaned, $matches) !== 1) {
            return null;
        }

        $path = $this->prefixFor($matches[1]);

        if ($path === null) {
            return null;
        }

        // `GS` on its own is the empty path, and the empty path is the
        // progenitor — the one person every number in the archive descends
        // from. Not a missing answer: the arithmetic works on him like anybody
        // else, and it is the only way to name him at all.
        return $path . ($matches[2] ?? '');
    }

    /**
     * What the part in front of the oblique stands for.
     *
     * A line number stands for that line's prefix. `GS/` stands for nothing,
     * because a `GS` number is already the whole path — see `WHOLE_TREE`.
     */
    private function prefixFor(string $head): string|null
    {
        if ($head === self::WHOLE_TREE) {
            return '';
        }

        return $this->lines()[(int) $head] ?? null;
    }

    /**
     * Every equivalent way of writing one path.
     *
     * A number is one line of descent, and the archive files the children of
     * an in-family marriage under **one** parent. So a person whose ancestors
     * married within the family has more than one true number: `24/3133.42`
     * is also `24/b63.42`, because `24/313` and `24/b6` married and the
     * children went under his number. Both writings name the same person and
     * each measures a *different* distance to somebody else — which is what
     * being related twice over means.
     *
     * `merge()` solves the same problem for one *pair* of numbers: it re-roots
     * one side when the other happens to descend from the partner. That is the
     * right answer where there is one answer to give, and it is why the
     * calculator has been quietly naming the relationship of whichever writing
     * happened to be stored. This enumerates instead, so the nearest can be
     * found and the rest named beside it.
     *
     * Re-rooted with `merge()`'s own `reroot()`, join character and all: the
     * result is for measuring, not for writing on a card, and the `-` is what
     * stops the join from lining up with one of the other parent's own
     * children (see `merge()`).
     *
     * @return array<int,string> The path itself first, then its alternatives.
     */
    public function writings(string $path): array
    {
        // **A list, not a set keyed by the path.** A path that is all digits —
        // "7243215" is one — becomes an *integer* array key in PHP, and the
        // caller then hands an int to `merge()`, whose parameters are strings
        // by reference and cannot be coerced. `marriages()` carries the same
        // warning for the same reason; these sets hold a handful of entries,
        // so scanning one costs nothing.
        $writings = [$path];
        $queue    = [$path];

        // A marriage can sit above a marriage, so an alternative writing can
        // have alternatives of its own — hence the queue rather than one pass.
        while ($queue !== [] && count($writings) < self::MAX_WRITINGS) {
            $current = (string) array_shift($queue);

            foreach ($this->marriages() as ['left' => $left, 'right' => $right]) {
                $length = strlen($right);

                // Strictly below the couple: at or above them there is no
                // second descent to name, only the marriage itself.
                if ($length === 0 || strlen($current) <= $length || !$this->startsWith($current, $right)) {
                    continue;
                }

                // Never back the way we came. The family writes these
                // marriages in both directions, so re-rooting an alternative
                // at the join we just made walks straight back — and arrives
                // at the original path with its child index replaced by the
                // join character. That is not a second descent; it is the
                // first one with a digit missing, and being *shorter* on a
                // shared prefix it would read as a nearer relationship than
                // the truth. The join is the marker: a `-` where the next
                // character should be means this is our own footprint.
                if ($current[$length] === '-') {
                    continue;
                }

                $other = $this->reroot($current, $left, $length);

                if (in_array($other, $writings, true)) {
                    continue;
                }

                $writings[] = $other;
                $queue[]    = $other;
            }
        }

        return $writings;
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
     * The branch a written number belongs to, by name, or null.
     *
     * Read off what stands *in front of* the oblique rather than off the
     * resolved path, for two reasons. `HS` is a numbering the calculator does
     * not read, and its carriers still have a branch. And the branch is a
     * property of the line, so it is decided before a single character of the
     * descent has been looked at.
     *
     * **The oblique is required here**, though `path()` makes it optional. A
     * bare two-digit number is also what the archive's older, unrelated
     * numbering looks like once it reaches two digits — see §2.57 — and naming
     * the wrong branch on somebody's own record is a worse failure than naming
     * none. The 36 line heads written bare are the price, and they are written
     * "24/" as often as "24".
     *
     * The name comes back in the language the request is being answered in —
     * §2.17, the same rule the fact labels and the dates follow — or in the
     * table's untagged name where that language has none.
     *
     * @param string|null $language A language tag; the request's own when null.
     */
    public function branch(string $number, string|null $language = null): string|null
    {
        $head = $this->headOf($number);

        if ($head === null) {
            return null;
        }

        $line = preg_match('/^[0-9]{1,2}$/', $head) === 1 ? (int) $head : null;

        foreach ($this->branches() as $row) {
            $matches = $row['head'] !== null
                ? $row['head'] === $head
                : ($line !== null && $line >= $row['low'] && $line <= $row['high']);

            if ($matches) {
                return $this->named($row, $language ?? I18N::locale()->languageTag());
            }
        }

        return null;
    }

    /**
     * The row's name in one language, falling back to the one it was written
     * with. The rule, and the reasoning for it, live in `TranslatedText` —
     * branch names are not the only phrase the family writes in more than one
     * language, and there is one notation rather than two.
     *
     * @param array{name:string,translations:array<string,string>} $row
     */
    private function named(array $row, string $language): string
    {
        return TranslatedText::pick($row['name'], $row['translations'], $language);
    }

    /**
     * What stands in front of the oblique, lower-cased, or null where a number
     * has no oblique at all — see `branch()` for why that is not read.
     */
    private function headOf(string $number): string|null
    {
        $cleaned = strtolower(str_replace(' ', '', trim($number)));
        $matches = [];

        if (preg_match('/^([a-z]{2}|[0-9]{1,2})\//', $cleaned, $matches) !== 1) {
            return null;
        }

        return $matches[1];
    }

    /**
     * The branch table, as the family maintains it.
     *
     * A row is either a range of line numbers — "8-14", or "20" for a branch
     * that is one line — or a two-letter head of its own, like `GS`. A row
     * that is neither is dropped rather than fatal, for the same reason the
     * other two tables drop theirs: this is edited by hand, and one bad row
     * must not take the rest of it down.
     *
     * The name may be followed by `|` and further names, each tagged with the
     * language it is written in — see `DEFAULT_BRANCHES`.
     *
     * @return array<int,array{head:string|null,low:int,high:int,name:string,translations:array<string,string>}>
     */
    public function branches(): array
    {
        if ($this->branches !== null) {
            return $this->branches;
        }

        $table = [];

        foreach ($this->rows(self::SETTING_BRANCHES, self::DEFAULT_BRANCHES) as [$key, $value]) {
            $key     = strtolower($key);
            $matches = [];

            ['name' => $name, 'translations' => $translations] = $this->names($value);

            if ($name === '') {
                continue;
            }

            if (preg_match('/^([0-9]{1,2})(?:-([0-9]{1,2}))?$/', $key, $matches) === 1) {
                $low  = (int) $matches[1];
                $high = (int) ($matches[2] ?? $matches[1]);

                if ($low <= $high) {
                    $table[] = [
                        'head'         => null,
                        'low'          => $low,
                        'high'         => $high,
                        'name'         => $name,
                        'translations' => $translations,
                    ];
                }

                continue;
            }

            if (preg_match('/^[a-z]{2}$/', $key) === 1) {
                $table[] = [
                    'head'         => $key,
                    'low'          => 0,
                    'high'         => 0,
                    'name'         => $name,
                    'translations' => $translations,
                ];
            }
        }

        return $this->branches = $table;
    }

    /**
     * One row's names: the one it was written with, then the tagged ones.
     * `Name | en: Name | fr: Nom` — see `TranslatedText` for the notation.
     *
     * @return array{name:string,translations:array<string,string>}
     */
    private function names(string $value): array
    {
        ['text' => $name, 'translations' => $translations] = TranslatedText::parse($value);

        return ['name' => $name, 'translations' => $translations];
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

            if ($left === null || $right === null || $left === '' || $right === '') {
                // A number naming a line that no longer exists, or a typo —
                // or the progenitor, who married nobody inside his own family
                // and whose empty path would otherwise prefix-match every
                // number there is. Skipped rather than fatal: one bad row in a
                // table the family edits by hand must not take the whole
                // calculator down.
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
