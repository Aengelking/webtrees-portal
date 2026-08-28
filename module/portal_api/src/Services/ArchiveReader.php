<?php

declare(strict_types=1);

namespace Engelking\Webtrees\PortalApi\Services;

use Fisharebest\Webtrees\DB;
use Fisharebest\Webtrees\Family;
use Fisharebest\Webtrees\I18N;
use Fisharebest\Webtrees\Individual;
use Fisharebest\Webtrees\Note;
use Fisharebest\Webtrees\Registry;
use Fisharebest\Webtrees\Services\LinkedRecordService;
use Fisharebest\Webtrees\Services\SearchService;
use Illuminate\Support\Collection;

use function array_slice;
use function array_values;
use function count;
use function mb_stripos;
use function mb_strtolower;
use function max;
use function min;
use function trim;
use function usort;

/**
 * Every question the MCP server knows how to answer, and nothing else.
 *
 * The tools in `Mcp\ArchiveTools` are a thin skin over this class: they parse
 * arguments and hand back JSON. What a question *means* is decided here, and
 * every answer leaves through `ArchivePresenter`, which is where the
 * deceased-only rule lives. There is no method on this class that can return a
 * living person, and that is meant to be checkable by reading it rather than
 * by trusting it.
 *
 * **The searches lean on what the portal already has.** `TreeSearch` finds
 * people by name, nickname or archive number for the members' own search
 * screen, and finds them the same way here — a second implementation of "what
 * did somebody type" is a second set of bugs. It applies `SearchConsent`,
 * which is *weaker* than this rule (a living member who joined the directory
 * is findable there), so its results are narrowed again on the way out. The
 * narrowing is what matters, and it is done in one place.
 *
 * **The index does not.** `TreeSearch`'s surname and place counts are counts of
 * people a member may find, and a count that includes people this server will
 * not name is a count that answers a different question from the one asked.
 * So the scan is done here, bounded by the same ceiling and honest about it in
 * the same way when the ceiling is reached.
 */
class ArchiveReader
{
    /**
     * How many people or notes one tool call will return.
     *
     * Smaller than the portal's own pages on purpose. What comes out of here
     * goes into a model's context, where a hundred half-relevant results cost
     * more than they are worth; a caller who wants more asks a better question
     * or asks for the next page.
     */
    public const int MAX_RESULTS = 50;

    public const int DEFAULT_RESULTS = 20;

    /** How far up a pedigree one call will walk. */
    public const int MAX_GENERATIONS = 12;

    public const int DEFAULT_GENERATIONS = 4;

    /**
     * How many records the surname and place index will read.
     *
     * `TreeSearch::MAX_SCAN`'s reasoning, and deliberately its number: an
     * archive large enough to reach it needs an index built somewhere other
     * than inside a web request, and when it is reached the answer says so
     * rather than quietly being short.
     */
    public const int MAX_SCAN = TreeSearch::MAX_SCAN;

    /**
     * How many people one walk up or down will return.
     *
     * `AncestorTree::MAX_PEOPLE`'s number and its reasoning: a pedigree grows
     * by doubling, so the generation count is a poor bound on the size of the
     * answer and the number of people is the thing that actually costs. It
     * exists for the tree that surprises us rather than for any tree we
     * expect, and reaching it is reported rather than hidden.
     */
    public const int MAX_PEOPLE = AncestorTree::MAX_PEOPLE;

    /** Findable dead, read once per request. @var Collection<int,Individual>|null */
    private Collection|null $scanned = null;

    private bool $truncated = false;

    public function __construct(
        private readonly PortalTreeService $trees,
        private readonly ArchivePresenter $presenter,
        private readonly ArchiveNotes $notes,
        private readonly DeceasedOnly $rule,
        private readonly TreeSearch $search,
        private readonly SearchService $searches,
        private readonly LinkedRecordService $links,
        private readonly RelationshipNamer $relationships,
    ) {
    }

