<script setup lang="ts">
import { computed, onMounted, ref, watch } from 'vue'
import { useRouter } from 'vue-router'
import { VAutocomplete } from 'vuetify/components'
import axiosIns from '@/plugins/axios'
import { closeAlert, showErrorToast } from '@/utils/alert'
import { usePermissionStore } from '@/stores/permission'
import { usePolling } from '@core/composable/usePolling'

interface AxiosErrorShape {
  response?: {
    status?: number
    data?: {
      message?: string
    }
  }
}

interface ActivityCauser {
  id: number
  name: string
  username: string | null
}

interface ActivityItem {
  id: number
  log_name: string | null
  event: string | null
  description: string | null
  causer: ActivityCauser | null
  subject_type: string | null
  subject_id: number | null
  ip: string | null
  method: string | null
  path: string | null
  status_code: number | null
  created_at: string | null
  properties?: Record<string, unknown>
}

interface UserOption {
  id: number
  name: string
  email: string
  title: string
}

const router = useRouter()
const permissionStore = usePermissionStore()

const viewPermissionCode = 'activity_log.view'

const canView = computed(() => permissionStore.can(viewPermissionCode))

const isLoading = ref(false)
const isDetailLoading = ref(false)
const lastUpdatedAt = ref<Date | null>(null)

const activities = ref<ActivityItem[]>([])
const userOptions = ref<UserOption[]>([])
const logNameOptions = ref<string[]>([])
const eventOptions = ref<string[]>([])

const search = ref('')
const userFilter = ref<number | null>(null)
const eventFilter = ref<string | null>(null)
const logNameFilter = ref<string | null>(null)
const dateFrom = ref<string | null>(null)
const dateTo = ref<string | null>(null)

const page = ref(1)
const itemsPerPage = ref(25)
const totalItems = ref(0)
const totalPages = ref(1)

const itemsPerPageOptions = [
  { title: '25', value: 25 },
  { title: '50', value: 50 },
  { title: '100', value: 100 },
  { title: '200', value: 200 },
]

const detailDialog = ref(false)
const detailActivity = ref<ActivityItem | null>(null)

const isForbiddenResponse = (error: unknown): boolean => {
  const err = error as AxiosErrorShape

  return Number(err.response?.status ?? 0) === 403
}

const redirectToForbidden = async (): Promise<void> => {
  closeAlert()

  await router.replace('/forbidden')
}

const EVENT_COLORS: Record<string, string> = {
  login: 'success',
  logout: 'secondary',
  login_failed: 'error',
  login_blocked: 'error',
  session_expired: 'warning',
  approve: 'success',
  create: 'success',
  restore: 'success',
  submit: 'info',
  post: 'info',
  view: 'secondary',
  read: 'secondary',
  update: 'warning',
  edit: 'warning',
  toggle: 'warning',
  assign: 'warning',
  unassign: 'error',
  reject: 'error',
  cancel: 'error',
  delete: 'error',
  request: 'primary',
}

const EVENT_LABELS: Record<string, string> = {
  request: 'Request',
  request_failed: 'Request Gagal',
  login: 'Login',
  logout: 'Logout',
  login_failed: 'Login Gagal',
  login_blocked: 'Login Diblokir',
  session_expired: 'Sesi Berakhir',
  approve: 'Approve',
  reject: 'Reject',
  submit: 'Submit',
  post: 'Posting',
  cancel: 'Batalkan',
  create: 'Tambah',
  update: 'Ubah',
  edit: 'Ubah',
  delete: 'Hapus',
  toggle: 'Ubah Status',
  assign: 'Assign',
  unassign: 'Batalkan Assign',
  restore: 'Pulihkan',
  view: 'Lihat',
  read: 'Tandai Dibaca',
}

const eventColor = (event: string | null): string => {
  const value = String(event ?? '').toLowerCase()

  if (value.endsWith('_failed'))
    return 'error'

  return EVENT_COLORS[value] ?? 'primary'
}

