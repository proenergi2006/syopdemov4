<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use RuntimeException;

class ApprovalFlowSeeder extends Seeder
{
    public function run(): void
    {
        DB::transaction(function () {
            $typeColumn = $this->flowTypeColumn();
            $flowScopeColumn = $this->flowScopeColumn();
            $stepScopeColumn = $this->stepScopeColumn();

            $permissionModuleIds = [
                'vendor' => $this->permissionModuleId('vendor'),
                'purchase_request' => $this->permissionModuleId('purchase_request'),
                'purchase_order' => $this->permissionModuleId('purchase_order'),
            ];

            $departmentIds = [
                'IT' => $this->departmentId('IT'),
                'GA' => $this->departmentId('GA'),
                'LOG' => $this->departmentId('LOG'),
                'FIN' => $this->departmentId('FIN'),
            ];

            /*
            |--------------------------------------------------------------------------
            | Master Vendor
            |--------------------------------------------------------------------------
            */
            $this->seedFlow(
                [
                    'module' => 'Master Vendor',
                    'name' => 'Approval Master Vendor',
                    'is_active' => true,
                    $typeColumn => 'Vendor',
                    'min_amount' => null,
                    'max_amount' => null,
                    'description' => 'Approval master vendor.',
                    'created_by' => null,
                    'updated_by' => null,
                    'deleted_at' => null,
                    $flowScopeColumn => null,
                    'branch_id' => null,
                    'department_id' => null,
                    'permission_module_id' => $permissionModuleIds['vendor'],
                ],
                [
                    [
                        'step_order' => 1,
                        'approver_type' => 'ROLE',
                        'role_kode' => 'FIN',
                        'label' => 'Finance Approval',
                        'approval_mode' => 'ANY',
                        $stepScopeColumn => 'GLOBAL',
                    ],
                ],
            );

            /*
            |--------------------------------------------------------------------------
            | Purchase Order
            |--------------------------------------------------------------------------
            | Konsep:
            | - Semua nilai      : GM Procurement
            | - > 10 s/d 50 juta : CFO
            | - > 50 juta        : CEO
            |--------------------------------------------------------------------------
            */
            $this->seedFlow(
                [
                    'module' => 'Procurement',
                    'name' => 'Approval PO Semua Nilai',
                    'is_active' => true,
                    $typeColumn => 'PO',
                    'min_amount' => null,
                    'max_amount' => null,
                    'description' => 'Approval wajib untuk semua PO.',
                    'created_by' => null,
                    'updated_by' => null,
                    'deleted_at' => null,
                    $flowScopeColumn => null,
                    'branch_id' => null,
                    'department_id' => null,
                    'permission_module_id' => $permissionModuleIds['purchase_order'],
                ],
                [
                    [
                        'step_order' => 1,
                        'approver_type' => 'ROLE',
                        'role_kode' => 'GMP',
                        'label' => 'GM Procurement',
                        'approval_mode' => 'ANY',
                        $stepScopeColumn => 'GLOBAL',
                    ],
                ],
            );

            $this->seedFlow(
                [
                    'module' => 'Procurement',
                    'name' => 'Approval PO 10 Juta sampai 50 Juta',
                    'is_active' => true,
                    $typeColumn => 'PO',
                    'min_amount' => 10000001,
                    'max_amount' => 50000000,
                    'description' => 'Tambahan approval CFO.',
                    'created_by' => null,
                    'updated_by' => null,
                    'deleted_at' => null,
                    $flowScopeColumn => null,
                    'branch_id' => null,
                    'department_id' => null,
                    'permission_module_id' => $permissionModuleIds['purchase_order'],
                ],
                [
                    [
                        'step_order' => 1,
                        'approver_type' => 'ROLE',
                        'role_kode' => 'CFO',
                        'label' => 'CFO',
                        'approval_mode' => 'ANY',
                        $stepScopeColumn => 'GLOBAL',
                    ],
                ],
            );

            $this->seedFlow(
                [
                    'module' => 'Procurement',
                    'name' => 'Approval PO Di Atas 50 Juta',
                    'is_active' => true,
                    $typeColumn => 'PO',
                    'min_amount' => 50000001,
                    'max_amount' => null,
                    'description' => 'Tambahan approval CEO.',
                    'created_by' => null,
                    'updated_by' => null,
                    'deleted_at' => null,
                    $flowScopeColumn => null,
                    'branch_id' => null,
                    'department_id' => null,
                    'permission_module_id' => $permissionModuleIds['purchase_order'],
                ],
                [
                    [
                        'step_order' => 1,
                        'approver_type' => 'ROLE',
                        'role_kode' => 'CEO',
                        'label' => 'CEO',
                        'approval_mode' => 'ANY',
                        $stepScopeColumn => 'GLOBAL',
                    ],
                ],
            );

            /*
            |--------------------------------------------------------------------------
            | Purchase Request - HO 1 Juta sampai 10 Juta
            |--------------------------------------------------------------------------
            */
            $this->seedPrFlow(
                departmentId: $departmentIds['GA'],
                permissionModuleId: $permissionModuleIds['purchase_request'],
                typeColumn: $typeColumn,
                flowScopeColumn: $flowScopeColumn,
                stepScopeColumn: $stepScopeColumn,
                area: 'HO',
                name: 'Approval PR – HO – Rp 1.000.000 s/d Rp 10.000.000 - GA',
                description: 'PR HO GA.',
                minAmount: 1000000,
                maxAmount: 10000000,
                steps: [
                    ['step_order' => 1, 'role_kode' => 'Spv GA', 'label' => 'Spv GA / GA PIC'],
                    ['step_order' => 2, 'role_kode' => 'HRGA Mgr', 'label' => 'HRGA Manager'],
                ],
            );

            $this->seedPrFlow(
                departmentId: $departmentIds['LOG'],
                permissionModuleId: $permissionModuleIds['purchase_request'],
                typeColumn: $typeColumn,
                flowScopeColumn: $flowScopeColumn,
                stepScopeColumn: $stepScopeColumn,
                area: 'HO',
                name: 'Approval PR – HO – Rp 1.000.000 s/d Rp 10.000.000 - LOG',
                description: 'PR HO Logistik.',
                minAmount: 1000000,
                maxAmount: 10000000,
                steps: [
                    ['step_order' => 1, 'role_kode' => 'Spv LOG', 'label' => 'Spv Log / Log PIC'],
                    ['step_order' => 2, 'role_kode' => 'ML', 'label' => 'Manager Logistik'],
                ],
            );

            $this->seedPrFlow(
                departmentId: $departmentIds['IT'],
                permissionModuleId: $permissionModuleIds['purchase_request'],
                typeColumn: $typeColumn,
                flowScopeColumn: $flowScopeColumn,
                stepScopeColumn: $stepScopeColumn,
                area: 'HO',
                name: 'Approval PR – HO – Rp 1.000.000 s/d Rp 10.000.000 - IT',
                description: 'PR HO IT.',
                minAmount: 1000000,
                maxAmount: 10000000,
                steps: [
                    ['step_order' => 1, 'role_kode' => 'Spv IT', 'label' => 'Spv IT / IT PIC'],
                    ['step_order' => 2, 'role_kode' => 'Mgr IT', 'label' => 'Manager IT'],
                ],
            );

            /*
            |--------------------------------------------------------------------------
            | Purchase Request - CABANG 1 Juta sampai 10 Juta
            |--------------------------------------------------------------------------
            */
            $this->seedPrCabangFlow(
                departmentId: $departmentIds['GA'],
                permissionModuleId: $permissionModuleIds['purchase_request'],
                typeColumn: $typeColumn,
                flowScopeColumn: $flowScopeColumn,
                stepScopeColumn: $stepScopeColumn,
                name: 'Approval PR – Cabang – Rp 1.000.000 s/d Rp 10.000.000 - GA',
                description: 'PR Cabang GA.',
                minAmount: 1000000,
                maxAmount: 10000000,
                departmentHeadRole: 'HRGA Mgr',
                departmentHeadLabel: 'HRGA Manager',
            );

            $this->seedPrCabangFlow(
                departmentId: $departmentIds['LOG'],
                permissionModuleId: $permissionModuleIds['purchase_request'],
                typeColumn: $typeColumn,
                flowScopeColumn: $flowScopeColumn,
                stepScopeColumn: $stepScopeColumn,
                name: 'Approval PR – Cabang – Rp 1.000.000 s/d Rp 10.000.000 - LOG',
                description: 'PR Cabang Logistik.',
                minAmount: 1000000,
                maxAmount: 10000000,
                departmentHeadRole: 'ML',
                departmentHeadLabel: 'Manager Logistik',
            );

            $this->seedPrCabangFlow(
                departmentId: $departmentIds['IT'],
                permissionModuleId: $permissionModuleIds['purchase_request'],
                typeColumn: $typeColumn,
                flowScopeColumn: $flowScopeColumn,
                stepScopeColumn: $stepScopeColumn,
                name: 'Approval PR – Cabang – Rp 1.000.000 s/d Rp 10.000.000 - IT',
                description: 'PR Cabang IT.',
                minAmount: 1000000,
                maxAmount: 10000000,
                departmentHeadRole: 'Mgr IT',
                departmentHeadLabel: 'Manager IT',
            );

            /*
            |--------------------------------------------------------------------------
            | Purchase Request - HO 10 Juta sampai 50 Juta
            |--------------------------------------------------------------------------
            */
            $this->seedPrFlow(
                departmentId: $departmentIds['GA'],
                permissionModuleId: $permissionModuleIds['purchase_request'],
                typeColumn: $typeColumn,
                flowScopeColumn: $flowScopeColumn,
                stepScopeColumn: $stepScopeColumn,
                area: 'HO',
                name: 'Approval PR – HO – Rp 10.000.001 s/d Rp 50.000.000 - GA',
                description: 'Purchase Request HO GA.',
                minAmount: 10000001,
                maxAmount: 50000000,
                steps: [
                    ['step_order' => 1, 'role_kode' => 'Spv GA', 'label' => 'Spv GA / GA PIC'],
                    ['step_order' => 2, 'role_kode' => 'HRGA Mgr', 'label' => 'HRGA Manager'],
                    ['step_order' => 3, 'role_kode' => 'MGF', 'label' => 'Finance Manager'],
                    ['step_order' => 4, 'role_kode' => 'CFO', 'label' => 'CFO'],
                ],
            );

            $this->seedPrFlow(
                departmentId: $departmentIds['LOG'],
                permissionModuleId: $permissionModuleIds['purchase_request'],
                typeColumn: $typeColumn,
                flowScopeColumn: $flowScopeColumn,
                stepScopeColumn: $stepScopeColumn,
                area: 'HO',
                name: 'Approval PR – HO – Rp 10.000.001 s/d Rp 50.000.000 - LOG',
                description: 'Purchase Request HO Logistik.',
                minAmount: 10000001,
                maxAmount: 50000000,
                steps: [
                    ['step_order' => 1, 'role_kode' => 'Spv LOG', 'label' => 'Spv Log / Log PIC'],
                    ['step_order' => 2, 'role_kode' => 'ML', 'label' => 'Manager Logistik'],
                    ['step_order' => 3, 'role_kode' => 'MGF', 'label' => 'Finance Manager'],
                    ['step_order' => 4, 'role_kode' => 'CFO', 'label' => 'CFO'],
                ],
            );

            $this->seedPrFlow(
                departmentId: $departmentIds['IT'],
                permissionModuleId: $permissionModuleIds['purchase_request'],
                typeColumn: $typeColumn,
                flowScopeColumn: $flowScopeColumn,
                stepScopeColumn: $stepScopeColumn,
                area: 'HO',
                name: 'Approval PR – HO – Rp 10.000.001 s/d Rp 50.000.000 - IT',
                description: 'Purchase Request HO IT.',
                minAmount: 10000001,
                maxAmount: 50000000,
                steps: [
                    ['step_order' => 1, 'role_kode' => 'Spv IT', 'label' => 'Spv IT / IT PIC'],
                    ['step_order' => 2, 'role_kode' => 'Mgr IT', 'label' => 'Manager IT'],
                    ['step_order' => 3, 'role_kode' => 'MGF', 'label' => 'Finance Manager'],
                    ['step_order' => 4, 'role_kode' => 'CFO', 'label' => 'CFO'],
                ],
            );

            /*
            |--------------------------------------------------------------------------
            | Purchase Request - CABANG 10 Juta sampai 50 Juta
            |--------------------------------------------------------------------------
            */
            $this->seedPrCabangFlow(
                departmentId: $departmentIds['GA'],
                permissionModuleId: $permissionModuleIds['purchase_request'],
                typeColumn: $typeColumn,
                flowScopeColumn: $flowScopeColumn,
                stepScopeColumn: $stepScopeColumn,
                name: 'Approval PR – Cabang – Rp 10.000.001 s/d Rp 50.000.000 - GA',
                description: 'Purchase Request Cabang GA.',
                minAmount: 10000001,
                maxAmount: 50000000,
                departmentHeadRole: 'HRGA Mgr',
                departmentHeadLabel: 'HRGA Manager',
                extraSteps: [
                    ['step_order' => 4, 'role_kode' => 'MGF', 'label' => 'Finance Manager'],
                    ['step_order' => 5, 'role_kode' => 'CFO', 'label' => 'CFO'],
                ],
            );

            $this->seedPrCabangFlow(
                departmentId: $departmentIds['LOG'],
                permissionModuleId: $permissionModuleIds['purchase_request'],
                typeColumn: $typeColumn,
                flowScopeColumn: $flowScopeColumn,
                stepScopeColumn: $stepScopeColumn,
                name: 'Approval PR – Cabang – Rp 10.000.001 s/d Rp 50.000.000 - LOG',
                description: 'Purchase Request Cabang Logistik.',
                minAmount: 10000001,
                maxAmount: 50000000,
                departmentHeadRole: 'LH',
                departmentHeadLabel: 'Logistic Head',
                extraSteps: [
                    ['step_order' => 4, 'role_kode' => 'MGF', 'label' => 'Finance Manager'],
                    ['step_order' => 5, 'role_kode' => 'CFO', 'label' => 'CFO'],
                ],
            );

            $this->seedPrCabangFlow(
                departmentId: $departmentIds['IT'],
                permissionModuleId: $permissionModuleIds['purchase_request'],
                typeColumn: $typeColumn,
                flowScopeColumn: $flowScopeColumn,
                stepScopeColumn: $stepScopeColumn,
                name: 'Approval PR – Cabang – Rp 10.000.001 s/d Rp 50.000.000 - IT',
                description: 'Purchase Request Cabang IT.',
                minAmount: 10000001,
                maxAmount: 50000000,
                departmentHeadRole: 'ITH',
                departmentHeadLabel: 'IT Head',
                extraSteps: [
                    ['step_order' => 4, 'role_kode' => 'MGF', 'label' => 'Finance Manager'],
                    ['step_order' => 5, 'role_kode' => 'CFO', 'label' => 'CFO'],
                ],
            );

            /*
            |--------------------------------------------------------------------------
            | Purchase Request - HO > 50 Juta
            |--------------------------------------------------------------------------
            */
            $this->seedPrFlow(
                departmentId: $departmentIds['GA'],
                permissionModuleId: $permissionModuleIds['purchase_request'],
                typeColumn: $typeColumn,
                flowScopeColumn: $flowScopeColumn,
                stepScopeColumn: $stepScopeColumn,
                area: 'HO',
                name: 'Matrix Approval PR – HO – > Rp 50.000.000 - GA',
                description: 'Purchase Request HO GA di atas 50 juta.',
                minAmount: 50000001,
                maxAmount: null,
                steps: [
                    ['step_order' => 1, 'role_kode' => 'Spv GA', 'label' => 'Spv GA / GA PIC'],
                    ['step_order' => 2, 'role_kode' => 'HRGA Mgr', 'label' => 'HRGA Manager'],
                    ['step_order' => 3, 'role_kode' => 'MGF', 'label' => 'Finance Manager'],
                    ['step_order' => 4, 'role_kode' => 'CFO', 'label' => 'CFO'],
                    ['step_order' => 5, 'role_kode' => 'CEO', 'label' => 'CEO'],
                ],
            );

            $this->seedPrFlow(
                departmentId: $departmentIds['LOG'],
                permissionModuleId: $permissionModuleIds['purchase_request'],
                typeColumn: $typeColumn,
                flowScopeColumn: $flowScopeColumn,
                stepScopeColumn: $stepScopeColumn,
                area: 'HO',
                name: 'Matrix Approval PR – HO – > Rp 50.000.000 - LOG',
                description: 'Purchase Request HO Logistik di atas 50 juta.',
                minAmount: 50000001,
                maxAmount: null,
                steps: [
                    ['step_order' => 1, 'role_kode' => 'Spv LOG', 'label' => 'Spv Log / Log PIC'],
                    ['step_order' => 2, 'role_kode' => 'ML', 'label' => 'Manager Logistik'],
                    ['step_order' => 3, 'role_kode' => 'MGF', 'label' => 'Finance Manager'],
                    ['step_order' => 4, 'role_kode' => 'CFO', 'label' => 'CFO'],
                    ['step_order' => 5, 'role_kode' => 'CEO', 'label' => 'CEO'],
                ],
            );

            $this->seedPrFlow(
                departmentId: $departmentIds['IT'],
                permissionModuleId: $permissionModuleIds['purchase_request'],
                typeColumn: $typeColumn,
                flowScopeColumn: $flowScopeColumn,
                stepScopeColumn: $stepScopeColumn,
                area: 'HO',
                name: 'Matrix Approval PR – HO – > Rp 50.000.000 - IT',
                description: 'Purchase Request HO IT di atas 50 juta.',
                minAmount: 50000001,
                maxAmount: null,
                steps: [
                    ['step_order' => 1, 'role_kode' => 'Spv IT', 'label' => 'Spv IT / IT PIC'],
                    ['step_order' => 2, 'role_kode' => 'Mgr IT', 'label' => 'Manager IT'],
                    ['step_order' => 3, 'role_kode' => 'MGF', 'label' => 'Finance Manager'],
                    ['step_order' => 4, 'role_kode' => 'CFO', 'label' => 'CFO'],
                    ['step_order' => 5, 'role_kode' => 'CEO', 'label' => 'CEO'],
                ],
            );

            $this->seedPrFlow(
                departmentId: $departmentIds['FIN'],
                permissionModuleId: $permissionModuleIds['purchase_request'],
                typeColumn: $typeColumn,
                flowScopeColumn: $flowScopeColumn,
                stepScopeColumn: $stepScopeColumn,
                area: 'HO',
                name: 'Matrix Approval PR – HO – > Rp 50.000.000 - FIN',
                description: 'Purchase Request HO Finance di atas 50 juta.',
                minAmount: 50000001,
                maxAmount: null,
                steps: [
                    ['step_order' => 1, 'role_kode' => 'F Spv', 'label' => 'Finance Spv / Finance PIC'],
                    ['step_order' => 2, 'role_kode' => 'MGF', 'label' => 'Finance Manager'],
                    ['step_order' => 3, 'role_kode' => 'CFO', 'label' => 'CFO'],
                    ['step_order' => 4, 'role_kode' => 'CEO', 'label' => 'CEO'],
                ],
            );

            /*
            |--------------------------------------------------------------------------
            | Purchase Request - CABANG > 50 Juta
            |--------------------------------------------------------------------------
            */
            $this->seedPrCabangFlow(
                departmentId: $departmentIds['GA'],
                permissionModuleId: $permissionModuleIds['purchase_request'],
                typeColumn: $typeColumn,
                flowScopeColumn: $flowScopeColumn,
                stepScopeColumn: $stepScopeColumn,
                name: 'Matrix Approval PR – Cabang – > Rp 50.000.000 - GA',
                description: 'Purchase Request Cabang GA di atas 50 juta.',
                minAmount: 50000001,
                maxAmount: null,
                departmentHeadRole: 'HRGA Mgr',
                departmentHeadLabel: 'HRGA Manager',
                extraSteps: [
                    ['step_order' => 4, 'role_kode' => 'MGF', 'label' => 'Finance Manager'],
                    ['step_order' => 5, 'role_kode' => 'CFO', 'label' => 'CFO'],
                    ['step_order' => 6, 'role_kode' => 'CEO', 'label' => 'CEO'],
                ],
            );

            $this->seedPrCabangFlow(
                departmentId: $departmentIds['LOG'],
                permissionModuleId: $permissionModuleIds['purchase_request'],
                typeColumn: $typeColumn,
                flowScopeColumn: $flowScopeColumn,
                stepScopeColumn: $stepScopeColumn,
                name: 'Matrix Approval PR – Cabang – > Rp 50.000.000 - LOG',
                description: 'Purchase Request Cabang Logistik di atas 50 juta.',
                minAmount: 50000001,
                maxAmount: null,
                departmentHeadRole: 'LH',
                departmentHeadLabel: 'Logistic Head',
                extraSteps: [
                    ['step_order' => 4, 'role_kode' => 'MGF', 'label' => 'Finance Manager'],
                    ['step_order' => 5, 'role_kode' => 'CFO', 'label' => 'CFO'],
                    ['step_order' => 6, 'role_kode' => 'CEO', 'label' => 'CEO'],
                ],
            );

            $this->seedPrCabangFlow(
                departmentId: $departmentIds['IT'],
                permissionModuleId: $permissionModuleIds['purchase_request'],
                typeColumn: $typeColumn,
                flowScopeColumn: $flowScopeColumn,
                stepScopeColumn: $stepScopeColumn,
                name: 'Matrix Approval PR – Cabang – > Rp 50.000.000 - IT',
                description: 'Purchase Request Cabang IT di atas 50 juta.',
                minAmount: 50000001,
                maxAmount: null,
                departmentHeadRole: 'ITH',
                departmentHeadLabel: 'IT Head',
                extraSteps: [
                    ['step_order' => 4, 'role_kode' => 'MGF', 'label' => 'Finance Manager'],
                    ['step_order' => 5, 'role_kode' => 'CFO', 'label' => 'CFO'],
                    ['step_order' => 6, 'role_kode' => 'CEO', 'label' => 'CEO'],
                ],
            );
        });
    }

