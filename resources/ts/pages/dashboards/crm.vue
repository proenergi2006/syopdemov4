<script setup lang="ts">
import axios from '@axios'
import {
  computed,
  nextTick,
  onBeforeUnmount,
  onMounted,
  ref,
  watch,
} from 'vue'
import { useI18n } from 'vue-i18n'
import { useRouter } from 'vue-router'

type DisplayMode = 4 | 8 | 12 | 'all'

interface DashboardModuleGroup {
  id: number
  code: string
  name: string
  icon: string | null
  modules_count: number
}

interface DashboardModule {
  id: number
  code: string
  title: string
  short_title: string | null
  description: string | null
  icon: string | null
  color: string
  route_path: string | null
  permission_name: string | null
  features: string[]
  is_available: boolean

  group: {
    id: number
    code: string
    name: string
    icon: string | null
  }
}

interface DashboardModulesResponse {
  message: string
  data: DashboardModule[]

  meta: {
    current_page: number
    last_page: number
    per_page: number
    total: number
    has_more: boolean
  }
}

interface DashboardModuleGroupsResponse {
  message: string
  data: DashboardModuleGroup[]
}

interface ApprovalNotification {
  pending_count: number
  oldest_waiting_at: string | null
  waiting_days: number
  waiting_hours: number
  waiting_label: string | null
}

interface ApprovalNotificationsResponse {
  message: string
  data: Record<string, ApprovalNotification>
}

const router = useRouter()
const { t } = useI18n()

const dashboardModules = ref<DashboardModule[]>([])
const dashboardGroups = ref<DashboardModuleGroup[]>([])
const approvalNotifications = ref<Record<string, ApprovalNotification>>({})

const selectedGroupCode = ref('ALL')
const displayMode = ref<DisplayMode>(4)

const currentPage = ref(1)
const lastPage = ref(1)
const totalModules = ref(0)

/*
 * Default true supaya skeleton loading langsung tampil saat halaman
 * dibuka, bukan empty state -- fetchDashboardGroups() berjalan lebih
 * dulu di onMounted sebelum fetchDashboardModules() sempat men-set ini.
 */
const isInitialLoading = ref(true)
const isLoadingMore = ref(false)

const errorMessage = ref('')

const loadMoreTrigger = ref<HTMLElement | null>(null)

let scrollObserver: IntersectionObserver | null = null

const displayOptions = computed(() => [
  {
    title: t('dashboard.moduleLauncher.filters.displayOptions.four'),
    value: 4,
  },
  {
    title: t('dashboard.moduleLauncher.filters.displayOptions.eight'),
    value: 8,
  },
  {
    title: t('dashboard.moduleLauncher.filters.displayOptions.twelve'),
    value: 12,
  },
  {
    title: t('dashboard.moduleLauncher.filters.displayOptions.all'),
    value: 'all',
  },
])

const groupOptions = computed(() => [
  {
    id: 0,
    code: 'ALL',
    name: t('dashboard.moduleLauncher.filters.allCategory'),
    icon: 'mdi-view-grid-outline',
    modules_count: dashboardGroups.value.reduce(
      (total, group) => total + group.modules_count,
      0,
    ),
  },
  ...dashboardGroups.value,
])

const requestPerPage = computed(() => {
  /*
   * Ketika Tampilkan Semua dipilih,
   * API tetap mengambil data per delapan modul.
   */
  if (displayMode.value === 'all')
    return 8

  return displayMode.value
})

const hasMoreModules = computed(() => {
  return currentPage.value < lastPage.value
})

const showInfiniteScroll = computed(() => {
  return displayMode.value === 'all'
})

const selectedGroupName = computed(() => {
  return groupOptions.value.find(
    group => group.code === selectedGroupCode.value,
  )?.name ?? t('dashboard.moduleLauncher.filters.allCategory')
})

