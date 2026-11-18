<?php

/*
|--------------------------------------------------------------------------
| Pesan API Realisasi FPU
|--------------------------------------------------------------------------
*/

return [
    'user_not_authenticated' => 'Sesi Anda telah berakhir. Silakan login kembali.',

    'index' => [
        'loaded' => 'Data Realisasi FPU berhasil dimuat.',
        'load_failed' => 'Data Realisasi FPU gagal dimuat.',
    ],

    'show' => [
        'loaded' => 'Detail Realisasi FPU berhasil dimuat.',
        'not_found' => 'Realisasi FPU tidak ditemukan atau Anda tidak memiliki akses.',
        'failed' => 'Detail Realisasi FPU gagal dimuat.',
    ],

    'realizable' => [
        'loaded' => 'Daftar FPU yang siap direalisasi berhasil dimuat.',
        'failed' => 'Daftar FPU yang siap direalisasi gagal dimuat.',
    ],

    'source' => [
        'not_found' => 'FPU yang dipilih tidak ditemukan.',
        'not_disbursed' => 'Realisasi hanya dapat dibuat dari FPU yang sudah dibayarkan.',
        'already_realized' => 'FPU ini sudah memiliki dokumen realisasi.',
    ],

    'store' => [
        'forbidden' => 'Anda tidak memiliki akses untuk membuat Realisasi FPU.',
        'success' => 'Realisasi FPU berhasil disimpan sebagai draft.',
        'invalid' => 'Data Realisasi FPU tidak valid.',
        'failed' => 'Realisasi FPU gagal disimpan.',
    ],

    'update' => [
        'forbidden' => 'Anda tidak memiliki akses untuk mengubah Realisasi FPU.',
        'only_draft' => 'Hanya Realisasi FPU berstatus draft yang dapat diubah.',
        'success' => 'Realisasi FPU berhasil diperbarui.',
        'failed' => 'Realisasi FPU gagal diperbarui.',
    ],

    'destroy' => [
        'forbidden' => 'Anda tidak memiliki akses untuk menghapus Realisasi FPU.',
        'only_draft' => 'Hanya Realisasi FPU berstatus draft yang dapat dihapus.',
        'success' => 'Realisasi FPU berhasil dihapus.',
        'failed' => 'Realisasi FPU gagal dihapus.',
    ],

    'submit' => [
        'forbidden' => 'Anda tidak memiliki akses untuk mengajukan Realisasi FPU.',
        'only_draft' => 'Hanya Realisasi FPU berstatus draft yang dapat diajukan.',
        'items_unavailable' => 'Realisasi FPU belum memiliki rincian pengeluaran.',
        'signature_missing' => 'Anda belum memiliki tanda tangan digital.',
        'success' => 'Realisasi FPU berhasil diajukan.',
        'failed' => 'Realisasi FPU gagal diajukan.',
    ],

    'approve' => [
        'not_in_progress' => 'Realisasi FPU tidak sedang dalam proses approval.',
        'final_success' => 'Realisasi FPU berhasil disetujui secara final.',
        'step_success' => 'Tahap approval Realisasi FPU berhasil disetujui.',
        'waiting_others' => 'Approval Anda tersimpan dan masih menunggu approver lain pada tahap yang sama.',
        'failed' => 'Approval Realisasi FPU tidak dapat diproses.',
    ],

    'reject' => [
        'not_in_progress' => 'Realisasi FPU tidak sedang dalam proses approval.',
        'success' => 'Realisasi FPU berhasil ditolak.',
        'failed' => 'Penolakan Realisasi FPU tidak dapat diproses.',
    ],

    'cancel' => [
        'forbidden' => 'Anda tidak memiliki akses untuk membatalkan Realisasi FPU.',
        'only_approved' => 'Hanya Realisasi FPU berstatus approved yang dapat dibatalkan.',
        'success' => 'Realisasi FPU berhasil dibatalkan.',
        'failed' => 'Realisasi FPU gagal dibatalkan.',
    ],

    /*
    |--------------------------------------------------------------------------
    | Penyelesaian selisih
    |--------------------------------------------------------------------------
    | return    : sisa dana dikembalikan pemohon ke Finance, bukti wajib.
    | reimburse : kekurangan dibayarkan Finance kepada pemohon, bukti opsional.
    |--------------------------------------------------------------------------
    */
    /*
    | Penerimaan mendahului penyelesaian selisih: berkasnya diterima dulu,
    | baru uangnya berpindah.
    */
    /*
    | Meneruskan ke pembayaran kekurangan.
    |
    | Hanya ada pada cabang kelebihan: dua cabang lain tidak berpindah meja,
    | jadi tidak ada yang perlu diteruskan ke siapa pun.
    */
    'verify' => [
        'forbidden' => 'Anda tidak punya akses untuk meneruskan Realisasi FPU ke pembayaran.',
        'only_received' => 'Hanya Realisasi FPU yang berkasnya sudah diterima yang bisa diteruskan ke pembayaran.',
        'only_reimburse' => 'Hanya Realisasi FPU yang kekurangannya perlu dibayarkan yang perlu diteruskan. Yang nilainya pas sudah selesai saat diterima, dan yang lebih ditutup saat uang sisanya kembali.',
        'success' => 'Realisasi FPU diteruskan ke pembayaran kekurangan.',
        'failed' => 'Realisasi FPU gagal diteruskan ke pembayaran.',
    ],

    'receive' => [
        'forbidden' => 'Anda tidak memiliki akses untuk menerima Realisasi FPU.',
        'only_approved' => 'Hanya Realisasi FPU berstatus approved yang dapat ditandai diterima.',
        'success' => 'Realisasi FPU berhasil ditandai sudah diterima.',
        'failed' => 'Penerimaan Realisasi FPU gagal diproses.',
    ],

    /*
    | Pemohon menyatakan sisa dananya sudah dikembalikan.
    |
    | Uangnya berpindah di luar sistem; yang dikerjakan di sini penandaannya.
    | Pengakuan ini TIDAK menutup dokumen -- lihat confirm_return di bawah.
    */
    'return' => [
        'forbidden' => 'Anda tidak memiliki akses untuk menandai pengembalian sisa dana.',
        'only_received' => 'Sisa dana baru bisa dikembalikan setelah berkas Realisasi FPU diterima Finance.',
        'only_approved' => 'Hanya Realisasi FPU berstatus approved yang dapat diselesaikan.',
        'only_requester' => 'Hanya pemohon Realisasi FPU ini yang dapat menandai pengembalian sisa dananya,'
            . ' karena dialah yang memegang uangnya.',
        'wrong_difference' => 'Realisasi FPU ini tidak menyisakan dana, jadi tidak ada yang perlu dikembalikan.',
        'attachment_required' => 'Bukti transfer wajib dilampirkan minimal satu berkas.',
        'amount_mismatch' => 'Nominal pengembalian harus tepat Rp :expected, sedangkan yang diisi Rp :given. Pengembalian sebagian tidak dapat dicatat.',
        'success' => 'Pengembalian sisa dana sudah ditandai dan menunggu konfirmasi Finance.',
        'failed' => 'Penandaan pengembalian sisa dana gagal diproses.',
    ],

    /* Finance mengonfirmasi uangnya benar-benar masuk -- di sinilah tutupnya. */
    'confirm_return' => [
        'forbidden' => 'Anda tidak memiliki akses untuk mengonfirmasi pengembalian sisa dana.',
        'only_declared' => 'Hanya Realisasi FPU yang pengembaliannya sudah dinyatakan pemohon yang dapat dikonfirmasi.',
        'success' => 'Pengembalian sisa dana dikonfirmasi dan Realisasi FPU ditutup.',
        'failed' => 'Konfirmasi pengembalian sisa dana gagal diproses.',
    ],

    /*
    | Menolak pernyataan pengembalian. Izinnya terpisah dari konfirmasi,
    | sejalan dengan receive dan unreceive: yang satu menutup dokumen, yang
    | lain melemparkannya kembali ke pemohon beserta alasan yang akan ia baca.
    */
    'reject_return' => [
        'forbidden' => 'Anda tidak memiliki akses untuk menolak pernyataan pengembalian sisa dana.',
        'only_declared' => 'Hanya Realisasi FPU yang pengembaliannya sudah dinyatakan pemohon yang dapat ditolak.',
        'success' => 'Pernyataan pengembalian ditolak dan dokumennya dikembalikan ke pemohon.',
        'failed' => 'Penolakan pernyataan pengembalian gagal diproses.',
        'rejected_note' => 'Pernyataan pengembalian ditolak Finance: :reason',
    ],

    /*
    | Cabang ketiga: realisasi yang nilainya PAS. Tidak ada uang yang
    | berpindah, jadi yang tersisa hanya memeriksa angkanya lalu menutupnya.
    */
    'finalize' => [
        'forbidden' => 'Anda tidak memiliki akses untuk menyetujui Realisasi FPU tanpa selisih.',
        'only_on_checking' => 'Hanya Realisasi FPU yang sedang diperiksa yang dapat disetujui di sini.',
        'only_no_difference' => 'Realisasi FPU ini punya selisih, jadi penutupannya lewat pembayaran kekurangan atau pengembalian sisa dana, bukan lewat approve ini.',
        'success' => 'Realisasi FPU disetujui dan prosesnya selesai.',
        'failed' => 'Approve Realisasi FPU gagal diproses.',
    ],

    'reimburse' => [
        'forbidden' => 'Anda tidak memiliki akses untuk mencatat pembayaran kekurangan.',
        'only_received' => 'Hanya Realisasi FPU yang sudah diterima yang dapat diselesaikan selisihnya.',
        'only_approved' => 'Hanya Realisasi FPU berstatus approved yang dapat diselesaikan.',
        'wrong_difference' => 'Realisasi FPU ini tidak memiliki kekurangan yang perlu dibayarkan.',
        'attachment_required' => 'Bukti pembayaran wajib dilampirkan minimal satu berkas.',
        'amount_mismatch' => 'Nominal pembayaran harus tepat Rp :expected, sedangkan yang diisi Rp :given. Pembayaran sebagian tidak dapat dicatat.',
        'success' => 'Pembayaran kekurangan berhasil dicatat.',
        'failed' => 'Pencatatan pembayaran kekurangan gagal diproses.',
    ],

    /*
    |--------------------------------------------------------------------------
    | Export Excel
    |--------------------------------------------------------------------------
    */
    'export' => [
        'forbidden' => 'Anda tidak memiliki akses untuk export data Realisasi FPU.',
        'failed' => 'Export data Realisasi FPU gagal diproses.',
        'filename' => 'Realisasi_FPU',
        'sheet_title' => 'Realisasi FPU',
        'no_expense_category' => '(tanpa kategori)',
        'arranged_by_ga' => 'Diurus GA (tidak dikeluarkan)',
        'no_item' => '(tanpa rincian)',

        'difference_none' => 'Tidak ada selisih',
        'difference_return' => 'Dikembalikan',
        'difference_reimburse' => 'Kekurangan dibayarkan',

        'columns' => [
            'no' => 'No',
            'realization_number' => 'Nomor Realisasi',
            'date' => 'Tanggal',
            'advance_number' => 'Nomor FPU',
            'branch' => 'Cabang',
            'department' => 'Department',
            'transaction_category' => 'Keterangan Transaksi',
            'subject' => 'Perihal',
            'item_description' => 'Deskripsi Rincian',
            'expense_category' => 'Kategori Biaya',
            'qty' => 'Qty',
            'unit_price' => 'Rincian Biaya',
            'item_advance_amount' => 'Nominal Pengajuan',
            'item_realization_amount' => 'Nominal Realisasi',
            'item_difference' => 'Selisih Rincian',
            'total_advance_amount' => 'Total Pengajuan',
            'total_realization_amount' => 'Total Realisasi',
            'difference_amount' => 'Total Selisih',
            'difference_type' => 'Jenis Selisih',
            'status' => 'Status',
        ],
    ],

    'print' => [
        'not_found' => 'Realisasi FPU tidak ditemukan.',
        'not_printable' => 'Realisasi FPU hanya dapat dicetak setelah disetujui.',
        'url_failed' => 'URL cetak Realisasi FPU gagal dibuat.',
        'failed' => 'Cetakan Realisasi FPU gagal dibuat.',
    ],

    'items' => [
        'required' => 'Rincian pengeluaran wajib diisi.',
        'description_required' => 'Deskripsi pada baris :row wajib diisi.',
        'date_required' => 'Tanggal pada baris :row wajib diisi.',
        'amount_invalid' => 'Nominal realisasi pada baris :row tidak boleh kurang dari 0.',
        'attachment_required' => 'Bukti pada baris :row wajib diisi minimal satu berkas.',
        'unknown_source' => 'Baris :row mengacu pada rincian FPU yang tidak dikenali.',
        'duplicate_source' => 'Baris :row mengacu pada rincian FPU yang sudah dipakai baris lain.',
        'cancelled_reason_required' => 'Baris :row bernilai nol. Tuliskan alasannya -- pengeluaran yang tidak jadi tetap perlu keterangan.',
    ],

    /*
    | Rincian berkategori pada dokumen perjalanan dinas.
    |
    | Nominal tiap baris dihitung dari Qty x Rincian Biaya, bukan diterima
    | dari layar -- nominal yang bisa datang dari dua tempat cepat atau
    | lambat akan datang berbeda.
    */
    'breakdown' => [
        'category_required' => 'Baris :row: kategori biayanya belum dipilih. Rincian perjalanan dinas dikelompokkan per kategori.',
        'qty_required' => 'Baris :row: Qty wajib diisi dan harus lebih dari nol.',
        'unit_price_required' => 'Baris :row: Rincian Biaya wajib diisi dan harus lebih dari nol.',
        'cancelled_reason_required' => 'Baris :row bernilai nol. Tuliskan alasannya -- pengeluaran yang tidak jadi tetap perlu keterangan.',
        'arranged_has_items' => 'Kategori :category ditandai diurus GA, jadi tidak boleh punya baris rincian. Hapus barisnya, atau matikan penandanya.',
        'arranged_not_allowed' => 'Kategori :category tidak diurus GA, jadi penandanya tidak bisa dipasang. Rinciannya diisi pemohon sendiri. Pengaturannya ada di master Kategori Biaya Perjalanan Dinas.',
    ],
];
