<script setup lang="ts">
import { computed, onMounted, ref } from 'vue'
import { useI18n } from 'vue-i18n'
import { useRouter } from 'vue-router'
import axios from '@axios'
import { usePermissionStore } from '@/stores/permission'
import { LOCALE_ANGKA } from '@/utils/numberFormatter'

/*
|--------------------------------------------------------------------------
| Panel saldo kas kecil
|--------------------------------------------------------------------------
| Inilah yang memunculkan peringatan "saldo menipis" di hadapan pemegang kas
| kecil. Peringatannya juga dikirim sebagai notifikasi harian, tetapi
| notifikasi bisa terlewat begitu ada yang lain menumpuk di atasnya; panel ini
| ada di halaman yang dibuka setiap hari, dan ia hilang sendiri begitu
| saldonya sudah di atas ambang.
|
| TIDAK MENAMPILKAN APA PUN bagi yang bukan pemegang kas kecil. Komponennya
| bisa ditempel di mana saja -- daftar petty cash, dashboard -- tanpa perlu
| yang menempelkannya tahu siapa pemegang kas kecil dan siapa bukan.
|--------------------------------------------------------------------------
*/

interface TopupBerjalan {
  topup_number: string
  status: string
  amount: number
}

interface KasKecil {
  public_id: string
  branch_id: number
  branch: string | null
  ceiling_amount: number
  balance: number
  claim_threshold: number
  warning_percentage: number
  warning_threshold: number
  below_warning: boolean
  topup_amount: number
  running_topup: TopupBerjalan | null
  pending_claims: number
}

const props = withDefaults(defineProps<{

  /** Hanya menampilkan kartu yang saldonya di bawah ambang. */
  warningsOnly?: boolean
}>(), {
  warningsOnly: false,
})

const { t } = useI18n()
const router = useRouter()
const permissionStore = usePermissionStore()

const rows = ref<KasKecil[]>([])
const loading = ref(false)

const rupiah = (nilai: number | null | undefined): string =>
  new Intl.NumberFormat(LOCALE_ANGKA, { maximumFractionDigits: 0 }).format(Number(nilai ?? 0))

const terlihat = computed(() =>
  props.warningsOnly ? rows.value.filter(r => r.below_warning) : rows.value,
)

const bolehAjukanTopup = computed(() => permissionStore.can('petty_cash_topup.create'))

const muat = async (): Promise<void> => {
  if (!permissionStore.can('petty_cash.view'))
    return

  loading.value = true

  try {
    const response = await axios.get('/fund-request/petty-cash/my-ceilings', {
      headers: { Accept: 'application/json' },
    })

    rows.value = response.data?.data ?? []
  }
  catch {
    /*
     * Panel tambahan. Kegagalan memuatnya tidak boleh mengganggu halaman
     * yang menempelkannya -- yang terlihat cukup panel yang tidak muncul.
     */
    rows.value = []
  }
  finally {
    loading.value = false
  }
}

const keTopup = (): void => {
  router.push('/fund_request/petty_cash_topup')
}

onMounted(muat)

defineExpose({ muat })
</script>

<template>
  <div v-if="terlihat.length > 0">
    <VRow>
      <VCol
        v-for="row in terlihat"
        :key="row.public_id"
        cols="12"
        md="6"
      >
        <VCard
          :color="row.below_warning ? 'error' : undefined"
          :variant="row.below_warning ? 'tonal' : 'outlined'"
        >
          <VCardText>
            <div class="d-flex align-start gap-3">
              <VAvatar
                size="40"
                rounded
                variant="tonal"
                :color="row.below_warning ? 'error' : 'success'"
              >
                <VIcon
                  :icon="row.below_warning ? 'tabler-alert-triangle' : 'tabler-wallet'"
                  size="22"
                />
              </VAvatar>

              <div class="min-w-0 flex-grow-1">
                <div class="text-body-1 font-weight-medium">
                  <template v-if="row.below_warning">
                    {{ t('pettyCash.warningPanel.lowTitle', { branch: row.branch ?? '-' }) }}
                  </template>

                  <template v-else>
                    {{ row.branch ?? '-' }}
                  </template>
                </div>

                <div class="text-body-2 text-medium-emphasis mt-1">
                  <template v-if="row.below_warning">
                    {{ t('pettyCash.warningPanel.lowBody', {
                      balance: rupiah(row.balance),
                      ceiling: rupiah(row.ceiling_amount),
                      percentage: row.warning_percentage,
                    }) }}
                  </template>

                  <template v-else>
                    {{ t('pettyCash.warningPanel.balance') }} {{ rupiah(row.balance) }}
                    / {{ t('pettyCash.warningPanel.ceiling') }} {{ rupiah(row.ceiling_amount) }}
                  </template>
                </div>

                <!--
                  Tiga keterangan yang mengubah keputusan ADH, dan hanya
                  ditampilkan ketika memang berlaku:
                  - top up yang sedang berjalan, supaya ia tidak mengajukan dua kali
                  - claim yang menunggu, supaya ia tahu seberapa mendesak
                  - tombol top up, hanya kalau memang perlu dan boleh
                -->
                <div
                  v-if="row.running_topup"
                  class="text-body-2 mt-2"
                >
                  <VIcon
                    icon="tabler-clock"
                    size="16"
                    class="me-1"
                  />

                  {{ t('pettyCash.warningPanel.runningTopup', {
                    number: row.running_topup.topup_number,
                    status: row.running_topup.status,
                  }) }}
                </div>

                <div
                  v-if="row.pending_claims > 0"
                  class="text-body-2 mt-1"
                >
                  <VIcon
                    icon="tabler-receipt"
                    size="16"
                    class="me-1"
                  />

                  {{ t('pettyCash.warningPanel.pendingClaims', { count: row.pending_claims }) }}
                </div>

                <VBtn
                  v-if="row.below_warning && !row.running_topup && bolehAjukanTopup && row.topup_amount > 0"
                  color="error"
                  variant="flat"
                  size="small"
                  class="text-none mt-3"
                  prepend-icon="tabler-cash-banknote"
                  @click="keTopup"
                >
                  {{ t('pettyCash.warningPanel.topupCta', { amount: rupiah(row.topup_amount) }) }}
                </VBtn>
              </div>
            </div>
          </VCardText>
        </VCard>
      </VCol>
    </VRow>
  </div>
</template>
