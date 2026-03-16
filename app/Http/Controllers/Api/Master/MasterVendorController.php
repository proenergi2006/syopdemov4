<?php

namespace App\Http\Controllers\Api\Master;

use App\Http\Controllers\Controller;
use App\Models\MasterVendor;
use App\Models\VendorBank;
use App\Models\VendorDokumenPendukung;
use App\Models\VendorTransaksi;
use App\Models\MasterDokumenPendukung;
use Carbon\Carbon;
use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

class MasterVendorController extends Controller
{
    public function index(Request $request)
    {
        try {
            $query = MasterVendor::query();

            // search
            $search = trim((string) $request->get('search', ''));

            if ($search !== '') {
                $query->where(function ($q) use ($search) {
                    $q->where('kode_vendor', 'ILIKE', "%{$search}%")
                        ->orWhere('nama_vendor', 'ILIKE', "%{$search}%")
                        ->orWhere('inisial_vendor', 'ILIKE', "%{$search}%");
                });
            }

            // status
            $isActiveParam = $request->get('is_active');

            if ($isActiveParam !== null && $isActiveParam !== '' && $isActiveParam !== 'all') {
                $isActive = filter_var($isActiveParam, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);

                if ($isActive !== null) {
                    $query->where('is_active', $isActive);
                }
            }

            $perPage = (int) $request->get('per_page', 10);
            if ($perPage <= 0) {
                $perPage = 10;
            }

            $data = $query
                ->orderBy('id', 'desc')
                ->paginate($perPage);

            $items = collect($data->items())->map(function ($item) {
                $item->public_id = Crypt::encryptString((string) $item->id);
                return $item;
            })->values();

            return response()->json([
                'success' => true,
                'message' => 'Data vendor berhasil dimuat.',
                'data' => $items,
                'total' => $data->total(),
                'last_page' => $data->lastPage(),
                'current_page' => $data->currentPage(),
                'per_page' => $data->perPage(),
            ], 200);
        } catch (\Throwable $e) {
            Log::error('Gagal memuat data vendor', [
                'message' => $e->getMessage(),
                'file'    => $e->getFile(),
                'line'    => $e->getLine(),
                'request' => $request->all(),
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Gagal memuat data vendor.',
            ], 500);
        }
    }

