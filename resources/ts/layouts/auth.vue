<script setup lang="ts">
import { useSkins } from '@core/composable/useSkins'
import loginBackground from '@images/pages/bg2.png'
import LanguageSwitcher from '@/layouts/components/NavBarI18n.vue'

const { injectSkinClasses } = useSkins()

injectSkinClasses()
</script>

<template>
  <div class="layout-wrapper layout-blank auth-page">
    <div class="auth-language-switcher">
      <LanguageSwitcher />
    </div>

    <VRow
      no-gutters
      class="auth-wrapper"
    >
      <!-- Background: hidup di layout, tidak ikut remount saat pindah halaman -->
      <VCol
        cols="12"
        lg="8"
        class="d-none d-lg-flex auth-left"
      >
        <VImg
          :src="loginBackground"
          alt="Pro Energi Oil and Gas"
          cover
          eager
          class="auth-background-image"
        />
      </VCol>

      <!-- Shell kartu tetap diam; hanya isinya (RouterView) yang beranimasi -->
      <VCol
        cols="12"
        lg="4"
        class="auth-card-v2 d-flex align-center justify-center"
      >
        <VCard
          flat
          width="100%"
          max-width="500"
          class="login-card pa-4 auth-card-shell"
        >
          <RouterView v-slot="{ Component }">
            <Transition
              name="auth-flip"
              mode="out-in"
            >
              <Component :is="Component" />
            </Transition>
          </RouterView>
        </VCard>
      </VCol>
    </VRow>
  </div>
</template>

<style lang="scss">
@use "@core-scss/template/pages/page-auth.scss";

.auth-page {
  position: relative;
  width: 100%;
  min-height: 100vh;
  overflow: hidden;
}

.auth-language-switcher {
  position: absolute;
  z-index: 10;
  inset-block-start: 16px;
  inset-inline-end: 16px;
}

.auth-wrapper {
  width: 100%;
  min-height: 100vh;
  margin: 0 !important;
}

.auth-left {
  position: relative;
  min-height: 100vh;
  padding: 0 !important;
  overflow: hidden;
  background-color: #eef4fa;
}

.auth-background-image {
  width: 100%;
  height: 100vh;
  min-height: 100vh;
}

/*
 * Memastikan gambar VImg memenuhi seluruh area sebelah kiri.
 */
.auth-background-image :deep(.v-img__img) {
  object-fit: cover;
  object-position: center center;
}

.auth-card-v2 {
  min-height: 100vh;
  padding: 24px;
  background-color: rgb(var(--v-theme-surface));
}

.login-card {
  background-color: transparent !important;
}

.auth-card-shell {
  overflow: visible;
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
|--------------------------------------------------------------------------
| Transisi "flip" antar halaman auth
|--------------------------------------------------------------------------
| Hanya konten kartu (RouterView) yang beranimasi -- background dan shell
| kartu tetap diam karena keduanya hidup di layout ini, bukan di
| masing-masing halaman. mode="out-in" memastikan konten lama selesai
| keluar dulu sebelum konten baru masuk, jadi tidak ada kedip/tumpang
| tindih.
|--------------------------------------------------------------------------
*/
.auth-flip-enter-active,
.auth-flip-leave-active {
  transition:
    opacity 0.32s cubic-bezier(0.4, 0, 0.2, 1),
    transform 0.32s cubic-bezier(0.4, 0, 0.2, 1);
}

.auth-flip-enter-from {
  opacity: 0;
  transform: perspective(900px) rotateY(-8deg) translateX(10px);
}

.auth-flip-leave-to {
  opacity: 0;
  transform: perspective(900px) rotateY(8deg) translateX(-10px);
}

@media (max-width: 1279px) {
  .auth-card-v2 {
    min-height: 100vh;
    padding: 16px;
  }

  .login-card {
    max-width: 500px !important;
  }
}
</style>
