<script setup lang="ts">
import { computed } from 'vue'
import { useI18n } from 'vue-i18n'
import { useRoute, useRouter } from 'vue-router'
import { useTheme } from 'vuetify'
import logoProenergi from '@images/logo-proenergi.png'

/*
|--------------------------------------------------------------------------
| Halaman tidak ditemukan
|--------------------------------------------------------------------------
| Dipakai dua tempat: penangkap alamat tak dikenal, dan halaman contoh
| /pages/misc/not-found. Satu komponen, bukan dua salinan -- dua halaman 404
| yang berbeda rupa di satu aplikasi membingungkan lebih dari 404 itu sendiri.
|
| Warnanya diambil dari logo Pro Energi, bukan dikarang: biru tua #04247A,
| merah #E50E30, dan oranye #EF8006 adalah tiga warna yang benar-benar ada di
| berkas logonya.
|
| Bahasanya mengikuti bahasa yang sedang dipakai. Seluruh kalimatnya lewat
| t(), tidak satu pun ditulis langsung -- itu sebabnya halaman ini ikut
| berganti begitu bahasanya ditukar, tanpa perlu dimuat ulang.
|
| Dirancang muat dalam satu layar tanpa gulir: ukuran angka, jarak, dan
| logonya memakai satuan vh, jadi ia mengecil sendiri pada layar pendek.
|--------------------------------------------------------------------------
*/

const { t } = useI18n()
const route = useRoute()
const router = useRouter()
const theme = useTheme()

/*
| Temanya dibaca langsung, bukan diwarisi dari nenek moyang: halaman ini
| ditempelkan ke body, jadi ia sudah di luar elemen yang membawa kelas
| v-theme--dark.
*/
const isDark = computed<boolean>(() => theme.global.current.value.dark)

/** Alamat yang tadi dicoba, apa adanya. */
const attemptedPath = computed<string>(() => route.fullPath || '/')

/*
| "/" sudah tahu ke mana harus mengantar: dashboard bagi yang sudah masuk,
| halaman login bagi yang belum. Menunjuk langsung ke salah satu dashboard
| justru bisa mendaratkan orang pada 403 -- tersesat lagi, di tempat lain.
*/
const goToDashboard = (): void => {
  router.push('/')
}

const goBack = (): void => {
  /*
  | Kalau tidak ada riwayat -- misalnya tautan ini dibuka dari luar aplikasi --
  | mundur tidak ke mana-mana. Diantar ke dashboard saja.
  */
  if (window.history.length > 1)
    router.back()
  else
    goToDashboard()
}
</script>

<template>
  <!--
    Ditempelkan langsung ke body.

    Halaman ini berdiri sendiri: tidak ada sidebar, tidak ada navbar. Itu
    seharusnya cukup diurus meta.layout: blank, dan meta itu memang ada -- tapi
    kerangkanya masih sempat ikut tampil. Dengan Teleport, isinya keluar dari
    kerangka apa pun: tidak ada lagi nenek moyang yang bisa mengurungnya, dan
    position: fixed benar-benar mengukur layar, bukan kotak isi di sebelah
    sidebar.
  -->
  <Teleport to="body">
    <div
      class="nf"
      :class="{ 'nf--dark': isDark }"
    >
      <!-- Dua cahaya merek di latar: biru tua dan merah, sangat samar. -->
      <div class="nf__glow nf__glow--navy" />
      <div class="nf__glow nf__glow--red" />

      <div class="nf__content">
        <img
          :src="logoProenergi"
          alt="Pro Energi"
          class="nf__logo"
        >

        <div class="nf__code">
          404
        </div>

        <!-- Tiga warna merek, setipis mungkin. -->
        <div class="nf__ribbon" />

        <h1 class="nf__title">
          {{ t('errorPage.notFound.title') }}
        </h1>

        <p class="nf__desc">
          {{ t('errorPage.notFound.description') }}
        </p>

        <!--
          Alamat yang dicoba. Salah ketik terlihat sendiri dari sini, dan kalau
          tautannya memang rusak, inilah yang dilaporkan ke tim IT.
        -->
        <div class="nf__path">
          <span class="nf__path-label">
            {{ t('errorPage.notFound.pathLabel') }}
          </span>

          <code class="nf__path-value">{{ attemptedPath }}</code>
        </div>

        <div class="nf__actions">
          <VBtn
            class="text-none nf__btn-primary"
            prepend-icon="tabler-layout-dashboard"
            @click="goToDashboard"
          >
            {{ t('errorPage.notFound.backToDashboard') }}
          </VBtn>

          <VBtn
            variant="text"
            class="text-none nf__btn-secondary"
            prepend-icon="tabler-arrow-left"
            @click="goBack"
          >
            {{ t('errorPage.notFound.goBack') }}
          </VBtn>
        </div>
      </div>
    </div>
  </Teleport>
</template>

