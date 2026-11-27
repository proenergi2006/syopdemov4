<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\BankDisbursementVoucher;
use App\Models\BdvApproval;
use App\Models\BdvItem;
use App\Models\BdvSource;
use App\Models\Cabang;
use App\Models\Department;
use App\Models\FundRequestRevision;
use App\Services\Bdv\BdvAccuratePostingService;
use App\Services\Bdv\BdvApprovalGeneratorService;
use App\Services\Bdv\BdvApprovalService;
use App\Services\Bdv\BdvNotificationService;
use App\Services\Bdv\BdvAttachmentUploadService;
use App\Services\Bdv\BdvPrintComposer;
use App\Services\Bdv\BdvSourceRegistry;
use App\Services\FundRequest\DocumentRevisionService;
use App\Services\FundRequest\RevisionNotifier;
use App\Support\AmountWords;
use App\Support\DocumentActionScope;
use App\Support\ModuleApproverCheck;
use App\Support\UserHomeAccess;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Database\QueryException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\URL;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/*
|--------------------------------------------------------------------------
| Bank Disbursement Voucher
|--------------------------------------------------------------------------
| Tahap ini BARU BAGIAN YANG MEMBACA: daftar beserta penyaringnya, pilihan
| jenis dokumen, pencarian dokumen yang layak diangkat, dan rincian dokumen
| yang dipilih. Menyimpan, mengajukan, dan approval flow menyusul.
|
| Ditulis terpisah seperti itu dengan sengaja, supaya tampilannya bisa
| diperiksa lebih dulu sebelum aturan penyimpanannya dikunci.
|--------------------------------------------------------------------------
*/
class BdvController extends Controller
{
    private const ALLOWED_SCOPES = [
        'NONE',
        'OWN_DATA',
        'OWN_DEPARTMENT',
        'OWN_CABANG',
        'ALL',
    ];

    public function __construct(
        private readonly BdvSourceRegistry $registry,
        private readonly BdvAccuratePostingService $accurate,
        private readonly BdvApprovalGeneratorService $generatorApproval,
        private readonly BdvApprovalService $approvalService,
        private readonly BdvAttachmentUploadService $lampiranAccurate,

        /*
        | Servis yang SAMA dengan FPU, Claim, Realisasi, dan Perdin.
        |
        | Bukan salinan untuk BDV: aturan "potret dulu, rantai approval
        | diarsipkan lalu dibongkar, status pindah, riwayat ditulis" hanya
        | boleh tinggal di satu tempat. Modul yang menyusunnya ulang sendiri
        | adalah modul yang suatu hari lupa mengarsipkan tanda tangannya.
        */
        private readonly DocumentRevisionService $revisi,

        /*
        | Lonceng aplikasi. TIDAK ADA pasangan email-nya, dan itu disengaja
        | -- lihat keterangan di kepala BdvNotificationService.
        */
        private readonly BdvNotificationService $notifikasi,

        /*
        | Kabar seputar revisi dipakai bersama FPU, Claim, Realisasi,
        | Perdin, PR, dan PO. Kalimatnya satu untuk tujuh modul.
        */
        private readonly RevisionNotifier $kabarRevisi,
    ) {
    }

    /*
    |--------------------------------------------------------------------------
    | Daftar
    |--------------------------------------------------------------------------
    */
    public function index(Request $request): JsonResponse
    {
        $user = $request->user();

        if (!$user || !$user->hasPermission('bdv.view')) {
            return response()->json([
                'success' => false,
                'message' => __('bdv_messages.forbidden_view'),
            ], 403);
        }

        $validated = $request->validate([
            'search' => ['nullable', 'string', 'max:150'],
            'status' => ['nullable', 'string', 'max:30'],
            'document_type' => ['nullable', 'string', 'max:20'],
            'branch' => ['nullable', 'string', 'max:30'],
            'department_id' => ['nullable', 'integer'],
            'date_from' => ['nullable', 'date'],
            'date_to' => ['nullable', 'date'],

            /*
             | Saringan "hanya yang menunggu approval saya".
             |
             | Dipisahkan dari penyaring status: menunggu APPROVAL SAYA bukan
             | sekadar berstatus IN PROGRESS. Satu voucher berjalan bisa
             | sedang menunggu orang lain, dan menyamakan keduanya membuat
             | daftar yang menjanjikan pekerjaan yang tombolnya pun tidak
             | muncul.
             */
            'waiting_my_approval' => ['nullable', 'boolean'],

            'per_page' => ['nullable', 'integer', 'min:1', 'max:200'],
            'page' => ['nullable', 'integer', 'min:1'],
        ]);

        $query = BankDisbursementVoucher::query()
            ->with([
                'branchData:id,nama_cabang,inisial_cabang',
                'departmentData:id,kode,nama',
                'creator:id,name',

                /*
                 | Dokumen sumbernya ikut dimuat: daftar menampilkan nomornya,
                 | dan tanpa eager load ini satu halaman 25 baris menjadi 25
                 | query tambahan.
                 */
                'sources',
            ]);

        $this->terapkanBatasAkses($query, $user);

        $cari = trim((string) ($validated['search'] ?? ''));

        if ($cari !== '') {
            $query->where(function ($sub) use ($cari) {
                /*
                 | Nomor dokumen sumber dicari lewat tabel anaknya: satu
                 | voucher bisa memegang beberapa, dan mencarinya di kepala
                 | voucher sudah tidak mungkin sejak kolomnya dibuang.
                 */
                $sub->where('bdv_number', 'ILIKE', "%{$cari}%")
                    ->orWhere('notes', 'ILIKE', "%{$cari}%")
                    ->orWhereHas(
                        'sources',
                        fn ($s) => $s->where('source_number', 'ILIKE', "%{$cari}%"),
                    );
            });
        }

        if (!empty($validated['status'])) {
            $query->whereRaw('UPPER(status) = ?', [strtoupper($validated['status'])]);
        }

        if (!empty($validated['document_type'])) {
            /*
             | Voucher ikut tersaring bila SALAH SATU dokumennya berjenis itu.
             | Voucher campur FPU+Claim memang muncul pada kedua penyaring --
             | dan itu benar: ia memang mencairkan keduanya.
             */
            $query->whereHas(
                'sources',
                fn ($s) => $s->where('document_type', strtoupper($validated['document_type'])),
            );
        }

        if (!empty($validated['branch'])) {
            $query->where('branch', $validated['branch']);
        }

        if (!empty($validated['department_id'])) {
            $query->where('department_id', (int) $validated['department_id']);
        }

        if (!empty($validated['date_from'])) {
            $query->whereDate('date', '>=', $validated['date_from']);
        }

        if (!empty($validated['date_to'])) {
            $query->whereDate('date', '<=', $validated['date_to']);
        }

        /*
        | Disaring di KUERI, bukan sesudah paginate.
        |
        | Menyaringnya sesudah halaman terbentuk akan membuat jumlah halaman
        | dan angka "menampilkan sekian dari sekian" menghitung baris yang
        | justru dibuang.
        */
        if ($request->boolean('waiting_my_approval')) {
            $query->whereHas(
                'approvals',
                fn ($approval) => $this->barisMenungguSaya($approval, $user),
            );
        }

        /*
        | Voucher yang menunggu orang ini selalu di atas, menyala atau tidak
        | saringannya. Hanya memengaruhi URUTAN: tidak ada baris yang
        | bertambah maupun hilang karenanya.
        */
        $query->withExists([
            'approvals as is_waiting_my_approval' => fn ($approval) => $this->barisMenungguSaya(
                $approval,
                $user,
            ),
        ]);

        $halaman = $query
            ->orderByDesc('is_waiting_my_approval')
            ->orderByDesc('id')
            ->paginate((int) ($validated['per_page'] ?? 25));

        return response()->json([
            'success' => true,
            'data' => $halaman->getCollection()
                ->map(fn (BankDisbursementVoucher $bdv): array => $this->bentukBaris($bdv, $user))
                ->values(),

            'meta' => [
                'current_page' => $halaman->currentPage(),
                'last_page' => $halaman->lastPage(),
                'per_page' => $halaman->perPage(),
                'total' => $halaman->total(),
            ],

            'abilities' => [
                'can_view' => true,

                /*
                | Penentu tampil tidaknya saringan "hanya yang menunggu
                | approval saya".
                |
                | Bagi yang tidak pernah menyetujui apa pun di sini --
                | pemohon, GA, kasir -- saringan itu hanya tombol yang selalu
                | mengosongkan daftar. Yang menyalakannya akan mengira
                | datanya hilang, bukan mengira dirinya memang bukan
                | penyetuju.
                */
                'is_approver' => ModuleApproverCheck::isApprover($user, 'bdv_approvals'),

                'can_create' => (bool) $user->hasPermission('bdv.create'),
                'can_update' => (bool) $user->hasPermission('bdv.update'),
                'can_delete' => (bool) $user->hasPermission('bdv.delete'),
                'can_submit' => (bool) $user->hasPermission('bdv.submit'),
            ],
        ]);
    }

    /*
    |--------------------------------------------------------------------------
    | Isi pilihan untuk penyaring dan formulir
    |--------------------------------------------------------------------------
    | Dikirim sekali sebagai satu permintaan, bukan satu permintaan per
    | dropdown: ketiganya selalu dibutuhkan bersamaan saat halaman dibuka.
    |--------------------------------------------------------------------------
    */
    public function options(Request $request): JsonResponse
    {
        $user = $request->user();

        if (!$user || !$user->hasPermission('bdv.view')) {
            return response()->json([
                'success' => false,
                'message' => __('bdv_messages.forbidden_view'),
            ], 403);
        }

        $cabang = Cabang::query()
            ->orderBy('nama_cabang')
            ->get(['id', 'nama_cabang', 'inisial_cabang'])
            ->map(fn (Cabang $c): array => [
                'value' => (string) $c->id,
                'title' => trim(
                    trim((string) $c->inisial_cabang) !== ''
                        ? $c->inisial_cabang . ' - ' . $c->nama_cabang
                        : (string) $c->nama_cabang,
                ),
            ]);

        $department = Department::query()
            ->orderBy('nama')
            ->get(['id', 'kode', 'nama'])
            ->map(fn (Department $d): array => [
                'value' => $d->id,
                'title' => trim(
                    trim((string) $d->kode) !== ''
                        ? $d->kode . ' - ' . $d->nama
                        : (string) $d->nama,
                ),
            ]);

        return response()->json([
            'success' => true,
            'data' => [
                'document_types' => $this->registry->all(),
                'statuses' => BankDisbursementVoucher::SEMUA_STATUS,
                'branches' => $cabang,
                'departments' => $department,

                /*
                | Kode jenis akun Accurate untuk kolom Kas/Bank. Dikirim dari
                | sini supaya kodenya tinggal di config, bukan tertulis di
                | dalam halaman Vue -- bila Accurate kelak memakai kode yang
                | berbeda, yang diubah satu baris config, bukan berkas layar.
                */
                'cash_bank_account_type' => config('bdv.cash_bank_account_type'),
            ],
        ]);
    }

    /*
    |--------------------------------------------------------------------------
    | Dokumen yang layak diangkat menjadi BDV
    |--------------------------------------------------------------------------
    | Dicari dengan mengetik nomornya, bukan diturunkan seluruhnya: dokumen
    | yang layak bisa ratusan, dan daftar sepanjang itu tidak membantu
    | siapa pun menemukan nomor yang ia cari.
    |--------------------------------------------------------------------------
    */
    public function availableDocuments(Request $request): JsonResponse
    {
        $user = $request->user();

        /*
        | Pemegang izin ubah ikut dibolehkan, sama seperti documentDetail.
        | Halaman ubah memilih dokumen dari daftar yang sama, dan orang yang
        | hanya boleh mengubah voucher tidak harus juga boleh menerbitkannya.
        */
        $boleh = $user
            && ($user->hasPermission('bdv.create') || $user->hasPermission('bdv.update'));

        if (!$boleh) {
            return response()->json([
                'success' => false,
                'message' => __('bdv_messages.forbidden_create'),
            ], 403);
        }

        $validated = $request->validate([
            'document_type' => ['required', 'string', 'max:20'],
            'search' => ['nullable', 'string', 'max:150'],

            /* Voucher yang sedang diubah -- lihat bdvUntukPilihan(). */
            'bdv' => ['nullable', 'string', 'max:512'],

            /*
            | Department dan cabang dipilih lebih dulu di layar, dan daftarnya
            | disaring ke keduanya. Bukan kenyamanan belaka: seluruh dokumen
            | dalam satu BDV wajib sedepartment dan secabang, dan menawarkan
            | dokumen di luar itu hanya mengundang penolakan setelah orangnya
            | terlanjur memilih.
            */
            'branch' => ['nullable', 'string', 'max:50'],
            'department_id' => ['nullable', 'integer'],
        ]);

        $kode = strtoupper(trim($validated['document_type']));

        if (!$this->registry->has($kode)) {
            return response()->json([
                'success' => false,
                'message' => __('bdv_messages.unknown_document_type'),
            ], 422);
        }

        $jenis = $this->registry->get($kode);

        $kecuali = $this->bdvUntukPilihan($user, $validated['bdv'] ?? null);

        if ($kecuali instanceof JsonResponse) {
            return $kecuali;
        }

        $query = $this->registry->eligibleQuery($kode, $kecuali);

        /* Dokumen yang boleh dilihat user ini saja, aturannya sama dengan daftar. */
        $this->terapkanBatasAkses($query, $user);

        /*
        | Disaring ke department DAN cabang yang dipilih di layar.
        |
        | Seluruh dokumen dalam satu BDV wajib sedepartment, secabang, dan
        | sejenis. Menawarkan yang di luar itu hanya mengundang penolakan
        | setelah orangnya terlanjur memilih.
        */
        if (!empty($validated['branch'])) {
            $query->where('branch', $validated['branch']);
        }

        if (!empty($validated['department_id'])) {
            $query->where('department_id', (int) $validated['department_id']);
        }

        $cari = trim((string) ($validated['search'] ?? ''));

        if ($cari !== '') {
            $kolomNomor = $jenis['number_column'];

            $query->where(function ($sub) use ($cari, $kolomNomor) {
                $sub->where($kolomNomor, 'ILIKE', "%{$cari}%")
                    ->orWhere('subject', 'ILIKE', "%{$cari}%");
            });
        }

        $dokumen = $query
            ->orderByDesc('id')
            ->limit((int) config('bdv.search_limit', 30))
            ->get();

        return response()->json([
            'success' => true,
            'data' => $dokumen->map(function ($satu) use ($kode): array {
                $ringkas = $this->registry->ringkasan($kode, $satu);

                /* Id asli tidak pernah keluar, sama seperti modul lain. */
                $ringkas['public_id'] = Crypt::encryptString((string) $satu->getKey());

                unset($ringkas['id']);

                return $ringkas;
            })->values(),
        ]);
    }

