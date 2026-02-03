<?php

namespace App\Http\Controllers\Api\Master;

use App\Http\Controllers\Controller;
use App\Models\Area;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;

class AreaController extends Controller
{
  public function index(Request $request)
  {
    $q = Area::query();

    if ($request->filled('search')) {
      $s = (string) $request->input('search');
      $q->where('nama_area', 'ilike', "%{$s}%");
    }

    if ($request->filled('is_active')) {
      $q->where('is_active', filter_var($request->input('is_active'), FILTER_VALIDATE_BOOLEAN));
    }

    $perPage = (int) $request->input('per_page', 15);

    return response()->json(
      $q->orderBy('nama_area')->paginate($perPage)
    );
  }

  public function store(Request $request)
  {
    $data = $request->validate([
      'nama_area' => ['required', 'string', 'max:150'],
      'wapu' => ['nullable', 'boolean'],
      'is_active' => ['nullable', 'boolean'],
      'lampiran' => ['nullable', 'file', 'max:5120'], // max 5MB, sesuaikan
    ]);

    $row = new Area();
    $row->nama_area = $data['nama_area'];
    $row->wapu = (bool)($data['wapu'] ?? false);
    $row->is_active = (bool)($data['is_active'] ?? true);

    // audit
    $row->created_time = now();
    $row->created_ip = $request->ip();
    $row->created_by = optional($request->user())->id;

    // upload lampiran
    if ($request->hasFile('lampiran')) {
      $path = $request->file('lampiran')->store('area', 'public');
      $row->lampiran = $path;
    }

    $row->save();

    return response()->json($row, 201);
  }

  public function show(Area $area)
  {
    return response()->json($area);
  }

  public function update(Request $request, Area $area)
  {
    $data = $request->validate([
      'nama_area' => ['required', 'string', 'max:150'],
      'wapu' => ['nullable', 'boolean'],
      'is_active' => ['nullable', 'boolean'],
      'lampiran' => ['nullable', 'file', 'max:5120'],
      'remove_lampiran' => ['nullable', 'boolean'],
    ]);

    $area->nama_area = $data['nama_area'];
    $area->wapu = (bool)($data['wapu'] ?? $area->wapu);
    $area->is_active = (bool)($data['is_active'] ?? $area->is_active);

    // audit
    $area->lastupdate_time = now();

    // remove lampiran
    if (!empty($data['remove_lampiran']) && $area->lampiran) {
      Storage::disk('public')->delete($area->lampiran);
      $area->lampiran = null;
    }

    // upload lampiran baru
    if ($request->hasFile('lampiran')) {
      // hapus yang lama kalau ada
      if ($area->lampiran) Storage::disk('public')->delete($area->lampiran);

      $path = $request->file('lampiran')->store('area', 'public');
      $area->lampiran = $path;
    }

    $area->save();

    return response()->json($area);
  }

  public function destroy(Area $area)
  {
    if ($area->lampiran) {
      Storage::disk('public')->delete($area->lampiran);
    }

    $area->delete();

    return response()->json(['message' => 'Deleted']);
  }
}
