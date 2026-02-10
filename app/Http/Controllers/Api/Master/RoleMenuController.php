<?php

namespace App\Http\Controllers\Api\Master;

use App\Http\Controllers\Controller;
use App\Models\Menu;
use App\Models\Role;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class RoleMenuController extends Controller
{
    public function index(Request $request)
    {
        $roleId = $request->query('role_id');

        $roles = Role::query()
            ->where('is_active', true)
            ->orderBy('nama')
            ->get(['id','kode','nama']);

        $menus = Menu::query()
            ->where('is_active', true)
            ->orderByRaw('COALESCE(parent_id, 0) ASC')
            ->orderBy('order_no')
            ->get(['id','parent_id','name','icon','order_no']);

        $checked = [];
        if ($roleId) {
            $checked = DB::table('role_menus')
                ->where('role_id', (int) $roleId)
                ->pluck('menu_id')
                ->map(fn($x) => (int) $x)
                ->values()
                ->all();
        }

        return response()->json([
            'roles'   => $roles,
            'menus'   => $menus,
            'checked' => $checked,
        ]);
    }

    public function store(Request $request)
    {
        $data = $request->validate([
            'role_id' => ['required','integer','exists:roles,id'],
            'menu_ids' => ['required','array'],
            'menu_ids.*' => ['integer','exists:menus,id'],
        ]);

        $roleId = (int) $data['role_id'];
        $menuIds = collect($data['menu_ids'])->map(fn($x) => (int) $x)->unique()->values();

        DB::transaction(function () use ($roleId, $menuIds) {
            // HAPUS SEMUA DULU -> ini yang bikin row pasti berkurang saat uncheck
            DB::table('role_menus')->where('role_id', $roleId)->delete();

            if ($menuIds->isNotEmpty()) {
                $insert = $menuIds->map(fn($mid) => [
                    'role_id' => $roleId,
                    'menu_id' => $mid,
                ])->all();

                DB::table('role_menus')->insert($insert);
            }
        });

        return response()->json([
            'message' => 'Role menus saved',
            'checked' => DB::table('role_menus')
                ->where('role_id', $roleId)
                ->pluck('menu_id')
                ->map(fn($x) => (int) $x)
                ->values()
                ->all(),
        ]);
    }
}
