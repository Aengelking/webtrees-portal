/**
 * Between the date picker and GEDCOM.
 *
 * The API speaks GEDCOM dates, because that is what is stored: "12 MAR 1985",
 * and also "ABT 1985", "BET 1980 AND 1985", "JUL 1985" and dates in other
 * calendars. A member editing their *own* record does not need any of that —
 * they know their birthday — so the form offers a calendar, and these two
 * functions are the whole of the translation.
 *
 * The API is deliberately not narrowed to match. It still accepts every form
 * webtrees does; it is only this one form, for this one field, that asks for
 * a plain date.
 */

const MONTHS = ['JAN', 'FEB', 'MAR', 'APR', 'MAY', 'JUN', 'JUL', 'AUG', 'SEP', 'OCT', 'NOV', 'DEC']

/**
 * "12 MAR 1985" → "1985-03-12", for an `<input type="date">`.
 *
 * Returns null for anything that is not one exact Gregorian day — an
 * approximation, a range, a month without a day, another calendar. The caller
 * must then leave the field empty rather than guess, because a date the
 * picker cannot hold is a date it must not silently replace.
 */
export function gedcomToIso(gedcom: string | null | undefined): string | null {
  if (gedcom === null || gedcom === undefined) {
    return null
  }

  const match = /^\s*(\d{1,2})\s+([A-Z]{3})\s+(\d{1,4})\s*$/i.exec(gedcom)

  if (match === null) {
    return null
  }

  const day = Number(match[1])
  const month = MONTHS.indexOf((match[2] as string).toUpperCase()) + 1
  const year = Number(match[3])

  if (month === 0 || day < 1 || year < 1) {
    return null
  }

  const iso = `${pad(year, 4)}-${pad(month, 2)}-${pad(day, 2)}`

  // 31 FEB parses as a shape and is not a day. Round-tripping through Date
  // catches it, and every other overflow, without a table of month lengths.
  const parsed = new Date(`${iso}T00:00:00Z`)

  if (Number.isNaN(parsed.getTime()) || parsed.getUTCDate() !== day || parsed.getUTCMonth() + 1 !== month) {
    return null
  }

  return iso
}

/**
 * "1985-03-12" → "12 MAR 1985".
 *
 * Returns null for anything else, so a caller cannot turn a value it does not
 * understand into a deletion. An `<input type="date">` only ever hands over
 * this shape or an empty string, so in practice this is a guard rather than a
 * branch that runs.
 */
export function isoToGedcom(iso: string): string | null {
  const match = /^(\d{4})-(\d{2})-(\d{2})$/.exec(iso.trim())

  if (match === null) {
    return null
  }

  const month = MONTHS[Number(match[2]) - 1]

  if (month === undefined) {
    return null
  }

  return `${Number(match[3])} ${month} ${Number(match[1])}`
}

function pad(value: number, width: number): string {
  return String(value).padStart(width, '0')
}
