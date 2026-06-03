import { setupLayouts } from 'virtual:generated-layouts'
import { createRouter, createWebHistory } from 'vue-router'
import { isUserLoggedIn } from './utils'
import routes from '~pages'
import { canNavigate } from '@layouts/plugins/casl'

const router = createRouter({
  history: createWebHistory(import.meta.env.BASE_URL),
  routes: [
    // ℹ️ We are redirecting to different pages based on role.
    // NOTE: Role is just for UI purposes. ACL is based on abilities.
    {
      path: '/',
      redirect: to => {
        const userData = JSON.parse(localStorage.getItem('userData') || '{}')
        const userRole = userData && userData.role ? userData.role : null

        if (userRole === 'admin')
          return { name: 'dashboards-crm' }
        if (userRole === 'client')
          return { name: 'access-control' }

        return { name: 'login', query: to.query }
      },
    },
    {
      path: '/pages/user-profile',
      redirect: () => ({ name: 'pages-user-profile-tab', params: { tab: 'profile' } }),
    },
    {
      path: '/pages/account-settings',
      redirect: () => ({ name: 'pages-account-settings-tab', params: { tab: 'account' } }),
    },
    ...setupLayouts(routes),
  ],
})

// Docs: https://router.vuejs.org/guide/advanced/navigation-guards.html#global-before-guards
router.beforeEach(to => {
  const isLoggedIn = isUserLoggedIn()

  const publicRoutes = ['login', 'not-authorized']

  /*
  |--------------------------------------------------------------------------
  | 1. Public route
  |--------------------------------------------------------------------------
  */
  if (publicRoutes.includes(String(to.name))) {
    if (isLoggedIn && to.name === 'login') {
      return { path: '/dashboards/crm' }
    }

    return true
  }

  /*
  |--------------------------------------------------------------------------
  | 2. Belum login
  |--------------------------------------------------------------------------
  */
  if (!isLoggedIn) {
    return {
      name: 'login',
      query: {
        to: to.fullPath !== '/' ? to.fullPath : undefined,
      },
    }
  }

  /*
  |--------------------------------------------------------------------------
  | 3. Sudah login tapi route khusus guest
  |--------------------------------------------------------------------------
  */
  if (to.meta.redirectIfLoggedIn) {
    return { path: '/dashboards/crm' }
  }

  /*
  |--------------------------------------------------------------------------
  | 4. Cek CASL permission
  |--------------------------------------------------------------------------
  */
  if (!canNavigate(to)) {
    return { name: 'not-authorized' }
  }

  return true
})

export default router