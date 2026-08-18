<?php

declare(strict_types=1);

namespace Engelking\Webtrees\PortalApi\Services;

use Engelking\Webtrees\PortalApi\Http\ApiException;
use Fig\Http\Message\StatusCodeInterface;
use Fisharebest\Webtrees\Date;
use Fisharebest\Webtrees\Elements\RestrictionNotice;
use Fisharebest\Webtrees\I18N;
use Fisharebest\Webtrees\Individual;

use function array_key_exists;
use function array_shift;
use function explode;
use function in_array;
use function implode;
use function is_string;
use function mb_strlen;
use function preg_match;
use function preg_replace;
use function str_contains;
use function str_starts_with;
use function trim;

/**
 * The only place in the module that changes genealogy data.
 *
 * Three rules, and each of them is load-bearing:
 *
 * 1. **Work from the raw GEDCOM, never from `facts()`.** `facts()` is privacy
 *    filtered. Rebuilding a record from it would silently delete every fact
 *    the member is not allowed to see — a data-loss bug that would look like
 *    a successful edit and would only be noticed much later, if ever.
 *
 * 2. **Touch only the allow-listed level-1 tags.** Everything else — FAMC,
 *    FAMS, OBJE, SOUR, NOTE, RESN, custom tags — is carried across byte for
 *    byte.
 *
 * 3. **One `updateRecord()` call.** That produces a single pending change for
 *    an editor to approve, rather than one per field, and a single CHAN entry
 *    naming the member.
 *
 * Nothing here writes to the tree directly. `updateRecord()` inserts into
 * webtrees' `change` table with status `pending`; only a moderator's approval
 * makes it real. Members do not have `auto_accept`, so there is no path by
 * which this bypasses review.
 */
class GedcomEditor
{
    public function __construct(private readonly PendingChanges $pending_changes)
    {
    }

    /**
     * What a member may change about themselves, and where it goes.
     *
     * The key is the field name in the API; the value is the level-1 GEDCOM
     * tag it lives in. Contact details are here because a member is the
     * authority on their own address — but see RecordPresenter: they are
     * returned on the member's *own* record only, never on anyone else's.
     */
    private const array SIMPLE_FIELDS = [
        'occupation' => 'OCCU',
        'address'    => 'ADDR',
        'email'      => 'EMAIL',
        'phone'      => 'PHON',
        'website'    => 'WWW',
    ];

    private const array MAX_LENGTHS = [
        'given_names' => 120,
        'surname'     => 120,
        'birth_date'  => 60,
        'birth_place' => 120,
        'occupation'  => 120,
        'address'     => 200,
        'email'       => 120,
        'phone'       => 60,
        'website'     => 200,
    ];

    /**
     * Apply a member's changes to their own record, as a pending change.
     *
     * @param array<string,mixed> $changes Validated against IndividualUpdate.
     *
     * @throws ApiException on anything malformed, or a record locked against editing.
     */
    public function applyToOwnRecord(Individual $individual, array $changes): void
    {
        $this->refuseIfLocked($individual);
        $this->refuseIfAlreadyPending($individual);

        $values = $this->clean($changes);

        if ($values === []) {
            throw ApiException::badRequest(I18N::translate('There was nothing to change.'));
        }

        $gedcom = $this->rewrite($individual->gedcom(), $values);

        // webtrees writes this to the `change` table as pending, adds a CHAN
        // entry naming the member, and logs it. It does not apply it.
        $individual->updateRecord($gedcom, true);
    }

    /**
     * A record carrying `RESN locked` is one an administrator has deliberately
     * closed to editing. Honour that rather than quietly queueing changes
     * against it.
     */
    private function refuseIfLocked(Individual $individual): void
    {
        if (preg_match('/\n1 RESN (.+)/', $individual->gedcom(), $match) !== 1) {
            return;
        }

        $restriction = (new RestrictionNotice(''))->canonical($match[1]);

        if (str_starts_with($restriction, RestrictionNotice::VALUE_LOCKED)) {
            throw new ApiException(
                'record_locked',
                StatusCodeInterface::STATUS_LOCKED,
                I18N::translate('This record is locked and cannot be changed. Please contact an administrator.')
            );
        }
    }

    /**
     * A member cannot see pending changes, so a second edit would be built
     * from the approved record and would discard the first once an editor
     * applied them in order. Refusing is the only option that cannot lose
     * someone's work silently.
     */
    private function refuseIfAlreadyPending(Individual $individual): void
    {
        if ($this->pending_changes->existsFor($individual)) {
            throw new ApiException(
                'change_pending',
                StatusCodeInterface::STATUS_CONFLICT,
                I18N::translate('Your last change is still waiting to be approved. Please wait until it has been reviewed before making another.')
            );
        }
    }

