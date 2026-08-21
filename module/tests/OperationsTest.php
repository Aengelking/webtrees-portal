<?php

declare(strict_types=1);

namespace Engelking\Webtrees\PortalApi\Tests;

use Engelking\Webtrees\PortalApi\Http\ApiException;
use Engelking\Webtrees\PortalApi\Http\Middleware\ApiEnvelope;
use Engelking\Webtrees\PortalApi\Http\RequestHandlers\HealthRead;
use Engelking\Webtrees\PortalApi\Http\RequestHandlers\IndividualRead;
use Engelking\Webtrees\PortalApi\Http\RequestHandlers\MeRead;
use Engelking\Webtrees\PortalApi\PortalApiModule;
use Engelking\Webtrees\PortalApi\Services\Diagnosis;
use Engelking\Webtrees\PortalApi\Services\DiagnosisCheck;
use Engelking\Webtrees\PortalApi\Services\ErrorLog;
use Fig\Http\Message\StatusCodeInterface;
use Fisharebest\Webtrees\Contracts\UserInterface;
use Fisharebest\Webtrees\DB;
use Fisharebest\Webtrees\Registry;
use Fisharebest\Webtrees\Site;
use Illuminate\Support\Collection;
use PHPUnit\Framework\Attributes\CoversNothing;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;
use RuntimeException;

use function json_decode;
use function time;

use const JSON_THROW_ON_ERROR;

/**
 * Phase 6: knowing that the portal is broken.
 *
 * Everything here exists because of one property of the design: the module is
 * built so that it can never take webtrees down with it. `boot()` swallows
 * whatever it throws, and `ApiEnvelope` turns every unhandled exception into
 * a polite "please try again later". Both are right, and together they mean a
 * portal can be broken for one member for weeks with nothing anywhere looking
 * wrong.
 */
#[CoversNothing]
class OperationsTest extends PortalTestCase
{
    private ErrorLog $errors;

    protected function setUp(): void
    {
        parent::setUp();

        $this->errors = new ErrorLog();
    }

    // -----------------------------------------------------------------
    // Helpers
    // -----------------------------------------------------------------

    /**
     * Send a request through `ApiEnvelope` alone, wrapped around a handler
     * that fails.
     *
     * Going through a real route would be closer to life, but no handler in
     * the module throws on demand — which is the point of them. This is the
     * one place where building the middleware by hand says more than routing
     * would: what is under test is the envelope's behaviour when something
     * beneath it goes wrong, and the something is deliberately arbitrary.
     */
    private function dispatchFailing(\Throwable $failure, string $route_name = MeRead::class): ResponseInterface
    {
        $route   = Registry::routeFactory()->routeMap()->getRoute($route_name);
        $request = self::createRequest()
            ->withAttribute('route', $route)
            ->withAttribute('client-ip', '203.0.113.7');

        $handler = new class ($failure) implements RequestHandlerInterface {
            public function __construct(private readonly \Throwable $failure)
            {
            }

            public function handle(ServerRequestInterface $request): ResponseInterface
            {
                throw $this->failure;
            }
        };

        return (new ApiEnvelope($this->errors))->process($request, $handler);
    }

    /**
     * @return array<string,mixed>
     */
    private function body(ResponseInterface $response): array
    {
        $response->getBody()->rewind();

        return json_decode($response->getBody()->getContents(), true, 32, JSON_THROW_ON_ERROR);
    }

    /**
     * @return Collection<int,DiagnosisCheck>
     */
    private function diagnose(): Collection
    {
        return Registry::container()->get(Diagnosis::class)->run();
    }

    private function check(string $key): DiagnosisCheck
    {
        $check = $this->diagnose()->first(static fn (DiagnosisCheck $c): bool => $c->key === $key);

        self::assertInstanceOf(DiagnosisCheck::class, $check, 'There is no check called "' . $key . '".');

        return $check;
    }

    // -----------------------------------------------------------------
    // Recording a failure
    // -----------------------------------------------------------------

    public function testAnUnhandledFailureIsRecordedAndGivenAReference(): void
    {
        $response = $this->dispatchFailing(new RuntimeException('the database went away'));
        $body     = $this->body($response);

        self::assertSame(StatusCodeInterface::STATUS_INTERNAL_SERVER_ERROR, $response->getStatusCode());
        self::assertSame('server_error', $body['error']);
        self::assertArrayHasKey('reference', $body);
        self::assertNotSame('', $body['reference']);

        $row = DB::table(ErrorLog::TABLE)->where('reference', '=', $body['reference'])->first();

        self::assertNotNull($row, 'The reference shown to the member matches no recorded error.');
        self::assertSame('RuntimeException', $row->error_class);
        self::assertSame('the database went away', $row->message);
        self::assertSame('MeRead', $row->route);
    }

