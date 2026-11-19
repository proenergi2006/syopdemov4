export type PermissionScope
  = | 'NONE'
    | 'OWN_DATA'
    | 'OWN_DEPARTMENT'
    | 'OWN_CABANG'
    | 'ALL'

export interface ModuleAbilities {
  can_view: boolean
  view_scope: PermissionScope
  can_create: boolean
  can_update: boolean
  can_delete: boolean

  /*
  | Berdiri di rantai approval modul ini.
  |
  | Penentu tampil tidaknya saringan "hanya yang menunggu approval saya".
  | Bagi yang tidak pernah menyetujui apa pun di sana, saringan itu hanya
  | tombol yang selalu mengosongkan daftar.
  */
  is_approver: boolean
}

const allowedPermissionScopes: PermissionScope[] = [
  'NONE',
  'OWN_DATA',
  'OWN_DEPARTMENT',
  'OWN_CABANG',
  'ALL',
]

const normalizeBoolean = (value: unknown): boolean => {
  return value === true
    || value === 1
    || value === '1'
    || String(value).toLowerCase() === 'true'
}

export const normalizePermissionScope = (
  value: unknown,
): PermissionScope => {
  const scope = String(value || 'NONE')
    .trim()
    .toUpperCase() as PermissionScope

  return allowedPermissionScopes.includes(scope)
    ? scope
    : 'NONE'
}

export const defaultModuleAbilities = (): ModuleAbilities => ({
  can_view: false,
  view_scope: 'NONE',
  can_create: false,
  can_update: false,
  can_delete: false,
  is_approver: false,
})

export const normalizeModuleAbilities = (
  value: unknown,
): ModuleAbilities => {
  const abilities = (
    value
    && typeof value === 'object'
      ? value
      : {}
  ) as Record<string, unknown>

  return {
    can_view: normalizeBoolean(abilities.can_view),

    view_scope: normalizePermissionScope(
      abilities.view_scope,
    ),

    can_create: normalizeBoolean(
      abilities.can_create,
    ),

    can_update: normalizeBoolean(
      abilities.can_update,
    ),

    can_delete: normalizeBoolean(
      abilities.can_delete,
    ),

    is_approver: normalizeBoolean(
      abilities.is_approver,
    ),
  }
}