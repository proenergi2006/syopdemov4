{{--
    Badan cetakan Bank Disbursement Voucher.

    Dipanggil dua tempat: berkas cetakan tunggalnya, dan pembungkus
    cetakan gabungan. Blok PHP di bawah ikut ke sini, bukan tinggal di
    pembungkusnya -- di sanalah penolongnya didefinisikan, dan tiap
    include punya lingkupnya sendiri sehingga penolong bernama sama pada
    dokumen lain tidak saling menimpa.
--}}
@php
    /*
    |--------------------------------------------------------------------------
    | Cetakan BDV
    |--------------------------------------------------------------------------
    | DUA BAHASA, menurut $bahasa yang dikirim pemanggilnya ('id' atau 'en').
    | Satu-satunya cetakan yang begitu di aplikasi ini, karena voucher
    | pencairan bank ikut dibaca pihak di luar perusahaan.
    |
    | Terjemahannya diambil dengan trans(..., $bahasa), BUKAN dengan
    | app()->setLocale(). Mengubah locale aplikasi di tengah permintaan akan
    | ikut mengubah pesan lain yang kebetulan dibentuk sesudahnya -- dan
    | cetakan ini menumpangi permintaan yang sama dengan segalanya.
    |
    | Kolom tanda tangannya sama dengan FPU dan Claim: "Dibuat Oleh" berisi
    | pemohonnya, dan seluruh penyetuju digabung di "Disetujui Oleh".
    |--------------------------------------------------------------------------
    */

    $bahasaCetak = in_array($bahasa ?? 'id', ['id', 'en'], true) ? $bahasa : 'id';

    $teks = static fn (string $kunci, array $ganti = []): string =>
        (string) trans('bdv_print.' . $kunci, $ganti, $bahasaCetak);

    $rupiah = static fn ($value): string =>
        'Rp ' . angka((float) $value, 0);

    $tanggal = static fn ($value): string =>
        $value ? \Carbon\Carbon::parse($value)->format('d/m/Y') : '-';

    $tanggalJam = static fn ($value): string =>
        $value ? \Carbon\Carbon::parse($value)->format('d/m/Y H:i') : '-';

    $isiAtauStrip = static fn ($value): string =>
        filled($value) ? (string) $value : '-';

    $cabangVoucher = $bdv->branchData
        ? trim(($bdv->branchData->inisial_cabang ?? '-') . ' - ' . ($bdv->branchData->nama_cabang ?? '-'))
        : '-';

    $departmentVoucher = $bdv->departmentData
        ? trim(($bdv->departmentData->kode ?? '-') . ' - ' . ($bdv->departmentData->nama ?? '-'))
        : '-';

    /*
    | Asal tiap baris: nomor dokumen sumbernya dan cabangnya.
    |
    | DISIAPKAN PEMANGGILNYA, bukan dirangkai di sini. Nama cabang harus
    | dicari ke tabel cabang -- bdv_sources hanya menyimpan id-nya sebagai
    | teks -- dan kueri di dalam blade berarti satu kueri per baris yang
    | dicetak, tersembunyi di tempat yang tidak pernah diperiksa orang.
    |
    | Cabang per baris dibaca dari dokumen SUMBERNYA, bukan dari kepala
    | voucher: satu voucher boleh mencairkan dokumen dari beberapa cabang,
    | dan kepalanya justru dikosongkan bila begitu. Kolom inilah yang
    | dipakai membagi beban ke tiap cabang.
    */
    $cabangSumber = collect($asalBaris ?? []);

    $daftarDokumen = collect($bdv->sources ?? [])
        ->pluck('source_number')
        ->filter()
        ->implode(', ');
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
                        {{ $teks('title') }}
                    </div>

                    <div class="document-subtitle">
                        {{ $teks('subtitle') }}
                    </div>

                    <div class="document-number">
                        {{ $isiAtauStrip($bdv->bdv_number) }}
                    </div>
                </td>
            </tr>
        </table>
    </div>

    {{-- ============================================================
         INFORMASI VOUCHER
         ============================================================ --}}
    <div class="info-card">
        <div class="info-card-title">{{ $teks('info_title') }}</div>

        <table class="info-table">
            <tr>
                <td class="info-label">{{ $teks('number') }}</td>
                <td class="info-separator">:</td>
                <td class="info-value">{{ $isiAtauStrip($bdv->bdv_number) }}</td>

                <td class="info-label">{{ $teks('date') }}</td>
                <td class="info-separator">:</td>
                <td class="info-value">{{ $tanggal($bdv->date) }}</td>
            </tr>

            {{--
                Dibayar Dari dan Dibayarkan Kepada: dua baris pertama pada
                voucher kertasnya, dan dua hal pertama yang diperiksa orang
                bank. Ditaruh setinggi mungkin dengan alasan yang sama.
            --}}
            <tr>
                <td class="info-label">{{ $teks('paid_from') }}</td>
                <td class="info-separator">:</td>
                <td class="info-value">
                    {{ $isiAtauStrip(trim(
                        ($bdv->cash_bank_account_name ?: '')
                        . ($bdv->cash_bank_account_no ? ' (' . $bdv->cash_bank_account_no . ')' : '')
                    )) }}
                </td>

                <td class="info-label">{{ $teks('payee') }}</td>
                <td class="info-separator">:</td>
                <td class="info-value">{{ $isiAtauStrip($bdv->payee) }}</td>
            </tr>

            <tr>
                <td class="info-label">{{ $teks('branch') }}</td>
                <td class="info-separator">:</td>
                <td class="info-value">{{ $cabangVoucher }}</td>

                <td class="info-label">{{ $teks('department') }}</td>
                <td class="info-separator">:</td>
                <td class="info-value">{{ $departmentVoucher }}</td>
            </tr>

            <tr>
                <td class="info-label">{{ $teks('check_number') }}</td>
                <td class="info-separator">:</td>
                <td class="info-value">{{ $isiAtauStrip($bdv->check_number) }}</td>

                <td class="info-label">{{ $teks('status') }}</td>
                <td class="info-separator">:</td>
                <td class="info-value">{{ $isiAtauStrip($bdv->status) }}</td>
            </tr>

            <tr>
                <td class="info-label">{{ $teks('requester') }}</td>
                <td class="info-separator">:</td>
                <td class="info-value">
                    {{ $isiAtauStrip($bdv->submitter->name ?? $bdv->creator->name ?? null) }}
                </td>

                <td class="info-label">{{ $teks('category') }}</td>
                <td class="info-separator">:</td>
                <td class="info-value">
                    {{ $isiAtauStrip($bdv->transactionCategory->name ?? null) }}
                </td>
            </tr>

            {{--
                Nomor dokumen yang dicairkan, seluruhnya dalam satu baris.
                Inilah tali yang menghubungkan voucher ini dengan dokumen
                yang menerangkan untuk apa uangnya keluar.
            --}}
            <tr>
                <td class="info-label">{{ $teks('documents') }}</td>
                <td class="info-separator">:</td>
                <td class="info-value" colspan="4">{{ $isiAtauStrip($daftarDokumen) }}</td>
            </tr>
        </table>
    </div>

    {{-- ============================================================
         RINCIAN PENCAIRAN
         ============================================================ --}}
    <div class="section-title">{{ $teks('detail_title') }}</div>

    <table class="item-table">
        <thead>
            <tr>
                <th class="item-no">{{ $teks('col_no') }}</th>
                <th class="item-account-code">{{ $teks('col_account_code') }}</th>
                <th class="item-account-name">{{ $teks('col_account_name') }}</th>
                <th class="item-branch">{{ $teks('col_branch') }}</th>
                <th>{{ $teks('col_memo') }}</th>
                <th class="item-amount">{{ $teks('col_amount') }}</th>
            </tr>
        </thead>

        <tbody>
            @forelse ($bdv->items as $index => $item)
                @php
                    $asal = $cabangSumber[(int) ($item->bdv_source_id ?? 0)] ?? null;
                @endphp

                <tr>
                    <td class="item-no">{{ $index + 1 }}</td>

                    <td class="item-account-code">
                        {{ $isiAtauStrip($item->accurate_account_no) }}
                    </td>

                    <td class="item-account-name">
                        {{ $isiAtauStrip($item->accurate_account_name) }}
                    </td>

                    <td class="item-branch">
                        {{ $isiAtauStrip($asal['branch'] ?? null) }}
                    </td>

                    <td>
                        {{ $isiAtauStrip($item->description) }}

                        {{--
                            Nomor dokumen asal baris ini, dicetak kecil di
                            bawah memonya. Satu voucher bisa mencairkan
                            beberapa dokumen sekaligus, dan tanpa penanda
                            ini tidak ada cara mengetahui baris mana milik
                            dokumen yang mana.
                        --}}
                        @if (!empty($asal['number']))
                            <span class="item-memo-source">{{ $asal['number'] }}</span>
                        @endif
                    </td>

                    <td class="item-amount nowrap">{{ $rupiah($item->amount) }}</td>
                </tr>
            @empty
                <tr>
                    <td colspan="6" class="text-center" style="padding: 14px;">
                        {{ $teks('empty_items') }}
                    </td>
                </tr>
            @endforelse

            <tr class="total-row">
                <td colspan="5" class="text-right">{{ $teks('total') }}</td>
                <td class="item-amount nowrap">{{ $rupiah($totalAmount) }}</td>
            </tr>
        </tbody>
    </table>

    <div class="terbilang-box">
        <span class="terbilang-label">{{ $teks('say') }}</span> {{ $terbilang }}
    </div>

    @if (!empty($bdv->notes))
        <div class="notes-box">
            <div class="notes-label">{{ $teks('description') }}</div>
            <div>{{ $bdv->notes }}</div>
        </div>
    @endif

    {{-- ============================================================
         TANDA TANGAN
         ============================================================
         Partial bersama FPU, Realisasi, Claim, dan Perdin. Ia memakai
         $requester, $approvers, dan $tanggalJam dari lingkup ini; label
         kolomnya dititipkan lewat $teksTtd karena cetakan ini bisa
         berbahasa Inggris, sedangkan keempat cetakan lain tidak.
         ============================================================ --}}
    @include('pdf.partials.signatures', [
        'teksTtd' => [
            'prepared' => $teks('prepared_by'),
            'requester' => $teks('requester_role'),
            'approved' => $teks('approved_by'),
            'approver' => $teks('approver_role'),
            'empty' => $teks('no_approval'),
        ],
    ])

    <div class="footer">
        {{ $teks('footer', ['waktu' => now()->format('d/m/Y H:i')]) }}
    </div>

    {{--
        Lampiran, bila pencetaknya memintanya digabung DAN dokumen
        sumbernya tidak ikut dicetak. Kalau ikut, lampirannya menempel di
        belakang masing-masing dokumen -- lihat BdvPrintComposer.
    --}}
    @include('pdf.partials.attachments')
