<script setup lang="ts">
import { computed, onMounted, reactive, ref } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import { useI18n } from 'vue-i18n'
import axios from '@axios'
import {
  closeAlert,
  showConfirmAlert,
  showErrorToast,
  showLoadingAlert,
  showSuccessToast,
  showWarningToast,
} from '@/utils/alert'
import { getApiErrorMessage } from '@/utils/apiHelper'
import { usePermissionStore } from '@/stores/permission'
import { useDialogDatePicker } from '@core/composable/useDialogDatePicker'

/*
|--------------------------------------------------------------------------
| Ubah Perjalanan Dinas
|--------------------------------------------------------------------------
| Identitas pemohon ditampilkan dari DOKUMEN, bukan dari akun yang sedang
| membuka. Ia adalah keadaan saat dokumen dibuat, dan tidak ikut diperbarui --
| kalau ikut, perdin tahun lalu akan berganti jabatan sendiri begitu orangnya
| naik pangkat.
|--------------------------------------------------------------------------
*/

interface ItineraryForm {
  date: string | null
  time_start: string | null
  time_end: string | null
  timezone: string
  description: string
  pic: string
}

const { t } = useI18n()
const route = useRoute()
const router = useRouter()
const permissionStore = usePermissionStore()

const canUpdate = computed(() => permissionStore.can('business_trip.update'))

const publicId = computed<string>(() => String(route.query.id ?? ''))

const loadingForm = ref(true)
const saving = ref(false)
const isSubmitted = ref(false)
const notFound = ref(false)

const tripNumber = ref('')

const employee = reactive({
  employee_name: '',
  department_name: '' as string | null,
  position_name: '' as string | null,
})

const timezones = ref<string[]>(['WIB', 'WITA', 'WIT'])

const emptyRow = (): ItineraryForm => ({
  date: null,
  time_start: null,
  time_end: null,
  timezone: 'WIB',
  description: '',
  pic: '',
})

const form = reactive({
  destination: '',
  depart_date: null as string | null,
  depart_time: null as string | null,
  return_date: null as string | null,
  return_time: null as string | null,
  purpose: '',
  notes: '',

  /*
   * Dimulai KOSONG, bukan satu baris kosong. Satu baris kosong membuat
   * daftarnya terlihat berisi -- tombolnya berbunyi "Ubah" padahal belum
   * ada apa pun untuk diubah. Baris pertamanya disediakan modal saat
   * dibuka.
   */
  itineraries: [] as ItineraryForm[],
})

/** Baris rundown yang belum lengkap: tanggal, jam mulai, dan keterangan wajib. */
const rowIncomplete = (baris: ItineraryForm): boolean =>
  !baris.date || !baris.time_start || !baris.description.trim()

/*
|--------------------------------------------------------------------------
| Rundown disunting di modal layar penuh
|--------------------------------------------------------------------------
| Mengikuti pola rincian FPU. Satu baris rundown membawa tanggal, dua jam,
| zona waktu, keterangan, dan PIC sekaligus -- tujuh kolom yang terlalu
| padat untuk dimuat di tengah formulir, dan hanya menyisakan kotak-kotak
| sempit yang harus digeser ke samping untuk dibaca.
|
| Halaman utama hanya menampilkan ringkasannya sebagai teks.
|--------------------------------------------------------------------------
*/
const itineraryDialog = ref(false)
const itineraryDialogSaved = ref(false)
const confirmCloseItinerary = ref(false)
const tempItineraries = ref<ItineraryForm[]>([])

/* Keadaan saat modal dibuka -- titik kembali bagi tombol Reset. */
const itineraryOnOpen = ref<ItineraryForm[]>([])
const confirmResetItinerary = ref(false)

/* Baris tidak membawa objek File, jadi salinan dangkal sudah cukup. */
const cloneRows = (rows: ItineraryForm[]): ItineraryForm[] =>
  rows.map(row => ({ ...row }))

/** Pulang mendahului berangkat: periodenya belum masuk akal. */
const returnBeforeDepart = computed<boolean>(() =>
  !!form.depart_date
  && !!form.return_date
  && form.return_date < form.depart_date)

/*
| Jendela tanggal rundown MENGIKUTI PERIODE PERJALANAN.
|
| Bukan "tujuh hari dari hari ini": angka itu tidak ada hubungannya dengan
| perjalanan yang sedang direncanakan, dan menutup perdin yang diajukan
| jauh hari. Rundown memang tidak mungkin berada di luar perjalanannya.
*/
const toIsoDate = (d: Date): string => {
  const pad = (n: number): string => String(n).padStart(2, '0')

  return `${d.getFullYear()}-${pad(d.getMonth() + 1)}-${pad(d.getDate())}`
}

