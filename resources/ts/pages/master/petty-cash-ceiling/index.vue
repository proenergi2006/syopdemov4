<script setup lang="ts">
import { computed, onMounted, reactive, ref } from 'vue'
import { useI18n } from 'vue-i18n'
import axios from '@axios'
import {
  showConfirmAlert,
  showErrorToast,
  showSuccessToast,
} from '@/utils/alert'
import { getApiErrorMessage } from '@/utils/apiHelper'
import { usePermissionStore } from '@/stores/permission'
import { LOCALE_ANGKA } from '@/utils/numberFormatter'

/*
|--------------------------------------------------------------------------
| Master Plafon Petty Cash
|--------------------------------------------------------------------------
| Satu plafon per cabang. Keberadaan barisnya yang menentukan sebuah cabang
| memakai petty cash atau tidak -- jadi menonaktifkan plafon adalah cara
| mematikan fitur ini untuk satu cabang, tanpa menyentuh permission siapa pun.
|
| Saldo ditampilkan di sebelah plafon, bukan hanya plafonnya, karena yang
| ditanyakan orang selalu "tinggal berapa", bukan "batasnya berapa".
|--------------------------------------------------------------------------
*/

interface CeilingRow {
  public_id: string
  branch_id: number
  branch: string | null
  branch_code: string | null
  department_id: number
  department: string | null
  ceiling_amount: number
  claim_threshold: number
  warning_percentage: number
  balance: number
  topup_amount: number
  warning_threshold: number
  below_warning: boolean
  is_active: boolean
  notes: string | null
}

interface LedgerRow {
  entry_type: string
  amount: number
  balance_after: number
  reference_type: string | null
  notes: string | null
  created_by: string | null
  created_at: string | null
}

interface Opsi {
  value: number
  title: string
}

const { t } = useI18n()
const permissionStore = usePermissionStore()

const ENDPOINT = '/fund-request/petty-cash-ceilings'

const rows = ref<CeilingRow[]>([])
const loading = ref(false)

const branchOptions = ref<Opsi[]>([])
const departmentOptions = ref<Opsi[]>([])

const canView = computed(() => permissionStore.can('master_petty_cash_ceiling.view'))
const canCreate = computed(() => permissionStore.can('master_petty_cash_ceiling.create'))
const canUpdate = computed(() => permissionStore.can('master_petty_cash_ceiling.update'))
const canDelete = computed(() => permissionStore.can('master_petty_cash_ceiling.delete'))
const canAdjust = computed(() => permissionStore.can('master_petty_cash_ceiling.adjust'))

const rupiah = (nilai: number | null | undefined): string =>
  new Intl.NumberFormat(LOCALE_ANGKA, { maximumFractionDigits: 0 }).format(Number(nilai ?? 0))

const waktu = (nilai: string | null): string => {
  if (!nilai)
    return '-'

  return new Date(nilai).toLocaleString('id-ID', {
    day: '2-digit',
    month: 'short',
    year: 'numeric',
    hour: '2-digit',
    minute: '2-digit',
  })
}

const fetchRows = async (): Promise<void> => {
  loading.value = true

  try {
    const response = await axios.get(ENDPOINT, { headers: { Accept: 'application/json' } })

    rows.value = response.data?.data ?? []
  }
  catch (error: unknown) {
    showErrorToast({
      title: t('common.alert.error'),
      text: getApiErrorMessage(error, t('pettyCash.ceiling.loadFailed')),
    })
  }
  finally {
    loading.value = false
  }
}

const fetchOptions = async (): Promise<void> => {
  try {
    const [cabang, department] = await Promise.all([
      axios.get('/master/cabang/dropdown-select', { headers: { Accept: 'application/json' } }),
      axios.get('/master/department/dropdown-select', { headers: { Accept: 'application/json' } }),
    ])

    branchOptions.value = (cabang?.data?.data ?? []).map((x: Record<string, unknown>) => ({
      value: Number(x.id),
      title: String(x.nama_cabang ?? x.nama ?? x.title ?? ''),
    }))

    departmentOptions.value = (department?.data?.data ?? []).map((x: Record<string, unknown>) => ({
      value: Number(x.id),
      title: String(x.nama ?? x.title ?? ''),
    }))
  }
  catch {
    branchOptions.value = []
    departmentOptions.value = []
  }
}

/*
|--------------------------------------------------------------------------
| Formulir plafon
|--------------------------------------------------------------------------
*/
const dialog = ref(false)
const saving = ref(false)
const editing = ref<CeilingRow | null>(null)

