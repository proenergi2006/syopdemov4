{{--
    Badan cetakan FPU.

    Dipanggil dua tempat: berkas cetakan tunggalnya, dan pembungkus
    cetakan gabungan. Blok blok PHP di bawah ikut ke sini, bukan tinggal di
    pembungkusnya -- di sanalah penolongnya didefinisikan, dan tiap
    include punya lingkupnya sendiri sehingga penolong bernama sama
    pada dokumen lain tidak saling menimpa.
--}}
@php
    /*
    |--------------------------------------------------------------------------
    | Cetakan FPU (Form Pengajuan Uang)
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

    $cabang = $fpu->branchData
        ? trim(($fpu->branchData->inisial_cabang ?? '-') . ' - ' . ($fpu->branchData->nama_cabang ?? '-'))
        : '-';

    $department = $fpu->departmentData
        ? trim(($fpu->departmentData->kode ?? '-') . ' - ' . ($fpu->departmentData->nama ?? '-'))
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
                        Form Pengajuan Uang
                    </div>

                    <div class="document-subtitle">
                        FPU &middot; Pengajuan Dana
                    </div>

                    <div class="document-number">
                        {{ $fpu->advance_number ?? '-' }}
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
                <td class="info-label">Nomor FPU</td>
                <td class="info-separator">:</td>
                <td class="info-value">{{ $fpu->advance_number ?? '-' }}</td>

                <td class="info-label">Tanggal</td>
                <td class="info-separator">:</td>
                <td class="info-value">{{ $tanggal($fpu->date) }}</td>
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
                    {{-- Seluruhnya, dipisah koma. Aturannya di CashAdvance::transactionCategoryLabel(). --}}
                    {{ $fpu->transactionCategoryLabel() }}
                </td>

                <td class="info-label">Sifat Pengajuan</td>
                <td class="info-separator">:</td>
                <td class="info-value">{{ $fpu->request_type ?? '-' }}</td>
            </tr>

            <tr>
                <td class="info-label">Diajukan Oleh</td>
                <td class="info-separator">:</td>
                <td class="info-value">
                    {{ $fpu->submitter->name ?? $fpu->creator->name ?? '-' }}
                </td>

                <td class="info-label">Status</td>
                <td class="info-separator">:</td>
                <td class="info-value">{{ $fpu->status ?? '-' }}</td>
            </tr>

            <tr>
                <td class="info-label">Perihal</td>
                <td class="info-separator">:</td>
                <td class="info-value" colspan="4">{{ $fpu->subject ?? '-' }}</td>
            </tr>
        </table>
    </div>

    {{-- ============================================================
         RINCIAN PENGAJUAN
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
    <div class="section-title">Rincian Pengajuan</div>

    {{-- Bentuk perjalanan dinas: dikelompokkan menurut kategori biaya. --}}
    @if ($rincianPerdin !== null)
        <table class="item-table">
            <thead>
                <tr>
                    <th class="item-no">No</th>
                    <th>Item</th>
                    <th class="item-qty">Qty</th>
                    <th class="item-unit">Rincian Biaya</th>
                    <th class="item-amount">Jumlah</th>
                </tr>
            </thead>

            <tbody>
                @forelse ($rincianPerdin as $kelompok)
                    <tr class="cat-row">
                        <td colspan="5">
                            {{ $kelompok['name'] }}

                            @if ($kelompok['arranged'])
                                <span class="cat-ga">Diurus GA</span>
                            @endif
                        </td>
                    </tr>

                    @if ($kelompok['arranged'])
                        <tr class="cat-empty">
                            <td colspan="5">
                                Diurus General Affair, tidak ditagihkan pada FPU ini.
                            </td>
                        </tr>
                    @else
                        @forelse ($kelompok['rows'] as $urut => $item)
                            <tr>
                                <td class="item-no">{{ $urut + 1 }}</td>
                                <td>{{ $item->description }}</td>
                                <td class="item-qty">{{ $angka($item->qty) }}</td>
                                <td class="item-unit nowrap">{{ $rupiah($item->unit_price) }}</td>
                                <td class="item-amount nowrap">{{ $rupiah($item->amount) }}</td>
                            </tr>
                        @empty
                            <tr class="cat-empty">
                                <td colspan="5">Belum ada rincian pada kategori ini.</td>
                            </tr>
                        @endforelse

                        <tr class="cat-subtotal">
                            <td colspan="4" class="text-right">Subtotal {{ $kelompok['name'] }}</td>
                            <td class="item-amount nowrap">{{ $rupiah($kelompok['total']) }}</td>
                        </tr>
                    @endif
                @empty
                    <tr>
                        <td colspan="5" class="text-center" style="padding: 14px;">
                            Tidak ada rincian pengajuan.
                        </td>
                    </tr>
                @endforelse

                <tr class="total-row">
                    <td colspan="4" class="text-right">Total Pengajuan</td>
                    <td class="item-amount nowrap">{{ $rupiah($totalAmount) }}</td>
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
                    <th class="item-amount">Nominal</th>
                </tr>
            </thead>

            <tbody>
                @forelse ($fpu->items as $index => $item)
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
                    <td colspan="3" class="text-right">Total Pengajuan</td>
                    <td class="item-amount nowrap">{{ $rupiah($totalAmount) }}</td>
                </tr>
            </tbody>
        </table>
    @endif

    <div class="terbilang-box">
        <span class="terbilang-label">Terbilang:</span> {{ $terbilang }}
    </div>

    @if (!empty($fpu->notes))
        <div class="notes-box">
            <div class="notes-label">Catatan</div>
            <div>{{ $fpu->notes }}</div>
        </div>
    @endif

    @if (!empty($fpu->disbursed_at))
        <div class="notes-box">
            <div class="notes-label">Pencairan</div>
            <div>
                Dibayarkan pada {{ $tanggalJam($fpu->disbursed_at) }}
                oleh {{ $fpu->disburser->name ?? '-' }}.
                @if (!empty($fpu->disbursement_notes))
                    {{ $fpu->disbursement_notes }}
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

