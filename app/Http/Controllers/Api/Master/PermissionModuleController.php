<?php

namespace App\Http\Controllers\Api\Master;

use App\Http\Controllers\Controller;
use App\Models\PermissionModule;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use App\Models\Permission;
use Illuminate\Support\Facades\Schema;

class PermissionModuleController extends Controller
{
    /*
    |--------------------------------------------------------------------------
    | Daftar Permission Module
    |--------------------------------------------------------------------------
    */
    public function index(Request $request): JsonResponse
    {
        $user = $request->user();

        /*
        |--------------------------------------------------------------------------
        | Permission
        |--------------------------------------------------------------------------
        */
        if (
            !$user
            || !$user->hasPermission(
                'auth_permission_module.view',
            )
        ) {
            return response()->json([
                'success' => false,
                'message' => 'Anda tidak memiliki akses untuk melihat Permission Module.',
                'data' => [],
            ], 403);
        }

        /*
        |--------------------------------------------------------------------------
        | Validasi Filter
        |--------------------------------------------------------------------------
        */
        $validated = $request->validate([
            'search' => [
                'nullable',
                'string',
                'max:100',
            ],

            'is_active' => [
                'nullable',
                'boolean',
            ],
        ]);

        try {
            $query = PermissionModule::query()
                ->withCount([
                    'permissions',

                    'permissions as active_permissions_count'
                    => function ($permissionQuery): void {
                        $permissionQuery->where(
                            'is_active',
                            true,
                        );
                    },
                ]);

            /*
            |--------------------------------------------------------------------------
            | Search
            |--------------------------------------------------------------------------
            */
            $search = trim(
                (string) (
                    $validated['search']
                    ?? ''
                ),
            );

            if ($search !== '') {
                $query->where(
                    function ($searchQuery) use ($search): void {
                        $searchQuery
                            ->where(
                                'code',
                                'ILIKE',
                                "%{$search}%",
                            )
                            ->orWhere(
                                'name',
                                'ILIKE',
                                "%{$search}%",
                            )
                            ->orWhere(
                                'description',
                                'ILIKE',
                                "%{$search}%",
                            )
                            ->orWhere(
                                'route_prefix',
                                'ILIKE',
                                "%{$search}%",
                            );
                    },
                );
            }

            /*
            |--------------------------------------------------------------------------
            | Status
            |--------------------------------------------------------------------------
            */
            if (
                array_key_exists(
                    'is_active',
                    $validated,
                )
            ) {
                $query->where(
                    'is_active',
                    (bool) $validated['is_active'],
                );
            }

            /*
            |--------------------------------------------------------------------------
            | Data
            |--------------------------------------------------------------------------
            */
            $modules = $query
                ->orderBy('sort_order')
                ->orderBy('name')
                ->orderBy('id')
                ->get()
                ->map(function (
                    PermissionModule $module,
                ): array {
                    return [
                        'id' => (int) $module->id,
                        'code' => $module->code,
                        'name' => $module->name,
                        'description' => $module->description,
                        'route_prefix' => $module->route_prefix,

                        'sort_order' => (int) (
                            $module->sort_order
                            ?? 0
                        ),

                        'is_active' => (bool) $module->is_active,

                        'permissions_count' => (int) (
                            $module->permissions_count
                            ?? 0
                        ),

                        'active_permissions_count' => (int) (
                            $module->active_permissions_count
                            ?? 0
                        ),

                        /*
                         * Date tetap raw dari model/backend.
                         * Format tampilan dilakukan frontend.
                         */
                        'created_at' => $module->created_at,
                        'updated_at' => $module->updated_at,
                    ];
                })
                ->values();

            return response()->json([
                'success' => true,
                'message' => 'Data permission module berhasil dimuat.',
                'data' => $modules,
            ]);
        } catch (\Throwable $e) {
            Log::error(
                '[Permission Module] Index error',
                [
                    'message' => $e->getMessage(),
                    'file' => $e->getFile(),
                    'line' => $e->getLine(),
                    'request' => $request->all(),
                    'user_id' => $user?->id,
                ],
            );

            return response()->json([
                'success' => false,
                'message' => 'Gagal memuat data permission module.',
                'data' => [],
                'debug' => app()->environment('local')
                    ? $e->getMessage()
                    : null,
            ], 500);
        }
    }

