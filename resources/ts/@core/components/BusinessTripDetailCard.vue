<script setup lang="ts">
import { computed } from 'vue'

/*
|--------------------------------------------------------------------------
| Rincian Perjalanan Dinas
|--------------------------------------------------------------------------
| Dipakai dua tempat: halaman daftar perdin, dan detail FPU yang menumpang
| perdin itu. Sengaja satu komponen, bukan dua salinan -- salinan selalu
| berawal sama dan berakhir berbeda.
|
| Isinya baca-saja. Yang berbeda antar halaman hanya tombol aksinya, dan itu
| dititipkan lewat slot "actions".
|--------------------------------------------------------------------------
*/

interface ItineraryRow {
  sort_no: number
  date: string | null
  time_text: string | null
  description: string
  pic: string | null
}

interface ApprovalRow {
  step_order: number
  label: string | null
  approver_name: string | null
  approver_type: string
  status: string
  approved_at: string | null
  rejected_at: string | null
  notes: string | null
}

/*
| Bentuk yang dibutuhkan kartu ini -- sama persis dengan yang dikirim
| BusinessTripPresenter, dan itulah yang dipakai kedua halaman.
*/
interface TripDetail {
  trip_number: string | null
  status: string
  can_approve?: boolean
  allows_parallel_cash_advance?: boolean
  duration_days: number
  submitted_at: string | null
  rejection_notes: string | null
  cancellation_notes: string | null
  employee_name: string
  department_name: string | null
  position_name: string | null
  destination: string
  depart_date: string | null
  depart_time: string | null
  return_date: string | null
  return_time: string | null
  purpose: string
  notes: string | null
  itineraries: ItineraryRow[]
  approvals: ApprovalRow[]
}

const props = defineProps<{ trip: TripDetail | null }>()

const emit = defineEmits<{ (e: 'close'): void }>()

const { t } = useI18n()

/* Warna status, sebagai peta -- bukan percabangan yang harus dibaca ulang. */
const STATUS_COLORS: Record<string, string> = {
  'APPROVED': 'success',
  'IN PROGRESS': 'warning',
  'REJECTED': 'error',
  'CANCELLED': 'secondary',
}

const statusColor = (status: string): string => STATUS_COLORS[status] ?? 'info'

/* Warna kepala kartu mengikuti keadaan dokumennya. */
const detailAccent = computed<string>(() =>
  props.trip ? statusColor(props.trip.status) : 'info')

/**
 * Rundown dikelompokkan per hari.
 *
 * Dibaca sebagai agenda, bukan tabel bernomor: satu perjalanan lima hari
 * dengan lima belas kegiatan lebih mudah ditangkap sebagai lima hari berisi
 * kegiatan, daripada lima belas baris yang tanggalnya berulang-ulang.
 */
const itineraryByDay = computed<{ date: string | null; rows: ItineraryRow[] }[]>(() => {
  const hari: { date: string | null; rows: ItineraryRow[] }[] = []

  for (const row of props.trip?.itineraries ?? []) {
    const terakhir = hari[hari.length - 1]

    if (terakhir && terakhir.date === row.date)
      terakhir.rows.push(row)
    else
      hari.push({ date: row.date, rows: [row] })
  }

  return hari
})

/** "17 Sep 2026, 14:30" -- tanggal saja tidak cukup untuk riwayat. */
const formatDateTime = (nilai?: string | null): string => {
  if (!nilai)
    return ''

  const d = new Date(nilai)

  if (Number.isNaN(d.getTime()))
    return ''

  return d.toLocaleString(undefined, {
    day: '2-digit',
    month: 'short',
    year: 'numeric',
    hour: '2-digit',
    minute: '2-digit',
  })
}

/* Waktu yang pantas ditampilkan untuk sebuah langkah. */
const approvalTime = (langkah: ApprovalRow): string =>
  formatDateTime(langkah.approved_at ?? langkah.rejected_at)

/* Warna penanda tiap langkah persetujuan. */
const APPROVAL_COLORS: Record<string, string> = {
  APPROVED: 'success',
  REJECTED: 'error',
  WAITING: 'warning',
  CANCELLED: 'secondary',
  SKIPPED: 'secondary',
}

const approvalColor = (status: string): string => APPROVAL_COLORS[status] ?? 'secondary'

const approvalIcon = (status: string): string => {
  if (status === 'APPROVED')
    return 'tabler-check'

  if (status === 'REJECTED')
    return 'tabler-x'

  return status === 'WAITING' ? 'tabler-clock' : 'tabler-minus'
}

