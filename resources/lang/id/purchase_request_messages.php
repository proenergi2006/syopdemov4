<?php

return [
    'user_not_authenticated' => 'User tidak terautentikasi.',
    'not_found' => 'Purchase Requisition tidak ditemukan.',
    'minimum_amount' => 'Minimal nilai Purchase Requisition adalah Rp 1.000.000.',

    'access_assignment' => [
        'branch_department_required' => 'Cabang dan department wajib dipilih.',
        'no_access_branch_department' => 'Anda tidak memiliki akses untuk cabang dan department tersebut.',
        'no_access_create' => 'Anda tidak memiliki akses membuat Purchase Requisition untuk cabang dan department tersebut.',
    ],

    'index' => [
        'loaded' => 'Data Purchase Requisition berhasil dimuat.',
        'load_failed' => 'Gagal memuat data Purchase Requisition.',
    ],

    'store' => [
        'forbidden' => 'Anda tidak memiliki akses untuk membuat Purchase Requisition.',
        'success' => 'Purchase Requisition berhasil disimpan.',
        'failed' => 'Gagal menyimpan Purchase Requisition. Silakan periksa data atau hubungi IT.',
    ],

    'show' => [
        'loaded' => 'Detail Purchase Requisition berhasil dimuat.',
        'load_failed' => 'Gagal memuat detail Purchase Requisition.',
    ],

    'update' => [
        'forbidden' => 'Anda tidak memiliki akses untuk mengubah Purchase Requisition.',
        'already_approved' => 'Purchase Requisition sudah diapprove. Tidak dapat diperbarui.',
        'success' => 'Purchase Requisition berhasil diperbarui.',
        'failed' => 'Gagal update Purchase Requisition. Silakan periksa data atau hubungi IT.',
    ],

    'destroy' => [
        'forbidden' => 'Anda tidak memiliki akses untuk menghapus Purchase Requisition.',
        'only_draft' => 'Purchase Requisition hanya dapat dihapus jika status masih Draft.',
        'success' => 'Purchase Requisition berhasil dihapus.',
        'failed' => 'Gagal menghapus Purchase Requisition.',
    ],

    'edit' => [
        'forbidden' => 'Anda tidak memiliki akses untuk mengubah Purchase Requisition.',
        'loaded' => 'Data edit Purchase Requisition berhasil dimuat.',
        'load_failed' => 'Gagal memuat data edit Purchase Requisition.',
    ],

    'attachment' => [
        'already_approved' => 'PR sudah diapprove atau sedang tahap approval. Lampiran tidak dapat dihapus.',
        'deleted' => 'Lampiran berhasil dihapus.',
        'not_found' => 'Lampiran tidak ditemukan.',
        'delete_failed' => 'Gagal menghapus lampiran.',
    ],

    'approve' => [
        'not_in_progress' => 'Purchase Requisition tidak sedang dalam proses approval.',
        'failed' => 'Gagal approve Purchase Requisition.',
    ],

    'reject' => [
        'not_in_progress' => 'Purchase Requisition tidak sedang dalam proses approval.',
        'success' => 'Purchase Requisition berhasil ditolak.',
        'failed' => 'Gagal reject Purchase Requisition.',
    ],

    'cancel' => [
        'forbidden' => 'Anda tidak memiliki akses untuk membatalkan Purchase Requisition.',
        'success' => 'Purchase Requisition berhasil dibatalkan.',
        'invalid_id' => 'ID Purchase Requisition tidak valid.',
        'not_found' => 'Purchase Requisition tidak ditemukan.',
        'failed' => 'Gagal membatalkan Purchase Requisition.',
    ],

    'submit' => [
        'only_draft' => 'Purchase Requisition hanya bisa disubmit dari status Draft.',
        'items_unavailable' => 'Purchase Requisition tidak dapat disubmit karena item belum tersedia.',
        'signature_missing' => 'Tanda tangan requester belum tersedia. Silakan lengkapi tanda tangan terlebih dahulu.',
        'success' => 'Purchase Requisition berhasil disubmit.',
        'failed' => 'Gagal submit Purchase Requisition.',
    ],

    'dropdown_approved' => [
        'forbidden' => 'Anda tidak memiliki akses untuk membuat Purchase Order.',
        'department_forbidden' => 'Anda tidak memiliki akses membuat Purchase Order untuk department tersebut.',
        'loaded' => 'Purchase Requisition berhasil dimuat.',
        'load_failed' => 'Gagal memuat Purchase Requisition.',
    ],

    'special_document_type' => [
        'not_allowed' => 'Tipe dokumen khusus tersebut tidak tersedia untuk department Anda.',
    ],

    'export' => [
        'failed' => 'Gagal export data Purchase Requisition.',
        'forbidden' => 'Anda tidak memiliki akses untuk export Purchase Requisition.',

        'filename' => 'purchase_requisition',
        'sheet_title' => 'Purchase Requisition',

        'columns' => [
            'no' => 'No',
            'nomor_pr' => 'Nomor PR',
            'tanggal_pr' => 'Tanggal PR',
            'cabang' => 'Cabang',
            'department' => 'Department',
            'item' => 'Item',
            'harga_satuan' => 'Harga Satuan',
            'qty' => 'Qty',
            'satuan' => 'Satuan',
            'subtotal_item' => 'Total Harga Item',
            'ppn' => 'PPN',
            'total_pr' => 'Total PR',
            'status' => 'Status',
            'status_po' => 'Status Pemenuhan PO',
            'nomor_po' => 'Nomor PO',
        ],

        'status_po_open' => 'Open',
        'status_po_partial' => 'Partial',
        'status_po_completed' => 'Completed',
        'status_po_empty' => 'Belum ada PO',

        'no_item' => 'Tidak ada item',
        'no_po' => 'Belum ada PO',
    ],

    'print' => [
        'not_found' => 'Purchase Requisition tidak ditemukan.',
        'failed' => 'Gagal membuat cetakan Purchase Requisition.',
        'url_failed' => 'Gagal membuat link cetak Purchase Requisition.',
    ],

    'vendor' => [
        'load_failed' => 'Gagal memuat Purchase Requisition untuk vendor.',
    ],
];
