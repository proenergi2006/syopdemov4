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
];
