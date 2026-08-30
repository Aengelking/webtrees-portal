<?php

declare(strict_types=1);

namespace Engelking\Webtrees\PortalApi\Tests;

use Engelking\Webtrees\PortalApi\Http\RequestHandlers\ConnectionCreate;
use Engelking\Webtrees\PortalApi\Http\RequestHandlers\IndividualRead;
use Engelking\Webtrees\PortalApi\PortalApiModule;
use Engelking\Webtrees\PortalApi\Services\Connections;
use Fig\Http\Message\RequestMethodInterface;
use Fig\Http\Message\StatusCodeInterface;
use Fisharebest\Webtrees\Contracts\UserInterface;
use Fisharebest\Webtrees\DB;
use Fisharebest\Webtrees\User;
use PHPUnit\Framework\Attributes\CoversNothing;
use Psr\Http\Message\ResponseInterface;

use function time;

/**
 * Asking to connect from the page of the person themselves.
 *
 * Walking the tree is how a member finds a relative; the number search was
 * how they had to ask afterwards, having read a number off one screen to type
 * it into another. The button is the short way — and the *answer* has to stay
 * the long way's answer, which is what this file is really about.
 *
 * A member who stays out of the directory has decided that the portal must not
 * confirm they are in it. So the page may not say "this one has an account":
 * an offer that appeared only for account holders would answer that question
 * to anybody who can see the record, which on a family tree is nearly
 * everybody. `open` therefore means "the button is worth showing" and covers
 * the member who is not listed, the relative with no account at all, and a
 * request sent yesterday that nobody has answered — one word for the three.
 */
#[CoversNothing]
class ConnectFromRecordTest extends PortalTestCase
{
    private User $anna;

    protected function setUp(): void
    {
        parent::setUp();

        $this->anna = $this->createUser('anna', 'Anna Beispiel', 'pw', UserInterface::ROLE_MEMBER, 'X1');
        $this->createProfile($this->anna, false);
        $this->login($this->anna);
    }

    private function record(string $xref): array
    {
        return $this->json($this->api(IndividualRead::class, attributes: ['xref' => $xref]));
    }

    private function connectTo(string $xref): ResponseInterface
    {
        return $this->api(
            ConnectionCreate::class,
            RequestMethodInterface::METHOD_POST,
            body: ['xref' => $xref],
            headers: $this->csrfHeader(),
        );
    }

    /** The two of them, already contacts, without going through a request. */
    private function connect(User $other): void
    {
        DB::table(Connections::TABLE)->insert([
            'requested_by' => $this->anna->id(),
            'requested_of' => $other->id(),
            'status'       => Connections::STATUS_ACCEPTED,
            'source'       => Connections::SOURCE_CODE,
            'created_at'   => time(),
            'decided_at'   => time(),
        ]);
    }

    /** Dieter, with an account of his own. `$listed` is his directory choice. */
    private function dieter(bool $listed): User
    {
        $dieter = $this->createUser('dieter', 'Dieter Beispiel', 'pw', UserInterface::ROLE_MEMBER, 'X4');
        $this->createProfile($dieter, $listed);

        return $dieter;
    }

    public function testARelativeWithAnAccountCanBeAskedFromTheirPage(): void
    {
        $this->dieter(true);

        self::assertSame('open', $this->record('X4')['connection']);
    }

    /**
     * The one that matters. Fritz has no account, and his page says exactly
     * what Dieter's says — otherwise the button itself would be the answer to
     * "who here is in the portal".
     */
    public function testARelativeWithNoAccountLooksExactlyTheSame(): void
    {
        $this->dieter(true);

        self::assertSame($this->record('X4')['connection'], $this->record('X6')['connection']);
    }

    /** And so does a member who kept out of the directory. */
    public function testAMemberWhoStayedOutOfTheDirectoryLooksTheSameToo(): void
    {
        $this->dieter(false);

        self::assertSame('open', $this->record('X4')['connection']);
        self::assertSame($this->record('X6')['connection'], $this->record('X4')['connection']);
    }

    /**
     * A request already sent is not shown either — that is the same
     * disclosure one step later. Only the other member accepting it changes
     * what this page says.
     */
    public function testARequestAlreadySentIsNotReportedBack(): void
    {
        $this->dieter(false);
        $this->connectTo('X4');

        self::assertSame('open', $this->record('X4')['connection']);
    }

