<?php

namespace App\Http\Controllers\Api\Master;

use App\Helpers\ApiResponse;
use App\Http\Controllers\Controller;
use App\Models\MasterDokumenPendukung;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class MasterDokumenPendukungController extends Controller
{
    public function index()
    {
        try {
            $data = DB::table('master_dokumen_pendukung')
                ->select('id', 'nama_dokumen', 'deskripsi')
                ->where('is_active', 1)
                ->orderBy('id', 'asc')
                ->get();

            return ApiResponse::success(
                $data,
                'Data dokumen pendukung berhasil dimuat.'
            );
        } catch (\Throwable $e) {
            Log::error('Gagal memuat dokumen pendukung', [
                'message' => $e->getMessage(),
                'file'    => $e->getFile(),
                'line'    => $e->getLine(),
            ]);

            return ApiResponse::error(
                'Gagal memuat data dokumen pendukung.',
                500
            );
        }
    }
}
