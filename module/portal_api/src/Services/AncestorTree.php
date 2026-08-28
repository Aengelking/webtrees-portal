<?php

declare(strict_types=1);

namespace Engelking\Webtrees\PortalApi\Services;

use Fisharebest\Webtrees\Auth;
use Fisharebest\Webtrees\Elements\RestrictionNotice;
use Fisharebest\Webtrees\Family;
use Fisharebest\Webtrees\GedcomRecord;
use Fisharebest\Webtrees\Individual;

use function array_key_exists;
use function count;
use function preg_match;
use function str_starts_with;

/**
 * A person's ancestors, several generations at a time.
 *
 * The portal shows these as indented columns rather than a drawn chart, and it
 * needs them in one response: fifteen separate round trips to build four
 * generations would be slow on a phone and would hammer the host.
 *
 * Positions are Ahnentafel numbers — the root is 1, a father is 2n and a
 * mother is 2n+1 — which is the oldest and simplest way to write a pedigree
 * down flat. The client indexes by number and never has to walk a nested
 * structure.
 *
 * ## The rule this screen goes by (§2.75)
 *
 * **The shape of the pedigree is shown; who stands in it is not, unless the
 * reader may see them.** A rung the member may not read comes back as an
 * anonymous placeholder — a position and nothing else — and the walk carries
 * on above it. So the dead the family archive exists to keep are reachable
 * through the living, and the living are not named.
 *
 * This is a deliberate reversal of what this class did in Phase 3, where a
 * person the member could not see ended the branch: no entry, no placeholder,
 * nothing above them. That was the right shape for a screen that was only a
 * faster way of tapping from relative to relative. It stopped being the right
 * shape once the family put a relationship path length on member accounts
 * (§2.25), because from then on nearly every line ran into a living person
 * within two or three rungs and simply stopped — a genealogy portal whose
 * genealogy ended at the reader's grandparents.
 *
 * What a placeholder discloses is that somebody occupies that position, and
 * that is the whole of it: no name, no dates, no picture, no archive number,
 * no XREF — so it is not a link, and it cannot be used to ask the API anything
 * further. What it does *not* disclose is whether that person is alive, or
 * hidden for some other reason; every kind of "not yours to read" produces the
 * identical entry, which is the same discipline `individualRef()` keeps
 * everywhere else in the module.
 *
 * **The one exception is a person's own consent.** Where the record belongs to
 * a member who put themselves in the portal's directory, the placeholder
 * carries the name they publish there and the member id behind it — because
 * every member can already read both, on the directory screen, by that
 * person's own decision. It is portal data, not genealogy data: the record
 * itself stays shut, and nothing from it — not a birth year, not a place, not
 * a photograph — travels with the name. See `listedMember()`.
 */
class AncestorTree
{
    /**
     * How far back the walk will go, and why it is not a small number.
     *
     * It used to be six, on the reasoning that more than that stops being
     * something a phone renders. That was the wrong quantity to bound. This
     * archive is measured from one man — every SB number is a path down from
     * Georg Sack, which is what `GS` means (§2.58) — and a pedigree that stops
     * four generations up stops in the nineteenth century, in the middle of
     * the thing the family keeps the archive *for*. What a member asked for is
     * the line back to the base, and the base is roughly a dozen generations
     * away.
     *
     * Twenty covers that with room to spare, and costs nothing where the
     * archive is shallower: the walk stops the moment a generation turns up
     * empty, which is nearly always long before this.
     */
    public const int MAX_GENERATIONS = 20;

    /** Asked for when the caller does not say: as far back as the archive goes. */
    public const int DEFAULT_GENERATIONS = self::MAX_GENERATIONS;

    /**
     * And the bound that actually protects the response.
     *
     * Generations are the wrong unit to limit by, because a pedigree grows by
     * doubling and a *complete* one twenty deep would be a million people. Real
     * ones are nothing like complete — lines run out within three or four rungs
     * except the one the archive was built to follow — so in practice this is
     * never reached. It is here for the tree that surprises us, so that a
     * strange record cannot turn one screen into a megabyte.
     *
     * Reaching it is reported rather than hidden: see `Pedigree::$truncated`.
     */
    public const int MAX_PEOPLE = 400;

    public function __construct(
        private readonly RecordPresenter $presenter,
        private readonly MemberService $members,
    ) {
    }

