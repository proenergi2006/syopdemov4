<?php

namespace App\Services\FundRequest;

use App\Models\FundRequestRevision;
use Illuminate\Database\Eloquent\Model;

/*
|--------------------------------------------------------------------------
| Mengembalikan dokumen ke pemohon untuk direvisi
|--------------------------------------------------------------------------
| Dipakai FPU, Realisasi, Claim, dan Perdin. Keempatnya dikembalikan dengan
| cara yang sama dan menyimpan riwayat yang sama, jadi aturannya tinggal di
| satu tempat.
|
| DUA PINTU MASUK.
|
| Pertama, approver yang sedang memegang dokumennya (IN PROGRESS). Kedua,
| Finance yang berkasnya baru diterima (RECEIVED) -- dan di situ penerimaannya
| ikut ditarik, karena tanggal pembayaran yang sudah dijanjikan ke pemohon
| tidak boleh tetap menempel pada dokumen yang justru belum beres. Yang sudah
| dicairkan, dibayarkan, atau diselesaikan tidak lewat pintu mana pun:
| statusnya sudah keluar dari RECEIVED, dan pemeriksaan satu baris itu
| sekaligus menutup ketiganya.
|
| RANTAI APPROVAL DIBONGKAR, BUKAN DIBEKUKAN DI TEMPAT.
|
| Dokumennya akan diajukan ulang dari step 1, karena isinya berubah: tanda
| tangan step 1 pada putaran lama adalah persetujuan atas angka yang sudah
| tidak ada lagi. Supaya seluruh pembacaan approval yang sudah berjalan
| (daftar tugas, notifikasi, email, cetakan) tidak perlu tahu apa-apa soal
| revisi, tabel approval sengaja hanya memuat SATU putaran -- yang sedang
| hidup. Rantai putaran lama disalin utuh ke approvals_before lebih dulu,
| lengkap dengan tanda tangan dan catatannya, lalu barisnya dihapus.
|
| Tanda tangan di approval menunjuk ke berkas milik penggunanya sendiri,
| bukan salinan per baris, jadi menghapus barisnya tidak meninggalkan berkas
| yatim.
|--------------------------------------------------------------------------
*/
class DocumentRevisionService
{
    /**
     * Mengembalikan dokumen ke pemohonnya.
     *
     * Dokumennya ikut tersimpan di sini; pemanggilnya hanya perlu memastikan
     * semuanya berada di dalam satu transaksi, dan sudah memastikan bahwa
     * yang mengembalikan memang berhak atas dokumen ini (approver aktifnya,
     * atau Finance yang menerima berkasnya).
     *
     * @param  string|null  $permission  null untuk pintu approver -- lihat keterangan di bawah
     * @param  string  $from  FundRequestRevision::FROM_APPROVAL | FROM_RECEIPT
     * @param  string|null  $approvedStatus  Wajib bila dari FROM_RECEIPT
     * @return array{
     *     ok: bool,
     *     message_key: string|null,
     *     message_replace: array<string, mixed>,
     *     status: int,
     *     revision: FundRequestRevision|null,
     *     scheduled_date: string|null,
     * }
     */
    public function kembalikan(
        Model $document,
        mixed $user,
        ?string $reason,
        ?string $permission,
        string $from,
        ?int $stepOrder = null,
        ?string $stepLabel = null,
        ?string $approvedStatus = null,
    ): array {
        $tolak = static fn (string $kunci, int $kode, array $ganti = []): array => [
            'ok' => false,
            'message_key' => $kunci,
            'message_replace' => $ganti,
            'status' => $kode,
            'revision' => null,
            'scheduled_date' => null,
        ];

        if (!$user) {
            return $tolak('fund_request_messages.return_for_revision.forbidden', 403);
        }

        /*
        | Izinnya hanya diperiksa pada pintu Finance.
        |
        | Pintu approver sengaja tanpa izin, karena di modul ini TIDAK ADA
        | izin "menyetujui": yang menentukan siapa boleh menyetujui adalah
        | rantai approval, dan mengembalikan adalah pilihan ketiga di meja
        | yang sama -- di samping menyetujui dan menolak. Membuat izin baru
        | untuknya justru akan membuat approver yang sah terdiam menunggu
        | admin memberinya izin atas pekerjaan yang sudah jadi tugasnya.
        |
        | Yang memastikan ia memang approver aktif -- dan bukan pemohonnya
        | sendiri -- adalah controller, dengan jalan yang sama seperti
        | tombol Setujui.
        |
        | Pintu Finance lain ceritanya: di sana penerimaan berkas ikut
        | ditarik dan tanggal pembayaran dibatalkan, dan itu pekerjaan yang
        | sudah punya izinnya sendiri.
        */
        if ($permission !== null && !$user->hasPermission($permission)) {
            return $tolak('fund_request_messages.return_for_revision.forbidden', 403);
        }

        /*
        | Statusnya harus sama dengan pintu yang dipakai.
        |
        | Nilai FROM_APPROVAL dan FROM_RECEIPT memang statusnya sendiri, jadi
        | satu perbandingan ini sekaligus menolak dokumen yang sudah dicairkan,
        | dibayarkan, diselesaikan, ditolak, dibatalkan, atau masih draft --
        | tanpa perlu menyebut mereka satu per satu dan tanpa perlu diingat
        | lagi pada hari ada status baru.
        */
        if (strtoupper((string) $document->status) !== $from) {
            return $tolak(
                $from === FundRequestRevision::FROM_RECEIPT
                    ? 'fund_request_messages.return_for_revision.only_received'
                    : 'fund_request_messages.return_for_revision.only_in_progress',
                422,
            );
        }

        $reason = trim((string) $reason);

        /*
        | Alasannya wajib, dan ini bukan sekadar kolom yang diisi.
        |
        | Pemohon yang menerima dokumennya kembali tanpa sepatah kata pun
        | tidak punya cara menebak mana yang salah; yang akan ia lakukan
        | adalah mengajukannya ulang apa adanya, dan dokumennya berputar
        | sekali lagi lewat seluruh rantai approval.
        */
        if (mb_strlen($reason) < 3) {
            return $tolak('fund_request_messages.return_for_revision.reason_required', 422);
        }

        $batas = $this->batasRevisi();
        $sudah = (int) ($document->revision_count ?? 0);

        if ($batas !== null && $sudah >= $batas) {
            return $tolak(
                'fund_request_messages.return_for_revision.limit_reached',
                422,
                ['max' => $batas],
            );
        }

        /*
        | Tidak boleh ada revisi yang masih terbuka.
        |
        | Kalau ada, statusnya seharusnya sudah "Butuh Revisi" dan tidak akan
        | sampai ke sini. Yang menyisakannya berarti ada jalan lain yang
        | menulis status tanpa melalui servis ini, dan itu lebih baik
        | berhenti di sini daripada melahirkan dokumen dengan dua revisi
        | terbuka yang tidak jelas mana yang dibaca pemohon.
        */
        if ($document->openRevision()) {
            return $tolak('fund_request_messages.return_for_revision.already_open', 422);
        }

        $jadwalLama = null;

        /*
        | Finance: penerimaannya ditarik lebih dulu.
        |
        | Memakai servis yang sudah ada, bukan menyalin isinya: ia yang tahu
        | bahwa tanggal pembayaran harus ikut batal, dan itu satu-satunya
        | tempat aturan tersebut tinggal.
        */
        if ($from === FundRequestRevision::FROM_RECEIPT) {
            /*
            | Pintu ini menuntut keduanya: status tujuan penarikannya, dan
            | izin yang mengaturnya. Yang memanggil tanpa salah satunya
            | sedang keliru, dan lebih baik berhenti di sini daripada
            | menarik penerimaan berkas tanpa ada yang memeriksa haknya.
            */
            if ($approvedStatus === null || $permission === null) {
                return $tolak('fund_request_messages.return_for_revision.failed', 500);
            }

            $pembatalan = app(ReceiptReversalService::class)->revert(
                document: $document,
                approvedStatus: $approvedStatus,
                receivedStatus: FundRequestRevision::FROM_RECEIPT,
                user: $user,
                permission: $permission,
                notes: $reason,
            );

            if (!$pembatalan['ok']) {
                return $tolak($pembatalan['message_key'], $pembatalan['status']);
            }

            $jadwalLama = $pembatalan['scheduled_date'];
        }

        /* Dipotret sebelum apa pun berubah. */
        $potret = $document->revisionSnapshot();
        $rantai = $this->arsipkanRantaiApproval($document);

        $revisi = FundRequestRevision::create([
            'document_type' => $document->getMorphClass(),
            'document_id' => $document->getKey(),
            'revision_number' => $sudah + 1,

            'returned_by' => $user->id,
            'returned_at' => now(),
            'returned_from_status' => $from,
            'returned_step_order' => $stepOrder,
            'returned_step_label' => $stepLabel,
            'return_reason' => $reason,

            'snapshot_before' => $potret,
            'approvals_before' => $rantai,
        ]);

        $document->status = $document::STATUS_NEED_REVISION;
        $document->revision_count = $sudah + 1;
        $document->save();

        return [
            'ok' => true,
            'message_key' => null,
            'message_replace' => [],
            'status' => 200,
            'revision' => $revisi,
            'scheduled_date' => $jadwalLama,
        ];
    }

