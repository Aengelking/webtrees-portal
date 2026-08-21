<?php

declare(strict_types=1);

namespace Engelking\Webtrees\PortalApi\Tests;

use Engelking\Webtrees\PortalApi\Http\RequestHandlers\InboxDelete;
use Engelking\Webtrees\PortalApi\Http\RequestHandlers\InboxList;
use Engelking\Webtrees\PortalApi\Http\RequestHandlers\InboxUpdate;
use Engelking\Webtrees\PortalApi\Http\RequestHandlers\MeRead;
use Engelking\Webtrees\PortalApi\Http\RequestHandlers\ReplyCreate;
use Engelking\Webtrees\PortalApi\PortalApiModule;
use Engelking\Webtrees\PortalApi\Services\Inbox;
use Engelking\Webtrees\PortalApi\Services\MemberService;
use Fig\Http\Message\RequestMethodInterface;
use Fig\Http\Message\StatusCodeInterface;
use Fisharebest\Webtrees\Contracts\UserInterface;
use Fisharebest\Webtrees\DB;
use Fisharebest\Webtrees\Registry;
use Fisharebest\Webtrees\User;
use PHPUnit\Framework\Attributes\CoversNothing;
use Psr\Http\Message\ResponseInterface;

/**
 * Phase 10: reading messages in the portal.
 *
 * Two properties of webtrees' `message` table are what this is really about,
 * and neither is visible from its name.
 *
 * `body` is not the message. It is a rendered email — a greeting, a line
 * naming the sender, a rule, the message, another rule, and a note about the
 * URL. An inbox that showed that would be showing somebody their own
 * envelope.
 *
 * `sender` is an email address, not an account. The name has to be looked up,
 * and the lookup can fail.
 */
#[CoversNothing]
class InboxTest extends PortalTestCase
{
    private User $anna;
    private User $dieter;

    /** Exactly what webtrees' own `emails/message-user-text` renders. */
    private const string RENDERED = <<<'BODY'
        Hallo Anna Beispiel…

        Dieter Beispiel (dieter@example.test) hat Ihnen die folgende Nachricht geschickt.

        ------------------------------------------------------------
        Kommst du zum Familientreffen?

        Viele Grüße
        Dieter
        ------------------------------------------------------------

        Diese Nachricht wurde beim Betrachten der folgenden URL gesendet:
        https://portal.example.test
        BODY;

    protected function setUp(): void
    {
        parent::setUp();

        $this->anna   = $this->createUser('anna', 'Anna Beispiel', 'correct-horse', UserInterface::ROLE_MEMBER, 'X1');
        $this->dieter = $this->createUser('dieter', 'Dieter Beispiel', 'anderes-pferd', UserInterface::ROLE_MEMBER, 'X4');

        $this->login($this->anna);
    }

    private function deliver(User $to, string $sender, string $subject, string $body): int
    {
        DB::table('message')->insert([
            'sender'     => $sender,
            'ip_address' => '203.0.113.7',
            'user_id'    => $to->id(),
            'subject'    => $subject,
            'body'       => $body,
            'created'    => '2026-08-01 10:00:00',
        ]);

        return (int) DB::lastInsertId();
    }

    private function list(): ResponseInterface
    {
        return $this->api(InboxList::class);
    }

    private function inbox(): Inbox
    {
        return Registry::container()->get(Inbox::class);
    }

    // -----------------------------------------------------------------
    // The envelope comes off
    // -----------------------------------------------------------------

    public function testTheMessageIsShownWithoutTheEmailAroundIt(): void
    {
        $this->deliver($this->anna, 'dieter@example.test', 'Familientreffen', self::RENDERED);

        $message = $this->json($this->list())['messages'][0];

        self::assertStringContainsString('Kommst du zum Familientreffen?', $message['body']);
        self::assertStringNotContainsString('Hallo Anna Beispiel', $message['body']);
        self::assertStringNotContainsString('hat Ihnen die folgende Nachricht', $message['body']);
        self::assertStringNotContainsString('https://portal.example.test', $message['body']);
        self::assertStringNotContainsString('---', $message['body']);
    }

    /**
     * The fallback matters more than the rule. Losing the wrapper is a
     * nicety; losing the message would be a bug.
     */
    public function testABodyWithNoRulesIsShownWhole(): void
    {
        $this->deliver($this->anna, 'dieter@example.test', 'Kurz', 'Nur ein Satz, ohne alles.');

        self::assertSame('Nur ein Satz, ohne alles.', $this->json($this->list())['messages'][0]['body']);
    }