    /**
     * Validate and normalise the submitted values.
     *
     * A value is either a non-empty string, or null meaning "remove this".
     * Absent keys are left alone entirely, which is what makes this a PATCH of
     * the record rather than a replacement.
     *
     * @param array<string,mixed> $changes
     *
     * @return array<string,string|null>
     */
    private function clean(array $changes): array
    {
        $values = [];

        foreach ($changes as $field => $value) {
            if (!array_key_exists($field, self::MAX_LENGTHS)) {
                throw ApiException::badRequest(I18N::translate('Unknown field: %s', $field));
            }

            if ($value === null) {
                $values[$field] = null;
                continue;
            }

            if (!is_string($value)) {
                throw ApiException::badRequest();
            }

            $values[$field] = $this->cleanValue($field, $value);
        }

        return $values;
    }

    private function cleanValue(string $field, string $value): string|null
    {
        // A newline in a value would let a member append arbitrary level-1
        // facts to their own record — the GEDCOM equivalent of SQL injection.
        // Control characters are stripped rather than escaped, because there
        // is no legitimate reason for one to be in a name or a place.
        $value = (string) preg_replace('/[\x00-\x1F\x7F]+/u', ' ', $value);
        $value = trim((string) preg_replace('/\s+/u', ' ', $value));

        if ($value === '') {
            // An empty string and null mean the same thing: remove the fact.
            return null;
        }

        if (mb_strlen($value) > self::MAX_LENGTHS[$field]) {
            throw ApiException::badRequest(I18N::translate('“%s” is too long.', $field));
        }

        // A slash delimits the surname inside a NAME value. Allowing one in a
        // given name would silently restructure the name.
        if (($field === 'given_names' || $field === 'surname') && str_contains($value, '/')) {
            throw ApiException::badRequest(I18N::translate('A name cannot contain a slash.'));
        }

        if ($field === 'birth_date' && !(new Date($value))->isOK()) {
            throw ApiException::badRequest(I18N::translate('This date could not be understood. Try a form such as “12 MAR 1985” or “ABT 1985”.'));
        }

        return $value;
    }

    /**
     * Produce the new record.
     *
     * @param array<string,string|null> $values
     */
    private function rewrite(string $gedcom, array $values): string
    {
        $blocks = $this->splitIntoLevel1Blocks($gedcom);

        $blocks = $this->rewriteName($blocks, $values);
        $blocks = $this->rewriteBirth($blocks, $values);

        foreach (self::SIMPLE_FIELDS as $field => $tag) {
            if (array_key_exists($field, $values)) {
                $blocks = $this->replaceTag($blocks, $tag, $this->simpleFact($tag, $values[$field]));
            }
        }

        return implode("\n", $blocks);
    }

    /**
     * Split a record into its header line and one string per level-1 fact,
     * each carrying its own subordinate lines.
     *
     * @return array<int,string>
     */
    private function splitIntoLevel1Blocks(string $gedcom): array
    {
        $lines  = explode("\n", trim(preg_replace('/[\r\n]+/', "\n", $gedcom) ?? ''));
        $blocks = [];
        $current = '';

        foreach ($lines as $line) {
            if (str_starts_with($line, '1 ') || $line === '1') {
                if ($current !== '') {
                    $blocks[] = $current;
                }

                $current = $line;
            } else {
                $current = $current === '' ? $line : $current . "\n" . $line;
            }
        }

        if ($current !== '') {
            $blocks[] = $current;
        }

        return $blocks;
    }

    /**
     * @param array<int,string> $blocks
     *
     * @return array<int,string>
     */
    private function rewriteName(array $blocks, array $values): array
    {
        if (!array_key_exists('given_names', $values) && !array_key_exists('surname', $values)) {
            return $blocks;
        }

        $existing = $this->firstBlock($blocks, 'NAME');

        $given = array_key_exists('given_names', $values)
            ? $values['given_names']
            : $this->subTag($existing, 'GIVN');

        $surname = array_key_exists('surname', $values)
            ? $values['surname']
            : $this->subTag($existing, 'SURN');

        if ($given === null && $surname === null) {
            throw ApiException::badRequest(I18N::translate('A name needs at least a given name or a surname.'));
        }

        // webtrees derives the displayed name from NAME, and its indexes from
        // GIVN/SURN, so all three have to agree or search stops matching what
        // is on screen.
        $fact = '1 NAME ' . trim(($given ?? '') . ' /' . ($surname ?? '') . '/');

        if ($given !== null) {
            $fact .= "\n2 GIVN " . $given;
        }

        if ($surname !== null) {
            $fact .= "\n2 SURN " . $surname;
        }

        // Subordinate lines of the existing name that are not ours to manage —
        // NICK, SPFX, TYPE, SOUR, NOTE — are carried across.
        foreach ($this->otherSubLines($existing, ['GIVN', 'SURN']) as $line) {
            $fact .= "\n" . $line;
        }

        return $this->replaceTag($blocks, 'NAME', $fact);
    }

