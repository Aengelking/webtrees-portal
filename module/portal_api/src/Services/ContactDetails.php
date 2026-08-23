<?php

declare(strict_types=1);

namespace Engelking\Webtrees\PortalApi\Services;

use Engelking\Webtrees\PortalApi\PortalApiModule;
use Fisharebest\Webtrees\Contracts\UserInterface;
use Fisharebest\Webtrees\DB;
use Fisharebest\Webtrees\Individual;
use Fisharebest\Webtrees\Registry;
use Fisharebest\Webtrees\Tree;

use function array_fill_keys;
use function array_filter;
use function array_slice;
use function count;
use function array_key_exists;
use function date;
use function implode;
use function in_array;
use function is_array;
use function is_string;
use function json_decode;
use function json_encode;
use function mb_substr;
use function preg_match;
use function preg_replace;
use function preg_split;
use function trim;

use const JSON_UNESCAPED_SLASHES;
use const JSON_UNESCAPED_UNICODE;

/**
 * What a member shares, and with whom.
 *
 * Three rules, and the whole class is them.
 *
 * **Nothing here comes from the family tree.** See Schema/Migration3.php.
 *
 * **Every entry carries its own audience.** "My email may go to the whole
 * family" and "my address is for my brother" are different decisions.
 *
 * **"My contacts" is an audience the member built themselves.** Close family
 * is decided by the tree and the whole membership by the directory; the
 * people a member connected with are neither, and every one of them agreed
 * to it. When the family switches connections off, that audience shares
 * nothing — see `Connections::disclosableUserIds()`.
 *
 * **The narrowest answer is the default, everywhere.** An unknown audience, a
 * missing row, a viewer with no linked record, a subject with no linked
 * record — every one of them resolves to "not shared" rather than to an
 * assumption.
 */
class ContactDetails
{
    public const string TABLE = 'portal_contact_detail';

    public const string AUDIENCE_NOBODY       = 'nobody';
    public const string AUDIENCE_CLOSE_FAMILY = 'close_family';
    public const string AUDIENCE_CONNECTIONS  = 'connections';
    public const string AUDIENCE_MEMBERS      = 'members';

    /** The kinds a member may share. Closed, and decided here rather than by the client. */
    public const array KINDS = ['email', 'phone', 'address'];

    private const array AUDIENCES = [
        self::AUDIENCE_NOBODY,
        self::AUDIENCE_CLOSE_FAMILY,
        self::AUDIENCE_CONNECTIONS,
        self::AUDIENCE_MEMBERS,
    ];

    private const int MAX_VALUE_LENGTH = 255;

    /** The kind that is more than one answer. */
    public const string KIND_ADDRESS = 'address';

    /**
     * The fields an address is made of, in the order they are written down.
     *
     * German order, because this is a German family's portal: street and
     * number, then postcode and town on one line, then the country. A member
     * who lives abroad still gets every field they need; what they do not get
     * is their own country's line order, which is a good deal less important
     * than being able to type the address into the right boxes at all.
     */
    public const array ADDRESS_PARTS = ['street', 'postcode', 'city', 'country'];

    private const array MAX_PART_LENGTH = [
        'street'   => 120,
        'postcode' => 20,
        'city'     => 120,
        'country'  => 80,
    ];

    public function __construct(
        private readonly PortalApiModule $module,
        private readonly CloseFamily $close_family,
        private readonly Connections $connections,
    ) {
    }

    /**
     * Whether the family shares contact details through the portal at all.
     *
     * Checked where the disclosure happens rather than where the data is
     * written: switching this off must silence entries that already exist,
     * and a member must still be able to see and clear their own.
     */
    public function enabled(): bool
    {
        return $this->module->getPreference(PortalApiModule::SETTING_MEMBER_CONTACT, '1') === '1';
    }

