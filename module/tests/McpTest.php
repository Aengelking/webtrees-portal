<?php

declare(strict_types=1);

namespace Engelking\Webtrees\PortalApi\Tests;

use Engelking\Webtrees\PortalApi\Http\RequestHandlers\McpCreate;
use Engelking\Webtrees\PortalApi\Http\Middleware\RequireMcpToken;
use Engelking\Webtrees\PortalApi\Http\RequestHandlers\McpRead;
use Engelking\Webtrees\PortalApi\Mcp\McpException;
use Engelking\Webtrees\PortalApi\Mcp\Server;
use Engelking\Webtrees\PortalApi\PortalApiModule;
use Engelking\Webtrees\PortalApi\Services\Diagnosis;
use Engelking\Webtrees\PortalApi\Services\DiagnosisCheck;
use Engelking\Webtrees\PortalApi\Services\McpTokens;
use Fig\Http\Message\RequestMethodInterface;
use Fig\Http\Message\StatusCodeInterface;
use Fisharebest\Webtrees\Contracts\UserInterface;
use Fisharebest\Webtrees\DB;
use Fisharebest\Webtrees\Factories\CacheFactory;
use Fisharebest\Webtrees\Registry;
use Fisharebest\Webtrees\Session;
use Fisharebest\Webtrees\User;
use PHPUnit\Framework\Attributes\CoversNothing;
use Psr\Http\Message\ResponseInterface;
use stdClass;

use function array_column;
use function json_decode;
use function str_contains;
use function time;

use const JSON_THROW_ON_ERROR;

/**
 * The MCP server: the protocol, the lock on it, and the one rule it exists to
 * keep.
 *
 * The fixture (tests/data/portal.ged) is the same one every other test uses,
 * with notes added to it:
 *
 *   X1 Anna    living  — an inline note of her own, and the only record
 *                        linking the shared note @N2@
 *   X2 Bertha  dead    — an inline note, a note under her birth, a note under
 *                        a fact marked "2 RESN confidential", and a link to
 *                        the shared note @N1@; mother of three living children
 *   X5 Emil    dead    — also links @N1@, so that note's own visibility does
 *                        not depend on anybody living
 *   X9 Ida     dead    — "1 RESN confidential": managers only. Bertha's
 *                        mother, and therefore the gap a pedigree has to walk
 *                        past to reach X12 Otto above her
 *
 * Most of these assertions are about what is *not* in a response. They are
 * written against the whole response text wherever a structured assertion
 * could be satisfied by a differently-shaped answer that still leaked.
 */
#[CoversNothing]
class McpTest extends PortalTestCase
{
    private User $reader;
    private string $token;

    protected function setUp(): void
    {
        parent::setUp();

        $this->module()->setPreference(PortalApiModule::SETTING_MCP, '1');
        $this->module()->setPreference(PortalApiModule::SETTING_MCP_NOTES, '1');

        // A member, not a manager: the cautious end of what a token can be
        // issued for, and the level most of these assertions are about.
        $this->reader = $this->createUser('leser', 'Der Leser', 'correct-horse', UserInterface::ROLE_MEMBER);
        $this->token  = $this->tokens()->create('Test', $this->reader, null);
    }

    // -----------------------------------------------------------------
    // The lock
    // -----------------------------------------------------------------

    public function testTheEndpointIsNotThereWhenTheArchiveIsNotPublished(): void
    {
        $this->module()->setPreference(PortalApiModule::SETTING_MCP, '0');

        $response = $this->rpc('initialize', []);

        self::assertSame(StatusCodeInterface::STATUS_NOT_FOUND, $response->getStatusCode());
    }

    public function testARequestWithoutATokenIsRefused(): void
    {
        $response = $this->call(['jsonrpc' => '2.0', 'id' => 1, 'method' => 'ping'], token: '');

        self::assertSame(StatusCodeInterface::STATUS_UNAUTHORIZED, $response->getStatusCode());
        self::assertStringStartsWith('Bearer', $response->getHeaderLine('WWW-Authenticate'));
    }

