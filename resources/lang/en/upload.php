<?php

/*
|--------------------------------------------------------------------------
| Upload failures at the PHP level
|--------------------------------------------------------------------------
| Replaces Laravel's built-in "failed to upload", which names neither the
| file, the cause, nor the next step. See App\Http\Middleware\ExplainUploadFailure.
|
| Every sentence names THE FILE and ONE ACTION. What the submitter can fix
| themselves says how; what is genuinely the server's problem points them at
| IT, so they do not keep retrying something that will never work.
|--------------------------------------------------------------------------
*/
return [
    'failed' => [
        'ini_size' => 'The file ":file" exceeds the server upload limit of :limit per file.'
            . ' Compress or split the file first, or ask IT to raise that limit.',

        'form_size' => 'The file ":file" exceeds the size limit set by this form.',

        'partial' => 'The upload of ":file" was cut off before it finished.'
            . ' Check your connection, then upload it again.',

        'no_tmp_dir' => 'The server has no temporary directory to hold uploads, so ":file" could not be'
            . ' accepted. Contact IT -- this cannot be fixed from here.',

        'cant_write' => 'The server could not write ":file" to disk, most likely because storage is full.'
            . ' Contact IT -- this cannot be fixed from here.',

        'extension' => 'The upload of ":file" was stopped by a PHP extension on the server.'
            . ' Contact IT -- this cannot be fixed from here.',

        'unknown' => 'The file ":file" failed to upload on the server (error code :code).'
            . ' Quote that code when you contact IT.',
    ],

    'post_too_large' => 'The attachments add up to more than the server accepts in one submission, which is :limit.'
        . ' Send fewer attachments, or submit some now and add the rest later through Edit.',

    'unlimited' => 'unlimited',

    /*
    |--------------------------------------------------------------------------
    | Attachments that SILENTLY never arrive
    |--------------------------------------------------------------------------
    | PHP caps the number of files per submission through max_file_uploads.
    | Past that cap the extra files are not rejected -- they simply are not
    | there. No error code, no entry in $_FILES, nothing left to read
    | afterwards.
    |
    | The consequence has happened and was measured: twenty-five attachments
    | sent, twenty stored, the document reported as saved, and nobody told
    | that five receipts had gone missing.
    |
    | The only way to notice is to compare against the count the sender
    | DECLARED. The screen sends that number; the sentences below are used
    | when it does not match.
    |--------------------------------------------------------------------------
    */
    'truncated' => [
        'server_limit' => 'The server accepts only :limit attachments per submission,'
            . ' but :sent were sent -- :lost of them never arrived.'
            . ' The document was NOT saved, so that no evidence goes missing unnoticed.'
            . ' Send fewer attachments, or save some now and add the rest through Edit.',

        'unknown' => 'Of the :sent attachments sent, only :received reached the server.'
            . ' The document was NOT saved, so that no evidence goes missing unnoticed.'
            . ' Check your connection, then send it again.',
    ],

    /*
    | A file that passed validation but turned out to be incomplete when it
    | was about to be written to disk. It used to be skipped silently -- the
    | document was stored, the attachment was not, and nothing said so.
    */
    'not_received' => 'The attachment ":file" did not reach the server intact.'
        . ' Saving was cancelled so the document is not stored without that evidence.'
        . ' Upload the file again.',

    /*
    | Attachments pointing at a detail row that does not exist. These used to
    | be discarded silently, so the files vanished without a trace.
    */
    'orphan_row' => 'There are attachments for row :row, but the detail sent contains no such row.'
        . ' Reload the page, then arrange the attachments again.',

    /*
    |--------------------------------------------------------------------------
    | Validation sentences for per-row attachments
    |--------------------------------------------------------------------------
    | Laravel's defaults name the raw attribute -- "line_attachments.0.0" --
    | which means nothing to the person filling in the form. The sentences
    | below name the file and its row number instead.
    |--------------------------------------------------------------------------
    */
    'rule' => [
        'attribute' => 'Attachment ":file" on row :row',

        'file' => ':attribute was not received by the server as a complete file. Upload it again.',

        'uploaded' => ':attribute failed to upload to the server. Upload it again.',

        'mimes' => ':attribute is neither an image nor a PDF.'
            . ' Only JPG, JPEG, PNG and PDF can be attached.',

        'max' => ':attribute is over the :max KB limit per attachment.'
            . ' Compress the image, or split the PDF, then upload it again.',
    ],
];
