@php
    /*
    |--------------------------------------------------------------------------
    | Blok tanda tangan cetakan
    |--------------------------------------------------------------------------
    | Dipakai FPU, Realisasi, Claim, dan Perdin. Sebelumnya blok ini disalin
    | utuh ke empat berkas badan dokumen -- 68 baris yang sama persis, empat
    | kali. Disatukan di sini dengan alasan yang sama seperti lampiran: empat
    | salinan yang berawal sama selalu berakhir berbeda.
    |
    | Kolomnya hanya dua, dan itu disengaja: "Dibuat Oleh" berisi tepat satu
    | orang (pemohonnya), sementara seluruh penyetuju digabung di dalam
    | "Disetujui Oleh", berapa pun jumlahnya dan apa pun jabatannya.
    |
    | PENYETUJU DIPECAH KE BAWAH, MAKSIMAL TIGA PER BARIS.
    |
    | Sebelumnya seluruh penyetuju dijejalkan pada satu baris, lebarnya dibagi
    | rata. Dengan lima penyetuju tiap kolom hanya tersisa sekitar 15% lebar
    | halaman, dan jabatan seperti "FINANCE MANAGER" pecah menjadi dua baris
    | dengan satu kata per baris. Alur persetujuan di aplikasi ini bisa
    | berjenjang panjang, jadi lima penyetuju bukan hal yang jarang.
    |
    | Tiga per baris dipilih supaya tiap kolom penyetuju selebar kolom "Dibuat
    | Oleh" di sebelahnya: 25% halaman untuk pemohon, dan 75% dibagi tiga juga
    | menghasilkan 25%. Seluruh halaman terbagi rata empat kolom.
    |--------------------------------------------------------------------------
    */

    $maksPerBaris = 3;

    $daftarPenyetuju = collect($approvers ?? []);

    /*
    | Lebar kolom dihitung dari jumlah penyetuju bila hanya satu baris, dan
    | dari batas tiga bila lebih. Dengan begitu dua penyetuju tetap melebar
    | memenuhi haknya, sementara lima penyetuju tersusun sebagai tiga kolom
    | yang lurus atas-bawah.
    |
    | Dibatasi minimal 1 agar tidak pernah membagi dengan nol saat dokumen
    | belum punya penyetuju sama sekali.
    */
    $jumlahKolom = max(min($daftarPenyetuju->count(), $maksPerBaris), 1);
    $lebarKolom = 100 / $jumlahKolom;

    $barisPenyetuju = $daftarPenyetuju->chunk($maksPerBaris)->values();

    /*
    | Baris terakhir yang tidak penuh diganjal sel kosong, supaya kolomnya
    | tetap lurus dengan baris di atasnya. Tanpa ganjalan, dua penyetuju di
    | baris kedua akan melebar menjadi separuh halaman masing-masing dan tidak
    | lagi sejajar dengan tiga penyetuju di atasnya.
    |
    | Hanya diperlukan bila barisnya memang lebih dari satu.
    */
    $perluGanjal = $barisPenyetuju->count() > 1;

    /*
    | Yang memanggil partial ini wajib menyediakan $requester, $approvers, dan
    | $tanggalJam. Sengaja tanpa nilai cadangan: keempat berkas badan dokumen
    | sudah menyediakan ketiganya, dan bila kelak ada pemanggil baru yang lupa,
    | gagal nyaring saat dicetak lebih baik daripada diam-diam mencetak tanggal
    | mentah pada dokumen keuangan.
    */
@endphp

<style>
    /*
    | Hanya aturan yang lahir bersama pemecahan baris ini. Sisanya -- warna,
    | ukuran huruf, tinggi kotak tanda tangan -- tetap datang dari berkas gaya
    | masing-masing dokumen, yang isinya memang sudah sama.
    */
    .ttd-baris-lanjutan td {
        border-top: 1px solid #e3e9f1;
    }
</style>

<div class="signature-section">
    <table class="signature-frame">
        <tr>
            <td style="width: 25%;">
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

            <td style="width: 75%;">
                <div class="signature-heading">Disetujui Oleh</div>

                <table class="signer-table">
                    @forelse ($barisPenyetuju as $nomorBaris => $isiBaris)
                        <tr class="{{ $nomorBaris > 0 ? 'ttd-baris-lanjutan' : '' }}">
                            @foreach ($isiBaris as $approver)
                                <td style="width: {{ $lebarKolom }}%;">
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
                            @endforeach

                            @if ($perluGanjal)
                                @for ($ganjal = $isiBaris->count(); $ganjal < $maksPerBaris; $ganjal++)
                                    <td style="width: {{ $lebarKolom }}%;"></td>
                                @endfor
                            @endif
                        </tr>
                    @empty
                        <tr>
                            <td class="signer-empty">Belum ada persetujuan.</td>
                        </tr>
                    @endforelse
                </table>
            </td>
        </tr>
    </table>
</div>
