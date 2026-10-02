<script setup lang="ts">
import { computed, onMounted, reactive, ref, watch } from 'vue'
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
| Master Kategori Biaya Perdin
|--------------------------------------------------------------------------
| Transportasi, Penginapan, Uang Saku -- pengelompokan rincian FPU dan
| Realisasi yang keterangan transaksinya Perjalanan Dinas.
|
| Berlabel dua bahasa, berbeda dari halaman master lain yang masih memakai
| Bahasa Indonesia langsung di templatnya. Perbedaannya disengaja dan
| diminta; halaman master lain belum ikut.
|--------------------------------------------------------------------------
*/

interface CategoryRow {
  id: number
  name: string
  description: string | null
  sort_order: number
  is_active: boolean

  /*
  | Sudah dipakai baris rincian mana pun. Dipakai mematikan tombol hapusnya
  | sebelum ditekan -- penjagaan sebenarnya tetap di server.
  */
  in_use: boolean
}

interface Abilities {
  can_create: boolean
  can_update: boolean
  can_delete: boolean
}

const { t } = useI18n()
const permissionStore = usePermissionStore()

const canView = computed(() => permissionStore.can('business_trip_expense_category.view'))

const rows = ref<CategoryRow[]>([])
const loading = ref(false)
const loadError = ref('')

const abilities = ref<Abilities>({
  can_create: false,
  can_update: false,
  can_delete: false,
})

const searchQuery = ref('')
const statusFilter = ref<string | null>(null)

const statusOptions = computed(() => [
  { title: t('businessTripExpenseCategory.statusAll'), value: null },
  { title: t('businessTripExpenseCategory.statusActive'), value: 'active' },
  { title: t('businessTripExpenseCategory.statusInactive'), value: 'inactive' },
])

const fetchRows = async (): Promise<void> => {
  if (!canView.value)
    return

  loading.value = true
  loadError.value = ''

  try {
    const response = await axios.get('/fund-request/business-trip-expense-categories', {
      params: {
        search: searchQuery.value || undefined,
        status: statusFilter.value || undefined,
      },
      headers: { Accept: 'application/json' },
    })

    rows.value = Array.isArray(response.data?.data) ? response.data.data : []

    abilities.value = {
      can_create: false,
      can_update: false,
      can_delete: false,
      ...(response.data?.abilities || {}),
    }
  }
  catch (error: unknown) {
    rows.value = []
    loadError.value = getApiErrorMessage(
      error,
      t('businessTripExpenseCategory.loadFailed'),
    )
  }
  finally {
    loading.value = false
  }
}

/*
| Saringannya diminta ulang ke server, bukan disaring di layar: daftarnya
| memang pendek, tetapi menyaring di dua tempat berarti dua aturan yang suatu
| hari berbeda jawabannya.
*/
let searchTimer: ReturnType<typeof setTimeout> | null = null

watch(searchQuery, () => {
  if (searchTimer)
    clearTimeout(searchTimer)

  searchTimer = setTimeout(() => fetchRows(), 350)
})

watch(statusFilter, () => fetchRows())

/*
|--------------------------------------------------------------------------
| Formulir
|--------------------------------------------------------------------------
*/
const dialog = ref(false)
const saving = ref(false)
const formError = ref('')
const editingId = ref<number | null>(null)

const form = reactive({
  name: '',
  description: '',
  sort_order: 0,
  is_active: true,
})

const dialogTitle = computed(() => editingId.value
  ? t('businessTripExpenseCategory.form.editTitle')
  : t('businessTripExpenseCategory.form.createTitle'))

const openCreate = (): void => {
  editingId.value = null

  form.name = ''
  form.description = ''

  /*
  | Urutan baru ditaruh sepuluh di belakang yang terakhir, bukan nol --
  | supaya kategori baru muncul di bawah, bukan menyelinap ke paling atas.
  */
  form.sort_order = rows.value.length
    ? Math.max(...rows.value.map(row => row.sort_order)) + 10
    : 10

  form.is_active = true

  formError.value = ''
  dialog.value = true
}

const openEdit = (row: CategoryRow): void => {
  editingId.value = row.id

  form.name = row.name
  form.description = row.description || ''
  form.sort_order = row.sort_order
  form.is_active = row.is_active

  formError.value = ''
  dialog.value = true
}