/*
| Periode perjalanan tidak boleh mundur ke belakang.
|
| Berangkat paling awal hari ini -- izin perjalanan mendahului
| perjalanannya, bukan menyusul. Kembali paling awal tanggal berangkat.
|
| Dibatasi di kalendernya, bukan hanya ditolak setelah disimpan: tanggal
| yang salah sebaiknya tidak pernah sempat dipilih.
*/
const todayIso = computed<string>(() => toIsoDate(new Date()))

const departDateConfig = computed(() => ({
  dateFormat: 'Y-m-d',
  position: 'below',
  minDate: todayIso.value,
}))

const returnDateConfig = computed(() => ({
  dateFormat: 'Y-m-d',
  position: 'below',
  minDate: form.depart_date || todayIso.value,
}))

const itineraryMinDate = computed<string>(() => form.depart_date ?? '')
const itineraryMaxDate = computed<string>(() => form.return_date ?? '')

/*
| Periode perjalanannya harus sudah diisi sebelum rundown bisa disusun --
| tanpa itu tidak ada yang membatasi tanggalnya, dan kerangka per hari
| tidak tahu hari apa saja yang harus dibuat.
*/
const canFillItinerary = computed<boolean>(() =>
  !!form.depart_date && !!form.return_date && !returnBeforeDepart.value)

/** Setiap hari dalam periode perjalanan, sebagai tanggal ISO. */
const tripDays = computed<string[]>(() => {
  if (!canFillItinerary.value)
    return []

  const mulai = new Date(`${form.depart_date}T00:00:00`).getTime()
  const akhir = new Date(`${form.return_date}T00:00:00`).getTime()

  /* Indonesia tidak mengenal waktu musim panas, jadi sehari selalu 24 jam. */
  const sehari = 24 * 60 * 60 * 1000

  /* Batas aman: perjalanan yang lebih panjang dari ini hampir pasti salah ketik. */
  const jumlah = Math.min(Math.floor((akhir - mulai) / sehari) + 1, 90)

  if (jumlah < 1)
    return []

  return Array.from(
    { length: jumlah },
    (_, i) => toIsoDate(new Date(mulai + (i * sehari))),
  )
})

/*
| Kalender rundown memakai komponen yang sama dengan Tanggal Berangkat dan
| Tanggal Kembali, supaya satu layar tidak memuat dua bentuk pemilih
| tanggal yang berbeda.
|
| Penyebab melesetnya kalender di dalam dialog diselesaikan di
| useDialogDatePicker -- lihat catatan di sana.
*/
const { dialogDateConfig } = useDialogDatePicker()

const itineraryDateConfig = computed(() => dialogDateConfig({
  minDate: itineraryMinDate.value,
  maxDate: itineraryMaxDate.value,
}))

/*
| Menambah baris. Satu per satu tetap ada; pilihan borongan hanya
| menyingkat pekerjaan yang sama, untuk rundown panjang.
*/
const BULK_ROW_OPTIONS = [5, 10, 20]

/*
| Baris baru mewarisi baris di atasnya.
|
| Pada rundown sungguhan, tanggal dan zona hampir selalu sama dengan baris
| sebelumnya, dan jam mulai sebuah kegiatan biasanya jam selesai kegiatan
| sebelumnya. Diwariskan, bukan dikunci -- semuanya tetap bisa diubah.
*/
const addRow = (count = 1): void => {
  for (let i = 0; i < count; i++) {
    const sebelumnya = tempItineraries.value[tempItineraries.value.length - 1]

    const baris = emptyRow()

    if (sebelumnya) {
      baris.date = sebelumnya.date
      baris.timezone = sebelumnya.timezone

      /* Hanya baris pertama yang menyambung jamnya; sisanya dibiarkan kosong. */
      if (i === 0)
        baris.time_start = sebelumnya.time_end || null
    }

    tempItineraries.value.push(baris)
  }
}

/**
 * Membuat satu baris per hari perjalanan, tanggalnya sudah terisi.
 *
 * Hari yang sudah punya baris DILEWATI: tombolnya menambah yang kurang,
 * bukan menimpa yang sudah disusun. Menekannya dua kali karena itu tidak
 * menggandakan apa pun.
 */
const buildDaySkeleton = (): void => {
  const sudahAda = new Set(
    tempItineraries.value.map(row => row.date).filter(Boolean),
  )

  const kurang = tripDays.value.filter(hari => !sudahAda.has(hari))

  if (!kurang.length) {
    showWarningToast({
      title: t('common.alert.warning'),
      text: t('businessTrip.form.itineraryDialog.skeletonNothingToAdd'),
    })

    return
  }

  /* Baris kosong yang menganggur dipakai dulu sebelum menambah yang baru. */
  const kosong = tempItineraries.value.filter(
    row => !row.date && !row.time_start && !row.description.trim() && !row.pic.trim(),
  )

  const terpakai = tempItineraries.value.filter(row => !kosong.includes(row))

  const zona = terpakai[terpakai.length - 1]?.timezone ?? 'WIB'

  tempItineraries.value = [
    ...terpakai,
    ...kurang.map(hari => ({ ...emptyRow(), date: hari, timezone: zona })),
  ]
}

