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

/**
 * The reader's own archive number, where they have one the calculator can use.
 *
 * The archive has numbered people two ways over the years, and only one of
 * them is a path: a line, an oblique, then the descent. Anything else on a
 * record — an older plain number, an internal one — is a label, and handing it
 * to the calculator would answer "not a valid number" about somebody's own
 * card.
 *
 * The shape is checked here rather than asked of the server because it is the
 * same check the server makes and it saves a request that could only fail.
 */
export function ownReference(references: Reference[] | undefined): string | null {
  if (references === undefined) {
    return null
  }

  const path = references.find((reference) => /^\s*\d{1,2}\/[1-9a-z. ]+$/i.test(reference.number))

  return path === undefined ? null : path.number.trim()
}