const formatDate = (nilai?: string | null): string => {
  if (!nilai)
    return '-'

  const d = new Date(`${nilai}T00:00:00`)

  if (Number.isNaN(d.getTime()))
    return nilai

  return d.toLocaleDateString(undefined, {
    day: '2-digit',
    month: 'short',
    year: 'numeric',
  })
}
</script>

<template>
  <VCard v-if="trip">
    <!-- KEPALA: nomor, keadaan, dan lama perjalanan sekaligus -->
    <div
      class="bt-detail__header"
      :class="[`bt-detail__header--${detailAccent}`]"
    >
      <div class="d-flex align-center justify-space-between gap-3">
        <div class="bt-detail__eyebrow">
          <VIcon
            icon="tabler-plane"
            size="16"
          />
          {{ t('businessTrip.detail.title') }}
        </div>

        <VBtn
          icon
          variant="text"
          size="small"
          @click="emit('close')"
        >
          <VIcon icon="tabler-x" />
        </VBtn>
      </div>

      <div class="d-flex align-end justify-space-between flex-wrap gap-4 mt-2">
        <div class="min-w-0">
          <div class="bt-detail__number">
            {{ trip.trip_number || '—' }}
          </div>

          <div class="d-flex align-center flex-wrap gap-2 mt-2">
            <VChip
              size="small"
              variant="flat"
              :color="statusColor(trip.status)"
            >
              {{ t(`businessTrip.status.${trip.status}`) }}
            </VChip>

            <VChip
              v-if="trip.status === 'IN PROGRESS'"
              size="small"
              variant="tonal"
              :color="trip.allows_parallel_cash_advance ? 'success' : 'secondary'"
              :prepend-icon="trip.allows_parallel_cash_advance ? 'tabler-lock-open' : 'tabler-lock'"
            >
              {{ trip.allows_parallel_cash_advance
                ? t('businessTrip.list.fpuUnlockedBadge')
                : t('businessTrip.list.fpuLockedBadge') }}
            </VChip>

            <VChip
              v-if="trip.can_approve"
              size="small"
              variant="tonal"
              color="warning"
              prepend-icon="tabler-alert-circle"
            >
              {{ t('businessTrip.list.waitingMyApprovalBadge') }}
            </VChip>
          </div>
        </div>

        <div class="bt-detail__tile">
          <div class="bt-detail__tile-label">
            {{ t('businessTrip.columns.duration') }}
          </div>

          <div class="bt-detail__tile-value">
            {{ t('businessTrip.days', { count: trip.duration_days }) }}
          </div>
        </div>
      </div>
    </div>

    <VDivider />

    <VCardText class="bt-detail__body">
      <!-- Alasan penolakan atau pembatalan: yang paling perlu dibaca lebih dulu -->
      <VAlert
        v-if="trip.status === 'REJECTED' && trip.rejection_notes"
        type="error"
        variant="tonal"
        density="comfortable"
        class="mb-5"
        icon="tabler-message-2-x"
      >
        {{ trip.rejection_notes }}
      </VAlert>

      <VAlert
        v-else-if="trip.status === 'CANCELLED' && trip.cancellation_notes"
        type="warning"
        variant="tonal"
        density="comfortable"
        class="mb-5"
        icon="tabler-ban"
      >
        {{ trip.cancellation_notes }}
      </VAlert>

      <!-- PEMOHON -->
      <div class="bt-detail__person mb-5">
        <VAvatar
          size="46"
          color="primary"
          variant="tonal"
        >
          <VIcon
            icon="tabler-user"
            size="24"
          />
        </VAvatar>

        <div class="min-w-0">
          <div class="bt-detail__person-name">
            {{ trip.employee_name }}
          </div>

          <div class="text-body-2 text-medium-emphasis">
            {{ [trip.department_name, trip.position_name].filter(Boolean).join(' · ') || '-' }}
          </div>
        </div>
      </div>

      <!-- PERJALANAN: dibaca sebagai satu perjalanan, bukan dua tanggal -->
      <div class="bt-detail__section-title">
        <VIcon
          icon="tabler-map-pin"
          size="18"
        />
        {{ t('businessTrip.detail.period') }}
      </div>

      <div class="bt-detail__journey mb-5">
        <div class="bt-detail__journey-leg">
          <div class="bt-detail__label">
            {{ t('businessTrip.form.fields.departDate') }}
          </div>

          <div class="bt-detail__journey-date">
            {{ formatDate(trip.depart_date) }}
          </div>

          <div class="bt-detail__journey-time">
            <VIcon
              icon="tabler-clock"
              size="14"
            />
            {{ trip.depart_time || '-' }}
          </div>
        </div>

        <div class="bt-detail__journey-arrow">
          <VIcon
            icon="tabler-arrow-narrow-right"
            size="22"
          />

          <span>{{ trip.destination }}</span>
        </div>

        <div class="bt-detail__journey-leg text-end">
          <div class="bt-detail__label">
            {{ t('businessTrip.form.fields.returnDate') }}
          </div>

          <div class="bt-detail__journey-date">
            {{ formatDate(trip.return_date) }}
          </div>

          <div class="bt-detail__journey-time justify-end">
            <VIcon
              icon="tabler-clock"
              size="14"
            />
            {{ trip.return_time || '-' }}
          </div>
        </div>
      </div>

      <VRow class="mb-2">
        <VCol cols="12">
          <div class="bt-detail__label">
            {{ t('businessTrip.columns.purpose') }}
          </div>

          <div class="bt-detail__value">
            {{ trip.purpose }}
          </div>
        </VCol>

        <VCol
          v-if="trip.notes"
          cols="12"
        >
          <div class="bt-detail__label">
            {{ t('businessTrip.detail.notes') }}
          </div>

          <div class="bt-detail__value">
            {{ trip.notes }}
          </div>
        </VCol>
      </VRow>

      <!-- RUNDOWN: agenda per hari -->
      <div class="bt-detail__section-title mt-5">
        <VIcon
          icon="tabler-route"
          size="18"
        />
        {{ t('businessTrip.detail.itinerary') }}
      </div>

      <div
        v-for="(hari, index) in itineraryByDay"
        :key="`hari-${index}`"
        class="bt-day"
      >
        <div class="bt-day__head">
          <VAvatar
            size="28"
            color="primary"
            variant="tonal"
          >
            {{ index + 1 }}
          </VAvatar>

          <span>{{ formatDate(hari.date) }}</span>
        </div>

        <div
          v-for="baris in hari.rows"
          :key="`baris-${baris.sort_no}`"
          class="bt-day__row"
        >
          <div class="bt-day__time">
            {{ baris.time_text || '-' }}
          </div>

          <div class="bt-day__desc">
            {{ baris.description }}
          </div>

          <VChip
            v-if="baris.pic"
            size="x-small"
            variant="tonal"
            color="secondary"
            prepend-icon="tabler-user-check"
          >
            {{ baris.pic }}
          </VChip>
        </div>
      </div>

      <div
        v-if="!itineraryByDay.length"
        class="text-body-2 text-medium-emphasis py-3"
      >
        {{ t('businessTrip.list.empty') }}
      </div>

      <!--
        PERSETUJUAN

        Rantainya dimulai dari pengajuan, bukan dari penyetuju pertama:
        tanpa titik awal itu, tahap 1 seolah muncul entah dari mana.
        Langkah yang sedang aktif diberi cincin, supaya titik berhentinya
        terlihat tanpa membaca status satu per satu.
      -->
      <div class="bt-detail__section-title mt-5">
        <VIcon
          icon="tabler-writing-sign"
          size="18"
        />
        {{ t('businessTrip.detail.approvals') }}
      </div>

      <div class="bt-steps">
        <!-- Titik awal: dokumen berangkat dari tangan pemohonnya -->
        <div
          v-if="trip.submitted_at"
          class="bt-steps__item"
        >
          <VAvatar
            size="32"
            color="primary"
            variant="tonal"
            class="bt-steps__dot"
          >
            <VIcon
              icon="tabler-send"
              size="16"
            />
          </VAvatar>

          <div class="min-w-0 flex-grow-1">
            <div class="bt-steps__name">
              {{ trip.employee_name }}
            </div>

            <div class="bt-steps__meta">
              {{ t('businessTrip.detail.submittedBy') }}
              <span class="bt-steps__time">&middot; {{ formatDateTime(trip.submitted_at) }}</span>
            </div>
          </div>
        </div>

        <div
          v-for="(langkah, index) in trip.approvals"
          :key="`langkah-${index}`"
          class="bt-steps__item"
          :class="[{ 'bt-steps__item--active': langkah.status === 'WAITING' }]"
        >
          <VAvatar
            size="32"
            :color="approvalColor(langkah.status)"
            variant="tonal"
            class="bt-steps__dot"
          >
            <VIcon
              :icon="approvalIcon(langkah.status)"
              size="16"
            />
          </VAvatar>

          <div class="min-w-0 flex-grow-1">
            <div class="d-flex align-center justify-space-between flex-wrap gap-2">
              <div class="bt-steps__name">
                {{ langkah.approver_name || t('businessTrip.detail.anyHolder') }}
              </div>

              <VChip
                size="x-small"
                variant="tonal"
                :color="approvalColor(langkah.status)"
              >
                {{ t(`businessTrip.approvalStatus.${langkah.status}`) }}
              </VChip>
            </div>

            <div class="bt-steps__meta">
              <VChip
                size="x-small"
                variant="outlined"
                class="me-1"
              >
                {{ t('businessTrip.detail.step') }} {{ langkah.step_order }}
              </VChip>

              <span v-if="langkah.label">{{ langkah.label }}</span>

              <span
                v-if="langkah.approver_type === 'ROLE'"
                class="text-disabled"
              >&middot; {{ t('businessTrip.detail.byRole') }}</span>

              <span
                v-if="approvalTime(langkah)"
                class="bt-steps__time"
              >&middot; {{ approvalTime(langkah) }}</span>
            </div>

            <div
              v-if="langkah.notes"
              class="bt-steps__note"
            >
              {{ langkah.notes }}
            </div>
          </div>
        </div>

        <!-- Belum diajukan: rantainya memang belum dimulai -->
        <div
          v-if="!trip.submitted_at && !trip.approvals.length"
          class="text-body-2 text-medium-emphasis"
        >
          {{ t('businessTrip.detail.notSubmittedYet') }}
        </div>
      </div>
    </VCardText>

    <VDivider />

    <VCardActions class="justify-end flex-wrap gap-2 pa-4">
      <!--
        Tombol yang berbeda antar halaman dititipkan pemanggilnya:
        halaman perdin menitipkan Cetak/Tolak/Setujui, detail FPU tidak
        menitipkan apa-apa -- dari sana perdin memang tidak disetujui.
      -->
      <slot name="actions" />

      <VBtn
        variant="tonal"
        color="secondary"
        class="text-none"
        @click="emit('close')"
      >
        {{ t('common.actions.close') }}
      </VBtn>
    </VCardActions>
  </VCard>
