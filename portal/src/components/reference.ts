import type { Reference } from '../api/types'

/**
 * The reference number as one line, or null where a record has none.
 *
 * In one place rather than four, because every card in the portal now shows
 * it and a number written three different ways is three numbers as far as a
 * reader is concerned. The type goes in front where the record names one —
 * "SB 214" rather than a bare 214 — since that is how the family says it.
 */
export function referenceLabel(references: Reference[] | undefined): string | null {
  if (references === undefined || references.length === 0) {
    return null
  }

  return references
    .map((reference) =>
      reference.type === null || reference.type === ''
        ? reference.number
        : `${reference.type} ${reference.number}`,
    )
    .join(' · ')
}
