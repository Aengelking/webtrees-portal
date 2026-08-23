<?php

declare(strict_types=1);

namespace Engelking\Webtrees\PortalApi\Services;

use Engelking\Webtrees\PortalApi\Http\ApiException;
use Fisharebest\Webtrees\Contracts\UserInterface;
use Fisharebest\Webtrees\DB;
use Fisharebest\Webtrees\I18N;
use Fisharebest\Webtrees\Individual;
use Fisharebest\Webtrees\Media;
use Fisharebest\Webtrees\Registry;
use Fisharebest\Webtrees\Services\LinkedRecordService;
use Fisharebest\Webtrees\Services\PendingChangesService;
use Fisharebest\Webtrees\Tree;
use Psr\Http\Message\UploadedFileInterface;
use Throwable;

use function imagecreatefromstring;
use function imagedestroy;
use function imageinterlace;
use function imagejpeg;
use function imagepalettetotruecolor;
use function imagesx;
use function imagesy;
use function imagecopyresampled;
use function imagecreatetruecolor;
use function bin2hex;
use function explode;
use function getimagesizefromstring;
use function implode;
use function max;
use function preg_match;
use function preg_quote;
use function random_bytes;
use function round;
use function strlen;
use function ob_get_clean;
use function ob_start;
use function sprintf;
use function time;
use function trim;

/**
 * Photographs a member put there themselves.
 *
 * See `Schema/Migration9.php` for the rule and the argument behind it. This is
 * what applies it, and what lets a member give the permission in the first
 * place — a rule that hides photographs without offering a way to add one is
 * not a privacy feature, it is a portal with no faces in it.
 */
class Photos
{
    /** Four megabytes. A phone photograph is one or two; anything much larger is a mistake. */
    public const int MAX_BYTES = 4 * 1024 * 1024;

    /** Long edge. Enough for a full-screen view on any phone, far short of a print. */
    private const int MAX_EDGE = 1600;

    /** What a browser will actually produce from a camera roll. */
    private const array ACCEPTED = [
        'image/jpeg' => 'jpg',
        'image/png'  => 'jpg',
        'image/webp' => 'jpg',
    ];

    public function __construct(
        private readonly PortalTreeService $trees,
        private readonly PendingChanges $pending,
    ) {
    }

    /**
     * The xrefs of every photograph this portal has permission for.
     *
     * Asked once per record rather than once per photograph: a gallery is a
     * handful of pictures and this is one query either way.
     *
     * @return array<int,string>
     */
    public function consented(): array
    {
        return DB::table('portal_photo')->pluck('media_xref')->all();
    }

    /**
     * May this photograph be shown on this record?
     *
     * The living/dead split is the whole of it. For somebody dead, webtrees'
     * own access level has already decided and there is nobody left to ask; for
     * somebody living, the only permission that counts is their own.
     */
    public function mayShow(Individual $individual, Media $media): bool
    {
        if ($individual->isDead()) {
            return true;
        }

        return DB::table('portal_photo')->where('media_xref', '=', $media->xref())->exists();
    }

    /**
     * Did somebody put this photograph into the portal themselves?
     *
     * The same row `mayShow()` looks for, asked without a record in hand —
     * which is the point. A picture with a row is one its subject uploaded so
     * that the family could see them, and that permission does not lapse
     * because webtrees withholds the record it hangs on. See
     * `PhotoPresenter::consentedPortrait()` and `MediaRead`.
     */
    public function isPortalUpload(string $media_xref): bool
    {
        return DB::table('portal_photo')->where('media_xref', '=', $media_xref)->exists();
    }

    /**
     * The photographs this member put there, oldest first.
     *
     * Oldest rather than newest because the first one is the one they chose
     * when they were asked for a picture; the later ones are additions.
     *
     * @return array<int,string> media xrefs
     */
    public function uploadsOf(UserInterface $user): array
    {
        return DB::table('portal_photo')
            ->where('wt_user_id', '=', $user->id())
            ->orderBy('created_at')
            ->orderBy('id')
            ->pluck('media_xref')
            ->all();
    }