/*
 * Plafon dimulai KOSONG, bukan 0.
 *
 * Nol adalah angka yang sudah diisi, dan angka yang sudah diisi berarti
 * sudah bisa dinilai salah -- itulah yang membuat formulir ini menyalakan
 * galat merah sebelum siapa pun mengetik apa pun. Kosong berarti belum
 * dijawab, dan yang belum dijawab tidak salah.
 */
const form = reactive({
  branch_id: null as number | null,
  department_id: null as number | null,
  ceiling_amount: null as number | null,
  claim_threshold: 500000,
  warning_percentage: 50,
  is_active: true,
  notes: '',
})

/*
 * Baru dinilai setelah plafonnya benar-benar diisi. Sebelum itu tidak ada
 * yang bisa dibandingkan, jadi tidak ada yang bisa dinyatakan salah.
 */
const ambangLebihBesar = computed(
  () =>
    Number(form.ceiling_amount) > 0
    && Number(form.claim_threshold) > Number(form.ceiling_amount),
)

const formValid = computed(
  () =>
    !!form.branch_id
    && !!form.department_id
    && Number(form.ceiling_amount) > 0
    && Number(form.claim_threshold) > 0
    && Number(form.warning_percentage) > 0
    && !ambangLebihBesar.value,
)

const openCreate = (): void => {
  editing.value = null

  form.branch_id = null
  form.department_id = departmentOptions.value.find(d => d.title === 'Finance')?.value ?? null
  form.ceiling_amount = null
  form.claim_threshold = 500000
  form.warning_percentage = 50
  form.is_active = true
  form.notes = ''

  dialog.value = true
}

const openEdit = (row: CeilingRow): void => {
  editing.value = row

  form.branch_id = row.branch_id
  form.department_id = row.department_id
  form.ceiling_amount = row.ceiling_amount
  form.claim_threshold = row.claim_threshold
  form.warning_percentage = row.warning_percentage
  form.is_active = row.is_active
  form.notes = row.notes ?? ''

  dialog.value = true
}

const save = async (): Promise<void> => {
  if (!formValid.value)
    return

  saving.value = true

  try {
    if (editing.value) {
      await axios.put(`${ENDPOINT}/${editing.value.public_id}`, {
        ceiling_amount: form.ceiling_amount,
        claim_threshold: form.claim_threshold,
        warning_percentage: form.warning_percentage,
        is_active: form.is_active,
        notes: form.notes || null,
      })
    }
    else {
      await axios.post(ENDPOINT, {
        branch_id: form.branch_id,
        department_id: form.department_id,
        ceiling_amount: form.ceiling_amount,
        claim_threshold: form.claim_threshold,
        warning_percentage: form.warning_percentage,
        notes: form.notes || null,
      })
    }

    dialog.value = false

    showSuccessToast({
      title: t('common.alert.success'),
      text: t('pettyCash.ceiling.saved'),
    })

    await fetchRows()
  }
  catch (error: unknown) {
    showErrorToast({
      title: t('common.alert.error'),
      text: getApiErrorMessage(error, t('pettyCash.ceiling.loadFailed')),
    })
  }
  finally {
    saving.value = false
  }
}

const remove = async (row: CeilingRow): Promise<void> => {
  const konfirmasi = await showConfirmAlert({
    title: t('pettyCash.ceiling.deleteConfirm.title'),
    text: t('pettyCash.ceiling.deleteConfirm.text'),
  })

  if (!konfirmasi?.isConfirmed)
    return

  try {
    await axios.delete(`${ENDPOINT}/${row.public_id}`)

    showSuccessToast({
      title: t('common.alert.success'),
      text: t('pettyCash.ceiling.deleted'),
    })

    await fetchRows()
  }
  catch (error: unknown) {
    showErrorToast({
      title: t('common.alert.error'),
      text: getApiErrorMessage(error, t('pettyCash.action.failed')),
    })
  }
}

/*
|--------------------------------------------------------------------------
| Buku mutasi
|--------------------------------------------------------------------------
*/
const ledgerDialog = ref(false)
const ledgerLoading = ref(false)
const ledgerRows = ref<LedgerRow[]>([])
const ledgerFor = ref<CeilingRow | null>(null)

