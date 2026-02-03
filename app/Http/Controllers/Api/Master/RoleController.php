<?php

namespace App\Http\Controllers\Api\Master;

use App\Http\Controllers\Controller;
use App\Models\Role;
use Illuminate\Http\Request;

class RoleController extends Controller
{
    public function index(Request $request)
    {
        $q = Role::query()->where('is_active', true)->orderBy('nama');
        $perPage = (int) $request->input('per_page', 9999);

        return response()->json($q->paginate($perPage));
    }
}
