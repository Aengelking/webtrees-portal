<?php

declare(strict_types=1);

namespace Engelking\Webtrees\PortalApi\Tests;

use Engelking\Webtrees\PortalApi\Http\RequestHandlers\MailingListRead;
use Engelking\Webtrees\PortalApi\Http\RequestHandlers\MailingListUpdate;
use Engelking\Webtrees\PortalApi\PortalApiModule;
use Engelking\Webtrees\PortalApi\Services\DistributionLists;
use Engelking\Webtrees\PortalApi\Services\ExchangeFailure;
use Engelking\Webtrees\PortalApi\Services\ExchangeOnline;
use Fig\Http\Message\RequestMethodInterface;
use Fig\Http\Message\StatusCodeInterface;
use Fisharebest\Webtrees\Auth;
use Fisharebest\Webtrees\Contracts\UserInterface;
use Fisharebest\Webtrees\DB;
use Fisharebest\Webtrees\Registry;
use Fisharebest\Webtrees\Services\UserService;
use Fisharebest\Webtrees\User;
use PHPUnit\Framework\Attributes\CoversNothing;
use Psr\Http\Message\ResponseInterface;

use function array_filter;
use function array_values;
use function count;
use function str_contains;
use function str_starts_with;

/**
 * Exchange, with the wire cut.
 *
 * Everything about the real connector is about somebody else's cloud —
 * tokens, cmdlet names, an undocumented beta endpoint — and none of it can be
 * asserted here. What can be asserted is the half this repository is
 * responsible for: that the member's decision is written down before anything
 * is attempted, that the attempt is made with the right address, and that a
 * cloud which refuses does not become a portal that misleads.
 */
class FakeExchange extends ExchangeOnline
{
    /** @var array<int,string> Every call, in order. */
    public array $calls = [];

    public ExchangeFailure|null $refuse = null;

    /**
     * Who Exchange thinks is on each list, by list address. The world as it
     * stands, which is not the same as what this portal has been told.
     *
     * @var array<string,array<int,string>>
     */
    public array $onList = [];

    /** Whether reading a list is refused, which must never break a screen. */
    public bool $unreadable = false;

    public function configured(): bool
    {
        return true;
    }

    /**
     * Only the calls that change something.
     *
     * Reading a list is bookkeeping the screen does on its own account, and a
     * test about what a member's switch did should not have to know when the
     * snapshot happened to be stale.
     *
     * @return array<int,string>
     */
    public function writes(): array
    {
        return array_values(array_filter(
            $this->calls,
            static fn (string $call): bool => !str_starts_with($call, 'read ')
        ));
    }

    /** @return array<int,string> */
    public function members(string $list, string|null $token = null): array
    {
        $this->calls[] = 'read ' . $list;

        if ($this->unreadable) {
            throw new ExchangeFailure('Exchange could not be reached: timeout');
        }

        return $this->onList[$list] ?? [];
    }

    public function subscribe(string $list, string $address, string $name): void
    {
        $this->calls[] = 'add ' . $list . ' ' . $address;

        if ($this->refuse !== null) {
            throw $this->refuse;
        }

        $this->onList[$list][] = $address;
    }

    public function unsubscribe(string $list, string $address): void
    {
        $this->calls[] = 'remove ' . $list . ' ' . $address;

        if ($this->refuse !== null) {
            throw $this->refuse;
        }

        $this->onList[$list] = array_values(array_filter(
            $this->onList[$list] ?? [],
            static fn (string $member): bool => $member !== $address
        ));
    }
}

/**
 * Phase 14: the family's mailing lists.
 *
 * The lists live in Exchange Online and this portal holds only the wish. That
 * split is the feature's whole design and it is what these tests are about:
 * a member's answer is taken down first and applied second, and the two are
 * allowed to disagree for a while without either of them being lost or
 * misrepresented.
 *
 * Two promises get a test of their own because breaking them would be quiet.
 * The first is that **a list's address never leaves the server** — a member
 * subscribes to *the family news*, not to an address, and putting the family's
 * distribution addresses into every browser would be a disclosure nobody asked
 * for. The second is that **an unsubscribe is a row rather than a deletion**,
 * because a withdrawal that leaves no trace is a withdrawal that cannot be
 * carried out and cannot be proved.
 */