    /**
     * One person, in full.
     *
     * @return array<string,mixed>|null null for "no such record" and for "not
     *                                  yours to read" alike — the caller says
     *                                  one thing for both.
     */
    public function person(string $id): array|null
    {
        $individual = $this->individual($id);

        return $individual === null ? null : $this->presenter->person($individual, $this->accessLevel());
    }

    /**
     * People matching a name, a nickname or an archive number.
     *
     * @return array{people: array<int,array<string,mixed>>, total: int, truncated: bool}
     */
    public function searchPeople(string $query, int $limit): array
    {
        $query = trim($query);

        if ($query === '') {
            return ['people' => [], 'total' => 0, 'truncated' => false];
        }

        $level = $this->accessLevel();

        // One page, as wide as the search itself goes: the dead have to be
        // picked out of the whole result before it can be cut to size, or a
        // page of living members would arrive as a page of nothing.
        $found = $this->search->people($query, $level, 1, TreeSearch::MAX_MATCHES);
        $found = $this->presenter->references($found['items'], $level);
        $limit = $this->bounded($limit);

        return [
            'people'    => array_slice($found, 0, $limit),
            'total'     => count($found),
            'truncated' => count($found) > $limit,
        ];
    }

    /**
     * The line above somebody, in Ahnentafel order.
     *
     * **A living rung is a gap, not a wall.** Where somebody living stands
     * between the reader and the archive, the rung comes back without a person
     * on it and the walk carries on above them — the same answer the portal's
     * own pedigree gives (§2.75), for the same reason: an archive whose dead
     * are reachable only through its living is an archive nobody can reach.
     *
     * @return array{root: array<string,mixed>, rungs: array<int,array<string,mixed>>, truncated: bool}|null
     */
    public function ancestors(string $id, int $generations): array|null
    {
        $root  = $this->individual($id);
        $level = $this->accessLevel();

        if ($root === null || !$this->rule->mayRead($root, $level)) {
            return null;
        }

        $generations = max(1, min($generations, self::MAX_GENERATIONS));
        $rungs       = [];
        $pending     = [1 => $root];
        $truncated   = false;

        for ($generation = 0; $generation <= $generations; $generation++) {
            $next = [];

            foreach ($pending as $position => $individual) {
                if (count($rungs) >= self::MAX_PEOPLE) {
                    $truncated = true;

                    break 2;
                }

                $rungs[] = [
                    'position'   => $position,
                    'generation' => $generation,
                    'person'     => $this->presenter->reference($individual, $level),
                ];

                if ($generation === $generations) {
                    continue;
                }

                foreach ($this->parents($individual, $level) as $offset => $parent) {
                    $next[$position * 2 + $offset] = $parent;
                }
            }

            if ($next === []) {
                break;
            }

            $pending = $next;
        }

        return [
            'root'      => $this->presenter->reference($root, $level),
            'rungs'     => $rungs,
            'truncated' => $truncated,
        ];
    }

    /**
     * The line below somebody, generation by generation.
     *
     * The mirror of `ancestors()`, and the one that runs into the living
     * fastest — a nineteenth-century couple's descendants are mostly alive. So
     * the same rule applies in the same way: the person is withheld, the line
     * is not, and each generation says how many of it were kept back.
     *
     * @return array{root: array<string,mixed>, generations: array<int,array<string,mixed>>, truncated: bool}|null
     */
    public function descendants(string $id, int $generations): array|null
    {
        $root  = $this->individual($id);
        $level = $this->accessLevel();

        if ($root === null || !$this->rule->mayRead($root, $level)) {
            return null;
        }

        $generations = max(1, min($generations, self::MAX_GENERATIONS));
        $levels      = [];
        $pending     = [$root];
        $seen        = [$root->xref() => true];
        $truncated   = false;
        $counted     = 0;

        for ($generation = 1; $generation <= $generations; $generation++) {
            $next     = [];
            $people   = [];
            $withheld = 0;

            foreach ($pending as $individual) {
                foreach ($this->childrenOf($individual, $level) as $child) {
                    if (isset($seen[$child->xref()])) {
                        continue;
                    }

                    $seen[$child->xref()] = true;

                    if (++$counted > self::MAX_PEOPLE) {
                        $truncated = true;

                        break 3;
                    }

                    $next[] = $child;

                    $person = $this->presenter->reference($child, $level);

                    if ($person === null) {
                        $withheld++;
                    } else {
                        $people[] = $person;
                    }
                }
            }

            if ($next === []) {
                break;
            }

            $levels[] = ['generation' => $generation, 'people' => $people, 'withheld' => $withheld];
            $pending  = $next;
        }

        return [
            'root'        => $this->presenter->reference($root, $level),
            'generations' => $levels,
            'truncated'   => $truncated,
        ];
    }

