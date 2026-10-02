<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\BusinessTrip;
use App\Models\BusinessTripArrangement;
use App\Models\BusinessTripArrangementFile;
use App\Services\BusinessTrip\BusinessTripArrangementNotifier;
use App\Services\BusinessTrip\BusinessTripPresenter;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

/*
|--------------------------------------------------------------------------
| Pemesanan perjalanan
|--------------------------------------------------------------------------
| Yang dikerjakan GA sesudah sebuah perdin tuntas disetujui: memesan hotel,
| tiket pesawat, dan transport, lalu menaruh buktinya pada perdin itu supaya
| pemohonnya tahu apa saja yang sudah diurus.
|
| Dipisahkan dari BusinessTripController dengan sengaja. Controller itu
| mengurus daur hidup dokumennya -- dibuat, diajukan, disetujui, dibatalkan.
| Pemesanan bukan bagian dari daur itu: ia terjadi sesudah dokumennya selesai,
| dikerjakan orang lain, dan tidak pernah mengubah status perdinnya.
|
| HANYA ADA MENCATAT DAN MEMBATALKAN.
|
| Tidak ada sunting dan tidak ada hapus, dan itu bukan kekurangan. Begitu
| sebuah pemesanan dicatat, pemohonnya langsung dikabari -- nomor tiket dan
| nama hotel itu sudah terlanjur dibaca dan dicatat orang. Menyuntingnya
| diam-diam berarti pemohon membawa keterangan yang sudah tidak berlaku ke
| bandara. Maka yang keliru dibatalkan secara terbuka beserta alasannya, lalu
| penggantinya dicatat sebagai baris baru.
|--------------------------------------------------------------------------
*/
class BusinessTripArrangementController extends Controller
{
    /** Sebesar lampiran di modul lain: 3 MB, dan hanya PDF atau gambar. */
    private const MAX_FILE_KB = 3000;

    private const ALLOWED_MIMES = 'jpg,jpeg,png,pdf';

    /**
     * Mencatat sebuah pemesanan.
     */
    public function store(
        string $publicId,
        Request $request,
        BusinessTripArrangementNotifier $notifier,
    ): JsonResponse {
        $user = $request->user();

        if (!$user || !$user->hasPermission('business_trip.arrange')) {
            return response()->json([
                'success' => false,
                'message' => __('business_trip_messages.forbidden_arrange'),
            ], 403);
        }

        $trip = app(BusinessTripController::class)->findVisible($publicId, $user);

        if (!$trip) {
            return response()->json([
                'success' => false,
                'message' => __('business_trip_messages.not_found'),
            ], 404);
        }

        if (($salah = $this->guardTripState($trip)) !== null) {
            return $salah;
        }

        $validated = $request->validate(
            $this->rules(),
            [],
            $this->attributeNames(),
        );

        /*
        | Pengganti sebuah pemesanan yang dibatalkan.
        |
        | Diperiksa terpisah dari validasi biasa karena syaratnya menyangkut
        | keadaan baris lain: ia harus milik perdin yang sama, sudah batal,
        | dan belum punya pengganti. Rantai penggantian yang bercabang tidak
        | bisa dibaca lagi -- mana di antara dua penggantinya yang berlaku?
        */
        $replaces = null;

        if (!empty($validated['replaces_id'])) {
            $replaces = BusinessTripArrangement::where('business_trip_id', $trip->id)
                ->whereKey((int) $validated['replaces_id'])
                ->first();

            if (
                !$replaces
                || !$replaces->isCancelled()
                || $replaces->replacement()->exists()
            ) {
                return response()->json([
                    'success' => false,
                    'message' => __('business_trip_messages.arrange_replaces_invalid'),
                ], 422);
            }
        }

        DB::beginTransaction();

        try {
            $arrangement = BusinessTripArrangement::create([
                'business_trip_id' => $trip->id,
                'type' => $validated['type'],
                'vendor_name' => $this->bersih($validated['vendor_name'] ?? null),
                'reference_no' => $this->bersih($validated['reference_no'] ?? null),
                'starts_at' => $validated['starts_at'] ?? null,
                'ends_at' => $validated['ends_at'] ?? null,
                'notes' => $this->bersih($validated['notes'] ?? null),
                'status' => BusinessTripArrangement::STATUS_ACTIVE,
                'replaces_id' => $replaces?->id,
                'created_by' => $user->id,
            ]);

            $this->simpanBerkas(
                $request->file('files', []),
                $trip,
                $arrangement,
                (int) $user->id,
            );

            DB::commit();
        } catch (\Throwable $e) {
            DB::rollBack();

            Log::error('[Perdin] Pemesanan gagal dicatat', [
                'trip_id' => $trip->id,
                'message' => $e->getMessage(),
            ]);

            return response()->json([
                'success' => false,
                'message' => __('business_trip_messages.arrange_failed'),
            ], 500);
        }

        /*
        | Kabarnya dikirim SESUDAH transaksinya tuntas.
        |
        | Di dalam transaksi, sebuah kegagalan di ujung akan membatalkan
        | penyimpanannya -- sementara emailnya sudah terlanjur terkirim, dan
        | email tidak bisa ditarik kembali.
        */
        $notifier->created($trip, $arrangement->fresh(['files']), $replaces);

        return response()->json([
            'success' => true,
            'message' => __('business_trip_messages.arrange_created'),
            'data' => BusinessTripPresenter::arrangement(
                $arrangement->fresh(['files', 'creator', 'canceller']),
            ),
        ], 201);
    }