async function fetchDashboardGroups(): Promise<void> {
  try {
    const response = await axios.get<DashboardModuleGroupsResponse>(
      '/dashboard/module-groups',
    )

    dashboardGroups.value = response.data.data
  }
  catch (error) {
    console.error('Failed to load dashboard groups:', error)

    errorMessage.value = t('dashboard.moduleLauncher.errors.groupsFailed')
  }
}

async function fetchDashboardModules(
  reset = true,
): Promise<void> {
  if (reset) {
    currentPage.value = 1
    lastPage.value = 1
    totalModules.value = 0
    dashboardModules.value = []
    isInitialLoading.value = true
  }
  else {
    if (
      isLoadingMore.value
      || !hasMoreModules.value
      || displayMode.value !== 'all'
    ) {
      return
    }

    isLoadingMore.value = true
  }

  errorMessage.value = ''

  try {
    const params: Record<string, string | number> = {
      page: currentPage.value,
      per_page: requestPerPage.value,
    }

    if (selectedGroupCode.value !== 'ALL')
      params.group_code = selectedGroupCode.value

    const response = await axios.get<DashboardModulesResponse>(
      '/dashboard/modules',
      {
        params,
      },
    )

    const responseModules = response.data.data

    if (reset) {
      dashboardModules.value = responseModules
    }
    else {
      const existingIds = new Set(
        dashboardModules.value.map(module => module.id),
      )

      dashboardModules.value.push(
        ...responseModules.filter(
          module => !existingIds.has(module.id),
        ),
      )
    }

    currentPage.value = response.data.meta.current_page
    lastPage.value = response.data.meta.last_page
    totalModules.value = response.data.meta.total

    await nextTick()
    observeLoadMoreTrigger()
  }
  catch (error) {
    console.error('Failed to load dashboard modules:', error)

    errorMessage.value = t('dashboard.moduleLauncher.errors.modulesFailed')
  }
  finally {
    isInitialLoading.value = false
    isLoadingMore.value = false
  }
}

async function fetchApprovalNotifications(): Promise<void> {
  try {
    const response = await axios.get<ApprovalNotificationsResponse>(
      '/dashboard/approval-notifications',
    )

    approvalNotifications.value = response.data.data
  }
  catch (error) {
    /*
     * Notifikasi approval bersifat pelengkap, jadi kegagalan
     * fetch tidak perlu memblokir tampilan dashboard utama.
     */
    console.error('Failed to load approval notifications:', error)
  }
}

function approvalNotificationFor(
  dashboardModule: DashboardModule,
): ApprovalNotification | null {
  const notification = approvalNotifications.value[dashboardModule.code]

  if (!notification || notification.pending_count <= 0)
    return null

  return notification
}

/*
 * Halaman daftar (index) sesuai modul, tempat approver benar-benar
 * memproses approval-nya. Route dashboard module sendiri (route_path)
 * mengarah ke halaman analitik, bukan ke daftar approval.
 */
const approvalListRouteByModuleCode: Record<string, string> = {
  PURCHASE_REQUISITION: '/non_stock/purchase_request',
  PURCHASE_ORDER: '/non_stock/purchase_order',
}

async function openApprovalList(
  dashboardModule: DashboardModule,
): Promise<void> {
  const path = approvalListRouteByModuleCode[dashboardModule.code]

  if (!path)
    return

  try {
    await router.push(path)
  }
  catch (error) {
    console.error(
      `Failed to open approval list for ${dashboardModule.title}:`,
      error,
    )
  }
}

async function loadMoreModules(): Promise<void> {
  if (
    displayMode.value !== 'all'
    || isLoadingMore.value
    || !hasMoreModules.value
  ) {
    return
  }

  currentPage.value += 1

  await fetchDashboardModules(false)
}

