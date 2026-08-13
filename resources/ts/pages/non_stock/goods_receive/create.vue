<script setup lang="ts">
import { computed, onMounted, ref, watch } from 'vue'
import { useRouter } from 'vue-router'
import { useI18n } from 'vue-i18n'
import axios from '@axios'
import Swal from 'sweetalert2'
import {
  showLoadingAlert,
  showSuccessToast,
  showWarningToast,
  showErrorToast,
  closeAlert,
  showConfirmAlert,
} from '@/utils/alert'
import { getApiErrorMessage } from '@/utils/apiHelper'
import { formatDate, formatStatusPKP, formatNumberWithoutRp, toTitleCase, formatDecimalQty } from '@/utils/textFormatter'
import { usePermissionStore } from '@/stores/permission'

interface AxiosErrorShape {
  response?: {
    status?: number
    data?: {
      message?: string
      errors?: Record<string, string[]>
    }
  }
}

interface SelectOption {
  id: number | string
  title: string
  subtitle?: string
  raw?: any
}

interface GrItem {
  po_item_id: number | string
  item_id: number | string | null
  item_name: string
  item_code: string
  unit: string
  ordered_qty: number
  received_qty: number
  remaining_qty: number
  receive_qty: number
  notes: string
}

const permissionStore = usePermissionStore()

const canCreate = computed(() => {
  return permissionStore.can('goods_receive.create')
})

const isCheckingPermission = ref(true)


const router = useRouter()
const { t } = useI18n()

const loading = ref(false)
const submitLoading = ref(false)
const poLoading = ref(false)
const itemLoading = ref(false)

const poOptions = ref<SelectOption[]>([])
const selectedPo = ref<number | string | null>(null)
const userData = JSON.parse(localStorage.getItem('userData') || '{}')

const MAX_FILE_SIZE = 3 * 1024 * 1024 // 3 MB
const attachmentInput = ref<File[]>([])
const attachments = ref<File[]>([])

/*
|--------------------------------------------------------------------------
| Whitelist lampiran
|--------------------------------------------------------------------------
| Wajib sama persis dengan aturan `mimes` pada GoodsReceiveController
| (store & update): pdf, jpg, jpeg, png, webp. Sebelumnya pengecekan
| memakai file.type.startsWith('image/') yang ikut meloloskan gif, bmp,
| svg, dan tiff -- file lolos di frontend lalu ditolak backend dengan
| pesan validasi yang membingungkan user.
|--------------------------------------------------------------------------
*/
const ALLOWED_ATTACHMENT_MIMES = [
  'application/pdf',
  'image/jpeg',
  'image/jpg',
  'image/png',
  'image/webp',
]

const ALLOWED_ATTACHMENT_EXTENSIONS = [
  'pdf',
  'jpg',
  'jpeg',
  'png',
  'webp',
]

const isAllowedAttachment = (file: File): boolean => {
  const mimeType = String(file.type || '').toLowerCase()

  if (mimeType)
    return ALLOWED_ATTACHMENT_MIMES.includes(mimeType)

  /*
   * Sebagian browser/OS tidak mengisi file.type sama sekali.
   * Jangan tolak mentah-mentah, jatuhkan ke pengecekan ekstensi.
   */
  const extension = file.name.split('.').pop()?.toLowerCase() ?? ''

  return ALLOWED_ATTACHMENT_EXTENSIONS.includes(extension)
}

const form = ref({
  receive_date: new Date().toISOString().slice(0, 10),
  po_id: null as number | string | null,
  po_number: '',
  vendor_id: null as number | string | null,
  vendor_name: '',
  cabang_id: null as number | string | null,
  cabang_name: '',
  department_id: null as number | string | null,
  department_name: '',
  delivery_note_no: '',
  created_by: userData?.name ?? '',
  notes: '',
})

const items = ref<GrItem[]>([])

