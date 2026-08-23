<?php

declare(strict_types=1);

namespace Engelking\Webtrees\PortalApi\Services;

use Fisharebest\Webtrees\DB;
use Fisharebest\Webtrees\Fact;
use Fisharebest\Webtrees\I18N;
use Fisharebest\Webtrees\Individual;
use Fisharebest\Webtrees\Registry;
use Fisharebest\Webtrees\Services\SearchService;
use Fisharebest\Webtrees\Tree;
use Illuminate\Support\Collection;

use function array_filter;
use function array_slice;
use function array_values;
use function count;
use function mb_stripos;
use function mb_strtolower;
use function preg_match;
use function preg_split;
use function str_replace;
use function strcasecmp;
use function trim;
use function usort;

/**
 * Looking through the tree, rather than walking it.
 *
 * Until now every route to a person went through somebody: your own record,
 * a relative of a relative, a member in the directory. That is a good way to
 * find your grandmother and a hopeless way to find the second cousin whose
 * name you half remember. This is the other way in — a name, a reference
 * number, a surname, a place.
 *
 * Two rules apply to everything that comes out of here, in this order:
 *
 * 1. webtrees' own access level, which is applied by the queries themselves
 *    and is the same one every other endpoint uses.
 * 2. `SearchConsent`, which asks the narrower question a search raises — see
 *    that class for the argument. Nothing here decides it; it is asked once,
 *    in one place, for every shape of result.
 *
 * **Why the free-text search queries and the indexes scan.** A search has a
 * term, so the database can find the handful of rows that match it and the
 * work is proportional to the answer. An index has no term: "which surnames
 * are in this tree, and how many people does each have" cannot be answered in
 * SQL without ignoring both rules above, and a count that ignores them is a
 * count of people the reader may not see. So the index is built by reading
 * the records — bounded by `MAX_SCAN`, and honest about it when the bound is
 * reached.
 */
class TreeSearch
{
    /** How many matches a free-text search will consider before giving up on being useful. */
    public const int MAX_MATCHES = 500;

    /**
     * How many records the indexes will read.
     *
     * A ceiling rather than a target: the family this portal serves is a few
     * thousand people, and a tree large enough to hit this needs an index
     * built somewhere other than inside a web request. When it is reached the
     * response says so rather than quietly showing a shorter list.
     */
    public const int MAX_SCAN = 5000;

    /** Findable records, read once per request. @var Collection<int,Individual>|null */
    private Collection|null $scanned = null;

    private bool $truncated = false;

    public function __construct(
        private readonly PortalTreeService $trees,
        private readonly SearchService $search,
        private readonly SearchConsent $consent,
    ) {
    }

    /**
     * People matching what somebody typed: a name, a nickname, or a
     * reference number.
     *
     * All three at once, because a member typing "1487", a member typing
     * "Bertha" and a member typing "Betty" are doing the same thing and should
     * not have to say which. The result sets are merged and de-duplicated, so
     * a person who matches more than one way is listed once.
     *
     * @return array{items: array<int,Individual>, total: int, truncated: bool}
     */
    public function people(string $query, int $access_level, int $page, int $per_page): array
    {
        $query = trim($query);

        if ($query === '') {
            return ['items' => [], 'total' => 0, 'truncated' => false];
        }

        $tree  = $this->trees->tree();
        $found = [];

        foreach ($this->byName($tree, $query) as $individual) {
            $found[$individual->xref()] = $individual;
        }

        foreach ($this->byReference($tree, $query, $access_level) as $individual) {
            $found[$individual->xref()] = $individual;
        }

        foreach ($this->byNickname($tree, $query, $access_level) as $individual) {
            $found[$individual->xref()] = $individual;
        }

        $truncated = count($found) >= self::MAX_MATCHES;

        return $this->page($this->keep(array_values($found)), $page, $per_page, $truncated);
    }

    /**
     * Everybody whose surname is exactly this one.
     *
     * Exact, not "contains": this is the second half of the surname index, and
     * the member got here by tapping a name that came out of it. A member who
     * wants "everything like Müller" has the search field for that.
     *
     * @return array{items: array<int,Individual>, total: int, truncated: bool}
     */
    public function bySurname(string $surname, int $access_level, int $page, int $per_page): array
    {
        $wanted = mb_strtolower(trim($surname));

        $matching = $this->scan($access_level)
            ->filter(fn (Individual $individual): bool => $this->surnamesOf($individual)[$wanted] ?? false)
            ->values()
            ->all();

        return $this->page($matching, $page, $per_page, $this->truncated);
    }

    /**
     * Everybody with an event in exactly this place.
     *
     * @return array{items: array<int,Individual>, total: int, truncated: bool}
     */
    public function byPlace(string $place, int $access_level, int $page, int $per_page): array
    {
        $wanted = mb_strtolower(trim($place));

        $matching = $this->scan($access_level)
            ->filter(fn (Individual $individual): bool => isset($this->placesOf($individual, $access_level)[$wanted]))
            ->values()
            ->all();

        return $this->page($matching, $page, $per_page, $this->truncated);
    }

