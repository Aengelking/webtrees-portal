<?php

declare(strict_types=1);

namespace Engelking\Webtrees\PortalApi\Services;

use Fisharebest\Webtrees\Date;
use Fisharebest\Webtrees\Fact;
use Fisharebest\Webtrees\Gedcom;
use Fisharebest\Webtrees\I18N;
use Fisharebest\Webtrees\Individual;
use Illuminate\Support\Collection;

use function array_values;
use function html_entity_decode;
use function in_array;
use function preg_replace;
use function str_contains;
use function str_replace;
use function strip_tags;
use function trim;

use const ENT_HTML5;
use const ENT_QUOTES;

/**
 * Turns webtrees records into the JSON shapes in openapi.yaml.
 *
 * This is the only place in the module that reads genealogy data, and the
 * only place that decides what is visible. Nothing else queries the
 * `individuals`, `families`, `link` or `dates` tables — everything goes
 * through GedcomRecord and the factories at an explicit access level.
 *
 * The contract of every method here: given an access level, a record the
 * caller may not see comes back as `null`, never as a redacted object. The
 * callers drop nulls out of lists, so a hidden person does not show up as a
 * differently-shaped entry that would prove they exist.
 *
 * The access level passed in must be the *session* user's access level for
 * the tree — see PortalTreeService::accessLevel().
 */
class RecordPresenter
{
    public function __construct(
        private readonly PendingChanges $pending_changes,
        private readonly RelationshipNamer $relationships,
    ) {
    }

    /**
     * Level-1 INDI tags the portal publishes.
     *
     * An allow-list, not a deny-list. Anything not listed is omitted, which
     * is the safe direction to be wrong in. Contact details (ADDR, EMAIL,
     * PHON, WWW) are deliberately absent from Phase 1; see NOTES.md.
     */
    /**
     * Contact details, published on the member's **own** record only.
     *
     * A member is the authority on their own address and may edit it (Phase
     * 2), so they must be able to see it. Handing it out on other members'
     * records is a different decision with a different consent question, and
     * belongs with Phase 3's connections rather than here.
     */
    private const array OWN_RECORD_TAGS = [
        'INDI:ADDR', 'INDI:EMAIL', 'INDI:PHON', 'INDI:WWW',
    ];

    private const array PUBLISHED_TAGS = [
        'INDI:BIRT', 'INDI:CHR', 'INDI:BAPM', 'INDI:CONF', 'INDI:BARM', 'INDI:BASM',
        'INDI:ADOP', 'INDI:DEAT', 'INDI:BURI', 'INDI:CREM',
        'INDI:EDUC', 'INDI:GRAD', 'INDI:OCCU', 'INDI:RETI',
        'INDI:RESI', 'INDI:CENS', 'INDI:EMIG', 'INDI:IMMI', 'INDI:NATU',
        'INDI:NATI', 'INDI:RELI', 'INDI:TITL', 'INDI:DSCR',
        'INDI:EVEN', 'INDI:FACT',
    ];

    /**
     * A reference to an individual, for relative lists and the directory.
     *
     * @return array<string,mixed>|null null when this caller may not see the record.
     */
    public function individualRef(Individual $individual, int $access_level): array|null
    {
        if (!$individual->canShow($access_level)) {
            return null;
        }

        $facts = $this->visibleFacts($individual, $access_level);
        $birth = $this->firstEvent($facts, Gedcom::BIRTH_EVENTS);
        $death = $this->firstEvent($facts, Gedcom::DEATH_EVENTS);

        return [
            'xref'        => $individual->xref(),
            'name'        => $this->name($individual, $access_level),
            'sex'         => $this->sex($individual),
            'is_deceased' => $individual->isDead(),
            'lifespan'    => $this->lifespan($birth, $death, $individual->isDead()),
        ];
    }

    /**
     * A full individual record, with events and immediate relatives.
     *
     * @return array<string,mixed>|null null when this caller may not see the record.
     */
    /**
     * @param bool            $own_record Whether this is the authenticated
     *                                    member's own record. Unlocks contact
     *                                    details and the pending change flag,
     *                                    neither of which is anyone else's
     *                                    business.
     * @param Individual|null $viewer     The member's own record, when they
     *                                    have one. Used only to say how they
     *                                    are related to this person.
     */
    public function individualDetail(
        Individual $individual,
        int $access_level,
        bool $own_record = false,
        Individual|null $viewer = null
    ): array|null {
        $ref = $this->individualRef($individual, $access_level);

        if ($ref === null) {
            return null;
        }

        $published = $own_record
            ? [...self::PUBLISHED_TAGS, ...self::OWN_RECORD_TAGS]
            : self::PUBLISHED_TAGS;

        $facts  = $this->visibleFacts($individual, $access_level);
        $birth  = $this->firstEvent($facts, Gedcom::BIRTH_EVENTS);
        $death  = $this->firstEvent($facts, Gedcom::DEATH_EVENTS);
        $events = $facts
            ->filter(static fn (Fact $fact): bool => in_array($fact->tag(), $published, true))
            ->map(fn (Fact $fact): array => $this->event($fact))
            ->values()
            ->all();

        return $ref + [
            'name_alternative' => $this->alternateName($individual, $access_level),
            'relationship'     => $this->relationships->name($viewer, $individual, $access_level),
            'references'       => $this->references($facts),
            'birth'            => $birth === null ? null : $this->event($birth),
            'death'            => $death === null ? null : $this->event($death),
            'events'           => $events,
            'parents'          => $this->parents($individual, $access_level),
            'siblings'         => $this->siblings($individual, $access_level),
            'spouses'          => $this->spouses($individual, $access_level),
            'children'         => $this->children($individual, $access_level),
            'pending_change'   => $own_record && $this->pending_changes->existsFor($individual),
            'webtrees_url'     => $individual->url(),
        ];
    }

