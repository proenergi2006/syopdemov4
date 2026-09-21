<script setup lang="ts">
import { computed, onMounted, reactive, ref, watch } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import { useI18n } from 'vue-i18n'
import axios from '@axios'
import {
  closeAlert,
  showConfirmAlert,
  showErrorToast,
  showLoadingAlert,
  showWarningToast,
} from '@/utils/alert'
import { getApiErrorMessage } from '@/utils/apiHelper'
import { formatSanitizedNumberInput } from '@/utils/textFormatter'
import { usePermissionStore } from '@/stores/permission'
import { useDialogDatePicker } from '@core/composable/useDialogDatePicker'

/*
|--------------------------------------------------------------------------
| Buat Realisasi FPU
|--------------------------------------------------------------------------
| Dokumen ini selalu berangkat dari satu FPU yang sudah dibayarkan. Memilih
| FPU akan menyalin baris-barisnya beserta nominal pengajuan, tinggal diisi
| nominal realisasinya.
|--------------------------------------------------------------------------
*/

interface RealizationItemForm {
  cash_advance_item_id: number | null
  date: string | null
  description: string
  advance_amount: number
  realization_amount: number
  notes: string

  /*
   * Bukti melekat pada baris, bukan pada dokumen -- satu pengeluaran dibaca
   * bersama buktinya sendiri. Wajib minimal satu berkas.
   */
  attachments: File[]
}

interface CashAdvanceOption {
  id: number
  public_id: string
  advance_number: string
  title: string
  date: string | null
  subject: string
  branch: string
  department: string
  transaction_category: string | null
  request_type: string | null
  total_amount: number
  disbursed_at: string | null
  items: Array<{
    id: number
    date: string | null
    description: string
    amount: number
  }>
}

const route = useRoute()
const router = useRouter()
const permissionStore = usePermissionStore()
const { t } = useI18n()

const ENDPOINT = '/fund-request/cash-advance-realization'
const LIST_PATH = '/fund_request/cash_advance_realization'

const isCheckingPermission = ref(true)
const isSubmitted = ref(false)

/*
| Kalender tanggal pada baris rincian berada di dalam dialog layar penuh.
|
| Di sana flatpickr memposisikan kalendernya dengan koordinat dokumen,
| sementara dialog mengunci gulir halaman dengan menggeser <html> -- dan
| kalendernya mendarat menutupi field-nya sendiri. Pemosisi ini memakai
| koordinat layar, yang tidak ikut bergeser.
*/
const { dialogDateConfig } = useDialogDatePicker()

const lineDateConfig = dialogDateConfig()
const isSaving = ref(false)

const MAX_FILE_SIZE = 3 * 1024 * 1024
const ALLOWED_TYPES = ['application/pdf', 'image/jpeg', 'image/png']
const ALLOWED_EXTENSIONS = ['pdf', 'jpg', 'jpeg', 'png']

const attachmentError = ref('')

const cashAdvanceOptions = ref<CashAdvanceOption[]>([])
const isLoadingCashAdvance = ref(false)

const canCreate = computed(() => permissionStore.can('cash_advance_realization.create'))

const form = reactive({
  cash_advance_public_id: null as string | null,
  date: '',
  notes: '',
  items: [] as RealizationItemForm[],
})

const selectedCashAdvance = computed<CashAdvanceOption | null>(() => {
  if (!form.cash_advance_public_id)
    return null

  return cashAdvanceOptions.value.find(
    item => item.public_id === form.cash_advance_public_id,
  ) ?? null
})

const today = (): string => new Date().toISOString().split('T')[0]

const formatMoney = (value: number | null | undefined): string => {
  if (!value)
    return ''

  return new Intl.NumberFormat('id-ID').format(Number(value))
}

/**
 * Nominal bertanda, dipakai kolom selisih yang bisa negatif.
 */
const formatSigned = (value: number): string => {
  const rounded = Math.round(Number(value || 0))

  if (rounded === 0)
    return '0'

  const prefix = rounded > 0 ? '+' : '-'

  return prefix + new Intl.NumberFormat('id-ID').format(Math.abs(rounded))
}

const getExtension = (fileName: string): string =>
  fileName.split('.').pop()?.toLowerCase() || ''

/*
|--------------------------------------------------------------------------
| Total dan selisih
|--------------------------------------------------------------------------
| Total dicairkan diambil dari nilai FPU, bukan penjumlahan baris -- baris
| di luar rencana tidak menambah uang yang sudah diterima pemohon.
|--------------------------------------------------------------------------
*/
const totalAdvance = computed(() =>
  Number(selectedCashAdvance.value?.total_amount || 0),
)

const totalRealization = computed(() =>
  form.items.reduce((total, item) => total + Number(item.realization_amount || 0), 0),
)

const differenceAmount = computed(() =>
  Math.round((totalAdvance.value - totalRealization.value) * 100) / 100,
)

const differenceLabel = computed(() => {
  if (Math.abs(differenceAmount.value) < 0.005)
    return t('cashAdvanceRealization.difference.none')

  return differenceAmount.value > 0
    ? t('cashAdvanceRealization.difference.return')
    : t('cashAdvanceRealization.difference.reimburse')
})