    /**
     * How two people in the archive stand to one another.
     *
     * Both ends must be somebody this server may name. The path between them
     * need not be: webtrees' walk goes through whoever is there, and a
     * relationship name says that a link exists without saying who it is —
     * which is the same thing `withheld` says on a record, and the same thing
     * an empty rung says in a pedigree.
     *
     * @return array<string,mixed>|null
     */
    public function relationship(string $from_id, string $to_id): array|null
    {
        $level = $this->accessLevel();
        $from  = $this->individual($from_id);
        $to    = $this->individual($to_id);

        if ($from === null || $to === null) {
            return null;
        }

        if (!$this->rule->mayRead($from, $level) || !$this->rule->mayRead($to, $level)) {
            return null;
        }

        return [
            'from'         => $this->presenter->reference($from, $level),
            'to'           => $this->presenter->reference($to, $level),
            'relationship' => $this->relationships->name($from, $to, $level),
        ];
    }

    /**
     * The family's prose, searched.
     *
     * Two halves, because a note is written in two places. What is typed onto
     * a person lives in that person's own GEDCOM, so the individuals are
     * searched and their notes read; what is typed into a shared `NOTE`
     * record lives somewhere else entirely, so those are searched too and
     * followed back to the people who link to them.
     *
     * Either way the answer is a note **and the person it is about**. A note
     * that reaches nobody this server may name does not come back at all: the
     * deceased-only rule is a rule about what may be said, not only about
     * whose record may be opened, and a paragraph of family history detached
     * from its subject is no safer for being anonymous.
     *
     * @return array{notes: array<int,array<string,mixed>>, total: int, truncated: bool}
     */
    public function searchNotes(string $query, int $limit): array
    {
        $query = trim($query);

        if ($query === '') {
            return ['notes' => [], 'total' => 0, 'truncated' => false];
        }

        $tree  = $this->trees->tree();
        $level = $this->accessLevel();
        $found = [];

        foreach ($this->searches->searchIndividuals([$tree], [$query]) as $individual) {
            $this->collectNotes($found, $individual, $query, $level);
        }

        foreach ($this->searches->searchNotes([$tree], [$query]) as $note) {
            if (!$note instanceof Note) {
                continue;
            }

            foreach ($this->links->linkedIndividuals($note, 'NOTE') as $individual) {
                $this->collectNotes($found, $individual, $query, $level);
            }
        }

        $notes = array_values($found);
        $limit = $this->bounded($limit);

        return [
            'notes'     => array_slice($notes, 0, $limit),
            'total'     => count($notes),
            'truncated' => count($notes) > $limit,
        ];
    }

    /**
     * What surnames and what places the archive's dead were filed under.
     *
     * @return array{surnames: array<int,array{name: string, count: int}>, places: array<int,array{name: string, count: int}>, truncated: bool}
     */
    public function index(): array
    {
        $level    = $this->accessLevel();
        $surnames = [];
        $places   = [];
        $labels   = [];

        foreach ($this->scan($level) as $individual) {
            foreach ($this->surnamesOf($individual) as $key => $label) {
                $surnames[$key] ??= 0;
                $surnames[$key]++;
                $labels[$key] ??= $label;
            }

            foreach ($this->placesOf($individual, $level) as $key => $label) {
                $places[$key] ??= 0;
                $places[$key]++;
                $labels[$key] ??= $label;
            }
        }

        $collate = I18N::comparator();

        return [
            'surnames'  => $this->counted($surnames, $labels, static fn (array $a, array $b): int => $collate($a['name'], $b['name'])),
            'places'    => $this->counted($places, $labels, static function (array $a, array $b) use ($collate): int {
                return $b['count'] <=> $a['count'] ?: $collate($a['name'], $b['name']);
            }),
            'truncated' => $this->truncated,
        ];
    }