    public function testAnUnknownTokenIsRefused(): void
    {
        $response = $this->call(['jsonrpc' => '2.0', 'id' => 1, 'method' => 'ping'], token: McpTokens::PREFIX . 'deadbeef');

        self::assertSame(StatusCodeInterface::STATUS_UNAUTHORIZED, $response->getStatusCode());
    }

    public function testAWithdrawnTokenIsRefused(): void
    {
        $id = (int) DB::table(McpTokens::TABLE)->value('id');
        $this->tokens()->revoke($id);

        $response = $this->call(['jsonrpc' => '2.0', 'id' => 1, 'method' => 'ping']);

        self::assertSame(StatusCodeInterface::STATUS_UNAUTHORIZED, $response->getStatusCode());
    }

    public function testAnExpiredTokenIsRefused(): void
    {
        DB::table(McpTokens::TABLE)->update(['expires_at' => time() - 1]);

        $response = $this->call(['jsonrpc' => '2.0', 'id' => 1, 'method' => 'ping']);

        self::assertSame(StatusCodeInterface::STATUS_UNAUTHORIZED, $response->getStatusCode());
    }

    public function testATokenForASuspendedAccountIsRefused(): void
    {
        $this->reader->setPreference(UserInterface::PREF_IS_ACCOUNT_APPROVED, '0');

        $response = $this->call(['jsonrpc' => '2.0', 'id' => 1, 'method' => 'ping']);

        self::assertSame(StatusCodeInterface::STATUS_UNAUTHORIZED, $response->getStatusCode());
    }

    public function testTheTokenIsNotStoredAndItsUseIsRecorded(): void
    {
        $row = DB::table(McpTokens::TABLE)->first();

        self::assertNotSame($this->token, $row->token_hash);
        self::assertNull($row->last_used_at);

        $this->rpc('ping', []);

        $row = DB::table(McpTokens::TABLE)->first();

        self::assertNotNull($row->last_used_at);
        self::assertSame(1, (int) $row->uses);
    }

    /**
     * The token under the name the portal's proxy carries it by.
     *
     * `Authorization` does not reach PHP on an Apache running CGI or FastCGI
     * without `CGIPassAuth On`, which on shared hosting is not the
     * administrator's to set — so a good token was answered with 401 and
     * nothing said why. The Worker copies it; this reads the copy.
     */
    public function testTheTokenIsAcceptedUnderTheProxysOwnHeader(): void
    {
        $response = $this->call(
            ['jsonrpc' => '2.0', 'id' => 1, 'method' => 'ping'],
            token: '',
            headers: [RequireMcpToken::FALLBACK_HEADER => 'Bearer ' . $this->token],
        );

        self::assertSame(StatusCodeInterface::STATUS_OK, $response->getStatusCode());
        self::assertSame([], $this->json($response)['result']);
    }

    /** `Authorization` wins where it arrives; the copy is only a fallback. */
    public function testTheOriginalHeaderIsPreferred(): void
    {
        $response = $this->call(
            ['jsonrpc' => '2.0', 'id' => 1, 'method' => 'ping'],
            headers: [RequireMcpToken::FALLBACK_HEADER => 'Bearer ' . McpTokens::PREFIX . 'rubbish'],
        );

        self::assertSame(StatusCodeInterface::STATUS_OK, $response->getStatusCode());
    }

    /**
     * A header the proxy did not fill in is not a way past the lock. It holds
     * a token like any other, and an unknown one is refused like any other.
     */
    public function testTheProxysHeaderStillHasToCarryARealToken(): void
    {
        $response = $this->call(
            ['jsonrpc' => '2.0', 'id' => 1, 'method' => 'ping'],
            token: '',
            headers: [RequireMcpToken::FALLBACK_HEADER => 'Bearer ' . McpTokens::PREFIX . 'rubbish'],
        );

        self::assertSame(StatusCodeInterface::STATUS_UNAUTHORIZED, $response->getStatusCode());
    }

