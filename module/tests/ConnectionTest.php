<?php

declare(strict_types=1);

namespace Engelking\Webtrees\PortalApi\Tests;

use Engelking\Webtrees\PortalApi\Http\RequestHandlers\ConnectionCodeCreate;
use Engelking\Webtrees\PortalApi\Http\RequestHandlers\ConnectionCodeDelete;
use Engelking\Webtrees\PortalApi\Http\RequestHandlers\ConnectionCreate;
use Engelking\Webtrees\PortalApi\Http\RequestHandlers\ConnectionDelete;
use Engelking\Webtrees\PortalApi\Http\RequestHandlers\ConnectionLinkCreate;
use Engelking\Webtrees\PortalApi\Http\RequestHandlers\ConnectionLinkDelete;
use Engelking\Webtrees\PortalApi\Http\RequestHandlers\ConnectionList;
use Engelking\Webtrees\PortalApi\Http\RequestHandlers\ConnectionUpdate;
use Engelking\Webtrees\PortalApi\Http\RequestHandlers\ContactRead;
use Engelking\Webtrees\PortalApi\Http\RequestHandlers\MemberList;
use Engelking\Webtrees\PortalApi\Http\RequestHandlers\MemberRead;
use Engelking\Webtrees\PortalApi\Http\RequestHandlers\MeRead;
use Engelking\Webtrees\PortalApi\Http\RequestHandlers\MessageCreate;
use Engelking\Webtrees\PortalApi\PortalApiModule;
use Engelking\Webtrees\PortalApi\Services\Connections;
use Engelking\Webtrees\PortalApi\Services\ContactDetails;
use Engelking\Webtrees\PortalApi\Services\Diagnosis;
use Engelking\Webtrees\PortalApi\Services\MemberService;
use Engelking\Webtrees\PortalApi\Services\PortalTreeService;
use Fig\Http\Message\RequestMethodInterface;
use Fig\Http\Message\StatusCodeInterface;
use Fisharebest\Webtrees\Auth;
use Fisharebest\Webtrees\Contracts\UserInterface;
use Fisharebest\Webtrees\DB;
use Fisharebest\Webtrees\Registry;
use Fisharebest\Webtrees\User;
use PHPUnit\Framework\Attributes\CoversNothing;
use Psr\Http\Message\ResponseInterface;

use function hash;
use function parse_str;
use function parse_url;
use function time;

use const PHP_URL_QUERY;

/**
 * Phase 11: two members deciding that they know each other.
 *
 * The fixture: Anna (X1, "SB 4711") is the member doing things. Dieter (X4,
 * "SB 4714") is her brother and is listed in the directory. Fritz (X6,
 * "SB 4716") is listed nowhere and is the one who proves what a connection
 * is worth — he cannot be found, opened or written to until he and Anna
 * agree, and then he can.
 *
 * The branch numbers are there to be confused with each other on purpose.
 * Dieter also carries "10/1335.21" and "7/22.9"; Fritz carries "101/335.21",
 * which is Dieter's first number once the slash is gone, and "10/1335.21!",
 * which is Dieter's first number with the marker that means "the spouse of".
 * Only "7/22.9" is unambiguous with the slash left out.
 */
#[CoversNothing]
class ConnectionTest extends PortalTestCase
{
    private User $anna;
    private User $dieter;
    private User $fritz;

    private int $dieter_id;
    private int $fritz_id;

    protected function setUp(): void
    {
        parent::setUp();

        $this->anna   = $this->createUser('anna', 'Anna Beispiel', 'correct-horse', UserInterface::ROLE_MEMBER, 'X1');
        $this->dieter = $this->createUser('dieter', 'Dieter Beispiel', 'anderes-pferd', UserInterface::ROLE_MEMBER, 'X4');
        $this->fritz  = $this->createUser('fritz', 'Fritz Beispiel', 'drittes-pferd', UserInterface::ROLE_MEMBER, 'X6');

        $this->createProfile($this->anna, true);
        $this->dieter_id = $this->createProfile($this->dieter, true);
        // Out of the directory on purpose: the whole question this phase
        // answers is what a member who is not listed can still agree to.
        $this->fritz_id = $this->createProfile($this->fritz, false);

        $this->module()->setPreference(PortalApiModule::SETTING_MEMBER_CONNECTIONS, '1');
        $this->module()->setPreference(PortalApiModule::SETTING_MEMBER_CONTACT, '1');
        $this->module()->setPreference(PortalApiModule::SETTING_MEMBER_MESSAGES, '1');
        $this->module()->setPreference(PortalApiModule::SETTING_PORTAL_URL, 'https://portal.example.test');

        $this->login($this->anna);
    }

    // -----------------------------------------------------------------
    // Helpers
    // -----------------------------------------------------------------

    /**
     * @param array<string,mixed> $body
     */
    private function connect(array $body): ResponseInterface
    {
        return $this->api(
            ConnectionCreate::class,
            RequestMethodInterface::METHOD_POST,
            body: $body,
            headers: $this->csrfHeader(),
        );
    }

    /**
     * @return array<string,mixed>
     */
    private function connections(): array
    {
        return $this->json($this->api(ConnectionList::class));
    }

    /**
     * Issue a code as somebody else, and hand back the token in it.
     *
     * `$back` is who to sign in as afterwards — the member the test is about,
     * which is Anna unless it says otherwise.
     */
    private function codeOf(User $owner, User|null $back = null): string
    {
        $this->login($owner);

        $url = $this->json($this->api(
            ConnectionCodeCreate::class,
            RequestMethodInterface::METHOD_POST,
            headers: $this->csrfHeader(),
        ))['url'];

        $this->login($back ?? $this->anna);

        parse_str((string) parse_url($url, PHP_URL_QUERY), $query);

        return (string) ($query['code'] ?? '');
    }

    /** Connect Anna and somebody, the way a family gathering does it. */
    private function connectWith(User $user): void
    {
        self::assertSame(
            StatusCodeInterface::STATUS_CREATED,
            $this->connect(['code' => $this->codeOf($user)])->getStatusCode()
        );
    }

