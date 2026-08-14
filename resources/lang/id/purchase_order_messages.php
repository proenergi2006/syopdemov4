<?php

return [
    'store' => [
        'forbidden' => 'Anda tidak memiliki akses untuk membuat Purchase Order.',
        'department_forbidden' => 'Anda tidak memiliki akses membuat Purchase Order untuk department tersebut.',
        'success' => 'Purchase Order berhasil disimpan.',
        'failed' => 'Gagal menyimpan Purchase Order.',
    ],

    'show' => [
        'loaded' => 'Detail Purchase Order berhasil dimuat.',
        'load_failed' => 'Gagal memuat detail Purchase Order.',
    ],

    'update' => [
        'forbidden' => 'Anda tidak memiliki akses untuk memperbarui Purchase Order.',
        'invalid_id' => 'ID Purchase Order tidak valid.',
        'department_forbidden' => 'Anda tidak memiliki akses memperbarui Purchase Order untuk department tersebut.',
        'success' => 'Purchase Order berhasil diperbarui.',
        'not_found' => 'Purchase Order tidak ditemukan.',
        'failed' => 'Gagal memperbarui Purchase Order.',
        'only_draft_status' => 'Purchase Order hanya dapat diperbarui jika status masih Draft.',
    ],

    'destroy' => [
        'forbidden' => 'Anda tidak memiliki akses untuk menghapus Purchase Order.',
        'only_draft' => 'Purchase Order hanya dapat dihapus jika status masih Draft.',
        'success' => 'Purchase Order berhasil dihapus.',
        'failed' => 'Gagal menghapus Purchase Order.',
    ],

    'cancel' => [
        'forbidden' => 'Anda tidak memiliki akses untuk membatalkan Purchase Order.',
        'success' => 'Purchase Order berhasil dibatalkan.',
        'invalid_id' => 'ID Purchase Order tidak valid.',
        'not_found' => 'Purchase Order tidak ditemukan.',
        'invalid_status' => 'Purchase Order hanya dapat dibatalkan jika status APPROVED.',
        'has_active_gr' => 'Purchase Order tidak dapat dibatalkan karena sudah ada Goods Receipt.',
        'failed' => 'Gagal membatalkan Purchase Order.',
    ],

    'submit' => [
        'signature_missing' => 'Anda belum memiliki tanda tangan digital. Silakan registrasi tanda tangan terlebih dahulu.',
        'success' => 'Purchase Order berhasil disubmit.',
        'failed' => 'Gagal submit Purchase Order.',
        'only_draft_status' => 'Purchase Order hanya dapat disubmit jika status masih Draft.',
        'items_unavailable' => 'Purchase Order tidak dapat disubmit karena item belum tersedia.',
        'zero_total' => 'Purchase Order tidak dapat disubmit karena total nilai masih 0.',
    ],

    'approve' => [
        'not_current_approver' => 'Anda bukan approver pada tahap approval Purchase Order saat ini.',
        'failed' => 'Gagal approve Purchase Order.',
        'only_in_progress_status' => 'Purchase Order hanya dapat diapprove jika status masih In Progress.',
        'no_pending_approval' => 'Tidak ada approval yang sedang menunggu untuk Purchase Order ini.',
    ],

    'reject' => [
        'not_current_approver' => 'Anda bukan approver pada tahap approval Purchase Order saat ini.',
        'success' => 'Purchase Order berhasil direject.',
        'failed' => 'Gagal reject Purchase Order.',
        'only_in_progress_status' => 'Purchase Order hanya dapat direject jika status masih In Progress.',
        'no_pending_approval' => 'Tidak ada approval yang sedang menunggu untuk Purchase Order ini.',
    ],

    'department_unavailable' => 'Department akun Anda belum tersedia.',

    'index' => [
        'loaded' => 'Purchase Order berhasil dimuat.',
        'load_failed' => 'Gagal memuat Purchase Order.',
    ],

    'receivable' => [
        'invalid_id' => 'ID Purchase Order tidak valid.',
        'not_found_or_unavailable' => 'Purchase Order tidak ditemukan atau tidak tersedia untuk Goods Receipt.',
        'items_loaded' => 'Item Purchase Order berhasil dimuat.',
        'items_load_failed' => 'Gagal memuat item Purchase Order.',
    ],

    'item' => [
        'unit_price_negative' => 'Harga unit tidak boleh kurang dari 0.',
        'unit_price_required' => 'Harga unit wajib diisi.',
        'unit_price_numeric' => 'Harga unit hanya boleh berupa angka.',
        'qty_exceeds_outstanding' => 'Qty PO item :item_name melebihi qty outstanding.',
        'qty_exceeds_max_editable' => 'Qty PO item :item_name maksimal :max_qty.',
        'unit_invalid' => 'Satuan item :item_name tidak valid.',
    ],

    'purchase_request' => [
        'mismatch_with_items' => 'Daftar Purchase Request tidak sesuai dengan item Purchase Order yang dipilih.',
        'not_found' => 'Terdapat Purchase Request yang tidak ditemukan.',
        'not_approved' => 'Purchase Request :nomor_pr belum berstatus APPROVED.',
        'no_longer_processable_create' => 'Purchase Request :nomor_pr sudah tidak dapat diproses menjadi PO.',
        'no_longer_processable_update' => 'Purchase Request :nomor_pr sudah tidak dapat digunakan untuk memperbarui PO.',
        'department_mismatch' => 'Seluruh Purchase Request dalam satu Purchase Order wajib berasal dari department yang sama.',
        'cabang_mismatch' => 'Seluruh Purchase Request dalam satu Purchase Order wajib berasal dari cabang yang sama.',
        'special_type_mismatch' => 'Satu Purchase Order tidak boleh mencampur Purchase Request dengan tipe dokumen yang berbeda.',
        'items_not_found' => 'Terdapat item Purchase Request yang tidak ditemukan atau sudah dihapus.',
        'item_not_found' => 'Item Purchase Request tidak ditemukan.',
        'item_mismatch' => 'Item tidak sesuai dengan Purchase Request yang dipilih.',
        'item_not_in_pr_list' => 'Purchase Request item tidak terdapat dalam daftar Purchase Request PO.',
    ],

    'id_department_mismatch' => 'Department Purchase Order tidak sesuai dengan department Purchase Request.',
    'cabang_mismatch' => 'Cabang Purchase Order tidak sesuai dengan cabang Purchase Request.',

    'vendor' => [
        'master_table_not_found' => 'Table master vendor tidak ditemukan.',
        'pkp_column_not_found' => 'Kolom status PKP vendor tidak ditemukan.',
        'not_found' => 'Vendor tidak ditemukan.',
        'column_not_found' => 'Kolom vendor pada Purchase Order tidak ditemukan.',
    ],

    'lampiran' => [
        'min_required' => 'Minimal 1 lampiran Purchase Order wajib diisi.',
    ],


    'export' => [
        'failed' => 'Gagal export data Purchase Order.',
        'forbidden' => 'Anda tidak memiliki akses untuk export Purchase Order.',

        'filename' => 'purchase_order',
        'sheet_title' => 'Purchase Order',

        'columns' => [
            'no' => 'No',
            'nomor_po' => 'Nomor PO',
            'tanggal_po' => 'Tanggal PO',
            'vendor' => 'Vendor',
            'cabang' => 'Cabang',
            'department' => 'Department',
            'item' => 'Item',
            'harga_satuan' => 'Harga Satuan',
            'qty' => 'Qty',
            'satuan' => 'Satuan',
            'subtotal_item' => 'Total Harga Item',
            'ppn' => 'PPN',
            'total_po' => 'Total PO',
            'status' => 'Status',
            'status_gr' => 'Status GR',
            'nomor_pr' => 'Nomor PR',
        ],

        'status_gr_open' => 'Open',
        'status_gr_partial' => 'Partial',
        'status_gr_completed' => 'Completed',
        'status_gr_empty' => 'Belum ada GR',

        'no_item' => 'Tidak ada item',
        'no_pr' => 'Tidak ada PR',
    ],

    'print' => [
        'not_found' => 'Purchase Order tidak ditemukan.',
        'failed' => 'Gagal membuat cetakan Purchase Order.',
        'url_failed' => 'Gagal membuat link cetak Purchase Order.',
    ],
];
