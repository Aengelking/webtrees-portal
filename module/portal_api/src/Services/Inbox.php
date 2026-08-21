<?php

declare(strict_types=1);

namespace Engelking\Webtrees\PortalApi\Services;

use Engelking\Webtrees\PortalApi\Http\ApiException;
use Fisharebest\Webtrees\Contracts\UserInterface;
use Fisharebest\Webtrees\DB;
use Fisharebest\Webtrees\I18N;
use Fisharebest\Webtrees\Services\UserService;
use Fisharebest\Webtrees\User;

use function count;
use function implode;
use function gmdate;
use function preg_split;
use function stripos;
use function strtotime;
use function time;
use function trim;

/**
 * The member's own messages, read out of webtrees' `message` table.
 *
 * Everything addressed to them is here, not only what the portal sent:
 * webtrees' own contact forms and an administrator's broadcast deliver to the
 * same table, and a member who reads their messages in the portal should not
 * have to know which route a message took to get there.
 *
 * Two properties of that table shape this class, and neither is obvious.
 */
class Inbox
{
    public const string READ_TABLE = 'portal_message_read';

    /**
     * The rule webtrees' own email template draws around the message.
     *
     * Sixty hyphens, on their own line, above and below. Matched as a run of
     * hyphens rather than as exactly sixty, because the number is a detail of
     * a template rather than a promise.
     */
    private const string RULE = '/^-{10,}$/m';

    private const int PAGE_SIZE = 50;

    public function __construct(private readonly UserService $user_service)
    {
    }

    /**
     * Everything addressed to this member, newest first.
     *
     * @return array<int,array<string,mixed>>
     */
    public function messages(UserInterface $user): array
    {
        $read = DB::table(self::READ_TABLE)
            ->where('wt_user_id', '=', $user->id())
            ->pluck('read_at', 'message_id')
            ->all();

        $messages = [];

        $rows = DB::table('message')
            ->where('user_id', '=', $user->id())
            ->orderBy('message_id', 'desc')
            ->limit(self::PAGE_SIZE)
            ->get();

        foreach ($rows as $row) {
            $id      = (int) $row->message_id;
            $sender  = trim((string) $row->sender);
            $account = $this->senderAccount($sender);

            $messages[] = [
                'id'        => $id,
                'from'      => $account?->realName() ?? $sender,
                'subject'   => (string) $row->subject,
                'body'      => $this->messageOnly((string) $row->body),
                'sent_at'   => gmdate('c', (int) strtotime((string) $row->created)),
                'read'      => isset($read[$id]),
                'can_reply' => $this->answerable($user, $account),
            ];
        }

        return $messages;
    }

    public function unreadCount(UserInterface $user): int
    {
        return DB::table('message')
            ->where('user_id', '=', $user->id())
            ->whereNotIn('message_id', DB::table(self::READ_TABLE)
                ->where('wt_user_id', '=', $user->id())
                ->select('message_id'))
            ->count();
    }

    /** Idempotent: marking a message read twice is not an error. */
    public function markRead(UserInterface $user, int $id): void
    {
        $this->assertOwn($user, $id);

        DB::table(self::READ_TABLE)->updateOrInsert(
            ['wt_user_id' => $user->id(), 'message_id' => $id],
            ['read_at' => time()]
        );
    }

    public function markUnread(UserInterface $user, int $id): void
    {
        $this->assertOwn($user, $id);

        DB::table(self::READ_TABLE)
            ->where('wt_user_id', '=', $user->id())
            ->where('message_id', '=', $id)
            ->delete();
    }

    /**
     * Delete one of the member's own messages.
     *
     * The read state goes with it through the foreign key, so there is
     * nothing to tidy up here.
     */
    public function delete(UserInterface $user, int $id): void
    {
        $this->assertOwn($user, $id);

        DB::table('message')
            ->where('message_id', '=', $id)
            ->where('user_id', '=', $user->id())
            ->delete();
    }

    /**
     * Somebody else's message, and one that does not exist, are both "not
     * found". A member has no business learning which.
     */
    private function assertOwn(UserInterface $user, int $id): void
    {
        $exists = DB::table('message')
            ->where('message_id', '=', $id)
            ->where('user_id', '=', $user->id())
            ->exists();

        if (!$exists) {
            throw ApiException::notFound();
        }
    }