    public function store(Request $request)
    {
        DB::beginTransaction();

        try {
            $lastVendor = MasterVendor::where('kode_vendor', 'like', 'TEMP-%')
                ->orderBy('kode_vendor', 'desc')
                ->first();

            if ($lastVendor) {
                $lastNumber = (int) str_replace('TEMP-', '', $lastVendor->kode_vendor);
                $newNumber = $lastNumber + 1;
            } else {
                $newNumber = 1;
            }

            $kodeVendor = 'TEMP-' . str_pad($newNumber, 3, '0', STR_PAD_LEFT);

            $clean = fn($v) => is_null($v) ? null : htmlspecialchars(strip_tags(trim((string) $v)), ENT_QUOTES, 'UTF-8');

            $vendor = MasterVendor::create([
                'nama_vendor'       => $clean($request->nama_vendor),
                'kode_vendor'       => $kodeVendor,
                'inisial_vendor'    => $clean($request->inisial_vendor),
                'telepon'           => $clean($request->telepon),
                'fax'               => $clean($request->fax),
                'email'             => $clean($request->email),
                'jenis_perusahaan'  => $clean($request->jenis_perusahaan),
                'kategori_vendor'   => $clean($request->kategori_vendor),
                'no_ktp'            => $clean($request->nomor_ktp),
                'alamat'            => $clean($request->alamat),

                'nama_pic'          => $clean($request->contact_nama),
                'jabatan_pic'       => $clean($request->contact_jabatan),
                'telp_pic'          => $clean($request->contact_hp),
                'email_pic'         => $clean($request->contact_email),

                'status_pkp'        => $clean($request->status_pkp),
                'no_npwp'           => $clean($request->npwp),
                'alamat_npwp'       => $clean($request->npwp_alamat),
                'no_sppkp'          => $clean($request->sppkp_nomor),
                'tgl_sppkp'         => $request->sppkp_tanggal ?: null,
                'alamat_sppkp'      => $clean($request->sppkp_alamat),
                'same_as_npwp'      => $request->same_as_npwp == "true" ? 1 : 0,

                'jenis_pembayaran'  => $clean($request->jenis_pembayaran),
                'top'               => $clean($request->top ?? 0),
            ]);

            $vendorId = $vendor->id;

            $transaksi = json_decode($request->transaksi_ids ?? '[]', true);
            if (is_array($transaksi) && !empty($transaksi)) {
                foreach ($transaksi as $trxId) {
                    VendorTransaksi::create([
                        'vendor_id'    => $vendorId,
                        'transaksi_id' => $trxId,
                    ]);
                }
            }

            $banks = json_decode($request->banks ?? '[]', true);

            if (is_array($banks) && !empty($banks)) {
                foreach ($banks as $bank) {
                    VendorBank::create([
                        'vendor_id'       => $vendorId,
                        'nama_bank'       => $bank['nama_bank'] ?? '',
                        'atas_nama'       => $bank['atas_nama'] ?? '',
                        'nomor_rekening'  => $bank['nomor_rekening'] ?? '',
                        'cabang'          => $bank['cabang'] ?? '',
                        'alamat_bank'     => $bank['alamat_bank'] ?? '',
                        'swift_code'      => $bank['swift_code'] ?? '',
                    ]);
                }
            }

            $selectedDokumen = json_decode($request->dokumen_pendukung ?? '[]', true);
            $dokumenFiles = $request->file('dokumen_files', []);

            if (!empty($dokumenFiles)) {
                foreach ($dokumenFiles as $docId => $files) {
                    if (!in_array((int) $docId, array_map('intval', $selectedDokumen ?? []))) {
                        continue;
                    }

                    $masterDoc = MasterDokumenPendukung::find($docId);
                    if (!$masterDoc) {
                        continue;
                    }

                    $slug = $masterDoc->slug;
                    $folder = "syopv4/uploads/vendors/dokumen_pendukung/{$slug}";
                    Storage::disk('public')->makeDirectory($folder);

                    foreach ($files as $file) {
                        $filename = time() . '_' . preg_replace('/\s+/', '_', $file->getClientOriginalName());
                        $path = $file->storeAs($folder, $filename, 'public');

                        VendorDokumenPendukung::create([
                            'vendor_id'  => $vendorId,
                            'dokumen_id' => $docId,
                            'file_name'  => $filename,
                            'file_path'  => $path,
                            'file_size'  => $file->getSize(),
                            'file_type'  => $file->getMimeType(),
                        ]);
                    }
                }
            }

            DB::commit();

            return response()->json([
                'success'   => true,
                'message'   => 'Vendor berhasil dibuat!',
                'vendor_id' => $vendorId,
            ], 201);
        } catch (\Throwable $e) {
            DB::rollBack();

            Log::error('Gagal membuat vendor', [
                'message' => $e->getMessage(),
                'file'    => $e->getFile(),
                'line'    => $e->getLine(),
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Gagal membuat vendor.',
            ], 500);
        }
    }

    public function destroy(string $publicId)
    {
        DB::beginTransaction();

        try {
            $vendorId = (int) Crypt::decryptString($publicId);

            $vendor = MasterVendor::findOrFail($vendorId);
            $vendorName = $vendor->nama_vendor;

            // Ambil semua dokumen vendor untuk hapus file fisik
            $dokumenPendukung = VendorDokumenPendukung::where('vendor_id', $vendor->id)->get();

            foreach ($dokumenPendukung as $dokumen) {
                if ($dokumen->file_path && Storage::disk('public')->exists($dokumen->file_path)) {
                    Storage::disk('public')->delete($dokumen->file_path);
                }
            }

            $vendor->delete();

            DB::commit();

            return response()->json([
                'success' => true,
                'message' => "Vendor {$vendorName} berhasil dihapus.",
            ], 200);
        } catch (DecryptException $e) {
            DB::rollBack();

            Log::warning('Public ID vendor tidak valid saat hapus', [
                'public_id' => $publicId,
                'message' => $e->getMessage(),
            ]);

            return response()->json([
                'success' => false,
                'message' => 'ID vendor tidak valid.',
            ], 404);
        } catch (ModelNotFoundException $e) {
            DB::rollBack();

            Log::warning('Vendor tidak ditemukan saat hapus', [
                'public_id' => $publicId,
                'message' => $e->getMessage(),
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Vendor tidak ditemukan.',
            ], 404);
        } catch (\Throwable $e) {
            DB::rollBack();

            Log::error('Gagal menghapus vendor', [
                'public_id' => $publicId,
                'message' => $e->getMessage(),
                'file' => $e->getFile(),
                'line' => $e->getLine(),
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Gagal menghapus vendor.',
            ], 500);
        }
    }