const submitForm = async (): Promise<void> => {
  if (!form.name.trim()) {
    formError.value = t('businessTripExpenseCategory.form.name')

    return
  }

  saving.value = true
  formError.value = ''

  try {
    const payload = {
      name: form.name.trim(),
      description: form.description.trim() || null,
      sort_order: Number(form.sort_order) || 0,
      is_active: form.is_active,
    }

    const response = editingId.value
      ? await axios.put(
        `/fund-request/business-trip-expense-categories/${editingId.value}`,
        payload,
      )
      : await axios.post('/fund-request/business-trip-expense-categories', payload)

    dialog.value = false

    showSuccessToast({
      title: t('common.alert.success'),
      text: response.data?.message || '',
    })

    await fetchRows()
  }
  catch (error: unknown) {
    /*
    | Pesannya di dalam dialognya: formulirnya masih terbuka dan masih
    | berisi, dan pesan yang lewat di sudut layar akan hilang sebelum yang
    | mengisi selesai membacanya.
    */
    formError.value = getApiErrorMessage(error, '')
  }
  finally {
    saving.value = false
  }
}

const toggleStatus = async (row: CategoryRow): Promise<void> => {
  try {
    showLoadingAlert(t('common.alert.processing'), t('common.alert.pleaseWait'))

    const response = await axios.patch(
      `/fund-request/business-trip-expense-categories/${row.id}/toggle-status`,
    )

    closeAlert()

    showSuccessToast({
      title: t('common.alert.success'),
      text: response.data?.message || '',
    })

    await fetchRows()
  }
  catch (error: unknown) {
    closeAlert()

    showErrorToast({
      title: t('common.alert.error'),
      text: getApiErrorMessage(error, ''),
    })
  }
}

const removeRow = async (row: CategoryRow): Promise<void> => {
  const konfirmasi = await showConfirmAlert({
    title: t('businessTripExpenseCategory.delete.title'),
    text: t('businessTripExpenseCategory.delete.text', { name: row.name }),
    confirmButtonText: t('businessTripExpenseCategory.delete.confirm'),
    cancelButtonText: t('businessTripExpenseCategory.form.cancel'),
  })

  if (!konfirmasi.isConfirmed)
    return

  try {
    showLoadingAlert(t('common.alert.processing'), t('common.alert.pleaseWait'))

    const response = await axios.delete(
      `/fund-request/business-trip-expense-categories/${row.id}`,
    )

    closeAlert()

    showSuccessToast({
      title: t('common.alert.success'),
      text: response.data?.message || '',
    })

    await fetchRows()
  }
  catch (error: unknown) {
    closeAlert()

    showErrorToast({
      title: t('common.alert.error'),
      text: getApiErrorMessage(error, ''),
    })
  }
}

onMounted(() => fetchRows())
</script>

