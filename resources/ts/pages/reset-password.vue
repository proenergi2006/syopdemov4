<script setup lang="ts">
import {
  computed,
  onBeforeUnmount,
  onMounted,
  ref,
} from 'vue'
import { useI18n } from 'vue-i18n'
import { useRoute, useRouter } from 'vue-router'
import axios from '@axios'
import LanguageSwitcher from '@/layouts/components/NavBarI18n.vue'
import { showErrorToast } from '@/utils/alert'
import { clearAuthSession } from '@/router'

const { t } = useI18n()
const route = useRoute()
const router = useRouter()

const REDIRECT_DELAY_MS = 2500

const token = ref('')
const email = ref('')

const isNewPasswordVisible = ref(false)
const isConfirmPasswordVisible = ref(false)

const newPassword = ref('')
const confirmPassword = ref('')

const submitLoading = ref(false)
const isSubmitted = ref(false)
const isSuccess = ref(false)
const isLinkInvalid = ref(false)

/*
 * Cek validitas token dulu sebelum form ditampilkan, supaya link yang
 * sudah kedaluwarsa langsung ketahuan begitu halaman dibuka -- tidak
 * perlu user isi form dan submit dulu baru tahu link-nya sudah mati.
 */
const isVerifying = ref(true)

let redirectTimer: ReturnType<typeof setTimeout> | null = null

