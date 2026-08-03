<?php

namespace App\Http\Controllers\Api\Dashboard;

use App\Http\Controllers\Controller;
use App\Services\Dashboard\PurchaseOrderDashboardService;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class PurchaseOrderDashboardController extends Controller
{
    public function __construct(
        private readonly PurchaseOrderDashboardService $dashboardService,
    ) {}

    public function index(Request $request): JsonResponse
    {
        try {
            $user = $request->user();

            if (
                !$user
                || !$user->hasPermission(
                    'dashboard.po.view',
                )
            ) {
                return response()->json([
                    'success' => false,
                    'message' => 'Anda tidak memiliki akses ke dashboard Purchase Order.',
                ], 403);
            }

            $validated = $request->validate(
                $this->periodFilterValidationRules(),
            );

            /*
            |--------------------------------------------------------------------------
            | Resolve Access dan Effective Filters
            |--------------------------------------------------------------------------
            | OWN_CABANG dan OWN_DEPARTMENT akan menimpa filter tertentu dengan
            | data user login. Controller hanya meneruskan hasil resolve ke service.
            |--------------------------------------------------------------------------
            */
            $resolvedAccess = $this->dashboardService
                ->resolveAccessAndFilters(
                    user: $user,
                    filters: $validated,
                );

            $dashboard = $this->dashboardService
                ->getDashboard(
                    $resolvedAccess['filters'],
                );

            return response()->json([
                'success' => true,

                'message'
                => 'Purchase Order dashboard retrieved successfully.',

                'data' => [
                    'access'
                    => $resolvedAccess['access'],

                    'filters'
                    => $dashboard['filters'] ?? [],

                    'summary'
                    => $dashboard['summary'] ?? [],

                    'trend'
                    => $dashboard['trend'] ?? [],

                    'statuses'
                    => $dashboard['statuses'] ?? [],

                    'attention_items'
                    => $dashboard['attention_items'] ?? [],

                    'breakdown' => $dashboard['breakdown'] ?? [
                        'by_cabang' => [],
                        'by_department' => [],
                    ],

                    /*
                    |--------------------------------------------------------------------------
                    | Perbandingan Harga Item PR dan PO
                    |--------------------------------------------------------------------------
                    | Data ini dipakai frontend untuk section:
                    | "Perbandingan Harga Item PR dan PO".
                    |--------------------------------------------------------------------------
                    */
                    'item_price_comparison'
                    => $dashboard['item_price_comparison'] ?? [
                        'summary' => [
                            'total_items' => 0,
                            'increased_items' => 0,
                            'decreased_items' => 0,
                            'unchanged_items' => 0,
                            'average_difference_percent' => 0,
                            'total_difference_amount' => 0,
                        ],
                        'items' => [],
                    ],

                    /*
                    |--------------------------------------------------------------------------
                    | Efisiensi dan Kenaikan Nilai PR vs PO
                    |--------------------------------------------------------------------------
                    | Data ini dipakai frontend untuk section:
                    | "Efisiensi dan Kenaikan Nilai PR vs PO".
                    |
                    | Rule:
                    | - Hanya PR status_po COMPLETED yang dihitung di service.
                    | - PO DRAFT tetap dihitung sebagai PO valid sesuai rule yang
                    |   sudah disepakati.
                    |--------------------------------------------------------------------------
                    */
                    'value_comparison'
                    => $dashboard['value_comparison'] ?? [
                        'summary' => [
                            'completed_pr_count' => 0,
                            'efficiency_pr_count' => 0,
                            'increase_pr_count' => 0,
                            'same_pr_count' => 0,
                            'total_pr_amount' => 0,
                            'total_po_amount' => 0,
                            'efficiency_amount' => 0,
                            'increase_amount' => 0,
                            'net_difference_amount' => 0,
                            'average_difference_percent' => 0,
                        ],
                        'items' => [],
                    ],
                ],
            ]);
        } catch (ValidationException $e) {
            throw $e;
        } catch (AuthorizationException $e) {
            return response()->json([
                'success' => false,
                'message' => $e->getMessage(),
            ], 403);
        } catch (\Throwable $e) {
            Log::error('[Dashboard][Purchase Order] Index error', [
                'message' => $e->getMessage(),
                'file' => $e->getFile(),
                'line' => $e->getLine(),
                'user_id' => $request->user()?->id,
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Gagal mengambil data dashboard Purchase Order.',
                'error' => app()->environment('local')
                    ? $e->getMessage()
                    : null,
            ], 500);
        }
    }

    public function pendingApprovals(Request $request): JsonResponse
    {
        try {
            $user = $request->user();

            if (
                !$user
                || !$user->hasPermission(
                    'dashboard.po.view',
                )
            ) {
                return response()->json([
                    'success' => false,
                    'message' => 'Anda tidak memiliki akses ke dashboard Purchase Order.',
                ], 403);
            }

            $validated = $request->validate(
                array_merge(
                    $this->periodFilterValidationRules(),
                    [
                        'per_page' => [
                            'nullable',
                            'integer',
                            'min:1',
                            'max:50',
                        ],
                    ],
                ),
            );

            /*
            |--------------------------------------------------------------------------
            | Resolve Access dan Effective Filters
            |--------------------------------------------------------------------------
            | Widget ini mengikuti scope akses DAN filter periode yang sama
            | dengan dashboard utama (ALL / OWN_CABANG / OWN_DEPARTMENT),
            | supaya angka yang tampil seragam dengan card ringkasan lain.
            |--------------------------------------------------------------------------
            */
            $resolvedAccess = $this->dashboardService
                ->resolveAccessAndFilters(
                    user: $user,
                    filters: $validated,
                );

            $perPage = (int) (
                $validated['per_page'] ?? 3
            );

            $pendingApprovals = $this->dashboardService
                ->getPendingApprovalPurchaseOrders(
                    filters: $resolvedAccess['filters'],
                    perPage: $perPage,
                );

            return response()->json([
                'success' => true,

                'message'
                => 'Daftar Purchase Order menunggu approval berhasil diambil.',

                'data' => $pendingApprovals->items(),

                'meta' => [
                    'current_page' => $pendingApprovals->currentPage(),
                    'last_page' => $pendingApprovals->lastPage(),
                    'per_page' => $pendingApprovals->perPage(),
                    'total' => $pendingApprovals->total(),
                ],
            ]);
        } catch (ValidationException $e) {
            throw $e;
        } catch (AuthorizationException $e) {
            return response()->json([
                'success' => false,
                'message' => $e->getMessage(),
            ], 403);
        } catch (\Throwable $e) {
            Log::error('[Dashboard][Purchase Order] Pending approvals error', [
                'message' => $e->getMessage(),
                'file' => $e->getFile(),
                'line' => $e->getLine(),
                'user_id' => $request->user()?->id,
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Gagal mengambil daftar Purchase Order yang menunggu approval.',
                'error' => app()->environment('local')
                    ? $e->getMessage()
                    : null,
            ], 500);
        }
    }

    /**
     * Aturan validasi filter periode + cabang + departemen yang dipakai
     * bersama oleh index() dan pendingApprovals(), supaya keduanya selalu
     * menginterpretasikan filter dengan cara yang sama.
     */
    private function periodFilterValidationRules(): array
    {
        return [
            'period' => [
                'required',
                Rule::in([
                    'day',
                    'week',
                    'month',
                    'year',
                    'range',
                ]),
            ],

            'date' => [
                'nullable',
                'required_if:period,day',
                'date_format:Y-m-d',
            ],

            'week' => [
                'nullable',
                'required_if:period,week',
                'regex:/^\d{4}-W\d{2}$/',
            ],

            'month' => [
                'nullable',
                'required_if:period,month',
                'date_format:Y-m',
            ],

            'year' => [
                'nullable',
                'required_if:period,year',
                'integer',
                'min:2000',
                'max:2100',
            ],

            'start_date' => [
                'nullable',
                'required_if:period,range',
                'date_format:Y-m-d',
            ],

            'end_date' => [
                'nullable',
                'required_if:period,range',
                'date_format:Y-m-d',
                'after_or_equal:start_date',
            ],

            'cabang_id' => [
                'nullable',
                'integer',
                'exists:cabang,id',
            ],

            'department_id' => [
                'nullable',
                'integer',
                'exists:departments,id',
            ],
        ];
    }
}
