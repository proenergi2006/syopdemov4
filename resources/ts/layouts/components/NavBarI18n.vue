<script lang="ts" setup>
import NavBarI18n from '@core/components/I18n.vue'
import type { I18nLanguage } from '@layouts/types'

const i18nCompLanguages: I18nLanguage[] = [
  {
    label: 'Bahasa Indonesia',
    i18nLang: 'id',
  },
  {
    label: 'English',
    i18nLang: 'en',
  },
]
</script>

<template>
  <NavBarI18n :languages="i18nCompLanguages" />
</template>
