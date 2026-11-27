<?php

/*
|--------------------------------------------------------------------------
| Teks cetakan Bank Disbursement Voucher
|--------------------------------------------------------------------------
| Cetakan PERTAMA di aplikasi ini yang dua bahasa. Yang lain -- FPU,
| Realisasi, Claim, Perdin -- hanya Indonesia, karena pembacanya memang
| orang dalam saja. Voucher pencairan bank berbeda: ia ikut dibaca pihak
| bank dan pemeriksa dari luar.
|
| Berkas ini DIPAKAI CETAKANNYA SAJA, bukan oleh layar. Layarnya memakai
| JSON di resources/ts/plugins/i18n. Memisahkannya disengaja: kalimat di
| atas kertas berumur panjang dan dibaca orang yang tidak pernah membuka
| aplikasinya, sedangkan kalimat di layar berubah mengikuti tata letaknya.
|
| Pasangannya di resources/lang/en/bdv_print.php WAJIB punya kunci yang
| sama persis. Kunci yang hanya ada di satu bahasa akan tercetak sebagai
| "bdv_print.sesuatu" di tengah dokumen keuangan.
|--------------------------------------------------------------------------
*/

return [
    'title' => 'Bank Disbursement Voucher',
    'subtitle' => 'Voucher Pencairan Bank',

    'info_title' => 'Informasi Voucher',

    'number' => 'Nomor BDV',
    'date' => 'Tanggal',
    'branch' => 'Cabang',
    'department' => 'Department',
    'category' => 'Keterangan Transaksi',
    'status' => 'Status',
    'requester' => 'Diajukan Oleh',

    'paid_from' => 'Dibayar Dari',
    'payee' => 'Dibayarkan Kepada',
    'check_number' => 'Nomor Cek/Giro',

    'documents' => 'Dokumen yang Dicairkan',

    'detail_title' => 'Rincian Pencairan',

    'col_no' => 'No',
    'col_account_code' => 'Kode Akun',
    'col_account_name' => 'Nama Akun',
    'col_branch' => 'Cabang',
    'col_memo' => 'Memo',
    'col_amount' => 'Nominal',

    'empty_items' => 'Tidak ada rincian pencairan.',
    'total' => 'Total Pencairan',
    'say' => 'Terbilang:',

    'description' => 'Keterangan',

    'prepared_by' => 'Dibuat Oleh',
    'requester_role' => 'Pemohon',
    'approved_by' => 'Disetujui Oleh',
    'approver_role' => 'Penyetuju',
    'no_approval' => 'Belum ada persetujuan.',

    'footer' => 'Dokumen ini dicetak dari sistem SYOP pada :waktu WIB.',

    /*
    | Kenapa dokumennya belum boleh dicetak. Dijawab server, bukan layar --
    | tautan cetak bertanda tangan bisa disimpan orang dan dibuka lagi
    | berhari-hari kemudian, saat statusnya sudah bukan yang tadi.
    */
    'not_printable' => 'Hanya BDV yang sudah disetujui penuh yang bisa dicetak.',
    'not_found' => 'Bank Disbursement Voucher tersebut tidak ditemukan.',
    'failed' => 'Cetakan BDV gagal dibuat.',
];
