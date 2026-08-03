<script setup lang="ts">
import {
  computed,
  onBeforeUnmount,
  ref,
  watch,
} from 'vue'
import { useI18n } from 'vue-i18n'
import { VForm } from 'vuetify/components'
import axios from '@axios'

const { t } = useI18n()

const RESEND_COOLDOWN_SECONDS = 60

/*
|--------------------------------------------------------------------------
| Cooldown yang Persisten
|--------------------------------------------------------------------------
| Cooldown disimpan sebagai timestamp "berakhir pada" di localStorage,
| bukan cuma di state komponen -- supaya tetap berjalan walau user
| pindah halaman (komponen ini remount) atau me-refresh browser.
|--------------------------------------------------------------------------
*/
const COOLDOWN_STORAGE_KEY = 'forgot-password-cooldown-until'

function readCooldownUntil(): number {
  const raw = localStorage.getItem(COOLDOWN_STORAGE_KEY)
  const value = raw ? Number(raw) : 0

  return Number.isFinite(value) ? value : 0
}

function computeRemainingSeconds(): number {
  return Math.max(
    Math.ceil((readCooldownUntil() - Date.now()) / 1000),
    0,
  )
}

const refVForm = ref<InstanceType<typeof VForm> | null>(null)
const email = ref('')
const emailError = ref<string | undefined>(undefined)

const submitLoading = ref(false)
const isSent = ref(false)

const cooldownSeconds = ref(computeRemainingSeconds())
let cooldownTimer: ReturnType<typeof setInterval> | null = null

const isCooldownActive = computed(() => cooldownSeconds.value > 0)

/**
 * Aturan email sederhana ala requiredValidator/emailValidator,
 * tapi pesannya diambil dari i18n supaya konsisten dengan sisa halaman.
 */
const emailRules = [
  (value: string) => !!value?.trim() || t('auth.forgotPassword.errors.emailRequired'),

  (value: string) =>
    /^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(value ?? '')
    || t('auth.forgotPassword.errors.emailInvalid'),
]

watch(email, () => {
  emailError.value = undefined
})

function clearCooldownInterval(): void {
  if (cooldownTimer) {
    clearInterval(cooldownTimer)
    cooldownTimer = null
  }
}

function tickCooldown(): void {
  const remaining = computeRemainingSeconds()

  cooldownSeconds.value = remaining

  if (remaining <= 0) {
    clearCooldownInterval()
    localStorage.removeItem(COOLDOWN_STORAGE_KEY)
  }
}

function startCooldown(): void {
  clearCooldownInterval()

  localStorage.setItem(
    COOLDOWN_STORAGE_KEY,
    String(Date.now() + RESEND_COOLDOWN_SECONDS * 1000),
  )

  cooldownSeconds.value = RESEND_COOLDOWN_SECONDS
  cooldownTimer = setInterval(tickCooldown, 1000)
}

/*
 * Kalau halaman ini di-mount ulang (pindah dari login lalu balik lagi,
 * atau di-refresh) sementara cooldown sebelumnya masih berjalan,
 * lanjutkan hitung mundurnya alih-alih mulai dari 0.
 */
if (cooldownSeconds.value > 0)
  cooldownTimer = setInterval(tickCooldown, 1000)

onBeforeUnmount(() => {
  clearCooldownInterval()
})

async function sendResetLink(): Promise<void> {
  if (submitLoading.value || isCooldownActive.value)
    return

  emailError.value = undefined
  submitLoading.value = true

  try {
    await axios.post('/auth/forgot-password', {
      email: email.value,
    })

    isSent.value = true
    startCooldown()
  }
  catch (error: any) {
    const response = error?.response
    const status = Number(response?.status || 0)
    const data = response?.data

    console.error('FORGOT PASSWORD ERROR:', status, data || error)

    if (status === 422 && data?.field === 'email') {
      emailError.value = t('auth.forgotPassword.errors.emailNotFound')

      return
    }

    if (status === 429) {
      /*
       * Backend sudah menolak karena throttle -- selaraskan cooldown FE
       * dengan kondisi server supaya tombol tidak bisa langsung diklik lagi.
       */
      startCooldown()
      emailError.value = t('auth.forgotPassword.errors.throttled')

      return
    }

    emailError.value = t('auth.forgotPassword.errors.generic')
  }
  finally {
    submitLoading.value = false
  }
}