    /**
     * Menandai pemohon sudah mulai mengerjakan revisinya.
     *
     * Dipanggil setiap kali pemohon menyimpan perubahan, tetapi hanya
     * menulis sekali -- yang dicari adalah "sejak kapan dikerjakan", bukan
     * "kapan terakhir disimpan". Tanpa pembatasan sekali itu, approver yang
     * menunggu akan dikabari setiap kali pemohon menekan simpan.
     *
     * @return bool true bila baru saja ditandai (jadi layak dikabarkan)
     */
    public function tandaiDirevisi(Model $document): bool
    {
        $revisi = $document->openRevision();

        if (!$revisi || $revisi->revised_at !== null) {
            return false;
        }

        $revisi->revised_at = now();
        $revisi->save();

        return true;
    }

    /**
     * Menutup revisi yang berjalan, saat dokumennya diajukan ulang.
     *
     * Mengembalikan null bila dokumennya tidak sedang direvisi -- yaitu
     * pengajuan pertama, yang memang tidak menutup apa pun.
     */
    public function tutup(Model $document, mixed $user): ?FundRequestRevision
    {
        $revisi = $document->openRevision();

        if (!$revisi) {
            return null;
        }

        $sesudah = $document->revisionSnapshot();

        $revisi->snapshot_after = $sesudah;
        $revisi->changes = $this->hitungPerubahan($revisi->snapshot_before ?? [], $sesudah);
        $revisi->resubmitted_by = $user?->id;
        $revisi->resubmitted_at = now();

        /*
        | Pemohon yang mengajukan ulang tanpa pernah menekan simpan -- karena
        | yang salah ternyata hanya lampirannya, misalnya -- tetap pernah
        | mengerjakannya.
        */
        if ($revisi->revised_at === null) {
            $revisi->revised_at = now();
        }

        $revisi->save();

        return $revisi;
    }