const eventLabel = (event: string | null): string => {
  if (!event)
    return '-'

  const value = event.toLowerCase()

  if (EVENT_LABELS[value])
    return EVENT_LABELS[value]

  const base = value.endsWith('_failed') ? value.slice(0, -'_failed'.length) : value
  const suffix = value.endsWith('_failed') ? ' Gagal' : ''
  const baseLabel = EVENT_LABELS[base] ?? base
    .split('_')
    .map(word => word.charAt(0).toUpperCase() + word.slice(1))
    .join(' ')

  return baseLabel + suffix
}

const methodColor = (method: string | null): string => {
  const value = String(method ?? '').toUpperCase()

  if (value === 'POST')
    return 'success'

  if (value === 'PUT' || value === 'PATCH')
    return 'warning'

  if (value === 'DELETE')
    return 'error'

  return 'secondary'
}

const formatDateTime = (value: string | null): string => {
  if (!value)
    return '-'

  const date = new Date(value)

  if (Number.isNaN(date.getTime()))
    return String(value)

  const dd = String(date.getDate()).padStart(2, '0')
  const mm = String(date.getMonth() + 1).padStart(2, '0')
  const yyyy = date.getFullYear()
  const hh = String(date.getHours()).padStart(2, '0')
  const ii = String(date.getMinutes()).padStart(2, '0')
  const ss = String(date.getSeconds()).padStart(2, '0')

  return `${dd}/${mm}/${yyyy} ${hh}:${ii}:${ss}`
}

const formatHeaderTimestamp = (value: Date | null): string => {
  if (!value)
    return '-'

  return new Intl.DateTimeFormat('id-ID', {
    day: '2-digit',
    month: 'short',
    year: 'numeric',
    hour: '2-digit',
    minute: '2-digit',
  }).format(value)
}

const fetchActivities = async () => {
  isLoading.value = true

  try {
    const response = await axiosIns.get('/master/activity-log', {
      params: {
        search: search.value.trim() || undefined,
        user_id: userFilter.value || undefined,
        event: eventFilter.value || undefined,
        log_name: logNameFilter.value || undefined,
        date_from: dateFrom.value || undefined,
        date_to: dateTo.value || undefined,
        per_page: itemsPerPage.value,
        page: page.value,
      },
    })

    activities.value = response.data?.data ?? []
    totalItems.value = response.data?.meta?.total ?? 0
    totalPages.value = response.data?.meta?.last_page ?? 1
    lastUpdatedAt.value = new Date()
  }
  catch (error: any) {
    if (isForbiddenResponse(error)) {
      await redirectToForbidden()

      return
    }

    showErrorToast({
      title: 'Gagal Memuat Activity Log',
      text: error.response?.data?.message
        ?? 'Terjadi kesalahan saat memuat data activity log.',
    })
  }
  finally {
    isLoading.value = false
  }
}

const fetchUserOptions = async () => {
  try {
    const response = await axiosIns.get('/master/dropdown/users')

    userOptions.value = response.data?.data ?? response.data ?? []
  }
  catch {
    // Tidak perlu mengganggu proses utama.
  }
}

const fetchFilterOptions = async () => {
  try {
    const response = await axiosIns.get('/master/activity-log/filter-options')

    logNameOptions.value = response.data?.data?.log_names ?? []
    eventOptions.value = response.data?.data?.events ?? []
  }
  catch {
    // Tidak perlu mengganggu proses utama.
  }
}

const userFilterItems = computed(() => {
  return userOptions.value.map(user => ({
    title: user.title || user.name,
    value: user.id,
  }))
})

const eventFilterItems = computed(() => {
  return eventOptions.value.map(event => ({
    title: eventLabel(event),
    value: event,
  }))
})

const logNameFilterItems = computed(() => {
  return logNameOptions.value.map(logName => ({
    title: logName,
    value: logName,
  }))
})