const removeRow = (index: number): void => {
  if (tempItineraries.value.length <= 1)
    return

  tempItineraries.value.splice(index, 1)
}

const openItineraryDialog = (): void => {
  /*
  | Periodenya disyaratkan di sini, bukan dengan mematikan tombolnya.
  | Tombol mati tidak bisa ditanyai -- ia tidak menerima klik dan tidak
  | memunculkan tooltip, jadi yang menekannya hanya tahu "tidak bisa",
  | tanpa tahu kenapa.
  */
  if (!canFillItinerary.value) {
    isSubmitted.value = true

    showWarningToast({
      title: t('common.alert.warning'),
      text: t('businessTrip.form.itineraryDialog.needPeriod'),
    })

    return
  }

  tempItineraries.value = cloneRows(form.itineraries)

  if (!tempItineraries.value.length)
    tempItineraries.value = [emptyRow()]

  itineraryOnOpen.value = cloneRows(tempItineraries.value)

  itineraryDialogSaved.value = false
  itineraryDialog.value = true
}

const closeItineraryDialog = (): void => {
  if (itineraryDialogSaved.value) {
    tempItineraries.value = []
    itineraryDialog.value = false

    return
  }

  /* Isian yang belum disimpan tidak dibuang tanpa bertanya. */
  confirmCloseItinerary.value = true
}

/**
 * Mengembalikan isian ke keadaan saat modal dibuka.
 *
 * Bukan mengosongkan: bagi orang yang baru saja salah menambah dua puluh
 * baris, titik mulainya adalah rundown yang sudah ia simpan, bukan lembar
 * kosong. Modalnya tetap terbuka -- yang diminta memang membatalkan
 * suntingan, bukan berhenti menyunting.
 */
const resetItineraryDialog = (): void => {
  tempItineraries.value = cloneRows(itineraryOnOpen.value)

  if (!tempItineraries.value.length)
    tempItineraries.value = [emptyRow()]

  confirmResetItinerary.value = false
}

const discardItineraryDialog = (): void => {
  confirmCloseItinerary.value = false
  tempItineraries.value = []
  itineraryDialog.value = false
}

const saveItineraryDialog = (): void => {
  /*
  | Baris kosong seluruhnya dibuang diam-diam: itu sisa dari tombol
  | "tambah 20" yang tidak semuanya terpakai, bukan kesalahan pengguna.
  */
  const terisi = tempItineraries.value.filter(
    row => row.date || row.time_start || row.description.trim() || row.pic.trim(),
  )

  if (!terisi.length) {
    showWarningToast({
      title: t('common.alert.warning'),
      text: t('businessTrip.form.validation.itineraryEmpty'),
    })

    return
  }

  /* Yang tersisa harus lengkap -- separuh terisi bukan sisa, itu lupa. */
  const kurang = terisi.findIndex(rowIncomplete)

  if (kurang !== -1) {
    showWarningToast({
      title: t('common.alert.warning'),
      text: t('businessTrip.form.validation.rowNumberIncomplete', { number: kurang + 1 }),
    })

    return
  }

  form.itineraries = cloneRows(terisi)
  itineraryDialogSaved.value = true
  itineraryDialog.value = false
}

/*
| Ringkasan dibaca orang, bukan diisi -- jadi tanggalnya ditulis lengkap
| dengan nama hari, seperti pada formulir kertasnya.
*/
const summaryDate = (nilai?: string | null): string => {
  if (!nilai)
    return '-'

  const d = new Date(`${nilai}T00:00:00`)

  if (Number.isNaN(d.getTime()))
    return nilai

  return d.toLocaleDateString(undefined, {
    weekday: 'long',
    day: '2-digit',
    month: 'short',
    year: 'numeric',
  })
}

/** "13:00 - 17:00 WITA", dirangkai dari tiga kolom terpisah. */
const summaryTime = (row: ItineraryForm): string => {
  if (!row.time_start)
    return ''

  const rentang = row.time_end && row.time_end !== row.time_start
    ? `${row.time_start} - ${row.time_end}`
    : row.time_start

  return `${rentang} ${row.timezone}`.trim()
}

