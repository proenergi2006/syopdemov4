import axios from '@/plugins/axios';
import router from '@/router';
import { defineStore } from 'pinia';

export const useAuthStore = defineStore('auth', {
  state: () => ({
    user: null as any,
    menus: [] as any[],
    loading: false,
  }),

  actions: {
    async login(payload: { email: string; password: string }) {
      this.loading = true
      try {
        const { data } = await axios.post('/auth/login', payload)
        localStorage.setItem('access_token', data.token)

        await this.fetchUser()
        await this.fetchMenus()

        router.push('/')
      } finally {
        this.loading = false
      }
    },

    async fetchUser() {
      const { data } = await axios.get('/auth/me')
      this.user = data
    },

    async fetchMenus() {
      const { data } = await axios.get('/auth/my-menus')
      this.menus = data
    },

    async logout() {
      try { await axios.post('/auth/logout') } catch {}
      localStorage.removeItem('access_token')
      this.user = null
      this.menus = []
      router.push('/login')
    },
  },
})