    /**
     * Apa yang berubah antara dua potret.
     *
     * Hasilnya disimpan sekali saat diajukan ulang, lalu dibaca apa adanya
     * oleh layar. Dihitung ulang setiap kali ditampilkan, ia akan ikut
     * bergeser bersama kode yang menghitungnya -- dan riwayat yang bisa
     * bergeser bukan riwayat.
     *
     * @param  array<string, mixed>  $sebelum
     * @param  array<string, mixed>  $sesudah
     * @return array{
     *     header: array<int, array{field: string, old: mixed, new: mixed}>,
     *     relations: array<string, array{added: array<int, mixed>, removed: array<int, mixed>, changed: array<int, mixed>}>,
     *     total: int,
     * }
     */
    public function hitungPerubahan(array $sebelum, array $sesudah): array
    {
        $header = [];

        $kolom = array_unique(array_merge(
            array_keys($sebelum['header'] ?? []),
            array_keys($sesudah['header'] ?? []),
        ));

        foreach ($kolom as $nama) {
            $lama = $sebelum['header'][$nama] ?? null;
            $baru = $sesudah['header'][$nama] ?? null;

            if ($this->sama($lama, $baru)) {
                continue;
            }

            $header[] = ['field' => $nama, 'old' => $lama, 'new' => $baru];
        }

        $relasi = [];

        $namaRelasi = array_unique(array_merge(
            array_keys($sebelum['relations'] ?? []),
            array_keys($sesudah['relations'] ?? []),
        ));

        foreach ($namaRelasi as $nama) {
            $hasil = $this->bandingkanBaris(
                $sebelum['relations'][$nama] ?? [],
                $sesudah['relations'][$nama] ?? [],
            );

            if ($hasil['added'] === [] && $hasil['removed'] === [] && $hasil['changed'] === []) {
                continue;
            }

            $relasi[$nama] = $hasil;
        }

        $total = count($header);

        foreach ($relasi as $hasil) {
            $total += count($hasil['added']) + count($hasil['removed']) + count($hasil['changed']);
        }

        return [
            'header' => $header,
            'relations' => $relasi,
            'total' => $total,
        ];
    }

    /*
    |--------------------------------------------------------------------------
    | Penyajian
    |--------------------------------------------------------------------------
    | Keempat modul menampilkan riwayat revisi dengan cara yang sama, jadi
    | bentuk kirimannya pun disusun di sini. Nama kolomnya diterjemahkan di
    | server, bukan di layar: satu peta nama untuk empat modul, dan kiriman
    | yang sudah menjelaskan dirinya sendiri.
    |--------------------------------------------------------------------------
    */

