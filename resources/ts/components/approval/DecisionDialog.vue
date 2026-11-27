<script setup lang="ts">
import { computed, ref, watch } from 'vue'
import { useI18n } from 'vue-i18n'

/*
|--------------------------------------------------------------------------
| Dialog keputusan approver
|--------------------------------------------------------------------------
| SATU BENTUK untuk Setujui, Tolak, dan Kembalikan untuk revisi, dipakai
| lintas modul.
|
| Bentuknya diambil apa adanya dari dialog FPU -- lebar 520, judul bertanya
| yang menyebut nomor dokumennya, satu kalimat penjelas, satu kotak catatan,
| lalu Batal dan tombol berwarna di kanan bawah. Bukan karena FPU lebih
| penting, melainkan karena ia yang paling banyak dipakai orang: menyamakan
| ke bentuk yang sudah dikenal berarti tidak ada yang perlu belajar lagi.
|
| KENAPA KOMPONEN, BUKAN DISALIN.
|
| Dialog keputusan pernah ditulis ulang di tiap modul, dan hasilnya
| perlahan berbeda: ada yang memakai SweetAlert, ada yang memakai VDialog,
| ada yang tombolnya berbunyi "Ya, setujui" sementara tetangganya "Setujui".
| Yang paling mahal bukan rupanya, melainkan perilakunya -- alasan wajib
| yang diperiksa di satu modul dan tidak di modul lain.
|
| APA YANG DIURUS SENDIRI OLEH KOMPONEN INI:
|   - mengosongkan catatan dan galat setiap kali dibuka;
|   - menahan kiriman bila catatannya wajib tetapi belum cukup panjang;
|   - mengunci tombolnya selama permintaannya berjalan.
|
| APA YANG TETAP MILIK PEMANGGILNYA: mengirim permintaannya, memilih
| kalimat, dan menutup dialognya saat berhasil. Komponen ini tidak tahu
| endpoint mana pun, dan memang tidak boleh tahu.
|--------------------------------------------------------------------------
*/

interface Props {

  /** Terbuka atau tidak. Dipakai dengan v-model. */
  modelValue: boolean

  /** Judul yang bertanya, biasanya memuat nomor dokumennya. */
  title: string

  /** Satu kalimat yang menerangkan akibat keputusannya. */
  subtitle?: string

  notesLabel: string
  notesPlaceholder?: string

  confirmText: string

  /** success untuk menyetujui, error untuk menolak, warning untuk mengembalikan. */
  confirmColor?: string

  loading?: boolean

  /**
   * Panjang minimal catatan. 0 berarti opsional.
   *
   * Disebut sebagai panjang, bukan sebagai "wajib ya/tidak", karena
   * keduanya memang berbeda: penolakan cukup tidak kosong, sedangkan
   * pengembalian untuk revisi menuntut tiga huruf -- angka yang sama
   * dengan penjaga di server, supaya layar tidak meloloskan apa yang pasti
   * ditolak di sana.
   */
  minLength?: number

  /** Pesan yang muncul bila catatannya kurang. */
  requiredMessage?: string
}

const props = withDefaults(defineProps<Props>(), {
  subtitle: '',
  notesPlaceholder: '',
  confirmColor: 'primary',
  loading: false,
  minLength: 0,
  requiredMessage: '',
})

const emit = defineEmits<{
  (e: 'update:modelValue', value: boolean): void

  /** Catatannya, atau null bila opsional dan dibiarkan kosong. */
  (e: 'confirm', notes: string | null): void
}>()

const { t } = useI18n()

const catatan = ref('')
const galat = ref('')

const terbuka = computed({
  get: () => props.modelValue,
  set: (value: boolean) => emit('update:modelValue', value),
})

/*
| Dikosongkan saat DIBUKA, bukan saat ditutup.
|
| Dialog yang gagal dikirim ditutup oleh pemanggilnya hanya bila berhasil;
| kalau dikosongkan saat ditutup, kegagalan jaringan akan membuang alasan
| yang sudah diketik panjang-panjang. Mengosongkannya saat dibuka memberi
| hasil yang sama bagi pemakai -- kotaknya selalu bersih -- tanpa risiko itu.
*/
watch(
  () => props.modelValue,
  (baru: boolean) => {
    if (baru) {
      catatan.value = ''
      galat.value = ''
    }
  },
)

const kirim = (): void => {
  if (props.loading)
    return

  const isi = catatan.value.trim()

  if (props.minLength > 0 && isi.length < props.minLength) {
    galat.value = props.requiredMessage

    return
  }

  galat.value = ''

  emit('confirm', isi === '' ? null : isi)
}

const batal = (): void => {
  terbuka.value = false
}
</script>

<template>
  <VDialog
    v-model="terbuka"
    max-width="520"
    :persistent="loading"
  >
    <VCard>
      <!--
        Judulnya bertanya dan menyebut nomornya sekaligus. Pernyataan
        seperti "Setujui Voucher" terbaca sebagai pemberitahuan bahwa
        dokumennya sedang disetujui, bukan sebagai pertanyaan yang menunggu
        jawaban -- dan nomornya adalah hal pertama yang perlu dipastikan
        sebelum menjawab, karena menu yang dibuka dari baris yang salah
        adalah kekeliruan yang paling mudah terjadi.
      -->
      <VCardTitle class="text-h6 font-weight-bold">
        {{ title }}
      </VCardTitle>

      <VCardText>
        <div
          v-if="subtitle"
          class="text-body-2 text-medium-emphasis mb-4"
        >
          {{ subtitle }}
        </div>

        <!--
          Tempat pemanggil menitipkan konteks tambahan -- misalnya kotak
          keterangan perjalanan dinas pada FPU. Berada di ATAS kotak
          catatan, karena ia bahan pertimbangan, bukan hasil pertimbangan.
        -->
        <slot name="konteks" />

        <VTextarea
          v-model="catatan"
          :label="notesLabel"
          :placeholder="notesPlaceholder"
          rows="3"
          auto-grow
          :error="!!galat"
          :error-messages="galat ? [galat] : []"
          :disabled="loading"
        />
      </VCardText>

      <VCardActions class="justify-end">
        <VBtn
          variant="tonal"
          color="secondary"
          class="text-none"
          :disabled="loading"
          @click="batal"
        >
          {{ t('common.actions.cancel') }}
        </VBtn>

        <VBtn
          :color="confirmColor"
          class="text-none"
          :loading="loading"
          @click="kirim"
        >
          {{ confirmText }}
        </VBtn>
      </VCardActions>
    </VCard>
  </VDialog>
</template>
