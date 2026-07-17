<script lang="ts" setup>
import { computed, inject, ref, watch } from 'vue'
import { injectionKeyIsVerticalNavHovered, useLayouts } from '@layouts'
import { TransitionExpand, VerticalNavLink } from '@layouts/components'
import { config } from '@layouts/config'
import { canViewNavMenuGroup } from '@layouts/plugins/casl'
import type { NavGroup } from '@layouts/types'
import { isNavGroupActive, openGroups } from '@layouts/utils'
import { useI18n } from 'vue-i18n'

const props = defineProps<{
  item: NavGroup
}>()

defineOptions({
  name: 'VerticalNavGroup',
})

const route = useRoute()
const router = useRouter()
const { width: windowWidth } = useWindowSize()
const { isVerticalNavMini } = useLayouts()
const { te, t } = useI18n()

const hideTitleAndBadge = isVerticalNavMini(windowWidth)

const isVerticalNavHovered = inject(
  injectionKeyIsVerticalNavHovered,
  ref(false),
)

const safeChildren = computed(() => {
  return Array.isArray(props.item?.children)
    ? props.item.children.filter(child => child && typeof child === 'object')
    : []
})

const hasValidChildren = (item: unknown): item is NavGroup => {
  return !!item
    && typeof item === 'object'
    && 'children' in item
    && Array.isArray((item as NavGroup).children)
    && (item as NavGroup).children.length > 0
}

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

const isGroupActive = ref(false)
const isGroupOpen = ref(false)

const isAnyChildOpen = (children: NavGroup['children']): boolean => {
  const normalizedChildren = Array.isArray(children)
    ? children.filter(child => child && typeof child === 'object')
    : []

  return normalizedChildren.some(child => {
    let result = openGroups.value.includes(child.title)

    if (hasValidChildren(child))
      result = isAnyChildOpen(child.children) || result

    return result
  })
}

const collapseChildren = (children: NavGroup['children']) => {
  const normalizedChildren = Array.isArray(children)
    ? children.filter(child => child && typeof child === 'object')
    : []

  normalizedChildren.forEach(child => {
    if (hasValidChildren(child))
      collapseChildren(child.children)

    openGroups.value = openGroups.value.filter(group => group !== child.title)
  })
}

/*
  Watch for route changes, more specifically route path.
*/
watch(() => route.path, () => {
  const isActive = isNavGroupActive(safeChildren.value, router)

  isGroupOpen.value = isActive
    && !isVerticalNavMini(windowWidth, isVerticalNavHovered).value

  isGroupActive.value = isActive
}, { immediate: true })

/*
  Watch for isGroupOpen.
*/
watch(isGroupOpen, (val: boolean) => {
  const grpIndex = openGroups.value.indexOf(props.item.title)

  if (val && grpIndex === -1) {
    openGroups.value.push(props.item.title)
  }
  else if (!val && grpIndex !== -1) {
    openGroups.value.splice(grpIndex, 1)
    collapseChildren(safeChildren.value)
  }
}, { immediate: true })

/*
  Watch for openGroups.
*/
watch(openGroups, val => {
  const lastOpenedGroup = val[val.length - 1]

  if (lastOpenedGroup === props.item.title)
    return

  const isActive = isNavGroupActive(safeChildren.value, router)

  if (isActive)
    return

  if (isAnyChildOpen(safeChildren.value))
    return

  isGroupOpen.value = isActive
  isGroupActive.value = isActive
}, { deep: true })

watch(isVerticalNavMini(windowWidth, isVerticalNavHovered), val => {
  isGroupOpen.value = val ? false : isGroupActive.value
})
</script>

<template>
  <li
    v-if="canViewNavMenuGroup(item)"
    class="nav-group"
    :class="[
      {
        active: isGroupActive,
        open: isGroupOpen,
        disabled: item.disable,
      },
    ]"
  >
    <div
      class="nav-group-label"
      @click="isGroupOpen = !isGroupOpen"
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

        <Component
          :is="config.app.iconRenderer || 'div'"
          v-show="!hideTitleAndBadge"
          v-bind="config.icons.chevronRight"
          key="arrow"
          class="nav-group-arrow"
        />
      </TransitionGroup>
    </div>

    <TransitionExpand>
      <ul
        v-show="isGroupOpen"
        class="nav-group-children"
      >
        <Component
          :is="hasValidChildren(child) ? 'VerticalNavGroup' : VerticalNavLink"
          v-for="child in safeChildren"
          :key="child.title"
          :item="child"
        />
      </ul>
    </TransitionExpand>
  </li>
</template>

<style lang="scss">
.layout-vertical-nav {
  .nav-group {
    &-label {
      display: flex;
      align-items: center;
      min-inline-size: 0;
      cursor: pointer;
    }

    .nav-group-arrow {
      flex-shrink: 0;
      margin-inline-start: 6px;
    }
  }
}
</style>