const differenceColor = computed(() => {
  if (Math.abs(differenceAmount.value) < 0.005)
    return 'secondary'

  return differenceAmount.value > 0 ? 'success' : 'warning'
})

/*
|--------------------------------------------------------------------------
| FPU sumber
|--------------------------------------------------------------------------
*/
const loadCashAdvances = async (showAlert = true): Promise<void> => {
  isLoadingCashAdvance.value = true

  try {
    const response = await axios.get(`${ENDPOINT}/realizable`, {
      headers: { Accept: 'application/json' },
    })

    const data = Array.isArray(response?.data?.data) ? response.data.data : []

    cashAdvanceOptions.value = data.map((item: any): CashAdvanceOption => ({
      id: Number(item.id),
      public_id: String(item.public_id),
      advance_number: String(item.advance_number || '-'),
      title: String(item.title || item.advance_number || '-'),
      date: item.date || null,
      subject: String(item.subject || '-'),
      branch: String(item.branch || '-'),
      department: String(item.department || '-'),
      transaction_category: item.transaction_category || null,
      request_type: item.request_type || null,
      total_amount: Number(item.total_amount || 0),
      disbursed_at: item.disbursed_at || null,
      items: Array.isArray(item.items)
        ? item.items.map((line: any) => ({
          id: Number(line.id),
          date: line.date || null,
          description: String(line.description || ''),
          amount: Number(line.amount || 0),
        }))
        : [],
    }))
  }
  catch (error: unknown) {
    console.error('[Realisasi FPU] REALIZABLE ERROR:', error)

    cashAdvanceOptions.value = []

    if (showAlert) {
      showErrorToast({
        title: t('common.alert.error'),
        text: getApiErrorMessage(
          error,
          t('cashAdvanceRealization.form.toast.loadSourceFailed'),
        ),
      })
    }
  }
  finally {
    isLoadingCashAdvance.value = false
  }
}

/**
 * Menyalin baris FPU menjadi baris realisasi.
 *
 * Nominal realisasi sengaja diisi sama dengan pengajuan sebagai titik awal --
 * lebih sering hanya sebagian baris yang berubah daripada semuanya.
 */
const applyCashAdvanceItems = (): void => {
  const source = selectedCashAdvance.value

  if (!source) {
    form.items = []

    return
  }

  form.items = source.items.map(line => ({
    cash_advance_item_id: line.id,
    date: line.date,
    description: line.description,
    advance_amount: line.amount,
    realization_amount: line.amount,
    notes: '',
    attachments: [],
  }))
}

watch(() => form.cash_advance_public_id, () => {
  applyCashAdvanceItems()
})

/*
|--------------------------------------------------------------------------
| Baris realisasi
|--------------------------------------------------------------------------
*/
/*
 * Rincian disunting di modal layar penuh, mengikuti pola Purchase Requisition.
 * Halaman utama hanya menampilkan ringkasannya sebagai teks.
 */
const itemDialog = ref(false)
const itemDialogSaved = ref(false)
const confirmCloseItemDialog = ref(false)
const tempItems = ref<RealizationItemForm[]>([])

/*
 * Salinan dangkal, bukan JSON clone: baris membawa objek File yang tidak
 * selamat melewati serialisasi JSON.
 */
const cloneItems = (items: RealizationItemForm[]): RealizationItemForm[] =>
  items.map(item => ({
    ...item,
    attachments: [...item.attachments],
  }))

const openItemFullscreen = (): void => {
  tempItems.value = cloneItems(form.items)
  itemDialogSaved.value = false
  itemDialog.value = true
}

const closeItemDialog = (): void => {
  if (itemDialogSaved.value) {
    tempItems.value = []
    itemDialog.value = false

    return
  }

  confirmCloseItemDialog.value = true
}

const discardItemDialog = (): void => {
  confirmCloseItemDialog.value = false
  tempItems.value = []
  itemDialog.value = false
}

const saveItemsFromDialog = (): void => {
  /*
   * Tanggal wajib pada setiap baris. Dipisah dari pemeriksaan deskripsi
   * dan nominal supaya pesannya menunjuk langsung ke kolom yang kosong.
   */
  const withoutDate = tempItems.value.findIndex(item => !String(item.date ?? '').trim())

  if (withoutDate !== -1) {
    isSubmitted.value = true

    showWarningToast({
      title: t('common.alert.warning'),
      text: t('cashAdvanceRealization.form.toast.itemDateRequired', { number: withoutDate + 1 }),
    })

    return
  }

  const invalidIndex = tempItems.value.findIndex(
    item => !item.description.trim() || Number(item.realization_amount) < 0,
  )

  if (invalidIndex !== -1) {
    isSubmitted.value = true

    showWarningToast({
      title: t('common.alert.warning'),
      text: t('cashAdvanceRealization.form.toast.completeItemRow', {
        number: invalidIndex + 1,
      }),
    })

    return
  }

  const withoutAttachment = tempItems.value.findIndex(item => !item.attachments.length)

  if (withoutAttachment !== -1) {
    isSubmitted.value = true

    showWarningToast({
      title: t('common.alert.warning'),
      text: t('cashAdvanceRealization.form.toast.itemAttachmentRequired', {
        number: withoutAttachment + 1,
      }),
    })

    return
  }

  form.items = cloneItems(tempItems.value)
  itemDialogSaved.value = true
  itemDialog.value = false
}

