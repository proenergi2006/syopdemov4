<script setup lang="ts">
import axios from '@axios'
import { computed, ref, watch, watchEffect } from 'vue'

type RoleRow = {
  id: number
  kode: string
  nama: string
  is_active: boolean
}

type RoleForm = {
  id?: number
  kode: string
  nama: string
  is_active: boolean
}

const loading = ref(false)
const rows = ref<RoleRow[]>([])

const searchQuery = ref('')
const selectedStatus = ref<string | null>(null)

const rowPerPage = ref(10)
const currentPage = ref(1)
const totalPage = ref(1)
const totalRows = ref(0)

// dialog
const isDialogOpen = ref(false)
const isEdit = ref(false)
const formLoading = ref(false)
const formErrors = ref<Record<string, string>>({})

const form = ref<RoleForm>({
  kode: '',
  nama: '',
  is_active: true,
})

// snackbar
const snackbar = ref(false)
const snackText = ref('')
const snackColor = ref<'success' | 'error' | 'warning' | 'info'>('success')
const snackTimeout = ref(3000)

const notify = (text: string, color: 'success'|'error'|'warning'|'info'='success', timeout=3000) => {
  snackText.value = text
  snackColor.value = color
  snackTimeout.value = timeout
  snackbar.value = true
}

// delete dialog
const deleteDialog = ref(false)
const deleteLoading = ref(false)
const deleteTarget = ref<RoleRow | null>(null)

const openDelete = (row: RoleRow) => {
  deleteTarget.value = row
  deleteDialog.value = true
}
const closeDelete = () => {
  deleteDialog.value = false
  deleteTarget.value = null
}
const confirmDelete = async () => {
  if (!deleteTarget.value) return
  deleteLoading.value = true
  try {
    await axios.delete(`/master/roles/${deleteTarget.value.id}`)
    notify(`Role "${deleteTarget.value.nama}" deleted`, 'success')
    closeDelete()
    fetchRows()
  } catch (e: any) {
    const res = e?.response
    console.error('[Roles] DELETE ERROR:', res?.status, res?.data || e)
    notify(res?.data?.message || 'Failed to delete role', 'error')
  } finally {
    deleteLoading.value = false
  }
}

// fetch
const fetchRows = async () => {
  loading.value = true
  try {
    const params: any = {
      page: currentPage.value,
      per_page: rowPerPage.value,
    }
    if (searchQuery.value) params.search = searchQuery.value
    if (selectedStatus.value !== null) params.is_active = selectedStatus.value

    const { data } = await axios.get('/master/roles', { params })

    rows.value = Array.isArray(data?.data) ? data.data : []
    totalRows.value = Number(data?.total ?? 0)

    const lastPage = Number(data?.last_page ?? 1)
    totalPage.value = lastPage > 0 ? lastPage : 1

    if (currentPage.value > totalPage.value)
      currentPage.value = totalPage.value
  } catch (e: any) {
    console.error('[Roles] FETCH ERROR:', e?.response?.status, e?.response?.data || e)
    rows.value = []
    totalRows.value = 0
    totalPage.value = 1
  } finally {
    loading.value = false
  }
}

let t: any = null
watch(searchQuery, () => {
  clearTimeout(t)
  t = setTimeout(() => {
    currentPage.value = 1
    fetchRows()
  }, 400)
})

watch([selectedStatus, rowPerPage, currentPage], () => fetchRows())
watchEffect(() => fetchRows())

const paginationData = computed(() => {
  const firstIndex = totalRows.value ? ((currentPage.value - 1) * rowPerPage.value) + 1 : 0
  const lastIndex = rows.value.length + ((currentPage.value - 1) * rowPerPage.value)
  return `${firstIndex}-${lastIndex} of ${totalRows.value}`
})

// actions
const openCreate = () => {
  isEdit.value = false
  formErrors.value = {}
  form.value = { kode: '', nama: '', is_active: true }
  isDialogOpen.value = true
}

const openEdit = (row: RoleRow) => {
  isEdit.value = true
  formErrors.value = {}
  form.value = { id: row.id, kode: row.kode, nama: row.nama, is_active: row.is_active }
  isDialogOpen.value = true
}

const closeDialog = () => { isDialogOpen.value = false }

const save = async () => {
  formLoading.value = true
  formErrors.value = {}

  const payload = {
    kode: form.value.kode,
    nama: form.value.nama,
    is_active: form.value.is_active,
  }

  try {
    if (isEdit.value && form.value.id) {
      await axios.put(`/master/roles/${form.value.id}`, payload)
      notify('Role updated', 'success')
    } else {
      await axios.post('/master/roles', payload)
      notify('Role created', 'success')
    }

    isDialogOpen.value = false
    fetchRows()
  } catch (e: any) {
    const res = e?.response
    console.error('[Roles] SAVE ERROR:', res?.status, res?.data || e)

    if (res?.status === 422 && res.data?.errors) {
      Object.keys(res.data.errors).forEach(k => {
        formErrors.value[k] = res.data.errors[k][0]
      })
      notify('Validation failed', 'warning')
      return
    }

    notify(res?.data?.message || 'Failed to save role', 'error')
  } finally {
    formLoading.value = false
  }
}
</script>

