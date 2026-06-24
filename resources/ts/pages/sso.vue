<script setup lang="ts">
import axios from '@axios'
import { useAppAbility } from '@/plugins/casl/useAppAbility'
import { onMounted } from 'vue'
import { useRoute, useRouter } from 'vue-router'

const route = useRoute()
const router = useRouter()
const ability = useAppAbility()

onMounted(async () => {
  try {
    const email = route.query.email

    if (!email) {
      router.replace('/login')
      return
    }

    // Login SSO
    const { data } = await axios.post('/auth/sso', {
      email,
    })

    // Simpan token
    localStorage.setItem('accessToken', data.token)

    // Set header bearer dulu
    axios.defaults.headers.common.Authorization = `Bearer ${data.token}`

    // Ambil user seperti login normal
    const me = await axios.get('/auth/me')

    localStorage.setItem(
      'userData',
      JSON.stringify(me.data),
    )

    // Ability
    const abilities = [
      {
        action: 'manage',
        subject: 'all',
      },
    ]

    localStorage.setItem(
      'userAbilities',
      JSON.stringify(abilities),
    )

    ability.update(abilities)

    // Menu
    try {
      const menuRes = await axios.get('/auth/my-menus')

      localStorage.setItem(
        'navItems',
        JSON.stringify(menuRes.data),
      )
    }
    catch (err) {
      console.warn('Fetch menu failed:', err)
    }

    // Redirect dashboard
    router.replace('/dashboards/crm')
  }
  catch (err) {
    console.error(err)
    router.replace('/login')
  }
})
</script>

<template>
  <div class="d-flex align-center justify-center fill-height">
    Loading SSO...
  </div>
</template>

<route lang="yaml">
meta:
  layout: blank
</route>