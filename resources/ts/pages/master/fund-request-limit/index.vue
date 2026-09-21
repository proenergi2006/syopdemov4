<script setup lang="ts">
import { computed, onMounted, reactive, ref } from 'vue'
import { useI18n } from 'vue-i18n'
import axios from '@axios'
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
| Master Batas Pengajuan FPU
|--------------------------------------------------------------------------
| Dua angka per baris: berapa FPU boleh berjalan bersamaan, dan berapa lama
| sebuah FPU boleh menunggu direalisasi.
|
| Cakupannya dua sumbu -- area dan department -- keduanya boleh dikosongkan.
| Yang paling khusus menang, sama seperti master jadwal pembayaran.
|--------------------------------------------------------------------------
*/

interface LimitRow {
  id: number
  name: string

  area_type: string | null
  area_type_label: string | null

  department_id: number | null
  department_name: string | null

  max_outstanding: number
  max_realization_days: number

  is_active: boolean
  notes: string | null

  /** Batas umum tidak boleh dihapus selama belum ada penggantinya. */
  is_fallback: boolean
}

interface Abilities {
  can_view: boolean
  can_create: boolean
  can_update: boolean
  can_delete: boolean
}

interface PilihanOpsi {
  value: string
  label: string
}

const { t } = useI18n()
const permissionStore = usePermissionStore()

const ENDPOINT = '/fund-request/fund-request-limits'

const rows = ref<LimitRow[]>([])
const loading = ref(false)

const abilities = ref<Abilities>({
  can_view: false,
  can_create: false,
  can_update: false,
  can_delete: false,
})

const areaTypes = ref<PilihanOpsi[]>([])
const departments = ref<{ id: number; name: string }[]>([])
const maxOutstandingCap = ref(50)
const maxDaysCap = ref(365)

const canView = computed(() => permissionStore.can('master_fund_request_limit.view'))