<template>
  <section>
    <VCard title="Filters" class="mb-6">
      <VCardText>
        <VRow>
          <VCol cols="12" sm="6">
            <VTextField
              v-model="searchQuery"
              label="Search (kode/nama)"
              placeholder="Search (kode/nama)"
              density="compact"
              clearable
            />
          </VCol>

          <VCol cols="12" sm="6">
            <VSelect
              v-model="selectedStatus"
              label="Status"
              :items="[
                { title: 'All', value: null },
                { title: 'Active', value: 'true' },
                { title: 'Inactive', value: 'false' },
              ]"
              item-title="title"
              item-value="value"
              density="compact"
              clearable
              clear-icon="mdi-close"
            />
          </VCol>
        </VRow>
      </VCardText>
    </VCard>

    <VCard>
      <VCardText class="d-flex flex-wrap gap-4 align-center">
        <VBtn color="primary" @click="openCreate">
          + Add Role
        </VBtn>

        <VSpacer />

        <VChip v-if="loading" size="small" variant="tonal">Loading...</VChip>
      </VCardText>

      <VDivider />

      <VTable class="text-no-wrap">
        <thead>
          <tr>
            <th scope="col">KODE</th>
            <th scope="col">NAMA</th>
            <th scope="col">STATUS</th>
            <th scope="col" class="text-center" style="width: 5rem;">ACTIONS</th>
          </tr>
        </thead>

        <tbody>
          <tr v-for="r in rows" :key="r.id">
            <td class="text-medium-emphasis">{{ r.kode }}</td>
            <td class="text-medium-emphasis">{{ r.nama }}</td>
            <td>
              <VChip :color="r.is_active ? 'success' : 'secondary'" size="small" class="text-capitalize">
                {{ r.is_active ? 'active' : 'inactive' }}
              </VChip>
            </td>

            <td class="text-center" style="width: 5rem;">
              <VBtn size="x-small" color="default" variant="plain" icon>
                <VIcon size="24" icon="mdi-dots-vertical" />
                <VMenu activator="parent">
                  <VList>
                    <VListItem href="javascript:void(0)" @click="openEdit(r)">
                      <template #prepend>
                        <VIcon icon="mdi-pencil-outline" :size="20" class="me-3" />
                      </template>
                      <VListItemTitle>Edit</VListItemTitle>
                    </VListItem>

                    <VListItem href="javascript:void(0)" @click="openDelete(r)">
                      <template #prepend>
                        <VIcon icon="mdi-delete-outline" :size="20" class="me-3" />
                      </template>
                      <VListItemTitle>Delete</VListItemTitle>
                    </VListItem>
                  </VList>
                </VMenu>
              </VBtn>
            </td>
          </tr>
        </tbody>

        <tfoot v-show="!rows.length && !loading">
          <tr>
            <td colspan="4" class="text-center">No data available</td>
          </tr>
        </tfoot>
      </VTable>

      <VDivider />

      <VCardText class="d-flex align-center flex-wrap justify-end gap-4 pa-2">
        <div class="d-flex align-center me-3" style="width: 200px;">
          <span class="text-no-wrap me-3">Rows per page:</span>

          <VSelect
            v-model="rowPerPage"
            density="compact"
            variant="plain"
            class="user-pagination-select"
            :items="[10, 20, 30, 50]"
          />
        </div>

        <div class="d-flex align-center">
          <h6 class="text-sm font-weight-regular">{{ paginationData }}</h6>

          <VPagination
            v-model="currentPage"
            size="small"
            :total-visible="1"
            :length="totalPage"
          />
        </div>
      </VCardText>
    </VCard>

    <!-- Form Dialog -->
    <VDialog v-model="isDialogOpen" max-width="520">
      <VCard>
        <VCardTitle class="text-h6">
          {{ isEdit ? 'Edit Role' : 'Add Role' }}
        </VCardTitle>

        <VCardText class="d-flex flex-column gap-3">
          <VTextField v-model="form.kode" label="Kode" :error-messages="formErrors.kode" />
          <VTextField v-model="form.nama" label="Nama" :error-messages="formErrors.nama" />
          <VSwitch v-model="form.is_active" label="Active" inset />
        </VCardText>

        <VCardActions class="justify-end">
          <VBtn variant="text" :disabled="formLoading" @click="closeDialog">Cancel</VBtn>
          <VBtn color="primary" :loading="formLoading" @click="save">Save</VBtn>
        </VCardActions>
      </VCard>
    </VDialog>

    <!-- Delete Dialog -->
    <VDialog v-model="deleteDialog" max-width="520">
      <VCard>
        <VCardTitle class="text-h6">Confirm Delete</VCardTitle>
        <VCardText>
          Are you sure you want to delete role <b>{{ deleteTarget?.nama }}</b>?
          <div class="text-body-2 opacity-70 mt-2">This action cannot be undone.</div>
        </VCardText>

        <VCardActions class="justify-end">
          <VBtn variant="text" :disabled="deleteLoading" @click="closeDelete">Cancel</VBtn>
          <VBtn color="error" :loading="deleteLoading" @click="confirmDelete">Delete</VBtn>
        </VCardActions>
      </VCard>
    </VDialog>

    <!-- Snackbar -->
    <VSnackbar v-model="snackbar" :timeout="snackTimeout" :color="snackColor" location="top end">
      {{ snackText }}
      <template #actions>
        <VBtn variant="text" @click="snackbar = false">Close</VBtn>
      </template>
    </VSnackbar>
  </section>
</template>

<style lang="scss" scoped>
.user-pagination-select {
  .v-field__input,
  .v-field__append-inner {
    padding-block-start: 0.3rem;
  }
}
</style>