    /*
    |--------------------------------------------------------------------------
    | Rincian satu dokumen sumber
    |--------------------------------------------------------------------------
    */
    public function documentDetail(Request $request): JsonResponse
    {
        $user = $request->user();

        /*
        | Pemegang izin ubah ikut dibolehkan, bukan hanya pemegang izin buat.
        | Halaman ubah menampilkan rincian dokumen sumber yang sama, dan orang
        | yang hanya boleh mengubah voucher tidak harus juga boleh
        | menerbitkannya.
        */
        $boleh = $user
            && ($user->hasPermission('bdv.create') || $user->hasPermission('bdv.update'));

        if (!$boleh) {
            return response()->json([
                'success' => false,
                'message' => __('bdv_messages.forbidden_create'),
            ], 403);
        }

        $validated = $request->validate([
            'document_type' => ['required', 'string', 'max:20'],
            'public_id' => ['required', 'string', 'max:512'],

            /* Voucher yang sedang diubah -- lihat bdvUntukPilihan(). */
            'bdv' => ['nullable', 'string', 'max:512'],
        ]);

        $kode = strtoupper(trim($validated['document_type']));

        if (!$this->registry->has($kode)) {
            return response()->json([
                'success' => false,
                'message' => __('bdv_messages.unknown_document_type'),
            ], 422);
        }

        try {
            $id = (int) Crypt::decryptString($validated['public_id']);
        } catch (\Throwable $e) {
            return response()->json([
                'success' => false,
                'message' => __('bdv_messages.document_not_found'),
            ], 404);
        }

        $kecuali = $this->bdvUntukPilihan($user, $validated['bdv'] ?? null);

        if ($kecuali instanceof JsonResponse) {
            return $kecuali;
        }

        $query = $this->registry->eligibleQuery($kode, $kecuali);

        $this->terapkanBatasAkses($query, $user);

        $dokumen = $query->find($id);

        if (!$dokumen) {
            /*
            | Tidak dibedakan antara "tidak ada", "sudah dicairkan", dan "sudah
            | diangkat BDV lain": ketiganya sama-sama berarti nomor itu tidak
            | boleh dipakai sekarang, dan membedakannya hanya membocorkan
            | keberadaan dokumen yang tidak boleh dilihat user ini.
            */
            return response()->json([
                'success' => false,
                'message' => __('bdv_messages.document_not_available'),
            ], 404);
        }

        $rincian = $this->registry->rincian($kode, $dokumen);

        $rincian['public_id'] = $validated['public_id'];

        unset($rincian['id']);

        return response()->json([
            'success' => true,
            'data' => $rincian,
        ]);
    }

    /*
    |--------------------------------------------------------------------------
    | Satu BDV, untuk halaman ubah
    |--------------------------------------------------------------------------
    | Dipagari 'bdv.view', bukan 'bdv.update': yang dikerjakan di sini memang
    | membaca. Boleh tidaknya dokumen ini diubah dijawab terpisah lewat
    | can_update, supaya halaman bisa menampilkan isinya sekalipun tombol
    | simpannya tidak berlaku -- lebih berguna daripada menolak membuka sama
    | sekali dan membiarkan orang menebak sebabnya.
    |
    | Batas aksesnya sama persis dengan daftar. Dokumen yang tidak boleh
    | dilihat seseorang dijawab 404, bukan 403: membedakan keduanya sudah
    | cukup untuk memberi tahu bahwa nomor itu ada.
    |--------------------------------------------------------------------------
    */
    public function show(Request $request, string $publicId): JsonResponse
    {
        $user = $request->user();

        if (!$user || !$user->hasPermission('bdv.view')) {
            return response()->json([
                'success' => false,
                'message' => __('bdv_messages.forbidden_view'),
            ], 403);
        }

        $bdv = $this->temukanUntukDilihat($user, $publicId, [
            'branchData:id,nama_cabang,inisial_cabang',
            'departmentData:id,kode,nama',
            'transactionCategory:id,name',
            'creator:id,name',
            'sources.items',
        ]);

        if ($bdv instanceof JsonResponse) {
            return $bdv;
        }

        return response()->json([
            'success' => true,

            'data' => array_merge($this->bentukBaris($bdv, $user), [
                'notes' => $bdv->notes,

                /*
                | SELURUH dokumen sumbernya, lengkap dengan rincian dan
                | pilihan akun tiap barisnya. Dikirim di sini, BUKAN diambil
                | layar lewat document-detail.
                |
                | Sebabnya pernah menjadi bug: document-detail menyaring
                | dengan eligibleQuery, yang justru mengecualikan dokumen
                | yang sudah dipakai BDV. Begitu BDV-nya terbit, dokumen
                | sumbernya sendiri tersaring keluar, dan halaman ubah
                | terbuka dengan rincian kosong tanpa petunjuk sebabnya.
                |
                | Dibaca lewat cari(), yang memang tidak menjaga kelayakan.
                | Yang menjaga di sini adalah BDV-nya: ia sudah lolos
                | batas akses di atas sebelum baris ini jalan.
                */
                'sources' => $this->rincianSeluruhSumber($bdv),

                /*
                | Berapa bukti yang dipegang voucher ini, dan berapa yang
                | sudah ada di Accurate.
                |
                | Dikatakan terang-terangan pada detail, bukan hanya lewat
                | toast saat menyimpan: toast lewat sekali, sedangkan
                | pertanyaan "buktinya sudah masuk belum?" biasanya baru
                | muncul beberapa hari kemudian. Selisih di antara keduanya
                | berarti menyimpan ulang akan mencoba lagi yang tertinggal.
                */
                'attachments' => $this->lampiranAccurate->ringkasan($bdv),

                /*
                | Riwayat revisi, terbaru lebih dulu. Kosong untuk voucher
                | yang belum pernah dikembalikan.
                |
                | Dikirim pada DETAIL saja, tidak pada daftar: ia satu kueri
                | per dokumen, dan daftar 25 baris tidak boleh membayar 25
                | kueri untuk sesuatu yang hanya dibaca saat dibuka.
                */
                'revisions' => $this->revisi->riwayat($bdv),

                /*
                | Revisi yang SEDANG berjalan -- sudah dikembalikan, belum
                | diajukan ulang. Inilah yang dibaca pemohon untuk tahu apa
                | yang harus ia perbaiki, jadi ia dipisahkan dari riwayat
                | supaya layar tidak perlu mencarinya sendiri.
                */
                'open_revision' => ($terbuka = $bdv->openRevision())
                    ? $this->revisi->sajikan($terbuka)
                    : null,

                /*
                | Riwayat approval. Kosong selama vouchernya masih draft --
                | rantainya memang baru dibuat saat diajukan. Dikirim sebagai
                | array kosong, bukan dihilangkan, supaya layar tidak perlu
                | membedakan "belum ada" dari "tidak dikirim".
                */
                'approvals' => $this->riwayatApproval($bdv),

                'transaction_category' => $bdv->transactionCategory->name ?? null,

                /*
                | Isian voucher dikirim utuh supaya halaman ubah bisa
                | memulihkan formulirnya apa adanya -- termasuk kas/bank,
                | yang berupa kolom pencarian ke Accurate.
                |
                | Nama akunnya ikut dikirim, bukan hanya nomornya: kolom
                | pencarian itu baru berisi daftar sesudah orangnya mengetik,
                | dan tanpa nama yang sudah tersimpan kolomnya akan tampak
                | kosong padahal pilihannya ada.
                */
                'cash_bank_account_no' => $bdv->cash_bank_account_no,
                'cash_bank_account_name' => $bdv->cash_bank_account_name,
                'check_number' => $bdv->check_number,
                'payee' => $bdv->payee,

                /*
                | can_update dan can_delete TIDAK diulang di sini. Keduanya
                | sudah dihitung bentukBaris() dengan scope aksinya
                | masing-masing, dan menulisnya lagi di array_merge ini akan
                | menimpa hasil itu -- persis kekeliruan yang pernah ada di
                | sini, di mana izin diperiksa tanpa scope sehingga pemegang
                | bdv.update bercakupan OWN_DATA tetap ditawari mengubah
                | dokumen orang lain.
                */
            ]),
        ]);
    }

    /*
    |--------------------------------------------------------------------------
    | Rincian seluruh dokumen sumber sebuah voucher
    |--------------------------------------------------------------------------
    | Tiap dokumen dikirim lengkap: rinciannya dari dokumen aslinya, plus
    | pilihan akun tiap baris dari voucher ini. Layar mengelompokkan rincian
    | item menurut dokumennya dari sini.
    |
    | Dokumen yang tidak bisa dibaca lagi -- sudah terhapus, atau jenisnya
    | tidak dikenal -- tidak menjatuhkan halaman. Yang dikirim untuknya hanya
    | identitas bekunya, dengan detail null. Halaman yang menolak terbuka
    | tanpa menyebut sebab jauh lebih buruk daripada satu bagian yang kosong.
    |
    | @return array<int, array<string, mixed>>
    |--------------------------------------------------------------------------
    */
    private function rincianSeluruhSumber(BankDisbursementVoucher $bdv): array
    {
        return $bdv->sources
            ->map(function (BdvSource $sumber): array {
                $akun = $sumber->items
                    ->mapWithKeys(fn (BdvItem $i): array => [
                        (int) $i->line_no => [
                            'line_no' => (int) $i->line_no,
                            'accurate_account_no' => $i->accurate_account_no,
                            'accurate_account_name' => $i->accurate_account_name,
                        ],
                    ])
                    ->all();

                return [
                    /*
                    | Terenkripsi, supaya layar bisa mengirimkannya kembali
                    | saat menyimpan tanpa pernah memegang id aslinya.
                    */
                    'public_id' => Crypt::encryptString((string) $sumber->source_id),

                    'document_type' => $sumber->document_type,
                    'document_type_label' => $this->registry->has((string) $sumber->document_type)
                        ? $this->registry->label((string) $sumber->document_type)
                        : $sumber->document_type,

                    'source_number' => $sumber->source_number,
                    'total_amount' => (float) $sumber->total_amount,

                    /* Rincian hidup dari dokumen aslinya; null bila tak terbaca. */
                    'detail' => $this->rincianSatuSumber($sumber),

                    /* Pilihan akun milik voucher ini, dikunci menurut nomor baris. */
                    'items' => array_values($akun),
                ];
            })
            ->values()
            ->all();
    }

    /**
     * Rincian satu dokumen sumber, dibaca dari dokumen aslinya.
     *
     * Dikembalikan null, bukan melempar: halaman BDV tetap harus terbuka
     * sekalipun dokumen sumbernya sudah terhapus atau jenisnya sudah tidak
     * dikenal lagi.
     *
     * @return array<string, mixed>|null
     */
    private function rincianSatuSumber(BdvSource $sumber): ?array
    {
        if (!$this->registry->has((string) $sumber->document_type)) {
            return null;
        }

        try {
            $dokumen = $this->registry->cari(
                (string) $sumber->document_type,
                (int) $sumber->source_id,
            );

            if (!$dokumen) {
                return null;
            }

            $rincian = $this->registry->rincian((string) $sumber->document_type, $dokumen);

            /* Id asli tidak pernah meninggalkan server. */
            unset($rincian['id']);

            return $rincian;
        } catch (\Throwable $e) {
            Log::warning('[BDV] Rincian dokumen sumber gagal dibaca', [
                'bdv_source_id' => $sumber->id,
                'document_type' => $sumber->document_type,
                'source_id' => $sumber->source_id,
                'message' => $e->getMessage(),
            ]);

            return null;
        }
    }

    /*
    |--------------------------------------------------------------------------
    | Menyimpan BDV baru
    |--------------------------------------------------------------------------
    | SELALU LAHIR SEBAGAI DRAFT. Tidak ada jalan pintas "simpan lalu langsung
    | ajukan" di sini -- pengajuan adalah tindakan tersendiri yang nanti punya
    | pintunya sendiri, lengkap dengan pemeriksaan kelengkapan yang lebih
    | ketat daripada pemeriksaan menyimpan.
    |
    | NOMOR BDV DIKETIK, bukan diterbitkan sistem. Keunikannya dijaga indeks
    | unik di basis data, bukan hanya pemeriksaan di sini: dua orang yang
    | menekan Simpan pada saat hampir bersamaan dengan nomor yang sama akan
    | lolos dari pemeriksaan kode, tetapi tidak dari indeksnya.
    |
    | RINCIANNYA TIDAK DIPERCAYAKAN KE LAYAR.
    |
    | Yang diterima dari layar hanya pilihan akun per baris. Tanggal, uraian,
    | kategori, dan nominalnya dibaca ulang dari dokumen sumbernya di sini.
    | Alasannya sederhana: nominal adalah uang, dan uang yang dikirim layar
    | bisa disunting siapa pun yang bisa membuka alat pengembang peramban.
    |--------------------------------------------------------------------------
    */
    public function store(Request $request): JsonResponse
    {
        $user = $request->user();

        if (!$user || !$user->hasPermission('bdv.create')) {
            return response()->json([
                'success' => false,
                'message' => __('bdv_messages.forbidden_create'),
            ], 403);
        }

        $validated = $request->validate($this->aturanIsian(), $this->pesanIsian());

        $kumpulan = $this->kumpulkanSumber($validated['sources'], $user);

        if ($kumpulan instanceof JsonResponse) {
            return $kumpulan;
        }

        try {
            $bdv = DB::transaction(function () use ($validated, $kumpulan, $user) {
                $bdv = BankDisbursementVoucher::create(
                    $this->isianVoucher($validated) + $kumpulan['kepala'] + [
                        'status' => BankDisbursementVoucher::STATUS_DRAFT,
                        'created_by' => $user->id,
                    ],
                );

                $this->sinkronkanSumber($bdv, $kumpulan['sumber']);

                return $bdv;
            });
        } catch (QueryException $e) {
            return $this->jawabBentrok($e);
        }

        return $this->jawabSesudahSimpan($bdv, __('bdv_messages.created'), 201, $user);
    }

    /*
    |--------------------------------------------------------------------------
    | Mengubah BDV
    |--------------------------------------------------------------------------
    | Hanya draft. Begitu diajukan, isinya sudah menjadi dasar orang lain
    | menyetujui -- menggesernya diam-diam membuat persetujuan itu menyetujui
    | sesuatu yang lain.
    |
    | DAFTAR DOKUMEN SUMBERNYA IKUT BISA DIUBAH. Dokumen boleh ditambahkan
    | atau dikeluarkan selama vouchernya masih draft; yang dikeluarkan segera
    | bebas diangkat voucher lain, dan rinciannya dibereskan seperti baris
    | yang hilang -- lihat sinkronkanSumber().
    |
    | RINCIANNYA DIBACA ULANG DARI DOKUMEN SUMBER, bukan dipertahankan apa
    | adanya. Nilai dokumen masih bisa direvisi Finance selama BDV-nya belum
    | diajukan, dan draft yang memperlihatkan angka lama hanya menunggu
    | ditandatangani dengan jumlah yang sudah tidak berlaku. Pilihan akun yang
    | sudah dibuat tetap dipertahankan menurut nomor barisnya.
    |--------------------------------------------------------------------------
    */
    public function update(Request $request, string $publicId): JsonResponse
    {
        $user = $request->user();

        if (!$user || !$user->hasPermission('bdv.update')) {
            return response()->json([
                'success' => false,
                'message' => __('bdv_messages.forbidden_update'),
            ], 403);
        }

        $bdv = $this->temukanUntukDilihat($user, $publicId);

        if ($bdv instanceof JsonResponse) {
            return $bdv;
        }

        /*
        | Dibaca di dalam transaksi, dipakai sesudahnya. Bernilai true hanya
        | pada penyimpanan PERTAMA sesudah voucher dikembalikan -- yang
        | berikutnya tidak mengabari lagi, karena yang dicari approver yang
        | menunggu adalah "sejak kapan dikerjakan", bukan "kapan terakhir
        | disimpan".
        */
        $mulaiDirevisi = false;

        /*
        | Scope AKSI INI, bukan scope view.
        |
        | temukanUntukDilihat di atas memakai scope bdv.view -- gunanya hanya
        | menentukan dokumen mana yang boleh DILIHAT orang ini. Tanpa
        | pemeriksaan berikut, "bdv.view: ALL, bdv.update: OWN_DATA" tidak
        | berarti apa-apa: dokumennya tetap terambil dan tetap bisa diubah.
        |
        | Dijawab 404, sama seperti dokumen yang memang tidak ada. Membedakan
        | 403 dari 404 di sini akan memberi tahu bahwa nomor itu ada kepada
        | orang yang tidak berhak menyentuhnya.
        */
        if (!DocumentActionScope::allows($user, 'bdv.update', $bdv, self::KOLOM_SCOPE)) {
            return response()->json([
                'success' => false,
                'message' => __('bdv_messages.not_found'),
            ], 404);
        }

        /* Aturannya di masihBisaDiketik() -- jangan ditulis ulang di sini. */
        if (!$this->masihBisaDiketik($bdv)) {
            return response()->json([
                'success' => false,
                'message' => __('bdv_messages.not_editable'),
            ], 422);
        }

        $validated = $request->validate(
            $this->aturanIsian($bdv->id),
            $this->pesanIsian(),
        );

        /*
        | Dokumen yang SUDAH terikat voucher ini dikecualikan dari pemeriksaan
        | kelayakan. Ia memang sudah tidak "layak diangkat" -- justru karena
        | sudah diangkat voucher ini -- dan tanpa pengecualian itu, menyimpan
        | ulang tanpa menyentuh daftar dokumennya pun akan ditolak.
        */
        $sudahMilikVoucher = $bdv->sources()
            ->get()
            ->map(fn (BdvSource $s): string => $s->document_type . ':' . $s->source_id)
            ->all();

        $kumpulan = $this->kumpulkanSumber($validated['sources'], $user, $sudahMilikVoucher);

        if ($kumpulan instanceof JsonResponse) {
            return $kumpulan;
        }

        try {
            DB::transaction(function () use ($bdv, $validated, $kumpulan, $user, &$mulaiDirevisi): void {
                $bdv->update(
                    $this->isianVoucher($validated) + $kumpulan['kepala'] + [
                        'updated_by' => $user->id,
                    ],
                );

                $this->sinkronkanSumber($bdv, $kumpulan['sumber']);

                /*
                | Menandai revisinya sudah MULAI dikerjakan.
                |
                | Hanya menulis sekali -- yang dicari approver yang menunggu
                | adalah "sejak kapan dikerjakan", bukan "kapan terakhir
                | disimpan". Tidak berbuat apa-apa pada voucher yang memang
                | tidak sedang direvisi, jadi tidak perlu dijaga if.
                */
                $mulaiDirevisi = $this->revisi->tandaiDirevisi($bdv);
            });
        } catch (QueryException $e) {
            return $this->jawabBentrok($e);
        }

        /*
        | Di LUAR transaksi: perubahannya sudah tersimpan, dan kabar yang
        | gagal tidak boleh menariknya kembali.
        */
        if ($mulaiDirevisi) {
            try {
                $berjalan = $bdv->openRevision();

                if ($berjalan) {
                    $this->kabarRevisi->sedangDirevisi($bdv, $berjalan);
                }
            } catch (\Throwable $e) {
                Log::error('[BDV] Notifikasi "sedang direvisi" gagal', [
                    'bdv_id' => $bdv->id,
                    'message' => $e->getMessage(),
                ]);
            }
        }

        return $this->jawabSesudahSimpan($bdv, __('bdv_messages.updated'), 200, $user);
    }

