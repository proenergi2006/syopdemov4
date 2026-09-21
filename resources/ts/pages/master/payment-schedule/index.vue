<script setup lang="ts">
import { computed, onMounted, reactive, ref } from 'vue'
import { useI18n } from 'vue-i18n'
import axios from '@axios'
import { useDayNames } from '@core/composable/useDayNames'
import {
  closeAlert,
  showConfirmAlert,
  showErrorToast,
  showLoadingAlert,
  showSuccessToast,
} from '@/utils/alert'
import { getApiErrorMessage } from '@/utils/apiHelper'
import { usePermissionStore } from '@/stores/permission'

/*
|--------------------------------------------------------------------------
| Master Jadwal Pembayaran Finance
|--------------------------------------------------------------------------
| Finance membayar pada hari tertentu saja, dan berkas yang masuk sebelum
| suatu batas ikut rombongan pembayaran berikutnya. Halaman ini yang mengatur
| batas-batas itu -- tanpa perlu mengubah satu baris kode pun.
|
| Seluruh label lewat i18n, termasuk kalimat batas setor yang dirakit di
| server -- susunan kalimatnya berbeda antar bahasa, jadi tidak bisa disambung
| dari potongan kata di sini.
|--------------------------------------------------------------------------
*/

interface Cutoff {
  id?: number
  cutoff_day: number
  cutoff_day_name?: string
  cutoff_time: string
  payment_day: number
  payment_day_name?: string
  payment_week_offset: number
  description?: string
}

interface ScheduleRow {
  id: number
  name: string
  document_type: string | null
  document_type_label: string | null
  transaction_category_id: number | null
  transaction_category_name: string | null
  is_active: boolean
  notes: string | null

  /** Jadwal umum tidak boleh dihapus selama belum ada penggantinya. */
  is_fallback: boolean

  cutoffs: Cutoff[]
}

interface Abilities {
  can_view: boolean
  can_create: boolean
  can_update: boolean
  can_delete: boolean
}

interface PilihanOpsi {
  value: number | string
  label: string
}

const { t } = useI18n()
const permissionStore = usePermissionStore()

const ENDPOINT = '/fund-request/payment-schedules'

const rows = ref<ScheduleRow[]>([])
const loading = ref(false)

const abilities = ref<Abilities>({
  can_view: false,
  can_create: false,
  can_update: false,
  can_delete: false,
})

const documentTypes = ref<PilihanOpsi[]>([])

/*
| Nama harinya dirangkai di sini, bukan diambil dari server, supaya ikut
| berganti begitu pengguna menukar bahasa -- tanpa menunggu data diambil
| ulang. Angkanyalah yang disimpan dan dikirim, dan angka tidak berbahasa.
*/
const { dayName } = useDayNames()

const days = computed<PilihanOpsi[]>(() =>
  [1, 2, 3, 4, 5, 6, 7].map(iso => ({ value: iso, label: dayName(iso) })))

const categories = ref<{ id: number; name: string }[]>([])

const canView = computed(() => permissionStore.can('master_payment_schedule.view'))

/*
|--------------------------------------------------------------------------
| Memuat data
|--------------------------------------------------------------------------
*/
const fetchRows = async (): Promise<void> => {
  loading.value = true

  try {
    const response = await axios.get(ENDPOINT, {
      headers: { Accept: 'application/json' },
    })

    rows.value = response.data?.data ?? []
    abilities.value = response.data?.abilities ?? abilities.value

    const opsi = response.data?.options ?? {}

    documentTypes.value = opsi.document_types ?? []
    categories.value = opsi.transaction_categories ?? []
  }
  catch (error: unknown) {
    showErrorToast({
      title: t('common.alert.error'),
      text: getApiErrorMessage(error, t('paymentSchedule.list.loadFailed')),
    })
  }
  finally {
    loading.value = false
  }
}

/*
|--------------------------------------------------------------------------
| Formulir
|--------------------------------------------------------------------------
| Batas setornya disunting sebagai daftar di dalam formulir yang sama, karena
| satu batas sendirian tidak punya arti -- yang berlaku adalah seluruh
| himpunannya.
|--------------------------------------------------------------------------
*/
const dialog = ref(false)
const saving = ref(false)
const editingId = ref<number | null>(null)
const formError = ref('')

