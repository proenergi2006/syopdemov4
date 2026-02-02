<?php

namespace App\Http\Controllers\Api\Master;

use App\Http\Controllers\Controller;
use App\Models\Departemen;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class DepartemenController extends Controller
{
    public function index(Request $request)
    {
        $q = Departemen::query();

        if ($request->filled('search')) {
            $s = (string) $request->input('search');
            $q->where(function ($qq) use ($s) {
                $qq->where('kode', 'like', "%{$s}%")
                   ->orWhere('nama', 'like', "%{$s}%");
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
            'kode' => ['required', 'string', 'max:20', 'unique:departemen,kode'],
            'nama' => ['required', 'string', 'max:150'],
            'is_active' => ['nullable', 'boolean'],
        ]);

        $row = Departemen::create($data);

        return response()->json($row, 201);
    }

    public function show(Departemen $departemen)
    {
        return response()->json($departemen);
    }

    public function update(Request $request, Departemen $departemen)
    {
        $data = $request->validate([
            'kode' => ['required', 'string', 'max:20', Rule::unique('departemen', 'kode')->ignore($departemen->id)],
            'nama' => ['required', 'string', 'max:150'],
            'is_active' => ['nullable', 'boolean'],
        ]);

        $departemen->update($data);

        return response()->json($departemen);
    }

    public function destroy(Departemen $departemen)
    {
        $departemen->delete();

        return response()->json(['message' => 'Deleted']);
    }
}
