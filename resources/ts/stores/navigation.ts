import type { VerticalNavItems } from '@/@layouts/types'
import axios from '@axios'
import { defineStore } from 'pinia'

export const useNavigationStore = defineStore('navigation', {
  state: () => ({
    items: [] as VerticalNavItems,
    loaded: false,
  }),

  actions: {
    loadFromLocal() {
      try {
        const raw = localStorage.getItem('navItems')
        this.items = raw ? JSON.parse(raw) : []
        this.loaded = true
      }
      catch {
        this.items = []
        this.loaded = true
      }
    },

    async fetchFromApi() {
      const { data } = await axios.get('/auth/my-menus')
      this.items = data
      localStorage.setItem('navItems', JSON.stringify(data))
      this.loaded = true
    },
  },
})
