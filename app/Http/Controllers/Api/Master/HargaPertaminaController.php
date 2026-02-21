<?php

namespace App\Http\Controllers\Api\Master;

use App\Http\Controllers\Controller;
use App\Models\Area;
use App\Models\HargaPertamina;
use App\Models\Produk;
use Illuminate\Http\Request;

class HargaPertaminaController extends Controller
{
    public function area() {
        return response()->json(Area::all(['id', 'nama_area']));
    }

    public function produk() {
        return response()->json(Produk::all(['id', 'merk_dagang','jenis_produk']));
    }

    /**
     * Display a listing of the resource.
     *
     * @return \Illuminate\Http\Response
     */
    public function index(Request $request)
    {
        $q = HargaPertamina::with(['produk', 'area']);

        if ($request->filled('id_area')) {
            $q->where('id_area', $request->input('id_area'));
        }
        if ($request->filled('id_produk')) {
            $q->where('id_produk', $request->input('id_produk'));
        }

        if ($request->filled('search')) {
            $s = (string) $request->input('search');
            // $q->whereRaw('LOWER(wilayah_angkut) LIKE ?', ['%' . strtolower($s) . '%']);
            $q->where(function ($qq) use ($s) {
                $qq->where('harga_minyak', 'like', "%{$s}%");
            });
        }

        $perPage = (int) $request->input('per_page', 15);

        return response()->json(
            $q->orderBy('id')->paginate($perPage)
        );
    }

    /**
     * Show the form for creating a new resource.
     *
     * @return \Illuminate\Http\Response
     */
    public function create()
    {
        //
    }

    /**
     * Store a newly created resource in storage.
     *
     * @param  \Illuminate\Http\Request  $request
     * @return \Illuminate\Http\Response
     */
   public function store(Request $request)
    {
        $data = $request->validate([
            'periode_awal' => ['required', 'date'],
            'periode_akhir' => ['required', 'date'],
            'details' => ['required', 'array', 'min:1'],
            'details.*.id_area' => ['required', 'integer', 'exists:area,id'],
            'details.*.id_produk' => ['required', 'integer', 'exists:produk,id'],
            'details.*.harga_minyak' => ['required', 'numeric'],
        ]);

        $insertedRows = [];

        foreach ($data['details'] as $detail) {
            $rowData = [
                'periode_awal'   => $data['periode_awal'],
                'periode_akhir'  => $data['periode_akhir'],
                'id_area'        => $detail['id_area'],
                'id_produk'      => $detail['id_produk'],
                'harga_minyak'   => $detail['harga_minyak'],
                'created_time'   => now(),
                'created_ip'     => $request->ip(),
                'created_by'     => optional($request->user())->email ?? 'system',
            ];

            $insertedRows[] = HargaPertamina::create($rowData);
        }

        return response()->json([
            'message' => 'Data berhasil disimpan',
            'data' => $insertedRows
        ], 201);
    }
    /**
     * Display the specified resource.
     *
     * @param  int  $id
     * @return \Illuminate\Http\Response
     */
    public function show($id)
    {
        //
    }

    /**
     * Show the form for editing the specified resource.
     *
     * @param  int  $id
     * @return \Illuminate\Http\Response
     */
    public function edit($id)
    {
        //
    }

    /**
     * Update the specified resource in storage.
     *
     * @param  \Illuminate\Http\Request  $request
     * @param  int  $id
     * @return \Illuminate\Http\Response
     */
    public function update(Request $request, HargaPertamina $hargaPertamina)
    {
         $data = $request->validate([
            'periode_awal' => ['required', 'date'],
            'periode_akhir' => ['required', 'date'],
            'id_area' => ['required', 'integer', 'exists:area,id'],
            'id_produk' => ['required', 'integer', 'exists:produk,id'],
            'harga_minyak' => ['required', 'numeric'],
        ]);

        $data['lastupdate_time'] = now();
        $data['lastupdate_by'] = optional($request->user())->email ?? 'system';

        $hargaPertamina->update($data);

        return response()->json([
            'message' => 'Data berhasil diubah',
        ], 201);
    }

    /**
     * Remove the specified resource from storage.
     *
     * @param  int  $id
     * @return \Illuminate\Http\Response
     */
     public function destroy(HargaPertamina $hargaPertamina)
    {
        $hargaPertamina->delete();

        return response()->json(['message' => 'Deleted']);
    }
}