</template>

<style scoped>
/*
 * Rincian perdin.
 *
 * Kosakata visualnya ditiru dari rincian FPU -- kepala berwarna status,
 * eyebrow huruf kecil, dan satu kotak angka di kanan -- supaya kedua modul
 * terasa satu aplikasi, bukan dua yang kebetulan bertetangga.
 */
.bt-detail__header {
  padding-block: 1.25rem;
  padding-inline: 1.5rem;
}

.bt-detail__header--success {
  background: linear-gradient(135deg, rgba(var(--v-theme-success), 0.14), transparent 70%);
}

.bt-detail__header--warning {
  background: linear-gradient(135deg, rgba(var(--v-theme-warning), 0.14), transparent 70%);
}

.bt-detail__header--error {
  background: linear-gradient(135deg, rgba(var(--v-theme-error), 0.14), transparent 70%);
}

.bt-detail__header--info {
  background: linear-gradient(135deg, rgba(var(--v-theme-info), 0.14), transparent 70%);
}

.bt-detail__header--secondary {
  background: linear-gradient(135deg, rgba(var(--v-theme-secondary), 0.12), transparent 70%);
}

.bt-detail__eyebrow {
  display: inline-flex;
  align-items: center;
  color: rgba(var(--v-theme-on-surface), 0.6);
  font-size: 0.75rem;
  font-weight: 600;
  gap: 0.375rem;
  letter-spacing: 0.06em;
  text-transform: uppercase;
}