    /**
     * @param array<int,string> $blocks
     *
     * @return array<int,string>
     */
    private function rewriteBirth(array $blocks, array $values): array
    {
        if (!array_key_exists('birth_date', $values) && !array_key_exists('birth_place', $values)) {
            return $blocks;
        }

        $existing = $this->firstBlock($blocks, 'BIRT');

        // A field the member did not touch keeps its whole block, children
        // included. A place can carry coordinates, a source citation, a note:
        //
        //   2 PLAC Reutlingen
        //   3 MAP
        //   4 LATI N48.4919
        //
        // Rebuilding that line from its value alone would drop all of it,
        // silently, because the member edited the date. A field the member
        // *did* change is written fresh without the old children, which
        // described the old value and would be wrong attached to the new one.
        $date = array_key_exists('birth_date', $values)
            ? $this->newSubLine('DATE', $values['birth_date'])
            : $this->subBlock($existing, 'DATE');

        $place = array_key_exists('birth_place', $values)
            ? $this->newSubLine('PLAC', $values['birth_place'])
            : $this->subBlock($existing, 'PLAC');

        $others = $this->otherSubLines($existing, ['DATE', 'PLAC']);

        // A birth fact with no date, no place and nothing else is noise.
        if ($date === [] && $place === [] && $others === []) {
            return $this->replaceTag($blocks, 'BIRT', null);
        }

        $fact = '1 BIRT';

        foreach ([...$date, ...$place, ...$others] as $line) {
            $fact .= "\n" . $line;
        }

        return $this->replaceTag($blocks, 'BIRT', $fact);
    }

    private function simpleFact(string $tag, string|null $value): string|null
    {
        return $value === null ? null : '1 ' . $tag . ' ' . $value;
    }

    /**
     * Replace the first block with this tag, or append one, or remove it.
     *
     * Only the *first* occurrence is touched. A record with three OCCU facts
     * keeps the other two: the portal offers one occupation field, and that
     * is not a mandate to delete a genealogist's careful research.
     *
     * @param array<int,string> $blocks
     *
     * @return array<int,string>
     */
    private function replaceTag(array $blocks, string $tag, string|null $replacement): array
    {
        $result  = [];
        $done    = false;

        foreach ($blocks as $block) {
            if (!$done && $this->blockTag($block) === $tag) {
                $done = true;

                if ($replacement !== null) {
                    $result[] = $replacement;
                }

                continue;
            }

            $result[] = $block;
        }

        if (!$done && $replacement !== null) {
            $result[] = $replacement;
        }

        return $result;
    }

    /**
     * @param array<int,string> $blocks
     */
    private function firstBlock(array $blocks, string $tag): string|null
    {
        foreach ($blocks as $block) {
            if ($this->blockTag($block) === $tag) {
                return $block;
            }
        }

        return null;
    }

    private function blockTag(string $block): string|null
    {
        $first = explode("\n", $block)[0];

        return preg_match('/^1 (\w+)/', $first, $match) === 1 ? $match[1] : null;
    }

    /**
     * The value of a level-2 subtag, or null.
     */
    /**
     * One level-2 line for a value the member just supplied, or nothing when
     * they cleared it.
     *
     * @return array<int,string>
     */
    private function newSubLine(string $tag, string|null $value): array
    {
        return $value === null || $value === '' ? [] : ['2 ' . $tag . ' ' . $value];
    }

    /**
     * A level-2 line *and everything under it*, exactly as it stands.
     *
     * This is what keeps a place's coordinates, or a date's source citation,
     * when the member edits the other one of the pair.
     *
     * @return array<int,string>
     */
    private function subBlock(string|null $block, string $tag): array
    {
        if ($block === null) {
            return [];
        }

        $lines = explode("\n", $block);
        array_shift($lines);

        $keep      = [];
        $capturing = false;

        foreach ($lines as $line) {
            if (preg_match('/^2 (\w+)/', $line, $match) === 1) {
                // Only the first one: a fact may carry several places, and
                // rebuilding them all under one heading would merge them.
                $capturing = $match[1] === $tag && $keep === [];
            }

            if ($capturing) {
                $keep[] = $line;
            }
        }

        return $keep;
    }

    private function subTag(string|null $block, string $tag): string|null
    {
        if ($block === null) {
            return null;
        }

        if (preg_match('/^2 ' . $tag . ' (.+)$/m', $block, $match) !== 1) {
            return null;
        }

        $value = trim($match[1]);

        return $value === '' ? null : $value;
    }

    /**
     * Every subordinate line of a block except those under the given level-2
     * tags — kept so an edit does not discard sources, notes or nicknames.
     *
     * @param array<int,string> $managed
     *
     * @return array<int,string>
     */
    private function otherSubLines(string|null $block, array $managed): array
    {
        if ($block === null) {
            return [];
        }

        $lines = explode("\n", $block);
        array_shift($lines);

        $keep = [];
        $skipping = false;

        foreach ($lines as $line) {
            if (preg_match('/^2 (\w+)/', $line, $match) === 1) {
                $skipping = in_array($match[1], $managed, true);
            }

            // Deeper lines belong to whichever level-2 line preceded them.
            if (!$skipping) {
                $keep[] = $line;
            }
        }

        return $keep;
    }
}
