<?php

declare(strict_types=1);

namespace Engelking\Webtrees\PortalApi\Tests;

use Engelking\Webtrees\PortalApi\Http\RequestHandlers\IndexRead;
use Engelking\Webtrees\PortalApi\Http\RequestHandlers\SearchList;
use Fisharebest\Webtrees\Contracts\UserInterface;
use Fisharebest\Webtrees\User;
use PHPUnit\Framework\Attributes\CoversNothing;
use Psr\Http\Message\ResponseInterface;

use function array_column;
use function array_map;
use function implode;

/**
 * Phase 16: looking through the archive rather than walking it.
 *
 * Everything here is about one rule and its two halves. Somebody dead is
 * findable; somebody living is findable only if they are a portal member who
 * put themselves in the directory. See `SearchConsent` for the argument.
 *
 * The fixture is convenient for it. Anna (X1), Clara (X3), Dieter (X4) and
 * Fritz (X6) are living; everybody else is dead. Clara carries a
 * `RESN confidential` and Ida (X9) does too, so between them they also prove
 * that this rule *narrows* and never widens: a record webtrees hides stays
 * hidden whether or not anybody consented to anything.
 */
#[CoversNothing]
class TreeSearchTest extends PortalTestCase
{
    private function signInAsAnna(): User
    {
        $anna = $this->createUser('anna', 'Anna Beispiel', 'geheim', UserInterface::ROLE_MEMBER, 'X1');

        $this->login($anna);

        return $anna;
    }

    /**
     * @param array<string,mixed> $query
     *
     * @return array<int,array<string,mixed>>
     */
    private function search(array $query): array
    {
        $response = $this->api(SearchList::class, query: $query);

        self::assertSame(200, $response->getStatusCode());

        return $this->json($response)['items'];
    }

    /**
     * @param array<string,mixed> $query
     *
     * @return array<int,string>
     */
    private function xrefs(array $query): array
    {
        return array_column($this->search($query), 'xref');
    }

    /**
     * @return array<string,mixed>
     */
    private function index(): array
    {
        $response = $this->api(IndexRead::class);

        self::assertSame(200, $response->getStatusCode());

        return $this->json($response);
    }

    /**
     * @param array<int,array<string,mixed>> $entries
     *
     * @return array<string,int>
     */
    private function counted(array $entries): array
    {
        $counts = [];

        foreach ($entries as $entry) {
            $counts[$entry['name']] = $entry['count'];
        }

        return $counts;
    }

    // -----------------------------------------------------------------
    // The rule
    // -----------------------------------------------------------------

    public function testTheDeadAreFoundByName(): void
    {
        $this->signInAsAnna();

        self::assertContains('X2', $this->xrefs(['q' => 'Bertha']));
    }

    /**
     * The whole reason this endpoint needed a rule of its own.
     *
     * Dieter is visible to Anna — she can reach him by tapping "Geschwister"
     * on her own record, and every other endpoint will describe him. What he
     * has not done is agree to be *listed* to everybody, and a search is a
     * list.
     */
    public function testALivingPersonWhoIsNotAListedMemberIsNotFound(): void
    {
        $this->signInAsAnna();

        self::assertNotContains('X4', $this->xrefs(['q' => 'Dieter']));
        self::assertSame([], $this->search(['q' => 'Dieter']));
    }

    public function testALivingMemberWhoListedThemselvesIsFound(): void
    {
        $this->signInAsAnna();

        $dieter = $this->createUser('dieter', 'Dieter Beispiel', 'geheim', UserInterface::ROLE_MEMBER, 'X4');
        $this->createProfile($dieter, true);

        self::assertContains('X4', $this->xrefs(['q' => 'Dieter']));
    }

    /**
     * Consent withdrawn is consent gone, in the search as everywhere else.
     */
    public function testAMemberWhoLeavesTheDirectoryLeavesTheSearch(): void
    {
        $this->signInAsAnna();

        $dieter = $this->createUser('dieter', 'Dieter Beispiel', 'geheim', UserInterface::ROLE_MEMBER, 'X4');
        $this->createProfile($dieter, false);

        self::assertNotContains('X4', $this->xrefs(['q' => 'Dieter']));
    }

    /**
     * The rule narrows; it never widens.
     *
     * Clara is living *and* carries `RESN confidential`. Listing her in the
     * directory must not hand her to the search, because webtrees' own access
     * level said no first and this rule is applied after it.
     */
    public function testConsentDoesNotOverrideWebtreesOwnPrivacy(): void
    {
        $this->signInAsAnna();

        $clara = $this->createUser('clara', 'Clara Beispiel', 'geheim', UserInterface::ROLE_MEMBER, 'X3');
        $this->createProfile($clara, true);

        self::assertNotContains('X3', $this->xrefs(['q' => 'Clara']));
    }

    public function testAConfidentialDeadPersonIsNotFound(): void
    {
        $this->signInAsAnna();

        // Ida is dead, and hidden by her own RESN.
        self::assertNotContains('X9', $this->xrefs(['q' => 'Ida']));
    }

    // -----------------------------------------------------------------
    // Reference numbers
    // -----------------------------------------------------------------

    public function testAReferenceNumberFindsThePerson(): void
    {
        $this->signInAsAnna();

        self::assertSame(['X2'], $this->xrefs(['q' => '4712']));
    }

    /**
     * The numbers this family actually quotes have punctuation in them.
     *
     * Reducing the query to its digits would find "4712" and lose
     * "10/1335.21", which is half the archive's numbering.
     */
    public function testANumberWithPunctuationIsFoundAsItIsWritten(): void
    {
        $this->signInAsAnna();

        $dieter = $this->createUser('dieter', 'Dieter Beispiel', 'geheim', UserInterface::ROLE_MEMBER, 'X4');
        $this->createProfile($dieter, true);

        self::assertContains('X4', $this->xrefs(['q' => '10/1335.21']));

        // And it is the number, not a prefix of one: Fritz's "10/1335.21!" is
        // a different number and stays out of it.
        self::assertNotContains('X6', $this->xrefs(['q' => '10/1335.21']));
    }

