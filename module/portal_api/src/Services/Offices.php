<?php

declare(strict_types=1);

namespace Engelking\Webtrees\PortalApi\Services;

use Fisharebest\Webtrees\Contracts\UserInterface;
use Fisharebest\Webtrees\DB;
use Fisharebest\Webtrees\Individual;

use function mb_substr;
use function preg_replace;
use function time;
use function trim;

/**
 * The offices of the foundation, and who holds them.
 *
 * A thin service over one table, and almost all of it is here to answer one
 * question cheaply: *does this person hold an office?* The directory asks it
 * once per row and the tree asks it once per relative, so the answer is read
 * once and kept for the request.
 *
 * **This deliberately does not consult webtrees' privacy.** An office is the
 * foundation's own statement about one of its officers, not the archive's
 * account of a person — the same argument `Recognition` makes for a portrait
 * somebody uploaded themselves. Members of a board are a public function; the
 * card already names the person, and withholding what they do for the family
 * would leave a name with no way to know why it is worth writing to.
 *
 * What it does *not* do is make anybody visible who was not: nothing here
 * turns an xref into a name, a year or a face. A hidden person on a page the
 * reader may not see stays absent; this only labels a card that was already
 * being drawn.
 */
class Offices
{
    /** The office of every person who holds one, by xref. @var array<string,string>|null */
    private ?array $titles = null;

    /** As long as `title` in the schema. Longer is a paragraph, not an office. */
    public const int MAX_TITLE = 128;

    public function __construct(private readonly PortalTreeService $trees)
    {
    }

    /**
     * The office held by the person at `$xref`, or null.
     *
     * Null for "holds none" and for "no such person" alike: a card that shows
     * nothing looks the same either way, and there is nothing here worth
     * telling the two apart for.
     */
    public function titleFor(?string $xref): ?string
    {
        if ($xref === null || $xref === '') {
            return null;
        }

        return $this->all()[$xref] ?? null;
    }

    /**
     * The office of the person a member's account is linked to.
     *
     * The link is webtrees' own `gedcomid` user setting — the member's own
     * account data rather than anything read out of the record — so this
     * answers for a member whose record the reader may not open, which is the
     * case the directory needs it for.
     */
    public function titleForMember(UserInterface $user): ?string
    {
        $individual = $this->trees->linkedIndividual($this->trees->tree(), $user);

        if (!$individual instanceof Individual) {
            return null;
        }

        return $this->titleFor($individual->xref());
    }

    /**
     * Every office, by xref.
     *
     * Read once per request. The table holds a handful of rows — a board, not
     * a membership list — so reading all of it beats a query per card by a
     * wide margin, and by more the longer the directory gets.
     *
     * @return array<string,string>
     */
    public function all(): array
    {
        if ($this->titles === null) {
            $this->titles = DB::table('portal_office')
                ->orderBy('sort_order')
                ->orderBy('id')
                ->pluck('title', 'xref')
                ->all();
        }

        return $this->titles;
    }

    /**
     * Every office in the order they should be listed, for the control panel.
     *
     * @return array<int,array{id:int,xref:string,title:string,sort_order:int}>
     */
    public function listed(): array
    {
        $rows = [];

        foreach (DB::table('portal_office')->orderBy('sort_order')->orderBy('id')->get() as $row) {
            $rows[] = [
                'id'         => (int) $row->id,
                'xref'       => (string) $row->xref,
                'title'      => (string) $row->title,
                'sort_order' => (int) $row->sort_order,
            ];
        }

        return $rows;
    }

    /**
     * Give the person at `$xref` an office, or change the one they hold.
     *
     * An empty title takes the office away rather than recording an officer
     * with no office — the same reading `ContactDetails` gives an emptied
     * field, and the only sense a blank can be meant in.
     *
     * @return bool Whether anything changed.
     */
    public function set(string $xref, string $title, int $sort_order = 0): bool
    {
        $xref  = trim($xref);
        $title = $this->clean($title);

        if ($xref === '') {
            return false;
        }

        if ($title === '') {
            return $this->remove($xref);
        }

        $existing = DB::table('portal_office')->where('xref', '=', $xref)->first();
        $now      = time();

        if ($existing === null) {
            DB::table('portal_office')->insert([
                'xref'       => $xref,
                'title'      => $title,
                'sort_order' => $sort_order,
                'created_at' => $now,
                'updated_at' => $now,
            ]);

            $this->titles = null;

            return true;
        }

        if ((string) $existing->title === $title && (int) $existing->sort_order === $sort_order) {
            return false;
        }

        DB::table('portal_office')
            ->where('xref', '=', $xref)
            ->update(['title' => $title, 'sort_order' => $sort_order, 'updated_at' => $now]);

        $this->titles = null;

        return true;
    }

    /** @return bool Whether there was one to take away. */
    public function remove(string $xref): bool
    {
        $deleted = DB::table('portal_office')->where('xref', '=', trim($xref))->delete();

        $this->titles = null;

        return $deleted > 0;
    }

    /**
     * A title as it will be stored.
     *
     * Control characters out, whitespace collapsed, length capped. The cap
     * truncates rather than refuses because this runs behind a form an
     * administrator typed into, and a silently shortened office is easier to
     * notice and correct than a saved form that says nothing happened.
     */
    private function clean(string $title): string
    {
        $title = (string) preg_replace('/[\x00-\x1F\x7F]+/u', ' ', $title);
        $title = (string) preg_replace('/\s+/u', ' ', $title);

        return mb_substr(trim($title), 0, self::MAX_TITLE);
    }
}
