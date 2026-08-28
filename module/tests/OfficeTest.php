<?php

declare(strict_types=1);

namespace Engelking\Webtrees\PortalApi\Tests;

use Engelking\Webtrees\PortalApi\Http\RequestHandlers\ConnectionCreate;
use Engelking\Webtrees\PortalApi\Http\RequestHandlers\ConnectionList;
use Engelking\Webtrees\PortalApi\Http\RequestHandlers\IndividualRead;
use Engelking\Webtrees\PortalApi\Http\RequestHandlers\MemberList;
use Engelking\Webtrees\PortalApi\Http\RequestHandlers\MemberRead;
use Engelking\Webtrees\PortalApi\PortalApiModule;
use Engelking\Webtrees\PortalApi\Services\Offices;
use Fig\Http\Message\RequestMethodInterface;
use Fisharebest\Webtrees\Contracts\UserInterface;
use Fisharebest\Webtrees\DB;
use Fisharebest\Webtrees\Registry;
use Fisharebest\Webtrees\User;
use PHPUnit\Framework\Attributes\CoversNothing;

use function array_column;
use function str_repeat;
use function strlen;

/**
 * The office a person holds in the foundation, on every card that names them.
 *
 * The whole point of this feature is one distinction: an office is what the
 * *foundation* says about a person, a GEDCOM fact is what the *archive* says.
 * That is not a philosophical nicety here — it decides whether a member who
 * may not read the chairwoman's record can see that she is the chairwoman.
 * She can, and the assertions below are mostly about pinning down how far
 * that goes and where it stops.
 *
 * Clara (X3) carries `1 RESN confidential`, so Anna — an ordinary member —
 * may not read her record at all. She is the same subject `RecognitionTest`
 * uses and for the same reason: a `RESN` needs none of the care a
 * relationship limit would (see that file's note on `Individual::isRelated()`).
 */
#[CoversNothing]
class OfficeTest extends PortalTestCase
{
    private User $anna;
    private User $clara;

    private int $anna_id;
    private int $clara_id;

    protected function setUp(): void
    {
        parent::setUp();

        $this->anna  = $this->createUser('anna', 'Anna Beispiel', 'correct-horse', UserInterface::ROLE_MEMBER, 'X1');
        $this->clara = $this->createUser('clara', 'Clara Beispiel', 'anderes-pferd', UserInterface::ROLE_MEMBER, 'X3');

        $this->anna_id  = $this->createProfile($this->anna, true);
        $this->clara_id = $this->createProfile($this->clara, true);

        $this->module()->setPreference(PortalApiModule::SETTING_MEMBER_CONNECTIONS, '1');
        $this->module()->setPreference(PortalApiModule::SETTING_PORTAL_URL, 'https://portal.example.test');

        $this->login($this->anna);
    }

    // -----------------------------------------------------------------
    // Helpers
    // -----------------------------------------------------------------

    private function offices(): Offices
    {
        return Registry::container()->get(Offices::class);
    }

    private function giveOffice(string $xref, string $title, int $order = 0): void
    {
        $this->offices()->set($xref, $title, $order);
    }

    /** One member's row of the directory, as Anna reads it. */
    private function row(int $member_id): array
    {
        foreach ($this->json($this->api(MemberList::class))['items'] as $item) {
            if ($item['id'] === $member_id) {
                return $item;
            }
        }

        self::fail('That member is not in the directory.');
    }

    // -----------------------------------------------------------------
    // Where the record can be read
    // -----------------------------------------------------------------

    public function testAnOfficeTravelsWithEveryMentionOfThePerson(): void
    {
        $this->giveOffice('X1', 'Vorsitzende des Vorstands');

        $this->login($this->clara);

        $row = $this->row($this->anna_id);

        self::assertNotNull($row['individual'], 'Clara can read Anna’s record, or this proves the wrong thing.');
        self::assertSame('Vorsitzende des Vorstands', $row['individual']['office']);
    }

    public function testTheRecordItselfCarriesIt(): void
    {
        $this->giveOffice('X1', 'Vorsitzende des Vorstands');

        $individual = $this->json($this->api(IndividualRead::class, attributes: ['xref' => 'X1']));

        self::assertSame('Vorsitzende des Vorstands', $individual['office']);
    }

    /** Nobody holds one, and the field still exists: a card reads it either way. */
    public function testSomebodyWithoutAnOfficeSaysSo(): void
    {
        $individual = $this->json($this->api(IndividualRead::class, attributes: ['xref' => 'X1']));

        self::assertArrayHasKey('office', $individual);
        self::assertNull($individual['office']);
    }

    /**
     * Where the record is readable there is one copy and it lives inside it.
     * A second beside it would be two answers to one question.
     */
    public function testAReadableRecordCarriesNoSecondCopy(): void
    {
        $this->giveOffice('X1', 'Vorsitzende des Vorstands');

        $this->login($this->clara);

        self::assertArrayNotHasKey('office', $this->row($this->anna_id));
    }

    // -----------------------------------------------------------------
    // Where it cannot
    // -----------------------------------------------------------------

