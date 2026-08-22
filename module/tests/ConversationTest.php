<?php

declare(strict_types=1);

namespace Engelking\Webtrees\PortalApi\Tests;

use Engelking\Webtrees\PortalApi\Http\RequestHandlers\ConversationCreate;
use Engelking\Webtrees\PortalApi\Http\RequestHandlers\ConversationDelete;
use Engelking\Webtrees\PortalApi\Http\RequestHandlers\ConversationList;
use Engelking\Webtrees\PortalApi\Http\RequestHandlers\ConversationMessageCreate;
use Engelking\Webtrees\PortalApi\Http\RequestHandlers\ConversationMessageDelete;
use Engelking\Webtrees\PortalApi\Http\RequestHandlers\ConversationRead;
use Engelking\Webtrees\PortalApi\Http\RequestHandlers\MeRead;
use Engelking\Webtrees\PortalApi\PortalApiModule;
use Fig\Http\Message\RequestMethodInterface;
use Fig\Http\Message\StatusCodeInterface;
use Fisharebest\Webtrees\Contracts\UserInterface;
use Fisharebest\Webtrees\DB;
use Fisharebest\Webtrees\User;
use PHPUnit\Framework\Attributes\CoversNothing;
use Psr\Http\Message\ResponseInterface;

/**
 * Phase 12: conversations.
 *
 * webtrees' `message` table keeps one row per message, owned by whoever
 * received it, so the half a member wrote themselves was never written down.
 * That is why there is a second store, and what it must not get wrong is
 * *who may see which half of it*.
 *
 * So the assertions here are mostly about refusal: somebody else's
 * conversation is a 404 rather than a 403; a member who is neither listed nor
 * connected cannot be written to first; deleting takes a message off one
 * screen and leaves it on the other. The pleasant path — two people talking —
 * is one test, because it is the part that would be noticed immediately.
 */
#[CoversNothing]
class ConversationTest extends PortalTestCase
{
    private User $anna;
    private User $dieter;
    private User $unlisted;

    private int $dieter_member_id;
    private int $unlisted_member_id;

    protected function setUp(): void
    {
        parent::setUp();

        $this->anna     = $this->createUser('anna', 'Anna Beispiel', 'correct-horse', UserInterface::ROLE_MEMBER, 'X1');
        $this->dieter   = $this->createUser('dieter', 'Dieter Beispiel', 'anderes-pferd', UserInterface::ROLE_MEMBER, 'X4');
        $this->unlisted = $this->createUser('erna', 'Erna Beispiel', 'drittes-pferd', UserInterface::ROLE_MEMBER, 'X5');

        $this->createProfile($this->anna, true);
        $this->dieter_member_id   = $this->createProfile($this->dieter, true);
        $this->unlisted_member_id = $this->createProfile($this->unlisted, false);

        $this->login($this->anna);
    }

    // -----------------------------------------------------------------
    // Two people talking
    // -----------------------------------------------------------------

    public function testAConversationKeepsBothHalvesOfIt(): void
    {
        $id = $this->open($this->dieter_member_id);

        $this->say($id, 'Kommst du zum Familientreffen?');

        $this->login($this->dieter);
        $this->say($id, 'Ja, gerne!');

        $transcript = $this->json($this->read($id));

        self::assertCount(2, $transcript['messages']);
        self::assertSame('Kommst du zum Familientreffen?', $transcript['messages'][0]['body']);
        self::assertTrue($transcript['messages'][1]['mine'], 'Dieter wrote the second one');
        self::assertFalse($transcript['messages'][0]['mine'], 'and not the first');

        // The half webtrees never stored: Anna can read what she wrote.
        $this->login($this->anna);
        $hers = $this->json($this->read($id));

        self::assertCount(2, $hers['messages']);
        self::assertTrue($hers['messages'][0]['mine']);
    }

    /** One pair, one conversation — asking twice does not make a second. */
    public function testOpeningTwiceFindsTheSameConversation(): void
    {
        self::assertSame($this->open($this->dieter_member_id), $this->open($this->dieter_member_id));
        self::assertSame(1, DB::table('portal_conversation')->count());
    }

