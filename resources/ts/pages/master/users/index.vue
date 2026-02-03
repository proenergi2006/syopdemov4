<script setup lang="ts">
import axios from '@axios';
import { computed, ref, watch, watchEffect } from 'vue';

// =====================
// Types
// =====================
type Role = { id: number; nama: string; kode?: string }
type Cabang = { id: number; nama: string }
type Departemen = { id: number; nama: string }

type UserRow = {
  id: number
  name: string
  email: string
  is_active: boolean
  cabang?: Cabang | null
  departemen?: Departemen | null
  roles?: Role[]
  role_names?: string[]
}

type UserForm = {
  id?: number
  name: string
  email: string
  is_active: boolean
  cabang_id: number | null
  departemen_id: number | null
  role_ids: number[]
  password: string
  password_confirmation: string
}

// =====================
// State (table + filters)
// =====================
const loading = ref(false)
const rows = ref<UserRow[]>([])

const searchQuery = ref('')
const selectedStatus = ref<string | null>(null)     // 'true' | 'false' | null
const selectedRoleId = ref<number | null>(null)     // filter by role_id

const rowPerPage = ref(10)
const currentPage = ref(1)
const totalPage = ref(1)
const totalRows = ref(0)

// =====================
// Options data
// =====================
const roleOptions = ref<Role[]>([])
const cabangOptions = ref<Cabang[]>([])
const departemenOptions = ref<Departemen[]>([])

// =====================
// Dialog & form
// =====================
const isDialogOpen = ref(false)
const isEdit = ref(false)
const formLoading = ref(false)
const formErrors = ref<Record<string, string>>({})

const form = ref<UserForm>({
  name: '',
  email: '',
  is_active: true,
  cabang_id: null,
  departemen_id: null,
  role_ids: [],
  password: '',
  password_confirmation: '',
})

// =====================
// Snackbar
// =====================
const snackbar = ref(false)
const snackText = ref('')
const snackColor = ref<'success' | 'error' | 'warning' | 'info'>('success')
const snackTimeout = ref(3000)

const notify = (
  text: string,
  color: 'success' | 'error' | 'warning' | 'info' = 'success',
  timeout = 3000,
) => {
  snackText.value = text
  snackColor.value = color
  snackTimeout.value = timeout
  snackbar.value = true
}

// =====================
// Delete confirm dialog
// =====================
const deleteDialog = ref(false)
const deleteLoading = ref(false)
const deleteTarget = ref<UserRow | null>(null)

const openDelete = (row: UserRow) => {
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
    await axios.delete(`/master/users/${deleteTarget.value.id}`)
    notify(`User "${deleteTarget.value.name}" berhasil dihapus`, 'success')
    closeDelete()
    fetchRows()
  } catch (e: any) {
    const res = e?.response
    console.error('[Users] DELETE ERROR:', res?.status, res?.data || e)
    notify(res?.data?.message || 'Gagal menghapus user', 'error')
  } finally {
    deleteLoading.value = false
  }
}

// =====================
// Fetch options
// =====================
const fetchOptions = async () => {
  try {
    // roles
    const rolesRes = await axios.get('/master/roles', { params: { per_page: 9999 } })
    roleOptions.value = Array.isArray(rolesRes.data?.data) ? rolesRes.data.data : []

    // cabang
    const cabangRes = await axios.get('/master/cabang', { params: { per_page: 9999 } })
    cabangOptions.value = Array.isArray(cabangRes.data?.data) ? cabangRes.data.data : []

    // departemen
    const deptRes = await axios.get('/master/departemen', { params: { per_page: 9999 } })
    departemenOptions.value = Array.isArray(deptRes.data?.data) ? deptRes.data.data : []
  } catch (e: any) {
    console.error('[Users] OPTIONS ERROR:', e?.response?.status, e?.response?.data || e)
  }
}

// =====================
// Fetch table rows
// =====================
const fetchRows = async () => {
  loading.value = true
  try {
    const params: any = {
      page: currentPage.value,
      per_page: rowPerPage.value,
    }

    if (searchQuery.value) params.search = searchQuery.value
    if (selectedStatus.value !== null) params.is_active = selectedStatus.value
    if (selectedRoleId.value !== null) params.role_id = selectedRoleId.value

    const { data } = await axios.get('/master/users', { params })

    rows.value = Array.isArray(data?.data) ? data.data : []
    totalRows.value = Number(data?.total ?? 0)

    const lastPage = Number(data?.last_page ?? 1)
    totalPage.value = lastPage > 0 ? lastPage : 1

    if (currentPage.value > totalPage.value)
      currentPage.value = totalPage.value
  } catch (e: any) {
    console.error('[Users] FETCH ERROR:', e?.response?.status, e?.response?.data || e)
    rows.value = []
    totalRows.value = 0
    totalPage.value = 1
  } finally {
    loading.value = false
  }
}

// =====================
// debounce search
// =====================
let t: any = null
watch(searchQuery, () => {
  clearTimeout(t)
  t = setTimeout(() => {
    currentPage.value = 1
    fetchRows()
  }, 400)
})