    /**
     * @param int             $generations How many generations *above* the root to include.
     * @param Individual|null $viewer      The reader's own record, so each
     *                                     rung can say how they stand to it.
     *
     * @return Pedigree One entry per ancestor, the root included, in
     *                  Ahnentafel order. Entries the reader may not read are
     *                  present as placeholders.
     */
    public function build(
        Individual $root,
        int $access_level,
        int $generations,
        Individual|null $viewer = null
    ): Pedigree
    {
        $generations = max(1, min($generations, self::MAX_GENERATIONS));

        $people    = [];
        $pending   = [1 => $root];
        $truncated = false;

        for ($generation = 0; $generation <= $generations; $generation++) {
            $next = [];

            foreach ($pending as $position => $individual) {
                if (count($people) >= self::MAX_PEOPLE) {
                    $truncated = true;

                    break 2;
                }

                $people[] = ['position' => $position, 'generation' => $generation]
                    + $this->rung($individual, $access_level, $viewer);

                if ($generation === $generations) {
                    continue;
                }

                foreach ($this->parents($individual, $access_level) as $offset => $parent) {
                    $next[$position * 2 + $offset] = $parent;
                }
            }

            // Every line has run out. Twenty generations of looking at nothing
            // is twenty generations of doing nothing, and the common tree is
            // three deep.
            if ($next === []) {
                break;
            }

            $pending = $next;
        }

        return new Pedigree($people, $truncated);
    }

    /**
     * Is there a rung above this person at all?
     *
     * Asked by the member screen, which offers a way into the pedigree of
     * somebody whose record it may not open, and should not offer a door onto
     * an empty room. Deliberately the same question `build()` asks — the first
     * child family, the same restriction test, the same reader — so the button
     * and the screen behind it cannot disagree.
     *
     * It answers about the *shape*: true where somebody stands above, whether
     * or not this reader may read them. That is the whole point of §2.75, and
     * it is why a member with only living parents still gets the door.
     */
    public function hasParents(Individual $individual, int $access_level): bool
    {
        return $this->parents($individual, $access_level) !== [];
    }

    /**
     * One rung: the person, or the fact that somebody is standing there.
     *
     * `individualRef()` is the module's single gate on genealogy data and it
     * stays that: where it answers, its answer is passed through untouched;
     * where it refuses, nothing from the record is read here instead.
     *
     * @return array<string,mixed>
     */
    private function rung(Individual $individual, int $access_level, Individual|null $viewer): array
    {
        $ref = $this->presenter->individualRef($individual, $access_level, $viewer);

        if ($ref !== null) {
            return ['private' => false] + $ref;
        }

        return ['private' => true, 'member' => $this->listedMember($individual, $access_level)];
    }

    /**
     * The directory listing this record belongs to, where there is one.
     *
     * **Consent, not a hole in the privacy rule.** A member who switches
     * themselves into the directory has decided that every other member may
     * see their name and open their member page; the directory screen has
     * published exactly that since Phase 2. Repeating it on a rung of a
     * pedigree adds one thing — that this listed member stands at this
     * position — and that is precisely what the family asked this screen to
     * show. No genealogy data comes with it: the record stayed shut, and what
     * is published here is the display name from `portal_member_profile`,
     * which `Member` exists to keep separate from the tree.
     *
     * **An explicit restriction on the record outranks it.** A `1 RESN
     * confidential` or `1 RESN privacy`, or a per-record privacy level set in
     * webtrees' control panel, is somebody who keeps the archive saying that
     * *this record* is not to be shown — a different question from what the
     * person publishes about themselves in the portal, and not one a switch in
     * the portal's settings should be able to answer. Such a rung stays a bare
     * placeholder. `RESN locked` is not among them: it forbids editing, not
     * reading, and reading it as a privacy notice would quietly hide people
     * the archive never meant to hide.
     *
     * The test mirrors `GedcomRecord::canShowRecord()` line for line, on
     * purpose — if webtrees changes what counts as a restriction, this should
     * change with it and not drift into its own second opinion.
     *
     * @return array<string,mixed>|null
     */
    private function listedMember(Individual $individual, int $access_level): array|null
    {
        if ($this->restrictedFrom($individual, $access_level)) {
            return null;
        }

        $member = $this->members->listedByXref($individual->tree())[$individual->xref()] ?? null;

        if ($member === null) {
            return null;
        }

        return ['id' => $member->id, 'display_name' => $member->display_name];
    }

