<script setup lang="ts">
import type { Anchor } from 'vuetify/lib/components'
import type { I18nLanguage } from '@layouts/types'
import { persistLocale } from '@/plugins/i18n'
import type { AppLocale } from '@/plugins/i18n'
import axios from '@axios'

const props = withDefaults(defineProps<Props>(), {
  location: 'bottom end',
})

const emit = defineEmits<{
  (e: 'change', id: string): void
}>()

interface Props {
  languages: I18nLanguage[]
  location?: Anchor
}

const { locale } = useI18n({ useScope: 'global' })

// ℹ️ Selection state mirrors the active locale so the menu always
// highlights the language that's actually in effect, including on load.
const currentLang = computed(() => [locale.value])

function selectLanguage(lang: I18nLanguage): void {
  locale.value = lang.i18nLang as AppLocale
  persistLocale(lang.i18nLang as AppLocale)
  emit('change', lang.i18nLang)

  /*
  |--------------------------------------------------------------------------
  | Sinkronkan ke backend
  |--------------------------------------------------------------------------
  | Supaya email/notifikasi yang dikirim ke user ini nanti (bukan hanya
  | request yang sedang berjalan) memakai bahasa pilihannya. Cukup untuk
  | user yang sudah login -- gagal diam-diam saja, ini bukan aksi wajib.
  |--------------------------------------------------------------------------
  */
  if (localStorage.getItem('accessToken')) {
    axios.put('/account/locale', {
      locale: lang.i18nLang,
    }).catch(() => {
      // Sengaja diabaikan -- locale FE tetap berubah walau sync gagal.
    })
  }
}
</script>

<template>
  <VBtn
    icon
    variant="text"
    color="default"
    size="small"
  >
    <VIcon
      icon="mdi-translate"
      size="24"
    />
    <!-- Menu -->
    <VMenu
      activator="parent"
      :location="props.location"
      offset="14px"
    >
      <!-- List -->
      <VList
        :selected="currentLang"
        active-color="primary"
        min-width="175px"
      >
        <!-- List item -->
        <VListItem
          v-for="lang in props.languages"
          :key="lang.i18nLang"
          :value="lang.i18nLang"
          @click="selectLanguage(lang)"
        >
          <!-- Language label -->
          <VListItemTitle>{{ lang.label }}</VListItemTitle>
        </VListItem>
      </VList>
    </VMenu>
  </VBtn>
</template>