const form = reactive({
  name: '',
  document_type: null as string | null,
  transaction_category_id: null as number | null,
  is_active: true,
  notes: '',
  cutoffs: [] as Cutoff[],
})

/* Computed, bukan konstanta: labelnya harus ikut berubah saat bahasa diganti. */
const weekOffsetItems = computed(() => [
  { title: t('paymentSchedule.form.weekSame'), value: 0 },
  { title: t('paymentSchedule.form.weekNext'), value: 1 },
  { title: t('paymentSchedule.form.weekAfter'), value: 2 },
])

const batasKosong = (): Cutoff => ({
  cutoff_day: 3,
  cutoff_time: '14:00',
  payment_day: 1,
  payment_week_offset: 1,
})

const resetForm = (): void => {
  form.name = ''
  form.document_type = null
  form.transaction_category_id = null
  form.is_active = true
  form.notes = ''
  form.cutoffs = [batasKosong()]
  formError.value = ''
  editingId.value = null
}

const openCreate = (): void => {
  resetForm()
  dialog.value = true
}

const openEdit = (row: ScheduleRow): void => {
  resetForm()

  editingId.value = row.id
  form.name = row.name
  form.document_type = row.document_type
  form.transaction_category_id = row.transaction_category_id
  form.is_active = row.is_active
  form.notes = row.notes ?? ''

  form.cutoffs = row.cutoffs.map(c => ({
    cutoff_day: c.cutoff_day,
    cutoff_time: c.cutoff_time,
    payment_day: c.payment_day,
    payment_week_offset: c.payment_week_offset,
  }))

  if (form.cutoffs.length === 0)
    form.cutoffs = [batasKosong()]

  dialog.value = true
}

const addCutoff = (): void => {
  form.cutoffs = [...form.cutoffs, batasKosong()]
}

const removeCutoff = (index: number): void => {
  if (form.cutoffs.length <= 1)
    return

  form.cutoffs = form.cutoffs.filter((_, i) => i !== index)
}

/** Kalimat utuh untuk satu baris batas, supaya aturannya terbaca saat diketik. */
const cutoffSentence = (c: Cutoff): string => {
  const hari = (iso: number): string =>
    days.value.find(d => d.value === iso)?.label ?? '-'

  const minggu = c.payment_week_offset === 0
    ? t('paymentSchedule.form.weekSame')
    : c.payment_week_offset === 1
      ? t('paymentSchedule.form.weekNext')
      : t('paymentSchedule.form.weekAfter')

  /* Kalimatnya dirakit dari pola, bukan disambung -- susunannya beda per bahasa. */
  return t('paymentSchedule.form.sentence', {
    cutoffDay: hari(c.cutoff_day),
    cutoffTime: c.cutoff_time,
    paymentDay: hari(c.payment_day),
    week: minggu.toLowerCase(),
  })
}

const save = async (): Promise<void> => {
  if (saving.value)
    return

  formError.value = ''

  if (!form.name.trim()) {
    formError.value = t('paymentSchedule.form.nameRequired')

    return
  }

  saving.value = true

  try {
    showLoadingAlert(t('common.alert.processing'), t('common.alert.pleaseWait'))

    const payload = {
      name: form.name.trim(),
      document_type: form.document_type,
      transaction_category_id: form.transaction_category_id,
      is_active: form.is_active,
      notes: form.notes.trim() || null,
      cutoffs: form.cutoffs,
    }

    const response = editingId.value === null
      ? await axios.post(ENDPOINT, payload, { headers: { Accept: 'application/json' } })
      : await axios.put(`${ENDPOINT}/${editingId.value}`, payload, { headers: { Accept: 'application/json' } })

    closeAlert()

    dialog.value = false

    showSuccessToast({
      title: t('common.alert.success'),
      text: response.data?.message || t('paymentSchedule.form.saveSuccess'),
    })

    await fetchRows()
  }
  catch (error: unknown) {
    closeAlert()

    formError.value = getApiErrorMessage(error, t('paymentSchedule.form.saveFailed'))
  }
  finally {
    saving.value = false
  }
}