    /**
     * The message, without the email webtrees wrapped around it.
     *
     * `message.body` is not what the sender typed. It is the rendered
     * `emails/message-user-text` view: a greeting, a line naming the sender,
     * a rule, the message, another rule, and a note about the URL the sender
     * was looking at. Showing that in an inbox would be showing somebody
     * their own email envelope.
     *
     * So the text between the two rules is taken, and everything else
     * dropped. The greeting and the sender line are not lost information —
     * the inbox shows the sender in its own column, and better.
     *
     * **The fallback matters more than the rule.** A body with no rules at
     * all is returned whole rather than emptied: a message stored by some
     * other version of webtrees, or by a module with its own template, must
     * still be readable. Losing the wrapper is a nicety; losing the message
     * would be a bug.
     */
    public function messageOnly(string $body): string
    {
        $parts = preg_split(self::RULE, $body);

        if ($parts === false || count($parts) < 3) {
            return trim($body);
        }

        // Between the first rule and the second. A message that itself
        // contains a row of hyphens would split into more parts; taking
        // everything between the first and the last rule keeps it intact.
        $middle = [];

        for ($i = 1, $last = count($parts) - 1; $i < $last; $i++) {
            $middle[] = $parts[$i];
        }

        $message = trim(implode("\n", $middle));

        return $message === '' ? trim($body) : $message;
    }

    /**
     * Who a reply to this message would go to, and under what subject.
     *
     * `null` means "this one cannot be answered from the portal" — see
     * `answerable()`. Somebody else's message id is a `404` here as
     * everywhere else, and is not the same answer as an unanswerable one:
     * a member is allowed to know that their own message has nobody to
     * reply to, and is not allowed to know anything about anybody else's.
     *
     * @return array{user: User, subject: string}|null
     */
    public function replyTarget(UserInterface $user, int $id): ?array
    {
        $this->assertOwn($user, $id);

        $row = DB::table('message')
            ->where('message_id', '=', $id)
            ->where('user_id', '=', $user->id())
            ->first();

        if ($row === null) {
            throw ApiException::notFound();
        }

        $account = $this->senderAccount(trim((string) $row->sender));

        if (!$this->answerable($user, $account)) {
            return null;
        }

        return ['user' => $account, 'subject' => $this->replySubject((string) $row->subject)];
    }

    /**
     * `RE: ` in front, unless it is there already.
     *
     * Deliberately webtrees' own string and webtrees' own test, from
     * `modules/user-messages/user-messages.phtml`, so that a member who reads
     * one message in the portal and the next in webtrees does not get two
     * different conventions. The translation is webtrees' too — German
     * renders it "Re: ".
     */
    private function replySubject(string $subject): string
    {
        $prefix = I18N::translate('RE: ');

        // Case-insensitively, and against the untranslated string as well as
        // the translated one. Core compares exactly, which stacks a second
        // prefix onto a thread whose earlier reply was written in another
        // language — German renders this "Re: " and English "RE: ", so
        // "RE: Re: Familientreffen" is one language switch away.
        foreach ([$prefix, 'RE: '] as $candidate) {
            if (stripos($subject, $candidate) === 0) {
                return $subject;
            }
        }

        return $prefix . $subject;
    }

    /**
     * Can this message be answered at all?
     *
     * Only when the address on it belongs to an account that still exists —
     * the same condition webtrees' own message list uses to decide whether to
     * offer a Reply button, and for the same reason: without an account there
     * is nothing for `deliverMessage()` to deliver to. The portal will not
     * fall back to sending an email straight to the stored address, because
     * that address is a *reply* address rather than a consent to be contacted
     * by the portal, and the person behind it may have no account here at all.
     *
     * A message from oneself — an administrator's broadcast reaches the
     * administrator too — is not answerable either. Writing to yourself is
     * refused everywhere else in the module and there is no reason for this
     * corner to be the exception.
     */
    private function answerable(UserInterface $user, ?User $account): bool
    {
        return $account instanceof User && $account->id() !== $user->id();
    }

    /**
     * The account behind the address on a message, where there is one.
     *
     * `message.sender` is an email address, not a link to an account —
     * webtrees stores `Auth::user()->email()` at the moment of sending. So
     * the account has to be looked up, and the lookup can fail: the sender may
     * have changed their address since, or the account may be gone, or the
     * message may have come from a webtrees contact form filled in by a
     * visitor who has no account at all.
     *
     * When it fails the caller shows the address instead. That discloses
     * nothing new — it was already in the recipient's email as the reply
     * address, which is the whole reason it is stored.
     */
    private function senderAccount(string $sender): ?User
    {
        if ($sender === '') {
            return null;
        }

        $user = $this->user_service->findByEmail($sender);

        return $user instanceof User ? $user : null;
    }
}
