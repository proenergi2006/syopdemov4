import { defineStore } from 'pinia'
import axios from '@axios'

/*
|--------------------------------------------------------------------------
| Types
|--------------------------------------------------------------------------
*/

type PermissionScope =
  | 'NONE'
  | 'OWN_DATA'
  | 'OWN_DEPARTMENT'
  | 'OWN_CABANG'
  | 'ASSIGNED_DEPARTMENTS'
  | 'ALL'

interface PermissionAbility {
  allowed: boolean
  scope: PermissionScope
  department_ids: number[]
}

/*
|--------------------------------------------------------------------------
| Compatibility
|--------------------------------------------------------------------------
| Permission lama mungkin masih menggunakan format boolean.
|--------------------------------------------------------------------------
*/

type PermissionValue =
  | PermissionAbility
  | boolean

interface PermissionModuleRoute {
  id: number
  code: string
  name: string
  route_prefix: string
  sort_order?: number
}

interface PermissionState {
  permissions: Record<string, PermissionValue>
  modules: PermissionModuleRoute[]
  isLoaded: boolean
  isLoading: boolean
  loadingPromise: Promise<void> | null

  /** Kapan daftar terakhir diambil dari server, untuk menilai basi. */
  loadedAt: number

  /** Kapan terakhir dipaksa muat ulang, untuk menahan permintaan beruntun. */
  lastForcedAt: number
}

/*
|--------------------------------------------------------------------------
| Umur daftar permission
|--------------------------------------------------------------------------
| Daftar permission dulu dimuat SEKALI per sesi dan tidak pernah diperbarui.
| Akibatnya permission yang baru diberikan tidak berlaku sampai orangnya
| logout dan login lagi -- dan yang baru dicabut tetap terbuka di layarnya.
|
| Dua pagar, dan keduanya bekerja tanpa satu pun halaman perlu diubah:
|
| 1. BASI KARENA UMUR. loadPermissions() mengambil ulang bila daftarnya sudah
|    lebih tua dari STALE_AFTER_MS. Setiap halaman memanggilnya saat dibuka,
|    jadi seluruh aplikasi ikut menikmatinya. Menangani pemberian MAUPUN
|    pencabutan, termasuk yang dilakukan orang lain dari komputer lain.
|
| 2. BASI KARENA TIDAK DIKENAL. ensureCan() memaksa muat ulang ketika sebuah
|    permission tidak ditemukan, lalu memeriksanya sekali lagi. Permission
|    yang baru lahir sesudah sesi dimulai memang tidak akan ada di daftar
|    lama -- dan itulah penanda paling jelas bahwa daftarnya perlu disegarkan.
|
| COOLDOWN_MS menahan pagar kedua: orang yang memang tidak punya izin jangan
| sampai memukul server setiap kali ia menekan menu yang terlarang baginya.
|--------------------------------------------------------------------------
*/
const STALE_AFTER_MS = 60 * 1000

const COOLDOWN_MS = 10 * 1000

/*
|--------------------------------------------------------------------------
| Path Normalizer
|--------------------------------------------------------------------------
*/

const normalizePath = (
  path: string,
): string => {
  const cleanPath = String(path || '')
    .split('?')[0]
    .split('#')[0]
    .trim()

  if (!cleanPath)
    return '/'

  const withLeadingSlash
    = cleanPath.startsWith('/')
      ? cleanPath
      : `/${cleanPath}`

  return withLeadingSlash.length > 1
    ? withLeadingSlash.replace(/\/+$/, '')
    : withLeadingSlash
}

/*
|--------------------------------------------------------------------------
| Scope Normalizer
|--------------------------------------------------------------------------
*/

const allowedPermissionScopes:
PermissionScope[] = [
  'NONE',
  'OWN_DATA',
  'OWN_DEPARTMENT',
  'OWN_CABANG',
  'ASSIGNED_DEPARTMENTS',
  'ALL',
]