    /*
|--------------------------------------------------------------------------
| Detail Permission Module
|--------------------------------------------------------------------------
*/
    public function show(
        Request $request,
        int $id,
    ): JsonResponse {
        $user = $request->user();

        /*
    |--------------------------------------------------------------------------
    | Permission
    |--------------------------------------------------------------------------
    */
        if (
            !$user
            || !$user->hasPermission(
                'auth_permission_module.view',
            )
        ) {
            return response()->json([
                'success' => false,
                'message' => 'Anda tidak memiliki akses untuk melihat detail Permission Module.',
                'data' => null,
            ], 403);
        }

        try {
            /*
        |--------------------------------------------------------------------------
        | Module dan Permission Turunannya
        |--------------------------------------------------------------------------
        */
            $module = PermissionModule::query()
                ->with([
                    'permissions' => function ($query): void {
                        $query
                            ->orderBy('id');
                    },
                ])
                ->find($id);

            if (!$module) {
                return response()->json([
                    'success' => false,
                    'message' => 'Permission Module tidak ditemukan.',
                    'data' => null,
                ], 404);
            }

            /*
        |--------------------------------------------------------------------------
        | Response
        |--------------------------------------------------------------------------
        */
            return response()->json([
                'success' => true,
                'message' => 'Detail Permission Module berhasil dimuat.',

                'data' => [
                    'module' => [
                        'id' => (int) $module->id,
                        'code' => $module->code,
                        'name' => $module->name,
                        'description' => $module->description,
                        'route_prefix' => $module->route_prefix,
                        'sort_order' => (int) $module->sort_order,
                        'is_active' => (bool) $module->is_active,
                        'created_at' => $module->created_at,
                        'updated_at' => $module->updated_at,
                    ],

                    'permissions' => $module->permissions
                        ->map(function ($permission): array {
                            return [
                                'id' => (int) $permission->id,
                                'module' => $permission->module,
                                'action' => $permission->action,
                                'code' => $permission->code,
                                'name' => $permission->name,
                                'description' => $permission->description,
                                'is_active' => (bool) $permission->is_active,
                                'created_at' => $permission->created_at,
                                'updated_at' => $permission->updated_at,
                            ];
                        })
                        ->values(),
                ],
            ]);
        } catch (\Throwable $e) {
            Log::error(
                '[Permission Module] Show error',
                [
                    'permission_module_id' => $id,
                    'message' => $e->getMessage(),
                    'file' => $e->getFile(),
                    'line' => $e->getLine(),
                    'user_id' => $user?->id,
                ],
            );

            return response()->json([
                'success' => false,
                'message' => 'Gagal memuat detail Permission Module.',
                'data' => null,
                'debug' => app()->environment('local')
                    ? $e->getMessage()
                    : null,
            ], 500);
        }
    }