#[CoversNothing]
class MailingListTest extends PortalTestCase
{
    private const string FAMILY = 'familie@example.de';
    private const string INVITES = 'einladungen@example.de';

    private User $anna;

    private FakeExchange $exchange;

    protected function setUp(): void
    {
        parent::setUp();

        $this->anna = $this->createUser('anna', 'Anna Beispiel', 'correct-horse', UserInterface::ROLE_MEMBER, 'X1');

        $module = $this->module();
        $module->setPreference(PortalApiModule::SETTING_MAILING_LISTS, '1');
        $module->setPreference(
            PortalApiModule::SETTING_MAILING_LIST_ADDRESSES,
            "# the family's own\n"
            . self::FAMILY . ' | Familiennachrichten | Ein- bis zweimal im Jahr.' . "\n"
            . "\n"
            . self::INVITES . ' | Einladungen'
        );

        $this->exchange = new FakeExchange($module);

        $lists = new DistributionLists($module, $this->exchange);

        $container = Registry::container();
        $container->set(DistributionLists::class, $lists);
        $container->set(MailingListRead::class, new MailingListRead($lists));
        $container->set(MailingListUpdate::class, new MailingListUpdate($lists));

        $this->login($this->anna);
    }

    // -----------------------------------------------------------------
    // What a member is offered
    // -----------------------------------------------------------------

    public function testTheListsAreOfferedWithTheirNamesAndTheAccountAddress(): void
    {
        $body = $this->json($this->read());

        self::assertTrue($body['enabled']);
        self::assertSame('anna@example.test', $body['address']);
        self::assertCount(2, $body['lists']);
        self::assertSame('Familiennachrichten', $body['lists'][0]['name']);
        self::assertSame('Ein- bis zweimal im Jahr.', $body['lists'][0]['description']);
        self::assertFalse($body['lists'][0]['subscribed']);

        // A line with no description, and the two lines that are not lists at
        // all — a comment and a blank — are not on the screen.
        self::assertSame('Einladungen', $body['lists'][1]['name']);
        self::assertSame('', $body['lists'][1]['description']);
    }

    /**
     * The promise the opaque key exists to keep.
     *
     * Asserted against the whole response rather than against a field, because
     * the failure this guards against is an address appearing somewhere a
     * structured assertion was not looking.
     */
    public function testAListsAddressNeverReachesTheBrowser(): void
    {
        $this->subscribe(self::FAMILY);

        $raw = $this->raw($this->read());

        self::assertStringNotContainsString(self::FAMILY, $raw);
        self::assertStringNotContainsString('example.de', $raw);

        // The key is the hash of the address, and it is in the payload — the
        // point is that it discloses nothing, not that it is absent.
        self::assertStringContainsString(DistributionLists::hash(self::FAMILY), $raw);
    }

    // -----------------------------------------------------------------
    // Joining and leaving
    // -----------------------------------------------------------------

    public function testJoiningRecordsTheDecisionAndTellsExchange(): void
    {
        $body = $this->json($this->subscribe(self::FAMILY));

        self::assertSame(['add ' . self::FAMILY . ' anna@example.test'], $this->exchange->writes());
        self::assertTrue($body['lists'][0]['subscribed']);
        self::assertSame('applied', $body['lists'][0]['state']);

        $row = DB::table('portal_list_subscription')->first();

        self::assertNotNull($row);
        self::assertSame($this->anna->id(), (int) $row->wt_user_id);
        self::assertSame(1, (int) $row->subscribed);
        self::assertSame('anna@example.test', (string) $row->address);
        self::assertNotNull($row->applied_at);
        self::assertNull($row->last_error);
    }

    /**
     * A "no" is a row.
     *
     * Deleting on unsubscribe would lose the two things worth keeping: the
     * instruction that still has to reach Exchange, and the fact that this
     * member was asked and answered. "Never asked" and "declined" are
     * different states and only one of them is an answer.
     */
    public function testLeavingIsRecordedRatherThanForgotten(): void
    {
        $this->subscribe(self::FAMILY);
        $this->exchange->calls = [];

        $body = $this->json($this->patch([DistributionLists::hash(self::FAMILY) => false]));

        self::assertSame(['remove ' . self::FAMILY . ' anna@example.test'], $this->exchange->writes());
        self::assertFalse($body['lists'][0]['subscribed']);

        $row = DB::table('portal_list_subscription')->first();

        self::assertNotNull($row);
        self::assertSame(0, (int) $row->subscribed);
        self::assertNotNull($row->decided_at);
    }

