<?php

declare(strict_types=1);

namespace Engelking\Webtrees\PortalApi\Tests;

use Engelking\Webtrees\PortalApi\Http\RequestHandlers\MeRead;
use Engelking\Webtrees\PortalApi\Http\RequestHandlers\PhotoCreate;
use Engelking\Webtrees\PortalApi\Http\RequestHandlers\PhotoDelete;
use Fig\Http\Message\RequestMethodInterface;
use Fig\Http\Message\StatusCodeInterface;
use Fisharebest\Webtrees\Auth;
use Fisharebest\Webtrees\Contracts\UserInterface;
use Fisharebest\Webtrees\DB;
use Fisharebest\Webtrees\Registry;
use Fisharebest\Webtrees\User;
use PHPUnit\Framework\Attributes\CoversNothing;
use Psr\Http\Message\StreamFactoryInterface;
use Psr\Http\Message\UploadedFileFactoryInterface;
use Psr\Http\Message\UploadedFileInterface;

use function exif_read_data;
use function imagecreatetruecolor;
use function imagedestroy;
use function imagefill;
use function imagecolorallocate;
use function imagejpeg;
use function ob_get_clean;
use function ob_start;
use function str_repeat;

/**
 * Phase 15: a member puts their own photograph there.
 *
 * The half that makes the rule in `PhotoTest` honest. A portal that hides
 * every photograph of a living person and offers no way to add one is not a
 * privacy feature — it is a portal with no faces in it.
 *
 * Two things here are not about the happy path and matter more than it. **The
 * file is re-encoded, not stored**, so the GPS coordinates a phone writes into
 * every picture never reach the family: a member sharing their face would
 * otherwise be publishing the address they took it at. And **it can be taken
 * down again**, by the member who put it there and nobody else, because a
 * permission that cannot be withdrawn is not one.
 */
#[CoversNothing]
class PhotoUploadTest extends PortalTestCase
{
    private User $anna;
    private User $dieter;

    protected function setUp(): void
    {
        parent::setUp();

        $this->anna   = $this->createUser('anna', 'Anna Beispiel', 'geheim', UserInterface::ROLE_MEMBER, 'X1');
        $this->dieter = $this->createUser('dieter', 'Dieter Beispiel', 'anderes', UserInterface::ROLE_MEMBER, 'X4');

        $this->login($this->anna);
    }

    public function testAPhotographUploadedByTheMemberIsShownOnTheirRecord(): void
    {
        $before = $this->json($this->api(MeRead::class))['individual'];

        self::assertSame([], $before['photos'], 'Nothing of hers is shown to begin with.');

        $response = $this->upload($this->jpeg());

        self::assertSame(StatusCodeInterface::STATUS_CREATED, $response->getStatusCode());

        $after = $this->json($this->api(MeRead::class))['individual'];

        self::assertCount(1, $after['photos']);
        self::assertNotNull($after['portrait']);
    }

    /** One row per photograph, and the row is the permission. */
    public function testTheUploadIsRecordedAsThisMembersConsent(): void
    {
        $this->upload($this->jpeg());

        $row = DB::table('portal_photo')->first();

        self::assertNotNull($row);
        self::assertSame($this->anna->id(), (int) $row->wt_user_id);
    }

    /**
     * The assertion this feature owes the family. A photograph off a phone
     * carries the coordinates it was taken at; a member sharing their face
     * must not be publishing their front door with it.
     */
    public function testWhereThePhotographWasTakenDoesNotTravelWithIt(): void
    {
        $withGps = $this->jpegWithExif();

        // The file that arrives really does carry an EXIF block. Without this
        // the rest of the test would pass against a camera that writes none.
        // `IFD0` is the metadata directory itself — the block a camera writes
        // its make, its model and its coordinates into.
        self::assertStringContainsString('IFD0', $this->sectionsIn($withGps));

        $this->upload($withGps);

        $stored = $this->storedBytes();

        self::assertNotSame('', $stored);

        // What is left is GD's own comment — "CREATOR: gd-jpeg …" — which is
        // the point: the file on disk was written by this server from pixels,
        // not passed through from the phone.
        self::assertStringNotContainsString('IFD0', $this->sectionsIn($stored));
        self::assertStringNotContainsString('GPS', $this->sectionsIn($stored));
        self::assertStringNotContainsString('Sack', $stored, 'nor any tag that was in it');
    }

