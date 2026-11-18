<script setup lang="ts">
import { computed, onMounted, ref } from 'vue'

import { useImpersonationStore } from '@/stores/impersonation'
import { closeAlert, showConfirmAlert, showErrorToast, showLoadingAlert } from '@/utils/alert'

/*
|--------------------------------------------------------------------------
| Banner "Anda sedang masuk sebagai ..."
|--------------------------------------------------------------------------
| Sengaja tidak bisa ditutup, dan sengaja menempel di atas (sticky) sehingga
| tetap terlihat meski halaman digulir.
|
| Fitur penyamaran di aplikasi ini penuh: admin boleh submit dan approve
| sebagai user tersebut. Yang mencegah kecelakaan karena ia lupa sedang
| menyamar bukan pemeriksaan di server -- server memang mengizinkan -- tapi
| justru banner ini. Karena itu ia tidak boleh bisa disembunyikan.
|
| Ikonnya memakai koleksi mdi yang ikut dibundel, bukan tabler yang baru
| diambil dari Iconify saat dijalankan: banner ini harus tetap tampil utuh
| walau jaringan ke CDN ikon sedang tidak bisa dihubungi.
|--------------------------------------------------------------------------
*/

const impersonation = useImpersonationStore()

const keluarLoading = ref(false)

const namaTarget = computed(() => impersonation.target?.name ?? 'user ini')

const usernameTarget = computed(() => impersonation.target?.username ?? null)

const namaAdmin = computed(() => impersonation.admin?.name ?? null)

const mulaiPukul = computed(() => {
  if (!impersonation.startedAt)
    return null

  const tanggal = new Date(impersonation.startedAt)

  if (Number.isNaN(tanggal.getTime()))
    return null

  return tanggal.toLocaleString('id-ID', {
    day: '2-digit',
    month: 'short',
    hour: '2-digit',
    minute: '2-digit',
  })
})

/*
| Baris keterangan dirangkai di sini, bukan dari beberapa <span> bersebelahan.
| Vue memangkas spasi di antara elemen, sehingga pemisahnya menempel ke kata
| sebelumnya dan terbaca "Admin SYOP- Mulai". Dirangkai sebagai satu teks,
| jaraknya pasti.
*/
const rinci = computed(() => {
  const bagian: string[] = []

  if (namaAdmin.value)
    bagian.push(`Akun asli: ${namaAdmin.value}`)

  if (mulaiPukul.value)
    bagian.push(`Mulai ${mulaiPukul.value}`)

  if (impersonation.reason)
    bagian.push(impersonation.reason)

  return bagian.join(' · ')
})

const keluar = async (): Promise<void> => {
  if (keluarLoading.value)
    return

  const konfirmasi = await showConfirmAlert({
    title: 'Kembali ke akun Anda?',
    text: `Sesi sebagai ${namaTarget.value} akan diakhiri dan Anda kembali ke akun Anda sendiri.`,
    confirmButtonText: 'Ya, kembali',
    cancelButtonText: 'Tetap di sini',
  })

  if (!konfirmasi.isConfirmed)
    return

  keluarLoading.value = true

  try {
    showLoadingAlert('Mengembalikan sesi...', 'Mohon tunggu sebentar')

    /*
     * Berhasil berarti halaman dimuat ulang oleh store, jadi tidak ada kode
     * sesudah ini yang perlu dijalankan pada jalur normal.
     */
    await impersonation.hentikan()
  }
  catch (error: any) {
    closeAlert()

    keluarLoading.value = false

    showErrorToast({
      title: 'Gagal kembali ke akun Anda',
      text: error?.response?.data?.message
        ?? 'Sesi tidak bisa dipulihkan. Silakan logout lalu login kembali.',
    })
  }
}

onMounted(() => {
  impersonation.muat()
})
</script>

<template>
  <div
    v-if="impersonation.impersonating"
    class="impersonation-banner"
  >
    <div class="impersonation-banner__isi">
      <VIcon
        icon="mdi-account-switch-outline"
        size="22"
        class="flex-shrink-0"
      />

      <div class="min-width-0">
        <div class="text-body-2 font-weight-bold">
          Anda sedang masuk sebagai {{ namaTarget }}
          <span
            v-if="usernameTarget"
            class="font-weight-regular"
          >
            ({{ usernameTarget }})
          </span>
        </div>

        <div class="text-caption impersonation-banner__rinci">
          {{ rinci }}
        </div>
      </div>
    </div>

    <!--
      color="surface", bukan "white": "white" bukan warna tema, sehingga
      Vuetify memutihkan latar DAN tulisannya sekaligus dan tombolnya terbaca
      kosong. "surface" membawa pasangan on-surface, jadi tulisannya gelap di
      skin terang dan ikut menyesuaikan di skin gelap.
    -->
    <VBtn
      size="small"
      variant="flat"
      color="surface"
      class="text-none flex-shrink-0"
      :loading="keluarLoading"
      prepend-icon="mdi-logout-variant"
      @click="keluar"
    >
      Kembali ke akun saya
    </VBtn>
  </div>
</template>

<style lang="scss" scoped>
.impersonation-banner {
  position: sticky;
  z-index: 10;
  display: flex;
  flex-wrap: wrap;
  gap: 12px;
  align-items: center;
  justify-content: space-between;
  padding: 10px 16px;
  border-radius: 6px;

  /*
  | Latar memakai warna warning dari tema, supaya banner ini sewarna dengan
  | peringatan lain di aplikasi dan ikut berubah bila paletnya disesuaikan.
  |
  | Tulisannya TIDAK memakai --v-theme-on-warning. Tema ini menetapkan
  | on-warning putih di atas warning #FFB400, dan putih di atas amber hanya
  | mencapai rasio kontras sekitar 1,9:1. Itu memadai untuk label tombol yang
  | pendek dan tebal, tetapi tidak untuk baris keterangan kecil di banner ini
  | -- padahal justru baris itu yang memuat nama akun asli dan alasannya.
  | Hitam pekat di atas amber mencapai sekitar 11:1.
  */
  background-color: rgb(var(--v-theme-warning));
  box-shadow: 0 2px 8px rgb(0 0 0 / 18%);
  color: rgba(0, 0, 0, 87%);
  inset-block-start: 0;
  margin-block-end: 16px;

  &__isi {
    display: flex;
    flex: 1 1 320px;
    gap: 12px;
    align-items: center;
    min-inline-size: 0;
  }

  &__rinci {
    opacity: 0.85;
  }

  /*
  | styles/@core/base/libs/vuetify/_overrides.scss memaksa .text-body-2 ke
  | rgba(on-background, medium-emphasis) untuk seluruh aplikasi. Aturan kelas
  | itu mengalahkan warna yang diturunkan dari induk, sehingga tanpa baris di
  | bawah tulisan banner memakai warna teks halaman biasa -- kelabu di atas
  | latar berwarna, bukan warna yang dipilih di sini.
  */
  .text-body-2,
  .text-caption {
    color: inherit;
  }
}

.min-width-0 {
  min-inline-size: 0;
}
</style>
