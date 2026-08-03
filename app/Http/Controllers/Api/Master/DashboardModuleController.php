<?php

namespace App\Http\Controllers\Api\Master;

use App\Http\Controllers\Controller;
use App\Models\Dashboard\DashboardModule;
use App\Models\Dashboard\DashboardModuleGroup;
use App\Models\Permission;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class DashboardModuleController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $this->ensurePermission(
            request: $request,
            permission: 'dashboard_module.view',
            message: 'Anda tidak memiliki akses melihat Kelola Dashboard Module.',
        );

        $modules = DashboardModule::query()
            ->with('group')
            ->orderBy('sort_order')
            ->orderBy('title')
            ->get();

        return response()->json([
            'success' => true,
            'message' => 'Data dashboard module berhasil dimuat.',
            'data' => $modules,
        ]);
    }

    public function groups(Request $request): JsonResponse
    {
        $this->ensurePermission(
            request: $request,
            permission: 'dashboard_module.view',
            message: 'Anda tidak memiliki akses melihat Kelola Dashboard Module.',
        );

        $groups = DashboardModuleGroup::query()
            ->withCount('modules')
            ->orderBy('sort_order')
            ->orderBy('name')
            ->get();

        return response()->json([
            'success' => true,
            'message' => 'Data dashboard module group berhasil dimuat.',
            'data' => $groups,
        ]);
    }

    public function storeGroup(Request $request): JsonResponse
    {
        $this->ensurePermission(
            request: $request,
            permission: 'dashboard_module.create',
            message: 'Anda tidak memiliki akses membuat dashboard module group.',
        );

        $validated = $this->validateGroupPayload($request);

        $group = DashboardModuleGroup::create($validated);

        return response()->json([
            'success' => true,
            'message' => 'Dashboard module group berhasil dibuat.',
            'data' => $group->fresh(),
        ], 201);
    }

    public function updateGroup(Request $request, DashboardModuleGroup $group): JsonResponse
    {
        $this->ensurePermission(
            request: $request,
            permission: 'dashboard_module.update',
            message: 'Anda tidak memiliki akses memperbarui dashboard module group.',
        );

        $validated = $this->validateGroupPayload($request, $group);

        $group->update($validated);

        return response()->json([
            'success' => true,
            'message' => 'Dashboard module group berhasil diperbarui.',
            'data' => $group->fresh(),
        ]);
    }

    public function toggleGroupActive(Request $request, DashboardModuleGroup $group): JsonResponse
    {
        $this->ensurePermission(
            request: $request,
            permission: 'dashboard_module.update',
            message: 'Anda tidak memiliki akses mengubah status dashboard module group.',
        );

        $group->update([
            'is_active' => !$group->is_active,
        ]);

        return response()->json([
            'success' => true,
            'message' => $group->is_active
                ? 'Dashboard module group berhasil diaktifkan.'
                : 'Dashboard module group berhasil dinonaktifkan.',
            'data' => $group->fresh(),
        ]);
    }

    public function destroyGroup(Request $request, DashboardModuleGroup $group): JsonResponse
    {
        $this->ensurePermission(
            request: $request,
            permission: 'dashboard_module.delete',
            message: 'Anda tidak memiliki akses menghapus dashboard module group.',
        );

        $hasModules = DashboardModule::query()
            ->where('dashboard_module_group_id', $group->id)
            ->exists();

        if ($hasModules) {
            return response()->json([
                'success' => false,
                'message' => 'Group masih memiliki dashboard module. Pindahkan atau hapus module tersebut terlebih dahulu.',
            ], 422);
        }

        $group->delete();

        return response()->json([
            'success' => true,
            'message' => 'Dashboard module group berhasil dihapus.',
        ]);
    }

    public function permissionOptions(Request $request): JsonResponse
    {
        $this->ensurePermission(
            request: $request,
            permission: 'dashboard_module.view',
            message: 'Anda tidak memiliki akses melihat Kelola Dashboard Module.',
        );

        $permissions = Permission::query()
            ->where('module', 'dashboard')
            ->orderBy('code')
            ->get(['id', 'code', 'name']);

        return response()->json([
            'success' => true,
            'message' => 'Data permission dashboard berhasil dimuat.',
            'data' => $permissions,
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $this->ensurePermission(
            request: $request,
            permission: 'dashboard_module.create',
            message: 'Anda tidak memiliki akses membuat dashboard module.',
        );

        $validated = $this->validatePayload($request);

        $module = DashboardModule::create($validated);

        return response()->json([
            'success' => true,
            'message' => 'Dashboard module berhasil dibuat.',
            'data' => $module->fresh('group'),
        ], 201);
    }

    public function show(Request $request, DashboardModule $dashboardModule): JsonResponse
    {
        $this->ensurePermission(
            request: $request,
            permission: 'dashboard_module.view',
            message: 'Anda tidak memiliki akses melihat detail dashboard module.',
        );

        return response()->json([
            'success' => true,
            'message' => 'Detail dashboard module berhasil dimuat.',
            'data' => $dashboardModule->load('group'),
        ]);
    }

    public function update(Request $request, DashboardModule $dashboardModule): JsonResponse
    {
        $this->ensurePermission(
            request: $request,
            permission: 'dashboard_module.update',
            message: 'Anda tidak memiliki akses memperbarui dashboard module.',
        );

        $validated = $this->validatePayload($request, $dashboardModule);

        $dashboardModule->update($validated);

        return response()->json([
            'success' => true,
            'message' => 'Dashboard module berhasil diperbarui.',
            'data' => $dashboardModule->fresh('group'),
        ]);
    }

    public function toggleActive(Request $request, DashboardModule $dashboardModule): JsonResponse
    {
        $this->ensurePermission(
            request: $request,
            permission: 'dashboard_module.update',
            message: 'Anda tidak memiliki akses mengubah status dashboard module.',
        );

        $dashboardModule->update([
            'is_active' => !$dashboardModule->is_active,
        ]);

        return response()->json([
            'success' => true,
            'message' => $dashboardModule->is_active
                ? 'Dashboard module berhasil diaktifkan.'
                : 'Dashboard module berhasil dinonaktifkan.',
            'data' => $dashboardModule->fresh('group'),
        ]);
    }

    public function toggleAvailable(Request $request, DashboardModule $dashboardModule): JsonResponse
    {
        $this->ensurePermission(
            request: $request,
            permission: 'dashboard_module.update',
            message: 'Anda tidak memiliki akses mengubah status ketersediaan dashboard module.',
        );

        $dashboardModule->update([
            'is_available' => !$dashboardModule->is_available,
        ]);

        return response()->json([
            'success' => true,
            'message' => $dashboardModule->is_available
                ? 'Dashboard module berhasil ditandai tersedia.'
                : 'Dashboard module berhasil ditandai coming soon.',
            'data' => $dashboardModule->fresh('group'),
        ]);
    }

    public function destroy(Request $request, DashboardModule $dashboardModule): JsonResponse
    {
        $this->ensurePermission(
            request: $request,
            permission: 'dashboard_module.delete',
            message: 'Anda tidak memiliki akses menghapus dashboard module.',
        );

        $dashboardModule->delete();

        return response()->json([
            'success' => true,
            'message' => 'Dashboard module berhasil dihapus.',
        ]);
    }

    private function ensurePermission(
        Request $request,
        string $permission,
        string $message,
    ): void {
        $user = $request->user();

        if (
            !$user
            || !$user->hasPermission($permission)
        ) {
            abort(403, $message);
        }
    }

    private function validatePayload(
        Request $request,
        ?DashboardModule $dashboardModule = null,
    ): array {
        $validated = $request->validate([
            'dashboard_module_group_id' => [
                'required',
                'integer',
                Rule::exists('dashboard_module_groups', 'id'),
            ],
            'code' => [
                'required',
                'string',
                'max:100',
                Rule::unique('dashboard_modules', 'code')
                    ->ignore($dashboardModule?->id),
            ],
            'title' => [
                'required',
                'string',
                'max:150',
            ],
            'short_title' => [
                'nullable',
                'string',
                'max:50',
            ],
            'description' => [
                'nullable',
                'string',
            ],
            'icon' => [
                'nullable',
                'string',
                'max:100',
            ],
            'color' => [
                'nullable',
                'string',
                'max:30',
            ],
            'route_path' => [
                'nullable',
                'string',
                'max:255',
            ],
            'permission_name' => [
                'nullable',
                'string',
                'max:150',
            ],
            'features' => [
                'nullable',
                'array',
            ],
            'features.*' => [
                'string',
                'max:150',
            ],
            'is_active' => [
                'nullable',
                'boolean',
            ],
            'is_available' => [
                'nullable',
                'boolean',
            ],
            'sort_order' => [
                'nullable',
                'integer',
                'min:0',
            ],
        ]);

        $validated['short_title'] = $validated['short_title'] ?? null;
        $validated['description'] = $validated['description'] ?? null;
        $validated['icon'] = $validated['icon'] ?? null;
        $validated['color'] = $validated['color'] ?? 'primary';
        $validated['route_path'] = $validated['route_path'] ?? null;
        $validated['permission_name'] = $validated['permission_name'] ?? null;
        $validated['features'] = $validated['features'] ?? [];
        $validated['is_active'] = (bool) ($validated['is_active'] ?? true);
        $validated['is_available'] = (bool) ($validated['is_available'] ?? false);
        $validated['sort_order'] = (int) ($validated['sort_order'] ?? 0);

        return $validated;
    }

    private function validateGroupPayload(
        Request $request,
        ?DashboardModuleGroup $group = null,
    ): array {
        $validated = $request->validate([
            'code' => [
                'required',
                'string',
                'max:50',
                Rule::unique('dashboard_module_groups', 'code')
                    ->ignore($group?->id),
            ],
            'name' => [
                'required',
                'string',
                'max:100',
            ],
            'icon' => [
                'nullable',
                'string',
                'max:100',
            ],
            'sort_order' => [
                'nullable',
                'integer',
                'min:0',
            ],
            'is_active' => [
                'nullable',
                'boolean',
            ],
        ]);

        $validated['icon'] = $validated['icon'] ?? null;
        $validated['sort_order'] = (int) ($validated['sort_order'] ?? 0);
        $validated['is_active'] = (bool) ($validated['is_active'] ?? true);

        return $validated;
    }
}
