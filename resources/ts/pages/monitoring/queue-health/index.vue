<script setup lang="ts">
import { computed, onMounted, onUnmounted, ref } from 'vue'
import { useI18n } from 'vue-i18n'
import { useRouter } from 'vue-router'
import axios from '@axios'
import { getApiErrorMessage } from '@/utils/apiHelper'
import { showConfirmAlert, showErrorToast, showSuccessToast } from '@/utils/alert'
import { usePermissionStore } from '@/stores/permission'

/*
|--------------------------------------------------------------------------
| Pemantauan Antrean Email
|--------------------------------------------------------------------------
| Jalur ketiga pelaporan kesehatan antrean, di samping log dan email
| peringatan. Angkanya diambil dari service yang sama dengan perintah
| terjadwalnya, jadi apa yang terbaca di sini tidak mungkin berbeda dengan
| isi email peringatan.
|
| Urutan bacanya: status lebih dulu, lalu SEBAB-nya, baru angka. Orang yang
| membuka halaman ini biasanya baru saja menerima peringatan dan ingin tahu
| apa yang harus dilakukan -- bukan ingin membaca statistik.
|--------------------------------------------------------------------------
*/

interface FailedItem {
  id: number
  uuid: string
  queue: string
  job: string
  error: string
  failed_at: string
}

interface FailedByJob {
  job: string
  count: number
  last_failed_at: string
  sample_error: string
}

interface Snapshot {
  checked_at: string
  status: string
  is_healthy: boolean
  reasons: string[]

  thresholds: {
    stale_after_minutes: number
    backlog_warning: number
    failure_window_hours: number
  }

  waiting: {
    count: number
    reserved: number
    oldest_available_at: string | null
    oldest_waiting_minutes: number | null
  }

  failed: {
    total: number
    recent: number
    by_job: FailedByJob[]
    items: FailedItem[]
    truncated: boolean
  }
}

const router = useRouter()
const { t } = useI18n()
const permissionStore = usePermissionStore()

const isCheckingPermission = ref(true)
const loading = ref(false)
const acting = ref(false)
const loadError = ref('')

const snapshot = ref<Snapshot | null>(null)
const canManage = ref(false)

const canView = computed(() => permissionStore.can('queue_monitor.view'))

/*
|--------------------------------------------------------------------------
| Turunan tampilan
|--------------------------------------------------------------------------
*/
const statusMeta: Record<string, { color: string; icon: string }> = {
  SEHAT: { color: 'success', icon: 'tabler-circle-check' },
  PERHATIAN: { color: 'warning', icon: 'tabler-alert-triangle' },
  BERMASALAH: { color: 'error', icon: 'tabler-alert-octagon' },
}

const statusColor = computed(() => statusMeta[snapshot.value?.status ?? '']?.color ?? 'secondary')
const statusIcon = computed(() => statusMeta[snapshot.value?.status ?? '']?.icon ?? 'tabler-help-circle')

const statusLabel = computed(() => {
  const status = snapshot.value?.status ?? ''

  return status === ''
    ? '-'
    : t(`queueHealth.status.${status.toLowerCase()}`)
})

const formatNumber = (value: number | null | undefined): string =>
  new Intl.NumberFormat('id-ID').format(Number(value ?? 0))

const formatMinutes = (value: number | null | undefined): string => {
  if (value === null || value === undefined)
    return '—'

  return `${new Intl.NumberFormat('id-ID', { maximumFractionDigits: 1 }).format(value)} ${t('queueHealth.units.minutes')}`
}

const formatDateTime = (value: string | null | undefined): string => {
  if (!value)
    return '-'

  const parsed = new Date(value.replace(' ', 'T'))

  return Number.isNaN(parsed.getTime())
    ? String(value)
    : parsed.toLocaleString('id-ID', {
      day: '2-digit',
      month: 'short',
      year: 'numeric',
      hour: '2-digit',
      minute: '2-digit',
    })
}

/*
|--------------------------------------------------------------------------
| Data
|--------------------------------------------------------------------------
*/
const fetchSnapshot = async (): Promise<void> => {
  loading.value = true
  loadError.value = ''

  try {
    const response = await axios.get('/monitoring/queue-health', {
      headers: { Accept: 'application/json' },
    })

    snapshot.value = response.data?.data ?? null
    canManage.value = Boolean(response.data?.abilities?.can_manage)
  }
  catch (error: unknown) {
    loadError.value = getApiErrorMessage(error, t('queueHealth.errors.loadFailed'))
  }
  finally {
    loading.value = false
  }
}

/*
 * Halaman ini dibuka justru saat ada yang tidak beres, dan keadaannya berubah
 * begitu worker dihidupkan lagi. Menyegarkan sendiri membuat pemulihan
 * terlihat tanpa perlu menekan apa pun.
 */
