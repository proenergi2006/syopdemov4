<?php

namespace App\Http\Controllers\Api\Master;

use App\Http\Controllers\Controller;
use App\Models\Wilayah;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class WilayahController extends Controller
{
    public function index(Request $request)
    {
        $q = Wilayah::query();

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
            'kode' => ['required', 'string', 'max:20', 'unique:wilayah,kode'],
            'nama' => ['required', 'string', 'max:120'],
            'is_active' => ['nullable', 'boolean'],
        ]);

        $row = Wilayah::create($data);

        return response()->json($row, 201);
    }

    public function show(Wilayah $wilayah)
    {
        return response()->json($wilayah);
    }

    public function update(Request $request, Wilayah $wilayah)
    {
        $data = $request->validate([
            'kode' => ['required', 'string', 'max:20', Rule::unique('wilayah', 'kode')->ignore($wilayah->id)],
            'nama' => ['required', 'string', 'max:120'],
            'is_active' => ['nullable', 'boolean'],
        ]);

        $wilayah->update($data);

        return response()->json($wilayah);
    }

    public function destroy(Wilayah $wilayah)
    {
        $wilayah->delete();

        return response()->json(['message' => 'Deleted']);
    }
}