    // -----------------------------------------------------------------
    // Reading the tree
    // -----------------------------------------------------------------

    /**
     * The record behind an id, without deciding anything about it.
     *
     * Whether it may be read is `DeceasedOnly`'s question and is asked by the
     * caller, so that "no such person" and "not this one" are one answer.
     */
    private function individual(string $id): Individual|null
    {
        $id = trim($id);

        return $id === '' ? null : Registry::individualFactory()->make($id, $this->trees->tree());
    }

    private function accessLevel(): int
    {
        return $this->trees->accessLevel($this->trees->tree());
    }

    /**
     * A person's father and mother, as Ahnentafel offsets.
     *
     * The first child family only: a record with two of them is a record with
     * an adoption in it, and a pedigree that walks both lines produces two
     * people at one position.
     *
     * @return array<int,Individual> 0 for the father's side, 1 for the mother's.
     */
    private function parents(Individual $individual, int $access_level): array
    {
        $family = $individual->childFamilies($access_level)->first();

        if (!$family instanceof Family) {
            return [];
        }

        $parents = [];

        if ($family->husband($access_level) instanceof Individual) {
            $parents[0] = $family->husband($access_level);
        }

        if ($family->wife($access_level) instanceof Individual) {
            $parents[1] = $family->wife($access_level);
        }

        return $parents;
    }

    /**
     * @return array<int,Individual>
     */
    private function childrenOf(Individual $individual, int $access_level): array
    {
        $children = [];

        foreach ($individual->spouseFamilies($access_level) as $family) {
            foreach ($family->children($access_level) as $child) {
                $children[] = $child;
            }
        }

        return $children;
    }

    /**
     * Every note on one person that mentions what was asked for.
     *
     * Keyed so that a person found twice — once by their own GEDCOM and once
     * through a shared note — contributes each note once.
     *
     * @param array<string,array<string,mixed>> $found
     */
    private function collectNotes(array &$found, Individual $individual, string $query, int $access_level): void
    {
        if (!$this->rule->mayRead($individual, $access_level)) {
            return;
        }

        $person = $this->presenter->reference($individual, $access_level);

        if ($person === null) {
            return;
        }

        $notes = $this->notes->forRecord($individual, $access_level);

        foreach ($individual->facts([], false, $access_level, true) as $fact) {
            foreach ($this->notes->forFact($fact, $access_level) as $note) {
                $notes[] = ['event' => $fact->tag()] + $note;
            }
        }

        foreach ($notes as $note) {
            if (mb_stripos($note['text'], $query) === false) {
                continue;
            }

            $found[$individual->xref() . "\0" . $note['text']] = ['person' => $person] + $note;
        }
    }

    /**
     * Every readable dead person in the tree, read once.
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
            ->filter(fn (Individual $individual): bool => $this->rule->mayRead($individual, $access_level))
            ->values();
    }

    /**
     * @return array<string,string> lower-cased surname => the form to display
     */
    private function surnamesOf(Individual $individual): array
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
     * @param array<string,int>    $counts
     * @param array<string,string> $labels
     * @param callable(array{name: string, count: int}, array{name: string, count: int}): int $order
     *
     * @return array<int,array{name: string, count: int}>
     */
    private function counted(array $counts, array $labels, callable $order): array
    {
        $items = [];

        foreach ($counts as $key => $count) {
            $items[] = ['name' => $labels[$key] ?? $key, 'count' => $count];
        }

        usort($items, $order);

        return $items;
    }

    private function bounded(int $limit): int
    {
        return max(1, min($limit, self::MAX_RESULTS));
    }
}