    /**
     * A member's own entries, whatever their audience.
     *
     * Only ever for the member themselves — this is the settings screen's
     * view, and it deliberately ignores the audience, because you can always
     * see what you have chosen to share.
     *
     * The address carries its parts as well as its text — the parts as the
     * member typed them where the row has them, and otherwise the best sense
     * that can be made of the text (see `partsFrom()`). Both, always, so that
     * a client can use whichever it understands.
     *
     * @return array<string,array{value:string,audience:string,parts?:array<string,string>}>
     */
    public function forMember(UserInterface $user): array
    {
        $entries = [];

        foreach (DB::table(self::TABLE)->where('wt_user_id', '=', $user->id())->get() as $row) {
            if (!in_array($row->kind, self::KINDS, true)) {
                continue;
            }

            $value = (string) $row->value;

            $entry = [
                'value'    => $value,
                'audience' => $this->audience((string) $row->audience),
            ];

            if ($row->kind === self::KIND_ADDRESS) {
                $entry['parts'] = $this->storedParts($row->parts ?? null) ?? $this->partsFrom($value);
            }

            $entries[$row->kind] = $entry;
        }

        return $entries;
    }

    /**
     * The parts a row was written with, or null where it has none.
     *
     * Null is the ordinary case for a row written before the address had
     * fields, and for one written by a client that only sends text. It is also
     * what a corrupt column resolves to, because guessing from the text is a
     * better answer than an error on a settings screen.
     *
     * @return array<string,string>|null
     */
    private function storedParts(mixed $stored): array|null
    {
        if (!is_string($stored) || trim($stored) === '') {
            return null;
        }

        $decoded = json_decode($stored, true);

        if (!is_array($decoded)) {
            return null;
        }

        $parts = [];

        foreach (self::ADDRESS_PARTS as $part) {
            $parts[$part] = $this->cleanPart($part, (string) ($decoded[$part] ?? ''));
        }

        return $parts === $this->emptyParts() ? null : $parts;
    }