let timer: ReturnType<typeof setInterval> | null = null

const retryFailed = async (uuid: string | null): Promise<void> => {
  const konfirmasi = await showConfirmAlert({
    title: t('queueHealth.retry.confirmTitle'),
    text: uuid ? t('queueHealth.retry.confirmOne') : t('queueHealth.retry.confirmAll'),
    confirmButtonText: t('queueHealth.retry.confirmButton'),
  })

  if (!konfirmasi?.isConfirmed)
    return

  acting.value = true

  try {
    const response = await axios.post(
      '/monitoring/queue-health/retry',
      { uuid },
      { headers: { Accept: 'application/json' } },
    )

    showSuccessToast({
      title: t('common.alert.success'),
      text: response.data?.message || t('queueHealth.retry.successFallback'),
    })

    await fetchSnapshot()
  }
  catch (error: unknown) {
    showErrorToast({
      title: t('common.alert.error'),
      text: getApiErrorMessage(error, t('queueHealth.retry.failedFallback')),
    })
  }
  finally {
    acting.value = false
  }
}

const deleteFailed = async (uuid: string | null): Promise<void> => {
  const konfirmasi = await showConfirmAlert({
    title: t('queueHealth.delete.confirmTitle'),
    text: uuid ? t('queueHealth.delete.confirmOne') : t('queueHealth.delete.confirmAll'),
    confirmButtonText: t('queueHealth.delete.confirmButton'),
  })

  if (!konfirmasi?.isConfirmed)
    return

  acting.value = true

  try {
    const response = await axios.delete('/monitoring/queue-health/failed', {
      headers: { Accept: 'application/json' },
      data: { uuid },
    })

    showSuccessToast({
      title: t('common.alert.success'),
      text: response.data?.message || t('queueHealth.delete.successFallback'),
    })

    await fetchSnapshot()
  }
  catch (error: unknown) {
    showErrorToast({
      title: t('common.alert.error'),
      text: getApiErrorMessage(error, t('queueHealth.delete.failedFallback')),
    })
  }
  finally {
    acting.value = false
  }
}

onMounted(async () => {
  await permissionStore.loadPermissions()

  if (!canView.value) {
    await router.replace('/forbidden')

    return
  }

  isCheckingPermission.value = false

  await fetchSnapshot()

  timer = setInterval(fetchSnapshot, 30000)
})