const isValid = computed<boolean>(() =>
  !!form.destination.trim()
  && !!form.depart_date
  && !!form.depart_time
  && !!form.return_date
  && !!form.return_time
  && !returnBeforeDepart.value
  && !!form.purpose.trim()
  && form.itineraries.length > 0
  && !form.itineraries.some(rowIncomplete))

const goBack = (): void => {
  router.push('/business_trip/perdin')
}

/*
| Pembacaan rundown dipisah dari pembacaan dokumennya.
|
| Digabung, satu fungsi harus sekaligus menangani permintaan gagal, dokumen
| kosong, daftar kosong, dan tiap medan yang boleh tidak ada -- percabangannya
| menumpuk sampai tidak terbaca lagi.
*/
const readItineraries = (data: Record<string, unknown>): ItineraryForm[] => {
  const daftar = Array.isArray(data.itineraries) ? data.itineraries : []

  /* Kosong tetap kosong -- lihat alasannya pada nilai awal form.itineraries. */
  if (!daftar.length)
    return []

  return daftar.map((baris: Record<string, unknown>): ItineraryForm => ({
    date: (baris.date as string) ?? null,
    time_start: (baris.time_start as string) ?? null,
    time_end: (baris.time_end as string) ?? null,
    timezone: (baris.timezone as string) ?? 'WIB',
    description: (baris.description as string) ?? '',
    pic: (baris.pic as string) ?? '',
  }))
}

const fetchDetail = async (): Promise<void> => {
  loadingForm.value = true

  try {
    const response = await axios.get(
      `/business-trip/perdin/${encodeURIComponent(publicId.value)}`,
    )

    const data = response.data?.data

    if (!data) {
      notFound.value = true

      return
    }

    tripNumber.value = data.trip_number ?? ''

    employee.employee_name = data.employee_name ?? ''
    employee.department_name = data.department_name ?? null
    employee.position_name = data.position_name ?? null

    form.destination = data.destination ?? ''
    form.depart_date = data.depart_date ?? null
    form.depart_time = data.depart_time ?? null
    form.return_date = data.return_date ?? null
    form.return_time = data.return_time ?? null
    form.purpose = data.purpose ?? ''
    form.notes = data.notes ?? ''

    form.itineraries = readItineraries(data)
  }
  catch (error: unknown) {
    notFound.value = true

    showErrorToast({
      title: t('common.alert.error'),
      text: getApiErrorMessage(error, t('businessTrip.form.loadFailed')),
    })
  }
  finally {
    loadingForm.value = false
  }
}

const save = async (): Promise<void> => {
  isSubmitted.value = true

  if (!isValid.value) {
    showWarningToast({
      title: t('common.alert.warning'),
      text: t('businessTrip.form.validation.summary'),
    })

    return
  }

  const konfirmasi = await showConfirmAlert({
    title: t('businessTrip.form.confirmTitle'),
    text: t('businessTrip.form.confirmText'),
    confirmButtonText: t('common.actions.confirm'),
    cancelButtonText: t('common.actions.cancel'),
  })

  if (!konfirmasi.isConfirmed)
    return

  saving.value = true

  try {
    showLoadingAlert(t('common.alert.processing'), t('common.alert.pleaseWait'))

    await axios.put(`/business-trip/perdin/${encodeURIComponent(publicId.value)}`, {
      destination: form.destination.trim(),
      depart_date: form.depart_date,
      depart_time: form.depart_time,
      return_date: form.return_date,
      return_time: form.return_time,
      purpose: form.purpose.trim(),
      notes: form.notes.trim() || null,

      itineraries: form.itineraries.map(baris => ({
        date: baris.date,
        time_start: baris.time_start,
        time_end: baris.time_end || null,
        timezone: baris.timezone,
        description: baris.description.trim(),
        pic: baris.pic.trim() || null,
      })),
    })

    closeAlert()

    showSuccessToast({
      title: t('common.alert.success'),
      text: t('businessTrip.form.saveSuccess'),
    })

    goBack()
  }
  catch (error: unknown) {
    closeAlert()

    showErrorToast({
      title: t('common.alert.error'),
      text: getApiErrorMessage(error, t('businessTrip.form.saveFailed')),
    })
  }
  finally {
    saving.value = false
  }
}

onMounted(() => {
  if (!canUpdate.value) {
    loadingForm.value = false

    return
  }

  if (!publicId.value) {
    notFound.value = true
    loadingForm.value = false

    return
  }

  fetchDetail()
})
</script>