async function onSubmit(): Promise<void> {
  const validation = await refVForm.value?.validate()

  if (validation?.valid)
    await sendResetLink()
}

function resendLink(): void {
  if (isCooldownActive.value || submitLoading.value)
    return

  sendResetLink()
}
</script>

<template>
  <div class="auth-card-content">
    <!-- Form state -->
    <template v-if="!isSent">
      <VCardText>
        <div class="auth-icon-badge mb-4">
          <VIcon
            icon="mdi-lock-question"
            size="26"
          />
        </div>

        <h1 class="text-h5 mb-1">
          {{ t('auth.forgotPassword.title') }}
        </h1>

        <p class="mb-0 text-medium-emphasis">
          {{ t('auth.forgotPassword.subtitle') }}
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
                v-model="email"
                :label="t('auth.forgotPassword.emailLabel')"
                :placeholder="t('auth.forgotPassword.emailPlaceholder')"
                type="email"
                :rules="emailRules"
                :error-messages="emailError"
                prepend-inner-icon="mdi-email-outline"
                autocomplete="email"
                autofocus
              />
            </VCol>

            <VCol cols="12">
              <VBtn
                block
                type="submit"
                size="large"
                class="text-none"
                :loading="submitLoading"
                :disabled="submitLoading || isCooldownActive"
              >
                <template v-if="isCooldownActive">
                  {{ t('auth.forgotPassword.resendIn', { seconds: cooldownSeconds }) }}
                </template>

                <template v-else>
                  {{ t('auth.forgotPassword.submit') }}
                </template>
              </VBtn>
            </VCol>

            <VCol
              cols="12"
              class="text-center mt-2"
            >
              <RouterLink
                to="/login"
                class="text-primary text-body-2 d-inline-flex align-center"
              >
                <VIcon
                  icon="mdi-chevron-left"
                  size="18"
                />
                {{ t('auth.forgotPassword.backToLogin') }}
              </RouterLink>
            </VCol>
          </VRow>
        </VForm>
      </VCardText>
    </template>

    <!-- Success state -->
    <template v-else>
      <VCardText class="text-center">
        <div class="auth-icon-badge auth-icon-badge--success mb-4 auth-success-icon">
          <VIcon
            icon="mdi-email-check-outline"
            size="30"
          />
        </div>

        <h1 class="text-h5 mb-2">
          {{ t('auth.forgotPassword.successTitle') }}
        </h1>

        <p class="text-medium-emphasis">
          {{ t('auth.forgotPassword.successMessage', { email }) }}
        </p>

        <VBtn
          block
          variant="tonal"
          class="mt-4 text-none"
          :loading="submitLoading"
          :disabled="submitLoading || isCooldownActive"
          @click="resendLink"
        >
          <template v-if="isCooldownActive">
            {{ t('auth.forgotPassword.resendIn', { seconds: cooldownSeconds }) }}
          </template>

          <template v-else>
            {{ t('auth.forgotPassword.resend') }}
          </template>
        </VBtn>

        <RouterLink
          to="/login"
          class="text-primary text-body-2 d-inline-flex align-center justify-center mt-4"
        >
          <VIcon
            icon="mdi-chevron-left"
            size="18"
          />
          {{ t('auth.forgotPassword.backToLogin') }}
        </RouterLink>
      </VCardText>
    </template>
  </div>
</template>

<route lang="yaml">
meta:
  layout: auth
  action: read
  subject: Auth
  redirectIfLoggedIn: true
</route>