    /**
     * The case this exists for. Anna may not read a word of Clara's record;
     * she can still see that Clara is the one to write to.
     */
    public function testAnOfficeCrossesTheLineAClosedRecordDraws(): void
    {
        $this->giveOffice('X3', 'Schriftführerin');

        $row = $this->row($this->clara_id);

        self::assertNull($row['individual'], 'Anna must not be able to read Clara’s record.');
        self::assertSame('Schriftführerin', $row['office']);
    }

    public function testTheMembersOwnPageCarriesItToo(): void
    {
        $this->giveOffice('X3', 'Schriftführerin');

        $member = $this->json($this->api(MemberRead::class, attributes: ['id' => $this->clara_id]));

        self::assertNull($member['individual_detail']);
        self::assertSame('Schriftführerin', $member['office']);
    }

    public function testAConnectionRequestCarriesItToo(): void
    {
        $this->giveOffice('X3', 'Schriftführerin');

        $this->login($this->clara);

        $this->api(
            ConnectionCreate::class,
            RequestMethodInterface::METHOD_POST,
            body: ['member_id' => $this->anna_id],
            headers: $this->csrfHeader(),
        );

        $this->login($this->anna);

        $incoming = $this->json($this->api(ConnectionList::class))['incoming'][0];

        self::assertNull($incoming['individual']);
        self::assertSame('Schriftführerin', $incoming['office']);
    }

    /**
     * The line is one field wide.
     *
     * An office must not become a keyhole: naming Clara's job says nothing
     * about her years, her nickname or how Anna is related to her, and the
     * nickname lives on the record and nowhere else, so it is the cleanest
     * proof that the record was not read.
     */
    public function testNothingElseComesWithIt(): void
    {
        $this->giveOffice('X3', 'Schriftführerin');

        $row = $this->row($this->clara_id);

        self::assertNull($row['individual']);
        self::assertArrayNotHasKey('lifespan', $row);
        self::assertArrayNotHasKey('relationship', $row);
        self::assertStringNotContainsString('Clärchen', $this->raw($this->api(MemberList::class)));
    }

    /**
     * And an office does not conjure a person into a list they were absent
     * from. Clara's record is closed, so reading it by xref is refused —
     * giving her an office changes nothing about that.
     */
    public function testAnOfficeDoesNotOpenTheRecord(): void
    {
        $this->giveOffice('X3', 'Schriftführerin');

        self::assertNull($this->json($this->api(IndividualRead::class, attributes: ['xref' => 'X3']))['office'] ?? null);
        self::assertNotSame(200, $this->api(IndividualRead::class, attributes: ['xref' => 'X3'])->getStatusCode());
    }

    // -----------------------------------------------------------------
    // Keeping the list
    // -----------------------------------------------------------------

    public function testAPersonHoldsOneOfficeAndChangingItReplacesIt(): void
    {
        $this->giveOffice('X1', 'Beisitzerin');
        $this->giveOffice('X1', 'Vorsitzende des Vorstands');

        self::assertSame(1, DB::table('portal_office')->where('xref', '=', 'X1')->count());
        self::assertSame(['X1' => 'Vorsitzende des Vorstands'], $this->offices()->all());
    }

    /** An emptied field is the only way a blank can be meant. */
    public function testAnEmptyTitleTakesTheOfficeAway(): void
    {
        $this->giveOffice('X1', 'Beisitzerin');

        $this->offices()->set('X1', '   ');

        self::assertSame([], $this->offices()->all());
    }

    public function testRemovingAnOfficeLeavesTheCardBare(): void
    {
        $this->giveOffice('X3', 'Schriftführerin');
        $this->offices()->remove('X3');

        self::assertNull($this->row($this->clara_id)['office']);
    }

    /** Saying nothing changed is how the screen knows not to claim it saved. */
    public function testSavingTheSameOfficeTwiceChangesNothing(): void
    {
        self::assertTrue($this->offices()->set('X1', 'Beisitzerin'));
        self::assertFalse($this->offices()->set('X1', 'Beisitzerin'));
    }

    public function testTitlesAreTidiedAndCapped(): void
    {
        $this->offices()->set('X1', "  Vorsitzende\tdes   Vorstands \n");

        self::assertSame('Vorsitzende des Vorstands', $this->offices()->titleFor('X1'));

        $this->offices()->set('X2', str_repeat('a', Offices::MAX_TITLE + 50));

        self::assertSame(Offices::MAX_TITLE, strlen((string) $this->offices()->titleFor('X2')));
    }

    /**
     * A record deleted in webtrees must not take the portal down with it. The
     * row is left behind on purpose (there is no foreign key) and simply
     * names nobody.
     */
    public function testAnOfficeOnARecordThatIsGoneNamesNobody(): void
    {
        $this->giveOffice('X999', 'Ehrenvorsitzender');

        self::assertNull($this->offices()->titleFor('X404'));
        self::assertSame('Ehrenvorsitzender', $this->offices()->titleFor('X999'));

        // And the directory is unbothered by it.
        self::assertNotSame([], $this->json($this->api(MemberList::class))['items']);
    }

    public function testTheListedOrderIsTheOneThatWasAskedFor(): void
    {
        $this->giveOffice('X2', 'Beisitzer', 2);
        $this->giveOffice('X1', 'Vorsitzende des Vorstands', 1);

        self::assertSame(
            ['X1', 'X2'],
            array_column($this->offices()->listed(), 'xref')
        );
    }
}
