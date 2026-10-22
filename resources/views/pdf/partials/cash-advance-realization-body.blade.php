{{--
    Badan cetakan Realisasi FPU.

    Dipanggil dua tempat: berkas cetakan tunggalnya, dan pembungkus
    cetakan gabungan. Blok blok PHP di bawah ikut ke sini, bukan tinggal di
    pembungkusnya -- di sanalah penolongnya didefinisikan, dan tiap
    include punya lingkupnya sendiri sehingga penolong bernama sama
    pada dokumen lain tidak saling menimpa.
--}}
@php
    /*
    |--------------------------------------------------------------------------
    | Cetakan Realisasi FPU
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

    $cabang = $realization->branchData
        ? trim(($realization->branchData->inisial_cabang ?? '-') . ' - ' . ($realization->branchData->nama_cabang ?? '-'))
        : '-';

    $department = $realization->departmentData
        ? trim(($realization->departmentData->kode ?? '-') . ' - ' . ($realization->departmentData->nama ?? '-'))
        : '-';

    $selisih = (float) $realization->difference_amount;

    $labelSelisih = match ($realization->difference_type) {
        'RETURN' => 'Sisa Dikembalikan',
        'REIMBURSE' => 'Kekurangan Dibayarkan',
        default => 'Tidak Ada Selisih',
    };

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
                        Realisasi FPU
                    </div>

                    <div class="document-subtitle">
                        Pertanggungjawaban Pengajuan Dana
                    </div>

                    <div class="document-number">
                        {{ $realization->realization_number ?? '-' }}
                    </div>
                </td>
            </tr>
        </table>
    </div>

    {{-- ============================================================
         INFORMASI DOKUMEN
         ============================================================ --}}
    <div class="info-card">
        <div class="info-card-title">Informasi Realisasi</div>

        <table class="info-table">
            <tr>
                <td class="info-label">Nomor Realisasi</td>
                <td class="info-separator">:</td>
                <td class="info-value">{{ $realization->realization_number ?? '-' }}</td>

                <td class="info-label">Tanggal Realisasi</td>
                <td class="info-separator">:</td>
                <td class="info-value">{{ $tanggal($realization->date) }}</td>
            </tr>

            <tr>
                <td class="info-label">Nomor FPU</td>
                <td class="info-separator">:</td>
                <td class="info-value">
                    {{ $realization->cashAdvance->advance_number ?? '-' }}
                </td>

                <td class="info-label">Tanggal FPU</td>
                <td class="info-separator">:</td>
                <td class="info-value">
                    {{ $tanggal($realization->cashAdvance->date ?? null) }}
                </td>
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
                    {{-- Milik FPU sumbernya; realisasi hanya menjawabnya. --}}
                    {{ $realization->cashAdvance?->transactionCategoryLabel()
                        ?? ($realization->transactionCategory->name ?? '-') }}
                </td>

                <td class="info-label">Status</td>
                <td class="info-separator">:</td>
                <td class="info-value">{{ $realization->status ?? '-' }}</td>
            </tr>

            <tr>
                <td class="info-label">Diajukan Oleh</td>
                <td class="info-separator">:</td>
                <td class="info-value">
                    {{ $realization->submitter->name ?? $realization->creator->name ?? '-' }}
                </td>

                <td class="info-label">Sifat Pengajuan</td>
                <td class="info-separator">:</td>
                <td class="info-value">
                    {{ $realization->cashAdvance->request_type ?? '-' }}
                </td>
            </tr>

            <tr>
                <td class="info-label">Perihal</td>
                <td class="info-separator">:</td>
                <td class="info-value" colspan="4">
                    {{ $realization->cashAdvance->subject ?? '-' }}
                </td>
            </tr>
        </table>
    </div>

    {{-- ============================================================
         RINCIAN REALISASI
         ============================================================ --}}
@php
    $angka = function ($nilai): string {
        if ($nilai === null || $nilai === '') {
            return '-';
        }

        $angka = (float) $nilai;

        return rtrim(rtrim(number_format($angka, 2, ',', '.'), '0'), ',');
    };
@endphp
    <div class="section-title">Rincian Realisasi</div>

    {{-- Bentuk perjalanan dinas: dikelompokkan menurut kategori biaya. --}}
    @if ($rincianPerdin !== null)
        <table class="item-table">
            <thead>
                <tr>
                    <th class="item-no">No</th>
                    <th>Item</th>
                    <th class="item-qty">Qty</th>
                    <th class="item-unit">Rincian Biaya</th>
                    <th class="item-amount">Realisasi</th>
                    <th class="item-amount">Pengajuan</th>
                </tr>
            </thead>

            <tbody>
                @forelse ($rincianPerdin as $kelompok)
                    <tr class="cat-row">
                        <td colspan="6">
                            {{ $kelompok['name'] }}

                            @if ($kelompok['arranged'])
                                <span class="cat-ga">Diurus GA</span>
                            @endif
                        </td>
                    </tr>

                    @if ($kelompok['arranged'])
                        <tr class="cat-empty">
                            <td colspan="6">
                                Diurus General Affair, tidak dikeluarkan pemohon.
                            </td>
                        </tr>
                    @else
                        @forelse ($kelompok['rows'] as $urut => $item)
                            <tr>
                                <td class="item-no">{{ $urut + 1 }}</td>

                                <td>
                                    {{ $item->description }}

                                    @unless ($item->cash_advance_item_id)
                                        <span class="item-extra-tag">(di luar rencana)</span>
                                    @endunless

                                    @if (!empty($item->notes))
                                        <div style="color: #7d8999; font-size: 8px;">
                                            {{ $item->notes }}
                                        </div>
                                    @endif
                                </td>

                                <td class="item-qty">{{ $angka($item->qty) }}</td>
                                <td class="item-unit nowrap">{{ $rupiah($item->unit_price) }}</td>
                                <td class="item-amount nowrap">{{ $rupiah($item->realization_amount) }}</td>

                                {{-- Baris di luar rencana tidak punya pembanding: itu bukan nol. --}}
                                <td class="item-amount nowrap">
                                    {{ $item->cash_advance_item_id ? $rupiah($item->advance_amount) : '-' }}
                                </td>
                            </tr>
                        @empty
                            <tr class="cat-empty">
                                <td colspan="6">Belum ada rincian pada kategori ini.</td>
                            </tr>
                        @endforelse

                        <tr class="cat-subtotal">
                            <td colspan="4" class="text-right">Subtotal {{ $kelompok['name'] }}</td>
                            <td class="item-amount nowrap">{{ $rupiah($kelompok['total']) }}</td>
                            <td class="item-amount"></td>
                        </tr>
                    @endif
                @empty
                    <tr>
                        <td colspan="6" class="text-center" style="padding: 14px;">
                            Tidak ada rincian realisasi.
                        </td>
                    </tr>
                @endforelse

                <tr class="total-row">
                    <td colspan="4" class="text-right">Total Realisasi</td>
                    <td class="item-amount nowrap">{{ $rupiah($realization->total_realization_amount) }}</td>
                    <td class="item-amount"></td>
                </tr>
            </tbody>
        </table>
    @else
        <table class="item-table">
            <thead>
                <tr>
                    <th class="item-no">No</th>
                    <th class="item-date">Tanggal</th>
                    <th>Deskripsi</th>
                    <th class="item-amount">Pengajuan</th>
                    <th class="item-amount">Realisasi</th>
                    <th class="item-amount">Selisih</th>
                </tr>
            </thead>

            <tbody>
                @forelse ($realization->items as $index => $item)
                    @php
                        $dariFpu = !empty($item->cash_advance_item_id);
                        $selisihBaris = (float) $item->advance_amount - (float) $item->realization_amount;
                    @endphp

                    <tr>
                        <td class="item-no">{{ $index + 1 }}</td>
                        <td class="item-date">{{ $tanggal($item->date) }}</td>

                        <td>
                            {{ $item->description }}

                            @unless ($dariFpu)
                                <span class="item-extra-tag">(di luar rencana)</span>
                            @endunless

                            @if (!empty($item->notes))
                                <div style="color: #7d8999; font-size: 8px;">
                                    {{ $item->notes }}
                                </div>
                            @endif
                        </td>

                        <td class="item-amount nowrap">
                            {{ $dariFpu ? $rupiah($item->advance_amount) : '-' }}
                        </td>

                        <td class="item-amount nowrap">
                            {{ $rupiah($item->realization_amount) }}
                        </td>

                        <td class="item-amount nowrap">
                            {{ $dariFpu ? $rupiah(abs($selisihBaris)) : '-' }}
                        </td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="6" class="text-center" style="padding: 14px;">
                            Tidak ada rincian realisasi.
                        </td>
                    </tr>
                @endforelse

                <tr class="total-row">
                    <td colspan="4" class="text-right">Total Realisasi</td>
                    <td class="item-amount nowrap">{{ $rupiah($realization->total_realization_amount) }}</td>
                    <td class="item-amount"></td>
                </tr>
            </tbody>
        </table>
    @endif

    {{-- ============================================================
         RINGKASAN SELISIH
         ============================================================ --}}
    <div class="difference-box">
        <table class="difference-table">
            <tr>
                <td class="difference-label">Nilai FPU yang dicairkan</td>
                <td class="difference-value nowrap">
                    {{ $rupiah($realization->total_advance_amount) }}
                </td>
            </tr>

            <tr>
                <td class="difference-label">Total realisasi</td>
                <td class="difference-value nowrap">
                    {{ $rupiah($realization->total_realization_amount) }}
                </td>
            </tr>

            <tr class="difference-final">
                <td class="text-bold">{{ $labelSelisih }}</td>
                <td class="difference-value nowrap">
                    {{ $rupiah(abs($selisih)) }}
                </td>
            </tr>
        </table>
    </div>

    <div class="terbilang-box">
        <span class="terbilang-label">Terbilang total realisasi:</span> {{ $terbilang }}
    </div>

    @if (!empty($realization->notes))
        <div class="notes-box">
            <div class="notes-label">Catatan</div>
            <div>{{ $realization->notes }}</div>
        </div>
    @endif

    @if (!empty($realization->settled_at))
        <div class="notes-box">
            <div class="notes-label">Penyelesaian Selisih</div>
            <div>
                Diselesaikan pada {{ $tanggalJam($realization->settled_at) }}
                oleh {{ $realization->settler->name ?? '-' }}.
                @if (!empty($realization->settlement_notes))
                    {{ $realization->settlement_notes }}
                @endif
            </div>
        </div>
    @endif

    {{-- ============================================================
         TANDA TANGAN
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

