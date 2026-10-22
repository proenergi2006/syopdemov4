{{--
    Badan cetakan Claim.

    Dipanggil dua tempat: berkas cetakan tunggalnya, dan pembungkus
    cetakan gabungan. Blok blok PHP di bawah ikut ke sini, bukan tinggal di
    pembungkusnya -- di sanalah penolongnya didefinisikan, dan tiap
    include punya lingkupnya sendiri sehingga penolong bernama sama
    pada dokumen lain tidak saling menimpa.
--}}
@php
    /*
    |--------------------------------------------------------------------------
    | Cetakan Claim
    |--------------------------------------------------------------------------
    | Bahasa Indonesia saja -- dokumen ini untuk kebutuhan internal.
    |
    | Kolom tanda tangan hanya dua: "Dibuat Oleh" dan "Disetujui Oleh".
    | Bila penyetujunya lebih dari satu orang, semuanya digabung berdampingan
    | di dalam kolom Disetujui.
    |--------------------------------------------------------------------------
    */

    $rupiah = static fn ($value): string =>
        'Rp ' . number_format((float) $value, 0, ',', '.');

    $tanggal = static fn ($value): string =>
        $value ? \Carbon\Carbon::parse($value)->format('d/m/Y') : '-';

    $tanggalJam = static fn ($value): string =>
        $value ? \Carbon\Carbon::parse($value)->format('d/m/Y H:i') : '-';

    $cabang = $claim->branchData
        ? trim(($claim->branchData->inisial_cabang ?? '-') . ' - ' . ($claim->branchData->nama_cabang ?? '-'))
        : '-';

    $department = $claim->departmentData
        ? trim(($claim->departmentData->kode ?? '-') . ' - ' . ($claim->departmentData->nama ?? '-'))
        : '-';

    /*
    | Lebar kolom penyetuju dibagi rata. Dibatasi minimal 1 agar tidak pernah
    | membagi dengan nol saat dokumen belum punya penyetuju sama sekali.
    */
    $jumlahPenyetuju = max($approvers->count(), 1);
    $lebarPenyetuju = 100 / $jumlahPenyetuju;
@endphp

    {{-- ============================================================
         HEADER
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
                        Claim
                    </div>

                    <div class="document-subtitle">
                        Penggantian Biaya &middot; Pengajuan Dana
                    </div>

                    <div class="document-number">
                        {{ $claim->claim_number ?? '-' }}
                    </div>
                </td>
            </tr>
        </table>
    </div>

    {{-- ============================================================
         INFORMASI DOKUMEN
         ============================================================ --}}
    <div class="info-card">
        <div class="info-card-title">Informasi Pengajuan</div>

        <table class="info-table">
            <tr>
                <td class="info-label">Nomor Claim</td>
                <td class="info-separator">:</td>
                <td class="info-value">{{ $claim->claim_number ?? '-' }}</td>

                <td class="info-label">Tanggal</td>
                <td class="info-separator">:</td>
                <td class="info-value">{{ $tanggal($claim->date) }}</td>
            </tr>

            <tr>
                <td class="info-label">Cabang</td>
                <td class="info-separator">:</td>
                <td class="info-value">{{ $cabang }}</td>

                <td class="info-label">Department</td>
                <td class="info-separator">:</td>
                <td class="info-value">{{ $department }}</td>
            </tr>

            <tr>
                <td class="info-label">Keterangan Transaksi</td>
                <td class="info-separator">:</td>
                <td class="info-value">
                    {{-- Seluruhnya, dipisah koma. Aturannya di Claim::transactionCategoryLabel(). --}}
                    {{ $claim->transactionCategoryLabel() }}
                </td>

                <td class="info-label"></td>
                <td class="info-separator"></td>
                <td class="info-value"></td>
            </tr>

            <tr>
                <td class="info-label">Diajukan Oleh</td>
                <td class="info-separator">:</td>
                <td class="info-value">
                    {{ $claim->submitter->name ?? $claim->creator->name ?? '-' }}
                </td>

                <td class="info-label">Status</td>
                <td class="info-separator">:</td>
                <td class="info-value">{{ $claim->status ?? '-' }}</td>
            </tr>

            <tr>
                <td class="info-label">Perihal</td>
                <td class="info-separator">:</td>
                <td class="info-value" colspan="4">{{ $claim->subject ?? '-' }}</td>
            </tr>
        </table>
    </div>

    {{-- ============================================================
         RINCIAN PENGAJUAN
         ============================================================ --}}
    <div class="section-title">Rincian Pengajuan</div>

    <table class="item-table">
        <thead>
            <tr>
                <th class="item-no">No</th>
                <th class="item-date">Tanggal</th>
                <th>Deskripsi</th>
                <th class="item-amount">Nominal</th>
            </tr>
        </thead>

        <tbody>
            @forelse ($claim->items as $index => $item)
                <tr>
                    <td class="item-no">{{ $index + 1 }}</td>
                    <td class="item-date">{{ $tanggal($item->date) }}</td>
                    <td>{{ $item->description }}</td>
                    <td class="item-amount nowrap">{{ $rupiah($item->amount) }}</td>
                </tr>
            @empty
                <tr>
                    <td colspan="4" class="text-center" style="padding: 14px;">
                        Tidak ada rincian pengajuan.
                    </td>
                </tr>
            @endforelse

            <tr class="total-row">
                <td colspan="3" class="text-right">Total Claim</td>
                <td class="item-amount nowrap">{{ $rupiah($totalAmount) }}</td>
            </tr>
        </tbody>
    </table>

    <div class="terbilang-box">
        <span class="terbilang-label">Terbilang:</span> {{ $terbilang }}
    </div>

    @if (!empty($claim->notes))
        <div class="notes-box">
            <div class="notes-label">Catatan</div>
            <div>{{ $claim->notes }}</div>
        </div>
    @endif

    @if (!empty($claim->disbursed_at))
        <div class="notes-box">
            <div class="notes-label">Pencairan</div>
            <div>
                Dibayarkan pada {{ $tanggalJam($claim->disbursed_at) }}
                oleh {{ $claim->disburser->name ?? '-' }}.
                @if (!empty($claim->disbursement_notes))
                    {{ $claim->disbursement_notes }}
                @endif
            </div>
        </div>
    @endif

    {{-- ============================================================
         TANDA TANGAN
         ============================================================
         Dua kolom saja. Seluruh penyetuju digabung di dalam kolom
         "Disetujui Oleh", berapa pun jumlahnya.
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

    {{--
        Lampiran, bila pencetaknya memintanya digabung. Tanpa permintaan
        itu, variabelnya kosong dan partial ini tidak menghasilkan
        satu halaman pun.
    --}}
    @include('pdf.partials.attachments')

