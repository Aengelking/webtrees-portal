<?php

declare(strict_types=1);

namespace Engelking\Webtrees\PortalApi\Tests;

use Engelking\Webtrees\PortalApi\Http\RequestHandlers\ContactRead;
use Engelking\Webtrees\PortalApi\Http\RequestHandlers\ContactUpdate;
use Engelking\Webtrees\PortalApi\Http\RequestHandlers\MemberList;
use Engelking\Webtrees\PortalApi\Http\RequestHandlers\MemberRead;
use Engelking\Webtrees\PortalApi\Http\RequestHandlers\MessageCreate;
use Engelking\Webtrees\PortalApi\PortalApiModule;
use Engelking\Webtrees\PortalApi\Services\ContactDetails;
use Fig\Http\Message\RequestMethodInterface;
use Fig\Http\Message\StatusCodeInterface;
use Fisharebest\Webtrees\Contracts\UserInterface;
use Fisharebest\Webtrees\DB;
use Fisharebest\Webtrees\User;
use PHPUnit\Framework\Attributes\CoversNothing;
use Psr\Http\Message\ResponseInterface;

/**
 * Phase 9: what a member shares, and with whom.
 *
 * The fixture again does the arranging. Anna (X1) is the viewer. Dieter (X4)
 * is her brother — one step away, so "close family" under any setting. Fritz
 * (X6) is alive and connected to nobody, so he is close family to nobody, and
 * he is what makes the difference between "all members" and "close family"
 * visible.
 */
