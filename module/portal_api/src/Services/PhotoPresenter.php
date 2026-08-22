<?php

declare(strict_types=1);

namespace Engelking\Webtrees\PortalApi\Services;

use Fisharebest\Webtrees\Fact;
use Fisharebest\Webtrees\Individual;
use Fisharebest\Webtrees\Media;
use Fisharebest\Webtrees\MediaFile;

/**
 * The photographs on a record, as links the portal can put in an `<img>`.
 *
 * Two things about webtrees' own media URLs make them unusable here.
 *
 * They point at the webtrees host, built from its `base_url`. The member's
 * browser is on the portal's origin and the session cookie was deliberately
 * re-scoped to it, so such a request would arrive with no session at all,
 * `canShow()` would fail, and webtrees would answer with its "forbidden"
 * replacement image. Every picture in the portal would be a grey box.
 *
 * And they carry a signature over the resize parameters, computed from a
 * site-wide key, which is webtrees protecting itself against someone asking it
 * to render ten thousand sizes of the same photograph. That protection is
 * webtrees' business and stays there — the portal simply does not hand out
 * URLs that reach it.
 *
 * **And a third filter that is the portal's own**, not webtrees': a photograph
 * of a *living* person is shown only where that person uploaded it themselves.
 * `Photos` holds the rule and `Schema/Migration9.php` the argument for it.
 *
 * So the URLs here are the portal's own, relative to its origin, and the bytes
 * come back through `MediaRead`. What that costs is one more hop; what it buys
 * is that a photograph is subject to exactly the same session, the same access
 * level and the same `canShow()` as the name printed beside it.
 */
class PhotoPresenter
{
    public function __construct(private readonly Photos $photos)
    {
    }

    /** The thumbnail the portal shows beside a name. Square, and retina-sized. */
    public const int THUMBNAIL_SIZE = 160;

    /**
     * The one picture that stands for a person, or null.
     *
     * webtrees' own idea of "the" photograph — the first OBJE on the record
     * with an image in it — so the portal and webtrees agree about which face
     * belongs to a name.
     *
     * @return array<string,mixed>|null
     */
    public function portrait(Individual $individual, int $access_level): array|null
    {
        foreach ($this->visibleMedia($individual, $access_level) as $media) {
            $file = $media->firstImageFile();

            if ($file instanceof MediaFile) {
                return $this->photo($media, $file);
            }
        }

        return null;
    }

    /**
     * Every picture on the record this member may see.
     *
     * @return array<int,array<string,mixed>>
     */
    public function gallery(Individual $individual, int $access_level): array
    {
        $photos = [];

        foreach ($this->visibleMedia($individual, $access_level) as $media) {
            foreach ($media->mediaFiles() as $file) {
                // External files live on somebody else's server. Proxying them
                // would make the portal a fetcher of arbitrary URLs, and not
                // proxying them would leak the member's address to that
                // server. Neither is worth a photograph.
                if ($file->isImage() && !$file->isExternal()) {
                    $photos[] = $this->photo($media, $file);
                }
            }
        }

        return $photos;
    }

    /**
     * The media records linked to this individual that the member may see.
     *
     * Two filters, because they answer different questions: `facts()` decides
     * whether the *link* is visible at this access level, and `canShow()`
     * decides whether the media record itself is. A record can be restricted
     * without its links being, and the picture is the thing being protected.
     *
     * @return array<int,Media>
     */
    private function visibleMedia(Individual $individual, int $access_level): array
    {
        $media = [];

        foreach ($individual->facts(['OBJE'], false, $access_level, true) as $fact) {
            $target = $fact->target();

            if (!$target instanceof Media || !$target->canShow($access_level)) {
                continue;
            }

            // The third filter, and the only one webtrees cannot make: a
            // living person's photograph is shown only if they put it there.
            // See `Photos` and `Schema/Migration9.php` — what the family tree
            // happens to hold about somebody living is not something they
            // agreed to publish, and a face is the least deniable thing on a
            // record.
            if (!$this->photos->mayShow($individual, $target)) {
                continue;
            }

            $media[] = $target;
        }

        return $media;
    }

    /**
     * @return array<string,mixed>
     */
    private function photo(Media $media, MediaFile $file): array
    {
        $title = trim($file->title());
        $base  = '/api/v1/media/' . rawurlencode($media->xref()) . '/' . $file->factId();

        return [
            'id'            => $file->factId(),
            'title'         => $title === '' ? null : $title,
            'thumbnail_url' => $base . '/thumbnail',
            'image_url'     => $base . '/image',
        ];
    }
}