    /** Whether this member uploaded this photograph, and may therefore remove it. */
    public function uploadedBy(UserInterface $user, string $media_xref): bool
    {
        return DB::table('portal_photo')
            ->where('media_xref', '=', $media_xref)
            ->where('wt_user_id', '=', $user->id())
            ->exists();
    }

    /**
     * Take a photograph from a member and put it on their own record.
     *
     * **Re-encoded rather than stored.** What comes off a phone carries EXIF,
     * and EXIF carries where the picture was taken — a member sharing their
     * face would be publishing their home address to everyone who can read the
     * record. Decoding the pixels and writing a fresh JPEG drops every tag
     * there is, which is both simpler and more complete than deleting the ones
     * anybody has heard of. It also settles the "is this really an image"
     * question: bytes that will not decode do not become a file.
     */
    public function upload(UserInterface $user, Individual $individual, UploadedFileInterface $file): array
    {
        $bytes = $this->readable($file);
        $image = $this->decode($bytes);

        $tree      = $this->trees->tree();
        $name      = sprintf('portal/%d-%s.jpg', $user->id(), bin2hex(random_bytes(8)));
        $written   = $this->write($tree, $name, $image);

        if (!$written) {
            throw new ApiException(
                'server_error',
                500,
                I18N::translate('The photograph could not be saved. Please try again later.')
            );
        }

        // Asked *before* anything is written: whether the member already has an
        // edit waiting decides whether this photograph can go live at once.
        $waiting = $this->pending->existsFor($individual);

        $media = $this->record($tree, $individual, $name, $waiting);

        DB::table('portal_photo')->insert([
            'wt_user_id' => $user->id(),
            'media_xref' => $media->xref(),
            'created_at' => time(),
        ]);

        return ['media' => $media, 'pending' => $waiting];
    }

    /**
     * Take it down again.
     *
     * Only the member who uploaded it, and a photograph that is not theirs is
     * reported as not found rather than refused — the same answer everything
     * else in this module gives, so a member id is never a way to discover what
     * exists.
     *
     * The media record and the link go with it. This is the one place the
     * portal deletes from the family's tree, and it is right that it does: the
     * record only exists because a member gave permission, and permission
     * withdrawn leaves nothing behind that anybody agreed to.
     */
    public function remove(UserInterface $user, string $media_xref): void
    {
        if (!$this->uploadedBy($user, $media_xref)) {
            throw ApiException::notFound();
        }

        $tree  = $this->trees->tree();
        $media = Registry::mediaFactory()->make($media_xref, $tree);

        DB::table('portal_photo')->where('media_xref', '=', $media_xref)->delete();

        if ($media instanceof Media) {
            // Unlinks it from every record first — webtrees leaves a dangling
            // OBJE pointer otherwise, which shows up in its own integrity
            // checks as a broken link the family did not create.
            $links = Registry::container()->get(LinkedRecordService::class);

            foreach ($links->linkedIndividuals($media, 'OBJE') as $linked) {
                $linked->updateRecord($this->withoutLink($linked->gedcom(), $media_xref), false);
            }

            $media->deleteRecord();
        }
    }

    private function readable(UploadedFileInterface $file): string
    {
        if ($file->getError() !== UPLOAD_ERR_OK) {
            throw ApiException::badRequest(I18N::translate('The photograph could not be read.'));
        }

        if ($file->getSize() !== null && $file->getSize() > self::MAX_BYTES) {
            throw ApiException::badRequest(
                I18N::translate('That photograph is too large. Please choose one under %s.', '4 MB')
            );
        }

        $bytes = (string) $file->getStream();

        if ($bytes === '' || strlen($bytes) > self::MAX_BYTES) {
            throw ApiException::badRequest(
                I18N::translate('That photograph is too large. Please choose one under %s.', '4 MB')
            );
        }

        return $bytes;
    }