    /*
    |--------------------------------------------------------------------------
    | Menghapus BDV
    |--------------------------------------------------------------------------
    | Hanya draft, dengan alasan yang sama seperti mengubah.
    |
    | ACCURATE DIHAPUS LEBIH DULU, DAN URUTANNYA KEBALIKAN DARI MENYIMPAN.
    |
    | Saat menyimpan, lokal dikerjakan dulu supaya kegagalan tidak pernah
    | meninggalkan dokumen yatim di Accurate. Saat menghapus, bahayanya
    | terbalik: menghapus lokal lebih dulu berarti membuang accurate_id --
    | satu-satunya cara menemukan dokumen itu lagi. Kalau Accurate kemudian
    | menolak, dokumennya tinggal di pembukuan tanpa pernah bisa dilacak
    | kembali ke sini.
    |
    | Karena itu penghapusan di Accurate yang gagal MEMBATALKAN penghapusan
    | lokal -- berbeda dari menyimpan, di mana kegagalan Accurate dibiarkan
    | lewat sebagai peringatan. Di sini tidak ada "coba lagi nanti" yang aman:
    | begitu barisnya hilang, tidak ada lagi yang bisa mencoba.
    |
    | SOFT DELETE, bukan penghapusan sungguhan. Modelnya memakai SoftDeletes,
    | jadi jejaknya tetap ada untuk ditelusuri -- sementara dokumen sumbernya
    | langsung bisa diangkat BDV lain, karena eligibleQuery hanya melihat
    | baris yang belum terhapus.
    |--------------------------------------------------------------------------
    */
    public function destroy(Request $request, string $publicId): JsonResponse
    {
        $user = $request->user();

        if (!$user || !$user->hasPermission('bdv.delete')) {
            return response()->json([
                'success' => false,
                'message' => __('bdv_messages.forbidden_delete'),
            ], 403);
        }

        try {
            $id = (int) Crypt::decryptString($publicId);
        } catch (\Throwable $e) {
            return response()->json([
                'success' => false,
                'message' => __('bdv_messages.not_found'),
            ], 404);
        }

        $query = BankDisbursementVoucher::query();

        $this->terapkanBatasAkses($query, $user);

        $bdv = $query->find($id);

        if (!$bdv) {
            return response()->json([
                'success' => false,
                'message' => __('bdv_messages.not_found'),
            ], 404);
        }

        /*
        | Scope bdv.delete, bukan scope bdv.view -- lihat keterangan yang
        | sama pada update(). Di sini taruhannya lebih besar: yang terjadi
        | bukan hanya baris di aplikasi ini, melainkan dokumen di pembukuan
        | Accurate milik orang lain.
        */
        if (!DocumentActionScope::allows($user, 'bdv.delete', $bdv, self::KOLOM_SCOPE)) {
            return response()->json([
                'success' => false,
                'message' => __('bdv_messages.not_found'),
            ], 404);
        }

        if (strtoupper(trim((string) $bdv->status)) !== BankDisbursementVoucher::STATUS_DRAFT) {
            return response()->json([
                'success' => false,
                'message' => __('bdv_messages.delete_draft_only'),
            ], 422);
        }

        /*
        | Di luar transaksi, dan sebelum apa pun disentuh di sini. Panggilan
        | jaringan di dalam transaksi menahan kunci baris selama Accurate
        | berpikir -- dan Accurate bisa berpikir lama.
        */
        try {
            $this->accurate->hapus($bdv);
        } catch (\Throwable $e) {
            return response()->json([
                'success' => false,
                'message' => __('bdv_messages.delete_accurate_failed', [
                    'reason' => $e->getMessage(),
                ]),
            ], 422);
        }

        try {
            DB::transaction(function () use ($bdv, $user): void {
                /*
                | Rinciannya dibuang sungguhan, bukan ditandai. Ia tidak punya
                | arti apa pun tanpa vouchernya, dan nisannya (removed_at)
                | memang hanya untuk baris yang masih menunggu dihapus di
                | Accurate -- yang sekarang sudah tidak ada lagi di sana.
                */
                BdvItem::where('bdv_id', $bdv->id)->delete();

                /*
                | Dokumen sumbernya DILEPAS, bukan dihapus.
                |
                | Tanpa ini dokumennya terkunci selamanya: indeks unik yang
                | menjaga "satu dokumen, satu voucher berlaku" membacanya dari
                | released_at, bukan dari hidup-matinya voucher -- status dan
                | deleted_at ada di tabel induk, dan indeks parsial tidak bisa
                | membaca lintas tabel.
                |
                | Barisnya sendiri ditinggalkan sebagai jejak: voucher yang
                | dihapus tetap bisa ditelusuri lengkap dengan dokumen apa
                | saja yang dulu hendak dicairkannya.
                */
                BdvSource::where('bdv_id', $bdv->id)
                    ->whereNull('released_at')
                    ->update(['released_at' => now()]);

                $bdv->forceFill(['updated_by' => $user->id])->save();

                $bdv->delete();
            });
        } catch (\Throwable $e) {
            /*
            | Keadaan yang paling pahit di sini: dokumennya SUDAH hilang dari
            | Accurate, tetapi barisnya masih berdiri di sini dengan
            | accurate_id yang sudah tidak menunjuk apa pun. Menyimpannya lagi
            | akan menerbitkan dokumen baru, bukan memperbarui yang lama.
            |
            | Dicatat sebagai critical, dan disampaikan apa adanya: yang
            | membacanya perlu tahu bahwa mencoba menghapus lagi itu aman,
            | sedangkan menekan Simpan tidak.
            */
            Log::critical('[BDV] Terhapus di Accurate tetapi gagal dihapus lokal', [
                'bdv_id' => $bdv->id,
                'bdv_number' => $bdv->bdv_number,
                'accurate_id' => $bdv->accurate_id,
                'message' => $e->getMessage(),
            ]);

            return response()->json([
                'success' => false,
                'message' => __('bdv_messages.delete_local_failed'),
            ], 500);
        }

        Log::info('[BDV] Dihapus', [
            'bdv_id' => $bdv->id,
            'bdv_number' => $bdv->bdv_number,
            'oleh' => $user->id,
        ]);

        return response()->json([
            'success' => true,
            'message' => __('bdv_messages.deleted'),
        ]);
    }

    /*
    |--------------------------------------------------------------------------
    | Mengajukan
    |--------------------------------------------------------------------------
    | Draft dan dokumen revisian masuk lewat pintu yang sama, dan itu
    | disengaja: yang terjadi sesudahnya memang sama persis -- rantai approval
    | dibuat, statusnya menjadi berjalan. Dua pintu terpisah berarti dua
    | salinan aturan pengajuan, dan yang berbeda di antara keduanya adalah
    | uang orang.
    |
    | SYARAT TAMBAHAN YANG TIDAK ADA DI MODUL LAIN: dokumennya harus sudah
    | masuk Accurate.
    |
    | BDV ditulis ke Accurate sejak draft pertama disimpan. Kalau pengiriman
    | itu pernah gagal, accurate_id kosong, dan angka di sini belum tentu sama
    | dengan angka di pembukuan. Mengajukannya dalam keadaan itu berarti
    | meminta orang menyetujui sesuatu yang belum tercatat di mana pun --
    | dan persetujuannya baru ketahuan tidak berdasar berminggu-minggu
    | kemudian, saat seseorang mencocokkan kedua sistem.
    |--------------------------------------------------------------------------
    */
    public function submit(Request $request, string $publicId): JsonResponse
    {
        $user = $request->user();

        if (!$user || !$user->hasPermission('bdv.submit')) {
            return response()->json([
                'success' => false,
                'message' => __('bdv_messages.forbidden_submit'),
            ], 403);
        }

        $bdv = $this->temukanUntukAksi($request, $user, 'bdv.submit', $publicId);

        if ($bdv instanceof JsonResponse) {
            return $bdv;
        }

        /* Aturannya di masihBisaDiketik() -- jangan ditulis ulang di sini. */
        if (!$this->masihBisaDiketik($bdv)) {
            return response()->json([
                'success' => false,
                'message' => __('bdv_messages.submit_draft_only'),
            ], 422);
        }

        /*
        | Dua keadaan, dua pesan -- dan membedakannya penting.
        |
        | accurate_id kosong berarti dokumennya belum pernah ada di sana sama
        | sekali. accurate_in_sync false berarti dokumennya ADA, tetapi isinya
        | di sana bukan isi yang sekarang: pengiriman terakhir ditolak.
        |
        | Keduanya sama-sama menahan pengajuan, sebab yang akan disetujui
        | orang adalah angka yang berbeda dari pembukuan. Tetapi yang kedua
        | jauh lebih mudah luput: vouchernya kelihatan normal, nomornya ada
        | di Accurate, dan hanya angkanya yang tertinggal.
        */
        if (!$bdv->accurate_id) {
            return response()->json([
                'success' => false,
                'message' => __('bdv_messages.submit_needs_accurate'),
            ], 422);
        }

        if (!$bdv->accurate_in_sync) {
            return response()->json([
                'success' => false,
                'message' => __('bdv_messages.submit_needs_resync'),
            ], 422);
        }

        /*
        | Terisi hanya bila yang baru saja diajukan adalah dokumen REVISIAN.
        | Dipakai di bawah untuk memilih kalimat kabarnya.
        */
        $revisiDitutup = null;

        try {
            DB::transaction(function () use ($bdv, $user, &$revisiDitutup): void {
                /*
                | submitted_by diisi LEBIH DULU, sebelum generator jalan.
                |
                | Generator memakainya untuk mengeluarkan pemohon dari rantai
                | approval-nya sendiri. Kalau diisi sesudahnya, orang yang
                | mengajukan bisa muncul sebagai penyetuju vouchernya sendiri
                | -- dan tidak ada satu pun pesan yang memberitahukannya.
                */
                $bdv->forceFill([
                    'submitted_by' => $user->id,
                    'submitted_at' => now(),
                    'updated_by' => $user->id,

                    /* Sisa penolakan sebelumnya dibersihkan saat diajukan lagi. */
                    'rejected_by' => null,
                    'rejected_at' => null,
                    'rejection_notes' => null,
                ])->save();

                /*
                | Rantai lama dibuang saat pengajuan ulang. Generator menolak
                | membuat rantai kedua, dan memang seharusnya: dua rantai pada
                | satu dokumen berarti dua tahap yang sama-sama "menunggu".
                */
                BdvApproval::where('bdv_id', $bdv->id)->delete();

                $this->generatorApproval->generate($bdv);

                /*
                | Revisi yang berjalan ditutup di sini, SEBELUM statusnya
                | berpindah.
                |
                | Di situlah potret "sesudah" diambil dan daftar perubahannya
                | dihitung -- sekali, lalu disimpan. Menghitungnya ulang
                | setiap kali riwayatnya dibuka akan membuatnya ikut bergeser
                | bersama kode yang menghitungnya, dan riwayat yang bisa
                | bergeser bukan riwayat.
                |
                | Menjawab null pada pengajuan pertama, yang memang tidak
                | menutup apa pun.
                */
                $revisiDitutup = $this->revisi->tutup($bdv, $user);

                $bdv->forceFill([
                    'status' => BankDisbursementVoucher::STATUS_IN_PROGRESS,
                ])->save();
            });
        } catch (ValidationException $e) {
            /*
            | Kegagalan matriks approval disampaikan apa adanya: isinya
            | memberi tahu admin bagian mana dari matriks yang perlu
            | dilengkapi. Menggantinya dengan "gagal mengajukan" membuang
            | satu-satunya petunjuk yang ada.
            */
            return response()->json([
                'success' => false,
                'message' => collect($e->errors())->flatten()->implode(' '),
                'errors' => $e->errors(),
            ], 422);
        }

        Log::info('[BDV] Diajukan', [
            'bdv_id' => $bdv->id,
            'bdv_number' => $bdv->bdv_number,
            'oleh' => $user->id,
        ]);

        /*
        | Approver tahap pertama dikabari, DI LUAR transaksi.
        |
        | Dokumen revisian memakai kalimatnya sendiri: permintaan
        | persetujuan biasa tidak menyebut apa pun soal revisi, dan approver
        | yang menerimanya akan membacanya sebagai dokumen baru -- lalu
        | menyetujui perubahan yang justru menjadi sebab pengembaliannya
        | tanpa pernah melihatnya. Yang mengembalikan ikut dikabari di sana,
        | supaya tahu permintaannya sudah dipenuhi.
        */
        try {
            if ($revisiDitutup) {
                $this->kabarRevisi->diajukanUlang(
                    $bdv,
                    $revisiDitutup,
                    fn ($baris) => $this->notifikasi->resolveApproverUsers($baris),
                );
            } else {
                $this->notifikasi->mintaApproval($bdv);
            }
        } catch (\Throwable $e) {
            Log::error('[BDV] Notifikasi pengajuan gagal', [
                'bdv_id' => $bdv->id,
                'bdv_number' => $bdv->bdv_number,
                'message' => $e->getMessage(),
            ]);
        }

        return response()->json([
            'success' => true,
            'message' => __('bdv_messages.submitted'),
            'data' => $this->bentukBaris($bdv->fresh(), $user),
        ]);
    }

    /*
    |--------------------------------------------------------------------------
    | Menyetujui
    |--------------------------------------------------------------------------
    | Siapa yang boleh, ditentukan RANTAI APPROVAL-nya, bukan izinnya.
    |
    | Izin bdv.approve hanya menjawab "orang ini termasuk yang pernah boleh
    | menyetujui BDV"; yang menentukan sekarang giliran siapa adalah baris
    | bdv_approvals bertahap WAITING. Memeriksa izin saja akan membuat setiap
    | pemegang izin bisa menyetujui dokumen siapa pun pada tahap mana pun.
    |
    | TIDAK ADA PENGIRIMAN KE ACCURATE DI SINI. Vouchernya sudah ada di sana
    | sejak draft pertama disimpan.
    |--------------------------------------------------------------------------
    */
    public function approve(Request $request, string $publicId): JsonResponse
    {
        return $this->putuskan($request, $publicId, 'approve');
    }