    /**
     * Seluruh riwayat revisi sebuah dokumen, terbaru lebih dulu.
     *
     * @return array<int, array<string, mixed>>
     */
    public function riwayat(Model $document): array
    {
        return $document->revisions()
            ->with(['returnedBy:id,name', 'resubmittedBy:id,name'])
            ->get()
            ->map(fn (FundRequestRevision $r): array => $this->sajikan($r))
            ->all();
    }

    /**
     * Satu revisi, siap ditampilkan.
     *
     * @return array<string, mixed>
     */
    public function sajikan(FundRequestRevision $revisi): array
    {
        return [
            'revision_number' => $revisi->revision_number,

            'returned_by' => $revisi->returnedBy?->name,
            'returned_at' => optional($revisi->returned_at)->toIso8601String(),
            'returned_from_status' => $revisi->returned_from_status,
            'returned_from_receipt' => $revisi->is_from_receipt,
            'returned_step_order' => $revisi->returned_step_order,
            'returned_step_label' => $revisi->returned_step_label,
            'return_reason' => $revisi->return_reason,

            'revised_at' => optional($revisi->revised_at)->toIso8601String(),

            'resubmitted_by' => $revisi->resubmittedBy?->name,
            'resubmitted_at' => optional($revisi->resubmitted_at)->toIso8601String(),

            'is_open' => $revisi->is_open,

            'changes' => $this->sajikanPerubahan($revisi->changes ?? []),

            /*
            | Jejak persetujuan putaran itu.
            |
            | Bukan hiasan: inilah satu-satunya tempat tanda tangan yang
            | pernah diberikan orang pada putaran sebelumnya masih tercatat,
            | karena baris approval-nya sudah dibongkar untuk putaran baru.
            */
            'approvals_before' => $revisi->approvals_before ?? [],
        ];
    }

    /**
     * @param  array<string, mixed>  $perubahan
     * @return array<string, mixed>
     */
    private function sajikanPerubahan(array $perubahan): array
    {
        $header = [];

        foreach ($perubahan['header'] ?? [] as $satu) {
            $header[] = [
                'field' => $satu['field'] ?? '',
                'label' => $this->labelKolom((string) ($satu['field'] ?? '')),
                'old' => $satu['old'] ?? null,
                'new' => $satu['new'] ?? null,
            ];
        }

        $relasi = [];

        foreach ($perubahan['relations'] ?? [] as $nama => $isi) {
            $relasi[] = [
                'name' => $nama,
                'label' => $this->labelRelasi((string) $nama),
                'added' => count($isi['added'] ?? []),
                'removed' => count($isi['removed'] ?? []),
                'changed' => array_map(
                    fn (array $baris): array => [
                        'id' => $baris['id'] ?? null,
                        'fields' => array_map(
                            fn (array $f): array => [
                                'field' => $f['field'] ?? '',
                                'label' => $this->labelKolom((string) ($f['field'] ?? '')),
                                'old' => $f['old'] ?? null,
                                'new' => $f['new'] ?? null,
                            ],
                            $baris['fields'] ?? [],
                        ),
                    ],
                    $isi['changed'] ?? [],
                ),
            ];
        }

        return [
            'total' => $perubahan['total'] ?? 0,
            'header' => $header,
            'relations' => $relasi,
        ];
    }

    private function labelKolom(string $kolom): string
    {
        $kunci = "fund_request_messages.revision_fields.{$kolom}";
        $teks = __($kunci);

        /* Yang belum punya nama ditampilkan apa adanya, bukan dihilangkan. */
        return $teks === $kunci ? $kolom : (string) $teks;
    }

    private function labelRelasi(string $nama): string
    {
        $kunci = "fund_request_messages.revision_relations.{$nama}";
        $teks = __($kunci);

        return $teks === $kunci ? $nama : (string) $teks;
    }

    /** Batas jumlah revisi, atau null bila tanpa batas. */
    public function batasRevisi(): ?int
    {
        $batas = config('fund_request.revisions.max');

        if ($batas === null || $batas === '') {
            return null;
        }

        $batas = (int) $batas;

        return $batas > 0 ? $batas : null;
    }

    /*
    |--------------------------------------------------------------------------
    | Bagian dalam
    |--------------------------------------------------------------------------
    */

