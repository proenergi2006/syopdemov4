<?php

namespace App\Http\Controllers\Api\Master;

use App\Http\Controllers\Controller;
use App\Models\Cabang;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class CabangController extends Controller
{
    public function index(Request $request)
    {
        $q = Cabang::query();

        // search kode/nama
        if ($request->filled('search')) {
            $s = (string) $request->input('search');
            $q->where(function ($qq) use ($s) {
                $qq->where('kode', 'like', "%{$s}%")
                   ->orWhere('nama', 'like', "%{$s}%");
            });
        }

        // filter status
        if ($request->filled('is_active')) {
            $q->where('is_active', filter_var($request->input('is_active'), FILTER_VALIDATE_BOOLEAN));
        }

        // filter wilayah
        if ($request->filled('wilayah_id')) {
            $q->where('wilayah_id', (int) $request->input('wilayah_id'));
        }

        $perPage = (int) $request->input('per_page', 15);

        return response()->json(
            $q->orderBy('nama')->paginate($perPage)
        );
    }

    public function store(Request $request)
    {
        $data = $request->validate([
            'kode' => ['required', 'string', 'max:20', 'unique:cabang,kode'],
            'nama' => ['required', 'string', 'max:150'],
            'wilayah_id' => ['required', 'integer'],
            'is_active' => ['nullable', 'boolean'],
        ]);

        $row = Cabang::create($data);

        return response()->json($row, 201);
    }

    public function show(Cabang $cabang)
    {
        return response()->json($cabang);
    }

    public function update(Request $request, Cabang $cabang)
    {
        $data = $request->validate([
            'kode' => ['required', 'string', 'max:20', Rule::unique('cabang', 'kode')->ignore($cabang->id)],
            'nama' => ['required', 'string', 'max:150'],
            'wilayah_id' => ['required', 'integer'],
            'is_active' => ['nullable', 'boolean'],
        ]);

        $cabang->update($data);

        return response()->json($cabang);
    }

    public function destroy(Cabang $cabang)
    {
        $cabang->delete();

        return response()->json(['message' => 'Deleted']);
    }
}