/*
|--------------------------------------------------------------------------
| Password Strength
|--------------------------------------------------------------------------
| Sama persis dengan aturan di AccountSettingsSecurity.vue (dan di-enforce
| ulang di backend), supaya kebijakan password konsisten di seluruh
| aplikasi -- baik saat ganti password dari akun sendiri maupun dari
| link reset password.
|--------------------------------------------------------------------------
*/
const hasMinLength = computed(() => newPassword.value.length >= 8)
const hasLowercase = computed(() => /[a-z]/.test(newPassword.value))
const hasUppercase = computed(() => /[A-Z]/.test(newPassword.value))
const hasNumber = computed(() => /\d/.test(newPassword.value))
const hasSymbol = computed(() => /[!@#$%^&*(),.?":{}|<>_\-+=/\\[\]`;']/.test(newPassword.value))

const isConfirmPasswordMatch = computed(() => {
  if (!confirmPassword.value)
    return false

  return newPassword.value === confirmPassword.value
})

const passwordRequirements = computed(() => [
  {
    text: t('auth.resetPassword.requirements.minLength'),
    valid: hasMinLength.value,
  },
  {
    text: t('auth.resetPassword.requirements.lowercase'),
    valid: hasLowercase.value,
  },
  {
    text: t('auth.resetPassword.requirements.uppercase'),
    valid: hasUppercase.value,
  },
  {
    text: t('auth.resetPassword.requirements.number'),
    valid: hasNumber.value,
  },
  {
    text: t('auth.resetPassword.requirements.symbol'),
    valid: hasSymbol.value,
  },
])

const isPasswordRequirementValid = computed(() => {
  return hasMinLength.value
    && hasLowercase.value
    && hasUppercase.value
    && hasNumber.value
    && hasSymbol.value
})

const passwordStrengthScore = computed(() => {
  let score = 0

  if (hasMinLength.value)
    score += 1

  if (hasLowercase.value)
    score += 1

  if (hasUppercase.value)
    score += 1

  if (hasNumber.value)
    score += 1

  if (hasSymbol.value)
    score += 1

  return score
})

const passwordStrength = computed(() => {
  if (!newPassword.value) {
    return {
      label: t('auth.resetPassword.strength.empty'),
      color: 'secondary',
      percent: 0,
      className: '',
    }
  }

  if (passwordStrengthScore.value <= 2) {
    return {
      label: t('auth.resetPassword.strength.weak'),
      color: 'error',
      percent: 25,
      className: 'strength-weak',
    }
  }

  if (passwordStrengthScore.value === 3) {
    return {
      label: t('auth.resetPassword.strength.medium'),
      color: 'warning',
      percent: 50,
      className: 'strength-medium',
    }
  }

  if (passwordStrengthScore.value === 4) {
    return {
      label: t('auth.resetPassword.strength.strong'),
      color: 'info',
      percent: 75,
      className: 'strength-strong',
    }
  }

  return {
    label: t('auth.resetPassword.strength.veryStrong'),
    color: 'success',
    percent: 100,
    className: 'strength-very-strong',
  }
})

const isFormValid = computed(() => {
  return isPasswordRequirementValid.value && isConfirmPasswordMatch.value
})

const newPasswordError = computed(() => {
  if (!isSubmitted.value && !newPassword.value)
    return ''

  if (!newPassword.value)
    return t('auth.resetPassword.errors.passwordRequired')

  if (!isPasswordRequirementValid.value)
    return t('auth.resetPassword.errors.passwordRequirementsNotMet')

  return ''
})

const confirmPasswordError = computed(() => {
  if (!isSubmitted.value && !confirmPassword.value)
    return ''

  if (!confirmPassword.value)
    return t('auth.resetPassword.errors.confirmRequired')

  if (!isConfirmPasswordMatch.value)
    return t('auth.resetPassword.errors.confirmMismatch')

  return ''
})

onMounted(async () => {
  /*
   * Halaman reset password diakses lewat link email, bukan lewat sesi
   * yang sedang aktif. Kalau browser masih menyimpan accessToken/userData
   * lama (mis. dari sesi lain yang sudah kedaluwarsa tapi belum ter-clear),
   * router guard bisa salah anggap user "sudah login" begitu nanti
   * diarahkan ke /login, lalu mencoba memuat permission dengan token basi
   * itu -- ujungnya malah muncul alert "Sesi Berakhir" yang membingungkan
   * karena user tidak pernah login di flow ini sama sekali.
   */
  clearAuthSession()

  const queryToken = route.query.token
  const queryEmail = route.query.email

  token.value = typeof queryToken === 'string' ? queryToken : ''
  email.value = typeof queryEmail === 'string' ? queryEmail : ''

  if (!token.value || !email.value) {
    isLinkInvalid.value = true
    isVerifying.value = false

    return
  }

  try {
    const response = await axios.get('/auth/reset-password/verify', {
      params: {
        token: token.value,
        email: email.value,
      },
    })

    if (!response.data?.valid)
      isLinkInvalid.value = true
  }
  catch (error) {
    /*
     * Gagal memeriksa (mis. server error) -- anggap tidak valid supaya
     * tidak menampilkan form untuk link yang belum tentu benar-benar OK.
     */
    console.error('VERIFY RESET TOKEN ERROR:', error)

    isLinkInvalid.value = true
  }
  finally {
    isVerifying.value = false
  }
})

onBeforeUnmount(() => {
  if (redirectTimer)
    clearTimeout(redirectTimer)
})

async function submitResetPassword(): Promise<void> {
  isSubmitted.value = true

  if (!isFormValid.value)
    return

  submitLoading.value = true

  try {
    await axios.post('/auth/reset-password', {
      token: token.value,
      email: email.value,
      password: newPassword.value,
      password_confirmation: confirmPassword.value,
    })

    isSuccess.value = true

    redirectTimer = setTimeout(() => {
      router.replace('/login')
    }, REDIRECT_DELAY_MS)
  }
  catch (error: any) {
    const response = error?.response
    const status = Number(response?.status || 0)
    const data = response?.data

    console.error('RESET PASSWORD ERROR:', status, data || error)

    /*
     * Backend mengembalikan pesan yang sama untuk token invalid/expired
     * (lihat AuthController::resetPassword) -- alihkan ke tampilan
     * "link tidak valid" alih-alih menampilkan error di form.
     */
    if (status === 422 && !data?.errors) {
      isLinkInvalid.value = true

      return
    }

    /*
     * Validasi server (mis. password.regex) seharusnya jarang tersentuh
     * karena aturan yang sama sudah dicek di form, tapi tetap ditampilkan
     * sebagai jaring pengaman kalau backend menolak untuk alasan lain.
     * Pesannya tetap dari i18n, bukan teks mentah dari backend.
     */
    showErrorToast({
      text: data?.errors?.password
        ? t('auth.resetPassword.errors.passwordRequirementsNotMet')
        : t('auth.resetPassword.errors.generic'),
    })
  }
  finally {
    submitLoading.value = false
  }
}

function goToForgotPassword(): void {
  router.replace('/forgot-password')
}
</script>

<template>
  <div class="reset-password-page">
    <div class="reset-password-language-switcher">
      <LanguageSwitcher />
    </div>

    <VCard
      flat
      width="100%"
      max-width="460"
      class="reset-password-card pa-2 auth-enter"
    >
      <!-- Verifying state -->
      <template v-if="isVerifying">
        <VCardText class="text-center py-10">
          <VProgressCircular
            indeterminate
            color="primary"
            size="40"
            class="mb-4"
          />

          <p class="text-medium-emphasis mb-0">
            {{ t('auth.resetPassword.verifying') }}
          </p>
        </VCardText>
      </template>

      <!-- Invalid link state -->
      <template v-else-if="isLinkInvalid">
        <VCardText class="text-center">
          <div class="auth-icon-badge auth-icon-badge--error mb-4 auth-success-icon">
            <VIcon
              icon="mdi-link-variant-off"
              size="30"
            />
          </div>

          <h1 class="text-h5 mb-2">
            {{ t('auth.resetPassword.invalidLinkTitle') }}
          </h1>

          <p class="text-medium-emphasis">
            {{ t('auth.resetPassword.invalidLinkMessage') }}
          </p>

          <VBtn
            block
            class="mt-4 text-none"
            @click="goToForgotPassword"
          >
            {{ t('auth.resetPassword.requestNewLink') }}
          </VBtn>

          <RouterLink
            to="/login"
            class="text-primary text-body-2 d-inline-flex align-center justify-center mt-4"
          >
            <VIcon
              icon="mdi-chevron-left"
              size="18"
            />
            {{ t('auth.resetPassword.backToLogin') }}
          </RouterLink>
        </VCardText>
      </template>

      <!-- Success state -->
      <template v-else-if="isSuccess">
        <VCardText class="text-center">
          <div class="auth-icon-badge auth-icon-badge--success mb-4 auth-success-icon">
            <VIcon
              icon="mdi-check-circle-outline"
              size="30"
            />
          </div>

          <h1 class="text-h5 mb-2">
            {{ t('auth.resetPassword.successTitle') }}
          </h1>

          <p class="text-medium-emphasis">
            {{ t('auth.resetPassword.successMessage') }}
          </p>

          <VBtn
            block
            variant="tonal"
            class="mt-4 text-none"
            to="/login"
          >
            {{ t('auth.resetPassword.goToLogin') }}
          </VBtn>
        </VCardText>
      </template>

      <!-- Form state -->
      <template v-else>
        <VCardText>
          <div class="auth-icon-badge mb-4">
            <VIcon
              icon="mdi-shield-key-outline"
              size="26"
            />
          </div>

          <h1 class="text-h5 mb-1">
            {{ t('auth.resetPassword.title') }}
          </h1>

          <p class="mb-0 text-medium-emphasis">
            {{ t('auth.resetPassword.subtitle') }}
          </p>
        </VCardText>

        <VCardText>
          <VForm @submit.prevent="submitResetPassword">
            <VRow>
              <VCol cols="12">
                <VTextField
                  v-model="newPassword"
                  :type="isNewPasswordVisible ? 'text' : 'password'"
                  :label="t('auth.resetPassword.newPasswordLabel')"
                  :placeholder="t('auth.resetPassword.newPasswordPlaceholder')"
                  :error-messages="newPasswordError"
                  :append-inner-icon="
                    isNewPasswordVisible
                      ? 'mdi-eye-off-outline'
                      : 'mdi-eye-outline'
                  "
                  prepend-inner-icon="mdi-lock-outline"
                  autocomplete="new-password"
                  autofocus
                  @click:append-inner="
                    isNewPasswordVisible = !isNewPasswordVisible
                  "
                />
              </VCol>

              <VCol cols="12">
                <VTextField
                  v-model="confirmPassword"
                  :type="isConfirmPasswordVisible ? 'text' : 'password'"
                  :label="t('auth.resetPassword.confirmPasswordLabel')"
                  :placeholder="t('auth.resetPassword.confirmPasswordPlaceholder')"
                  :error-messages="confirmPasswordError"
                  :append-inner-icon="
                    isConfirmPasswordVisible
                      ? 'mdi-eye-off-outline'
                      : 'mdi-eye-outline'
                  "
                  prepend-inner-icon="mdi-lock-check-outline"
                  autocomplete="new-password"
                  @click:append-inner="
                    isConfirmPasswordVisible = !isConfirmPasswordVisible
                  "
                />
              </VCol>

              <VCol cols="12">
                <div class="password-strength-box">
                  <div class="d-flex align-center justify-space-between mb-2">
                    <span class="text-caption text-medium-emphasis">
                      {{ t('auth.resetPassword.strengthLabel') }}
                    </span>

                    <VChip
                      size="x-small"
                      :color="passwordStrength.color"
                      variant="tonal"
                      class="font-weight-bold"
                    >
                      {{ passwordStrength.label }}
                    </VChip>
                  </div>

                  <VProgressLinear
                    :model-value="passwordStrength.percent"
                    :color="passwordStrength.color"
                    height="8"
                    rounded
                    class="password-strength-progress"
                    :class="passwordStrength.className"
                  />
                </div>
              </VCol>

              <VCol cols="12">
                <p class="text-body-2 font-weight-medium mb-3">
                  {{ t('auth.resetPassword.requirements.title') }}
                </p>

                <ul class="password-requirement-list">
                  <li
                    v-for="item in passwordRequirements"
                    :key="item.text"
                    class="password-requirement-item"
                    :class="{
                      'is-valid': item.valid,
                      'is-invalid': !item.valid && newPassword,
                    }"
                  >
                    <VIcon
                      :icon="item.valid ? 'mdi-check-circle' : 'mdi-circle-outline'"
                      size="18"
                      class="requirement-icon"
                    />

                    <span>{{ item.text }}</span>
                  </li>

                  <li
                    class="password-requirement-item"
                    :class="{
                      'is-valid': isConfirmPasswordMatch,
                      'is-invalid': !isConfirmPasswordMatch && confirmPassword,
                    }"
                  >
                    <VIcon
                      :icon="isConfirmPasswordMatch ? 'mdi-check-circle' : 'mdi-circle-outline'"
                      size="18"
                      class="requirement-icon"
                    />

                    <span>{{ t('auth.resetPassword.requirements.match') }}</span>
                  </li>
                </ul>
              </VCol>

              <VCol cols="12">
                <VBtn
                  block
                  type="submit"
                  size="large"
                  class="text-none"
                  :loading="submitLoading"
                  :disabled="submitLoading"
                >
                  {{ t('auth.resetPassword.submit') }}
                </VBtn>
              </VCol>

              <VCol
                cols="12"
                class="text-center mt-2"
              >
                <RouterLink
                  to="/login"
                  class="text-primary text-body-2 d-inline-flex align-center justify-center"
                >
                  <VIcon
                    icon="mdi-chevron-left"
                    size="18"
                  />
                  {{ t('auth.resetPassword.backToLogin') }}
                </RouterLink>
              </VCol>
            </VRow>
          </VForm>
        </VCardText>
      </template>
    </VCard>
  </div>
