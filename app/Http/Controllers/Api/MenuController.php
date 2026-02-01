<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class MenuController extends Controller
{
    public function myMenus(Request $request)
    {
        $user = $request->user();

        // 1) Ambil role user (pivot user_roles)
        $roleIds = DB::table('user_roles')
            ->where('user_id', $user->id)
            ->pluck('role_id');

        // Jika user belum punya role, return kosong
        if ($roleIds->isEmpty()) {
            return response()->json([]);
        }

        // 2) Ambil menu berdasarkan role (pivot role_menus)
        $rows = DB::table('menus as m')
            ->join('role_menus as rm', 'rm.menu_id', '=', 'm.id')
            ->whereIn('rm.role_id', $roleIds)
            ->where('m.is_active', true)
            ->select('m.id', 'm.parent_id', 'm.name', 'm.path', 'm.route_name', 'm.icon', 'm.order_no')
            ->orderByRaw('COALESCE(m.parent_id, 0) ASC')
            ->orderBy('m.order_no')
            ->get();

        // Jika tidak ada menu, return kosong
        if ($rows->isEmpty()) {
            return response()->json([]);
        }

        // 3) Build map node by id
        $byId = [];

        foreach ($rows as $r) {

            // Materio: leaf item butuh `to`
            // kita utamakan path karena paling aman match router
            $to = null;

            if (!empty($r->path)) {
                $to = ['path' => $r->path];
            } elseif (!empty($r->route_name)) {
                $to = ['name' => $r->route_name];
            }

            $byId[$r->id] = [
                'id' => $r->id,
                'parent_id' => $r->parent_id,
                'title' => $r->name,
                // icon Materio biasanya: { icon: 'tabler-xxx' }
                // kalau kamu sudah pakai string "tabler-xxx" di DB, ini sudah cocok
                'icon' => !empty($r->icon) ? ['icon' => $r->icon] : null,
                'to' => $to,
                'children' => [],
            ];
        }

        // 4) Build tree
        $tree = [];

        foreach ($byId as $id => &$node) {
            $pid = $node['parent_id'];

            if (!empty($pid) && isset($byId[$pid])) {
                $byId[$pid]['children'][] = &$node;
            } else {
                $tree[] = &$node;
            }
        }
        unset($node);

        // 5) Cleanup output untuk Materio
        // - hapus id & parent_id
        // - parent punya children => hapus "to" (biar jadi group)
        // - leaf tidak punya children => pastikan ada "to"
        // - buang icon/children kalau kosong
        $clean = function (array $items) use (&$clean) {
            return array_values(array_map(function (array $it) use (&$clean) {

                unset($it['id'], $it['parent_id']);

                if (empty($it['icon'])) {
                    unset($it['icon']);
                }

                if (!empty($it['children'])) {
                    $it['children'] = $clean($it['children']);
                    // parent group tidak boleh punya to
                    unset($it['to']);
                } else {
                    unset($it['children']);
                    // leaf wajib punya to
                    if (empty($it['to'])) {
                        $it['to'] = ['path' => '#'];
                    }
                }

                return $it;
            }, $items));
        };

        $result = $clean($tree);

        return response()->json($result);
    }
}
