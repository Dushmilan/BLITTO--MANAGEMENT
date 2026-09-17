import { describe, it, expect } from 'vitest'
import { STATUS_OPTIONS, normalizeInventors, inventorSummary } from '../notifyHelpers.js'

describe('notifyHelpers', () => {
  it('exports STATUS_OPTIONS as an array', () => {
    expect(Array.isArray(STATUS_OPTIONS)).toBe(true)
  })

  it('contains all expected statuses in order', () => {
    expect(STATUS_OPTIONS).toEqual([
      'draft', 'filed', 'acknowledged',
      'defect_sheet_1', 'defect_sheet_2', 'defect_sheet_3',
      'granted', 'rejected',
    ])
  })
})

describe('normalizeInventors', () => {
  it('passes arrays through, normalizing entries', () => {
    expect(normalizeInventors([
      { inventor_name: 'A', inventor_email: 'a@x.y' },
      'b@x.y',
    ])).toEqual([
      { inventor_name: 'A', inventor_email: 'a@x.y' },
      { inventor_name: '', inventor_email: 'b@x.y' },
    ])
  })

  it('coerces a single email string or bare object', () => {
    expect(normalizeInventors('a@x.y')).toEqual([{ inventor_name: '', inventor_email: 'a@x.y' }])
    expect(normalizeInventors({ inventor_name: 'A' })).toEqual([{ inventor_name: 'A', inventor_email: '' }])
  })

  it('returns [] for empty values', () => {
    expect(normalizeInventors(null)).toEqual([])
    expect(normalizeInventors(undefined)).toEqual([])
    expect(normalizeInventors([])).toEqual([])
    expect(normalizeInventors('')).toEqual([])
  })
})

describe('inventorSummary', () => {
  it('summarizes first inventor plus overflow count', () => {
    expect(inventorSummary([{ inventor_name: 'A' }, { inventor_name: 'B' }, {}])).toEqual({ first: 'A', extra: 2 })
    expect(inventorSummary([{ inventor_email: 'a@x.y' }])).toEqual({ first: 'a@x.y', extra: 0 })
    expect(inventorSummary([])).toEqual({ first: '', extra: 0 })
  })
})