    public function testReadingMarksTheOtherSidesMessagesRead(): void
    {
        $id = $this->open($this->dieter_member_id);
        $this->say($id, 'Hallo Dieter');

        $this->login($this->dieter);

        self::assertSame(1, $this->json($this->api(MeRead::class))['unread_conversations']);

        $this->read($id);

        self::assertSame(0, $this->json($this->api(MeRead::class))['unread_conversations']);

        // And the sender is told it arrived, which is the one thing a read
        // receipt is actually asked for.
        $this->login($this->anna);
        self::assertTrue($this->json($this->read($id))['messages'][0]['read']);
    }

    // -----------------------------------------------------------------
    // Who may open one
    // -----------------------------------------------------------------

    /**
     * The directory rule, unchanged from `POST /members/{id}/message`: opening
     * a conversation is *finding* somebody, and a member who stayed out of the
     * directory is reported as missing rather than as refused — the same
     * answer as a member id that never existed.
     */
    public function testAMemberWhoIsNeitherListedNorConnectedCannotBeWrittenTo(): void
    {
        $response = $this->api(
            ConversationCreate::class,
            RequestMethodInterface::METHOD_POST,
            body: ['member_id' => $this->unlisted_member_id],
            headers: $this->csrfHeader(),
        );

        self::assertSame(StatusCodeInterface::STATUS_NOT_FOUND, $response->getStatusCode());
        self::assertSame(0, DB::table('portal_conversation')->count());
    }

    /** A connection is consent given to one person, and it lifts that rule. */
    public function testAConnectedMemberCanBeWrittenToEvenWhenUnlisted(): void
    {
        DB::table('portal_connection')->insert([
            'requested_by'   => $this->anna->id(),
            'requested_of'   => $this->unlisted->id(),
            'status'         => 'accepted',
            'source'         => 'reference',
            'created_at'     => time(),
            'decided_at'     => time(),
        ]);

        $response = $this->api(
            ConversationCreate::class,
            RequestMethodInterface::METHOD_POST,
            body: ['member_id' => $this->unlisted_member_id],
            headers: $this->csrfHeader(),
        );

        self::assertSame(StatusCodeInterface::STATUS_OK, $response->getStatusCode());
    }

    /**
     * And once it exists, it stays usable. The transcript on the screen is
     * proof the two know each other — the same argument §2.28 makes about
     * replies, which is why a reply lifts the directory rule too.
     */
    public function testAConversationSurvivesTheOtherLeavingTheDirectory(): void
    {
        $id = $this->open($this->dieter_member_id);

        DB::table('portal_member_profile')
            ->where('wt_user_id', '=', $this->dieter->id())
            ->update(['visible_in_directory' => 0]);

        $response = $this->api(
            ConversationMessageCreate::class,
            RequestMethodInterface::METHOD_POST,
            attributes: ['id' => $id],
            body: ['body' => 'Bist du noch da?'],
            headers: $this->csrfHeader(),
        );

        self::assertSame(StatusCodeInterface::STATUS_CREATED, $response->getStatusCode());

        // Their name is still shown: a member talking to a blank is worse than
        // one talking to somebody who has left a list.
        self::assertSame('Dieter Beispiel', $this->json($this->read($id))['conversation']['name']);
    }

    public function testAMemberCannotOpenAConversationWithThemselves(): void
    {
        $mine = DB::table('portal_member_profile')
            ->where('wt_user_id', '=', $this->anna->id())
            ->value('id');

        $response = $this->api(
            ConversationCreate::class,
            RequestMethodInterface::METHOD_POST,
            body: ['member_id' => (int) $mine],
            headers: $this->csrfHeader(),
        );

        self::assertSame(StatusCodeInterface::STATUS_BAD_REQUEST, $response->getStatusCode());
    }

    // -----------------------------------------------------------------
    // Somebody else's conversation
    // -----------------------------------------------------------------

