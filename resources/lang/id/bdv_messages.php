<?php

return [
    'forbidden_view' => 'Anda tidak memiliki akses melihat Bank Disbursement Voucher.',

    'forbidden_create' => 'Anda tidak memiliki akses membuat Bank Disbursement Voucher.',

    'not_found' => 'Bank Disbursement Voucher tersebut tidak ditemukan.',

    'unknown_document_type' => 'Jenis dokumen tersebut tidak terdaftar sebagai sumber BDV.',

    'document_not_found' => 'Dokumen yang dipilih tidak ditemukan.',

    /*
    | Sengaja satu kalimat untuk tiga keadaan sekaligus -- belum diterima,
    | sudah dicairkan, atau sudah diangkat BDV lain. Membedakannya hanya
    | membocorkan keberadaan dokumen yang memang tidak boleh dilihat.
    */
    'document_not_available' => 'Dokumen itu tidak bisa diangkat menjadi BDV. Mungkin berkasnya belum diterima, sudah dicairkan, atau sudah dipakai pada BDV lain.',

    /*
    |--------------------------------------------------------------------------
    | Menyimpan
    |--------------------------------------------------------------------------
    */
    'forbidden_update' => 'Anda tidak memiliki akses mengubah Bank Disbursement Voucher.',

    'not_draft' => 'Hanya BDV berstatus draft yang bisa diubah.',

    /*
    | Dipakai pintu Ubah, yang sejak ada pengembalian untuk revisi menerima
    | dua status, bukan satu. not_draft di atas dibiarkan berdiri: pintu lain
    | masih memakainya, dan pesan yang menyebut dua status pada pintu yang
    | hanya menerima satu justru menyesatkan.
    */
    'not_editable' => 'Hanya BDV berstatus draft atau butuh revisi yang bisa diubah.',

    'number_taken' => 'Nomor BDV tersebut sudah dipakai dokumen lain.',

    'duplicate_source' => 'Dokumen itu sudah diangkat menjadi BDV lain.',

    'item_line_unknown' => 'Baris rincian nomor :line tidak ada pada dokumen sumbernya.',

    'sources_required' => 'Pilih minimal satu dokumen yang akan dicairkan.',

    'duplicate_document_in_voucher' => 'Ada dokumen yang dipilih dua kali pada voucher ini.',

    /*
    | Nomor dokumennya disebutkan beserta cabangnya masing-masing. Pesan yang
    | hanya berkata "cabangnya berbeda" meninggalkan orangnya membuka satu
    | per satu untuk tahu yang mana.
    */
    /*
    | FPU dan Claim tidak boleh bercampur dalam satu voucher: keduanya
    | dokumen yang berbeda sifatnya, dan cetakannya tidak bisa menyebut
    | dirinya voucher apa bila keduanya ada di dalamnya.
    */
    'mixed_document_type' => 'Satu BDV hanya boleh mencairkan satu jenis dokumen. Yang dipilih bercampur :types.',

    'mixed_department' => 'Seluruh dokumen dalam satu BDV harus berasal dari department yang sama. Yang dipilih: :documents.',

    'mixed_branch' => 'Seluruh dokumen dalam satu BDV harus berasal dari cabang yang sama. Yang dipilih: :documents.',

    'item_line_unknown_on' => 'Baris rincian nomor :line tidak ada pada dokumen :document.',

    'items_required' => 'Rincian BDV tidak boleh kosong.',

    'created' => 'Bank Disbursement Voucher berhasil disimpan.',

    'updated' => 'Perubahan Bank Disbursement Voucher berhasil disimpan.',

    'save_failed' => 'Bank Disbursement Voucher gagal disimpan.',

    /*
    |--------------------------------------------------------------------------
    | Menghapus
    |--------------------------------------------------------------------------
    */
    'forbidden_delete' => 'Anda tidak memiliki akses menghapus Bank Disbursement Voucher.',

    'delete_draft_only' => 'Hanya BDV berstatus draft yang bisa dihapus.',

    /*
    | Penghapusan di Accurate yang gagal membatalkan penghapusan di sini juga.
    | Disebutkan terang-terangan supaya yang membacanya tahu dokumennya masih
    | utuh di kedua tempat, bukan setengah terhapus.
    */
    'delete_accurate_failed' => 'Dokumen ini gagal dihapus di Accurate, jadi penghapusannya dibatalkan. BDV-nya masih utuh. Alasan dari Accurate: :reason',

    /*
    | Kebalikannya, dan jauh lebih jarang: Accurate sudah menerima
    | penghapusannya tetapi baris di sini gagal dibuang.
    */
    'delete_local_failed' => 'Dokumennya sudah terhapus di Accurate, tetapi BDV-nya gagal dihapus di sini. Jangan menekan Simpan pada voucher ini -- itu akan menerbitkan dokumen baru di Accurate. Coba hapus sekali lagi, dan laporkan bila tetap gagal.',

    'deleted' => 'Bank Disbursement Voucher berhasil dihapus.',

    /*
    |--------------------------------------------------------------------------
    | Pengiriman ke Accurate
    |--------------------------------------------------------------------------
    */
    'accurate_no_cash_bank' => 'Akun kas/bank belum dipilih, jadi tidak ada sumber dana yang bisa dicatat di Accurate.',

    'accurate_no_payee' => 'Penerima pembayaran belum diisi. Accurate mewajibkannya pada setiap Other Payment.',

    'accurate_no_items' => 'BDV tanpa rincian tidak bisa dikirim ke Accurate.',

    'accurate_item_no_account' => 'Akun Accurate pada baris :lines belum dipilih.',

    'accurate_rejected' => 'Accurate menolak dokumen ini: :reason',

    'accurate_failed' => 'Pengiriman ke Accurate gagal, dan Accurate tidak menyebutkan alasannya.',

    'accurate_no_id' => 'Accurate menjawab berhasil tetapi tidak mengirimkan id dokumennya. Periksa langsung di Accurate sebelum mencoba ulang.',

    'accurate_sent_not_recorded' => 'Dokumen sudah masuk Accurate dengan id :id, tetapi gagal dicatat di sini. Jangan menyimpan ulang sebelum diperiksa -- hubungi IT dengan menyebutkan id tersebut.',

    /*
    | Dipakai saat BDV-nya sendiri sudah tersimpan tetapi pengirimannya ke
    | Accurate gagal. Dua kabar sekaligus, dan keduanya perlu: yang sudah
    | diisi tidak hilang, tetapi pembukuannya belum menerima apa pun.
    */
    'saved_accurate_failed' => 'BDV tersimpan sebagai draft, tetapi belum masuk Accurate. :reason',

    /*
    |--------------------------------------------------------------------------
    | Mengirim bukti ke Accurate
    |--------------------------------------------------------------------------
    | Satu kalimat untuk tiap sebab, bukan satu kalimat umum untuk semuanya.
    | Yang membaca kabar ini harus bisa langsung tahu apa yang perlu ia
    | lakukan: mengecilkan berkasnya, mengubah jenisnya, atau menekan Simpan
    | sekali lagi karena sebabnya ada di luar kendalinya.
    |--------------------------------------------------------------------------
    */
    'attachment_file_missing' => 'Berkasnya tidak ditemukan di penyimpanan. Unggah ulang bukti itu di dokumen sumbernya, lalu simpan BDV ini sekali lagi.',

    'attachment_unreadable' => 'Berkasnya ada tetapi tidak bisa dibaca. Unggah ulang bukti itu di dokumen sumbernya.',

    'attachment_bad_type' => 'Jenis berkas :type tidak diterima Accurate. Yang diterima hanya :allowed.',

    'attachment_too_big' => 'Ukurannya :size, melampaui batas Accurate :max per berkas.',

    'attachment_too_many' => 'Dokumen di Accurate sudah mencapai batas maksimumnya, :max bukti.',

    'attachment_no_answer' => 'Accurate tidak memberi jawaban yang bisa dibaca. Coba simpan sekali lagi.',

    'attachment_rejected_unknown' => 'Accurate menolaknya tanpa menyebutkan alasan.',



    'attachment_delete_failed' => 'gagal dihapus di Accurate -- :reason. Simpan sekali lagi untuk mencoba lagi',



    'attachment_no_remote_id' => 'tidak bisa dihapus dari sini karena id lampirannya tidak pernah tercatat -- harus dicabut manual di Accurate',

    /*
    | Dipakai saat vouchernya SUDAH masuk Accurate dan yang gagal hanya
    | sebagian buktinya. Dua kabar sekaligus, dan keduanya perlu -- menyebut
    | salah satunya saja membuat orangnya mengira harus mengulang semuanya,
    | atau sebaliknya mengira semuanya sudah beres.
    */
    'attachment_partial' => ':failed dari :total bukti belum masuk Accurate. :detail Simpan sekali lagi untuk mencoba ulang yang gagal.',

    'attachment_leftover' => 'Bukti lama yang masih menempel di Accurate padahal sudah tidak dipakai voucher ini (:count). :detail',



    'attachment_detail' => ':name (:reason)',

    'attachment_and_more' => 'dan :count bukti lain.',

    /*
    |--------------------------------------------------------------------------
    | Mengajukan dan approval
    |--------------------------------------------------------------------------
    */
    'forbidden_submit' => 'Anda tidak memiliki akses mengajukan Bank Disbursement Voucher.',

    'forbidden_approve' => 'Anda tidak memiliki akses menyetujui Bank Disbursement Voucher.',

    'submit_draft_only' => 'Hanya BDV berstatus draft atau perlu revisi yang bisa diajukan.',

    'submitted' => 'Bank Disbursement Voucher berhasil diajukan.',

    /*
    | Belum masuk Accurate berarti angka di sini belum tentu sama dengan angka
    | di pembukuan. Mengajukannya sekarang berarti meminta orang menyetujui
    | sesuatu yang belum tercatat di mana pun.
    */
    /*
    | Dokumennya ADA di Accurate, tetapi isinya di sana bukan isi yang
    | sekarang. Disebutkan terang-terangan supaya tidak dikira sama dengan
    | pesan di atas -- jalan keluarnya kebetulan sama, sebabnya tidak.
    */
    'submit_needs_resync' => 'Perubahan terakhir BDV ini belum masuk Accurate, jadi angka di sini berbeda dari pembukuan. Buka halaman ubahnya lalu tekan Simpan untuk mengirimnya ulang sebelum diajukan.',

    'submit_needs_accurate' => 'BDV ini belum masuk Accurate, jadi belum bisa diajukan. Buka halaman ubahnya lalu tekan Simpan untuk mengirimnya ulang.',

    'approved' => 'Persetujuan Anda tersimpan.',

    'approved_final' => 'Bank Disbursement Voucher disetujui sepenuhnya.',

    'rejected' => 'Bank Disbursement Voucher ditolak.',

    'approval_not_in_progress' => 'BDV ini sedang tidak dalam proses approval.',

    'approval_not_yours' => 'Anda bukan approver aktif untuk BDV ini.',

    'approval_no_signature' => 'Anda belum memiliki tanda tangan digital, jadi belum bisa menyetujui.',

    'approval_reject_needs_notes' => 'Alasan penolakan wajib diisi.',

    'approval_already_built' => 'Rantai approval BDV ini sudah pernah dibuat.',

    'approval_amount_zero' => 'Nilai BDV harus lebih besar dari 0 untuk bisa diajukan.',

    'approval_no_department' => 'Department BDV tidak tersedia untuk mencari approval flow.',

    'approval_no_branch' => 'Cabang BDV belum ditentukan.',

    /*
    | Kriterianya dirangkai di BdvApprovalGeneratorService, dari sakelar
    | module: hanya yang benar-benar dipakai yang ikut disebut.
    */
    'approval_flow_missing' => 'Approval flow BDV tidak ditemukan untuk :criteria. Lengkapi matriks approval BDV untuk kombinasi tersebut.',

    /*
    | Module BDV disetel global: menyuruh admin melengkapi "matriks" di sini
    | justru menyesatkan, sebab matriksnya memang tidak dipakai.
    */
    'approval_flow_missing_global' => 'Belum ada approval flow BDV yang aktif untuk nominal Rp :amount. Module BDV disetel berlaku global -- tanpa kondisi matriks dan tanpa keterangan transaksi -- jadi satu alur BDV yang aktif dan mencakup nominal tersebut sudah cukup. Buat atau aktifkan alurnya di menu Approval Flow.',

    'approval_criteria_area' => 'area :value',
    'approval_criteria_department' => 'department :value',
    'approval_criteria_category' => 'keterangan transaksi :value',
    'approval_criteria_amount' => 'nominal Rp :value',
    'approval_criteria_join' => 'dan',

    'approval_flow_no_steps' => 'Approval flow BDV ditemukan, tetapi belum memiliki approver.',

    'approval_no_approver_left' => 'Tidak ada approver yang tersisa pada approval flow BDV ini untuk Anda. Hubungi admin untuk memeriksa matriks approval-nya.',

    'approval_step_empty' => 'Approver untuk step ":step" pada cabang BDV ini belum ditemukan.',

    'approval_step_no_approver' => 'Approver pada step ":step" belum dikonfigurasi.',

    'approval_step_no_branches' => 'Cabang yang ditangani approver pada step ":step" belum dikonfigurasi.',

    'approval_step_scope_must_global' => 'Step ":step" memakai department atau aturan rekan setingkat, sehingga scope-nya harus Global.',

    'approval_scope_unsupported' => 'Approver scope ":scope" pada step ":step" tidak didukung.',

    'approval_type_unsupported' => 'Tipe approver ":type" pada step ":step" tidak didukung.',

    'approval_skipped_any' => 'Dilewati karena approval mode ANY sudah dipenuhi oleh :name.',

    'approval_cancelled_by_reject' => 'Dibatalkan karena BDV ditolak.',
];
