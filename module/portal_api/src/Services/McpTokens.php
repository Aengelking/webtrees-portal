<?php

declare(strict_types=1);

namespace Engelking\Webtrees\PortalApi\Services;

use Fisharebest\Webtrees\Contracts\UserInterface;
use Fisharebest\Webtrees\DB;
use Fisharebest\Webtrees\Services\UserService;
use Fisharebest\Webtrees\User;
use Illuminate\Support\Collection;

use function bin2hex;
use function hash;
use function max;
use function min;
use function random_bytes;
use function str_starts_with;
use function substr;
use function time;
use function trim;

/**
 * Issuing, presenting and withdrawing the MCP server's credentials.
 *
 * The rules, which are the ones the rest of this module already keeps:
 *
 *  - the raw token is returned exactly once, when it is issued, and is never
 *    stored — only its SHA-256;
 *  - an unknown token, an expired one, a revoked one and one whose account has
 *    since been deleted are the same answer, `null`, so that the refusal
 *    cannot be read for information;
 *  - the token says which account to read as, and nothing else. What that
 *    account may see is webtrees' question, asked again on every request.
 *
 * **The prefix is not decoration.** A token that begins `wtmcp_` is
 * recognisable on sight in a configuration file, in a paste, and to the secret
 * scanners that read repositories for credentials somebody committed by
 * accident. The randomness is all after it.
 */
class McpTokens
{
    public const string TABLE = 'portal_mcp_token';

    /**
     * What every token starts with. See the class note.
     *
     * Hashed along with the rest — it is part of the string the client sends,
     * so it is part of the string that is hashed.
     */
    public const string PREFIX = 'wtmcp_';

    public const int DEFAULT_VALIDITY_DAYS = 365;
    public const int MAX_VALIDITY_DAYS     = 3650;

    /** 32 bytes of randomness, hex encoded. */
    private const int TOKEN_BYTES = 32;

    public function __construct(private readonly UserService $users)
    {
    }

    /**
     * Issue a token for an account, and return it.
     *
     * The return value is the only copy there will ever be. An administrator
     * who loses it before it reaches the client's configuration revokes this
     * one and issues another; there is nothing to look up.
     */
    public function create(
        string $name,
        UserInterface $reads_as,
        UserInterface|null $creator,
        int $valid_days = self::DEFAULT_VALIDITY_DAYS
    ): string {
        $token = self::PREFIX . bin2hex(random_bytes(self::TOKEN_BYTES));
        $days  = min(self::MAX_VALIDITY_DAYS, max(1, $valid_days));
        $now   = time();

        DB::table(self::TABLE)->insert([
            'token_hash' => $this->hash($token),
            'name'       => $this->label($name),
            'wt_user_id' => $reads_as->id(),
            'created_by' => $creator?->id(),
            'created_at' => $now,
            'expires_at' => $now + $days * 86400,
        ]);

        return $token;
    }

    /**
     * The account this token reads the archive as, if it is good for anything.
     *
     * Everything that can be wrong with a token comes back the same way, and
     * the caller has nothing to tell them apart with: unknown, expired,
     * revoked, malformed, or naming an account that no longer exists.
     *
     * **The account is fetched rather than trusted.** A row in this table is
     * an assertion that some `user_id` may be read as; whether that account
     * still exists is a different question, and `UserService` is the one that
     * answers it. Whether it may see anything at all is a third question,
     * asked later still, by webtrees.
     */
    public function authenticate(string $token): User|null
    {
        $token = trim($token);

        // Cheap and exact: everything this module issues starts with the
        // prefix, so anything that does not is not one of ours and there is no
        // reason to go to the database about it.
        if ($token === '' || !str_starts_with($token, self::PREFIX)) {
            return null;
        }

        $row = DB::table(self::TABLE)
            ->where('token_hash', '=', $this->hash($token))
            ->whereNull('revoked_at')
            ->where('expires_at', '>', time())
            ->first();

        if ($row === null) {
            return null;
        }

        $user = $this->users->find((int) $row->wt_user_id);

        if ($user === null || !$this->usable($user)) {
            return null;
        }

        $this->recordUse((int) $row->id);

        return $user;
    }

    /**
     * Is this account one somebody could sign in with today?
     *
     * The same two questions `SessionCreate` asks of a password, asked of a
     * token. An administrator who suspends an account expects that to end its
     * access, and would not think to go looking for a token issued against it
     * months ago — so the token asks the account, on every request, rather than
     * standing on what was true when it was issued.
     */
    private function usable(UserInterface $user): bool
    {
        return $user->getPreference(UserInterface::PREF_IS_EMAIL_VERIFIED) === '1'
            && $user->getPreference(UserInterface::PREF_IS_ACCOUNT_APPROVED) === '1';
    }

    /**
     * Every token, spent and unspent, newest first.
     *
     * For the administrator's screen, which is the only caller. Revoked and
     * expired rows are included on purpose: "this token was withdrawn on
     * Tuesday" is the answer to somebody asking why their assistant stopped
     * working, and a row that vanishes cannot give it.
     *
     * @return Collection<int,McpToken>
     */
    public function all(): Collection
    {
        return DB::table(self::TABLE)
            ->orderByDesc('id')
            ->get()
            ->map(static fn (object $row): McpToken => McpToken::fromRow($row));
    }

    /** Withdraw one token. Kept as a row — see `all()`. */
    public function revoke(int $id): void
    {
        DB::table(self::TABLE)
            ->where('id', '=', $id)
            ->whereNull('revoked_at')
            ->update(['revoked_at' => time()]);
    }

    /**
     * Are there any tokens that would work right now?
     *
     * Asked by the diagnosis screen, which should say "the MCP server is
     * switched on and nothing can open it" rather than reporting it as well.
     */
    public function usableCount(): int
    {
        return DB::table(self::TABLE)
            ->whereNull('revoked_at')
            ->where('expires_at', '>', time())
            ->count();
    }

    /**
     * When, and how often. Not what was asked — see Schema/Migration16.php.
     *
     * A plain `UPDATE` with no read in front of it, so that two requests
     * arriving together cost one row lock rather than a lost count.
     */
    private function recordUse(int $id): void
    {
        DB::table(self::TABLE)
            ->where('id', '=', $id)
            ->increment('uses', 1, ['last_used_at' => time()]);
    }

    /**
     * A name for the administrator's screen, never empty and never too long
     * for its column.
     */
    private function label(string $name): string
    {
        $name = trim($name);

        return $name === '' ? 'MCP' : substr($name, 0, 128);
    }

    /**
     * SHA-256, unsalted and unstretched, exactly as the other tokens in this
     * module are stored. There is nothing to stretch: the token is 32 bytes
     * from `random_bytes()`, so there is no dictionary to run against it and
     * a slow hash would only make every request slower.
     */
    private function hash(string $token): string
    {
        return hash('sha256', $token);
    }
}