watch([selectedStatus, selectedRoleId, rowPerPage, currentPage], () => {
  fetchRows()
})

watchEffect(() => {
  fetchOptions()
  fetchRows()
})

// =====================
// Pagination text
// =====================
const paginationData = computed(() => {
  const firstIndex = totalRows.value ? ((currentPage.value - 1) * rowPerPage.value) + 1 : 0
  const lastIndex = rows.value.length + ((currentPage.value - 1) * rowPerPage.value)
  return `${firstIndex}-${lastIndex} of ${totalRows.value}`
})

// =====================
// Actions (create/edit)
// =====================
const openCreate = () => {
  isEdit.value = false
  formErrors.value = {}
  form.value = {
    name: '',
    email: '',
    is_active: true,
    cabang_id: null,
    departemen_id: null,
    role_ids: [],
    password: '',
    password_confirmation: '',
  }
  isDialogOpen.value = true
}

const openEdit = (row: UserRow) => {
  isEdit.value = true
  formErrors.value = {}

  const roleIds = Array.isArray(row.roles) ? row.roles.map(r => r.id) : []

  form.value = {
    id: row.id,
    name: row.name,
    email: row.email,
    is_active: row.is_active,
    cabang_id: row.cabang?.id ?? null,
    departemen_id: row.departemen?.id ?? null,
    role_ids: roleIds,
    password: '',
    password_confirmation: '',
  }

  isDialogOpen.value = true
}

const closeDialog = () => {
  isDialogOpen.value = false
}

// =====================
// Save (create/update)
// =====================
const save = async () => {
  formLoading.value = true
  formErrors.value = {}

  const payload: any = {
    name: form.value.name,
    email: form.value.email,
    is_active: form.value.is_active,
    cabang_id: form.value.cabang_id,
    departemen_id: form.value.departemen_id,
    role_ids: form.value.role_ids,
  }

  // password only if create OR user fills it on edit
  if (!isEdit.value || form.value.password) {
    payload.password = form.value.password
    payload.password_confirmation = form.value.password_confirmation
  }

  try {
    if (isEdit.value && form.value.id) {
      await axios.put(`/master/users/${form.value.id}`, payload)
      notify('User berhasil diperbarui', 'success')
    } else {
      await axios.post('/master/users', payload)
      notify('User berhasil ditambahkan', 'success')
    }

    isDialogOpen.value = false
    fetchRows()
  } catch (e: any) {
    const res = e?.response
    console.error('[Users] SAVE ERROR:', res?.status, res?.data || e)

    if (res?.status === 422 && res.data?.errors) {
      Object.keys(res.data.errors).forEach(k => {
        formErrors.value[k] = res.data.errors[k][0]
      })
      notify('Validasi gagal, cek input', 'warning')
      return
    }

    notify(res?.data?.message || 'Gagal menyimpan user', 'error')
  } finally {
    formLoading.value = false
  }
}

// =====================
// Helpers for display
// =====================
const roleText = (u: UserRow) => {
  if (Array.isArray(u.role_names) && u.role_names.length) return u.role_names.join(', ')
  if (Array.isArray(u.roles) && u.roles.length) return u.roles.map(r => r.nama).join(', ')
  return '-'
}
</script>

