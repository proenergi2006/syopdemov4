<script setup lang="ts">
import { digitSebelumKaret, ketikAngka, parseAngka, posisiKaret } from '@/utils/numberFormatter'

/*
|--------------------------------------------------------------------------
| Isian nominal yang memasang pemisah sambil diketik
|--------------------------------------------------------------------------
| Pengganti <VTextField type="number"> untuk uang.
|
| type="number" bawaan peramban tidak bisa menampilkan pemisah ribuan, jadi
| 5000000 dan 50000000 terlihat nyaris sama dan kelebihan satu nol baru
| ketahuan setelah dokumennya berjalan. Di sini pemisahnya muncul begitu
| digit keempat diketik, sehingga nominalnya terbaca sewaktu diisi.
|
| Nilai yang keluar lewat v-model tetap angka biasa, bukan teks berpemisah --
| halaman pemakainya tidak perlu tahu-menahu soal format.
|
| Komponen ini otomatis terdaftar global (lihat vite.config.ts), jadi bisa
| langsung dipakai tanpa impor.
|--------------------------------------------------------------------------
*/

interface Props {
  modelValue: number | string | null | undefined

  /** Banyak angka di belakang titik. 0 berarti nominal bulat. */
  desimal?: number

  /** Batas banyak digit sebelum titik. */
  maxDigit?: number

  /** Izinkan nominal minus, misalnya penyesuaian saldo. */
  allowNegative?: boolean

  /** Kotak kosong dibaca 0. Matikan bila kosong harus berarti belum diisi. */
  emptyAsZero?: boolean
}

const props = withDefaults(defineProps<Props>(), {
  desimal: 0,
  maxDigit: 15,
  allowNegative: false,
  emptyAsZero: false,
})

const emit = defineEmits<{
  (e: 'update:modelValue', value: number | null): void
}>()

defineOptions({ inheritAttrs: false })

const teks = ref('')

const opsi = computed(() => ({
  maxDesimal: props.desimal,
  maxDigit: props.maxDigit,
  allowNegative: props.allowNegative,
  emptyAsZero: props.emptyAsZero,
}))

/*
| Perubahan dari luar -- memuat data lama, mengosongkan formulir, mengisi
| otomatis -- ikut diformat. Tapi hanya bila nilainya memang berbeda dari
| yang sedang tampil, supaya "1,250." yang baru setengah diketik tidak
| dirapikan sendiri di tengah pengetikan dan titiknya hilang.
*/
watch(
  () => props.modelValue,
  nilai => {
    if (nilai === null || nilai === undefined || nilai === '') {
      if (parseAngka(teks.value) !== 0 || teks.value === '')
        teks.value = ''

      return
    }

    if (parseAngka(teks.value) === Number(nilai))
      return

    teks.value = ketikAngka(String(nilai), opsi.value).formatted
  },
  { immediate: true },
)

const saatKetik = (event: Event) => {
  const el = event.target as HTMLInputElement

  const mentah = el.value
  const digitKiri = digitSebelumKaret(mentah, el.selectionStart ?? mentah.length)

  const hasil = ketikAngka(mentah, opsi.value)

  teks.value = hasil.formatted
  emit('update:modelValue', hasil.numeric)

  /*
  | Nilai kotaknya ditulis langsung, tidak menunggu Vue. Saat yang diketik
  | adalah huruf yang memang dibuang (misalnya "x"), hasil formatnya sama
  | dengan sebelumnya, Vue tidak melihat perubahan apa pun, dan huruf itu
  | akan tertinggal di layar.
  */
  el.value = hasil.formatted

  const letak = posisiKaret(hasil.formatted, digitKiri)

  el.setSelectionRange(letak, letak)
}

/*
| Saat selesai diisi, isiannya dirapikan: titik desimal yang menggantung
| dibuang dan nolnya dibakukan. Dirapikan di akhir, bukan sambil diketik,
| supaya tidak melawan jari yang sedang mengetik.
*/
const saatSelesai = () => {
  if (!teks.value) {
    emit('update:modelValue', props.emptyAsZero ? 0 : null)

    return
  }

  const hasil = ketikAngka(teks.value, opsi.value)

  teks.value = hasil.numeric === null
    ? ''
    : ketikAngka(String(hasil.numeric), opsi.value).formatted

  emit('update:modelValue', hasil.numeric)
}
</script>

<template>
  <VTextField
    v-bind="$attrs"
    :model-value="teks"
    inputmode="decimal"
    @input="saatKetik"
    @blur="saatSelesai"
  />
</template>
