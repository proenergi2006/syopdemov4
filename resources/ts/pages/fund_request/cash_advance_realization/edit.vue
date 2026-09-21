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
| Ubah Realisasi FPU
|--------------------------------------------------------------------------
| Hanya berlaku pada dokumen draft. FPU induknya terkunci: memindahkannya
| akan membuat nomor, cabang, dan department dokumen ini tidak lagi cocok
| dengan isinya. Bila salah pilih, hapus draft-nya lalu buat ulang.
|--------------------------------------------------------------------------
*/

interface RealizationItemForm {
  cash_advance_item_id: number | null
  date: string | null
  description: string
  advance_amount: number
  realization_amount: number
  notes: string

  /** Null untuk baris baru; diisi supaya baris lama diperbarui, bukan diganti. */
  id: number | null

  /*
   * Bukti melekat pada baris. attachments adalah berkas yang baru dipilih,
   * existing adalah yang sudah tersimpan.
   */
  attachments: File[]
  existing: ExistingAttachment[]
}

/**
 * Ringkasan FPU induk. Pada halaman ubah, datanya datang dari dokumen
 * realisasi itu sendiri -- bukan dari daftar FPU yang siap direalisasi,
 * karena FPU tersebut sudah tidak muncul di sana.
 */
interface CashAdvanceSummary {
  advance_number: string
  subject: string
  branch: string
  department: string
  transaction_category: string | null
  total_amount: number
}

interface ExistingAttachment {
  id: number
  original_filename: string | null
  filename: string
  mime_type: string | null
  file_size: number | null
  url: string | null
}

const route = useRoute()
const router = useRouter()
const permissionStore = usePermissionStore()
const { t } = useI18n()

const ENDPOINT = '/fund-request/cash-advance-realization'
const LIST_PATH = '/fund_request/cash_advance_realization'

const publicId = ref<string>(String(route.query.id || ''))

const isCheckingPermission = ref(true)
const isLoading = ref(true)
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

const realizationNumber = ref('-')
const existingAttachments = ref<ExistingAttachment[]>([])
const deletedAttachmentIds = ref<number[]>([])

const MAX_FILE_SIZE = 3 * 1024 * 1024
const ALLOWED_TYPES = ['application/pdf', 'image/jpeg', 'image/png']
const ALLOWED_EXTENSIONS = ['pdf', 'jpg', 'jpeg', 'png']

const attachmentError = ref('')

const sourceCashAdvance = ref<CashAdvanceSummary | null>(null)

const canUpdate = computed(() => permissionStore.can('cash_advance_realization.update'))

const form = reactive({
  date: '',
  notes: '',
  items: [] as RealizationItemForm[],
})

/*
 * Dinamai sama dengan halaman buat supaya seluruh template di bawahnya tidak
 * perlu berbeda; di sini isinya selalu FPU induk yang sudah terkunci.
 */
