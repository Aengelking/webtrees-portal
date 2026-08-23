<?php

declare(strict_types=1);

namespace Engelking\Webtrees\PortalApi\Services;

use Engelking\Webtrees\PortalApi\PortalApiModule;

use function array_filter;
use function array_walk_recursive;
use function curl_close;
use function curl_error;
use function curl_exec;
use function curl_getinfo;
use function curl_init;
use function curl_setopt_array;
use function http_build_query;
use function is_array;
use function is_string;
use function json_decode;
use function json_encode;
use function mb_strtolower;
use function mb_substr;
use function preg_replace;
use function rawurlencode;
use function sha1;
use function sprintf;
use function str_contains;
use function trim;

use const CURLINFO_HTTP_CODE;
use const CURLOPT_CONNECTTIMEOUT;
use const CURLOPT_CUSTOMREQUEST;
use const CURLOPT_HTTPHEADER;
use const CURLOPT_POSTFIELDS;
use const CURLOPT_RETURNTRANSFER;
use const CURLOPT_TIMEOUT;
use const JSON_UNESCAPED_SLASHES;
use const JSON_UNESCAPED_UNICODE;

/**
 * Adding a family address to an Exchange Online distribution list, and taking
 * it off again.
 *
 * **Why this is not Microsoft Graph.** It would be, if it could be. Graph is
 * the documented, supported, pleasant way to manage groups — and classic
 * distribution lists are the one kind of group it will not manage. They belong
 * to Exchange rather than to the directory, so Graph's `/groups` shows them
 * and refuses to change them. The supported way to change one is the
 * Exchange Online PowerShell module, which is not a thing a PHP process on a
 * shared webhost can run.
 *
 * What is left is the REST endpoint that module itself calls:
 * `adminapi/beta/{tenant}/InvokeCommand`, a cmdlet name in a header and its
 * parameters in the body. It authenticates with an ordinary app-only token
 * (`Exchange.ManageAsApp`), it is the same surface the supported module uses,
 * and it is *beta and undocumented*. That is the price of the decision, and it
 * is written here rather than buried: if a future Exchange release moves it,
 * subscriptions stop being applied, every row goes outstanding, and the
 * diagnosis screen says so. Nothing is lost but the syncing — the members'
 * wishes are in this portal's own database and can be applied by hand, or by
 * whatever replaces this class.
 *
 * **Why external addresses make this longer than it looks.** A distribution
 * list cannot hold an address; it holds *recipients*. Family members on
 * gmail.com are not recipients in the family's tenant until somebody makes a
 * mail contact for them, so subscribing is two or three calls rather than
 * one — find the recipient, create it if it is absent, then add it. Removal is
 * one call and deliberately leaves the contact behind: it may be on another
 * list, and deleting a recipient because one list lost interest in it is not
 * this module's decision to make.
 *
 * **No token is cached.** A client-credentials token is a bearer credential
 * for the whole tenant, and the only reason to keep one would be to save a
 * round trip on a request that already has several. Members change these
 * settings a handful of times each; reading them costs nothing here at all.
 * So the token lives for the length of one PHP request and is never written
 * anywhere.
 */
class ExchangeOnline
{
    /** Seconds. A family portal must not sit waiting on somebody else's cloud. */
    private const int TIMEOUT = 10;

    private const string TOKEN_URL = 'https://login.microsoftonline.com/%s/oauth2/v2.0/token';

    /** The scope, and the audience of the token. `.default` means "whatever was consented". */
    private const string SCOPE = 'https://outlook.office365.com/.default';

    private const string ADMIN_API = 'https://outlook.office365.com/adminapi/beta/%s/InvokeCommand';

    public function __construct(private readonly PortalApiModule $module)
    {
    }

    /** Whether there is anything to try at all. */
    public function configured(): bool
    {
        return $this->tenant() !== '' && $this->clientId() !== '' && $this->clientSecret() !== '';
    }