    /** A proxy that pads the value must not cost a member their credential. */
    public function testSurroundingWhitespaceIsTolerated(): void
    {
        $response = $this->call(
            ['jsonrpc' => '2.0', 'id' => 1, 'method' => 'ping'],
            token: '',
            headers: ['Authorization' => "  Bearer \t" . $this->token . ' '],
        );

        self::assertSame(StatusCodeInterface::STATUS_OK, $response->getStatusCode());
    }

    public function testTheStreamIsNotOffered(): void
    {
        $response = $this->api(
            McpRead::class,
            RequestMethodInterface::METHOD_GET,
            headers: ['Authorization' => 'Bearer ' . $this->token],
        );

        self::assertSame(StatusCodeInterface::STATUS_METHOD_NOT_ALLOWED, $response->getStatusCode());
        self::assertSame('POST', $response->getHeaderLine('Allow'));
    }

    // -----------------------------------------------------------------
    // The protocol
    // -----------------------------------------------------------------

    public function testInitializeAgreesAVersionAndNamesItself(): void
    {
        $result = $this->rpcResult('initialize', ['protocolVersion' => Server::LATEST_PROTOCOL]);

        self::assertSame(Server::LATEST_PROTOCOL, $result['protocolVersion']);
        self::assertSame(Server::NAME, $result['serverInfo']['name']);
        self::assertArrayHasKey('tools', $result['capabilities']);
        self::assertStringContainsString('only people who have died', $result['instructions']);
    }

    /**
     * Asserted on the encoded body, and it has to be.
     *
     * PHP has one array type for both a list and a map, so `json_encode`
     * guesses from the keys: an empty one becomes `[]`. Both of these are
     * empty objects in the specification's schemas, and a client that
     * validates against them — mcp-remote does, with zod — rejects the
     * message. Decoding the response back into a PHP array, which is what
     * every other test here does, cannot see the difference: `[]` and `{}`
     * both arrive as an empty array. So these two read the JSON as objects.
     */
    public function testTheToolsCapabilityIsAnEmptyObjectAndNotAnEmptyList(): void
    {
        $body = $this->decodeAsObjects($this->rpc('initialize', ['protocolVersion' => Server::LATEST_PROTOCOL]));

        self::assertInstanceOf(stdClass::class, $body->result->capabilities->tools);
    }

    public function testPingIsAnsweredWithAnEmptyObjectAndNotAnEmptyList(): void
    {
        $body = $this->decodeAsObjects($this->call(['jsonrpc' => '2.0', 'id' => 1, 'method' => 'ping']));

        self::assertEquals(new stdClass(), $body->result);
    }

    public function testAVersionThisServerDoesNotKnowIsAnsweredWithOneItDoes(): void
    {
        $result = $this->rpcResult('initialize', ['protocolVersion' => '2099-01-01']);

        self::assertSame(Server::LATEST_PROTOCOL, $result['protocolVersion']);
    }

    public function testANotificationIsAcceptedAndNotAnswered(): void
    {
        $response = $this->call(['jsonrpc' => '2.0', 'method' => 'notifications/initialized']);

        self::assertSame(StatusCodeInterface::STATUS_ACCEPTED, $response->getStatusCode());
        self::assertSame('', $this->raw($response));
    }

    public function testAnUnknownMethodIsAJsonRpcError(): void
    {
        $body = $this->json($this->call(['jsonrpc' => '2.0', 'id' => 7, 'method' => 'resources/list']));

        self::assertSame(7, $body['id']);
        self::assertSame(McpException::METHOD_NOT_FOUND, $body['error']['code']);
    }

    public function testAnUnparseableBodyIsAParseError(): void
    {
        $response = $this->raw2('not json at all');

        self::assertSame(StatusCodeInterface::STATUS_BAD_REQUEST, $response->getStatusCode());
        self::assertSame(McpException::PARSE_ERROR, $this->json($response)['error']['code']);
    }

