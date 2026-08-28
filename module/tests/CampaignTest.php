<?php

declare(strict_types=1);

namespace Engelking\Webtrees\PortalApi\Tests;

use Engelking\Webtrees\PortalApi\Http\RequestHandlers\InvitationClaimCreate;
use Engelking\Webtrees\PortalApi\PortalApiModule;
use Engelking\Webtrees\PortalApi\Services\DistributionLists;
use Engelking\Webtrees\PortalApi\Services\ExchangeOnline;
use Engelking\Webtrees\PortalApi\Services\InvitationCampaigns;
use Engelking\Webtrees\PortalApi\Services\InvitationService;
use Engelking\Webtrees\PortalApi\Services\PortalTreeService;
use Engelking\Webtrees\PortalApi\Services\TreeSearch;
use Fig\Http\Message\RequestMethodInterface;
use Fig\Http\Message\StatusCodeInterface;
use Fisharebest\Webtrees\Contracts\UserInterface;
use Fisharebest\Webtrees\DB;
use Fisharebest\Webtrees\Registry;
use Fisharebest\Webtrees\Services\EmailService;
use Fisharebest\Webtrees\Services\RateLimitService;
use Fisharebest\Webtrees\Services\UserService;
use PHPUnit\Framework\Attributes\CoversNothing;
use Psr\Http\Message\ResponseInterface;

use function hash;
use function preg_match;
use function time;
use function trim;

/** Captures what would have been sent, and sends nothing. */
class SilentMailer extends EmailService
{
    /** @var array<int,array{to:string,subject:string,text:string}> */
    public array $sent = [];

    public function send(
        UserInterface $from,
        UserInterface $to,
        UserInterface $reply_to,
        string $subject,
        string $message_text,
        string $message_html
    ): bool {
        $this->sent[] = ['to' => $to->email(), 'subject' => $subject, 'text' => $message_text];

        return true;
    }
}

/** Exchange, with the wire cut. Only the contact's name matters here. */
class NamingExchange extends ExchangeOnline
{
    /** @var array<string,string> address to the name Exchange holds for it */
    public array $names = [];

    public function configured(): bool
    {
        return true;
    }

    /** @return array<int,string> */
    public function members(string $list, string|null $token = null): array
    {
        return [];
    }

    public function recipientName(string $address): string
    {
        return $this->names[$address] ?? '';
    }
}

/**
 * Phase 15: inviting a mailing list without putting a credential in it.
 *
 * The feature exists because the obvious thing does not work. A distribution
 * list is one address that fans out to hundreds of people, so a letter to it
 * carries one link — and an invitation here is a credential naming one person.
 * What goes in the letter therefore has to be something that grants nothing,
 * and the personal invitation has to be earned by the one thing a forwarded
 * letter cannot pass on: access to one's own mailbox.
 *
 * Two promises, and every test here is about one of them.
 *
 * **The letter's link grants nothing.** Holding it does not get anybody an
 * account, an address, or an answer about who is in this family.
 *
 * **The page says the same thing whatever it found.** On a list, on no list,
 * already an account, mail server down — one body, one status. Otherwise the
 * page becomes a way of asking whether a person belongs to this family, of a
 * portal built so that nobody can ask that.
 */
#[CoversNothing]
class CampaignTest extends PortalTestCase
{
    private const string FAMILY = 'familie@example.de';

    private InvitationCampaigns $campaigns;

    private SilentMailer $mailer;

    private NamingExchange $exchange;

    /** The campaign token, kept so a test does not have to thread it through. */
    private string $token = '';

    protected function setUp(): void
    {
        parent::setUp();

        $module = $this->module();
        $module->setPreference(PortalApiModule::SETTING_PORTAL_URL, 'https://portal.example.test');
        $module->setPreference(PortalApiModule::SETTING_MAILING_LISTS, '1');
        $module->setPreference(PortalApiModule::SETTING_MAILING_LIST_ADDRESSES, self::FAMILY . ' | Familiennachrichten');

        $container      = Registry::container();
        $this->mailer   = new SilentMailer();
        $this->exchange = new NamingExchange($module);

        $lists = new DistributionLists($module, $this->exchange);

        $this->campaigns = new InvitationCampaigns(
            $module,
            $container->get(PortalTreeService::class),
            $lists,
            $this->exchange,
            $container->get(InvitationService::class),
            $container->get(TreeSearch::class),
            $container->get(UserService::class),
            $this->mailer,
        );

        $container->set(InvitationCampaigns::class, $this->campaigns);
        $container->set(
            InvitationClaimCreate::class,
            new InvitationClaimCreate($this->campaigns, $container->get(RateLimitService::class))
        );
    }