    /**
     * Membatalkan sebuah pemesanan, beserta alasannya.
     *
     * Barisnya tetap tinggal dan berkasnya tidak disentuh. Pembatalan hotel
     * kadang berbiaya, dan biaya itu harus bisa dipertanggungjawabkan di
     * Realisasi -- menghapus vouchernya berarti menghapus bukti tagihannya.
     */
    public function cancel(
        string $publicId,
        int $arrangementId,
        Request $request,
        BusinessTripArrangementNotifier $notifier,
    ): JsonResponse {
        $user = $request->user();

        if (!$user || !$user->hasPermission('business_trip.arrange')) {
            return response()->json([
                'success' => false,
                'message' => __('business_trip_messages.forbidden_arrange'),
            ], 403);
        }

        $trip = app(BusinessTripController::class)->findVisible($publicId, $user);

        if (!$trip) {
            return response()->json([
                'success' => false,
                'message' => __('business_trip_messages.not_found'),
            ], 404);
        }

        /*
        | Alasannya wajib, sama seperti membatalkan perdinnya sendiri.
        |
        | Pemohon sudah menerima kabar bahwa hotelnya dipesan. Kabar
        | berikutnya mencabut kabar yang pertama, dan pencabutan tanpa
        | penjelasan hanya melahirkan pertanyaan ke GA lewat telepon.
        */
        $validated = $request->validate([
            'notes' => ['required', 'string', 'min:3', 'max:1000'],
        ], [], [
            'notes' => __('business_trip_messages.arrange_cancel_notes_label'),
        ]);

        $arrangement = BusinessTripArrangement::where('business_trip_id', $trip->id)
            ->whereKey($arrangementId)
            ->first();

        if (!$arrangement) {
            return response()->json([
                'success' => false,
                'message' => __('business_trip_messages.arrange_not_found'),
            ], 404);
        }

        if ($arrangement->isCancelled()) {
            return response()->json([
                'success' => false,
                'message' => __('business_trip_messages.arrange_already_cancelled'),
            ], 422);
        }

        $arrangement->update([
            'status' => BusinessTripArrangement::STATUS_CANCELLED,
            'cancelled_by' => $user->id,
            'cancelled_at' => now(),
            'cancellation_notes' => trim($validated['notes']),
        ]);

        $notifier->cancelled($trip, $arrangement->fresh(['files']));

        return response()->json([
            'success' => true,
            'message' => __('business_trip_messages.arrange_cancelled'),
            'data' => BusinessTripPresenter::arrangement(
                $arrangement->fresh(['files', 'creator', 'canceller']),
            ),
        ], 200);
    }

    /**
     * Daftar pemesanan sebuah perdin.
     *
     * Melihat mengikuti izin melihat perdinnya, bukan izin mengelolanya:
     * pemohon harus bisa membaca apa saja yang sudah diurus untuknya tanpa
     * ikut berwenang mencatat pemesanan orang lain.
     */
    public function index(string $publicId, Request $request): JsonResponse
    {
        $user = $request->user();

        if (!$user || !$user->hasPermission('business_trip.view')) {
            return response()->json([
                'success' => false,
                'message' => __('business_trip_messages.forbidden_view'),
            ], 403);
        }

        $trip = app(BusinessTripController::class)->findVisible($publicId, $user);

        if (!$trip) {
            return response()->json([
                'success' => false,
                'message' => __('business_trip_messages.not_found'),
            ], 404);
        }

        $trip->load(['arrangements.files', 'arrangements.creator', 'arrangements.canceller']);

        return response()->json([
            'success' => true,
            'data' => $trip->arrangements
                ->map(fn (BusinessTripArrangement $a): array => BusinessTripPresenter::arrangement($a))
                ->values()
                ->all(),

            /*
            | Rentang perjalanannya ikut dikirim sebagai pegangan, BUKAN
            | sebagai batas. Menginap semalam sebelum berangkat itu lumrah
            | untuk penerbangan pagi, begitu pula pulang sehari sesudahnya --
            | memblokir tanggal di luar rentang akan menolak pemesanan yang
            | justru benar. Yang dibutuhkan GA hanya rentangnya terlihat saat
            | mengisi, supaya salah ketik bulan ketahuan sendiri.
            */
            'trip_period' => [
                'depart_date' => optional($trip->depart_date)->toDateString(),
                'return_date' => optional($trip->return_date)->toDateString(),
            ],

            'can_arrange' => $user->hasPermission('business_trip.arrange')
                && $this->guardTripState($trip) === null,
        ], 200);
    }

    /*
    |--------------------------------------------------------------------------
    | Pendukung
    |--------------------------------------------------------------------------
    */