    public function updateStatus(Request $request, string $publicId)
    {
        $request->validate([
            'is_active' => ['required', 'boolean'],
        ]);

        DB::beginTransaction();

        try {
            $vendorId = (int) Crypt::decryptString($publicId);

            $vendor = MasterVendor::findOrFail($vendorId);

            $vendor->update([
                'is_active' => $request->boolean('is_active'),
            ]);

            DB::commit();

            return response()->json([
                'success' => true,
                'message' => 'Status vendor berhasil diupdate.',
                'data' => $vendor->fresh(),
            ], 200);
        } catch (DecryptException $e) {
            DB::rollBack();

            Log::warning('Public ID vendor tidak valid saat update status', [
                'public_id' => $publicId,
                'request' => $request->all(),
                'message' => $e->getMessage(),
            ]);

            return response()->json([
                'success' => false,
                'message' => 'ID vendor tidak valid.',
            ], 404);
        } catch (ModelNotFoundException $e) {
            DB::rollBack();

            Log::warning('Vendor tidak ditemukan saat update status', [
                'public_id' => $publicId,
                'request' => $request->all(),
                'message' => $e->getMessage(),
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Vendor tidak ditemukan.',
            ], 404);
        } catch (\Throwable $e) {
            DB::rollBack();

            Log::error('Gagal mengupdate status vendor', [
                'public_id' => $publicId,
                'request' => $request->all(),
                'message' => $e->getMessage(),
                'file' => $e->getFile(),
                'line' => $e->getLine(),
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Gagal mengupdate status vendor.',
            ], 500);
        }
    }

    public function show(string $publicId)
    {
        try {
            $vendorId = (int) Crypt::decryptString($publicId);

            $vendor = MasterVendor::with([
                'banks',
                'transaksi:id,vendor_id,transaksi_id',
                'dokumenPendukung:id,vendor_id,dokumen_id,file_name,file_path',
            ])->findOrFail($vendorId);

            return response()->json([
                'success' => true,
                'message' => 'Detail vendor berhasil dimuat.',
                'data' => [
                    'public_id' => Crypt::encryptString((string) $vendor->id),
                    'nama_vendor' => $vendor->nama_vendor,
                    'inisial_vendor' => $vendor->inisial_vendor,
                    'telepon' => $vendor->telepon,
                    'fax' => $vendor->fax,
                    'email' => $vendor->email,
                    'jenis_perusahaan' => $vendor->jenis_perusahaan,
                    'kategori_vendor' => $vendor->kategori_vendor,
                    'nomor_ktp' => $vendor->nomor_ktp,
                    'alamat' => $vendor->alamat,
                    'is_active' => $vendor->is_active,

                    'contact_nama' => $vendor->nama_pic,
                    'contact_jabatan' => $vendor->jabatan_pic,
                    'contact_hp' => $vendor->telp_pic,
                    'contact_email' => $vendor->email_pic,

                    'status_pkp' => $vendor->status_pkp,
                    'npwp' => $vendor->no_npwp,
                    'npwp_alamat' => $vendor->alamat_npwp,
                    'sppkp_nomor' => $vendor->no_sppkp,
                    'sppkp_tanggal' => $vendor->tgl_sppkp
                        ? Carbon::parse($vendor->tgl_sppkp)->format('Y-m-d')
                        : null,
                    'sppkp_alamat' => $vendor->alamat_sppkp,
                    'same_as_npwp' => (bool) $vendor->same_as_npwp,

                    'jenis_pembayaran' => $vendor->jenis_pembayaran,
                    'top' => $vendor->top,

                    'transaksi_ids' => $vendor->transaksi->pluck('transaksi_id')->values(),
                    'dokumen_ids' => $vendor->dokumenPendukung->pluck('dokumen_id')->values(),

                    'dokumen_files' => $vendor->dokumenPendukung->map(function ($dokumen) {
                        return [
                            'id' => $dokumen->id,
                            'dokumen_id' => $dokumen->dokumen_id,
                            'file_name' => $dokumen->file_name,
                            'file_path' => $dokumen->file_path,
                            'file_url' => $dokumen->file_path ? asset('storage/' . $dokumen->file_path) : null,
                        ];
                    })->values(),

                    'banks' => $vendor->banks->map(function ($bank) {
                        return [
                            'id' => $bank->id,
                            'nama_bank' => $bank->nama_bank,
                            'atas_nama' => $bank->atas_nama,
                            'nomor_rekening' => $bank->nomor_rekening,
                            'cabang' => $bank->cabang,
                            'alamat_bank' => $bank->alamat_bank,
                            'swift_code' => $bank->swift_code,
                        ];
                    })->values(),
                ],
            ], 200);
        } catch (DecryptException $e) {
            Log::warning('Public ID vendor tidak valid', [
                'public_id' => $publicId,
                'message' => $e->getMessage(),
            ]);

            return response()->json([
                'success' => false,
                'message' => 'ID vendor tidak valid',
            ], 404);
        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException $e) {
            Log::warning('Vendor tidak ditemukan', [
                'public_id' => $publicId,
                'message' => $e->getMessage(),
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Vendor tidak ditemukan',
            ], 404);
        } catch (\Throwable $e) {
            Log::error('Gagal memuat detail vendor', [
                'public_id' => $publicId,
                'message' => $e->getMessage(),
                'file' => $e->getFile(),
                'line' => $e->getLine(),
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Gagal memuat data vendor',
            ], 500);
        }
    }

