<?php

namespace App\Http\Controllers\Api\Master;

use App\Http\Controllers\Controller;
use App\Models\Role;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class RoleController extends Controller
{
  public function index(Request $request)
  {
    $q = Role::query();

    if ($request->filled('search')) {
      $s = (string) $request->input('search');
      $q->where(function ($qq) use ($s) {
        $qq->where('kode', 'ilike', "%{$s}%")
           ->orWhere('nama', 'ilike', "%{$s}%");
      });
    }

    if ($request->filled('is_active')) {
      $q->where('is_active', filter_var($request->input('is_active'), FILTER_VALIDATE_BOOLEAN));
    }

    $perPage = (int) $request->input('per_page', 15);

    return response()->json(
      $q->orderBy('nama')->paginate($perPage)
    );
  }

  public function store(Request $request)
  {
    $data = $request->validate([
      'kode' => ['required', 'string', 'max:30', 'unique:roles,kode'],
      'nama' => ['required', 'string', 'max:120'],
      'is_active' => ['nullable', 'boolean'],
    ]);

    $row = Role::create($data);

    return response()->json($row, 201);
  }

  public function show(Role $role)
  {
    return response()->json($role);
  }

  public function update(Request $request, Role $role)
  {
    $data = $request->validate([
      'kode' => ['required', 'string', 'max:30', Rule::unique('roles', 'kode')->ignore($role->id)],
      'nama' => ['required', 'string', 'max:120'],
      'is_active' => ['nullable', 'boolean'],
    ]);

    $role->update($data);

    return response()->json($role);
  }

  public function destroy(Role $role)
  {
    $role->delete();
    return response()->json(['message' => 'Deleted']);
  }
}
