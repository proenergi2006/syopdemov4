<script lang="ts" setup>
import { computed, provide, ref, watch } from 'vue'
import type { Component } from 'vue'
import { PerfectScrollbar } from 'vue3-perfect-scrollbar'
import { VNodeRenderer } from './VNodeRenderer'
import { injectionKeyIsVerticalNavHovered, useLayouts } from '@layouts'
import { VerticalNavGroup, VerticalNavLink, VerticalNavSectionTitle } from '@layouts/components'
import { config } from '@layouts/config'
import type { NavGroup, NavLink, NavSectionTitle, VerticalNavItems } from '@layouts/types'

interface Props {
  tag?: string | Component
  navItems: VerticalNavItems
  isOverlayNavActive: boolean
  toggleIsOverlayNavActive: (value: boolean) => void
}

const props = withDefaults(defineProps<Props>(), {
  tag: 'aside',
})

const refNav = ref()

const { width: windowWidth } = useWindowSize()

const isHovered = useElementHover(refNav)

provide(injectionKeyIsVerticalNavHovered, isHovered)

const {
  isVerticalNavCollapsed: isCollapsed,
  isLessThanOverlayNavBreakpoint,
  isVerticalNavMini,
  isAppRtl,
} = useLayouts()

const hideTitleAndIcon = isVerticalNavMini(windowWidth, isHovered)

const safeNavItems = computed(() => {
  return Array.isArray(props.navItems)
    ? props.navItems.filter(item => item && typeof item === 'object')
    : []
})

const hasValidChildren = (item: unknown): boolean => {
  return !!item
    && typeof item === 'object'
    && 'children' in item
    && Array.isArray((item as NavGroup).children)
    && (item as NavGroup).children.length > 0
}

const resolveNavItemComponent = (
  item: NavLink | NavSectionTitle | NavGroup | null | undefined,
) => {
  if (!item || typeof item !== 'object')
    return VerticalNavLink

  if ('heading' in item)
    return VerticalNavSectionTitle

  if (hasValidChildren(item))
    return VerticalNavGroup

  return VerticalNavLink
}

/*
  ℹ️ Close overlay side when route is changed
  Close overlay vertical nav when link is clicked
*/
const route = useRoute()

watch(() => route.name, () => {
  props.toggleIsOverlayNavActive(false)
})

const isVerticalNavScrolled = ref(false)

const updateIsVerticalNavScrolled = (val: boolean) => {
  isVerticalNavScrolled.value = val
}

const handleNavScroll = (evt: Event) => {
  isVerticalNavScrolled.value = (evt.target as HTMLElement).scrollTop > 0
}
</script>

<template>
  <Component
    :is="props.tag"
    ref="refNav"
    class="layout-vertical-nav"
    :class="[
      {
        'overlay-nav': isLessThanOverlayNavBreakpoint(windowWidth),
        hovered: isHovered,
        visible: isOverlayNavActive,
        scrolled: isVerticalNavScrolled,
      },
    ]"
  >
    <!-- 👉 Header -->
    <div class="nav-header">
      <slot name="nav-header">
        <RouterLink
          to="/"
          class="app-logo d-flex align-center gap-x-3 app-title-wrapper"
        >
          <VNodeRenderer :nodes="config.app.logo" />

          <Transition name="vertical-nav-app-title">
            <h1
              v-show="!hideTitleAndIcon"
              class="font-weight-medium leading-normal text-xl text-uppercase"
            >
              {{ config.app.title }}
            </h1>
          </Transition>
        </RouterLink>

        <!-- 👉 Vertical nav actions -->
        <template v-if="!isLessThanOverlayNavBreakpoint(windowWidth)">
          <Component
            :is="config.app.iconRenderer || 'div'"
            v-show="isCollapsed && !hideTitleAndIcon"
            class="header-action"
            v-bind="config.icons.verticalNavUnPinned"
            @click="isCollapsed = !isCollapsed"
          />

          <Component
            :is="config.app.iconRenderer || 'div'"
            v-show="!isCollapsed && !hideTitleAndIcon"
            class="header-action"
            v-bind="config.icons.verticalNavPinned"
            @click="isCollapsed = !isCollapsed"
          />
        </template>

        <template v-else>
          <Component
            :is="config.app.iconRenderer || 'div'"
            class="header-action"
            v-bind="config.icons.close"
            @click="toggleIsOverlayNavActive(false)"
          />
        </template>
      </slot>
    </div>

    <slot name="before-nav-items">
      <div class="vertical-nav-items-shadow" />
    </slot>

    <slot
      name="nav-items"
      :update-is-vertical-nav-scrolled="updateIsVerticalNavScrolled"
    >
      <PerfectScrollbar
        :key="isAppRtl"
        tag="ul"
        class="nav-items"
        :options="{ wheelPropagation: false }"
        @ps-scroll-y="handleNavScroll"
      >
        <Component
          :is="resolveNavItemComponent(item)"
          v-for="(item, index) in safeNavItems"
          :key="index"
          :item="item"
        />
      </PerfectScrollbar>
    </slot>
  </Component>