<template>
  <section>
    <!-- Filters -->
    <VCard title="Filters" class="mb-6">
      <VCardText>
        <VRow>
          <VCol cols="12" sm="4">
            <VTextField
              v-model="searchQuery"
              label="Search (name/email)"
              placeholder="Search (name/email)"
              density="compact"
              clearable
            />
          </VCol>

          <VCol cols="12" sm="4">
            <VSelect
              v-model="selectedRoleId"
              label="Role"
              :items="roleOptions"
              item-title="nama"
              item-value="id"
              density="compact"
              clearable
              clear-icon="mdi-close"
            />
          </VCol>

          <VCol cols="12" sm="4">
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

    <!-- Table -->
    <VCard>
      <VCardText class="d-flex flex-wrap gap-4 align-center">
        <VBtn color="primary" @click="openCreate">
          + Add User
        </VBtn>

        <VSpacer />

        <VChip v-if="loading" size="small" variant="tonal">Loading...</VChip>
      </VCardText>

      <VDivider />

      <VTable class="text-no-wrap">
        <thead>
          <tr>
            <th scope="col">NAME</th>
            <th scope="col">EMAIL</th>
            <th scope="col">ROLE</th>
            <th scope="col">CABANG</th>
            <th scope="col">DEPARTEMEN</th>
            <th scope="col">STATUS</th>
            <th scope="col" class="text-center" style="width: 5rem;">ACTIONS</th>
          </tr>
        </thead>

        <tbody>
          <tr v-for="u in rows" :key="u.id">
            <td class="text-medium-emphasis">{{ u.name }}</td>
            <td class="text-medium-emphasis">{{ u.email }}</td>
            <td class="text-medium-emphasis">{{ roleText(u) }}</td>
            <td class="text-medium-emphasis">{{ u.cabang?.nama ?? '-' }}</td>
            <td class="text-medium-emphasis">{{ u.departemen?.nama ?? '-' }}</td>
            <td>
              <VChip :color="u.is_active ? 'success' : 'secondary'" size="small" class="text-capitalize">
                {{ u.is_active ? 'active' : 'inactive' }}
              </VChip>
            </td>

            <td class="text-center" style="width: 5rem;">
              <VBtn size="x-small" color="default" variant="plain" icon>
                <VIcon size="24" icon="mdi-dots-vertical" />
                <VMenu activator="parent">
                  <VList>
                    <VListItem href="javascript:void(0)" @click="openEdit(u)">
                      <template #prepend>
                        <VIcon icon="mdi-pencil-outline" :size="20" class="me-3" />
                      </template>
                      <VListItemTitle>Edit</VListItemTitle>
                    </VListItem>

                    <VListItem href="javascript:void(0)" @click="openDelete(u)">
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
            <td colspan="7" class="text-center">No data available</td>
          </tr>
        </tfoot>
      </VTable>

      <VDivider />

      <!-- Footer pagination -->
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

    <!-- Dialog Form -->
    <VDialog v-model="isDialogOpen" max-width="580">
      <VCard>
        <VCardTitle class="text-h6">
          {{ isEdit ? 'Edit User' : 'Add User' }}
        </VCardTitle>

        <VCardText class="d-flex flex-column gap-3">
          <VTextField
            v-model="form.name"
            label="Name"
            :error-messages="formErrors.name"
          />

          <VTextField
            v-model="form.email"
            label="Email"
            type="email"
            :error-messages="formErrors.email"
          />

          <VRow>
            <VCol cols="12" sm="6">
              <VSelect
                v-model="form.cabang_id"
                label="Cabang"
                :items="cabangOptions"
                item-title="nama"
                item-value="id"
                clearable
                density="compact"
                :error-messages="formErrors.cabang_id"
              />
            </VCol>

            <VCol cols="12" sm="6">
              <VSelect
                v-model="form.departemen_id"
                label="Departemen"
                :items="departemenOptions"
                item-title="nama"
                item-value="id"
                clearable
                density="compact"
                :error-messages="formErrors.departemen_id"
              />
            </VCol>
          </VRow>

          <!-- ✅ Roles multi select (fix TS error: use :multiple="true") -->
          <VSelect
            v-model="form.role_ids"
            label="Roles"
            :items="roleOptions"
            item-title="nama"
            item-value="id"
            :multiple="true"
            chips
            closable-chips
            clearable
            density="compact"
            :error-messages="formErrors.role_ids"
          />

          <VSwitch
            v-model="form.is_active"
            label="Active"
            inset
          />

          <!-- Password: required on create, optional on edit -->
          <VAlert v-if="isEdit" type="info" variant="tonal" density="compact">
            Leave password blank if you don’t want to change it.
          </VAlert>

          <VRow>
            <VCol cols="12" sm="6">
              <VTextField
                v-model="form.password"
                label="Password"
                type="password"
                :error-messages="formErrors.password"
              />
            </VCol>

            <VCol cols="12" sm="6">
              <VTextField
                v-model="form.password_confirmation"
                label="Confirm Password"
                type="password"
                :error-messages="formErrors.password_confirmation"
              />
            </VCol>
          </VRow>
        </VCardText>

        <VCardActions class="justify-end">
          <VBtn variant="text" :disabled="formLoading" @click="closeDialog">Cancel</VBtn>
          <VBtn color="primary" :loading="formLoading" @click="save">
            Save
          </VBtn>
        </VCardActions>
      </VCard>
    </VDialog>

    <!-- Delete Dialog -->
    <VDialog v-model="deleteDialog" max-width="520">
      <VCard>
        <VCardTitle class="text-h6">Confirm Delete</VCardTitle>
        <VCardText>
          Are you sure you want to delete user
          <b>{{ deleteTarget?.name }}</b> ({{ deleteTarget?.email }})?
          <div class="text-body-2 opacity-70 mt-2">
            This action cannot be undone.
          </div>
        </VCardText>

        <VCardActions class="justify-end">
          <VBtn variant="text" :disabled="deleteLoading" @click="closeDelete">Cancel</VBtn>
          <VBtn color="error" :loading="deleteLoading" @click="confirmDelete">Delete</VBtn>
        </VCardActions>
      </VCard>
    </VDialog>

    <!-- Snackbar -->
    <VSnackbar
      v-model="snackbar"
      :timeout="snackTimeout"
      :color="snackColor"
      location="top end"
    >
      {{ snackText }}

      <template #actions>
        <VBtn variant="text" @click="snackbar = false">Close</VBtn>
      </template>
    </VSnackbar>
  </section>
</template>

<style lang="scss">
.text-capitalize { text-transform: capitalize; }
</style>

<style lang="scss" scoped>
.user-pagination-select {
  .v-field__input,
  .v-field__append-inner {
    padding-block-start: 0.3rem;
  }
}
</style>
