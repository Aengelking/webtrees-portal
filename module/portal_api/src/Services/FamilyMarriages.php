<?php

declare(strict_types=1);

namespace Engelking\Webtrees\PortalApi\Services;

use Fisharebest\Webtrees\Auth;
use Fisharebest\Webtrees\DB;
use Fisharebest\Webtrees\Family;
use Fisharebest\Webtrees\Individual;
use Fisharebest\Webtrees\Registry;

use function str_contains;
use function str_starts_with;
use function strip_tags;
use function strlen;
use function trim;
use function usort;

/**
 * Marriages inside the family, as the *tree* has them.
 *
 * The relationship calculator needs a table of these — see `SackNumbers` — and
 * the table is maintained by hand. That was fine while it was the only thing
 * that existed. Since §2.94 it decides something bigger: a marriage recorded
 * there gives every descendant a second archive number and therefore a second,
 * often nearer, relationship to everybody else. A row that is missing does not
 * produce a wrong answer with a warning on it; it produces a confident answer
 * that is too distant, and nothing says so.
 *
 * So this reads the tree and reports what the table ought to contain. It is a
 * scan, not a fix: the table stays what the family wrote, and this says where
 * the two disagree.
 *
 * **What counts as one of these marriages.** A family where *both* spouses
 * carry a readable archive number. That is the whole rule, and it is the same
 * one the table exists for: two people who each have a number, whose children
 * can only be filed under one of them.
 *
 * **Which side the children are filed under is read, not guessed.** A row says
 * "descendants under the right-hand number also descend from the left-hand
 * one", so the right-hand side has to be the parent the children actually sit
 * beneath. That is a fact about the records, and a child's own number is where
 * it is written: whichever parent's number is the start of the child's is the
 * one they were filed under. A row written the other way round is not a
 * different opinion, it is a row that will never match anybody — which is why
 * `wrong_way` is reported apart from `missing`.
 *
 * Read at `PRIV_HIDE`, like the offices screen and for the same reason: this
 * runs in the control panel, which webtrees has already decided the reader may
 * open, and a list of xrefs with the living silently absent would be a scan
 * that lies about what it scanned.
 */
class FamilyMarriages
{
    /**
     * How many families to read. The archive is a few thousand; this is a
     * guard against a tree far larger than the one this was built for, and
     * the screen says when it was reached rather than quietly showing less.
     */
    private const int MAX_FAMILIES = 20000;

    public function __construct(
        private readonly PortalTreeService $trees,
        private readonly SackNumbers $numbers,
        private readonly SackRelationship $sack,
    ) {
    }

    /**
     * Every in-family marriage the tree has, and what the table makes of it.
     *
     * @return array{
     *     rows:array<int,array{
     *         xref:string,
     *         husband:array{xref:string,name:string,number:string|null},
     *         wife:array{xref:string,name:string,number:string|null},
     *         filed_under:string|null,
     *         other:string|null,
     *         state:string,
     *         suggestion:string|null
     *     }>,
     *     truncated:bool
     * }
     */
    public function scan(): array
    {
        $tree      = $this->trees->tree();
        $families  = DB::table('families')
            ->where('f_file', '=', $tree->id())
            ->select(['families.*'])
            ->limit(self::MAX_FAMILIES + 1)
            ->get();

        $truncated = $families->count() > self::MAX_FAMILIES;
        $rows      = [];

        foreach ($families->take(self::MAX_FAMILIES) as $record) {
            $family = Registry::familyFactory()->mapper($tree)($record);

            if (!$family instanceof Family) {
                continue;
            }

            $row = $this->examine($family);

            if ($row !== null) {
                $rows[] = $row;
            }
        }

        // What needs doing first: the rows that will never match anybody, then
        // the ones that are not there at all, then everything already right.
        $rank = ['wrong_way' => 0, 'missing' => 1, 'unclear' => 2, 'recorded' => 3];

        usort(
            $rows,
            static fn (array $a, array $b): int => [$rank[$a['state']], $a['xref']] <=> [$rank[$b['state']], $b['xref']]
        );

        return ['rows' => $rows, 'truncated' => $truncated];
    }