const selectedCashAdvance = computed(() => sourceCashAdvance.value)

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
| Muat dokumen
|--------------------------------------------------------------------------
*/
const loadRealization = async (): Promise<void> => {
  isLoading.value = true

  try {
    const response = await axios.get(`${ENDPOINT}/${publicId.value}/edit`, {
      headers: { Accept: 'application/json' },
    })

    const data = response.data?.data

    if (!data) {
      showErrorToast({
        title: t('common.alert.error'),
        text: t('cashAdvanceRealization.form.toast.loadFailed'),
      })

      await router.replace(LIST_PATH)

      return
    }

    realizationNumber.value = data.realization_number || '-'

    form.date = data.date || ''
    form.notes = data.notes || ''

    sourceCashAdvance.value = {
      advance_number: String(data.advance_number || '-'),
      subject: String(data.subject || '-'),
      branch: String(data.branch || '-'),
      department: String(data.department || '-'),
      transaction_category: data.transaction_category || null,
      total_amount: Number(data.total_advance_amount || 0),
    }

    form.items = Array.isArray(data.items)
      ? data.items.map((item: any): RealizationItemForm => ({
        cash_advance_item_id: item.cash_advance_item_id
          ? Number(item.cash_advance_item_id)
          : null,
        date: item.date || null,
        description: String(item.description || ''),
        advance_amount: Number(item.advance_amount || 0),
        realization_amount: Number(item.realization_amount || 0),
        notes: item.notes || '',
        id: item.id ? Number(item.id) : null,
        attachments: [],
        existing: Array.isArray(item.attachments)
          ? item.attachments.map((file: any): ExistingAttachment => ({
            id: Number(file.id),
            original_filename: file.original_filename || null,
            filename: file.filename || '',
            mime_type: file.mime_type || null,
            file_size: file.file_size !== null && file.file_size !== undefined
              ? Number(file.file_size)
              : null,
            url: file.url || null,
          }))
          : [],
      }))
      : []

    existingAttachments.value = Array.isArray(data.attachments)
      ? data.attachments.map((item: any): ExistingAttachment => ({
        id: Number(item.id),
        original_filename: item.original_filename || null,
        filename: String(item.filename || ''),
        mime_type: item.mime_type || null,
        file_size: item.file_size !== null && item.file_size !== undefined
          ? Number(item.file_size)
          : null,
        url: item.url || null,
      }))
      : []
  }
  catch (error: unknown) {
    console.error('[Realisasi FPU] LOAD ERROR:', error)

    showErrorToast({
      title: t('common.alert.error'),
      text: getApiErrorMessage(error, t('cashAdvanceRealization.form.toast.loadFailed')),
    })

    await router.replace(LIST_PATH)
  }
  finally {
    isLoading.value = false
  }
}

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
 * Penghapusan bukti lama baru dicatat saat modal disimpan, supaya membatalkan
 * modal tidak meninggalkan id yang tetap terhapus di server.
 */
const tempDeletedIds = ref<number[]>([])

/*
 * Salinan dangkal, bukan JSON clone: baris membawa objek File yang tidak
 * selamat melewati serialisasi JSON.
 */
const cloneItems = (items: RealizationItemForm[]): RealizationItemForm[] =>
  items.map(item => ({
    ...item,
    attachments: [...item.attachments],
    existing: [...item.existing],
  }))

const openItemFullscreen = (): void => {
  tempItems.value = cloneItems(form.items)
  tempDeletedIds.value = [...deletedAttachmentIds.value]
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
  tempDeletedIds.value = []
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

  const withoutAttachment = tempItems.value.findIndex(item => item.attachments.length + item.existing.length < 1)

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
  deletedAttachmentIds.value = [...tempDeletedIds.value]
  itemDialogSaved.value = true
  itemDialog.value = false
}

