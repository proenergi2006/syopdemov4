<?php

namespace App\Http\Controllers\Api\Master;

use App\Http\Controllers\Controller;
use App\Models\Area;
use App\Models\HargaJual;
use App\Models\HargaPertamina;
use App\Models\Produk;
use Illuminate\Http\Request;

class HargaJualController extends Controller
{
   public function area() {
        return response()->json(Area::all(['id', 'nama']));
    }

    public function getproduk() {
        return response()->json(Produk::all(['id', 'merk_dagang','jenis_produk']));
    }
    public function pbbkb() {
        return response()->json(Produk::all(['id', 'nilai_pbbkb']));
    }

    /**
     * Display a listing of the resource.
     *
     * @return \Illuminate\Http\Response
     */
    public function index(Request $request)
    {
        $q = HargaJual::with(['area', 'getproduk','pbbkb']);

        if ($request->filled('id_area')) {
            $q->where('id_area', $request->input('id_area'));
        }
        if ($request->filled('produk')) {
            $q->where('produk', $request->input('produk'));
        }

        if ($request->filled('search')) {
            $s = (string) $request->input('search');
            // $q->whereRaw('LOWER(wilayah_angkut) LIKE ?', ['%' . strtolower($s) . '%']);
            $q->where(function ($qq) use ($s) {
                $qq->where('harga_normal', 'like', "%{$s}%");
            });
        }

        $perPage = (int) $request->input('per_page', 15);

        return response()->json(
            $q->orderBy('periode_awal')->paginate($perPage)
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
        'periode_awal'   => ['required', 'date'],
        'periode_akhir'  => ['required', 'date'],
        'list'           => ['required', 'array', 'min:1'],

        'list.*.id_area'      => ['required', 'integer', 'exists:area,id'],
        'list.*.produk'    => ['required', 'integer', 'exists:produk,id'],

        // angka
        'list.*.harga_normal'  => ['required', 'numeric'],
        'list.*.harga_sm'     => ['required', 'numeric'],
        'list.*.harga_om'     => ['required', 'numeric'],
        'list.*.harga_coo'    => ['required', 'numeric'],
        'list.*.harga_ceo'    => ['required', 'numeric'],
        'list.*.note_jual'    => ['required', 'string'],
    ]);

    $inserted = [];

    foreach ($data['list'] as $row) {

        $payload = [
            'periode_awal'     => $data['periode_awal'],
            'periode_akhir'    => $data['periode_akhir'],
            'id_area'          => $row['id_area'],

            // mapping sesuai database
            'produk'           => $row['produk'],   // kolom “produk”
            'pajak'            => 1,
            'harga_normal'     => $row['harga_normal'],
            'harga_sm'         => $row['harga_sm'],    // jika bukan “loco”, tinggal ganti
            'harga_om'         => $row['harga_om'],
            'harga_coo'        => $row['harga_coo'],
            'harga_ceo'        => $row['harga_ceo'],
            'note_jual'        => $detail['note_jual'] ?? '',

            // required by your model
            'created_time'     => now(),
            'created_ip'       => $request->ip(),
            'created_by'       => optional($request->user())->email ?? 'system',
        ];

        $inserted[] = HargaJual::create($payload);
    }

    return response()->json([
        'message' => 'Berhasil disimpan',
        'data' => $inserted
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
    public function update(Request $request, HargaJual $hargaJual)
    {
         $data = $request->validate([
            'periode_awal' => ['required', 'date'],
            'periode_akhir' => ['required', 'date'],
            'id_area'      => ['required', 'integer', 'exists:area,id'],
            'produk'    => ['required', 'integer', 'exists:produk,id'],
            'harga_normal'  => ['required', 'numeric'],
            'harga_sm'     => ['required', 'numeric'],
            'harga_om'     => ['required', 'numeric'],
            'harga_coo'    => ['required', 'numeric'],
            'harga_ceo'    => ['required', 'numeric'],
            'note_jual'    => ['required', 'string'],
        ]);

        $data['lastupdate_time'] = now();
        $data['lastupdate_by'] = optional($request->user())->email ?? 'system';

        $hargaJual->update($data);

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
     public function destroy(HargaJual $hargaJual)
    {
        $hargaJual->delete();

        return response()->json(['message' => 'Deleted']);
    }
}
