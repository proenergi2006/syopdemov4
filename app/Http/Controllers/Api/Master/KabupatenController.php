<?php

namespace App\Http\Controllers\Api\Master;

use App\Http\Controllers\Controller;
use App\Models\Kabupaten;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class KabupatenController extends Controller
{
  public function index(Request $request)
  {
    $q = Kabupaten::query()
      ->with(['provinsi:id,kode,nama']); // supaya UI bisa tampil nama provinsi

    if ($request->filled('provinsi_id')) {
      $q->where('provinsi_id', (int) $request->input('provinsi_id'));
    }

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

    $perPage = (int) $request->input('per_page', 10);

    return response()->json(
      $q->orderBy('nama')->paginate($perPage)
    );
  }

  public function store(Request $request)
  {
    $data = $request->validate([
      'provinsi_id' => ['required', 'integer', 'exists:provinsi,id'],
      'kode' => ['required', 'string', 'max:20', 'unique:kabupaten,kode'],
      'nama' => ['required', 'string', 'max:150'],
      'is_active' => ['nullable', 'boolean'],
    ]);

    $row = Kabupaten::create($data);
    return response()->json($row->load('provinsi:id,kode,nama'), 201);
  }

  public function show(Kabupaten $kabupaten)
  {
    return response()->json($kabupaten->load('provinsi:id,kode,nama'));
  }

  public function update(Request $request, Kabupaten $kabupaten)
  {
    $data = $request->validate([
      'provinsi_id' => ['required', 'integer', 'exists:provinsi,id'],
      'kode' => ['required', 'string', 'max:20', Rule::unique('kabupaten', 'kode')->ignore($kabupaten->id)],
      'nama' => ['required', 'string', 'max:150'],
      'is_active' => ['nullable', 'boolean'],
    ]);

    $kabupaten->update($data);
    return response()->json($kabupaten->load('provinsi:id,kode,nama'));
  }

  public function destroy(Kabupaten $kabupaten)
  {
    $kabupaten->delete();
    return response()->json(['message' => 'Deleted']);
  }
}