    /*
|--------------------------------------------------------------------------
| Create Permission Module
|--------------------------------------------------------------------------
*/
    public function store(Request $request): JsonResponse
    {
        $user = $request->user();

        /*
    |--------------------------------------------------------------------------
    | Permission
    |--------------------------------------------------------------------------
    */
        if (
            !$user
            || !$user->hasPermission(
                'auth_permission_module.create',
            )
        ) {
            return response()->json([
                'success' => false,
                'message' => 'Anda tidak memiliki akses untuk membuat Permission Module.',
                'data' => null,
            ], 403);
        }

        /*
    |--------------------------------------------------------------------------
    | Validation
    |--------------------------------------------------------------------------
    */
        $validated = $request->validate([
            'code' => [
                'required',
                'string',
                'max:100',
            ],

            'name' => [
                'required',
                'string',
                'max:150',
            ],

            'description' => [
                'nullable',
                'string',
            ],

            'route_prefix' => [
                'required',
                'string',
                'max:255',
            ],

            'sort_order' => [
                'nullable',
                'integer',
                'min:0',
                'max:999999',
            ],

            'is_active' => [
                'nullable',
                'boolean',
            ],
        ]);

        /*
    |--------------------------------------------------------------------------
    | Normalize Code
    |--------------------------------------------------------------------------
    | Contoh:
    | "Master Customer" → master_customer
    | "AUTH USERS"      → auth_users
    |--------------------------------------------------------------------------
    */
        $normalizedCode = Str::of(
            (string) $validated['code'],
        )
            ->trim()
            ->lower()
            ->replaceMatches('/[^a-z0-9]+/', '_')
            ->trim('_')
            ->toString();

        if (
            $normalizedCode === ''
            || !preg_match(
                '/^[a-z][a-z0-9_]*$/',
                $normalizedCode,
            )
        ) {
            throw ValidationException::withMessages([
                'code' => [
                    'Code module harus diawali huruf dan hanya boleh berisi huruf kecil, angka, serta underscore.',
                ],
            ]);
        }

        /*
    |--------------------------------------------------------------------------
    | Normalize Route Prefix
    |--------------------------------------------------------------------------
    */
        $routePrefix = trim(
            (string) $validated['route_prefix'],
        );

        /*
     * Query string dan fragment tidak boleh menjadi bagian route prefix.
     */
        if (
            str_contains($routePrefix, '?')
            || str_contains($routePrefix, '#')
        ) {
            throw ValidationException::withMessages([
                'route_prefix' => [
                    'Route prefix tidak boleh mengandung query string atau fragment.',
                ],
            ]);
        }

        if (!str_starts_with($routePrefix, '/')) {
            $routePrefix = '/' . $routePrefix;
        }

        /*
     * Rapikan slash ganda.
     */
        $routePrefix = preg_replace(
            '#/+#',
            '/',
            $routePrefix,
        ) ?: '/';

        /*
     * Hilangkan slash terakhir, kecuali root.
     */
        if ($routePrefix !== '/') {
            $routePrefix = rtrim(
                $routePrefix,
                '/',
            );
        }

        if ($routePrefix === '/') {
            throw ValidationException::withMessages([
                'route_prefix' => [
                    'Route prefix tidak boleh menggunakan root path.',
                ],
            ]);
        }

        /*
    |--------------------------------------------------------------------------
    | Cek Unique Setelah Normalisasi
    |--------------------------------------------------------------------------
    */
        if (
            PermissionModule::query()
            ->where('code', $normalizedCode)
            ->exists()
        ) {
            throw ValidationException::withMessages([
                'code' => [
                    'Code Permission Module sudah digunakan.',
                ],
            ]);
        }

        if (
            PermissionModule::query()
            ->where('route_prefix', $routePrefix)
            ->exists()
        ) {
            throw ValidationException::withMessages([
                'route_prefix' => [
                    'Route prefix sudah digunakan oleh module lain.',
                ],
            ]);
        }

        try {
            $module = DB::transaction(
                function () use (
                    $validated,
                    $normalizedCode,
                    $routePrefix,
                ): PermissionModule {
                    return PermissionModule::query()->create([
                        'code' => $normalizedCode,

                        'name' => trim(
                            (string) $validated['name'],
                        ),

                        'description' => isset(
                            $validated['description'],
                        )
                            ? trim(
                                (string) $validated['description'],
                            )
                            : null,

                        'route_prefix' => $routePrefix,

                        'sort_order' => (int) (
                            $validated['sort_order']
                            ?? 0
                        ),

                        /*
                     * Default false lebih aman.
                     *
                     * Module baru belum mempunyai permission *.view,
                     * sehingga sebaiknya belum langsung melindungi route.
                     */
                        'is_active' => (bool) (
                            $validated['is_active']
                            ?? false
                        ),
                    ]);
                },
            );

            return response()->json([
                'success' => true,
                'message' => 'Permission Module berhasil dibuat.',

                'data' => [
                    'id' => (int) $module->id,
                    'code' => $module->code,
                    'name' => $module->name,
                    'description' => $module->description,
                    'route_prefix' => $module->route_prefix,
                    'sort_order' => (int) $module->sort_order,
                    'is_active' => (bool) $module->is_active,
                    'permissions_count' => 0,
                    'active_permissions_count' => 0,
                    'created_at' => $module->created_at,
                    'updated_at' => $module->updated_at,
                ],
            ], 201);
        } catch (\Throwable $e) {
            Log::error(
                '[Permission Module] Store error',
                [
                    'message' => $e->getMessage(),
                    'file' => $e->getFile(),
                    'line' => $e->getLine(),
                    'request' => $request->except([
                        'password',
                        'password_confirmation',
                    ]),
                    'user_id' => $user?->id,
                ],
            );

            return response()->json([
                'success' => false,
                'message' => 'Gagal membuat Permission Module.',
                'data' => null,
                'debug' => app()->environment('local')
                    ? $e->getMessage()
                    : null,
            ], 500);
        }
    }

