<?php

namespace App\Http\Controllers\Api\Dashboard;

use App\Http\Controllers\Controller;
use App\Services\Dashboard\CashAdvanceRealizationDashboardService;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/*
|--------------------------------------------------------------------------
| Dashboard Realisasi FPU
|--------------------------------------------------------------------------
| Bentuk filter, penanganan scope, dan bentuk responsnya mengikuti dashboard
| FPU, supaya kedua layarnya memakai pola frontend yang sama dan angkanya
| bisa dibandingkan langsung.
|--------------------------------------------------------------------------
*/
class CashAdvanceRealizationDashboardController extends Controller
{
    public function __construct(
        private readonly CashAdvanceRealizationDashboardService $dashboardService,
    ) {}

    public function index(Request $request): JsonResponse
    {
        try {
            $user = $request->user();

            if (!$user || !$user->hasPermission('dashboard.cash-advance-realization.view')) {
                return response()->json([
                    'success' => false,
                    'message' => 'Anda tidak memiliki akses ke dashboard Realisasi FPU.',
                ], 403);
            }

            $validated = $request->validate($this->periodFilterValidationRules());

            /*
            | Scope permission menentukan filter mana yang dipaksakan sistem.
            | Seluruh penguncian wilayah dikerjakan di service, pada satu
            | tempat.
            */
            $resolvedAccess = $this->dashboardService->resolveAccessAndFilters(
                user: $user,
                filters: $validated,
            );

            $dashboard = $this->dashboardService->getDashboard($resolvedAccess['filters']);

            return response()->json([
                'success' => true,
                'message' => 'Dashboard Realisasi FPU berhasil dimuat.',

                'data' => [
                    'access' => $resolvedAccess['access'],
                    'filters' => $dashboard['filters'] ?? [],
                    'summary' => $dashboard['summary'] ?? [],
                    'difference' => $dashboard['difference'] ?? [],
                    'statuses' => $dashboard['statuses'] ?? [],
                    'trend' => $dashboard['trend'] ?? ['granularity' => 'day', 'points' => []],
                    'approval_bottleneck' => $dashboard['approval_bottleneck'] ?? [],

                    'attention' => $dashboard['attention'] ?? [
                        'threshold_days' => 0,
                        'realization_limit_days' => 0,
                        'not_submitted' => [],
                        'stuck_approval' => [],
                        'unsettled_difference' => [],
                    ],

                    'breakdown' => $dashboard['breakdown'] ?? [
                        'by_cabang' => [],
                        'by_department' => [],
                        'by_category' => [],
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
            Log::error('[Dashboard Realisasi FPU] Index error', [
                'message' => $e->getMessage(),
                'file' => $e->getFile(),
                'line' => $e->getLine(),
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Dashboard Realisasi FPU gagal dimuat.',
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