    /**
     * A confidential number is not a back door to the record it is on.
     *
     * Bertha's "9999" is `RESN confidential`, so it is filtered out of her
     * facts before the comparison — quoting it finds nobody, which is the same
     * answer her record gives.
     */
    public function testAConfidentialReferenceNumberFindsNobody(): void
    {
        $this->signInAsAnna();

        self::assertSame([], $this->search(['q' => '9999']));
    }

    /**
     * A number that belongs to no line is quoted the same way.
     *
     * Otto's is a `GS/` number — the archive writes those for the ancestors
     * above the lines and for branches that have none — and searching for it
     * has to work like searching for any other.
     */
    public function testANumberWithNoLineIsFoundToo(): void
    {
        $this->signInAsAnna();

        self::assertSame(['X12'], $this->xrefs(['q' => 'GS/755133']));
    }

    /**
     * The archive writes "24/b521.12" in lower case and "GS/7D8" in upper.
     * Nobody typing one into a search box should have to remember which.
     */
    public function testAReferenceNumberIsFoundWhicheverCaseItIsTypedIn(): void
    {
        $this->signInAsAnna();

        self::assertSame(['X12'], $this->xrefs(['q' => 'gs/755133']));
    }

    /**
     * `%` is a character in an archive number, not a wildcard.
     */
    public function testAWildcardInTheQueryMatchesNothingByItself(): void
    {
        $this->signInAsAnna();

        self::assertSame([], $this->search(['q' => '4%']));
    }

    // -----------------------------------------------------------------
    // What a card says
    // -----------------------------------------------------------------

    public function testEveryCardSaysHowTheReaderIsRelated(): void
    {
        $this->signInAsAnna();

        $found = $this->search(['q' => 'Bertha']);

        self::assertCount(1, $found);
        self::assertNotNull($found[0]['relationship']);
        self::assertSame('X2', $found[0]['xref']);
    }

    public function testACardCarriesTheArchiveNumber(): void
    {
        $this->signInAsAnna();

        $found = $this->search(['q' => 'Bertha']);

        self::assertSame(['4712', '47C12'], array_column($found[0]['references'], 'number'));
    }

    // -----------------------------------------------------------------
    // The indexes
    // -----------------------------------------------------------------

    public function testTheSurnameIndexCountsOnlyPeopleTheReaderMayFind(): void
    {
        $this->signInAsAnna();

        $counts = $this->counted($this->index()['surnames']);

        // Bertha, Emil, Gustav, Helene, Konrad, Ludwig — the visible dead.
        // Anna, Dieter, Clara and Fritz are living and unlisted; Ida is
        // confidential.
        self::assertSame(6, $counts['Beispiel']);
        self::assertSame(1, $counts['Fernab']);
    }

    public function testListingYourselfPutsYouInTheIndex(): void
    {
        $anna = $this->signInAsAnna();
        $this->createProfile($anna, true);

        self::assertSame(7, $this->counted($this->index()['surnames'])['Beispiel']);
    }

    public function testThePlaceIndexIsBuiltFromTheEventsThatAreVisible(): void
    {
        $this->signInAsAnna();

        $counts = $this->counted($this->index()['places']);

        // Bertha (born there) and Emil (born and died there).
        self::assertSame(2, $counts['Celle, Niedersachsen, Deutschland']);
        // Bertha died there. Anna, Clara and Dieter were born there and are
        // not findable.
        self::assertSame(1, $counts['Hannover, Niedersachsen, Deutschland']);
    }

    public function testTappingASurnameListsTheSamePeople(): void
    {
        $this->signInAsAnna();

        self::assertSame(['X12'], $this->xrefs(['surname' => 'Fernab']));
    }

    public function testTappingAPlaceListsThePeopleWithAnEventThere(): void
    {
        $this->signInAsAnna();

        self::assertSame(['X2', 'X5'], $this->xrefs(['place' => 'Celle, Niedersachsen, Deutschland']));
    }

    /**
     * Exact, not "contains" — this is the other half of the index, and the
     * member got here by tapping an entry that came out of it.
     */
    public function testBrowsingBySurnameDoesNotMatchOnPartOfAName(): void
    {
        $this->signInAsAnna();

        self::assertSame([], $this->search(['surname' => 'Beispi']));
    }

    // -----------------------------------------------------------------
    // Shape and access
    // -----------------------------------------------------------------

    public function testAnEmptyQueryAsksNothingAndFindsNobody(): void
    {
        $this->signInAsAnna();

        $body = $this->json($this->api(SearchList::class));

        self::assertSame([], $body['items']);
        self::assertSame(0, $body['total']);
        self::assertFalse($body['truncated']);
    }

    public function testTheSearchNeedsASession(): void
    {
        self::assertSame(401, $this->api(SearchList::class, query: ['q' => 'Bertha'])->getStatusCode());
    }

    public function testTheIndexNeedsASession(): void
    {
        self::assertSame(401, $this->api(IndexRead::class)->getStatusCode());
    }

    public function testTheAnswerIsNotCached(): void
    {
        $this->signInAsAnna();

        foreach ([$this->api(SearchList::class, query: ['q' => 'Bertha']), $this->api(IndexRead::class)] as $response) {
            self::assertStringContainsString('no-store', $this->cacheControl($response));
        }
    }

    private function cacheControl(ResponseInterface $response): string
    {
        return implode(' ', array_map('strval', $response->getHeader('Cache-Control')));
    }
}