    /*
|--------------------------------------------------------------------------
| Create Permission pada Module
|--------------------------------------------------------------------------
*/
    public function storePermission(
        Request $request,
        int $id,
    ): JsonResponse {
        $user = $request->user();

        /*
    |--------------------------------------------------------------------------
    | Permission
    |--------------------------------------------------------------------------
    */
        if (
            !$user
            || !$user->hasPermission(
                'auth_permission_module.create',
            )
        ) {
            return response()->json([
                'success' => false,
                'message' => 'Anda tidak memiliki akses untuk membuat Permission.',
                'data' => null,
            ], 403);
        }

        /*
    |--------------------------------------------------------------------------
    | Validation
    |--------------------------------------------------------------------------
    */
        $validated = $request->validate([
            'action' => [
                'required',
                'string',
                'max:50',
            ],

            'name' => [
                'required',
                'string',
                'max:150',
            ],

            'description' => [
                'nullable',
                'string',
            ],

            'is_active' => [
                'nullable',
                'boolean',
            ],
        ]);

        /*
    |--------------------------------------------------------------------------
    | Cari Module
    |--------------------------------------------------------------------------
    */
        $module = PermissionModule::query()
            ->find($id);

        if (!$module) {
            return response()->json([
                'success' => false,
                'message' => 'Permission Module tidak ditemukan.',
                'data' => null,
            ], 404);
        }

        /*
    |--------------------------------------------------------------------------
    | Normalisasi Action
    |--------------------------------------------------------------------------
    | Contoh:
    |
    | View Data      → view_data
    | Submit         → submit
    | Approve Final  → approve_final
    |--------------------------------------------------------------------------
    */
        $normalizedAction = Str::of(
            (string) $validated['action'],
        )
            ->trim()
            ->lower()
            ->replaceMatches(
                '/[^a-z0-9]+/',
                '_',
            )
            ->trim('_')
            ->toString();

        if (
            $normalizedAction === ''
            || !preg_match(
                '/^[a-z][a-z0-9_]*$/',
                $normalizedAction,
            )
        ) {
            throw ValidationException::withMessages([
                'action' => [
                    'Action harus diawali huruf dan hanya boleh berisi huruf kecil, angka, serta underscore.',
                ],
            ]);
        }

        /*
    |--------------------------------------------------------------------------
    | Permission Code Otomatis
    |--------------------------------------------------------------------------
    */
        $permissionCode = $module->code
            . '.'
            . $normalizedAction;

        /*
    |--------------------------------------------------------------------------
    | Cek Duplikasi
    |--------------------------------------------------------------------------
    */
        if (
            Permission::query()
            ->where('code', $permissionCode)
            ->exists()
        ) {
            throw ValidationException::withMessages([
                'action' => [
                    "Permission {$permissionCode} sudah tersedia.",
                ],
            ]);
        }

        if (
            Permission::query()
            ->where('module', $module->code)
            ->where('action', $normalizedAction)
            ->exists()
        ) {
            throw ValidationException::withMessages([
                'action' => [
                    'Action tersebut sudah tersedia pada Permission Module ini.',
                ],
            ]);
        }

        try {
            $permission = DB::transaction(
                function () use (
                    $validated,
                    $module,
                    $normalizedAction,
                    $permissionCode,
                ): Permission {
                    return Permission::query()->create([
                        'module' => $module->code,
                        'action' => $normalizedAction,
                        'code' => $permissionCode,

                        'name' => trim(
                            (string) $validated['name'],
                        ),

                        'description' => isset(
                            $validated['description'],
                        )
                            ? trim(
                                (string) $validated['description'],
                            )
                            : null,

                        'is_active' => (bool) (
                            $validated['is_active']
                            ?? true
                        ),
                    ]);
                },
            );

            return response()->json([
                'success' => true,
                'message' => 'Permission berhasil dibuat.',

                'data' => [
                    'id' => (int) $permission->id,
                    'module' => $permission->module,
                    'action' => $permission->action,
                    'code' => $permission->code,
                    'name' => $permission->name,
                    'description' => $permission->description,
                    'is_active' => (bool) $permission->is_active,
                    'created_at' => $permission->created_at,
                    'updated_at' => $permission->updated_at,
                ],
            ], 201);
        } catch (\Throwable $e) {
            Log::error(
                '[Permission Module] Store Permission error',
                [
                    'permission_module_id' => $id,
                    'permission_code' => $permissionCode,
                    'message' => $e->getMessage(),
                    'file' => $e->getFile(),
                    'line' => $e->getLine(),
                    'request' => $request->all(),
                    'user_id' => $user?->id,
                ],
            );

            return response()->json([
                'success' => false,
                'message' => 'Gagal membuat Permission.',
                'data' => null,
                'debug' => app()->environment('local')
                    ? $e->getMessage()
                    : null,
            ], 500);
        }
    }

