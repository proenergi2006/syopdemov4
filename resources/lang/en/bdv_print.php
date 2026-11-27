<?php

/*
|--------------------------------------------------------------------------
| Bank Disbursement Voucher printout wording
|--------------------------------------------------------------------------
| Pasangan resources/lang/id/bdv_print.php. Kuncinya wajib sama persis --
| keterangan selengkapnya ada di berkas itu.
|
| Nama dokumennya TIDAK diterjemahkan: "Bank Disbursement Voucher" memang
| sudah berbahasa Inggris, dan itulah nama yang dipakai orang menyebutnya
| di kedua bahasa.
|--------------------------------------------------------------------------
*/

return [
    'title' => 'Bank Disbursement Voucher',
    'subtitle' => 'Bank Payment Voucher',

    'info_title' => 'Voucher Information',

    'number' => 'Voucher No.',
    'date' => 'Date',
    'branch' => 'Branch',
    'department' => 'Department',
    'category' => 'Transaction Category',
    'status' => 'Status',
    'requester' => 'Submitted By',

    'paid_from' => 'Paid From',
    'payee' => 'Payee',
    'check_number' => 'Cheque/Giro No.',

    'documents' => 'Documents Disbursed',

    'detail_title' => 'Disbursement Details',

    'col_no' => 'No',
    'col_account_code' => 'Account Code',
    'col_account_name' => 'Account Name',
    'col_branch' => 'Branch',
    'col_memo' => 'Memo',
    'col_amount' => 'Amount',

    'empty_items' => 'No disbursement details.',
    'total' => 'Total',
    'say' => 'Say:',

    'description' => 'Description',

    'prepared_by' => 'Prepared By',
    'requester_role' => 'Requester',
    'approved_by' => 'Approved By',
    'approver_role' => 'Approver',
    'no_approval' => 'No approval yet.',

    'footer' => 'Printed from the SYOP system on :waktu (WIB).',

    'not_printable' => 'Only fully approved BDVs can be printed.',
    'not_found' => 'That Bank Disbursement Voucher was not found.',
    'failed' => 'Failed to generate the BDV printout.',
];