    /**
     * The surname index: every surname in the tree, with how many people carry it.
     *
     * Sorted by name rather than by count, because this is the thing a member
     * reads down looking for their own — the count is what tells them whether
     * tapping it is worth doing.
     *
     * @return array{items: array<int,array{name: string, count: int}>, truncated: bool}
     */
    public function surnames(int $access_level): array
    {
        $counts = [];
        $labels = [];

        foreach ($this->scan($access_level) as $individual) {
            foreach ($this->surnamesOf($individual) as $key => $ignored) {
                $counts[$key] ??= 0;
                $counts[$key]++;
                $labels[$key] ??= $this->surnameLabels($individual)[$key] ?? $key;
            }
        }

        $collate = I18N::comparator();

        return $this->index($counts, $labels, static fn (array $a, array $b): int => $collate($a['name'], $b['name']));
    }

    /**
     * The place index: every place an event happened in, with how many people.
     *
     * Sorted by count, then by name. Unlike surnames, nobody reads a place
     * list looking for one particular entry — they read it to see where this
     * family has been, and the answer to that is the places with people in
     * them.
     *
     * @return array{items: array<int,array{name: string, count: int}>, truncated: bool}
     */
    public function places(int $access_level): array
    {
        $counts = [];
        $labels = [];

        foreach ($this->scan($access_level) as $individual) {
            foreach ($this->placesOf($individual, $access_level) as $key => $label) {
                $counts[$key] ??= 0;
                $counts[$key]++;
                $labels[$key] ??= $label;
            }
        }

        $collate = I18N::comparator();

        return $this->index($counts, $labels, static function (array $a, array $b) use ($collate): int {
            return $b['count'] <=> $a['count'] ?: $collate($a['name'], $b['name']);
        });
    }

    /**
     * The consent rule, and then the order a member reads a list of names in.
     *
     * @param array<int,Individual> $people
     *
     * @return array<int,Individual>
     */
    private function keep(array $people): array
    {
        $kept = array_values(array_filter(
            $people,
            fn (Individual $individual): bool => $this->consent->mayFind($individual)
        ));

        $collate = I18N::comparator();

        usort($kept, static fn (Individual $a, Individual $b): int => $collate($a->sortName(), $b->sortName()));

        return $kept;
    }

    /**
     * @param array<int,Individual> $people
     *
     * @return array{items: array<int,Individual>, total: int, truncated: bool}
     */
    private function page(array $people, int $page, int $per_page, bool $truncated): array
    {
        return [
            'items'     => array_slice($people, ($page - 1) * $per_page, $per_page),
            'total'     => count($people),
            'truncated' => $truncated,
        ];
    }

    /**
     * @param array<string,int>    $counts
     * @param array<string,string> $labels
     * @param callable(array{name: string, count: int}, array{name: string, count: int}): int $order
     *
     * @return array{items: array<int,array{name: string, count: int}>, truncated: bool}
     */
    private function index(array $counts, array $labels, callable $order): array
    {
        $items = [];

        foreach ($counts as $key => $count) {
            $items[] = ['name' => $labels[$key] ?? $key, 'count' => $count];
        }

        usort($items, $order);

        return ['items' => $items, 'truncated' => $this->truncated];
    }

    /**
     * Every findable person in the tree, read once.
     *
     * @return Collection<int,Individual>
     */
    private function scan(int $access_level): Collection
    {
        if ($this->scanned !== null) {
            return $this->scanned;
        }

        $tree = $this->trees->tree();
        $rows = DB::table('individuals')
            ->where('i_file', '=', $tree->id())
            ->select(['individuals.*'])
            ->limit(self::MAX_SCAN + 1)
            ->get();

        $this->truncated = $rows->count() > self::MAX_SCAN;

        return $this->scanned = $rows
            ->take(self::MAX_SCAN)
            ->map(Registry::individualFactory()->mapper($tree))
            ->filter(static fn (Individual $individual): bool => $individual->canShow($access_level))
            ->filter(fn (Individual $individual): bool => $this->consent->mayFind($individual))
            ->values();
    }

    /**
     * The names a person is filed under, keyed by their lower-cased form.
     *
     * Every name, not only the primary one: a woman recorded with both her
     * maiden and married surnames belongs in the index under both, which is
     * the whole reason a genealogy database keeps them.
     *
     * @return array<string,true>
     */
    private function surnamesOf(Individual $individual): array
    {
        $keys = [];

        foreach ($this->surnameLabels($individual) as $key => $ignored) {
            $keys[$key] = true;
        }

        return $keys;
    }

    /**
     * @return array<string,string> lower-cased surname => the form to display
     */
    private function surnameLabels(Individual $individual): array
    {
        $labels = [];

        foreach ($individual->getAllNames() as $name) {
            $surname = trim($name['surname'] ?? '');

            if ($surname === '' || $surname === '@N.N.') {
                continue;
            }

            $labels[mb_strtolower($surname)] ??= $surname;
        }

        return $labels;
    }

