<?php

declare(strict_types=1);

namespace Engelking\Webtrees\PortalApi\Services;

use Engelking\Webtrees\PortalApi\PortalApiModule;
use Fisharebest\Webtrees\Contracts\UserInterface;
use Fisharebest\Webtrees\DB;
use Fisharebest\Webtrees\I18N;
use Fisharebest\Webtrees\Individual;
use Fisharebest\Webtrees\Services\EmailService;
use Fisharebest\Webtrees\Services\UserService;
use Fisharebest\Webtrees\SiteUser;
use Fisharebest\Webtrees\Tree;
use Fisharebest\Webtrees\User;
use Throwable;

use function array_filter;
use function array_values;
use function bin2hex;
use function error_log;
use function explode;
use function hash;
use function implode;
use function max;
use function mb_strtolower;
use function mb_substr;
use function min;
use function preg_match;
use function preg_split;
use function random_bytes;
use function rawurlencode;
use function rtrim;
use function str_contains;
use function time;
use function trim;
use function view;

/**
 * Inviting three hundred people with one letter, without putting a credential
 * in it.
 *
 * The letter goes to the distribution list and carries a **campaign link**,
 * which grants nothing. It opens a page with one field on it: your own
 * address. If that address is on one of the lists the campaign names, the
 * personal invitation is made then and there and sent *to that address* — so
 * what proves who you are is the one thing a round-robin letter cannot
 * forward, which is access to your own mailbox.
 *
 * See `Schema/Migration15.php` for why the obvious way — a personal link in
 * the letter — is not merely untidy but hands one person's account to whoever
 * opens the letter first.
 *
 * **The answer is the same either way.** On a list, not on a list, already has
 * an account, mail server down: one sentence, every time. Anything else turns
 * the page into a way of asking *is this person in the family*, of a portal
 * whose whole purpose is that the answer to that is nobody's business. The
 * same rule as `PasswordRequestCreate`, for the same reason.
 *
 * **The archive number does the linking.** This family names its mail contacts
 * `22/1a32.124 Antje Beispiel`, so the contact carries the one fact that can
 * tie an account to a record in the tree. Where it reads, the invitation names
 * that record and the account arrives linked; where it does not, the account
 * arrives as every hand-issued invitation does, and somebody links it later.
 * Nothing is guessed: two records under one number produce no link at all.
 */
class InvitationCampaigns
{
    public const int DEFAULT_VALIDITY_DAYS = 30;
    public const int MAX_VALIDITY_DAYS     = 180;

    /** 32 bytes, like every other token here. */
    private const int TOKEN_BYTES = 32;

    /**
     * How long before the same address may ask again.
     *
     * Long enough that a button pressed twice sends one letter, short enough
     * that "I cannot find the mail" is answered by asking again rather than by
     * writing to an administrator.
     */
    private const int RECLAIM_AFTER = 900;

    public function __construct(
        private readonly PortalApiModule $module,
        private readonly PortalTreeService $trees,
        private readonly DistributionLists $lists,
        private readonly ExchangeOnline $exchange,
        private readonly InvitationService $invitations,
        private readonly TreeSearch $search,
        private readonly UserService $users,
        private readonly EmailService $email,
    ) {
    }

    // -----------------------------------------------------------------
    // The administrator's half
    // -----------------------------------------------------------------

    /**
     * Start a campaign and return its token, once.
     *
     * @param array<int,string> $lists list hashes the campaign will accept
     */
    public function create(string $name, array $lists, int $days, UserInterface|null $creator): string
    {
        $token = bin2hex(random_bytes(self::TOKEN_BYTES));

        DB::table('portal_invitation_campaign')->insert([
            'token_hash' => self::hash($token),
            'name'       => trim($name) === '' ? I18N::translate('Invitation') : mb_substr(trim($name), 0, 128),
            'lists'      => implode("\n", $lists),
            'created_by' => $creator?->id(),
            'created_at' => time(),
            'expires_at' => time() + min(self::MAX_VALIDITY_DAYS, max(1, $days)) * 86400,
        ]);

        return $token;
    }

    public function revoke(int $id): void
    {
        DB::table('portal_invitation_campaign')
            ->where('id', '=', $id)
            ->whereNull('revoked_at')
            ->update(['revoked_at' => time()]);
    }

