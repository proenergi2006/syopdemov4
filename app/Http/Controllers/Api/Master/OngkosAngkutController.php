<?php

namespace App\Http\Controllers\Api\Master;

use App\Http\Controllers\Controller;
use App\Models\OngkosAngkut;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class OngkosAngkutController extends Controller
{
    // GET /api/master/ongkos-angkut
    public function index(Request $request)
    {
        $q = OngkosAngkut::query()
            ->with([
                'transportir:id,nama_transportir',
                // ✅ FIX: kolom di tabel wilayah_angkut adalah "wilayah_angkut"
                'wilayahAngkut:id,wilayah_angkut',

                // yang lain dibuat aman (tanpa select kolom nama spesifik)
                'provinsi',
                'kabupaten',
                'volume',
            ]);

        // search
        if ($request->filled('search')) {
            $s = trim((string) $request->input('search'));

            $q->where(function ($w) use ($s) {
                // cari di nama transportir
                $w->whereHas('transportir', function ($x) use ($s) {
                    $x->where('nama_transportir', 'ilike', "%{$s}%");
                })
                // cari di wilayah_angkut.wilayah_angkut
                ->orWhereHas('wilayahAngkut', function ($x) use ($s) {
                    $x->where('wilayah_angkut', 'ilike', "%{$s}%");
                })
                // cari angka ongkos juga (optional)
                ->orWhere('ongkos_angkut', '::text ilike', "%{$s}%");
            });
        }

        // filters
        if ($request->filled('id_transportir')) {
            $q->where('id_transportir', (int) $request->input('id_transportir'));
        }
        if ($request->filled('id_wil_angkut')) {
            $q->where('id_wil_angkut', (int) $request->input('id_wil_angkut'));
        }
        if ($request->filled('id_prov_angkut')) {
            $q->where('id_prov_angkut', (int) $request->input('id_prov_angkut'));
        }
        if ($request->filled('id_kab_angkut')) {
            $q->where('id_kab_angkut', (int) $request->input('id_kab_angkut'));
        }
        if ($request->filled('id_vol_angkut')) {
            $q->where('id_vol_angkut', (int) $request->input('id_vol_angkut'));
        }

        $perPage = (int) $request->input('per_page', 10);
        if ($perPage <= 0) $perPage = 10;
        if ($perPage > 100) $perPage = 100;

        return response()->json(
            $q->orderByDesc('id')->paginate($perPage)
        );
    }

    // POST /api/master/ongkos-angkut
    public function store(Request $request)
    {
        $data = $request->validate([
            'id_transportir' => ['required', 'integer', 'exists:transportir,id'],
            'id_wil_angkut'  => ['required', 'integer', 'exists:wilayah_angkut,id'],
            'id_prov_angkut' => ['required', 'integer', 'exists:provinsi,id'],
            'id_kab_angkut'  => ['required', 'integer', 'exists:kabupaten,id'],
            'id_vol_angkut'  => ['required', 'integer', 'exists:volume,id'],
            'ongkos_angkut'  => ['required', 'integer', 'min:0'],
        ]);

        // ✅ cegah duplicate combo (sesuai unique constraint)
        $dup = OngkosAngkut::where('id_transportir', $data['id_transportir'])
            ->where('id_wil_angkut', $data['id_wil_angkut'])
            ->where('id_vol_angkut', $data['id_vol_angkut'])
            ->exists();

        if ($dup) {
            return response()->json([
                'message' => 'Data sudah ada untuk kombinasi Transportir + Wilayah + Volume.',
                'errors' => [
                    'unique' => ['Duplicate: id_transportir + id_wil_angkut + id_vol_angkut'],
                ],
            ], 422);
        }

        $row = new OngkosAngkut();
        $row->fill($data);

        $row->created_time = now();
        $row->created_ip   = $request->ip();
        $row->created_by   = (string) (Auth::id() ?? 'system');

        $row->save();

        return response()->json(
            $row->load([
                'transportir:id,nama_transportir',
                'wilayahAngkut:id,wilayah_angkut',
                'provinsi',
                'kabupaten',
                'volume',
            ]),
            201
        );
    }

    // GET /api/master/ongkos-angkut/{id}
    public function show($id)
    {
        $row = OngkosAngkut::with([
            'transportir:id,nama_transportir',
            'wilayahAngkut:id,wilayah_angkut',
            'provinsi',
            'kabupaten',
            'volume',
        ])->findOrFail((int) $id);

        return response()->json($row);
    }

    // POST /api/master/ongkos-angkut/{id}  (mengikuti pola kamu seperti mobil)
    public function update(Request $request, $id)
    {
        $row = OngkosAngkut::findOrFail((int) $id);

        $data = $request->validate([
            'id_transportir' => ['required', 'integer', 'exists:transportir,id'],
            'id_wil_angkut'  => ['required', 'integer', 'exists:wilayah_angkut,id'],
            'id_prov_angkut' => ['required', 'integer', 'exists:provinsi,id'],
            'id_kab_angkut'  => ['required', 'integer', 'exists:kabupaten,id'],
            'id_vol_angkut'  => ['required', 'integer', 'exists:volume,id'],
            'ongkos_angkut'  => ['required', 'integer', 'min:0'],
        ]);

        // ✅ cek duplicate untuk row lain
        $dup = OngkosAngkut::where('id_transportir', $data['id_transportir'])
            ->where('id_wil_angkut', $data['id_wil_angkut'])
            ->where('id_vol_angkut', $data['id_vol_angkut'])
            ->where('id', '!=', $row->id)
            ->exists();

        if ($dup) {
            return response()->json([
                'message' => 'Data sudah ada untuk kombinasi Transportir + Wilayah + Volume.',
                'errors' => [
                    'unique' => ['Duplicate: id_transportir + id_wil_angkut + id_vol_angkut'],
                ],
            ], 422);
        }

        $row->fill($data);

        $row->lastupdate_time = now();
        $row->lastupdate_ip   = $request->ip();
        $row->lastupdate_by   = (string) (Auth::id() ?? 'system');

        $row->save();

        return response()->json(
            $row->load([
                'transportir:id,nama_transportir',
                'wilayahAngkut:id,wilayah_angkut',
                'provinsi',
                'kabupaten',
                'volume',
            ])
        );
    }

    // DELETE /api/master/ongkos-angkut/{id}
    public function destroy($id)
    {
        $row = OngkosAngkut::findOrFail((int) $id);
        $row->delete();

        return response()->json(['message' => 'Deleted']);
    }
}