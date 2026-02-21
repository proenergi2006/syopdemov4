<?php

namespace App\Http\Controllers\Api\Master;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use App\Models\Volume;

class VolumeController extends Controller
{
    /**
     * Display a listing of the resource.
     *
     * @return \Illuminate\Http\Response
     */
    public function index(Request $request)
    {
        $q = Volume::query();

        if ($request->filled('search')) {
            $s = (string) $request->input('search');
            $q->where(function ($qq) use ($s) {
                $qq->where('volume_angkut', 'like', "%{$s}%");
            });
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
            'volume_angkut' => ['required', 'numeric'],
            'is_active' => ['nullable', 'boolean'],
        ]);
        $data['created_time'] = now();
        $data['created_ip'] = $request->ip();
        $data['created_by'] = optional($request->user())->email ?? 'system';

        $row = Volume::create($data);

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
     public function update(Request $request, Volume $volume)
    {
         $data = $request->validate([
            'volume_angkut' => ['required', 'numeric'],
            'is_active' => ['nullable', 'boolean'],
        ]);

        $data['lastupdate_time'] = now();
        $data['lastupdate_by'] = optional($request->user())->email ?? 'system';

        $volume->update($data);

        return response()->json($volume);
    }

    /**
     * Remove the specified resource from storage.
     *
     * @param  int  $id
     * @return \Illuminate\Http\Response
     */
    public function destroy(Volume $volume)
    {
        $volume->delete();

        return response()->json(['message' => 'Deleted']);
    }
}
