import qrcode from 'qrcode-generator'

/**
 * A QR code, drawn as one SVG path.
 *
 * **What is in the code is a link to the portal**, not a token for something
 * to interpret. That is the whole design: every telephone's own camera reads
 * a URL and offers to open it, so the member being scanned needs no app, and
 * the portal needs no camera permission, no scanner and no barcode library
 * on the reading side — which matters, because the browser API for that
 * (`BarcodeDetector`) does not exist on iOS at all.
 *
 * Drawn here rather than fetched as an image: the code is a credential with a
 * quarter of an hour to live, and a URL that renders it would put it in a
 * webserver log, in the browser's cache and in the `src` of an element any
 * script on the page can read.
 *
 * One `<path>` rather than a rectangle per module: a version-4 code is about
 * a thousand modules, and a thousand SVG elements is a slow screen on the
 * telephone this is meant to be held up on.
 */

/** The quiet zone the specification asks for, in modules. Four, on each side. */
const MARGIN = 4

/**
 * The dark/light matrix, without the quiet zone.
 *
 * Exported because it is what the test can decode: `QrCode.test.tsx` renders
 * this to pixels and reads it back with an independent decoder, which is the
 * only assertion that means "a camera would read this".
 */
export function qrMatrix(value: string): boolean[][] {
  // Type 0 picks the smallest version the data fits in. Error correction M —
  // the usual choice for a code on a screen: it survives a thumb over one
  // corner without doubling the number of modules an old camera has to
  // resolve.
  const qr = qrcode(0, 'M')
  qr.addData(value)
  qr.make()

  const count = qr.getModuleCount()

  return Array.from({ length: count }, (_, row) =>
    Array.from({ length: count }, (_, column) => qr.isDark(row, column)),
  )
}

function path(matrix: boolean[][]): string {
  const parts: string[] = []

  matrix.forEach((row, y) => {
    row.forEach((dark, x) => {
      if (dark) {
        parts.push(`M${x + MARGIN} ${y + MARGIN}h1v1h-1z`)
      }
    })
  })

  return parts.join('')
}

export function QrCode({ value, label }: { value: string; label: string }) {
  const matrix = qrMatrix(value)
  const size = matrix.length + MARGIN * 2

  return (
    <svg
      role="img"
      aria-label={label}
      viewBox={`0 0 ${size} ${size}`}
      // A QR code is read as light-on-dark contrast, so the white is drawn
      // rather than left to whatever is behind it.
      className="h-auto w-full max-w-[18rem] rounded-lg bg-white p-2"
      shapeRendering="crispEdges"
    >
      <rect width={size} height={size} fill="#ffffff" />
      <path d={path(matrix)} fill="#0f172a" />
    </svg>
  )
}
