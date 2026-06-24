<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\OngkosAngkutKapal;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class OngkosAngkutKapalController extends Controller
{
    /**
     * Display a listing of the resource.
     *
     * @return \Illuminate\Http\Response
     */
    public function index(Request $request)
    {
        $query = OngkosAngkutKapal::with('transportir')
            ->when($request->asal_angkut, function ($q) use ($request) {
                $q->where('asal_angkut', 'ILIKE', '%' . $request->asal_angkut . '%');
            })

            ->when($request->tujuan_angkut, function ($q) use ($request) {
                $q->where('tujuan_angkut', 'ILIKE', '%' . $request->tujuan_angkut . '%');
            })

            ->when($request->transportir, function ($q) use ($request) {
                $q->where('id_transportir', $request->transportir);
            });

        $data = $query
            ->orderBy('created_time', 'desc')
            ->paginate($request->per_page ?? 10);

        return response()->json([
            'data' => $data->items(),
            'current_page' => $data->currentPage(),
            'last_page' => $data->lastPage(),
            'per_page' => $data->perPage(),
            'total' => $data->total(),
        ]);
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
        $request->validate([
            'items' => 'required|array|min:1',
        ]);

        DB::transaction(function () use ($request) {

            $rows = [];

            foreach ($request->items as $item) {

                $rows[] = [
                    'id_transportir' => $item['id_transportir'],
                    'nama_kapal' => $item['nama_kapal'],
                    'tipe_kapal' => $item['tipe_kapal'],
                    'max_kapal' => $item['max_kapal'],
                    'asal_angkut' => $item['asal_angkut'],
                    'tujuan_angkut' => $item['tujuan_angkut'],
                    'volume_angkut' => $item['volume_angkut'],
                    'harga_angkut' => $item['harga_angkut'],

                    'created_time' => now(),
                    'created_ip' => request()->ip(),
                    'created_by' => auth()->user()->name ?? 'system',
                ];
            }

            OngkosAngkutKapal::insert($rows);
        });

        return response()->json([
            'success' => true,
            'message' => 'Data berhasil disimpan',
        ]);
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
    public function update(Request $request, $id)
    {
        $data = OngkosAngkutKapal::findOrFail($id);

        $data->update([
            'id_transportir' => $request->transportir,
            'nama_kapal' => $request->nama_kapal,
            'tipe_kapal' => $request->tipe_kapal,
            'max_kapal' => $request->max_kapal,
            'asal_angkut' => $request->asal_angkut,
            'tujuan_angkut' => $request->tujuan_angkut,
            'volume_angkut' => $request->volume_angkut,
            'harga_angkut' => $request->harga_angkut,
        ]);

        return response()->json([
            'message' => 'Data berhasil diupdate'
        ]);
    }

    /**
     * Remove the specified resource from storage.
     *
     * @param  int  $id
     * @return \Illuminate\Http\Response
     */
   public function destroy($id)
    {
        try {
            $data = OngkosAngkutKapal::findOrFail($id);
            $data->delete();

            return true;
        } catch (\Exception $e) {
            throw new \Exception('Gagal menghapus data');
        }
    }
    public function oaKapal(Request $request)
    {
        $query = OngkosAngkutKapal::query();

        if ($request->filled('transportir_id')) {
            $query->where('id_transportir', $request->transportir_id);
        }

        return response()->json(
            $query->get([
                'id',
                'id_transportir',
                'nama_kapal',
                'tipe_kapal',
                'asal_angkut',
                'tujuan_angkut',
            ])
        );
    }
}
