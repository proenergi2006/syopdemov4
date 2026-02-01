import type { VerticalNavItems } from '@/@layouts/types'

let items: VerticalNavItems = []

try {
  const raw = localStorage.getItem('navItems')
  items = raw ? JSON.parse(raw) : []
} catch {
  items = []
}

export default items
