<?php

declare(strict_types=1);

namespace Engelking\Webtrees\PortalApi\Services;

use Fisharebest\Webtrees\Contracts\UserInterface;
use Fisharebest\Webtrees\DB;
use Fisharebest\Webtrees\Individual;
use Fisharebest\Webtrees\Tree;

use function filter_var;
use function implode;
use function mb_strtolower;
use function mb_substr;
use function preg_match;
use function preg_split;
use function str_contains;
use function time;
use function trim;

use const FILTER_VALIDATE_EMAIL;

/**
 * "I read about the portal in the magazine, and I would like in."
 *
 * The campaign in `InvitationCampaigns` answers a letter that went to a
 * mailing list, and it can be automatic because the list has already answered
 * the hard question: an address on it belongs to the family. A notice in the
 * family magazine reaches further than the list does, and for a reader who is
 * on none of them there is nothing the software can check — which is exactly
 * the question §1.3 says software should not be asked.
 *
 * So this queue exists, and it is deliberately dumb. It records what somebody
 * typed. It creates nothing, sends nothing, and tells the person who wrote it
 * nothing beyond "it arrived". An administrator reads it and decides, which is
 * the same decision they make on the invitations screen — only now it starts
 * with somebody saying who they are rather than with an address out of the
 * blue.
 *
 * **The one thing it does help with is the linking.** This family prints the
 * archive number beside every name, so a reader usually has theirs to hand;
 * where the number names exactly one record — the same rule the campaign uses,
 * `TreeSearch::individualByNumber()` — the screen shows which, and the
 * invitation can be issued against it so the account arrives linked. Nothing
 * is guessed: two records under one number, or none, and the screen simply
 * says so.
 */
class AccessRequests
{
    /**
     * How long a handled request is kept.
     *
     * The same ninety days an invitation is kept for, and for the same reason:
     * "who let this person in, and on what grounds" is a question asked after
     * the fact, and it stops being interesting long before the row stops
     * naming somebody's address.
     */
    public const int RETAIN_DAYS = 90;

    /**
     * How long before the same address may ask again.
     *
     * Shorter than the campaign's quarter of an hour, because nothing is sent
     * here: what this prevents is one person's second thoughts arriving as a
     * second entry in somebody's queue.
     */
    private const int REASK_AFTER = 300;

    private const int MAX_NAME      = 128;
    private const int MAX_REFERENCE = 64;
    private const int MAX_EMAIL     = 128;
    private const int MAX_NOTE      = 500;

    public function __construct(
        private readonly TreeSearch $search,
    ) {
    }

    // -----------------------------------------------------------------
    // The family's half
    // -----------------------------------------------------------------

    /**
     * Write down that somebody asked.
     *
     * Returns nothing on purpose. The handler answers the same sentence
     * whatever happened here — a request that was ignored for want of an
     * address looks exactly like one that was written down, because the
     * alternative is a form that tells a stranger which of their guesses about
     * this family were right.
     */
    public function record(string $name, string $email, string $reference, string $note): void
    {
        $name  = mb_substr(trim($name), 0, self::MAX_NAME);
        $email = mb_strtolower(mb_substr(trim($email), 0, self::MAX_EMAIL));

        // Nothing to act on without these two: an administrator cannot answer
        // a request that names nobody, and cannot answer it anywhere without
        // an address. Silently dropped rather than refused, per the above.
        if ($name === '' || !str_contains($email, '@') || filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
            return;
        }

        $now = time();
        $row = [
            'name'       => $name,
            'reference'  => $this->nullIfEmpty(mb_substr(trim($reference), 0, self::MAX_REFERENCE)),
            'email'      => $email,
            'note'       => $this->nullIfEmpty(mb_substr(trim($note), 0, self::MAX_NOTE)),
            'updated_at' => $now,
        ];

        $open = DB::table('portal_access_request')
            ->where('email', '=', $email)
            ->whereNull('handled_at')
            ->orderByDesc('id')
            ->first();

        // Already waiting, and asked again just now: one entry, brought up to
        // date. Somebody who corrects their own typing should not appear twice
        // in the queue, and somebody pressing a button twice should not
        // either.
        if ($open !== null && (int) $open->updated_at > $now - self::REASK_AFTER) {
            DB::table('portal_access_request')->where('id', '=', (int) $open->id)->update($row);

            return;
        }

        DB::table('portal_access_request')->insert($row + ['created_at' => $now]);
    }

    // -----------------------------------------------------------------
    // The administrator's half
    // -----------------------------------------------------------------

