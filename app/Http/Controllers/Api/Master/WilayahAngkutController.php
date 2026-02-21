<?php

namespace App\Http\Controllers\Api\Master;

use App\Http\Controllers\Controller;
use App\Models\Kabupaten;
use App\Models\Provinsi;
use App\Models\WilayahAngkut;
use Illuminate\Http\Request;

class WilayahAngkutController extends Controller
{
    public function provinsi() {
        return response()->json(Provinsi::all(['id', 'nama']));
    }

    public function kabupaten($provinsi) {
        return response()->json(
            Kabupaten::where('provinsi_id', $provinsi)->get(['id', 'nama'])
        );
    }
    /**
     * Display a listing of the resource.
     *
     * @return \Illuminate\Http\Response
     */
    public function index(Request $request)
    {
        $q = WilayahAngkut::with(['provinsi', 'kabupaten']);

        if ($request->filled('id_prov')) {
            $q->where('id_prov', $request->input('id_prov'));
        }

        // Filter by kabupaten
       if ($request->filled('id_kab')) {
            $kabIds = $request->input('id_kab'); // array dari frontend
            if (is_array($kabIds)) {
                $q->whereIn('id_kab', $kabIds);
            } else {
                $q->where('id_kab', $kabIds);
            }
        }

        if ($request->filled('search')) {
            $s = (string) $request->input('search');
            $q->whereRaw('LOWER(wilayah_angkut) LIKE ?', ['%' . strtolower($s) . '%']);
            // $q->where(function ($qq) use ($s) {
            //     $qq->where('wilayah_angkut', 'like', "%{$s}%");
            // });
        }

        if ($request->filled('is_active')) {
            $q->where('is_active', filter_var($request->input('is_active'), FILTER_VALIDATE_BOOLEAN));
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
            'id_prov' => ['required', 'integer', 'exists:provinsi,id'],
            'id_kab' => ['required', 'integer', 'exists:kabupaten,id'],
            'wilayah_angkut' => ['required', 'string'],
            'is_active'      => ['nullable', 'boolean'],
        ]);
        $data['created_time'] = now();
        $data['created_ip'] = $request->ip();
        $data['created_by'] = optional($request->user())->email ?? 'system';

        $row = WilayahAngkut::create($data);

        return response()->json($row, 201);
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
    public function update(Request $request, WilayahAngkut $wilayahAngkut)
    {
       $data = $request->validate([
            'id_prov' => ['required', 'integer', 'exists:provinsi,id'],
            'id_kab' => ['required', 'integer', 'exists:kabupaten,id'],
            'wilayah_angkut' => ['required', 'string'],
            'is_active'      => ['nullable', 'boolean'],
        ]);

        $data['lastupdate_time'] = now();
        $data['lastupdate_by'] = optional($request->user())->email ?? 'system';

        $wilayahAngkut->update($data);
        return response()->json($wilayahAngkut, 201);
    }

    /**
     * Remove the specified resource from storage.
     *
     * @param  int  $id
     * @return \Illuminate\Http\Response
     */
    public function destroy($id)
    {
        //
    }
}