const formatFileSize = (size: number): string => {
  if (!size) return '0 KB'

  const units = ['B', 'KB', 'MB', 'GB']
  let fileSize = size
  let unitIndex = 0

  while (fileSize >= 1024 && unitIndex < units.length - 1) {
    fileSize /= 1024
    unitIndex++
  }

  return `${fileSize.toFixed(2)} ${units[unitIndex]}`
}

const removeAttachment = (index: number): void => {
  attachments.value.splice(index, 1)
}

const handleAttachmentChange = (files: File[] | File | null): void => {
  if (!files) return

  const selectedFiles = Array.isArray(files) ? files : [files]
  const validFiles: File[] = []

  selectedFiles.forEach(file => {
    const isValidType = isAllowedAttachment(file)

    if (!isValidType) {
      showErrorToast({
        title: t('goodsReceive.create.attachment.invalidTypeTitle'),
        text: t('goodsReceive.create.attachment.invalidTypeText', { file: file.name }),
      })

      return
    }

    if (file.size > MAX_FILE_SIZE) {
      showErrorToast({
        title: t('goodsReceive.create.attachment.tooLargeTitle'),
        text: t('goodsReceive.create.attachment.tooLargeText', { file: file.name }),
      })

      return
    }

    validFiles.push(file)
  })

  attachments.value.push(...validFiles)

  attachmentInput.value = []
}

const totalReceiveQty = computed(() => {
  return items.value.reduce((sum, item) => sum + Number(item.receive_qty || 0), 0)
})

const totalItemSelected = computed(() => {
  return items.value.filter(item => Number(item.receive_qty || 0) > 0).length
})

const canSubmit = computed(() => {
  return (
    !!form.value.po_id &&
    !!form.value.receive_date &&
    items.value.length > 0 &&
    totalReceiveQty.value > 0 &&
    items.value.every(item => Number(item.receive_qty || 0) <= Number(item.remaining_qty || 0))
  )
})

const formatNumber = (value: number | string | null | undefined): string => {
  const num = Number(value || 0)

  return new Intl.NumberFormat('id-ID', {
    minimumFractionDigits: 0,
    maximumFractionDigits: 2,
  }).format(num)
}

const confirmCancel = async (): Promise<void> => {
  const result = await showConfirmAlert({
    title: t('goodsReceive.create.toast.cancelConfirmTitle'),
    text: t('goodsReceive.create.toast.cancelConfirmText'),
    confirmButtonText: t('goodsReceive.create.toast.cancelConfirmButton'),
    cancelButtonText: t('goodsReceive.create.toast.cancelConfirmCancelButton'),
  })

  if (result.isConfirmed) {
    router.push('/non_stock/goods_receive')
  }
}

const fetchPoOptions = async (forceReload = false): Promise<void> => {
  if (poLoading.value) return
  if (!forceReload && poOptions.value.length > 0) return

  poLoading.value = true

  try {
    const response = await axios.get('/transaction/purchase-order/dropdown-receivable', {
      headers: { Accept: 'application/json' },
    })

    const rows = response.data?.data ?? []

    poOptions.value = rows.map((row: any) => ({
      id: row.id,
      public_id: row.public_id,
      title: row.nomor_po ?? row.po_number ?? '-',
      subtitle: [
        row.vendor?.nama_vendor ?? row.vendor_name ?? '-',
        row.cabang ?? '-',
        row.department ?? '-',
      ].join(' • '),
      raw: row,
    }))
  } catch (error) {
    poOptions.value = []

    const err = error as AxiosErrorShape

    showErrorToast({
      title: t('common.alert.error'),
      text: getApiErrorMessage(err, t('goodsReceive.create.toast.loadPoFailed')),
    })
  } finally {
    poLoading.value = false
  }
}

