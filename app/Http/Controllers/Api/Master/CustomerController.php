<?php

namespace App\Http\Controllers\Api\Master;

use App\Http\Controllers\Controller;
use App\Models\Customer;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class CustomerController extends Controller
{
    // GET /api/master/customers
    public function index(Request $request)
    {
        $q = Customer::query()->with([
            'marketing:id,name,email',
            'provinsi',   // biar aman jika nama kolom berbeda
            'kabupaten',
        ]);

        // search
        if ($request->filled('search')) {
            $s = trim((string) $request->input('search'));
            $q->where(function ($w) use ($s) {
                $w->where('nama_perusahaan', 'ilike', "%{$s}%")
                  ->orWhere('email', 'ilike', "%{$s}%")
                  ->orWhere('telepon', 'ilike', "%{$s}%");
            });
        }

        // filter
        if ($request->filled('marketing_id')) {
            $q->where('marketing_id', (int) $request->input('marketing_id'));
        }
        if ($request->filled('provinsi_id')) {
            $q->where('provinsi_id', (int) $request->input('provinsi_id'));
        }
        if ($request->filled('kabupaten_id')) {
            $q->where('kabupaten_id', (int) $request->input('kabupaten_id'));
        }
        if ($request->filled('jenis_customer')) {
            $q->where('jenis_customer', (string) $request->input('jenis_customer'));
        }
        if ($request->filled('is_active')) {
            $q->where('is_active', filter_var($request->input('is_active'), FILTER_VALIDATE_BOOLEAN));
        }

        $perPage = (int) $request->input('per_page', 10);
        if ($perPage <= 0) $perPage = 10;
        if ($perPage > 100) $perPage = 100;

        return response()->json(
            $q->orderByDesc('id')->paginate($perPage)
        );
    }

    // POST /api/master/customers
    public function store(Request $request)
    {
        $data = $request->validate([
            'marketing_id'      => ['required', 'integer', 'exists:users,id'],
            'nama_perusahaan'   => ['required', 'string', 'max:255'],
            'email'             => ['required', 'email', 'max:255', 'unique:customers,email'],
            'alamat_perusahaan' => ['required', 'string'],
            'provinsi_id'       => ['required', 'integer', 'exists:provinsi,id'],
            'kabupaten_id'      => ['required', 'integer', 'exists:kabupaten,id'],
            'postal_code'       => ['nullable', 'string', 'max:20'],
            'telepon'           => ['required', 'string', 'max:30'],
            'fax'               => ['nullable', 'string', 'max:30'],
            'jenis_customer'    => ['required', 'string', 'max:50'],
            'is_active'         => ['nullable', 'boolean'],
        ]);

        $row = new Customer();
        $row->fill($data);

        $row->is_active    = $data['is_active'] ?? true;
        $row->created_time = now();
        $row->created_ip   = $request->ip();
        $row->created_by   = (string) (Auth::id() ?? 'system');

        $row->save();

        return response()->json(
            $row->load(['marketing:id,name,email', 'provinsi', 'kabupaten']),
            201
        );
    }

    // GET /api/master/customers/{id}
    public function show($id)
    {
        $row = Customer::with(['marketing:id,name,email', 'provinsi', 'kabupaten'])
            ->findOrFail((int) $id);

        return response()->json($row);
    }

    // PUT/PATCH /api/master/customers/{id}
    public function update(Request $request, $id)
    {
        $row = Customer::findOrFail((int) $id);

        $data = $request->validate([
            'marketing_id'      => ['required', 'integer', 'exists:users,id'],
            'nama_perusahaan'   => ['required', 'string', 'max:255'],
            'email'             => ['required', 'email', 'max:255', "unique:customers,email,{$row->id},id"],
            'alamat_perusahaan' => ['required', 'string'],
            'provinsi_id'       => ['required', 'integer', 'exists:provinsi,id'],
            'kabupaten_id'      => ['required', 'integer', 'exists:kabupaten,id'],
            'postal_code'       => ['nullable', 'string', 'max:20'],
            'telepon'           => ['required', 'string', 'max:30'],
            'fax'               => ['nullable', 'string', 'max:30'],
            'jenis_customer'    => ['required', 'string', 'max:50'],
            'is_active'         => ['nullable', 'boolean'],
        ]);

        $row->fill($data);
        if (array_key_exists('is_active', $data)) {
            $row->is_active = (bool) $data['is_active'];
        }

        $row->lastupdate_time = now();
        $row->lastupdate_ip   = $request->ip();
        $row->lastupdate_by   = (string) (Auth::id() ?? 'system');

        $row->save();

        return response()->json(
            $row->load(['marketing:id,name,email', 'provinsi', 'kabupaten'])
        );
    }

    // DELETE /api/master/customers/{id}
    public function destroy($id)
    {
        $row = Customer::findOrFail((int) $id);
        $row->delete();

        return response()->json(['message' => 'Deleted']);
    }
}