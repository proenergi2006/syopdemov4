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
    'submit_signature_missing' => 'You do not have a digital signature yet. Please upload one in your profile.',
    'submitted' => 'Business trip submitted successfully.',
    'submit_failed' => 'Failed to submit the business trip.',
    'approve_not_in_progress' => 'This business trip is not currently in the approval process.',
    'approved' => 'Business trip approved successfully.',
    'approve_failed' => 'Failed to approve the business trip.',
    'rejected' => 'Business trip rejected successfully.',
    'reject_failed' => 'Failed to reject the business trip.',
    'cancel_not_allowed' => 'Only draft or in-progress business trips can be cancelled.',
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
];
