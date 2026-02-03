<?php

namespace App\Http\Controllers\Api\Master;

use App\Http\Controllers\Controller;
use App\Models\Provinsi;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class ProvinsiController extends Controller
{
  public function index(Request $request)
  {
    $q = Provinsi::query();

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
      'kode' => ['required','string','max:20', 'unique:provinsi,kode'],
      'nama' => ['required','string','max:150'],
      'is_active' => ['nullable','boolean'],
    ]);

    $row = Provinsi::create($data);
    return response()->json($row, 201);
  }

  public function show(Provinsi $provinsi)
  {
    return response()->json($provinsi);
  }

  public function update(Request $request, Provinsi $provinsi)
  {
    $data = $request->validate([
      'kode' => ['required','string','max:20', Rule::unique('provinsi','kode')->ignore($provinsi->id)],
      'nama' => ['required','string','max:150'],
      'is_active' => ['nullable','boolean'],
    ]);

    $provinsi->update($data);
    return response()->json($provinsi);
  }

  public function destroy(Provinsi $provinsi)
  {
    $provinsi->delete();
    return response()->json(['message' => 'Deleted']);
  }
}