    /**
     * Replace the member's entries with what they just submitted.
     *
     * An empty value deletes the row. That is the point rather than a
     * shortcut: clearing the field and withdrawing consent should be the same
     * act, because a member who deletes their telephone number has plainly
     * finished sharing it, and leaving a hidden copy behind would be a way of
     * not listening.
     *
     * **An address may arrive either way.** `parts` if the client has fields,
     * `value` if it has a box — the module and the portal ship separately and
     * can be a version apart, so neither is allowed to be the only shape that
     * works. Where parts are sent they decide the text as well, because two
     * versions of one address with a member's own words in only one of them is
     * the sort of disagreement nobody can resolve later.
     *
     * @param array<string,array{value?:mixed,audience?:mixed,parts?:mixed}> $changes
     *
     * @return array<string,array{value:string,audience:string,parts?:array<string,string>}>
     */
    public function update(UserInterface $user, array $changes): array
    {
        $now = date('Y-m-d H:i:s');

        foreach (self::KINDS as $kind) {
            if (!array_key_exists($kind, $changes)) {
                continue;
            }

            $submitted = $changes[$kind];
            $audience  = $this->audience((string) ($submitted['audience'] ?? self::AUDIENCE_NOBODY));
            $parts     = $kind === self::KIND_ADDRESS ? $this->submittedParts($submitted) : null;
            $value     = $parts === null
                ? mb_substr(trim((string) ($submitted['value'] ?? '')), 0, self::MAX_VALUE_LENGTH)
                : mb_substr($this->compose($parts), 0, self::MAX_VALUE_LENGTH);

            // Nothing to share, or nobody to share it with. Either way the
            // row goes, so there is nothing left to leak later.
            if ($value === '' || $audience === self::AUDIENCE_NOBODY) {
                DB::table(self::TABLE)
                    ->where('wt_user_id', '=', $user->id())
                    ->where('kind', '=', $kind)
                    ->delete();

                continue;
            }

            DB::table(self::TABLE)->updateOrInsert(
                ['wt_user_id' => $user->id(), 'kind' => $kind],
                [
                    'value'    => $value,
                    'audience' => $audience,
                    // Always written, so that an address saved from a box
                    // clears parts that are no longer what it says.
                    'parts'      => $parts === null ? null : json_encode($parts, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                    'updated_at' => $now,
                    'created_at' => $now,
                ]
            );
        }

        return $this->forMember($user);
    }

    /**
     * The parts of a submitted address, or null where the client sent none.
     *
     * A `parts` object with nothing in it is also null: an address cleared
     * field by field is an address being withdrawn, and the row goes.
     *
     * @param array{parts?:mixed} $submitted
     *
     * @return array<string,string>|null
     */
    private function submittedParts(array $submitted): array|null
    {
        $sent = $submitted['parts'] ?? null;

        if (!is_array($sent)) {
            return null;
        }

        $parts = [];

        foreach (self::ADDRESS_PARTS as $part) {
            $value = $sent[$part] ?? '';

            $parts[$part] = $this->cleanPart($part, is_string($value) ? $value : '');
        }

        return $parts === $this->emptyParts() ? null : $parts;
    }

    /**
     * The address as one readable piece of text.
     *
     * This is what every reader gets, and what the envelope would say:
     *
     *     Musterstraße 12
     *     29223 Celle
     *     Deutschland
     *
     * Empty fields take their line with them rather than leaving a gap, and
     * postcode and town share a line because that is how a German address is
     * written — never one without the other on a line of its own.
     *
     * @param array<string,string> $parts
     */
    private function compose(array $parts): string
    {
        $town = trim(($parts['postcode'] ?? '') . ' ' . ($parts['city'] ?? ''));

        $lines = array_filter(
            [$parts['street'] ?? '', $town, $parts['country'] ?? ''],
            static fn (string $line): bool => $line !== ''
        );

        return implode("\n", $lines);
    }

    /**
     * The best sense that can be made of an address that was typed as text.
     *
     * Used for rows written before the address had fields, and for rows
     * written by a client that only sends text. It is a guess, and it is
     * allowed to be one: the member sees it in the fields, corrects whatever
     * landed in the wrong box, and their save replaces it with the truth.
     *
     * The line that gives it away is the postcode and town — "29223 Celle" is
     * unmistakable, and it is the *middle* of a German address, so everything
     * above it is the street and everything below it is the country. That is
     * what makes a "c/o" line or a flat number land with the street rather
     * than shunting every field down by one.
     *
     * What it must not do is lose anything, because the member's next save
     * would then delete it. Every line ends up somewhere.
     *
     * @return array<string,string>
     */
    public function partsFrom(string $value): array
    {
        $parts = $this->emptyParts();
        $lines = [];

        foreach (preg_split('/[\r\n,]+/u', $value) ?: [] as $line) {
            $line = trim($line);

            if ($line !== '') {
                $lines[] = $line;
            }
        }

        $above = [];
        $below = [];
        $found = false;

        foreach ($lines as $line) {
            // "29223 Celle", and "CH-3011 Bern" — a postcode is only a
            // postcode when a town follows it on the same line.
            if (!$found && preg_match('/^([A-Z]{0,3}-?\d{3,6})\s+(\S.*)$/u', $line, $matched) === 1) {
                $parts['postcode'] = $this->cleanPart('postcode', $matched[1]);
                $parts['city']     = $this->cleanPart('city', $matched[2]);
                $found             = true;

                continue;
            }

            if ($found) {
                $below[] = $line;
            } else {
                $above[] = $line;
            }
        }

        // With no postcode line to orient by there is nothing to be clever
        // about: street, then town, then country, in the order they were
        // written.
        if (!$found) {
            $count = count($above);

            if ($count > 2) {
                $parts['street']  = $this->cleanPart('street', implode(', ', array_slice($above, 0, $count - 2)));
                $parts['city']    = $this->cleanPart('city', $above[$count - 2]);
                $parts['country'] = $this->cleanPart('country', $above[$count - 1]);
            } else {
                $parts['street'] = $this->cleanPart('street', $above[0] ?? '');
                $parts['city']   = $this->cleanPart('city', $above[1] ?? '');
            }

            return $parts;
        }

        $parts['street']  = $this->cleanPart('street', implode(', ', $above));
        $parts['country'] = $this->cleanPart('country', implode(', ', $below));

        return $parts;
    }

    /** @return array<string,string> */
    private function emptyParts(): array
    {
        return array_fill_keys(self::ADDRESS_PARTS, '');
    }

    /**
     * One field of an address: no control characters, no runs of whitespace,
     * and no longer than its own limit.
     *
     * Truncated rather than refused, unlike the GEDCOM editor, because nothing
     * here is genealogy and a member being told "too long" about their own
     * street would be a poor trade for the two characters it saves.
     */
    private function cleanPart(string $part, string $value): string
    {
        $value = (string) preg_replace('/[\x00-\x1F\x7F]+/u', ' ', $value);
        $value = trim((string) preg_replace('/\s+/u', ' ', $value));

        return mb_substr($value, 0, self::MAX_PART_LENGTH[$part] ?? self::MAX_VALUE_LENGTH);
    }

    /**
     * The entries `$viewer` may see of `$subject`.
     *
     * Deliberately one member at a time. Deciding "close family" means
     * walking the tree, and doing that once per row of the directory would
     * turn a list into a page-load nobody waits for — so the directory
     * carries no contact details at all and this is only ever asked on the
     * screen for one person. `ContactTest::testTheDirectoryListCarriesNoContactDetails`
     * pins that.
     *
     * @return array<string,string> kind => value, for the kinds this viewer may see.
     */
    public function visibleTo(
        UserInterface $subject,
        UserInterface $viewer,
        Tree $tree,
        int $access_level,
        int $steps
    ): array {
        if (!$this->enabled()) {
            return [];
        }

        $entries = $this->forMember($subject);

        if ($entries === []) {
            return [];
        }

        // Both computed once, and only if some entry actually needs the
        // answer: one walks the tree and the other reads a table.
        $close     = null;
        $connected = null;

        $visible = [];

        foreach ($entries as $kind => $entry) {
            if ($entry['audience'] === self::AUDIENCE_MEMBERS) {
                $visible[$kind] = $entry['value'];

                continue;
            }

            if ($entry['audience'] === self::AUDIENCE_CONNECTIONS) {
                $connected ??= in_array($subject->id(), $this->connections->disclosableUserIds($viewer), true);

                if ($connected) {
                    $visible[$kind] = $entry['value'];
                }

                continue;
            }

            if ($entry['audience'] !== self::AUDIENCE_CLOSE_FAMILY) {
                continue;
            }

            $close ??= $this->isCloseFamily($subject, $viewer, $tree, $access_level, $steps);

            if ($close) {
                $visible[$kind] = $entry['value'];
            }
        }

        return $visible;
    }

    /**
     * Is the subject within `$steps` of the viewer?
     *
     * Measured from the *viewer*, at the *viewer's* access level, which is the
     * same walk and the same rule as everywhere else in this module. Either
     * side missing a linked record answers no: the portal has no way to place
     * an unlinked account in the family, and "I could not work it out" must
     * not resolve to "share it".
     */
    private function isCloseFamily(
        UserInterface $subject,
        UserInterface $viewer,
        Tree $tree,
        int $access_level,
        int $steps
    ): bool {
        $viewer_record  = $this->linked($tree, $viewer);
        $subject_record = $this->linked($tree, $subject);

        if (!$viewer_record instanceof Individual || !$subject_record instanceof Individual) {
            return false;
        }

        if ($viewer_record->xref() === $subject_record->xref()) {
            return true;
        }

        return array_key_exists(
            $subject_record->xref(),
            $this->close_family->within($viewer_record, $access_level, $steps)
        );
    }

    private function linked(Tree $tree, UserInterface $user): Individual|null
    {
        $xref = $tree->getUserPreference($user, UserInterface::PREF_TREE_ACCOUNT_XREF);

        return $xref === '' ? null : Registry::individualFactory()->make($xref, $tree);
    }

    /** Anything unrecognised becomes the narrowest audience there is. */
    private function audience(string $audience): string
    {
        return in_array($audience, self::AUDIENCES, true) ? $audience : self::AUDIENCE_NOBODY;
    }
}
