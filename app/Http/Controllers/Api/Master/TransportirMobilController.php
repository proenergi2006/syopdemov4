<?php

namespace App\Http\Controllers\Api\Master;

use App\Http\Controllers\Controller;
use App\Models\TransportirMobil;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Storage;

class TransportirMobilController extends Controller
{
    // GET /api/master/transportir-mobil
    public function index(Request $request)
    {
        $q = TransportirMobil::query()
            ->with(['transportir:id,nama_transportir']);

        // search plat / no_proyek
        if ($request->filled('search')) {
            $s = trim((string) $request->input('search'));

            $q->where(function ($w) use ($s) {
                $w->where('nomor_plat', 'ilike', "%{$s}%")
                  ->orWhere('no_proyek', 'ilike', "%{$s}%");
            });
        }

        if ($request->filled('id_transportir')) {
            $q->where('id_transportir', (int) $request->input('id_transportir'));
        }

        // IMPORTANT: jangan pakai filled() kalau bisa bernilai 0
        // terima: 0/1, "0"/"1", true/false
        if ($request->has('is_active') && $request->input('is_active') !== '' && $request->input('is_active') !== null) {
            $val = $request->input('is_active');

            // normalisasi
            if ($val === true || $val === 'true') $val = 1;
            if ($val === false || $val === 'false') $val = 0;

            $q->where('is_active', (int) $val);
        }

        $perPage = (int) $request->input('per_page', 10);
        if ($perPage <= 0) $perPage = 10;
        if ($perPage > 100) $perPage = 100;

        return response()->json(
            $q->orderBy('nomor_plat')->paginate($perPage)
        );
    }

    // POST /api/master/transportir-mobil
    public function store(Request $request)
    {
        $data = $request->validate([
            'id_transportir' => ['required', 'integer', 'exists:transportir,id'],
            'nomor_plat'     => ['required', 'string', 'max:20'],
            'no_proyek'      => ['nullable', 'string', 'max:50'],
            'max_kap'        => ['nullable', 'integer', 'min:0'],
            'komp_tanki'     => ['required', 'string'],
            'link_gps'       => ['required', 'string', 'max:150'],
            'user_gps'       => ['required', 'string', 'max:100'],
            'pass_gps'       => ['required', 'string', 'max:100'],
            'membercode_gps' => ['required', 'string', 'max:50'],
            'is_active'      => ['nullable', 'integer', 'in:0,1'],
            'photo'          => ['nullable', 'file', 'mimes:jpg,jpeg,png,webp', 'max:4096'],
        ]);

        $data['max_kap']   = $data['max_kap'] ?? 0;
        $data['is_active'] = $data['is_active'] ?? 1;

        $row = new TransportirMobil();
        $row->fill($data);

        $row->created_time = now();
        $row->created_ip   = $request->ip();
        $row->created_by   = (string)(Auth::id() ?? 'system');

        if ($request->hasFile('photo')) {
            $file = $request->file('photo');
            $row->photo_ori = $file->getClientOriginalName();
            $row->photo     = $file->store('transportir/mobil', 'public');
        }

        $row->save();

        return response()->json($row->load('transportir:id,nama_transportir'), 201);
    }

    // GET /api/master/transportir-mobil/{id}
    public function show($id)
    {
        $row = TransportirMobil::with(['transportir:id,nama_transportir'])
            ->findOrFail((int) $id);

        return response()->json($row);
    }

    // POST /api/master/transportir-mobil/{id}
    public function update(Request $request, $id)
    {
        $row = TransportirMobil::findOrFail((int) $id);

        $data = $request->validate([
            'id_transportir' => ['required', 'integer', 'exists:transportir,id'],
            'nomor_plat'     => ['required', 'string', 'max:20'],
            'no_proyek'      => ['nullable', 'string', 'max:50'],
            'max_kap'        => ['nullable', 'integer', 'min:0'],
            'komp_tanki'     => ['required', 'string'],
            'link_gps'       => ['required', 'string', 'max:150'],
            'user_gps'       => ['required', 'string', 'max:100'],
            'pass_gps'       => ['required', 'string', 'max:100'],
            'membercode_gps' => ['required', 'string', 'max:50'],
            'is_active'      => ['nullable', 'integer', 'in:0,1'],
            'photo'          => ['nullable', 'file', 'mimes:jpg,jpeg,png,webp', 'max:4096'],
        ]);

        // default kalau tidak dikirim
        if (!array_key_exists('max_kap', $data)) $data['max_kap'] = $row->max_kap ?? 0;
        if (!array_key_exists('is_active', $data)) $data['is_active'] = $row->is_active ?? 1;

        $row->fill($data);

        $row->lastupdate_time = now();
        $row->lastupdate_ip   = $request->ip();
        $row->lastupdate_by   = (string)(Auth::id() ?? 'system');

        if ($request->hasFile('photo')) {
            if ($row->photo && Storage::disk('public')->exists($row->photo)) {
                Storage::disk('public')->delete($row->photo);
            }

            $file = $request->file('photo');
            $row->photo_ori = $file->getClientOriginalName();
            $row->photo     = $file->store('transportir/mobil', 'public');
        }

        $row->save();

        return response()->json($row->load('transportir:id,nama_transportir'));
    }

    // DELETE /api/master/transportir-mobil/{id}
    public function destroy($id)
    {
        $row = TransportirMobil::findOrFail((int) $id);

        if ($row->photo && Storage::disk('public')->exists($row->photo)) {
            Storage::disk('public')->delete($row->photo);
        }

        $row->delete();

        return response()->json(['message' => 'Deleted']);
    }
}