    public function testOnlyTheListThatMovedIsTouched(): void
    {
        $this->subscribe(self::FAMILY);
        $this->exchange->calls = [];

        $this->subscribe(self::INVITES);

        // Not "add familie" a second time. A PATCH carries the switch that
        // moved, and nothing else is re-applied on the strength of it.
        self::assertSame(['add ' . self::INVITES . ' anna@example.test'], $this->exchange->writes());
    }

    public function testAListNobodyConfiguredIsRefused(): void
    {
        $response = $this->patch(['0000000000000000000000000000000000000000000000000000000000000000' => true]);

        self::assertSame(StatusCodeInterface::STATUS_BAD_REQUEST, $response->getStatusCode());
        self::assertSame(0, DB::table('portal_list_subscription')->count());
    }

    // -----------------------------------------------------------------
    // When Exchange will not play
    // -----------------------------------------------------------------

    /**
     * The decision stands even when nobody could be told about it. This is the
     * reason the wish is written down before the call is made, rather than
     * after it succeeds.
     */
    public function testARefusalKeepsTheAnswerAndSaysItIsOnItsWay(): void
    {
        $this->exchange->refuse = new ExchangeFailure('Exchange could not be reached: timeout');

        $body = $this->json($this->subscribe(self::FAMILY));

        self::assertTrue($body['lists'][0]['subscribed']);
        self::assertSame('pending', $body['lists'][0]['state']);

        $row = DB::table('portal_list_subscription')->first();

        self::assertNotNull($row);
        self::assertNull($row->applied_at);
        self::assertSame(1, (int) $row->attempts);
    }

    public function testARefusalThatWillNotChangeStopsTryingAtOnce(): void
    {
        $this->exchange->refuse = new ExchangeFailure('Add-DistributionGroupMember failed (HTTP 403): no.', true);

        $body = $this->json($this->subscribe(self::FAMILY));

        self::assertSame('failed', $body['lists'][0]['state']);

        $row = DB::table('portal_list_subscription')->first();

        self::assertNotNull($row);
        self::assertSame(DistributionLists::MAX_ATTEMPTS, (int) $row->attempts);
    }

    /**
     * Exchange's complaint is for an administrator. It names a tenant, an
     * application registration and a cmdlet, none of which a family member can
     * act on — so it is stored, and it is not sent.
     */
    public function testExchangesOwnWordsAreNeverShownToTheMember(): void
    {
        $this->exchange->refuse = new ExchangeFailure('Add-DistributionGroupMember failed (HTTP 401): the token is expired.', true);

        $raw = $this->raw($this->subscribe(self::FAMILY));

        self::assertFalse(str_contains($raw, 'DistributionGroupMember'));
        self::assertFalse(str_contains($raw, 'expired'));
        self::assertFalse(str_contains($raw, '401'));

        $row = DB::table('portal_list_subscription')->first();

        self::assertNotNull($row);
        self::assertStringContainsString('401', (string) $row->last_error);
    }

    /**
     * An outage must not become a portal that takes ten seconds to open. A
     * refusal is held off for a few minutes before it is tried again, so
     * reading the screen twice in a row costs one attempt and not two.
     */
    public function testAFailedChangeIsNotRetriedOnEveryVisit(): void
    {
        $this->exchange->refuse = new ExchangeFailure('Exchange could not be reached: timeout');

        $this->subscribe(self::FAMILY);
        $this->exchange->calls = [];

        $this->read();
        $this->read();

        self::assertSame([], $this->exchange->writes());
    }

    public function testAnOutstandingChangeIsTriedAgainOnceTheWaitIsOver(): void
    {
        $this->exchange->refuse = new ExchangeFailure('Exchange could not be reached: timeout');

        $this->subscribe(self::FAMILY);

        $this->exchange->refuse = null;
        $this->exchange->calls  = [];

        // What the passage of time would do.
        DB::table('portal_list_subscription')->update(['attempted_at' => null]);

        $body = $this->json($this->read());

        self::assertSame(['add ' . self::FAMILY . ' anna@example.test'], $this->exchange->writes());
        self::assertSame('applied', $body['lists'][0]['state']);
    }