</template>

<style lang="scss">
@use "@configured-variables" as variables;
@use "@layouts/styles/mixins";

// 👉 Vertical Nav
.layout-vertical-nav {
  position: fixed;
  z-index: variables.$layout-vertical-nav-z-index;
  display: flex;
  flex-direction: column;
  block-size: 100%;
  inline-size: variables.$layout-vertical-nav-width;
  inset-block-start: 0;
  inset-inline-start: 0;
  transition: transform 0.25s ease-in-out, inline-size 0.25s ease-in-out, box-shadow 0.25s ease-in-out;
  will-change: transform, inline-size;

  .nav-header {
    display: flex;
    align-items: center;

    .header-action {
      cursor: pointer;
    }
  }

  .app-title-wrapper {
    margin-inline-end: auto;
  }

  .nav-items {
    block-size: 100%;
  }

  /*
    Nama menu datang dari basis data dan boleh diubah admin, jadi panjangnya
    tidak bisa dipastikan. Dulu dipotong elipsis begitu ruangnya kurang, dan
    lencana angka memakan ruang itu -- "Pengajuan Dana" jadi "Pengajuan D...".

    Sekarang boleh turun ke baris kedua, dan hanya dipotong bila dua baris
    pun tidak cukup. Tinggi barisnya tidak berubah: dua baris pada
    line-height 1,2 masih muat di dalam 2,75rem.
  */
  .nav-item-title {
    display: -webkit-box;
    overflow: hidden;
    min-inline-size: 0;
    margin-inline-end: auto;
    line-height: 1.2;
    text-overflow: ellipsis;
    white-space: normal;
    -webkit-box-orient: vertical;
    -webkit-line-clamp: 2;
  }

  .nav-item-badge {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    min-inline-size: 18px;
    block-size: 18px;
    flex-shrink: 0;
    padding-block: 0;
    padding-inline: 5px;
    border-radius: 999px;
    color: #fff;
    font-size: 11px;
    font-weight: 700;
    line-height: 18px;
    margin-inline-start: 6px;
  }

  .nav-item-badge.bg-error {
    background-color: rgb(var(--v-theme-error));
  }

  .nav-link a,
  .nav-group-label {
    min-inline-size: 0;
  }

  // 👉 Collapsed
  .layout-vertical-nav-collapsed & {
    &:not(.hovered) {
      inline-size: variables.$layout-vertical-nav-collapsed-width;
    }
  }

  // 👉 Overlay nav
  &.overlay-nav {
    &:not(.visible) {
      transform: translateX(-#{variables.$layout-vertical-nav-width});

      @include mixins.rtl {
        transform: translateX(variables.$layout-vertical-nav-width);
      }
    }
  }
}
</style>