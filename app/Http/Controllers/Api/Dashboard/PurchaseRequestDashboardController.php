<?php

namespace App\Http\Controllers\Api\Dashboard;

use App\Http\Controllers\Controller;
use App\Services\Dashboard\PurchaseRequestDashboardService;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/*
|--------------------------------------------------------------------------
| Dashboard Purchase Requisition
|--------------------------------------------------------------------------
| Bentuk filter, penanganan scope, dan bentuk responsnya sengaja mengikuti
| dashboard Purchase Order supaya frontend keduanya bisa memakai pola yang
| sama.
|--------------------------------------------------------------------------
*/
class PurchaseRequestDashboardController extends Controller
{
    public function __construct(
        private readonly PurchaseRequestDashboardService $dashboardService,
    ) {}

    public function index(Request $request): JsonResponse
    {
        try {
            $user = $request->user();

            if (!$user || !$user->hasPermission('dashboard.pr.view')) {
                return response()->json([
                    'success' => false,
                    'message' => 'Anda tidak memiliki akses ke dashboard Purchase Requisition.',
                ], 403);
            }

            $validated = $request->validate($this->periodFilterValidationRules());

            /*
            | OWN_CABANG dan OWN_DEPARTMENT menimpa filter tertentu dengan data
            | user login. Controller hanya meneruskan hasil resolve ke service.
            */
            $resolvedAccess = $this->dashboardService->resolveAccessAndFilters(
                user: $user,
                filters: $validated,
            );

            $dashboard = $this->dashboardService->getDashboard($resolvedAccess['filters']);

            return response()->json([
                'success' => true,
                'message' => 'Purchase Requisition dashboard retrieved successfully.',

                'data' => [
                    'access' => $resolvedAccess['access'],
                    'filters' => $dashboard['filters'] ?? [],
                    'summary' => $dashboard['summary'] ?? [],
                    'statuses' => $dashboard['statuses'] ?? [],
                    'trend' => $dashboard['trend'] ?? ['granularity' => 'day', 'points' => []],
                    'approval_bottleneck' => $dashboard['approval_bottleneck'] ?? [],

                    'attention' => $dashboard['attention'] ?? [
                        'threshold_days' => 0,
                        'stuck_approval' => [],
                        'awaiting_po' => [],
                    ],

                    'breakdown' => $dashboard['breakdown'] ?? [
                        'by_cabang' => [],
                        'by_department' => [],
                        'by_type' => [],
                    ],
                ],
            ], 200);
        } catch (ValidationException $e) {
            return response()->json([
                'success' => false,
                'message' => collect($e->errors())->flatten()->first()
                    ?? 'Filter dashboard tidak valid.',
                'errors' => $e->errors(),
            ], 422);
        } catch (AuthorizationException $e) {
            return response()->json([
                'success' => false,
                'message' => $e->getMessage(),
            ], 403);
        } catch (\Throwable $e) {
            Log::error('[Dashboard PR] Index error', [
                'message' => $e->getMessage(),
                'file' => $e->getFile(),
                'line' => $e->getLine(),
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Dashboard Purchase Requisition gagal dimuat.',
                'debug' => app()->environment('local') ? $e->getMessage() : null,
            ], 500);
        }
    }

    private function periodFilterValidationRules(): array
    {
        return [
            'period' => ['required', Rule::in(['day', 'week', 'month', 'year', 'range'])],

            'date' => ['nullable', 'required_if:period,day', 'date_format:Y-m-d'],
            'week' => ['nullable', 'required_if:period,week', 'regex:/^\d{4}-W\d{2}$/'],
            'month' => ['nullable', 'required_if:period,month', 'date_format:Y-m'],

            'year' => [
                'nullable',
                'required_if:period,year',
                'integer',
                'min:2000',
                'max:2100',
            ],

            'start_date' => ['nullable', 'required_if:period,range', 'date_format:Y-m-d'],

            'end_date' => [
                'nullable',
                'required_if:period,range',
                'date_format:Y-m-d',
                'after_or_equal:start_date',
            ],

            'cabang_id' => ['nullable', 'integer', 'exists:cabang,id'],
            'department_id' => ['nullable', 'integer', 'exists:departments,id'],
        ];
    }
}