    public function testTheToolsAreListed(): void
    {
        $names = array_column($this->rpcResult('tools/list', [])['tools'], 'name');

        self::assertContains('search_people', $names);
        self::assertContains('get_person', $names);
        self::assertContains('get_ancestors', $names);
        self::assertContains('get_descendants', $names);
        self::assertContains('get_relationship', $names);
        self::assertContains('search_notes', $names);
        self::assertContains('list_index', $names);
    }

    public function testEveryToolSaysItOnlyReads(): void
    {
        foreach ($this->rpcResult('tools/list', [])['tools'] as $tool) {
            self::assertTrue($tool['annotations']['readOnlyHint'], $tool['name'] . ' does not say it only reads');
        }
    }

    public function testAToolNobodyHasIsInvalidParams(): void
    {
        $body = $this->json($this->call([
            'jsonrpc' => '2.0',
            'id'      => 3,
            'method'  => 'tools/call',
            'params'  => ['name' => 'delete_everybody', 'arguments' => []],
        ]));

        self::assertSame(McpException::INVALID_PARAMS, $body['error']['code']);
    }

    // -----------------------------------------------------------------
    // Only the dead
    // -----------------------------------------------------------------

    public function testADeadPersonIsReadable(): void
    {
        $person = $this->tool('get_person', ['id' => 'X2']);

        self::assertSame('X2', $person['id']);
        self::assertStringContainsString('Bertha', $person['name']);
        self::assertSame('1889–1976', $person['lifespan']);
    }

    public function testALivingPersonIsNotReadable(): void
    {
        $response = $this->call($this->message('tools/call', ['name' => 'get_person', 'arguments' => ['id' => 'X1']]));
        $result   = $this->json($response)['result'];

        self::assertTrue($result['isError']);
        self::assertStringNotContainsString('Anna', $this->raw($response));
    }

    public function testALivingPersonIsNotAmongTheirParentsChildren(): void
    {
        $response = $this->call($this->message('tools/call', ['name' => 'get_person', 'arguments' => ['id' => 'X2']]));
        $person   = $this->json($response)['result']['structuredContent'];

        self::assertSame([], $person['children']);

        // Two, not three. Bertha has three living children in the fixture, and
        // one of them — Clara, "1 RESN confidential" — was hidden by webtrees
        // before this rule was asked. `withheld` counts what *this* rule kept
        // back and nothing else, which is what stops it becoming a way to
        // measure what privacy is hiding.
        self::assertSame(2, $person['withheld']['children']);

        $raw = $this->raw($response);

        foreach (['Anna', 'Clara', 'Dieter', '"X1"', '"X3"', '"X4"'] as $needle) {
            self::assertStringNotContainsString($needle, $raw, $needle . ' reached an assistant');
        }
    }

    public function testALivingPersonIsNotFoundBySearchingEvenFromTheDirectory(): void
    {
        // In the directory, and therefore findable by a member on the portal's
        // own search screen. Not here: this rule has no such exception.
        $this->createProfile($this->createUser('anna', 'Anna Beispiel', 'correct-horse', UserInterface::ROLE_MEMBER, 'X1'), true);

        $response = $this->call($this->message('tools/call', ['name' => 'search_people', 'arguments' => ['query' => 'Beispiel']]));
        $found    = $this->json($response)['result']['structuredContent'];

        self::assertNotSame([], $found['people']);
        self::assertNotContains('X1', array_column($found['people'], 'id'));
        self::assertStringNotContainsString('Anna', $this->raw($response));
    }

    public function testTheIndexCountsOnlyTheDead(): void
    {
        $index = $this->tool('list_index', ['kind' => 'surnames']);
        $names = array_column($index['surnames'], 'name');

        self::assertContains('Beispiel', $names);

        // Eleven people in the fixture are called Beispiel. Four are alive, and
        // one of the dead — Ida, "1 RESN confidential" — is not this member's
        // to see. Six are left, and a count that said anything else would be a
        // count of people this server will not name.
        foreach ($index['surnames'] as $surname) {
            if ($surname['name'] === 'Beispiel') {
                self::assertSame(6, $surname['count']);
            }
        }
    }

