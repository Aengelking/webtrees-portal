<?php

declare(strict_types=1);

namespace Engelking\Webtrees\PortalApi\Services;

use Engelking\Webtrees\PortalApi\Http\ApiException;
use Fisharebest\Webtrees\Contracts\UserInterface;
use Fisharebest\Webtrees\DB;
use Fisharebest\Webtrees\Services\UserService;
use Fisharebest\Webtrees\User;

use function count;
use function implode;
use function gmdate;
use function preg_split;
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
            $id = (int) $row->message_id;

            $messages[] = [
                'id'        => $id,
                'from'      => $this->senderName((string) $row->sender),
                'subject'   => (string) $row->subject,
                'body'      => $this->messageOnly((string) $row->body),
                'sent_at'   => gmdate('c', (int) strtotime((string) $row->created)),
                'read'      => isset($read[$id]),
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
     * Who sent it, as a name where that can be worked out.
     *
     * `message.sender` is an email address, not a link to an account —
     * webtrees stores `Auth::user()->email()` at the moment of sending. So
     * the name has to be looked up, and the lookup can fail: the sender may
     * have changed their address since, or the account may be gone, or the
     * message may have come from a webtrees contact form filled in by a
     * visitor who has no account at all.
     *
     * When it fails, the address is shown. That discloses nothing new — it
     * was already in the recipient's email as the reply address, which is the
     * whole reason it is stored.
     */
    private function senderName(string $sender): string
    {
        $sender = trim($sender);

        if ($sender === '') {
            return '';
        }

        $user = $this->user_service->findByEmail($sender);

        return $user instanceof User ? $user->realName() : $sender;
    }
}
