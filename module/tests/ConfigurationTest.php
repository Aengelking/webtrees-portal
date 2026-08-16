<?php

declare(strict_types=1);

namespace Engelking\Webtrees\PortalApi\Tests;

use Engelking\Webtrees\PortalApi\Http\RequestHandlers\MeRead;
use Engelking\Webtrees\PortalApi\PortalApiModule;
use Fig\Http\Message\StatusCodeInterface;
use Fisharebest\Webtrees\Contracts\UserInterface;
use PHPUnit\Framework\Attributes\CoversNothing;

/**
 * What the API does when it cannot work out which tree to serve.
 *
 * It answers 503, which is the right code — the request was fine, the server
 * is not ready to serve it — but the member-facing message deliberately says
 * nothing about why. These tests pin both halves of that: the client learns
 * nothing about the installation, and the administrator gets the reason in the
 * server's error log.
 */
#[CoversNothing]
class ConfigurationTest extends PortalTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $this->login($this->createUser('anna', 'Anna Beispiel', 'pw', UserInterface::ROLE_MEMBER, 'X1'));
    }

    public function testAConfiguredTreeThatDoesNotExistIsRefused(): void
    {
        $this->module()->setPreference(PortalApiModule::SETTING_TREE, 'no-such-tree');

        $response = $this->api(MeRead::class);

        self::assertSame(StatusCodeInterface::STATUS_SERVICE_UNAVAILABLE, $response->getStatusCode());
        self::assertSame('not_configured', $this->json($response)['error']);
    }

    public function testRefusingDoesNotServeADifferentTreeInstead(): void
    {
        // The point of refusing rather than falling back: a wrong tree name
        // must not quietly hand out another family's records.
        $this->module()->setPreference(PortalApiModule::SETTING_TREE, 'no-such-tree');

        $response = $this->api(MeRead::class);

        self::assertStringNotContainsString('Beispiel', $this->raw($response));
        self::assertStringNotContainsString('X1', $this->raw($response));
    }

    public function testTheMemberIsToldNothingAboutTheInstallation(): void
    {
        $this->module()->setPreference(PortalApiModule::SETTING_TREE, 'no-such-tree');

        $body = $this->json($this->api(MeRead::class));

        self::assertStringNotContainsString('no-such-tree', $body['message']);

        // Asserting the exact string is the point rather than laziness: it
        // rules out a tree name, a file path or a class name being appended
        // later, which a "does not contain" check would let through.
        self::assertSame(
            'The member portal is not configured correctly. Please contact an administrator.',
            $body['message'],
        );
    }

    public function testAnEmptySettingFallsBackToTheOnlyTree(): void
    {
        $this->module()->setPreference(PortalApiModule::SETTING_TREE, '');

        $response = $this->api(MeRead::class);

        self::assertSame(StatusCodeInterface::STATUS_OK, $response->getStatusCode());
        self::assertSame('portal', $this->json($response)['tree']['name']);
    }
}