    public function testAPedigreeWalksPastSomebodyItMayNotName(): void
    {
        $pedigree = $this->tool('get_ancestors', ['id' => 'X2', 'generations' => 3]);
        $rungs    = [];

        foreach ($pedigree['rungs'] as $rung) {
            $rungs[$rung['position']] = $rung['person'];
        }

        // 2 is Bertha's father Konrad, 3 her mother Ida, who is confidential
        // and stays unnamed — and 6 is Ida's father Otto, above the gap.
        self::assertNotNull($rungs[2]);
        self::assertNull($rungs[3]);
        self::assertNotNull($rungs[6]);
        self::assertStringContainsString('Otto', $rungs[6]['name']);
    }

    public function testDescendantsAreCountedRatherThanNamed(): void
    {
        $descendants = $this->tool('get_descendants', ['id' => 'X2']);

        self::assertSame([], $descendants['generations'][0]['people']);
        self::assertSame(3, $descendants['generations'][0]['withheld']);
    }

    public function testARelationshipBetweenTwoOfTheDeadIsNamed(): void
    {
        $answer = $this->tool('get_relationship', ['from_id' => 'X5', 'to_id' => 'X7']);

        self::assertNotNull($answer['relationship']);
    }

    public function testARelationshipInvolvingTheLivingIsRefused(): void
    {
        $result = $this->rawTool('get_relationship', ['from_id' => 'X2', 'to_id' => 'X1']);

        self::assertTrue($result['isError']);
    }

    // -----------------------------------------------------------------
    // The notes
    // -----------------------------------------------------------------

    public function testTheNotesOnARecordTravelWithIt(): void
    {
        $person = $this->tool('get_person', ['id' => 'X2']);
        $texts  = array_column($person['notes'], 'text');

        self::assertCount(2, $texts);
        self::assertStringContainsString('Familienstammbuch', $texts[0]);
        self::assertStringContainsString('Traueranzeige', $texts[0]);
        self::assertStringContainsString('Hebamme in Celle', $texts[1]);
        self::assertStringContainsString('Stellmacher', $texts[1]);
    }

    public function testANoteUnderAnEventTravelsWithTheEvent(): void
    {
        $person = $this->tool('get_person', ['id' => 'X2']);

        self::assertStringContainsString('Kirchenbuch', $person['birth']['notes'][0]['text']);
        self::assertStringContainsString('Taufe', $person['birth']['notes'][0]['text']);
    }

    public function testANoteUnderAConfidentialFactStaysThere(): void
    {
        $response = $this->call($this->message('tools/call', ['name' => 'get_person', 'arguments' => ['id' => 'X2']]));

        self::assertStringNotContainsString('vertraulich', $this->raw($response));
        self::assertStringNotContainsString('Geheimniskraemerin', $this->raw($response));
    }

    public function testTheNotesAreSearchable(): void
    {
        $found = $this->tool('search_notes', ['query' => 'Hebamme']);

        self::assertSame(2, $found['total']);
        self::assertSame(['X2', 'X5'], array_column(array_column($found['notes'], 'person'), 'id'));
    }

    public function testANoteAboutSomebodyLivingIsNotSearchable(): void
    {
        $response = $this->call($this->message('tools/call', [
            'name'      => 'search_notes',
            'arguments' => ['query' => 'lebende Person'],
        ]));

        self::assertSame(0, $this->json($response)['result']['structuredContent']['total']);
        self::assertStringNotContainsString('lebende Person', $this->raw($response));
    }

    public function testTheNotesCanBeSwitchedOff(): void
    {
        $this->module()->setPreference(PortalApiModule::SETTING_MCP_NOTES, '0');

        $names = array_column($this->rpcResult('tools/list', [])['tools'], 'name');

        self::assertNotContains('search_notes', $names);

        $person = $this->tool('get_person', ['id' => 'X2']);

        self::assertSame([], $person['notes']);
        self::assertSame([], $person['birth']['notes']);
    }