    /*
|--------------------------------------------------------------------------
| Update Permission Module
|--------------------------------------------------------------------------
*/
    public function update(
        Request $request,
        int $id,
    ): JsonResponse {
        $user = $request->user();

        /*
    |--------------------------------------------------------------------------
    | Permission
    |--------------------------------------------------------------------------
    */
        if (
            !$user
            || !$user->hasPermission(
                'auth_permission_module.update',
            )
        ) {
            return response()->json([
                'success' => false,
                'message' => 'Anda tidak memiliki akses untuk memperbarui Permission Module.',
                'data' => null,
            ], 403);
        }

        /*
    |--------------------------------------------------------------------------
    | Validation
    |--------------------------------------------------------------------------
    | Code sengaja tidak diterima karena tidak boleh diubah setelah module dibuat.
    |--------------------------------------------------------------------------
    */
        $validated = $request->validate([
            'name' => [
                'required',
                'string',
                'max:150',
            ],

            'description' => [
                'nullable',
                'string',
            ],

            'route_prefix' => [
                'required',
                'string',
                'max:255',
            ],

            'sort_order' => [
                'required',
                'integer',
                'min:0',
                'max:999999',
            ],

            'is_active' => [
                'required',
                'boolean',
            ],
        ]);

        /*
    |--------------------------------------------------------------------------
    | Cari Module
    |--------------------------------------------------------------------------
    */
        $module = PermissionModule::query()
            ->find($id);

        if (!$module) {
            return response()->json([
                'success' => false,
                'message' => 'Permission Module tidak ditemukan.',
                'data' => null,
            ], 404);
        }

        /*
    |--------------------------------------------------------------------------
    | Normalize Route Prefix
    |--------------------------------------------------------------------------
    */
        $routePrefix = trim(
            (string) $validated['route_prefix'],
        );

        if (
            str_contains($routePrefix, '?')
            || str_contains($routePrefix, '#')
        ) {
            throw ValidationException::withMessages([
                'route_prefix' => [
                    'Route prefix tidak boleh mengandung query string atau fragment.',
                ],
            ]);
        }

        if (!str_starts_with($routePrefix, '/')) {
            $routePrefix = '/' . $routePrefix;
        }

        $routePrefix = preg_replace(
            '#/+#',
            '/',
            $routePrefix,
        ) ?: '/';

        if ($routePrefix !== '/') {
            $routePrefix = rtrim(
                $routePrefix,
                '/',
            );
        }

        if ($routePrefix === '/') {
            throw ValidationException::withMessages([
                'route_prefix' => [
                    'Route prefix tidak boleh menggunakan root path.',
                ],
            ]);
        }

        /*
    |--------------------------------------------------------------------------
    | Route Prefix Harus Unik
    |--------------------------------------------------------------------------
    */
        $routePrefixExists = PermissionModule::query()
            ->where('route_prefix', $routePrefix)
            ->where('id', '<>', $module->id)
            ->exists();

        if ($routePrefixExists) {
            throw ValidationException::withMessages([
                'route_prefix' => [
                    'Route prefix sudah digunakan oleh module lain.',
                ],
            ]);
        }

        /*
    |--------------------------------------------------------------------------
    | Validasi Sebelum Module Diaktifkan
    |--------------------------------------------------------------------------
    | Module aktif akan langsung dibaca router frontend.
    | Karena itu wajib memiliki permission view yang aktif.
    |--------------------------------------------------------------------------
    */
        $willBeActive = (bool) $validated['is_active'];

        if ($willBeActive) {
            $viewPermissionCode = $module->code
                . '.view';

            $hasActiveViewPermission = Permission::query()
                ->where('module', $module->code)
                ->where('action', 'view')
                ->where('code', $viewPermissionCode)
                ->where('is_active', true)
                ->exists();

            if (!$hasActiveViewPermission) {
                throw ValidationException::withMessages([
                    'is_active' => [
                        "Module belum dapat diaktifkan karena permission {$viewPermissionCode} belum tersedia atau tidak aktif.",
                    ],
                ]);
            }
        }

        try {
            DB::transaction(
                function () use (
                    $module,
                    $validated,
                    $routePrefix,
                    $willBeActive,
                ): void {
                    $module->update([
                        'name' => trim(
                            (string) $validated['name'],
                        ),

                        'description' => isset(
                            $validated['description'],
                        )
                            ? trim(
                                (string) $validated['description'],
                            )
                            : null,

                        'route_prefix' => $routePrefix,

                        'sort_order' => (int) $validated['sort_order'],

                        'is_active' => $willBeActive,
                    ]);
                },
            );

            $module->refresh();

            return response()->json([
                'success' => true,
                'message' => 'Permission Module berhasil diperbarui.',

                'data' => [
                    'id' => (int) $module->id,
                    'code' => $module->code,
                    'name' => $module->name,
                    'description' => $module->description,
                    'route_prefix' => $module->route_prefix,
                    'sort_order' => (int) $module->sort_order,
                    'is_active' => (bool) $module->is_active,
                    'created_at' => $module->created_at,
                    'updated_at' => $module->updated_at,
                ],
            ]);
        } catch (\Throwable $e) {
            Log::error(
                '[Permission Module] Update error',
                [
                    'permission_module_id' => $id,
                    'module_code' => $module->code,
                    'message' => $e->getMessage(),
                    'file' => $e->getFile(),
                    'line' => $e->getLine(),
                    'request' => $request->all(),
                    'user_id' => $user?->id,
                ],
            );

            return response()->json([
                'success' => false,
                'message' => 'Gagal memperbarui Permission Module.',
                'data' => null,
                'debug' => app()->environment('local')
                    ? $e->getMessage()
                    : null,
            ], 500);
        }
    }