.bt-detail__number {
  font-size: 1.375rem;
  font-weight: 700;
  line-height: 1.25;
}

.bt-detail__tile {
  border-radius: 10px;
  background: rgb(var(--v-theme-surface));
  box-shadow: 0 2px 8px rgba(var(--v-theme-on-surface), 0.08);
  padding-block: 0.5rem;
  padding-inline: 1rem;
  text-align: end;
}

.bt-detail__tile-label {
  color: rgba(var(--v-theme-on-surface), 0.6);
  font-size: 0.7rem;
  letter-spacing: 0.04em;
  text-transform: uppercase;
}

.bt-detail__tile-value {
  color: rgb(var(--v-theme-primary));
  font-size: 1.25rem;
  font-weight: 700;
  line-height: 1.3;
  white-space: nowrap;
}

.bt-detail__body {
  padding-block: 1.25rem;
  padding-inline: 1.5rem;
}

.bt-detail__section-title {
  display: flex;
  align-items: center;
  margin-block-end: 0.75rem;
  color: rgba(var(--v-theme-on-surface), 0.78);
  font-size: 0.8rem;
  font-weight: 700;
  gap: 0.5rem;
  letter-spacing: 0.05em;
  text-transform: uppercase;
}

.bt-detail__label {
  color: rgba(var(--v-theme-on-surface), 0.6);
  font-size: 0.72rem;
  letter-spacing: 0.03em;
  text-transform: uppercase;
}

