<?php

namespace App\Http\Controllers\Api\Dashboard;

use App\Http\Controllers\Controller;
use App\Services\Dashboard\ClaimDashboardService;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/*
|--------------------------------------------------------------------------
| Dashboard Claim
|--------------------------------------------------------------------------
| Bentuk filter, penanganan scope, dan bentuk responsnya mengikuti dashboard
| FPU dan Realisasi, supaya ketiga layarnya memakai pola frontend yang sama
| dan angkanya bisa dibandingkan langsung.
|--------------------------------------------------------------------------
*/
class ClaimDashboardController extends Controller
{
    public function __construct(
        private readonly ClaimDashboardService $dashboardService,
    ) {}

    public function index(Request $request): JsonResponse
    {
        try {
            $user = $request->user();

            if (!$user || !$user->hasPermission('dashboard.claim.view')) {
                return response()->json([
                    'success' => false,
                    'message' => 'Anda tidak memiliki akses ke dashboard Claim.',
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
                'message' => 'Dashboard Claim berhasil dimuat.',

                'data' => [
                    'access' => $resolvedAccess['access'],
                    'filters' => $dashboard['filters'] ?? [],
                    'summary' => $dashboard['summary'] ?? [],
                    'revision' => $dashboard['revision'] ?? [],
                    'schedule' => $dashboard['schedule'] ?? [],
                    'statuses' => $dashboard['statuses'] ?? [],
                    'trend' => $dashboard['trend'] ?? ['granularity' => 'day', 'points' => []],
                    'approval_bottleneck' => $dashboard['approval_bottleneck'] ?? [],

                    'attention' => $dashboard['attention'] ?? [
                        'threshold_days' => 0,
                        'stuck_approval' => [],
                        'unscheduled' => [],
                        'overdue_payment' => [],
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
            Log::error('[Dashboard Claim] Index error', [
                'message' => $e->getMessage(),
                'file' => $e->getFile(),
                'line' => $e->getLine(),
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Dashboard Claim gagal dimuat.',
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
