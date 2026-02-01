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
      'kode' => ['required','string','max:20','unique:departemens,kode'],
      'nama' => ['required','string','max:150'],
      'is_active' => ['nullable','boolean'],
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
      'kode' => ['required','string','max:20', Rule::unique('departemens','kode')->ignore($departemen->id)],
      'nama' => ['required','string','max:150'],
      'is_active' => ['nullable','boolean'],
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