const remove = async (row: ScheduleRow): Promise<void> => {
  const konfirmasi = await showConfirmAlert({
    title: t('paymentSchedule.delete.confirmTitle'),
    text: t('paymentSchedule.delete.confirmText', { nama: row.name }),
    confirmButtonText: t('paymentSchedule.delete.confirmButton'),
    cancelButtonText: t('common.actions.cancel'),
  })

  if (!konfirmasi.isConfirmed)
    return

  try {
    showLoadingAlert(t('common.alert.processing'), t('common.alert.pleaseWait'))

    const response = await axios.delete(`${ENDPOINT}/${row.id}`, {
      headers: { Accept: 'application/json' },
    })

    closeAlert()

    showSuccessToast({
      title: t('common.alert.success'),
      text: response.data?.message || t('paymentSchedule.delete.success'),
    })

    await fetchRows()
  }
  catch (error: unknown) {
    closeAlert()

    showErrorToast({
      title: t('common.alert.error'),
      text: getApiErrorMessage(error, t('paymentSchedule.delete.failed')),
    })
  }
}

/*
|--------------------------------------------------------------------------
| Contoh hasil perhitungan
|--------------------------------------------------------------------------
| Aturan batas setor mudah disalahpahami saat diketik. Contoh satu pekan
| penuh membuat akibat pengaturannya terlihat sebelum ada dokumen sungguhan
| yang terlanjur memakainya.
|--------------------------------------------------------------------------
*/
const previewDialog = ref(false)
const previewLoading = ref(false)
const previewRows = ref<{ received_day: string; received_date: string; payment_day: string | null; payment_date: string | null }[]>([])
const previewName = ref<string | null>(null)
const previewScope = ref('')

const openPreview = async (row: ScheduleRow): Promise<void> => {
  previewDialog.value = true
  previewLoading.value = true
  previewRows.value = []

  previewScope.value = [
    row.document_type_label ?? t('paymentSchedule.scope.allModules'),
    row.transaction_category_name ?? t('paymentSchedule.scope.allCategories'),
  ].join(' — ')

  try {
    const response = await axios.get(`${ENDPOINT}/preview`, {
      params: {
        document_type: row.document_type,
        transaction_category_id: row.transaction_category_id,
      },
      headers: { Accept: 'application/json' },
    })

    previewRows.value = response.data?.data?.rows ?? []
    previewName.value = response.data?.data?.schedule ?? null
  }
  catch (error: unknown) {
    showErrorToast({
      title: t('common.alert.error'),
      text: getApiErrorMessage(error, t('paymentSchedule.preview.failed')),
    })

    previewDialog.value = false
  }
  finally {
    previewLoading.value = false
  }
}

const scopeLabel = (row: ScheduleRow): string => {
  if (row.is_fallback)
    return t('paymentSchedule.scope.fallback')

  return [
    row.document_type_label,
    row.transaction_category_name,
  ].filter(Boolean).join(' — ')
}

onMounted(() => {
  if (canView.value)
    fetchRows()
})
</script>

