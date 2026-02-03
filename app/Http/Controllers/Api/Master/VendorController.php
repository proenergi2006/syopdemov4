<?php

namespace App\Http\Controllers\Api\Master;

use App\Http\Controllers\Controller;
use App\Models\MasterVendor;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class VendorController extends Controller
{
    public function index(Request $request)
    {
        $q = MasterVendor::query();

        if ($request->filled('search')) {
            $s = $request->search;
            $q->where(function ($qq) use ($s) {
                $qq->where('kode_vendor', 'ilike', "%{$s}%")
                   ->orWhere('inisial_vendor', 'ilike', "%{$s}%")
                   ->orWhere('nama_vendor', 'ilike', "%{$s}%");
            });
        }

        if ($request->filled('is_active')) {
            $q->where('is_active', filter_var($request->is_active, FILTER_VALIDATE_BOOLEAN));
        }

        return response()->json(
            $q->orderBy('nama_vendor')
              ->paginate($request->per_page ?? 10)
        );
    }

    public function store(Request $request)
    {
        $data = $request->validate([
            'kode_vendor'    => ['required', 'string', 'max:50', 'unique:master_vendor,kode_vendor'],
            'id_accurate'    => ['nullable', 'string', 'max:50'],
            'inisial_vendor' => ['required', 'string', 'max:20'],
            'nama_vendor'    => ['required', 'string', 'max:150'],
            'is_active'      => ['boolean'],
        ]);

        $data['created_time'] = now();
        $data['created_ip']   = $request->ip();
        $data['created_by']   = $request->user()->id ?? null;

        return response()->json(
            MasterVendor::create($data),
            201
        );
    }

    public function update(Request $request, MasterVendor $vendor)
    {
        $data = $request->validate([
            'kode_vendor'    => ['required', 'string', 'max:50', Rule::unique('master_vendor', 'kode_vendor')->ignore($vendor->id)],
            'id_accurate'    => ['nullable', 'string', 'max:50'],
            'inisial_vendor' => ['required', 'string', 'max:20'],
            'nama_vendor'    => ['required', 'string', 'max:150'],
            'is_active'      => ['boolean'],
        ]);

        $data['lastupdate_time'] = now();

        $vendor->update($data);

        return response()->json($vendor);
    }

    public function destroy(MasterVendor $vendor)
    {
        $vendor->delete();

        return response()->json(['message' => 'Vendor deleted']);
    }
}