const addExtraItem = (): void => {
  tempItems.value.push({
    cash_advance_item_id: null,
    date: null,
    description: '',
    advance_amount: 0,
    realization_amount: 0,
    notes: '',
    attachments: [],
  })
}

/**
 * Baris yang berasal dari FPU tidak boleh dihapus -- perbandingan per baris
 * akan bolong. Isi 0 bila memang tidak terpakai.
 */
const removeItem = (index: number): void => {
  if (tempItems.value[index]?.cash_advance_item_id !== null)
    return

  tempItems.value.splice(index, 1)
}

const handleAmountInput = (event: Event, index: number): void => {
  const target = event.target as HTMLInputElement

  const result = formatSanitizedNumberInput(target.value, formatMoney, {
    maxLength: 15,
    emptyAsZero: true,
  })

  if (!tempItems.value[index])
    return

  tempItems.value[index].realization_amount = result.numeric ?? 0

  target.value = result.formatted
}

const itemDifference = (item: RealizationItemForm): number =>
  Math.round((Number(item.advance_amount || 0) - Number(item.realization_amount || 0)) * 100) / 100

/*
|--------------------------------------------------------------------------
| Lampiran per baris
|--------------------------------------------------------------------------
| Satu input berkas tersembunyi per baris. Dikumpulkan dalam array supaya
| tombol pada baris ke-n membuka pemilih berkas milik baris itu saja.
|--------------------------------------------------------------------------
*/
const lineFileRefs = ref<Array<HTMLInputElement | null>>([])

const setLineFileRef = (el: any, index: number): void => {
  lineFileRefs.value[index] = el as HTMLInputElement | null
}

const triggerLineFileInput = (index: number): void => {
  lineFileRefs.value[index]?.click()
}

const handleLineFileUpload = (event: Event, index: number): void => {
  const input = event.target as HTMLInputElement
  const row = tempItems.value[index]

  if (!input.files || !row)
    return

  attachmentError.value = ''

  const invalidMessages: string[] = []

  for (const file of Array.from(input.files)) {
    const extension = getExtension(file.name)
    const validMime = ALLOWED_TYPES.includes(file.type)
    const validExtension = ALLOWED_EXTENSIONS.includes(extension)

    if (!validMime && !validExtension) {
      invalidMessages.push(
        t('cashAdvanceRealization.form.toast.invalidFileType', { name: file.name }),
      )

      continue
    }

    if (file.size > MAX_FILE_SIZE) {
      invalidMessages.push(
        t('cashAdvanceRealization.form.toast.invalidFileSize', { name: file.name }),
      )

      continue
    }

    const exists = row.attachments.some(
      existing => existing.name === file.name && existing.size === file.size,
    )

    if (!exists)
      row.attachments.push(file)
  }

  if (invalidMessages.length) {
    attachmentError.value = invalidMessages.join(' ')

    showWarningToast({
      title: t('cashAdvanceRealization.form.toast.invalidFileTitle'),
      text: attachmentError.value,
    })
  }

  input.value = ''
}

const removeLineAttachment = (index: number, fileIndex: number): void => {
  tempItems.value[index]?.attachments.splice(fileIndex, 1)
}

/** Baris tanpa bukti sama sekali; dipakai menandai isian yang kurang. */
const lineMissingAttachment = (index: number): boolean =>
  !tempItems.value[index]?.attachments.length

const formatFileSize = (bytes: number): string =>
  `${(bytes / 1024 / 1024).toFixed(2)} MB`

/*
|--------------------------------------------------------------------------
| Simpan
|--------------------------------------------------------------------------
*/
const validateForm = (): boolean => {
  if (!form.cash_advance_public_id || !form.date) {
    showWarningToast({
      title: t('common.alert.warning'),
      text: t('cashAdvanceRealization.form.toast.completeRequiredData'),
    })

    return false
  }

  if (!form.items.length) {
    showWarningToast({
      title: t('common.alert.warning'),
      text: t('cashAdvanceRealization.form.toast.minOneItem'),
    })

    return false
  }

  /*
   * Tanggal wajib pada setiap baris. Dipisah dari pemeriksaan deskripsi
   * dan nominal supaya pesannya menunjuk langsung ke kolom yang kosong.
   */
  const withoutDate = form.items.findIndex(item => !String(item.date ?? '').trim())

  if (withoutDate !== -1) {
    showWarningToast({
      title: t('common.alert.warning'),
      text: t('cashAdvanceRealization.form.toast.itemDateRequired', { number: withoutDate + 1 }),
    })

    return false
  }

  const invalidIndex = form.items.findIndex(
    item => !item.description.trim() || Number(item.realization_amount) < 0,
  )

  if (invalidIndex !== -1) {
    showWarningToast({
      title: t('common.alert.warning'),
      text: t('cashAdvanceRealization.form.toast.completeItemRow', {
        number: invalidIndex + 1,
      }),
    })

    return false
  }

  /*
   * Setiap baris wajib berbukti. Diperiksa di sini juga, bukan hanya di
   * backend, supaya user langsung tahu baris mana yang belum dilampiri.
   */
  const withoutAttachment = form.items.findIndex(item => !item.attachments.length)

  if (withoutAttachment !== -1) {
    showWarningToast({
      title: t('common.alert.warning'),
      text: t('cashAdvanceRealization.form.toast.itemAttachmentRequired', {
        number: withoutAttachment + 1,
      }),
    })

    return false
  }

  return true
}

