/**
 * Renders public/icons/*.svg into the PNGs the manifest and iOS ask for.
 *
 * The two SVGs are the drawing. Safari reads none of the manifest's icons and
 * will not take an SVG for the home screen, and Android wants real pixels for
 * the maskable ones — so those are rendered from the SVGs rather than drawn a
 * second time. A second drawing is a second thing to forget to update.
 *
 * resvg is not a dependency of the portal: this runs by hand, on the rare
 * occasion the mark changes, and its output is committed.
 *
 *   cd portal
 *   npm install --no-save @resvg/resvg-js
 *   node tools/build-icons.mjs
 */
import { readFileSync, writeFileSync } from 'node:fs'
import { dirname, join } from 'node:path'
import { fileURLToPath } from 'node:url'
import { Resvg } from '@resvg/resvg-js'

const icons = join(dirname(fileURLToPath(import.meta.url)), '..', 'public', 'icons')
const read = (name) => readFileSync(join(icons, name), 'utf8')

const PARCHMENT = '#efe8da'

function write(name, svg, width, background) {
  const data = new Resvg(svg, { fitTo: { mode: 'width', value: width }, background }).render().asPng()
  writeFileSync(join(icons, name), data)
  console.log(`${name}  ${width}px  ${(data.length / 1024).toFixed(1)} kB`)
}

const icon = read('icon.svg')
const maskable = read('icon-maskable.svg')

// The manifest's "any" icons keep their own rounded corners, so the corners
// stay transparent and whatever is behind them shows through.
write('icon-192.png', icon, 192)
write('icon-512.png', icon, 512)

// iOS cuts its own squircle out of this and paints anything transparent
// black, so the corners are filled rather than rounded. The drawing inside is
// the same one, at the same inset.
write('apple-touch-icon.png', icon, 180, PARCHMENT)

// Already square to the edge by construction; the flag is belt and braces.
write('icon-maskable-192.png', maskable, 192, PARCHMENT)
write('icon-maskable-512.png', maskable, 512, PARCHMENT)