const resetFilter = () => {
  search.value = ''
  userFilter.value = null
  eventFilter.value = null
  logNameFilter.value = null
  dateFrom.value = null
  dateTo.value = null
  itemsPerPage.value = 25
  page.value = 1
}

watch([search, userFilter, eventFilter, logNameFilter, dateFrom, dateTo, itemsPerPage], () => {
  page.value = 1
})

watch([search, userFilter, eventFilter, logNameFilter, dateFrom, dateTo, itemsPerPage, page], () => {
  fetchActivities()
})

const openDetail = async (item: ActivityItem) => {
  detailDialog.value = true
  detailActivity.value = item
  isDetailLoading.value = true

  try {
    const response = await axiosIns.get(`/master/activity-log/${item.id}`)

    detailActivity.value = response.data?.data ?? item
  }
  catch (error: any) {
    if (isForbiddenResponse(error)) {
      detailDialog.value = false
      await redirectToForbidden()

      return
    }

    showErrorToast({
      title: 'Gagal Memuat Detail',
      text: error.response?.data?.message
        ?? 'Terjadi kesalahan saat memuat detail activity log.',
    })
  }
  finally {
    isDetailLoading.value = false
  }
}

const detailPropertiesEntries = computed(() => {
  const properties = detailActivity.value?.properties ?? {}

  return Object.entries(properties).filter(([, value]) => value !== null && value !== undefined && value !== '')
})

onMounted(async () => {
  await permissionStore.loadPermissions(true)

  if (!canView.value) {
    await redirectToForbidden()

    return
  }

  await Promise.all([
    fetchActivities(),
    fetchUserOptions(),
    fetchFilterOptions(),
  ])
})

/*
|--------------------------------------------------------------------------
| Auto Refresh
|--------------------------------------------------------------------------
| Data activity log otomatis di-refresh setiap 30 detik selama halaman ini
| terbuka dan tab masih aktif, plus tetap bisa di-refresh manual lewat
| tombol "Perbarui". Fetch pertama sudah ditangani onMounted di atas
| (setelah permission dipastikan), jadi polling ini tidak langsung jalan.
|--------------------------------------------------------------------------
*/
usePolling(fetchActivities, {
  interval: 30000,
  immediate: false,
})
</script>