async function openDashboardModule(
  dashboardModule: DashboardModule,
): Promise<void> {
  if (
    !dashboardModule.is_available
    || !dashboardModule.route_path
  ) {
    return
  }

  try {
    await router.push(dashboardModule.route_path)
  }
  catch (error) {
    console.error(
      `Failed to open ${dashboardModule.title}:`,
      error,
    )
  }
}

function createScrollObserver(): void {
  scrollObserver = new IntersectionObserver(
    entries => {
      const entry = entries[0]

      if (
        entry?.isIntersecting
        && displayMode.value === 'all'
        && hasMoreModules.value
        && !isLoadingMore.value
      ) {
        loadMoreModules()
      }
    },
    {
      root: null,
      rootMargin: '250px 0px',
      threshold: 0.1,
    },
  )
}

function observeLoadMoreTrigger(): void {
  if (!scrollObserver)
    return

  scrollObserver.disconnect()

  if (
    loadMoreTrigger.value
    && displayMode.value === 'all'
  ) {
    scrollObserver.observe(loadMoreTrigger.value)
  }
}

watch(
  [
    selectedGroupCode,
    displayMode,
  ],
  async () => {
    await fetchDashboardModules(true)
  },
)

watch(
  loadMoreTrigger,
  () => {
    observeLoadMoreTrigger()
  },
)

onMounted(async () => {
  createScrollObserver()

  await fetchDashboardGroups()
  await fetchDashboardModules(true)
  await fetchApprovalNotifications()
})

onBeforeUnmount(() => {
  scrollObserver?.disconnect()
})
</script>

