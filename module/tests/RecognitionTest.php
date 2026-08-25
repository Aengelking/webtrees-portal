<?php

declare(strict_types=1);

namespace Engelking\Webtrees\PortalApi\Tests;

use Engelking\Webtrees\PortalApi\Http\RequestHandlers\ConnectionCreate;
use Engelking\Webtrees\PortalApi\Http\RequestHandlers\ConnectionList;
use Engelking\Webtrees\PortalApi\Http\RequestHandlers\MediaRead;
use Engelking\Webtrees\PortalApi\Http\RequestHandlers\MemberList;
use Engelking\Webtrees\PortalApi\Http\RequestHandlers\MemberRead;
use Engelking\Webtrees\PortalApi\Http\RequestHandlers\PhotoCreate;
use Engelking\Webtrees\PortalApi\PortalApiModule;
use Fig\Http\Message\RequestMethodInterface;
use Fig\Http\Message\StatusCodeInterface;
use Fisharebest\Webtrees\Contracts\UserInterface;
use Fisharebest\Webtrees\DB;
use Fisharebest\Webtrees\Registry;
use Fisharebest\Webtrees\User;
use PHPUnit\Framework\Attributes\CoversNothing;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\StreamFactoryInterface;
use Psr\Http\Message\UploadedFileFactoryInterface;
use Psr\Http\Message\UploadedFileInterface;

use function array_column;
use function imagecolorallocate;
use function imagecreatetruecolor;
use function imagedestroy;
use function imagefill;
use function imagejpeg;
use function ob_get_clean;
use function ob_start;
use function strlen;

/**
 * What is left of a person when their record is closed to the reader.
 *
 * Clara (X3) carries `1 RESN confidential`, so Anna — an ordinary member —
 * may not read her record at all. Until now Clara appeared in the directory
 * and on a connection request as a name and white space: no face, no number,
 * nothing to recognise her by. `Recognition` lets exactly two things across
 * that line, and this is where the "exactly" is pinned.
 *
 * The point of Clara rather than a relationship limit: `Individual::isRelated()`
 * keeps its answers in a function-level `static` that is keyed by neither user
 * nor tree, so a test that hides somebody by path length has to run in its own
 * process to mean anything (see `VisibilityTest`). A `RESN` needs no such care.
 */
