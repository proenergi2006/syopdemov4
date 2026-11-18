<script lang="ts" setup>
import { useRoute } from 'vue-router'
import AccountSettingsAccount from '@/views/pages/account-settings/AccountSettingsAccount.vue'
import AccountSettingsBillingAndPlans from '@/views/pages/account-settings/AccountSettingsBillingAndPlans.vue'
import AccountSettingsConnections from '@/views/pages/account-settings/AccountSettingsConnections.vue'
import AccountSettingsNotification from '@/views/pages/account-settings/AccountSettingsNotification.vue'
import AccountSettingsSecurity from '@/views/pages/account-settings/AccountSettingsSecurity.vue'
import AccountSettingsSignature from '@/views/pages/account-settings/AccountSettingsSignature.vue'

const route = useRoute()

const activeTab = ref(route.params.tab)

// tabs
/*
| Tanda tangan sengaja dipisah dari Security. Yang ada di Security adalah
| pengamanan akun -- password, dan nanti OTP. Tanda tangan bukan pengaman:
| ia identitas yang tercetak di dokumen, dan dikelola dengan alur sendiri.
*/
const tabs = [
  { title: 'Security', icon: 'mdi-lock-open-outline', tab: 'security' },
  { title: 'Tanda Tangan', icon: 'mdi-signature-freehand', tab: 'signature' },
]
</script>

<template>
  <div>
    <VTabs
      v-model="activeTab"
      class="v-tabs-pill"
    >
      <VTab
        v-for="item in tabs"
        :key="item.icon"
        :value="item.tab"
        :to="{ name: 'pages-account-settings-tab', params: { tab: item.tab } }"
      >
        <VIcon
          size="20"
          start
          :icon="item.icon"
        />
        {{ item.title }}
      </VTab>
    </VTabs>

    <VWindow
      v-model="activeTab"
      class="mt-4 disable-tab-transition"
      :touch="false"
    >
      <!-- Security -->
      <VWindowItem value="security">
        <AccountSettingsSecurity />
      </VWindowItem>

      <!-- Tanda tangan digital -->
      <VWindowItem value="signature">
        <AccountSettingsSignature />
      </VWindowItem>
    </VWindow>
  </div>
</template>

<route lang="yaml">
meta:
  navActiveLink: pages-account-settings-tab
</route>
