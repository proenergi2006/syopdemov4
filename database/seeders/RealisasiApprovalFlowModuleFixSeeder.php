<?php

namespace Database\Seeders;

use App\Models\PermissionModule;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

/*
|--------------------------------------------------------------------------
| Membetulkan module pada approval flow Realisasi FPU
|--------------------------------------------------------------------------
| Flow realisasi diturunkan dari flow FPU oleh approval-flow:mirror-realisasi,
| dan dulu perintah itu ikut menyalin module_name serta permission_module_id
| milik FPU. Akibatnya 58 dari 59 flow REALISASI tercatat bermodul
| "cash_advance", padahal layar master mengharuskan "cash_advance_realization".
|
| Gejalanya tidak terlihat sampai seseorang membuka salah satu flow itu dan
| menekan simpan: form mengirim balik module FPU yang tersimpan, validasi
| menolaknya, dan flow tersebut tidak pernah bisa diubah lewat layar.
|
| Kolom ini tidak dipakai saat mencocokkan approval flow dengan dokumen --
| pencocokan memakai document_type, area, department, keterangan transaksi,
| dan rentang nominal -- jadi membetulkannya tidak mengubah satu pun rantai
| approval yang sedang berjalan.
|
| AMAN DIJALANKAN BERULANG: flow yang modulnya sudah benar tidak disentuh.
|--------------------------------------------------------------------------
*/
class RealisasiApprovalFlowModuleFixSeeder extends Seeder
{
    public function run(): void
    {
        $modul = PermissionModule::query()
            ->where('code', 'cash_advance_realization')
            ->where('is_active', true)
            ->first();

        if (!$modul) {
            $this->command?->error(
                'Module aktif "cash_advance_realization" tidak ada. Seeder dihentikan.',
            );

            return;
        }

        $salah = DB::table('approval_flows')
            ->where('document_type', 'REALISASI')
            ->whereNull('deleted_at')
            ->where(function ($q) use ($modul) {
                $q->whereNull('permission_module_id')
                    ->orWhere('permission_module_id', '!=', $modul->id);
            })
            ->orderBy('id')
            ->pluck('id');

        if ($salah->isEmpty()) {
            $this->command?->info('Semua approval flow Realisasi FPU sudah bermodul benar.');

            return;
        }

        DB::table('approval_flows')
            ->whereIn('id', $salah)
            ->update([
                'permission_module_id' => $modul->id,
                'module_name' => $modul->name,
                'updated_at' => now(),
            ]);

        $this->command?->info(sprintf(
            '%d approval flow Realisasi FPU dibetulkan menjadi module "%s": %s',
            $salah->count(),
            $modul->code,
            $salah->implode(', '),
        ));
    }
}
