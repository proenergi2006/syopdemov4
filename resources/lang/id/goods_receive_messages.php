<?php

return [
    'store' => [
        'forbidden' => 'Anda tidak memiliki akses untuk membuat Goods Receipt.',
        'invalid_ids' => 'ID Purchase Order, Goods Return, atau item tidak valid.',
        'not_found' => 'Purchase Order, Goods Return, atau item tidak ditemukan.',
        'failed' => 'Gagal membuat Goods Receipt.',
    ],

    'update' => [
        'forbidden' => 'Anda tidak memiliki akses untuk mengubah Goods Receipt.',
        'only_draft' => 'Goods Receipt hanya dapat diubah jika status masih DRAFT.',
        'branch_department_incomplete' => 'Cabang atau department pada Purchase Order belum lengkap.',
    ],

    'show' => [
        'loaded' => 'Detail Goods Receipt berhasil dimuat.',
        'load_failed' => 'Gagal memuat detail Goods Receipt.',
    ],

    'post' => [
        'forbidden' => 'Anda tidak memiliki akses untuk posting Goods Receipt.',
        'success' => 'Goods Receipt berhasil diposting.',
        'invalid_id' => 'ID Goods Receipt tidak valid.',
        'not_found' => 'Goods Receipt tidak ditemukan.',
        'failed' => 'Gagal posting Goods Receipt.',
    ],

    'return_history' => [
        'forbidden' => 'Anda tidak memiliki akses untuk melihat history Goods Return.',
        'loaded' => 'History Goods Return berhasil dimuat.',
        'invalid_id' => 'ID Goods Receipt tidak valid.',
        'not_found' => 'Goods Receipt tidak ditemukan.',
        'load_failed' => 'Gagal memuat history Goods Return.',
    ],

    'destroy' => [
        'forbidden' => 'Anda tidak memiliki akses untuk menghapus Goods Receipt.',
        'only_draft' => 'Goods Receipt hanya dapat dihapus jika status masih DRAFT.',
        'failed' => 'Gagal menghapus Goods Receipt.',
    ],

    'cancel' => [
        'forbidden' => 'Anda tidak memiliki akses untuk membatalkan Goods Receipt.',
        'success' => 'Goods Receipt berhasil dibatalkan.',
        'invalid_id' => 'ID Goods Receipt tidak valid.',
        'not_found' => 'Goods Receipt tidak ditemukan.',
        'invalid_status' => 'Goods Receipt hanya dapat dibatalkan jika status sudah POSTED.',
        'failed' => 'Gagal membatalkan Goods Receipt.',
    ],

    'validation' => [
        'source_return_status_posted' => 'Goods Return sumber harus berstatus POSTED.',
        'po_status_approved' => 'Purchase Order harus berstatus APPROVED.',
        'department_not_found' => 'Department akun login tidak ditemukan.',
        'po_not_from_department' => 'Purchase Order tidak berasal dari department Anda.',
        'po_return_mismatch' => 'Purchase Order tidak sesuai dengan Goods Return sumber.',
        'return_not_from_department' => 'Goods Return tidak berasal dari department Anda.',
        'items_not_in_po' => 'Terdapat item yang tidak termasuk dalam Purchase Order.',
        'po_item_not_found' => 'Item Purchase Order tidak ditemukan.',
        'qty_receive_positive' => 'Qty receipt harus lebih besar dari nol.',
        'qty_replacement_exceeds_outstanding' => 'Qty replacement item :item_name melebihi outstanding replacement. Maksimal :max_qty.',
        'qty_receive_exceeds_outstanding' => 'Qty receipt item :item_name melebihi outstanding Purchase Order. Maksimal :max_qty.',
        'multiple_return_candidates' => 'Terdapat lebih dari satu Goods Return yang masih membutuhkan replacement. Goods Return sumber harus dipilih secara spesifik.',
        'item_qty_mismatch_replacement' => 'Item atau qty penerimaan tidak sesuai dengan outstanding replacement Goods Return.',
    ],
];
