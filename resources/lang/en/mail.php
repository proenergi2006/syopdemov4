<?php

return [
    'po' => [
        'subject' => [
            'final_approved' => 'Purchase Order Approved - :nomor_po',
            'step_approved' => 'Purchase Order Approval Update - :nomor_po',
            'rejected' => 'Purchase Order Rejected - :nomor_po',
            'default' => 'Purchase Order Approval - :nomor_po',
        ],
        'title' => [
            'final_approved' => 'Purchase Order Has Been Approved',
            'step_approved' => 'Purchase Order Approval Update',
            'rejected' => 'Purchase Order Rejected',
            'default' => 'Purchase Order Approval',
        ],
        'description' => [
            'final_approved' => 'Your Purchase Order has received final approval from :actor_name.',
            'step_approved' => 'Your Purchase Order has been approved by :actor_name and is still awaiting further approval.',
            'rejected' => 'Your Purchase Order has been rejected by :actor_name.',
            'default' => 'There is a Purchase Order awaiting your approval.',
        ],
        'body_heading' => 'Purchase Order Approval',
        'field_no' => 'PO No.',
        'field_date' => 'PO Date',
        'field_total' => 'Total Amount',
        'field_status' => 'Status',
        'field_rejection_notes' => 'Rejection Notes',
        'instruction' => 'Please click the button below to open the Purchase Order page in SYOP v4.',
        'button' => 'Open Purchase Order',
    ],

    'pr' => [
        'subject' => [
            'step_approved' => 'Purchase Requisition Approval Step Approved - :nomor_pr',
            'final_approved' => 'Purchase Requisition Approved - :nomor_pr',
            'rejected' => 'Purchase Requisition Rejected - :nomor_pr',
            'default' => 'Purchase Requisition Approval Request - :nomor_pr',
        ],
        'title' => [
            'final_approved' => 'Purchase Requisition Has Been Approved',
            'step_approved' => 'Purchase Requisition Approval Update',
            'rejected' => 'Purchase Requisition Rejected',
            'default' => 'Purchase Requisition Approval',
        ],
        'description' => [
            'final_approved' => 'Your Purchase Requisition has received final approval from :actor_name.',
            'step_approved' => 'Your Purchase Requisition has been approved by :actor_name and is still awaiting further approval.',
            'rejected' => 'Your Purchase Requisition has been rejected by :actor_name.',
            'default' => 'There is a Purchase Requisition awaiting your approval.',
        ],
        'field_no' => 'PR No.',
        'field_date' => 'PR Date',
        'field_total' => 'Total Amount',
        'field_step' => 'Approval Step',
        'field_step_value' => 'Step :step_order',
        'field_status' => 'Status',
        'field_rejection_notes' => 'Rejection Notes',
        'instruction' => 'Please click the button below to open the Purchase Requisition page in SYOP v4.',
        'button' => 'Open Purchase Requisition',
    ],

    'claim' => [
        'subject' => [
            'final_approved' => 'Claim Approved - :claim_number',
            'rejected' => 'Claim Rejected - :claim_number',
            'paid' => 'Claim Reimbursed - :claim_number',
            'received' => 'Claim Received - :claim_number',
            'receipt_request' => 'Claim Awaiting Receipt - :claim_number',
            'payment_request' => 'Claim Awaiting Reimbursement - :claim_number',

            'default' => 'Claim Approval Request - :claim_number',
        ],
        'title' => [
            'final_approved' => 'Claim Approved',
            'rejected' => 'Claim Rejected',
            'paid' => 'Claim Reimbursed',
            'received' => 'Claim Received',
            'receipt_request' => 'Claim Awaiting Receipt',
            'payment_request' => 'Claim Awaiting Reimbursement',

            'default' => 'Claim Approval',
        ],
        'description' => [
            'final_approved' => 'Your claim has received final approval from :actor_name.',
            'rejected' => 'Your claim was rejected by :actor_name.',
            'paid' => 'Your claim has been reimbursed by :actor_name.',
            'received' => 'Your claim was received by :actor_name and is awaiting payment.',
            'receipt_request' => 'There is an approved claim awaiting your receipt.',
            'payment_request' => 'A claim has been approved and is awaiting your reimbursement.',

            'default' => 'There is a claim awaiting your approval.',
        ],
        'field_no' => 'Claim No.',
        'field_date' => 'Claim Date',
        'field_payment_date' => 'Scheduled Payment',
        'field_category' => 'Transaction Category',
        'field_subject' => 'Subject',
        'field_total' => 'Claim Total',
        'field_step' => 'Approval Step',
        'field_step_value' => 'Step :step_order',
        'field_status' => 'Status',
        'field_notes' => 'Notes',
        'instruction' => 'Please click the button below to open the claim in SYOP v4.',
        'button' => 'Open Claim',
    ],
    'fpu' => [
        'subject' => [
            'final_approved' => 'Cash Advance Approved - :advance_number',
            'rejected' => 'Cash Advance Rejected - :advance_number',
            'disbursed' => 'Cash Advance Disbursed - :advance_number',
            'received' => 'Cash Advance Received - :advance_number',
            'receipt_request' => 'Cash Advance Awaiting Receipt - :advance_number',
            'disbursement_request' => 'Cash Advance Awaiting Disbursement - :advance_number',

            'default' => 'Cash Advance Approval Request - :advance_number',
        ],
        'title' => [
            'final_approved' => 'Cash Advance Approved',
            'rejected' => 'Cash Advance Rejected',
            'disbursed' => 'Cash Advance Disbursed',
            'received' => 'Cash Advance Received',
            'receipt_request' => 'Cash Advance Awaiting Receipt',
            'disbursement_request' => 'Cash Advance Awaiting Disbursement',

            'default' => 'Cash Advance Approval',
        ],
        'description' => [
            'final_approved' => 'Your cash advance has been fully approved by :actor_name.',
            'rejected' => 'Your cash advance has been rejected by :actor_name.',
            'disbursed' => 'Your cash advance has been disbursed by :actor_name.',
            'received' => 'Your cash advance was received by :actor_name and is awaiting disbursement.',
            'receipt_request' => 'There is an approved cash advance awaiting your receipt.',
            'disbursement_request' => 'A cash advance has been approved and is awaiting your disbursement.',

            'default' => 'There is a cash advance awaiting your approval.',
        ],
        'field_no' => 'Number',
        'field_date' => 'Date',
        'field_payment_date' => 'Scheduled Payment',
        'field_category' => 'Transaction Category',
        'field_subject' => 'Subject',
        'field_total' => 'Total Requested',
        'field_step' => 'Approval Step',
        'field_step_value' => 'Step :step_order',
        'field_status' => 'Status',
        'field_notes' => 'Notes',
        'instruction' => 'Please click the button below to open the cash advance page in SYOP v4.',
        'button' => 'Open Cash Advance',
    ],

    'realisasi' => [
        'subject' => [
            'final_approved' => 'Realization Approved - :realization_number',
            'rejected' => 'Realization Rejected - :realization_number',
            'settled' => 'Realization Difference Settled - :realization_number',
            'received' => 'Realization Received - :realization_number',
            'receipt_request' => 'Realization Awaiting Receipt - :realization_number',
            'return_request' => 'Realization Surplus Awaiting Return - :realization_number',
            'reimburse_request' => 'Realization Shortfall Awaiting Payment - :realization_number',

            'default' => 'Realization Approval Request - :realization_number',
        ],
        'title' => [
            'final_approved' => 'Realization Approved',
            'rejected' => 'Realization Rejected',
            'settled' => 'Realization Difference Settled',
            'received' => 'Realization Received',
            'receipt_request' => 'Realization Awaiting Receipt',
            'return_request' => 'Realization Surplus Awaiting Return',
            'reimburse_request' => 'Realization Shortfall Awaiting Payment',

            'default' => 'Realization Approval',
        ],
        'description' => [
            'final_approved' => 'Your realization has been fully approved by :actor_name.',
            'rejected' => 'Your realization has been rejected by :actor_name.',
            'settled' => 'The difference on your realization has been settled by :actor_name.',
            'received' => 'The realization was received by :actor_name.',
            'receipt_request' => 'There is an approved realization awaiting your receipt.',
            'return_request' => 'The realization below has been approved and leaves a surplus the requester must return to Finance.',
            'reimburse_request' => 'The realization below has been approved and its shortfall must be paid to the requester.',

            'default' => 'There is a realization awaiting your approval.',
        ],
        'field_no' => 'Realization No.',
        'field_advance_no' => 'Cash Advance No.',
        'field_date' => 'Realization Date',
        'field_payment_date' => 'Scheduled Payment',
        'field_category' => 'Transaction Category',
        'field_subject' => 'Subject',
        'field_total_advance' => 'Disbursed Amount',
        'field_total_realization' => 'Total Realized',
        'field_difference' => 'Difference',
        'difference_return' => 'To Be Returned',
        'difference_reimburse' => 'To Be Reimbursed',
        'difference_none' => 'No Difference',
        'field_step' => 'Approval Step',
        'field_step_value' => 'Step :step_order',
        'field_status' => 'Status',
        'field_notes' => 'Notes',
        'instruction' => 'Please click the button below to open the realization page in SYOP v4.',
        'button' => 'Open Realization',
    ],

    'greeting' => 'Dear',
    'footer_notice' => 'This email was sent automatically by the SYOP v4 system. Please do not reply to this email.',
    'footer_rights' => 'All Rights Reserved.',
    /*
    | Perdin -- the travel permit that precedes the cash advance funding it.
    | Only three events: requested, approved, rejected. No payment stage:
    | what is approved here is the trip, not the money.
    */
    'business_trip' => [
        'subject' => [
            'default' => 'Business trip :trip_number needs your approval',
            'final_approved' => 'Business trip :trip_number has been approved',
            'rejected' => 'Business trip :trip_number was rejected',
        ],

        'title' => [
            'default' => 'Business Trip Approval Request',
            'final_approved' => 'Business Trip Approved',
            'rejected' => 'Business Trip Rejected',
        ],

        'description' => [
            'default' => 'A business trip request is waiting for your approval. Please review the itinerary below.',
            'final_approved' => 'Your business trip request has been fully approved by :actor_name. You may now submit the cash advance for this trip.',
            'rejected' => 'Your business trip request was rejected by :actor_name. Please review the notes, revise it, and submit again.',
        ],

        'field_no' => 'Trip Number',
        'field_employee' => 'Employee Name',
        'field_destination' => 'Destination',
        'field_period' => 'Travel Period',
        'field_purpose' => 'Purpose',
        'field_step' => 'Approval Step',
        'field_step_value' => 'Step :step_order',
        'field_status' => 'Status',
        'field_notes' => 'Notes',

        'itinerary_title' => 'Itinerary',
        'itinerary_date' => 'Day & Date',
        'itinerary_time' => 'Time',
        'itinerary_description' => 'Description',

        'instruction' => 'Click the button below to open the document in the app.',
        'button' => 'Open Business Trip',
    ],

];