    private function share(User $user, string $kind, string $value, string $audience): void
    {
        DB::table(ContactDetails::TABLE)->insert([
            'wt_user_id' => $user->id(),
            'kind'       => $kind,
            'value'      => $value,
            'audience'   => $audience,
            'created_at' => '2026-01-01 00:00:00',
            'updated_at' => '2026-01-01 00:00:00',
        ]);
    }

    // -----------------------------------------------------------------
    // The code: for when both people are in the same room
    // -----------------------------------------------------------------

    public function testAScannedCodeConnectsBothAtOnce(): void
    {
        $response = $this->connect(['code' => $this->codeOf($this->dieter)]);
        $result   = $this->json($response);

        self::assertSame(StatusCodeInterface::STATUS_CREATED, $response->getStatusCode());
        self::assertSame('connected', $result['status']);
        self::assertSame('Dieter Beispiel', $result['name']);
        self::assertCount(1, $result['connections']);
        self::assertSame([], $result['incoming']);
        self::assertSame([], $result['outgoing']);
        self::assertSame($this->dieter_id, $result['connections'][0]['member_id']);
    }

    /**
     * Showing the code is the consent, so it needs no second act — but it is
     * still consent given by *both*, because the other person has to do the
     * scanning. It shows up on the other member's list without them doing
     * anything else.
     */
    public function testTheOtherMemberSeesTheConnectionToo(): void
    {
        $this->connectWith($this->dieter);

        $this->login($this->dieter);

        $result = $this->connections();

        self::assertCount(1, $result['connections']);
        self::assertSame('Anna Beispiel', $result['connections'][0]['name']);
        self::assertFalse($result['connections'][0]['requested_by_me']);
    }

    public function testTheCodeIsStoredOnlyAsAHash(): void
    {
        $token = $this->codeOf($this->dieter);

        $row = DB::table(Connections::CODE_TABLE)->where('wt_user_id', '=', $this->dieter->id())->first();

        self::assertNotNull($row);
        self::assertNotSame($token, $row->token_hash);
        self::assertSame(hash('sha256', $token), $row->token_hash);
    }

    public function testAskingForANewCodeStopsTheOldOneWorking(): void
    {
        $first = $this->codeOf($this->dieter);
        $this->codeOf($this->dieter);

        $response = $this->connect(['code' => $first]);

        self::assertSame(StatusCodeInterface::STATUS_BAD_REQUEST, $response->getStatusCode());
        self::assertSame('invalid_token', $this->json($response)['error']);
    }

    public function testAWithdrawnCodeStopsWorking(): void
    {
        $token = $this->codeOf($this->dieter);

        $this->login($this->dieter);
        $this->api(ConnectionCodeDelete::class, RequestMethodInterface::METHOD_DELETE, headers: $this->csrfHeader());
        $this->login($this->anna);

        self::assertSame(StatusCodeInterface::STATUS_BAD_REQUEST, $this->connect(['code' => $token])->getStatusCode());
    }

    public function testAnExpiredCodeStopsWorking(): void
    {
        $token = $this->codeOf($this->dieter);

        DB::table(Connections::CODE_TABLE)
            ->where('wt_user_id', '=', $this->dieter->id())
            ->update(['expires_at' => time() - 1]);

        self::assertSame(StatusCodeInterface::STATUS_BAD_REQUEST, $this->connect(['code' => $token])->getStatusCode());
    }

    /**
     * An unknown code and an expired one are the same answer, for the same
     * reason an invitation's are: there is one thing the reader can do about
     * either, which is to ask for the code again.
     */
    public function testAnUnknownCodeIsRefusedTheSameWayAsAnExpiredOne(): void
    {
        $token = $this->codeOf($this->dieter);

        DB::table(Connections::CODE_TABLE)->where('wt_user_id', '=', $this->dieter->id())->delete();

        self::assertSame($this->raw($this->connect(['code' => $token])), $this->raw($this->connect(['code' => 'nonsense'])));
    }

    public function testScanningTheSameCodeTwiceIsNotAnError(): void
    {
        $token = $this->codeOf($this->dieter);

        $this->connect(['code' => $token]);
        $again = $this->connect(['code' => $token]);

        self::assertSame(StatusCodeInterface::STATUS_CREATED, $again->getStatusCode());
        self::assertCount(1, $this->json($again)['connections']);
    }

    public function testAMemberCannotConnectWithThemselves(): void
    {
        $response = $this->connect(['code' => $this->codeOf($this->anna)]);

        self::assertSame(StatusCodeInterface::STATUS_BAD_REQUEST, $response->getStatusCode());
    }

    /**
     * The code is a link into the portal, so without the portal's address
     * there is nothing to put in a QR code. Refused rather than handed over
     * broken — a member cannot tell a code nobody can scan from one that
     * works until somebody tries.
     */
    public function testACodeIsRefusedWhenThePortalAddressIsNotSet(): void
    {
        $this->module()->setPreference(PortalApiModule::SETTING_PORTAL_URL, '');

        $response = $this->api(ConnectionCodeCreate::class, RequestMethodInterface::METHOD_POST, headers: $this->csrfHeader());

        self::assertSame(StatusCodeInterface::STATUS_SERVICE_UNAVAILABLE, $response->getStatusCode());
        self::assertSame('not_configured', $this->json($response)['error']);
    }

    // -----------------------------------------------------------------
    // The link: for somebody who is not in the room
    // -----------------------------------------------------------------

    /** Issue a link as somebody else, and hand back the token in it. */
    private function linkOf(User $owner, User|null $back = null): string
    {
        $this->login($owner);

        $url = $this->json($this->api(
            ConnectionLinkCreate::class,
            RequestMethodInterface::METHOD_POST,
            headers: $this->csrfHeader(),
        ))['url'];

        $this->login($back ?? $this->anna);

        parse_str((string) parse_url($url, PHP_URL_QUERY), $query);

        return (string) ($query['code'] ?? '');
    }