    public function testAContactIsShownAsOne(): void
    {
        $dieter = $this->dieter(true);
        $this->connect($dieter);

        self::assertSame('connected', $this->record('X4')['connection']);
    }

    /** Nobody connects with the dead, and saying so gives nothing away. */
    public function testTheDeadAreNotOffered(): void
    {
        self::assertNull($this->record('X2')['connection']);
    }

    public function testTheMembersOwnRecordIsNotOffered(): void
    {
        self::assertNull($this->record('X1')['connection']);
    }

    public function testNothingIsOfferedWhereTheFamilySwitchedConnectionsOff(): void
    {
        $this->dieter(true);
        $this->module()->setPreference(PortalApiModule::SETTING_MEMBER_CONNECTIONS, '0');

        self::assertNull($this->record('X4')['connection']);
    }

    // -----------------------------------------------------------------
    // And the request itself
    // -----------------------------------------------------------------

    public function testAskingAListedMemberIsAnsweredByName(): void
    {
        $this->dieter(true);

        $body = $this->json($this->connectTo('X4'));

        self::assertSame('requested', $body['status']);
        self::assertSame('Dieter Beispiel', $body['name']);
    }

    /**
     * The quiet answer, and the reason for it: a member who is not listed is
     * asked all the same — that is what makes them reachable at all — and the
     * person asking learns nothing until the answer comes.
     */
    public function testAskingAnUnlistedMemberSaysNothingAboutThem(): void
    {
        $this->dieter(false);

        $body = $this->json($this->connectTo('X4'));

        self::assertSame('requested', $body['status']);
        self::assertNull($body['name']);
    }

    /** And the same sentence where there was nobody to ask. */
    public function testAskingSomebodyWithNoAccountIsAnsweredIdentically(): void
    {
        $this->dieter(false);

        $unlisted = $this->json($this->connectTo('X4'));
        $nobody   = $this->json($this->connectTo('X6'));

        self::assertSame($unlisted['status'], $nobody['status']);
        self::assertSame($unlisted['name'], $nobody['name']);
        self::assertSame(StatusCodeInterface::STATUS_CREATED, $this->connectTo('X6')->getStatusCode());
    }

    /** Nothing is written for a record nobody is behind. */
    public function testAskingSomebodyWithNoAccountWritesNothing(): void
    {
        $before = DB::table(Connections::TABLE)->count();

        $this->connectTo('X6');

        self::assertSame($before, DB::table(Connections::TABLE)->count());
    }

    public function testAskingTheSameMemberTwiceLeavesOneRequest(): void
    {
        $this->dieter(false);

        $this->connectTo('X4');
        $this->connectTo('X4');

        self::assertSame(1, DB::table(Connections::TABLE)->count());
    }

    public function testAMemberCannotAskThemselves(): void
    {
        self::assertSame(
            StatusCodeInterface::STATUS_BAD_REQUEST,
            $this->connectTo('X1')->getStatusCode()
        );
    }

    /**
     * A record this member cannot see is a record that does not exist — the
     * same 404 the read gives, so that asking cannot become a way of finding
     * out that somebody is there.
     */
    public function testARecordThatIsNotThereIsANotFound(): void
    {
        self::assertSame(
            StatusCodeInterface::STATUS_NOT_FOUND,
            $this->connectTo('X999')->getStatusCode()
        );
    }

    public function testAskingIsRefusedWhereTheFamilySwitchedConnectionsOff(): void
    {
        $this->dieter(true);
        $this->module()->setPreference(PortalApiModule::SETTING_MEMBER_CONNECTIONS, '0');

        self::assertSame(
            StatusCodeInterface::STATUS_FORBIDDEN,
            $this->connectTo('X4')->getStatusCode()
        );
    }

    /** Already a contact: said by name, and nothing new is written. */
    public function testAskingSomebodyWhoIsAlreadyAContactSaysSo(): void
    {
        $dieter = $this->dieter(true);
        $this->connect($dieter);

        $body = $this->json($this->connectTo('X4'));

        self::assertSame('already_connected', $body['status']);
        self::assertSame('Dieter Beispiel', $body['name']);
        self::assertSame(1, DB::table(Connections::TABLE)->count());
    }
}