<template>
  <section class="dashboard-module-page">
    <!-- Welcome header -->
    <VCard class="dashboard-welcome-card mb-6">
      <VCardText class="pa-6 pa-md-8">
        <VRow align="center">
          <VCol
            cols="12"
            md="8"
          >
            <div class="d-flex align-start align-sm-center gap-4">
              <VAvatar
                color="primary"
                variant="flat"
                rounded="lg"
                size="64"
                class="dashboard-header-icon"
              >
                <VIcon
                  icon="mdi-view-dashboard-outline"
                  size="34"
                />
              </VAvatar>

              <div>
                <div class="d-flex flex-wrap align-center gap-2 mb-2">
                  <h1 class="text-h4 font-weight-bold mb-0">
                    {{ t('dashboard.moduleLauncher.header.title') }}
                  </h1>

                  <VChip
                    color="primary"
                    variant="tonal"
                    size="small"
                  >
                    {{ t('dashboard.moduleLauncher.header.badge') }}
                  </VChip>
                </div>

                <p class="text-body-1 text-medium-emphasis mb-0">
                  {{ t('dashboard.moduleLauncher.header.description') }}
                </p>
              </div>
            </div>
          </VCol>

          <VCol
            cols="12"
            md="4"
          >
            <div class="dashboard-summary-box">
              <div>
                <div class="text-caption text-medium-emphasis mb-1">
                  {{ t('dashboard.moduleLauncher.header.categoryLabel') }}
                </div>

                <div class="text-h6 font-weight-bold">
                  {{ selectedGroupName }}
                </div>
              </div>

              <VDivider vertical />

              <div>
                <div class="text-caption text-medium-emphasis mb-1">
                  {{ t('dashboard.moduleLauncher.header.totalModulesLabel') }}
                </div>

                <div class="text-h4 font-weight-bold text-primary">
                  {{ totalModules }}
                </div>
              </div>
            </div>
          </VCol>
        </VRow>
      </VCardText>
    </VCard>

    <!-- Filter -->
    <VCard class="mb-6">
      <VCardText>
        <VRow align="center">
          <VCol
            cols="12"
            lg="9"
          >
            <div class="text-body-2 font-weight-medium mb-3">
              {{ t('dashboard.moduleLauncher.filters.categoryLabel') }}
            </div>

            <VChipGroup
              v-model="selectedGroupCode"
              selected-class="text-primary"
              mandatory
              column
            >
              <VChip
                v-for="group in groupOptions"
                :key="group.code"
                :value="group.code"
                :prepend-icon="group.icon ?? 'mdi-folder-outline'"
                filter
                variant="tonal"
              >
                {{ group.name }}

                <VBadge
                  :content="group.modules_count"
                  inline
                  color="primary"
                  class="ms-2"
                />
              </VChip>
            </VChipGroup>
          </VCol>

          <VCol
            cols="12"
            sm="6"
            lg="3"
          >
            <VSelect
              v-model="displayMode"
              :items="displayOptions"
              item-title="title"
              item-value="value"
              :label="t('dashboard.moduleLauncher.filters.displayLabel')"
              prepend-inner-icon="mdi-view-grid-plus-outline"
              variant="outlined"
              density="compact"
              hide-details
            />
          </VCol>
        </VRow>
      </VCardText>
    </VCard>

    <VAlert
      v-if="errorMessage"
      type="error"
      variant="tonal"
      class="mb-6"
      closable
      @click:close="errorMessage = ''"
    >
      {{ errorMessage }}
    </VAlert>

    <!-- Title -->
    <div class="d-flex flex-wrap align-center justify-space-between gap-3 mb-4">
      <div>
        <h2 class="text-h5 font-weight-semibold mb-1">
          {{ t('dashboard.moduleLauncher.section.title', { category: selectedGroupName }) }}
        </h2>

        <p class="text-body-2 text-medium-emphasis mb-0">
          {{ t('dashboard.moduleLauncher.section.description') }}
        </p>
      </div>

      <VChip
        prepend-icon="mdi-shield-account-outline"
        color="secondary"
        variant="tonal"
      >
        {{ t('dashboard.moduleLauncher.section.permissionBadge') }}
      </VChip>
    </div>

    <!-- Initial skeleton -->
    <VRow
      v-if="isInitialLoading"
      class="match-height"
    >
      <VCol
        v-for="index in 4"
        :key="`initial-skeleton-${index}`"
        cols="12"
        sm="6"
        xl="3"
      >
        <VCard class="h-100">
          <VCardText class="pa-6">
            <VSkeletonLoader
              type="avatar, heading, paragraph, list-item-two-line, actions"
            />
          </VCardText>
        </VCard>
      </VCol>
    </VRow>

    <!-- Empty state -->
    <VCard
      v-else-if="dashboardModules.length === 0"
      variant="outlined"
    >
      <VCardText class="text-center py-12">
        <VAvatar
          color="secondary"
          variant="tonal"
          size="72"
          class="mb-4"
        >
          <VIcon
            icon="mdi-view-dashboard-variant-outline"
            size="38"
          />
        </VAvatar>

        <h3 class="text-h5 mb-2">
          {{ t('dashboard.moduleLauncher.empty.title') }}
        </h3>

        <p class="text-body-2 text-medium-emphasis mb-0">
          {{ t('dashboard.moduleLauncher.empty.description') }}
        </p>
      </VCardText>
    </VCard>

    <!-- Module cards -->
    <VRow
      v-else
      class="match-height"
    >
      <VCol
        v-for="dashboardModule in dashboardModules"
        :key="dashboardModule.id"
        cols="12"
        sm="6"
        xl="3"
      >
        <VHover v-slot="{ isHovering, props }">
          <VCard
            v-bind="props"
            class="dashboard-module-card h-100"
            :class="{
              'dashboard-module-card--active':
                dashboardModule.is_available,
              'dashboard-module-card--disabled':
                !dashboardModule.is_available,
            }"
            :elevation="
              isHovering && dashboardModule.is_available
                ? 8
                : 1
            "
            :tabindex="dashboardModule.is_available ? 0 : -1"
            @click="openDashboardModule(dashboardModule)"
            @keydown.enter="openDashboardModule(dashboardModule)"
          >
            <VCardText class="d-flex flex-column h-100 pa-6">
              <div class="d-flex justify-space-between align-start mb-5">
                <VBadge
                  :model-value="!!approvalNotificationFor(dashboardModule)"
                  :content="approvalNotificationFor(dashboardModule)?.pending_count"
                  color="error"
                  overlap
                  bordered
                >
                  <VAvatar
                    :color="dashboardModule.color"
                    variant="tonal"
                    rounded="lg"
                    size="56"
                  >
                    <VIcon
                      :icon="
                        dashboardModule.icon
                          ?? 'mdi-view-dashboard-outline'
                      "
                      size="30"
                    />
                  </VAvatar>
                </VBadge>

                <VChip
                  v-if="dashboardModule.is_available"
                  color="success"
                  variant="tonal"
                  size="small"
                  prepend-icon="mdi-check-circle-outline"
                >
                  {{ t('dashboard.moduleLauncher.card.available') }}
                </VChip>

                <VChip
                  v-else
                  color="secondary"
                  variant="tonal"
                  size="small"
                  prepend-icon="mdi-clock-outline"
                >
                  {{ t('dashboard.moduleLauncher.card.comingSoon') }}
                </VChip>
              </div>

              <div class="mb-4">
                <div class="d-flex flex-wrap align-center gap-2 mb-2">
                  <h3 class="text-h5 font-weight-semibold mb-0">
                    {{ dashboardModule.title }}
                  </h3>

                  <VChip
                    v-if="dashboardModule.short_title"
                    :color="dashboardModule.color"
                    size="x-small"
                    variant="flat"
                  >
                    {{ dashboardModule.short_title }}
                  </VChip>
                </div>

                <VChip
                  size="x-small"
                  variant="outlined"
                  prepend-icon="mdi-folder-outline"
                >
                  {{ dashboardModule.group.name }}
                </VChip>
              </div>

              <div
                v-if="approvalNotificationFor(dashboardModule)"
                class="dashboard-approval-alert d-flex align-center justify-space-between gap-3 mb-5"
              >
                <div class="d-flex align-center gap-2">
                  <VIcon
                    icon="mdi-clock-alert-outline"
                    color="warning"
                    size="20"
                  />

                  <span class="text-body-2 font-weight-medium text-no-wrap">
                    {{
                      t(
                        'dashboard.moduleLauncher.card.pendingApproval',
                        { count: approvalNotificationFor(dashboardModule)?.pending_count },
                      )
                    }}
                  </span>
                </div>

                <VBtn
                  size="x-small"
                  variant="tonal"
                  color="warning"
                  class="text-no-wrap"
                  @click.stop="openApprovalList(dashboardModule)"
                >
                  {{ t('dashboard.moduleLauncher.card.viewAll') }}
                </VBtn>
              </div>

              <VSpacer />

              <VDivider class="mb-4" />

              <div
                class="d-flex align-center justify-space-between"
                :class="{
                  'text-medium-emphasis':
                    !dashboardModule.is_available,
                }"
              >
                <span class="text-body-2 font-weight-medium">
                  {{
                    dashboardModule.is_available
                      ? t('dashboard.moduleLauncher.card.openDashboard')
                      : t('dashboard.moduleLauncher.card.notAvailable')
                  }}
                </span>

                <VBtn
                  :color="
                    dashboardModule.is_available
                      ? dashboardModule.color
                      : 'secondary'
                  "
                  :disabled="!dashboardModule.is_available"
                  icon
                  size="small"
                  variant="tonal"
                  tabindex="-1"
                >
                  <VIcon icon="mdi-arrow-right" />
                </VBtn>
              </div>
            </VCardText>

            <div
              class="dashboard-module-card__line"
              :style="{
                backgroundColor:
                  `rgb(var(--v-theme-${dashboardModule.color}))`,
              }"
            />
          </VCard>
        </VHover>
      </VCol>

      <!-- Scroll loading skeleton -->
      <template v-if="isLoadingMore">
        <VCol
          v-for="index in 4"
          :key="`load-more-skeleton-${index}`"
          cols="12"
          sm="6"
          xl="3"
        >
          <VCard class="h-100">
            <VCardText class="pa-6">
              <VSkeletonLoader
                type="avatar, heading, paragraph, list-item-two-line, actions"
              />
            </VCardText>
          </VCard>
        </VCol>
      </template>
    </VRow>

    <!-- Infinite scroll trigger -->
    <div
      v-if="showInfiniteScroll"
      ref="loadMoreTrigger"
      class="dashboard-load-more-trigger"
    >
      <div
        v-if="hasMoreModules && !isLoadingMore"
        class="text-center text-body-2 text-medium-emphasis py-6"
      >
        {{ t('dashboard.moduleLauncher.infiniteScroll.loadMore') }}
      </div>

      <div
        v-else-if="!hasMoreModules && dashboardModules.length > 0"
        class="text-center text-body-2 text-medium-emphasis py-6"
      >
        {{ t('dashboard.moduleLauncher.infiniteScroll.allLoaded') }}
      </div>
    </div>
  </section>