const normalizePermissionScope = (
  value: unknown,
): PermissionScope => {
  const scope = String(
    value || 'NONE',
  )
    .trim()
    .toUpperCase()

  return allowedPermissionScopes.includes(
    scope as PermissionScope,
  )
    ? scope as PermissionScope
    : 'NONE'
}

/*
|--------------------------------------------------------------------------
| Department IDs Normalizer
|--------------------------------------------------------------------------
*/

const normalizeDepartmentIds = (
  value: unknown,
): number[] => {
  if (!Array.isArray(value))
    return []

  const departmentIds = value
    .map(item => Number(item))
    .filter(item => (
      Number.isInteger(item)
      && item > 0
    ))

  return Array.from(
    new Set<number>(departmentIds),
  ).sort(
    (a, b) => a - b,
  )
}

/*
|--------------------------------------------------------------------------
| Permission Map Normalizer
|--------------------------------------------------------------------------
*/

const normalizePermissionMap = (
  payload: unknown,
): Record<string, PermissionValue> => {
  if (
    !payload
    || typeof payload !== 'object'
    || Array.isArray(payload)
  )
    return {}

  const normalizedPermissions:
  Record<string, PermissionValue> = {}

  const permissionEntries = Object.entries(
    payload as Record<string, unknown>,
  )

  permissionEntries.forEach(
    ([permissionCode, value]) => {
      const code = String(
        permissionCode || '',
      ).trim()

      if (!code)
        return

      /*
      |--------------------------------------------------------------------------
      | Compatibility: boolean permission
      |--------------------------------------------------------------------------
      */

      if (typeof value === 'boolean') {
        normalizedPermissions[code] = value

        return
      }

      /*
      |--------------------------------------------------------------------------
      | Ability object
      |--------------------------------------------------------------------------
      */

      if (
        !value
        || typeof value !== 'object'
        || Array.isArray(value)
      )
        return

      const ability = value as {
        allowed?: unknown
        scope?: unknown
        department_ids?: unknown
      }

      normalizedPermissions[code] = {
        allowed: Boolean(
          ability.allowed,
        ),

        scope: normalizePermissionScope(
          ability.scope,
        ),

        department_ids:
          normalizeDepartmentIds(
            ability.department_ids,
          ),
      }
    },
  )

  return normalizedPermissions
}

/*
|--------------------------------------------------------------------------
| Module Route Normalizer
|--------------------------------------------------------------------------
*/

const normalizeModuleRoutes = (
  payload: unknown,
): PermissionModuleRoute[] => {
  if (!Array.isArray(payload))
    return []

  return payload
    .map((item: any) => {
      const id = Number(
        item?.id ?? 0,
      )

      const code = String(
        item?.code ?? '',
      ).trim()

      const name = String(
        item?.name
        ?? item?.nama
        ?? code,
      ).trim()

      const routePrefix = String(
        item?.route_prefix ?? '',
      ).trim()

      const sortOrder = Number(
        item?.sort_order ?? 0,
      )

      return {
        id,
        code,
        name,
        route_prefix: routePrefix,
        sort_order:
          Number.isFinite(sortOrder)
            ? sortOrder
            : 0,
      }
    })
    .filter(item => {
      return item.id > 0
        && item.code !== ''
        && item.route_prefix !== ''
    })
}

/*
|--------------------------------------------------------------------------
| Permission Store
|--------------------------------------------------------------------------
*/

