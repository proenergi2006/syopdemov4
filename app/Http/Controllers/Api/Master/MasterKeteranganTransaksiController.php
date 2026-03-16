<?php

namespace App\Http\Controllers\Api\Master;

use App\Http\Controllers\Controller;
use App\Helpers\ApiResponse;
use App\Models\MasterKeteranganTransaksi;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class MasterKeteranganTransaksiController extends Controller
{
    public function index()
    {
        try {

            $data = DB::table('master_keterangan_transaksi')
                ->select('id', 'kategori', 'pasal_pajak')
                ->where('is_active', 1)
                ->orderBy('id')
                ->get();

            return ApiResponse::success($data, 'Data berhasil dimuat');
        } catch (\Throwable $e) {

            Log::error('Error master_keterangan_transaksi', [
                'message' => $e->getMessage(),
                'line' => $e->getLine(),
            ]);

            return ApiResponse::error('Gagal memuat data keterangan transaksi');
        }
    }
}
