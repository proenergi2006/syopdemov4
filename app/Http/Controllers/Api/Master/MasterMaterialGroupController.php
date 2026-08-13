<?php

namespace App\Http\Controllers\Api\Master;

use App\Http\Controllers\Controller;
use App\Models\MasterMaterialGroup;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class MasterMaterialGroupController extends Controller
{
    /**
     * Dropdown Material Group untuk form input item.
     */
    public function dropdownSelect(Request $request): JsonResponse
    {
        try {
            $query = MasterMaterialGroup::query()
                ->where('is_active', true)
                ->orderBy('code', 'asc');

            if ($request->search) {
                $search = $request->search;

                $query->where(function ($q) use ($search) {
                    $q->where('code', 'ILIKE', "%{$search}%")
                        ->orWhere('name', 'ILIKE', "%{$search}%")
                        ->orWhere('description', 'ILIKE', "%{$search}%");
                });
            }

            $materialGroups = $query->get()->map(function ($group) {
                return [
                    'id' => $group->id,
                    'value' => $group->id,

                    'code' => $group->code,
                    'name' => $group->name,
                    'description' => $group->description,

                    'title' => $group->code . ' - ' . $group->name,
                ];
            });

            return response()->json([
                'success' => true,
                'data' => $materialGroups,
            ]);
        } catch (\Throwable $e) {
            Log::error('[Master Material Group] Dropdown error', [
                'message' => $e->getMessage(),
                'file' => $e->getFile(),
                'line' => $e->getLine(),
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Gagal memuat data Material Group.',
                'data' => [],
            ], 500);
        }
    }
}