<template>
  <section>
    <VAlert
      v-if="!canUpdate"
      type="warning"
      variant="tonal"
    >
      {{ t('businessTrip.form.noPermissionEdit') }}
    </VAlert>

    <VAlert
      v-else-if="notFound"
      type="error"
      variant="tonal"
    >
      {{ t('businessTrip.detail.loadFailed') }}
    </VAlert>

    <VCard v-else>
      <VCardTitle class="d-flex align-center justify-space-between flex-wrap gap-3">
        <div>
          <div class="text-h6 font-weight-bold">
            {{ t('businessTrip.form.editTitle') }}
          </div>

          <div class="text-body-2 text-medium-emphasis">
            {{ tripNumber || t('businessTrip.form.editSubtitle') }}
          </div>
        </div>

        <VBtn
          prepend-icon="tabler-arrow-left"
          variant="text"
          color="secondary"
          class="text-none"
          @click="goBack"
        >
          {{ t('businessTrip.form.backButton') }}
        </VBtn>
      </VCardTitle>

      <VDivider />

      <VProgressLinear
        v-if="loadingForm"
        indeterminate
        color="primary"
      />

      <VCardText>
        <!-- IDENTITAS: dari dokumen, bukan dari akun yang membuka -->
        <div class="text-subtitle-2 font-weight-medium mb-1">
          {{ t('businessTrip.form.identity') }}
        </div>

        <div class="text-body-2 text-medium-emphasis mb-3">
          {{ t('businessTrip.form.editSubtitle') }}
        </div>

        <VRow class="mb-2">
          <VCol
            cols="12"
            md="4"
          >
            <VTextField
              :model-value="employee.employee_name"
              :label="t('businessTrip.form.fields.employeeName')"
              density="comfortable"
              readonly
              variant="filled"
            />
          </VCol>

          <VCol
            cols="12"
            md="4"
          >
            <VTextField
              :model-value="employee.department_name || '-'"
              :label="t('businessTrip.form.fields.department')"
              density="comfortable"
              readonly
              variant="filled"
            />
          </VCol>

          <VCol
            cols="12"
            md="4"
          >
            <VTextField
              :model-value="employee.position_name || '-'"
              :label="t('businessTrip.form.fields.position')"
              density="comfortable"
              readonly
              variant="filled"
            />
          </VCol>
        </VRow>

        <VDivider class="my-4" />

        <!-- PERJALANAN -->
        <div class="text-subtitle-2 font-weight-medium mb-3">
          {{ t('businessTrip.form.trip') }}
        </div>

        <VRow>
          <VCol cols="12">
            <VTextField
              v-model="form.destination"
              :label="t('businessTrip.form.fields.destination')"
              density="comfortable"
              :error="isSubmitted && !form.destination.trim()"
              :error-messages="isSubmitted && !form.destination.trim()
                ? [t('businessTrip.form.validation.destination')]
                : []"
            />
          </VCol>

          <VCol
            cols="12"
            md="4"
          >
            <AppDateTimePicker
              v-model="form.depart_date"
              :label="t('businessTrip.form.fields.departDate')"
              :config="departDateConfig"
              :error="isSubmitted && !form.depart_date"
              :error-messages="isSubmitted && !form.depart_date
                ? [t('businessTrip.form.validation.departDate')]
                : []"
            />
          </VCol>

          <VCol
            cols="12"
            md="2"
          >
            <VTextField
              v-model="form.depart_time"
              :label="t('businessTrip.form.fields.departTime')"
              type="time"
              density="comfortable"
              :error="isSubmitted && !form.depart_time"
              :error-messages="isSubmitted && !form.depart_time
                ? [t('businessTrip.form.validation.departTime')]
                : []"
            />
          </VCol>

          <VCol
            cols="12"
            md="4"
          >
            <AppDateTimePicker
              v-model="form.return_date"
              :label="t('businessTrip.form.fields.returnDate')"
              :config="returnDateConfig"
              :error="isSubmitted && (!form.return_date || returnBeforeDepart)"
              :error-messages="isSubmitted && !form.return_date
                ? [t('businessTrip.form.validation.returnDate')]
                : (returnBeforeDepart ? [t('businessTrip.form.validation.returnBeforeDepart')] : [])"
            />
          </VCol>

          <VCol
            cols="12"
            md="2"
          >
            <VTextField
              v-model="form.return_time"
              :label="t('businessTrip.form.fields.returnTime')"
              type="time"
              density="comfortable"
              :error="isSubmitted && !form.return_time"
              :error-messages="isSubmitted && !form.return_time
                ? [t('businessTrip.form.validation.returnTime')]
                : []"
            />
          </VCol>

          <VCol cols="12">
            <VTextarea
              v-model="form.purpose"
              :label="t('businessTrip.form.fields.purpose')"
              rows="2"
              density="comfortable"
              :error="isSubmitted && !form.purpose.trim()"
              :error-messages="isSubmitted && !form.purpose.trim()
                ? [t('businessTrip.form.validation.purpose')]
                : []"
            />
          </VCol>

          <VCol cols="12">
            <VTextarea
              v-model="form.notes"
              :label="t('businessTrip.form.fields.notes')"
              rows="2"
              density="comfortable"
            />
          </VCol>
        </VRow>

        <VDivider class="my-4" />

        <!-- ITINERARY: ringkasan baca-saja, penyuntingan di modal layar penuh -->
        <div class="d-flex align-center justify-space-between flex-wrap gap-3 mb-3">
          <div>
            <div class="text-subtitle-2 font-weight-medium">
              {{ t('businessTrip.form.itinerary') }}
            </div>

            <div class="text-body-2 text-medium-emphasis">
              {{ t('businessTrip.form.itineraryHint') }}
            </div>
          </div>

          <VBtn
            color="primary"
            variant="tonal"
            class="text-none"
            prepend-icon="tabler-list-details"
            @click="openItineraryDialog"
          >
            {{ form.itineraries.length
              ? t('businessTrip.form.itineraryDialog.editButton')
              : t('businessTrip.form.itineraryDialog.fillButton') }}
          </VBtn>
        </div>

        <VCard
          flat
          class="bt-summary-card"
        >
          <VCardText>
            <!--
              Dua sebab kosong yang berbeda jauh: periodenya belum diisi, atau
              sudah diisi tetapi rundown-nya memang belum disusun. Yang
              pertama tidak bisa diselesaikan di sini, jadi harus dikatakan.
            -->
            <VAlert
              v-if="!canFillItinerary"
              type="info"
              variant="tonal"
              density="compact"
            >
              {{ t('businessTrip.form.itineraryDialog.needPeriod') }}
            </VAlert>

            <VAlert
              v-else-if="!form.itineraries.length || form.itineraries.every(row => !row.description)"
              :type="isSubmitted ? 'warning' : 'info'"
              variant="tonal"
              density="compact"
            >
              {{ t('businessTrip.form.itineraryDialog.emptyAlert', {
                action: t('businessTrip.form.itineraryDialog.fillButton'),
              }) }}
            </VAlert>

            <div
              v-else
              class="d-flex flex-column gap-3"
            >
              <div
                v-for="(row, index) in form.itineraries"
                :key="`summary-row-${index}`"
                class="bt-summary-row"
              >
                <div class="d-flex align-start gap-3">
                  <VAvatar
                    size="30"
                    color="primary"
                    variant="tonal"
                  >
                    {{ index + 1 }}
                  </VAvatar>

                  <div class="flex-grow-1 min-w-0">
                    <div class="font-weight-bold">
                      {{ row.description || '-' }}
                    </div>

                    <div class="text-caption text-medium-emphasis mt-1">
                      {{ summaryDate(row.date) }}
                      <span v-if="summaryTime(row)">&middot; {{ summaryTime(row) }}</span>
                    </div>
                  </div>

                  <div
                    v-if="row.pic"
                    class="text-end"
                  >
                    <div class="text-caption text-medium-emphasis">
                      {{ t('businessTrip.form.fields.rowPic') }}
                    </div>

                    <div class="font-weight-medium">
                      {{ row.pic }}
                    </div>
                  </div>
                </div>
              </div>
            </div>
          </VCardText>
        </VCard>
      </VCardText>

      <VDivider />

      <VCardActions class="justify-end pa-4">
        <VBtn
          variant="tonal"
          color="secondary"
          class="text-none"
          @click="goBack"
        >
          {{ t('businessTrip.form.backButton') }}
        </VBtn>

        <VBtn
          color="primary"
          class="text-none"
          :loading="saving"
          @click="save"
        >
          {{ t('businessTrip.form.saveButton') }}
        </VBtn>
      </VCardActions>
    </VCard>

    <!--
      RUNDOWN -- modal layar penuh, mengikuti rincian FPU.

      persistent: isian yang belum disimpan tidak boleh hilang hanya karena
      salah klik di luar kotaknya.
    -->
    <VDialog
      v-model="itineraryDialog"
      fullscreen
      scrollable
      persistent
      no-click-animation
      :retain-focus="false"
    >
      <VCard>
        <VToolbar color="primary">
          <VBtn
            icon
            variant="text"
            color="white"
            @click="closeItineraryDialog"
          >
            <VIcon icon="tabler-x" />
          </VBtn>

          <VToolbarTitle>
            {{ t('businessTrip.form.itineraryDialog.title') }}
          </VToolbarTitle>

          <VSpacer />

          <!--
            Kerangka per hari: memakai tanggal berangkat dan kembali yang
            sudah diisi di formulir, jadi tanggalnya tidak perlu diketik
            satu per satu.
          -->
          <VBtn
            variant="text"
            color="white"
            class="me-2 text-none"
            prepend-icon="tabler-calendar-plus"
            :disabled="!tripDays.length"
            @click="buildDaySkeleton"
          >
            {{ t('businessTrip.form.itineraryDialog.skeletonButton', {
              count: tripDays.length,
            }) }}
          </VBtn>

          <!--
            Tombol bercabang: badannya menambah SATU baris seperti biasa,
            panahnya membuka pilihan borongan. Disatukan dalam satu
            kelompok supaya terbaca sebagai satu tombol, bukan dua tombol
            yang kebetulan bersebelahan.
          -->
          <VBtnGroup
            variant="flat"
            divided
            density="comfortable"
            class="me-3 bt-add-row-group"
          >
            <VBtn
              prepend-icon="tabler-plus"
              class="text-none"
              @click="addRow(1)"
            >
              {{ t('businessTrip.form.addRowShort') }}
            </VBtn>

            <VMenu location="bottom end">
              <template #activator="{ props: menuProps }">
                <VBtn
                  v-bind="menuProps"
                  icon="tabler-chevron-down"
                  :aria-label="t('businessTrip.form.itineraryDialog.addManyTitle')"
                />
              </template>

              <VList density="compact">
                <VListSubheader>
                  {{ t('businessTrip.form.itineraryDialog.addManyTitle') }}
                </VListSubheader>

                <VListItem
                  v-for="jumlah in BULK_ROW_OPTIONS"
                  :key="`bulk-${jumlah}`"
                  @click="addRow(jumlah)"
                >
                  <template #prepend>
                    <VIcon
                      icon="tabler-rows-plus-bottom"
                      size="18"
                    />
                  </template>

                  <VListItemTitle>
                    {{ t('businessTrip.form.itineraryDialog.addManyOption', { count: jumlah }) }}
                  </VListItemTitle>
                </VListItem>
              </VList>
            </VMenu>
          </VBtnGroup>

          <!--
            Reset tidak menutup modal: yang diminta membatalkan suntingan,
            bukan berhenti menyunting.
          -->
          <VBtn
            variant="text"
            color="white"
            class="me-2 text-none"
            prepend-icon="tabler-rotate"
            @click="confirmResetItinerary = true"
          >
            {{ t('businessTrip.form.itineraryDialog.resetButton') }}
          </VBtn>

          <VBtn
            variant="flat"
            class="me-3 text-none"
            @click="saveItineraryDialog"
          >
            {{ t('businessTrip.form.itineraryDialog.saveButton') }}
          </VBtn>
        </VToolbar>

        <VCardText class="pa-4">
          <VAlert
            type="info"
            variant="tonal"
            density="comfortable"
            class="mb-4"
          >
            {{ t('businessTrip.form.itineraryDialog.dateWindow', {
              from: summaryDate(itineraryMinDate),
              to: summaryDate(itineraryMaxDate),
            }) }}
          </VAlert>

          <div class="bt-table-scroll">
            <VTable density="compact">
              <thead>
                <tr>
                  <th style="inline-size: 56px;">
                    {{ t('businessTrip.columns.no') }}
                  </th>
                  <th style="min-inline-size: 190px;">
                    {{ t('businessTrip.form.fields.rowDate') }}
                    <span class="text-error">*</span>
                  </th>
                  <th class="bt-time-col">
                    {{ t('businessTrip.form.fields.rowTimeStart') }}
                    <span class="text-error">*</span>
                  </th>
                  <th class="bt-time-col">
                    {{ t('businessTrip.form.fields.rowTimeEnd') }}
                  </th>
                  <th style="min-inline-size: 110px;">
                    {{ t('businessTrip.form.fields.rowTimezone') }}
                  </th>
                  <th style="min-inline-size: 320px;">
                    {{ t('businessTrip.form.fields.rowDescription') }}
                    <span class="text-error">*</span>
                  </th>
                  <th style="min-inline-size: 200px;">
                    {{ t('businessTrip.form.fields.rowPic') }}
                  </th>
                  <th style="inline-size: 56px;" />
                </tr>
              </thead>

              <tbody>
                <tr
                  v-for="(baris, index) in tempItineraries"
                  :key="`row-${index}`"
                >
                  <td>{{ index + 1 }}</td>

                  <td>
                    <AppDateTimePicker
                      v-model="baris.date"
                      density="compact"
                      hide-details="auto"
                      :config="itineraryDateConfig"
                      :placeholder="t('businessTrip.form.itineraryDialog.datePlaceholder')"
                    />
                  </td>

                  <td class="bt-time-col">
                    <VTextField
                      v-model="baris.time_start"
                      type="time"
                      density="compact"
                      hide-details="auto"
                    />
                  </td>

                  <td class="bt-time-col">
                    <VTextField
                      v-model="baris.time_end"
                      type="time"
                      density="compact"
                      hide-details="auto"
                    />
                  </td>

                  <td>
                    <VSelect
                      v-model="baris.timezone"
                      :items="timezones"
                      density="compact"
                      hide-details="auto"
                    />
                  </td>

                  <td>
                    <VTextField
                      v-model="baris.description"
                      density="compact"
                      hide-details="auto"
                      :placeholder="t('businessTrip.form.itineraryDialog.descriptionPlaceholder')"
                    />
                  </td>

                  <td>
                    <VTextField
                      v-model="baris.pic"
                      density="compact"
                      hide-details="auto"
                      :placeholder="t('businessTrip.form.itineraryDialog.picPlaceholder')"
                    />
                  </td>

                  <td>
                    <VBtn
                      icon
                      variant="text"
                      size="small"
                      color="error"
                      :disabled="tempItineraries.length <= 1"
                      :title="t('businessTrip.form.removeRow')"
                      @click="removeRow(index)"
                    >
                      <VIcon icon="tabler-trash" />
                    </VBtn>
                  </td>
                </tr>
              </tbody>
            </VTable>
          </div>

          <div class="text-caption text-medium-emphasis mt-3">
            {{ t('businessTrip.form.itineraryDialog.inheritNote') }}
            {{ t('businessTrip.form.itineraryDialog.blankRowsNote') }}
          </div>
        </VCardText>
      </VCard>
    </VDialog>

    <!-- Reset: ditanyakan dulu, karena suntingannya ikut hilang -->
    <VDialog
      v-model="confirmResetItinerary"
      max-width="460"
    >
      <VCard>
        <VCardTitle class="text-h6 font-weight-bold">
          {{ t('businessTrip.form.itineraryDialog.resetTitle') }}
        </VCardTitle>

        <VCardText>
          {{ t('businessTrip.form.itineraryDialog.resetText') }}
        </VCardText>

        <VCardActions class="justify-end">
          <VBtn
            variant="tonal"
            color="secondary"
            class="text-none"
            @click="confirmResetItinerary = false"
          >
            {{ t('common.actions.cancel') }}
          </VBtn>

          <VBtn
            color="warning"
            class="text-none"
            @click="resetItineraryDialog"
          >
            {{ t('businessTrip.form.itineraryDialog.resetButton') }}
          </VBtn>
        </VCardActions>
      </VCard>
    </VDialog>

    <!-- Menutup modal tanpa menyimpan: ditanyakan, tidak dibuang diam-diam -->
    <VDialog
      v-model="confirmCloseItinerary"
      max-width="460"
    >
      <VCard>
        <VCardTitle class="text-h6 font-weight-bold">
          {{ t('businessTrip.form.itineraryDialog.discardTitle') }}
        </VCardTitle>

        <VCardText>
          {{ t('businessTrip.form.itineraryDialog.discardText') }}
        </VCardText>

        <VCardActions class="justify-end">
          <VBtn
            variant="tonal"
            color="secondary"
            class="text-none"
            @click="confirmCloseItinerary = false"
          >
            {{ t('common.actions.cancel') }}
          </VBtn>

          <VBtn
            color="error"
            class="text-none"
            @click="discardItineraryDialog"
          >
            {{ t('businessTrip.form.itineraryDialog.discardButton') }}
          </VBtn>
        </VCardActions>
      </VCard>
    </VDialog>
  </section>