</template>

<style lang="scss">
.reset-password-page {
  position: relative;
  display: flex;
  min-height: 100vh;
  align-items: center;
  justify-content: center;
  padding: 24px;
  background-color: rgb(var(--v-theme-background));
}

.reset-password-language-switcher {
  position: absolute;
  z-index: 10;
  inset-block-start: 16px;
  inset-inline-end: 16px;
}

.reset-password-card {
  border: 1px solid rgba(var(--v-border-color), var(--v-border-opacity));
  border-radius: 12px;
  background-color: rgb(var(--v-theme-surface));
}

.auth-icon-badge {
  display: flex;
  align-items: center;
  justify-content: center;
  block-size: 52px;
  inline-size: 52px;
  border-radius: 14px;
  background-color: rgba(var(--v-theme-primary), 0.12);
  color: rgb(var(--v-theme-primary));
}

.auth-icon-badge--success {
  margin-inline: auto;
  background-color: rgba(var(--v-theme-success), 0.12);
  color: rgb(var(--v-theme-success));
}

.auth-icon-badge--error {
  margin-inline: auto;
  background-color: rgba(var(--v-theme-error), 0.12);
  color: rgb(var(--v-theme-error));
}

.auth-success-icon {
  animation: auth-success-pop 0.45s cubic-bezier(0.34, 1.56, 0.64, 1) both;
}

