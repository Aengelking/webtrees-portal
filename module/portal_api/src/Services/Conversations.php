<?php

declare(strict_types=1);

namespace Engelking\Webtrees\PortalApi\Services;

use Engelking\Webtrees\PortalApi\Http\ApiException;
use Fisharebest\Webtrees\Contracts\UserInterface;
use Fisharebest\Webtrees\DB;
use Fisharebest\Webtrees\I18N;
use Fisharebest\Webtrees\Services\UserService;
use Fisharebest\Webtrees\User;

use function array_map;
use function count;
use function gmdate;
use function max;
use function mb_strlen;
use function min;
use function time;
use function trim;
use function usort;

/**
 * Conversations: the transcript webtrees' `message` table cannot hold.
 *
 * See `Schema/Migration6.php` for why there is a second store at all. This is
 * what reads and writes it, and the rules it applies are the ones Phases 9 to
 * 11 already settled — deliberately not new ones:
 *
 * **Who may be written to first** is `MemberMessages::send()`'s rule exactly:
 * listed in the directory, or connected to me. Starting a conversation is
 * *finding* somebody, and finding is the thing that rule guards.
 *
 * **Who may be written to afterwards** is whoever is already in the
 * conversation, whatever has changed since. That is §2.28's argument about
 * replies, and it holds harder here: the transcript on the screen is proof
 * that these two people know each other. A member who leaves the directory
 * stops being findable; they do not stop being someone you were in the middle
 * of a conversation with.
 *
 * **Somebody else's conversation and one that never existed give the same
 * 404**, as everywhere else in this module.
 */
class Conversations
{
    public const int MAX_BODY_LENGTH = 4000;

    /** Messages per request. A family conversation is not a chat log. */
    private const int PAGE_SIZE = 50;

    public function __construct(
        private readonly MemberService $members,
        private readonly Connections $connections,
        private readonly MemberMessages $messages,
        private readonly UserService $user_service,
        private readonly PushSubscriptions $push,
    ) {
    }

    /**
     * Start a conversation with a member, or find the one already there.
     *
     * @return array<string,mixed>
     */
    public function with(UserInterface $me, int $member_id): array
    {
        $this->messages->refuseIfDisabled();

        $member = $this->members->readableMember($member_id, $this->connections->disclosableUserIds($me));

        // Not findable is reported as not found — the same answer as a member
        // id that never existed, so this cannot be used to discover who holds
        // an account.
        if ($member === null) {
            throw ApiException::notFound();
        }

        if ($member->user->id() === $me->id()) {
            throw ApiException::badRequest(I18N::translate('You cannot send a message to yourself.'));
        }

        return $this->summarise($me, $this->findOrCreate($me->id(), $member->user->id()));
    }

    /**
     * Every conversation with something in it, newest first.
     *
     * A conversation whose messages this member has all deleted is not shown.
     * It is not deleted either — the other side may still have it, and a new
     * message brings it back, which is what "delete for me" has to mean.
     *
     * @return array<int,array<string,mixed>>
     */
    public function overview(UserInterface $me): array
    {
        $conversations = $this->mine($me);

        if ($conversations === []) {
            return [];
        }

        $summaries = [];

        foreach ($conversations as $row) {
            $summary = $this->summarise($me, $row);

            if ($summary['last_message'] !== null) {
                $summaries[] = $summary;
            }
        }

        usort(
            $summaries,
            static fn (array $a, array $b): int => $b['last_message']['sent_at'] <=> $a['last_message']['sent_at'],
        );

        return $summaries;
    }

    /**
     * One conversation, read.
     *
     * Reading marks the other side's messages read, for the reason §2.27 gives
     * about opening a message: that is what opening means, and the alternative
     * is a second deliberate act whose only purpose is to make a badge go away.
     *
     * @return array<string,mixed>
     */
    public function transcript(UserInterface $me, int $id, int|null $before = null): array
    {
        $conversation = $this->mineOrNotFound($me, $id);
        $mine_is_one  = (int) $conversation->user_one === $me->id();

        $query = DB::table('portal_message')
            ->where('conversation_id', '=', (int) $conversation->id)
            ->whereNull($mine_is_one ? 'hidden_one_at' : 'hidden_two_at');

        if ($before !== null) {
            $query->where('id', '<', $before);
        }

        $rows = $query->orderBy('id', 'desc')->limit(self::PAGE_SIZE)->get()->reverse()->values();

        $this->markRead($me, $conversation);

        return [
            'conversation' => $this->summarise($me, $conversation),
            'messages'     => $rows->map(fn (object $row): array => $this->message($row, $me))->all(),
            // The oldest id in this page, for asking what came before it.
            'before'       => count($rows) < self::PAGE_SIZE ? null : (int) $rows->first()->id,
        ];
    }