    /*
|--------------------------------------------------------------------------
| Update Permission pada Module
|--------------------------------------------------------------------------
*/
    public function updatePermission(
        Request $request,
        int $moduleId,
        int $permissionId,
    ): JsonResponse {
        $user = $request->user();

        /*
    |--------------------------------------------------------------------------
    | Permission
    |--------------------------------------------------------------------------
    */
        if (
            !$user
            || !$user->hasPermission(
                'auth_permission_module.update',
            )
        ) {
            return response()->json([
                'success' => false,
                'message' => 'Anda tidak memiliki akses untuk memperbarui Permission.',
                'data' => null,
            ], 403);
        }

        /*
    |--------------------------------------------------------------------------
    | Validation
    |--------------------------------------------------------------------------
    | module, action, dan code sengaja tidak diterima.
    |--------------------------------------------------------------------------
    */
        $validated = $request->validate([
            'name' => [
                'required',
                'string',
                'max:150',
            ],

            'description' => [
                'nullable',
                'string',
            ],

            'is_active' => [
                'required',
                'boolean',
            ],
        ]);

        /*
    |--------------------------------------------------------------------------
    | Permission Module
    |--------------------------------------------------------------------------
    */
        $module = PermissionModule::query()
            ->find($moduleId);

        if (!$module) {
            return response()->json([
                'success' => false,
                'message' => 'Permission Module tidak ditemukan.',
                'data' => null,
            ], 404);
        }

        /*
    |--------------------------------------------------------------------------
    | Permission Anak
    |--------------------------------------------------------------------------
    | Permission harus benar-benar milik module tersebut.
    |--------------------------------------------------------------------------
    */
        $permission = Permission::query()
            ->where('id', $permissionId)
            ->where('module', $module->code)
            ->first();

        if (!$permission) {
            return response()->json([
                'success' => false,
                'message' => 'Permission tidak ditemukan pada module tersebut.',
                'data' => null,
            ], 404);
        }

        $willBeActive = (bool) $validated['is_active'];

        /*
    |--------------------------------------------------------------------------
    | Proteksi Permission View
    |--------------------------------------------------------------------------
    | Module aktif wajib mempunyai permission *.view yang aktif.
    | Jangan sampai semua user terkunci karena permission view dimatikan.
    |--------------------------------------------------------------------------
    */
        if (
            $module->is_active
            && strtolower((string) $permission->action) === 'view'
            && !$willBeActive
        ) {
            throw ValidationException::withMessages([
                'is_active' => [
                    "Permission {$permission->code} tidak dapat dinonaktifkan selama module {$module->code} masih aktif.",
                ],
            ]);
        }

        try {
            DB::transaction(
                function () use (
                    $permission,
                    $validated,
                    $willBeActive,
                ): void {
                    $permission->update([
                        'name' => trim(
                            (string) $validated['name'],
                        ),

                        'description' => isset(
                            $validated['description'],
                        )
                            ? trim(
                                (string) $validated['description'],
                            )
                            : null,

                        'is_active' => $willBeActive,
                    ]);
                },
            );

            $permission->refresh();

            return response()->json([
                'success' => true,
                'message' => 'Permission berhasil diperbarui.',

                'data' => [
                    'id' => (int) $permission->id,
                    'module' => $permission->module,
                    'action' => $permission->action,
                    'code' => $permission->code,
                    'name' => $permission->name,
                    'description' => $permission->description,
                    'is_active' => (bool) $permission->is_active,
                    'created_at' => $permission->created_at,
                    'updated_at' => $permission->updated_at,
                ],
            ]);
        } catch (\Throwable $e) {
            Log::error(
                '[Permission Module] Update Permission error',
                [
                    'permission_module_id' => $moduleId,
                    'permission_id' => $permissionId,
                    'permission_code' => $permission->code,
                    'message' => $e->getMessage(),
                    'file' => $e->getFile(),
                    'line' => $e->getLine(),
                    'request' => $request->all(),
                    'user_id' => $user?->id,
                ],
            );

            return response()->json([
                'success' => false,
                'message' => 'Gagal memperbarui Permission.',
                'data' => null,
                'debug' => app()->environment('local')
                    ? $e->getMessage()
                    : null,
            ], 500);
        }
    }