    /**
     * Put an address on a list.
     *
     * Idempotent, and not by trusting Exchange to say so in a particular form
     * of words. If the add fails, the membership is read back; where it already
     * agrees with what was wanted, the call succeeded and the error was
     * Exchange telling us so in a sentence this module would otherwise have to
     * recognise by its wording. See `reconciles()`.
     *
     * Not for a refusal about permission, though — see `ExchangeFailure`. Those
     * are rethrown without asking, because a list that already says the right
     * thing proves nothing about a call that was never allowed to run.
     */
    public function subscribe(string $list, string $address, string $name): void
    {
        $token = $this->token();

        $this->ensureRecipient($token, $address, $name);

        try {
            $this->invoke($token, 'Add-DistributionGroupMember', [
                'Identity' => $list,
                'Member'   => $address,
                // The application is not the list's manager and should not
                // have to be made one to do the single thing it is for.
                'BypassSecurityGroupManagerCheck' => true,
            ]);
        } catch (ExchangeFailure $failure) {
            if ($failure->denied || !$this->reconciles($token, $list, $address, true)) {
                throw $failure;
            }
        }
    }

    /**
     * Take an address off a list. The mail contact stays; see the class note.
     */
    public function unsubscribe(string $list, string $address): void
    {
        $token = $this->token();

        try {
            $this->invoke($token, 'Remove-DistributionGroupMember', [
                'Identity' => $list,
                'Member'   => $address,
                'BypassSecurityGroupManagerCheck' => true,
                // There is no console here to answer a prompt.
                'Confirm'  => false,
            ]);
        } catch (ExchangeFailure $failure) {
            if ($failure->denied || !$this->reconciles($token, $list, $address, false)) {
                throw $failure;
            }
        }
    }

    /**
     * Can this configuration reach that list? For the diagnosis screen.
     *
     * Returns an empty string when all is well, and Exchange's own complaint
     * when it is not — an administrator is the only person who ever sees this,
     * and a paraphrase would be less useful to them than the original.
     */
    public function check(string $list): string
    {
        try {
            $this->invoke($this->token(), 'Get-DistributionGroup', ['Identity' => $list]);
        } catch (ExchangeFailure $failure) {
            return $failure->getMessage();
        }

        return '';
    }

    // -----------------------------------------------------------------
    // Recipients
    // -----------------------------------------------------------------

    /**
     * Make sure the address is something a list can hold.
     *
     * An address inside the tenant is already a recipient and this does
     * nothing. An outside one — which is most of a family — needs a mail
     * contact, and the two things Exchange insists be unique about one are
     * `Alias` and `Name`.
     *
     * `Alias` is derived from the address rather than from the person, because
     * it has to be unique, valid, and stable across a change of name. `Name`
     * is the person's, because it is what an administrator reads in the admin
     * centre — and where the family already contains a second person of that
     * name, the collision is resolved on the second attempt rather than
     * pre-empted by decorating every name with a hash.
     */
    private function ensureRecipient(string $token, string $address, string $name): void
    {
        if ($this->recipientExists($token, $address)) {
            return;
        }

        $alias   = $this->alias($address);
        $display = $this->displayName($name, $address);

        $parameters = [
            'Name'                 => $display,
            'DisplayName'          => $display,
            'Alias'                => $alias,
            'ExternalEmailAddress' => $address,
        ];

        try {
            $this->invoke($token, 'New-MailContact', $parameters);
        } catch (ExchangeFailure $failure) {
            // Almost always a name already in use. Rather than reading the
            // message, try the one name that cannot be: the address is unique
            // by definition.
            $parameters['Name'] = mb_substr($display . ' (' . $address . ')', 0, 64);

            try {
                $this->invoke($token, 'New-MailContact', $parameters);
            } catch (ExchangeFailure) {
                // Report the first failure, not the second: if the retry was
                // the wrong medicine, the original complaint is the useful one.
                throw $failure;
            }
        }

        if ($this->module->getPreference(PortalApiModule::SETTING_EXCHANGE_HIDE_CONTACTS, '1') !== '1') {
            return;
        }

        // A family member's private address is not an entry in the family
        // business's address book. This is a second call because Exchange does
        // not accept the flag when the contact is created, and it is allowed
        // to fail loudly: a contact that is created but left visible is not
        // the outcome this was switched on for.
        $this->invoke($token, 'Set-MailContact', [
            'Identity'                       => $alias,
            'HiddenFromAddressListsEnabled'  => true,
        ]);
    }