    // -----------------------------------------------------------------
    // The address the subscription was made under
    // -----------------------------------------------------------------

    /**
     * A member who changes their account address is subscribed under an
     * address that is no longer theirs, and nothing else in the portal would
     * ever notice. Both halves are asserted: the old one comes off before the
     * new one goes on, because doing it the other way round leaves a stranger's
     * former address on a family list whenever the second call fails.
     */
    public function testChangingTheAccountAddressMovesTheSubscription(): void
    {
        $this->subscribe(self::FAMILY);
        $this->exchange->calls = [];

        // Through the service, because `UserService::find()` caches the object
        // `Auth::user()` will hand the module — setting it on another instance
        // of the same row would change the database and not the request.
        Registry::container()->get(UserService::class)->find($this->anna->id())?->setEmail('anna.neu@example.test');

        $body = $this->json($this->read());

        self::assertSame([
            'remove ' . self::FAMILY . ' anna@example.test',
            'add ' . self::FAMILY . ' anna.neu@example.test',
        ], $this->exchange->writes());

        self::assertSame('anna.neu@example.test', $body['address']);
        self::assertSame('applied', $body['lists'][0]['state']);

        $row = DB::table('portal_list_subscription')->first();

        self::assertNotNull($row);
        self::assertSame('anna.neu@example.test', (string) $row->address);
    }

    // -----------------------------------------------------------------
    // What Exchange already says
    // -----------------------------------------------------------------

    /**
     * The reason this reads Exchange at all.
     *
     * The family's lists are older than the portal. Somebody who has never seen
     * a switch is on two of them, and being told "not subscribed" is not a
     * cautious answer — it is a wrong one, and it invites them to subscribe to
     * something they already get.
     */
    public function testAMemberWhoNeverTouchedASwitchIsShownWhatExchangeSays(): void
    {
        $this->exchange->onList = [self::FAMILY => ['anna@example.test']];

        $body = $this->json($this->read());

        self::assertTrue($body['lists'][0]['subscribed']);
        self::assertSame('applied', $body['lists'][0]['state']);

        // Nothing was decided here, so nothing is recorded as having been.
        // A row would be a consent this member never gave.
        self::assertSame(0, DB::table('portal_list_subscription')->count());
    }

    public function testSomebodyElseBeingOnTheListSaysNothingAboutMe(): void
    {
        $this->exchange->onList = [self::FAMILY => ['dieter@example.test']];

        $body = $this->json($this->read());

        self::assertFalse($body['lists'][0]['subscribed']);
    }

    /**
     * The list is read once and the answer kept, or every member opening their
     * settings would put a round trip to Exchange in front of the screen.
     */
    public function testAListIsNotReadAgainForEveryVisit(): void
    {
        $this->exchange->onList = [self::FAMILY => ['anna@example.test']];

        $this->read();
        $this->read();
        $this->read();

        $reads = array_filter($this->exchange->calls, static fn (string $call): bool => str_starts_with($call, 'read '));

        // Two lists, one read each, and then no more: one list is read per
        // request, so a cold cache warms over as many visits as there are
        // lists rather than costing them all at once.
        self::assertSame(2, count($reads));
    }

    /**
     * The bug this pairing would otherwise have. A member unsubscribes, the
     * change is applied, and the answer Exchange gave ten minutes ago still
     * says they are on the list — so the switch they just moved springs back
     * under their hand.
     */
    public function testLeavingIsNotUndoneByAStaleAnswer(): void
    {
        $this->exchange->onList = [self::FAMILY => ['anna@example.test']];

        // The first read is what puts the stale answer in place.
        self::assertTrue($this->json($this->read())['lists'][0]['subscribed']);

        $off = $this->json($this->patch([DistributionLists::hash(self::FAMILY) => false]));

        self::assertFalse($off['lists'][0]['subscribed']);

        // And still off when the screen is opened again, without waiting for
        // the answer to expire.
        self::assertFalse($this->json($this->read())['lists'][0]['subscribed']);
    }

