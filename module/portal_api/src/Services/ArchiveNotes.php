<?php

declare(strict_types=1);

namespace Engelking\Webtrees\PortalApi\Services;

use Engelking\Webtrees\PortalApi\PortalApiModule;
use Fisharebest\Webtrees\Fact;
use Fisharebest\Webtrees\GedcomRecord;
use Fisharebest\Webtrees\Note;
use Fisharebest\Webtrees\Registry;

use function preg_match;
use function preg_match_all;
use function preg_replace;
use function trim;

/**
 * The prose the family wrote about itself.
 *
 * A genealogy database is mostly dates, and the dates are the least of it. What
 * somebody did in the war, why a family left a village, which of two Berthas
 * the photograph shows, the fact that a date is a guess and here is why — all
 * of that is in `NOTE`, and none of it has ever left this module before. The
 * portal publishes an allow-list of event tags and `NOTE` has never been on it,
 * for the good reason that a note is written by a researcher for other
 * researchers and is not a thing to put on a member's telephone unannounced.
 *
 * The MCP server is the caller that changes the calculation, because reading
 * the prose is the entire point of pointing a language model at a family
 * archive. Dates it can already get from anywhere. So notes are published
 * there and nowhere else, under two conditions that are not negotiable:
 *
 *  - **only about the dead** — enforced by `DeceasedOnly`, before this class
 *    is ever asked, and asked again about the person a shared note is reached
 *    through;
 *  - **only what webtrees would show** — a note under a fact the reader may
 *    not see is gone with the fact, and a shared `NOTE` record has its own
 *    `canShow()`, which webtrees itself makes stricter than most: a shared note
 *    linked to *any* record the reader may not see is hidden outright.
 *
 * **Two spellings, one meaning.** GEDCOM writes a note either inline —
 * `1 NOTE Sie war Hebamme in Celle` with `2 CONT` for further lines — or as a
 * pointer to a shared `NOTE` record that several people link to. Both are read
 * here and both come out the same shape, because the difference is a matter of
 * how the archive was typed and no business of the caller's.
 *
 * **Text, not markup.** Notes are returned exactly as they were written, CONT
 * lines rejoined and nothing else done to them. webtrees may render a note as
 * Markdown depending on a tree setting; rendering it here would mean handing a
 * model HTML to read around, and the raw text is both smaller and truer.
 *
 * **And an administrator can switch them off.** A note is written by a
 * researcher for other researchers, and a family that is happy to have its
 * dates and its places read by an assistant may not be happy to have its
 * commentary read — a note about a dead man is quite often also a note about
 * his living children. So the setting is separate from the one that turns the
 * MCP server on, it is asked here rather than in six places, and with it off
 * the `search_notes` tool is not offered at all instead of being offered and
 * answering nothing.
 */
class ArchiveNotes
{
    public function __construct(private readonly PortalApiModule $module)
    {
    }

    /** Whether this installation lets an assistant read the family's prose. */
    public function published(): bool
    {
        return $this->module->getPreference(PortalApiModule::SETTING_MCP_NOTES, '1') === '1';
    }

    /**
     * The notes written on the record itself.
     *
     * @return array<int,array<string,mixed>>
     */
    public function forRecord(GedcomRecord $record, int $access_level): array
    {
        if (!$this->published()) {
            return [];
        }

        $notes = [];

        foreach ($record->facts(['NOTE'], false, $access_level, true) as $fact) {
            $note = $this->fromValue($fact->value(), $record, $access_level);

            if ($note !== null) {
                $notes[] = $note;
            }
        }

        return $notes;
    }

    /**
     * The notes written under one fact — `2 NOTE` beneath a birth, a
     * marriage, an occupation.
     *
     * Read out of the fact's own GEDCOM rather than through `attribute()`,
     * which returns the first and stops. A fact with three notes under it has
     * three notes, and a reader that silently keeps one of them is worse than
     * one that keeps none.
     *
     * The fact reached here has already been through `Fact::canShow()`, which
     * is what carries a `2 RESN` on the event itself. What is checked again is
     * the shared record behind a pointer, which has privacy of its own.
     *
     * @return array<int,array<string,mixed>>
     */
    public function forFact(Fact $fact, int $access_level): array
    {
        if (!$this->published()) {
            return [];
        }

        if (preg_match_all('/\n2 NOTE ?(.*(?:\n3 CONT ?.*)*)/', $fact->gedcom(), $matches) === 0) {
            return [];
        }

        $notes = [];

        foreach ($matches[1] as $value) {
            $value = (string) preg_replace("/\n3 CONT ?/", "\n", $value);
            $note  = $this->fromValue($value, $fact->record(), $access_level);

            if ($note !== null) {
                $notes[] = $note;
            }
        }

        return $notes;
    }

    /**
     * The text of a shared note, if this reader may have it.
     *
     * Used by the note search, which finds the record first and asks about the
     * person afterwards.
     */
    public function textOf(Note $note, int $access_level): string|null
    {
        if (!$this->published() || !$note->canShow($access_level)) {
            return null;
        }

        $text = $this->clean($note->getNote());

        return $text === '' ? null : $text;
    }

    /**
     * One `NOTE` value — either a pointer to a shared record or the text
     * itself — as the shape that leaves this module.
     *
     * @return array<string,mixed>|null null where there is nothing to say, or
     *                                  nothing this reader may be told.
     */
    private function fromValue(string $value, GedcomRecord $record, int $access_level): array|null
    {
        $value = trim($value);

        if ($value === '') {
            return null;
        }

        if (preg_match('/^@([^@]+)@$/', $value, $match) === 1) {
            $shared = Registry::noteFactory()->make($match[1], $record->tree());

            if (!$shared instanceof Note) {
                return null;
            }

            $text = $this->textOf($shared, $access_level);

            return $text === null ? null : ['text' => $text, 'shared' => true, 'id' => $shared->xref()];
        }

        $text = $this->clean($value);

        return $text === '' ? null : ['text' => $text, 'shared' => false, 'id' => null];
    }

    /**
     * Trailing whitespace off each line, and off the whole; nothing else.
     *
     * Blank lines inside a note are the writer's paragraphs and are kept.
     */
    private function clean(string $text): string
    {
        return trim((string) preg_replace('/[ \t]+(\n|$)/', '$1', $text));
    }
}