    public function testSearchingNotesIsRefusedWhenTheyAreSwitchedOff(): void
    {
        $this->module()->setPreference(PortalApiModule::SETTING_MCP_NOTES, '0');

        $body = $this->json($this->call($this->message('tools/call', [
            'name'      => 'search_notes',
            'arguments' => ['query' => 'Hebamme'],
        ])));

        self::assertSame(McpException::INVALID_PARAMS, $body['error']['code']);
    }

    // -----------------------------------------------------------------
    // Access levels
    // -----------------------------------------------------------------

    public function testATokenReadsAtItsAccountsAccessLevelAndNoHigher(): void
    {
        // Ida is dead, and "1 RESN confidential" — managers only. A member's
        // token may not have her; a manager's may.
        self::assertTrue($this->rawTool('get_person', ['id' => 'X9'])['isError']);

        $manager = $this->createUser('mia', 'Mia Verwalterin', 'correct-horse', UserInterface::ROLE_MANAGER);
        $this->token = $this->tokens()->create('Test', $manager, null);

        self::assertSame('X9', $this->tool('get_person', ['id' => 'X9'])['id']);
    }

    public function testAManagersTokenStillMayNotHaveTheLiving(): void
    {
        $manager     = $this->createUser('mia', 'Mia Verwalterin', 'correct-horse', UserInterface::ROLE_MANAGER);
        $this->token = $this->tokens()->create('Test', $manager, null);

        $response = $this->call($this->message('tools/call', ['name' => 'get_person', 'arguments' => ['id' => 'X1']]));

        self::assertTrue($this->json($response)['result']['isError']);
        self::assertStringNotContainsString('Anna', $this->raw($response));
    }

    // -----------------------------------------------------------------
    // The administrator's screen
    // -----------------------------------------------------------------

    /**
     * Rendered with what the action actually passes, because an undefined
     * variable in a `.phtml` is a warning and an empty string — the page still
     * renders, just without the thing it was for. The same reasoning as
     * `InvitationTest::testThePreferencesScreenRenders`.
     */
    public function testTheTokenScreenRenders(): void
    {
        $html = view('_portal_api_::mcp', [
            'title'        => 'Assistant access',
            'module'       => $this->module(),
            'tokens'       => $this->tokens()->all(),
            'accounts'     => [$this->reader->id() => 'Der Leser (leser)'],
            'issuers'      => [],
            'enabled'      => true,
            'notes'        => true,
            'valid_days'   => McpTokens::DEFAULT_VALIDITY_DAYS,
            'new_token'    => McpTokens::PREFIX . 'abc',
            'mcp_url'      => 'https://portal.example.test/api/mcp',
            'settings_url' => '/settings',
        ]);

        self::assertStringContainsString('Der Leser (leser)', $html);
        self::assertStringContainsString(McpTokens::PREFIX . 'abc', $html);
        self::assertStringContainsString('https://portal.example.test/api/mcp', $html);

        // The one-line form of the same two things, so that an administrator
        // does not have to assemble them.
        self::assertStringContainsString('claude mcp add', $html);
    }

    /**
     * The screen's own round trip: what an administrator types becomes a token
     * that opens the endpoint, and withdrawing it closes it again.
     */
    public function testIssuingAndWithdrawingThroughTheScreen(): void
    {
        $this->module()->postAdminMcpAction(self::createRequest(RequestMethodInterface::METHOD_POST, [], [
            'token_name' => 'Claude on the study computer',
            'reads_as'   => (string) $this->reader->id(),
            'valid_days' => '30',
        ]));

        $issued = Session::get('portal_api_new_mcp_token', '');

        self::assertIsString($issued);
        self::assertStringStartsWith(McpTokens::PREFIX, $issued);

        // Shown once, and through the session rather than the redirect: a
        // token in a URL is a token in the webserver's access log.
        self::assertSame(
            StatusCodeInterface::STATUS_OK,
            $this->call(['jsonrpc' => '2.0', 'id' => 1, 'method' => 'ping'], token: $issued)->getStatusCode()
        );

        $id = (int) DB::table(McpTokens::TABLE)->where('name', '=', 'Claude on the study computer')->value('id');

        $this->module()->postAdminMcpAction(self::createRequest(RequestMethodInterface::METHOD_POST, [], [
            'token_action' => 'revoke',
            'token_id'     => (string) $id,
        ]));

        self::assertSame(
            StatusCodeInterface::STATUS_UNAUTHORIZED,
            $this->call(['jsonrpc' => '2.0', 'id' => 1, 'method' => 'ping'], token: $issued)->getStatusCode()
        );
    }