    /**
     * Not 403. A refusal that distinguishes "not yours" from "not there" is a
     * way to count how many conversations the site holds.
     */
    public function testSomebodyElsesConversationIsReportedAsMissing(): void
    {
        $id = $this->open($this->dieter_member_id);

        $this->login($this->unlisted);

        self::assertSame(StatusCodeInterface::STATUS_NOT_FOUND, $this->read($id)->getStatusCode());

        $wrote = $this->api(
            ConversationMessageCreate::class,
            RequestMethodInterface::METHOD_POST,
            attributes: ['id' => $id],
            body: ['body' => 'Ich lese mit'],
            headers: $this->csrfHeader(),
        );

        self::assertSame(StatusCodeInterface::STATUS_NOT_FOUND, $wrote->getStatusCode());
        self::assertSame(StatusCodeInterface::STATUS_NOT_FOUND, $this->api(
            ConversationDelete::class,
            RequestMethodInterface::METHOD_DELETE,
            attributes: ['id' => $id],
            headers: $this->csrfHeader(),
        )->getStatusCode());
    }

    public function testAConversationThatNeverExistedGivesTheSameAnswer(): void
    {
        self::assertSame(StatusCodeInterface::STATUS_NOT_FOUND, $this->read(9999)->getStatusCode());
    }

    public function testTheListShowsOnlyMyOwnConversations(): void
    {
        $id = $this->open($this->dieter_member_id);
        $this->say($id, 'Hallo');

        $this->login($this->unlisted);

        self::assertSame([], $this->json($this->api(ConversationList::class))['conversations']);
    }

    // -----------------------------------------------------------------
    // Deleting is for oneself
    // -----------------------------------------------------------------

    public function testDeletingAMessageLeavesTheOtherSidesCopyAlone(): void
    {
        $id = $this->open($this->dieter_member_id);
        $this->say($id, 'Das war unüberlegt');

        $message_id = (int) DB::table('portal_message')->value('id');

        $this->api(
            ConversationMessageDelete::class,
            RequestMethodInterface::METHOD_DELETE,
            attributes: ['id' => $id, 'message' => $message_id],
            headers: $this->csrfHeader(),
        );

        self::assertCount(0, $this->json($this->read($id))['messages'], 'gone for the sender');

        $this->login($this->dieter);

        self::assertCount(1, $this->json($this->read($id))['messages'], 'still there for the reader');
    }

    /** What neither side can see is not kept. */
    public function testAMessageBothSidesDeletedIsRemoved(): void
    {
        $id = $this->open($this->dieter_member_id);
        $this->say($id, 'Weg damit');

        $message_id = (int) DB::table('portal_message')->value('id');

        $this->api(
            ConversationMessageDelete::class,
            RequestMethodInterface::METHOD_DELETE,
            attributes: ['id' => $id, 'message' => $message_id],
            headers: $this->csrfHeader(),
        );

        $this->login($this->dieter);

        $this->api(
            ConversationMessageDelete::class,
            RequestMethodInterface::METHOD_DELETE,
            attributes: ['id' => $id, 'message' => $message_id],
            headers: $this->csrfHeader(),
        );

        self::assertSame(0, DB::table('portal_message')->count());
    }

    /**
     * Clearing a conversation empties this member's copy. The conversation
     * itself stays, because the other side still has it — and a new message
     * brings it back, which is the only honest meaning of "delete for me".
     */
    public function testClearingAConversationDoesNotEndItForTheOtherSide(): void
    {
        $id = $this->open($this->dieter_member_id);
        $this->say($id, 'Erstes');
        $this->say($id, 'Zweites');

        $this->api(
            ConversationDelete::class,
            RequestMethodInterface::METHOD_DELETE,
            attributes: ['id' => $id],
            headers: $this->csrfHeader(),
        );

        self::assertSame([], $this->json($this->api(ConversationList::class))['conversations']);

        $this->login($this->dieter);

        self::assertCount(2, $this->json($this->read($id))['messages']);

        $this->say($id, 'Bist du noch da?');

        $this->login($this->anna);

        $back = $this->json($this->api(ConversationList::class))['conversations'];

        self::assertCount(1, $back, 'a new message brings it back');
        self::assertCount(1, $this->json($this->read($id))['messages'], 'without the cleared ones');
    }

    // -----------------------------------------------------------------
    // The limits that apply to any message
    // -----------------------------------------------------------------

    public function testTheDailyLimitCountsConversationMessages(): void
    {
        $this->module()->setPreference(PortalApiModule::SETTING_MESSAGE_LIMIT, '1');

        $id = $this->open($this->dieter_member_id);
        $this->say($id, 'Eine');

        $response = $this->api(
            ConversationMessageCreate::class,
            RequestMethodInterface::METHOD_POST,
            attributes: ['id' => $id],
            body: ['body' => 'Und noch eine'],
            headers: $this->csrfHeader(),
        );

        self::assertSame(StatusCodeInterface::STATUS_CONFLICT, $response->getStatusCode());
    }

