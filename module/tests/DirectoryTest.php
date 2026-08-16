<?php

declare(strict_types=1);

namespace Engelking\Webtrees\PortalApi\Tests;

use Engelking\Webtrees\PortalApi\Http\RequestHandlers\MeRead;
use Engelking\Webtrees\PortalApi\Http\RequestHandlers\MemberList;
use Fig\Http\Message\StatusCodeInterface;
use Fisharebest\Webtrees\Contracts\UserInterface;
use PHPUnit\Framework\Attributes\CoversNothing;

use function array_column;

/**
 * The member directory, and /me for accounts that are not fully set up.
 */
#[CoversNothing]
class DirectoryTest extends PortalTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $anna = $this->createUser('anna', 'Anna Beispiel', 'pw', UserInterface::ROLE_MEMBER, 'X1');
        $this->createProfile($anna, true);
        $this->login($anna);

        $this->createProfile($this->createUser('dieter', 'Dieter Beispiel', 'pw', UserInterface::ROLE_MEMBER, 'X4'), true);
        $this->createProfile($this->createUser('emil', 'Emil Beispiel', 'pw', UserInterface::ROLE_MEMBER, 'X5'), true);
        $this->createProfile($this->createUser('bertha', 'Bertha Beispiel', 'pw', UserInterface::ROLE_MEMBER, 'X2'), true);
    }

    public function testTheDirectoryIsOrderedByDisplayName(): void
    {
        $body = $this->json($this->api(MemberList::class));

        self::assertSame(4, $body['total']);
        self::assertSame(
            ['Anna Beispiel', 'Bertha Beispiel', 'Dieter Beispiel', 'Emil Beispiel'],
            array_column($body['items'], 'display_name')
        );
    }

    public function testTheDirectoryCanBeSearched(): void
    {
        $body = $this->json($this->api(MemberList::class, query: ['q' => 'bert']));

        self::assertSame(1, $body['total']);
        self::assertSame('Bertha Beispiel', $body['items'][0]['display_name']);
    }

    public function testTheDirectoryPaginates(): void
    {
        $page_2 = $this->json($this->api(MemberList::class, query: ['page' => 2, 'per_page' => 2]));

        self::assertSame(4, $page_2['total']);
        self::assertSame(2, $page_2['page']);
        self::assertSame(['Dieter Beispiel', 'Emil Beispiel'], array_column($page_2['items'], 'display_name'));
    }

    public function testADisplayNameOverrideIsUsed(): void
    {
        $this->createProfile(
            $this->createUser('xaver', 'Xaver Langname-Beispiel', 'pw', UserInterface::ROLE_MEMBER),
            true,
            'Xaver B.'
        );

        $body = $this->json($this->api(MemberList::class));

        self::assertContains('Xaver B.', array_column($body['items'], 'display_name'));
        self::assertNotContains('Xaver Langname-Beispiel', array_column($body['items'], 'display_name'));
    }

    public function testAMemberWithNoLinkedRecordGetsANullIndividual(): void
    {
        $unlinked = $this->createUser('nora', 'Nora Ohnesatz', 'pw', UserInterface::ROLE_MEMBER);
        $this->createProfile($unlinked, true);

        $body  = $this->json($this->api(MemberList::class, query: ['q' => 'Nora']));

        self::assertSame(1, $body['total']);
        self::assertNull($body['items'][0]['individual']);
    }

    public function testMeWorksForAnAccountWithNoLinkedRecord(): void
    {
        $unlinked = $this->createUser('nora', 'Nora Ohnesatz', 'pw', UserInterface::ROLE_MEMBER);
        $this->login($unlinked);

        $response = $this->api(MeRead::class);
        $body     = $this->json($response);

        self::assertSame(StatusCodeInterface::STATUS_OK, $response->getStatusCode());
        self::assertNull($body['individual']);
        self::assertNull($body['profile']);
        self::assertSame('portal', $body['tree']['name']);
    }

    public function testAProfileIsReportedForTheAuthenticatedMember(): void
    {
        $body = $this->json($this->api(MeRead::class));

        self::assertTrue($body['profile']['visible_in_directory']);
        self::assertNull($body['profile']['display_name_override']);
        self::assertIsInt($body['profile']['id']);
    }
}