<template>
  <div>
    <VCard v-if="!canView">
      <VCardText class="text-center py-12">
        <VIcon
          icon="tabler-lock"
          size="48"
          class="text-disabled mb-3"
        />

        <div class="text-body-1">
          {{ t('paymentSchedule.noAccess') }}
        </div>
      </VCardText>
    </VCard>

    <template v-else>
      <!-- PENJELASAN SINGKAT -->
      <VCard class="mb-4 ps-bar">
        <VCardText class="d-flex align-start gap-3 py-4">
          <VAvatar
            size="40"
            color="primary"
            variant="tonal"
            rounded
          >
            <VIcon
              icon="tabler-calendar-dollar"
              size="22"
            />
          </VAvatar>

          <div class="min-w-0">
            <div class="text-body-1 font-weight-medium mb-1">
              {{ t('paymentSchedule.intro.title') }}
            </div>

            <div class="text-body-2 text-medium-emphasis">
              {{ t('paymentSchedule.intro.body') }}
            </div>

            <VAlert
              type="info"
              variant="tonal"
              density="compact"
              class="mt-3"
            >
              {{ t('paymentSchedule.intro.holidayNotice') }}
            </VAlert>
          </div>
        </VCardText>
      </VCard>

      <!-- DAFTAR -->
      <VCard>
        <VCardItem>
          <VCardTitle>{{ t('paymentSchedule.list.title') }}</VCardTitle>

          <VCardSubtitle>
            {{ t('paymentSchedule.list.subtitle') }}
          </VCardSubtitle>

          <template #append>
            <VBtn
              v-if="abilities.can_create"
              color="primary"
              prepend-icon="tabler-plus"
              class="text-none"
              @click="openCreate"
            >
              {{ t('paymentSchedule.list.addButton') }}
            </VBtn>
          </template>
        </VCardItem>

        <VDivider />

        <VCardText>
          <VProgressLinear
            v-if="loading"
            indeterminate
            color="primary"
            class="mb-4"
          />

          <div
            v-if="!loading && !rows.length"
            class="text-center py-10 text-medium-emphasis"
          >
            {{ t('paymentSchedule.list.empty') }}
          </div>

          <VRow v-else>
            <VCol
              v-for="row in rows"
              :key="row.id"
              cols="12"
              md="6"
            >
              <VCard
                variant="outlined"
                :class="{ 'ps-card-inactive': !row.is_active }"
              >
                <VCardText>
                  <div class="d-flex align-start justify-space-between gap-3 mb-3">
                    <div class="min-w-0">
                      <div class="text-body-1 font-weight-medium">
                        {{ row.name }}
                      </div>

                      <div class="text-caption text-medium-emphasis">
                        {{ scopeLabel(row) }}
                      </div>
                    </div>

                    <div class="d-flex align-center gap-1 flex-shrink-0">
                      <VChip
                        size="x-small"
                        :color="row.is_active ? 'success' : 'secondary'"
                        variant="tonal"
                      >
                        {{ row.is_active ? t('paymentSchedule.list.active') : t('paymentSchedule.list.inactive') }}
                      </VChip>

                      <VChip
                        v-if="row.is_fallback"
                        size="x-small"
                        color="info"
                        variant="tonal"
                      >
                        {{ t('paymentSchedule.list.fallbackChip') }}
                      </VChip>
                    </div>
                  </div>

                  <!-- Batas setornya, ditulis sebagai kalimat utuh -->
                  <div
                    v-for="cutoff in row.cutoffs"
                    :key="cutoff.id"
                    class="d-flex align-start gap-2 mb-2"
                  >
                    <VIcon
                      icon="tabler-arrow-narrow-right"
                      size="16"
                      class="mt-1 text-medium-emphasis flex-shrink-0"
                    />

                    <div class="text-body-2">
                      {{ cutoffSentence(cutoff) }}
                    </div>
                  </div>

                  <div
                    v-if="!row.cutoffs.length"
                    class="text-body-2 text-warning"
                  >
                    {{ t('paymentSchedule.list.noCutoff') }}
                  </div>

                  <div
                    v-if="row.notes"
                    class="text-caption text-medium-emphasis mt-3"
                  >
                    {{ row.notes }}
                  </div>
                </VCardText>

                <VDivider />

                <VCardActions class="justify-end">
                  <VBtn
                    size="small"
                    variant="text"
                    class="text-none"
                    prepend-icon="tabler-eye"
                    @click="openPreview(row)"
                  >
                    {{ t('paymentSchedule.list.previewButton') }}
                  </VBtn>

                  <VBtn
                    v-if="abilities.can_update"
                    size="small"
                    variant="text"
                    class="text-none"
                    prepend-icon="tabler-edit"
                    @click="openEdit(row)"
                  >
                    {{ t('paymentSchedule.list.editButton') }}
                  </VBtn>

                  <VBtn
                    v-if="abilities.can_delete && !row.is_fallback"
                    size="small"
                    variant="text"
                    color="error"
                    class="text-none"
                    prepend-icon="tabler-trash"
                    @click="remove(row)"
                  >
                    {{ t('paymentSchedule.list.deleteButton') }}
                  </VBtn>
                </VCardActions>
              </VCard>
            </VCol>
          </VRow>
        </VCardText>
      </VCard>
    </template>

    <!-- FORMULIR -->
    <VDialog
      v-model="dialog"
      max-width="760"
      persistent
      scrollable
    >
      <VCard>
        <VCardItem>
          <VCardTitle>
            {{ editingId === null ? t('paymentSchedule.form.createTitle') : t('paymentSchedule.form.editTitle') }}
          </VCardTitle>
        </VCardItem>

        <VDivider />

        <VCardText>
          <VAlert
            v-if="formError"
            type="error"
            variant="tonal"
            density="compact"
            class="mb-4"
          >
            {{ formError }}
          </VAlert>

          <VRow>
            <VCol cols="12">
              <VTextField
                v-model="form.name"
                :label="t('paymentSchedule.form.name')"
                :placeholder="t('paymentSchedule.form.namePlaceholder')"
                persistent-placeholder
                density="comfortable"
              />
            </VCol>

            <VCol
              cols="12"
              md="6"
            >
              <VSelect
                v-model="form.document_type"
                :label="t('paymentSchedule.form.documentType')"
                :items="documentTypes"
                item-title="label"
                item-value="value"
                density="comfortable"
                clearable
                :placeholder="t('paymentSchedule.form.documentTypePlaceholder')"
                persistent-placeholder
                :hint="t('paymentSchedule.form.documentTypeHint')"
                persistent-hint
              />
            </VCol>

            <VCol
              cols="12"
              md="6"
            >
              <VAutocomplete
                v-model="form.transaction_category_id"
                :label="t('paymentSchedule.form.category')"
                :items="categories"
                item-title="name"
                item-value="id"
                density="comfortable"
                clearable
                :placeholder="t('paymentSchedule.form.categoryPlaceholder')"
                persistent-placeholder
                :hint="t('paymentSchedule.form.categoryHint')"
                persistent-hint
              />
            </VCol>

            <VCol cols="12">
              <VDivider class="my-2" />

              <div class="d-flex align-center justify-space-between mb-3">
                <div>
                  <div class="text-body-1 font-weight-medium">
                    {{ t('paymentSchedule.form.cutoffTitle') }}
                  </div>

                  <div class="text-caption text-medium-emphasis">
                    {{ t('paymentSchedule.form.cutoffSubtitle') }}
                  </div>
                </div>

                <VBtn
                  size="small"
                  variant="tonal"
                  prepend-icon="tabler-plus"
                  class="text-none"
                  @click="addCutoff"
                >
                  {{ t('paymentSchedule.form.addCutoff') }}
                </VBtn>
              </div>

              <VCard
                v-for="(cutoff, index) in form.cutoffs"
                :key="index"
                variant="outlined"
                class="mb-3"
              >
                <VCardText class="py-3">
                  <VRow dense>
                    <VCol
                      cols="6"
                      md="3"
                    >
                      <VSelect
                        v-model="cutoff.cutoff_day"
                        :label="t('paymentSchedule.form.cutoffDay')"
                        :items="days"
                        item-title="label"
                        item-value="value"
                        density="compact"
                        hide-details
                      />
                    </VCol>

                    <VCol
                      cols="6"
                      md="2"
                    >
                      <VTextField
                        v-model="cutoff.cutoff_time"
                        :label="t('paymentSchedule.form.cutoffTime')"
                        type="time"
                        density="compact"
                        hide-details
                      />
                    </VCol>

                    <VCol
                      cols="6"
                      md="3"
                    >
                      <VSelect
                        v-model="cutoff.payment_day"
                        :label="t('paymentSchedule.form.paymentDay')"
                        :items="days"
                        item-title="label"
                        item-value="value"
                        density="compact"
                        hide-details
                      />
                    </VCol>

                    <VCol
                      cols="6"
                      md="3"
                    >
                      <VSelect
                        v-model="cutoff.payment_week_offset"
                        :label="t('paymentSchedule.form.weekOffset')"
                        :items="weekOffsetItems"
                        density="compact"
                        hide-details
                      />
                    </VCol>

                    <VCol
                      cols="12"
                      md="1"
                      class="d-flex align-center justify-end"
                    >
                      <VBtn
                        icon
                        size="small"
                        variant="text"
                        color="error"
                        :disabled="form.cutoffs.length <= 1"
                        @click="removeCutoff(index)"
                      >
                        <VIcon
                          icon="tabler-trash"
                          size="18"
                        />
                      </VBtn>
                    </VCol>
                  </VRow>

                  <!-- Kalimat utuhnya, supaya akibat pengaturannya terbaca saat diketik -->
                  <div class="text-caption text-medium-emphasis mt-2">
                    {{ cutoffSentence(cutoff) }}
                  </div>
                </VCardText>
              </VCard>
            </VCol>

            <VCol cols="12">
              <VTextarea
                v-model="form.notes"
                :label="t('paymentSchedule.form.notes')"
                rows="2"
                density="comfortable"
                :placeholder="t('paymentSchedule.form.notesPlaceholder')"
                persistent-placeholder
              />
            </VCol>

            <VCol cols="12">
              <VSwitch
                v-model="form.is_active"
                :label="t('paymentSchedule.form.isActive')"
                color="success"
                hide-details
              />
            </VCol>
          </VRow>
        </VCardText>

        <VDivider />

        <VCardActions class="justify-end">
          <VBtn
            variant="tonal"
            color="secondary"
            class="text-none"
            :disabled="saving"
            @click="dialog = false"
          >
            {{ t('common.actions.cancel') }}
          </VBtn>

          <VBtn
            color="primary"
            class="text-none"
            :loading="saving"
            @click="save"
          >
            {{ t('common.actions.save') }}
          </VBtn>
        </VCardActions>
      </VCard>
    </VDialog>

    <!-- CONTOH PERHITUNGAN -->
    <VDialog
      v-model="previewDialog"
      max-width="560"
      scrollable
    >
      <VCard>
        <VCardItem>
          <VCardTitle>{{ t('paymentSchedule.preview.title') }}</VCardTitle>

          <VCardSubtitle>{{ previewScope }}</VCardSubtitle>
        </VCardItem>

        <VDivider />

        <VCardText>
          <VProgressLinear
            v-if="previewLoading"
            indeterminate
            color="primary"
            class="mb-4"
          />

          <VAlert
            v-if="!previewLoading && previewName"
            type="info"
            variant="tonal"
            density="compact"
            class="mb-4"
          >
            {{ t('paymentSchedule.preview.usedSchedule') }} <strong>{{ previewName }}</strong>
          </VAlert>

          <VTable
            v-if="!previewLoading"
            density="compact"
          >
            <thead>
              <tr>
                <th scope="col">
                  {{ t('paymentSchedule.preview.received') }}
                </th>
                <th scope="col">
                  {{ t('paymentSchedule.preview.paid') }}
                </th>
              </tr>
            </thead>

            <tbody>
              <tr
                v-for="baris in previewRows"
                :key="baris.received_date"
              >
                <td>{{ baris.received_day }}, {{ baris.received_date }}</td>

                <td>
                  <span
                    v-if="baris.payment_date"
                    class="font-weight-medium"
                  >
                    {{ baris.payment_day }}, {{ baris.payment_date }}
                  </span>

                  <span
                    v-else
                    class="text-disabled"
                  >—</span>
                </td>
              </tr>
            </tbody>
          </VTable>
        </VCardText>

        <VDivider />

        <VCardActions class="justify-end">
          <VBtn
            variant="tonal"
            color="secondary"
            class="text-none"
            @click="previewDialog = false"
          >
            {{ t('common.actions.close') }}
          </VBtn>
        </VCardActions>
      </VCard>
    </VDialog>
  </div>
</template>

<style scoped>
.ps-bar {
  border-inline-start: 4px solid rgb(var(--v-theme-primary));
}

/* Jadwal nonaktif tetap terlihat, tetapi jelas tidak sedang berlaku. */
.ps-card-inactive {
  opacity: 0.65;
}
</style>
