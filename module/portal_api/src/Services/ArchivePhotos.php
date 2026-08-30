<?php

declare(strict_types=1);

namespace Engelking\Webtrees\PortalApi\Services;

use Engelking\Webtrees\PortalApi\PortalApiModule;
use Fisharebest\Webtrees\Auth;
use Fisharebest\Webtrees\Individual;
use Fisharebest\Webtrees\Media;
use Fisharebest\Webtrees\MediaFile;
use Fisharebest\Webtrees\Registry;
use Fisharebest\Webtrees\Services\LinkedRecordService;
use Throwable;

use function base64_encode;
use function error_log;
use function getimagesizefromstring;
use function in_array;
use function min;
use function preg_match;
use function trim;

/**
 * The photographs an assistant may be handed, and the bytes of one of them.
 *
 * **The rule is borrowed, not invented.** A picture leaves here only if the
 * portal would show it on the page of somebody this archive may name — that
 * is, `PhotoPresenter::visibleMedia()` put it on a dead person's record. Every
 * filter behind that answer applies unchanged: webtrees' own privacy on the
 * media object, the `RESN` on the link, and the consent a living person gave
 * or did not give for a photograph of themselves (`Schema/Migration9.php`).
 * Writing a second opinion here about who is in a photograph would be writing
 * a rule that drifts from the one the portal enforces, and the drift would be
 * invisible until it mattered.
 *
 * **What the rule cannot do.** It reasons about *records*, and a photograph is
 * not a record: a group picture on Bertha's page may show her living
 * grandchildren, and no code here knows that. This is a real difference from
 * the text the other tools return, and it is why photographs are off by
 * default and switched on deliberately.
 *
 * **Addressed the way the portal addresses them**, `M3/<fact id>` — the media
 * record and the file inside it. A media object may hold several files, and an
 * id naming only the record would make all but the first unreachable. The
 * model never has to build one of these: `get_person` hands them out.
 */
final class ArchivePhotos
{
    /**
     * The longest edge a picture is scaled down to before it is handed over.
     *
     * Claude bills an image in 28x28 patches, so this box costs at most
     * ceil(1200/28) x ceil(1200/28) visual tokens, and a landscape photograph
     * around 1400. That is a few percent of what the text of a full record
     * costs, and enough resolution to read a caption written on the back or an
     * inscription on a stone, which is what this is for.
     *
     * Never *up* — a small scan stays small. webtrees' `contain` fit would
     * enlarge it to fill the box, spending thirty times the tokens on exactly
     * the pictures that carry the least, so the box handed to it is capped at
     * the file's own size.
     */
    public const int MAX_EDGE = 1200;

    /**
     * The formats a picture may leave in.
     *
     * Not what webtrees can read — what the far end can look at. Anything else
     * in the archive is simply not listed and not fetchable, which keeps one
     * answer for "no such picture" instead of two.
     *
     * @var array<int,string>
     */
    private const array SERVABLE = ['image/jpeg', 'image/png', 'image/gif', 'image/webp'];

    public function __construct(
        private readonly PortalApiModule $module,
        private readonly PortalTreeService $trees,
        private readonly PhotoPresenter $photos,
        private readonly DeceasedOnly $rule,
        private readonly LinkedRecordService $links,
    ) {
    }

    public function published(): bool
    {
        return $this->module->getPreference(PortalApiModule::SETTING_MCP_PHOTOS, '0') === '1';
    }

    /**
     * What `get_person` lists: an id to ask for and whatever the family called
     * it. No bytes, no dimensions — a few words each, so that a model knows a
     * picture exists and can decide whether to spend a look on it.
     *
     * @return array<int,array<string,string|null>>
     */
    public function forRecord(Individual $individual, int $access_level): array
    {
        if (!$this->published()) {
            return [];
        }

        $listed = [];

        foreach ($this->photos->visibleMedia($individual, $access_level) as $media) {
            foreach ($this->servableFiles($media) as $file) {
                $title = trim($file->title());

                $listed[] = [
                    'id'    => $media->xref() . '/' . $file->factId(),
                    'title' => $title === '' ? null : $title,
                ];
            }
        }

        return $listed;
    }