    /*
|--------------------------------------------------------------------------
| Delete Permission pada Module
|--------------------------------------------------------------------------
*/
    public function destroyPermission(
        Request $request,
        int $moduleId,
        int $permissionId,
    ): JsonResponse {
        $user = $request->user();

        /*
    |--------------------------------------------------------------------------
    | Permission Access
    |--------------------------------------------------------------------------
    */
        if (
            !$user
            || !$user->hasPermission(
                'auth_permission_module.delete',
            )
        ) {
            return response()->json([
                'success' => false,
                'message' => 'Anda tidak memiliki akses untuk menghapus Permission.',
                'data' => null,
            ], 403);
        }

        try {
            return DB::transaction(
                function () use (
                    $moduleId,
                    $permissionId,
                    $user,
                ): JsonResponse {
                    /*
                |--------------------------------------------------------------------------
                | Lock Permission Module
                |--------------------------------------------------------------------------
                */
                    $module = PermissionModule::query()
                        ->lockForUpdate()
                        ->find($moduleId);

                    if (!$module) {
                        return response()->json([
                            'success' => false,
                            'message' => 'Permission Module tidak ditemukan.',
                            'data' => null,
                        ], 404);
                    }

                    /*
                |--------------------------------------------------------------------------
                | Lock Permission Anak
                |--------------------------------------------------------------------------
                */
                    $permission = Permission::query()
                        ->where('id', $permissionId)
                        ->where('module', $module->code)
                        ->lockForUpdate()
                        ->first();

                    if (!$permission) {
                        return response()->json([
                            'success' => false,
                            'message' => 'Permission tidak ditemukan pada module tersebut.',
                            'data' => null,
                        ], 404);
                    }

                    /*
                |--------------------------------------------------------------------------
                | Permission Aktif Tidak Boleh Langsung Dihapus
                |--------------------------------------------------------------------------
                */
                    if ((bool) $permission->is_active) {
                        return response()->json([
                            'success' => false,
                            'message' => 'Permission masih aktif. Nonaktifkan permission terlebih dahulu sebelum menghapusnya.',
                            'data' => [
                                'permission_code' => $permission->code,
                            ],
                        ], 422);
                    }

                    /*
                |--------------------------------------------------------------------------
                | Proteksi View Permission
                |--------------------------------------------------------------------------
                */
                    if (
                        (bool) $module->is_active
                        && strtolower(
                            trim(
                                (string) $permission->action,
                            ),
                        ) === 'view'
                    ) {
                        return response()->json([
                            'success' => false,
                            'message' => 'Permission view tidak dapat dihapus selama Permission Module masih aktif.',
                            'data' => [
                                'permission_code' => $permission->code,
                            ],
                        ], 422);
                    }

                    /*
                |--------------------------------------------------------------------------
                | Periksa Seluruh Dependency
                |--------------------------------------------------------------------------
                */
                    $rolePermissionCount = DB::table(
                        'role_permissions',
                    )
                        ->where(
                            'permission_id',
                            $permission->id,
                        )
                        ->count();

                    $userPermissionCount = DB::table(
                        'user_permissions',
                    )
                        ->where(
                            'permission_id',
                            $permission->id,
                        )
                        ->count();

                    $permissionRouteCount = DB::table(
                        'permission_routes',
                    )
                        ->where(
                            'permission_id',
                            $permission->id,
                        )
                        ->count();

                    $dependencies = [
                        'role_permissions'
                        => $rolePermissionCount,

                        'user_permissions'
                        => $userPermissionCount,

                        'permission_routes'
                        => $permissionRouteCount,
                    ];

                    $hasDependency = collect(
                        $dependencies,
                    )->contains(
                        fn(int $count): bool =>
                        $count > 0,
                    );

                    if ($hasDependency) {
                        return response()->json([
                            'success' => false,
                            'message' => 'Permission tidak dapat dihapus karena masih digunakan.',
                            'data' => [
                                'permission_code'
                                => $permission->code,

                                'dependencies'
                                => $dependencies,
                            ],
                        ], 409);
                    }

                    /*
                |--------------------------------------------------------------------------
                | Delete Permission
                |--------------------------------------------------------------------------
                */
                    $deletedPermission = [
                        'id' => (int) $permission->id,
                        'module' => $permission->module,
                        'action' => $permission->action,
                        'code' => $permission->code,
                        'name' => $permission->name,
                    ];

                    $permission->delete();

                    Log::info(
                        '[Permission Module] Permission deleted',
                        [
                            'permission_module_id'
                            => $module->id,

                            'permission_module_code'
                            => $module->code,

                            'permission'
                            => $deletedPermission,

                            'deleted_by'
                            => $user->id,
                        ],
                    );

                    return response()->json([
                        'success' => true,
                        'message' => 'Permission berhasil dihapus.',
                        'data' => $deletedPermission,
                    ]);
                },
            );
        } catch (\Throwable $e) {
            Log::error(
                '[Permission Module] Destroy Permission error',
                [
                    'permission_module_id' => $moduleId,
                    'permission_id' => $permissionId,
                    'message' => $e->getMessage(),
                    'file' => $e->getFile(),
                    'line' => $e->getLine(),
                    'user_id' => $user?->id,
                ],
            );

            return response()->json([
                'success' => false,
                'message' => 'Gagal menghapus Permission.',
                'data' => null,
                'debug' => app()->environment('local')
                    ? $e->getMessage()
                    : null,
            ], 500);
        }
    }