    /**
     * Every campaign, newest first, with what has come of it.
     *
     * @return array<int,array<string,mixed>>
     */
    public function all(): array
    {
        $counts = [];

        foreach (DB::table('portal_invitation_claim')->get() as $claim) {
            $counts[(int) $claim->campaign_id][(string) $claim->outcome] ??= 0;
            $counts[(int) $claim->campaign_id][(string) $claim->outcome]++;
        }

        $names      = $this->listNames();
        $campaigns  = [];

        foreach (DB::table('portal_invitation_campaign')->orderByDesc('id')->get() as $row) {
            $covers = [];

            foreach ($this->listsOf($row) as $hash) {
                $covers[] = $names[$hash] ?? I18N::translate('A list that is no longer configured');
            }

            $campaigns[] = [
                'id'         => (int) $row->id,
                'name'       => (string) $row->name,
                'lists'      => $covers,
                'created_at' => (int) $row->created_at,
                'expires_at' => (int) $row->expires_at,
                'revoked'    => $row->revoked_at !== null,
                'invited'    => $counts[(int) $row->id]['invited'] ?? 0,
                'existing'   => $counts[(int) $row->id]['existing'] ?? 0,
                'unknown'    => $counts[(int) $row->id]['unknown'] ?? 0,
            ];
        }

        return $campaigns;
    }

    /** Where the letter should point. The portal, never webtrees. */
    public function link(string $token): string
    {
        $base = rtrim($this->module->getPreference(PortalApiModule::SETTING_PORTAL_URL, ''), '/');

        return $base . '/einladung?aktion=' . rawurlencode($token);
    }

    // -----------------------------------------------------------------
    // The family's half
    // -----------------------------------------------------------------

    /**
     * Somebody has typed their address into the page the letter pointed at.
     *
     * Returns nothing, ever, and throws nothing the caller should report. The
     * handler above says the same sentence whatever happened here, and every
     * branch below is a reason this method must not be readable from outside.
     */
    public function claim(string $token, string $email): void
    {
        $address = mb_strtolower(trim($email));

        if ($address === '' || !str_contains($address, '@')) {
            return;
        }

        $campaign = $this->usable($token);

        if ($campaign === null) {
            return;
        }

        $hash     = self::hash($address);
        $previous = DB::table('portal_invitation_claim')
            ->where('campaign_id', '=', (int) $campaign->id)
            ->where('address_hash', '=', $hash)
            ->first();

        // Asked again, recently. One letter, not two — and silence rather than
        // a different answer, which would say that the first one was sent.
        if ($previous !== null && (int) $previous->claimed_at > time() - self::RECLAIM_AFTER) {
            return;
        }

        $outcome    = 'unknown';
        $invitation = null;

        if ($this->lists->holds($address, $this->listsOf($campaign))) {
            [$outcome, $invitation] = $this->inviteOrGreet($address);
        }

        $this->record((int) $campaign->id, $hash, $outcome, $invitation, $previous !== null);
    }

    /**
     * On a list, so this address belongs to the family. Two possibilities.
     *
     * @return array{0:string,1:int|null}
     */
    private function inviteOrGreet(string $address): array
    {
        $existing = $this->users->findByEmail($address);

        if ($existing !== null) {
            // An account already. Sending an invitation would be an offer to
            // create a second one; what this person needs is the way back into
            // the one they have.
            $this->send(
                new User(0, '', $existing->realName(), $address),
                I18N::translate('You already have an account in the family portal'),
                'emails/campaign-existing',
                ['url' => $this->portal(), 'name' => $existing->realName()]
            );

            return ['existing', null];
        }

        $tree       = $this->trees->tree();
        $name       = $this->exchange->recipientName($address);
        $individual = $this->individualFor($tree, $name);

        $token = $this->invitations->create(
            $tree,
            $individual?->xref() ?? '',
            $individual === null ? $this->personName($name) : $individual->fullName(),
            $address,
            null,
            (int) $this->module->getPreference(PortalApiModule::SETTING_INVITATION_DAYS, (string) InvitationService::DEFAULT_VALIDITY_DAYS)
        );

        $id = (int) DB::lastInsertId();

        $this->send(
            new User(0, '', $this->personName($name), $address),
            I18N::translate('Your invitation to the family portal'),
            'emails/campaign-invitation',
            ['url' => $this->invitationLink($token), 'name' => $this->personName($name)]
        );

        return ['invited', $id];
    }