    /**
     * @param Collection<int,Individual> $individuals
     *
     * @return array<int,array<string,mixed>>
     */
    public function individualRefs(Collection $individuals, int $access_level): array
    {
        return array_values(array_filter(
            $individuals
                ->map(fn (Individual $individual): array|null => $this->individualRef($individual, $access_level))
                ->all(),
            static fn (array|null $ref): bool => $ref !== null
        ));
    }

    // -----------------------------------------------------------------
    // Relatives
    // -----------------------------------------------------------------

    /**
     * @return array<int,array<string,mixed>>
     */
    private function parents(Individual $individual, int $access_level): array
    {
        $parents = new Collection();

        foreach ($individual->childFamilies($access_level) as $family) {
            foreach ($family->spouses($access_level) as $parent) {
                $parents->push($parent);
            }
        }

        return $this->individualRefs($this->unique($parents), $access_level);
    }

    /**
     * @return array<int,array<string,mixed>>
     */
    private function siblings(Individual $individual, int $access_level): array
    {
        $siblings = new Collection();

        foreach ($individual->childFamilies($access_level) as $family) {
            foreach ($family->children($access_level) as $child) {
                if ($child->xref() !== $individual->xref()) {
                    $siblings->push($child);
                }
            }
        }

        return $this->individualRefs($this->unique($siblings), $access_level);
    }

    /**
     * @return array<int,array<string,mixed>>
     */
    private function spouses(Individual $individual, int $access_level): array
    {
        $spouses = new Collection();

        foreach ($individual->spouseFamilies($access_level) as $family) {
            $spouse = $family->spouse($individual, $access_level);

            if ($spouse instanceof Individual) {
                $spouses->push($spouse);
            }
        }

        return $this->individualRefs($this->unique($spouses), $access_level);
    }

    /**
     * @return array<int,array<string,mixed>>
     */
    private function children(Individual $individual, int $access_level): array
    {
        $children = new Collection();

        foreach ($individual->spouseFamilies($access_level) as $family) {
            foreach ($family->children($access_level) as $child) {
                $children->push($child);
            }
        }

        return $this->individualRefs($this->unique($children), $access_level);
    }

    /**
     * @param Collection<int,Individual> $individuals
     *
     * @return Collection<int,Individual>
     */
    private function unique(Collection $individuals): Collection
    {
        return $individuals->unique(static fn (Individual $individual): string => $individual->xref())->values();
    }

    // -----------------------------------------------------------------
    // Facts
    // -----------------------------------------------------------------

    /**
     * Facts this caller may see, in webtrees' own order.
     *
     * `GedcomRecord::facts()` applies both record-level and fact-level
     * privacy at the given access level. Fact-level restrictions (a
     * `2 RESN privacy` under a single event, or a tree's per-tag privacy
     * settings) are enforced here and nowhere else.
     *
     * @return Collection<int,Fact>
     */
    private function visibleFacts(Individual $individual, int $access_level): Collection
    {
        return $individual->facts([], true, $access_level, true);
    }

    /**
     * @param Collection<int,Fact> $facts
     * @param array<int,string>    $tags  Unqualified tags, e.g. ['BIRT', 'CHR'].
     */
    private function firstEvent(Collection $facts, array $tags): Fact|null
    {
        foreach ($tags as $tag) {
            $fact = $facts->first(static fn (Fact $fact): bool => $fact->tag() === 'INDI:' . $tag);

            if ($fact instanceof Fact) {
                return $fact;
            }
        }

        return null;
    }

    /**
     * The record's user reference numbers.
     *
     * A field of its own rather than an event, because that is what it is:
     * bookkeeping, not something that happened to the person. This tree keeps
     * a "SB" number per person, and the Vesta "Classic Look & Feel" module
     * shows it as a badge in front of the name in webtrees. The portal will
     * not do that — see §2.18 — but the number is genuinely useful to a member
     * comparing notes with the family archive, so it is published in the open,
     * labelled, and not glued to a name.
     *
     * Filtered like everything else: `$facts` has already been through
     * `Fact::canShow()`, so a `2 RESN` under a REFN is honoured here for free.
     * GEDCOM allows several, so this is a list.
     *
     * @param Collection<int,Fact> $facts
     *
     * @return array<int,array<string,string|null>>
     */
    private function references(Collection $facts): array
    {
        return $facts
            ->filter(static fn (Fact $fact): bool => $fact->tag() === 'INDI:REFN')
            ->map(static function (Fact $fact): array {
                $type = trim($fact->attribute('TYPE'));

                return [
                    'number' => trim($fact->value()),
                    'type'   => $type === '' ? null : $type,
                ];
            })
            ->filter(static fn (array $reference): bool => $reference['number'] !== '')
            ->values()
            ->all();
    }