    /**
     * One photograph, scaled and encoded.
     *
     * @return array<string,mixed>|null null for "no such picture", for "not
     *                                  one this archive may hand over" and for
     *                                  "this server could not read it" alike.
     *                                  The first two must not be told apart,
     *                                  and the third is rare enough that a
     *                                  third answer would cost more clarity
     *                                  than it buys — it is in the log.
     */
    public function one(string $id): array|null
    {
        if (!$this->published() || preg_match('#^([A-Za-z0-9_.\-]{1,20})/([0-9a-f]{32})$#', $id, $match) !== 1) {
            return null;
        }

        $tree  = $this->trees->tree();
        $level = $this->trees->accessLevel($tree);
        $media = Registry::mediaFactory()->make($match[1], $tree);

        if (!$media instanceof Media) {
            return null;
        }

        $people = $this->nameable($media, $level);

        if ($people === []) {
            return null;
        }

        $file = $this->fileIn($media, $match[2]);

        return $file === null ? null : $this->encode($media, $file, $people);
    }

    /**
     * The people this picture hangs on that the archive may name — and the
     * proof that it may be handed over at all.
     *
     * An empty answer means both at once: nobody dead is on it, or the portal
     * would not show it on the page of the one who is. The names travel with
     * the picture because "who is this of" is the first thing anybody asks,
     * and the living linked to the same photograph are not among them.
     *
     * @return array<int,string>
     */
    private function nameable(Media $media, int $level): array
    {
        $names = [];

        foreach ($this->links->linkedIndividuals($media, 'OBJE') as $individual) {
            if (!$individual instanceof Individual || !$this->rule->mayRead($individual, $level)) {
                continue;
            }

            foreach ($this->photos->visibleMedia($individual, $level) as $shown) {
                if ($shown->xref() === $media->xref()) {
                    $names[] = $individual->fullName();

                    break;
                }
            }
        }

        return $names;
    }

    /**
     * The image files in a media record that may be looked at.
     *
     * Read at `PRIV_HIDE` for the reason `PhotoPresenter::firstImage()` gives:
     * `facts()` hands back nothing at all for a record the reader may not see,
     * and whether this reader may see this picture was settled before we got
     * here. What is left is reading the file name.
     *
     * @return array<int,MediaFile>
     */
    private function servableFiles(Media $media): array
    {
        $files = [];

        foreach ($media->facts(['FILE'], false, Auth::PRIV_HIDE) as $fact) {
            $file = new MediaFile($fact->gedcom(), $media);

            // Not external: those are files on somebody else's server, and
            // this module does not fetch arbitrary URLs on anybody's behalf.
            if ($file->isImage() && !$file->isExternal() && in_array($file->mimeType(), self::SERVABLE, true)) {
                $files[] = $file;
            }
        }

        return $files;
    }

    private function fileIn(Media $media, string $fact_id): MediaFile|null
    {
        foreach ($this->servableFiles($media) as $file) {
            if ($file->factId() === $fact_id) {
                return $file;
            }
        }

        return null;
    }

    /**
     * @param array<int,string> $people
     *
     * @return array<string,mixed>|null
     */
    private function encode(Media $media, MediaFile $file, array $people): array|null
    {
        try {
            $original = $media->tree()->mediaFilesystem()->read($file->filename());
            $size     = getimagesizefromstring($original);

            if ($size === false) {
                return null;
            }

            [$width, $height] = $size;

            $factory = Registry::imageFactory();

            // The same watermark webtrees would put on this picture for this
            // account on its own pages. A token reads as somebody, and that
            // somebody's watermark is theirs.
            //
            // webtrees hands back a *response*, and there is no method that
            // hands back bytes — see §2.96, where calling one that does not
            // exist made every photograph answer "no such photograph".
            $response = $factory->mediaFileThumbnailResponse(
                $file,
                min($width, self::MAX_EDGE),
                min($height, self::MAX_EDGE),
                'contain',
                $factory->fileNeedsWatermark($file, Auth::user()),
            );

            // **A failure here is a picture, not an exception.** When webtrees
            // cannot read or resize a file it answers with a placeholder — the
            // word "500" drawn on a square — and says so only in this header.
            // Reading the body without looking would hand an assistant that
            // placeholder as if it were somebody's photograph, captioned with
            // their name.
            if ($response->hasHeader('x-thumbnail-exception')) {
                error_log(
                    'portal_api: mcp: photo ' . $media->xref() . ': '
                    . $response->getHeaderLine('x-thumbnail-exception')
                );

                return null;
            }

            $bytes = (string) $response->getBody();
        } catch (Throwable $exception) {
            error_log('portal_api: mcp: photo ' . $media->xref() . ': ' . $exception::class . ': ' . $exception->getMessage());

            return null;
        }

        $title = trim($file->title());

        return [
            'data'   => base64_encode($bytes),
            'mime'   => $file->mimeType(),
            'title'  => $title === '' ? null : $title,
            'people' => $people,
            'width'  => $width,
            'height' => $height,
        ];
    }
}
