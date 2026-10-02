<?php

/*
|--------------------------------------------------------------------------
| Business Trip Expense Category Master
|--------------------------------------------------------------------------
| Transportasi, Penginapan, Uang Saku -- the grouping used by cash advance
| and realization lines whose transaction category is a business trip.
|
| These categories only apply there; see the notes on its migration.
|--------------------------------------------------------------------------
*/
return [
    'forbidden' => 'You do not have access to manage business trip expense categories.',
    'not_found' => 'Expense category not found.',

    'fields' => [
        'name' => 'category name',
        'sort_order' => 'display order',
    ],

    'store' => [
        'success' => 'Expense category added.',
    ],

    'update' => [
        'success' => 'Expense category updated.',
    ],

    'toggle' => [
        'success' => 'Expense category status changed.',
    ],

    'destroy' => [
        'success' => 'Expense category deleted.',

        /*
        | A category already in use is deactivated rather than deleted. The
        | pointing column is nullOnDelete -- the documents will not break --
        | but the grouping on documents that are already approved and already
        | printed would be lost, and nothing could bring it back.
        */
        'in_use' => 'This category is already used by document lines, so it cannot be deleted. Deactivate it instead — it disappears from the choices without touching existing documents.',

        'failed' => 'The expense category could not be deleted. Please try again.',
    ],
];