#[CoversNothing]
class RecognitionTest extends PortalTestCase
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

    /** Clara's row of the directory, as Anna reads it. */
    private function row(): array
    {
        foreach ($this->json($this->api(MemberList::class))['items'] as $item) {
            if ($item['id'] === $this->clara_id) {
                return $item;
            }
        }

        self::fail('Clara is not in the directory.');
    }

    /** Clara puts a photograph of herself into the portal, and hands back its xref. */
    private function claraUploads(): string
    {
        $this->login($this->clara);

        $response = $this->api(
            PhotoCreate::class,
            RequestMethodInterface::METHOD_POST,
            headers: $this->csrfHeader(),
            files: ['photo' => $this->file($this->jpeg())],
        );

        self::assertSame(StatusCodeInterface::STATUS_CREATED, $response->getStatusCode());

        $this->login($this->anna);

        return (string) DB::table('portal_photo')->where('wt_user_id', '=', $this->clara->id())->value('media_xref');
    }

    /** Withdraw the consent without removing the picture from the tree. */
    private function forgetTheConsent(): void
    {
        DB::table('portal_photo')->delete();
    }

    private function showNumbers(bool $shown): void
    {
        $this->module()->setPreference(PortalApiModule::SETTING_MEMBER_SHOW_NUMBER, $shown ? '1' : '0');
    }

    private function jpeg(): string
    {
        $image = imagecreatetruecolor(40, 30);

        imagefill($image, 0, 0, imagecolorallocate($image, 200, 120, 40));

        ob_start();
        imagejpeg($image);
        $bytes = (string) ob_get_clean();

        imagedestroy($image);

        return $bytes;
    }

    private function file(string $bytes): UploadedFileInterface
    {
        $stream = Registry::container()->get(StreamFactoryInterface::class)->createStream($bytes);

        return Registry::container()
            ->get(UploadedFileFactoryInterface::class)
            ->createUploadedFile($stream, strlen($bytes), UPLOAD_ERR_OK, 'foto.jpg', 'image/jpeg');
    }

    /** The picture's own URL, as the payload hands it out. */
    private function thumbnail(string $media_xref, string $fact): ResponseInterface
    {
        return $this->api(MediaRead::class, attributes: ['xref' => $media_xref, 'fact' => $fact, 'size' => 'thumbnail']);
    }

    // -----------------------------------------------------------------
    // The face
    // -----------------------------------------------------------------

    /**
     * The permission is Clara's own and was given for this portal. Honouring
     * webtrees' rule about the *family's* data against the person the data is
     * about would be the wrong way round.
     */
    public function testAPhotographSomebodyPutThereThemselvesCrossesTheLine(): void
    {
        $this->claraUploads();

        $row = $this->row();

        self::assertNull($row['individual'], 'Anna must not be able to read Clara’s record, or this proves nothing.');
        self::assertNotNull($row['portrait']);
    }

    /** The row in `portal_photo` *is* the permission, so losing it withdraws this too. */
    public function testWithoutThatPermissionThereIsNoFace(): void
    {
        $this->claraUploads();
        $this->forgetTheConsent();

        self::assertNull($this->row()['portrait']);
    }

    /**
     * The half that is easy to forget: a URL in the payload that answers 404
     * is a broken image rather than a face. `Media::canShowByType()` hides a
     * picture whose linked record is private, so `MediaRead` has to know the
     * same rule.
     */
    public function testThePhotographCanActuallyBeFetched(): void
    {
        $xref = $this->claraUploads();
        $fact = (string) $this->row()['portrait']['id'];

        self::assertSame(StatusCodeInterface::STATUS_OK, $this->thumbnail($xref, $fact)->getStatusCode());
    }

    public function testTheSamePictureIsRefusedOnceThePermissionIsGone(): void
    {
        $xref = $this->claraUploads();
        $fact = (string) $this->row()['portrait']['id'];

        $this->forgetTheConsent();

        self::assertSame(StatusCodeInterface::STATUS_NOT_FOUND, $this->thumbnail($xref, $fact)->getStatusCode());
    }

    // -----------------------------------------------------------------
    // The number
    // -----------------------------------------------------------------

    /** Off by default: this is the family's decision, not the portal's. */
    public function testTheArchiveNumberIsWithheldUntilTheFamilyPublishesIt(): void
    {
        self::assertSame([], $this->row()['references']);
    }

    public function testTheArchiveNumberAppearsWhereTheFamilyPublishesIt(): void
    {
        $this->showNumbers(true);

        self::assertSame(['4713'], array_column($this->row()['references'], 'number'));
    }

    /**
     * The same shape the record's own numbers have, branch and all.
     *
     * Two services build this list now — `RecordPresenter::references()` and
     * this one — and the card that reads it does not know which. One of them
     * being a field short is how one shape quietly becomes two. Clara's number
     * carries no oblique, so its branch is null (§2.67); what is asserted here
     * is that the field is *there*.
     */
    public function testTheNumberCarriesItsBranchLikeEverywhereElse(): void
    {
        $this->showNumbers(true);

        self::assertSame(
            [['number' => '4713', 'type' => 'SB', 'branch' => null]],
            $this->row()['references']
        );
    }

    /**
     * `Fact::canShow()` asks about the `RESN` on the fact rather than the
     * privacy of the record around it — which is exactly the half that belongs
     * here. Clara's second number is marked confidential and stays that way.
     */
    public function testAConfidentialNumberIsWithheldEvenThen(): void
    {
        $this->showNumbers(true);

        self::assertNotContains('8888', array_column($this->row()['references'], 'number'));
    }

    // -----------------------------------------------------------------
    // And nothing else
    // -----------------------------------------------------------------

    /**
     * The line is two fields wide. Not the name on the record, not the years,
     * not the nickname, not how the two are related: that is the archive's
     * account of a person, and the archive has said no.
     */
    public function testNothingElseFromTheRecordCrossesTheLine(): void
    {
        $this->claraUploads();
        $this->showNumbers(true);

        $row = $this->row();

        self::assertSame('Clara Beispiel', $row['display_name'], 'The name comes from the portal profile.');
        self::assertNull($row['individual']);
        self::assertArrayNotHasKey('relationship', $row);
        self::assertArrayNotHasKey('lifespan', $row);

        // The nickname is on the record and nowhere else, so it is the
        // cleanest proof that nothing was read out of it.
        self::assertStringNotContainsString('Clärchen', $this->raw($this->api(MemberList::class)));
    }

    /**
     * Where the record *is* readable, both fields live inside it and follow
     * its rules. A second copy beside it would be two answers to one question.
     */
    public function testAReadableRecordCarriesNoSecondCopy(): void
    {
        $this->showNumbers(true);
        $this->login($this->clara);

        foreach ($this->json($this->api(MemberList::class))['items'] as $item) {
            if ($item['id'] === $this->anna_id) {
                self::assertNotNull($item['individual'], 'Clara can read Anna’s record.');
                self::assertArrayNotHasKey('portrait', $item);
                self::assertArrayNotHasKey('references', $item);

                return;
            }
        }

        self::fail('Anna is not in the directory.');
    }

    // -----------------------------------------------------------------
    // The two other screens a person appears on
    // -----------------------------------------------------------------

    public function testTheMembersOwnPageCarriesThemToo(): void
    {
        $this->claraUploads();
        $this->showNumbers(true);

        $member = $this->json($this->api(MemberRead::class, attributes: ['id' => $this->clara_id]));

        self::assertNull($member['individual_detail']);
        self::assertNotNull($member['portrait']);
        self::assertSame(['4713'], array_column($member['references'], 'number'));
    }

    /**
     * The screen this started from: a request from somebody a member cannot
     * look up used to be a name and white space.
     */
    public function testAConnectionRequestCarriesThemToo(): void
    {
        $this->claraUploads();
        $this->showNumbers(true);

        $this->login($this->clara);

        $this->api(
            ConnectionCreate::class,
            RequestMethodInterface::METHOD_POST,
            body: ['member_id' => $this->anna_id],
            headers: $this->csrfHeader(),
        );

        $this->login($this->anna);

        $incoming = $this->json($this->api(ConnectionList::class))['incoming'][0];

        self::assertSame('Clara Beispiel', $incoming['name']);
        self::assertNull($incoming['individual']);
        self::assertNotNull($incoming['portrait']);
        self::assertSame(['4713'], array_column($incoming['references'], 'number'));
    }
}