<style scoped>
/*
| Warna merek Pro Energi, diambil dari berkas logonya.
*/
.nf {
  --nf-navy: #04247a;
  --nf-navy-soft: #183786;
  --nf-red: #e50e30;
  --nf-orange: #ef8006;
  --nf-text: #1c2536;
  --nf-muted: #6b7690;
  --nf-line: #e3e8f2;
  --nf-path-bg: #f4f6fb;

  position: fixed;

  /* Di atas sidebar dan navbar, di bawah dialog. */
  z-index: 2000;
  display: flex;
  align-items: center;
  justify-content: center;

  /*
  | Terkunci setinggi layar dan tidak boleh menggulir. Isinya yang menyesuaikan
  | diri, bukan halamannya yang memanjang.
  */
  overflow: hidden;
  padding-inline: 1.25rem;
  background: linear-gradient(160deg, #f6f8fd 0%, #eef2fa 100%);
  inset: 0;
}

/* Pada tema gelap, yang berubah hanya latar dan tulisannya -- warna
   mereknya tetap, karena itu yang membuatnya terbaca sebagai Pro Energi. */
.nf--dark {
  --nf-text: #e6e9f4;
  --nf-muted: #99a2bd;
  --nf-line: #2c3352;
  --nf-path-bg: #161a2d;

  background: linear-gradient(160deg, #11152a 0%, #0b0e1d 100%);
}

.nf__glow {
  position: absolute;
  border-radius: 50%;
  filter: blur(90px);
  opacity: 0.15;
  pointer-events: none;
}

.nf__glow--navy {
  inline-size: 24rem;
  block-size: 24rem;
  background: var(--nf-navy);
  inset-block-start: -8rem;
  inset-inline-start: -6rem;
}

.nf__glow--red {
  inline-size: 20rem;
  block-size: 20rem;
  background: var(--nf-red);
  inset-block-end: -7rem;
  inset-inline-end: -5rem;
}

.nf__content {
  position: relative;
  z-index: 1;
  inline-size: 100%;
  max-inline-size: 30rem;
  text-align: center;
}

/*
| Semua ukuran di bawah ini memakai vh sebagai batas atas, jadi pada layar
| pendek seluruh isinya ikut mengecil -- bukan terpotong, dan bukan memaksa
| halamannya menggulir.
*/
.nf__logo {
  block-size: clamp(2.25rem, 7vh, 3.5rem);
  margin-block-end: clamp(0.75rem, 2.5vh, 1.5rem);
  object-fit: contain;
}

.nf__code {
  background: linear-gradient(135deg, var(--nf-navy) 0%, var(--nf-red) 78%, var(--nf-orange) 100%);
  background-clip: text;
  font-size: clamp(2.75rem, 11vh, 5rem);
  font-weight: 800;
  letter-spacing: -0.04em;
  line-height: 1;
  -webkit-text-fill-color: transparent;
}

.nf__ribbon {
  block-size: 3px;
  border-radius: 999px;
  margin: clamp(0.75rem, 2.5vh, 1.25rem) auto clamp(0.75rem, 2.5vh, 1.25rem);
  background: linear-gradient(
    90deg,
    var(--nf-navy) 0%,
    var(--nf-navy-soft) 38%,
    var(--nf-red) 72%,
    var(--nf-orange) 100%
  );
  inline-size: 4.5rem;
}

.nf__title {
  color: var(--nf-text);
  font-size: clamp(1.0625rem, 2.6vh, 1.375rem);
  font-weight: 700;
  line-height: 1.3;
}

.nf__desc {
  margin-block: clamp(0.4rem, 1.4vh, 0.75rem) clamp(0.9rem, 3vh, 1.5rem);
  color: var(--nf-muted);
  font-size: clamp(0.8125rem, 1.8vh, 0.9375rem);
  line-height: 1.6;
}

.nf__path {
  display: flex;
  flex-wrap: wrap;
  align-items: center;
  justify-content: center;
  gap: 0.5rem;
  padding: 0.5rem 0.875rem;
  border: 1px solid var(--nf-line);
  border-radius: 0.5rem;
  margin-block-end: clamp(0.9rem, 3vh, 1.5rem);
  background: var(--nf-path-bg);
}

.nf__path-label {
  color: var(--nf-muted);
  font-size: 0.6875rem;
  text-transform: uppercase;
  letter-spacing: 0.04em;
}

/* Alamatnya bisa panjang; ia yang boleh menggeser, bukan halamannya. */
.nf__path-value {
  overflow-x: auto;
  max-inline-size: 100%;
  color: var(--nf-navy-soft);
  font-family: ui-monospace, "SFMono-Regular", "Menlo", monospace;
  font-size: 0.8125rem;
  white-space: nowrap;
}

.nf--dark .nf__path-value {
  color: #8fa6e8;
}

.nf__actions {
  display: flex;
  flex-wrap: wrap;
  align-items: center;
  justify-content: center;
  gap: 0.5rem;
}

/*
| Tombol utamanya memakai warna merek langsung, bukan warna tema aplikasi --
| halaman ini memang sengaja berbaju Pro Energi.
*/
.nf__btn-primary {
  border: 0;
  background: linear-gradient(135deg, var(--nf-navy) 0%, var(--nf-navy-soft) 100%);
  box-shadow: 0 0.375rem 0.875rem rgba(4, 36, 122, 0.22);
  color: #fff;
}

.nf__btn-secondary {
  color: var(--nf-muted);
}
</style>