const openLedger = async (row: CeilingRow): Promise<void> => {
  ledgerFor.value = row
  ledgerRows.value = []
  ledgerDialog.value = true
  ledgerLoading.value = true

  try {
    const response = await axios.get(`${ENDPOINT}/${row.public_id}/ledger`, {
      headers: { Accept: 'application/json' },
    })

    ledgerRows.value = response.data?.data?.data ?? []
  }
  catch (error: unknown) {
    showErrorToast({
      title: t('common.alert.error'),
      text: getApiErrorMessage(error, t('pettyCash.ceiling.loadFailed')),
    })
  }
  finally {
    ledgerLoading.value = false
  }
}

const jenisMutasi = (kode: string): string => {
  const kunci = `pettyCash.ceiling.ledger.types.${kode}`
  const hasil = t(kunci)

  /* Jenis baru yang belum punya terjemahan tetap terbaca apa adanya. */
  return hasil === kunci ? kode : hasil
}

/*
|--------------------------------------------------------------------------
| Penyesuaian saldo
|--------------------------------------------------------------------------
*/
const adjustDialog = ref(false)
const adjustSaving = ref(false)
const adjustFor = ref<CeilingRow | null>(null)

const adjustForm = reactive({
  amount: 0,
  notes: '',
})

const adjustValid = computed(
  () => Number(adjustForm.amount) !== 0 && adjustForm.notes.trim().length >= 5,
)

const openAdjust = (row: CeilingRow): void => {
  adjustFor.value = row
  adjustForm.amount = 0
  adjustForm.notes = ''
  adjustDialog.value = true
}

const saveAdjust = async (): Promise<void> => {
  if (!adjustFor.value || !adjustValid.value)
    return

  adjustSaving.value = true

  try {
    await axios.patch(`${ENDPOINT}/${adjustFor.value.public_id}/adjust`, {
      amount: adjustForm.amount,
      notes: adjustForm.notes,
    })

    adjustDialog.value = false

    showSuccessToast({
      title: t('common.alert.success'),
      text: t('pettyCash.ceiling.adjusted'),
    })

    await fetchRows()
  }
  catch (error: unknown) {
    showErrorToast({
      title: t('common.alert.error'),
      text: getApiErrorMessage(error, t('pettyCash.action.failed')),
    })
  }
  finally {
    adjustSaving.value = false
  }
}