    /** A message that itself contains a rule keeps both halves. */
    public function testARuleInsideTheMessageDoesNotTruncateIt(): void
    {
        $body = "Hallo\n\n----------\nOben\n----------\nUnten\n----------\n\nFuß";

        $this->deliver($this->anna, 'dieter@example.test', 'Striche', $body);

        $shown = $this->json($this->list())['messages'][0]['body'];

        self::assertStringContainsString('Oben', $shown);
        self::assertStringContainsString('Unten', $shown);
    }

    // -----------------------------------------------------------------
    // Who sent it
    // -----------------------------------------------------------------

    public function testTheSenderIsNamedWhenTheAddressIsKnown(): void
    {
        $this->deliver($this->anna, 'dieter@example.test', 'Hallo', self::RENDERED);

        self::assertSame('Dieter Beispiel', $this->json($this->list())['messages'][0]['from']);
    }

    /**
     * The address may belong to nobody — a sender who changed it since, a
     * deleted account, or a visitor filling in a webtrees contact form. The
     * address itself is shown, which discloses nothing new: it was already in
     * the recipient's email as the reply address.
     */
    public function testAnUnknownSenderIsShownAsTheAddress(): void
    {
        $this->deliver($this->anna, 'fremde@example.test', 'Hallo', 'Text');

        self::assertSame('fremde@example.test', $this->json($this->list())['messages'][0]['from']);
    }

    // -----------------------------------------------------------------
    // Whose inbox it is
    // -----------------------------------------------------------------

    public function testAMemberSeesOnlyTheirOwnMessages(): void
    {
        $this->deliver($this->anna, 'dieter@example.test', 'Für Anna', 'Text');
        $this->deliver($this->dieter, 'anna@example.test', 'Für Dieter', 'Geheim');

        $raw = $this->raw($this->list());

        self::assertStringContainsString('Für Anna', $raw);
        self::assertStringNotContainsString('Für Dieter', $raw);
        self::assertStringNotContainsString('Geheim', $raw);
    }

    public function testAMemberCannotReadSomebodyElsesMessage(): void
    {
        $id = $this->deliver($this->dieter, 'anna@example.test', 'Für Dieter', 'Geheim');

        $response = $this->api(
            InboxUpdate::class,
            RequestMethodInterface::METHOD_PATCH,
            attributes: ['id' => $id],
            body: ['read' => true],
            headers: $this->csrfHeader(),
        );

        self::assertSame(StatusCodeInterface::STATUS_NOT_FOUND, $response->getStatusCode());
    }

    public function testAMemberCannotDeleteSomebodyElsesMessage(): void
    {
        $id = $this->deliver($this->dieter, 'anna@example.test', 'Für Dieter', 'Geheim');

        $response = $this->api(
            InboxDelete::class,
            RequestMethodInterface::METHOD_DELETE,
            attributes: ['id' => $id],
            headers: $this->csrfHeader(),
        );

        self::assertSame(StatusCodeInterface::STATUS_NOT_FOUND, $response->getStatusCode());
        self::assertSame(1, DB::table('message')->where('message_id', '=', $id)->count());
    }

    /**
     * Somebody else's message and one that never existed give the same
     * answer, so this is not a way to learn that a message exists.
     */
    public function testEveryRefusalLooksTheSame(): void
    {
        $theirs = $this->deliver($this->dieter, 'anna@example.test', 'Für Dieter', 'Geheim');

        $a = $this->raw($this->api(InboxDelete::class, RequestMethodInterface::METHOD_DELETE, attributes: ['id' => $theirs], headers: $this->csrfHeader()));
        $b = $this->raw($this->api(InboxDelete::class, RequestMethodInterface::METHOD_DELETE, attributes: ['id' => 999999], headers: $this->csrfHeader()));

        self::assertSame($a, $b);
    }

    // -----------------------------------------------------------------
    // Read, unread, gone
    // -----------------------------------------------------------------

    /**
     * No row means unread, so an arriving message needs nothing written for
     * it — which is the whole reason the state is stored this way round.
     */
    public function testAMessageArrivesUnread(): void
    {
        $this->deliver($this->anna, 'dieter@example.test', 'Hallo', 'Text');

        $body = $this->json($this->list());

        self::assertFalse($body['messages'][0]['read']);
        self::assertSame(1, $body['unread']);
    }

    public function testMarkingReadAndUnreadAgain(): void
    {
        $id = $this->deliver($this->anna, 'dieter@example.test', 'Hallo', 'Text');

        $read = $this->json($this->markRead($id, true));

        self::assertTrue($read['messages'][0]['read']);
        self::assertSame(0, $read['unread']);

        $unread = $this->json($this->markRead($id, false));

        self::assertFalse($unread['messages'][0]['read']);
        self::assertSame(1, $unread['unread']);
    }