    public function reject(Request $request, string $publicId): JsonResponse
    {
        return $this->putuskan($request, $publicId, 'reject');
    }

    /*
    |--------------------------------------------------------------------------
    | Kembalikan ke pemohon untuk direvisi
    |--------------------------------------------------------------------------
    | PILIHAN KETIGA DI MEJA YANG SAMA dengan Setujui dan Tolak.
    |
    | Penjaganya pun sama persis: izin bdv.approve, lalu baris approval
    | berstatus WAITING atas nama orang ini. Yang berhak menyuruh
    | memperbaiki adalah yang berhak menilai -- tidak ada izin tersendiri
    | untuk mengembalikan.
    |
    | Itu keputusan, bukan kelalaian. Izin terpisah berarti approver yang
    | sah hanya punya dua pilihan -- setujui yang ia tahu salah, atau tolak
    | yang mematikan dokumennya -- sampai ada admin yang menyadari dan
    | memberinya izin ketiga. Yang menanggung jeda itu adalah pemohon.
    |
    | BEDANYA DENGAN FPU DAN CLAIM: di sana ada pintu kedua, milik Finance,
    | untuk dokumen yang berkasnya baru diterima. BDV tidak punya meja
    | penerimaan berkas -- begitu disetujui penuh ia langsung menjadi dasar
    | pencairan -- jadi di sini hanya ada satu pintu, dan servisnya dipanggil
    | dengan FROM_APPROVAL saja.
    |
    | ACCURATE TIDAK DISENTUH. Vouchernya tetap berdiri di pembukuan dengan
    | isi yang sekarang; yang berubah baru niat orang terhadapnya. Begitu
    | pemohon menyimpan perbaikannya, jalur simpan biasa yang mengirimkan
    | isi barunya ke sana -- satu jalan, bukan dua.
    |--------------------------------------------------------------------------
    */
    public function returnForRevision(Request $request, string $publicId): JsonResponse
    {
        $user = $request->user();

        if (!$user || !$user->hasPermission('bdv.approve')) {
            return response()->json([
                'success' => false,
                'message' => __('bdv_messages.forbidden_approve'),
            ], 403);
        }

        $validated = $request->validate([
            'reason' => ['required', 'string', 'min:3', 'max:2000'],
        ], [], [
            'reason' => __('fund_request_messages.return_for_revision.reason_label'),
        ]);

        /*
        | Dicari dengan batas akses VIEW, sama seperti putuskan().
        |
        | Approver memang sering berada di luar cakupan biasanya -- Finance
        | Manager di HO yang memegang voucher cabang. Yang menjaga di sini
        | adalah rantai approval-nya sendiri, bukan cakupan izin.
        */
        $bdv = $this->temukanUntukDilihat($user, $publicId);

        if ($bdv instanceof JsonResponse) {
            return $bdv;
        }

        DB::beginTransaction();

        try {
            /*
            | Dikunci dulu, baru diperiksa.
            |
            | Tanpa kunci, dua approver pada tahap ANY yang menekan tombolnya
            | hampir bersamaan akan sama-sama lolos pemeriksaan, dan voucher
            | yang sama tercatat dua kali dikembalikan -- dengan nomor revisi
            | yang sama pula.
            */
            $bdv = BankDisbursementVoucher::query()
                ->lockForUpdate()
                ->find($bdv->id);

            if (!$bdv) {
                DB::rollBack();

                return response()->json([
                    'success' => false,
                    'message' => __('bdv_messages.not_found'),
                ], 404);
            }

            if (strtoupper(trim((string) $bdv->status)) !== BankDisbursementVoucher::STATUS_IN_PROGRESS) {
                DB::rollBack();

                return response()->json([
                    'success' => false,
                    'message' => __('bdv_messages.approval_not_in_progress'),
                ], 422);
            }

            $baris = $this->approvalService->barisMilikUser($bdv, $user, true);

            if (!$baris) {
                DB::rollBack();

                return response()->json([
                    'success' => false,
                    'message' => __('fund_request_messages.return_for_revision.not_active_approver'),
                ], 403);
            }

            $hasil = $this->revisi->kembalikan(
                document: $bdv,
                user: $user,
                reason: $validated['reason'],

                /*
                | null: pintu approver memang tanpa izin tambahan. Haknya
                | sudah dijawab barisMilikUser() di atas.
                */
                permission: null,

                from: FundRequestRevision::FROM_APPROVAL,
                stepOrder: (int) $baris->step_order,
                stepLabel: $baris->label,
            );

            if (!$hasil['ok']) {
                DB::rollBack();

                return response()->json([
                    'success' => false,
                    'message' => __($hasil['message_key'], $hasil['message_replace']),
                ], $hasil['status']);
            }

            DB::commit();
        } catch (ValidationException $e) {
            DB::rollBack();

            return response()->json([
                'success' => false,
                'message' => collect($e->errors())->flatten()->first()
                    ?? __('fund_request_messages.return_for_revision.failed'),
                'errors' => $e->errors(),
            ], 422);
        } catch (\Throwable $e) {
            DB::rollBack();

            Log::error('[BDV] Kembalikan untuk revisi gagal', [
                'bdv_id' => $bdv->id ?? null,
                'user_id' => $user->id,
                'message' => $e->getMessage(),
                'file' => $e->getFile(),
                'line' => $e->getLine(),
            ]);

            return response()->json([
                'success' => false,
                'message' => __('fund_request_messages.return_for_revision.failed'),
            ], 500);
        }

        $segar = $bdv->fresh();

        /*
        | Pemohon dikabari beserta alasannya, di luar transaksi. Tanpa kabar
        | ini vouchernya hanya berpindah status diam-diam, dan yang diminta
        | memperbaiki tidak pernah tahu ada yang menunggunya.
        */
        try {
            $this->kabarRevisi->dikembalikan($segar, $hasil['revision']);
        } catch (\Throwable $e) {
            Log::error('[BDV] Notifikasi pengembalian gagal', [
                'bdv_id' => $bdv->id,
                'message' => $e->getMessage(),
            ]);
        }

        Log::info('[BDV] Dikembalikan untuk revisi', [
            'bdv_id' => $bdv->id,
            'bdv_number' => $bdv->bdv_number,
            'oleh' => $user->id,
            'tahap' => $baris->step_order,
            'revisi_ke' => (int) $hasil['revision']->revision_number,
        ]);

        return response()->json([
            'success' => true,
            'message' => __('fund_request_messages.return_for_revision.success'),
            'data' => $this->bentukBaris($segar, $user),
        ]);
    }

    /**
     * Jalur bersama untuk menyetujui dan menolak.
     *
     * Disatukan karena penjagaan di depannya sama persis -- izin, dokumen,
     * status, dan pencairan baris approval. Yang berbeda hanya satu panggilan
     * di tengahnya, dan menuliskannya dua kali berarti dua tempat yang harus
     * sama-sama diingat setiap kali penjagaannya berubah.
     */
    private function putuskan(Request $request, string $publicId, string $jalur): JsonResponse
    {
        $user = $request->user();

        if (!$user || !$user->hasPermission('bdv.approve')) {
            return response()->json([
                'success' => false,
                'message' => __('bdv_messages.forbidden_approve'),
            ], 403);
        }

        /*
        | Dicari dengan batas akses VIEW, bukan scope aksi.
        |
        | Approver bisa saja berada di luar cakupan biasanya -- justru itu
        | gunanya ia ditunjuk pada matriks. Yang menjaga di sini adalah rantai
        | approval itu sendiri: barisMilikUser() hanya menjawab bila orang ini
        | memang tercantum pada tahap yang sedang menunggu.
        */
        $bdv = $this->temukanUntukDilihat($user, $publicId);

        if ($bdv instanceof JsonResponse) {
            return $bdv;
        }

        if (strtoupper(trim((string) $bdv->status)) !== BankDisbursementVoucher::STATUS_IN_PROGRESS) {
            return response()->json([
                'success' => false,
                'message' => __('bdv_messages.approval_not_in_progress'),
            ], 422);
        }

        $catatan = $request->input('notes');

        try {
            $hasil = DB::transaction(function () use ($bdv, $user, $jalur, $catatan) {
                if ($jalur === 'approve') {
                    return $this->approvalService->setujui($bdv, $user, $catatan);
                }

                $this->approvalService->tolak($bdv, $user, $catatan);

                return null;
            });
        } catch (ValidationException $e) {
            return response()->json([
                'success' => false,
                'message' => collect($e->errors())->flatten()->implode(' '),
                'errors' => $e->errors(),
            ], 422);
        }

        $segar = $bdv->fresh();

        if ($jalur === 'reject') {
            try {
                $this->notifikasi->ditolak($segar, $user);
            } catch (\Throwable $e) {
                Log::error('[BDV] Notifikasi penolakan gagal', [
                    'bdv_id' => $bdv->id,
                    'message' => $e->getMessage(),
                ]);
            }

            Log::info('[BDV] Ditolak', [
                'bdv_id' => $bdv->id,
                'bdv_number' => $bdv->bdv_number,
                'oleh' => $user->id,
            ]);

            return response()->json([
                'success' => true,
                'message' => __('bdv_messages.rejected'),
                'data' => $this->bentukBaris($segar, $user),
            ]);
        }

        $penuh = (bool) ($hasil['is_final_approved'] ?? false);

        /*
        | DUA KABAR, dan keduanya perlu.
        |
        | Pemohon diberi tahu dokumennya maju -- dengan kalimat yang berbeda
        | antara "maju satu langkah" dan "selesai seluruhnya", karena yang
        | kedua berarti vouchernya sudah bisa dipakai mencairkan.
        |
        | Approver tahap berikutnya diberi tahu giliran sudah berpindah ke
        | mejanya. Tanpa itu ia baru tahu saat kebetulan membuka daftar, dan
        | voucher yang sudah lewat separuh rantai bisa menunggu berhari-hari
        | di tahap yang tidak ada yang tahu sedang menunggu.
        */
        try {
            if (($hasil['approval'] ?? null) instanceof BdvApproval) {
                $this->notifikasi->tahapDisetujui($segar, $user, $hasil['approval'], !$penuh);
            }

            if (!$penuh && ($hasil['has_pending_approval'] ?? false)) {
                $this->notifikasi->mintaApproval($segar);
            }
        } catch (\Throwable $e) {
            Log::error('[BDV] Notifikasi persetujuan gagal', [
                'bdv_id' => $bdv->id,
                'message' => $e->getMessage(),
            ]);
        }

        Log::info('[BDV] Disetujui', [
            'bdv_id' => $bdv->id,
            'bdv_number' => $bdv->bdv_number,
            'oleh' => $user->id,
            'disetujui_penuh' => $penuh,
        ]);

        return response()->json([
            'success' => true,
            'message' => $penuh
                ? __('bdv_messages.approved_final')
                : __('bdv_messages.approved'),

            'is_final_approved' => $penuh,
            'data' => $this->bentukBaris($segar, $user),
        ]);
    }

    /*
    |--------------------------------------------------------------------------
    | Pembantu
    |--------------------------------------------------------------------------
    */

    /**
     * Mencari BDV untuk sebuah AKSI, lengkap dengan penjagaan scope-nya.
     *
     * Mengembalikan JsonResponse bila harus ditolak, supaya pemanggilnya
     * tinggal meneruskannya. Dijawab 404 untuk ketiga sebab -- id yang tidak
     * terbaca, dokumen yang tidak ada, dan dokumen di luar cakupan --
     * karena membedakannya sudah cukup untuk memberi tahu bahwa nomor itu
     * ada kepada orang yang tidak berhak mengetahuinya.
     *
     * @return BankDisbursementVoucher|JsonResponse
     */
    private function temukanUntukAksi(
        Request $request,
        object $user,
        string $permission,
        string $publicId,
    ) {
        $bdv = $this->temukanUntukDilihat($user, $publicId);

        if ($bdv instanceof JsonResponse) {
            return $bdv;
        }

        if (!DocumentActionScope::allows($user, $permission, $bdv, self::KOLOM_SCOPE)) {
            return response()->json([
                'success' => false,
                'message' => __('bdv_messages.not_found'),
            ], 404);
        }

        return $bdv;
    }

    /*
    |--------------------------------------------------------------------------
    | Voucher yang sedang diubah, untuk daftar pilihan dokumen
    |--------------------------------------------------------------------------
    | Halaman ubah boleh mengganti daftar dokumennya. Supaya dokumen yang SUDAH
    | terpilih tetap muncul di pilihannya sendiri, voucher ini dikecualikan
    | dari saringan "sudah diangkat BDV lain".
    |
    | Pengecualian itu melonggarkan aturan, jadi haknya diperiksa penuh di
    | sini -- tidak cukup mengandalkan izin bdv.update saja:
    |
    | 1. Nomornya harus bisa dibuka dan vouchernya ada dalam cakupan BACA-nya.
    | 2. Cakupan AKSI ubah juga harus mengizinkan voucher itu. Tanpa langkah
    |    ini, "bdv.view: ALL, bdv.update: OWN_DATA" tidak berarti apa-apa.
    | 3. Vouchernya harus masih draft, sama seperti syarat update().
    |
    | Yang gagal dijawab 404, bukan 403: membedakan keduanya memberi tahu
    | bahwa voucher itu ada kepada orang yang tidak berhak menyentuhnya.
    |
    | Tanpa 'bdv' sama sekali -- yaitu dari halaman buat -- jawabannya null,
    | dan tidak ada yang dilonggarkan.
    |--------------------------------------------------------------------------
    *
    * @return int|null|JsonResponse
    */
    private function bdvUntukPilihan(object $user, ?string $publicId)
    {
        if ($publicId === null || trim($publicId) === '') {
            return null;
        }

        $bdv = $this->temukanUntukDilihat($user, $publicId);

        if ($bdv instanceof JsonResponse) {
            return $bdv;
        }

        $bolehUbah = DocumentActionScope::allows($user, 'bdv.update', $bdv, self::KOLOM_SCOPE)
            && $this->masihBisaDiketik($bdv);

        if (!$bolehUbah) {
            return response()->json([
                'success' => false,
                'message' => __('bdv_messages.not_found'),
            ], 404);
        }

        return (int) $bdv->id;
    }

    /**
     * Mencari BDV dengan batas akses baca.
     *
     * @return BankDisbursementVoucher|JsonResponse
     */
    private function temukanUntukDilihat(object $user, string $publicId, array $relasi = [])
    {
        try {
            $id = (int) Crypt::decryptString($publicId);
        } catch (\Throwable $e) {
            return response()->json([
                'success' => false,
                'message' => __('bdv_messages.not_found'),
            ], 404);
        }

        $query = BankDisbursementVoucher::query()
            ->when($relasi !== [], fn ($q) => $q->with($relasi));

        $this->terapkanBatasAkses($query, $user);

        $bdv = $query->find($id);

        if (!$bdv) {
            return response()->json([
                'success' => false,
                'message' => __('bdv_messages.not_found'),
            ], 404);
        }

        return $bdv;
    }

