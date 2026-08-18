import { describe, expect, it } from 'vitest'
import { gedcomToIso, isoToGedcom } from './dates'

describe('reading a GEDCOM date into the calendar field', () => {
  it('takes an exact day', () => {
    expect(gedcomToIso('12 MAR 1985')).toBe('1985-03-12')
    expect(gedcomToIso('1 JAN 1900')).toBe('1900-01-01')
    expect(gedcomToIso('31 DEC 2026')).toBe('2026-12-31')
  })

  it('is not fussy about spacing or case', () => {
    expect(gedcomToIso('  09 sep 1972 ')).toBe('1972-09-09')
  })

  /**
   * The important half. A date the picker cannot hold must come back as null,
   * so the form leaves the field empty — and because only changed fields are
   * sent, an empty field nobody touched leaves the stored date alone.
   */
  it('refuses anything that is not one exact day', () => {
    for (const fuzzy of [
      'ABT 1985',
      'BET 1980 AND 1985',
      'JUL 1985',
      '1985',
      'FROM 1980 TO 1985',
      'BEF 12 MAR 1985',
      '@#DJULIAN@ 12 MAR 1985',
      '',
      null,
      undefined,
    ]) {
      expect(gedcomToIso(fuzzy)).toBeNull()
    }
  })

  it('refuses a day that does not exist', () => {
    expect(gedcomToIso('31 FEB 1985')).toBeNull()
    expect(gedcomToIso('29 FEB 1900')).toBeNull()
    expect(gedcomToIso('12 XXX 1985')).toBeNull()
  })

  it('keeps a leap day', () => {
    expect(gedcomToIso('29 FEB 2024')).toBe('2024-02-29')
  })
})

describe('writing the calendar field back as GEDCOM', () => {
  it('uses the three-letter English month webtrees expects', () => {
    expect(isoToGedcom('1985-03-12')).toBe('12 MAR 1985')
    expect(isoToGedcom('1900-01-01')).toBe('1 JAN 1900')
    expect(isoToGedcom('2024-02-29')).toBe('29 FEB 2024')
  })

  it('round-trips', () => {
    for (const gedcom of ['12 MAR 1985', '1 JAN 1900', '29 FEB 2024']) {
      expect(isoToGedcom(gedcomToIso(gedcom) as string)).toBe(gedcom)
    }
  })

  it('returns null rather than guessing', () => {
    for (const bad of ['', 'today', '12 MAR 1985', '1985-13-01', '1985-3-1']) {
      expect(isoToGedcom(bad)).toBeNull()
    }
  })
})
