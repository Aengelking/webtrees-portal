<?php

declare(strict_types=1);

namespace Engelking\Webtrees\PortalApi\Tests;

use Engelking\Webtrees\PortalApi\Http\RequestHandlers\AccessRequestCreate;
use Engelking\Webtrees\PortalApi\PortalApiModule;
use Engelking\Webtrees\PortalApi\Services\AccessRequests;
use Engelking\Webtrees\PortalApi\Services\InvitationService;
use Fig\Http\Message\RequestMethodInterface;
use Fig\Http\Message\StatusCodeInterface;
use Fisharebest\Webtrees\Auth;
use Fisharebest\Webtrees\Contracts\UserInterface;
use Fisharebest\Webtrees\DB;
use Fisharebest\Webtrees\Individual;
use Fisharebest\Webtrees\Registry;
use PHPUnit\Framework\Attributes\CoversNothing;
use Psr\Http\Message\ResponseInterface;

use function time;
use function view;

/**
 * Asking for a way in, for the reader no mailing list holds.
 *
 * The campaign (`CampaignTest`) can answer by itself because being on the
 * family's list settles the only hard question. A notice in the family
 * magazine reaches further than the list does, and for those readers the
 * campaign page is a dead end on purpose — an address on no list gets the same
 * silence as one that was never family.
 *
 * So there is a form, and everything here is about the two promises it makes.
 *
 * **It decides nothing.** No account, no invitation, no email: a line in a
 * queue that an administrator reads. §1.3's rule — nobody gets in who was not
 * decided on by a person — is the whole reason this feature is shaped like
 * this rather than like a registration form.
 *
 * **It answers the same thing whatever it made of the request.** Written down,
 * ignored for want of an address, rate-limited: one body, one status. The form
 * asks for an archive number, which the magazine prints beside every name, and
 * a form that said "that number is not one of ours" would be a way of reading
 * the family's index from outside.
 */
#[CoversNothing]
class AccessRequestTest extends PortalTestCase
{
    private AccessRequests $requests;

    protected function setUp(): void
    {
        parent::setUp();

        $this->module()->setPreference(PortalApiModule::SETTING_PORTAL_URL, 'https://portal.example.test');

        $this->requests = Registry::container()->get(AccessRequests::class);

        // Nobody is signed in. This is a page reached from a magazine.
        Auth::logout();
    }

    // -----------------------------------------------------------------
    // Helpers
    // -----------------------------------------------------------------

    /**
     * @param array<string,mixed> $overrides
     */
    private function ask(array $overrides = []): ResponseInterface
    {
        return $this->api(
            AccessRequestCreate::class,
            RequestMethodInterface::METHOD_POST,
            body: $overrides + [
                'name'      => 'Antje Beispiel',
                'email'     => 'antje@example.test',
                'reference' => '',
                'note'      => '',
            ],
            headers: $this->csrfHeader(),
        );
    }

    /**
     * @return array<int,array<string,mixed>>
     */
    private function open(): array
    {
        return $this->requests->open($this->tree);
    }

    // -----------------------------------------------------------------
    // What it does, and what it deliberately does not
    // -----------------------------------------------------------------

    public function testARequestIsWrittenDownAndNothingElseHappens(): void
    {
        $response = $this->ask(['note' => 'Meine Großmutter war Bertha.']);

        self::assertSame(StatusCodeInterface::STATUS_ACCEPTED, $response->getStatusCode());
        self::assertSame('received', $this->json($response)['status']);

        $open = $this->open();

        self::assertCount(1, $open);
        self::assertSame('Antje Beispiel', $open[0]['name']);
        self::assertSame('antje@example.test', $open[0]['email']);
        self::assertSame('Meine Großmutter war Bertha.', $open[0]['note']);

        // The two things that must not have happened.
        self::assertSame(0, DB::table(InvitationService::TABLE)->count());
        self::assertNull($this->userService()->findByEmail('antje@example.test'));
    }

