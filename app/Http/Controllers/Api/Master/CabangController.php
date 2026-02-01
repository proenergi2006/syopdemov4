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
    $q = Cabang::query()->with('wilayah:id,kode,nama');

    if ($request->filled('wilayah_id')) {
      $q->where('wilayah_id', $request->integer('wilayah_id'));
    }

    if ($request->filled('search')) {
      $s = $request->string('search')->toString();
      $q->where(function ($qq) use ($s) {
        $qq->where('kode', 'ilike', "%{$s}%")
           ->orWhere('nama', 'ilike', "%{$s}%");
      });
    }

    if ($request->filled('is_active')) {
      $q->where('is_active', filter_var($request->is_active, FILTER_VALIDATE_BOOLEAN));
    }

    return response()->json(
      $q->orderBy('nama')->paginate($request->integer('per_page', 15))
    );
  }

  public function store(Request $request)
  {
    $data = $request->validate([
      'wilayah_id' => ['required','integer','exists:wilayah,id'],
      'kode' => ['required','string','max:20','unique:cabangs,kode'],
      'nama' => ['required','string','max:150'],
      'alamat' => ['nullable','string','max:255'],
      'is_active' => ['nullable','boolean'],
    ]);

    $row = Cabang::create($data);
    return response()->json($row->load('wilayah:id,kode,nama'), 201);
  }

  public function show(Cabang $cabang)
  {
    return response()->json($cabang->load('wilayah:id,kode,nama'));
  }

  public function update(Request $request, Cabang $cabang)
  {
    $data = $request->validate([
      'wilayah_id' => ['required','integer','exists:wilayah,id'],
      'kode' => ['required','string','max:20', Rule::unique('cabangs','kode')->ignore($cabang->id)],
      'nama' => ['required','string','max:150'],
      'alamat' => ['nullable','string','max:255'],
      
      'is_active' => ['nullable','boolean'],
    ]);

    $cabang->update($data);
    return response()->json($cabang->load('wilayah:id,kode,nama'));
  }

  public function destroy(Cabang $cabang)
  {
    $cabang->delete();
    return response()->json(['message' => 'Deleted']);
  }
}