const loadPoDetail = async (public_id: number | string): Promise<void> => {
  itemLoading.value = true
  items.value = []

  try {
    const response = await axios.get(`/transaction/purchase-order/${public_id}/receivable-items`, {
      headers: { Accept: 'application/json' },
    })

    const data = response.data?.data ?? response.data

    form.value.po_id = data.public_id ?? data.id ?? public_id
    form.value.po_number = data.po_number ?? data.nomor_po ?? ''
    form.value.vendor_id = data.vendor_id ?? data.vendor?.id ?? null
    form.value.vendor_name = data.vendor_name ?? data.vendor?.nama_vendor ?? data.vendor?.name ?? ''
    form.value.cabang_id = data.cabang_id ?? data.cabang?.id ?? null
    form.value.cabang_name = data.cabang_name ?? data.cabang?.nama_cabang ?? ''
    form.value.department_id = data.department_id ?? data.department?.id ?? null
    form.value.department_name = data.department_name ?? data.department?.name ?? data.department?.nama_department ?? ''

    const rows = data.items ?? data.po_items ?? []

    items.value = rows.map((row: any) => ({
      po_item_id: row.public_id ?? row.id ?? row.po_item_id,
      item_id: row.item_id ?? row.item?.id ?? null,
      item_name: row.item_name ?? row.item?.name ?? row.nama_item ?? '-',
      item_code: row.item_code ?? row.item?.code ?? row.kode_item ?? '-',
      unit: row.satuan ?? row.unit ?? '-',
      ordered_qty: Number(row.qty ?? row.ordered_qty ?? 0),
      received_qty: Number(row.received_qty ?? row.qty_received ?? 0),
      remaining_qty: Number(row.remaining_qty ?? row.qty_remaining ?? 0),
      receive_qty: 0,
      notes: '',
    }))
  } catch (error) {
    Swal.fire({
      icon: 'error',
      title: t('goodsReceive.create.toast.loadPoDetailFailedTitle'),
      text: t('goodsReceive.create.toast.loadPoDetailFailedText'),
    })
  } finally {
    itemLoading.value = false
  }
}

watch(selectedPo, async value => {
  if (!value) return

  await loadPoDetail(value)
})

const setReceiveAll = (): void => {
  items.value = items.value.map(item => ({
    ...item,
    receive_qty: Number(item.remaining_qty || 0),
  }))
}

const clearReceiveQty = (): void => {
  items.value = items.value.map(item => ({
    ...item,
    receive_qty: 0,
  }))
}

const validateItems = (): boolean => {
  for (const item of items.value) {
    if (Number(item.receive_qty || 0) < 0) {
      Swal.fire({
        icon: 'warning',
        title: t('goodsReceive.create.toast.invalidQtyTitle'),
        text: t('goodsReceive.create.toast.invalidQtyText', { item: item.item_name }),
      })

      return false
    }

    if (Number(item.receive_qty || 0) > Number(item.remaining_qty || 0)) {
      Swal.fire({
        icon: 'warning',
        title: t('goodsReceive.create.toast.qtyExceedsRemainingTitle'),
        text: t('goodsReceive.create.toast.qtyExceedsRemainingText', { item: item.item_name }),
      })

      return false
    }
  }

  if (totalReceiveQty.value <= 0) {
    Swal.fire({
      icon: 'warning',
      title: t('goodsReceive.create.toast.noItemFilledTitle'),
      text: t('goodsReceive.create.toast.noItemFilledText'),
    })

    return false
  }

  return true
}