    /*
|--------------------------------------------------------------------------
| Delete Permission Module
|--------------------------------------------------------------------------
*/
    public function destroy(
        Request $request,
        int $id,
    ): JsonResponse {
        $user = $request->user();

        /*
    |--------------------------------------------------------------------------
    | Permission Access
    |--------------------------------------------------------------------------
    */
        if (
            !$user
            || !$user->hasPermission(
                'auth_permission_module.delete',
            )
        ) {
            return response()->json([
                'success' => false,
                'message' => 'Anda tidak memiliki akses untuk menghapus Permission Module.',
                'data' => null,
            ], 403);
        }

        try {
            return DB::transaction(
                function () use (
                    $id,
                    $user,
                ): JsonResponse {
                    /*
                |--------------------------------------------------------------------------
                | Lock Permission Module
                |--------------------------------------------------------------------------
                */
                    $module = PermissionModule::query()
                        ->lockForUpdate()
                        ->find($id);

                    if (!$module) {
                        return response()->json([
                            'success' => false,
                            'message' => 'Permission Module tidak ditemukan.',
                            'data' => null,
                        ], 404);
                    }

                    /*
                |--------------------------------------------------------------------------
                | Proteksi Module Pengelolaan Permission
                |--------------------------------------------------------------------------
                | Module ini digunakan untuk mengelola halaman Permission Module.
                | Jangan izinkan module menghapus sistem pengelolaannya sendiri.
                |--------------------------------------------------------------------------
                */
                    if (
                        $module->code
                        === 'auth_permission_module'
                    ) {
                        return response()->json([
                            'success' => false,
                            'message' => 'Module pengelolaan Permission Module tidak dapat dihapus.',
                            'data' => [
                                'module_code' => $module->code,
                            ],
                        ], 422);
                    }

                    /*
                |--------------------------------------------------------------------------
                | Module Aktif Tidak Boleh Dihapus
                |--------------------------------------------------------------------------
                */
                    if ((bool) $module->is_active) {
                        return response()->json([
                            'success' => false,
                            'message' => 'Permission Module masih aktif. Nonaktifkan module terlebih dahulu sebelum menghapusnya.',
                            'data' => [
                                'module_code' => $module->code,
                            ],
                        ], 422);
                    }

                    /*
                |--------------------------------------------------------------------------
                | Periksa Permission Anak
                |--------------------------------------------------------------------------
                */
                    $permissionCount = Permission::query()
                        ->where(
                            'module',
                            $module->code,
                        )
                        ->count();

                    $activePermissionCount = Permission::query()
                        ->where(
                            'module',
                            $module->code,
                        )
                        ->where(
                            'is_active',
                            true,
                        )
                        ->count();

                    /*
                |--------------------------------------------------------------------------
                | Periksa Approval Flow
                |--------------------------------------------------------------------------
                | Pemeriksaan dibuat defensif agar tidak error apabila tabel atau
                | kolom approval flow belum tersedia pada environment tertentu.
                |--------------------------------------------------------------------------
                */
                    $approvalFlowCount = 0;

                    if (
                        Schema::hasTable(
                            'approval_flows',
                        )
                        && Schema::hasColumn(
                            'approval_flows',
                            'module_name',
                        )
                    ) {
                        $approvalFlowCount = DB::table(
                            'approval_flows',
                        )
                            ->where(
                                'module_name',
                                $module->code,
                            )
                            ->count();
                    }

                    /*
                |--------------------------------------------------------------------------
                | Dependency Summary
                |--------------------------------------------------------------------------
                */
                    $dependencies = [
                        'permissions'
                        => $permissionCount,

                        'active_permissions'
                        => $activePermissionCount,

                        'approval_flows'
                        => $approvalFlowCount,
                    ];

                    $hasDependency = collect(
                        $dependencies,
                    )->contains(
                        fn(int $count): bool =>
                        $count > 0,
                    );

                    if ($hasDependency) {
                        return response()->json([
                            'success' => false,
                            'message' => 'Permission Module tidak dapat dihapus karena masih memiliki data yang digunakan.',
                            'data' => [
                                'module_code'
                                => $module->code,

                                'dependencies'
                                => $dependencies,
                            ],
                        ], 409);
                    }

                    /*
                |--------------------------------------------------------------------------
                | Simpan Data untuk Audit Log
                |--------------------------------------------------------------------------
                */
                    $deletedModule = [
                        'id' => (int) $module->id,
                        'code' => $module->code,
                        'name' => $module->name,
                        'description'
                        => $module->description,

                        'route_prefix'
                        => $module->route_prefix,

                        'sort_order'
                        => (int) $module->sort_order,

                        'is_active'
                        => (bool) $module->is_active,
                    ];

                    /*
                |--------------------------------------------------------------------------
                | Delete Module
                |--------------------------------------------------------------------------
                */
                    $module->delete();

                    Log::info(
                        '[Permission Module] Module deleted',
                        [
                            'permission_module'
                            => $deletedModule,

                            'deleted_by'
                            => $user->id,
                        ],
                    );

                    return response()->json([
                        'success' => true,
                        'message' => 'Permission Module berhasil dihapus.',
                        'data' => $deletedModule,
                    ]);
                },
            );
        } catch (\Throwable $e) {
            Log::error(
                '[Permission Module] Destroy error',
                [
                    'permission_module_id' => $id,
                    'message' => $e->getMessage(),
                    'file' => $e->getFile(),
                    'line' => $e->getLine(),
                    'user_id' => $user?->id,
                ],
            );

            return response()->json([
                'success' => false,
                'message' => 'Gagal menghapus Permission Module.',
                'data' => null,
                'debug' => app()->environment('local')
                    ? $e->getMessage()
                    : null,
            ], 500);
        }
    }
}