const buildFormData = (): FormData => {
  const formData = new FormData()

  formData.append('cash_advance_public_id', String(form.cash_advance_public_id || ''))
  formData.append('date', String(form.date || ''))
  formData.append('notes', form.notes || '')

  formData.append(
    'items',
    JSON.stringify(
      form.items.map(item => ({
        cash_advance_item_id: item.cash_advance_item_id,
        date: item.date || null,
        description: item.description,
        realization_amount: Number(item.realization_amount || 0),
        notes: item.notes || null,
      })),
    ),
  )

  /*
   * Berkas dikirim berkelompok menurut nomor urut baris. Backend memetakan
   * nomor urut itu ke id baris setelah barisnya tersimpan.
   */
  form.items.forEach((item, index) => {
    item.attachments.forEach(file => {
      formData.append(`line_attachments[${index}][]`, file)
    })
  })

  return formData
}

const save = async (event?: Event): Promise<void> => {
  event?.preventDefault()
  event?.stopPropagation()

  if (isSaving.value)
    return

  isSubmitted.value = true

  if (!validateForm())
    return

  const confirm = await showConfirmAlert({
    title: t('cashAdvanceRealization.form.toast.saveConfirmTitle'),
    text: t('cashAdvanceRealization.form.toast.saveConfirmText'),
    confirmButtonText: t('common.actions.confirm'),
    cancelButtonText: t('common.actions.cancel'),
  })

  if (!confirm.isConfirmed)
    return

  isSaving.value = true

  try {
    showLoadingAlert(
      t('cashAdvanceRealization.form.toast.savingData'),
      t('common.alert.pleaseWait'),
    )

    /*
     * Content-Type sengaja tidak diset manual: browser harus menyusun sendiri
     * boundary multipart, kalau ditimpa maka lampiran gagal terkirim.
     */
    await axios.post(ENDPOINT, buildFormData(), {
      headers: { Accept: 'application/json' },
    })

    closeAlert()

    await router.replace({ path: LIST_PATH, query: { success: 'created' } })
  }
  catch (error: unknown) {
    closeAlert()

    console.error('[Realisasi FPU] SAVE ERROR:', error)

    showErrorToast({
      title: t('common.alert.error'),
      text: getApiErrorMessage(error, t('cashAdvanceRealization.form.toast.saveFailed')),
    })
  }
  finally {
    isSaving.value = false
  }
}

const confirmCancel = async (): Promise<void> => {
  const confirm = await showConfirmAlert({
    title: t('cashAdvanceRealization.form.toast.cancelConfirmTitle'),
    text: t('cashAdvanceRealization.form.toast.cancelConfirmText'),
    confirmButtonText: t('common.actions.confirm'),
    cancelButtonText: t('common.actions.cancel'),
  })

  if (confirm.isConfirmed)
    await router.replace(LIST_PATH)
}

const goBack = async (): Promise<void> => {
  await router.replace(LIST_PATH)
}

onMounted(async () => {
  await permissionStore.loadPermissions()

  if (!canCreate.value) {
    await router.replace('/forbidden')

    return
  }

  isCheckingPermission.value = false

  form.date = today()

  await loadCashAdvances(false)

  /*
   * Dibuka lewat pintasan pada daftar FPU: langsung pilih FPU tersebut bila
   * memang ada di daftar yang siap direalisasi.
   *
   * Dicocokkan lewat id numerik, bukan public_id. public_id dienkripsi dengan
   * IV acak sehingga dokumen yang sama menghasilkan ciphertext berbeda pada
   * setiap request -- membandingkannya antar-endpoint tidak akan pernah cocok.
   * Yang dipasang ke form tetap public_id milik opsi tersebut, karena itulah
   * yang dikirim saat menyimpan.
   */
  const preselectId = String(route.query.cash_advance_id || '')

  if (preselectId) {
    const match = cashAdvanceOptions.value.find(
      item => String(item.id) === preselectId,
    )

    if (match)
      form.cash_advance_public_id = match.public_id
  }
})
</script>