</template>

<style scoped>
.dashboard-module-page {
  min-block-size: 100%;
}

.dashboard-welcome-card {
  position: relative;
  overflow: hidden;
  border: 1px solid rgba(var(--v-border-color), var(--v-border-opacity));
  background:
    linear-gradient(
      135deg,
      rgba(var(--v-theme-primary), 0.12) 0%,
      rgba(var(--v-theme-surface), 1) 52%,
      rgba(var(--v-theme-info), 0.08) 100%
    );
}

.dashboard-welcome-card::after {
  position: absolute;
  border: 30px solid rgba(var(--v-theme-primary), 0.05);
  border-radius: 50%;
  block-size: 180px;
  content: '';
  inline-size: 180px;
  inset-block-start: -75px;
  inset-inline-end: -50px;
}

.dashboard-header-icon {
  flex-shrink: 0;
  box-shadow: 0 8px 20px rgba(var(--v-theme-primary), 0.25);
}

.dashboard-summary-box {
  position: relative;
  z-index: 1;
  display: flex;
  justify-content: space-around;
  border: 1px solid rgba(var(--v-border-color), var(--v-border-opacity));
  border-radius: 12px;
  background-color: rgba(var(--v-theme-surface), 0.72);
  gap: 20px;
  padding-block: 18px;
  padding-inline: 20px;
  text-align: center;
}