    /**
     * The record this contact's name points at, if it points at exactly one.
     *
     * The archive number is the first thing in the name — `22/1a32.124 Antje
     * Beispiel`, and in this archive also plainly `4711 Anna Beispiel`, which
     * is why the test for one is `archiveNumberIn()`'s and not
     * `SackNumbers::path()`'s. That method parses the *descent* form in order
     * to do arithmetic on it, and half this family's numbers are not in it;
     * gating on it linked nobody whose number has no oblique in it.
     *
     * Nothing is guessed. `individualByNumber()` answers only when exactly one
     * record carries the number, so a token that merely looks numeric finds
     * nobody rather than somebody.
     */
    private function individualFor(Tree $tree, string $name): Individual|null
    {
        $number = $this->archiveNumberIn($name);

        return $number === '' ? null : $this->search->individualByNumber($tree, $number);
    }

    /**
     * The first word of a contact's name, where it could be an archive number.
     *
     * "Could be" is deliberately the same test `TreeSearch::byReference()`
     * uses — it contains a digit — because these two have to agree about what
     * a number looks like or the lookup is asked questions it will not answer.
     */
    private function archiveNumberIn(string $name): string
    {
        $parts = preg_split('/\s+/', trim($name));
        $first = $parts === false ? '' : trim($parts[0] ?? '');

        return $first !== '' && preg_match('/\d/', $first) === 1 ? $first : '';
    }

    /** The name without the number in front of it, for a greeting. */
    private function personName(string $name): string
    {
        $parts = preg_split('/\s+/', trim($name));
        $parts = $parts === false ? [] : $parts;

        if ($parts !== [] && $this->archiveNumberIn($name) !== '') {
            unset($parts[0]);
        }

        return trim(implode(' ', $parts));
    }

    // -----------------------------------------------------------------

    private function usable(string $token): object|null
    {
        $token = trim($token);

        if ($token === '') {
            return null;
        }

        return DB::table('portal_invitation_campaign')
            ->where('token_hash', '=', self::hash($token))
            ->whereNull('revoked_at')
            ->where('expires_at', '>', time())
            ->first();
    }

    private function record(int $campaign, string $hash, string $outcome, int|null $invitation, bool $again): void
    {
        $columns = [
            'outcome'       => $outcome,
            'invitation_id' => $invitation,
            'claimed_at'    => time(),
        ];

        if ($again) {
            DB::table('portal_invitation_claim')
                ->where('campaign_id', '=', $campaign)
                ->where('address_hash', '=', $hash)
                ->update($columns);

            return;
        }

        DB::table('portal_invitation_claim')
            ->insert($columns + ['campaign_id' => $campaign, 'address_hash' => $hash]);
    }

    /**
     * @param array<string,mixed> $data
     */
    private function send(UserInterface $to, string $subject, string $view, array $data): void
    {
        try {
            $this->email->send(
                new SiteUser(),
                $to,
                new SiteUser(),
                $subject,
                view($this->module->name() . '::' . $view . '-text', $data),
                view($this->module->name() . '::' . $view . '-html', $data)
            );
        } catch (Throwable $exception) {
            // A mail server that is down must not produce a different answer
            // from an address that is on no list.
            error_log('portal_api: could not send an invitation email: ' . $exception->getMessage());
        }
    }

    /**
     * @return array<int,string>
     */
    private function listsOf(object $campaign): array
    {
        return array_values(array_filter(
            explode("\n", (string) $campaign->lists),
            static fn (string $hash): bool => trim($hash) !== ''
        ));
    }

    /**
     * @return array<string,string>
     */
    private function listNames(): array
    {
        $names = [];

        foreach ($this->lists->configured() as $hash => $list) {
            $names[$hash] = $list['name'];
        }

        return $names;
    }

    private function portal(): string
    {
        return rtrim($this->module->getPreference(PortalApiModule::SETTING_PORTAL_URL, ''), '/');
    }

    private function invitationLink(string $token): string
    {
        return $this->portal() . '/invitation?token=' . rawurlencode($token);
    }

    private static function hash(string $value): string
    {
        return hash('sha256', $value);
    }
}
