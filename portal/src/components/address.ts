import type { AddressParts, ContactEntry } from '../api/types'

/**
 * An address, as the four answers it is made of.
 *
 * The server owns this shape — see `ContactDetails::ADDRESS_PARTS` — and the
 * two halves of the composition have to agree, because both of them write it:
 * the server from the fields it was sent, and this from the same fields, so
 * that a module that predates them still receives a readable address in
 * `value`. The alternative is a portal one deployment ahead of its module
 * silently emptying everybody's address, and the two deploy separately.
 */
export const EMPTY_ADDRESS: AddressParts = { street: '', postcode: '', city: '', country: '' }

/**
 * The address as one readable piece of text, exactly as the server composes
 * it: street, then postcode and town on one line, then country, and an empty
 * field takes its line with it rather than leaving a gap.
 */
export function composeAddress(parts: AddressParts): string {
  const town = `${parts.postcode} ${parts.city}`.trim()

  return [parts.street.trim(), town, parts.country.trim()].filter((line) => line !== '').join('\n')
}

/**
 * The fields to put in the form for an entry that may not have any.
 *
 * A server that knows about fields always sends them — its own, or its best
 * reading of an address that was typed as one line. One that does not sends
 * only the text, and then the whole of it goes in the street, which is the
 * one place it cannot be wrong: nothing is lost, and the member's first save
 * puts each piece where it belongs.
 */
export function addressFields(entry: ContactEntry | undefined): AddressParts {
  if (entry === undefined) {
    return EMPTY_ADDRESS
  }

  if (entry.parts !== undefined) {
    return entry.parts
  }

  return { ...EMPTY_ADDRESS, street: entry.value.split('\n').join(', ') }
}