    public function testMarkingReadTwiceIsNotAnError(): void
    {
        $id = $this->deliver($this->anna, 'dieter@example.test', 'Hallo', 'Text');

        $this->markRead($id, true);

        self::assertSame(StatusCodeInterface::STATUS_OK, $this->markRead($id, true)->getStatusCode());
        self::assertSame(1, DB::table(Inbox::READ_TABLE)->count());
    }

    public function testDeletingAMessageTakesItsReadStateWithIt(): void
    {
        $id = $this->deliver($this->anna, 'dieter@example.test', 'Hallo', 'Text');

        $this->markRead($id, true);

        self::assertSame(1, DB::table(Inbox::READ_TABLE)->count());

        $response = $this->api(
            InboxDelete::class,
            RequestMethodInterface::METHOD_DELETE,
            attributes: ['id' => $id],
            headers: $this->csrfHeader(),
        );

        self::assertSame(StatusCodeInterface::STATUS_OK, $response->getStatusCode());
        self::assertSame([], $this->json($response)['messages']);
        self::assertSame(0, DB::table('message')->count());

        // Through the foreign key, not through code.
        self::assertSame(0, DB::table(Inbox::READ_TABLE)->count());
    }

    /**
     * The navigation bar shows the count on every screen, and `/me` is the
     * request every screen already makes.
     */
    public function testTheUnreadCountRidesAlongWithMe(): void
    {
        $this->deliver($this->anna, 'dieter@example.test', 'Eins', 'Text');
        $this->deliver($this->anna, 'dieter@example.test', 'Zwei', 'Text');

        self::assertSame(2, $this->json($this->api(MeRead::class))['unread_messages']);
    }

    public function testTheCountIgnoresOtherPeoplesMessages(): void
    {
        $this->deliver($this->dieter, 'anna@example.test', 'Für Dieter', 'Text');

        self::assertSame(0, $this->inbox()->unreadCount($this->anna));
        self::assertSame(1, $this->inbox()->unreadCount($this->dieter));
    }

    public function testAnEmptyInboxIsAnEmptyList(): void
    {
        $body = $this->json($this->list());

        self::assertSame([], $body['messages']);
        self::assertSame(0, $body['unread']);
    }

    // -----------------------------------------------------------------
    // Answering
    // -----------------------------------------------------------------

    /**
     * The Reply button is offered on exactly the messages that can be
     * answered, so the interface never has to refuse after the member has
     * written something.
     */
    public function testAMessageFromAnAccountIsMarkedAnswerable(): void
    {
        $this->deliver($this->anna, 'dieter@example.test', 'Hallo', 'Text');

        self::assertTrue($this->json($this->list())['messages'][0]['can_reply']);
    }

    public function testAMessageFromNobodyInParticularIsNotAnswerable(): void
    {
        // A webtrees contact form filled in by a visitor with no account, or
        // a member who has changed their address since.
        $this->deliver($this->anna, 'fremder@example.test', 'Hallo', 'Text');

        self::assertFalse($this->json($this->list())['messages'][0]['can_reply']);
    }

    /**
     * An administrator's broadcast reaches the administrator as well.
     * Writing to yourself is refused everywhere else in the module, and this
     * corner is not the exception.
     */
    public function testYourOwnMessageIsNotAnswerable(): void
    {
        $this->deliver($this->anna, 'anna@example.test', 'Rundmail', 'An alle');

        self::assertFalse($this->json($this->list())['messages'][0]['can_reply']);

        $response = $this->reply($this->json($this->list())['messages'][0]['id']);

        self::assertSame(StatusCodeInterface::STATUS_CONFLICT, $response->getStatusCode());
    }

    public function testAReplyReachesTheSenderWithWebtreesSubjectConvention(): void
    {
        $id = $this->deliver($this->anna, 'dieter@example.test', 'Familientreffen', 'Kommst du?');

        $response = $this->reply($id, 'Ja, sehr gern.');

        self::assertSame(StatusCodeInterface::STATUS_ACCEPTED, $response->getStatusCode());

        $sent = DB::table('message')->where('user_id', '=', $this->dieter->id())->first();

        self::assertNotNull($sent);

        // webtrees' own string, so a member who reads one message here and
        // the next in webtrees meets one convention. The suite runs in
        // English; the German catalogue renders the same string "Re: ".
        self::assertSame('RE: Familientreffen', $sent->subject);
        self::assertStringContainsString('Ja, sehr gern.', (string) $sent->body);
    }

