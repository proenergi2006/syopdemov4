{{--
    Badan cetakan Perjalanan Dinas.

    Dipanggil dua tempat: berkas cetakan tunggalnya, dan pembungkus
    cetakan gabungan. Blok blok PHP di bawah ikut ke sini, bukan tinggal di
    pembungkusnya -- di sanalah penolongnya didefinisikan, dan tiap
    include punya lingkupnya sendiri sehingga penolong bernama sama
    pada dokumen lain tidak saling menimpa.
--}}
@php
    /*
    |--------------------------------------------------------------------------
    | Cetakan Perjalanan Dinas (Perdin)
    |--------------------------------------------------------------------------
    | Bahasa Indonesia saja -- dokumen ini untuk kebutuhan internal, sama
    | seperti cetakan FPU.
    |
    | Dua halaman:
    |
    |   1. formulir perjalanan beserta kolom tanda tangan
    |   2. daftar perjalanan (itinerary)
    |
    | Itinerary sengaja dipisah. Di halaman pertama ia mendesak blok tanda
    | tangan turun, dan tanda tangan yang terdorong ke halaman berikutnya
    | membuat lembar pertama terlihat belum lengkap.
    |--------------------------------------------------------------------------
    */

    $tanggal = static fn ($value): string =>
        $value ? \Carbon\Carbon::parse($value)->format('d/m/Y') : '-';

    $tanggalJam = static fn ($value): string =>
        $value ? \Carbon\Carbon::parse($value)->format('d/m/Y H:i') : '-';

    /* Nama hari ikut dicetak: formulir aslinya menyebut "Hari & Tanggal". */
    $namaHari = [
        1 => 'Senin', 2 => 'Selasa', 3 => 'Rabu', 4 => 'Kamis',
        5 => 'Jumat', 6 => 'Sabtu', 7 => 'Minggu',
    ];

    $hariTanggal = static function ($value) use ($namaHari, $tanggal): string {
        if (!$value) {
            return '-';
        }

        $d = \Carbon\Carbon::parse($value);

        return ($namaHari[$d->dayOfWeekIso] ?? '') . ', ' . $tanggal($value);
    };

    $jam = static fn ($value): string => $value ? substr((string) $value, 0, 5) : '';

    $cabang = $trip->branchData ?? null;

    $cabangTeks = $cabang
        ? trim(($cabang->inisial_cabang ?? '-') . ' - ' . ($cabang->nama_cabang ?? '-'))
        : '-';

    /*
    | Lebar kolom penyetuju dibagi rata. Dibatasi minimal 1 agar tidak pernah
    | membagi dengan nol saat dokumen belum punya penyetuju sama sekali.
    */
    $jumlahPenyetuju = max($approvers->count(), 1);
    $lebarPenyetuju = 100 / $jumlahPenyetuju;
