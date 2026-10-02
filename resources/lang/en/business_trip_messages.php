<?php

/*
|--------------------------------------------------------------------------
| Pesan API Perjalanan Dinas
|--------------------------------------------------------------------------
| Baru memuat tahap CRUD. Kunci untuk pengajuan, persetujuan, dan tautan ke
| FPU menyusul bersama tahapnya.
|--------------------------------------------------------------------------
*/

return [
    'user_not_authenticated' => 'Your session has ended. Please sign in again.',
    'list_loaded' => 'Business trip data loaded successfully.',
    'list_failed' => 'Failed to load business trip data.',
    'detail_loaded' => 'Business trip detail loaded successfully.',
    'options_loaded' => 'Form data loaded successfully.',
    'created' => 'Business trip created successfully.',
    'create_failed' => 'Failed to create the business trip.',
    'updated' => 'Business trip updated successfully.',
    'update_failed' => 'Failed to update the business trip.',
    'deleted' => 'Business trip deleted successfully.',
    'delete_failed' => 'Failed to delete the business trip.',
    'not_found' => 'Business trip not found.',
    'forbidden_view' => 'You do not have access to view business trips.',
    'forbidden_create' => 'You do not have access to create business trips.',
    'forbidden_update' => 'You do not have access to update business trips.',
    'forbidden_delete' => 'You do not have access to delete business trips.',

    /* Alur dokumen: ajukan, setujui, tolak, batalkan. */
    'not_editable' => 'This business trip has been submitted and can no longer be edited.',
    'delete_only_draft' => 'Only draft business trips can be deleted.',
    'forbidden_submit' => 'You do not have access to submit business trips.',
    'forbidden_cancel' => 'You do not have access to cancel business trips.',
    'submit_only_draft' => 'Only draft or rejected business trips can be submitted.',
    'submit_itinerary_missing' => 'Fill in the itinerary before submitting.',
    'submit_departure_passed' => 'The departure date of this business trip has already passed (:date), so it can no longer be submitted. Change the travel dates, or delete this one and create a new trip.',
    'submit_signature_missing' => 'You do not have a digital signature yet. Please upload one in your profile.',
    'submitted' => 'Business trip submitted successfully.',
    'submit_failed' => 'Failed to submit the business trip.',
    'approve_not_in_progress' => 'This business trip is not currently in the approval process.',
    'approved' => 'Business trip approved successfully.',
    'approve_failed' => 'Failed to approve the business trip.',
    'rejected' => 'Business trip rejected successfully.',
    'reject_failed' => 'Failed to reject the business trip.',
    'cancel_not_allowed' => 'Only submitted business trips still in progress can be cancelled. A draft can simply be deleted.',
    'cancel_blocked_received' => 'This business trip cannot be cancelled yet: :document has already been received by :receiver. Ask Finance to undo the receipt first, then this trip can be cancelled and the document will lapse with it.',
    'cancel_blocked_paid' => 'This business trip cannot be cancelled: :document has already been disbursed. The money is out, so this is settled through a realization or a refund -- not by cancelling the trip.',
    'reject_blocked_received' => 'This business trip cannot be rejected yet: :document has already been received by :receiver. Ask Finance to undo the receipt first, then this trip can be rejected and the document will lapse with it.',
    'reject_blocked_paid' => 'This business trip cannot be rejected: :document has already been disbursed. The money is out, so this is settled through a realization or a refund -- not by rejecting the trip.',
    'dependent_cancelled_by_cancel' => 'Lapsed automatically because business trip :trip was cancelled. Reason the trip was cancelled: :reason',
    'dependent_cancelled_by_reject' => 'Lapsed automatically because business trip :trip was rejected. Reason the trip was rejected: :reason',
    'document_label_cash_advance' => 'Cash advance :number',
    'document_label_claim' => 'Claim :number',
    'cancel_step_note' => 'The business trip was cancelled by its requester. Reason: :reason',
    'cancel_notes_label' => 'cancellation reason',
    'cancelled' => 'Business trip cancelled successfully.',
    'cancel_failed' => 'Failed to cancel the business trip.',

    /* Cetak: hanya dokumen yang sudah disetujui, dan hanya bagi pemegangnya. */
    'forbidden_print' => 'You do not have access to print business trips.',
    'print_not_approved' => 'A business trip can only be printed once it is fully approved.',
    'print_failed' => 'Failed to generate the business trip printout.',
    'export' => [
        'forbidden' => 'You do not have access to export business trip data.',
        'failed' => 'Failed to process the business trip export.',
        'filename' => 'Business_Trip',
        'sheet_title' => 'Business Trip',

        'columns' => [
            'no' => 'No',
            'trip_number' => 'Trip Number',
            'date' => 'Document Date',
            'branch' => 'Branch',
            'department' => 'Department',
            'employee_name' => 'Employee Name',
            'position' => 'Position',
            'destination' => 'Destination',
            'depart' => 'Departure',
            'return' => 'Return',
            'duration' => 'Duration (days)',
            'purpose' => 'Purpose',
            'trip_status' => 'Trip Status',
            'cash_advance_number' => 'Cash Advance Number',
            'cash_advance_date' => 'Cash Advance Date',
            'cash_advance_amount' => 'Cash Advance Amount',
            'cash_advance_status' => 'Cash Advance Status',
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Travel arrangements
    |--------------------------------------------------------------------------
    */
    'forbidden_arrange' => 'You do not have access to manage Business Trip arrangements.',
    'arrange_not_approved' => 'Arrangements can only be recorded after the Business Trip is fully approved.',
    'arrange_trip_cancelled' => 'This Business Trip has been cancelled; its arrangements can no longer be changed.',
    'arrange_not_found' => 'Arrangement not found.',
    'arrange_already_cancelled' => 'This arrangement has already been cancelled.',
    'arrange_replaces_invalid' => 'The arrangement being replaced is not valid: it must be one that was cancelled and does not already have a replacement.',
    'arrange_created' => 'Arrangement recorded and the notification has been sent.',
    'arrange_cancelled' => 'Arrangement cancelled and the notification has been sent.',
    'arrange_failed' => 'The arrangement could not be saved. Please try again.',

    'arrange_type_label' => 'arrangement type',
    'arrange_vendor_label' => 'provider name',
    'arrange_reference_label' => 'booking number',
    'arrange_start_label' => 'start date',
    'arrange_end_label' => 'end date',
    'arrange_notes_label' => 'notes',
    'arrange_files_label' => 'supporting files',
    'arrange_cancel_notes_label' => 'cancellation reason',
];