const addExtraItem = (): void => {
  tempItems.value.push({
    id: null,
    cash_advance_item_id: null,
    date: null,
    description: '',
    advance_amount: 0,
    realization_amount: 0,
    notes: '',
    attachments: [],
    existing: [],
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
| Lampiran
|--------------------------------------------------------------------------
*/
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

/**
 * Menandai bukti lama sebuah baris untuk dihapus. Penghapusan sebenarnya
 * terjadi di server saat perubahan disimpan.
 */
const removeLineExistingAttachment = (index: number, attachmentId: number): void => {
  const row = tempItems.value[index]

  if (!row)
    return

  tempDeletedIds.value.push(attachmentId)

  row.existing = row.existing.filter(item => item.id !== attachmentId)
}

/** Baris tanpa bukti sama sekali, baik yang lama maupun yang baru dipilih. */
const lineMissingAttachment = (index: number): boolean => {
  const row = tempItems.value[index]

  if (!row)
    return true

  return row.attachments.length + row.existing.length < 1
}

/*
 * Bukti lama tidak langsung dihapus dari server -- hanya ditandai, lalu benar
 * benar dihapus ketika perubahan disimpan. Dengan begitu membatalkan edit
 * tidak menghilangkan lampiran.
 */
const removeExistingAttachment = (attachment: ExistingAttachment): void => {
  deletedAttachmentIds.value.push(attachment.id)

  existingAttachments.value = existingAttachments.value.filter(
    item => item.id !== attachment.id,
  )
}

const formatFileSize = (bytes: number | null): string => {
  if (!bytes)
    return '-'

  return `${(bytes / 1024 / 1024).toFixed(2)} MB`
}

/*
|--------------------------------------------------------------------------
| Simpan
|--------------------------------------------------------------------------
*/
const validateForm = (): boolean => {
  if (!form.date) {
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
   * Setiap baris wajib berbukti, dihitung dari gabungan bukti lama yang masih
   * ada dan berkas yang baru dipilih.
   */
  const withoutAttachment = form.items.findIndex(
    item => item.attachments.length + item.existing.length < 1,
  )

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

  /*
   * FormData tidak bisa dikirim lewat PUT, jadi dipakai method spoofing --
   * mengikuti pola form PR dan FPU.
   */
  formData.append('_method', 'PUT')

  formData.append('date', String(form.date || ''))
  formData.append('notes', form.notes || '')

  formData.append(
    'items',
    JSON.stringify(
      form.items.map(item => ({
        id: item.id,
        cash_advance_item_id: item.cash_advance_item_id,
        date: item.date || null,
        description: item.description,
        realization_amount: Number(item.realization_amount || 0),
        notes: item.notes || null,
      })),
    ),
  )

  if (deletedAttachmentIds.value.length) {
    formData.append(
      'deleted_attachment_ids',
      JSON.stringify(deletedAttachmentIds.value),
    )
  }

  /*
   * Berkas baru dikirim berkelompok menurut nomor urut baris. Bukti lama tidak
   * dikirim ulang -- yang sudah di server tetap di sana kecuali id-nya masuk
   * deleted_attachment_ids.
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
    title: t('cashAdvanceRealization.form.toast.updateConfirmTitle'),
    text: t('cashAdvanceRealization.form.toast.updateConfirmText'),
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
    await axios.post(`${ENDPOINT}/${publicId.value}`, buildFormData(), {
      headers: { Accept: 'application/json' },
    })

    closeAlert()

    await router.replace({ path: LIST_PATH, query: { success: 'updated' } })
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

  if (!canUpdate.value) {
    await router.replace('/forbidden')

    return
  }

  if (!publicId.value) {
    await router.replace(LIST_PATH)

    return
  }

  isCheckingPermission.value = false

  await loadRealization()
})
</script>

<template>
  <!--
    Bentuk loading disamakan dengan halaman edit PR dan PO: kartu berlabel,
    bukan spinner telanjang, supaya user tahu apa yang sedang dimuat.
  -->
  <VCard
    v-if="isCheckingPermission || isLoading"
    class="mb-6 rounded-lg"
    elevation="2"
  >
    <VCardText class="pa-6">
      <div class="d-flex align-center">
        <VProgressCircular
          indeterminate
          color="primary"
          size="28"
          width="3"
          class="me-4"
        />

        <div>
          <div class="text-h6 font-weight-medium">
            {{ t('cashAdvanceRealization.form.loadingTitle') }}
          </div>

          <div class="text-body-2 text-medium-emphasis">
            {{ t('common.alert.pleaseWait') }}
          </div>
        </div>
      </div>
    </VCardText>
  </VCard>

  <section v-else>
    <VCard>
      <VCardTitle class="d-flex align-center justify-space-between flex-wrap gap-3">
        <div>
          <div class="text-h6 font-weight-bold">
            {{ t('cashAdvanceRealization.form.editTitle') }} — {{ realizationNumber }}
          </div>

          <div class="text-body-2 text-medium-emphasis">
            {{ t('cashAdvanceRealization.form.editSubtitle') }}
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
            <!--
              FPU induk terkunci: memindahkannya akan membuat nomor, cabang,
              dan department dokumen ini tidak lagi cocok dengan isinya.
            -->
            <VTextField
              :model-value="sourceCashAdvance?.advance_number || '-'"
              :label="t('cashAdvanceRealization.form.fields.cashAdvance')"
              density="comfortable"
              readonly
              append-inner-icon="tabler-lock"
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
                          v-for="attachment in item.existing"
                          :key="`summary-item-${index}-existing-${attachment.id}`"
                          size="x-small"
                          variant="tonal"
                          color="primary"
                          prepend-icon="tabler-paperclip"
                        >
                          {{ attachment.original_filename || attachment.filename }}
                        </VChip>

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

          <!-- BUKTI TERSIMPAN -->
          <div
            v-if="existingAttachments.length"
            class="mt-6"
          >
            <div class="text-subtitle-1 font-weight-bold mb-2">
              {{ t('cashAdvanceRealization.form.attachment.existingTitle') }}
            </div>

            <VDivider class="mb-4" />

            <VList
              density="comfortable"
              border
              rounded
            >
              <VListItem
                v-for="attachment in existingAttachments"
                :key="`existing-${attachment.id}`"
              >
                <template #prepend>
                  <VIcon
                    :icon="attachment.mime_type === 'application/pdf' ? 'mdi-file-pdf-box' : 'mdi-file-image-outline'"
                  />
                </template>

                <VListItemTitle class="text-body-2">
                  <a
                    v-if="attachment.url"
                    :href="attachment.url"
                    target="_blank"
                    rel="noopener"
                    class="text-primary"
                  >
                    {{ attachment.original_filename || attachment.filename }}
                  </a>

                  <span v-else>
                    {{ attachment.original_filename || attachment.filename }}
                  </span>
                </VListItemTitle>

                <VListItemSubtitle>
                  {{ formatFileSize(attachment.file_size) }}
                </VListItemSubtitle>

                <template #append>
                  <VBtn
                    type="button"
                    color="error"
                    variant="text"
                    size="small"
                    class="text-none"
                    @click="removeExistingAttachment(attachment)"
                  >
                    {{ t('cashAdvanceRealization.form.attachment.deleteButton') }}
                  </VBtn>
                </template>
              </VListItem>
            </VList>
          </div>

          <!--
            Bukti baru tidak lagi berdiri di tingkat dokumen -- setiap baris
            rincian membawa buktinya sendiri di kolom Bukti.
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
            {{ t('cashAdvanceRealization.form.buttons.update') }}
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
                        v-for="attachment in item.existing"
                        :key="`item-${index}-existing-${attachment.id}`"
                        class="car-line-file"
                      >
                        <VIcon
                          :icon="attachment.mime_type === 'application/pdf' ? 'mdi-file-pdf-box' : 'mdi-file-image-outline'"
                          size="18"
                          color="primary"
                        />

                        <div class="car-line-file__body">
                          <a
                            v-if="attachment.url"
                            :href="attachment.url"
                            target="_blank"
                            rel="noopener"
                            class="car-line-file__name d-block text-primary"
                          >
                            {{ attachment.original_filename || attachment.filename }}
                          </a>

                          <div
                            v-else
                            class="car-line-file__name"
                          >
                            {{ attachment.original_filename || attachment.filename }}
                          </div>

                          <div class="text-caption text-medium-emphasis">
                            {{ formatFileSize(attachment.file_size) }}
                          </div>
                        </div>

                        <VBtn
                          icon
                          size="x-small"
                          variant="text"
                          color="error"
                          @click="removeLineExistingAttachment(index, attachment.id)"
                        >
                          <VIcon
                            icon="tabler-x"
                            size="16"
                          />
                        </VBtn>
                      </div>

                      <div
                        v-for="(file, fileIndex) in item.attachments"
                        :key="`item-${index}-file-${fileIndex}`"
                        class="car-line-file"
                      >
                        <VIcon
                          :icon="file.type === 'application/pdf' ? 'mdi-file-pdf-box' : 'mdi-file-image-outline'"
                          size="18"
                          color="success"
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