    /**
     * Whether the record's **own** restriction hides it from *this* reader.
     *
     * The question webtrees asks, asked the same way. It has cost this class
     * two pedigrees to arrive at, and both mistakes were the same mistake in
     * different clothes: asking whether a record carries a restriction instead
     * of whether that restriction applies to the person reading.
     *
     * `RESN` is not a flag, it is a level. `confidential` means "managers";
     * `privacy` means "members"; `none` means everybody; `locked` is not about
     * reading at all — it forbids editing, and an archive that locks records
     * against accidental change, as this one does, must not lose its lines for
     * it. `GedcomRecord::canShowRecord()` is mirrored below so that if webtrees
     * ever changes what a notice means, this changes with it rather than
     * growing a second opinion.
     *
     * **Deliberately only the record's own notice**, which is why this exists
     * at all rather than a call to `Family::canShow()`. webtrees hides a family
     * whenever *any* of its members is private (§2.20), so one confidential
     * cousin would end a line that has nothing confidential about it. What is
     * asked here is whether somebody said that *this record* is not to be
     * shown.
     *
     * `getIndividualPrivacy()` is keyed by XREF whatever the record type,
     * because `default_resn` is, so one lookup serves people and families
     * alike.
     */
    private function restrictedFrom(GedcomRecord $record, int $access_level): bool
    {
        if (preg_match('/\n1 RESN (.+)/', $record->gedcom(), $match) === 1) {
            $restriction = (new RestrictionNotice(''))->canonical($match[1]);

            if (str_starts_with($restriction, RestrictionNotice::VALUE_CONFIDENTIAL)) {
                return Auth::PRIV_NONE < $access_level;
            }

            if (str_starts_with($restriction, RestrictionNotice::VALUE_PRIVACY)) {
                return Auth::PRIV_USER < $access_level;
            }

            if (str_starts_with($restriction, RestrictionNotice::VALUE_NONE)) {
                return false;
            }
        }

        $privacy = $record->tree()->getIndividualPrivacy();

        return array_key_exists($record->xref(), $privacy) && $privacy[$record->xref()] < $access_level;
    }

    /**
     * The father at offset 0 and the mother at offset 1.
     *
     * Only the first child-family is followed. A record can have several — an
     * adoptive family beside a birth family — and webtrees puts the primary one
     * first; picking one keeps the Ahnentafel numbering meaningful, which is
     * the whole reason for using it.
     *
     * **Read at `Auth::PRIV_HIDE`, which is not a privacy decision but the
     * absence of one.** The structure — which family, which two people are in
     * it — is what this screen now shows in every case; whether each of those
     * people may be *read* is decided one place up, in `rung()`, where the
     * module's single gate is. Asking webtrees for the structure at the
     * member's own level would give a different and worse answer: `spouses()`
     * filters on `canShowName()`, which depends on the tree's
     * `SHOW_LIVING_NAMES` preference, and `childFamilies()` silently escalates
     * to `PRIV_HIDE` anyway whenever `SHOW_PRIVATE_RELATIONSHIPS` is on. The
     * shape of a pedigree would then move with settings that have nothing to
     * do with it.
     *
     * **A restriction on the family record ends the branch — for the readers it
     * is meant to keep out, and for nobody else.** A `RESN` on a FAM is
     * somebody saying that *this connection* is confidential: not these
     * people, the fact that they are joined. A placeholder in the position it
     * names would say the one thing that was asked to stay quiet, so the line
     * ends there instead.
     *
     * **Which readers, though, is the whole of it**, and getting that wrong
     * cost this screen its pedigree twice over. `RelationshipNamer::families()`
     * refuses on any restriction at all and is right to: it decides whether to
     * *name* a relationship, where being wrong means a disclosure. Copying it
     * here inverted the cost — being wrong means losing the archive — and it
     * was wrong twice, in the same way both times. First `RESN locked`, which
     * is not about reading at all and truncated every locked line in a family
     * that locks its records. Then `RESN privacy`, which webtrees shows to
     * every member, and `confidential`, which it shows to every manager: read
     * as flags rather than as levels, they cut the line for the very people
     * entitled to see past them — including the administrator looking at the
     * screen and wondering where the family went.
     *
     * `restrictedFrom()` asks webtrees' own question, with the reader in it.
     *
     * @return array<int,Individual>
     */
    private function parents(Individual $individual, int $access_level): array
    {
        $family = $individual->childFamilies(Auth::PRIV_HIDE)->first();

        if (!$family instanceof Family) {
            return [];
        }

        if ($this->restrictedFrom($family, $access_level)) {
            return [];
        }

        $parents = [];

        foreach ($family->spouses(Auth::PRIV_HIDE) as $parent) {
            $offset = $parent->sex() === 'F' ? 1 : 0;

            // Two fathers, two mothers, or a parent whose sex is not recorded:
            // put whoever comes second in the free slot rather than dropping
            // them. The numbering stays valid; only "2 is the father" stops
            // being a promise, and the portal does not make that promise.
            if (array_key_exists($offset, $parents)) {
                $offset = $offset === 0 ? 1 : 0;
            }

            if (!array_key_exists($offset, $parents)) {
                $parents[$offset] = $parent;
            }
        }

        return $parents;
    }
}