    /**
     * The single most important assertion here. The form asks for an archive
     * number — the family magazine prints one beside every name — so an answer
     * that varied would let anybody holding a copy read the family's index by
     * typing numbers into it.
     */
    public function testTheAnswerIsTheSameWhateverWasSent(): void
    {
        $complete = $this->raw($this->ask(['reference' => '4711']));
        $unknown  = $this->raw($this->ask(['email' => 'zzz@example.test', 'reference' => '999999']));
        $useless  = $this->raw($this->ask(['email' => '', 'name' => '']));

        self::assertSame($complete, $unknown);
        self::assertSame($complete, $useless);
    }

    public function testARequestWithNothingToActOnIsNotWrittenDown(): void
    {
        // No address to answer at, and no name to answer to. Accepted in the
        // reply, dropped on the floor: an administrator cannot act on either.
        self::assertSame(StatusCodeInterface::STATUS_ACCEPTED, $this->ask(['email' => ''])->getStatusCode());
        self::assertSame(StatusCodeInterface::STATUS_ACCEPTED, $this->ask(['name' => ''])->getStatusCode());
        self::assertSame(StatusCodeInterface::STATUS_ACCEPTED, $this->ask(['email' => 'not-an-address'])->getStatusCode());

        self::assertSame([], $this->open());
    }

    /**
     * Somebody correcting their own typing, or pressing the button twice, is
     * one person waiting — not two entries for whoever reads the queue.
     */
    public function testAskingAgainLeavesOneEntry(): void
    {
        $this->ask(['name' => 'Antje Beispil']);
        $this->ask(['name' => 'Antje Beispiel']);

        $open = $this->open();

        self::assertCount(1, $open);
        self::assertSame('Antje Beispiel', $open[0]['name'], 'The correction did not replace the typo.');
    }

    public function testTheFormNeedsNoSessionAndNoAccount(): void
    {
        self::assertFalse(Auth::check());
        self::assertSame(StatusCodeInterface::STATUS_ACCEPTED, $this->ask()->getStatusCode());
    }

    public function testAnUnsafeRequestStillNeedsACsrfToken(): void
    {
        $response = $this->api(
            AccessRequestCreate::class,
            RequestMethodInterface::METHOD_POST,
            body: ['name' => 'Antje Beispiel', 'email' => 'antje@example.test'],
        );

        self::assertSame(StatusCodeInterface::STATUS_FORBIDDEN, $response->getStatusCode());
        self::assertSame([], $this->open());
    }

    // -----------------------------------------------------------------
    // What the administrator is shown
    // -----------------------------------------------------------------

    /**
     * The number does the linking, by the same rule the campaign uses: it
     * answers only where it names exactly one record.
     */
    public function testAnArchiveNumberNamesTheRecordItPointsAt(): void
    {
        $this->ask(['reference' => '4711']);

        $open = $this->open();

        self::assertInstanceOf(Individual::class, $open[0]['individual']);
        self::assertSame('X1', $open[0]['individual']->xref());
    }

    /** A number written into the name field is the same information. */
    public function testANumberInFrontOfTheNameIsReadAsTheNumber(): void
    {
        $this->ask(['name' => '4716 Fritz Beispiel']);

        $open = $this->open();

        self::assertSame('4716', $open[0]['reference']);
        self::assertSame('Fritz Beispiel', $open[0]['person']);
        self::assertInstanceOf(Individual::class, $open[0]['individual']);
        self::assertSame('X6', $open[0]['individual']->xref());
    }

    public function testANumberThatNamesNobodyNamesNobody(): void
    {
        $this->ask(['reference' => '999999']);

        $open = $this->open();

        self::assertSame('999999', $open[0]['reference']);
        self::assertNull($open[0]['individual'], 'A number nobody carries produced a record anyway.');
    }

