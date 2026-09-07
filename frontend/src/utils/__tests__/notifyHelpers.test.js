import { describe, it, expect } from 'vitest'
import { STATUS_OPTIONS } from '../notifyHelpers.js'

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