.bt-detail__value {
  font-size: 0.95rem;
}

.bt-detail__person {
  display: flex;
  align-items: center;
  border: 1px solid rgba(var(--v-border-color), var(--v-border-opacity));
  border-radius: 10px;
  gap: 0.875rem;
  padding-block: 0.75rem;
  padding-inline: 1rem;
}

.bt-detail__person-name {
  font-size: 1.05rem;
  font-weight: 700;
}

/*
 * Periode dibaca sebagai satu perjalanan: berangkat di kiri, tujuan di
 * tengah, kembali di kanan. Dua tanggal berderet tidak menyampaikan bahwa
 * keduanya ujung dari hal yang sama.
 */
.bt-detail__journey {
  display: flex;
  align-items: center;
  justify-content: space-between;
  border: 1px solid rgba(var(--v-border-color), var(--v-border-opacity));
  border-radius: 10px;
  background: rgba(var(--v-theme-primary), 0.03);
  gap: 1rem;
  padding-block: 0.875rem;
  padding-inline: 1.125rem;
}

.bt-detail__journey-leg {
  min-inline-size: 0;
}

.bt-detail__journey-date {
  margin-block-start: 0.125rem;
  font-size: 1rem;
  font-weight: 700;
}

.bt-detail__journey-time {
  display: flex;
  align-items: center;
  color: rgba(var(--v-theme-on-surface), 0.6);
  font-size: 0.8rem;
  gap: 0.25rem;
}

.bt-detail__journey-arrow {
  display: flex;
  flex-direction: column;
  flex-shrink: 1;
  align-items: center;
  min-inline-size: 0;
  color: rgb(var(--v-theme-primary));
  font-size: 0.85rem;
  font-weight: 600;
  gap: 0.125rem;
  text-align: center;
}

/* Rundown sebagai agenda: satu blok per hari. */
.bt-day {
  margin-block-end: 0.875rem;
}

.bt-day__head {
  display: flex;
  align-items: center;
  margin-block-end: 0.375rem;
  font-size: 0.85rem;
  font-weight: 700;
  gap: 0.5rem;
}

.bt-day__row {
  display: flex;
  align-items: baseline;
  border-block-end: 1px dashed rgba(var(--v-border-color), var(--v-border-opacity));
  gap: 0.75rem;
  margin-inline-start: 2.375rem;
  padding-block: 0.4rem;
}

.bt-day__row:last-child {
  border-block-end: 0;
}

.bt-day__time {
  flex-shrink: 0;
  min-inline-size: 8.5rem;
  color: rgba(var(--v-theme-on-surface), 0.68);
  font-size: 0.8rem;
  font-variant-numeric: tabular-nums;
}

.bt-day__desc {
  flex-grow: 1;
  min-inline-size: 0;
  font-size: 0.9rem;
}

/* Persetujuan sebagai alur menurun, supaya terlihat berhenti di mana. */
.bt-steps__item {
  display: flex;
  position: relative;
  gap: 0.75rem;
  padding-block-end: 0.875rem;
}

.bt-steps__item:not(:last-child)::before {
  position: absolute;
  inset-block: 2rem 0;
  inset-inline-start: 0.9rem;
  border-inline-start: 1px dashed rgba(var(--v-border-color), var(--v-border-opacity));
  content: "";
}

.bt-steps__dot {
  flex-shrink: 0;
}

/* Langkah yang sedang ditunggu diberi cincin, bukan sekadar warna lain. */
.bt-steps__item--active .bt-steps__dot {
  box-shadow: 0 0 0 3px rgba(var(--v-theme-warning), 0.22);
}

.bt-steps__name {
  font-size: 0.95rem;
  font-weight: 600;
  line-height: 1.3;
}

.bt-steps__meta {
  display: flex;
  flex-wrap: wrap;
  align-items: center;
  margin-block-start: 0.25rem;
  color: rgba(var(--v-theme-on-surface), 0.6);
  font-size: 0.75rem;
  gap: 0.25rem;
}

.bt-steps__time {
  font-variant-numeric: tabular-nums;
}

.bt-steps__note {
  margin-block-start: 0.375rem;
  border-inline-start: 3px solid rgba(var(--v-border-color), var(--v-border-opacity));
  color: rgba(var(--v-theme-on-surface), 0.8);
  font-size: 0.85rem;
  padding-inline-start: 0.625rem;
}
</style>
