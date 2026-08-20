<?php

declare(strict_types=1);

namespace Engelking\Webtrees\PortalApi\Services;

use Engelking\Webtrees\PortalApi\Http\ApiException;
use Engelking\Webtrees\PortalApi\PortalApiModule;
use Fig\Http\Message\StatusCodeInterface;
use Fisharebest\Webtrees\Contracts\UserInterface;
use Fisharebest\Webtrees\Http\Exceptions\HttpTooManyRequestsException;
use Fisharebest\Webtrees\I18N;
use Fisharebest\Webtrees\Log;
use Fisharebest\Webtrees\Services\MessageService;
use Fisharebest\Webtrees\Services\RateLimitService;
use Fisharebest\Webtrees\Site;

use function mb_strlen;
use function max;
use function min;
use function trim;

/**
 * One member writing to another.
 *
 * Delivery is webtrees' own `MessageService::deliverMessage()`, which puts the
 * message wherever the *recipient* chose to be reached — their internal
 * webtrees inbox, their email address, or both. The portal does not decide
 * that and does not need to know the answer, which is the point: a member can
 * be written to without their address ever passing through here.
 *
 * **What does travel is the sender's address.** `deliverMessage()` records
 * `Auth::user()->email()` on the stored message and sets the sender as the
 * `Reply-To` of the email, so that a reply is possible at all. There is no
 * version of "write to me and I will answer" that avoids it. So the portal
 * says so on the form, before the send button — see
 * `portal/src/routes/MemberDetail.tsx`. Hiding an unavoidable disclosure
 * would be worse than the disclosure.
 *
 * Two limits, for the two things that go wrong with a message box in a
 * family: only members who put themselves in the directory can be written to,
 * and nobody can send very many in a day.
 */
class MemberMessages
{
    public const int DEFAULT_DAILY_LIMIT = 20;
    public const int MAX_DAILY_LIMIT     = 200;

    private const int MAX_SUBJECT_LENGTH = 128;
    private const int MAX_BODY_LENGTH    = 4000;

    public function __construct(
        private readonly PortalApiModule $module,
        private readonly MessageService $messages,
        private readonly RateLimitService $rate_limits,
        private readonly MemberService $members,
    ) {
    }

    public function enabled(): bool
    {
        return $this->module->getPreference(PortalApiModule::SETTING_MEMBER_MESSAGES, '1') === '1';
    }

    public function dailyLimit(): int
    {
        return max(0, min(
            self::MAX_DAILY_LIMIT,
            (int) $this->module->getPreference(PortalApiModule::SETTING_MESSAGE_LIMIT, (string) self::DEFAULT_DAILY_LIMIT)
        ));
    }

    /**
     * Send one message, and say whether it was delivered.
     *
     * @param int $recipient_id A portal member id — not a webtrees user id.
     */
    public function send(UserInterface $sender, int $recipient_id, string $subject, string $body, string $ip): void
    {
        if (!$this->enabled()) {
            throw new ApiException(
                'not_allowed',
                StatusCodeInterface::STATUS_FORBIDDEN,
                I18N::translate('Members cannot send messages in this family tree.')
            );
        }

        $subject = trim($subject);
        $body    = trim($body);

        if ($subject === '' || $body === '') {
            throw ApiException::badRequest(I18N::translate('Please fill in both the subject and the message.'));
        }

        if (mb_strlen($subject) > self::MAX_SUBJECT_LENGTH || mb_strlen($body) > self::MAX_BODY_LENGTH) {
            throw ApiException::badRequest(I18N::translate('That message is too long.'));
        }

        $member = $this->members->visibleMember($recipient_id);

        // `visibleMember()` is already "listed in the directory, or nothing".
        // A member who kept themselves out of the directory is not reachable,
        // and is reported as not found rather than as refused — the same
        // answer as a member id that never existed, so this is not a way to
        // discover who exists.
        if ($member === null) {
            throw ApiException::notFound();
        }

        if ($member->user->id() === $sender->id()) {
            throw ApiException::badRequest(I18N::translate('You cannot send a message to yourself.'));
        }

        $this->refuseIfTooMany($sender);
        $this->ensureRecipientHasALanguage($member->user);

        $delivered = $this->messages->deliverMessage(
            $sender,
            $member->user,
            $subject,
            $body,
            // The "where did this come from" link in webtrees' own template.
            // The portal's address, because that is where the conversation is.
            $this->module->getPreference(PortalApiModule::SETTING_PORTAL_URL, ''),
            $ip
        );

        Log::addAuthenticationLog('Portal message from ' . $sender->userName() . ' to ' . $member->user->userName());

        if (!$delivered) {
            // webtrees returns false when the email could not be sent. The
            // internal copy may still have been stored, so this is "we are
            // not sure", not "nothing happened" — and saying it was sent
            // would be the worse of the two mistakes.
            throw new ApiException(
                'not_delivered',
                StatusCodeInterface::STATUS_SERVICE_UNAVAILABLE,
                I18N::translate('The message could not be delivered. Please try again later.')
            );
        }
    }

    /**
     * Give the recipient a language, if nobody ever did.
     *
     * `MessageService::deliverMessage()` opens with
     * `I18N::init($recipient->getPreference(PREF_LANGUAGE))` so the message is
     * written in the recipient's language — and `Locale::create('')` throws a
     * `DomainException`. So an account whose language preference was never set
     * cannot be written to at all, and the sender sees a 500.
     *
     * Accounts created by invitation always have one. Accounts created by hand
     * in webtrees may not, which makes this exactly the pre-existing accounts
     * a family would be writing to first.
     *
     * Writing a value onto somebody else's account is not nothing, so it is
     * worth being precise about what it is: this fills in a missing default,
     * never overrides a choice, and the value is the one webtrees' own
     * `UseLanguage` middleware falls back to for the same case. The portal
     * already met this trap once, in `SessionCreate`, where a blank preference
     * would otherwise have locked a member out of signing in.
     */
    private function ensureRecipientHasALanguage(UserInterface $recipient): void
    {
        if ($recipient->getPreference(UserInterface::PREF_LANGUAGE, '') === '') {
            $recipient->setPreference(UserInterface::PREF_LANGUAGE, Site::getPreference('LANGUAGE'));
        }
    }

    /**
     * webtrees' own per-user limiter, the same one password resets use.
     *
     * Counted per sender rather than per IP: a family shares an address more
     * often than a stranger shares an account, and the thing worth limiting
     * here is one member writing to everybody, not one household writing at
     * all.
     */
    private function refuseIfTooMany(UserInterface $sender): void
    {
        $limit = $this->dailyLimit();

        if ($limit === 0) {
            throw new ApiException(
                'not_allowed',
                StatusCodeInterface::STATUS_FORBIDDEN,
                I18N::translate('Members cannot send messages in this family tree.')
            );
        }

        try {
            $this->rate_limits->limitRateForUser($sender, $limit, 86400, 'rate-limit-portal-message');
        } catch (HttpTooManyRequestsException) {
            Log::addAuthenticationLog('Portal message rate-limited: ' . $sender->userName());

            throw new ApiException(
                'quota_reached',
                StatusCodeInterface::STATUS_CONFLICT,
                I18N::translate('You have sent as many messages as you may send today. Please try again tomorrow.')
            );
        }
    }
}
