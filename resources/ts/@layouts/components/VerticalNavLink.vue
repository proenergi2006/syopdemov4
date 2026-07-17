<script lang="ts" setup>
import { computed } from 'vue'
import { useLayouts } from '@layouts'
import { config } from '@layouts/config'
import { can } from '@layouts/plugins/casl'
import type { NavLink } from '@layouts/types'
import { getComputedNavLinkToProp, isNavLinkActive } from '@layouts/utils'
import { useI18n } from 'vue-i18n'

const props = defineProps<{
  item: NavLink
}>()

const { width: windowWidth } = useWindowSize()
const { isVerticalNavMini } = useLayouts()
const { te, t } = useI18n()

const hideTitleAndBadge = isVerticalNavMini(windowWidth)

const navBadgeContent = computed(() => {
  const item = props.item as any

  const rawBadge = item.badgeContent
    ?? item.badge_count
    ?? item.badgeCount
    ?? null

  if (
    rawBadge === null
    || rawBadge === undefined
    || rawBadge === ''
  ) {
    return ''
  }

  const numericBadge = Number(rawBadge)

  if (!Number.isNaN(numericBadge)) {
    if (numericBadge <= 0)
      return ''

    return numericBadge > 99
      ? '99+'
      : String(numericBadge)
  }

  return String(rawBadge)
})

const navBadgeClass = computed(() => {
  const item = props.item as any

  return item.badgeClass || 'bg-error'
})
</script>

<template>
  <li
    v-if="can(item.action, item.subject)"
    class="nav-link"
    :class="{ disabled: item.disable }"
  >
    <Component
      :is="item.to ? 'RouterLink' : 'a'"
      v-bind="getComputedNavLinkToProp(item)"
      :class="{ 'router-link-active router-link-exact-active': isNavLinkActive(item, $router) }"
    >
      <Component
        :is="config.app.iconRenderer || 'div'"
        v-bind="item.icon || config.verticalNav.defaultNavItemIconProps"
        class="nav-item-icon"
      />

      <TransitionGroup name="transition-slide-x">
        <!-- 👉 Title -->
        <span
          v-show="!hideTitleAndBadge"
          key="title"
          class="nav-item-title"
        >
          {{ config.app.enableI18n && te(item.title) ? t(item.title) : item.title }}
        </span>

        <!-- 👉 Badge -->
        <span
          v-if="navBadgeContent"
          v-show="!hideTitleAndBadge"
          key="badge"
          class="nav-item-badge"
          :class="navBadgeClass"
        >
          {{
            config.app.enableI18n && te(String(navBadgeContent))
              ? t(String(navBadgeContent))
              : navBadgeContent
          }}
        </span>
      </TransitionGroup>
    </Component>
  </li>
</template>

<style lang="scss">
.layout-vertical-nav {
  .nav-link a {
    display: flex;
    align-items: center;
    min-inline-size: 0;
  }
}
</style>