    public function update(Request $request, string $publicId)
    {
        $request->validate([
            'nama_vendor' => ['required', 'string', 'max:255'],
            'inisial_vendor' => ['required', 'string', 'max:50'],
            'telepon' => ['nullable', 'string', 'max:50'],
            'fax' => ['nullable', 'string', 'max:50'],
            'email' => ['nullable', 'email', 'max:255'],
            'jenis_perusahaan' => ['required'],
            'kategori_vendor' => ['required'],
            'nomor_ktp' => ['nullable', 'string', 'max:100'],
            'alamat' => ['nullable', 'string'],

            'contact_nama' => ['nullable', 'string', 'max:255'],
            'contact_jabatan' => ['nullable', 'string', 'max:255'],
            'contact_hp' => ['nullable', 'string', 'max:50'],
            'contact_email' => ['nullable', 'email', 'max:255'],

            'status_pkp' => ['nullable'],
            'npwp' => ['nullable', 'string', 'max:100'],
            'npwp_alamat' => ['nullable', 'string'],
            'sppkp_nomor' => ['nullable', 'string', 'max:100'],
            'sppkp_tanggal' => ['nullable', 'date'],
            'sppkp_alamat' => ['nullable', 'string'],
            'same_as_npwp' => ['nullable', 'boolean'],

            'jenis_pembayaran' => ['nullable'],
            'top' => ['nullable'],

            'transaksi_ids' => ['nullable', 'array'],
            'transaksi_ids.*' => ['integer'],

            'dokumen_ids' => ['nullable', 'array'],
            'dokumen_ids.*' => ['integer'],

            'banks' => ['nullable', 'array'],
            'banks.*.id' => ['nullable', 'integer'],
            'banks.*.nama_bank' => ['nullable', 'string', 'max:255'],
            'banks.*.atas_nama' => ['nullable', 'string', 'max:255'],
            'banks.*.nomor_rekening' => ['nullable', 'string', 'max:100'],
            'banks.*.cabang' => ['nullable', 'string', 'max:255'],
            'banks.*.alamat_bank' => ['nullable', 'string'],
            'banks.*.swift_code' => ['nullable', 'string', 'max:100'],

            'dokumen_existing_ids' => ['nullable', 'array'],
            'dokumen_existing_ids.*' => ['nullable', 'array'],
            'dokumen_existing_ids.*.*' => ['integer'],

            'dokumen_files' => ['nullable', 'array'],
            'dokumen_files.*' => ['nullable', 'array'],
            'dokumen_files.*.*' => ['file', 'mimes:pdf,jpg,jpeg,png', 'max:5120'],
        ]);

        DB::beginTransaction();

        try {
            $vendorId = (int) Crypt::decryptString($publicId);
            $vendor = MasterVendor::findOrFail($vendorId);

            $vendor->update([
                'nama_vendor' => $request->nama_vendor,
                'inisial_vendor' => $request->inisial_vendor,
                'telepon' => $request->telepon,
                'fax' => $request->fax,
                'email' => $request->email,
                'jenis_perusahaan' => $request->jenis_perusahaan,
                'kategori_vendor' => $request->kategori_vendor,
                'no_ktp' => $request->nomor_ktp,
                'alamat' => $request->alamat,

                'nama_pic' => $request->contact_nama,
                'jabatan_pic' => $request->contact_jabatan,
                'telp_pic' => $request->contact_hp,
                'email_pic' => $request->contact_email,

                'status_pkp' => $request->status_pkp,
                'no_npwp' => $request->npwp,
                'alamat_npwp' => $request->npwp_alamat,
                'no_sppkp' => $request->sppkp_nomor,
                'tgl_sppkp' => $request->filled('sppkp_tanggal')
                    ? Carbon::parse($request->sppkp_tanggal)->format('Y-m-d')
                    : null,
                'alamat_sppkp' => $request->sppkp_alamat,
                'same_as_npwp' => $request->boolean('same_as_npwp'),

                'jenis_pembayaran' => $request->jenis_pembayaran,
                'top' => $request->filled('top') ? $request->top : null,
            ]);

            /*
        |--------------------------------------------------------------------------
        | 1. Sinkron transaksi vendor
        |--------------------------------------------------------------------------
        */
            $transaksiIds = collect($request->input('transaksi_ids', []))
                ->filter(fn($id) => $id !== null && $id !== '')
                ->map(fn($id) => (int) $id)
                ->unique()
                ->values();

            VendorTransaksi::where('vendor_id', $vendor->id)
                ->whereNotIn('transaksi_id', $transaksiIds->all())
                ->delete();

            foreach ($transaksiIds as $transaksiId) {
                VendorTransaksi::updateOrCreate(
                    [
                        'vendor_id' => $vendor->id,
                        'transaksi_id' => $transaksiId,
                    ],
                    [
                        'is_active' => true,
                    ]
                );
            }

            /*
        |--------------------------------------------------------------------------
        | 2. Sinkron bank vendor
        |--------------------------------------------------------------------------
        */
            $banks = collect($request->input('banks', []));

            $bankIdsToKeep = [];

            foreach ($banks as $bankData) {
                $isEmpty =
                    blank($bankData['nama_bank'] ?? null) &&
                    blank($bankData['atas_nama'] ?? null) &&
                    blank($bankData['nomor_rekening'] ?? null) &&
                    blank($bankData['cabang'] ?? null) &&
                    blank($bankData['alamat_bank'] ?? null) &&
                    blank($bankData['swift_code'] ?? null);

                if ($isEmpty) {
                    continue;
                }

                if (!empty($bankData['id'])) {
                    $bank = VendorBank::where('vendor_id', $vendor->id)
                        ->where('id', $bankData['id'])
                        ->first();

                    if ($bank) {
                        $bank->update([
                            'nama_bank' => $bankData['nama_bank'] ?? null,
                            'atas_nama' => $bankData['atas_nama'] ?? null,
                            'nomor_rekening' => $bankData['nomor_rekening'] ?? null,
                            'cabang' => $bankData['cabang'] ?? null,
                            'alamat_bank' => $bankData['alamat_bank'] ?? null,
                            'swift_code' => $bankData['swift_code'] ?? null,
                        ]);

                        $bankIdsToKeep[] = $bank->id;
                        continue;
                    }
                }

                $newBank = VendorBank::create([
                    'vendor_id' => $vendor->id,
                    'nama_bank' => $bankData['nama_bank'] ?? null,
                    'atas_nama' => $bankData['atas_nama'] ?? null,
                    'nomor_rekening' => $bankData['nomor_rekening'] ?? null,
                    'cabang' => $bankData['cabang'] ?? null,
                    'alamat_bank' => $bankData['alamat_bank'] ?? null,
                    'swift_code' => $bankData['swift_code'] ?? null,
                ]);

                $bankIdsToKeep[] = $newBank->id;
            }

            if (!empty($bankIdsToKeep)) {
                VendorBank::where('vendor_id', $vendor->id)
                    ->whereNotIn('id', $bankIdsToKeep)
                    ->delete();
            } else {
                VendorBank::where('vendor_id', $vendor->id)->delete();
            }

            /*
            |--------------------------------------------------------------------------
            | 3. Sinkron dokumen pendukung
            |--------------------------------------------------------------------------
            */
            $dokumenIds = collect($request->input('dokumen_ids', []))
                ->filter(fn($id) => $id !== null && $id !== '')
                ->map(fn($id) => (int) $id)
                ->unique()
                ->values();

            $dokumenExistingIds = collect($request->input('dokumen_existing_ids', []));

            // 1. Hapus file lama per dokumen yang masih dipilih, tapi tidak ikut dipertahankan
            foreach ($dokumenIds as $dokumenId) {
                $keepIdsForDokumen = collect($dokumenExistingIds->get((string) $dokumenId, []))
                    ->filter(fn($id) => $id !== null && $id !== '')
                    ->map(fn($id) => (int) $id)
                    ->values()
                    ->all();

                $oldFilesQuery = VendorDokumenPendukung::where('vendor_id', $vendor->id)
                    ->where('dokumen_id', $dokumenId);

                $filesToDelete = !empty($keepIdsForDokumen)
                    ? (clone $oldFilesQuery)->whereNotIn('id', $keepIdsForDokumen)->get()
                    : $oldFilesQuery->get();

                foreach ($filesToDelete as $file) {
                    if ($file->file_path && Storage::disk('public')->exists($file->file_path)) {
                        Storage::disk('public')->delete($file->file_path);
                    }

                    $file->delete();
                }
            }

            // 2. Hapus semua file dari dokumen yang sudah tidak dipilih sama sekali
            $dokumenYangDihapusTotal = VendorDokumenPendukung::where('vendor_id', $vendor->id)
                ->when(
                    $dokumenIds->isNotEmpty(),
                    fn($query) => $query->whereNotIn('dokumen_id', $dokumenIds->all()),
                    fn($query) => $query
                )
                ->get();

            if ($dokumenIds->isEmpty()) {
                $dokumenYangDihapusTotal = VendorDokumenPendukung::where('vendor_id', $vendor->id)->get();
            }

            foreach ($dokumenYangDihapusTotal as $file) {
                if ($file->file_path && Storage::disk('public')->exists($file->file_path)) {
                    Storage::disk('public')->delete($file->file_path);
                }

                $file->delete();
            }

            // 3. Simpan file baru
            $uploadedDokumenFiles = $request->file('dokumen_files', []);

            foreach ($uploadedDokumenFiles as $dokumenId => $files) {
                $dokumenId = (int) $dokumenId;

                if (!$dokumenIds->contains($dokumenId)) {
                    continue;
                }

                $files = is_array($files) ? $files : [$files];

                foreach ($files as $file) {
                    if (!$file) {
                        continue;
                    }

                    $masterDoc = MasterDokumenPendukung::find($dokumenId);
                    if (!$masterDoc) {
                        continue;
                    }

                    $slug = $masterDoc->slug;
                    $folder = "syopv4/uploads/vendors/dokumen_pendukung/{$slug}";
                    $storedPath = $file->store($folder, 'public');

                    VendorDokumenPendukung::create([
                        'vendor_id' => $vendor->id,
                        'dokumen_id' => $dokumenId,
                        'file_name' => $file->getClientOriginalName(),
                        'file_path' => $storedPath,
                    ]);
                }
            }

            DB::commit();

            return response()->json([
                'success' => true,
                'message' => 'Data vendor berhasil diperbarui.',
                'data' => [
                    'public_id' => Crypt::encryptString((string) $vendor->id),
                ],
            ], 200);
        } catch (DecryptException $e) {
            DB::rollBack();

            Log::warning('Public ID vendor tidak valid saat update', [
                'public_id' => $publicId,
                'request' => $request->all(),
                'message' => $e->getMessage(),
            ]);

            return response()->json([
                'success' => false,
                'message' => 'ID vendor tidak valid.',
            ], 404);
        } catch (ModelNotFoundException $e) {
            DB::rollBack();

            Log::warning('Vendor tidak ditemukan saat update', [
                'public_id' => $publicId,
                'request' => $request->all(),
                'message' => $e->getMessage(),
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Vendor tidak ditemukan.',
            ], 404);
        } catch (\Throwable $e) {
            DB::rollBack();

            Log::error('Gagal mengupdate vendor', [
                'public_id' => $publicId,
                'request' => $request->except(['dokumen_files']),
                'message' => $e->getMessage(),
                'file' => $e->getFile(),
                'line' => $e->getLine(),
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Gagal mengupdate vendor.',
            ], 500);
        }
    }
}