    public function testASentLinkConnectsBothAtOnce(): void
    {
        $result = $this->json($this->connect(['code' => $this->linkOf($this->dieter)]));

        self::assertSame('connected', $result['status']);
        self::assertSame('Dieter Beispiel', $result['name']);
        self::assertCount(1, $result['connections']);
    }

    /**
     * The difference that matters between a link and the code on the screen.
     * A link travels through somebody else's inbox and out the far side —
     * forwarded, quoted in a reply, left in a chat a new telephone still
     * syncs — so the second person to follow it finds it spent.
     */
    public function testASentLinkWorksOnce(): void
    {
        $token = $this->linkOf($this->dieter);

        self::assertSame(StatusCodeInterface::STATUS_CREATED, $this->connect(['code' => $token])->getStatusCode());

        $this->login($this->fritz);

        $response = $this->connect(['code' => $token]);

        self::assertSame(StatusCodeInterface::STATUS_BAD_REQUEST, $response->getStatusCode());
        self::assertSame('invalid_token', $this->json($response)['error']);
    }

    public function testTheLinkIsStoredOnlyAsAHash(): void
    {
        $token = $this->linkOf($this->dieter);

        $row = DB::table(Connections::LINK_TABLE)->where('wt_user_id', '=', $this->dieter->id())->first();

        self::assertNotNull($row);
        self::assertSame(hash('sha256', $token), $row->token_hash);
    }

    public function testALinkLastsAWeekAndThenStops(): void
    {
        $token = $this->linkOf($this->dieter);

        $row = DB::table(Connections::LINK_TABLE)->where('wt_user_id', '=', $this->dieter->id())->first();

        self::assertEqualsWithDelta(time() + Connections::LINK_DAYS * 86400, (int) $row->expires_at, 5);

        DB::table(Connections::LINK_TABLE)->where('id', '=', $row->id)->update(['expires_at' => time() - 1]);

        self::assertSame(StatusCodeInterface::STATUS_BAD_REQUEST, $this->connect(['code' => $token])->getStatusCode());
    }

    /** Several at a time, one per person written to. */
    public function testAMemberMayHaveSeveralLinksOutstanding(): void
    {
        $this->api(ConnectionLinkCreate::class, RequestMethodInterface::METHOD_POST, headers: $this->csrfHeader());
        $this->api(ConnectionLinkCreate::class, RequestMethodInterface::METHOD_POST, headers: $this->csrfHeader());

        $links = $this->connections()['links'];

        self::assertCount(2, $links);
        self::assertArrayHasKey('expires_at', $links[0]);
    }

    public function testAWithdrawnLinkStopsWorking(): void
    {
        $token = $this->linkOf($this->dieter, $this->dieter);

        $id = $this->connections()['links'][0]['id'];

        $result = $this->json($this->api(
            ConnectionLinkDelete::class,
            RequestMethodInterface::METHOD_DELETE,
            attributes: ['id' => $id],
            headers: $this->csrfHeader(),
        ));

        self::assertSame([], $result['links']);

        $this->login($this->anna);

        self::assertSame(StatusCodeInterface::STATUS_BAD_REQUEST, $this->connect(['code' => $token])->getStatusCode());
    }

    public function testSomebodyElsesLinkCannotBeWithdrawn(): void
    {
        $this->linkOf($this->dieter);

        $id = (int) DB::table(Connections::LINK_TABLE)->where('wt_user_id', '=', $this->dieter->id())->value('id');

        $response = $this->api(
            ConnectionLinkDelete::class,
            RequestMethodInterface::METHOD_DELETE,
            attributes: ['id' => $id],
            headers: $this->csrfHeader(),
        );

        self::assertSame(StatusCodeInterface::STATUS_NOT_FOUND, $response->getStatusCode());
    }

    public function testAMemberCannotFollowTheirOwnLink(): void
    {
        $token = $this->linkOf($this->anna, $this->anna);

        self::assertSame(StatusCodeInterface::STATUS_BAD_REQUEST, $this->connect(['code' => $token])->getStatusCode());
    }

    /**
     * The point of the whole thing: the person at the other end never opened
     * the settings screen, so they are in nobody's directory.
     */
    public function testALinkReachesSomebodyWhoIsInNoDirectory(): void
    {
        $result = $this->json($this->connect(['code' => $this->linkOf($this->fritz)]));

        self::assertSame('connected', $result['status']);
        self::assertSame('Fritz Beispiel', $result['name']);
    }

    public function testThereIsALimitOnUnusedLinks(): void
    {
        for ($i = 0; $i < Connections::MAX_OPEN_LINKS; $i++) {
            self::assertSame(
                StatusCodeInterface::STATUS_CREATED,
                $this->api(ConnectionLinkCreate::class, RequestMethodInterface::METHOD_POST, headers: $this->csrfHeader())->getStatusCode()
            );
        }

        $response = $this->api(ConnectionLinkCreate::class, RequestMethodInterface::METHOD_POST, headers: $this->csrfHeader());

        self::assertSame(StatusCodeInterface::STATUS_CONFLICT, $response->getStatusCode());
        self::assertSame('quota_reached', $this->json($response)['error']);
    }

    public function testALinkIsRefusedWhenThePortalAddressIsNotSet(): void
    {
        $this->module()->setPreference(PortalApiModule::SETTING_PORTAL_URL, '');

        $response = $this->api(ConnectionLinkCreate::class, RequestMethodInterface::METHOD_POST, headers: $this->csrfHeader());

        self::assertSame(StatusCodeInterface::STATUS_SERVICE_UNAVAILABLE, $response->getStatusCode());
    }

