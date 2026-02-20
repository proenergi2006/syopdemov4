<?php

namespace App\Http\Controllers\Api\Master;

use App\Http\Controllers\Controller;
use App\Models\Transportir;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class TransportirController extends Controller
{
    public function index(Request $request)
    {
        $q = Transportir::query();

        if ($request->filled('search')) {
            $s = (string) $request->input('search');
            $q->where(function ($qq) use ($s) {
                $qq->where('nama_transportir', 'ilike', "%{$s}%")
                   ->orWhere('nama_suplier', 'ilike', "%{$s}%")
                   ->orWhere('lokasi_suplier', 'ilike', "%{$s}%")
                   ->orWhere('tipe_angkutan', 'ilike', "%{$s}%");
            });
        }

        if ($request->filled('is_active')) {
            $q->where('is_active', filter_var($request->input('is_active'), FILTER_VALIDATE_BOOLEAN));
        }

        if ($request->filled('is_fleet')) {
            $q->where('is_fleet', (int) $request->input('is_fleet'));
        }

        $perPage = (int) $request->input('per_page', 10);

        return response()->json(
            $q->orderBy('nama_transportir')->paginate($perPage)
        );
    }

    public function store(Request $request)
    {
        $data = $request->validate([
            'nama_transportir' => ['required','string','max:200'],
            'nama_suplier' => ['nullable','string','max:200'],
            'lokasi_suplier' => ['nullable','string','max:200'],
            'alamat_suplier' => ['nullable','string'],
            'att_suplier' => ['nullable','string','max:150'],
            'telp_suplier' => ['nullable','string','max:50'],
            'fax_suplier' => ['nullable','string','max:50'],
            'is_fleet' => ['nullable','integer'],
            'terms_suplier' => ['nullable','string'],
            'catatan' => ['nullable','string'],
            'is_active' => ['nullable','boolean'],
            'tipe_angkutan' => ['nullable','string','max:100'],
            'owner_suplier' => ['nullable','integer'],
        ]);

        $data['created_time'] = now();
        $data['created_ip'] = $request->ip();
        $data['created_by'] = Auth::id();

        $row = Transportir::create($data);

        return response()->json($row, 201);
    }

    public function show(Transportir $transportir)
    {
        return response()->json($transportir);
    }

    public function update(Request $request, Transportir $transportir)
    {
        $data = $request->validate([
            'nama_transportir' => ['required','string','max:200'],
            'nama_suplier' => ['nullable','string','max:200'],
            'lokasi_suplier' => ['nullable','string','max:200'],
            'alamat_suplier' => ['nullable','string'],
            'att_suplier' => ['nullable','string','max:150'],
            'telp_suplier' => ['nullable','string','max:50'],
            'fax_suplier' => ['nullable','string','max:50'],
            'is_fleet' => ['nullable','integer'],
            'terms_suplier' => ['nullable','string'],
            'catatan' => ['nullable','string'],
            'is_active' => ['nullable','boolean'],
            'tipe_angkutan' => ['nullable','string','max:100'],
            'owner_suplier' => ['nullable','integer'],
        ]);

        $data['lastupdate_time'] = now();
        $data['lastupdate_ip'] = $request->ip();
        $data['lastupdate_by'] = Auth::id();

        $transportir->update($data);

        return response()->json($transportir);
    }

    public function destroy(Transportir $transportir)
    {
        $transportir->delete();
        return response()->json(['message' => 'Deleted']);
    }
}
