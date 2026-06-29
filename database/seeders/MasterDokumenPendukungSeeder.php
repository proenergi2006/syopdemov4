<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class MasterDokumenPendukungSeeder extends Seeder
{
    public function run(): void
    {
        $documents = [
            [
                'nama_dokumen' => 'Surat Referensi Bank / Surat Pernyataan No. Rekening dari Direktur',
                'slug' => 'surat-referensi-bank',
                'deskripsi' => '(wajib)',
                'is_required' => true,
            ],
            [
                'nama_dokumen' => 'Surat Pernyataan Pembayaran PPN Bermeterai',
                'slug' => 'surat-pernyataan-ppn',
                'deskripsi' => '(wajib jika PKP, format terlampir)',
                'is_required' => false,
            ],
            [
                'nama_dokumen' => 'SIUPAL',
                'slug' => 'siupal',
                'deskripsi' => '(Jika Perusahaan Pelayaran)',
                'is_required' => false,
            ],
            [
                'nama_dokumen' => 'SIUJK / SBUJK',
                'slug' => 'siujk-sbujk',
                'deskripsi' => '(Jika Perusahaan Konstruksi)',
                'is_required' => false,
            ],
            [
                'nama_dokumen' => 'Fotokopi E-KTP',
                'slug' => 'e-ktp',
                'deskripsi' => '(Jika Orang Pribadi / Perorangan)',
                'is_required' => false,
            ],
            [
                'nama_dokumen' => 'Lampiran Surat Pernyataan Non PKP Bermeterai',
                'slug' => 'non-pkp',
                'deskripsi' => '(Jika Non PKP)',
                'is_required' => false,
            ],
            [
                'nama_dokumen' => 'Fotokopi SPPKP',
                'slug' => 'sppkp',
                'deskripsi' => null,
                'is_required' => false,
            ],
            [
                'nama_dokumen' => 'Lampiran Surat Pernyataan Non NPWP Bermeterai',
                'slug' => 'non-npwp',
                'deskripsi' => '(Jika tidak melampirkan NPWP)',
                'is_required' => false,
            ],
            [
                'nama_dokumen' => 'Fotokopi NPWP',
                'slug' => 'npwp',
                'deskripsi' => null,
                'is_required' => false,
            ],
            [
                'nama_dokumen' => 'Dokumen Legalitas',
                'slug' => 'dokumen-legalitas',
                'deskripsi' => '(Akta Pendirian, Akta Terbaru, SIUP, NIB, TDP, Dokumen Pendukung Lainnya)',
                'is_required' => false,
            ],
        ];

        foreach ($documents as $document) {
            DB::table('master_dokumen_pendukung')->updateOrInsert(
                [
                    'slug' => $document['slug'],
                ],
                $document
            );
        }
    }
}