const fetchRows = async (): Promise<void> => {
  loading.value = true

  try {
    const response = await axios.get(ENDPOINT, {
      headers: { Accept: 'application/json' },
    })

    rows.value = response.data?.data ?? []
    abilities.value = response.data?.abilities ?? abilities.value

    const opsi = response.data?.options ?? {}

    areaTypes.value = opsi.area_types ?? []
    departments.value = opsi.departments ?? []
    maxOutstandingCap.value = Number(opsi.max_outstanding ?? 50)
    maxDaysCap.value = Number(opsi.max_days ?? 365)
  }
  catch (error: unknown) {
    showErrorToast({
      title: t('common.alert.error'),
      text: getApiErrorMessage(error, t('fundRequestLimit.list.loadFailed')),
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
*/
const dialog = ref(false)
const saving = ref(false)
const editingId = ref<number | null>(null)
const formError = ref('')

const form = reactive({
  name: '',
  area_type: null as string | null,
  department_id: null as number | null,
  max_outstanding: 3,
  max_realization_days: 14,
  is_active: true,
  notes: '',
})

const resetForm = (): void => {
  form.name = ''
  form.area_type = null
  form.department_id = null
  form.max_outstanding = 3
  form.max_realization_days = 14
  form.is_active = true
  form.notes = ''
  formError.value = ''
  editingId.value = null
}

const openCreate = (): void => {
  resetForm()
  dialog.value = true
}

const openEdit = (row: LimitRow): void => {
  resetForm()

  editingId.value = row.id
  form.name = row.name
  form.area_type = row.area_type
  form.department_id = row.department_id
  form.max_outstanding = row.max_outstanding
  form.max_realization_days = row.max_realization_days
  form.is_active = row.is_active
  form.notes = row.notes ?? ''

  dialog.value = true
}

/** Kalimat utuh cakupannya, supaya tidak perlu ditebak dari dua kolom kosong. */
const scopeLabel = (row: LimitRow): string => {
  if (row.is_fallback)
    return t('fundRequestLimit.scope.fallback')

  return [
    row.area_type_label ?? t('fundRequestLimit.scope.allAreas'),
    row.department_name ?? t('fundRequestLimit.scope.allDepartments'),
  ].join(' — ')
}

const save = async (): Promise<void> => {
  if (saving.value)
    return

  formError.value = ''

  if (!form.name.trim()) {
    formError.value = t('fundRequestLimit.form.nameRequired')

    return
  }

  saving.value = true

  try {
    showLoadingAlert(t('common.alert.processing'), t('common.alert.pleaseWait'))

    const payload = {
      name: form.name.trim(),
      area_type: form.area_type,
      department_id: form.department_id,
      max_outstanding: Number(form.max_outstanding),
      max_realization_days: Number(form.max_realization_days),
      is_active: form.is_active,
      notes: form.notes.trim() || null,
    }

    const response = editingId.value === null
      ? await axios.post(ENDPOINT, payload, { headers: { Accept: 'application/json' } })
      : await axios.put(`${ENDPOINT}/${editingId.value}`, payload, { headers: { Accept: 'application/json' } })

    closeAlert()

    dialog.value = false

    showSuccessToast({
      title: t('common.alert.success'),
      text: response.data?.message || t('fundRequestLimit.form.saveSuccess'),
    })

    await fetchRows()
  }
  catch (error: unknown) {
    closeAlert()

    formError.value = getApiErrorMessage(error, t('fundRequestLimit.form.saveFailed'))
  }
  finally {
    saving.value = false
  }
}

const remove = async (row: LimitRow): Promise<void> => {
  const konfirmasi = await showConfirmAlert({
    title: t('fundRequestLimit.delete.confirmTitle'),
    text: t('fundRequestLimit.delete.confirmText', { nama: row.name }),
    confirmButtonText: t('fundRequestLimit.delete.confirmButton'),
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
      text: response.data?.message || t('fundRequestLimit.delete.success'),
    })

    await fetchRows()
  }
  catch (error: unknown) {
    closeAlert()

    showErrorToast({
      title: t('common.alert.error'),
      text: getApiErrorMessage(error, t('fundRequestLimit.delete.failed')),
    })
  }
}

/*
|--------------------------------------------------------------------------
| Contoh penerapan
|--------------------------------------------------------------------------
| Cakupan bertingkat mudah disalahpahami saat diatur. Contoh ini menunjukkan
| batas mana yang akan dipakai untuk tiap area, sebelum ada pemohon sungguhan
| yang terlanjur terblokir olehnya.
|--------------------------------------------------------------------------
*/
const previewDialog = ref(false)
const previewLoading = ref(false)
const previewDepartment = ref<number | null>(null)

const previewRows = ref<{
  area_label: string
  limit_name: string | null
  max_outstanding: number | null
  max_realization_days: number | null
}[]>([])

const loadPreview = async (): Promise<void> => {
  previewLoading.value = true

  try {
    const response = await axios.get(`${ENDPOINT}/preview`, {
      params: { department_id: previewDepartment.value },
      headers: { Accept: 'application/json' },
    })

    previewRows.value = response.data?.data?.rows ?? []
  }
  catch (error: unknown) {
    showErrorToast({
      title: t('common.alert.error'),
      text: getApiErrorMessage(error, t('fundRequestLimit.preview.failed')),
    })
  }
  finally {
    previewLoading.value = false
  }
}

const openPreview = async (): Promise<void> => {
  previewDialog.value = true
  previewDepartment.value = null

  await loadPreview()
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
          {{ t('fundRequestLimit.noAccess') }}
        </div>
      </VCardText>
    </VCard>

    <template v-else>
      <!-- PENJELASAN SINGKAT -->
      <VCard class="mb-4 frl-bar">
        <VCardText class="d-flex align-start gap-3 py-4">
          <VAvatar
            size="40"
            color="warning"
            variant="tonal"
            rounded
          >
            <VIcon
              icon="tabler-hand-stop"
              size="22"
            />
          </VAvatar>

          <div class="min-w-0">
            <div class="text-body-1 font-weight-medium mb-1">
              {{ t('fundRequestLimit.intro.title') }}
            </div>

            <div class="text-body-2 text-medium-emphasis">
              {{ t('fundRequestLimit.intro.body') }}
            </div>

            <VAlert
              type="warning"
              variant="tonal"
              density="compact"
              class="mt-3"
            >
              {{ t('fundRequestLimit.intro.liveNotice') }}
            </VAlert>
          </div>
        </VCardText>
      </VCard>

      <!-- DAFTAR -->
      <VCard>
        <VCardItem>
          <VCardTitle>{{ t('fundRequestLimit.list.title') }}</VCardTitle>

          <VCardSubtitle>
            {{ t('fundRequestLimit.list.subtitle') }}
          </VCardSubtitle>

          <template #append>
            <div class="d-flex align-center gap-2">
              <VBtn
                variant="tonal"
                color="secondary"
                prepend-icon="tabler-eye"
                class="text-none"
                @click="openPreview"
              >
                {{ t('fundRequestLimit.list.previewButton') }}
              </VBtn>

              <VBtn
                v-if="abilities.can_create"
                color="primary"
                prepend-icon="tabler-plus"
                class="text-none"
                @click="openCreate"
              >
                {{ t('fundRequestLimit.list.addButton') }}
              </VBtn>
            </div>
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
            {{ t('fundRequestLimit.list.empty') }}
          </div>

          <VTable
            v-else
            class="text-no-wrap"
          >
            <thead>
              <tr>
                <th scope="col">
                  {{ t('fundRequestLimit.table.name') }}
                </th>
                <th scope="col">
                  {{ t('fundRequestLimit.table.scope') }}
                </th>
                <th
                  scope="col"
                  class="text-center"
                >
                  {{ t('fundRequestLimit.table.maxOutstanding') }}
                </th>
                <th
                  scope="col"
                  class="text-center"
                >
                  {{ t('fundRequestLimit.table.maxDays') }}
                </th>
                <th
                  scope="col"
                  class="text-center"
                >
                  {{ t('fundRequestLimit.table.status') }}
                </th>
                <th
                  scope="col"
                  class="text-end"
                >
                  {{ t('fundRequestLimit.table.action') }}
                </th>
              </tr>
            </thead>

            <tbody>
              <tr
                v-for="row in rows"
                :key="row.id"
                :class="{ 'frl-row-inactive': !row.is_active }"
              >
                <td>
                  <div class="font-weight-medium">
                    {{ row.name }}
                  </div>

                  <div
                    v-if="row.notes"
                    class="text-caption text-medium-emphasis"
                  >
                    {{ row.notes }}
                  </div>
                </td>

                <td>
                  <div class="d-flex align-center gap-2">
                    <span>{{ scopeLabel(row) }}</span>

                    <VChip
                      v-if="row.is_fallback"
                      size="x-small"
                      color="info"
                      variant="tonal"
                    >
                      {{ t('fundRequestLimit.list.fallbackChip') }}
                    </VChip>
                  </div>
                </td>

                <td class="text-center font-weight-medium">
                  {{ row.max_outstanding }}
                </td>

                <td class="text-center font-weight-medium">
                  {{ row.max_realization_days }}
                </td>

                <td class="text-center">
                  <VChip
                    size="x-small"
                    :color="row.is_active ? 'success' : 'secondary'"
                    variant="tonal"
                  >
                    {{ row.is_active ? t('fundRequestLimit.list.active') : t('fundRequestLimit.list.inactive') }}
                  </VChip>
                </td>

                <td class="text-end">
                  <VBtn
                    v-if="abilities.can_update"
                    size="small"
                    variant="text"
                    class="text-none"
                    prepend-icon="tabler-edit"
                    @click="openEdit(row)"
                  >
                    {{ t('fundRequestLimit.list.editButton') }}
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
                    {{ t('fundRequestLimit.list.deleteButton') }}
                  </VBtn>
                </td>
              </tr>
            </tbody>
          </VTable>
        </VCardText>
      </VCard>
    </template>

    <!-- FORMULIR -->
    <VDialog
      v-model="dialog"
      max-width="620"
      persistent
      scrollable
    >
      <VCard>
        <VCardItem>
          <VCardTitle>
            {{ editingId === null ? t('fundRequestLimit.form.createTitle') : t('fundRequestLimit.form.editTitle') }}
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
                :label="t('fundRequestLimit.form.name')"
                :placeholder="t('fundRequestLimit.form.namePlaceholder')"
                persistent-placeholder
                density="comfortable"
              />
            </VCol>

            <VCol
              cols="12"
              md="6"
            >
              <VSelect
                v-model="form.area_type"
                :label="t('fundRequestLimit.form.areaType')"
                :items="areaTypes"
                item-title="label"
                item-value="value"
                density="comfortable"
                clearable
                :placeholder="t('fundRequestLimit.scope.allAreas')"
                persistent-placeholder
                :hint="t('fundRequestLimit.form.areaTypeHint')"
                persistent-hint
              />
            </VCol>

            <VCol
              cols="12"
              md="6"
            >
              <VAutocomplete
                v-model="form.department_id"
                :label="t('fundRequestLimit.form.department')"
                :items="departments"
                item-title="name"
                item-value="id"
                density="comfortable"
                clearable
                :placeholder="t('fundRequestLimit.scope.allDepartments')"
                persistent-placeholder
                :hint="t('fundRequestLimit.form.departmentHint')"
                persistent-hint
              />
            </VCol>

            <VCol
              cols="12"
              md="6"
            >
              <VTextField
                v-model.number="form.max_outstanding"
                :label="t('fundRequestLimit.form.maxOutstanding')"
                type="number"
                min="1"
                :max="maxOutstandingCap"
                density="comfortable"
                :hint="t('fundRequestLimit.form.maxOutstandingHint')"
                persistent-hint
              />
            </VCol>

            <VCol
              cols="12"
              md="6"
            >
              <VTextField
                v-model.number="form.max_realization_days"
                :label="t('fundRequestLimit.form.maxDays')"
                type="number"
                min="1"
                :max="maxDaysCap"
                density="comfortable"
                :hint="t('fundRequestLimit.form.maxDaysHint')"
                persistent-hint
              />
            </VCol>

            <VCol cols="12">
              <VTextarea
                v-model="form.notes"
                :label="t('fundRequestLimit.form.notes')"
                rows="2"
                density="comfortable"
                :placeholder="t('fundRequestLimit.form.notesPlaceholder')"
                persistent-placeholder
              />
            </VCol>

            <VCol cols="12">
              <VSwitch
                v-model="form.is_active"
                :label="t('fundRequestLimit.form.isActive')"
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

    <!-- CONTOH PENERAPAN -->
    <VDialog
      v-model="previewDialog"
      max-width="560"
      scrollable
    >
      <VCard>
        <VCardItem>
          <VCardTitle>{{ t('fundRequestLimit.preview.title') }}</VCardTitle>

          <VCardSubtitle>{{ t('fundRequestLimit.preview.subtitle') }}</VCardSubtitle>
        </VCardItem>

        <VDivider />

        <VCardText>
          <VAutocomplete
            v-model="previewDepartment"
            :label="t('fundRequestLimit.form.department')"
            :items="departments"
            item-title="name"
            item-value="id"
            density="comfortable"
            clearable
            :placeholder="t('fundRequestLimit.scope.allDepartments')"
            persistent-placeholder
            class="mb-4"
            @update:model-value="loadPreview"
          />

          <VProgressLinear
            v-if="previewLoading"
            indeterminate
            color="primary"
            class="mb-4"
          />

          <VTable
            v-if="!previewLoading"
            density="compact"
          >
            <thead>
              <tr>
                <th scope="col">
                  {{ t('fundRequestLimit.table.scope') }}
                </th>
                <th scope="col">
                  {{ t('fundRequestLimit.preview.limitUsed') }}
                </th>
                <th
                  scope="col"
                  class="text-center"
                >
                  {{ t('fundRequestLimit.table.maxOutstanding') }}
                </th>
                <th
                  scope="col"
                  class="text-center"
                >
                  {{ t('fundRequestLimit.table.maxDays') }}
                </th>
              </tr>
            </thead>

            <tbody>
              <tr
                v-for="baris in previewRows"
                :key="baris.area_label"
              >
                <td>{{ baris.area_label }}</td>

                <td>
                  <span
                    v-if="baris.limit_name"
                    class="font-weight-medium"
                  >{{ baris.limit_name }}</span>

                  <span
                    v-else
                    class="text-error"
                  >{{ t('fundRequestLimit.preview.noLimit') }}</span>
                </td>

                <td class="text-center">
                  {{ baris.max_outstanding ?? '—' }}
                </td>

                <td class="text-center">
                  {{ baris.max_realization_days ?? '—' }}
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
.frl-bar {
  border-inline-start: 4px solid rgb(var(--v-theme-warning));
}

/* Batas nonaktif tetap terlihat, tetapi jelas tidak sedang berlaku. */
.frl-row-inactive {
  opacity: 0.55;
}
</style>