    /**
     * The member is told nothing about what broke. The reference is not an
     * exception to that — it is a number, and it means nothing without the
     * control panel.
     */
    public function testTheMemberIsStillToldNothingAboutTheFailure(): void
    {
        $raw = $this->dispatchFailing(new RuntimeException('SQLSTATE[HY000]: /var/www/secret/config.ini.php'));

        $raw->getBody()->rewind();
        $text = $raw->getBody()->getContents();

        self::assertStringNotContainsString('SQLSTATE', $text);
        self::assertStringNotContainsString('config.ini.php', $text);
    }

    /**
     * The route's *name* is a handler class. The request path is not
     * recorded, because `/individuals/X123` names a record and an error log
     * is not a place to accumulate those.
     */
    public function testTheRecordDoesNotNameWhatWasAskedFor(): void
    {
        $this->dispatchFailing(new RuntimeException('boom'), IndividualRead::class);

        $row = DB::table(ErrorLog::TABLE)->first();

        self::assertNotNull($row);
        self::assertSame('IndividualRead', $row->route);

        foreach ((array) $row as $column => $value) {
            self::assertStringNotContainsString('/api/v1', (string) $value, 'The request path is recorded in ' . $column . '.');
        }
    }

    /**
     * A 404 for a record somebody may not see is ordinary traffic. Recording
     * those would bury the handful of rows that are actually bugs.
     */
    public function testARefusalTheModuleMeantToGiveIsNotRecorded(): void
    {
        $response = $this->dispatchFailing(ApiException::notFound());

        self::assertSame(StatusCodeInterface::STATUS_NOT_FOUND, $response->getStatusCode());
        self::assertArrayNotHasKey('reference', $this->body($response));
        self::assertSame(0, DB::table(ErrorLog::TABLE)->count());
    }

    /**
     * Including the 503. An uptime monitor polling a half-configured
     * installation would otherwise add a row every minute, for a condition
     * the diagnosis screen already reports better.
     */
    public function testAMisconfiguredPortalDoesNotFillTheErrorLog(): void
    {
        $this->module()->setPreference(PortalApiModule::SETTING_TREE, 'no-such-tree');

        $this->api(HealthRead::class);
        $this->api(HealthRead::class);

        self::assertSame(0, DB::table(ErrorLog::TABLE)->count());
    }

    public function testOldEntriesArePruned(): void
    {
        $this->dispatchFailing(new RuntimeException('recent'));
        $this->dispatchFailing(new RuntimeException('ancient'));

        DB::table(ErrorLog::TABLE)
            ->where('message', '=', 'ancient')
            ->update(['occurred_at' => time() - 365 * 86400]);

        $this->errors->prune();

        self::assertSame(1, DB::table(ErrorLog::TABLE)->count());
        self::assertSame('recent', DB::table(ErrorLog::TABLE)->value('message'));
    }

    // -----------------------------------------------------------------
    // The health endpoint
    // -----------------------------------------------------------------

    public function testHealthAnswersWithTheVersionThatIsRunning(): void
    {
        $response = $this->api(HealthRead::class);
        $body     = $this->json($response);

        self::assertSame(StatusCodeInterface::STATUS_OK, $response->getStatusCode());
        self::assertSame('ok', $body['status']);
        self::assertSame(PortalApiModule::CUSTOM_VERSION, $body['version']);
        self::assertSame($this->module()->schemaVersion(), $body['schema_version']);
    }

    /**
     * Dull on purpose. This endpoint needs no credentials, so its payload has
     * to be worth nothing to whoever finds it.
     */
    public function testHealthDisclosesNothingAboutTheFamily(): void
    {
        $raw = $this->raw($this->api(HealthRead::class));

        self::assertStringNotContainsString('Beispiel', $raw);
        self::assertStringNotContainsString('portal', $raw, 'The tree name is in the health payload.');
        self::assertStringNotContainsString('X1', $raw);
    }

    public function testHealthIsStillBehindTheProxySecret(): void
    {
        $this->module()->setPreference(PortalApiModule::SETTING_PROXY_SECRET, 'shared-secret');

        self::assertSame(
            StatusCodeInterface::STATUS_FORBIDDEN,
            $this->api(HealthRead::class)->getStatusCode()
        );

        self::assertSame(
            StatusCodeInterface::STATUS_OK,
            $this->api(HealthRead::class, headers: ['X-Portal-Proxy-Secret' => 'shared-secret'])->getStatusCode()
        );
    }

    public function testHealthFailsWhenNoTreeCanBeServed(): void
    {
        $this->module()->setPreference(PortalApiModule::SETTING_TREE, 'no-such-tree');

        self::assertSame(
            StatusCodeInterface::STATUS_SERVICE_UNAVAILABLE,
            $this->api(HealthRead::class)->getStatusCode()
        );
    }

    // -----------------------------------------------------------------
    // The diagnosis screen
    // -----------------------------------------------------------------