    /**
     * Menyalin rantai approval yang sedang hidup, lalu menghapus barisnya.
     *
     * @return array<int, array<string, mixed>>
     */
    private function arsipkanRantaiApproval(Model $document): array
    {
        if (!method_exists($document, 'approvals')) {
            return [];
        }

        $baris = $document->approvals()
            ->orderBy('step_order')
            ->orderBy('id')
            ->get();

        $arsip = $baris
            ->map(static fn ($a): array => [
                'step_order' => $a->step_order,
                'label' => $a->label,
                'approver_type' => $a->approver_type,
                'approver_id' => $a->approver_id,
                'approver_name_snapshot' => $a->approver_name_snapshot,
                'approval_mode' => $a->approval_mode,
                'status' => $a->status,
                'signature_path' => $a->signature_path,
                'signed_at' => optional($a->signed_at)->format('Y-m-d H:i:s'),
                'approved_at' => optional($a->approved_at)->format('Y-m-d H:i:s'),
                'rejected_at' => optional($a->rejected_at)->format('Y-m-d H:i:s'),
                'notes' => $a->notes,
            ])
            ->all();

        /*
        | Dihapus memakai kunci barisnya, bukan lewat relasinya.
        |
        | Tabel approval keempat modul tidak memakai soft delete, jadi
        | penghapusan ini sungguhan -- dan itu yang dibutuhkan: generator
        | menolak jalan selama masih ada baris approval untuk dokumen ini.
        */
        if ($baris->isNotEmpty()) {
            $document->approvals()->whereKey($baris->modelKeys())->delete();
        }

        return $arsip;
    }

    /**
     * @param  array<int, array<string, mixed>>  $sebelum
     * @param  array<int, array<string, mixed>>  $sesudah
     * @return array{added: array<int, mixed>, removed: array<int, mixed>, changed: array<int, mixed>}
     */
    private function bandingkanBaris(array $sebelum, array $sesudah): array
    {
        $lama = $this->petakanMenurutId($sebelum);
        $baru = $this->petakanMenurutId($sesudah);

        $ditambah = [];
        $dihapus = [];
        $diubah = [];

        foreach ($baru as $id => $baris) {
            if (!array_key_exists($id, $lama)) {
                $ditambah[] = $baris;

                continue;
            }

            $beda = [];

            $kolom = array_unique(array_merge(array_keys($lama[$id]), array_keys($baris)));

            foreach ($kolom as $nama) {
                if ($nama === 'id') {
                    continue;
                }

                $a = $lama[$id][$nama] ?? null;
                $b = $baris[$nama] ?? null;

                if ($this->sama($a, $b)) {
                    continue;
                }

                $beda[] = ['field' => $nama, 'old' => $a, 'new' => $b];
            }

            if ($beda !== []) {
                $diubah[] = ['id' => $id, 'fields' => $beda];
            }
        }

        foreach ($lama as $id => $baris) {
            if (!array_key_exists($id, $baru)) {
                $dihapus[] = $baris;
            }
        }

        return [
            'added' => $ditambah,
            'removed' => $dihapus,
            'changed' => $diubah,
        ];
    }

    /**
     * @param  array<int, array<string, mixed>>  $baris
     * @return array<int|string, array<string, mixed>>
     */
    private function petakanMenurutId(array $baris): array
    {
        $peta = [];

        foreach ($baris as $i => $satu) {
            /*
            | Baris tanpa id dipasangkan menurut urutannya. Itu bukan
            | pengenal yang bisa dipercaya, tetapi lebih baik daripada
            | menganggap seluruh barisnya baru.
            */
            $kunci = $satu['id'] ?? 'urutan-' . $i;

            $peta[$kunci] = $satu;
        }

        return $peta;
    }

    /**
     * Dua nilai dianggap sama.
     *
     * Longgar dengan sengaja: nominal yang sama bisa datang sebagai
     * "1500000.00" dan 1500000, dan tanggal yang sama bisa datang dengan
     * dan tanpa jam. Yang keras di sini hanya akan melahirkan daftar
     * perubahan yang menyebut hal-hal yang tidak berubah -- dan daftar
     * seperti itu berhenti dibaca orang.
     */
    private function sama(mixed $a, mixed $b): bool
    {
        if ($a === null || $b === null) {
            return $a === $b;
        }

        if (is_numeric($a) && is_numeric($b)) {
            return abs((float) $a - (float) $b) < 0.00001;
        }

        $a = trim((string) $a);
        $b = trim((string) $b);

        /* "2026-10-21 00:00:00" dan "2026-10-21" adalah tanggal yang sama. */
        $potongJam = static fn (string $t): string => preg_replace(
            '/^(\d{4}-\d{2}-\d{2}) 00:00:00$/',
            '$1',
            $t,
        );

        return $potongJam($a) === $potongJam($b);
    }
}
