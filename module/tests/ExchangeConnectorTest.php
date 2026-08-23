<?php

declare(strict_types=1);

namespace Engelking\Webtrees\PortalApi\Tests;

use Engelking\Webtrees\PortalApi\PortalApiModule;
use Engelking\Webtrees\PortalApi\Services\ExchangeFailure;
use Engelking\Webtrees\PortalApi\Services\ExchangeOnline;
use PHPUnit\Framework\Attributes\CoversNothing;

use function array_shift;
use function json_encode;
use function sort;
use function str_contains;
use function substr;

/**
 * The connector with the wire replaced by a script.
 *
 * `ExchangeOnline::send()` is the one seam: everything above it is this
 * module's reasoning about Exchange's answers, and everything below it is
 * curl. Handing it a queue of prepared answers makes that reasoning testable
 * without a tenant, which matters more here than anywhere else in the module —
 * it is the only part that cannot be tried out except in production.
 */
class ScriptedExchange extends ExchangeOnline
{
    /** @var array<int,array{0:int,1:string}> */
    public array $answers = [];

    /** @var array<int,string> Every cmdlet asked for, in order. */
    public array $asked = [];

    /**
     * @param array<int,string> $headers
     *
     * @return array{0:int,1:string}
     */
    protected function send(string $url, string $body, array $headers): array
    {
        foreach ($headers as $header) {
            if (str_contains($header, 'X-CmdletName: ')) {
                $this->asked[] = substr($header, 14);
            }
        }

        if (str_contains($url, 'login.microsoftonline.com')) {
            return [200, (string) json_encode(['access_token' => 'a-token'])];
        }

        return array_shift($this->answers) ?? [500, ''];
    }
}

/**
 * What the connector may conclude from an answer, and what it may not.
 *
 * Written after a live tenant proved the hard way that the second half of that
 * sentence had been left out. See `ExchangeFailure` for the story.
 */
#[CoversNothing]
class ExchangeConnectorTest extends PortalTestCase
{
    private const string LIST = 'familie@example.de';
    private const string MEMBER = 'anna@example.test';

    private ScriptedExchange $exchange;

    protected function setUp(): void
    {
        parent::setUp();

        $module = $this->module();
        $module->setPreference(PortalApiModule::SETTING_EXCHANGE_TENANT, 'example.onmicrosoft.com');
        $module->setPreference(PortalApiModule::SETTING_EXCHANGE_CLIENT_ID, 'an-application');
        $module->setPreference(PortalApiModule::SETTING_EXCHANGE_SECRET, 'a-secret');

        $this->exchange = new ScriptedExchange($module);
    }

    // -----------------------------------------------------------------
    // The bug a live tenant found
    // -----------------------------------------------------------------

    /**
     * An application that may not write must not be reported as having
     * written, however agreeable the list happens to look.
     *
     * This is the exact shape of the failure: the member was already on the
     * list, the add was refused for want of a role, and reading the membership
     * back said "yes, they are on it". Before this, that counted as success.
     */
    public function testARefusedWriteIsNeverExcusedByTheListLookingRight(): void
    {
        $this->exchange->answers = [
            [200, $this->recipient()],              // Get-Recipient — reading works
            [403, ''],                              // Add-DistributionGroupMember — refused
            [200, $this->membership(self::MEMBER)], // and the member is on the list anyway
        ];

        $this->expectException(ExchangeFailure::class);

        try {
            $this->exchange->subscribe(self::LIST, self::MEMBER, 'Anna Beispiel');
        } finally {
            // The read-back is not even attempted: a refusal to act says
            // nothing about the world, so there is nothing to check it against.
            self::assertSame(['Get-Recipient', 'Add-DistributionGroupMember'], $this->exchange->asked);
        }
    }

    public function testTheSameHoldsForLeavingAList(): void
    {
        $this->exchange->answers = [[403, '']];

        $this->expectException(ExchangeFailure::class);

        $this->exchange->unsubscribe(self::LIST, self::MEMBER);
    }

    /**
     * The behaviour the exclusion above must not have taken away. A refusal
     * that is *not* about permission is still checked against the membership,
     * which is how "already a member" stays a success without this module
     * having to recognise the sentence.
     */
    public function testAnOrdinaryRefusalIsStillCheckedAgainstTheList(): void
    {
        $this->exchange->answers = [
            [200, $this->recipient()],
            [400, (string) json_encode(['error' => ['message' => 'is already a member of the group']])],
            [200, $this->membership(self::MEMBER)],
        ];

        $this->exchange->subscribe(self::LIST, self::MEMBER, 'Anna Beispiel');

        self::assertSame(
            ['Get-Recipient', 'Add-DistributionGroupMember', 'Get-DistributionGroupMember'],
            $this->exchange->asked
        );
    }