    private function seedPrFlow(
        int $departmentId,
        int $permissionModuleId,
        string $typeColumn,
        string $flowScopeColumn,
        string $stepScopeColumn,
        string $area,
        string $name,
        ?string $description,
        float|int|null $minAmount,
        float|int|null $maxAmount,
        array $steps,
    ): void {
        $this->seedFlow(
            [
                'module' => 'Procurement',
                'name' => $name,
                'is_active' => true,
                $typeColumn => 'PR',
                'min_amount' => $minAmount,
                'max_amount' => $maxAmount,
                'description' => $description,
                'created_by' => null,
                'updated_by' => null,
                'deleted_at' => null,
                $flowScopeColumn => $area,
                'branch_id' => null,
                'department_id' => $departmentId,
                'permission_module_id' => $permissionModuleId,
            ],
            collect($steps)
                ->map(function (array $step) use ($stepScopeColumn) {
                    return [
                        'step_order' => $step['step_order'],
                        'approver_type' => 'ROLE',
                        'role_kode' => $step['role_kode'],
                        'label' => $step['label'],
                        'approval_mode' => 'ANY',
                        $stepScopeColumn => 'GLOBAL',
                    ];
                })
                ->all(),
        );
    }

    private function seedPrCabangFlow(
        int $departmentId,
        int $permissionModuleId,
        string $typeColumn,
        string $flowScopeColumn,
        string $stepScopeColumn,
        string $name,
        ?string $description,
        float|int|null $minAmount,
        float|int|null $maxAmount,
        string $departmentHeadRole,
        string $departmentHeadLabel,
        array $extraSteps = [],
    ): void {
        $steps = [
            [
                'step_order' => 1,
                'approver_type' => 'ROLE',
                'role_kode' => 'AF',
                'label' => 'Adm / ADH',
                'approval_mode' => 'ANY',
                $stepScopeColumn => 'SAME_BRANCH',
            ],
            [
                'step_order' => 1,
                'approver_type' => 'ROLE',
                'role_kode' => 'ADH',
                'label' => 'Adm / ADH',
                'approval_mode' => 'ANY',
                $stepScopeColumn => 'SAME_BRANCH',
            ],
            [
                'step_order' => 2,
                'approver_type' => 'ROLE',
                'role_kode' => 'BH',
                'label' => 'Kacab',
                'approval_mode' => 'ANY',
                $stepScopeColumn => 'SAME_BRANCH',
            ],
            [
                'step_order' => 3,
                'approver_type' => 'ROLE',
                'role_kode' => $departmentHeadRole,
                'label' => $departmentHeadLabel,
                'approval_mode' => 'ANY',
                $stepScopeColumn => 'GLOBAL',
            ],
        ];

        foreach ($extraSteps as $extraStep) {
            $steps[] = [
                'step_order' => $extraStep['step_order'],
                'approver_type' => 'ROLE',
                'role_kode' => $extraStep['role_kode'],
                'label' => $extraStep['label'],
                'approval_mode' => 'ANY',
                $stepScopeColumn => 'GLOBAL',
            ];
        }

        $this->seedFlow(
            [
                'module' => 'Procurement',
                'name' => $name,
                'is_active' => true,
                $typeColumn => 'PR',
                'min_amount' => $minAmount,
                'max_amount' => $maxAmount,
                'description' => $description,
                'created_by' => null,
                'updated_by' => null,
                'deleted_at' => null,
                $flowScopeColumn => 'CABANG',
                'branch_id' => null,
                'department_id' => $departmentId,
                'permission_module_id' => $permissionModuleId,
            ],
            $steps,
        );
    }

