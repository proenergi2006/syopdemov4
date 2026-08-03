<script setup lang="ts">
import { useAppAbility } from '@/plugins/casl/useAppAbility'
import axios from '@axios'
import { requiredValidator } from '@validators'

import { ref, watch } from 'vue'
import { useI18n } from 'vue-i18n'
import { useRoute, useRouter } from 'vue-router'
import { VForm } from 'vuetify/components'
import { onMounted } from 'vue'
import { showErrorToast } from '@/utils/alert'

const { t } = useI18n()

const isPasswordVisible = ref(false)
const refVForm = ref<InstanceType<typeof VForm> | null>(null)
const loginLoading = ref(false)

const username = ref('')
const password = ref('')

const errors = ref<Record<'username' | 'password', string | undefined>>({
  username: undefined,
  password: undefined,
})

const route = useRoute()
const router = useRouter()
const ability = useAppAbility()

/**
 * Menghapus pesan error ketika user mulai mengetik kembali.
 */
watch(username, () => {
  errors.value.username = undefined
})

watch(password, () => {
  errors.value.password = undefined
})

const resetErrors = () => {
  errors.value = {
    username: undefined,
    password: undefined,
  }
}

const saveUserData = (authUser: any) => {
  const userData = {
    id: authUser?.id ?? null,
    name: authUser?.name ?? '-',
    username: authUser?.username ?? username.value,
    email: authUser?.email ?? null,

    role_id: authUser?.role_id ?? null,
    role: authUser?.role || authUser?.role_name || '-',
    roles: authUser?.roles || [],
    role_code: authUser?.role_code || authUser?.role_kode || null,

    cabang_id: authUser?.cabang_id ?? null,
    cabang: authUser?.cabang ?? null,

    department_id: authUser?.department_id ?? null,
    department: authUser?.department ?? null,
  }

  localStorage.setItem('userData', JSON.stringify(userData))
}

const login = async () => {
  if (loginLoading.value)
    return

  resetErrors()
  loginLoading.value = true

  try {
    /**
     * Proses login.
     */
    const loginResponse = await axios.post('/auth/login', {
      username: username.value,
      password: password.value,
    })

    const loginData = loginResponse.data

    /**
     * Pengaman jika backend mengembalikan HTTP 200,
     * tetapi payload menyatakan login gagal.
     */
    if (loginData?.success === false) {
      const field = loginData?.field

      if (field === 'username') {
        errors.value.username = t('auth.login.errors.usernameNotFound')
        return
      }

      if (field === 'password') {
        errors.value.password = t('auth.login.errors.incorrectPassword')
        return
      }

      errors.value.username = t('auth.login.errors.invalidCredentials')
      return
    }

    const token = loginData?.token

    if (!token)
      throw new Error(t('auth.login.errors.tokenMissing'))

    /**
     * Simpan token dan pasang pada header Axios.
     */
    localStorage.removeItem('authSource')
    localStorage.setItem('accessToken', token)

    axios.defaults.headers.common.Authorization = `Bearer ${token}`

    /**
     * Ambil data user terbaru.
     */
    const meResponse = await axios.get('/auth/me', {
      headers: {
        Accept: 'application/json',
      },
    })

    const authUser
      = meResponse.data?.data
        || meResponse.data?.user
        || loginData?.user

    if (!authUser)
      throw new Error(t('auth.login.errors.userDataMissing'))

    saveUserData(authUser)

    /**
     * Untuk sementara seluruh user diberikan ability manage all.
     * Nantinya dapat diganti dengan abilities dari backend.
     */
   const abilities = [
      {
        action: 'manage' as const,
        subject: 'all' as const,
      },
    ]

    localStorage.setItem(
      'userAbilities',
      JSON.stringify(abilities),
    )

    ability.update(abilities)

    /**
     * Mengambil menu yang diizinkan untuk user.
     * Kegagalan mengambil menu tidak membatalkan login.
     */
    try {
      const menuResponse = await axios.get('/master/menus/navigation')

      localStorage.setItem(
        'navItems',
        JSON.stringify(menuResponse.data),
      )
    }
    catch (menuError) {
      console.warn(
        'Fetch menu gagal, proses redirect tetap dilanjutkan:',
        menuError,
      )
    }

    /**
     * Redirect ke halaman yang sebelumnya dituju,
     * atau ke dashboard default.
     */
    const queryRedirect = route.query.to

    const redirectTo
      = typeof queryRedirect === 'string'
        ? queryRedirect
        : '/dashboards/crm'

    await router.replace(redirectTo)
  }
  catch (error: any) {
    const response = error?.response
    const status = Number(response?.status || 0)
    const data = response?.data

    console.error(
      'LOGIN ERROR:',
      status,
      data || error,
    )

    resetErrors()

    /*
    |--------------------------------------------------------------------------
    | Pesan error selalu dari i18n frontend, TIDAK PERNAH dari
    | data?.message backend
    |--------------------------------------------------------------------------
    | Backend hanya dipakai sebagai sinyal (status + field/errors) untuk
    | menentukan skenario mana yang terjadi. Teks yang ditampilkan ke user
    | selalu berasal dari kamus terjemahan, supaya konsisten mengikuti
    | bahasa yang dipilih user, bukan bahasa yang di-hardcode di controller.
    |--------------------------------------------------------------------------
    */

    /**
     * Validation error dan kesalahan kredensial
     * dengan status 422.
     */
    if (status === 422) {
      /**
       * Format validation Laravel (field wajib diisi):
       *
       * {
       *   errors: {
       *     username: [...],
       *     password: [...]
       *   }
       * }
       */
      if (data?.errors) {
        errors.value = {
          username: data.errors.username
            ? t('auth.login.errors.usernameRequired')
            : undefined,

          password: data.errors.password
            ? t('auth.login.errors.passwordRequired')
            : undefined,
        }

        return
      }

      /**
       * Format username/password salah:
       *
       * {
       *   success: false,
       *   field: "password",
       *   message: "Password salah."
       * }
       */
      const field = data?.field

      if (field === 'username') {
        errors.value.username = t('auth.login.errors.usernameNotFound')
        return
      }

      if (field === 'password') {
        errors.value.password = t('auth.login.errors.incorrectPassword')
        return
      }

      errors.value.username = t('auth.login.errors.invalidCredentials')
      return
    }

    /**
     * Tetap mendukung backend lama yang masih
     * mengembalikan status 401 untuk kredensial salah.
     */
    if (status === 401) {
      const field = data?.field

      if (field === 'username') {
        errors.value.username = t('auth.login.errors.usernameNotFound')
        return
      }

      if (field === 'password') {
        errors.value.password = t('auth.login.errors.incorrectPassword')
        return
      }

      errors.value.password = t('auth.login.errors.invalidCredentials')
      return
    }

    /**
     * Akun nonaktif atau akses ditolak.
     */
    if (status === 403) {
      errors.value.username = t('auth.login.errors.accountDisabled')

      return
    }

    /**
     * Benar-benar tidak menerima response dari server.
     * Contoh: timeout, DNS gagal, server mati, atau koneksi putus.
     */
    if (!response) {
      errors.value.username
        = t('auth.login.errors.connectionFailed')

      return
    }

    /**
     * Error server lainnya.
     */
    errors.value.username = t('auth.login.errors.generic')
  }
  finally {
    loginLoading.value = false
  }
}