<template>
  <VRow>
    <VCol cols="12">
      <VCard>
        <VCardItem>
          <div class="d-flex flex-wrap align-center justify-space-between gap-4">
            <div>
              <VCardTitle class="text-h5">
                Activity Log
              </VCardTitle>

              <VCardSubtitle>
                Catatan aktivitas seluruh user, mulai dari login sampai logout.
              </VCardSubtitle>
            </div>

            <div class="text-md-end">
              <div class="text-caption text-medium-emphasis">
                Terakhir diperbarui
              </div>

              <div class="text-body-2 font-weight-medium">
                {{ formatHeaderTimestamp(lastUpdatedAt) }}
              </div>

              <VBtn
                size="small"
                variant="text"
                color="primary"
                prepend-icon="tabler-refresh"
                :loading="isLoading"
                class="mt-1 text-none"
                @click="fetchActivities"
              >
                Perbarui
              </VBtn>
            </div>
          </div>
        </VCardItem>

        <VDivider />

        <VCardText>
          <VRow>
            <VCol
              cols="12"
              md="4"
            >
              <VTextField
                v-model="search"
                label="Search"
                placeholder="Cari aktivitas atau nama user..."
                prepend-inner-icon="tabler-search"
                clearable
                density="comfortable"
              />
            </VCol>

            <VCol
              cols="12"
              sm="6"
              md="4"
            >
              <VAutocomplete
                v-model="userFilter"
                :items="userFilterItems"
                item-title="title"
                item-value="value"
                label="User"
                placeholder="Semua User"
                clearable
                density="comfortable"
                no-data-text="User tidak ditemukan"
                :menu-props="{ maxHeight: 320 }"
              />
            </VCol>

            <VCol
              cols="12"
              sm="6"
              md="4"
            >
              <VAutocomplete
                v-model="eventFilter"
                :items="eventFilterItems"
                item-title="title"
                item-value="value"
                label="Event"
                placeholder="Semua Event"
                clearable
                density="comfortable"
                no-data-text="Event tidak ditemukan"
                :menu-props="{ maxHeight: 320 }"
              />
            </VCol>

            <VCol
              cols="12"
              sm="6"
              md="3"
            >
              <VAutocomplete
                v-model="logNameFilter"
                :items="logNameFilterItems"
                item-title="title"
                item-value="value"
                label="Kategori"
                placeholder="Semua Kategori"
                clearable
                density="comfortable"
                no-data-text="Kategori tidak ditemukan"
                :menu-props="{ maxHeight: 320 }"
              />
            </VCol>

            <VCol
              cols="6"
              sm="3"
              md="3"
            >
              <VTextField
                v-model="dateFrom"
                type="date"
                label="Dari"
                density="comfortable"
              />
            </VCol>

            <VCol
              cols="6"
              sm="3"
              md="3"
            >
              <VTextField
                v-model="dateTo"
                type="date"
                label="Sampai"
                density="comfortable"
              />
            </VCol>

            <VCol
              cols="12"
              sm="6"
              md="3"
            >
              <VBtn
                color="secondary"
                variant="tonal"
                prepend-icon="tabler-refresh"
                block
                class="text-none"
                @click="resetFilter"
              >
                Reset Filter
              </VBtn>
            </VCol>
          </VRow>
        </VCardText>

        <VDivider />

        <VCardText class="pa-0">
          <VTable>
            <thead>
              <tr>
                <th
                  class="text-left"
                  style="min-width: 150px;"
                >
                  Waktu
                </th>
                <th
                  class="text-left"
                  style="min-width: 140px;"
                >
                  User
                </th>
                <th
                  class="text-left"
                  style="min-width: 260px;"
                >
                  Aktivitas
                </th>
                <th
                  class="text-left"
                  style="min-width: 120px;"
                >
                  Event
                </th>
                <th
                  class="text-left"
                  style="min-width: 220px;"
                >
                  Request
                </th>
                <th
                  class="text-left"
                  style="min-width: 110px;"
                >
                  IP
                </th>
                <th
                  class="text-center"
                  style="min-width: 80px;"
                >
                  Action
                </th>
              </tr>
            </thead>

            <tbody>
              <tr v-if="isLoading">
                <td
                  colspan="7"
                  class="text-center py-8"
                >
                  <VProgressCircular
                    indeterminate
                    color="primary"
                    class="me-2"
                  />
                  Memuat data activity log...
                </td>
              </tr>

              <tr v-else-if="activities.length === 0">
                <td
                  colspan="7"
                  class="text-center py-8 text-disabled"
                >
                  Belum ada data activity log.
                </td>
              </tr>

              <tr
                v-for="item in activities"
                v-else
                :key="item.id"
              >
                <td class="text-no-wrap">
                  {{ formatDateTime(item.created_at) }}
                </td>

                <td class="text-no-wrap">
                  <div v-if="item.causer">
                    <div class="font-weight-medium">
                      {{ item.causer.name }}
                    </div>

                    <div
                      v-if="item.causer.username"
                      class="text-caption text-disabled"
                    >
                      {{ item.causer.username }}
                    </div>
                  </div>

                  <span
                    v-else
                    class="text-disabled"
                  >
                    System
                  </span>
                </td>

                <td class="text-wrap">
                  {{ item.description || '-' }}
                </td>

                <td class="text-no-wrap">
                  <VChip
                    size="small"
                    variant="tonal"
                    :color="eventColor(item.event)"
                  >
                    {{ eventLabel(item.event) }}
                  </VChip>
                </td>

                <td class="text-wrap">
                  <div
                    v-if="item.method"
                    class="d-flex align-center gap-2 flex-wrap"
                  >
                    <VChip
                      size="x-small"
                      variant="tonal"
                      :color="methodColor(item.method)"
                    >
                      {{ item.method }}
                    </VChip>
                  </div>

                  <span
                    v-else
                    class="text-disabled"
                  >
                    -
                  </span>
                </td>

                <td class="text-no-wrap">
                  {{ item.ip || '-' }}
                </td>

                <td class="text-center">
                  <VBtn
                    icon
                    size="small"
                    variant="text"
                    color="primary"
                    class="text-none"
                    @click="openDetail(item)"
                  >
                    <VIcon icon="tabler-eye" />
                    <VTooltip
                      activator="parent"
                      location="top"
                    >
                      Lihat Detail
                    </VTooltip>
                  </VBtn>
                </td>
              </tr>
            </tbody>
          </VTable>
        </VCardText>

        <VDivider />

        <VCardText>
          <div class="d-flex align-center justify-space-between flex-wrap gap-4">
            <div class="text-body-2 text-disabled">
              Total {{ totalItems }} activity log
            </div>

            <VPagination
              v-model="page"
              :length="totalPages"
              total-visible="5"
            />

            <VSelect
              v-model="itemsPerPage"
              :items="itemsPerPageOptions"
              label="Tampilkan"
              density="compact"
              hide-details
              style="max-width: 110px;"
            />
          </div>
        </VCardText>
      </VCard>
    </VCol>
  </VRow>

  <VDialog
    v-model="detailDialog"
    max-width="640"
  >
    <VCard>
      <VCardItem>
        <VCardTitle>
          Detail Activity Log
        </VCardTitle>

        <VCardSubtitle>
          {{ detailActivity?.description || '-' }}
        </VCardSubtitle>

        <template #append>
          <VBtn
            icon
            variant="text"
            color="primary"
            class="text-none"
            @click="detailDialog = false"
          >
            <VIcon icon="tabler-x" />
          </VBtn>
        </template>
      </VCardItem>

      <VDivider />

      <VCardText>
        <VProgressCircular
          v-if="isDetailLoading"
          indeterminate
          color="primary"
          class="mb-4"
        />

        <VTable v-else-if="detailActivity">
          <tbody>
            <tr>
              <td class="text-medium-emphasis">
                Waktu
              </td>
              <td>{{ formatDateTime(detailActivity.created_at) }}</td>
            </tr>

            <tr>
              <td class="text-medium-emphasis">
                User
              </td>
              <td>{{ detailActivity.causer?.name || 'System' }}</td>
            </tr>

            <tr>
              <td class="text-medium-emphasis">
                Event
              </td>
              <td>
                <VChip
                  size="small"
                  variant="tonal"
                  :color="eventColor(detailActivity.event)"
                >
                  {{ eventLabel(detailActivity.event) }}
                </VChip>
              </td>
            </tr>

            <tr v-if="detailActivity.subject_type">
              <td class="text-medium-emphasis">
                Subject
              </td>
              <td>{{ detailActivity.subject_type }} #{{ detailActivity.subject_id }}</td>
            </tr>

            <tr
              v-for="[key, value] in detailPropertiesEntries"
              :key="key"
            >
              <td class="text-medium-emphasis">
                {{ key }}
              </td>
              <td class="text-wrap">
                {{ value }}
              </td>
            </tr>
          </tbody>
        </VTable>
      </VCardText>

      <VDivider />

      <VCardActions class="justify-end">
        <VBtn
          variant="tonal"
          class="text-none"
          @click="detailDialog = false"
        >
          Tutup
        </VBtn>
      </VCardActions>
    </VCard>
  </VDialog>
</template>