    public function testTheDiagnosisNoticesAnEndpointNobodyCanOpen(): void
    {
        DB::table(McpTokens::TABLE)->delete();

        $checks = Registry::container()->get(Diagnosis::class)->run();
        $check  = $checks->first(static fn (DiagnosisCheck $check): bool => $check->key === 'mcp');

        self::assertSame(Diagnosis::WARNING, $check->status);
    }

    // -----------------------------------------------------------------
    // Helpers
    // -----------------------------------------------------------------

    private function tokens(): McpTokens
    {
        return Registry::container()->get(McpTokens::class);
    }

    /**
     * @param array<string,mixed> $params
     *
     * @return array<string,mixed>
     */
    private function message(string $method, array $params): array
    {
        return ['jsonrpc' => '2.0', 'id' => 1, 'method' => $method, 'params' => $params];
    }

    /**
     * @param array<string,mixed> $params
     */
    private function rpc(string $method, array $params): ResponseInterface
    {
        return $this->call($this->message($method, $params));
    }

    /**
     * @param array<string,mixed> $params
     *
     * @return array<string,mixed>
     */
    private function rpcResult(string $method, array $params): array
    {
        return $this->json($this->rpc($method, $params))['result'];
    }

    /**
     * One tool call, unwrapped to the thing the tool actually answered.
     *
     * @param array<string,mixed> $arguments
     *
     * @return array<string,mixed>
     */
    private function tool(string $name, array $arguments): array
    {
        $result = $this->rawTool($name, $arguments);

        self::assertFalse($result['isError'] ?? false, $name . ' refused: ' . ($result['content'][0]['text'] ?? ''));

        return $result['structuredContent'];
    }

    /**
     * @param array<string,mixed> $arguments
     *
     * @return array<string,mixed>
     */
    private function rawTool(string $name, array $arguments): array
    {
        return $this->json($this->rpc('tools/call', ['name' => $name, 'arguments' => $arguments]))['result'];
    }

    /**
     * @param array<string,mixed> $message
     */
    /**
     * @param array<string,mixed>  $message
     * @param array<string,string> $headers Anything beyond the credential.
     */
    private function call(array $message, string|null $token = null, array $headers = []): ResponseInterface
    {
        // A fresh array cache per request, as production has: webtrees caches
        // privacy answers by record and access level and not by user, and
        // these tests ask about the same records as several different ones.
        Registry::cache(new CacheFactory());

        $offered = $token ?? $this->token;

        if ($offered !== '') {
            $headers['Authorization'] = 'Bearer ' . $offered;
        }

        return $this->api(McpCreate::class, RequestMethodInterface::METHOD_POST, body: $message, headers: $headers);
    }

    /**
     * A body that is not JSON at all, which `api()` cannot express because it
     * encodes whatever it is given.
     */
    private function raw2(string $body): ResponseInterface
    {
        Registry::cache(new CacheFactory());

        return $this->api(
            McpCreate::class,
            RequestMethodInterface::METHOD_POST,
            body: null,
            headers: ['Authorization' => 'Bearer ' . $this->token],
            raw_body: $body,
        );
    }

    /**
     * The response body decoded with objects left as objects, so that a test
     * can tell `{}` from `[]`. `json()` cannot: it decodes to associative
     * arrays, where both are `[]`.
     */
    private function decodeAsObjects(ResponseInterface $response): stdClass
    {
        return json_decode($this->raw($response), false, 32, JSON_THROW_ON_ERROR);
    }
}