</template>

<style scoped>
/* Rundown-nya lebar; yang boleh menggeser hanya tabelnya, bukan halamannya. */
.bt-table-scroll {
  overflow-x: auto;
}

/*
 * Kolom jam.
 *
 * Input jam bawaan browser menggambar ikon pemilihnya di dalam kotak
 * isian. Pada penanggalan 12 jam isinya "--:-- --", dan bersama ikonnya ia
 * tidak muat di kolom sempit -- yang terpotong ikonnya.
 *
 * Kolomnya diberi ruang tetap, dan padding dalam kotaknya dikurangi.
 */
.bt-time-col {
  min-inline-size: 156px;
}

.bt-time-col :deep(.v-field__input) {
  padding-inline: 8px;
}

.bt-time-col :deep(input[type="time"]) {
  inline-size: 100%;
}

.bt-add-row-group {
  background: rgba(var(--v-theme-on-primary), 0.14);
  block-size: 36px;
}

.bt-summary-card {
  border: 1px solid rgba(var(--v-border-color), var(--v-border-opacity));
  border-radius: 6px;
}

.bt-summary-row {
  padding-block: 10px;
  border-block-end: 1px dashed rgba(var(--v-border-color), var(--v-border-opacity));
}

.bt-summary-row:last-child {
  padding-block-end: 0;
  border-block-end: 0;
}
</style>
