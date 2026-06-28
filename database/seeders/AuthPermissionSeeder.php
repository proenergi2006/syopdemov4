<?php

namespace Database\Seeders;

use App\Models\Permission;
use App\Models\PermissionModule;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class AuthPermissionSeeder extends Seeder
{
    public function run(): void
    {
        DB::transaction(function (): void {
            $modules = [
                /*
                |--------------------------------------------------------------------------
                | Permission Modules Management
                |--------------------------------------------------------------------------
                */
                [
                    'code' => 'auth_permission_module',
                    'name' => 'Auth - Permission Modules',
                    'description'
                    => 'Module pengelolaan master permission module dan permission.',
                    'route_prefix'
                    => '/master/permission-modules',
                    'sort_order' => 150,
                    'permissions' => [
                        [
                            'action' => 'view',
                            'name' => 'View Permission Modules',
                            'description'
                            => 'Mengizinkan pengguna melihat halaman dan daftar permission module.',
                        ],
                        [
                            'action' => 'create',
                            'name' => 'Create Permission Module',
                            'description'
                            => 'Mengizinkan pengguna membuat permission module baru.',
                        ],
                        [
                            'action' => 'update',
                            'name' => 'Update Permission Module',
                            'description'
                            => 'Mengizinkan pengguna memperbarui permission module dan permission.',
                        ],
                        [
                            'action' => 'delete',
                            'name' => 'Delete Permission Module',
                            'description'
                            => 'Mengizinkan pengguna menghapus permission module yang memenuhi ketentuan.',
                        ],
                    ],
                ],
                /*
                |--------------------------------------------------------------------------
                | Users
                |--------------------------------------------------------------------------
                */
                [
                    'code' => 'auth_user',
                    'name' => 'Auth - Users',
                    'description' => 'Module pengelolaan akun pengguna sistem.',
                    'route_prefix' => '/master/users',
                    'sort_order' => 100,
                    'permissions' => [
                        [
                            'action' => 'view',
                            'code' => 'auth_user.view',
                            'name' => 'View Users',
                            'description' => 'Mengizinkan pengguna melihat halaman dan daftar akun user.',
                        ],
                        [
                            'action' => 'create',
                            'code' => 'auth_user.create',
                            'name' => 'Create User',
                            'description' => 'Mengizinkan pengguna membuat akun user baru.',
                        ],
                        [
                            'action' => 'update',
                            'code' => 'auth_user.update',
                            'name' => 'Update User',
                            'description' => 'Mengizinkan pengguna memperbarui akun user.',
                        ],
                        [
                            'action' => 'delete',
                            'code' => 'auth_user.delete',
                            'name' => 'Delete User',
                            'description' => 'Mengizinkan pengguna menghapus akun user.',
                        ],
                    ],
                ],

                /*
                |--------------------------------------------------------------------------
                | Roles
                |--------------------------------------------------------------------------
                */
                [
                    'code' => 'auth_role',
                    'name' => 'Auth - Roles',
                    'description' => 'Module pengelolaan role pengguna.',
                    'route_prefix' => '/master/roles',
                    'sort_order' => 110,
                    'permissions' => [
                        [
                            'action' => 'view',
                            'code' => 'auth_role.view',
                            'name' => 'View Roles',
                            'description' => 'Mengizinkan pengguna melihat halaman dan daftar role.',
                        ],
                        [
                            'action' => 'create',
                            'code' => 'auth_role.create',
                            'name' => 'Create Role',
                            'description' => 'Mengizinkan pengguna membuat role baru.',
                        ],
                        [
                            'action' => 'update',
                            'code' => 'auth_role.update',
                            'name' => 'Update Role',
                            'description' => 'Mengizinkan pengguna memperbarui role.',
                        ],
                        [
                            'action' => 'delete',
                            'code' => 'auth_role.delete',
                            'name' => 'Delete Role',
                            'description' => 'Mengizinkan pengguna menghapus role.',
                        ],
                    ],
                ],

                /*
                |--------------------------------------------------------------------------
                | Role Menu
                |--------------------------------------------------------------------------
                */
                [
                    'code' => 'auth_role_menu',
                    'name' => 'Auth - Role Menu',
                    'description' => 'Module pengaturan akses menu berdasarkan role.',
                    'route_prefix' => '/master/role-menu',
                    'sort_order' => 120,
                    'permissions' => [
                        [
                            'action' => 'view',
                            'code' => 'auth_role_menu.view',
                            'name' => 'View Role Menu',
                            'description' => 'Mengizinkan pengguna melihat pengaturan Role Menu.',
                        ],
                        [
                            'action' => 'update',
                            'code' => 'auth_role_menu.update',
                            'name' => 'Update Role Menu',
                            'description' => 'Mengizinkan pengguna memperbarui pengaturan Role Menu.',
                        ],
                    ],
                ],

                /*
                |--------------------------------------------------------------------------
                | Role Permissions
                |--------------------------------------------------------------------------
                */
                [
                    'code' => 'auth_role_permission',
                    'name' => 'Auth - Role Permissions',
                    'description' => 'Module pengaturan permission berdasarkan role.',
                    'route_prefix' => '/master/role-permissions',
                    'sort_order' => 130,
                    'permissions' => [
                        [
                            'action' => 'view',
                            'code' => 'auth_role_permission.view',
                            'name' => 'View Role Permissions',
                            'description' => 'Mengizinkan pengguna melihat pengaturan permission role.',
                        ],
                        [
                            'action' => 'update',
                            'code' => 'auth_role_permission.update',
                            'name' => 'Update Role Permissions',
                            'description' => 'Mengizinkan pengguna memperbarui permission role.',
                        ],
                    ],
                ],

                /*
                |--------------------------------------------------------------------------
                | Direct User Permission
                |--------------------------------------------------------------------------
                */
                [
                    'code' => 'auth_user_permission',
                    'name' => 'Auth - User Permission',
                    'description' => 'Module pengaturan direct permission per pengguna.',
                    'route_prefix' => '/master/user-permission',
                    'sort_order' => 140,
                    'permissions' => [
                        [
                            'action' => 'view',
                            'code' => 'auth_user_permission.view',
                            'name' => 'View User Permission',
                            'description' => 'Mengizinkan pengguna melihat direct permission user.',
                        ],
                        [
                            'action' => 'update',
                            'code' => 'auth_user_permission.update',
                            'name' => 'Update User Permission',
                            'description' => 'Mengizinkan pengguna memperbarui direct permission user.',
                        ],
                    ],
                ],
            ];

            foreach ($modules as $moduleData) {
                $permissions = $moduleData['permissions'];

                unset($moduleData['permissions']);

                /*
                |--------------------------------------------------------------------------
                | Module
                |--------------------------------------------------------------------------
                */
                $module = PermissionModule::query()->updateOrCreate(
                    [
                        'code' => $moduleData['code'],
                    ],
                    [
                        'name' => $moduleData['name'],
                        'description' => $moduleData['description'],
                        'route_prefix' => $moduleData['route_prefix'],
                        'sort_order' => $moduleData['sort_order'],
                        'is_active' => true,
                    ],
                );

                /*
                |--------------------------------------------------------------------------
                | Permissions
                |--------------------------------------------------------------------------
                */
                foreach ($permissions as $permissionData) {
                    $permissionCode = $module->code
                        . '.'
                        . $permissionData['action'];

                    Permission::query()->updateOrCreate(
                        [
                            'code' => $permissionCode,
                        ],
                        [
                            'module' => $module->code,
                            'action' => $permissionData['action'],
                            'name' => $permissionData['name'],
                            'description' => $permissionData['description'],
                            'is_active' => true,
                        ],
                    );
                }
            }
        });
    }
}
