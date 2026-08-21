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
 * family: only members who put themselves in the directory — or who connected
 * with the sender, which is consent given to that one person — can be written
 * to, and nobody can send very many in a day.
 *
 * A **reply** is the one case where the first limit is lifted — see
 * `reply()`. The daily limit is not lifted, and a reply is counted against it
 * like anything else: it is a message, and the limit is about volume.
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
        private readonly Inbox $inbox,
        private readonly Connections $connections,
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
        $this->refuseIfDisabled();

        $subject = trim($subject);
        $body    = trim($body);

        if ($subject === '' || $body === '') {
            throw ApiException::badRequest(I18N::translate('Please fill in both the subject and the message.'));
        }

        if (mb_strlen($subject) > self::MAX_SUBJECT_LENGTH || mb_strlen($body) > self::MAX_BODY_LENGTH) {
            throw ApiException::badRequest(I18N::translate('That message is too long.'));
        }

        $member = $this->members->readableMember($recipient_id, $this->connections->disclosableUserIds($sender));

        // "Listed in the directory, or connected to me, or nothing." A member
        // who kept themselves out of the directory is not reachable by
        // anybody they have not connected with, and is reported as not found
        // rather than as refused — the same answer as a member id that never
        // existed, so this is not a way to discover who exists.
        //
        // The connection is the second lifting of the directory rule, and it
        // rests on the same argument as the first one below: nothing is
        // discovered by writing to somebody who agreed to know you.
        if ($member === null) {
            throw ApiException::notFound();
        }

        if ($member->user->id() === $sender->id()) {
            throw ApiException::badRequest(I18N::translate('You cannot send a message to yourself.'));
        }

        $this->deliver($sender, $member->user, $subject, $body, $ip);
    }

    /**
     * Answer a message that arrived in the member's own inbox.
     *
     * **The directory rule does not apply here, and that is deliberate.**
     * `send()` will only write to a member who listed themselves, because
     * picking somebody out of a directory is *finding* them. A reply is not:
     * the other person wrote first, so nothing is discovered — not that they
     * exist, not that they hold an account, not their name. Refusing to let a
     * member answer somebody who wrote to them would make the portal a place
     * where messages arrive and cannot be answered, which is the gap Phase 10
     * set out to close.
     *
     * What *is* disclosed is the replier's own address, exactly as in
     * `send()`, and the form says so before the button. That disclosure is
     * their choice; being written to was not.
     *
     * The subject is not the sender's to choose — it is webtrees' `RE: `
     * convention applied to the original. One less field on a phone, and no
     * way to write a reply that arrives looking like a new conversation.
     */
    public function reply(UserInterface $sender, int $message_id, string $body, string $ip): void
    {
        $this->refuseIfDisabled();

        $body = trim($body);

        if ($body === '') {
            throw ApiException::badRequest(I18N::translate('Please fill in both the subject and the message.'));
        }

        if (mb_strlen($body) > self::MAX_BODY_LENGTH) {
            throw ApiException::badRequest(I18N::translate('That message is too long.'));
        }

        // A message that is not the member's own is a 404 from in here.
        $target = $this->inbox->replyTarget($sender, $message_id);

        if ($target === null) {
            // Not "not found": the member is looking at their own message and
            // may be told plainly why it has no answer button — the address on
            // it belongs to nobody the portal can deliver to.
            throw new ApiException(
                'cannot_reply',
                StatusCodeInterface::STATUS_CONFLICT,
                I18N::translate('This message cannot be answered here. Please write to the sender directly.')
            );
        }

        $this->deliver($sender, $target['user'], $target['subject'], $body, $ip);
    }

    /**
     * Hand the message to webtrees, and be honest about what came back.
     */
    private function deliver(
        UserInterface $sender,
        UserInterface $recipient,
        string $subject,
        string $body,
        string $ip
    ): void {
        $this->refuseIfTooMany($sender);
        $this->ensureRecipientHasALanguage($recipient);
        $this->ensureRecipientCanBeReached($recipient);

        // Asked before delivering, because it is the question webtrees' own
        // return value cannot answer: `deliverMessage()` reports on the
        // *email*, and says nothing about the copy it files in the recipient's
        // webtrees inbox.
        $filed = $this->messages->sendInternalMessage($recipient);

        $emailed = $this->messages->deliverMessage(
            $sender,
            $recipient,
            $subject,
            $body,
            // The "where did this come from" link in webtrees' own template.
            // The portal's address, because that is where the conversation is.
            $this->module->getPreference(PortalApiModule::SETTING_PORTAL_URL, ''),
            $ip
        );

        Log::addAuthenticationLog('Portal message from ' . $sender->userName() . ' to ' . $recipient->userName());

        if (!$emailed && !$filed) {
            // Phase 9 refused here on `!$emailed` alone, and called it "we are
            // not sure". Phase 10 changed the fact that answer rested on: a
            // filed copy is no longer a copy nobody reads. It is in the
            // recipient's inbox, in the portal, on the screen they are already
            // using — that is delivery, and a site whose mail server is down
            // should not be telling a family their messages failed.
            //
            // Both failing is still a failure, and is still reported as one.
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

    private function refuseIfDisabled(): void
    {
        if (!$this->enabled()) {
            throw new ApiException(
                'not_allowed',
                StatusCodeInterface::STATUS_FORBIDDEN,
                I18N::translate('Members cannot send messages in this family tree.')
            );
        }
    }

    /**
     * Give the recipient a contact method, if nobody ever did.
     *
     * The same shape of trap as the language above, and worse in its effect.
     * `deliverMessage()` stores an internal copy only when the recipient's
     * `contactmethod` is one it recognises, and sends an email only when it
     * is one of the emailing ones. A preference that was **never set** — the
     * empty string — is in neither list, so the message is stored nowhere and
     * emailed to nobody, and `deliverMessage()` still returns `true`. The
     * sender is told it was delivered. Nothing was.
     *
     * Note that this is not the "no contact" setting: `none` is in the
     * internal list, so a member who chose to be left alone still gets their
     * copy in webtrees. Only *never chose* is broken, which is why it goes
     * unnoticed — it cannot happen to an account made through webtrees'
     * registration or through this module's invitations, both of which set
     * the value. It happens to accounts made by hand, and to old ones.
     *
     * So the missing value is filled in with what webtrees' own registration
     * uses. As with the language: a missing default restored, never a choice
     * overridden — the empty string is the absence of a choice, not one.
     */
    private function ensureRecipientCanBeReached(UserInterface $recipient): void
    {
        if ($recipient->getPreference(UserInterface::PREF_CONTACT_METHOD, '') === '') {
            $recipient->setPreference(
                UserInterface::PREF_CONTACT_METHOD,
                MessageService::CONTACT_METHOD_INTERNAL_AND_EMAIL
            );
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
