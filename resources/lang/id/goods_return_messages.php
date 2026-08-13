<?php

return [
    'user_not_authenticated' => 'User tidak terautentikasi.',
    'invalid_id' => 'ID Goods Return tidak valid.',
    'not_found' => 'Goods Return tidak ditemukan.',
    'not_found_or_no_access' => 'Goods Return tidak ditemukan atau tidak dapat Anda akses.',
    'source_not_found' => 'Goods Return atau data sumber tidak ditemukan.',
    'create_forbidden' => 'Anda tidak memiliki akses untuk membuat retur barang.',
    'edit_forbidden' => 'Anda tidak memiliki akses untuk mengubah Goods Return.',
    'only_draft' => 'Goods Return hanya dapat diubah jika status masih DRAFT.',
    'index_forbidden' => 'Anda tidak memiliki akses untuk melihat Goods Return.',

    'reasons' => [
        'forbidden' => 'Anda tidak memiliki akses ke master alasan retur.',
        'loaded' => 'Data alasan retur berhasil diambil.',
        'load_failed' => 'Gagal mengambil data alasan retur.',
    ],

    'create' => [
        'department_not_configured' => 'Department pengguna belum dikonfigurasi.',
        'gr_not_found_or_fully_returned' => 'Goods Receipt tidak ditemukan atau seluruh qty barang sudah diretur.',
    ],

    'form' => [
        'loaded' => 'Data form retur barang berhasil diambil.',
        'invalid_id' => 'ID Goods Receipt tidak valid.',
        'load_failed' => 'Gagal mengambil data form retur barang.',
    ],

    'draft' => [
        'created' => 'Draft retur barang berhasil dibuat.',
        'invalid_ids' => 'ID Goods Receipt atau item tidak valid.',
        'not_found' => 'Goods Receipt atau item sumber tidak ditemukan.',
        'create_failed' => 'Gagal membuat draft retur barang.',
    ],

    'show' => [
        'loaded' => 'Detail Goods Return berhasil dimuat.',
        'load_failed' => 'Gagal memuat detail Goods Return.',
    ],

    'edit' => [
        'loaded' => 'Data edit Goods Return berhasil dimuat.',
        'load_failed' => 'Gagal memuat data edit Goods Return.',
    ],

    'update' => [
        'success' => 'Draft Goods Return berhasil diperbarui.',
        'invalid_ids' => 'ID Goods Return atau item tidak valid.',
        'failed' => 'Gagal memperbarui Goods Return.',
    ],

    'destroy' => [
        'forbidden' => 'Anda tidak memiliki akses untuk menghapus Goods Return.',
        'only_draft' => 'Goods Return hanya dapat dihapus jika status masih DRAFT.',
        'success' => 'Draft Goods Return berhasil dihapus.',
        'failed' => 'Gagal menghapus Goods Return.',
    ],

    'post' => [
        'forbidden' => 'Anda tidak memiliki akses untuk memposting retur barang.',
        'success' => 'Goods Return berhasil diposting.',
        'failed' => 'Gagal memposting Goods Return.',
    ],

    'cancel' => [
        'forbidden' => 'Anda tidak memiliki akses untuk membatalkan retur barang.',
        'success' => 'Goods Return berhasil dibatalkan.',
        'failed' => 'Gagal membatalkan Goods Return.',
    ],

    'replacement' => [
        'forbidden' => 'Anda tidak memiliki akses untuk membuat Goods Receipt.',
        'department_unavailable' => 'Department akun Anda belum tersedia.',
        'none_needed' => 'Tidak ada Goods Return yang membutuhkan replacement.',
    ],

    'validation' => [
        'gr_must_be_posted' => 'Retur hanya dapat dibuat dari Goods Receipt yang sudah POSTED.',
        'gr_data_incomplete' => 'Data Purchase Order, cabang, atau department pada Goods Receipt belum lengkap.',
        'po_item_mismatch' => 'Item Purchase Order tidak sesuai dengan item Goods Receipt.',
        'reason_not_found' => 'Alasan retur tidak ditemukan atau sudah tidak aktif.',
        'qty_receive_invalid' => 'Qty penerimaan item tidak valid.',
        'qty_return_exceeds_returnable' => 'Qty retur item :item_name melebihi qty yang masih dapat diretur. Maksimal: :max_qty.',
        'unit_item_not_found' => 'Unit item :item_name tidak ditemukan pada Goods Receipt maupun Purchase Order.',
        'gr_source_must_be_posted' => 'Goods Receipt sumber harus berstatus POSTED.',
        'item_reference_mismatch' => 'Referensi item retur tidak sesuai.',
        'qty_return_positive' => 'Qty retur harus lebih besar dari nol.',
    ],
];
