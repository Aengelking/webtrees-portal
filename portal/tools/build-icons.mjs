/**
 * Renders public/icon.svg into the raster icons browsers still insist on.
 *
 * The SVG is the only drawing. Safari will not take an SVG for the home
 * screen, and no browser takes one for `favicon.ico`, so those are rendered
 * from it rather than drawn a second time — a second drawing is a second
 * thing to forget to update.
 *
 * resvg is not a dependency of the portal: this runs by hand, on the rare
 * occasion the mark changes, and the output is committed.
 *
 *   cd portal
 *   npm install --no-save @resvg/resvg-js
 *   node tools/build-icons.mjs
 */
import { readFileSync, writeFileSync } from 'node:fs'
import { dirname, join } from 'node:path'
import { fileURLToPath } from 'node:url'
import { Resvg } from '@resvg/resvg-js'

const publicDir = join(dirname(fileURLToPath(import.meta.url)), '..', 'public')
const source = readFileSync(join(publicDir, 'icon.svg'), 'utf8')

// The shield is drawn edge to edge in a 64-unit square. A home-screen icon
// needs air around it and something opaque behind it, and a maskable one needs
// enough of both that a circular crop still misses the mark.
const PARCHMENT = '#efe8da'

function tile(scale) {
  const inner = source.replace(/^[\s\S]*?<svg[^>]*>/, '').replace(/<\/svg>\s*$/, '')
  const size = 64 * scale
  const offset = (64 - size) / 2
  return `<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 64 64">
    <rect width="64" height="64" fill="${PARCHMENT}"/>
    <g transform="translate(${offset} ${offset}) scale(${scale})">${inner}</g>
  </svg>`
}

function png(svg, width) {
  return new Resvg(svg, { fitTo: { mode: 'width', value: width } }).render().asPng()
}

/**
 * An .ico is a directory of images with a 6-byte header and a 16-byte entry
 * each; every browser in use reads PNG payloads inside one, so the images go
 * in as they came out of the renderer.
 */
function ico(images) {
  const header = Buffer.alloc(6)
  header.writeUInt16LE(0, 0)
  header.writeUInt16LE(1, 2)
  header.writeUInt16LE(images.length, 4)

  let offset = 6 + images.length * 16
  const entries = images.map(({ size, data }) => {
    const entry = Buffer.alloc(16)
    entry.writeUInt8(size >= 256 ? 0 : size, 0)
    entry.writeUInt8(size >= 256 ? 0 : size, 1)
    entry.writeUInt16LE(1, 4)
    entry.writeUInt16LE(32, 6)
    entry.writeUInt32LE(data.length, 8)
    entry.writeUInt32LE(offset, 12)
    offset += data.length
    return entry
  })

  return Buffer.concat([header, ...entries, ...images.map((image) => image.data)])
}

const written = []
function write(name, data) {
  writeFileSync(join(publicDir, name), data)
  written.push(`${name} (${(data.length / 1024).toFixed(1)} kB)`)
}

// Transparent, because the browser tab it sits in may be any colour.
write('favicon.ico', ico([16, 32, 48].map((size) => ({ size, data: png(source, size) }))))

// Opaque and inset, because these land on a home screen.
write('apple-touch-icon.png', png(tile(0.82), 180))
write('icon-192.png', png(tile(0.82), 192))
write('icon-512.png', png(tile(0.82), 512))

// Android may crop this to a circle. 0.58 keeps the shield inside the safe
// area with the corners to spare.
write('icon-maskable-512.png', png(tile(0.58), 512))

console.log(written.join('\n'))