<template>
  <div
    v-if="isCheckingPermission"
    class="d-flex justify-center align-center"
    style="min-height: 300px;"
  >
    <VProgressCircular indeterminate />
  </div>

  <section v-else>
    <VCard>
      <VCardTitle class="d-flex align-center justify-space-between flex-wrap gap-3">
        <div>
          <div class="text-h6 font-weight-bold">
            {{ t('cashAdvanceRealization.form.createTitle') }}
          </div>

          <div class="text-body-2 text-medium-emphasis">
            {{ t('cashAdvanceRealization.form.createSubtitle') }}
          </div>
        </div>

        <VBtn
          prepend-icon="mdi-arrow-left"
          variant="text"
          color="secondary"
          class="text-none"
          @click="goBack"
        >
          {{ t('cashAdvanceRealization.form.backButton') }}
        </VBtn>
      </VCardTitle>

      <VDivider />

      <VCardText>
        <VRow>
          <VCol
            cols="12"
            md="8"
          >
            <VAutocomplete
              v-model="form.cash_advance_public_id"
              :label="t('cashAdvanceRealization.form.fields.cashAdvance')"
              :items="cashAdvanceOptions"
              item-title="title"
              item-value="public_id"
              density="comfortable"
              :loading="isLoadingCashAdvance"
              clearable
              :menu-props="{ location: 'bottom', offset: 8, maxHeight: 320 }"
              :error="isSubmitted && !form.cash_advance_public_id"
              :error-messages="isSubmitted && !form.cash_advance_public_id ? [t('cashAdvanceRealization.form.validation.cashAdvance')] : []"
              :no-data-text="t('cashAdvanceRealization.form.noData.cashAdvance')"
              :placeholder="t('cashAdvanceRealization.form.placeholders.cashAdvance')"
            />
          </VCol>

          <VCol
            cols="12"
            md="4"
          >
            <AppDateTimePicker
              v-model="form.date"
              :label="t('cashAdvanceRealization.form.fields.date')"
              :placeholder="t('cashAdvanceRealization.form.placeholders.date')"
              :config="{ dateFormat: 'Y-m-d', position: 'below' }"
              :error="isSubmitted && !form.date"
              :error-messages="isSubmitted && !form.date ? [t('cashAdvanceRealization.form.validation.date')] : []"
            />
          </VCol>
        </VRow>

        <!-- RINGKASAN FPU SUMBER -->
        <VCard
          v-if="selectedCashAdvance"
          flat
          class="car-source-card mt-2"
        >
          <VCardText>
            <div class="text-subtitle-1 font-weight-bold mb-1">
              {{ t('cashAdvanceRealization.form.source.sectionTitle') }}
            </div>

            <div class="text-caption text-medium-emphasis mb-4">
              {{ t('cashAdvanceRealization.form.source.hint') }}
            </div>

            <VRow dense>
              <VCol
                cols="12"
                md="4"
              >
                <div class="text-caption text-medium-emphasis">
                  {{ t('cashAdvanceRealization.form.source.advanceNumber') }}
                </div>
                <div class="font-weight-bold">
                  {{ selectedCashAdvance.advance_number }}
                </div>
              </VCol>

              <VCol
                cols="12"
                md="4"
              >
                <div class="text-caption text-medium-emphasis">
                  {{ t('cashAdvanceRealization.form.source.branch') }}
                </div>
                <div>{{ selectedCashAdvance.branch }}</div>
              </VCol>

              <VCol
                cols="12"
                md="4"
              >
                <div class="text-caption text-medium-emphasis">
                  {{ t('cashAdvanceRealization.form.source.department') }}
                </div>
                <div>{{ selectedCashAdvance.department }}</div>
              </VCol>

              <VCol
                cols="12"
                md="4"
              >
                <div class="text-caption text-medium-emphasis">
                  {{ t('cashAdvanceRealization.form.source.transactionCategory') }}
                </div>
                <div>{{ selectedCashAdvance.transaction_category || '-' }}</div>
              </VCol>

              <VCol
                cols="12"
                md="4"
              >
                <div class="text-caption text-medium-emphasis">
                  {{ t('cashAdvanceRealization.form.source.requestType') }}
                </div>
                <div>{{ selectedCashAdvance.request_type || '-' }}</div>
              </VCol>

              <VCol
                cols="12"
                md="4"
              >
                <div class="text-caption text-medium-emphasis">
                  {{ t('cashAdvanceRealization.form.source.totalAmount') }}
                </div>
                <div class="font-weight-bold">
                  Rp {{ formatMoney(selectedCashAdvance.total_amount) || '0' }}
                </div>
              </VCol>

              <VCol cols="12">
                <div class="text-caption text-medium-emphasis">
                  {{ t('cashAdvanceRealization.form.source.subject') }}
                </div>
                <div class="text-pre-line">
                  {{ selectedCashAdvance.subject }}
                </div>
              </VCol>
            </VRow>
          </VCardText>
        </VCard>

        <!-- RINCIAN REALISASI -->
        <template v-if="selectedCashAdvance">
          <div class="d-flex align-center justify-space-between flex-wrap gap-3 mt-6 mb-3">
            <div>
              <div class="text-subtitle-1 font-weight-bold">
                {{ t('cashAdvanceRealization.form.items.sectionTitle') }}
              </div>

              <div class="text-caption text-medium-emphasis">
                {{ t('cashAdvanceRealization.form.items.sectionSubtitle') }}
              </div>
            </div>

            <VBtn
              type="button"
              color="primary"
              variant="tonal"
              size="small"
              prepend-icon="tabler-list-details"
              class="text-none"
              @click="openItemFullscreen"
            >
              {{ t('cashAdvanceRealization.form.items.addButton') }}

              <VTooltip
                activator="parent"
                location="top"
                :offset="4"
              >
                {{ t('cashAdvanceRealization.form.items.addButtonTooltip') }}
              </VTooltip>
            </VBtn>
          </div>

          <!--
            Ringkasan baca-saja. Penyuntingan dilakukan di modal layar penuh,
            mengikuti pola Purchase Requisition.
          -->
          <VCard
            flat
            class="car-summary-card"
          >
            <VCardText>
              <VAlert
                v-if="!form.items.length"
                type="info"
                variant="tonal"
                density="compact"
              >
                {{ t('cashAdvanceRealization.form.items.emptyAlert', { action: t('cashAdvanceRealization.form.items.addButton') }) }}
              </VAlert>

              <div
                v-else
                class="d-flex flex-column gap-3"
              >
                <div
                  v-for="(item, index) in form.items"
                  :key="`summary-item-${index}`"
                  class="car-summary-row"
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
                        {{ item.description || '-' }}

                        <VChip
                          v-if="!item.cash_advance_item_id"
                          size="x-small"
                          variant="tonal"
                          color="warning"
                          class="ms-1"
                        >
                          {{ t('cashAdvanceRealization.form.items.extra') }}
                        </VChip>
                      </div>

                      <div class="text-caption text-medium-emphasis mt-1">
                        {{ t('cashAdvanceRealization.form.items.tableAdvance') }}:
                        <strong>Rp {{ formatMoney(item.advance_amount) || '0' }}</strong>
                        <span class="mx-1">•</span>
                        {{ t('cashAdvanceRealization.form.items.tableDifference') }}:
                        <strong>{{ item.cash_advance_item_id ? formatSigned(itemDifference(item)) : '—' }}</strong>
                      </div>

                      <div class="d-flex align-center flex-wrap gap-2 mt-2">
                        <VChip
                          v-for="(file, fileIndex) in item.attachments"
                          :key="`summary-item-${index}-file-${fileIndex}`"
                          size="x-small"
                          variant="tonal"
                          color="success"
                          prepend-icon="tabler-paperclip"
                        >
                          {{ file.name }}
                        </VChip>
                      </div>
                    </div>

                    <div class="text-end">
                      <div class="text-caption text-medium-emphasis">
                        {{ t('cashAdvanceRealization.form.items.tableRealization') }}
                      </div>

                      <div class="font-weight-bold">
                        Rp {{ formatMoney(item.realization_amount) || '0' }}
                      </div>
                    </div>
                  </div>
                </div>
              </div>
            </VCardText>
          </VCard>

          <div class="d-flex justify-end mt-4">
            <div class="car-total-box">
              <div class="car-total-row">
                <span>{{ t('cashAdvanceRealization.form.items.totalAdvance') }}</span>
                <strong>Rp {{ formatMoney(totalAdvance) || '0' }}</strong>
              </div>

              <div class="car-total-row">
                <span>{{ t('cashAdvanceRealization.form.items.totalRealization') }}</span>
                <strong>Rp {{ formatMoney(totalRealization) || '0' }}</strong>
              </div>

              <VDivider class="my-2" />

              <div class="car-total-row">
                <span>{{ t('cashAdvanceRealization.form.items.difference') }}</span>

                <div class="d-flex align-center gap-2">
                  <VChip
                    size="small"
                    :color="differenceColor"
                    variant="tonal"
                  >
                    {{ differenceLabel }}
                  </VChip>

                  <strong>Rp {{ formatMoney(Math.abs(differenceAmount)) || '0' }}</strong>
                </div>
              </div>
            </div>
          </div>

          <!--
            Bukti tidak lagi berdiri di tingkat dokumen -- setiap baris rincian
            membawa buktinya sendiri di kolom Bukti.
          -->
          <VAlert
            v-if="attachmentError"
            type="warning"
            variant="tonal"
            class="mt-4"
          >
            {{ attachmentError }}
          </VAlert>

          <VTextarea
            v-model="form.notes"
            class="mt-6"
            :label="t('cashAdvanceRealization.form.fields.notes')"
            :placeholder="t('cashAdvanceRealization.form.placeholders.notes')"
            rows="3"
            auto-grow
          />
        </template>

        <VAlert
          v-else
          type="info"
          variant="tonal"
          class="mt-4"
        >
          {{ t('cashAdvanceRealization.form.placeholders.cashAdvance') }}
        </VAlert>

        <VDivider class="mt-6 mb-4" />

        <div class="d-flex justify-end gap-3">
          <VBtn
            type="button"
            color="secondary"
            variant="outlined"
            class="text-none"
            @click.prevent.stop="confirmCancel"
          >
            {{ t('cashAdvanceRealization.form.buttons.cancel') }}
          </VBtn>

          <VBtn
            type="button"
            color="primary"
            class="text-none"
            :loading="isSaving"
            :disabled="!selectedCashAdvance"
            @click.prevent.stop="save($event)"
          >
            {{ t('cashAdvanceRealization.form.buttons.save') }}
          </VBtn>
        </div>
      </VCardText>
    </VCard>

    <!--
      MODAL RINCIAN (layar penuh)
      Mengikuti pola Purchase Requisition: penyuntingan dilakukan di sini agar
      kolom tidak berdesakan, lalu hasilnya tampil sebagai ringkasan teks.
    -->
    <!--
      Kalender flatpickr dipasang ke <body>, di luar DOM dialog. Tiga bawaan
      VDialog karenanya harus dimatikan:

      - persistent      : tanpa ini, klik pada tanggal terbaca sebagai klik di
      luar dialog dan modalnya ikut tertutup.
      - retain-focus    : jebakan fokus menarik fokus kembali ke dialog dan
      menutup kalender sebelum tanggalnya sempat dipilih.
      - no-click-animation
      : klik kalender tetap terhitung klik-luar, dan pada
      dialog persistent itu memicu animasi denyut.

      Penutupan modal tetap tersedia lewat tombol X, yang melewati konfirmasi
      buang perubahan.
    -->
    <VDialog
      v-model="itemDialog"
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
            @click="closeItemDialog"
          >
            <VIcon icon="tabler-x" />
          </VBtn>

          <VToolbarTitle>
            {{ t('cashAdvanceRealization.form.itemDialog.title') }}
          </VToolbarTitle>

          <VSpacer />

          <VBtn
            variant="flat"
            class="me-3 text-none"
            prepend-icon="tabler-plus"
            @click="addExtraItem"
          >
            {{ t('cashAdvanceRealization.form.itemDialog.addRowButton') }}
          </VBtn>

          <VBtn
            variant="flat"
            class="me-3 text-none"
            @click="saveItemsFromDialog"
          >
            {{ t('cashAdvanceRealization.form.itemDialog.saveButton') }}
          </VBtn>
        </VToolbar>

        <VCardText class="pa-4">
          <div class="car-table-wrapper">
            <VTable class="car-item-table">
              <thead>
                <tr>
                  <th class="car-col-no">
                    {{ t('cashAdvanceRealization.form.items.tableNo') }}
                  </th>
                  <th class="car-col-date">
                    {{ t('cashAdvanceRealization.form.items.tableDate') }}
                    <span class="text-error">*</span>
                  </th>
                  <th>
                    {{ t('cashAdvanceRealization.form.items.tableDescription') }}
                  </th>
                  <th class="car-col-money text-end">
                    {{ t('cashAdvanceRealization.form.items.tableAdvance') }}
                  </th>
                  <th class="car-col-money">
                    {{ t('cashAdvanceRealization.form.items.tableRealization') }}
                  </th>
                  <th class="car-col-money text-end">
                    {{ t('cashAdvanceRealization.form.items.tableDifference') }}
                  </th>
                  <th class="car-col-attachment">
                    {{ t('cashAdvanceRealization.form.items.tableAttachment') }}
                    <span class="text-error">*</span>
                  </th>
                  <th class="car-col-action text-center">
                    {{ t('cashAdvanceRealization.form.items.tableActions') }}
                  </th>
                </tr>
              </thead>

              <tbody>
                <tr
                  v-for="(item, index) in tempItems"
                  :key="`item-${index}`"
                >
                  <td class="text-medium-emphasis">
                    {{ index + 1 }}
                  </td>

                  <td>
                    <AppDateTimePicker
                      v-model="item.date"
                      density="compact"
                      hide-details="auto"
                      :config="lineDateConfig"
                      :error="isSubmitted && !item.date"
                      :error-messages="isSubmitted && !item.date ? [t('cashAdvanceRealization.form.validation.itemDate')] : []"
                    />
                  </td>

                  <td>
                    <VTextField
                      v-model="item.description"
                      density="compact"
                      variant="outlined"
                      hide-details="auto"
                      :error="isSubmitted && !item.description.trim()"
                      :error-messages="isSubmitted && !item.description.trim() ? [t('cashAdvanceRealization.form.validation.itemDescription')] : []"
                    />

                    <VChip
                      size="x-small"
                      variant="tonal"
                      :color="item.cash_advance_item_id ? 'primary' : 'warning'"
                      class="mt-1"
                    >
                      {{ item.cash_advance_item_id
                        ? t('cashAdvanceRealization.form.items.fromAdvance')
                        : t('cashAdvanceRealization.form.items.extra') }}
                    </VChip>
                  </td>

                  <td class="text-end text-medium-emphasis">
                    {{ item.cash_advance_item_id ? `Rp ${formatMoney(item.advance_amount) || '0'}` : '—' }}
                  </td>

                  <td>
                    <VTextField
                      :model-value="formatMoney(item.realization_amount)"
                      density="compact"
                      variant="outlined"
                      hide-details="auto"
                      prefix="Rp"
                      inputmode="numeric"
                      @input="handleAmountInput($event, index)"
                    />
                  </td>

                  <td
                    class="text-end font-weight-medium"
                    :class="{
                      'text-success': itemDifference(item) > 0,
                      'text-warning': itemDifference(item) < 0,
                      'text-medium-emphasis': itemDifference(item) === 0,
                    }"
                  >
                    {{ item.cash_advance_item_id ? formatSigned(itemDifference(item)) : '—' }}
                  </td>

                  <!-- Bukti milik baris ini -->
                  <td>
                    <div class="d-flex flex-column gap-2">
                      <input
                        :ref="el => setLineFileRef(el, index)"
                        type="file"
                        multiple
                        accept=".pdf,.jpg,.jpeg,.png"
                        class="d-none"
                        @change="handleLineFileUpload($event, index)"
                      >

                      <VBtn
                        type="button"
                        size="small"
                        variant="outlined"
                        class="text-none align-self-start"
                        :color="isSubmitted && lineMissingAttachment(index) ? 'error' : 'primary'"
                        prepend-icon="tabler-paperclip"
                        @click="triggerLineFileInput(index)"
                      >
                        {{ t('cashAdvanceRealization.form.attachment.addButton') }}
                      </VBtn>

                      <div
                        v-if="isSubmitted && lineMissingAttachment(index)"
                        class="text-error text-caption"
                      >
                        {{ t('cashAdvanceRealization.form.validation.itemAttachment') }}
                      </div>

                      <div
                        v-for="(file, fileIndex) in item.attachments"
                        :key="`item-${index}-file-${fileIndex}`"
                        class="car-line-file"
                      >
                        <VIcon
                          :icon="file.type === 'application/pdf' ? 'mdi-file-pdf-box' : 'mdi-file-image-outline'"
                          size="18"
                          color="primary"
                        />

                        <div class="car-line-file__body">
                          <div class="car-line-file__name">
                            {{ file.name }}
                          </div>

                          <div class="text-caption text-medium-emphasis">
                            {{ formatFileSize(file.size) }}
                          </div>
                        </div>

                        <VBtn
                          icon
                          size="x-small"
                          variant="text"
                          color="error"
                          @click="removeLineAttachment(index, fileIndex)"
                        >
                          <VIcon
                            icon="tabler-x"
                            size="16"
                          />
                        </VBtn>
                      </div>
                    </div>
                  </td>

                  <td class="text-center">
                    <VBtn
                      icon
                      size="small"
                      variant="text"
                      color="error"
                      :disabled="item.cash_advance_item_id !== null"
                      @click="removeItem(index)"
                    >
                      <VIcon icon="tabler-trash" />
                      <VTooltip
                        activator="parent"
                        location="top"
                        max-width="260"
                      >
                        {{ item.cash_advance_item_id !== null
                          ? t('cashAdvanceRealization.form.items.removeHint')
                          : t('common.actions.delete') }}
                      </VTooltip>
                    </VBtn>
                  </td>
                </tr>
              </tbody>
            </VTable>
          </div>
        </VCardText>
      </VCard>
    </VDialog>

    <VDialog
      v-model="confirmCloseItemDialog"
      max-width="460"
    >
      <VCard>
        <VCardTitle class="text-h6 font-weight-bold">
          {{ t('cashAdvanceRealization.form.itemDialog.discardTitle') }}
        </VCardTitle>

        <VCardText>
          {{ t('cashAdvanceRealization.form.itemDialog.discardText') }}
        </VCardText>

        <VCardActions class="justify-end">
          <VBtn
            variant="tonal"
            color="secondary"
            class="text-none"
            @click="confirmCloseItemDialog = false"
          >
            {{ t('common.actions.cancel') }}
          </VBtn>

          <VBtn
            color="error"
            class="text-none"
            @click="discardItemDialog"
          >
            {{ t('cashAdvanceRealization.form.itemDialog.discardButton') }}
          </VBtn>
        </VCardActions>
      </VCard>
    </VDialog>
  </section>
