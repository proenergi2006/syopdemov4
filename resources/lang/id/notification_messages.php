<?php

return [
    'purchase_order' => [
        'approval_request' => [
            'title' => 'Approval Purchase Order',
            'message' => 'Purchase Order :nomor_po menunggu approval Anda.',
        ],
        'approval_step_pending' => [
            'title' => 'Tahap Approval PO Disetujui',
            'message' => 'Purchase Order :nomor_po telah disetujui oleh :approver_name dan masih menunggu approval berikutnya.',
        ],
        'approval_step_final' => [
            'title' => 'Purchase Order Disetujui',
            'message' => 'Purchase Order :nomor_po telah final disetujui oleh :approver_name.',
        ],
        'rejected' => [
            'title' => 'Purchase Order Ditolak',
            'message' => 'Purchase Order :nomor_po telah ditolak oleh :rejecter_name.',
        ],
    ],

    'purchase_request' => [
        'approval_request' => [
            'title' => 'Approval Purchase Requisition',
            'message_with_label' => 'Purchase Requisition :nomor_pr menunggu approval Anda pada tahap :step_order (:step_label).',
            'message_without_label' => 'Purchase Requisition :nomor_pr menunggu approval Anda pada tahap :step_order.',
        ],
        'approval_step_pending' => [
            'title' => 'Tahap Approval PR Disetujui',
            'message' => 'Purchase Requisition :nomor_pr telah disetujui oleh :approver_name pada tahap :step_order dan masih menunggu approval berikutnya.',
        ],
        'approval_step_final' => [
            'title' => 'Purchase Requisition Disetujui',
            'message' => 'Purchase Requisition :nomor_pr telah final disetujui oleh :approver_name.',
        ],
        'rejected' => [
            'title' => 'Purchase Requisition Ditolak',
            'message' => 'Purchase Requisition :nomor_pr telah ditolak oleh :rejecter_name.',
        ],
    ],

    /*
    | FPU -- Form Pengajuan Uang, modul Pengajuan Dana.
    */
    /*
    |--------------------------------------------------------------------------
    | Claim
    |--------------------------------------------------------------------------
    */
    'claim' => [
        /*
        | Gugur karena perdinnya, bukan karena ada yang menolaknya.
        |
        | Dipisah dari 'rejected' justru karena itu: tidak ada penolak,
        | dan pesan yang menyebut penolak akan mengarang orang.
        |
        | Satu kalimat utuh per sebab, bukan satu kalimat bertambal kata
        | kerja. Notifikasi diterjemahkan ulang saat DIBACA, memakai kunci
        | dan parameter yang tersimpan -- dan parameternya tidak ikut
        | diterjemahkan. Kata kerja yang dikirim sebagai parameter akan
        | membeku dalam bahasa penulisnya, dan pembaca bahasa lain
        | menerima kalimat yang separuh benar.
        */
        'lapsed_by_trip' => [
            'requester' => [
                'cancelled' => [
                    'title' => 'Claim Gugur',
                    'message' => 'Claim :claim_number gugur karena Perjalanan Dinas :trip_number dibatalkan.',
                ],
                'rejected' => [
                    'title' => 'Claim Gugur',
                    'message' => 'Claim :claim_number gugur karena Perjalanan Dinas :trip_number ditolak.',
                ],
            ],
            'approver' => [
                'cancelled' => [
                    'title' => 'Persetujuan Claim Dihentikan',
                    'message' => 'Persetujuan Claim :claim_number dihentikan karena Perjalanan Dinas :trip_number dibatalkan.',
                ],
                'rejected' => [
                    'title' => 'Persetujuan Claim Dihentikan',
                    'message' => 'Persetujuan Claim :claim_number dihentikan karena Perjalanan Dinas :trip_number ditolak.',
                ],
            ],
        ],

        'receipt_request' => [
            'title' => 'Claim Menunggu Diterima',
            'message' => 'Claim :claim_number senilai Rp :total_amount menunggu Anda terima.',
        ],

        'received' => [
            'title' => 'Claim Sudah Diterima',
            'message' => 'Claim :claim_number Anda sudah diterima oleh :receiver_name dan menunggu pembayaran.',
            'message_scheduled' => 'Claim :claim_number Anda sudah diterima oleh :receiver_name dan dijadwalkan dibayarkan pada :payment_date.',
        ],

        /*
        | Di luar approval flow: penerimanya pemegang permission pembayaran.
        */
        'payment_request' => [
            'title' => 'Claim Menunggu Pembayaran',
            'message' => 'Claim :claim_number sudah disetujui dan menunggu penggantian sebesar Rp :total_amount.',
        ],

        'paid' => [
            'title' => 'Claim Sudah Dibayarkan',
            'message' => 'Claim :claim_number Anda telah dibayarkan oleh :payer_name.',
        ],
        'approval_request' => [
            'title' => 'Permintaan Approval Claim',
            'message_with_label' => 'Claim :claim_number menunggu approval Anda pada tahap :step_order (:step_label).',
            'message_without_label' => 'Claim :claim_number menunggu approval Anda pada tahap :step_order.',
        ],

        'approval_step_pending' => [
            'title' => 'Tahap Approval Claim Disetujui',
            'message' => 'Claim :claim_number telah disetujui :approver_name pada tahap :step_order dan masih menunggu tahap berikutnya.',
        ],

        'approval_step_final' => [
            'title' => 'Claim Disetujui',
            'message' => 'Claim :claim_number telah disetujui secara final oleh :approver_name.',
        ],

        'rejected' => [
            'title' => 'Claim Ditolak',
            'message' => 'Claim :claim_number ditolak oleh :rejecter_name.',
        ],

        /*
        | Angka yang ditulis pemohon sendiri berubah di tangan orang
        | lain. Kedua angkanya disebut terang-terangan, beserta
        | alasannya -- kembaran kalimat yang sama di FPU.
        */
        'amount_revised' => [
            'title' => 'Nominal Claim Direvisi',
            'message' => 'Nominal Claim :claim_number diubah oleh :reviser_name dari :old_total menjadi :new_total. Alasan: :reason',
        ],

        /*
        | Tanggal pembayaran yang sudah dijanjikan ikut batal. Disebut
        | terang-terangan karena itulah yang sudah dicatat pemohon dari
        | email sebelumnya -- dan tanpa menyebutnya, kabar ini hanya
        | memberi tahu bahwa ada sesuatu yang berubah.
        |
        | Dua kalimat karena dokumen lama bisa saja tidak pernah punya
        | tanggal pembayaran.
        */
        'receipt_reverted' => [
            'title' => 'Penerimaan Claim Dibatalkan',
            'with_date' => 'Penerimaan Claim :claim_number dibatalkan oleh :actor_name. Jadwal pembayaran :scheduled_date ikut batal. Dokumen kembali ke status disetujui. Alasan: :reason',
            'without_date' => 'Penerimaan Claim :claim_number dibatalkan oleh :actor_name. Dokumen kembali ke status disetujui. Alasan: :reason',
        ],
    ],

    'cash_advance' => [
        /*
        | Gugur karena perdinnya, bukan karena ada yang menolaknya.
        |
        | Dipisah dari 'rejected' justru karena itu: tidak ada penolak,
        | dan pesan yang menyebut penolak akan mengarang orang.
        |
        | Satu kalimat utuh per sebab, bukan satu kalimat bertambal kata
        | kerja. Notifikasi diterjemahkan ulang saat DIBACA, memakai kunci
        | dan parameter yang tersimpan -- dan parameternya tidak ikut
        | diterjemahkan. Kata kerja yang dikirim sebagai parameter akan
        | membeku dalam bahasa penulisnya, dan pembaca bahasa lain
        | menerima kalimat yang separuh benar.
        */
        'lapsed_by_trip' => [
            'requester' => [
                'cancelled' => [
                    'title' => 'FPU Gugur',
                    'message' => 'FPU :advance_number gugur karena Perjalanan Dinas :trip_number dibatalkan.',
                ],
                'rejected' => [
                    'title' => 'FPU Gugur',
                    'message' => 'FPU :advance_number gugur karena Perjalanan Dinas :trip_number ditolak.',
                ],
            ],
            'approver' => [
                'cancelled' => [
                    'title' => 'Persetujuan FPU Dihentikan',
                    'message' => 'Persetujuan FPU :advance_number dihentikan karena Perjalanan Dinas :trip_number dibatalkan.',
                ],
                'rejected' => [
                    'title' => 'Persetujuan FPU Dihentikan',
                    'message' => 'Persetujuan FPU :advance_number dihentikan karena Perjalanan Dinas :trip_number ditolak.',
                ],
            ],
        ],

        /*
        | Di luar approval flow: penerimanya pemegang permission pencairan,
        | bukan approver dokumen ini.
        */
        'receipt_request' => [
            'title' => 'FPU Menunggu Diterima',
            'message' => 'FPU :advance_number senilai Rp :total_amount menunggu Anda terima.',
        ],

        'received' => [
            'title' => 'FPU Sudah Diterima',
            'message' => 'FPU :advance_number sudah diterima oleh :receiver_name dan menunggu pencairan.',
            'message_scheduled' => 'FPU :advance_number sudah diterima oleh :receiver_name dan dijadwalkan dibayarkan pada :payment_date.',
        ],

        'disbursement_request' => [
            'title' => 'FPU Menunggu Pencairan',
            'message' => 'FPU :advance_number sudah disetujui dan menunggu pencairan sebesar Rp :total_amount.',
        ],
        'approval_request' => [
            'title' => 'Approval FPU',
            'message_with_label' => 'FPU :advance_number menunggu approval Anda pada tahap :step_order (:step_label).',
            'message_without_label' => 'FPU :advance_number menunggu approval Anda pada tahap :step_order.',
        ],
        'approval_step_pending' => [
            'title' => 'Tahap Approval FPU Disetujui',
            'message' => 'FPU :advance_number telah disetujui oleh :approver_name pada tahap :step_order dan masih menunggu approval berikutnya.',
        ],
        'approval_step_final' => [
            'title' => 'FPU Disetujui',
            'message' => 'FPU :advance_number telah final disetujui oleh :approver_name.',
        ],
        'rejected' => [
            'title' => 'FPU Ditolak',
            'message' => 'FPU :advance_number telah ditolak oleh :rejecter_name.',
        ],
        'disbursed' => [
            'title' => 'FPU Sudah Dibayarkan',
            'message' => 'FPU :advance_number telah dibayarkan oleh :disburser_name.',


            /*
            | Yang menyimpang dari jadwal dikabarkan apa adanya, beserta
            | keterangan Finance bila ada. Pemohon yang dijanjikan Rabu
            | lalu menerima uangnya Senin berikutnya berhak tahu tanpa
            | harus membuka rincian dokumennya sendiri.
            */
            'message_early' => 'FPU :advance_number telah dibayarkan oleh :disburser_name, lebih awal dari jadwal :scheduled_date.',
            'message_early_reason' => 'FPU :advance_number telah dibayarkan oleh :disburser_name, lebih awal dari jadwal :scheduled_date. Keterangan Finance: :reason',
            'message_late' => 'FPU :advance_number telah dibayarkan oleh :disburser_name, melewati jadwal :scheduled_date.',
            'message_late_reason' => 'FPU :advance_number telah dibayarkan oleh :disburser_name, melewati jadwal :scheduled_date. Keterangan Finance: :reason',
        ],

        /*
        | Angka yang ditulis pemohon sendiri berubah di tangan orang
        | lain. Kedua angkanya disebut terang-terangan, beserta
        | alasannya -- yang membacanya tidak semestinya perlu membuka
        | aplikasi hanya untuk tahu berapa selisihnya.
        */
        'amount_revised' => [
            'title' => 'Nominal FPU Direvisi',
            'message' => 'Nominal FPU :advance_number diubah oleh :reviser_name dari :old_total menjadi :new_total. Alasan: :reason',
        ],

        /*
        | Tanggal pembayaran yang sudah dijanjikan ikut batal. Disebut
        | terang-terangan karena itulah yang sudah dicatat pemohon dari
        | email sebelumnya -- dan tanpa menyebutnya, kabar ini hanya
        | memberi tahu bahwa ada sesuatu yang berubah.
        |
        | Dua kalimat karena dokumen lama bisa saja tidak pernah punya
        | tanggal pembayaran.
        */
        'receipt_reverted' => [
            'title' => 'Penerimaan FPU Dibatalkan',
            'with_date' => 'Penerimaan FPU :advance_number dibatalkan oleh :actor_name. Jadwal pembayaran :scheduled_date ikut batal. Dokumen kembali ke status disetujui. Alasan: :reason',
            'without_date' => 'Penerimaan FPU :advance_number dibatalkan oleh :actor_name. Dokumen kembali ke status disetujui. Alasan: :reason',
        ],
    ],

    /*
    | Realisasi FPU -- pertanggungjawaban atas uang yang sudah dicairkan.
    */
    'cash_advance_realization' => [
        'receipt_request' => [
            'title' => 'Realisasi FPU Menunggu Diterima',
            'message' => 'Realisasi FPU :realization_number senilai Rp :total_amount menunggu Anda terima.',
        ],

        'received' => [
            'title' => 'Realisasi FPU Sudah Diterima',
            'message' => 'Realisasi FPU :realization_number Anda sudah diterima oleh :receiver_name.',
            'message_scheduled' => 'Realisasi FPU :realization_number Anda sudah diterima oleh :receiver_name dan kekurangannya dijadwalkan dibayarkan pada :payment_date.',
        ],

        /*
        | Di luar approval flow: penerimanya pemegang permission penyelesaian
        | selisih, dipilih mengikuti arah uangnya.
        */
        'return_request' => [
            'title' => 'Selisih Realisasi Menunggu Pengembalian',
            'message' => 'Realisasi :realization_number sudah disetujui dan menyisakan dana Rp :difference_amount yang perlu dikembalikan pemohon.',
        ],

        'reimburse_request' => [
            'title' => 'Kekurangan Realisasi Menunggu Pembayaran',
            'message' => 'Realisasi :realization_number sudah disetujui dan kekurangannya sebesar Rp :difference_amount perlu dibayarkan kepada pemohon.',
        ],
        'approval_request' => [
            'title' => 'Approval Realisasi FPU',
            'message_with_label' => 'Realisasi FPU :realization_number menunggu approval Anda pada tahap :step_order (:step_label).',
            'message_without_label' => 'Realisasi FPU :realization_number menunggu approval Anda pada tahap :step_order.',
        ],
        'approval_step_pending' => [
            'title' => 'Tahap Approval Realisasi FPU Disetujui',
            'message' => 'Realisasi FPU :realization_number telah disetujui oleh :approver_name pada tahap :step_order dan masih menunggu approval berikutnya.',
        ],
        'approval_step_final' => [
            'title' => 'Realisasi FPU Disetujui',
            'message' => 'Realisasi FPU :realization_number telah final disetujui oleh :approver_name.',
        ],
        'rejected' => [
            'title' => 'Realisasi FPU Ditolak',
            'message' => 'Realisasi FPU :realization_number telah ditolak oleh :rejecter_name.',
        ],
        'settled' => [
            'title' => 'Selisih Realisasi FPU Selesai',
            'message' => 'Selisih Realisasi FPU :realization_number telah diselesaikan oleh :settler_name.',
        ],

        /*
        | Tanggal pembayaran yang sudah dijanjikan ikut batal. Disebut
        | terang-terangan karena itulah yang sudah dicatat pemohon dari
        | email sebelumnya -- dan tanpa menyebutnya, kabar ini hanya
        | memberi tahu bahwa ada sesuatu yang berubah.
        |
        | Dua kalimat karena dokumen lama bisa saja tidak pernah punya
        | tanggal pembayaran.
        */
        'receipt_reverted' => [
            'title' => 'Penerimaan Realisasi FPU Dibatalkan',
            'with_date' => 'Penerimaan Realisasi FPU :realization_number dibatalkan oleh :actor_name. Jadwal pembayaran :scheduled_date ikut batal. Dokumen kembali ke status disetujui. Alasan: :reason',
            'without_date' => 'Penerimaan Realisasi FPU :realization_number dibatalkan oleh :actor_name. Dokumen kembali ke status disetujui. Alasan: :reason',
        ],
    ],
    'business_trip' => [
        'approval_request' => [
            'title' => 'Perdin Menunggu Persetujuan',
            'message_with_label' => 'Perdin :trip_number menunggu persetujuan Anda pada tahap :step_order (:step_label).',
            'message_without_label' => 'Perdin :trip_number menunggu persetujuan Anda pada tahap :step_order.',
        ],

        'approval_step_pending' => [
            'title' => 'Satu Tahap Perdin Disetujui',
            'message' => 'Perdin :trip_number disetujui :approver_name pada tahap :step_order. Menunggu tahap berikutnya.',
        ],

        'approval_step_final' => [
            'title' => 'Perdin Disetujui',
            'message' => 'Perdin :trip_number telah disetujui seluruhnya oleh :approver_name. Anda dapat melanjutkan mengajukan FPU.',
        ],

        'rejected' => [
            'title' => 'Perdin Ditolak',
            'message' => 'Perdin :trip_number ditolak oleh :approver_name. Catatan: :notes',
        ],

        /*
        | Untuk pihak yang mengurus pemesanan, bukan untuk pemohon.
        | Nada dan isinya berbeda: yang dibutuhkan pembacanya adalah
        | siapa yang berangkat, ke mana, dan kapan.
        */
        'travel_arrangement' => [
            'title' => 'Perdin siap diurus pemesanannya',
            'message' => 'Perdin :trip_number atas nama :employee_name sudah disetujui seluruhnya. Tujuan :destination, berangkat :depart_date.',
        ],
        /*
        | Kabar untuk yang berangkat, satu per pemesanan.
        |
        | Tiga kalimat, bukan satu: dipesan, diganti, dan dibatalkan adalah
        | tiga kabar yang berbeda akibatnya. Kalimat seragam "ada perubahan
        | pemesanan" memaksa pembacanya membuka aplikasi hanya untuk tahu
        | apakah ia perlu berbuat sesuatu.
        */
        'arrangement' => [
            'created' => [
                'title' => 'Pemesanan Perjalanan Sudah Diurus',
                'message' => 'Untuk perdin :trip_number, :type sudah dipesan (:vendor). Rinciannya bisa dilihat di detail perdin.',
            ],

            'replaced' => [
                'title' => 'Pemesanan Pengganti Sudah Diurus',
                'message' => 'Untuk perdin :trip_number, :type sudah dipesan ulang (:vendor) menggantikan pemesanan yang dibatalkan. Gunakan yang terbaru ini.',
            ],

            'cancelled' => [
                'title' => 'Pemesanan Perjalanan Dibatalkan',
                'message' => 'Untuk perdin :trip_number, :type (:vendor) dibatalkan. Alasannya bisa dilihat di detail perdin.',
            ],

            'type_penginapan' => 'penginapan',
            'type_tiket' => 'tiket pesawat',
            'type_transport' => 'transport lokal',
            'type_lainnya' => 'pemesanan lainnya',

            /* Nama penyedianya boleh kosong, kalimatnya tetap harus utuh. */
            'vendor_unset' => 'penyedia belum dicantumkan',
        ],
    ],
];