    public function testAnOrdinaryRefusalStandsWhenTheListDisagrees(): void
    {
        $this->exchange->answers = [
            [200, $this->recipient()],
            [400, (string) json_encode(['error' => ['message' => 'something else entirely']])],
            [200, $this->membership('somebody.else@example.test')],
        ];

        $this->expectException(ExchangeFailure::class);

        $this->exchange->subscribe(self::LIST, self::MEMBER, 'Anna Beispiel');
    }

    // -----------------------------------------------------------------
    // What an administrator is told
    // -----------------------------------------------------------------

    /**
     * A 403 arrives with an empty body, and "failed (HTTP 403):" followed by
     * nothing reads like the message went missing rather than like Exchange
     * never sent one. It is also the one refusal whose cause is always the
     * same, so it is named.
     */
    public function testAnEmptyRefusalStillSaysSomethingUseful(): void
    {
        $this->exchange->answers = [[403, '']];

        try {
            $this->exchange->unsubscribe(self::LIST, self::MEMBER);
            self::fail('the refusal should have been reported');
        } catch (ExchangeFailure $failure) {
            self::assertStringContainsString('no message was returned', $failure->getMessage());
            self::assertStringContainsString('Entra role', $failure->getMessage());
            self::assertTrue($failure->denied);
        }
    }

    /**
     * A cloud having a bad day is not a configuration error, and the row it
     * belongs to must come back and try again rather than wait for a person.
     */
    public function testAServerErrorIsWorthAnotherAttempt(): void
    {
        $this->exchange->answers = [
            [200, $this->recipient()],
            [503, ''],
            [200, $this->membership('somebody.else@example.test')],
        ];

        try {
            $this->exchange->subscribe(self::LIST, self::MEMBER, 'Anna Beispiel');
            self::fail('the refusal should have been reported');
        } catch (ExchangeFailure $failure) {
            self::assertFalse($failure->permanent);
            self::assertFalse($failure->denied);
        }
    }

    /**
     * An address outside the tenant is not a recipient until somebody makes it
     * one, which is most of a family. The contact is created before the add,
     * and only when the address is not already known.
     */
    public function testAnUnknownAddressIsGivenAMailContactFirst(): void
    {
        $this->exchange->answers = [
            [404, (string) json_encode(['error' => ['message' => 'not found']])], // Get-Recipient
            [200, (string) json_encode(['value' => [['Name' => 'Anna Beispiel']]])], // New-MailContact
            [200, (string) json_encode(['value' => []])],                            // Set-MailContact
            [200, (string) json_encode(['value' => []])],                            // Add-…Member
        ];

        $this->exchange->subscribe(self::LIST, self::MEMBER, 'Anna Beispiel');

        self::assertSame(
            ['Get-Recipient', 'New-MailContact', 'Set-MailContact', 'Add-DistributionGroupMember'],
            $this->exchange->asked
        );
    }

    /**
     * Leaving a list does not delete the contact. It may be on another list,
     * and tidying up somebody else's directory is not this module's business.
     */
    public function testLeavingALeavesTheContactAlone(): void
    {
        $this->exchange->answers = [[200, (string) json_encode(['value' => []])]];

        $this->exchange->unsubscribe(self::LIST, self::MEMBER);

        self::assertSame(['Remove-DistributionGroupMember'], $this->exchange->asked);
    }

    // -----------------------------------------------------------------
    // Reading a list
    // -----------------------------------------------------------------

    /**
     * An address can be in any of several fields, in more than one shape, and
     * the same person can appear twice. Every string is searched for something
     * shaped like an address rather than a chosen field being trusted.
     */
    public function testEveryShapeOfAddressIsFound(): void
    {
        $this->exchange->answers = [[200, (string) json_encode(['value' => [
            ['PrimarySmtpAddress' => 'Anna@Example.test'],
            ['ExternalEmailAddress' => 'SMTP:opa@gmx.de', 'DisplayName' => 'Opa'],
            ['WindowsLiveID' => 'tante@web.de', 'Alias' => 'portal-abc'],
            // The same person under two names, and a field with no address in
            // it at all.
            ['PrimarySmtpAddress' => 'anna@example.test', 'Name' => 'Anna Beispiel'],
        ]])]];

        $members = $this->exchange->members(self::LIST);

        sort($members);

        self::assertSame(['anna@example.test', 'opa@gmx.de', 'tante@web.de'], $members);
    }