    public function testSwitchingConnectionsOffRefusesLinks(): void
    {
        $this->module()->setPreference(PortalApiModule::SETTING_MEMBER_CONNECTIONS, '0');

        $response = $this->api(ConnectionLinkCreate::class, RequestMethodInterface::METHOD_POST, headers: $this->csrfHeader());

        self::assertSame(StatusCodeInterface::STATUS_FORBIDDEN, $response->getStatusCode());
    }

    // -----------------------------------------------------------------
    // The reference number: for everybody else
    // -----------------------------------------------------------------

    /**
     * Asking for a number that reaches nobody: the answer names nobody and
     * nothing is written down.
     *
     * There is no "not found" any more, and that is the point — see
     * `Connections::requestByReference()`. A member who stayed out of the
     * directory is reachable, and the only way to reach them without turning
     * the search into a way of asking who has an account is to answer a
     * number nobody carries exactly as a number they do.
     */
    private function assertReachesNobody(string $reference): void
    {
        $before = DB::table(Connections::TABLE)->count();
        $result = $this->json($this->connect(['reference' => $reference]));

        self::assertSame('requested', $result['status']);
        self::assertNull($result['name']);
        self::assertSame([], $result['outgoing']);
        self::assertSame($before, DB::table(Connections::TABLE)->count(), 'Nothing should have been written.');
    }

    public function testAReferenceNumberAsksRatherThanConnects(): void
    {
        $result = $this->json($this->connect(['reference' => '4714']));

        self::assertSame('requested', $result['status']);
        self::assertSame('Dieter Beispiel', $result['name']);
        self::assertSame([], $result['connections']);
        self::assertCount(1, $result['outgoing']);
    }

    public function testTheTypeMayBeTypedOrLeftOut(): void
    {
        self::assertSame('requested', $this->json($this->connect(['reference' => 'SB 4714']))['status']);
    }

    /**
     * The common case, and the one a strict comparison got wrong: the record
     * carries no `TYPE` at all. GEDCOM does not require one and the family
     * calls its numbering "SB" either way, so a member reading "SB 4712" off
     * a letterhead must find Bertha, who is stored as a bare `1 REFN 4712`.
     */
    public function testTheFamilysPrefixFindsARecordThatCarriesNoTypeOfItsOwn(): void
    {
        $bertha = $this->createUser('bertha', 'Bertha Beispiel', 'fuenftes-pferd', UserInterface::ROLE_MEMBER, 'X2');
        $id     = $this->createProfile($bertha, true);

        self::assertSame($id, $this->askedMemberId('SB 4712'));
    }

    /**
     * But only where the record does not disagree. A number that says it
     * belongs to another numbering is another number.
     */
    public function testAPrefixThatContradictsTheRecordFindsNobody(): void
    {
        $this->assertReachesNobody('XY 4714');
    }

    /**
     * The family's real shape: a branch, a slash, a number. Dieter carries
     * "10/1335.21" as well, and the portal composes exactly that string from
     * the branch the member picked and the number they typed.
     */
    public function testANumberWithABranchIsFound(): void
    {
        self::assertSame($this->dieter_id, $this->askedMemberId('10/1335.21'));
    }

    public function testABranchNumberIsFoundHoweverItIsPunctuated(): void
    {
        foreach (['SB 10/1335.21', '10 / 1335,21', '10/133521', '10/1335-21'] as $typed) {
            self::assertSame($this->dieter_id, $this->askedMemberId($typed), $typed . ' should find the same person.');
        }
    }

    /**
     * Typed without the slash it is still found — somebody reading a number
     * off a letterhead should not have to know that the portal cares.
     *
     * Dieter's "7/22.9" is the one number in the fixture that nothing else
     * collides with once the slash is gone. The test below is what happens
     * to the rest.
     */
    public function testTheSlashMayBeLeftOut(): void
    {
        self::assertSame($this->dieter_id, $this->askedMemberId('7229'));
    }

    /**
     * A number may carry letters, anywhere in it.
     *
     * Bertha's "47C12" has no `TYPE` of its own, so the family's spoken
     * prefix has to be allowed to fall away from in front of it — and only
     * from in front of it. Stripping every leading letter, which is what this
     * did while numbers were assumed to be digits, would have turned
     * "SB 47C12" into "47" and found nobody.
     */
    public function testANumberMayCarryLetters(): void
    {
        $bertha = $this->createUser('bertha', 'Bertha Beispiel', 'fuenftes-pferd', UserInterface::ROLE_MEMBER, 'X2');
        $id     = $this->createProfile($bertha, true);

        self::assertSame($id, $this->askedMemberId('47C12'));
        self::assertSame($id, $this->askedMemberId('sb 47c12'));
    }

    /**
     * And a number may carry a marker on its end, which is not decoration:
     * "!" means the spouse of the person carrying the number without it.
     *
     * Dieter is 10/1335.21 and Fritz stands in for his spouse at
     * 10/1335.21!. Two people, two numbers — and a request meant for one of
     * them must not quietly arrive at the other, which is what happened while
     * the "!" was thrown away with the punctuation.
     */
    public function testTheMarkerOnTheEndTellsACoupleApart(): void
    {
        $this->list($this->fritz_id);

        self::assertSame($this->dieter_id, $this->askedMemberId('10/1335.21'));
        self::assertSame($this->fritz_id, $this->askedMemberId('10/1335.21!'));
    }

    /**
     * So it never falls away on the second pass either. Somebody who types
     * the marker has said which of the two they mean; a number that reaches
     * neither of them is a better answer than one that reaches the wrong one.
     */
    public function testAMarkerIsNeverDroppedToMakeANumberFit(): void
    {
        $this->assertReachesNobody('7/22.9!');
    }

    /**
     * And the slash is what keeps two numbers apart that would otherwise be
     * one string. Dieter is 10/1335.21 and Fritz is 101/335.21; typed as
     * they are written, each finds its own person.
     */
    public function testTheBranchSeparatorKeepsTwoNumbersApart(): void
    {
        $this->list($this->fritz_id);

        self::assertSame($this->dieter_id, $this->askedMemberId('10/1335.21'));
        self::assertSame($this->fritz_id, $this->askedMemberId('101/335.21'));
    }