    /**
     * Answering an answer does not stack the prefix — including across a
     * language switch, which is where core's exact comparison gives up. The
     * subject here is the German rendering; the reply is written in English.
     */
    public function testAnAnsweredAnswerKeepsOnePrefix(): void
    {
        $id = $this->deliver($this->anna, 'dieter@example.test', 'Re: Familientreffen', 'Text');

        $this->reply($id);

        self::assertSame(
            'Re: Familientreffen',
            DB::table('message')->where('user_id', '=', $this->dieter->id())->value('subject')
        );
    }

    /**
     * The point of the whole exception. Dieter has no directory profile, so
     * `POST /members/{id}/message` could never reach him — but he wrote
     * first, and a portal where messages arrive and cannot be answered is the
     * gap this was built to close.
     */
    public function testAReplyReachesSomebodyWhoIsNotInTheDirectory(): void
    {
        self::assertSame(
            0,
            DB::table(MemberService::TABLE)->where('wt_user_id', '=', $this->dieter->id())->count(),
            'Dieter is expected to have no directory profile for this test to mean anything.'
        );

        $id = $this->deliver($this->anna, 'dieter@example.test', 'Hallo', 'Text');

        self::assertSame(StatusCodeInterface::STATUS_ACCEPTED, $this->reply($id)->getStatusCode());
        self::assertSame(1, DB::table('message')->where('user_id', '=', $this->dieter->id())->count());
    }

    /**
     * Somebody else's message id and one that never existed give the same
     * answer here too — a reply endpoint would otherwise be a way of asking
     * whether a message exists.
     */
    public function testAMemberCannotReplyToSomebodyElsesMessage(): void
    {
        $theirs = $this->deliver($this->dieter, 'anna@example.test', 'Für Dieter', 'Geheim');

        $a = $this->reply($theirs);
        $b = $this->reply(999999);

        self::assertSame(StatusCodeInterface::STATUS_NOT_FOUND, $a->getStatusCode());
        self::assertSame($this->raw($a), $this->raw($b));
        self::assertSame(0, DB::table('message')->where('user_id', '=', $this->anna->id())->count());
    }

    /**
     * "Nobody to reply to" is not "no such message". The member is looking at
     * their own message and may be told plainly why it has no answer button.
     */
    public function testAnUnanswerableMessageSaysSoRatherThanVanishing(): void
    {
        $id = $this->deliver($this->anna, 'fremder@example.test', 'Hallo', 'Text');

        $response = $this->reply($id);

        self::assertSame(StatusCodeInterface::STATUS_CONFLICT, $response->getStatusCode());
        self::assertSame('cannot_reply', $this->json($response)['error']);
    }

    public function testAnEmptyReplyIsRefused(): void
    {
        $id = $this->deliver($this->anna, 'dieter@example.test', 'Hallo', 'Text');

        $response = $this->reply($id, '   ');

        self::assertSame(StatusCodeInterface::STATUS_BAD_REQUEST, $response->getStatusCode());
        self::assertSame(0, DB::table('message')->where('user_id', '=', $this->dieter->id())->count());
    }

    public function testSwitchingMessagesOffAlsoSwitchesOffReplies(): void
    {
        $this->module()->setPreference(PortalApiModule::SETTING_MEMBER_MESSAGES, '0');

        $id = $this->deliver($this->anna, 'dieter@example.test', 'Hallo', 'Text');

        self::assertSame(StatusCodeInterface::STATUS_FORBIDDEN, $this->reply($id)->getStatusCode());
    }

    /** A reply is a message, and the daily limit is about volume. */
    public function testTheDailyLimitCountsRepliesToo(): void
    {
        $this->module()->setPreference(PortalApiModule::SETTING_MESSAGE_LIMIT, '0');

        $id = $this->deliver($this->anna, 'dieter@example.test', 'Hallo', 'Text');

        self::assertSame(StatusCodeInterface::STATUS_FORBIDDEN, $this->reply($id)->getStatusCode());
    }

    private function reply(int $id, string $body = 'Danke für die Nachricht.'): ResponseInterface
    {
        return $this->api(
            ReplyCreate::class,
            RequestMethodInterface::METHOD_POST,
            attributes: ['id' => $id],
            body: ['body' => $body],
            headers: $this->csrfHeader(),
        );
    }

    private function markRead(int $id, bool $read): ResponseInterface
    {
        return $this->api(
            InboxUpdate::class,
            RequestMethodInterface::METHOD_PATCH,
            attributes: ['id' => $id],
            body: ['read' => $read],
            headers: $this->csrfHeader(),
        );
    }
}