    /**
     * Add a message to a conversation.
     *
     * @return array<string,mixed>
     */
    public function post(UserInterface $me, int $id, string $body, string $ip): array
    {
        $this->messages->refuseIfDisabled();

        $conversation = $this->mineOrNotFound($me, $id);
        $body         = trim($body);

        if ($body === '') {
            throw ApiException::badRequest(I18N::translate('Please write a message.'));
        }

        if (mb_strlen($body) > self::MAX_BODY_LENGTH) {
            throw ApiException::badRequest(I18N::translate('That message is too long.'));
        }

        // One quota for both ways of writing. A message is a message, and the
        // limit is about volume — §2.28 says the same about replies.
        $this->messages->refuseIfTooMany($me);

        $other = $this->partnerId($conversation, $me);

        // Asked *before* inserting: whether they have anything waiting decides
        // whether they are told about this one. See `tell()`.
        $waiting = $this->unreadIn($conversation, $other) > 0;

        $message_id = DB::table('portal_message')->insertGetId([
            'conversation_id' => (int) $conversation->id,
            'sender_id'       => $me->id(),
            'body'            => $body,
            'created_at'      => time(),
        ]);

        if (!$waiting) {
            $this->tell($me, $other, $body, $ip);
        }

        // The knock goes out on *every* message, unlike the e-mail above.
        // That is the difference between the two: an e-mail per line is a
        // mailbox nobody can use, while a notification per message is what a
        // conversation is. Nothing about the message travels with it — see
        // `PushSubscriptions` — so the lock screen says that something
        // arrived, and never what or from whom.
        $this->push->knock($other);

        $row = DB::table('portal_message')->where('id', '=', $message_id)->first();

        return $this->message($row, $me);
    }

    /**
     * Delete one message — for this member, and only for them.
     *
     * A row nobody can see any more is removed outright. The portal does not
     * keep what neither side can read.
     */
    public function hideMessage(UserInterface $me, int $id, int $message_id): void
    {
        $conversation = $this->mineOrNotFound($me, $id);
        $mine_is_one  = (int) $conversation->user_one === $me->id();
        $column       = $mine_is_one ? 'hidden_one_at' : 'hidden_two_at';

        $affected = DB::table('portal_message')
            ->where('id', '=', $message_id)
            ->where('conversation_id', '=', (int) $conversation->id)
            ->whereNull($column)
            ->update([$column => time()]);

        if ($affected === 0) {
            throw ApiException::notFound();
        }

        $this->forgetInvisible((int) $conversation->id);
    }

    /** Delete a whole conversation — again, only for this member. */
    public function hide(UserInterface $me, int $id): void
    {
        $conversation = $this->mineOrNotFound($me, $id);
        $mine_is_one  = (int) $conversation->user_one === $me->id();
        $column       = $mine_is_one ? 'hidden_one_at' : 'hidden_two_at';

        DB::table('portal_message')
            ->where('conversation_id', '=', (int) $conversation->id)
            ->whereNull($column)
            ->update([$column => time()]);

        $this->forgetInvisible((int) $conversation->id);
    }

    /** Unread messages across every conversation, for the badge. */
    public function unreadCount(UserInterface $me): int
    {
        $total = 0;

        foreach ($this->mine($me) as $row) {
            $total += $this->unreadIn($row, $me->id());
        }

        return $total;
    }

    /**
     * Tell the other side that a conversation has started — once.
     *
     * A chat that sends an e-mail per line is a chat nobody stays in. So the
     * message goes out through the path Phase 9 already built and tested —
     * `MemberMessages`, which respects the recipient's contact preference,
     * files webtrees' own copy and sends the e-mail — but only when they have
     * nothing waiting from this member already. Once something is unread, they
     * have been told; telling them again says nothing new.
     *
     * A failure here is not the member's problem: the message is in the
     * conversation, which is where they will read it. Notification is a
     * courtesy, and a courtesy that fails must not fail the message.
     */
    private function tell(UserInterface $me, int $other_id, string $body, string $ip): void
    {
        $recipient = $this->user_service->find($other_id);

        if (!$recipient instanceof User) {
            return;
        }

        try {
            $this->messages->notify(
                $me,
                $recipient,
                I18N::translate('A message in the family portal'),
                $body,
                $ip,
            );
        } catch (\Throwable $exception) {
            error_log('portal_api: could not announce a conversation message: ' . $exception->getMessage());
        }
    }