    /**
     * Typed without it, the two are one string — and then the portal says it
     * found nobody rather than guessing which cousin was meant.
     */
    public function testALeftOutSlashIsRefusedWhereItWouldBeAGuess(): void
    {
        $this->list($this->fritz_id);

        $this->assertReachesNobody('10133521');
    }

    /**
     * A number that belongs to somebody already in my contacts says so, and
     * writes nothing.
     *
     * `link()` never made a second row for them, but the answer was wrong in
     * both directions: a listed contact was told "you are now connected",
     * which reads as though the number had just done something, and an
     * unlisted one was told a request was on its way — which was untrue, and
     * left the member waiting for an answer that could not come.
     */
    public function testANumberThatBelongsToAContactSaysSoInsteadOfAsking(): void
    {
        $this->connect(['reference' => '4714']);
        $this->accept($this->dieter);

        $before = DB::table(Connections::TABLE)->count();
        $result = $this->json($this->connect(['reference' => '10/1335.21']));

        self::assertSame('already_connected', $result['status']);
        self::assertSame('Dieter Beispiel', $result['name']);
        self::assertSame($before, DB::table(Connections::TABLE)->count(), 'Nothing should have been written.');

        // And the contact is where it was.
        self::assertCount(1, $result['connections']);
    }

    /**
     * And it says so for a contact who is *not* in the directory, which is
     * the half the quiet answer used to swallow.
     *
     * Nothing is disclosed by naming them: they are this member's own
     * contact, already on the other half of the same screen. The silence is
     * there to keep the search from becoming a way of asking who has an
     * account, and about somebody in the address book that question was
     * answered when they accepted.
     */
    public function testAnUnlistedContactIsNamedRatherThanAnsweredQuietly(): void
    {
        $this->connect(['reference' => '4716']);
        $this->accept($this->fritz);

        $before = DB::table(Connections::TABLE)->count();
        $result = $this->json($this->connect(['reference' => '4716']));

        self::assertSame('already_connected', $result['status']);
        self::assertSame('Fritz Beispiel', $result['name']);
        self::assertSame($before, DB::table(Connections::TABLE)->count());
    }

    /**
     * A request that crosses one coming the other way is the answer to it —
     * and that is said out loud, even for a member who is not listed.
     *
     * It can mean nothing else: `link()` only accepts on the spot here when
     * the other member had already asked, and their request was in this
     * member's own list, under their name, before a number was typed. Staying
     * quiet would report a request as waiting that is already settled.
     */
    public function testANumberThatAnswersAWaitingRequestSaysWhoItConnected(): void
    {
        $this->login($this->fritz);
        $this->connect(['reference' => '4711']);
        $this->login($this->anna);

        $result = $this->json($this->connect(['reference' => '4716']));

        self::assertSame('connected', $result['status']);
        self::assertSame('Fritz Beispiel', $result['name']);
        self::assertCount(1, $result['connections']);
    }

    /**
     * A request still waiting for an answer keeps the quiet answer, though.
     *
     * "You already asked this person" would say that the number belongs to
     * somebody — which is exactly what a member who stayed out of the
     * directory is owed silence about. Typing a number twice must tell you no
     * more than typing it once did.
     */
    public function testAnUnansweredRequestStillReachesNobodyOutLoud(): void
    {
        $this->connect(['reference' => '4716']);

        $waiting = $this->raw($this->connect(['reference' => '4716']));

        self::assertSame($waiting, $this->raw($this->connect(['reference' => '1234'])));
    }

    /**
     * The case that made this feature look broken in an installation where
     * it was working exactly as written.
     *
     * Phase 8 lets an administrator limit what a member *sees* to a few steps
     * of the tree. Fritz is in the directory, by name, and Anna cannot see
     * his record — and the number search skipped him, because it read his
     * `REFN` at her access level and `GedcomRecord::facts()` hands back
     * nothing at all for a record the reader may not see.
     *
     * The number is not tree data in the sense that matters here: it comes
     * off a letterhead, and the person it belongs to is already published in
     * the directory under their name. So the search reaches the same people
     * the directory does.
     */
    public function testAListedMemberIsFoundEvenWhereTheirRecordIsHidden(): void
    {
        $this->list($this->fritz_id);

        // What an administrator does on the Diagnosis screen, for everybody.
        $this->tree->setUserPreference($this->anna, UserInterface::PREF_TREE_PATH_LENGTH, '1');

        self::assertNull(
            $this->trees()->linkedIndividual($this->tree, $this->fritz)?->canShow(Auth::PRIV_PRIVATE) ?: null,
            'The fixture must actually hide Fritz from Anna, or this proves nothing.'
        );

        self::assertSame($this->fritz_id, $this->askedMemberId('4716'));
    }

    private function trees(): PortalTreeService
    {
        return Registry::container()->get(PortalTreeService::class);
    }

    /**
     * The three reasons a number finds nobody, told apart on one screen.
     *
     * An administrator looking at "it does not work" has no way to see which
     * of them applies, because all three look the same from the form.
     */
    public function testTheDiagnosisScreenSaysWhoCanBeFoundByNumber(): void
    {
        $listed_without_record = $this->createUser('nora', 'Nora Ohnesatz', 'sechstes-pferd', UserInterface::ROLE_MEMBER);
        $this->createProfile($listed_without_record, true);

        $rows = [];

        foreach (Registry::container()->get(Diagnosis::class)->directoryNumbers() as $row) {
            $rows[$row['name']] = $row;
        }

        // Listed, with a record, with a number: findable.
        self::assertSame(['SB 4714', 'SB 10/1335.21', 'SB 7/22.9'], $rows['Dieter Beispiel']['numbers']);

        // Listed, but the account is linked to nobody: no number to find.
        self::assertSame([], $rows['Nora Ohnesatz']['numbers']);
        self::assertNull($rows['Nora Ohnesatz']['xref']);

        // Not in the directory at all, so not in the table either.
        self::assertArrayNotHasKey('Fritz Beispiel', $rows);
    }