    /**
     * The places this person's life touched, keyed by their lower-cased form.
     *
     * @return array<string,string> lower-cased place => the form to display
     */
    private function placesOf(Individual $individual, int $access_level): array
    {
        $places = [];

        foreach ($individual->facts([], false, $access_level, true) as $fact) {
            $place = trim($fact->place()->gedcomName());

            if ($place === '') {
                continue;
            }

            $places[mb_strtolower($place)] ??= $place;
        }

        return $places;
    }

    /**
     * @return array<int,Individual>
     */
    private function byName(Tree $tree, string $query): array
    {
        $terms = preg_split('/\s+/', $query) ?: [];

        return $this->search
            ->searchIndividualNames([$tree], array_values(array_filter($terms)), 0, self::MAX_MATCHES)
            ->all();
    }

    /**
     * People whose archive number is exactly what was typed.
     *
     * **Exact, and not digits-only.** This family's numbers are "4712" but
     * also "10/1335.21", so reducing the query to its digits would make half
     * of them unfindable by the form they are written and quoted in. What is
     * required instead is that the query contain a digit at all — otherwise
     * every name search would drag the whole GEDCOM through a `LIKE`.
     *
     * The `LIKE` is a way of not reading the whole tree, not the test: it
     * narrows the rows to those whose GEDCOM mentions the text at all, and
     * then the number is compared properly, against the REFN facts the member
     * is allowed to see. A confidential reference number is filtered out by
     * `facts()` at that point, so quoting it finds nobody — which is the same
     * answer the record itself gives.
     *
     * @return array<int,Individual>
     */
    private function byReference(Tree $tree, string $query, int $access_level): array
    {
        $needle = trim($query);

        if ($needle === '' || preg_match('/\d/', $needle) !== 1) {
            return [];
        }

        $rows = DB::table('individuals')
            ->where('i_file', '=', $tree->id())
            ->where('i_gedcom', 'LIKE', '%REFN ' . $this->loosened($needle) . '%')
            ->select(['individuals.*'])
            ->limit(self::MAX_MATCHES)
            ->get();

        $mapper = Registry::individualFactory()->mapper($tree);
        $found  = [];

        foreach ($rows as $row) {
            $individual = $mapper($row);

            if (!$individual->canShow($access_level)) {
                continue;
            }

            // Case-insensitively: the archive writes "24/b521.12" in lower
            // case and "GS/7D8" in upper, and nobody typing one of them into a
            // search box should have to remember which.
            $matches = $individual->facts(['REFN'], false, $access_level)
                ->contains(static fn (Fact $fact): bool => strcasecmp(trim($fact->value()), $needle) === 0);

            if ($matches) {
                $found[] = $individual;
            }
        }

        return $found;
    }

    /**
     * People the family calls something the archive did not write in their name.
     *
     * A nickname can be written inside the name — `Bertha "Betty" /Beispiel/`,
     * which the name index already holds and `byName()` already finds — or as
     * a `2 NICK` subtag of it. webtrees builds its index from the name value
     * alone, so the second kind is in no index at all: searching for "Betty"
     * finds nobody, however plainly the record says it.
     *
     * The `LIKE` is again a way of not reading the whole tree rather than the
     * test. It is deliberately loose — `%NICK %term%` matches the term
     * anywhere after any `NICK` line — and the nickname is then compared
     * properly, against the NAME facts this member is allowed to see.
     *
     * @return array<int,Individual>
     */
    private function byNickname(Tree $tree, string $query, int $access_level): array
    {
        $needle = trim($query);

        if ($needle === '') {
            return [];
        }

        $rows = DB::table('individuals')
            ->where('i_file', '=', $tree->id())
            ->where('i_gedcom', 'LIKE', '%NICK %' . $this->loosened($needle) . '%')
            ->select(['individuals.*'])
            ->limit(self::MAX_MATCHES)
            ->get();

        $mapper = Registry::individualFactory()->mapper($tree);
        $found  = [];

        foreach ($rows as $row) {
            $individual = $mapper($row);

            if (!$individual->canShow($access_level) || !$individual->canShowName($access_level)) {
                continue;
            }

            $matches = $individual->facts(['NAME'], false, $access_level)
                ->contains(static fn (Fact $fact): bool => mb_stripos($fact->attribute('NICK'), $needle) !== false);

            if ($matches) {
                $found[] = $individual;
            }
        }

        return $found;
    }

    /**
     * `%` in what somebody typed is a character, not a wildcard.
     *
     * Not escaped — escaping means an `ESCAPE` clause, and SQLite has no
     * default escape character where MySQL does, so the same pattern would
     * mean two things on two hosts. It is turned into `_` instead, which
     * matches any single character and therefore matches a literal `%` too.
     * That makes the pattern slightly *looser*, which costs nothing: this
     * `LIKE` is only a way of not reading the whole tree, and every row it
     * returns is then compared properly in PHP.
     */
    private function loosened(string $needle): string
    {
        return str_replace('%', '_', $needle);
    }
}