@endphp

    {{-- ============================================================
         HALAMAN 1 -- FORMULIR
         ============================================================ --}}
    <div class="header">
        <table class="header-table">
            <tr>
                <td style="width: 42%;">
                    <img
                        src="{{ public_path('logo-proenergi.png') }}"
                        class="company-logo"
                        alt="PT Pro Energi"
                    >
                </td>

                <td style="width: 58%;">
                    <div class="document-title">
                        Form Perjalanan Dinas
                    </div>

                    <div class="document-subtitle">
                        Perdin &middot; Perjalanan Dinas
                    </div>

                    <div class="document-number">
                        {{ $trip->trip_number ?? '-' }}
                    </div>
                </td>
            </tr>
        </table>
    </div>

    {{-- IDENTITAS PEMOHON --}}
    <div class="info-card">
        <div class="info-card-title">Identitas Pemohon</div>

        <table class="info-table">
            <tr>
                <td class="info-label">Nama Karyawan</td>
                <td class="info-separator">:</td>
                <td class="info-value">{{ $trip->employee_name ?? '-' }}</td>

                <td class="info-label">Nomor Perdin</td>
                <td class="info-separator">:</td>
                <td class="info-value">{{ $trip->trip_number ?? '-' }}</td>
            </tr>

            <tr>
                <td class="info-label">Departemen</td>
                <td class="info-separator">:</td>
                <td class="info-value">{{ $trip->department_name ?? '-' }}</td>

                <td class="info-label">Tanggal Dokumen</td>
                <td class="info-separator">:</td>
                <td class="info-value">{{ $tanggal($trip->date) }}</td>
            </tr>

            <tr>
                <td class="info-label">Jabatan</td>
                <td class="info-separator">:</td>
                <td class="info-value">{{ $trip->position_name ?? '-' }}</td>

                <td class="info-label">Cabang</td>
                <td class="info-separator">:</td>
                <td class="info-value">{{ $cabangTeks }}</td>
            </tr>
        </table>
    </div>


    {{-- PERIODE PERJALANAN --}}
    <div class="info-card">
        <div class="info-card-title">Periode Perjalanan Dinas</div>

        <table class="info-table info-table--periode">
            <tr>
                <td class="info-label">Tujuan</td>
                <td class="info-separator">:</td>
                <td class="info-value" colspan="4">{{ $trip->destination ?? '-' }}</td>
            </tr>

            <tr>
                <td class="info-label">Hari &amp; Tanggal (berangkat)</td>
                <td class="info-separator">:</td>
                <td class="info-value">{{ $hariTanggal($trip->depart_date) }}</td>

                <td class="info-label info-label-jam">Jam</td>
                <td class="info-separator">:</td>
                <td class="info-value info-value-jam">{{ $jam($trip->depart_time) ?: '-' }}</td>
            </tr>

            <tr>
                <td class="info-label">Hari &amp; Tanggal (kembali)</td>
                <td class="info-separator">:</td>
                <td class="info-value">{{ $hariTanggal($trip->return_date) }}</td>

                <td class="info-label info-label-jam">Jam</td>
                <td class="info-separator">:</td>
                <td class="info-value info-value-jam">{{ $jam($trip->return_time) ?: '-' }}</td>
            </tr>

            <tr>
                <td class="info-label">Lama Perjalanan</td>
                <td class="info-separator">:</td>
                <td class="info-value" colspan="4">
                    {{ $trip->duration_days }} hari
                </td>
            </tr>

            {{-- ========================================================
                 PESERTA LAIN -- RINGKASANNYA

                 Di halaman pertama hanya jumlah dan namanya, sebagai satu
                 baris yang boleh melipat. Tabel berjumlah baris tak tentu
                 di sini akan mendesak blok tanda tangan turun -- pelajaran
                 yang sudah dicatat pada gaya cetakan ini sendiri, waktu
                 itinerary dipindahkan ke halaman kedua karena sebab yang
                 sama. Tabel lengkapnya ada di halaman kedua.

                 Jumlahnya disebut lebih dulu: dari jumlah orang itulah
                 biaya penginapan, tiket, dan transportnya dinilai penyetuju.
                 ======================================================== --}}
            @if ($trip->relationLoaded('participants') && $trip->participants->isNotEmpty())
                <tr>
                    <td class="info-label">Peserta Lain</td>
                    <td class="info-separator">:</td>
                    <td class="info-value" colspan="4">
                        {{ $trip->participants->count() }} orang &mdash;
                        {{ $trip->participants->pluck('employee_name')->filter()->implode(', ') }}
                    </td>
                </tr>
            @endif

            <tr>
                <td class="info-label">Keperluan</td>
                <td class="info-separator">:</td>
                <td class="info-value" colspan="4">{{ $trip->purpose ?? '-' }}</td>
            </tr>

            @if (!empty($trip->notes))
                <tr>
                    <td class="info-label">Catatan</td>
                    <td class="info-separator">:</td>
                    <td class="info-value" colspan="4">{{ $trip->notes }}</td>
                </tr>
            @endif
        </table>
    </div>

    {{-- ============================================================
         TANDA TANGAN
         Dua kolom, persis seperti cetakan FPU: seluruh penyetuju
         digabung di dalam kolom "Disetujui Oleh", berapa pun jumlahnya.
         ============================================================ --}}
    <div class="signature-section">
        <table class="signature-frame">
            <tr>
                <td style="width: 26%;">
                    <div class="signature-heading">Dibuat Oleh</div>

                    <table class="signer-table">
                        <tr>
                            <td>
                                <div class="signer-role">Pemohon</div>

                                <div class="signature-area">
                                    @if (!empty($requester->signature_file) && file_exists($requester->signature_file))
                                        <img
                                            src="{{ $requester->signature_file }}"
                                            class="signature-image"
                                            alt="Tanda tangan"
                                        >
                                    @endif
                                </div>

                                <div class="signer-name">{{ $requester->name ?? '-' }}</div>

                                <div class="signer-date">
                                    {{ $tanggalJam($requester->signed_at ?? null) }}
                                </div>
                            </td>
                        </tr>
                    </table>
                </td>

                <td style="width: 74%;">
                    <div class="signature-heading">Disetujui Oleh</div>

                    <table class="signer-table">
                        <tr>
                            @forelse ($approvers as $approver)
                                <td style="width: {{ $lebarPenyetuju }}%;">
                                    <div class="signer-role">
                                        {{ $approver->label ?? 'Penyetuju' }}
                                    </div>

                                    <div class="signature-area">
                                        @if (!empty($approver->signature_file) && file_exists($approver->signature_file))
                                            <img
                                                src="{{ $approver->signature_file }}"
                                                class="signature-image"
                                                alt="Tanda tangan"
                                            >
                                        @endif
                                    </div>

                                    <div class="signer-name">{{ $approver->name ?? '-' }}</div>

                                    <div class="signer-date">
                                        {{ $tanggalJam($approver->signed_at ?? null) }}
                                    </div>
                                </td>
                            @empty
                                <td class="signer-empty">Belum ada persetujuan.</td>
                            @endforelse
                        </tr>
                    </table>
                </td>
            </tr>
        </table>
    </div>

    <div class="footer">
        Dokumen ini dicetak dari sistem SYOP pada {{ now()->format('d/m/Y H:i') }} WIB.
    </div>

    {{-- ============================================================
         HALAMAN 2 -- DAFTAR PERJALANAN (ITINERARY)
         ============================================================ --}}
    <div class="page-break"></div>

    <div class="header">
        <table class="header-table">
            <tr>
                <td style="width: 42%;">
                    <img
                        src="{{ public_path('logo-proenergi.png') }}"
                        class="company-logo"
                        alt="PT Pro Energi"
                    >
                </td>

                <td style="width: 58%;">
                    <div class="document-title">
                        Daftar Perjalanan
                    </div>

                    <div class="document-subtitle">
                        Itinerary &middot; {{ $trip->employee_name ?? '-' }}
                    </div>

                    <div class="document-number">
                        {{ $trip->trip_number ?? '-' }}
                    </div>
                </td>
            </tr>
        </table>
    </div>

    {{-- ============================================================
         PESERTA LAIN -- DAFTAR LENGKAPNYA

         Di halaman kedua, karena jumlah barisnya tidak tentu dan di
         halaman pertama ia akan mendesak blok tanda tangan turun.
         Departemen dan jabatannya ikut di sini: dua orang bernama mirip
         hanya bisa dibedakan dari keduanya.

         Nama akun pada baris ini adalah SALINAN keadaan saat dokumennya
         dibuat, bukan keadaan orangnya sekarang -- cetakan tahun lalu
         harus tetap berbunyi seperti saat ia dicetak.
         ============================================================ --}}
    @if ($trip->relationLoaded('participants') && $trip->participants->isNotEmpty())
        <div class="info-card-title" style="margin-block-end: 6px;">
            Peserta Lain &middot; {{ $trip->participants->count() }} orang
        </div>

        <table class="itinerary-table" style="margin-block-end: 14px;">
            <thead>
                <tr>
                    <th style="width: 6%;">No</th>
                    <th style="width: 40%;">Nama</th>
                    <th style="width: 27%;">Departemen</th>
                    <th style="width: 27%;">Jabatan</th>
                </tr>
            </thead>

            <tbody>
                @foreach ($trip->participants as $i => $peserta)
                    <tr>
                        <td class="text-center">{{ $i + 1 }}</td>
                        <td>{{ $peserta->employee_name ?: '-' }}</td>
                        <td>{{ $peserta->department_name ?: '-' }}</td>
                        <td>{{ $peserta->position_name ?: '-' }}</td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    @endif

    <table class="itinerary-table">
        <thead>
            <tr>
                <th style="width: 6%;">No</th>
                <th style="width: 20%;">Hari &amp; Tanggal</th>
                <th style="width: 17%;">Jam</th>
                <th style="width: 57%;">Keterangan</th>
            </tr>
        </thead>

        <tbody>
            @forelse ($trip->itineraries as $baris)
                <tr>
                    <td class="text-center">{{ $baris->sort_no }}</td>
                    <td class="nowrap">{{ $hariTanggal($baris->date) }}</td>
                    <td class="nowrap">{{ $baris->time_text }}</td>
                    <td>{{ $baris->description }}</td>
                </tr>
            @empty
                <tr>
                    <td colspan="4" class="itinerary-empty">
                        Belum ada rundown perjalanan.
                    </td>
                </tr>
            @endforelse
        </tbody>
    </table>

    {{--
        Berkas pemesanan GA -- hotel, tiket, transport -- bila pencetaknya
        meminta lampiran digabung. Tanpa permintaan itu variabelnya kosong
        dan partial ini tidak menghasilkan satu halaman pun.
    --}}
    @include("pdf.partials.attachments")

    <div class="footer">
        Dokumen ini dicetak dari sistem SYOP pada {{ now()->format('d/m/Y H:i') }} WIB.
    </div>