    /*
    |--------------------------------------------------------------------------
    | Menuliskannya ke Accurate, lalu menjawab layar
    |--------------------------------------------------------------------------
    | DIKIRIM SESUDAH TERSIMPAN, DI LUAR TRANSAKSI. Urutannya disengaja.
    |
    | Bila pengiriman ditaruh di dalam transaksi, kegagalan menyimpan sesudah
    | Accurate menjawab berhasil akan meninggalkan dokumen di sana yang tidak
    | dikenali siapa pun di sini -- yatim, dan baru ketahuan saat seseorang
    | membandingkan kedua sistem. Dengan urutan ini, yang mungkin terjadi
    | justru kebalikannya: tersimpan di sini tetapi belum sampai sana. Keadaan
    | itu kelihatan (accurate_id kosong) dan bisa diperbaiki hanya dengan
    | menekan Simpan sekali lagi.
    |
    | Karena itu kegagalan Accurate TIDAK membatalkan penyimpanan. Yang sudah
    | diketik orang tidak boleh hilang hanya karena sistem sebelah sedang
    | bermasalah. Yang disampaikan adalah dua kabar sekaligus: tersimpan, dan
    | belum masuk pembukuan.
    |--------------------------------------------------------------------------
    */
    private function jawabSesudahSimpan(
        BankDisbursementVoucher $bdv,
        string $pesan,
        int $status = 200,
        ?object $user = null,
    ): JsonResponse {
        $peringatan = null;
        $terkirim = false;

        /*
        | DITANDAI BELUM SINKRON LEBIH DULU, sebelum pengiriman dicoba.
        |
        | Isi vouchernya baru saja berubah, jadi apa pun yang ada di Accurate
        | sekarang sudah tidak mewakilinya. Penandanya baru kembali true bila
        | pengiriman di bawah berhasil -- lihat BdvAccuratePostingService.
        |
        | Urutannya menentukan. Kalau ditandai false hanya pada jalur gagal,
        | permintaan yang putus di tengah pengiriman meninggalkan voucher
        | bertanda "sinkron" dengan isi yang belum pernah sampai sana -- dan
        | penjaga pengajuan akan meloloskannya.
        */
        $bdv->forceFill(['accurate_in_sync' => false])->save();

        try {
            $this->accurate->kirim($bdv->fresh()->load(['items', 'sources']));

            $terkirim = true;
        } catch (\Throwable $e) {
            $peringatan = __('bdv_messages.saved_accurate_failed', [
                'reason' => $e->getMessage(),
            ]);
        }

        $segar = $bdv->fresh();

        /*
        | BUKTI DISELARASKAN HANYA BILA DOKUMENNYA SAMPAI.
        |
        | Dua arah: bukti yang belum ada diunggah, bukti yang sudah tidak lagi
        | milik voucher ini dibuang dari sana -- yang terakhir itu yang
        | menjawab dokumen sumbernya diganti atau dikeluarkan. Dihitung
        | sebagai selisih, bukan hapus-semua-lalu-unggah-ulang; alasannya
        | panjang dan ada di BdvAttachmentUploadService::selaraskan().
        |
        | Keduanya menempel pada sebuah transaksi di Accurate, jadi tanpa
        | dokumen yang baru saja diterima di sana tidak ada yang bisa
        | dikerjakan. Mencobanya hanya menghabiskan jatah permintaan per detik
        | untuk sesuatu yang pasti ditolak.
        |
        | Kegagalannya TIDAK PERNAH membatalkan penyimpanan: di titik ini
        | uangnya sudah tercatat di pembukuan, dan menampilkan "gagal" untuk
        | sesuatu yang berhasil hanya membuat orangnya menyimpan berulang
        | kali. Yang terjadi hanya kabar tambahan -- lihat peringatanLampiran.
        */
        if ($terkirim) {
            $laporanLampiran = $this->lampiranAccurate->selaraskan($segar->load('sources'));

            $peringatan = $this->gabungPeringatan(
                $peringatan,
                $this->peringatanLampiran($laporanLampiran),
            );
        }

        return response()->json([
            'success' => true,
            'message' => $peringatan ?? $pesan,

            /*
            | Dipisahkan dari message supaya layar bisa memilih nada yang
            | tepat -- berhasil, atau berhasil-sebagian. Keduanya bukan
            | kegagalan, dan menampilkan keduanya dengan warna yang sama
            | membuat yang kedua luput dibaca.
            |
            | ARTINYA "PENYIMPANAN INI SAMPAI KE ACCURATE", bukan "dokumen ini
            | ada di Accurate". Bedanya menentukan.
            |
            | Dokumen yang sudah pernah terkirim selalu punya accurate_id.
            | Bila nilai itu yang dipakai, perubahan yang DITOLAK Accurate
            | tetap terbaca "terkirim" -- layar menampilkan hijau, orangnya
            | pergi dengan tenang, dan sejak saat itu angka di sini berbeda
            | dari angka di pembukuan tanpa ada yang tahu.
            */
            'accurate_sent' => $terkirim,

            /* Keadaan dokumennya secara keseluruhan, untuk keperluan lain. */
            'accurate_id' => $segar->accurate_id,

            'warning' => $peringatan,

            'data' => $this->bentukBaris($segar, $user),
        ], $status);
    }

    /*
    |--------------------------------------------------------------------------
    | Kabar tentang bukti yang gagal diunggah -- null bila semuanya beres
    |--------------------------------------------------------------------------
    | Disebutkan SATU PER SATU dengan sebabnya, bukan sebagai "sebagian bukti
    | gagal". Sebabnya berbeda-beda dan tindakannya pun berbeda: yang terlalu
    | besar harus dikecilkan, yang salah jenis harus diganti, yang tidak
    | terbaca harus diunggah ulang di dokumen sumbernya. Kalimat umum
    | memaksa orangnya menebak mana yang mana.
    |
    | Dibatasi tiga nama supaya toast tetap terbaca; selebihnya disebut
    | jumlahnya, dan seluruhnya selalu ada di log.
    |
    | @param  array{terkirim: int, sudah_ada: int, gagal: array<int, array{nama: string, alasan: string}>}  $laporan
    |--------------------------------------------------------------------------
    */
    private function peringatanLampiran(array $laporan): ?string
    {
        $gagal = $laporan['gagal'] ?? [];
        $gagalHapus = $laporan['gagal_hapus'] ?? [];

        $kalimat = [];

        if ($gagal !== []) {
            $kalimat[] = __('bdv_messages.attachment_partial', [
                'failed' => count($gagal),

                /*
                | Penyebutnya SELURUH bukti voucher ini, termasuk yang sudah
                | terkirim pada penyimpanan sebelumnya. "2 dari 9" menyatakan
                | keadaan; "2 dari 2" -- yang keluar bila hanya yang baru
                | dihitung -- terbaca seolah tidak ada satu pun bukti yang
                | pernah sampai.
                |
                | Yang gagal DIHAPUS tidak ikut: ia bukan bagian dari bukti
                | voucher ini lagi, dan memasukkannya ke penyebut membuat
                | angkanya menghitung dua hal yang berbeda.
                */
                'total' => ($laporan['terkirim'] ?? 0) + ($laporan['sudah_ada'] ?? 0) + count($gagal),

                'detail' => $this->rincianKegagalanLampiran($gagal),
            ]);
        }

        /*
        | Kalimat terpisah, dan memang harus terpisah: yang satu berarti
        | buktinya BELUM sampai Accurate, yang lain berarti bukti lama MASIH
        | di sana padahal seharusnya sudah pergi. Digabung, kabarnya berbalik
        | arti untuk salah satunya.
        */
        if ($gagalHapus !== []) {
            $kalimat[] = __('bdv_messages.attachment_leftover', [
                'count' => count($gagalHapus),
                'detail' => $this->rincianKegagalanLampiran($gagalHapus),
            ]);
        }

        return $kalimat === [] ? null : implode(' ', $kalimat);
    }

    /**
     * Nama dan sebabnya, dibatasi tiga supaya toast tetap terbaca.
     *
     * Selebihnya disebut jumlahnya saja; seluruhnya selalu ada di log.
     *
     * @param  array<int, array{nama: string, alasan: string}>  $gagal
     */
    private function rincianKegagalanLampiran(array $gagal): string
    {
        $disebut = collect($gagal)
            ->take(3)
            ->map(fn (array $g): string => __('bdv_messages.attachment_detail', [
                'name' => $g['nama'],
                'reason' => rtrim($g['alasan'], '.'),
            ]))
            ->implode('; ');

        $sisa = count($gagal) - min(count($gagal), 3);

        if ($sisa > 0) {
            $disebut .= '; ' . __('bdv_messages.attachment_and_more', ['count' => $sisa]);
        }

        return rtrim($disebut, '.') . '.';
    }

    /**
     * Dua kabar buruk digabung menjadi satu kalimat, bukan saling menimpa.
     *
     * Keduanya bisa terjadi sekaligus -- dokumennya ditolak Accurate DAN
     * buktinya tidak terkirim -- dan yang kedua lenyap bila penggabungannya
     * sekadar memilih salah satu.
     */
    private function gabungPeringatan(?string $satu, ?string $dua): ?string
    {
        $bagian = array_filter([$satu, $dua], fn ($v): bool => is_string($v) && trim($v) !== '');

        return $bagian === [] ? null : implode(' ', $bagian);
    }

    /**
     * Aturan isian yang sama untuk simpan baru maupun ubah.
     *
     * $abaikanId membuat pemeriksaan nomor unik melewati dokumen itu sendiri --
     * tanpa itu, menyimpan ulang tanpa mengubah nomornya akan ditolak oleh
     * nomornya sendiri.
     *
     * @return array<string, mixed>
     */
    private function aturanIsian(?int $abaikanId = null): array
    {
        /*
        | Syaratnya HARUS sama persis dengan indeks parsial
        | bank_disbursement_vouchers_bdv_number_unique -- lihat migrasi
        | 2026_11_24_100000.
        |
        | Dua aturan yang berbeda sedikit saja menghasilkan keadaan yang
        | paling membingungkan: pemeriksaan di sini meloloskan nomornya, lalu
        | basis data menolaknya dengan pesan yang jauh lebih kasar -- atau
        | sebaliknya, layar menolak nomor yang sebenarnya bebas.
        |
        | Nomor dilepaskan oleh tiga keadaan: vouchernya sudah dihapus,
        | ditolak, atau dibatalkan. Ketiganya berarti voucher itu tidak akan
        | pernah hidup kembali, jadi nomornya tidak perlu ditahan.
        */
        $unik = Rule::unique('bank_disbursement_vouchers', 'bdv_number')
            ->whereNull('deleted_at')
            ->whereNotIn('status', [
                BankDisbursementVoucher::STATUS_REJECTED,
                BankDisbursementVoucher::STATUS_CANCELLED,
            ]);

        if ($abaikanId) {
            $unik->ignore($abaikanId);
        }

        return [
            'bdv_number' => ['required', 'string', 'max:100', $unik],
            'date' => ['required', 'date'],

            /* NOMOR akun Accurate, bukan id internalnya. */
            'cash_bank_account_no' => ['required', 'string', 'max:50'],
            'cash_bank_account_name' => ['nullable', 'string', 'max:255'],

            'check_number' => ['nullable', 'string', 'max:50'],

            /*
            | WAJIB. Bukan pilihan kita: dokumentasi other-payment/save.do
            | menyebut payee sebagai field wajib, dan dokumennya ditulis ke
            | Accurate pada saat disimpan. Membiarkannya opsional di sini
            | hanya memindahkan penolakannya ke Accurate, dengan pesan yang
            | jauh lebih sulit dimengerti Finance.
            */
            'payee' => ['required', 'string', 'max:255'],
            'notes' => ['nullable', 'string'],

            /*
            | WAJIB LENGKAP SEJAK SIMPAN PERTAMA, termasuk akun tiap barisnya.
            |
            | Bukan karena ketat demi ketat: menyimpan BDV berarti menuliskannya
            | ke Accurate saat itu juga, dan baris tanpa akun tidak punya
            | tempat jatuh di pembukuan. Jadi tidak ada draft setengah jadi di
            | modul ini -- berbeda dari modul pengajuan dana yang tidak
            | menyentuh sistem lain saat disimpan.
            */
            /*
            | SATU VOUCHER, BANYAK DOKUMEN SUMBER.
            |
            | Rinciannya menggantung pada dokumennya masing-masing, bukan
            | pada vouchernya: nomor baris berulang antar dokumen -- FPU A
            | punya baris 1, Claim B juga -- sehingga daftar item yang rata
            | tidak punya cara menyebut baris mana milik dokumen mana.
            */
            'sources' => ['required', 'array', 'min:1'],
            'sources.*.document_type' => ['required', 'string', 'max:20'],
            'sources.*.public_id' => ['required', 'string', 'max:512'],

            'sources.*.items' => ['required', 'array', 'min:1'],
            'sources.*.items.*.line_no' => ['required', 'integer', 'min:1'],
            'sources.*.items.*.accurate_account_no' => ['required', 'string', 'max:50'],
            'sources.*.items.*.accurate_account_name' => ['nullable', 'string', 'max:255'],
        ];
    }

    /**
     * Pesan yang menggantikan kalimat bawaan validator.
     *
     * Hanya untuk yang kalimat bawaannya akan membingungkan. "Items wajib
     * diisi" tidak berarti apa-apa bagi Finance -- yang mereka lihat di layar
     * bernama Rincian Item, dan rincian itu datang dari dokumennya sendiri,
     * bukan sesuatu yang mereka ketik.
     *
     * @return array<string, string>
     */
    private function pesanIsian(): array
    {
        return [
            'sources.required' => __('bdv_messages.sources_required'),
            'sources.min' => __('bdv_messages.sources_required'),
            'sources.*.items.required' => __('bdv_messages.items_required'),
            'sources.*.items.min' => __('bdv_messages.items_required'),
        ];
    }