const submit = async (): Promise<void> => {
  if (!validateItems()) return

  const confirm = await showConfirmAlert({
    title: t('goodsReceive.create.toast.saveConfirmTitle'),
    text: t('goodsReceive.create.toast.saveConfirmText'),
    confirmButtonText: t('goodsReceive.create.toast.saveConfirmButton'),
    cancelButtonText: t('common.actions.cancel'),
  })

  if (!confirm.isConfirmed) return

  submitLoading.value = true

  try {
    showLoadingAlert(t('goodsReceive.create.toast.savingTitle'), t('common.alert.pleaseWait'))

    const payload = new FormData()

    payload.append('purchase_order_public_id', String(form.value.po_id ?? ''))
    payload.append('tanggal_gr', String(form.value.receive_date ?? ''))
    payload.append('nomor_surat_jalan', String(form.value.delivery_note_no ?? ''))
    payload.append('created_by', String(form.value.created_by ?? ''))
    payload.append('notes', String(form.value.notes ?? ''))

    items.value
      .filter(item => Number(item.receive_qty || 0) > 0)
      .forEach((item, index) => {
        payload.append(`items[${index}][purchase_order_item_public_id]`, String(item.po_item_id))
        payload.append(`items[${index}][qty_receive]`, String(item.receive_qty || 0))
        payload.append(`items[${index}][notes]`, String(item.notes ?? ''))
      })

    attachments.value.forEach((file, index) => {
      payload.append(`attachments[${index}]`, file)
    })

    await axios.post('/transaction/goods-receive', payload, {
      headers: {
        Accept: 'application/json',
      },
    })

    closeAlert()

    await router.replace({
      path: '/non_stock/goods_receive',
      query: { success: 'created' },
    })
  } catch (error: any) {
    closeAlert()

    const err = error as AxiosErrorShape

    console.error('[Goods Receipt] SAVE ERROR:', err)

    if (err?.response?.status === 401) {
      showErrorToast({
        title: t('goodsReceive.create.toast.sessionExpiredTitle'),
        text: t('goodsReceive.create.toast.sessionExpiredText'),
      })

      localStorage.removeItem('accessToken')
      localStorage.removeItem('userData')
      localStorage.removeItem('navItems')

      await router.replace('/login')
      return
    }

    showErrorToast({
      title: t('common.alert.error'),
      text: err?.response?.data?.message || t('goodsReceive.create.toast.saveFailed'),
    })
  } finally {
    submitLoading.value = false
  }
}

const backToIndex = async (): Promise<void> => {
  await router.replace({
    path: '/non_stock/goods_receive',
  })
}

onMounted(async () => {
  await permissionStore.loadPermissions()

  if (!canCreate.value) {
    await router.replace('/forbidden')
    return
  }

  isCheckingPermission.value = false
  
  loading.value = true
  await fetchPoOptions()
  loading.value = false
})
</script>

