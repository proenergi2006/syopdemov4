import type {
  Directive,
  DirectiveBinding,
} from 'vue'

import { watchEffect } from 'vue'

import { usePermissionStore } from '@/stores/permission'

type PermissionRequirement =
  | string
  | string[]

interface PermissionElement extends HTMLElement {
  __permissionStop__?: () => void
  __permissionOriginalDisplay__?: string
}

/*
|--------------------------------------------------------------------------
| Normalize Permission Codes
|--------------------------------------------------------------------------
| String:
| v-permission="'auth_user.create'"
|
| Array:
| v-permission="['auth_user.update', 'auth_user.create']"
|
| Array menggunakan logika ANY:
| cukup memiliki salah satu permission.
|--------------------------------------------------------------------------
*/
const normalizePermissionCodes = (
  value: PermissionRequirement,
): string[] => {
  const values = Array.isArray(value)
    ? value
    : [value]

  return Array.from(
    new Set(
      values
        .map(item => String(item || '').trim())
        .filter(Boolean),
    ),
  )
}

/*
|--------------------------------------------------------------------------
| Apply Permission
|--------------------------------------------------------------------------
*/
const applyPermission = (
  element: PermissionElement,
  binding: DirectiveBinding<PermissionRequirement>,
): void => {
  /*
   * Hentikan watcher lama jika directive diperbarui.
   */
  element.__permissionStop__?.()

  if (
    element.__permissionOriginalDisplay__
    === undefined
  ) {
    element.__permissionOriginalDisplay__
      = element.style.display
  }

  const permissionStore = usePermissionStore()

  element.__permissionStop__ = watchEffect(() => {
    const permissionCodes
      = normalizePermissionCodes(
        binding.value,
      )

    const isAllowed
      = permissionCodes.length > 0
      && permissionCodes.some(permissionCode =>
        permissionStore.can(permissionCode),
      )

    element.style.display = isAllowed
      ? element.__permissionOriginalDisplay__ || ''
      : 'none'
  })
}

/*
|--------------------------------------------------------------------------
| Global Permission Directive
|--------------------------------------------------------------------------
*/
export const permissionDirective:
Directive<
  PermissionElement,
  PermissionRequirement
> = {
  mounted(
    element,
    binding,
  ) {
    applyPermission(
      element,
      binding,
    )
  },

  updated(
    element,
    binding,
  ) {
    applyPermission(
      element,
      binding,
    )
  },

  beforeUnmount(element) {
    element.__permissionStop__?.()

    delete element.__permissionStop__
    delete element.__permissionOriginalDisplay__
  },
}