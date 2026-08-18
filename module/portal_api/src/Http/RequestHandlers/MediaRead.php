<?php

declare(strict_types=1);

namespace Engelking\Webtrees\PortalApi\Http\RequestHandlers;

use Engelking\Webtrees\PortalApi\Http\ApiException;
use Engelking\Webtrees\PortalApi\Http\Middleware\ApiEnvelope;
use Engelking\Webtrees\PortalApi\Services\PhotoPresenter;
use Engelking\Webtrees\PortalApi\Services\PortalTreeService;
use Fisharebest\Webtrees\Auth;
use Fisharebest\Webtrees\Media;
use Fisharebest\Webtrees\MediaFile;
use Fisharebest\Webtrees\Registry;
use Fisharebest\Webtrees\Validator;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * GET /api/v1/media/{xref}/{fact}/thumbnail — and /image.
 *
 * The bytes of a photograph, served from the portal's own origin so that they
 * carry the member's session. See PhotoPresenter for why they cannot simply
 * come from webtrees' own media URL.
 *
 * The rendering is webtrees': its image factory produces the thumbnail, and
 * applies the watermark it would apply on its own pages for this user. The
 * only things decided here are whether this member may see the picture at all,
 * and how long a browser may keep it.
 */
class MediaRead implements RequestHandlerInterface
{
    /**
     * How long a browser may keep a photograph.
     *
     * This is the one response in the whole API that is not `no-store`, and it
     * is a deliberate exception rather than an oversight. Everything else here
     * is small JSON where re-fetching costs nothing; a gallery re-fetched on
     * every scroll costs a phone its battery and a shared host its bandwidth.
     *
     * `private` is what makes it safe: a browser may keep it, a shared cache
     * may not. webtrees' own header on these responses is
     * `public, max-age=31536000` — a year, in any cache that will have it —
     * which is fine for a site serving its own privacy-filtered pages and
     * quite wrong on the far side of a CDN.
     */
    public const int CACHE_SECONDS = 86400;

    public function __construct(
        private readonly PortalTreeService $trees,
        private readonly PhotoPresenter $photos,
    ) {
    }

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        $tree         = $this->trees->tree();
        $access_level = $this->trees->accessLevel($tree);
        $xref         = Validator::attributes($request)->string('xref');
        $fact         = Validator::attributes($request)->string('fact');
        $thumbnail    = Validator::attributes($request)->string('size', 'thumbnail') === 'thumbnail';

        $media = Registry::mediaFactory()->make($xref, $tree);

        // A picture this member may not see and one that does not exist give
        // the same answer, as everywhere else in this API.
        if (!$media instanceof Media || !$media->canShow($access_level)) {
            throw ApiException::notFound();
        }

        $file = $this->file($media, $fact);

        if (!$file instanceof MediaFile) {
            throw ApiException::notFound();
        }

        $factory   = Registry::imageFactory();
        $watermark = $thumbnail
            ? $factory->thumbnailNeedsWatermark($file, Auth::user())
            : $factory->fileNeedsWatermark($file, Auth::user());

        $response = $thumbnail
            ? $factory->mediaFileThumbnailResponse(
                $file,
                PhotoPresenter::THUMBNAIL_SIZE,
                PhotoPresenter::THUMBNAIL_SIZE,
                'crop',
                $watermark
            )
            : $factory->mediaFileResponse($file, $watermark, false);

        // ApiEnvelope turns this into a Cache-Control header and removes it.
        return $response->withHeader(ApiEnvelope::PRIVATE_CACHE_HEADER, (string) self::CACHE_SECONDS);
    }

    private function file(Media $media, string $fact): MediaFile|null
    {
        foreach ($media->mediaFiles() as $file) {
            // Not `isExternal()`: those are files on somebody else's server,
            // and the portal does not fetch arbitrary URLs on a member's
            // behalf. PhotoPresenter does not offer them either.
            if ($file->factId() === $fact && $file->isImage() && !$file->isExternal()) {
                return $file;
            }
        }

        return null;
    }
}
