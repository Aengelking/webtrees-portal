<?php

declare(strict_types=1);

namespace Engelking\Webtrees\PortalApi\Services;

use Fisharebest\Webtrees\Individual;

use function array_key_exists;
use function is_array;

/**
 * What a record looks like on its way to an assistant.
 *
 * Nothing here reads the family tree. Every person object begins life in
 * `RecordPresenter`, which is the module's one gate on genealogy data and
 * stays that; this class drops the parts of that answer an assistant has no
 * use for, applies `DeceasedOnly` to what is left, and renames `xref` to `id`
 * because that is what the tools' arguments are called.
 *
 * **Two things are dropped, and it is worth saying why.** The relationship
 * line goes, because it is computed against a reader and here there is no
 * reader — a token is not a cousin. The pending-change flag goes, because it
 * is a member's own business with their own record.
 *
 * **Photographs are rebuilt rather than passed through.** `RecordPresenter`
 * gives each one a URL, and a URL needs a portal session that no MCP client
 * has. What goes out instead is an id and a title, which cost a few words and
 * let a model ask for the picture itself; the bytes come from `ArchivePhotos`,
 * which also decides whether any of this is offered at all.
 *
 * **One thing is added: `withheld`.** A dead woman with three living children
 * comes back with an empty `children` list, and a model reading that will
 * cheerfully write "she had no children", which is false. So the counts of
 * relatives this rule removed travel with the record — how many, never who,
 * and never anything else about them. It is the same choice the portal's own
 * pedigree makes when it walks past a living rung rather than stopping at it
 * (§2.75): the shape of the family is not a secret, the living people in it
 * are.
 *
 * Counted only where webtrees would have shown the person. Somebody the
 * token's account may not see at all was gone before this class was asked, and
 * is not counted here — otherwise this field would quietly become a way to
 * measure what privacy is hiding.
 */
class ArchivePresenter
{
    public function __construct(
        private readonly RecordPresenter $records,
        private readonly ArchiveNotes $notes,
        private readonly DeceasedOnly $rule,
        private readonly ArchivePhotos $photos,
    ) {
    }

    /**
     * A name, some years and an archive number: enough to choose between two
     * people and to ask for the second one by id.
     *
     * @return array<string,mixed>|null null where this reader may not have the
     *                                  record, or where the person is not dead.
     */
    public function reference(Individual $individual, int $access_level): array|null
    {
        if (!$this->rule->mayRead($individual, $access_level)) {
            return null;
        }

        $ref = $this->records->individualRef($individual, $access_level);

        return $ref === null ? null : $this->shorten($ref);
    }

    /**
     * The whole of a person: events, the prose, and who stood around them.
     *
     * @return array<string,mixed>|null null where this reader may not have the
     *                                  record, or where the person is not dead.
     */
    public function person(Individual $individual, int $access_level): array|null
    {
        if (!$this->rule->mayRead($individual, $access_level)) {
            return null;
        }

        $detail = $this->records->individualDetail(
            $individual,
            $access_level,
            own_record: false,
            viewer: null,
            notes: $this->notes,
        );

        if ($detail === null) {
            return null;
        }

        $relatives = [];
        $withheld  = [];

        foreach (['parents', 'siblings', 'spouses', 'children'] as $kind) {
            [$relatives[$kind], $withheld[$kind]] = $this->living($detail[$kind] ?? []);
        }

        return [
            'id'               => $detail['xref'],
            'name'             => $detail['name'],
            'name_alternative' => $detail['name_alternative'],
            'sex'              => $detail['sex'],
            'lifespan'         => $detail['lifespan'],
            'references'       => $detail['references'],
            'birth'            => $detail['birth'],
            'death'            => $detail['death'],
            'events'           => $detail['events'],
            'notes'            => $detail['notes'] ?? [],
            'photos'           => $this->photos->forRecord($individual, $access_level),
            'parents'          => $relatives['parents'],
            'siblings'         => $relatives['siblings'],
            'spouses'          => $relatives['spouses'],
            'children'         => $relatives['children'],
            'withheld'         => $withheld,
            'webtrees_url'     => $detail['webtrees_url'],
        ];
    }

    /**
     * A list of people, the living dropped out of it.
     *
     * @param iterable<Individual> $individuals
     *
     * @return array<int,array<string,mixed>>
     */
    public function references(iterable $individuals, int $access_level): array
    {
        $refs = [];

        foreach ($individuals as $individual) {
            $ref = $this->reference($individual, $access_level);

            if ($ref !== null) {
                $refs[] = $ref;
            }
        }

        return $refs;
    }

    /**
     * Split a list of relatives `RecordPresenter` already built into the dead
     * and a count of the living.
     *
     * `is_deceased` is on every reference that class produces, which is what
     * makes this a filter rather than a second reading of the tree — and what
     * makes the count exactly right: a relative webtrees hid is not in this
     * list to be counted, and a relative it showed is counted here or shown.
     *
     * @param array<int,array<string,mixed>> $refs
     *
     * @return array{0: array<int,array<string,mixed>>, 1: int}
     */
    private function living(array $refs): array
    {
        $kept     = [];
        $withheld = 0;

        foreach ($refs as $ref) {
            if (!is_array($ref) || !array_key_exists('is_deceased', $ref)) {
                continue;
            }

            if ($ref['is_deceased'] === true) {
                $kept[] = $this->shorten($ref);
            } else {
                $withheld++;
            }
        }

        return [$kept, $withheld];
    }

    /**
     * One reference, with the portal's own furniture taken off it.
     *
     * @param array<string,mixed> $ref
     *
     * @return array<string,mixed>
     */
    private function shorten(array $ref): array
    {
        return [
            'id'         => $ref['xref'],
            'name'       => $ref['name'],
            'sex'        => $ref['sex'],
            'lifespan'   => $ref['lifespan'],
            'references' => $ref['references'],
        ];
    }
}