    private function recipientExists(string $token, string $address): bool
    {
        try {
            $found = $this->invoke($token, 'Get-Recipient', ['Identity' => $address]);
        } catch (ExchangeFailure) {
            // "No such recipient" arrives as an error rather than as an empty
            // answer. So does "the application may not ask", which is why the
            // creation that follows is allowed to fail in its own right
            // instead of this returning something reassuring.
            return false;
        }

        return $found !== [];
    }

    /**
     * Does the list already say what the member wanted it to say?
     *
     * Asked only after a failure that was not about permission, and it is what
     * saves this module from having to recognise "is already a member of the
     * group" — a sentence in Exchange's language, subject to Exchange's changes
     * of mind, and different again for a removal. Reading the membership is
     * unambiguous.
     *
     * The exclusion is not a detail. On the first tenant this ran against, the
     * application could read everything and write nothing, and the
     * administrator testing it was already on the list he was subscribing to.
     * Every add was refused, every read-back agreed, and the portal reported
     * three working subscriptions. Only the first *unsubscribe* — where reality
     * and the wish finally disagreed — admitted that nothing had ever been
     * applied.
     *
     * A member's address can sit in any of several fields of the object
     * Exchange returns — `PrimarySmtpAddress` for a mailbox, an
     * `SMTP:`-prefixed `ExternalEmailAddress` for a contact — so every string
     * in the answer is searched rather than a chosen few.
     */
    private function reconciles(string $token, string $list, string $address, bool $wanted): bool
    {
        try {
            $members = $this->invoke($token, 'Get-DistributionGroupMember', [
                'Identity'   => $list,
                'ResultSize' => 'Unlimited',
            ]);
        } catch (ExchangeFailure) {
            return false;
        }

        $needle = mb_strtolower($address);
        $found  = false;

        array_walk_recursive($members, static function ($value) use ($needle, &$found): void {
            if (is_string($value) && str_contains(mb_strtolower($value), $needle)) {
                $found = true;
            }
        });

        return $found === $wanted;
    }

    // -----------------------------------------------------------------
    // The wire
    // -----------------------------------------------------------------

    /**
     * An app-only token for Exchange, by client credentials.
     *
     * This is the client secret being exchanged for a bearer token, so the
     * failure here that matters is the boring one: a secret that expired.
     * Entra application secrets have an end date, they are not renewed by
     * anybody being reminded, and the symptom is every subscription silently
     * going outstanding. The diagnosis screen exists so that it is not silent.
     */
    private function token(): string
    {
        $body = http_build_query([
            'client_id'     => $this->clientId(),
            'client_secret' => $this->clientSecret(),
            'scope'         => self::SCOPE,
            'grant_type'    => 'client_credentials',
        ]);

        $url = sprintf(self::TOKEN_URL, rawurlencode($this->tenant()));

        [$status, $payload] = $this->send($url, $body, ['Content-Type: application/x-www-form-urlencoded']);

        $decoded = json_decode($payload, true);
        $token   = is_array($decoded) && is_string($decoded['access_token'] ?? null) ? $decoded['access_token'] : '';

        if ($status === 200 && $token !== '') {
            return $token;
        }

        throw new ExchangeFailure(
            'Exchange refused the application credentials (HTTP ' . $status . '): ' . $this->complaint($payload),
            // A rejected credential is not a passing condition, and neither is
            // a tenant that cannot be found. Anything that is not an answer at
            // all might be.
            $status >= 400 && $status < 500,
            // Nothing that needs a token can have happened, so nothing that
            // follows may be read as evidence that it did.
            true
        );
    }

