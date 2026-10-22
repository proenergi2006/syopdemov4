<?php

/*
|--------------------------------------------------------------------------
| Pengajuan dana: kalimat bersama
|--------------------------------------------------------------------------
| Wording shared by Cash Advance, Realization, and Claim.
|
| The amount-revision rules are identical across every fund request
| module, so their wording lives in one place rather than inside one
| module that the others would have to borrow from.
|--------------------------------------------------------------------------
*/
return [
    'revision' => [
        'forbidden' => 'You do not have access to revise amounts. Submitted amounts can only be changed by holders of the Revise Amount permission.',
        'notes_required' => 'A revision reason is required. An amount that has passed the approval chain cannot change without an explanation the requester can read.',
        'unknown_item' => 'One of the line items does not belong to this document. Please reload the page and try again.',
        'invalid_amount' => 'A revised amount must be a number and cannot be less than zero.',
        'amount_label' => 'revised amount',
    ],

    /*
    | Finance menarik kembali penerimaan berkas.
    |
    | Jalan mundur untuk dokumen yang terlanjur diterima: tanpa ini, satu-
    | satunya pintu keluar adalah mencairkan uang yang tidak jadi dipakai.
    */
    'receipt_reversal' => [
        'forbidden' => 'You do not have access to undo a document receipt.',
        'only_received' => 'Only documents marked as received can have their receipt undone. Documents that have been paid can no longer be reverted.',
        'notes_required' => 'A reason is required. The payment date already promised to the requester is cancelled along with the receipt, and they are entitled to know why.',
        'success' => 'The receipt was undone. The document is back to approved and its payment date has been cancelled.',
        'failed' => 'The receipt could not be undone. Please try again.',
        'notes_label' => 'receipt reversal reason',
    ],

    /*
    | Mengembalikan dokumen ke pemohon untuk direvisi.
    |
    | Berlaku sama di keempat modul, termasuk Perdin -- jadi kalimatnya di
    | sini, bukan di salah satu modulnya.
    */
    'return_for_revision' => [
        'forbidden' => 'You do not have access to return this document to its requester.',
        'not_active_approver' => 'Only the approver currently holding this document can return it to the requester.',
        'only_in_progress' => 'Only documents currently in the approval process can be returned to the requester.',
        'only_received' => 'Only documents whose files have just been received can be returned to the requester. Documents already paid or settled can no longer be returned.',
        'reason_required' => 'A reason is required, at least 3 characters. A requester who gets the document back without an explanation has no way of guessing what to fix.',
        'already_open' => 'This document is already with its requester for revision.',
        'limit_reached' => 'This document has already been revised :max times, which is the limit in force. What remains is to approve or reject it.',
        'only_need_revision' => 'Only documents marked as needing revision can be resubmitted.',
        'editable_only' => 'Only documents in draft or needing revision can be edited.',
        'submittable_only' => 'Only documents in draft or needing revision can be submitted.',
        'not_requester' => 'Only the requester of this document can revise and resubmit it.',
        'success' => 'The document was returned to its requester for revision. The requester has been notified along with the reason.',
        'success_with_schedule' => 'The document was returned to its requester for revision. The receipt was undone and the payment date :date has been cancelled.',
        'resubmit_success' => 'Revision :number was resubmitted. Approval starts again from the first step.',
        'failed' => 'The document could not be returned. Please try again.',
        'reason_label' => 'return reason',
    ],

    /*
    | Nama kolom pada daftar perubahan revisi.
    |
    | Yang membaca riwayat revisi adalah pemohon dan approver, bukan yang
    | menulis kodenya: "total_amount" di layar mereka hanya berarti sistemnya
    | bocor ke permukaan. Kolom yang belum punya nama di sini ditampilkan apa
    | adanya -- terbaca janggal, tetapi tidak pernah hilang.
    */
    'revision_fields' => [
        /* Shared */
        'date' => 'Date',
        'subject' => 'Subject',
        'notes' => 'Notes',
        'branch' => 'Branch',
        'department_id' => 'Department',
        'transaction_category_id' => 'Transaction category',
        'business_trip_id' => 'Business trip',
        'total_amount' => 'Total amount',
        'request_type' => 'Request type',

        /* Line items */
        'description' => 'Description',
        'amount' => 'Amount',
        'qty' => 'Qty',
        'unit_price' => 'Unit price',
        'expense_category_id' => 'Expense category',

        /* Realization */
        'cash_advance_id' => 'Cash advance',
        'total_advance_amount' => 'Total advance',
        'total_realization_amount' => 'Total realization',
        'difference_amount' => 'Difference',
        'difference_type' => 'Difference type',

        /* Attachments */
        'filename' => 'File',
        'original_filename' => 'File name',
        'attachment_type' => 'Attachment type',
        'mime_type' => 'File type',
        'file_size' => 'File size',
        'filepath' => 'File path',

        /* Business trip */
        'employee_name' => 'Employee name',
        'position_name' => 'Position',
        'department_name' => 'Department name',
        'user_id' => 'Employee',
        'destination' => 'Destination',
        'depart_date' => 'Departure date',
        'depart_time' => 'Departure time',
        'return_date' => 'Return date',
        'return_time' => 'Return time',
        'purpose' => 'Purpose',

        /* Itinerary */
        'sort_no' => 'Order',
        'time_start' => 'Start time',
        'time_end' => 'End time',
        'timezone' => 'Time zone',
    ],

    'revision_relations' => [
        'items' => 'Line items',
        'attachments' => 'Attachments',
        'itineraries' => 'Itinerary',
    ],
];
