<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\GoodsReceive;
use App\Models\GoodsReceiveItem;
use App\Models\PurchaseOrder;
use App\Models\PurchaseOrderItem;
use App\Services\NonTrade\GoodsReceive\GoodsReceivePostingService;
use App\Services\NonTrade\GoodsReceive\GoodsReceiveService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class GoodsReceiveController extends Controller
{
    public function __construct(
        protected GoodsReceiveService $goodsReceiveService,
        protected GoodsReceivePostingService $goodsReceivePostingService,
    ) {}

    public function index(Request $request)
    {
        try {
            $query = GoodsReceive::query()
                ->with([
                    'purchaseOrder:id,nomor_po',
                    'vendor:id,nama_vendor',
                    'creator:id,name',
                ]);

            if ($request->filled('search')) {
                $search = trim($request->search);

                $query->where(function ($q) use ($search) {
                    $q->where('nomor_gr', 'ILIKE', "%{$search}%")
                        ->orWhereHas('purchaseOrder', function ($po) use ($search) {
                            $po->where('nomor_po', 'ILIKE', "%{$search}%");
                        })
                        ->orWhereHas('vendor', function ($vendor) use ($search) {
                            $vendor->where('nama_vendor', 'ILIKE', "%{$search}%");
                        });
                });
            }

            if ($request->filled('status')) {
                $query->where('status', $request->status);
            }

            if ($request->filled('start_date')) {
                $query->whereDate('tanggal_gr', '>=', $request->start_date);
            }

            if ($request->filled('end_date')) {
                $query->whereDate('tanggal_gr', '<=', $request->end_date);
            }

            $perPage = (int) ($request->per_page ?? 10);

            $goodsReceives = $query
                ->orderByDesc('id')
                ->paginate($perPage);

            $goodsReceives->getCollection()->transform(function ($gr) {
                return [
                    'id' => $gr->id,
                    'public_id' => Crypt::encryptString((string) $gr->id),

                    'nomor_gr' => $gr->nomor_gr,
                    'tanggal_gr' => $gr->tanggal_gr,

                    'purchase_order_id' => $gr->purchase_order_id,
                    'nomor_po' => $gr->purchaseOrder->nomor_po ?? '-',

                    'vendor_id' => $gr->vendor_id,
                    'vendor' => $gr->vendor->nama_vendor ?? '-',

                    'status' => $gr->status,

                    'total_qty' => (float) ($gr->total_qty ?? 0),
                    'total_nilai' => (float) ($gr->total_nilai ?? 0),

                    'created_by' => $gr->creator->name ?? $gr->created_by,
                    'created_at' => $gr->created_at?->format('Y-m-d H:i:s'),
                ];
            });

            return response()->json([
                'success' => true,
                'message' => 'Data Goods Receipt berhasil dimuat.',
                'data' => $goodsReceives->items(),

                'pagination' => [
                    'current_page' => $goodsReceives->currentPage(),
                    'last_page' => $goodsReceives->lastPage(),
                    'per_page' => $goodsReceives->perPage(),
                    'total' => $goodsReceives->total(),
                ],
            ]);
        } catch (\Throwable $e) {
            Log::error('[Goods Receipt] Index error', [
                'message' => $e->getMessage(),
                'file' => $e->getFile(),
                'line' => $e->getLine(),
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Gagal memuat data Goods Receipt.',
                'debug' => app()->environment('local')
                    ? $e->getMessage()
                    : null,
            ], 500);
        }
    }

    public function store(Request $request)
    {
        try {
            $validated = $request->validate([
                'purchase_order_public_id' => ['required', 'string'],
                'tanggal_gr' => ['required', 'date'],
                'nomor_surat_jalan' => ['nullable', 'string', 'max:255'],
                'notes' => ['nullable', 'string'],

                'items' => ['required', 'array', 'min:1'],
                'items.*.purchase_order_item_public_id' => ['required', 'string'],
                'items.*.qty_receive' => ['required', 'numeric', 'gt:0'],
                'items.*.notes' => ['nullable', 'string'],
            ]);

            $poId = Crypt::decryptString($validated['purchase_order_public_id']);

            $po = PurchaseOrder::with(['items', 'vendor'])
                ->findOrFail($poId);

            $items = collect($validated['items'])->map(function ($item) {
                return [
                    'purchase_order_item_id' => Crypt::decryptString($item['purchase_order_item_public_id']),
                    'qty_receive' => (float) $item['qty_receive'],
                    'notes' => $item['notes'] ?? null,
                ];
            })->values()->toArray();

            $nomor_gr = $this->generateDraftGRNumber();

            $payload = [
                'purchase_order_public_id' => $validated['purchase_order_public_id'],
                'purchase_order_id' => $poId,
                'nomor_gr' => $nomor_gr,
                'tanggal_gr' => $validated['tanggal_gr'],
                'nomor_surat_jalan' => $validated['nomor_surat_jalan'] ?? null,
                'notes' => $validated['notes'] ?? null,
                'created_by' => $request->user()->name,
                'items' => $items,
            ];

            $gr = $this->goodsReceiveService->createDraftFromPurchaseOrder(
                $po,
                $payload,
                $request->user()->id
            );

            return response()->json([
                'success' => true,
                'message' => 'Goods Receipt berhasil dibuat sebagai draft.',
                'data' => [
                    'id' => $gr->id,
                    'public_id' => $gr->encrypted_id,
                    'nomor_gr' => $gr->nomor_gr,
                    'status' => $gr->status,
                    'tanggal_gr' => $gr->tanggal_gr,
                ],
            ], 201);
        } catch (\Throwable $e) {
            Log::error('[Goods Receipt] Store error', [
                'message' => $e->getMessage(),
                'file' => $e->getFile(),
                'line' => $e->getLine(),
                'request' => $request->all(),
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Gagal membuat Goods Receipt.',
                'debug' => app()->environment('local') ? $e->getMessage() : null,
            ], 500);
        }
    }

    public function edit($publicId)
    {
        try {
            $id = Crypt::decryptString(urldecode($publicId));

            $gr = GoodsReceive::with([
                'purchaseOrder:id,nomor_po,tanggal_po,cabang,id_department,vendor_id,status_receive',
                'purchaseOrder.vendor:id,nama_vendor,status_pkp',
                'purchaseOrder.cabangData:id,nama_cabang,inisial_cabang',
                'purchaseOrder.departmentData:id,kode,nama',
                'items.unitData:id,kode,nama',
                'creator:id,name',
            ])->findOrFail($id);

            $items = $gr->getRelation('items');

            if (strtoupper((string) $gr->status) !== 'DRAFT') {
                return response()->json([
                    'success' => false,
                    'message' => 'Goods Receive hanya dapat diedit jika status masih DRAFT.',
                    'data' => null,
                ], 422);
            }

            return response()->json([
                'success' => true,
                'message' => 'Data Goods Receive berhasil dimuat.',
                'data' => [
                    'id' => $gr->id,
                    'public_id' => Crypt::encryptString((string) $gr->id),

                    'nomor_gr' => $gr->nomor_gr,
                    'tanggal_gr' => optional($gr->tanggal_gr)->format('Y-m-d') ?? $gr->tanggal_gr,
                    'nomor_surat_jalan' => $gr->nomor_surat_jalan,
                    'status' => $gr->status,
                    'notes' => $gr->notes,

                    'purchase_order_id' => $gr->purchase_order_id,
                    'purchase_order_public_id' => Crypt::encryptString((string) $gr->purchase_order_id),
                    'nomor_po' => $gr->purchaseOrder->nomor_po ?? '-',
                    'tanggal_po' => $gr->purchaseOrder->tanggal_po ?? null,

                    'vendor_id' => $gr->purchaseOrder->vendor_id ?? null,
                    'vendor_name' => $gr->purchaseOrder->vendor->nama_vendor ?? '-',
                    'status_pkp' => $gr->purchaseOrder->vendor->status_pkp ?? 'NON_PKP',

                    'cabang_id' => $gr->purchaseOrder->cabang ?? null,
                    'cabang_name' => $gr->purchaseOrder->cabangData->nama_cabang
                        ?? $gr->purchaseOrder->cabangData->inisial_cabang
                        ?? '-',

                    'department_id' => $gr->purchaseOrder->id_department ?? null,
                    'department_name' => $gr->purchaseOrder->departmentData->nama
                        ?? $gr->purchaseOrder->departmentData->kode
                        ?? '-',

                    'created_by_id' => $gr->created_by,
                    'created_by' => $gr->creator->name ?? '-',

                    'items' => $items->map(function ($item) {
                        return [
                            'id' => $item->id,
                            'public_id' => Crypt::encryptString((string) $item->id),

                            'purchase_order_item_id' => $item->purchase_order_item_id,
                            'purchase_order_item_public_id' => Crypt::encryptString((string) $item->purchase_order_item_id),

                            'purchase_request_item_id' => $item->purchase_request_item_id,

                            'nama_item' => $item->nama_item,
                            'item_name' => $item->nama_item,
                            'item_code' => '-',

                            'unit_id' => $item->unit,
                            'unit' => $item->unitData->nama ?? $item->unitData->kode ?? '-',

                            'qty_ordered' => (float) ($item->qty_ordered ?? 0),
                            'qty_received_before' => (float) ($item->qty_received_before ?? 0),
                            'qty_receive' => (float) ($item->qty_receive ?? 0),
                            'qty_received_after' => (float) ($item->qty_received_after ?? 0),
                            'qty_outstanding' => (float) ($item->qty_outstanding ?? 0),

                            'notes' => $item->notes,
                        ];
                    })->values(),
                ],
            ], 200);
        } catch (\Throwable $e) {
            Log::error('[Goods Receive] Edit error', [
                'public_id' => $publicId,
                'message' => $e->getMessage(),
                'file' => $e->getFile(),
                'line' => $e->getLine(),
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Gagal memuat data Goods Receive.',
                'debug' => app()->environment('local') ? $e->getMessage() : null,
                'data' => null,
            ], 500);
        }
    }

    public function update(Request $request, $publicId)
    {
        DB::beginTransaction();

        try {
            $validated = $request->validate([
                'tanggal_gr' => ['required', 'date'],
                'nomor_surat_jalan' => ['nullable', 'string', 'max:255'],
                'notes' => ['nullable', 'string'],

                'items' => ['required', 'array', 'min:1'],
                'items.*.goods_receive_item_public_id' => ['required', 'string'],
                'items.*.purchase_order_item_public_id' => ['required', 'string'],
                'items.*.qty_receive' => ['required', 'numeric', 'gt:0'],
                'items.*.notes' => ['nullable', 'string'],
            ]);

            $grId = Crypt::decryptString(urldecode($publicId));

            $gr = GoodsReceive::with(['items'])
                ->lockForUpdate()
                ->findOrFail($grId);

            if (strtoupper((string) $gr->status) !== 'DRAFT') {
                return response()->json([
                    'success' => false,
                    'message' => 'Goods Receive hanya dapat diubah jika status masih DRAFT.',
                ], 422);
            }

            $gr->update([
                'tanggal_gr' => $validated['tanggal_gr'],
                'nomor_surat_jalan' => $validated['nomor_surat_jalan'] ?? null,
                'notes' => $validated['notes'] ?? null,
            ]);

            foreach ($validated['items'] as $itemPayload) {
                $grItemId = Crypt::decryptString($itemPayload['goods_receive_item_public_id']);
                $poItemId = Crypt::decryptString($itemPayload['purchase_order_item_public_id']);

                $grItem = GoodsReceiveItem::where('goods_receive_id', $gr->id)
                    ->where('id', $grItemId)
                    ->where('purchase_order_item_id', $poItemId)
                    ->lockForUpdate()
                    ->firstOrFail();

                $poItem = PurchaseOrderItem::where('id', $poItemId)
                    ->lockForUpdate()
                    ->firstOrFail();

                $qtyReceive = (float) $itemPayload['qty_receive'];
                $qtyOrdered = (float) ($grItem->qty_ordered ?? $poItem->qty ?? 0);
                $qtyReceivedBefore = (float) ($grItem->qty_received_before ?? 0);

                $maxReceive = max($qtyOrdered - $qtyReceivedBefore, 0);

                if ($qtyReceive > $maxReceive) {
                    throw new \Exception("Qty receive item {$grItem->nama_item} melebihi qty yang tersedia.");
                }

                $qtyReceivedAfter = $qtyReceivedBefore + $qtyReceive;
                $qtyOutstanding = max($qtyOrdered - $qtyReceivedAfter, 0);

                $grItem->update([
                    'qty_receive' => $qtyReceive,
                    'qty_received_after' => $qtyReceivedAfter,
                    'qty_outstanding' => $qtyOutstanding,
                    'notes' => $itemPayload['notes'] ?? null,
                ]);
            }

            DB::commit();

            return response()->json([
                'success' => true,
                'message' => 'Goods Receive berhasil diperbarui.',
                'data' => [
                    'id' => $gr->id,
                    'public_id' => Crypt::encryptString((string) $gr->id),
                    'nomor_gr' => $gr->nomor_gr,
                    'status' => $gr->status,
                    'tanggal_gr' => $gr->tanggal_gr,
                    'nomor_surat_jalan' => $gr->nomor_surat_jalan,
                ],
            ], 200);
        } catch (\Throwable $e) {
            DB::rollBack();

            Log::error('[Goods Receive] Update error', [
                'public_id' => $publicId,
                'message' => $e->getMessage(),
                'file' => $e->getFile(),
                'line' => $e->getLine(),
                'request' => $request->all(),
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Gagal memperbarui Goods Receive.',
                'debug' => app()->environment('local') ? $e->getMessage() : null,
            ], 500);
        }
    }

    public function show($publicId)
    {
        try {
            $id = Crypt::decryptString($publicId);

            try {

                $gr = GoodsReceive::with([
                    'purchaseOrder:id,nomor_po,tanggal_po,cabang,id_department,vendor_id,status_receive',
                    'purchaseOrder.vendor:id,nama_vendor,status_pkp',
                    'purchaseOrder.cabangData:id,nama_cabang,inisial_cabang',
                    'purchaseOrder.departmentData:id,kode,nama',
                    'items.unitData:id,kode,nama',
                    'creator:id,name',
                    'poster:id,name',
                ])->findOrFail($id);

                $items = $gr->getRelation('items');

                return response()->json([
                    'success' => true,
                    'message' => 'Detail Goods Receipt berhasil dimuat.',
                    'data' => [
                        'id' => $gr->id,
                        'public_id' => $gr->encrypted_id,

                        'nomor_gr' => $gr->nomor_gr,
                        'tanggal_gr' => $gr->tanggal_gr,
                        'nomor_surat_jalan' => $gr->nomor_surat_jalan,

                        'status' => $gr->status,
                        'notes' => $gr->notes,

                        'purchase_order_id' => $gr->purchase_order_id,
                        'nomor_po' => $gr->purchaseOrder->nomor_po ?? '-',
                        'tanggal_po' => $gr->purchaseOrder->tanggal_po ?? '-',
                        'status_receive' => $gr->purchaseOrder->status_receive ?? '-',

                        'vendor_id' => $gr->purchaseOrder->vendor_id ?? null,
                        'vendor' => $gr->purchaseOrder->vendor->nama_vendor ?? '-',
                        'status_pkp' => $gr->purchaseOrder->vendor->status_pkp ?? 'NON_PKP',

                        'cabang_id' => $gr->purchaseOrder->cabang ?? null,
                        'cabang' => $gr->purchaseOrder->cabangData->nama_cabang
                            ?? $gr->purchaseOrder->cabangData->inisial_cabang
                            ?? '-',

                        'department_id' => $gr->purchaseOrder->id_department ?? null,
                        'department' => $gr->purchaseOrder->departmentData->nama
                            ?? $gr->purchaseOrder->departmentData->kode
                            ?? '-',

                        'created_by_id' => $gr->created_by,
                        'created_by' => $gr->creator->name ?? '-',
                        'created_at' => $gr->created_at?->format('Y-m-d H:i:s'),

                        'posted_at' => $gr->posted_at,
                        'posted_by_id' => $gr->posted_by,
                        'posted_by' => $gr->poster->name ?? '-',

                        'items' => $items->map(function ($item) use ($gr) {
                            $qtyOrdered = (float) ($item->qty_ordered ?? 0);
                            $qtyReceive = (float) ($item->qty_receive ?? 0);

                            $qtyReceivedBefore = (float) DB::table('goods_receive_items as gri')
                                ->join('goods_receives as gr', 'gr.id', '=', 'gri.goods_receive_id')
                                ->where('gri.purchase_order_item_id', $item->purchase_order_item_id)
                                ->whereIn('gr.status', ['DRAFT', 'POSTED'])
                                ->whereNull('gr.deleted_at')
                                ->where('gr.id', '<', $gr->id)
                                ->sum('gri.qty_receive');

                            $qtyReceivedAfter = $qtyReceivedBefore + $qtyReceive;
                            $qtyOutstanding = max($qtyOrdered - $qtyReceivedAfter, 0);

                            return [
                                'id' => $item->id,
                                'public_id' => Crypt::encryptString((string) $item->id),

                                'purchase_order_item_id' => $item->purchase_order_item_id,
                                'purchase_request_item_id' => $item->purchase_request_item_id,

                                'nama_item' => $item->nama_item,
                                'unit_id' => $item->unit,
                                'unit' => $item->unitData->nama ?? $item->unitData->kode ?? '-',

                                'qty_ordered' => $qtyOrdered,
                                'qty_received_before' => $qtyReceivedBefore,
                                'qty_receive' => $qtyReceive,
                                'qty_received_after' => $qtyReceivedAfter,
                                'qty_outstanding' => $qtyOutstanding,

                                'notes' => $item->notes,
                            ];
                        })->values(),
                    ],
                ], 200);
            } catch (\Throwable $e) {
                Log::error('[Goods Receipt] Show error', [
                    'public_id' => $publicId,
                    'message' => $e->getMessage(),
                    'file' => $e->getFile(),
                    'line' => $e->getLine(),
                ]);

                return response()->json([
                    'success' => false,
                    'message' => 'Gagal memuat detail Goods Receipt.',
                    'data' => null,
                    'debug' => app()->environment('local') ? $e->getMessage() : null,
                ], 500);
            }
        } catch (\Throwable $e) {
            Log::error('[Goods Receipt] Show error', [
                'public_id' => $publicId,
                'message' => $e->getMessage(),
                'file' => $e->getFile(),
                'line' => $e->getLine(),
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Gagal memuat detail Goods Receipt.',
                'debug' => app()->environment('local') ? $e->getMessage() : null,
            ], 500);
        }
    }

    public function post(Request $request, $publicId)
    {
        try {
            $id = Crypt::decryptString($publicId);

            $gr = GoodsReceive::with(['items', 'purchaseOrder.items'])
                ->findOrFail($id);

            $this->goodsReceivePostingService->post($gr, $request->user());

            $gr->refresh();

            return response()->json([
                'success' => true,
                'message' => 'Goods Receipt berhasil diposting.',
                'data' => [
                    'id' => $gr->id,
                    'public_id' => $gr->encrypted_id,
                    'nomor_gr' => $gr->nomor_gr,
                    'status' => $gr->status,
                    'posted_at' => $gr->posted_at,
                ],
            ], 200);
        } catch (\Throwable $e) {
            Log::error('[Goods Receipt] Post error', [
                'public_id' => $publicId,
                'message' => $e->getMessage(),
                'file' => $e->getFile(),
                'line' => $e->getLine(),
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Gagal posting Goods Receipt.',
                'debug' => app()->environment('local') ? $e->getMessage() : null,
            ], 500);
        }
    }

    public function destroy($publicId)
    {
        DB::beginTransaction();

        try {
            $id = Crypt::decryptString(urldecode($publicId));

            $gr = GoodsReceive::with(['items'])
                ->lockForUpdate()
                ->findOrFail($id);

            if (strtoupper((string) $gr->status) !== 'DRAFT') {
                DB::rollBack();

                return response()->json([
                    'success' => false,
                    'message' => 'Goods Receipt hanya dapat dihapus jika status masih DRAFT.',
                ], 422);
            }

            DB::table('goods_receive_items')
                ->where('goods_receive_id', $gr->id)
                ->delete();

            $gr->delete();

            DB::commit();

            return response()->json([
                'success' => true,
                'message' => 'Goods Receipt berhasil dihapus.',
            ], 200);
        } catch (\Throwable $e) {
            DB::rollBack();

            Log::error('[Goods Receipt] Destroy error', [
                'public_id' => $publicId,
                'message' => $e->getMessage(),
                'file' => $e->getFile(),
                'line' => $e->getLine(),
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Gagal menghapus Goods Receipt.',
                'debug' => app()->environment('local') ? $e->getMessage() : null,
            ], 500);
        }
    }

    private function generateDraftGRNumber(): string
    {
        $year = now()->format('Y');

        $lastGR = GoodsReceive::withTrashed()
            ->whereYear('created_at', $year)
            ->where('nomor_gr', 'ILIKE', "DRAFT/GR/{$year}/%")
            ->orderByDesc('id')
            ->first();

        $nextNumber = 1;

        if ($lastGR) {
            $lastNumber = (int) substr($lastGR->nomor_gr, -4);
            $nextNumber = $lastNumber + 1;
        }

        return 'DRAFT/GR/' . $year . '/' . str_pad((string) $nextNumber, 4, '0', STR_PAD_LEFT);
    }
}
