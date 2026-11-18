<?php

/*
|--------------------------------------------------------------------------
| Digital signature
|--------------------------------------------------------------------------
| The failure sentences under image deliberately name what to change about
| the photo rather than simply saying it failed. Whoever uploads cannot see
| the pixel analysis; this sentence is their only clue, so it has to tell
| them what to do next.
|--------------------------------------------------------------------------
*/
return [
    'image' => [
        'unreadable_format' => 'The file could not be read as an image.'
            . ' Use a JPG, PNG, or WEBP photo.',

        'ink_not_found' => 'No signature could be read from that image.'
            . ' Make sure the signature is written with a dark pen on white paper,'
            . ' then photographed from above in good light.',

        'background_too_dark' => 'Almost the whole image reads as ink,'
            . ' usually because the photo is dark or the paper is lined or patterned.'
            . ' Take the photo again in brighter light, on clean white paper.',
    ],

    'slot_full' => 'You already have :max signatures. Delete one before adding another.',

    'not_found' => 'That signature was not found on your account.',

    'label_required' => 'The signature name is required.',

    'agree_required' => 'You need to agree to the use of this digital signature.',

    'source_required' => 'No signature was sent.'
        . ' Draw one on screen or upload a photo of your signature.',

    'file_invalid' => 'The file must be a JPG, PNG, or WEBP image.',

    'file_too_large' => 'The file is larger than 8 MB. Please shrink the photo first.',

    'loaded' => 'Signatures loaded successfully.',
    'created' => 'Signature saved successfully.',
    'activated' => 'That signature will be used on your next documents.',
    'deleted' => 'Signature removed from your list.',
    'label_updated' => 'Signature name updated.',

    'load_failed' => 'Failed to load the signature list.',
    'create_failed' => 'Failed to save the signature.',
    'activate_failed' => 'Failed to change the signature in use.',
    'delete_failed' => 'Failed to delete the signature.',
    'label_update_failed' => 'Failed to update the signature name.',
];