    private function seedFlow(
        array $flowData,
        array $steps,
    ): void {
        $now = now();

        $flowData = $this->onlyExistingColumns(
            'approval_flows',
            array_merge(
                $flowData,
                [
                    'created_at' => $now,
                    'updated_at' => $now,
                ],
            ),
        );

        $lookupColumns = [
            'name',
            $this->flowTypeColumn(),
            'min_amount',
            'max_amount',
            $this->flowScopeColumn(),
            'department_id',
            'permission_module_id',
        ];

        $existingFlowQuery = DB::table('approval_flows');

        foreach ($lookupColumns as $column) {
            if (
                $column === null
                || !Schema::hasColumn('approval_flows', $column)
                || !array_key_exists($column, $flowData)
            ) {
                continue;
            }

            $value = $flowData[$column];

            if ($value === null) {
                $existingFlowQuery->whereNull($column);
            } else {
                $existingFlowQuery->where($column, $value);
            }
        }

        $existingFlow = $existingFlowQuery->first();

        if ($existingFlow) {
            $updateData = $flowData;
            unset($updateData['created_at']);

            DB::table('approval_flows')
                ->where('id', $existingFlow->id)
                ->update($updateData);

            $flowId = (int) $existingFlow->id;
        } else {
            $flowId = (int) DB::table('approval_flows')
                ->insertGetId($flowData);
        }

        /*
        |--------------------------------------------------------------------------
        | Reset steps hanya untuk flow yang diseed.
        |--------------------------------------------------------------------------
        | Ini sengaja agar step lama/test/user-id lama tidak ikut kebawa.
        |--------------------------------------------------------------------------
        */
        $oldStepIds = DB::table('approval_flow_steps')
            ->where('approval_flow_id', $flowId)
            ->pluck('id');

        if (
            $oldStepIds->isNotEmpty()
            && Schema::hasTable('approval_flow_step_branches')
        ) {
            DB::table('approval_flow_step_branches')
                ->whereIn('approval_flow_step_id', $oldStepIds)
                ->delete();
        }

        DB::table('approval_flow_steps')
            ->where('approval_flow_id', $flowId)
            ->delete();

        foreach ($steps as $step) {
            $roleId = $this->roleId($step['role_kode']);

            $stepData = [
                'approval_flow_id' => $flowId,
                'step_order' => (int) $step['step_order'],
                'approver_type' => $step['approver_type'] ?? 'ROLE',
                'approver_id' => $roleId,
                'label' => $step['label'],
                'is_active' => true,
                'approval_mode' => $step['approval_mode'] ?? 'ANY',
                $this->stepScopeColumn() => $step[$this->stepScopeColumn()] ?? 'GLOBAL',
                'created_at' => $now,
                'updated_at' => $now,
            ];

            DB::table('approval_flow_steps')->insert(
                $this->onlyExistingColumns(
                    'approval_flow_steps',
                    $stepData,
                ),
            );
        }
    }