    public function testSwitchingMessagesOffSwitchesConversationsOff(): void
    {
        $id = $this->open($this->dieter_member_id);

        $this->module()->setPreference(PortalApiModule::SETTING_MEMBER_MESSAGES, '0');

        $response = $this->api(
            ConversationMessageCreate::class,
            RequestMethodInterface::METHOD_POST,
            attributes: ['id' => $id],
            body: ['body' => 'Hallo?'],
            headers: $this->csrfHeader(),
        );

        self::assertSame(StatusCodeInterface::STATUS_FORBIDDEN, $response->getStatusCode());
    }

    public function testAnEmptyMessageIsRefused(): void
    {
        $id = $this->open($this->dieter_member_id);

        $response = $this->api(
            ConversationMessageCreate::class,
            RequestMethodInterface::METHOD_POST,
            attributes: ['id' => $id],
            body: ['body' => '   '],
            headers: $this->csrfHeader(),
        );

        self::assertSame(StatusCodeInterface::STATUS_BAD_REQUEST, $response->getStatusCode());
    }

    // -----------------------------------------------------------------
    // What the other side is told, and what it is not told
    // -----------------------------------------------------------------

    /**
     * The announcement used to be webtrees' own delivery, which files a copy
     * of the message in the recipient's inbox. That copy then showed up under
     * *Sonstige Nachrichten* as well, so the same sentence arrived twice: once
     * as a conversation and once as post. The inbox is for what has nowhere
     * else to go.
     */
    public function testAConversationMessageIsNotAlsoFiledAsPost(): void
    {
        $before = DB::table('message')->count();

        $id = $this->open($this->dieter_member_id);
        $this->say($id, 'Kommst du zum Familientreffen?');

        self::assertSame($before, DB::table('message')->count());
    }

    /**
     * And the e-mail that does go out says nothing. An inbox is read by
     * whoever holds the phone and stored by whoever runs the mail server;
     * §2.36 refused to put a name on a lock screen, and this is the same
     * refusal one channel over.
     */
    public function testTheAnnouncementNamesNeitherTheWriterNorTheMessage(): void
    {
        $this->module()->setPreference(PortalApiModule::SETTING_PORTAL_URL, 'https://portal.example.test');

        foreach (['text', 'html'] as $format) {
            $body = view('_portal_api_::emails/conversation-' . $format, [
                'recipient' => $this->dieter,
                'url'       => 'https://portal.example.test',
            ]);

            self::assertStringNotContainsString('Anna', $body, $format . ': the writer is not named');
            self::assertStringNotContainsString('Familientreffen', $body, $format . ': nor what they wrote');

            // What it does carry: the recipient, and the way back in.
            self::assertStringContainsString('Dieter Beispiel', $body);
            self::assertStringContainsString('https://portal.example.test', $body);
        }
    }

    /**
     * A portal that does not know its own address still says that something
     * is waiting — it just cannot say where. An e-mail with an empty link in
     * it would be worse than one without.
     */
    public function testTheAnnouncementSurvivesAPortalWithNoAddress(): void
    {
        $body = view('_portal_api_::emails/conversation-text', [
            'recipient' => $this->dieter,
            'url'       => '',
        ]);

        self::assertStringContainsString('Dieter Beispiel', $body);
        self::assertStringNotContainsString('href', $body);
    }

    // -----------------------------------------------------------------

    private function open(int $member_id): int
    {
        $response = $this->api(
            ConversationCreate::class,
            RequestMethodInterface::METHOD_POST,
            body: ['member_id' => $member_id],
            headers: $this->csrfHeader(),
        );

        return (int) $this->json($response)['conversation']['id'];
    }

    private function say(int $id, string $body): void
    {
        $this->api(
            ConversationMessageCreate::class,
            RequestMethodInterface::METHOD_POST,
            attributes: ['id' => $id],
            body: ['body' => $body],
            headers: $this->csrfHeader(),
        );
    }

    private function read(int $id): ResponseInterface
    {
        return $this->api(ConversationRead::class, attributes: ['id' => $id]);
    }
}
