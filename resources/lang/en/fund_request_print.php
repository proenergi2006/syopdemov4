<?php

return [
    /*
    |--------------------------------------------------------------------------
    | Combined printing
    |--------------------------------------------------------------------------
    | Shared by cash advances, claims, realizations and business trips. One
    | file rather than four copies: the rule is one rule, and four copies that
    | start out alike always end up different.
    |
    | Each refusal says why, not merely that it refused. An option that is
    | disabled without a reason only moves the confusion to whoever reads it.
    */
    'combine' => [
        'no_trip' => 'This document is not linked to any business trip.',
        'trip_not_approved' => 'Business trip :number is not fully approved yet, so it cannot be printed along with this document.',

        'no_document' => 'No cash advance or claim is linked to this business trip yet.',
        'document_not_printable' => 'Document :number has not reached a printable status yet, so it cannot be combined.',

        'trip_attachment_label' => 'Booking Attachment',
    ],
];