onUnmounted(() => {
  if (timer !== null)
    clearInterval(timer)
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
    <!-- KEPALA -->
    <VCard class="qh-header mb-6">
      <VCardText class="d-flex flex-wrap align-center justify-space-between gap-4 pa-6">
        <div class="d-flex align-center gap-4 min-w-0">
          <VAvatar
            size="52"
            :color="statusColor"
            variant="tonal"
            rounded
          >
            <VIcon
              :icon="statusIcon"
              size="28"
            />
          </VAvatar>

          <div class="min-w-0">
            <div class="text-h5 font-weight-bold">
              {{ t('queueHealth.header.title') }}
            </div>

            <div class="text-body-2 text-medium-emphasis mt-1">
              {{ t('queueHealth.header.description') }}
            </div>
          </div>
        </div>

        <div class="d-flex align-center gap-2 flex-wrap">
          <VChip
            :color="statusColor"
            variant="flat"
            size="small"
          >
            {{ statusLabel }}
          </VChip>

          <VChip
            size="small"
            variant="tonal"
            prepend-icon="tabler-clock"
          >
            {{ t('queueHealth.header.checkedAt') }} {{ formatDateTime(snapshot?.checked_at) }}
          </VChip>

          <VBtn
            icon
            variant="tonal"
            size="small"
            :loading="loading"
            @click="fetchSnapshot"
          >
            <VIcon icon="tabler-refresh" />

            <VTooltip
              activator="parent"
              location="bottom"
              :offset="4"
            >
              {{ t('queueHealth.header.refresh') }}
            </VTooltip>
          </VBtn>
        </div>
      </VCardText>
    </VCard>

    <VAlert
      v-if="loadError"
      type="error"
      variant="tonal"
      class="mb-6"
    >
      {{ loadError }}
    </VAlert>

    <!--
      SEBAB
      Ditaruh sebelum angka: pembaca halaman ini biasanya baru menerima
      peringatan dan ingin tahu apa yang harus dikerjakan, bukan statistiknya.
    -->
    <VAlert
      v-if="snapshot && !snapshot.is_healthy"
      :type="snapshot.status === 'BERMASALAH' ? 'error' : 'warning'"
      variant="tonal"
      class="mb-6"
    >
      <div class="font-weight-medium mb-1">
        {{ t('queueHealth.reasons.title') }}
      </div>

      <ul class="ps-4 mb-0">
        <li
          v-for="(alasan, index) in snapshot.reasons"
          :key="`alasan-${index}`"
          class="text-body-2"
        >
          {{ alasan }}
        </li>
      </ul>
    </VAlert>

    <VAlert
      v-else-if="snapshot"
      type="success"
      variant="tonal"
      class="mb-6"
    >
      {{ t('queueHealth.reasons.healthy') }}
    </VAlert>

    <!-- ANGKA -->
    <VRow class="mb-2">
      <VCol
        cols="12"
        sm="6"
        lg="3"
      >
        <VCard class="h-100">
          <VCardText class="pa-5">
            <div class="qh-label mb-2">
              {{ t('queueHealth.stats.waiting') }}
            </div>

            <div class="qh-value">
              {{ formatNumber(snapshot?.waiting.count) }}
            </div>

            <div class="text-body-2 text-medium-emphasis mt-1">
              {{ t('queueHealth.stats.waitingHint', { reserved: formatNumber(snapshot?.waiting.reserved) }) }}
            </div>
          </VCardText>
        </VCard>
      </VCol>

      <VCol
        cols="12"
        sm="6"
        lg="3"
      >
        <VCard class="h-100">
          <VCardText class="pa-5">
            <div class="qh-label mb-2">
              {{ t('queueHealth.stats.oldest') }}
            </div>

            <div
              class="qh-value"
              :class="snapshot?.waiting.oldest_waiting_minutes
                && snapshot.waiting.oldest_waiting_minutes >= snapshot.thresholds.stale_after_minutes
                ? 'text-error'
                : ''"
            >
              {{ formatMinutes(snapshot?.waiting.oldest_waiting_minutes) }}
            </div>

            <div class="text-body-2 text-medium-emphasis mt-1">
              {{ t('queueHealth.stats.oldestHint', { threshold: snapshot?.thresholds.stale_after_minutes ?? 0 }) }}
            </div>
          </VCardText>
        </VCard>
      </VCol>

      <VCol
        cols="12"
        sm="6"
        lg="3"
      >
        <VCard class="h-100">
          <VCardText class="pa-5">
            <div class="qh-label mb-2">
              {{ t('queueHealth.stats.failedTotal') }}
            </div>

            <div
              class="qh-value"
              :class="(snapshot?.failed.total ?? 0) > 0 ? 'text-error' : ''"
            >
              {{ formatNumber(snapshot?.failed.total) }}
            </div>

            <div class="text-body-2 text-medium-emphasis mt-1">
              {{ t('queueHealth.stats.failedTotalHint') }}
            </div>
          </VCardText>
        </VCard>
      </VCol>

      <VCol
        cols="12"
        sm="6"
        lg="3"
      >
        <VCard class="h-100">
          <VCardText class="pa-5">
            <div class="qh-label mb-2">
              {{ t('queueHealth.stats.failedRecent') }}
            </div>

            <div
              class="qh-value"
              :class="(snapshot?.failed.recent ?? 0) > 0 ? 'text-warning' : ''"
            >
              {{ formatNumber(snapshot?.failed.recent) }}
            </div>

            <div class="text-body-2 text-medium-emphasis mt-1">
              {{ t('queueHealth.stats.failedRecentHint', { hours: snapshot?.thresholds.failure_window_hours ?? 24 }) }}
            </div>
          </VCardText>
        </VCard>
      </VCol>
    </VRow>

    <!-- KEGAGALAN PER JENIS -->
    <VCard
      v-if="snapshot?.failed.by_job.length"
      class="mb-6"
    >
      <VCardItem>
        <VCardTitle>{{ t('queueHealth.byJob.title') }}</VCardTitle>
        <VCardSubtitle>{{ t('queueHealth.byJob.subtitle') }}</VCardSubtitle>
      </VCardItem>

      <VDivider />

      <VCardText class="pa-0">
        <div class="qh-table">
          <VTable density="compact">
            <thead>
              <tr>
                <th>{{ t('queueHealth.byJob.job') }}</th>
                <th style="inline-size: 6rem;">
                  {{ t('queueHealth.byJob.count') }}
                </th>
                <th style="inline-size: 12rem;">
                  {{ t('queueHealth.byJob.lastFailed') }}
                </th>
                <th>{{ t('queueHealth.byJob.sampleError') }}</th>
              </tr>
            </thead>

            <tbody>
              <tr
                v-for="baris in snapshot.failed.by_job"
                :key="`jenis-${baris.job}`"
              >
                <td class="font-weight-medium">
                  {{ baris.job }}
                </td>
                <td>
                  <VChip
                    size="x-small"
                    variant="tonal"
                    color="error"
                  >
                    {{ formatNumber(baris.count) }}
                  </VChip>
                </td>
                <td class="text-caption text-medium-emphasis">
                  {{ formatDateTime(baris.last_failed_at) }}
                </td>
                <td class="text-caption text-medium-emphasis">
                  {{ baris.sample_error }}
                </td>
              </tr>
            </tbody>
          </VTable>
        </div>
      </VCardText>
    </VCard>

    <!-- DAFTAR JOB GAGAL -->
    <VCard>
      <VCardItem>
        <VCardTitle>{{ t('queueHealth.failedList.title') }}</VCardTitle>

        <VCardSubtitle>
          {{ t('queueHealth.failedList.subtitle') }}
        </VCardSubtitle>

        <template
          v-if="canManage && snapshot?.failed.items.length"
          #append
        >
          <div class="d-flex align-center gap-2">
            <VBtn
              size="small"
              variant="tonal"
              color="primary"
              class="text-none"
              prepend-icon="tabler-refresh-dot"
              :disabled="acting"
              @click="retryFailed(null)"
            >
              {{ t('queueHealth.retry.allButton') }}
            </VBtn>

            <VBtn
              size="small"
              variant="tonal"
              color="error"
              class="text-none"
              prepend-icon="tabler-trash"
              :disabled="acting"
              @click="deleteFailed(null)"
            >
              {{ t('queueHealth.delete.allButton') }}
            </VBtn>
          </div>
        </template>
      </VCardItem>

      <VDivider />

      <VCardText class="pa-5">
        <VAlert
          v-if="!snapshot?.failed.items.length"
          type="success"
          variant="tonal"
          density="compact"
        >
          {{ t('queueHealth.failedList.empty') }}
        </VAlert>

        <div
          v-else
          class="d-flex flex-column gap-2"
        >
          <div
            v-for="item in snapshot.failed.items"
            :key="`gagal-${item.uuid}`"
            class="qh-item"
          >
            <div class="d-flex align-center justify-space-between gap-3 flex-wrap">
              <div class="min-w-0">
                <div class="font-weight-medium text-truncate">
                  {{ item.job }}
                </div>

                <div class="text-caption text-medium-emphasis">
                  {{ formatDateTime(item.failed_at) }} &middot; {{ item.queue }}
                </div>
              </div>

              <div
                v-if="canManage"
                class="d-flex align-center gap-1"
              >
                <VBtn
                  icon
                  size="x-small"
                  variant="text"
                  color="primary"
                  :disabled="acting"
                  @click="retryFailed(item.uuid)"
                >
                  <VIcon icon="tabler-refresh-dot" />

                  <VTooltip
                    activator="parent"
                    location="top"
                    :offset="4"
                  >
                    {{ t('queueHealth.retry.oneButton') }}
                  </VTooltip>
                </VBtn>

                <VBtn
                  icon
                  size="x-small"
                  variant="text"
                  color="error"
                  :disabled="acting"
                  @click="deleteFailed(item.uuid)"
                >
                  <VIcon icon="tabler-trash" />

                  <VTooltip
                    activator="parent"
                    location="top"
                    :offset="4"
                  >
                    {{ t('queueHealth.delete.oneButton') }}
                  </VTooltip>
                </VBtn>
              </div>
            </div>

            <div class="qh-error mt-2">
              {{ item.error }}
            </div>
          </div>

          <div
            v-if="snapshot.failed.truncated"
            class="text-caption text-medium-emphasis mt-1"
          >
            {{ t('queueHealth.failedList.truncated', { shown: snapshot.failed.items.length, total: snapshot.failed.total }) }}
          </div>
        </div>
      </VCardText>
    </VCard>
  </section>
</template>

<style scoped>
.qh-header {
  background: linear-gradient(135deg, rgba(var(--v-theme-primary), 0.08), transparent 65%);
}

.qh-label {
  color: rgba(var(--v-theme-on-surface), 0.6);
  font-size: 0.72rem;
  font-weight: 600;
  letter-spacing: 0.05em;
  text-transform: uppercase;
}

.qh-value {
  font-size: 1.6rem;
  font-weight: 700;
  line-height: 1.2;
}

.qh-table {
  overflow-x: auto;
}

.qh-item {
  border: 1px solid rgba(var(--v-border-color), var(--v-border-opacity));
  border-radius: 10px;
  padding-block: 0.75rem;
  padding-inline: 0.875rem;
}

/* Pesan kesalahan dibiarkan apa adanya, termasuk spasinya. */
.qh-error {
  border-inline-start: 3px solid rgba(var(--v-theme-error), 0.5);
  background: rgba(var(--v-theme-error), 0.04);
  color: rgba(var(--v-theme-on-surface), 0.75);
  font-family: monospace;
  font-size: 0.78rem;
  overflow-x: auto;
  padding-block: 0.5rem;
  padding-inline: 0.75rem;
  white-space: pre-wrap;
  word-break: break-word;
}
</style>