    /**
     * One cmdlet.
     *
     * @param array<string,mixed> $parameters
     *
     * @return array<int,mixed> whatever the cmdlet returned, or an empty array
     */
    private function invoke(string $token, string $cmdlet, array $parameters): array
    {
        $url = sprintf(self::ADMIN_API, rawurlencode($this->tenant()));

        $body = (string) json_encode(
            ['CmdletInput' => ['CmdletName' => $cmdlet, 'Parameters' => $parameters]],
            JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE
        );

        [$status, $payload] = $this->send($url, $body, [
            'Authorization: Bearer ' . $token,
            'Content-Type: application/json',
            'Accept: application/json',
            // The endpoint dispatches on this header, not on the body.
            'X-CmdletName: ' . $cmdlet,
        ]);

        if ($status >= 200 && $status < 300) {
            $decoded = json_decode($payload, true);
            $value   = is_array($decoded) ? ($decoded['value'] ?? []) : [];

            return is_array($value) ? array_filter($value, static fn ($row): bool => $row !== null) : [];
        }

        $denied = $status === 401 || $status === 403;

        throw new ExchangeFailure(
            $cmdlet . ' failed (HTTP ' . $status . '): ' . $this->complaint($payload)
                // Exchange answers a refused cmdlet with a bare 403 and often
                // an empty body, which on its own tells an administrator
                // nothing at all. What it always means is this, so it is said
                // here rather than left to be looked up.
                . ($denied ? ' — the application may not run this cmdlet. Check the Entra role on its service principal.' : ''),
            // 429 is Exchange throttling and 5xx is Exchange having a bad day;
            // both mean the same thing to a member, which is "later". A 0 is
            // this end — a timeout or a name that did not resolve — and is
            // also worth another attempt.
            $status >= 400 && $status < 500 && $status !== 429,
            $denied
        );
    }

    /**
     * @param array<int,string> $headers
     *
     * @return array{0:int,1:string}
     */
    protected function send(string $url, string $body, array $headers): array
    {
        $handle = curl_init($url);

        curl_setopt_array($handle, [
            CURLOPT_CUSTOMREQUEST  => 'POST',
            CURLOPT_POSTFIELDS     => $body,
            CURLOPT_HTTPHEADER     => $headers,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => self::TIMEOUT,
            CURLOPT_CONNECTTIMEOUT => self::TIMEOUT,
        ]);

        $response = curl_exec($handle);
        $status   = (int) curl_getinfo($handle, CURLINFO_HTTP_CODE);
        $error    = curl_error($handle);

        curl_close($handle);

        if ($response === false) {
            throw new ExchangeFailure('Exchange could not be reached: ' . $error);
        }

        return [$status, (string) $response];
    }

    /**
     * The sentence inside an error payload, or the payload if it has no shape.
     *
     * Trimmed hard. This is stored in a column and shown on an administrator's
     * screen; Exchange's stack traces are neither wanted in either place.
     */
    private function complaint(string $payload): string
    {
        $decoded = json_decode($payload, true);

        if (is_array($decoded)) {
            $message = $decoded['error']['message']
                ?? $decoded['error_description']
                ?? $decoded['message']
                ?? null;

            if (is_string($message) && trim($message) !== '') {
                return mb_substr(trim($message), 0, 300);
            }
        }

        $raw = mb_substr(trim($payload), 0, 300);

        // A refusal with nothing in it is the case this exists for. Reporting
        // it as an empty string produced "failed (HTTP 403):" and a full stop,
        // which reads like the message went missing rather than like Exchange
        // never sent one.
        return $raw === '' ? 'no message was returned' : $raw;
    }

    // -----------------------------------------------------------------
    // Naming
    // -----------------------------------------------------------------

    /** Valid, unique and stable — which a person's name is none of. */
    private function alias(string $address): string
    {
        return 'portal-' . mb_substr(sha1(mb_strtolower($address)), 0, 16);
    }

    /**
     * What an administrator will see in the admin centre.
     *
     * Exchange refuses a `Name` containing any of `" / \ [ ] : ; | = , + * ? < >`
     * and will not take one over 64 characters. A member whose display name is
     * nothing but punctuation falls back to the address, which is always
     * something.
     */
    private function displayName(string $name, string $address): string
    {
        $clean = trim((string) preg_replace('/["\/\\\\\[\]:;|=,+*?<>]+/u', ' ', $name));
        $clean = trim((string) preg_replace('/\s+/u', ' ', $clean));

        return $clean === '' ? mb_substr($address, 0, 64) : mb_substr($clean, 0, 64);
    }

    private function tenant(): string
    {
        return trim($this->module->getPreference(PortalApiModule::SETTING_EXCHANGE_TENANT, ''));
    }

    private function clientId(): string
    {
        return trim($this->module->getPreference(PortalApiModule::SETTING_EXCHANGE_CLIENT_ID, ''));
    }

    private function clientSecret(): string
    {
        return trim($this->module->getPreference(PortalApiModule::SETTING_EXCHANGE_SECRET, ''));
    }
}