    /**
     * Let the other member say yes, and hand the session back to Anna.
     *
     * The half of a connection the tests about *her* screen need to have
     * happened, without repeating four lines of PATCH each time.
     */
    private function accept(User $other): void
    {
        $this->login($other);

        $this->api(
            ConnectionUpdate::class,
            RequestMethodInterface::METHOD_PATCH,
            attributes: ['id' => $this->connections()['incoming'][0]['id']],
            body: ['status' => 'accepted'],
            headers: $this->csrfHeader(),
        );

        $this->login($this->anna);
    }

    /** Put somebody into the directory who was deliberately left out of it. */
    private function list(int $member_id): void
    {
        DB::table(MemberService::TABLE)->where('id', '=', $member_id)->update(['visible_in_directory' => 1]);
    }

    /** The member a request went to, by portal member id. */
    private function askedMemberId(string $reference): int
    {
        DB::table(Connections::TABLE)->delete();

        $result = $this->json($this->connect(['reference' => $reference]));

        return (int) $result['outgoing'][0]['member_id'];
    }

    public function testTheRequestReachesTheOtherMemberAndConnectsWhenAccepted(): void
    {
        $this->connect(['reference' => '4714']);

        $this->login($this->dieter);

        $waiting = $this->connections();

        self::assertCount(1, $waiting['incoming']);
        self::assertSame('Anna Beispiel', $waiting['incoming'][0]['name']);
        self::assertSame(1, $this->json($this->api(MeRead::class))['connection_requests']);

        $accepted = $this->json($this->api(
            ConnectionUpdate::class,
            RequestMethodInterface::METHOD_PATCH,
            attributes: ['id' => $waiting['incoming'][0]['id']],
            body: ['status' => 'accepted'],
            headers: $this->csrfHeader(),
        ));

        self::assertCount(1, $accepted['connections']);
        self::assertSame([], $accepted['incoming']);

        $this->login($this->anna);

        self::assertCount(1, $this->connections()['connections']);
    }

    public function testANumberNobodyCarriesIsAnsweredLikeOneSomebodyDoes(): void
    {
        $this->assertReachesNobody('1234');
    }

    /**
     * The case this was asked for.
     *
     * Fritz is in nobody's directory. He carries "SB 4716", and a member who
     * knows that number can now ask him — he decides. Nothing about him
     * reaches the person asking until he does: not his name, not even that
     * the number belongs to anybody.
     */
    public function testAMemberWhoStayedOutOfTheDirectoryCanStillBeAsked(): void
    {
        $result = $this->json($this->connect(['reference' => '4716']));

        // Answered exactly as a number nobody carries.
        self::assertSame('requested', $result['status']);
        self::assertNull($result['name']);
        self::assertSame([], $result['outgoing']);

        // But the request is real, and it is waiting for him.
        $this->login($this->fritz);

        $waiting = $this->connections()['incoming'];

        self::assertCount(1, $waiting);
        self::assertSame('Anna Beispiel', $waiting[0]['name']);
    }

    /**
     * Reaching him and reaching nobody are the same answer, byte for byte.
     * Anything less and the number search becomes a way of asking which
     * relatives have an account — which is what staying out of the directory
     * is a decision against.
     */
    public function testReachingAnUnlistedMemberLooksExactlyLikeReachingNobody(): void
    {
        $unlisted = $this->raw($this->connect(['reference' => '4716']));

        DB::table(Connections::TABLE)->delete();

        self::assertSame($unlisted, $this->raw($this->connect(['reference' => '1234'])));
    }

    /**
     * And once he says yes, he is a contact like anybody else — that is the
     * moment the person who asked learns anything at all.
     */
    public function testAnUnlistedMemberAppearsOnceTheyAccept(): void
    {
        $this->connect(['reference' => '4716']);

        $this->login($this->fritz);

        $id = $this->connections()['incoming'][0]['id'];

        $this->api(
            ConnectionUpdate::class,
            RequestMethodInterface::METHOD_PATCH,
            attributes: ['id' => $id],
            body: ['status' => 'accepted'],
            headers: $this->csrfHeader(),
        );

        $this->login($this->anna);

        $connections = $this->connections()['connections'];

        self::assertCount(1, $connections);
        self::assertSame('Fritz Beispiel', $connections[0]['name']);
    }

    /**
     * The numbers this searches are the ones the member could already read
     * off the record. A `RESN` under a `REFN` hides it, so Bertha's internal
     * "9999" is not there while her published "4712" is.
     */
    public function testAConfidentialReferenceNumberCannotBeSearched(): void
    {
        $bertha = $this->createUser('bertha', 'Bertha Beispiel', 'fuenftes-pferd', UserInterface::ROLE_MEMBER, 'X2');
        $id     = $this->createProfile($bertha, true);

        $this->assertReachesNobody('9999');

        self::assertSame($id, $this->askedMemberId('4712'));
    }

    public function testAMemberCannotAskThemselves(): void
    {
        self::assertSame(StatusCodeInterface::STATUS_BAD_REQUEST, $this->connect(['reference' => '4711'])->getStatusCode());
    }

    /**
     * The directory is where a member is already looking for somebody, so it
     * is where a request should be sendable from. That needs the state on
     * every row — and one query for the page, not one per row.
     */
    public function testEveryRowOfTheDirectorySaysWhereTheTwoStand(): void
    {
        $before = $this->json($this->api(MemberList::class));

        self::assertTrue($before['connections_enabled']);
        self::assertSame(
            ['none'],
            $this->statesOf($before, $this->dieter_id),
            'Nothing between them yet.'
        );

        // A member's own row offers nothing, because there is nothing to
        // offer: you cannot connect with yourself.
        self::assertSame(['self'], $this->statesOf($before, $this->annaMemberId()));

        $this->connect(['member_id' => $this->dieter_id]);

        self::assertSame(['requested'], $this->statesOf($this->json($this->api(MemberList::class)), $this->dieter_id));

        $this->login($this->dieter);

        self::assertSame(['incoming'], $this->statesOf($this->json($this->api(MemberList::class)), $this->annaMemberId()));
    }