#[CoversNothing]
class ContactTest extends PortalTestCase
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
        $this->fritz_id  = $this->createProfile($this->fritz, true);

        $this->module()->setPreference(PortalApiModule::SETTING_MEMBER_CONTACT, '1');
        $this->module()->setPreference(PortalApiModule::SETTING_MEMBER_MESSAGES, '1');
        $this->module()->setPreference(PortalApiModule::SETTING_MEMBER_INVITE_STEPS, '2');

        $this->login($this->anna);
    }

    // -----------------------------------------------------------------
    // Helpers
    // -----------------------------------------------------------------

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

    private function readMember(int $id): ResponseInterface
    {
        return $this->api(MemberRead::class, attributes: ['id' => $id]);
    }

    /**
     * @return array<string,mixed>
     */
    private function contactOf(int $id): array
    {
        return $this->json($this->readMember($id))['contact'];
    }

    // -----------------------------------------------------------------
    // Who sees what
    // -----------------------------------------------------------------

    public function testAnEntryForAllMembersReachesAnyMember(): void
    {
        $this->share($this->fritz, 'email', 'fritz@example.test', ContactDetails::AUDIENCE_MEMBERS);

        // Fritz is related to Anna not at all, and she still sees it —
        // because he chose "all members", which is what that means.
        self::assertSame(['email' => 'fritz@example.test'], $this->contactOf($this->fritz_id));
    }

    public function testAnEntryForCloseFamilyReachesCloseFamily(): void
    {
        $this->share($this->dieter, 'phone', '0511 12345', ContactDetails::AUDIENCE_CLOSE_FAMILY);

        self::assertSame(['phone' => '0511 12345'], $this->contactOf($this->dieter_id));
    }

    public function testAnEntryForCloseFamilyReachesNobodyElse(): void
    {
        $this->share($this->fritz, 'phone', '0511 99999', ContactDetails::AUDIENCE_CLOSE_FAMILY);

        // Fritz is connected to nobody, so Anna is not his close family.
        self::assertSame([], $this->contactOf($this->fritz_id));
        self::assertStringNotContainsString('99999', $this->raw($this->readMember($this->fritz_id)));
    }

    public function testAnEntryForNobodyReachesNobody(): void
    {
        $this->share($this->dieter, 'address', 'Musterweg 1', ContactDetails::AUDIENCE_NOBODY);

        self::assertSame([], $this->contactOf($this->dieter_id));
    }

    /**
     * The point of choosing per entry: one member, two decisions.
     */
    public function testTheAudienceIsDecidedPerEntryAndNotPerMember(): void
    {
        $this->share($this->fritz, 'email', 'fritz@example.test', ContactDetails::AUDIENCE_MEMBERS);
        $this->share($this->fritz, 'phone', '0511 99999', ContactDetails::AUDIENCE_CLOSE_FAMILY);
        $this->share($this->fritz, 'address', 'Musterweg 1', ContactDetails::AUDIENCE_NOBODY);

        self::assertSame(['email' => 'fritz@example.test'], $this->contactOf($this->fritz_id));
    }

    /**
     * Walking the tree to decide "close family" is too expensive to do once
     * per row, so the list carries none of this at all.
     */
    public function testTheDirectoryListCarriesNoContactDetails(): void
    {
        $this->share($this->dieter, 'phone', '0511 12345', ContactDetails::AUDIENCE_MEMBERS);

        $raw = $this->raw($this->api(MemberList::class));

        self::assertStringNotContainsString('0511 12345', $raw);
        self::assertStringNotContainsString('contact', $raw);
    }

    public function testSwitchingTheFacilityOffSilencesEntriesThatAlreadyExist(): void
    {
        $this->share($this->dieter, 'phone', '0511 12345', ContactDetails::AUDIENCE_MEMBERS);
        $this->module()->setPreference(PortalApiModule::SETTING_MEMBER_CONTACT, '0');

        self::assertSame([], $this->contactOf($this->dieter_id));
    }

    /**
     * An unrecognised audience is not a reason to guess. It resolves to the
     * narrowest one there is.
     */
    public function testAnUnknownAudienceSharesNothing(): void
    {
        $this->share($this->dieter, 'phone', '0511 12345', 'everyone-on-earth');

        self::assertSame([], $this->contactOf($this->dieter_id));
    }

    // -----------------------------------------------------------------
    // Changing one's own
    // -----------------------------------------------------------------

    public function testAMemberSeesTheirOwnEntriesWhateverTheAudience(): void
    {
        $this->share($this->anna, 'address', 'Musterweg 1', ContactDetails::AUDIENCE_NOBODY);

        $body = $this->json($this->api(ContactRead::class));

        self::assertTrue($body['enabled']);
        self::assertSame('Musterweg 1', $body['contact']['address']['value']);
        self::assertSame(ContactDetails::AUDIENCE_NOBODY, $body['contact']['address']['audience']);
    }

    /**
     * Clearing the field and withdrawing consent are the same act, so the row
     * goes rather than being kept with a narrower audience. Nothing is left
     * to leak later.
     */
    public function testClearingAValueDeletesTheRow(): void
    {
        $this->share($this->anna, 'phone', '0511 12345', ContactDetails::AUDIENCE_MEMBERS);

        $this->patchContact(['phone' => ['value' => '', 'audience' => ContactDetails::AUDIENCE_MEMBERS]]);

        self::assertSame(0, DB::table(ContactDetails::TABLE)->where('wt_user_id', '=', $this->anna->id())->count());
    }

    public function testChoosingNobodyDeletesTheRowRatherThanHidingIt(): void
    {
        $this->share($this->anna, 'phone', '0511 12345', ContactDetails::AUDIENCE_MEMBERS);

        $this->patchContact(['phone' => ['value' => '0511 12345', 'audience' => ContactDetails::AUDIENCE_NOBODY]]);

        self::assertSame(0, DB::table(ContactDetails::TABLE)->where('wt_user_id', '=', $this->anna->id())->count());
    }

    public function testWithdrawalTakesEffectAtOnce(): void
    {
        $this->share($this->dieter, 'phone', '0511 12345', ContactDetails::AUDIENCE_MEMBERS);

        self::assertNotSame([], $this->contactOf($this->dieter_id));

        $this->login($this->dieter);
        $this->patchContact(['phone' => ['value' => '', 'audience' => ContactDetails::AUDIENCE_NOBODY]]);

        $this->login($this->anna);

        self::assertSame([], $this->contactOf($this->dieter_id));
    }

    public function testAKindThatWasNotSubmittedIsLeftAlone(): void
    {
        $this->share($this->anna, 'phone', '0511 12345', ContactDetails::AUDIENCE_MEMBERS);

        $this->patchContact(['email' => ['value' => 'anna@example.test', 'audience' => ContactDetails::AUDIENCE_MEMBERS]]);

        $body = $this->json($this->api(ContactRead::class));

        self::assertSame('0511 12345', $body['contact']['phone']['value']);
        self::assertSame('anna@example.test', $body['contact']['email']['value']);
    }

    public function testAKindTheModuleDoesNotKnowIsIgnored(): void
    {
        $this->patchContact(['fax' => ['value' => '0511 00000', 'audience' => ContactDetails::AUDIENCE_MEMBERS]]);

        self::assertSame(0, DB::table(ContactDetails::TABLE)->count());
    }

    /**
     * @param array<string,mixed> $contact
     */
    private function patchContact(array $contact): ResponseInterface
    {
        return $this->api(
            ContactUpdate::class,
            RequestMethodInterface::METHOD_PATCH,
            body: ['contact' => $contact],
            headers: $this->csrfHeader(),
        );
    }

    // -----------------------------------------------------------------
    // Messages
    // -----------------------------------------------------------------

    private function message(int $id, string $subject = 'Hallo', string $body = 'Wie geht es dir?'): ResponseInterface
    {
        return $this->api(
            MessageCreate::class,
            RequestMethodInterface::METHOD_POST,
            attributes: ['id' => $id],
            body: ['subject' => $subject, 'body' => $body],
            headers: $this->csrfHeader(),
        );
    }

    public function testAMessageReachesAListedMember(): void
    {
        $response = $this->message($this->dieter_id);

        self::assertSame(StatusCodeInterface::STATUS_ACCEPTED, $response->getStatusCode());
    }

    /**
     * Staying out of the directory means being unreachable, and it is
     * reported as "not found" — the same answer as an id that never existed,
     * so this is not a way to discover who has an account.
     */
    public function testAMemberWhoStayedOutOfTheDirectoryCannotBeWrittenTo(): void
    {
        $hidden = $this->createUser('heidi', 'Heidi Heimlich', 'viertes-pferd', UserInterface::ROLE_MEMBER);
        $id     = $this->createProfile($hidden, false);

        $unlisted = $this->raw($this->message($id));
        $unknown  = $this->raw($this->message(99999));

        self::assertSame($unlisted, $unknown);
    }

    public function testSwitchingMessagesOffRefusesThem(): void
    {
        $this->module()->setPreference(PortalApiModule::SETTING_MEMBER_MESSAGES, '0');

        $response = $this->message($this->dieter_id);

        self::assertSame(StatusCodeInterface::STATUS_FORBIDDEN, $response->getStatusCode());
        self::assertSame('not_allowed', $this->json($response)['error']);
    }

    public function testAnEmptyMessageIsRefused(): void
    {
        self::assertSame(
            StatusCodeInterface::STATUS_BAD_REQUEST,
            $this->message($this->dieter_id, 'Hallo', '   ')->getStatusCode()
        );
    }

    public function testAMemberCannotWriteToThemselves(): void
    {
        $mine = DB::table(\Engelking\Webtrees\PortalApi\Services\MemberService::TABLE)
            ->where('wt_user_id', '=', $this->anna->id())
            ->value('id');

        self::assertSame(
            StatusCodeInterface::STATUS_BAD_REQUEST,
            $this->message((int) $mine)->getStatusCode()
        );
    }

    /**
     * A real bug, found by these tests rather than by a member.
     *
     * `deliverMessage()` opens with `I18N::init()` on the *recipient's*
     * language preference, and `Locale::create('')` throws. An account whose
     * language was never set — which is any account made by hand in webtrees
     * rather than by invitation — could therefore not be written to at all.
     */
    public function testAMemberWithNoLanguagePreferenceCanStillBeWrittenTo(): void
    {
        $this->dieter->setPreference(UserInterface::PREF_LANGUAGE, '');

        self::assertSame(StatusCodeInterface::STATUS_ACCEPTED, $this->message($this->dieter_id)->getStatusCode());
    }

    /**
     * The second bug of the same shape, and the worse of the two.
     *
     * `deliverMessage()` files an internal copy only for a `contactmethod` it
     * recognises and emails only for an emailing one. A preference that was
     * never set is in neither list, so the message was stored nowhere and
     * sent to nobody — and the call still returned `true`, so the sender was
     * told it had been delivered.
     *
     * This test was green before the fix, for the wrong reason: nothing was
     * sent, so nothing could fail. It now checks the copy rather than the
     * status code.
     */
    public function testAMemberWhoNeverChoseAContactMethodStillGetsTheirCopy(): void
    {
        $this->dieter->setPreference(UserInterface::PREF_CONTACT_METHOD, '');

        self::assertSame(StatusCodeInterface::STATUS_ACCEPTED, $this->message($this->dieter_id)->getStatusCode());
        self::assertSame(1, DB::table('message')->where('user_id', '=', $this->dieter->id())->count());
    }

    public function testTheDailyLimitIsEnforced(): void
    {
        $this->module()->setPreference(PortalApiModule::SETTING_MESSAGE_LIMIT, '1');

        self::assertSame(StatusCodeInterface::STATUS_ACCEPTED, $this->message($this->dieter_id)->getStatusCode());

        $response = $this->message($this->dieter_id);

        self::assertSame(StatusCodeInterface::STATUS_CONFLICT, $response->getStatusCode());
        self::assertSame('quota_reached', $this->json($response)['error']);
    }

    // -----------------------------------------------------------------
    // An address is four answers
    // -----------------------------------------------------------------

    /**
     * The whole point of fields: what the member typed comes back in the
     * boxes they typed it into, and the text is assembled from them rather
     * than being a second version of the address that could disagree.
     */
    public function testAnAddressIsKeptAsFieldsAndAsText(): void
    {
        $this->patchContact([
            'address' => [
                'parts'    => ['street' => 'Musterstraße 12', 'postcode' => '29223', 'city' => 'Celle', 'country' => 'Deutschland'],
                'audience' => ContactDetails::AUDIENCE_MEMBERS,
            ],
        ]);

        $address = $this->json($this->api(ContactRead::class))['contact']['address'];

        self::assertSame(
            ['street' => 'Musterstraße 12', 'postcode' => '29223', 'city' => 'Celle', 'country' => 'Deutschland'],
            $address['parts']
        );
        self::assertSame("Musterstraße 12\n29223 Celle\nDeutschland", $address['value']);
    }

    /** A reader gets an address to read, not four fields to reassemble. */
    public function testAReaderGetsTheAddressAsOnePieceOfText(): void
    {
        $this->login($this->dieter);
        $this->patchContact([
            'address' => [
                'parts'    => ['street' => 'Musterstraße 12', 'postcode' => '29223', 'city' => 'Celle', 'country' => ''],
                'audience' => ContactDetails::AUDIENCE_MEMBERS,
            ],
        ]);

        $this->login($this->anna);

        self::assertSame("Musterstraße 12\n29223 Celle", $this->contactOf($this->dieter_id)['address']);
    }

    /**
     * An empty field takes its line with it. A member who does not write down
     * their own country should not have an address with a blank last line.
     */
    public function testAFieldLeftEmptyLeavesNoGap(): void
    {
        $this->patchContact([
            'address' => [
                'parts'    => ['street' => '', 'postcode' => '29223', 'city' => 'Celle', 'country' => ''],
                'audience' => ContactDetails::AUDIENCE_MEMBERS,
            ],
        ]);

        self::assertSame('29223 Celle', $this->json($this->api(ContactRead::class))['contact']['address']['value']);
    }

    /**
     * Clearing every field is the same act as clearing the box was: the
     * address is being withdrawn, so the row goes.
     */
    public function testAnAddressEmptiedFieldByFieldIsWithdrawn(): void
    {
        $this->share($this->anna, 'address', 'Musterweg 1', ContactDetails::AUDIENCE_MEMBERS);

        $this->patchContact([
            'address' => [
                'parts'    => ['street' => '', 'postcode' => '', 'city' => '', 'country' => ''],
                'audience' => ContactDetails::AUDIENCE_MEMBERS,
            ],
        ]);

        self::assertSame(0, DB::table(ContactDetails::TABLE)->where('wt_user_id', '=', $this->anna->id())->count());
    }

    /**
     * The rows that were there before addresses had fields — and the ones a
     * client that only knows the box will keep writing. The text is left
     * exactly as it was; the fields are the best reading of it, which the
     * member's next save replaces with the truth.
     */
    public function testAnAddressThatWasTypedAsOneLineStillFillsInTheFields(): void
    {
        $this->share($this->anna, 'address', 'Musterweg 1, 29223 Celle, Deutschland', ContactDetails::AUDIENCE_MEMBERS);

        $address = $this->json($this->api(ContactRead::class))['contact']['address'];

        self::assertSame('Musterweg 1, 29223 Celle, Deutschland', $address['value']);
        self::assertSame(
            ['street' => 'Musterweg 1', 'postcode' => '29223', 'city' => 'Celle', 'country' => 'Deutschland'],
            $address['parts']
        );
    }

    /**
     * Nothing may be dropped on the way into the fields, because the member's
     * next save would then delete it. A line that fits nowhere stays with the
     * street, which is where a "c/o" belongs anyway.
     */
    public function testReadingAnAddressIntoFieldsLosesNothing(): void
    {
        $this->share($this->anna, 'address', "c/o Meier\nMusterweg 1\n29223 Celle\nDeutschland", ContactDetails::AUDIENCE_MEMBERS);

        $parts = $this->json($this->api(ContactRead::class))['contact']['address']['parts'];

        self::assertSame('c/o Meier, Musterweg 1', $parts['street']);
        self::assertSame('29223', $parts['postcode']);
        self::assertSame('Celle', $parts['city']);
        self::assertSame('Deutschland', $parts['country']);
    }

    /**
     * A save from a plain box is still a save: the fields it implies are the
     * ones read back out of it, not the ones somebody typed last week.
     */
    public function testSavingFromABoxDoesNotLeaveTheOldFieldsBehind(): void
    {
        $this->patchContact([
            'address' => [
                'parts'    => ['street' => 'Musterstraße 12', 'postcode' => '29223', 'city' => 'Celle', 'country' => 'Deutschland'],
                'audience' => ContactDetails::AUDIENCE_MEMBERS,
            ],
        ]);

        $this->patchContact([
            'address' => ['value' => 'Am Markt 3, 21335 Lüneburg', 'audience' => ContactDetails::AUDIENCE_MEMBERS],
        ]);

        $address = $this->json($this->api(ContactRead::class))['contact']['address'];

        self::assertSame('Am Markt 3, 21335 Lüneburg', $address['value']);
        self::assertSame('Am Markt 3', $address['parts']['street']);
        self::assertSame('Lüneburg', $address['parts']['city']);
    }

    /**
     * An e-mail address and a telephone number are one value each. Inventing
     * structure for them would be structure nobody asked for.
     */
    public function testOnlyTheAddressHasFields(): void
    {
        $this->share($this->anna, 'email', 'anna@example.test', ContactDetails::AUDIENCE_MEMBERS);
        $this->share($this->anna, 'phone', '0511 12345', ContactDetails::AUDIENCE_MEMBERS);

        $contact = $this->json($this->api(ContactRead::class))['contact'];

        self::assertArrayNotHasKey('parts', $contact['email']);
        self::assertArrayNotHasKey('parts', $contact['phone']);
    }

    /** A kind that is not an object at all is a bad request, not a 500. */
    public function testAKindThatIsNotAnObjectIsRefused(): void
    {
        $response = $this->api(
            ContactUpdate::class,
            RequestMethodInterface::METHOD_PATCH,
            body: ['contact' => ['email' => 'anna@example.test']],
            headers: $this->csrfHeader(),
        );

        self::assertSame(StatusCodeInterface::STATUS_BAD_REQUEST, $response->getStatusCode());
    }
}
