import type { VerticalNavItems } from '@/@layouts/types'
import axios from '@axios'
import { defineStore } from 'pinia'

export const useNavigationStore = defineStore('navigation', {
  state: () => ({
    items: [] as VerticalNavItems,
    loaded: false,
    loading: false,
    loadedUserId: null as number | null,
  }),

  actions: {
    getCurrentUserId(): number | null {
      try {
        const rawUser = localStorage.getItem('userData')

        if (!rawUser)
          return null

        const userData = JSON.parse(rawUser)

        return userData?.id
          ? Number(userData.id)
          : null
      }
      catch {
        return null
      }
    },

    normalizeNavigationItems(items: any[]): any[] {
      return items.map(item => {
        const badgeCount = Number(
          item.badge_count
          ?? item.badgeCount
          ?? item.badgeContent
          ?? 0,
        )

        const normalizedItem = {
          ...item,
        }

        if (badgeCount > 0) {
          normalizedItem.badge_count = badgeCount
          normalizedItem.badgeContent = badgeCount > 99
            ? '99+'
            : String(badgeCount)

          normalizedItem.badgeClass = item.badgeClass || 'bg-error'
        }
        else {
          delete normalizedItem.badge_count
          delete normalizedItem.badgeContent
          delete normalizedItem.badgeClass
        }

        if (Array.isArray(item.children)) {
          normalizedItem.children = this.normalizeNavigationItems(item.children)
        }

        return normalizedItem
      })
    },

    loadFromLocal(): void {
      try {
        const raw = localStorage.getItem('navItems')

        const parsedItems = raw
          ? JSON.parse(raw)
          : []

        this.items = Array.isArray(parsedItems)
          ? this.normalizeNavigationItems(parsedItems) as VerticalNavItems
          : []

        this.loadedUserId = this.getCurrentUserId()
        this.loaded = false
      }
      catch {
        this.items = []
        this.loadedUserId = null
        this.loaded = true
      }
    },

    async fetchFromApi(force = false): Promise<void> {
      if (this.loading)
        return

      const currentUserId = this.getCurrentUserId()

      const sameUser = (
        currentUserId !== null
        && this.loadedUserId !== null
        && currentUserId === this.loadedUserId
      )

      /*
      |--------------------------------------------------------------------------
      | Gunakan cache hanya jika:
      | - bukan force refresh
      | - menu sudah loaded
      | - user masih sama
      | - items sudah ada
      |--------------------------------------------------------------------------
      |
      | Untuk update badge approval PR/PO, panggil:
      | fetchFromApi(true)
      |--------------------------------------------------------------------------
      */
      // if (
      //   !force
      //   && this.loaded
      //   && sameUser
      //   && this.items.length > 0
      // ) {
      //   return
      // }

      this.loading = true

      try {
        const response = await axios.get('/master/menus/navigation', {
          headers: {
            Accept: 'application/json',
          },
          params: {
            _t: Date.now(),
          },
        })

        /*
        |--------------------------------------------------------------------------
        | Sesuaikan dengan bentuk response API
        |--------------------------------------------------------------------------
        |
        | Mendukung:
        | - response langsung array;
        | - response { data: [...] }.
        |--------------------------------------------------------------------------
        */
        const responseItems = Array.isArray(response.data)
          ? response.data
          : Array.isArray(response.data?.data)
            ? response.data.data
            : []

        const normalizedItems = this.normalizeNavigationItems(responseItems)

        this.items = normalizedItems as VerticalNavItems
        this.loadedUserId = currentUserId
        this.loaded = true

        console.log('[NAVIGATION] fetched items:', normalizedItems)

        localStorage.setItem(
          'navItems',
          JSON.stringify(normalizedItems),
        )
      }
      catch (error) {
        /*
        |--------------------------------------------------------------------------
        | Jangan biarkan menu akun lama tetap tampil jika fetch gagal
        |--------------------------------------------------------------------------
        */
        this.clearNavigation()

        throw error
      }
      finally {
        this.loading = false
      }
    },

    async refreshBadges(): Promise<void> {
      localStorage.removeItem('navItems')
      sessionStorage.removeItem('navItems')

      this.loaded = false

      await this.fetchFromApi(true)
    },

    setItems(items: VerticalNavItems): void {
      const normalizedItems = this.normalizeNavigationItems(
        Array.isArray(items)
          ? items
          : [],
      )

      this.items = normalizedItems as VerticalNavItems
      this.loadedUserId = this.getCurrentUserId()
      this.loaded = false

      localStorage.setItem(
        'navItems',
        JSON.stringify(this.items),
      )
    },

    clearNavigation(): void {
      /*
      |--------------------------------------------------------------------------
      | Kosongkan reactive state Pinia
      |--------------------------------------------------------------------------
      */
      this.items = []
      this.loaded = false
      this.loading = false
      this.loadedUserId = null

      /*
      |--------------------------------------------------------------------------
      | Hapus cache menu browser
      |--------------------------------------------------------------------------
      */
      localStorage.removeItem('navItems')
      sessionStorage.removeItem('navItems')
    },
  },
})