<template>
  <section>
    <VContainer fluid>
        <VRow>
            <VCol cols="12">
                <VCard class="rounded-lg">
                <VCardText>
                    <div class="d-flex flex-wrap align-center justify-space-between gap-4">
                    <div>
                        <h2 class="text-h5 font-weight-bold mb-1">
                        {{ t('goodsReceive.create.pageTitle') }}
                        </h2>
                        <div class="text-body-2 text-medium-emphasis">
                        {{ t('goodsReceive.create.pageSubtitle') }}
                        </div>
                    </div>

                    <VBtn
                        variant="tonal"
                        color="secondary"
                        prepend-icon="tabler-arrow-left"
                        @click="backToIndex"
                        class="text-none"
                    >
                        {{ t('goodsReceive.create.backButton') }}
                    </VBtn>
                    </div>
                </VCardText>
                </VCard>
            </VCol>

            <VCol cols="12">
                <VCard class="rounded-lg">
                  <VCardText>
                      <VRow>
                        <VCol cols="12" md="4">
                            <AppDateTimePicker
                            v-model="form.receive_date"
                            :label="t('goodsReceive.create.fields.receiveDate')"
                            :placeholder="t('goodsReceive.create.fields.receiveDatePlaceholder')"
                            :config="{ dateFormat: 'Y-m-d' }"
                            />
                        </VCol>

                        <VCol cols="12" md="4">
                            <VAutocomplete
                                v-model="selectedPo"
                                :items="poOptions"
                                :loading="poLoading"
                                item-title="title"
                                item-value="public_id"
                                :label="t('goodsReceive.create.fields.purchaseOrder')"
                                :placeholder="t('goodsReceive.create.fields.purchaseOrderPlaceholder')"
                                clearable
                                density="compact"
                                :no-data-text="t('goodsReceive.create.fields.purchaseOrderNoData')"
                                @click:control="fetchPoOptions()"
                            >
                                <template #item="{ props, item }">
                                <VListItem v-bind="props">
                                    <VListItemSubtitle>
                                    {{ item.raw.subtitle }}
                                    </VListItemSubtitle>
                                </VListItem>
                                </template>

                                <template #append-inner>
                                <VTooltip
                                    v-if="!poLoading && poOptions.length === 0"
                                    :text="t('goodsReceive.create.fields.purchaseOrderReloadTooltip')"
                                    location="top"
                                >
                                    <template #activator="{ props }">
                                    <VBtn
                                        v-bind="props"
                                        icon
                                        size="x-small"
                                        variant="text"
                                        color="primary"
                                        @click.stop.prevent="fetchPoOptions(true)"
                                    >
                                        <VIcon icon="tabler-refresh" />
                                    </VBtn>
                                    </template>
                                </VTooltip>
                                </template>
                            </VAutocomplete>
                        </VCol>

                        <!-- <VCol cols="12" md="4">
                            <VTextField
                            v-model="form.delivery_note_no"
                            label="No Surat Jalan"
                            placeholder="Masukkan nomor surat jalan"
                            density="compact"
                            />
                        </VCol> -->

                        <VCol cols="12" md="4">
                            <VTextField
                            v-model="form.vendor_name"
                            :label="t('goodsReceive.create.fields.vendor')"
                            readonly
                            density="compact"
                            />
                        </VCol>

                        <VCol cols="12" md="4">
                            <VTextField
                            v-model="form.cabang_name"
                            :label="t('goodsReceive.create.fields.cabang')"
                            readonly
                            density="compact"
                            />
                        </VCol>

                        <VCol cols="12" md="4">
                            <VTextField
                            v-model="form.department_name"
                            :label="t('goodsReceive.create.fields.department')"
                            readonly
                            density="compact"
                            />
                        </VCol>

                        <VCol cols="12" md="4">
                            <VTextField
                            v-model="form.created_by"
                            :label="t('goodsReceive.create.fields.receivedBy')"
                            readonly
                            :placeholder="t('goodsReceive.create.fields.receivedByPlaceholder')"
                            density="compact"
                            prepend-inner-icon="tabler-user"
                            />
                        </VCol>

                        <VCol cols="12" md="12">
                            <VTextarea
                            v-model="form.notes"
                            :label="t('goodsReceive.create.fields.notes')"
                            :placeholder="t('goodsReceive.create.fields.notesPlaceholder')"
                            rows="2"
                            density="compact"
                            />
                        </VCol>
                      </VRow>
                  </VCardText>
                </VCard>
            </VCol>

            <VCol>
              <VCard
                elevation="2"
              >
                <VCardText class="pa-6">
                  <div class="d-flex flex-wrap align-center justify-space-between gap-4 mb-4">
                    <div>
                      <h3 class="text-h6 font-weight-bold mb-1">
                        {{ t('goodsReceive.create.attachment.title') }}
                      </h3>

                      <div class="text-body-2 text-medium-emphasis">
                        {{ t('goodsReceive.create.attachment.subtitle') }}
                      </div>
                    </div>

                    <VChip
                      color="primary"
                      variant="tonal"
                      prepend-icon="tabler-paperclip"
                    >
                      {{ t('goodsReceive.create.attachment.fileCount', { count: attachments.length }) }}
                    </VChip>
                  </div>

                  <VFileInput
                    v-model="attachmentInput"
                    multiple
                    show-size
                    clearable
                    density="comfortable"
                    variant="outlined"
                    prepend-icon=""
                    prepend-inner-icon="tabler-upload"
                    :label="t('goodsReceive.create.attachment.uploadLabel')"
                    :placeholder="t('goodsReceive.create.attachment.uploadPlaceholder')"
                    accept="application/pdf,image/jpeg,image/png,image/webp"
                    @update:model-value="handleAttachmentChange"
                  />

                  <VAlert
                    type="info"
                    variant="tonal"
                    class="mt-3"
                  >
                    {{ t('goodsReceive.create.attachment.formatInfo') }}
                  </VAlert>

                  <VAlert
                    v-if="!attachments.length"
                    type="info"
                    variant="tonal"
                    class="mt-4"
                  >
                    {{ t('goodsReceive.create.attachment.empty') }}
                  </VAlert>

                  <VTable
                    v-else
                    class="mt-4"
                  >
                    <thead>
                      <tr>
                        <th width="60">
                          {{ t('goodsReceive.create.attachment.table.no') }}
                        </th>

                        <th>
                          {{ t('goodsReceive.create.attachment.table.fileName') }}
                        </th>

                        <th width="160">
                          {{ t('goodsReceive.create.attachment.table.size') }}
                        </th>

                        <th width="120">
                          {{ t('goodsReceive.create.attachment.table.type') }}
                        </th>

                        <th width="100">
                          {{ t('goodsReceive.create.attachment.table.actions') }}
                        </th>
                      </tr>
                    </thead>

                    <tbody>
                      <tr
                        v-for="(file, index) in attachments"
                        :key="`${file.name}-${index}`"
                      >
                        <td>
                          {{ index + 1 }}
                        </td>

                        <td>
                          <div class="d-flex align-center">
                            <VIcon
                              icon="tabler-file"
                              size="18"
                              class="me-2"
                            />

                            <span>{{ file.name }}</span>
                          </div>
                        </td>

                        <td>
                          {{ formatFileSize(file.size) }}
                        </td>

                        <td>
                          {{ file.type || '-' }}
                        </td>

                        <td>
                          <VBtn
                            icon
                            size="small"
                            color="error"
                            variant="text"
                            @click="removeAttachment(index)"
                          >
                            <VIcon icon="tabler-trash" />
                          </VBtn>
                        </td>
                      </tr>
                    </tbody>
                  </VTable>
                </VCardText>
              </VCard>
            </VCol>
            <VCol cols="12">
                <VCard class="rounded-lg">
                <VCardText>
                  <div class="d-flex flex-wrap align-center justify-space-between gap-4 mb-4">
                    <div>
                        <h3 class="text-h6 font-weight-bold mb-1">
                        {{ t('goodsReceive.create.items.title') }}
                        </h3>
                        <div class="text-body-2 text-medium-emphasis">
                        {{ t('goodsReceive.create.items.subtitle') }}
                        </div>
                    </div>

                    <div class="d-flex gap-2">
                        <VBtn
                        variant="tonal"
                        color="primary"
                        prepend-icon="tabler-checks"
                        :disabled="!items.length"
                        @click="setReceiveAll"
                        class="text-none"
                        >
                        {{ t('goodsReceive.create.items.receiveAllButton') }}
                        </VBtn>

                        <VBtn
                        variant="tonal"
                        color="secondary"
                        prepend-icon="tabler-x"
                        :disabled="!items.length"
                        @click="clearReceiveQty"
                        class="text-none"
                        >
                        {{ t('goodsReceive.create.items.resetButton') }}
                        </VBtn>
                    </div>
                  </div>

                    <VProgressLinear
                    v-if="itemLoading"
                    indeterminate
                    color="primary"
                    class="mb-4"
                    />

                    <VTable class="text-no-wrap">
                    <thead>
                        <tr>
                        <th width="50">{{ t('goodsReceive.create.items.table.no') }}</th>
                        <th>{{ t('goodsReceive.create.items.table.item') }}</th>
                        <th width="120" class="text-end">{{ t('goodsReceive.create.items.table.qtyPo') }}</th>
                        <th width="140" class="text-end">{{ t('goodsReceive.create.items.table.sudahGr') }}</th>
                        <th width="140" class="text-end">{{ t('goodsReceive.create.items.table.sisa') }}</th>
                        <th width="160" class="text-end">{{ t('goodsReceive.create.items.table.qtyReceive') }}</th>
                        <th width="220">{{ t('goodsReceive.create.items.table.notes') }}</th>
                        </tr>
                    </thead>

                    <tbody>
                        <tr v-if="!items.length">
                        <td colspan="7" class="text-center py-8 text-medium-emphasis">
                            {{ t('goodsReceive.create.items.emptyState') }}
                        </td>
                        </tr>

                        <tr
                        v-for="(item, index) in items"
                        :key="item.po_item_id"
                        >
                        <td>{{ index + 1 }}</td>

                        <td>
                            <div class="font-weight-medium">
                            {{ toTitleCase(item.item_name) }}
                            </div>
                            <div class="text-caption text-medium-emphasis">
                            {{ item.unit }}
                            </div>
                        </td>

                        <td class="text-end">
                            {{ formatNumber(item.ordered_qty) }}
                        </td>

                        <td class="text-end">
                            {{ formatNumber(item.received_qty) }}
                        </td>

                        <td class="text-end">
                            <VChip
                            size="small"
                            color="warning"
                            variant="tonal"
                            >
                            {{ formatNumber(item.remaining_qty) }}
                            </VChip>
                        </td>

                        <td>
                            <VTextField
                            v-model.number="item.receive_qty"
                            type="number"
                            min="0"
                            :max="item.remaining_qty"
                            density="compact"
                            hide-details
                            class="text-end"
                            />
                        </td>

                        <td>
                            <VTextField
                            v-model="item.notes"
                            :placeholder="t('goodsReceive.create.items.notesPlaceholder')"
                            density="compact"
                            hide-details
                            />
                        </td>
                        </tr>
                    </tbody>
                    </VTable>

                    <VDivider class="my-4" />

                    <VRow>
                    <VCol cols="12" md="4">
                        <VAlert
                        color="primary"
                        variant="tonal"
                        density="compact"
                        >
                        {{ t('goodsReceive.create.items.totalItemSelected') }}
                        <strong>{{ totalItemSelected }}</strong>
                        </VAlert>
                    </VCol>

                    <VCol cols="12" md="4">
                        <VAlert
                        color="success"
                        variant="tonal"
                        density="compact"
                        >
                        {{ t('goodsReceive.create.items.totalQtyReceive') }}
                        <strong>{{ formatNumber(totalReceiveQty) }}</strong>
                        </VAlert>
                    </VCol>

                    <VCol cols="12" md="4">
                        <VAlert
                        color="info"
                        variant="tonal"
                        density="compact"
                        >
                        {{ t('goodsReceive.create.items.initialStatus') }}
                        <strong>{{ t('goodsReceive.create.items.statusDraft') }}</strong>
                        </VAlert>
                    </VCol>
                    </VRow>
                </VCardText>

                <VCardActions class="justify-end pa-6 pt-0">
                    <VBtn
                    variant="tonal"
                    color="secondary"
                    @click.prevent.stop="confirmCancel"
                    class="text-none"
                    >
                    {{ t('common.actions.cancel') }}
                    </VBtn>

                    <VBtn
                      color="primary"
                      prepend-icon="tabler-device-floppy"
                      :loading="submitLoading"
                      :disabled="!canSubmit"
                      @click="submit"
                      class="text-none"
                    >
                    {{ t('goodsReceive.create.toast.saveButton') }}
                    </VBtn>
                </VCardActions>
                </VCard>
            </VCol>
        </VRow>
  </VContainer>
  </section>
</template>