    /*
    |--------------------------------------------------------------------------
    | Mengumpulkan dokumen sumber yang dipilih
    |--------------------------------------------------------------------------
    | Satu tempat untuk seluruh pemeriksaan dokumen, dipakai bersama oleh
    | menyimpan dan mengubah. Yang dikembalikan sudah siap ditulis: rincian
    | tiap dokumen beserta pilihan akun per barisnya, plus nilai kepala
    | voucher yang diturunkan darinya.
    |
    | Mengembalikan JsonResponse bila ada yang harus ditolak, supaya
    | pemanggilnya tinggal meneruskan.
    |
    | $sudahMilikVoucher berisi pasangan "FPU:12" milik voucher yang sedang
    | diubah. Dokumen itu TIDAK lagi "layak diangkat" -- justru karena sudah
    | diangkat voucher ini -- jadi ia dicari lewat pintu yang tidak menjaga
    | kelayakan. Tanpa pengecualian itu, mengubah voucher tanpa menyentuh
    | daftar dokumennya pun akan ditolak.
    |
    | @param  array<int, array<string, mixed>>  $sources
    | @param  array<int, string>  $sudahMilikVoucher
    | @return array<string, mixed>|JsonResponse
    |--------------------------------------------------------------------------
    */
    private function kumpulkanSumber(array $sources, object $user, array $sudahMilikVoucher = [])
    {
        $terkumpul = [];
        $dilihat = [];

        foreach ($sources as $urutan => $satu) {
            $kode = strtoupper(trim((string) ($satu['document_type'] ?? '')));

            if (!$this->registry->has($kode)) {
                return response()->json([
                    'success' => false,
                    'message' => __('bdv_messages.unknown_document_type'),
                ], 422);
            }

            try {
                $sourceId = (int) Crypt::decryptString((string) ($satu['public_id'] ?? ''));
            } catch (\Throwable $e) {
                return response()->json([
                    'success' => false,
                    'message' => __('bdv_messages.document_not_found'),
                ], 404);
            }

            $kunci = $kode . ':' . $sourceId;

            /*
            | Dokumen yang sama dipilih dua kali pada voucher yang sama.
            | Ditolak di sini dengan kalimat yang bisa dibaca; indeks unik di
            | basis data juga menjaganya, tetapi pesannya jauh lebih kasar.
            */
            if (isset($dilihat[$kunci])) {
                return response()->json([
                    'success' => false,
                    'message' => __('bdv_messages.duplicate_document_in_voucher'),
                ], 422);
            }

            $dilihat[$kunci] = true;

            $query = in_array($kunci, $sudahMilikVoucher, true)
                ? $this->registry->get($kode)['model']::query()
                : $this->registry->eligibleQuery($kode);

            $this->terapkanBatasAkses($query, $user);

            $dokumen = $query->find($sourceId);

            if (!$dokumen) {
                return response()->json([
                    'success' => false,
                    'message' => __('bdv_messages.document_not_available'),
                ], 404);
            }

            $rincian = $this->registry->rincian($kode, $dokumen);

            $akun = $this->akunPerBaris($satu['items'] ?? []);

            if (($salah = $this->barisTakDikenal($akun, $rincian['items'])) !== null) {
                return response()->json([
                    'success' => false,
                    'message' => __('bdv_messages.item_line_unknown_on', [
                        'line' => $salah,
                        'document' => $rincian['number'] ?? '-',
                    ]),
                ], 422);
            }

            $terkumpul[] = [
                'kode' => $kode,
                'rincian' => $rincian,
                'akun' => $akun,
                'urutan' => $urutan + 1,
            ];
        }

        /*
        |----------------------------------------------------------------------
        | SELURUH DOKUMEN HARUS BERJENIS SAMA
        |----------------------------------------------------------------------
        | Satu BDV mencairkan FPU saja, atau Claim saja -- tidak boleh
        | campur. Keduanya dokumen yang berbeda sifatnya: FPU adalah uang
        | yang diminta di muka, Claim adalah penggantian uang yang sudah
        | lebih dulu dikeluarkan orang. Mencampurnya dalam satu voucher
        | membuat cetakannya tidak bisa menyebut dirinya voucher apa.
        |
        | Diperiksa di sini, bukan hanya di layar: daftar nomor dokumen di
        | layar memang sudah disaring per jenis, tetapi penyaring layar
        | bukan penjaga -- siapa pun yang bisa memanggil endpoint ini
        | langsung tidak melewatinya.
        |----------------------------------------------------------------------
        */
        $jenisDokumen = collect($terkumpul)->pluck('kode')->unique()->values();

        if ($jenisDokumen->count() > 1) {
            return response()->json([
                'success' => false,
                'message' => __('bdv_messages.mixed_document_type', [
                    'types' => $jenisDokumen
                        ->map(fn (string $k): string => $this->registry->label($k))
                        ->implode(' dan '),
                ]),
            ], 422);
        }

        /*
        |----------------------------------------------------------------------
        | SELURUH DOKUMEN HARUS DARI SATU CABANG
        |----------------------------------------------------------------------
        | Cabang voucher dipakai mencocokkan matriks approval (HO atau
        | CABANG) dan menyaring siapa yang boleh melihatnya. Dokumen dari dua
        | cabang berbeda tidak punya jawaban yang bisa dipertahankan untuk
        | keduanya -- dan menebaknya berarti menjatuhkan persetujuan uang ke
        | meja yang salah.
        |
        | Department dijaga dengan cara yang sama persis, tepat di bawah ini.
        |----------------------------------------------------------------------
        */
        $cabang = collect($terkumpul)
            ->map(fn ($s) => (string) ($s['rincian']['branch_id'] ?? ''))
            ->unique()
            ->values();

        if ($cabang->count() > 1) {
            return response()->json([
                'success' => false,
                'message' => __('bdv_messages.mixed_branch', [
                    'documents' => collect($terkumpul)
                        ->map(fn ($s) => $s['rincian']['number'] . ' (' . ($s['rincian']['branch'] ?? '-') . ')')
                        ->implode(', '),
                ]),
            ], 422);
        }

        /*
        |----------------------------------------------------------------------
        | SELURUH DOKUMEN HARUS DARI SATU DEPARTMENT
        |----------------------------------------------------------------------
        | Alasannya sama dengan cabang: department voucher ikut mencocokkan
        | matriks approval dan menyaring siapa yang boleh melihatnya. Dokumen
        | dari dua department tidak punya jawaban yang bisa dipertahankan
        | untuk keduanya.
        |
        | Layar sudah meminta department dipilih lebih dulu dan menyaring
        | daftar nomornya ke sana, jadi aturan ini jarang terlihat. Tetapi
        | penyaring layar bukan penjaga -- siapa pun yang memanggil endpoint
        | ini langsung tidak melewatinya.
        |----------------------------------------------------------------------
        */
        $department = collect($terkumpul)
            ->map(fn ($s) => (string) ($s['rincian']['department_id'] ?? ''))
            ->unique()
            ->values();

        if ($department->count() > 1) {
            return response()->json([
                'success' => false,
                'message' => __('bdv_messages.mixed_department', [
                    'documents' => collect($terkumpul)
                        ->map(fn ($s) => $s['rincian']['number']
                            . ' (' . ($s['rincian']['department'] ?? '-') . ')')
                        ->implode(', '),
                ]),
            ], 422);
        }

        /*
        | Keterangan transaksi masih BOLEH campur -- satu FPU dan satu Claim
        | di department yang sama bisa saja berbeda keterangannya, dan itu
        | wajar. Yang campur dikosongkan, sehingga pencocokan approval jatuh
        | ke flow "semua kategori". Mengisinya dengan salah satu dokumen akan
        | memilih meja approval atas nama dokumen yang kebetulan terdaftar
        | pertama.
        */
        $satuNilai = function (string $kunci) use ($terkumpul) {
            $nilai = collect($terkumpul)
                ->map(fn ($s) => $s['rincian'][$kunci] ?? null)
                ->unique()
                ->values();

            return $nilai->count() === 1 ? $nilai->first() : null;
        };

        return [
            'sumber' => $terkumpul,

            'kepala' => [
                'branch' => $terkumpul[0]['rincian']['branch_id'] ?? null,
                /*
                | Selalu terisi: seluruh dokumen wajib satu department, dan
                | aturan itu sudah ditegakkan di atas.
                */
                'department_id' => $terkumpul[0]['rincian']['department_id'] ?? null,
                'transaction_category_id' => $satuNilai('transaction_category_id'),

                'total_amount' => collect($terkumpul)
                    ->sum(fn ($s) => (float) ($s['rincian']['total_amount'] ?? 0)),
            ],
        ];
    }

    /**
     * @param  array<string, mixed>  $validated
     * @return array<string, mixed>
     */
    private function isianVoucher(array $validated): array
    {
        return [
            'bdv_number' => trim((string) $validated['bdv_number']),
            'date' => $validated['date'],
            'cash_bank_account_no' => trim((string) $validated['cash_bank_account_no']),
            'cash_bank_account_name' => $validated['cash_bank_account_name'] ?? null,
            'check_number' => $validated['check_number'] ?? null,
            'payee' => $validated['payee'] ?? null,
            'notes' => $validated['notes'] ?? null,
        ];
    }

    /**
     * Pilihan akun dari layar, dikunci menurut nomor baris.
     *
     * @param  array<int, array<string, mixed>>  $items
     * @return array<int, array{no: ?string, name: ?string}>
     */
    private function akunPerBaris(array $items): array
    {
        $akun = [];

        foreach ($items as $satu) {
            $baris = (int) ($satu['line_no'] ?? 0);

            if ($baris <= 0) {
                continue;
            }

            $akun[$baris] = [
                'no' => isset($satu['accurate_account_no'])
                    ? (trim((string) $satu['accurate_account_no']) ?: null)
                    : null,

                'name' => isset($satu['accurate_account_name'])
                    ? (trim((string) $satu['accurate_account_name']) ?: null)
                    : null,
            ];
        }

        return $akun;
    }

    /**
     * Nomor baris pertama yang dikirim layar tetapi tidak ada pada dokumennya.
     *
     * Ditolak, bukan didiamkan: baris yang tidak dikenal berarti layar dan
     * server sedang melihat dokumen yang berbeda, dan menyimpannya diam-diam
     * memasangkan akun ke baris yang salah.
     *
     * @param  array<int, mixed>  $akun
     * @param  array<int, array<string, mixed>>  $rincianItem
     */
    private function barisTakDikenal(array $akun, array $rincianItem): ?int
    {
        $dikenal = collect($rincianItem)->pluck('no')->map(fn ($n) => (int) $n)->all();

        foreach (array_keys($akun) as $baris) {
            if (!in_array((int) $baris, $dikenal, true)) {
                return (int) $baris;
            }
        }

        return null;
    }

    /**
     * Menyalin rincian dokumen sumber ke bdv_items, beserta akun pilihannya.
     *
     * Barisnya disamakan dengan dokumen: yang hilang dibuang, yang ada
     * diperbarui, yang baru ditambahkan. Dikerjakan dengan updateOrCreate
     * menurut (bdv_id, line_no) -- pasangan yang sama dengan indeks uniknya,
     * sehingga pengiriman ganda tidak melahirkan rincian kembar.
     *
     * @param  array<int, array<string, mixed>>  $rincianItem
     * @param  array<int, array{no: ?string, name: ?string}>  $akun
     */
    /*
    |--------------------------------------------------------------------------
    | Menuliskan daftar dokumen sumber beserta rinciannya
    |--------------------------------------------------------------------------
    | Dipakai menyimpan maupun mengubah. Dokumen yang DIKELUARKAN dari voucher
    | tidak langsung dibuang: lihat keterangan di bawah.
    |
    | @param  array<int, array<string, mixed>>  $terkumpul  hasil kumpulkanSumber
    |--------------------------------------------------------------------------
    */
    private function sinkronkanSumber(BankDisbursementVoucher $bdv, array $terkumpul): void
    {
        $idTerpakai = [];

        foreach ($terkumpul as $satu) {
            $rincian = $satu['rincian'];

            $sumber = BdvSource::updateOrCreate(
                [
                    'bdv_id' => $bdv->id,
                    'document_type' => $satu['kode'],
                    'source_id' => $rincian['id'],
                ],
                [
                    /*
                    | Salinan beku keadaan dokumennya saat ini. Dokumen
                    | sumbernya masih bisa direvisi Finance sesudah voucher
                    | diajukan, dan voucher yang sudah ditandatangani tidak
                    | boleh ikut berubah diam-diam.
                    */
                    'source_number' => $rincian['number'] ?? null,
                    'branch' => $rincian['branch_id'] ?? null,
                    'department_id' => $rincian['department_id'] ?? null,
                    'transaction_category_id' => $rincian['transaction_category_id'] ?? null,
                    'total_amount' => $rincian['total_amount'] ?? 0,
                    'sort_order' => $satu['urutan'],

                    /*
                    | Dokumen yang sempat dikeluarkan lalu dipilih lagi
                    | dihidupkan kembali -- sama seperti baris rincian yang
                    | sempat hilang.
                    */
                    'released_at' => null,
                ],
            );

            $idTerpakai[] = $sumber->id;

            $this->sinkronkanItem($bdv, $sumber, $rincian['items'], $satu['akun']);
        }

        /*
        |----------------------------------------------------------------------
        | Dokumen yang dikeluarkan dari voucher
        |----------------------------------------------------------------------
        | Rinciannya diperlakukan persis seperti baris yang hilang: yang belum
        | pernah sampai Accurate dibuang, yang sudah di sana hanya DITANDAI --
        | penghapusannya dititipkan ke pengiriman berikutnya, karena
        | membuangnya sekarang berarti kehilangan accurate_detail_id-nya.
        |
        | Baris sumbernya sendiri DILEPAS (released_at), bukan dihapus. Dua
        | sebabnya: dokumennya harus segera bebas diangkat voucher lain, dan
        | menghapusnya akan ikut menyeret rincian bernisan yang masih
        | menunggu dibereskan di Accurate -- relasinya cascade.
        |----------------------------------------------------------------------
        */
        $dikeluarkan = BdvSource::where('bdv_id', $bdv->id)
            ->when($idTerpakai !== [], fn ($q) => $q->whereNotIn('id', $idTerpakai))
            ->get();

        foreach ($dikeluarkan as $sumber) {
            $itemnya = BdvItem::where('bdv_source_id', $sumber->id);

            (clone $itemnya)->whereNull('accurate_detail_id')->delete();

            (clone $itemnya)
                ->whereNotNull('accurate_detail_id')
                ->whereNull('removed_at')
                ->update(['removed_at' => now()]);

            if ($sumber->released_at === null) {
                $sumber->forceFill(['released_at' => now()])->save();
            }

            /* Tidak ada sisa apa pun yang perlu dibereskan -> boleh dibuang. */
            if (BdvItem::where('bdv_source_id', $sumber->id)->count() === 0) {
                $sumber->delete();
            }
        }
    }

    private function sinkronkanItem(
        BankDisbursementVoucher $bdv,
        BdvSource $sumber,
        array $rincianItem,
        array $akun,
    ): void {
        $nomorBaris = [];

        foreach ($rincianItem as $item) {
            $baris = (int) $item['no'];

            $nomorBaris[] = $baris;

            BdvItem::updateOrCreate(
                [
                    'bdv_source_id' => $sumber->id,
                    'line_no' => $baris,
                ],
                [
                    'bdv_id' => $bdv->id,
                    'item_date' => $item['date'] ?? null,
                    'description' => $item['description'] ?? null,
                    'category' => $item['category'] ?? null,
                    'qty' => $item['qty'] ?? null,
                    'unit_price' => $item['unit_price'] ?? null,
                    'amount' => $item['amount'] ?? 0,

                    'accurate_account_no' => $akun[$baris]['no'] ?? null,
                    'accurate_account_name' => $akun[$baris]['name'] ?? null,

                    /*
                    | Baris yang sempat hilang lalu muncul lagi dihidupkan
                    | kembali. accurate_detail_id-nya SENGAJA tidak ikut
                    | dikosongkan: bila penghapusannya dulu gagal terkirim,
                    | barisnya masih ada di Accurate dan id itulah yang
                    | menautkan keduanya.
                    */
                    'removed_at' => null,
                ],
            );
        }

        $hilang = BdvItem::where('bdv_source_id', $sumber->id)
            ->when($nomorBaris !== [], fn ($q) => $q->whereNotIn('line_no', $nomorBaris));

        /*
        | Yang belum pernah sampai Accurate langsung dibuang -- tidak ada apa
        | pun di sana yang harus dibereskan untuknya.
        */
        (clone $hilang)->whereNull('accurate_detail_id')->delete();

        /*
        | Yang sudah terlanjur ada di Accurate hanya DITANDAI. Membuangnya
        | sekarang berarti kehilangan accurate_detail_id-nya, dan bersamanya
        | satu-satunya cara menghapus baris itu di sana. Penghapusan
        | sesungguhnya dititipkan ke pengiriman berikutnya.
        */
        (clone $hilang)
            ->whereNotNull('accurate_detail_id')
            ->whereNull('removed_at')
            ->update(['removed_at' => now()]);
    }

    /**
     * Menerjemahkan pelanggaran indeks unik menjadi kalimat yang bisa dibaca.
     *
     * Keduanya memang dijaga basis data, bukan hanya kode: dua orang yang
     * menekan Simpan pada saat hampir bersamaan lolos dari pemeriksaan di
     * kode, tetapi tidak dari indeksnya. Yang sampai ke layar tidak boleh
     * berupa pesan Postgres.
     */
    private function jawabBentrok(QueryException $e): JsonResponse
    {
        $pesan = $e->getMessage();

        /*
        | Nama indeksnya pindah bersama tabelnya: dokumen sumber kini di
        | bdv_sources, dan yang menjaganya bdv_sources_document_active_unique.
        | Nama lamanya tetap dikenali supaya basis data yang migrasinya
        | tertinggal tidak menjawab dengan pesan Postgres mentah.
        */
        if (
            str_contains($pesan, 'bdv_sources_document_active_unique')
            || str_contains($pesan, 'bdv_sources_bdv_document_unique')
            || str_contains($pesan, 'bank_disbursement_vouchers_source_unique')
        ) {
            return response()->json([
                'success' => false,
                'message' => __('bdv_messages.duplicate_source'),
            ], 422);
        }

        if (str_contains($pesan, 'bdv_number')) {
            return response()->json([
                'success' => false,
                'message' => __('bdv_messages.number_taken'),
            ], 422);
        }

        Log::error('[BDV] Gagal menyimpan', ['message' => $pesan]);

        return response()->json([
            'success' => false,
            'message' => __('bdv_messages.save_failed'),
        ], 500);
    }

    /**
     * @return array<string, mixed>
     */
    /*
    |--------------------------------------------------------------------------
    | Kolom dokumen untuk pemeriksaan scope aksi
    |--------------------------------------------------------------------------
    | Disebutkan sekali di sini, bukan diulang di tiap pemanggilan: ketiga
    | nama kolom ini berbeda antar modul, dan satu salah ketik pada salah
    | satunya menjadikan pemeriksaan scope diam-diam tidak berlaku.
    |--------------------------------------------------------------------------
    */
    private const KOLOM_SCOPE = [
        'branch' => 'branch',
        'department' => 'department_id',
        'owner' => 'created_by',
    ];