    public function testTheDirectoryOffersNothingWhileTheFacilityIsOff(): void
    {
        $this->module()->setPreference(PortalApiModule::SETTING_MEMBER_CONNECTIONS, '0');

        self::assertFalse($this->json($this->api(MemberList::class))['connections_enabled']);
    }

    /**
     * @param array<string,mixed> $page
     *
     * @return array<int,string> The connection status on that member's row.
     */
    private function statesOf(array $page, int $member_id): array
    {
        $states = [];

        foreach ($page['items'] as $item) {
            if ($item['id'] === $member_id) {
                $states[] = $item['connection']['status'];
            }
        }

        return $states;
    }

    private function annaMemberId(): int
    {
        $profile = DB::table('portal_member_profile')->where('wt_user_id', '=', $this->anna->id())->first();

        return (int) $profile->id;
    }

    public function testAMemberOfTheDirectoryCanBeAskedFromTheirOwnPage(): void
    {
        $result = $this->json($this->connect(['member_id' => $this->dieter_id]));

        self::assertSame('requested', $result['status']);
        self::assertCount(1, $result['outgoing']);
    }

    /**
     * Two people asking each other have both said yes, and there is nothing
     * left for either of them to confirm.
     */
    public function testARequestThatCrossesOneComingTheOtherWayIsTheAnswerToIt(): void
    {
        $this->connect(['reference' => '4714']);

        $this->login($this->dieter);

        $result = $this->json($this->connect(['reference' => '4711']));

        self::assertSame('connected', $result['status']);
        self::assertCount(1, $result['connections']);
        self::assertSame([], $result['incoming']);
        self::assertSame([], $result['outgoing']);
    }

    public function testAskingNeedsExactlyOneOfTheThreeWays(): void
    {
        self::assertSame(StatusCodeInterface::STATUS_BAD_REQUEST, $this->connect([])->getStatusCode());
        self::assertSame(
            StatusCodeInterface::STATUS_BAD_REQUEST,
            $this->connect(['reference' => '4714', 'member_id' => $this->dieter_id])->getStatusCode()
        );
    }

    public function testARequestCannotBeAcceptedByAnybodyElse(): void
    {
        $id = $this->json($this->connect(['reference' => '4714']))['outgoing'][0]['id'];

        // The member who *sent* it cannot accept it either. That is the whole
        // point of asking.
        $response = $this->api(
            ConnectionUpdate::class,
            RequestMethodInterface::METHOD_PATCH,
            attributes: ['id' => $id],
            body: ['status' => 'accepted'],
            headers: $this->csrfHeader(),
        );

        self::assertSame(StatusCodeInterface::STATUS_NOT_FOUND, $response->getStatusCode());
    }

    // -----------------------------------------------------------------
    // Ending it
    // -----------------------------------------------------------------

    public function testARefusalLeavesNothingBehind(): void
    {
        $this->connect(['reference' => '4714']);

        $this->login($this->dieter);

        $id     = $this->connections()['incoming'][0]['id'];
        $result = $this->json($this->api(
            ConnectionDelete::class,
            RequestMethodInterface::METHOD_DELETE,
            attributes: ['id' => $id],
            headers: $this->csrfHeader(),
        ));

        self::assertSame([], $result['incoming']);
        self::assertSame(0, DB::table(Connections::TABLE)->count());

        // And the member who asked is not told they were refused — there is
        // simply nothing outstanding any more.
        $this->login($this->anna);

        self::assertSame([], $this->connections()['outgoing']);
    }

    public function testEitherSideCanEndAConnection(): void
    {
        $this->connectWith($this->dieter);

        $this->login($this->dieter);

        $id = $this->connections()['connections'][0]['id'];

        $this->api(
            ConnectionDelete::class,
            RequestMethodInterface::METHOD_DELETE,
            attributes: ['id' => $id],
            headers: $this->csrfHeader(),
        );

        $this->login($this->anna);

        self::assertSame([], $this->connections()['connections']);
    }

    public function testAConnectionBetweenTwoOtherPeopleCannotBeEnded(): void
    {
        $this->login($this->dieter);
        $this->connect(['code' => $this->codeOf($this->fritz, $this->dieter)]);
        $id = $this->connections()['connections'][0]['id'];

        $this->login($this->anna);

        $response = $this->api(
            ConnectionDelete::class,
            RequestMethodInterface::METHOD_DELETE,
            attributes: ['id' => $id],
            headers: $this->csrfHeader(),
        );

        self::assertSame(StatusCodeInterface::STATUS_NOT_FOUND, $response->getStatusCode());
    }

    // -----------------------------------------------------------------
    // What a connection is worth
    // -----------------------------------------------------------------

    public function testContactDetailsSharedWithContactsReachAContact(): void
    {
        $this->share($this->dieter, 'phone', '0511 123456', ContactDetails::AUDIENCE_CONNECTIONS);

        self::assertSame([], $this->json($this->api(MemberRead::class, attributes: ['id' => $this->dieter_id]))['contact']);

        $this->connectWith($this->dieter);

        self::assertSame(
            ['phone' => '0511 123456'],
            $this->json($this->api(MemberRead::class, attributes: ['id' => $this->dieter_id]))['contact']
        );
    }

    public function testSwitchingConnectionsOffSilencesWhatTheyDisclosed(): void
    {
        $this->share($this->dieter, 'phone', '0511 123456', ContactDetails::AUDIENCE_CONNECTIONS);
        $this->connectWith($this->dieter);

        $this->module()->setPreference(PortalApiModule::SETTING_MEMBER_CONNECTIONS, '0');

        self::assertSame([], $this->json($this->api(MemberRead::class, attributes: ['id' => $this->dieter_id]))['contact']);

        // The list itself stays, so that a member can still see and end what
        // they agreed to.
        self::assertCount(1, $this->connections()['connections']);
        self::assertFalse($this->connections()['enabled']);
    }