    /**
     * One family, or null where it is not one of these marriages at all.
     *
     * @return array<string,mixed>|null
     */
    private function examine(Family $family): array|null
    {
        $husband = $family->husband(Auth::PRIV_HIDE);
        $wife    = $family->wife(Auth::PRIV_HIDE);

        if (!$husband instanceof Individual || !$wife instanceof Individual) {
            return null;
        }

        $his  = $this->numberOf($husband);
        $hers = $this->numberOf($wife);

        if ($his === null || $hers === null) {
            // Only one of them belongs to the family by descent, so their
            // children have one number and nothing is hidden.
            return null;
        }

        $filed = $this->filedUnder($family, $his, $hers);

        return [
            'xref'        => $family->xref(),
            'husband'     => $this->person($husband, $his),
            'wife'        => $this->person($wife, $hers),
            'filed_under' => $filed,
            'other'       => $filed === null ? null : ($filed === $his ? $hers : $his),
            'state'       => $this->state($his, $hers, $filed),
            'suggestion'  => $filed === null ? null : ($filed === $his ? $hers : $his) . ' = ' . $filed,
        ];
    }

    /**
     * Where this couple's children are written.
     *
     * A child's number begins with the number of the parent they were filed
     * under, so the children answer this themselves. Children with no number
     * of their own say nothing and are passed over; a couple whose children
     * all say nothing leaves this null, and the screen says "cannot tell"
     * rather than choosing.
     */
    private function filedUnder(Family $family, string $his, string $hers): string|null
    {
        $his_path  = (string) $this->numbers->path($his);
        $hers_path = (string) $this->numbers->path($hers);

        foreach ($family->children(Auth::PRIV_HIDE) as $child) {
            $number = $this->numberOf($child);

            if ($number === null) {
                continue;
            }

            $path = (string) $this->numbers->path($number);

            if ($his_path !== '' && strlen($path) > strlen($his_path) && str_starts_with($path, $his_path)) {
                return $his;
            }

            if ($hers_path !== '' && strlen($path) > strlen($hers_path) && str_starts_with($path, $hers_path)) {
                return $hers;
            }
        }

        return null;
    }

    /**
     * What the table says about this couple, in the words the screen sorts by.
     *
     * `recorded` — a row exists and points the way the records do.
     * `wrong_way` — a row exists for the pair, with the sides swapped. It will
     *   never match a descendant, so it is doing nothing at all.
     * `missing` — the tree has the marriage and the table does not.
     * `unclear` — no child carries a number, so which side they are filed
     *   under cannot be read. Left for a person to decide.
     */
    private function state(string $his, string $hers, string|null $filed): string
    {
        if ($filed === null) {
            return $this->recorded($his, $hers) || $this->recorded($hers, $his) ? 'recorded' : 'unclear';
        }

        $other = $filed === $his ? $hers : $his;

        if ($this->recorded($other, $filed)) {
            return 'recorded';
        }

        return $this->recorded($filed, $other) ? 'wrong_way' : 'missing';
    }

    /** Whether the table holds `$left = $right`, as paths rather than as text. */
    private function recorded(string $left, string $right): bool
    {
        $left_path  = $this->numbers->path($left);
        $right_path = $this->numbers->path($right);

        if ($left_path === null || $right_path === null) {
            return false;
        }

        foreach ($this->numbers->marriages() as $marriage) {
            if ($marriage['left'] === $left_path && $marriage['right'] === $right_path) {
                return true;
            }
        }

        return false;
    }

    /**
     * The archive number on a record, or null.
     *
     * The same reading `RelationshipNamer` uses: not filtered by `TYPE`, and a
     * number carrying an oblique wins over a bare one, because two digits with
     * no oblique are also what the archive's older numbering looks like.
     */
    private function numberOf(Individual $individual): string|null
    {
        $bare = null;

        foreach ($individual->facts(['REFN'], false, Auth::PRIV_HIDE) as $fact) {
            $value = trim($fact->value());

            if ($value === '' || !$this->sack->isNumber($value)) {
                continue;
            }

            if (str_contains($value, '/')) {
                return $value;
            }

            $bare ??= $value;
        }

        return $bare;
    }

    /** @return array{xref:string,name:string,number:string|null} */
    private function person(Individual $individual, string|null $number): array
    {
        return [
            'xref'   => $individual->xref(),
            'name'   => strip_tags($individual->fullName()),
            'number' => $number,
        ];
    }
}