    public function testAHealthyInstallationDiagnosesAsHealthy(): void
    {
        $this->module()->setPreference(PortalApiModule::SETTING_PORTAL_URL, 'https://portal.example.test');
        $this->module()->setPreference(PortalApiModule::SETTING_PROXY_SECRET, 'shared-secret');
        // Left at webtrees' own default ("no limit"), this is reported as
        // worth a look — correctly, because it means every member sees every
        // living person. A healthy installation has made a choice about it.
        $this->module()->setPreference(PortalApiModule::SETTING_MEMBER_PATH_LENGTH, '2');
        Site::setPreference('USE_REGISTRATION_MODULE', '0');

        $diagnosis = Registry::container()->get(Diagnosis::class);
        $checks    = $diagnosis->run();

        self::assertSame(
            Diagnosis::OK,
            $diagnosis->worst($checks),
            'Unexpected: ' . $checks
                ->reject(static fn (DiagnosisCheck $c): bool => $c->status === Diagnosis::OK)
                ->map(static fn (DiagnosisCheck $c): string => $c->key . '=' . $c->detail)
                ->implode(', ')
        );
    }

    public function testAMissingTreeIsReportedAsAProblem(): void
    {
        $this->module()->setPreference(PortalApiModule::SETTING_TREE, 'no-such-tree');

        self::assertSame(Diagnosis::PROBLEM, $this->check('tree')->status);
    }

    /**
     * The failure that looks like nothing: new files uploaded, migrations not
     * run. From outside, the deployment succeeded.
     */
    public function testASchemaBehindTheCodeIsReportedAsAProblem(): void
    {
        Site::setPreference('PORTAL_API_SCHEMA_VERSION', '1');

        $check = $this->check('schema');

        self::assertSame(Diagnosis::PROBLEM, $check->status);
        self::assertStringContainsString('migrations', $check->advice);
    }

    public function testAnOlderModuleOverANewerDatabaseSaysSo(): void
    {
        Site::setPreference('PORTAL_API_SCHEMA_VERSION', '99');

        $check = $this->check('schema');

        self::assertSame(Diagnosis::PROBLEM, $check->status);
        self::assertStringContainsString('newer than the code', $check->advice);
    }

    /**
     * webtrees' own registration page is a second front door that this module
     * knows nothing about, and Phase 5 is the reason to close it.
     */
    public function testWebtreesOwnRegistrationIsFlagged(): void
    {
        Site::setPreference('USE_REGISTRATION_MODULE', '1');

        self::assertSame(Diagnosis::WARNING, $this->check('registration')->status);
    }

    public function testAnAbsentProxySecretIsAWarningAndNotAProblem(): void
    {
        $this->module()->setPreference(PortalApiModule::SETTING_PROXY_SECRET, '');

        self::assertSame(Diagnosis::WARNING, $this->check('proxy_secret')->status);
    }

    public function testAnAbsentPortalAddressIsAProblem(): void
    {
        $this->module()->setPreference(PortalApiModule::SETTING_PORTAL_URL, '');

        self::assertSame(Diagnosis::PROBLEM, $this->check('portal_url')->status);
    }

    public function testAccountsWithNoRecordAreCounted(): void
    {
        $this->createUser('lonely', 'Ohne Datensatz', 'irgendwas', UserInterface::ROLE_MEMBER);

        self::assertSame(Diagnosis::WARNING, $this->check('unlinked')->status);
    }

    public function testARecordedFailureShowsUpInTheDiagnosis(): void
    {
        self::assertSame(Diagnosis::OK, $this->check('errors')->status);

        $this->dispatchFailing(new RuntimeException('boom'));

        self::assertSame(Diagnosis::PROBLEM, $this->check('errors')->status);
    }

    /**
     * A diagnosis screen that breaks on a broken installation is no use to
     * anybody, so every check has to survive one.
     */
    public function testTheDiagnosisSurvivesAnInstallationWithNothingConfigured(): void
    {
        $this->module()->setPreference(PortalApiModule::SETTING_TREE, 'no-such-tree');
        $this->module()->setPreference(PortalApiModule::SETTING_PORTAL_URL, '');
        $this->module()->setPreference(PortalApiModule::SETTING_PROXY_SECRET, '');

        $checks = $this->diagnose();

        self::assertCount(9, $checks);
        self::assertSame(Diagnosis::PROBLEM, Registry::container()->get(Diagnosis::class)->worst($checks));
    }

    public function testTheDiagnosisScreenRenders(): void
    {
        $this->dispatchFailing(new RuntimeException('boom'));

        $diagnosis = Registry::container()->get(Diagnosis::class);
        $checks    = $diagnosis->run();

        $html = view('_portal_api_::diagnosis', [
            'title'        => 'Diagnosis',
            'module'       => $this->module(),
            'checks'       => $checks,
            'worst'        => $diagnosis->worst($checks),
            'errors'       => $this->errors->recent(),
            'error_count'  => $this->errors->count(),
            'path_length'  => 2,
            'numbers'      => $diagnosis->directoryNumbers(),
            'settings_url' => '/settings',
        ]);

        self::assertStringContainsString('RuntimeException', $html);
        self::assertStringContainsString('MeRead', $html);
    }
}