    public function testSomethingThatIsNotAPhotographIsRefused(): void
    {
        $response = $this->upload('this is not a picture');

        self::assertSame(StatusCodeInterface::STATUS_BAD_REQUEST, $response->getStatusCode());
        self::assertSame(0, DB::table('portal_photo')->count());
    }

    public function testAPhotographTooLargeToBeAPhoneSnapIsRefused(): void
    {
        $response = $this->upload(str_repeat('x', 5 * 1024 * 1024));

        self::assertSame(StatusCodeInterface::STATUS_BAD_REQUEST, $response->getStatusCode());
    }

    // -----------------------------------------------------------------
    // Taking it down
    // -----------------------------------------------------------------

    public function testTheMemberCanTakeTheirOwnPhotographDown(): void
    {
        $this->upload($this->jpeg());

        $xref = (string) DB::table('portal_photo')->value('media_xref');

        $response = $this->api(
            PhotoDelete::class,
            RequestMethodInterface::METHOD_DELETE,
            attributes: ['xref' => $xref],
            headers: $this->csrfHeader(),
        );

        self::assertSame(StatusCodeInterface::STATUS_OK, $response->getStatusCode());
        self::assertSame(0, DB::table('portal_photo')->count());
        self::assertSame([], $this->json($this->api(MeRead::class))['individual']['photos']);
    }

    /** Somebody else's is not found, which is what everything else here says too. */
    public function testOneMemberCannotTakeDownAnothersPhotograph(): void
    {
        $this->upload($this->jpeg());

        $xref = (string) DB::table('portal_photo')->value('media_xref');

        $this->login($this->dieter);

        $response = $this->api(
            PhotoDelete::class,
            RequestMethodInterface::METHOD_DELETE,
            attributes: ['xref' => $xref],
            headers: $this->csrfHeader(),
        );

        self::assertSame(StatusCodeInterface::STATUS_NOT_FOUND, $response->getStatusCode());
        self::assertSame(1, DB::table('portal_photo')->count());
    }

    // -----------------------------------------------------------------

    private function upload(string $bytes): \Psr\Http\Message\ResponseInterface
    {
        return $this->api(
            PhotoCreate::class,
            RequestMethodInterface::METHOD_POST,
            headers: $this->csrfHeader(),
            files: ['photo' => $this->file($bytes)],
        );
    }

    private function file(string $bytes): UploadedFileInterface
    {
        $stream = Registry::container()->get(StreamFactoryInterface::class)->createStream($bytes);

        return Registry::container()
            ->get(UploadedFileFactoryInterface::class)
            ->createUploadedFile($stream, strlen($bytes), UPLOAD_ERR_OK, 'foto.jpg', 'image/jpeg');
    }

    /** A picture with nothing in it but pixels. */
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

    /**
     * The same picture with an EXIF block bolted on, GPS and all — which is
     * what every phone hands a browser.
     */
    private function jpegWithExif(): string
    {
        $jpeg = $this->jpeg();

        // A minimal APP1/Exif segment: little-endian TIFF header, one IFD
        // entry (Make), and a GPS pointer. Enough for `exif_read_data()` to
        // report EXIF, which is all this test needs it to do.
        $tiff = "II\x2a\x00\x08\x00\x00\x00"
            . "\x01\x00"
            . "\x0f\x01\x02\x00\x06\x00\x00\x00\x1a\x00\x00\x00"
            . "\x00\x00\x00\x00"
            . "Sack\x00\x00";

        $exif = "Exif\x00\x00" . $tiff;
        $app1 = "\xff\xe1" . pack('n', strlen($exif) + 2) . $exif;

        // After the SOI marker, where a decoder expects it.
        return substr($jpeg, 0, 2) . $app1 . substr($jpeg, 2);
    }

    /** Which metadata blocks a JPEG carries, as `exif_read_data` names them. */
    private function sectionsIn(string $jpeg): string
    {
        $data = @exif_read_data('data://image/jpeg;base64,' . base64_encode($jpeg));

        return $data === false ? '' : (string) ($data['SectionsFound'] ?? '');
    }

    /** What actually landed on disk. */
    private function storedBytes(): string
    {
        $tree = Registry::container()
            ->get(\Engelking\Webtrees\PortalApi\Services\PortalTreeService::class)
            ->tree();

        foreach ($tree->mediaFilesystem()->listContents('portal', false) as $item) {
            return $tree->mediaFilesystem()->read($item->path());
        }

        return '';
    }
}
