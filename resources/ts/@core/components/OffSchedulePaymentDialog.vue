<script setup lang="ts">
import { computed, ref } from 'vue'
import { useI18n } from 'vue-i18n'
import { formatDateLong } from '@/utils/textFormatter'

/*
|--------------------------------------------------------------------------
| Penegasan pembayaran di luar jadwal
|--------------------------------------------------------------------------
| Dipakai FPU, Realisasi, dan Claim saat Finance membayar lebih awal atau
| terlambat dari jadwal yang sudah dijanjikan ke pemohon.
|
| Berdiri sebagai dialog tersendiri, bukan SweetAlert, karena isinya bukan
| sekadar pertanyaan: ada keadaan yang perlu ditonjolkan, tanggal yang perlu
| terbaca jelas, dan satu isian catatan. SweetAlert bisa dipaksa
| menampilkannya, tetapi hasilnya berupa satu kalimat panjang yang menempel
| di kotak teks -- dan merapikannya berarti menambal gaya bawaannya dari luar.
|
| Dipanggil secara imperatif supaya sisi pemanggil tetap terbaca berurutan:
|
|   const { confirmed, notes } = await dialog.value.open({ ... })
|
| Pembayaran yang TEPAT jadwal tidak lewat sini sama sekali -- tidak ada yang
| perlu dijelaskan tentang sesuatu yang berjalan sebagaimana mestinya.
|--------------------------------------------------------------------------
*/

/** Yang dibutuhkan dialog ini untuk menampilkan dirinya. */
interface Payload {

  /** Namespace i18n modul pemanggil: cashAdvance, cashAdvanceRealization, claim. */
  ns: string

  title: string
  message: string
  confirmText: string

  deviation: 'EARLY' | 'LATE'
  scheduledDate: string
}

interface Hasil {
  confirmed: boolean
  notes: string
}

const { t, locale } = useI18n()

const dialog = ref(false)
const payload = ref<Payload | null>(null)
const notes = ref('')

/*
| Janji dari open() diselesaikan lewat penampung ini -- dialog Vue tidak punya
| nilai kembali sendiri, jadi jawabannya dikirim balik saat tombol ditekan.
*/
const resolver = ref<((hasil: Hasil) => void) | null>(null)

const isEarly = computed(() => payload.value?.deviation === 'EARLY')

/** Selisih hari antara hari ini dan tanggal jadwalnya, selalu positif. */
const gapDays = computed<number>(() => {
  if (!payload.value?.scheduledDate)
    return 0

  const jadwal = new Date(payload.value.scheduledDate)

  jadwal.setHours(0, 0, 0, 0)

  const hariIni = new Date()

  hariIni.setHours(0, 0, 0, 0)

  return Math.round(Math.abs(jadwal.getTime() - hariIni.getTime()) / 86400000)
})

/*
| Bentuk jamak dipilih lewat dua kunci terpisah, bukan lewat mesin plural --
| Bahasa Indonesia tidak memerlukannya sama sekali, sedangkan Inggris hanya
| membedakan satu dan banyak.
*/
const gapText = computed<string>(() => {
  const ns = payload.value?.ns ?? ''

  return t(
    gapDays.value === 1
      ? `${ns}.list.offSchedule.daySingular`
      : `${ns}.list.offSchedule.dayPlural`,
    { count: gapDays.value },
  )
})

const noticeTitle = computed<string>(() =>
  t(`${payload.value?.ns}.list.offSchedule.${isEarly.value ? 'earlyTitle' : 'lateTitle'}`))

const noticeMeta = computed<string>(() =>
  t(
    `${payload.value?.ns}.list.offSchedule.${isEarly.value ? 'earlyMeta' : 'lateMeta'}`,
    {
      tanggal: formatDateLong(
        payload.value?.scheduledDate ?? null,
        locale.value === 'en' ? 'en-GB' : 'id-ID',
      ),
      jarak: gapText.value,
    },
  ))

/*
| Lebih awal itu netral -- boleh jadi memang mendesak. Terlambat perlu
| perhatian, jadi warnanya berbeda supaya terbaca tanpa harus dibaca.
*/
const tone = computed<{ color: 'info' | 'warning'; icon: string }>(() => (isEarly.value
  ? { color: 'info', icon: 'tabler-calendar-up' }
  : { color: 'warning', icon: 'tabler-clock-exclamation' }))

const noteLabel = computed<string>(() => t(`${payload.value?.ns}.list.offSchedule.noteLabel`))
const notePlaceholder = computed<string>(() => t(`${payload.value?.ns}.list.offSchedule.placeholder`))

const selesai = (confirmed: boolean): void => {
  dialog.value = false

  resolver.value?.({ confirmed, notes: notes.value.trim() })
  resolver.value = null
}

const open = (isi: Payload): Promise<Hasil> => {
  payload.value = isi
  notes.value = ''
  dialog.value = true

  return new Promise<Hasil>(resolve => {
    resolver.value = resolve
  })
}

/*
| Menutup lewat Esc atau klik di luar dianggap batal. Tanpa ini, janji dari
| open() menggantung selamanya dan tombol pemanggilnya ikut terkunci.
*/
const onUpdate = (nilai: boolean): void => {
  dialog.value = nilai

  if (!nilai && resolver.value)
    selesai(false)
}

defineExpose({ open })
</script>

<template>
  <VDialog
    :model-value="dialog"
    max-width="520"
    @update:model-value="onUpdate"
  >
    <VCard v-if="payload">
      <VCardItem class="pb-2">
        <template #prepend>
          <VAvatar
            :color="tone.color"
            variant="tonal"
            rounded
            size="42"
          >
            <VIcon
              :icon="tone.icon"
              size="24"
            />
          </VAvatar>
        </template>

        <VCardTitle class="text-wrap">
          {{ payload.title }}
        </VCardTitle>
      </VCardItem>

      <VCardText>
        <div class="text-body-1">
          {{ payload.message }}
        </div>

        <!-- Keadaan jadwalnya, dipisah dari kalimat penegasan di atasnya -->
        <VAlert
          :type="tone.color"
          variant="tonal"
          density="comfortable"
          class="mt-4"
        >
          <div class="font-weight-medium">
            {{ noticeTitle }}
          </div>

          <div class="text-body-2 mt-1">
            {{ noticeMeta }}
          </div>
        </VAlert>

        <VTextarea
          v-model="notes"
          :label="noteLabel"
          :placeholder="notePlaceholder"
          rows="3"
          auto-grow
          max-rows="6"
          counter="2000"
          maxlength="2000"
          density="comfortable"
          class="mt-4"
        />
      </VCardText>

      <VDivider />

      <VCardActions class="justify-end px-4 py-3">
        <VBtn
          variant="tonal"
          color="secondary"
          class="text-none"
          @click="selesai(false)"
        >
          {{ t('common.actions.cancel') }}
        </VBtn>

        <VBtn
          :color="tone.color"
          variant="elevated"
          class="text-none"
          @click="selesai(true)"
        >
          {{ payload.confirmText }}
        </VBtn>
      </VCardActions>
    </VCard>
  </VDialog>
</template>