    // -----------------------------------------------------------------
    // The letter's link grants nothing
    // -----------------------------------------------------------------

    /**
     * The promise the whole design rests on. Somebody who has the letter — and
     * three hundred people have it — cannot use it to become a member.
     */
    public function testTheLinkAloneInvitesNobody(): void
    {
        $this->campaign();

        $this->claim('fremder@example.test');

        self::assertSame([], $this->mailer->sent);
        self::assertSame(0, DB::table(InvitationService::TABLE)->count());
    }

    public function testAnAddressOnTheListIsInvited(): void
    {
        $this->campaign();
        $this->onList('anna@example.test');

        $this->claim('anna@example.test');

        self::assertCount(1, $this->mailer->sent);
        self::assertSame('anna@example.test', $this->mailer->sent[0]['to']);
        self::assertSame(1, DB::table(InvitationService::TABLE)->count());
    }

    /**
     * The invitation goes to the address that was typed, and nowhere else —
     * not to whoever forwarded the letter, and not to an address supplied
     * alongside it. There is no second address in this design on purpose.
     */
    public function testTheInvitationGoesOnlyToTheAddressThatAskedForIt(): void
    {
        $this->campaign();
        $this->onList('anna@example.test');

        $this->claim('anna@example.test');

        $link = $this->mailer->sent[0]['text'];

        self::assertStringContainsString('https://portal.example.test/invitation?token=', $link);
        self::assertSame('anna@example.test', $this->mailer->sent[0]['to']);
    }

    // -----------------------------------------------------------------
    // One answer, whatever was found
    // -----------------------------------------------------------------

    /**
     * Asserted byte for byte, because "the same" is the whole promise and a
     * difference of one word would be enough to answer the question this
     * endpoint refuses to answer.
     */
    public function testTheAnswerIsIdenticalWhoeverAsks(): void
    {
        $token = $this->campaign();
        $this->onList('anna@example.test');

        $member  = $this->post($token, 'anna@example.test');
        $strange = $this->post($token, 'fremder@example.test');
        $expired = $this->post('a-token-that-was-never-issued', 'anna@example.test');

        self::assertSame(StatusCodeInterface::STATUS_ACCEPTED, $member->getStatusCode());
        self::assertSame($this->raw($member), $this->raw($strange));
        self::assertSame($this->raw($member), $this->raw($expired));
    }

    public function testACalledOffCampaignInvitesNobody(): void
    {
        $token = $this->campaign();
        $this->onList('anna@example.test');

        $this->campaigns->revoke((int) DB::table('portal_invitation_campaign')->value('id'));

        $response = $this->post($token, 'anna@example.test');

        self::assertSame(StatusCodeInterface::STATUS_ACCEPTED, $response->getStatusCode());
        self::assertSame([], $this->mailer->sent);
    }

    public function testAnExpiredCampaignInvitesNobody(): void
    {
        $token = $this->campaign();
        $this->onList('anna@example.test');

        DB::table('portal_invitation_campaign')->update(['expires_at' => time() - 1]);

        $this->claim('anna@example.test');

        self::assertSame([], $this->mailer->sent);
    }

    // -----------------------------------------------------------------
    // Somebody who is already a member
    // -----------------------------------------------------------------