export const usePermissionStore = defineStore(
  'permission',
  {
    state: (): PermissionState => ({
      permissions: {},
      modules: [],
      isLoaded: false,
      isLoading: false,
      loadingPromise: null,
      loadedAt: 0,
      lastForcedAt: 0,
    }),

    /*
    |--------------------------------------------------------------------------
    | Getters
    |--------------------------------------------------------------------------
    */

    getters: {
      /*
      |--------------------------------------------------------------------------
      | Can
      |--------------------------------------------------------------------------
      */

      can: state => {
        return (
          permissionCode: string,
        ): boolean => {
          const code = String(
            permissionCode || '',
          ).trim()

          if (!code)
            return false

          const permission
            = state.permissions[code]

          if (
            permission === undefined
            || permission === null
          )
            return false

          if (
            typeof permission === 'boolean'
          )
            return permission

          return Boolean(
            permission.allowed,
          )
        }
      },

      /*
      |--------------------------------------------------------------------------
      | Scope
      |--------------------------------------------------------------------------
      */

      scope: state => {
        return (
          permissionCode: string,
        ): PermissionScope => {
          const code = String(
            permissionCode || '',
          ).trim()

          if (!code)
            return 'NONE'

          const permission
            = state.permissions[code]

          if (
            permission === undefined
            || permission === null
            || typeof permission === 'boolean'
          )
            return 'NONE'

          return normalizePermissionScope(
            permission.scope,
          )
        }
      },

      /*
      |--------------------------------------------------------------------------
      | Assigned Department IDs
      |--------------------------------------------------------------------------
      */

      departmentIds: state => {
        return (
          permissionCode: string,
        ): number[] => {
          const code = String(
            permissionCode || '',
          ).trim()

          if (!code)
            return []

          const permission
            = state.permissions[code]

          if (
            permission === undefined
            || permission === null
            || typeof permission === 'boolean'
          )
            return []

          return normalizeDepartmentIds(
            permission.department_ids,
          )
        }
      },

      /*
      |--------------------------------------------------------------------------
      | Required Permission by Route
      |--------------------------------------------------------------------------
      */

      getRequiredPermission: state => {
        return (
          routePath: string,
        ): string | null => {
          const normalizedPath
            = normalizePath(routePath)

          /*
          |--------------------------------------------------------------------------
          | Cari prefix paling spesifik
          |--------------------------------------------------------------------------
          |
          | Contoh:
          |
          | /non_stock/purchase_request
          | /non_stock/purchase_request/report
          |
          | Prefix yang lebih panjang harus didahulukan.
          |--------------------------------------------------------------------------
          */

          const matchedModule
            = [...state.modules]
              .filter(module => {
                const prefix = normalizePath(
                  module.route_prefix,
                )

                if (
                  !prefix
                  || prefix === '/'
                )
                  return false

                return normalizedPath === prefix
                  || normalizedPath.startsWith(
                    `${prefix}/`,
                  )
              })
              .sort((a, b) => {
                const bLength = normalizePath(
                  b.route_prefix,
                ).length

                const aLength = normalizePath(
                  a.route_prefix,
                ).length

                return bLength - aLength
              })[0]

          if (!matchedModule)
            return null

          const prefix = normalizePath(
            matchedModule.route_prefix,
          )

          const remainingPath
            = normalizedPath
              .slice(prefix.length)
              .replace(/^\/+/, '')

          const segments = remainingPath
            .split('/')
            .filter(Boolean)

          const firstSegment = String(
            segments[0] || '',
          ).toLowerCase()

          /*
          |--------------------------------------------------------------------------
          | Tentukan action berdasarkan URL
          |--------------------------------------------------------------------------
          */

          let action = 'view'

          if (
            firstSegment === 'create'
            || firstSegment === 'new'
            || firstSegment === 'add'
          )
            action = 'create'

          else if (
            firstSegment === 'edit'
            || firstSegment === 'update'
          )
            action = 'update'

          else if (
            firstSegment === 'detail'
            || firstSegment === 'show'
            || firstSegment === 'view'
          )
            action = 'view'

          return `${matchedModule.code}.${action}`
        }
      },
    },

    /*
    |--------------------------------------------------------------------------
    | Actions
    |--------------------------------------------------------------------------
    */

    actions: {
      async loadPermissions(
        force = false,
      ): Promise<void> {
        /*
        |--------------------------------------------------------------------------
        | Permission sudah dimuat DAN belum basi
        |--------------------------------------------------------------------------
        | Syarat "belum basi" inilah yang membuat perubahan permission berlaku
        | tanpa logout. Tanpanya, daftar yang sekali dimuat dipegang sampai
        | sesinya berakhir.
        |--------------------------------------------------------------------------
        */

        const basi = this.loadedAt > 0
          && (Date.now() - this.loadedAt) > STALE_AFTER_MS

        if (
          this.isLoaded
          && !force
          && !basi
        )
          return

        /*
        |--------------------------------------------------------------------------
        | Request sedang berjalan
        |--------------------------------------------------------------------------
        */

        if (
          this.loadingPromise
          && !force
        ) {
          await this.loadingPromise

          return
        }

        this.isLoading = true

        const requestPromise: Promise<void>
          = (async (): Promise<void> => {
            try {
              const response = await axios.get(
                '/auth/me/permissions',
                {
                  headers: {
                    Accept: 'application/json',
                  },
                },
              )

              const responseData
                = response.data?.data ?? {}

              this.permissions
                = normalizePermissionMap(
                  responseData.permissions,
                )

              this.modules
                = normalizeModuleRoutes(
                  responseData.modules,
                )

              this.isLoaded = true
              this.loadedAt = Date.now()
            }
            catch (error: unknown) {
              this.permissions = {}
              this.modules = []
              this.isLoaded = false
              this.loadedAt = 0

              throw error
            }
            finally {
              this.isLoading = false
              this.loadingPromise = null
            }
          })()

        this.loadingPromise = requestPromise

        await requestPromise
      },

      /*
      |--------------------------------------------------------------------------
      | Reload Permissions
      |--------------------------------------------------------------------------
      */

      async reloadPermissions(): Promise<void> {
        await this.loadPermissions(true)
      },

      /*
      |--------------------------------------------------------------------------
      | Ensure Can
      |--------------------------------------------------------------------------
      | Memeriksa izin, dan bila TIDAK ADA, memuat ulang daftarnya sekali lalu
      | memeriksanya lagi.
      |
      | Permission yang baru lahir sesudah sesi dimulai mustahil ada di daftar
      | lama. Jadi "tidak ditemukan" bukan sekadar berarti tidak boleh -- ia
      | juga penanda paling jelas bahwa daftarnya sudah ketinggalan.
      |
      | Hanya dipaksa untuk permission yang BELUM DIKENAL. Yang sudah ada di
      | daftar tetapi bernilai false memang keputusan server, bukan daftar
      | basi, jadi tidak perlu ditanyakan ulang.
      |
      | Dibatasi cooldown supaya orang yang memang tidak berhak tidak memukul
      | server setiap kali menekan menu terlarang.
      */
      async ensureCan(permissionCode: string): Promise<boolean> {
        const code = String(permissionCode || '').trim()

        if (!code)
          return false

        if (this.can(code))
          return true

        const belumDikenal = this.permissions[code] === undefined

        const bolehCoba = Date.now() - this.lastForcedAt > COOLDOWN_MS

        if (!belumDikenal || !bolehCoba)
          return false

        this.lastForcedAt = Date.now()

        try {
          await this.loadPermissions(true)
        }
        catch {
          /*
           * Gagal menyegarkan bukan alasan untuk mengizinkan. Keputusannya
           * jatuh ke daftar yang ada sekarang -- yang berarti tetap ditolak.
           */
          return false
        }

        return this.can(code)
      },

      /*
      |--------------------------------------------------------------------------
      | Clear Store
      |--------------------------------------------------------------------------
      */

      clearPermissions(): void {
        this.permissions = {}
        this.modules = []
        this.isLoaded = false
        this.isLoading = false
        this.loadingPromise = null
        this.loadedAt = 0
        this.lastForcedAt = 0
      },
    },
  },
)