    /**
     * Isi voucher ini masih boleh diketik.
     *
     * DRAFT dan DOKUMEN REVISIAN, bukan draft saja. Approver yang
     * mengembalikan voucher menyuruh memperbaikinya; kalau pintu ini
     * tertutup, yang diminta memperbaiki tidak punya satu pun cara
     * melakukannya.
     *
     * Ditulis SEKALI dan dipakai tiga tempat -- update(), bentukBaris(),
     * dan bdvUntukPilihan() -- karena ketiganya pernah berselisih: dua
     * sudah mengizinkan dokumen revisian sementara yang ketiga masih
     * menuntut draft, sehingga halaman ubah terbuka tetapi daftar
     * dokumennya menjawab "tidak ditemukan" pada voucher yang ada di
     * layar. Yang berselisih bukan logikanya, melainkan salinannya.
     *
     * Yang sudah diajukan, disetujui, ditolak, atau dibatalkan tetap
     * tertutup -- isinya sedang dinilai orang, atau sudah selesai dinilai.
     */
    private function masihBisaDiketik(BankDisbursementVoucher $bdv): bool
    {
        return in_array(strtoupper(trim((string) $bdv->status)), [
            BankDisbursementVoucher::STATUS_DRAFT,
            BankDisbursementVoucher::STATUS_NEED_REVISION,
        ], true);
    }

    /**
     * Akun ini boleh melakukan aksi itu pada BDV ini.
     *
     * Dua syarat, dan keduanya perlu: scope izinnya mencakup dokumen ini,
     * DAN statusnya masih memungkinkan. Izin tanpa scope yang cocok berarti
     * "boleh pada dokumen lain, bukan yang ini".
     */
    private function bolehAksi(
        ?object $user,
        string $permission,
        BankDisbursementVoucher $bdv,
    ): bool {
        if (!$user || !$user->hasPermission($permission)) {
            return false;
        }

        return DocumentActionScope::allows($user, $permission, $bdv, self::KOLOM_SCOPE);
    }

    private function bentukBaris(BankDisbursementVoucher $bdv, ?object $user = null): array
    {
        $statusSekarang = strtoupper(trim((string) $bdv->status));

        $draft = $statusSekarang === BankDisbursementVoucher::STATUS_DRAFT;

        /*
        | Dokumen revisian disunting lewat pintu yang sama dengan draft.
        | Dihitung sekali di sini supaya can_update dan can_submit tidak
        | pernah berbeda pendapat soal mana yang masih boleh diketik.
        */
        $bisaDiketik = $this->masihBisaDiketik($bdv);

        /*
        | Dihitung SEKALI, dipakai tiga tombol.
        |
        | bolehMemutuskan() menanyai rantai approval-nya, jadi memanggilnya
        | tiga kali berarti tiga kali kueri yang sama untuk satu baris.
        */
        $bolehMemutuskan = $this->bolehMemutuskan($user, $bdv);

        return [
            'public_id' => $bdv->encrypted_id,
            'bdv_number' => $bdv->bdv_number,
            'date' => optional($bdv->date)->toDateString(),

            /*
            | Ringkasan dokumen sumbernya, bukan satu dokumen lagi.
            |
            | Daftar menampilkan nomornya berjajar; bila lebih dari satu,
            | layar menyebut jumlahnya. Dimuat lewat relasi supaya daftar
            | sepanjang apa pun tetap satu query tambahan, bukan satu per
            | baris.
            */
            'source_numbers' => $bdv->relationLoaded('sources')
                ? $bdv->sources->pluck('source_number')->filter()->values()->all()
                : $bdv->sources()->pluck('source_number')->filter()->values()->all(),

            'document_types' => $bdv->relationLoaded('sources')
                ? $bdv->sources->pluck('document_type')->unique()->values()->all()
                : $bdv->sources()->pluck('document_type')->unique()->values()->all(),

            'branch' => $bdv->branchData
                ? trim(
                    trim((string) $bdv->branchData->inisial_cabang) !== ''
                        ? $bdv->branchData->inisial_cabang . ' - ' . $bdv->branchData->nama_cabang
                        : (string) $bdv->branchData->nama_cabang,
                )
                : null,

            'department' => $bdv->departmentData
                ? trim(
                    trim((string) $bdv->departmentData->kode) !== ''
                        ? $bdv->departmentData->kode . ' - ' . $bdv->departmentData->nama
                        : (string) $bdv->departmentData->nama,
                )
                : null,

            'total_amount' => (float) $bdv->total_amount,
            'status' => $bdv->status,
            'requester' => $bdv->creator->name ?? null,
            'created_at' => $bdv->created_at,

            /*
            | Jejak Accurate ikut dikirim ke daftar, bukan hanya ke detail.
            | Yang kosong berarti dokumen itu belum sampai pembukuan -- hal
            | yang harus bisa dilihat sekilas tanpa membuka satu per satu.
            */
            'accurate_id' => $bdv->accurate_id,
            'accurate_number' => $bdv->accurate_number,
            'accurate_sent_at' => $bdv->accurate_sent_at,

            /*
            | Yang dipakai layar untuk menandai voucher yang tertinggal --
            | BUKAN accurate_id.
            |
            | Voucher yang pernah terkirim lalu perubahannya ditolak punya
            | accurate_id terisi, jadi penanda yang dihitung dari situ tidak
            | akan pernah muncul untuknya. Padahal justru keadaan itu yang
            | paling mudah luput: vouchernya kelihatan normal, dan hanya
            | angkanya yang tertinggal di pembukuan.
            */
            'accurate_in_sync' => (bool) $bdv->accurate_in_sync,

            /*
            | Apa yang boleh dikerjakan ORANG INI pada BARIS INI -- dihitung
            | di sini, bukan disimpulkan layar.
            |
            | Layar hanya tahu "punya izin bdv.update atau tidak", dan itu
            | tidak cukup. Scope-nya bisa OWN_DATA, OWN_CABANG, atau
            | OWN_DEPARTMENT, sehingga izin yang sama berarti boleh pada satu
            | baris dan tidak boleh pada baris sebelahnya. Menu yang disusun
            | dari izin saja akan menawarkan Ubah pada dokumen yang pasti
            | ditolak server -- dan orangnya tidak akan tahu kenapa.
            |
            | TIAP AKSI MEMBACA SCOPE IZINNYA SENDIRI. "Lihat semua, hapus
            | miliknya sendiri" harus berbunyi persis seperti itu.
            */
            'can_update' => $bisaDiketik && $this->bolehAksi($user, 'bdv.update', $bdv),

            /*
            | Menghapus tetap DRAFT SAJA, sengaja tidak ikut dilonggarkan.
            |
            | Voucher revisian sudah berdiri di Accurate dan sudah pernah
            | dinilai orang. Membuangnya berarti menghapusnya di sana juga,
            | dan meninggalkan approver yang menunggu perbaikan dengan
            | dokumen yang lenyap tanpa kabar. Jalan keluarnya Batalkan,
            | yang meninggalkan jejak.
            */
            'can_delete' => $draft && $this->bolehAksi($user, 'bdv.delete', $bdv),

            /*
            | Berapa kali voucher ini pernah dikembalikan. Dipakai layar
            | untuk menandai barisnya "Revisi ke-N" -- angka yang menjelaskan
            | kenapa sebuah voucher tua masih berstatus draft.
            */
            'revision_count' => (int) ($bdv->revision_count ?? 0),

            /*
            | Boleh tidaknya dicetak: statusnya saja, bukan izin tersendiri.
            |
            | Siapa pun yang boleh melihat vouchernya boleh mencetaknya --
            | cetakan ini tidak memperlihatkan apa pun yang tidak ada di
            | layar detail. Yang dijaga justru keadaannya: hanya yang sudah
            | disetujui penuh, karena kertas bertanda tangan beredar lebih
            | jauh daripada layar mana pun.
            */
            'can_print' => $statusSekarang === BankDisbursementVoucher::STATUS_APPROVED,

            /*
            | Boleh tidaknya mengajukan: izin, cakupan, DAN status.
            |
            | Dokumen revisian ikut, karena ia masuk lewat pintu yang sama
            | dengan draft -- lihat keterangan pada submit().
            */
            'can_submit' => $bisaDiketik && $this->bolehAksi($user, 'bdv.submit', $bdv),

            /*
            | Menyetujui dan menolak: ditentukan RANTAI APPROVAL, bukan izin.
            |
            | Izin bdv.approve hanya menjawab "orang ini termasuk yang pernah
            | boleh menyetujui BDV". Yang menentukan sekarang giliran siapa
            | adalah baris bdv_approvals bertahap WAITING -- dan hanya itu.
            | Menghitungnya dari izin saja akan menawarkan tombol Setujui
            | kepada setiap pemegang izin pada setiap dokumen, termasuk yang
            | tahapannya masih menunggu orang lain.
            |
            | Keduanya dihitung terpisah meski sekarang selalu sama: menolak
            | kelak bisa punya syarat sendiri, dan menyatukannya menjadi satu
            | nilai akan menyembunyikan perbedaan itu saat tiba waktunya.
            */
            'can_approve' => $bolehMemutuskan,
            'can_reject' => $bolehMemutuskan,

            /*
            | Pilihan ketiga di meja yang sama, jadi penjaganya pun sama.
            | Dikirim sebagai kunci tersendiri walau nilainya kini selalu
            | sama dengan dua di atasnya: mengembalikan kelak bisa punya
            | syaratnya sendiri -- batas jumlah revisi, misalnya -- dan
            | layar yang membaca can_approve untuk tombol Kembalikan akan
            | menyembunyikan perbedaan itu saat tiba waktunya.
            */
            'can_return_for_revision' => $bolehMemutuskan,
        ];
    }

    /**
     * Orang ini approver tahap yang sedang menunggu pada voucher ini.
     *
     * Izin diperiksa lebih dulu supaya pemegang baris approval yang izinnya
     * sudah dicabut tidak lagi ditawari tombolnya -- rantai approval dibekukan
     * saat pengajuan, sedangkan izin bisa berubah kapan saja sesudahnya.
     */
    private function bolehMemutuskan(?object $user, BankDisbursementVoucher $bdv): bool
    {
        if (!$user || !$user->hasPermission('bdv.approve')) {
            return false;
        }

        if (strtoupper(trim((string) $bdv->status)) !== BankDisbursementVoucher::STATUS_IN_PROGRESS) {
            return false;
        }

        return $this->approvalService->barisMilikUser($bdv, $user) !== null;
    }

    /**
     * Riwayat approval dalam bentuk yang dibaca layar.
     *
     * @return array<int, array<string, mixed>>
     */
    private function riwayatApproval(BankDisbursementVoucher $bdv): array
    {
        return $bdv->approvals()
            ->get()
            ->map(fn (BdvApproval $baris): array => [
                'step' => (int) $baris->step_order,
                'role' => $baris->label ?: ('Step ' . $baris->step_order),

                /*
                | Nama yang dibekukan saat rantai dibuat, atau nama orang yang
                | benar-benar menindak bila sudah ada. Keduanya kolom yang
                | sama: generator mengisinya dengan nama approver yang
                | ditunjuk, lalu ditimpa nama penandatangan sesungguhnya.
                */
                'approver' => $baris->approver_name_snapshot,

                'status' => $baris->status,
                'acted_at' => $baris->approved_at ?? $baris->rejected_at,
                'notes' => $baris->notes,
            ])
            ->values()
            ->all();
    }

    /*
    |--------------------------------------------------------------------------
    | Cetakan PDF
    |--------------------------------------------------------------------------
    | Dua pintu, sama seperti FPU dan Claim:
    |
    |   generatePrintUrl()  dipanggil layar; mengembalikan tautan bertanda
    |                       tangan yang berlaku sepuluh menit.
    |   printSigned()       dibuka tab barunya; di luar middleware auth,
    |                       yang menjaganya tanda tangan URL itu.
    |
    | SELURUH PILIHAN IKUT DITANDATANGANI. Bahasa, lampiran, dan dokumen
    | sumber dimasukkan ke dalam parameter yang ditandatangani -- bukan
    | ditempelkan ke URL hasilnya. Parameter di luar tanda tangan bisa
    | diubah siapa pun yang memegang tautannya, dan yang diubah di sini
    | menentukan berkas siapa saja yang ikut tercetak.
    |--------------------------------------------------------------------------
    */
    public function generatePrintUrl(Request $request, string $publicId): JsonResponse
    {
        $user = $request->user();

        if (!$user || !$user->hasPermission('bdv.view')) {
            return response()->json([
                'success' => false,
                'message' => __('bdv_messages.forbidden_view'),
            ], 403);
        }

        $bdv = $this->temukanUntukDilihat($user, $publicId);

        if ($bdv instanceof JsonResponse) {
            return $bdv;
        }

        $penahan = $this->penahanCetak($bdv);

        if ($penahan !== null) {
            return $penahan;
        }

        $pilihan = [
            'publicId' => $publicId,
            'lang' => $this->bahasaCetak($request),
        ];

        if ($request->boolean('with_attachments')) {
            $pilihan['with_attachments'] = 1;
        }

        if ($request->boolean('with_sources')) {
            $pilihan['with_sources'] = 1;
        }

        $relatif = URL::temporarySignedRoute(
            'cash-bank.bdv.print-signed',
            now()->addMinutes(10),
            $pilihan,
            false,
        );

        return response()
            ->json([
                'success' => true,
                'url' => rtrim(config('app.url'), '/') . $relatif,
            ])
            ->header('Content-Type', 'application/json; charset=UTF-8');
    }

    public function printSigned(Request $request, string $publicId)
    {
        return $this->print($request, $publicId);
    }

    public function print(Request $request, string $publicId)
    {
        try {
            $id = (int) Crypt::decryptString($publicId);

            $bdv = BankDisbursementVoucher::with([
                'branchData:id,nama_cabang,inisial_cabang',
                'departmentData:id,kode,nama',
                'transactionCategory:id,name',
                'creator:id,name',
                'submitter:id,name',
                'items',
                'sources',
                'approvals',
            ])->findOrFail($id);

            $penahan = $this->penahanCetak($bdv);

            if ($penahan !== null) {
                return $penahan;
            }

            $bahasa = $this->bahasaCetak($request);

            $total = (float) (
                $bdv->total_amount
                ?: $bdv->items->sum(fn (BdvItem $item): float => (float) ($item->amount ?? 0))
            );

            $penyusun = app(BdvPrintComposer::class);

            $denganLampiran = $request->boolean('with_attachments');
            $denganSumber = $request->boolean('with_sources');

            /*
            | Lampiran voucher hanya dikumpulkan bila dokumen sumbernya TIDAK
            | ikut dicetak. Kalau ikut, lampirannya menempel di belakang
            | masing-masing dokumen -- mengumpulkannya di sini juga berarti
            | gambar yang sama tercetak dua kali.
            */
            $lampiran = ($denganLampiran && !$denganSumber)
                ? $penyusun->lampiranSeluruhSumber($bdv)
                : ['pages' => [], 'others' => []];

            $dasar = [
                'bdv' => $bdv,
                'bahasa' => $bahasa,
                'totalAmount' => $total,
                'terbilang' => AmountWords::of($total, $bahasa),
                'asalBaris' => $this->asalBarisCetak($bdv),
                'requester' => $this->penandaTanganPemohon($bdv),
                'approvers' => $this->penandaTanganPenyetuju($bdv),
                'attachmentPages' => $lampiran['pages'],
                'attachmentOthers' => $lampiran['others'],
            ];

            $nama = 'BDV-' . $this->namaBerkasAman(
                (string) $bdv->bdv_number,
                (string) $bdv->id,
            );

            if ($denganSumber) {
                $blok = $penyusun->blokSumber($bdv, $denganLampiran);

                $pdf = Pdf::loadView('pdf.bdv-combined', array_merge($dasar, [
                    'judul' => trim(
                        (string) $bdv->bdv_number . ' + '
                        . collect($blok)->pluck('number')->filter()->implode(' + '),
                        ' +',
                    ),
                    'blokSumber' => $blok,
                ]))->setPaper('a4', 'portrait');

                return $this->jawabanPdf($pdf->output(), $nama . '-GABUNG');
            }

            $pdf = Pdf::loadView('pdf.bdv', $dasar)->setPaper('a4', 'portrait');

            return $this->jawabanPdf($pdf->output(), $nama);
        } catch (DecryptException | ModelNotFoundException $e) {
            return response()->json([
                'success' => false,
                'message' => __('bdv_print.not_found'),
            ], 404);
        } catch (\Throwable $e) {
            Log::error('[BDV] Gagal mencetak', [
                'public_id' => $publicId,
                'message' => $e->getMessage(),
                'file' => $e->getFile(),
                'line' => $e->getLine(),
            ]);

            return response()->json([
                'success' => false,
                'message' => __('bdv_print.failed'),
                'debug' => app()->environment('local') ? $e->getMessage() : null,
            ], 500);
        }
    }

