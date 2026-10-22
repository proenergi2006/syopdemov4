<?php

namespace App\Models\Concerns;

use App\Models\FundRequestRevision;
use Illuminate\Database\Eloquent\Relations\MorphMany;

/*
|--------------------------------------------------------------------------
| Dokumen yang bisa dikembalikan untuk direvisi
|--------------------------------------------------------------------------
| Dipakai FPU, Realisasi, Claim, dan Perdin.
|
| Yang disediakan di sini hanya cara MEMBACA dan MEMOTRET. Yang mengubah
| status, menulis riwayatnya, dan membongkar rantai approval-nya adalah
| DocumentRevisionService -- supaya tidak ada dokumen yang bisa berpindah ke
| "Butuh Revisi" lewat jalan belakang tanpa meninggalkan jejak.
|--------------------------------------------------------------------------
*/
trait HasRevisions
{
    /*
    |--------------------------------------------------------------------------
    | Yang TIDAK ikut dipotret
    |--------------------------------------------------------------------------
    | Snapshot ini merekam ISI YANG DIKETIK PEMOHON, bukan pembukuan alur.
    |
    | Kalau pembukuannya ikut, daftar perubahan setiap revisi akan dipenuhi
    | baris seperti "status: IN PROGRESS -> NEED REVISION" dan "submitted_at:
    | ... -> ...", yang tidak pernah menjadi sebab seseorang mengembalikan
    | dokumen. Yang dicari orang -- nominal yang berubah, tanggal yang
    | bergeser, baris yang ditambahkan -- akan tenggelam di antaranya.
    |
    | DIBUANG MENURUT POLA, BUKAN DAFTAR NAMA.
    |
    | Pembukuan di keempat modul selalu berbentuk <peristiwa>_by / _at /
    | _notes. Dengan membuang menurut awalan, kolom pembukuan baru yang
    | menyusul -- dan pasti ada -- tidak perlu diingat siapa pun lagi.
    | Sebaliknya kolom ISI yang baru akan langsung ikut terekam, karena yang
    | tidak dikenali justru DISIMPAN. Untuk riwayat, derau jauh lebih murah
    | daripada diam.
    |--------------------------------------------------------------------------
    */

    /** @var array<int, string> */
    private static array $revisionSkipExact = [
        'id',
        'status',
        'created_by',
        'updated_by',
        'created_at',
        'updated_at',
        'deleted_at',
        'revision_count',
    ];

    /** @var array<int, string> */
    private static array $revisionSkipPrefix = [
        'submitted_',

        /*
        | Seluruh pembukuan tentang pemohonnya: tanda tangannya, dan jabatan
        | yang disalin saat ia mengajukan. Bukan isi yang ia ketik.
        */
        'requester_',

        'final_approved_',

        /*
        | PO memakai approved_at/approved_by, tanpa awalan final_. Modul yang
        | lebih tua menamainya lebih singkat, dan pembukuannya tetap
        | pembukuan.
        */
        'approved_',

        'rejected_',
        'rejection_',
        'cancelled_',
        'cancellation_',

        /* PR dan PO menamai catatan pembatalannya cancel_notes. */
        'cancel_',
        'disbursed_',
        'disbursement_',
        'received_',
        'receipt_',
        'paid_',
        'payment_',
        'settled_',
        'settlement_',
        'verified_',
        'verification_',
        'scheduled_payment_',

        /*
        | Jejak revisi nominal oleh Finance (original_amount,
        | original_total_amount, amount_revision_notes). Ia revisi yang lain,
        | dengan riwayatnya sendiri, dan bukan hasil ketikan pemohon.
        */
        'original_',
        'amount_revision_',
    ];

    public function revisions(): MorphMany
    {
        return $this
            ->morphMany(FundRequestRevision::class, 'document', 'document_type', 'document_id')
            ->orderByDesc('revision_number');
    }

    /**
     * Revisi yang sedang berjalan: sudah dikembalikan, belum diajukan ulang.
     *
     * Inilah yang dibaca layar pemohon untuk menampilkan alasannya. Kosong
     * berarti dokumennya tidak sedang menunggu direvisi -- dan itu harus
     * sejalan dengan statusnya; kalau tidak, salah satu dari keduanya
     * ditulis lewat jalan yang tidak melalui DocumentRevisionService.
     */
    public function openRevision(): ?FundRequestRevision
    {
        return $this->revisions()->open()->first();
    }

    /**
     * Relasi anak yang ikut dipotret.
     *
     * Bisa ditimpa modelnya. Perdin, misalnya, memotret itinerary-nya tetapi
     * TIDAK memotret arrangement: pemesanan tiket dan hotel adalah pekerjaan
     * GA, bukan isi yang direvisi pemohon.
     *
     * @return array<int, string>
     */
    public function revisionSnapshotRelations(): array
    {
        return ['items', 'attachments'];
    }

    /**
     * Kolom yang dibuang khusus modul ini, di luar aturan bersama di atas.
     *
     * Untuk kolom TURUNAN yang namanya tidak mengikuti pola pembukuan mana
     * pun: sisa kuota yang dipakai dokumen lain, status alokasi, jumlah yang
     * sudah diterima. Angka-angka itu bukan ketikan pemohon -- ia berubah
     * karena dokumen LAIN dibuat, dan kalau ikut terekam, daftar perubahan
     * revisi akan menyalahkan pemohon atas hal yang bukan perbuatannya.
     *
     * @return array<int, string>
     */
    public function revisionSnapshotSkip(): array
    {
        return [];
    }