    public function testNothingIsGuessedFromANameAlone(): void
    {
        // The tree holds an Anna Beispiel. The queue must not decide that this
        // is her: a name is not an identifier, which is why the number exists.
        $this->ask(['name' => 'Anna Beispiel']);

        self::assertNull($this->open()[0]['individual']);
    }

    // -----------------------------------------------------------------
    // Answering one
    // -----------------------------------------------------------------

    public function testInvitingFromARequestClosesItAndIssuesAnInvitation(): void
    {
        $this->ask(['reference' => '4711']);

        $id = $this->open()[0]['id'];

        $this->signInAsAdministrator();
        $this->requests->close($id, 'invited', Auth::user());

        self::assertSame([], $this->open());

        $handled = $this->requests->handled($this->tree);

        self::assertCount(1, $handled);
        self::assertSame('invited', $handled[0]['outcome']);
        self::assertSame(Auth::id(), $handled[0]['handled_by']);
    }

    /**
     * Putting one aside says nothing to anybody, and that is deliberate: a
     * refusal would confirm that this address reached the family, which is
     * what the form is careful never to say.
     */
    public function testPuttingOneAsideAnswersNobody(): void
    {
        $this->ask();

        $this->requests->close($this->open()[0]['id'], 'declined', null);

        self::assertSame([], $this->open());
        self::assertSame('declined', $this->requests->handled($this->tree)[0]['outcome']);
        self::assertSame(0, DB::table(InvitationService::TABLE)->count());
    }

    public function testAnAnsweredRequestIsForgottenEventually(): void
    {
        $this->ask();
        $this->requests->close($this->open()[0]['id'], 'declined', null);

        DB::table('portal_access_request')
            ->update(['handled_at' => time() - (AccessRequests::RETAIN_DAYS + 1) * 86400]);

        $this->requests->prune();

        self::assertSame(0, DB::table('portal_access_request')->count());
    }

    /** An unanswered one is kept, however long it has waited. */
    public function testAnUnansweredRequestIsNeverPrunedAway(): void
    {
        $this->ask();

        DB::table('portal_access_request')
            ->update(['created_at' => time() - 3650 * 86400, 'updated_at' => time() - 3650 * 86400]);

        $this->requests->prune();

        self::assertCount(1, $this->open());
    }

    public function testTheOpenCountIsWhatTheSettingsScreenShows(): void
    {
        self::assertSame(0, $this->requests->openCount());

        $this->ask();
        $this->ask(['email' => 'jemand@example.test']);

        self::assertSame(2, $this->requests->openCount());

        $this->requests->close($this->open()[0]['id'], 'invited', null);

        self::assertSame(1, $this->requests->openCount());
    }

    // -----------------------------------------------------------------
    // The screens
    // -----------------------------------------------------------------

    public function testTheAdministratorsScreenRenders(): void
    {
        $this->ask(['reference' => '4711', 'note' => 'Über Bertha.']);
        $this->signInAsAdministrator();

        $html = view('_portal_api_::access-requests', [
            'title'        => 'Requests for access',
            'module'       => $this->module(),
            'tree'         => $this->tree,
            'open'         => $this->open(),
            'handled'      => $this->requests->handled($this->tree),
            'issuers'      => [],
            'new_link'     => '',
            'form_url'     => 'https://portal.example.test/zugang',
            'form_qr'      => '',
            'settings_url' => '#',
        ]);

        self::assertStringContainsString('Antje Beispiel', $html);
        self::assertStringContainsString('antje@example.test', $html);
        self::assertStringContainsString('https://portal.example.test/zugang', $html);
    }

    private function signInAsAdministrator(): void
    {
        $administrator = $this->createUser('chefin', 'Die Chefin', 'geheim', UserInterface::ROLE_MANAGER);
        $administrator->setPreference(UserInterface::PREF_IS_ADMINISTRATOR, '1');

        $this->login($administrator);
    }

    private function userService(): \Fisharebest\Webtrees\Services\UserService
    {
        return Registry::container()->get(\Fisharebest\Webtrees\Services\UserService::class);
    }
}
