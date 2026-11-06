import { describe, expect, it } from 'vitest'
import { NAV_ITEMS, rolesFor, visibleNav } from './nav'

const ROLES = ['super_admin', 'atasan', 'hr', 'finance', 'karyawan']

describe('nav', () => {
  it('only shows pages that exist, and always the dashboard', () => {
    for (const role of ROLES) {
      const items = visibleNav(role)

      expect(items.every((item) => item.built)).toBe(true)
      expect(items.map((item) => item.path)).toContain('/')
    }
  })

  it('keeps finance out of the employee pages and lets every other role in', () => {
    expect(rolesFor('/employees')).not.toContain('finance')

    for (const role of ROLES.filter((r) => r !== 'finance')) {
      expect(rolesFor('/employees')).toContain(role)
    }
  })

  it('has unique paths and denies unknown paths by default', () => {
    const paths = NAV_ITEMS.map((item) => item.path)

    expect(new Set(paths).size).toBe(paths.length)
    expect(rolesFor('/tidak-ada')).toEqual([])
  })
})