    /**
     * Penahan cetak: hanya voucher yang SUDAH DISETUJUI PENUH.
     *
     * Draft, yang masih berjalan, yang dikembalikan, yang ditolak, dan yang
     * dibatalkan sama-sama ditolak. Alasannya satu: cetakan ini berisi
     * gambar tanda tangan orang. Mencetak dokumen yang rantainya belum
     * tuntas menghasilkan kertas yang kelihatan sah padahal belum, dan
     * kertas semacam itu beredar lebih jauh daripada layar mana pun.
     *
     * Ditegakkan DI SERVER, dan diperiksa dua kali -- saat tautannya dibuat
     * dan saat tautannya dibuka. Tautan bertanda tangan berlaku sepuluh
     * menit dan bisa disimpan orang; statusnya bisa berubah di antara
     * keduanya.
     */
    private function penahanCetak(BankDisbursementVoucher $bdv): ?JsonResponse
    {
        if (
            strtoupper(trim((string) $bdv->status)) === BankDisbursementVoucher::STATUS_APPROVED
        ) {
            return null;
        }

        return response()->json([
            'success' => false,
            'message' => __('bdv_print.not_printable'),
        ], 422);
    }

    /**
     * Bahasa cetakannya: 'id' atau 'en', tidak ada yang lain.
     *
     * Nilai asing jatuh ke Indonesia alih-alih ditolak. Tautan cetak
     * bertanda tangan bisa dibuka berhari-hari kemudian dari riwayat
     * peramban, dan gagal mencetak karena satu huruf pada parameter bahasa
     * lebih merepotkan daripada tercetak dalam bahasa bawaan.
     */
    private function bahasaCetak(Request $request): string
    {
        $bahasa = strtolower(trim((string) $request->input('lang', 'id')));

        return in_array($bahasa, ['id', 'en'], true) ? $bahasa : 'id';
    }

    /**
     * Nomor dokumen dan cabang tiap baris, dikunci bdv_source_id.
     *
     * Dibentuk di sini, bukan di blade: nama cabangnya harus dicari ke
     * tabel cabang -- bdv_sources menyimpan id-nya sebagai teks -- dan
     * kueri di dalam blade berarti satu kueri per baris yang tercetak.
     *
     * @return array<int, array{number: string, branch: string}>
     */
    private function asalBarisCetak(BankDisbursementVoucher $bdv): array
    {
        $sumber = $bdv->relationLoaded('sources') ? $bdv->sources : $bdv->sources()->get();

        $idCabang = $sumber
            ->pluck('branch')
            ->map(fn ($nilai): int => (int) $nilai)
            ->filter(fn (int $id): bool => $id > 0)
            ->unique()
            ->values();

        $cabang = $idCabang->isEmpty()
            ? collect()
            : Cabang::query()
                ->whereIn('id', $idCabang->all())
                ->get(['id', 'inisial_cabang', 'nama_cabang'])
                ->keyBy('id');

        $hasil = [];

        foreach ($sumber as $satu) {
            $data = $cabang->get((int) $satu->branch);

            $hasil[(int) $satu->id] = [
                'number' => (string) ($satu->source_number ?? ''),

                /*
                | Inisialnya yang dipakai bila ada, bukan nama panjangnya:
                | kolom ini sempit, dan "HO" terbaca utuh sedangkan "Kantor
                | Pusat" pecah dua baris pada tiap barisnya.
                */
                'branch' => $data
                    ? trim((string) ($data->inisial_cabang ?: $data->nama_cabang))
                    : '',
            ];
        }

        return $hasil;
    }

    /**
     * Blok tanda tangan "Dibuat Oleh".
     */
    private function penandaTanganPemohon(BankDisbursementVoucher $bdv): object
    {
        return (object) [
            'name' => $bdv->submitter?->name ?? $bdv->creator?->name ?? '-',

            /*
            | BDV tidak menyimpan tanda tangan pemohonnya sendiri -- tidak
            | ada kolom requester_signature_path seperti di FPU dan Claim.
            | Diambil dari profil akunnya, supaya kolom "Dibuat Oleh" tidak
            | selalu kosong sementara kolom penyetuju di sebelahnya terisi.
            */
            'signature_file' => $this->berkasTandaTangan(
                $bdv->submitter?->signature_path ?? $bdv->creator?->signature_path ?? null,
            ),

            'signed_at' => $bdv->submitted_at,
        ];
    }

    /**
     * Blok tanda tangan "Disetujui Oleh".
     *
     * Hanya baris yang benar-benar APPROVED. Baris SKIPPED pada mode ANY
     * tidak pernah ditandatangani siapa pun, dan mencetaknya sebagai kolom
     * kosong terbaca seperti persetujuan yang belum diberikan.
     */
    private function penandaTanganPenyetuju(BankDisbursementVoucher $bdv)
    {
        return $bdv->approvals
            ->filter(
                fn (BdvApproval $baris): bool => strtoupper(trim((string) $baris->status))
                    === BdvApproval::STATUS_APPROVED,
            )
            ->sortBy(
                fn (BdvApproval $baris): string => sprintf(
                    '%010d-%010d',
                    (int) $baris->step_order,
                    (int) $baris->id,
                ),
            )
            ->map(fn (BdvApproval $baris): object => (object) [
                'label' => $baris->label ?: null,
                'name' => $baris->approver_name_snapshot ?? '-',
                'signature_file' => $this->berkasTandaTangan($baris->signature_path),
                'signed_at' => $baris->approved_at ?? $baris->signed_at,
            ])
            ->values();
    }

    /**
     * Path yang bisa dibaca DomPDF.
     *
     * DomPDF membaca berkas dari disk, bukan lewat URL: gambar yang dirujuk
     * dengan tautan http diam-diam tidak tergambar, tanpa galat.
     */
    private function berkasTandaTangan(?string $path): ?string
    {
        if (blank($path)) {
            return null;
        }

        return storage_path('app/public/' . ltrim($path, '/'));
    }

    private function namaBerkasAman(string $nomor, string $cadangan): string
    {
        $nama = trim((string) preg_replace('/[^A-Za-z0-9._-]+/', '-', $nomor), '-');

        return $nama !== '' ? $nama : $cadangan;
    }

    private function jawabanPdf(string $isi, string $namaBerkas)
    {
        $jawaban = response()->make($isi, 200);

        $jawaban->headers->set('Content-Type', 'application/pdf');

        /* inline supaya PDF terbuka langsung di tab, bukan terunduh. */
        $jawaban->headers->set(
            'Content-Disposition',
            'inline; filename="' . $namaBerkas . '.pdf"',
        );

        return $jawaban;
    }

    /*
    |--------------------------------------------------------------------------
    | Batas akses
    |--------------------------------------------------------------------------
    | Memakai scope pada permission, persis seperti modul pengajuan dana lain.
    |
    | BELUM ada bagian "approver selalu boleh melihat dokumen yang masuk ke
    | flow-nya", karena BDV memang belum punya tabel approval. Bagian itu
    | ditambahkan bersamaan dengan approval flow-nya nanti -- ditulis di sini
    | supaya tidak terlewat.
    |--------------------------------------------------------------------------
    */
    private function terapkanBatasAkses($query, $user): void
    {
        $scope = strtoupper(trim((string) ($user->getPermissionScope('bdv.view') ?? 'NONE')));

        if (!in_array($scope, self::ALLOWED_SCOPES, true)) {
            $scope = 'NONE';
        }

        /* ALL melihat semuanya; tidak perlu digabung dengan apa pun. */
        if ($scope === 'ALL') {
            return;
        }

        /*
        | Dua jalan masuk, dan dokumennya terlihat bila SALAH SATU terpenuhi:
        | cakupan izinnya, ATAU namanya tercantum pada rantai approval dokumen
        | itu.
        |
        | Yang kedua wajib ada. Approver ditunjuk oleh matriks approval, dan
        | matriks itu sering menunjuk orang di luar cabang atau department
        | dokumennya -- Finance Manager di HO yang menyetujui voucher cabang,
        | misalnya. Tanpa pengecualian ini ia diminta memutuskan dokumen yang
        | tidak bisa ia buka: tombolnya tidak muncul, endpoint-nya menjawab
        | 404, dan dokumennya berhenti di sana tanpa seorang pun tahu sebabnya.
        |
        | MELIHAT, BUKAN MENGUBAH. Pengecualian ini hanya berlaku di sini.
        | Ubah, hapus, dan ajukan tetap memakai DocumentActionScope, yang
        | sengaja tidak punya jalur khusus approver -- menyetujui bukan
        | mengubah.
        */
        $query->where(function ($terlihat) use ($user, $scope): void {
            $terlihat->where(function ($cakupan) use ($user, $scope): void {
                $this->aturanCakupan($cakupan, $user, $scope);
            });

            $terlihat->orWhereHas('approvals', function ($approval) use ($user): void {
                $this->cocokkanApprover($approval, $user);
            });
        });
    }

    /**
     * Aturan cakupan izin bdv.view, tanpa pengecualian approver.
     *
     * Dipisahkan supaya bisa dibungkus satu kelompok where() -- tanpa
     * pembungkus itu, OR pada pengecualian approver akan berlaku terhadap
     * SELURUH query, termasuk penyaring tanggal dan status di atasnya.
     */
    private function aturanCakupan($query, $user, string $scope): void
    {
        if ($scope === 'OWN_DATA') {
            $query->where('created_by', $user->id);

            return;
        }

        $penugasan = $this->penugasanAkses($user);

        $idCabang = collect([(int) ($user->cabang_id ?? 0)])
            ->merge($penugasan->pluck('branch_id')->map(fn ($id) => (int) $id))
            ->filter(fn ($id) => $id > 0)
            ->unique()
            ->values();

        $idDepartment = collect([(int) ($user->departemen_id ?? 0)])
            ->merge($penugasan->pluck('department_id')->map(fn ($id) => (int) $id))
            ->filter(fn ($id) => $id > 0)
            ->unique()
            ->values();

        if ($scope === 'OWN_CABANG') {
            $query->whereIn('branch', $idCabang->map(fn ($id) => (string) $id));

            return;
        }

        if ($scope === 'OWN_DEPARTMENT') {
            /*
            | Dicocokkan ke kepala voucher ATAU ke salah satu dokumen
            | sumbernya.
            |
            | department_id di kepala sengaja dikosongkan bila dokumennya
            | berasal dari beberapa department sekaligus. Memeriksa kepala
            | saja berarti voucher campur tidak terlihat oleh siapa pun yang
            | bercakupan OWN_DEPARTMENT -- termasuk orang yang department-nya
            | justru ada di dalamnya, dan yang pengeluarannya justru sedang
            | dicairkan di situ.
            */
            $query->where(function ($sub) use ($idDepartment) {
                $sub->whereIn('department_id', $idDepartment)
                    ->orWhereHas(
                        'sources',
                        fn ($s) => $s->whereIn('department_id', $idDepartment),
                    );
            });

            return;
        }

        /* NONE: hanya dokumen yang ia buat sendiri. */
        $query->where('created_by', $user->id);
    }

    /**
     * Baris approval yang menunggu keputusan orang ini SEKARANG.
     *
     * Dipakai dua kali -- untuk menyaring dan untuk mengurutkan -- dan
     * ditulis sekali supaya keduanya tidak mungkin berselisih: daftar yang
     * disaring "menunggu saya" tetapi barisnya tidak bertanda adalah
     * pertentangan yang tidak bisa dijelaskan kepada pemakainya.
     *
     * Empat syarat, dan keempatnya sengaja:
     *
     *   1. vouchernya berjalan -- yang sudah disetujui atau ditolak tidak
     *      menunggu siapa pun lagi;
     *   2. bukan voucher yang ia ajukan sendiri -- penjaga yang sama dengan
     *      BdvApprovalService::barisMilikUser(), sebab baris bertipe ROLE
     *      bisa menunjuk role yang ia terima SESUDAH mengajukan;
     *   3. barisnya masih WAITING;
     *   4. barisnya ada di tahap yang SEDANG berjalan, bukan tahap mana pun
     *      di depan. Tanpa syarat ini, approver tingkat tiga sudah melihat
     *      voucher yang tingkat satunya belum menjawab -- lengkap dengan
     *      penanda, tetapi tanpa tombol.
     *
     * Dibandingkan apa adanya ke status, tanpa UPPER/TRIM: ketiga nilainya
     * ditulis oleh kode ini sendiri lewat konstanta.
     */
    private function barisMenungguSaya($approval, $user): void
    {
        $approval
            ->whereRaw(
                'bank_disbursement_vouchers.status = ?',
                [BankDisbursementVoucher::STATUS_IN_PROGRESS],
            )
            ->whereRaw(
                'COALESCE(bank_disbursement_vouchers.submitted_by, bank_disbursement_vouchers.created_by, 0) <> ?',
                [(int) $user->id],
            )
            ->where('bdv_approvals.status', BdvApproval::STATUS_WAITING)
            ->whereRaw(
                'bdv_approvals.step_order = (
                    SELECT MIN(tahap.step_order)
                      FROM bdv_approvals AS tahap
                     WHERE tahap.bdv_id = bdv_approvals.bdv_id
                       AND tahap.status = ?
                )',
                [BdvApproval::STATUS_WAITING],
            );

        $this->cocokkanApprover($approval, $user);
    }

    /**
     * Baris approval yang ditujukan ke orang ini, langsung atau lewat role.
     *
     * Role ikut diperiksa karena rantai approval menyimpan baris bertipe ROLE
     * apa adanya -- yang dicairkan menjadi orang hanya baris bercakupan
     * cabang atau department. Memeriksa approver_id terhadap user id saja
     * akan melewatkan seluruh approver yang ditunjuk lewat jabatannya.
     */
    private function cocokkanApprover($query, $user): void
    {
        $idRole = DB::table('user_roles')
            ->where('user_id', $user->id)
            ->pluck('role_id')
            ->map(fn ($id): int => (int) $id)
            ->filter(fn (int $id): bool => $id > 0)
            ->values();

        $query->where(function ($cocok) use ($user, $idRole): void {
            $cocok->where(function ($langsung) use ($user): void {
                $langsung
                    ->where('bdv_approvals.approver_type', BdvApproval::APPROVER_TYPE_USER)
                    ->where('bdv_approvals.approver_id', $user->id);
            });

            if ($idRole->isNotEmpty()) {
                $cocok->orWhere(function ($lewatRole) use ($idRole): void {
                    $lewatRole
                        ->where('bdv_approvals.approver_type', BdvApproval::APPROVER_TYPE_ROLE)
                        ->whereIn('bdv_approvals.approver_id', $idRole->all());
                });
            }
        });
    }

    private function penugasanAkses($user)
    {
        if (!$user) {
            return collect();
        }

        try {
            $penugasan = DB::table('user_access_assignments')
                ->where('user_id', $user->id)
                ->where('is_active', true)
                ->select(['branch_id', 'department_id'])
                ->get()
                ->map(fn ($satu) => [
                    'branch_id' => (int) $satu->branch_id,
                    'department_id' => (int) $satu->department_id,
                ])
                ->filter(fn ($satu) => $satu['branch_id'] > 0 && $satu['department_id'] > 0)
                ->values();

            return collect(UserHomeAccess::gabungkan($user, $penugasan));
        } catch (\Throwable $e) {
            Log::warning('[BDV] Gagal membaca penugasan akses', [
                'user_id' => $user->id ?? null,
                'message' => $e->getMessage(),
            ]);

            return collect();
        }
    }
}