    /**
     * A member choosing an audience that would share nothing is being told
     * something untrue by a form. The settings screen is told, so it can drop
     * the choice rather than offer it.
     */
    public function testTheContactScreenIsToldWhetherThatAudienceMeansAnything(): void
    {
        self::assertTrue($this->json($this->api(ContactRead::class))['connections_enabled']);

        $this->module()->setPreference(PortalApiModule::SETTING_MEMBER_CONNECTIONS, '0');

        self::assertFalse($this->json($this->api(ContactRead::class))['connections_enabled']);
    }

    /** A badge counting something a member cannot act on teaches them to ignore badges. */
    public function testAWaitingRequestIsNotCountedWhileTheFacilityIsOff(): void
    {
        $this->connect(['reference' => '4714']);

        $this->login($this->dieter);

        self::assertSame(1, $this->json($this->api(MeRead::class))['connection_requests']);

        $this->module()->setPreference(PortalApiModule::SETTING_MEMBER_CONNECTIONS, '0');

        self::assertSame(0, $this->json($this->api(MeRead::class))['connection_requests']);
    }

    public function testSwitchingConnectionsOffRefusesNewOnes(): void
    {
        $token = $this->codeOf($this->dieter);

        $this->module()->setPreference(PortalApiModule::SETTING_MEMBER_CONNECTIONS, '0');

        $response = $this->connect(['code' => $token]);

        self::assertSame(StatusCodeInterface::STATUS_FORBIDDEN, $response->getStatusCode());
        self::assertSame('not_allowed', $this->json($response)['error']);
    }

    /**
     * The narrower consent of the two: Fritz told nobody he is here, and one
     * person can now open his page, because he and she agreed to it.
     */
    public function testAConnectedMemberWhoStayedOutOfTheDirectoryCanBeOpened(): void
    {
        self::assertSame(
            StatusCodeInterface::STATUS_NOT_FOUND,
            $this->api(MemberRead::class, attributes: ['id' => $this->fritz_id])->getStatusCode()
        );

        $this->connectWith($this->fritz);

        $member = $this->json($this->api(MemberRead::class, attributes: ['id' => $this->fritz_id]));

        self::assertSame('Fritz Beispiel', $member['display_name']);
        self::assertSame('connected', $member['connection']['status']);
    }

    public function testAConnectedMemberWhoStayedOutOfTheDirectoryCanBeWrittenTo(): void
    {
        $message = fn (): ResponseInterface => $this->api(
            MessageCreate::class,
            RequestMethodInterface::METHOD_POST,
            attributes: ['id' => $this->fritz_id],
            body: ['subject' => 'Hallo', 'body' => 'Wie geht es dir?'],
            headers: $this->csrfHeader(),
        );

        self::assertSame(StatusCodeInterface::STATUS_NOT_FOUND, $message()->getStatusCode());

        $this->connectWith($this->fritz);

        self::assertSame(StatusCodeInterface::STATUS_ACCEPTED, $message()->getStatusCode());
    }

    public function testAnUnconnectedMemberIsStillNotInTheDirectory(): void
    {
        $this->connectWith($this->fritz);

        $listed = $this->json($this->api(MemberList::class));

        foreach ($listed['items'] as $item) {
            self::assertNotSame($this->fritz_id, $item['id'], 'Connecting must not list somebody in the directory.');
        }
    }

    /** Being connected is not the same as being visible to everybody else. */
    public function testAConnectionDiscloseNothingToAThirdMember(): void
    {
        $this->share($this->fritz, 'phone', '0511 999999', ContactDetails::AUDIENCE_CONNECTIONS);
        $this->connectWith($this->fritz);

        $this->login($this->dieter);

        self::assertSame(
            StatusCodeInterface::STATUS_NOT_FOUND,
            $this->api(MemberRead::class, attributes: ['id' => $this->fritz_id])->getStatusCode()
        );
    }

    // -----------------------------------------------------------------
    // Limits
    // -----------------------------------------------------------------

    public function testThereIsALimitOnUnansweredRequests(): void
    {
        $now = time();

        for ($i = 0; $i < Connections::MAX_PENDING_REQUESTS; $i++) {
            $other = $this->createUser('other' . $i, 'Other ' . $i, 'pw' . $i, UserInterface::ROLE_MEMBER);

            DB::table(Connections::TABLE)->insert([
                'requested_by' => $this->anna->id(),
                'requested_of' => $other->id(),
                'status'       => Connections::STATUS_PENDING,
                'source'       => Connections::SOURCE_REFERENCE,
                'created_at'   => $now,
            ]);
        }

        $response = $this->connect(['reference' => '4714']);

        self::assertSame(StatusCodeInterface::STATUS_CONFLICT, $response->getStatusCode());
        self::assertSame('quota_reached', $this->json($response)['error']);

        // A code is not a request and is not counted: the person is standing
        // in front of them.
        self::assertSame(StatusCodeInterface::STATUS_CREATED, $this->connect(['code' => $this->codeOf($this->dieter)])->getStatusCode());
    }

    public function testEverythingThatChangesAConnectionNeedsTheCsrfToken(): void
    {
        $response = $this->api(
            ConnectionCreate::class,
            RequestMethodInterface::METHOD_POST,
            body: ['reference' => '4714'],
        );

        self::assertSame(StatusCodeInterface::STATUS_FORBIDDEN, $response->getStatusCode());
        self::assertSame('csrf_token_invalid', $this->json($response)['error']);
    }

    public function testSigningOutTakesTheListWithIt(): void
    {
        Auth::logout();

        self::assertSame(
            StatusCodeInterface::STATUS_UNAUTHORIZED,
            $this->api(ConnectionList::class)->getStatusCode()
        );
    }
}
