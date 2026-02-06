<?php

namespace App\Http\Controllers\Api\Master;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use App\Models\Produk;
use Illuminate\Validation\Rule;

class ProdukController extends Controller
{
    /**
     * Display a listing of the resource.
     *
     * @return \Illuminate\Http\Response
     */
    public function index(Request $request)
    {
        $q = Produk::query();

        if ($request->filled('search')) {
            $s = (string) $request->input('search');
            $q->where(function ($qq) use ($s) {
                $qq->where('jenis_produk', 'like', "%{$s}%")
                   ->orWhere('merk_dagang', 'like', "%{$s}%");
            });
        }

        if ($request->filled('is_active')) {
            $q->where('is_active', filter_var($request->input('is_active'), FILTER_VALIDATE_BOOLEAN));
        }

        $perPage = (int) $request->input('per_page', 15);

        return response()->json(
            $q->orderBy('merk_dagang')->paginate($perPage)
        );
    }

    /**
     * Show the form for creating a new resource.
     *
     * @return \Illuminate\Http\Response
     */
    public function create()
    {
        
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
        'jenis_produk' => ['required', 'string', 'max:20'],
        'merk_dagang' => ['required', 'string', 'max:150'],
        'catatan_produk' => ['required', 'string'],
        'is_active' => ['nullable', 'boolean'],
        ]);

        $data['created_time'] = now();
        $data['created_ip'] = $request->ip();
        $data['created_by'] = optional($request->user())->email ?? 'system';

        $row = Produk::create($data);
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
    public function update(Request $request, Produk $produk)
    {
        $data = $request->validate([
        'jenis_produk' => ['required', 'string', 'max:20'],
        'merk_dagang' => ['required', 'string', 'max:150'],
        'catatan_produk' => ['required', 'string'],
        'is_active' => ['nullable', 'boolean'],
        ]);

        $data['lastupdate_time'] = now();
        $data['lastupdate_by'] = optional($request->user())->email ?? 'system';

        $produk->update($data);

        return response()->json($produk);
    }

    /**
     * Remove the specified resource from storage.
     *
     * @param  int  $id
     * @return \Illuminate\Http\Response
     */
    public function destroy(Produk $produk)
    {
        $produk->delete();

        return response()->json(['message' => 'Deleted']);
    }
}