</template>

<style scoped>
.car-source-card {
  border: 1px solid rgba(var(--v-border-color), var(--v-border-opacity));
  border-radius: 8px;
}

/* Ringkasan rincian pada halaman utama. */
.car-summary-card {
  border: 1px solid rgba(var(--v-border-color), var(--v-border-opacity));
  border-radius: 10px;
}

.car-summary-row {
  border: 1px solid rgba(var(--v-border-color), var(--v-border-opacity));
  border-radius: 8px;
  padding-block: 0.75rem;
  padding-inline: 0.875rem;
}

.car-table-wrapper {
  overflow-x: auto;
}

.car-item-table {
  min-inline-size: 1300px;
}

.car-col-no {
  inline-size: 3.5rem;
}

/*
| Kolom tanggal butuh ruang untuk "2026-08-30" beserta padding field dan
| tombol clear-nya. Tanpa min-inline-size, tata letak tabel otomatis
| menyusutkan kolom ini demi kolom deskripsi dan tanggalnya terpotong.
*/
.car-col-date {
  inline-size: 14rem;
  min-inline-size: 14rem;
}

.car-col-attachment {
  inline-size: 17rem;
  min-inline-size: 17rem;
}

/* Kartu berkas yang menempel pada satu baris rincian. */
.car-line-file {
  display: flex;
  align-items: center;
  border: 1px solid rgba(var(--v-border-color), var(--v-border-opacity));
  border-radius: 6px;
  gap: 0.5rem;
  padding-block: 0.25rem;
  padding-inline: 0.5rem;
}

.car-line-file__body {
  flex: 1;
  min-inline-size: 0;
}

.car-line-file__name {
  overflow: hidden;
  font-size: 0.8rem;
  text-decoration: none;
  text-overflow: ellipsis;
  white-space: nowrap;
}

.car-col-money {
  inline-size: 12rem;
}

.car-col-action {
  inline-size: 5rem;
}

.car-total-box {
  border-radius: 8px;
  background: rgba(var(--v-theme-primary), 0.08);
  min-inline-size: 24rem;
  padding-block: 0.75rem;
  padding-inline: 1rem;
}

.car-total-row {
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: 2rem;
  padding-block: 0.2rem;
}
</style>