    /** @return array<int,object> */
    private function mine(UserInterface $me): array
    {
        return DB::table('portal_conversation')
            ->where('user_one', '=', $me->id())
            ->orWhere('user_two', '=', $me->id())
            ->get()
            ->all();
    }

    private function mineOrNotFound(UserInterface $me, int $id): object
    {
        $row = DB::table('portal_conversation')->where('id', '=', $id)->first();

        if ($row === null) {
            throw ApiException::notFound();
        }

        if ((int) $row->user_one !== $me->id() && (int) $row->user_two !== $me->id()) {
            // Not "forbidden": that would confirm the conversation exists.
            throw ApiException::notFound();
        }

        return $row;
    }

    private function findOrCreate(int $a, int $b): object
    {
        $one = min($a, $b);
        $two = max($a, $b);

        $row = DB::table('portal_conversation')
            ->where('user_one', '=', $one)
            ->where('user_two', '=', $two)
            ->first();

        if ($row !== null) {
            return $row;
        }

        DB::table('portal_conversation')->insert([
            'user_one'   => $one,
            'user_two'   => $two,
            'created_at' => time(),
        ]);

        return DB::table('portal_conversation')
            ->where('user_one', '=', $one)
            ->where('user_two', '=', $two)
            ->first();
    }

    /** @return array<string,mixed> */
    private function summarise(UserInterface $me, object $conversation): array
    {
        $mine_is_one = (int) $conversation->user_one === $me->id();
        $other_id    = $this->partnerId($conversation, $me);

        // Looked up by account rather than through the directory rule: an
        // existing conversation is its own proof that these two know each
        // other, so a partner who has since left the directory keeps their
        // name here. Withholding it would leave a member talking to a blank.
        $member = $this->members->memberForUser($other_id);
        $user   = $member?->user ?? $this->user_service->find($other_id);

        $last = DB::table('portal_message')
            ->where('conversation_id', '=', (int) $conversation->id)
            ->whereNull($mine_is_one ? 'hidden_one_at' : 'hidden_two_at')
            ->orderBy('id', 'desc')
            ->first();

        return [
            'id'           => (int) $conversation->id,
            'member_id'    => $member?->id,
            'name'         => $member?->display_name ?? ($user?->realName() ?? I18N::translate('Unknown')),
            'unread'       => $this->unreadIn($conversation, $me->id()),
            'last_message' => $last === null ? null : $this->message($last, $me),
        ];
    }

    /** @return array<string,mixed> */
    private function message(object $row, UserInterface $me): array
    {
        return [
            'id'      => (int) $row->id,
            'mine'    => (int) $row->sender_id === $me->id(),
            'body'    => (string) $row->body,
            'sent_at' => gmdate('c', (int) $row->created_at),
            // Only meaningful on one's own messages: it says the other side
            // has read it. On a received message it is always true by the time
            // anybody could look.
            'read'    => $row->read_at !== null,
        ];
    }

    private function partnerId(object $conversation, UserInterface $me): int
    {
        return (int) $conversation->user_one === $me->id()
            ? (int) $conversation->user_two
            : (int) $conversation->user_one;
    }

    private function unreadIn(object $conversation, int $user_id): int
    {
        $is_one = (int) $conversation->user_one === $user_id;

        return DB::table('portal_message')
            ->where('conversation_id', '=', (int) $conversation->id)
            ->where('sender_id', '!=', $user_id)
            ->whereNull('read_at')
            ->whereNull($is_one ? 'hidden_one_at' : 'hidden_two_at')
            ->count();
    }

    private function markRead(UserInterface $me, object $conversation): void
    {
        DB::table('portal_message')
            ->where('conversation_id', '=', (int) $conversation->id)
            ->where('sender_id', '!=', $me->id())
            ->whereNull('read_at')
            ->update(['read_at' => time()]);
    }

    /** Remove the messages both sides have deleted. */
    private function forgetInvisible(int $conversation_id): void
    {
        DB::table('portal_message')
            ->where('conversation_id', '=', $conversation_id)
            ->whereNotNull('hidden_one_at')
            ->whereNotNull('hidden_two_at')
            ->delete();
    }
}