const onSubmit = async () => {
  const validation = await refVForm.value?.validate()

  if (validation?.valid)
    await login()
}

onMounted(() => {
  const ssoLoginError
    = sessionStorage.getItem('ssoLoginError')

  if (!ssoLoginError)
    return

  /*
  |--------------------------------------------------------------------------
  | Hapus terlebih dahulu agar toast tidak muncul berulang kali
  |--------------------------------------------------------------------------
  */
  sessionStorage.removeItem('ssoLoginError')

  showErrorToast({
    title: t('auth.login.errors.ssoTitle'),
    text: ssoLoginError,
  })
})
</script>

<template>
  <div class="auth-card-content">
    <VCardText>
      <h1 class="text-h5 mb-1">
        {{ t('auth.login.appTitle') }}
      </h1>

      <p class="mb-0 text-medium-emphasis">
        {{ t('auth.login.subtitle') }}
      </p>
    </VCardText>

    <VCardText>
      <VForm
        ref="refVForm"
        @submit.prevent="onSubmit"
      >
        <VRow>
          <VCol cols="12">
            <VTextField
              v-model="username"
              :label="t('auth.login.usernameLabel')"
              :placeholder="t('auth.login.usernamePlaceholder')"
              :rules="[requiredValidator]"
              :error-messages="errors.username"
              prepend-inner-icon="mdi-account-outline"
              autocomplete="username"
              autofocus
            />
          </VCol>

          <VCol cols="12">
            <VTextField
              v-model="password"
              :label="t('auth.login.passwordLabel')"
              :placeholder="t('auth.login.passwordPlaceholder')"
              :rules="[requiredValidator]"
              :type="isPasswordVisible ? 'text' : 'password'"
              :error-messages="errors.password"
              :append-inner-icon="
                isPasswordVisible
                  ? 'mdi-eye-off-outline'
                  : 'mdi-eye-outline'
              "
              prepend-inner-icon="mdi-lock-outline"
              autocomplete="current-password"
              @click:append-inner="
                isPasswordVisible = !isPasswordVisible
              "
            />
          </VCol>

          <VCol
            cols="12"
            class="text-end mt-n2"
          >
            <RouterLink
              to="/forgot-password"
              class="text-primary text-body-2"
            >
              {{ t('auth.login.forgotPasswordLink') }}
            </RouterLink>
          </VCol>

          <VCol cols="12">
            <VBtn
              block
              type="submit"
              size="large"
              class="text-none"
              :loading="loginLoading"
              :disabled="loginLoading"
            >
              {{ t('auth.login.submit') }}
            </VBtn>
          </VCol>

          <VCol
            cols="12"
            class="d-flex align-center mt-4"
          >
            <VDivider />

            <span class="mx-4 text-medium-emphasis">
              -
            </span>

            <VDivider />
          </VCol>

          <VCol
            cols="12"
            class="text-center mt-4"
          >
            <div class="text-body-2 font-weight-medium text-primary">
              {{ t('auth.login.versionLabel') }}
            </div>

            <div class="text-caption text-medium-emphasis">
              {{ t('auth.login.footerTagline') }}
            </div>
          </VCol>
        </VRow>
      </VForm>
    </VCardText>
  </div>
</template>

<route lang="yaml">
meta:
  layout: auth
  action: read
  subject: Auth
  redirectIfLoggedIn: true
</route>