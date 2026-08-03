<?php

namespace App\Services\Dashboard;

use App\Models\User;
use App\Support\ApprovalWaitingDuration;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class PurchaseApprovalNotificationService
{
    /**
     * Ringkasan approval yang sedang menggantung (WAITING) untuk
     * user yang sedang login, dikelompokkan per dashboard module code.
     *
     * Hanya berisi count > 0 jika user memang approver pada step
     * WAITING saat ini (langsung sebagai USER atau lewat ROLE-nya).
     */
    public function getSummary(User $user): array
    {
        $summary = [];

        foreach ($this->moduleConfigs() as $moduleCode => $config) {
            $summary[$moduleCode] = $this->summarizeForConfig(
                $user,
                $config,
            );
        }

        return $summary;
    }

    private function moduleConfigs(): array
    {
        return [
            'PURCHASE_REQUISITION' => [
                'document_table' => 'purchase_requests',
                'approval_table' => 'purchase_request_approvals',

                'document_primary_key' => 'id',
                'approval_foreign_key' => 'purchase_request_id',

                'document_status_column' => 'status',
                'document_in_progress_status' => 'IN PROGRESS',

                'approval_status_column' => 'status',
                'waiting_status' => 'WAITING',

                'step_order_column' => 'step_order',
                'approver_type_column' => 'approver_type',
                'approver_id_column' => 'approver_id',
                'waiting_since_column' => 'updated_at',
            ],

            'PURCHASE_ORDER' => [
                'document_table' => 'purchase_orders',
                'approval_table' => 'purchase_order_approvals',

                'document_primary_key' => 'id',
                'approval_foreign_key' => 'purchase_order_id',

                'document_status_column' => 'status',
                'document_in_progress_status' => 'IN PROGRESS',

                'approval_status_column' => 'status',
                'waiting_status' => 'WAITING',

                'step_order_column' => 'step_order',
                'approver_type_column' => 'approver_type',
                'approver_id_column' => 'approver_id',
                'waiting_since_column' => 'updated_at',
            ],
        ];
    }

    private function summarizeForConfig(User $user, array $config): array
    {
        $empty = [
            'pending_count' => 0,
            'oldest_waiting_at' => null,
            'waiting_days' => 0,
            'waiting_hours' => 0,
            'waiting_label' => null,
        ];

        $documentTable = $config['document_table'];
        $approvalTable = $config['approval_table'];

        if (
            !Schema::hasTable($documentTable)
            || !Schema::hasTable($approvalTable)
        ) {
            return $empty;
        }

        $requiredDocumentColumns = [
            $config['document_primary_key'],
            $config['document_status_column'],
        ];

        $requiredApprovalColumns = [
            $config['approval_foreign_key'],
            $config['approval_status_column'],
            $config['step_order_column'],
            $config['approver_type_column'],
            $config['approver_id_column'],
            $config['waiting_since_column'],
        ];

        foreach ($requiredDocumentColumns as $column) {
            if (!Schema::hasColumn($documentTable, $column)) {
                return $empty;
            }
        }

        foreach ($requiredApprovalColumns as $column) {
            if (!Schema::hasColumn($approvalTable, $column)) {
                return $empty;
            }
        }

        $userRoleIds = DB::table('user_roles')
            ->where('user_id', $user->id)
            ->pluck('role_id')
            ->map(fn($id): int => (int) $id)
            ->filter(fn($id): bool => $id > 0)
            ->unique()
            ->values();

        $documentPrimaryKey = $config['document_primary_key'];
        $approvalForeignKey = $config['approval_foreign_key'];

        $documentStatusColumn = $config['document_status_column'];
        $documentInProgressStatus = strtoupper(trim((string) $config['document_in_progress_status']));

        $approvalStatusColumn = $config['approval_status_column'];
        $waitingStatus = strtoupper(trim((string) $config['waiting_status']));

        $stepOrderColumn = $config['step_order_column'];
        $approverTypeColumn = $config['approver_type_column'];
        $approverIdColumn = $config['approver_id_column'];
        $waitingSinceColumn = $config['waiting_since_column'];

        $row = DB::table($approvalTable)
            ->join(
                $documentTable,
                "{$documentTable}.{$documentPrimaryKey}",
                '=',
                "{$approvalTable}.{$approvalForeignKey}",
            )
            ->whereRaw(
                "UPPER(TRIM({$documentTable}.{$documentStatusColumn})) = ?",
                [$documentInProgressStatus],
            )
            ->whereRaw(
                "UPPER(TRIM({$approvalTable}.{$approvalStatusColumn})) = ?",
                [$waitingStatus],
            )
            ->whereRaw(
                "{$approvalTable}.{$stepOrderColumn} = (
                    SELECT MIN(approval_min.{$stepOrderColumn})
                    FROM {$approvalTable} AS approval_min
                    WHERE approval_min.{$approvalForeignKey} = {$approvalTable}.{$approvalForeignKey}
                      AND UPPER(TRIM(approval_min.{$approvalStatusColumn})) = ?
                )",
                [$waitingStatus],
            )
            ->where(function ($approverQuery) use (
                $user,
                $userRoleIds,
                $approvalTable,
                $approverTypeColumn,
                $approverIdColumn,
            ) {
                $approverQuery->where(function ($userQuery) use (
                    $user,
                    $approvalTable,
                    $approverTypeColumn,
                    $approverIdColumn,
                ) {
                    $userQuery
                        ->whereRaw(
                            "UPPER(TRIM({$approvalTable}.{$approverTypeColumn})) = ?",
                            ['USER'],
                        )
                        ->where("{$approvalTable}.{$approverIdColumn}", $user->id);
                });

                if ($userRoleIds->isNotEmpty()) {
                    $approverQuery->orWhere(function ($roleQuery) use (
                        $userRoleIds,
                        $approvalTable,
                        $approverTypeColumn,
                        $approverIdColumn,
                    ) {
                        $roleQuery
                            ->whereRaw(
                                "UPPER(TRIM({$approvalTable}.{$approverTypeColumn})) = ?",
                                ['ROLE'],
                            )
                            ->whereIn("{$approvalTable}.{$approverIdColumn}", $userRoleIds->all());
                    });
                }
            })
            ->selectRaw(
                "COUNT(DISTINCT {$documentTable}.{$documentPrimaryKey}) AS pending_count, "
                . "MIN({$approvalTable}.{$waitingSinceColumn}) AS oldest_waiting_at",
            )
            ->first();

        $pendingCount = (int) ($row->pending_count ?? 0);

        if ($pendingCount <= 0 || !$row->oldest_waiting_at) {
            return $empty;
        }

        $oldestWaitingAt = Carbon::parse($row->oldest_waiting_at);

        return array_merge(
            [
                'pending_count' => $pendingCount,
                'oldest_waiting_at' => $oldestWaitingAt->toIso8601String(),
            ],
            ApprovalWaitingDuration::describe($oldestWaitingAt),
        );
    }
}
