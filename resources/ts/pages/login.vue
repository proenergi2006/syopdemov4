<script setup lang="ts">
import { ref } from 'vue'
import { VForm } from 'vuetify/components'
import { useRoute, useRouter } from 'vue-router'
import { useAppAbility } from '@/plugins/casl/useAppAbility'
import AuthProvider from '@/views/pages/authentication/AuthProvider.vue'
import axios from '@axios'
import { useGenerateImageVariant } from '@core/composable/useGenerateImageVariant'
import tree from '@images/pages/tree.png'
import { VNodeRenderer } from '@layouts/components/VNodeRenderer'
import { themeConfig } from '@themeConfig'
import { emailValidator, requiredValidator } from '@validators'

import authV2LoginIllustrationBorderedDark from '@images/pages/auth-v2-login-illustration-bordered-dark.png'
import authV2LoginIllustrationBorderedLight from '@images/pages/auth-v2-login-illustration-bordered-light.png'
import authV2LoginIllustrationDark from '@images/pages/auth-v2-login-illustration-dark.png'
import authV2LoginIllustrationLight from '@images/pages/auth-v2-login-illustration-light.png'
import authV2MaskDark from '@images/pages/auth-v2-mask-dark.png'
import authV2MaskLight from '@images/pages/auth-v2-mask-light.png'

const isPasswordVisible = ref(false)
const refVForm = ref<VForm>()

const email = ref('admin@syop.local')
const password = ref('admin123')
const rememberMe = ref(false)

const errors = ref<Record<string, string | undefined>>({
  email: undefined,
  password: undefined,
})

const route = useRoute()
const router = useRouter()
const ability = useAppAbility()

const authThemeImg = useGenerateImageVariant(
  authV2LoginIllustrationLight,
  authV2LoginIllustrationDark,
  authV2LoginIllustrationBorderedLight,
  authV2LoginIllustrationBorderedDark,
  true,
)

const authThemeMask = useGenerateImageVariant(authV2MaskLight, authV2MaskDark)

const login = async () => {
  try {
    const { data } = await axios.post('/auth/login', {
      email: email.value,
      password: password.value,
    })

    // ✅ simpan token (pakai 1 key saja, konsisten)
    localStorage.setItem('accessToken', data.token)

    // ✅ userData
    localStorage.setItem(
      'userData',
      JSON.stringify({
        id: data.user.id,
        name: data.user.name,
        email: data.user.email,
        role: 'admin', // sementara
      }),
    )

    // ✅ abilities
    const abilities = [{ action: 'manage', subject: 'all' }]
    localStorage.setItem('userAbilities', JSON.stringify(abilities))
    ability.update(abilities)

    // ✅ set auth header untuk request selanjutnya
    axios.defaults.headers.common.Authorization = `Bearer ${data.token}`

    // ✅ (optional) ambil menu dulu (kalau endpoint ini sudah beres)
    // kalau masih error 500, comment dulu agar redirect tetap jalan
    try {
      const menuRes = await axios.get('/auth/my-menus')
      localStorage.setItem('navItems', JSON.stringify(menuRes.data))
    } catch (err) {
      console.warn('Fetch menu failed, redirect anyway:', err)
    }

    // ✅ redirect ke halaman setelah login
    const redirectTo = (route.query.to as string) || '/dashboards/crm'
    router.replace(redirectTo)
  }
  catch (e: any) {
    const res = e?.response
    console.error('LOGIN ERROR:', res?.status, res?.data || e)

    errors.value = { email: undefined, password: undefined }

    if (res?.status === 422 && res.data?.errors) {
      errors.value = {
        email: res.data.errors.email?.[0],
        password: res.data.errors.password?.[0],
      }
      return
    }

    if (res?.status === 401) {
      errors.value = {
        email: 'Email atau password salah',
        password: 'Email atau password salah',
      }
      return
    }

    errors.value = {
      email: 'Login gagal, cek console/network',
      password: 'Login gagal, cek console/network',
    }
  }
}


const onSubmit = () => {
  refVForm.value?.validate().then(({ valid }) => {
    if (valid)
      login()
  })
}
</script>

<template>
  <div>
    <div class="auth-logo d-flex align-start gap-x-3">
      <VNodeRenderer :nodes="themeConfig.app.logo" />
      <h1 class="font-weight-medium leading-normal text-2xl text-uppercase">
        {{ themeConfig.app.title }}
      </h1>
    </div>

    <VRow no-gutters class="auth-wrapper">
      <VCol lg="8" class="d-none d-lg-flex align-center justify-center position-relative">
        <VImg max-width="768px" :src="authThemeImg" class="auth-illustration" />
        <VImg :width="276" :src="tree" class="auth-footer-start-tree" />
        <VImg class="auth-footer-mask" :src="authThemeMask" />
      </VCol>

      <VCol cols="12" lg="4" class="auth-card-v2 d-flex align-center justify-center">
        <VCard flat :max-width="500" class="mt-12 mt-sm-0 pa-4">
          <VCardText>
            <h5 class="text-h5 mb-1">
  Welcome to {{ themeConfig.app.title }}
</h5>
<p class="mb-0">
  Please sign in with your account.
</p>
          </VCardText>

          <VCardText>
            <VForm ref="refVForm" @submit.prevent="onSubmit">
              <VRow>
                <VCol cols="12">
                  <VTextField
                    v-model="email"
                    label="Email"
                    type="email"
                    :rules="[requiredValidator, emailValidator]"
                    :error-messages="errors.email"
                  />
                </VCol>

                <VCol cols="12">
                  <VTextField
                    v-model="password"
                    label="Password"
                    :rules="[requiredValidator]"
                    :type="isPasswordVisible ? 'text' : 'password'"
                    :error-messages="errors.password"
                    :append-inner-icon="isPasswordVisible ? 'mdi-eye-off-outline' : 'mdi-eye-outline'"
                    @click:append-inner="isPasswordVisible = !isPasswordVisible"
                  />

                  <div class="d-flex align-center justify-space-between mt-1 mb-4">
                    <VCheckbox v-model="rememberMe" label="Remember me" />
                  </div>

                  <!-- ✅ HAPUS @click biar tidak dobel -->
                  <VBtn block type="submit">
                    Login
                  </VBtn>
                </VCol>

                <VCol cols="12" class="d-flex align-center">
                  <VDivider />
                  <span class="mx-4">-</span>
                  <VDivider />
                </VCol>

                <VCol cols="12" class="text-center mt-6">
  <div class="text-body-2 font-weight-medium text-primary">
    SYOP Version 4.0
  </div>

  <div class="text-caption text-medium-emphasis">
    Proenergi Operational System
  </div>
</VCol>

              </VRow>
            </VForm>
          </VCardText>
        </VCard>
      </VCol>
    </VRow>
  </div>
</template>

<style lang="scss">
@use "@core-scss/template/pages/page-auth.scss";
</style>

<route lang="yaml">
meta:
  layout: blank
  action: read
  subject: Auth
  redirectIfLoggedIn: true
</route>