    /**
     * Bytes to pixels, and refuse anything that is not a picture.
     *
     * @return \GdImage
     */
    private function decode(string $bytes)
    {
        $size = getimagesizefromstring($bytes);

        if ($size === false || !isset(self::ACCEPTED[$size['mime']])) {
            throw ApiException::badRequest(
                I18N::translate('That file is not a photograph. JPEG, PNG and WebP can be used.')
            );
        }

        $image = @imagecreatefromstring($bytes);

        if ($image === false) {
            throw ApiException::badRequest(I18N::translate('The photograph could not be read.'));
        }

        return $this->shrink($image);
    }

    /**
     * Down to something a phone can display, and no further.
     *
     * @param \GdImage $image
     * @return \GdImage
     */
    private function shrink($image)
    {
        $width  = imagesx($image);
        $height = imagesy($image);
        $edge   = max($width, $height);

        if ($edge <= self::MAX_EDGE) {
            imagepalettetotruecolor($image);

            return $image;
        }

        $scale  = self::MAX_EDGE / $edge;
        $target = imagecreatetruecolor((int) round($width * $scale), (int) round($height * $scale));

        imagecopyresampled(
            $target,
            $image,
            0,
            0,
            0,
            0,
            imagesx($target),
            imagesy($target),
            $width,
            $height
        );

        imagedestroy($image);

        return $target;
    }

    /** @param \GdImage $image */
    private function write(Tree $tree, string $name, $image): bool
    {
        ob_start();
        imageinterlace($image, true);
        imagejpeg($image, null, 82);
        $jpeg = (string) ob_get_clean();

        imagedestroy($image);

        try {
            $tree->mediaFilesystem()->write($name, $jpeg);
        } catch (Throwable) {
            return false;
        }

        return true;
    }

    /**
     * The GEDCOM side: a media record, and a link from the person to it.
     *
     * Written straight rather than through webtrees' pending changes, because
     * this is the member's own consent about their own face — see the README.
     * Everything else a member may change about themselves still waits for the
     * family's approval, and that difference is deliberate: a name is a claim
     * about a person, a photograph is permission from one.
     */
    private function record(Tree $tree, Individual $individual, string $name, bool $waiting): Media
    {
        $gedcom = sprintf(
            "0 @@ OBJE\n1 FILE %s\n2 FORM jpg\n2 TITL %s",
            $name,
            trim(I18N::translate('Photograph'))
        );

        $media = $tree->createMediaObject($gedcom);
        $accept = Registry::container()->get(PendingChangesService::class);

        // The media record is brand new, so nothing else can be pending on it
        // and accepting "everything pending here" means accepting exactly this.
        $accept->acceptRecord($media);

        $individual->createFact('1 OBJE @' . $media->xref() . '@', false);

        // **And this is why the question above was asked first.** A pending
        // change in webtrees is a snapshot of the whole record, not a patch:
        // accepting the link would also accept whatever else was waiting on
        // that record — a name the family has not approved yet, for instance.
        // So where something is already waiting, the photograph waits with it,
        // and the member is told. Rare, and the alternative is approving an
        // edit on somebody else's behalf.
        if (!$waiting) {
            $accept->acceptRecord($individual);
        }

        return $media;
    }

    /** Every line of the OBJE block that points at this media record, removed. */
    private function withoutLink(string $gedcom, string $media_xref): string
    {
        $kept  = [];
        $skip  = false;

        foreach (explode("\n", $gedcom) as $line) {
            if (preg_match('/^1 OBJE @' . preg_quote($media_xref, '/') . '@$/', $line) === 1) {
                $skip = true;

                continue;
            }

            // Anything nested under the removed link goes with it.
            if ($skip && preg_match('/^[2-9] /', $line) === 1) {
                continue;
            }

            $skip = false;
            $kept[] = $line;
        }

        return implode("\n", $kept);
    }
}