onMounted(async () => {
  if (!canView.value)
    return

  await fetchOptions()
  await fetchRows()
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
          {{ t('pettyCash.noAccess') }}
        </div>
      </VCardText>
    </VCard>

    <template v-else>
      <!-- PENJELASAN SINGKAT -->
      <VCard class="mb-4">
        <VCardText class="d-flex align-start gap-3 py-4">
          <VAvatar
            size="40"
            color="primary"
            variant="tonal"
            rounded
          >
            <VIcon
              icon="tabler-wallet"
              size="22"
            />
          </VAvatar>

          <div class="min-w-0">
            <div class="text-body-1 font-weight-medium mb-1">
              {{ t('pettyCash.ceiling.intro.title') }}
            </div>

            <div class="text-body-2 text-medium-emphasis">
              {{ t('pettyCash.ceiling.intro.body') }}
            </div>

            <VAlert
              type="info"
              variant="tonal"
              density="compact"
              class="mt-3"
            >
              {{ t('pettyCash.ceiling.intro.notice') }}
            </VAlert>
          </div>
        </VCardText>
      </VCard>

      <!-- DAFTAR -->
      <VCard>
        <!--
          Judul dan tombol disusun sendiri, tidak lewat slot #append milik
          VCardItem.

          Slot itu menempatkan tombol pada kolom grid yang sama tingginya
          dengan teks, sehingga subtitle terpaksa dipendekkan jadi satu baris
          ber-elipsis -- kalimatnya terpotong di tengah dan tidak menjelaskan
          apa pun. Dengan flex yang boleh membungkus, teksnya utuh, tombolnya
          tidak ikut menyusut, dan pada layar sempit tombolnya turun ke bawah
          alih-alih mendesak judulnya.
        -->
        <VCardItem class="pc-header">
          <div class="d-flex flex-wrap align-start justify-space-between gap-4">
            <div class="pc-header__text">
              <VCardTitle class="px-0 pt-0">
                {{ t('pettyCash.ceiling.title') }}
              </VCardTitle>

              <VCardSubtitle class="px-0">
                {{ t('pettyCash.ceiling.subtitle') }}
              </VCardSubtitle>
            </div>

            <VBtn
              v-if="canCreate"
              color="primary"
              prepend-icon="tabler-plus"
              class="text-none flex-shrink-0"
              @click="openCreate"
            >
              {{ t('pettyCash.ceiling.addButton') }}
            </VBtn>
          </div>
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
            v-if="!loading && rows.length === 0"
            class="text-center py-10 text-medium-emphasis"
          >
            {{ t('pettyCash.ceiling.empty') }}
          </div>

          <VTable
            v-else
            density="comfortable"
          >
            <thead>
              <tr>
                <th>{{ t('pettyCash.ceiling.columns.branch') }}</th>
                <th>{{ t('pettyCash.ceiling.columns.department') }}</th>
                <th class="text-end">
                  {{ t('pettyCash.ceiling.columns.ceiling') }}
                </th>
                <th class="text-end">
                  {{ t('pettyCash.ceiling.columns.balance') }}
                </th>
                <th class="text-end">
                  {{ t('pettyCash.ceiling.columns.threshold') }}
                </th>
                <th class="text-end">
                  {{ t('pettyCash.ceiling.columns.topup') }}
                </th>
                <th>{{ t('pettyCash.ceiling.columns.status') }}</th>
                <th class="text-end">
                  {{ t('pettyCash.ceiling.columns.actions') }}
                </th>
              </tr>
            </thead>

            <tbody>
              <tr
                v-for="row in rows"
                :key="row.public_id"
              >
                <td>
                  <div class="font-weight-medium">
                    {{ row.branch ?? '-' }}
                  </div>

                  <div class="text-caption text-medium-emphasis">
                    {{ row.branch_code ?? '' }}
                  </div>
                </td>

                <td>{{ row.department ?? '-' }}</td>

                <td class="text-end">
                  {{ rupiah(row.ceiling_amount) }}
                </td>

                <!--
                  Saldo diberi warna hanya ketika di bawah ambang. Mewarnai
                  seluruh kolom membuat yang penting tidak lagi menonjol.
                -->
                <td class="text-end">
                  <span
                    :class="row.below_warning ? 'text-error font-weight-medium' : ''"
                  >
                    {{ rupiah(row.balance) }}
                  </span>

                  <VIcon
                    v-if="row.below_warning"
                    icon="tabler-alert-triangle"
                    size="16"
                    color="error"
                    class="ms-1"
                  />
                </td>

                <td class="text-end">
                  {{ rupiah(row.claim_threshold) }}
                </td>

                <td class="text-end">
                  {{ row.topup_amount > 0 ? rupiah(row.topup_amount) : '-' }}
                </td>

                <td>
                  <VChip
                    :color="row.is_active ? 'success' : 'secondary'"
                    size="small"
                    variant="tonal"
                  >
                    {{ row.is_active ? t('pettyCash.ceiling.form.active') : '-' }}
                  </VChip>
                </td>

                <td class="text-end">
                  <VBtn
                    icon
                    variant="text"
                    size="small"
                    :title="t('pettyCash.action.ledger')"
                    @click="openLedger(row)"
                  >
                    <VIcon icon="tabler-list-details" />
                  </VBtn>

                  <VBtn
                    v-if="canAdjust"
                    icon
                    variant="text"
                    size="small"
                    :title="t('pettyCash.action.adjust')"
                    @click="openAdjust(row)"
                  >
                    <VIcon icon="tabler-adjustments" />
                  </VBtn>

                  <VBtn
                    v-if="canUpdate"
                    icon
                    variant="text"
                    size="small"
                    :title="t('pettyCash.action.edit')"
                    @click="openEdit(row)"
                  >
                    <VIcon icon="tabler-pencil" />
                  </VBtn>

                  <VBtn
                    v-if="canDelete"
                    icon
                    variant="text"
                    size="small"
                    color="error"
                    :title="t('pettyCash.action.delete')"
                    @click="remove(row)"
                  >
                    <VIcon icon="tabler-trash" />
                  </VBtn>
                </td>
              </tr>
            </tbody>
          </VTable>
        </VCardText>
      </VCard>

      <!-- FORMULIR PLAFON -->
      <VDialog
        v-model="dialog"
        max-width="760"
      >
        <VCard>
          <VCardItem>
            <VCardTitle>
              {{ editing ? t('pettyCash.ceiling.form.editTitle') : t('pettyCash.ceiling.form.createTitle') }}
            </VCardTitle>
          </VCardItem>

          <VDivider />

          <VCardText>
            <VRow>
              <VCol
                cols="12"
                md="6"
              >
                <VSelect
                  v-model="form.branch_id"
                  :items="branchOptions"
                  :label="t('pettyCash.ceiling.form.branch')"
                  :disabled="!!editing"
                />
              </VCol>

              <VCol
                cols="12"
                md="6"
              >
                <VSelect
                  v-model="form.department_id"
                  :items="departmentOptions"
                  :label="t('pettyCash.ceiling.form.department')"
                  :disabled="!!editing"
                />
              </VCol>

              <VCol
                cols="12"
                md="6"
              >
                <AppNumberField
                  v-model="form.ceiling_amount"
                  prefix="Rp"
                  :label="t('pettyCash.ceiling.form.ceiling')"
                  :hint="t('pettyCash.ceiling.form.ceilingHint')"
                  persistent-hint
                />
              </VCol>

              <VCol
                cols="12"
                md="6"
              >
                <!--
                  Keterangannya ada di hint, bukan di label. Label panjang pada
                  kolom selebar setengah dialog akan terpotong di tengah
                  kalimat, dan kalimat yang terpotong tidak menjelaskan apa pun.

                  Galatnya pun hanya di sini, tidak diulang sebagai alert di
                  bawah: satu kesalahan yang diberitahukan dua kali membuat
                  orang mencari dua kesalahan.
                -->
                <AppNumberField
                  v-model="form.claim_threshold"
                  prefix="Rp"
                  :label="t('pettyCash.ceiling.form.threshold')"
                  :hint="t('pettyCash.ceiling.form.thresholdHint')"
                  :error-messages="ambangLebihBesar ? [t('pettyCash.ceiling.form.thresholdError')] : []"
                  persistent-hint
                />
              </VCol>

              <VCol
                cols="12"
                md="6"
              >
                <VTextField
                  v-model.number="form.warning_percentage"
                  type="number"
                  min="1"
                  max="100"
                  suffix="%"
                  :label="t('pettyCash.ceiling.form.warning')"
                  :hint="t('pettyCash.ceiling.form.warningHint')"
                  persistent-hint
                />
              </VCol>

              <VCol
                v-if="editing"
                cols="12"
                md="6"
                class="d-flex align-center"
              >
                <VSwitch
                  v-model="form.is_active"
                  :label="t('pettyCash.ceiling.form.active')"
                />
              </VCol>

              <VCol cols="12">
                <VTextarea
                  v-model="form.notes"
                  rows="2"
                  :label="t('pettyCash.ceiling.form.notes')"
                />
              </VCol>

              <VCol
                v-if="!editing"
                cols="12"
              >
                <VAlert
                  type="info"
                  variant="tonal"
                  density="compact"
                >
                  {{ t('pettyCash.ceiling.form.openingNotice') }}
                </VAlert>
              </VCol>
            </VRow>
          </VCardText>

          <VDivider />

          <VCardActions>
            <VSpacer />

            <VBtn
              variant="text"
              class="text-none"
              @click="dialog = false"
            >
              {{ t('pettyCash.action.close') }}
            </VBtn>

            <VBtn
              color="primary"
              class="text-none"
              :loading="saving"
              :disabled="!formValid"
              @click="save"
            >
              {{ t('pettyCash.action.save') }}
            </VBtn>
          </VCardActions>
        </VCard>
      </VDialog>

      <!-- BUKU MUTASI -->
      <VDialog
        v-model="ledgerDialog"
        max-width="920"
      >
        <VCard>
          <VCardItem class="pc-header">
            <VCardTitle>
              {{ t('pettyCash.ceiling.ledger.title') }} — {{ ledgerFor?.branch ?? '-' }}
            </VCardTitle>

            <VCardSubtitle>{{ t('pettyCash.ceiling.ledger.subtitle') }}</VCardSubtitle>
          </VCardItem>

          <VDivider />

          <VCardText>
            <VProgressLinear
              v-if="ledgerLoading"
              indeterminate
              color="primary"
              class="mb-4"
            />

            <div
              v-if="!ledgerLoading && ledgerRows.length === 0"
              class="text-center py-8 text-medium-emphasis"
            >
              {{ t('pettyCash.ceiling.ledger.empty') }}
            </div>

            <VTable
              v-else
              density="compact"
            >
              <thead>
                <tr>
                  <th>{{ t('pettyCash.ceiling.ledger.columns.date') }}</th>
                  <th>{{ t('pettyCash.ceiling.ledger.columns.type') }}</th>
                  <th class="text-end">
                    {{ t('pettyCash.ceiling.ledger.columns.amount') }}
                  </th>
                  <th class="text-end">
                    {{ t('pettyCash.ceiling.ledger.columns.balance') }}
                  </th>
                  <th>{{ t('pettyCash.ceiling.ledger.columns.notes') }}</th>
                  <th>{{ t('pettyCash.ceiling.ledger.columns.by') }}</th>
                </tr>
              </thead>

              <tbody>
                <tr
                  v-for="(m, i) in ledgerRows"
                  :key="i"
                >
                  <td class="text-no-wrap">
                    {{ waktu(m.created_at) }}
                  </td>

                  <td>
                    <VChip
                      size="small"
                      variant="tonal"
                      :color="m.amount < 0 ? 'error' : 'success'"
                    >
                      {{ jenisMutasi(m.entry_type) }}
                    </VChip>
                  </td>

                  <td
                    class="text-end"
                    :class="m.amount < 0 ? 'text-error' : 'text-success'"
                  >
                    {{ m.amount < 0 ? '-' : '+' }}{{ rupiah(Math.abs(m.amount)) }}
                  </td>

                  <td class="text-end font-weight-medium">
                    {{ rupiah(m.balance_after) }}
                  </td>

                  <td class="text-body-2">
                    {{ m.notes ?? '-' }}
                  </td>

                  <td class="text-body-2">
                    {{ m.created_by ?? '-' }}
                  </td>
                </tr>
              </tbody>
            </VTable>
          </VCardText>

          <VDivider />

          <VCardActions>
            <VSpacer />

            <VBtn
              variant="text"
              class="text-none"
              @click="ledgerDialog = false"
            >
              {{ t('pettyCash.action.close') }}
            </VBtn>
          </VCardActions>
        </VCard>
      </VDialog>

      <!-- PENYESUAIAN SALDO -->
      <VDialog
        v-model="adjustDialog"
        max-width="560"
      >
        <VCard>
          <VCardItem class="pc-header">
            <VCardTitle>
              {{ t('pettyCash.ceiling.adjust.title') }} — {{ adjustFor?.branch ?? '-' }}
            </VCardTitle>

            <VCardSubtitle>{{ t('pettyCash.ceiling.adjust.subtitle') }}</VCardSubtitle>
          </VCardItem>

          <VDivider />

          <VCardText>
            <div class="text-body-2 text-medium-emphasis mb-4">
              {{ t('pettyCash.warningPanel.balance') }}:
              <span class="font-weight-medium">{{ rupiah(adjustFor?.balance) }}</span>
            </div>

            <!--
              Penyesuaian saldo boleh minus -- itulah gunanya: selisih hasil
              hitung fisik bisa jatuh ke dua arah.
            -->
            <AppNumberField
              v-model="adjustForm.amount"
              allow-negative
              empty-as-zero
              prefix="Rp"
              class="mb-4"
              :label="t('pettyCash.ceiling.adjust.amount')"
              :hint="t('pettyCash.ceiling.adjust.amountHint')"
              persistent-hint
            />

            <VTextarea
              v-model="adjustForm.notes"
              rows="3"
              :label="t('pettyCash.ceiling.adjust.notes')"
              :hint="t('pettyCash.ceiling.adjust.notesHint')"
              persistent-hint
            />
          </VCardText>

          <VDivider />

          <VCardActions>
            <VSpacer />

            <VBtn
              variant="text"
              class="text-none"
              @click="adjustDialog = false"
            >
              {{ t('pettyCash.action.close') }}
            </VBtn>

            <VBtn
              color="warning"
              class="text-none"
              :loading="adjustSaving"
              :disabled="!adjustValid"
              @click="saveAdjust"
            >
              {{ t('pettyCash.ceiling.adjust.button') }}
            </VBtn>
          </VCardActions>
        </VCard>
      </VDialog>
    </template>
  </div>
</template>

<style scoped>
/*
 * VCardSubtitle bawaan Vuetify dipaksa satu baris ber-elipsis. Untuk judul
 * halaman yang kalimatnya memang panjang, itu berarti penjelasannya terpotong
 * justru di bagian yang menjelaskan.
 */
.pc-header :deep(.v-card-subtitle) {
  overflow: visible;
  line-height: 1.5;
  text-overflow: clip;
  white-space: normal;
}

/*
 * Teks boleh melebar sampai batas yang masih nyaman dibaca, lalu membungkus.
 * Tanpa batas ini, satu baris penjelasan di layar lebar jadi terlalu panjang
 * untuk diikuti mata.
 */
.pc-header__text {
  flex: 1 1 320px;
  min-inline-size: 0;
  max-inline-size: 760px;
}
</style>