.dashboard-approval-alert {
  border: 1px solid rgba(var(--v-theme-warning), 0.28);
  border-radius: 10px;
  background-color: rgba(var(--v-theme-warning), 0.1);
  padding-block: 8px;
  padding-inline: 12px;
}

.dashboard-module-card {
  position: relative;
  overflow: hidden;
  border: 1px solid rgba(var(--v-border-color), var(--v-border-opacity));
  transition:
    transform 0.25s ease,
    box-shadow 0.25s ease,
    border-color 0.25s ease;
}

.dashboard-module-card--active {
  cursor: pointer;
}

.dashboard-module-card--active:hover {
  border-color: rgba(var(--v-theme-primary), 0.4);
  transform: translateY(-5px);
}

.dashboard-module-card--disabled {
  cursor: not-allowed;
  opacity: 0.72;
}

.dashboard-module-card__line {
  position: absolute;
  block-size: 4px;
  inset-block-end: 0;
  inset-inline: 0;
  opacity: 0;
  transform: scaleX(0);
  transform-origin: left;
  transition:
    transform 0.25s ease,
    opacity 0.25s ease;
}

.dashboard-module-card--active:hover .dashboard-module-card__line {
  opacity: 1;
  transform: scaleX(1);
}

.dashboard-load-more-trigger {
  min-block-size: 70px;
}

@media (max-width: 600px) {
  .dashboard-summary-box {
    justify-content: space-evenly;
  }
}
</style>