    /**
     * Perdinnya memang sedang boleh diurus pemesanannya.
     *
     * Dua syaratnya beralasan berbeda. Yang belum tuntas disetujui belum tentu
     * jadi berangkat -- memesan hotel untuk perjalanan yang masih mungkin
     * ditolak berarti menanggung biaya pembatalan tanpa dasar. Yang sudah
     * dibatalkan tidak akan berangkat sama sekali.
     */
    private function guardTripState(BusinessTrip $trip): ?JsonResponse
    {
        $status = strtoupper((string) $trip->status);

        if ($status === BusinessTrip::STATUS_CANCELLED) {
            return response()->json([
                'success' => false,
                'message' => __('business_trip_messages.arrange_trip_cancelled'),
            ], 422);
        }

        if ($status !== BusinessTrip::STATUS_APPROVED) {
            return response()->json([
                'success' => false,
                'message' => __('business_trip_messages.arrange_not_approved'),
            ], 422);
        }

        return null;
    }

    /**
     * @return array<string, mixed>
     */
    private function rules(): array
    {
        return [
            /*
            | Jenisnya satu-satunya yang wajib. Sisanya boleh kosong karena
            | vouchernya sendiri sudah memuat semuanya -- memaksa GA mengetik
            | ulang nomor booking yang sudah tercetak di berkasnya hanya
            | membuat kolomnya diisi tanda hubung.
            */
            'type' => ['required', 'string', Rule::in(BusinessTripArrangement::TYPES)],

            'vendor_name' => ['nullable', 'string', 'max:190'],
            'reference_no' => ['nullable', 'string', 'max:100'],
            'starts_at' => ['nullable', 'date'],
            'ends_at' => ['nullable', 'date', 'after_or_equal:starts_at'],
            'notes' => ['nullable', 'string', 'max:1000'],

            'replaces_id' => ['nullable', 'integer'],

            /*
            | Sekurang-kurangnya satu berkas. Pemesanan tanpa bukti tidak
            | menolong siapa pun: pemohon tidak bisa menunjukkan apa pun di
            | meja resepsionis, dan Finance tidak punya dasar apa pun.
            */
            'files' => ['required', 'array', 'min:1', 'max:10'],
            'files.*' => [
                'file',
                'mimes:' . self::ALLOWED_MIMES,
                'max:' . self::MAX_FILE_KB,
            ],
        ];
    }

    /**
     * @return array<string, string>
     */
    private function attributeNames(): array
    {
        return [
            'type' => __('business_trip_messages.arrange_type_label'),
            'vendor_name' => __('business_trip_messages.arrange_vendor_label'),
            'reference_no' => __('business_trip_messages.arrange_reference_label'),
            'starts_at' => __('business_trip_messages.arrange_start_label'),
            'ends_at' => __('business_trip_messages.arrange_end_label'),
            'notes' => __('business_trip_messages.arrange_notes_label'),
            'files' => __('business_trip_messages.arrange_files_label'),
            'files.*' => __('business_trip_messages.arrange_files_label'),
        ];
    }

    private function bersih(?string $nilai): ?string
    {
        $nilai = trim((string) $nilai);

        return $nilai === '' ? null : $nilai;
    }

    /**
     * Menyimpan berkas bukti, satu folder per pemesanan.
     *
     * Berfolder per pemesanan, bukan per perdin: berkas pemesanan yang batal
     * tetap tinggal selamanya, dan satu folder berisi voucher dari beberapa
     * pemesanan sekaligus cepat menjadi tumpukan yang tidak bisa ditelusuri.
     *
     * @param  array<int, UploadedFile>  $files
     */
    private function simpanBerkas(
        array $files,
        BusinessTrip $trip,
        BusinessTripArrangement $arrangement,
        int $userId,
    ): void {
        $folder = "syopv4/uploads/business_trips/arrangements/{$trip->id}/{$arrangement->id}";

        Storage::disk('public')->makeDirectory($folder);

        $fullFolderPath = storage_path('app/public/' . $folder);

        if (File::exists($fullFolderPath)) {
            @chmod($fullFolderPath, 0777);
        }

        foreach ($files as $file) {
            if (!$file instanceof UploadedFile || !$file->isValid()) {
                continue;
            }

            $ext = strtolower($file->getClientOriginalExtension() ?: 'dat');

            $asli = pathinfo($file->getClientOriginalName(), PATHINFO_FILENAME);

            $filename = implode('_', [
                Str::slug((string) $trip->trip_number, '_') ?: 'perdin',
                strtolower($arrangement->type),
                now()->format('YmdHis'),
                uniqid(),
                Str::slug($asli, '_') ?: 'berkas',
            ]) . '.' . $ext;

            $path = $file->storeAs($folder, $filename, 'public');

            BusinessTripArrangementFile::create([
                'business_trip_arrangement_id' => $arrangement->id,
                'filename' => $filename,
                'original_filename' => $file->getClientOriginalName(),
                'mime_type' => $file->getClientMimeType(),
                'file_size' => $file->getSize(),
                'filepath' => $path,
                'uploaded_by' => $userId,
            ]);
        }
    }
}