    /**
     * Apa yang MENGENALI satu baris anak, untuk memasangkan potret sebelum
     * dan sesudah.
     *
     * Bawaannya kunci utamanya, dan itu benar untuk modul yang menambal
     * barisnya di tempat: FPU, Realisasi, dan Claim memperbarui baris
     * rincian yang sudah ada, jadi id-nya tetap dari satu penyuntingan ke
     * penyuntingan berikutnya.
     *
     * Perlu ditimpa oleh relasi yang barisnya DITULIS ULANG seluruhnya
     * setiap kali disimpan. Di sana id-nya berganti pada setiap penyimpanan,
     * dan memasangkan menurut id membuat satu baris yang disunting terbaca
     * sebagai "seluruh baris dihapus, seluruh baris ditambah" -- daftar
     * perubahan yang secara teknis benar tetapi tidak menjawab satu pun
     * pertanyaan orang yang membacanya.
     *
     * NULL berarti "tidak ada yang mengenalinya": barisnya dipasangkan
     * menurut URUTANNYA. Itu bukan pengenal yang bisa dipercaya -- menghapus
     * baris pertama menggeser seluruh sisanya -- tetapi tetap lebih
     * menjelaskan daripada menganggap seluruh barisnya baru. Dipakai relasi
     * yang ditulis ulang DAN tidak punya satu kolom pun yang tetap, seperti
     * rincian PR dan PO.
     */
    public function revisionSnapshotRowKey(string $relasi): ?string
    {
        return $this->getKeyName();
    }

    /**
     * Isi dokumen apa adanya, untuk disimpan di riwayat revisinya.
     *
     * @return array{header: array<string, mixed>, relations: array<string, array<int, array<string, mixed>>>}
     */
    public function revisionSnapshot(): array
    {
        $relasi = [];

        foreach ($this->revisionSnapshotRelations() as $nama) {
            if (!method_exists($this, $nama)) {
                continue;
            }

            $kunci = $this->revisionSnapshotRowKey($nama);

            $relasi[$nama] = $this->{$nama}()
                ->get()
                ->map(fn ($baris): array => $this->ringkasBaris($baris, [], $kunci))
                ->all();
        }

        return [
            'header' => $this->ringkasBaris($this, [$this->getKeyName()]),
            'relations' => $relasi,
        ];
    }

    /**
     * Satu baris, tanpa pembukuan alurnya.
     *
     * @param  array<int, string>  $buang  Kolom yang dibuang khusus baris ini
     * @param  string|null  $kunciBaris  Kolom pengenal barisnya, bila ini baris anak
     * @return array<string, mixed>
     */
    private function ringkasBaris(mixed $baris, array $buang = [], ?string $kunciBaris = null): array
    {
        $hasil = [];

        foreach ($baris->getAttributes() as $kolom => $nilai) {
            if (
                in_array($kolom, $buang, true)
                || in_array($kolom, $this->revisionSnapshotSkip(), true)
                || self::kolomPembukuan($kolom)
            ) {
                continue;
            }

            /*
            | Dibaca lewat atribut modelnya, bukan dari nilai mentahnya,
            | supaya tanggal dan desimal tersimpan dalam bentuk yang sama
            | seperti yang dibandingkan nanti. Tanpa ini, "2026-10-21" dan
            | "2026-10-21 00:00:00" akan terbaca sebagai perubahan.
            */
            $hasil[$kolom] = $this->nilaiRata($baris->{$kolom});
        }

        /*
        | Pengenal baris anak tetap dibawa -- bukan sebagai isi, melainkan
        | supaya baris sebelum dan sesudah bisa DIPASANGKAN saat daftar
        | perubahannya dihitung. Tanpa itu, satu nominal yang dibetulkan akan
        | terbaca sebagai satu baris dihapus dan satu baris ditambah.
        |
        | Disimpan dengan nama 'id' walau nilainya belum tentu kunci utamanya:
        | yang dibutuhkan pembandingnya adalah "baris mana ini", dan apa yang
        | menjawab pertanyaan itu berbeda antar relasi -- lihat
        | revisionSnapshotRowKey(). Untuk peserta perdin jawabannya akun
        | orangnya; untuk rundown, urutannya.
        */
        if ($baris !== $this && $kunciBaris !== null) {
            $hasil['id'] = $kunciBaris !== $baris->getKeyName()
                ? $baris->{$kunciBaris}
                : $baris->getKey();
        }

        return $hasil;
    }

    /** @return string|int|float|bool|null */
    private function nilaiRata(mixed $nilai): string|int|float|bool|null
    {
        if ($nilai instanceof \DateTimeInterface) {
            return $nilai->format('Y-m-d H:i:s');
        }

        if (is_object($nilai) || is_array($nilai)) {
            return json_encode($nilai);
        }

        return $nilai;
    }

    private static function kolomPembukuan(string $kolom): bool
    {
        if (in_array($kolom, self::$revisionSkipExact, true)) {
            return true;
        }

        foreach (self::$revisionSkipPrefix as $awalan) {
            if (str_starts_with($kolom, $awalan)) {
                return true;
            }
        }

        return false;
    }
}
