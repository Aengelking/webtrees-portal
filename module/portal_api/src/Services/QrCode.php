<?php

declare(strict_types=1);

namespace Engelking\Webtrees\PortalApi\Services;

use TCPDF2DBarcode;
use Throwable;

use function class_exists;
use function error_log;
use function preg_match;
use function trim;

/**
 * A square somebody can point a telephone at.
 *
 * It exists because of print. A campaign link is sixty-four characters of hex
 * on the end of a URL, and the family magazine is read on paper by people who
 * are not going to type that — the link is useless there without a picture of
 * it beside it.
 *
 * **webtrees already carries the encoder**: TCPDF, which it uses for its own
 * reports, includes a 2D barcode generator. So nothing is vendored here and
 * nothing is fetched from a CDN — an administration page that phoned out to
 * an image service to draw a link to this family's portal would be handing
 * that address to a stranger for no reason.
 *
 * **Guarded, because a dependency is not a promise.** §2.70 is the lesson:
 * webtrees dropped `oscarotero/middleland` in 2.2.6 without warning anybody
 * outside itself. If TCPDF ever goes the same way, this returns an empty
 * string, the screen shows the link and no picture, and nothing fails.
 */
class QrCode
{
    /**
     * The QR code for this text as an inline SVG, or '' if it cannot be drawn.
     *
     * Error correction level M: the middle of the four, which survives a fold
     * and a coffee ring in a magazine without making the square so dense that
     * it needs a quarter of the page.
     */
    public static function svg(string $text): string
    {
        $text = trim($text);

        if ($text === '' || !class_exists(TCPDF2DBarcode::class)) {
            return '';
        }

        try {
            $svg = (new TCPDF2DBarcode($text, 'QRCODE,M'))->getBarcodeSVGcode(4, 4, 'black');
        } catch (Throwable $exception) {
            error_log('portal_api: could not draw a QR code: ' . $exception->getMessage());

            return '';
        }

        // TCPDF returns a whole document, XML declaration and all. What the
        // page needs is the drawing and only that: an XML declaration in the
        // middle of an HTML page is not markup a browser is obliged to make
        // sense of, and it is the only part that would land there.
        if (preg_match('~<svg\b.*</svg>~sui', $svg, $matches) !== 1) {
            return '';
        }

        return $matches[0];
    }
}
