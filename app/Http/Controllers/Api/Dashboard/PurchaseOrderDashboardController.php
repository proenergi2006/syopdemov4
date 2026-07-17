<?php

namespace App\Http\Controllers\Api\Dashboard;

use App\Http\Controllers\Controller;
use App\Services\Dashboard\PurchaseOrderDashboardService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class PurchaseOrderDashboardController extends Controller
{
    public function __construct(
        private readonly PurchaseOrderDashboardService $dashboardService,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $user = $request->user();

        if (
            !$user
            || !$user->hasPermission(
                'dashboard.po.view',
            )
        ) {
            abort(
                403,
                'Anda tidak memiliki akses ke dashboard Purchase Order.',
            );
        }

        $validated = $request->validate([
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
        ]);

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
    }
}