    /**
     * What is waiting, oldest first.
     *
     * Oldest first and not newest: this is a queue somebody works through, and
     * the person who has waited longest is the one to answer next.
     *
     * @return array<int,array<string,mixed>>
     */
    public function open(Tree $tree): array
    {
        $rows = DB::table('portal_access_request')
            ->whereNull('handled_at')
            ->orderBy('created_at')
            ->get();

        $open = [];

        foreach ($rows as $row) {
            $open[] = $this->present($tree, $row);
        }

        return $open;
    }

    /**
     * What was decided lately, newest first.
     *
     * @return array<int,array<string,mixed>>
     */
    public function handled(Tree $tree): array
    {
        $rows = DB::table('portal_access_request')
            ->whereNotNull('handled_at')
            ->orderByDesc('handled_at')
            ->limit(50)
            ->get();

        $handled = [];

        foreach ($rows as $row) {
            $handled[] = $this->present($tree, $row);
        }

        return $handled;
    }

    public function find(int $id): object|null
    {
        return DB::table('portal_access_request')->where('id', '=', $id)->whereNull('handled_at')->first();
    }

    /** Mark one as answered. `$outcome` is 'invited' or 'declined'. */
    public function close(int $id, string $outcome, UserInterface|null $administrator): void
    {
        DB::table('portal_access_request')
            ->where('id', '=', $id)
            ->whereNull('handled_at')
            ->update([
                'handled_at' => time(),
                'handled_by' => $administrator?->id(),
                'outcome'    => $outcome === 'invited' ? 'invited' : 'declined',
            ]);
    }

    /** Forget what was decided long enough ago. */
    public function prune(): void
    {
        DB::table('portal_access_request')
            ->whereNotNull('handled_at')
            ->where('handled_at', '<', time() - self::RETAIN_DAYS * 86400)
            ->delete();
    }

    /** How many people are waiting for an answer. */
    public function openCount(): int
    {
        return DB::table('portal_access_request')->whereNull('handled_at')->count();
    }

    /**
     * The record this request's number names, if it names exactly one.
     *
     * `PRIV_NONE` inside `individualByNumber()`, so this answers for an
     * administrator about anybody — which is who is reading. Nothing here is
     * shown to the person who asked.
     */
    public function individualFor(Tree $tree, string $reference): Individual|null
    {
        $reference = trim($reference);

        return $reference === '' ? null : $this->search->individualByNumber($tree, $reference);
    }

    /**
     * The name without a number in front of it.
     *
     * Readers write both — "Antje Beispiel" and "22/1a32.124 Antje Beispiel" —
     * and the second is the form the family's own address book uses. The
     * number is picked out for the lookup, so the greeting should not keep it.
     */
    public function personName(string $name): string
    {
        $parts = preg_split('/\s+/', trim($name));
        $parts = $parts === false ? [] : $parts;

        if ($parts !== [] && preg_match('/\d/', $parts[0] ?? '') === 1) {
            unset($parts[0]);
        }

        $without = trim(implode(' ', $parts));

        return $without === '' ? trim($name) : $without;
    }

    /**
     * A number the reader wrote into the name field rather than its own.
     *
     * Not a correction of them: both fields are asked for, and somebody who
     * copies the whole line out of the magazine has given the screen the same
     * information in one field instead of two.
     */
    public function referenceIn(string $name, string $reference): string
    {
        if (trim($reference) !== '') {
            return trim($reference);
        }

        $parts = preg_split('/\s+/', trim($name));
        $first = $parts === false ? '' : trim($parts[0] ?? '');

        return preg_match('/\d/', $first) === 1 ? $first : '';
    }

    /**
     * @return array<string,mixed>
     */
    private function present(Tree $tree, object $row): array
    {
        $reference  = $this->referenceIn((string) $row->name, (string) ($row->reference ?? ''));
        $individual = $this->individualFor($tree, $reference);

        return [
            'id'         => (int) $row->id,
            'name'       => (string) $row->name,
            'person'     => $this->personName((string) $row->name),
            'reference'  => $reference,
            'email'      => (string) $row->email,
            'note'       => (string) ($row->note ?? ''),
            'created_at' => (int) $row->created_at,
            'updated_at' => (int) $row->updated_at,
            'handled_at' => $row->handled_at === null ? null : (int) $row->handled_at,
            'handled_by' => $row->handled_by === null ? null : (int) $row->handled_by,
            'outcome'    => (string) ($row->outcome ?? ''),
            'individual' => $individual,
        ];
    }

    private function nullIfEmpty(string $value): string|null
    {
        $value = trim($value);

        return $value === '' ? null : $value;
    }
}