    /**
     * A real member object from a real tenant, in the shape Exchange Online
     * actually returns it.
     *
     * Everything above this is a fixture somebody wrote by hand from reading
     * the documentation, which is how NOTES §3 came to list "the address is
     * somewhere in each member object" as a guess. This one is a mail contact
     * as it came back from a live `Get-DistributionGroupMember`, trimmed of the
     * hundred-odd fields that carry no address and with the addresses changed.
     *
     * Three fields hold it and they disagree about the form: an `SMTP:` prefix
     * on two of them, a bare address on the third, and one of the two is inside
     * an array. Which is exactly why this searches every string rather than
     * trusting a chosen field — and why the same person must come back once.
     */
    public function testTheShapeALiveTenantActuallyReturns(): void
    {
        $this->exchange->answers = [[200, (string) json_encode(['value' => [[
            'Identity'                             => '22/1a32.124 Antje Beispiel',
            'Alias'                                => '22/1a32',
            'ArchiveGuid'                          => '00000000-0000-0000-0000-000000000000',
            'City'                                 => '',
            'ExternalDirectoryObjectId'            => '4c91c8c4-d7db-4e79-8e45-3ef55c50ad36',
            'EmailAddresses@odata.type'            => '#Collection(String)',
            'EmailAddresses'                       => ['SMTP:antje@gmx.example'],
            'ExternalEmailAddress'                 => 'SMTP:antje@gmx.example',
            'DisplayName'                          => '22/1a32.124 Antje Beispiel',
            'PrimarySmtpAddress'                   => 'antje@gmx.example',
            'RecipientType'                        => 'MailContact',
            'RecipientTypeDetails'                 => 'MailContact',
            // The fields that look like addresses and are not. None of them
            // may end up in the answer.
            'ObjectCategory'                       => 'DEUP281A005.PROD.OUTLOOK.COM/Configuration/Schema/Person',
            'OrganizationalUnit'                   => 'deup281a005.prod.outlook.com/Microsoft Exchange Hosted Organizations/example.onmicrosoft.com',
            'DistinguishedName'                    => 'CN=22/1a32.124 Antje Beispiel,OU=example.onmicrosoft.com,DC=DEUP281A005,DC=PROD,DC=OUTLOOK,DC=COM',
            'OriginatingServer'                    => 'DBBP281A05DC006.DEUP281A005.PROD.OUTLOOK.COM',
        ]]])]];

        // Once, not three times, and without the SMTP: prefix.
        self::assertSame(['antje@gmx.example'], $this->exchange->members(self::LIST));
    }

    public function testAnEmptyListIsAnEmptyAnswerAndNotAFailure(): void
    {
        $this->exchange->answers = [[200, (string) json_encode(['value' => []])]];

        self::assertSame([], $this->exchange->members(self::LIST));
    }

    /**
     * Members came back and this module could not find an address in any of
     * them. That is not an empty list — an empty list returns no rows — it is
     * the code failing to read an answer it was given, and the difference
     * matters: returning "no addresses" would tell every member of that list
     * they are not subscribed, with nothing anywhere saying why.
     *
     * The assumption this guards is the one NOTES §3 flags: that
     * `Get-DistributionGroupMember` puts the address somewhere in each member
     * object. If a tenant returns objects that carry only a display name, this
     * is what says so out loud instead of quietly emptying the list.
     */
    public function testMembersWithNoReadableAddressAreAFailureAndNotAnEmptyList(): void
    {
        $this->exchange->answers = [[200, (string) json_encode(['value' => [
            ['Name' => 'Anna Beispiel', 'RecipientType' => 'MailContact'],
            ['Name' => 'Opa', 'RecipientType' => 'MailContact'],
        ]])]];

        try {
            $this->exchange->members(self::LIST);
            self::fail('two unreadable members should be reported, not returned as an empty list');
        } catch (ExchangeFailure $failure) {
            self::assertStringContainsString('2 member(s)', $failure->getMessage());
            self::assertStringContainsString('not the ones this module looks in', $failure->getMessage());
        }
    }

    public function testALisThatCannotBeReadSaysSoRatherThanLookingEmpty(): void
    {
        $this->exchange->answers = [[403, '']];

        $this->expectException(ExchangeFailure::class);

        $this->exchange->members(self::LIST);
    }

    // -----------------------------------------------------------------

    private function recipient(): string
    {
        return (string) json_encode(['value' => [['PrimarySmtpAddress' => self::MEMBER]]]);
    }

    private function membership(string ...$addresses): string
    {
        $members = [];

        foreach ($addresses as $address) {
            $members[] = ['PrimarySmtpAddress' => $address];
        }

        return (string) json_encode(['value' => $members]);
    }
}