    /**
     * Almost always somebody who forgot they signed up. An invitation would
     * offer them a second account; what they need is the way back into the
     * first, so they are pointed at the sign-in and no invitation is made.
     */
    public function testSomebodyWhoAlreadyHasAnAccountIsPointedAtTheDoor(): void
    {
        $anna = $this->createUser('anna', 'Anna Beispiel', 'correct-horse', UserInterface::ROLE_MEMBER, 'X1');

        $this->campaign();
        $this->onList($anna->email());

        $this->claim($anna->email());

        self::assertCount(1, $this->mailer->sent);
        self::assertStringNotContainsString('/invitation?token=', $this->mailer->sent[0]['text']);
        self::assertSame(0, DB::table(InvitationService::TABLE)->count());

        $claim = DB::table('portal_invitation_claim')->first();

        self::assertNotNull($claim);
        self::assertSame('existing', (string) $claim->outcome);
    }

    // -----------------------------------------------------------------
    // Asking twice
    // -----------------------------------------------------------------

    public function testAskingTwiceSendsOneLetter(): void
    {
        $this->campaign();
        $this->onList('anna@example.test');

        $this->claim('anna@example.test');
        $this->claim('anna@example.test');

        self::assertCount(1, $this->mailer->sent);
    }

    // -----------------------------------------------------------------
    // The archive number in the contact's name
    // -----------------------------------------------------------------

    /**
     * This family names its mail contacts `22/1a32.124 Antje Beispiel`, so the
     * contact carries the one fact that can tie an account to a record. Where
     * it reads, the account arrives linked and nobody has to do it by hand.
     */
    public function testTheArchiveNumberInTheContactNameLinksTheInvitation(): void
    {
        $number = $this->numberOf('X1');

        self::assertNotSame('', $number, 'the fixture must carry a REFN for this to mean anything');

        $this->campaign();
        $this->onList('antje@example.test');
        $this->exchange->names['antje@example.test'] = $number . ' Antje Beispiel';

        $this->claim('antje@example.test');

        $invitation = DB::table(InvitationService::TABLE)->first();

        self::assertNotNull($invitation);
        self::assertSame('X1', (string) $invitation->xref);
    }

    /**
     * And where it does not read, the invitation still goes — unlinked, like
     * every invitation issued by hand. A contact nobody numbered is not a
     * reason to leave somebody out of the family portal.
     */
    public function testAContactWithoutANumberIsStillInvited(): void
    {
        $this->campaign();
        $this->onList('opa@example.test');
        $this->exchange->names['opa@example.test'] = 'Opa Beispiel';

        $this->claim('opa@example.test');

        $invitation = DB::table(InvitationService::TABLE)->first();

        self::assertNotNull($invitation);
        self::assertNull($invitation->xref);
        self::assertCount(1, $this->mailer->sent);
    }

    // -----------------------------------------------------------------

    private function campaign(): string
    {
        $this->token = $this->campaigns->create('Frühjahr', [DistributionLists::hash(self::FAMILY)], 30, null);

        return $this->token;
    }

    /** What the snapshot of the family list would say. */
    private function onList(string $address): void
    {
        $hash = DistributionLists::hash(self::FAMILY);
        $row  = DB::table('portal_list_snapshot')->where('list_hash', '=', $hash)->first();

        $members = $row === null ? '' : (string) $row->members;
        $members = ($members === '' ? '' : $members . "\n") . hash('sha256', $address);

        if ($row === null) {
            DB::table('portal_list_snapshot')->insert([
                'list_hash'  => $hash,
                'members'    => $members,
                'fetched_at' => time(),
                'read_at'    => time(),
            ]);

            return;
        }

        DB::table('portal_list_snapshot')->where('list_hash', '=', $hash)->update(['members' => $members]);
    }

    /** The archive number the fixture gives a record, or '' if it gives none. */
    private function numberOf(string $xref): string
    {
        $gedcom = (string) DB::table('individuals')
            ->where('i_file', '=', $this->tree->id())
            ->where('i_id', '=', $xref)
            ->value('i_gedcom');

        $matches = [];

        return preg_match('/\n1 REFN (.+)/', $gedcom, $matches) === 1 ? trim($matches[1]) : '';
    }

    private function claim(string $email): void
    {
        $this->campaigns->claim($this->token, $email);
    }

    private function post(string $campaign, string $email): ResponseInterface
    {
        return $this->api(
            InvitationClaimCreate::class,
            RequestMethodInterface::METHOD_POST,
            [],
            [],
            ['campaign' => $campaign, 'email' => $email]
        );
    }
}