@keyframes auth-success-pop {
  from {
    opacity: 0;
    transform: scale(0.6);
  }

  to {
    opacity: 1;
    transform: scale(1);
  }
}

/*
 * Animasi masuk yang ringan untuk kartu, karena halaman ini berdiri
 * sendiri (tidak lagi ikut transisi flip dari layout auth.vue).
 */
.auth-enter {
  animation: auth-enter 0.45s ease both;
}

@keyframes auth-enter {
  from {
    opacity: 0;
    transform: translateY(14px);
  }

  to {
    opacity: 1;
    transform: translateY(0);
  }
}

.password-strength-box {
  transition: all 0.25s ease;
}

.password-strength-progress {
  overflow: hidden;
  transition: all 0.25s ease;
}

.password-strength-progress :deep(.v-progress-linear__determinate) {
  transition: width 0.35s ease, background-color 0.25s ease;
}

.password-requirement-list {
  display: flex;
  flex-direction: column;
  gap: 10px;
  padding-left: 0;
  list-style: none;
}

.password-requirement-item {
  display: flex;
  align-items: center;
  gap: 10px;
  color: rgba(var(--v-theme-on-surface), 0.58);
  transition: all 0.25s ease;
  transform: translateX(0);

  .requirement-icon {
    color: rgba(var(--v-theme-on-surface), 0.38);
    transition: all 0.25s ease;
  }

  &.is-valid {
    color: rgb(var(--v-theme-success));
    transform: translateX(4px);

    .requirement-icon {
      color: rgb(var(--v-theme-success));
      transform: scale(1.1);
    }
  }

  &.is-invalid {
    color: rgb(var(--v-theme-error));

    .requirement-icon {
      color: rgb(var(--v-theme-error));
    }
  }
}

@media (max-width: 600px) {
  .reset-password-page {
    padding: 16px;
  }
}
</style>

<route lang="yaml">
meta:
  layout: blank
  action: read
  subject: Auth
  redirectIfLoggedIn: true
</route>