    /**
     * @return array<string,mixed>
     */
    private function event(Fact $fact): array
    {
        $date  = $fact->date();
        $place = $fact->place()->gedcomName();
        $value = trim($fact->value());

        // Facts whose value is a pointer to another record carry an XREF, not
        // text. The portal has no use for that here, and publishing it would
        // hand out an identifier for a record we have not privacy-checked.
        if (str_contains($value, '@')) {
            $value = '';
        }

        return [
            'tag'   => $fact->tag(),
            'label' => $this->text($fact->label()),
            'value' => $value === '' ? null : $value,
            'date'  => $date->isOK() ? $this->date($date, $fact) : null,
            'place' => $place === '' ? null : $place,
        ];
    }

    /**
     * @return array<string,mixed>
     */
    private function date(Date $date, Fact $fact): array
    {
        $year = $date->gregorianYear();

        return [
            'display' => $this->text($date->display($fact->record()->tree())),
            'gedcom'  => trim($fact->attribute('DATE')),
            'year'    => $year === 0 ? null : $year,
        ];
    }

    // -----------------------------------------------------------------
    // Names and small values
    // -----------------------------------------------------------------

    /**
     * A record can be visible while its name is not, and the reverse. Where
     * the name is hidden, emit exactly the placeholder webtrees would show,
     * so the shape of the response is the same either way.
     */
    private function name(Individual $individual, int $access_level): string
    {
        if (!$individual->canShowName($access_level)) {
            return I18N::translate('Private');
        }

        return $this->nameAt($individual, $individual->getPrimaryName());
    }

    private function alternateName(Individual $individual, int $access_level): string|null
    {
        if (!$individual->canShowName($access_level)) {
            return null;
        }

        $primary   = $individual->getPrimaryName();
        $secondary = $individual->getSecondaryName();

        if ($primary === $secondary) {
            return null;
        }

        $alternate = $this->nameAt($individual, $secondary);

        return $alternate === '' ? null : $alternate;
    }

    /**
     * One of a record's names, as a name and nothing else.
     *
     * Deliberately **not** `Individual::fullName()`. That method is a display
     * string, and custom modules decorate it: the Vesta "Classic Look & Feel"
     * module overrides it to prepend a badge (a reference number, say) and can
     * append the XREF, and any other module is free to do the same. Those are
     * reasonable things to do to a webtrees page and wrong in a JSON field
     * called `name` — a badge is not part of anybody's name, and an XREF is
     * something this API never publishes at all.
     *
     * `getAllNames()` is the structured form underneath, unchanged by all of
     * that: `fullNN` is the plain name that goes into the database. The two
     * things `fullName()` does that we still want are done here — the
     * placeholders for an unknown given name or surname, so a record with half
     * a name reads the same as it does in webtrees.
     */
    private function nameAt(Individual $individual, int $index): string
    {
        $names = $individual->getAllNames();
        $name  = $names[$index]['fullNN'] ?? '';

        $name = str_replace(
            [Individual::NOMEN_NESCIO, Individual::PRAENOMEN_NESCIO],
            [
                I18N::translateContext('Unknown surname', '…'),
                I18N::translateContext('Unknown given name', '…'),
            ],
            $name
        );

        // No strip_tags(): there is no markup in here by construction, and a
        // name that happens to contain "<" is a name, not a tag.
        return trim((string) preg_replace('/\s+/u', ' ', $name));
    }

    private function sex(Individual $individual): string
    {
        $sex = $individual->sex();

        return in_array($sex, ['M', 'F', 'X'], true) ? $sex : 'U';
    }

    private function lifespan(Fact|null $birth, Fact|null $death, bool $is_dead): string|null
    {
        $birth_year = $birth?->date()->isOK() === true ? $birth->date()->minimumDate()->format('%Y') : '';
        $death_year = $death?->date()->isOK() === true ? $death->date()->maximumDate()->format('%Y') : '';

        if ($birth_year === '' && $death_year === '') {
            return null;
        }

        $unknown = I18N::translate('…');

        if ($birth_year === '') {
            $birth_year = $unknown;
        }

        if ($death_year === '' && $is_dead) {
            $death_year = $unknown;
        }

        /* I18N: A range of years, e.g. “1870–”, “1870–1920”, “–1920” */
        return $this->text(I18N::translate('%1$s–%2$s', $birth_year, $death_year));
    }

    /**
     * webtrees returns display strings as HTML. The API returns text.
     */
    private function text(string $html): string
    {
        $text = strip_tags($html);
        $text = html_entity_decode($text, ENT_QUOTES | ENT_HTML5, 'UTF-8');

        // Collapse the whitespace that stripping inline markup leaves behind.
        return trim((string) preg_replace('/\s+/u', ' ', $text));
    }
}