    /**
     * The bug a live tenant found, and the reason `read_at` exists.
     *
     * A list that could not be read used to be written down as a list holding
     * nobody, and the two are indistinguishable once stored. Every member of
     * that list was then told they were not subscribed — not once, but for
     * good, because each further failed attempt only moved the timestamp along.
     *
     * The distinction under test: an attempt is recorded (so a dead Exchange is
     * not retried on every page load) and an *answer* is not.
     */
    public function testAListThatCouldNotBeReadIsNotRecordedAsEmpty(): void
    {
        $this->exchange->unreadable = true;

        $body = $this->json($this->read());

        // Falls back to the portal's own record — nothing recorded, so off —
        // rather than to an emptiness nobody established.
        self::assertFalse($body['lists'][0]['subscribed']);

        $row = DB::table('portal_list_snapshot')->first();

        self::assertNotNull($row, 'the attempt is recorded, or a dead Exchange is asked on every page load');
        self::assertNull($row->read_at, 'but not as an answer');
    }

    /**
     * And the moment it can be read, the answer arrives — the failed attempt
     * left nothing behind that has to be cleared out first.
     */
    public function testAnAnswerArrivesOnceTheListCanBeReadAgain(): void
    {
        $this->exchange->unreadable = true;
        $this->exchange->onList     = [self::FAMILY => ['anna@example.test']];

        self::assertFalse($this->json($this->read())['lists'][0]['subscribed']);

        $this->exchange->unreadable = false;

        // What the passage of time would do.
        DB::table('portal_list_snapshot')->update(['fetched_at' => 0]);

        self::assertTrue($this->json($this->read())['lists'][0]['subscribed']);
    }

    /**
     * An empty answer is still an answer, and must not be confused with the
     * absence of one. A list nobody is on says so.
     */
    public function testAListThatIsGenuinelyEmptyIsRecordedAsSuch(): void
    {
        $this->exchange->onList = [self::FAMILY => []];

        $this->read();

        $row = DB::table('portal_list_snapshot')->first();

        self::assertNotNull($row);
        self::assertNotNull($row->read_at);
        self::assertSame('', (string) $row->members);
    }

    /**
     * A list that cannot be read must cost a wrong-looking switch at worst,
     * never a screen that will not open.
     */
    public function testAnUnreadableListFallsBackToWhatThePortalRecorded(): void
    {
        $this->subscribe(self::FAMILY);

        $this->exchange->unreadable = true;

        $body = $this->json($this->read());

        self::assertTrue($body['enabled']);
        self::assertTrue($body['lists'][0]['subscribed']);
    }

    // -----------------------------------------------------------------
    // Refusals
    // -----------------------------------------------------------------

    public function testNothingIsOfferedWhereTheFamilyHasNotSwitchedThisOn(): void
    {
        $this->module()->setPreference(PortalApiModule::SETTING_MAILING_LISTS, '0');

        $body = $this->json($this->read());

        self::assertFalse($body['enabled']);
        self::assertSame([], $body['lists']);
    }

    public function testSwitchingItOffRefusesChangesButKeepsWhatWasDecided(): void
    {
        $this->subscribe(self::FAMILY);

        $this->module()->setPreference(PortalApiModule::SETTING_MAILING_LISTS, '0');

        $response = $this->patch([DistributionLists::hash(self::FAMILY) => false]);

        self::assertSame(StatusCodeInterface::STATUS_FORBIDDEN, $response->getStatusCode());

        // The answer is still there to be applied again if the family changes
        // its mind. Switching a feature off is not a reason to forget consent.
        self::assertSame(1, DB::table('portal_list_subscription')->count());
    }

    public function testSigningOutIsEnoughToBeRefused(): void
    {
        Auth::logout();

        self::assertSame(StatusCodeInterface::STATUS_UNAUTHORIZED, $this->read()->getStatusCode());
        self::assertSame(
            StatusCodeInterface::STATUS_UNAUTHORIZED,
            $this->patch([DistributionLists::hash(self::FAMILY) => true])->getStatusCode()
        );
    }

    // -----------------------------------------------------------------

    private function read(): ResponseInterface
    {
        return $this->api(MailingListRead::class);
    }

    private function subscribe(string $address): ResponseInterface
    {
        return $this->patch([DistributionLists::hash($address) => true]);
    }

    /**
     * @param array<string,bool> $lists
     */
    private function patch(array $lists): ResponseInterface
    {
        return $this->api(
            MailingListUpdate::class,
            RequestMethodInterface::METHOD_PATCH,
            [],
            [],
            ['lists' => $lists],
            $this->csrfHeader()
        );
    }
}
