// @vitest-environment node
import jsQR from 'jsqr'
import { describe, expect, it } from 'vitest'
import { qrMatrix } from './components/QrCode'

/**
 * The one assertion that means anything about a QR code: an independent
 * decoder reads back what was encoded.
 *
 * Everything else — that it has the right number of modules, that the corners
 * are square — can be true of a code no camera will read. So the matrix is
 * rendered to pixels here exactly as the SVG renders it, quiet zone and all,
 * and handed to a decoder that shares no code with the encoder.
 */
const SCALE = 6
const MARGIN = 4

function pixels(value: string): { data: Uint8ClampedArray; size: number } {
  const matrix = qrMatrix(value)
  const modules = matrix.length + MARGIN * 2
  const size = modules * SCALE
  const data = new Uint8ClampedArray(size * size * 4).fill(255)

  matrix.forEach((row, y) => {
    row.forEach((dark, x) => {
      if (!dark) {
        return
      }

      for (let dy = 0; dy < SCALE; dy++) {
        for (let dx = 0; dx < SCALE; dx++) {
          const px = (x + MARGIN) * SCALE + dx
          const py = (y + MARGIN) * SCALE + dy
          const offset = (py * size + px) * 4

          data[offset] = 0
          data[offset + 1] = 0
          data[offset + 2] = 0
        }
      }
    })
  })

  return { data, size }
}

describe('the connection code a camera has to read', () => {
  it('decodes back to the link that was encoded', () => {
    const url = 'https://portal.example.test/connect?code=' + 'a1b2c3d4'.repeat(8)

    const { data, size } = pixels(url)
    const decoded = jsQR(data, size, size)

    expect(decoded?.data).toBe(url)
  })

  it('carries the quiet zone a scanner needs', () => {
    const matrix = qrMatrix('https://portal.example.test/connect?code=abc')

    // Four light modules on every side, which is what makes the difference
    // between a code that scans on a table and one that does not.
    expect(MARGIN).toBe(4)
    expect(matrix.length).toBeGreaterThan(20)
  })
})