<template>
  <section>
    <VCard class="mb-4">
      <VCardText>
        <div class="d-flex flex-wrap align-center justify-space-between gap-3">
          <div>
            <div class="text-h6 font-weight-bold">
              {{ t('businessTripExpenseCategory.title') }}
            </div>

            <div class="text-body-2 text-medium-emphasis mt-1">
              {{ t('businessTripExpenseCategory.subtitle') }}
            </div>
          </div>

          <VBtn
            v-if="abilities.can_create"
            color="primary"
            prepend-icon="tabler-plus"
            class="text-none"
            @click="openCreate"
          >
            {{ t('businessTripExpenseCategory.addButton') }}
          </VBtn>
        </div>
      </VCardText>
    </VCard>

    <VCard>
      <VCardText class="d-flex flex-wrap gap-4">
        <VTextField
          v-model="searchQuery"
          :placeholder="t('businessTripExpenseCategory.search')"
          density="compact"
          prepend-inner-icon="tabler-search"
          clearable
          hide-details
          style="max-inline-size: 320px;"
        />

        <VSelect
          v-model="statusFilter"
          :items="statusOptions"
          density="compact"
          hide-details
          style="max-inline-size: 200px;"
        />
      </VCardText>

      <VDivider />

      <VAlert
        v-if="loadError"
        type="error"
        variant="tonal"
        density="compact"
        class="ma-4"
      >
        {{ loadError }}
      </VAlert>

      <VTable density="comfortable">
        <thead>
          <tr>
            <th style="inline-size: 90px;">
              {{ t('businessTripExpenseCategory.columns.order') }}
            </th>
            <th>{{ t('businessTripExpenseCategory.columns.name') }}</th>
            <th>{{ t('businessTripExpenseCategory.columns.description') }}</th>
            <th style="inline-size: 120px;">
              {{ t('businessTripExpenseCategory.columns.status') }}
            </th>
            <th
              class="text-end"
              style="inline-size: 140px;"
            >
              {{ t('businessTripExpenseCategory.columns.actions') }}
            </th>
          </tr>
        </thead>

        <tbody>
          <tr v-if="loading">
            <td
              colspan="5"
              class="text-center py-6"
            >
              <VProgressCircular
                indeterminate
                size="28"
              />
            </td>
          </tr>

          <tr v-else-if="!rows.length">
            <td
              colspan="5"
              class="text-center text-medium-emphasis py-6"
            >
              {{ t('businessTripExpenseCategory.empty') }}
            </td>
          </tr>

          <tr
            v-for="row in rows"
            v-else
            :key="`kategori-${row.id}`"
          >
            <td class="text-medium-emphasis">
              {{ row.sort_order }}
            </td>

            <td class="font-weight-medium">
              {{ row.name }}
            </td>

            <td class="text-body-2 text-medium-emphasis">
              {{ row.description || '-' }}
            </td>

            <td>
              <VChip
                size="small"
                variant="tonal"
                :color="row.is_active ? 'success' : 'secondary'"
              >
                {{ row.is_active
                  ? t('businessTripExpenseCategory.statusActive')
                  : t('businessTripExpenseCategory.statusInactive') }}
              </VChip>
            </td>

            <td>
              <!--
                Tombol ikon bertooltip, berjajar satu baris -- bentuk yang sama
                dengan master Keterangan Transaksi di sebelahnya. Tiga tombol
                berteks tidak muat di kolom ini dan menumpuk ke bawah.
              -->
              <div class="d-flex align-center justify-end flex-nowrap gap-1">
                <VBtn
                  v-if="abilities.can_update"
                  icon
                  size="small"
                  variant="text"
                  color="primary"
                  @click="openEdit(row)"
                >
                  <VIcon icon="tabler-pencil" />

                  <VTooltip
                    activator="parent"
                    location="top"
                  >
                    {{ t('common.actions.edit') }}
                  </VTooltip>
                </VBtn>

                <VBtn
                  v-if="abilities.can_update"
                  icon
                  size="small"
                  variant="text"
                  :color="row.is_active ? 'warning' : 'success'"
                  @click="toggleStatus(row)"
                >
                  <VIcon :icon="row.is_active ? 'tabler-circle-off' : 'tabler-circle-check'" />

                  <VTooltip
                    activator="parent"
                    location="top"
                  >
                    {{ row.is_active
                      ? t('businessTripExpenseCategory.toggle.deactivate')
                      : t('businessTripExpenseCategory.toggle.activate') }}
                  </VTooltip>
                </VBtn>

                <!--
                  Yang sudah dipakai rincian dokumen tidak bisa dihapus, dan
                  sebabnya dikatakan di tooltipnya sendiri -- bukan baru muncul
                  sesudah tombolnya ditekan.
                -->
                <VBtn
                  v-if="abilities.can_delete"
                  icon
                  size="small"
                  variant="text"
                  color="error"
                  :disabled="row.in_use"
                  @click="removeRow(row)"
                >
                  <VIcon icon="tabler-trash" />

                  <VTooltip
                    activator="parent"
                    location="top"
                  >
                    {{ row.in_use
                      ? t('businessTripExpenseCategory.delete.inUseTooltip')
                      : t('businessTripExpenseCategory.delete.action') }}
                  </VTooltip>
                </VBtn>
              </div>
            </td>
          </tr>
        </tbody>
      </VTable>
    </VCard>

    <!-- TAMBAH / UBAH -->
    <VDialog
      v-model="dialog"
      max-width="560"
    >
      <VCard>
        <VCardTitle class="text-h6 font-weight-bold">
          {{ dialogTitle }}
        </VCardTitle>

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
                :label="t('businessTripExpenseCategory.form.name')"
                :hint="t('businessTripExpenseCategory.form.nameHint')"
                persistent-hint
                density="comfortable"
                autofocus
              />
            </VCol>

            <VCol cols="12">
              <VTextarea
                v-model="form.description"
                :label="t('businessTripExpenseCategory.form.description')"
                rows="2"
                density="comfortable"
              />
            </VCol>

            <VCol cols="12">
              <VTextField
                v-model.number="form.sort_order"
                :label="t('businessTripExpenseCategory.form.sortOrder')"
                :hint="t('businessTripExpenseCategory.form.sortOrderHint')"
                persistent-hint
                type="number"
                min="0"
                density="comfortable"
              />
            </VCol>

            <VCol cols="12">
              <VSwitch
                v-model="form.is_active"
                color="primary"
                :label="t('businessTripExpenseCategory.form.active')"
                hide-details
              />

              <div class="text-caption text-medium-emphasis mt-1">
                {{ t('businessTripExpenseCategory.form.activeHint') }}
              </div>
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
            {{ t('businessTripExpenseCategory.form.cancel') }}
          </VBtn>

          <VBtn
            color="primary"
            class="text-none"
            :loading="saving"
            @click="submitForm"
          >
            {{ t('businessTripExpenseCategory.form.save') }}
          </VBtn>
        </VCardActions>
      </VCard>
    </VDialog>
  </section>
</template>
