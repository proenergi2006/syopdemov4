<?php

namespace App\Http\Controllers\Api\Master;

use App\Http\Controllers\Controller;
use App\Models\Transportir;
use App\Models\TransportirSopir;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Storage;

class TransportirSopirController extends Controller
{
    // GET /api/master/sopir?search=&is_active=&id_transportir=
    public function index(Request $request)
    {
        $q = TransportirSopir::query()->with(['transportir:id,nama_transportir']);

        if ($request->filled('id_transportir')) {
            $q->where('id_transportir', (int) $request->input('id_transportir'));
        }

        if ($request->filled('search')) {
            $s = (string) $request->input('search');
            $q->where('nama_sopir', 'ilike', "%{$s}%");
        }

        if ($request->filled('is_active')) {
            $q->where('is_active', (int) $request->input('is_active'));
        }

        return response()->json(
            $q->orderBy('nama_sopir')->paginate((int) $request->input('per_page', 10))
        );
    }

    // POST /api/master/sopir (multipart/form-data)
    public function store(Request $request)
    {
        $data = $request->validate([
            'id_transportir' => ['required','integer','exists:transportir,id'],
            'nama_sopir'     => ['required','string','max:70'],
            'is_active'      => ['nullable','integer'],
            'photo'          => ['nullable','file','mimes:jpg,jpeg,png,webp','max:4096'],
        ]);

        // pastikan transportir valid
        Transportir::findOrFail((int) $data['id_transportir']);

        $row = new TransportirSopir();
        $row->id_transportir = (int) $data['id_transportir'];
        $row->nama_sopir     = $data['nama_sopir'];
        $row->is_active      = isset($data['is_active']) ? (int) $data['is_active'] : 1;

        $row->created_time = now();
        $row->created_ip   = $request->ip();
        $row->created_by   = (string) (Auth::id() ?? 'system');

        if ($request->hasFile('photo')) {
            $file = $request->file('photo');
            $row->photo_ori = $file->getClientOriginalName();
            $row->photo = $file->store('transportir/sopir', 'public');
        }

        $row->save();

        return response()->json($row->load('transportir:id,nama_transportir'), 201);
    }

    // GET /api/master/sopir/{id}
    public function show($id)
    {
        $row = TransportirSopir::with(['transportir:id,nama_transportir'])->findOrFail((int) $id);
        return response()->json($row);
    }

    // PUT/PATCH /api/master/sopir/{id} (kalau mau multipart, boleh POST juga tapi route harus manual)
    public function update(Request $request, $id)
    {
        $row = TransportirSopir::findOrFail((int) $id);

        $data = $request->validate([
            'id_transportir' => ['required','integer','exists:transportir,id'],
            'nama_sopir'     => ['required','string','max:70'],
            'is_active'      => ['nullable','integer'],
            'photo'          => ['nullable','file','mimes:jpg,jpeg,png,webp','max:4096'],
        ]);

        $row->id_transportir = (int) $data['id_transportir'];
        $row->nama_sopir     = $data['nama_sopir'];
        $row->is_active      = isset($data['is_active']) ? (int) $data['is_active'] : $row->is_active;

        $row->lastupdate_time = now();
        $row->lastupdate_ip   = $request->ip();
        $row->lastupdate_by   = (string) (Auth::id() ?? 'system');

        if ($request->hasFile('photo')) {
            if ($row->photo && Storage::disk('public')->exists($row->photo)) {
                Storage::disk('public')->delete($row->photo);
            }

            $file = $request->file('photo');
            $row->photo_ori = $file->getClientOriginalName();
            $row->photo = $file->store('transportir/sopir', 'public');
        }

        $row->save();

        return response()->json($row->load('transportir:id,nama_transportir'));
    }

    // DELETE /api/master/sopir/{id}
    public function destroy($id)
    {
        $row = TransportirSopir::findOrFail((int) $id);

        if ($row->photo && Storage::disk('public')->exists($row->photo)) {
            Storage::disk('public')->delete($row->photo);
        }

        $row->delete();

        return response()->json(['message' => 'Deleted']);
    }
}