    private function roleId(string $kode): int
    {
        $roleId = DB::table('roles')
            ->where('kode', $kode)
            ->where('is_active', true)
            ->value('id');

        if (!$roleId) {
            throw new RuntimeException(
                "Role dengan kode {$kode} tidak ditemukan. Jalankan RoleSeeder terlebih dahulu."
            );
        }

        return (int) $roleId;
    }

    private function departmentId(string $kode): int
    {
        $departmentId = DB::table('departments')
            ->where('kode', $kode)
            ->where('is_active', true)
            ->value('id');

        if (!$departmentId) {
            throw new RuntimeException(
                "Department dengan kode {$kode} tidak ditemukan. Jalankan DepartmentSeeder terlebih dahulu."
            );
        }

        return (int) $departmentId;
    }

    private function permissionModuleId(string $code): int
    {
        $moduleId = DB::table('permission_modules')
            ->where('code', $code)
            ->where('is_active', true)
            ->value('id');

        if (!$moduleId) {
            throw new RuntimeException(
                "Permission module {$code} tidak ditemukan. Jalankan PermissionModuleSeeder terlebih dahulu."
            );
        }

        return (int) $moduleId;
    }

    private function flowTypeColumn(): string
    {
        foreach (['type', 'document_type', 'flow_type'] as $column) {
            if (Schema::hasColumn('approval_flows', $column)) {
                return $column;
            }
        }

        throw new RuntimeException(
            'Kolom type/document_type/flow_type tidak ditemukan pada approval_flows.'
        );
    }

    private function flowScopeColumn(): string
    {
        foreach (['branch_type', 'scope', 'approval_scope', 'area_type'] as $column) {
            if (Schema::hasColumn('approval_flows', $column)) {
                return $column;
            }
        }

        throw new RuntimeException(
            'Kolom branch_type/scope/approval_scope/area_type tidak ditemukan pada approval_flows.'
        );
    }

    private function stepScopeColumn(): string
    {
        foreach (['scope', 'approver_scope', 'branch_scope'] as $column) {
            if (Schema::hasColumn('approval_flow_steps', $column)) {
                return $column;
            }
        }

        throw new RuntimeException(
            'Kolom scope/approver_scope/branch_scope tidak ditemukan pada approval_flow_steps.'
        );
    }

    private function onlyExistingColumns(
        string $table,
        array $data,
    ): array {
        $columns = collect(Schema::getColumnListing($table))
            ->flip();

        return collect($data)
            ->filter(
                fn($value, $key): bool => $columns->has($key),
            )
            ->all();
    }
}
