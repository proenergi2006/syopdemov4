<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\InventoryGainLoss;
use App\Services\Inventory\GainLossService;
use Illuminate\Http\Request;

class GainLossInventoryController extends Controller
{
    protected $gainLossService;

    public function __construct(GainLossService $gainLossService)
    {
        $this->gainLossService = $gainLossService;
    }
    /**
     * Display a listing of the resource.
     *
     * @return \Illuminate\Http\Response
     */
      public function index(Request $request)
    {
        $query = InventoryGainLoss::with('po');

        if ($request->filled('keyword')) {
            $query->whereHas('po', function ($q) use ($request) {
                $q->where('nomor_po', 'ILIKE', '%' . $request->keyword . '%');
            });
        }
        if ($request->filled('status')) {
            $query->where('disposisi_gain_loss', $request->status);
        }

        $data = $query
        ->paginate($request->per_page ?? 25);
            // ->orderByDesc('tgl_terima')

        return response()->json($data);
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
            'id_po' => 'required',
            'jenis' => 'required|in:1,2',
            'volume' => 'required|numeric',
            'ket' => 'nullable|string',
            'file' => 'nullable|file|max:3072', // 3MB
        ]);

        $user = [
            'name' => auth()->user()->name ?? 'system'
        ];

        $result = $this->gainLossService->createGainLoss(
            $request->all(),
            $user
        );

        return response()->json($result);
    }
    /**
     * Display the specified resource.
     *
     * @param  int  $id
     * @return \Illuminate\Http\Response
     */
    public function show($id)
    {
         return InventoryGainLoss::with([
            'po.produk',
            'po.terminal',
            'po.vendor',
        ])->findOrFail($id);
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
        //
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
    public function approval(Request $request)
    {
        $request->validate([
            'id_master' => 'required',
            'revert' => 'required|in:1,2',
            'catatan' => 'nullable|string',
        ]);

        $this->gainLossService->approvalCEO(
            $request->id_master,
            $request->revert,
            $request->catatan
        );

        return response()->json([
            'success' => true,
            'message' => 'Approval berhasil diproses'
        ]);
    }
}
