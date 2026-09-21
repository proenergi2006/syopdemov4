<?php

// use App\Http\Api\Master\Controllers\ProdukController as ControllersProdukController;

use App\Http\Controllers\Api\AccountController;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\AccurateController;
use App\Http\Controllers\Api\GainLossInventoryController;
use App\Http\Controllers\Api\GoodsReceiptInventoryController;
use App\Http\Controllers\Api\GoodsReceiveController;
use App\Http\Controllers\Api\Master\ApprovalFlowController;
use App\Http\Controllers\Api\Master\WilayahController;
use App\Http\Controllers\Api\Master\CabangController;
use App\Http\Controllers\Api\Master\DepartmentController;
use App\Http\Controllers\Api\Master\ProvinsiController;
use App\Http\Controllers\Api\Master\KabupatenController;
use App\Http\Controllers\Api\Master\VendorController;
use App\Http\Controllers\Api\Master\AreaController;
use App\Http\Controllers\Api\Master\HargaJualController;
use App\Http\Controllers\Api\Master\HargaPertaminaController;
use App\Http\Controllers\Api\Master\PbbkbController;
use App\Http\Controllers\Api\Master\TerminalController;
use App\Http\Controllers\Api\Master\UserController;
use App\Http\Controllers\Api\Master\RoleController;
use App\Http\Controllers\Api\Master\ProdukController;
use App\Http\Controllers\Api\Master\RoleMenuController;
use App\Http\Controllers\Api\Master\TransportirController;
use App\Http\Controllers\Api\Master\TransportirSopirController;
use App\Http\Controllers\Api\Master\UserPermissionController;

use App\Http\Controllers\Api\Master\VolumeController;
use App\Http\Controllers\Api\Master\WilayahAngkutController;

use App\Http\Controllers\Api\Master\TransportirMobilController;

use App\Http\Controllers\Api\Master\OngkosAngkutController;
use App\Http\Controllers\Api\Master\CustomerController;
use App\Http\Controllers\Api\Master\GroupCabangController;
use App\Http\Controllers\Api\Master\MasterDokumenPendukungController;
use App\Http\Controllers\Api\Master\MasterKeteranganTransaksiController;
use App\Http\Controllers\Api\Master\MasterMaterialGroupController;
use App\Http\Controllers\Api\Master\SpecialDocumentTypeController;
use App\Http\Controllers\Api\Master\MasterVendorController;
use App\Http\Controllers\Api\OngkosAngkutKapalController;
use App\Http\Controllers\Api\Master\PermissionController;
use App\Http\Controllers\Api\Master\PermissionModuleController;
use App\Http\Controllers\Api\Master\RolePermissionController;
use App\Http\Controllers\Api\PurchaseOrderInventoryController;
use App\Http\Controllers\Api\Master\UnitController as MasterUnitController;
use App\Http\Controllers\Api\NotificationController;
use App\Http\Controllers\Api\PurchaseOrderController;
use App\Http\Controllers\Api\CashAdvanceController;
use App\Http\Controllers\Api\CashAdvanceRealizationController;
use App\Http\Controllers\Api\BusinessTripController;
use App\Http\Controllers\Api\ClaimController;
use App\Http\Controllers\Api\Master\FundRequestTransactionCategoryController;
use App\Http\Controllers\Api\Master\FundRequestLimitController;
use App\Http\Controllers\Api\Master\PaymentScheduleController;
use App\Http\Controllers\Api\PurchaseRequestController;
use App\Http\Controllers\Api\ShippingInstructionController;
use App\Http\Controllers\MasterBankController;
use App\Http\Controllers\UnitController;
use Illuminate\Support\Facades\Mail;
use App\Http\Controllers\Api\GoodsReturnController;
use App\Http\Controllers\Api\Dashboard\DashboardModuleController;
use App\Http\Controllers\Api\Dashboard\PurchaseOrderDashboardController;
use App\Http\Controllers\Api\Dashboard\GoodsReceiptDashboardController;
use App\Http\Controllers\Api\Dashboard\PurchaseRequestDashboardController;
use App\Http\Controllers\Api\Monitoring\QueueHealthController;
use App\Http\Controllers\Monitoring\LogViewerController;
use App\Http\Controllers\Api\Master\UserAccessAssignmentController;
use App\Http\Controllers\Api\Master\MenuController as MasterMenuController;
use App\Http\Controllers\Api\Master\DashboardModuleController as MasterDashboardModuleController;
use App\Http\Controllers\Api\Master\ActivityLogController as MasterActivityLogController;

Route::post('/auth/login', [AuthController::class, 'login']);
// routes/api.php

Route::post('/auth/sso', [AuthController::class, 'sso']);

Route::post(
    '/auth/forgot-password',
    [AuthController::class, 'forgotPassword'],
)->middleware('throttle:forgot-password');

Route::get('/auth/reset-password/verify', [AuthController::class, 'verifyResetToken']);
Route::post('/auth/reset-password', [AuthController::class, 'resetPassword']);
Route::middleware(['auth:sanctum', 'auth.token.idle', 'set.locale', 'log.activity'])->group(function () {
    Route::get('/auth/me', [AuthController::class, 'me']);
    Route::get('/auth/me/permissions', [AuthController::class, 'permissions']);
    Route::post('/auth/logout', [AuthController::class, 'logout']);
    Route::put('/account/change-password', [AccountController::class, 'changePassword']);
    Route::put('/account/locale', [AccountController::class, 'updateLocale']);
    Route::get('/account/access-assignments', [AccountController::class, 'accessAssignments']);
    Route::get(
        'master/cabang/options',
        [CabangController::class, 'dropdownSelect']
    );

    Route::get('/notifications', [NotificationController::class, 'index']);
    Route::patch('/notifications/read-all', [NotificationController::class, 'readAll']);
    Route::patch('/notifications/{id}/read', [NotificationController::class, 'read']);
    Route::delete('/notifications/read', [NotificationController::class, 'deleteRead']);

    Route::apiResource('master/wilayah', WilayahController::class);
    Route::apiResource('master/provinsi', ProvinsiController::class);
    Route::apiResource('master/kabupaten', KabupatenController::class);
    // Route::apiResource('master/vendor', VendorController::class);
    Route::apiResource('master/area', AreaController::class);
    Route::apiResource('master/terminal', TerminalController::class);
    Route::get('master/roles', [RoleController::class, 'index']);
    Route::prefix('master/user')->middleware('auth:sanctum')->group(function () {
        Route::get('/check-signature', [UserController::class, 'checkUserSignature']);
        Route::post('/store-signature', [UserController::class, 'storeUserSignature']);
    });

    Route::get('master/dropdown/users', [UserController::class, 'dropdown']);
    Route::get('master/dropdown/roles', [RoleController::class, 'dropdown']);

    Route::apiResource('master/produk', ProdukController::class);
    Route::apiResource('master/pbbkb', PbbkbController::class);

    Route::get('/master/role-menus', [RoleMenuController::class, 'index']);
    Route::post('/master/role-menus', [RoleMenuController::class, 'store']);
    Route::apiResource('master/transportir', TransportirController::class);

    Route::apiResource('master/sopir', TransportirSopirController::class);
    Route::apiResource('master/volume', VolumeController::class);
    Route::apiResource('master/wilayah-angkut', WilayahAngkutController::class);
    Route::apiResource('master/harga-jual', HargaJualController::class);
    Route::apiResource('master/harga-pertamina', HargaPertaminaController::class);
    Route::apiResource('master/oa-kapal', OngkosAngkutKapalController::class);

    //get API
    Route::get('/provinsi', [WilayahAngkutController::class, 'provinsi']);
    Route::get('/kabupaten/{provinsi}', [WilayahAngkutController::class, 'kabupaten']);
    Route::get('/area', [HargaPertaminaController::class, 'area']);
    Route::get('/produk', [HargaPertaminaController::class, 'produk']);
    Route::get('/terminal', [TerminalController::class, 'terminal']);
    Route::get('/transportir', [TransportirController::class, 'transportir']);
    Route::get('/oa-kapal', [OngkosAngkutKapalController::class, 'oaKapal']);
    Route::get('/pbbkb', [PbbkbController::class, 'pbbkb']);

    Route::get('master/transportir-mobil', [TransportirMobilController::class, 'index']);
    Route::post('master/transportir-mobil', [TransportirMobilController::class, 'store']);
    Route::get('master/transportir-mobil/{id}', [TransportirMobilController::class, 'show']);
    Route::post('master/transportir-mobil/{id}', [TransportirMobilController::class, 'update']);
    Route::delete('master/transportir-mobil/{id}', [TransportirMobilController::class, 'destroy']);

    Route::apiResource('master/ongkos-angkut', OngkosAngkutController::class);
    Route::apiResource('master/customers', CustomerController::class);

    Route::apiResource('master/banks', MasterBankController::class);
    Route::patch('master/banks/{id}/status', [MasterBankController::class, 'toggleStatus']);

    Route::get('master/keterangan-transaksi', [MasterKeteranganTransaksiController::class, 'index']);
    Route::get('master/dokumen-pendukung', [MasterDokumenPendukungController::class, 'index']);

    Route::get('/units/dropdown-select', [UnitController::class, 'dropdownSelect']);
    Route::apiResource('/units', UnitController::class);

    Route::get(
        '/material-groups/dropdown-select',
        [MasterMaterialGroupController::class, 'dropdownSelect']
    );

    Route::get(
        '/special-document-types/dropdown-select',
        [SpecialDocumentTypeController::class, 'dropdownSelect']
    );

    /*
    |--------------------------------------------------------------------------
    | Menu Navigation / Sidebar
    |--------------------------------------------------------------------------
    | Endpoint ini dipakai semua user login.
    | Jangan diproteksi dengan auth_menu.view.
    |--------------------------------------------------------------------------
    */
    Route::get('/master/menus/navigation', [MasterMenuController::class, 'navigation']);

    // Menu Management
    Route::prefix('master/menus')->group(function () {
        Route::get('/', [MasterMenuController::class, 'index']);
        Route::post('/', [MasterMenuController::class, 'store']);
        Route::get('/{menu}', [MasterMenuController::class, 'show']);
        Route::put('/{menu}', [MasterMenuController::class, 'update']);
        Route::patch('/{menu}/toggle-active', [MasterMenuController::class, 'toggleActive']);
        Route::delete('/{menu}', [MasterMenuController::class, 'destroy']);
    });

    // Dashboard Module Management
    Route::prefix('master/dashboard-modules')->group(function () {
        Route::get('/', [MasterDashboardModuleController::class, 'index']);
        Route::post('/', [MasterDashboardModuleController::class, 'store']);
        Route::get('/groups', [MasterDashboardModuleController::class, 'groups']);
        Route::post('/groups', [MasterDashboardModuleController::class, 'storeGroup']);
        Route::put('/groups/{group}', [MasterDashboardModuleController::class, 'updateGroup']);
        Route::patch('/groups/{group}/toggle-active', [MasterDashboardModuleController::class, 'toggleGroupActive']);
        Route::delete('/groups/{group}', [MasterDashboardModuleController::class, 'destroyGroup']);
        Route::get('/permission-options', [MasterDashboardModuleController::class, 'permissionOptions']);
        Route::get('/{dashboardModule}', [MasterDashboardModuleController::class, 'show']);
        Route::put('/{dashboardModule}', [MasterDashboardModuleController::class, 'update']);
        Route::patch('/{dashboardModule}/toggle-active', [MasterDashboardModuleController::class, 'toggleActive']);
        Route::patch('/{dashboardModule}/toggle-available', [MasterDashboardModuleController::class, 'toggleAvailable']);
        Route::delete('/{dashboardModule}', [MasterDashboardModuleController::class, 'destroy']);
    });

    // Activity Log
    Route::prefix('master/activity-log')->group(function () {
        Route::get('/', [MasterActivityLogController::class, 'index']);
        Route::get('/filter-options', [MasterActivityLogController::class, 'filterOptions']);
        Route::get('/{id}', [MasterActivityLogController::class, 'show']);
    });

    Route::prefix('monitoring')
        ->group(function () {
            Route::get(
                '/logs',
                [LogViewerController::class, 'index'],
            );

            /*
            | Pemantauan antrean job. Jalur ketiga pelaporan kesehatan
            | antrean, di samping log dan email peringatan.
            */
            Route::get(
                '/queue-health',
                [QueueHealthController::class, 'index'],
            );

            Route::post(
                '/queue-health/retry',
                [QueueHealthController::class, 'retry'],
            );

            Route::delete(
                '/queue-health/failed',
                [QueueHealthController::class, 'destroy'],
            );
        });

    // ===================== USER ACCESS ASSIGNMENT =====================

    Route::get('/master/users/{userId}/access-assignments', [UserAccessAssignmentController::class, 'index']);
    Route::post('/master/users/{userId}/access-assignments', [UserAccessAssignmentController::class, 'store']);
    Route::put('/master/users/{userId}/access-assignments/{assignmentId}', [UserAccessAssignmentController::class, 'update']);


    // ===================== DATA DASHBOARD =====================
    Route::prefix('dashboard')
        ->name('dashboard.')
        ->group(function () {
            Route::get(
                '/module-groups',
                [DashboardModuleController::class, 'groups'],
            )->name('module-groups');

            Route::get(
                '/modules',
                [DashboardModuleController::class, 'index'],
            )->name('modules');

            Route::get(
                '/approval-notifications',
                [DashboardModuleController::class, 'approvalNotifications'],
            )->name('approval-notifications');

            Route::get(
                '/purchase-order',
                [PurchaseOrderDashboardController::class, 'index'],
            )->name('purchase-order');

            Route::get(
                '/purchase-order/pending-approvals',
                [PurchaseOrderDashboardController::class, 'pendingApprovals'],
            )->name('purchase-order.pending-approvals');

            Route::get(
                '/purchase-request',
                [PurchaseRequestDashboardController::class, 'index'],
            )->name('purchase-request');

            Route::get(
                '/goods-receipt',
                [GoodsReceiptDashboardController::class, 'index'],
            )->name('goods-receipt');
        });

    // ===================== DATA MASTER =====================
    Route::prefix('master')->group(function () {

        // Module Permission
        Route::get(
            'permission-modules',
            [PermissionModuleController::class, 'index'],
        );

        Route::post(
            'permission-modules',
            [
                PermissionModuleController::class,
                'store',
            ],
        );

        Route::post(
            'permission-modules/{id}/permissions',
            [
                PermissionModuleController::class,
                'storePermission',
            ],
        )->whereNumber('id');

        Route::get(
            'permission-modules/{id}',
            [
                PermissionModuleController::class,
                'show',
            ],
        )->whereNumber('id');

        Route::put(
            'permission-modules/{id}',
            [
                PermissionModuleController::class,
                'update',
            ],
        )->whereNumber('id');

        Route::put(
            'permission-modules/{moduleId}/permissions/{permissionId}',
            [
                PermissionModuleController::class,
                'updatePermission',
            ],
        )
            ->whereNumber('moduleId')
            ->whereNumber('permissionId');

        Route::delete(
            'permission-modules/{moduleId}/permissions/{permissionId}',
            [
                PermissionModuleController::class,
                'destroyPermission',
            ],
        )
            ->whereNumber('moduleId')
            ->whereNumber('permissionId');

        Route::delete(
            'permission-modules/{id}',
            [
                PermissionModuleController::class,
                'destroy',
            ],
        )->whereNumber('id');

        // Permissions
        Route::get('permissions', [PermissionController::class, 'index']);
        Route::get('permissions/{permission}', [PermissionController::class, 'show']);

        Route::get('role-permissions', [RolePermissionController::class, 'index']);
        Route::put('role-permissions/bulk', [RolePermissionController::class, 'bulkUpdate']);

        Route::get(
            'user-permissions',
            [UserPermissionController::class, 'index'],
        );

        Route::put(
            'user-permissions/bulk',
            [UserPermissionController::class, 'bulkUpdate'],
        );

        // Users
        Route::apiResource('users', UserController::class);
        // Role
        Route::apiResource('roles', RoleController::class);

        // Vendor
        Route::get('vendor/dropdown-select', [MasterVendorController::class, 'dropdownSelect']);
        Route::get('vendor/dropdown-pr', [MasterVendorController::class, 'dropdownSelectForPurchaseRequest']);
        Route::get('vendor/dropdown-po', [MasterVendorController::class, 'dropdownSelectForPurchaseOrder']);
        Route::patch('vendor/{id}/status', [MasterVendorController::class, 'updateStatus']);
        Route::patch('vendor/{publicId}/submit', [MasterVendorController::class, 'submit']);
        Route::patch('vendor/{publicId}/approve', [MasterVendorController::class, 'approve']);
        Route::patch('vendor/{publicId}/reject', [MasterVendorController::class, 'reject']);
        Route::apiResource('vendor', MasterVendorController::class)
            ->parameters([
                'vendor' => 'publicId',
            ]);

        // Group Cabang
        Route::apiResource('group-cabang', GroupCabangController::class);

        // Cabang
        Route::get('cabang/dropdown-select', [CabangController::class, 'dropdownSelect']);
        Route::apiResource('cabang', CabangController::class);

        // Department
        Route::get(
            'department/dropdown-select',
            [DepartmentController::class, 'dropdownSelect']
        );

        Route::apiResource(
            'department',
            DepartmentController::class
        );

        // Approval Flow
        /*
        | Harus didaftarkan sebelum '/approval-flows/{publicId}', kalau tidak
        | "document-types" akan tertangkap sebagai publicId.
        */
        Route::get(
            '/approval-flows/document-types',
            [ApprovalFlowController::class, 'documentTypeOptions'],
        );

        Route::post('/approval-flows', [ApprovalFlowController::class, 'store']);
        Route::get('/approval-flows/{publicId}', [ApprovalFlowController::class, 'show']);
        Route::put('/approval-flows/{publicId}', [ApprovalFlowController::class, 'update']);
        Route::get('/approval-flows', [ApprovalFlowController::class, 'index']);
        Route::patch('/approval-flows/{publicId}/toggle-status', [ApprovalFlowController::class, 'toggleStatus']);
        Route::delete('/approval-flows/{publicId}', [ApprovalFlowController::class, 'destroy']);
    });

    Route::prefix('transaction')
        ->name('transaction.')
        ->group(function () {
            /*
            |--------------------------------------------------------------------------
            | PURCHASE REQUEST
            |--------------------------------------------------------------------------
            | Semua URL di dalam group ini otomatis diawali /transaction.
            |--------------------------------------------------------------------------
            */

            Route::post(
                'purchase-request/{publicId}/print-url',
                [PurchaseRequestController::class, 'generatePrintUrl']
            );

            Route::get(
                'purchase-request/dropdown-approved',
                [PurchaseRequestController::class, 'dropdownApproved'],
            );

            /*
            | Harus didaftarkan sebelum route 'purchase-request/{publicId}',
            | kalau tidak "export-excel" akan tertangkap sebagai publicId.
            */
            Route::get(
                'purchase-request/export-excel',
                [PurchaseRequestController::class, 'exportExcel'],
            );

            Route::get(
                'purchase-request/{publicId}/edit',
                [PurchaseRequestController::class, 'edit'],
            );

            Route::get(
                'purchase-request/{publicId}/print',
                [PurchaseRequestController::class, 'print'],
            );

            Route::patch(
                'purchase-request/{publicId}/submit',
                [PurchaseRequestController::class, 'submit'],
            );

            Route::patch(
                'purchase-request/{publicId}/approve',
                [PurchaseRequestController::class, 'approve'],
            );

            Route::patch(
                'purchase-request/{publicId}/reject',
                [PurchaseRequestController::class, 'reject'],
            );

            Route::patch(
                'purchase-request/{publicId}/cancel',
                [PurchaseRequestController::class, 'cancel'],
            );

            Route::apiResource(
                'purchase-request',
                PurchaseRequestController::class,
            )->parameters([
                'purchase-request' => 'publicId',
            ]);

            /*
            |--------------------------------------------------------------------------
            | PURCHASE ORDER
            |--------------------------------------------------------------------------
            */

            Route::post(
                'purchase-order/{publicId}/print-url',
                [PurchaseOrderController::class, 'generatePrintUrl']
            );

            Route::get(
                'purchase-order/dropdown-receivable',
                [PurchaseOrderController::class, 'dropdownReceivable'],
            );

            /*
            | Harus didaftarkan sebelum route 'purchase-order/{publicId}',
            | kalau tidak "export-excel" akan tertangkap sebagai publicId.
            */
            Route::get(
                'purchase-order/export-excel',
                [PurchaseOrderController::class, 'exportExcel'],
            );

            Route::get(
                'purchase-order/{publicId}/receivable-items',
                [PurchaseOrderController::class, 'receivableItems'],
            );

            Route::get(
                'purchase-order/{publicId}/edit',
                [PurchaseOrderController::class, 'edit'],
            );

            Route::get(
                'purchase-order/{publicId}/print',
                [PurchaseOrderController::class, 'print'],
            );

            Route::patch(
                'purchase-order/{publicId}/submit',
                [PurchaseOrderController::class, 'submit'],
            );

            Route::patch(
                'purchase-order/{publicId}/approve',
                [PurchaseOrderController::class, 'approve'],
            );

            Route::patch(
                'purchase-order/{publicId}/reject',
                [PurchaseOrderController::class, 'reject'],
            );

            Route::patch(
                'purchase-order/{publicId}/cancel',
                [PurchaseOrderController::class, 'cancel'],
            );

            Route::apiResource(
                'purchase-order',
                PurchaseOrderController::class,
            )->parameters([
                'purchase-order' => 'publicId',
            ]);

            /*
            |--------------------------------------------------------------------------
            | GOODS RECEIVE
            |--------------------------------------------------------------------------
            */

            Route::patch(
                'goods-receive/{publicId}/post',
                [GoodsReceiveController::class, 'post'],
            );

            Route::patch(
                'goods-receive/{publicId}/cancel',
                [GoodsReceiveController::class, 'cancel'],
            );

            Route::get(
                'goods-receive/{publicId}/edit',
                [GoodsReceiveController::class, 'edit'],
            );

            Route::get(
                'goods-receive/{publicId}/return-history',
                [
                    GoodsReceiveController::class,
                    'returnHistory',
                ],
            );

            Route::apiResource(
                'goods-receive',
                GoodsReceiveController::class,
            )->parameters([
                'goods-receive' => 'publicId',
            ]);

            /*
            |--------------------------------------------------------------------------
            | GOODS RETURN
            |--------------------------------------------------------------------------
            */

            Route::get(
                'goods-return/reasons',
                [GoodsReturnController::class, 'reasons'],
            );

            Route::get(
                'goods-return/create-data',
                [GoodsReturnController::class, 'createData'],
            );

            Route::patch(
                'goods-return/{publicId}/post',
                [GoodsReturnController::class, 'post'],
            );

            Route::get(
                'goods-return/replacement-receivable',
                [GoodsReturnController::class, 'replacementReceivable'],
            );

            Route::patch(
                'goods-return/{publicId}/cancel',
                [GoodsReturnController::class, 'cancel'],
            );

            Route::get(
                'goods-return/{publicId}/edit',
                [GoodsReturnController::class, 'edit'],
            );

            Route::apiResource(
                'goods-return',
                GoodsReturnController::class,
            )->parameters([
                'goods-return' => 'publicId',
            ]);
        });

    /*
    |--------------------------------------------------------------------------
    | PENGAJUAN DANA
    |--------------------------------------------------------------------------
    | FPU (Form Pengajuan Uang) dan turunannya. URL memakai bahasa Inggris
    | mengikuti frontend /fund_request.
    |--------------------------------------------------------------------------
    */
    Route::prefix('fund-request')
        ->name('fund-request.')
        ->group(function () {
            /*
            |------------------------------------------------------------------
            | Master batas pengajuan FPU
            |------------------------------------------------------------------
            | preview didaftarkan sebelum apiResource, kalau tidak segmen
            | "preview" akan tertangkap sebagai {id}.
            |------------------------------------------------------------------
            */
            Route::get(
                'fund-request-limits/preview',
                [FundRequestLimitController::class, 'preview'],
            );

            Route::apiResource(
                'fund-request-limits',
                FundRequestLimitController::class,
            )
                ->only(['index', 'store', 'update', 'destroy'])
                ->parameters(['fund-request-limits' => 'id']);

            /*
            |------------------------------------------------------------------
            | Master jadwal pembayaran Finance
            |------------------------------------------------------------------
            | preview didaftarkan sebelum apiResource, kalau tidak segmen
            | "preview" akan tertangkap sebagai {id}.
            |------------------------------------------------------------------
            */
            Route::get(
                'payment-schedules/preview',
                [PaymentScheduleController::class, 'preview'],
            );

            Route::apiResource(
                'payment-schedules',
                PaymentScheduleController::class,
            )
                ->only(['index', 'store', 'update', 'destroy'])
                ->parameters(['payment-schedules' => 'id']);

            /*
            |------------------------------------------------------------------
            | Master keterangan transaksi
            |------------------------------------------------------------------
            | dropdown-select harus didaftarkan sebelum apiResource, kalau tidak
            | "dropdown-select" akan tertangkap sebagai {id}.
            |------------------------------------------------------------------
            */
            Route::get(
                'transaction-categories/dropdown-select',
                [FundRequestTransactionCategoryController::class, 'dropdownSelect'],
            );

            Route::patch(
                'transaction-categories/{id}/toggle-status',
                [FundRequestTransactionCategoryController::class, 'toggleStatus'],
            );

            Route::apiResource(
                'transaction-categories',
                FundRequestTransactionCategoryController::class,
            )
                ->only(['index', 'store', 'update', 'destroy'])
                ->parameters(['transaction-categories' => 'id']);

            /*
            | Didaftarkan sebelum apiResource, kalau tidak segmen "export-excel"
            | akan tertangkap sebagai publicId.
            */
            Route::get(
                'cash-advance/export-excel',
                [CashAdvanceController::class, 'exportExcel'],
            );

            Route::post(
                'cash-advance/{publicId}/print-url',
                [CashAdvanceController::class, 'generatePrintUrl'],
            );

            Route::get(
                'cash-advance/{publicId}/edit',
                [CashAdvanceController::class, 'edit'],
            );

            Route::patch(
                'cash-advance/{publicId}/submit',
                [CashAdvanceController::class, 'submit'],
            );

            Route::patch(
                'cash-advance/{publicId}/approve',
                [CashAdvanceController::class, 'approve'],
            );

            Route::patch(
                'cash-advance/{publicId}/reject',
                [CashAdvanceController::class, 'reject'],
            );

            Route::patch(
                'cash-advance/{publicId}/cancel',
                [CashAdvanceController::class, 'cancel'],
            );

            Route::post(
                'cash-advance/bulk-receive',
                [CashAdvanceController::class, 'bulkReceive'],
            );

            Route::post(
                'cash-advance/bulk-disburse',
                [CashAdvanceController::class, 'bulkDisburse'],
            );

            Route::patch(
                'cash-advance/{publicId}/receive',
                [CashAdvanceController::class, 'receive'],
            );

            Route::patch(
                'cash-advance/{publicId}/disburse',
                [CashAdvanceController::class, 'disburse'],
            );

            Route::apiResource(
                'cash-advance',
                CashAdvanceController::class,
            )->parameters([
                'cash-advance' => 'publicId',
            ]);

            /*
            |------------------------------------------------------------------
            | REALISASI FPU
            |------------------------------------------------------------------
            | Route statis didaftarkan sebelum apiResource, kalau tidak
            | segmennya akan tertangkap sebagai publicId.
            |------------------------------------------------------------------
            */
            Route::get(
                'cash-advance-realization/export-excel',
                [CashAdvanceRealizationController::class, 'exportExcel'],
            );

            Route::get(
                'cash-advance-realization/realizable',
                [CashAdvanceRealizationController::class, 'realizableCashAdvances'],
            );

            Route::post(
                'cash-advance-realization/{publicId}/print-url',
                [CashAdvanceRealizationController::class, 'generatePrintUrl'],
            );

            Route::get(
                'cash-advance-realization/{publicId}/edit',
                [CashAdvanceRealizationController::class, 'edit'],
            );

            Route::patch(
                'cash-advance-realization/{publicId}/submit',
                [CashAdvanceRealizationController::class, 'submit'],
            );

            Route::patch(
                'cash-advance-realization/{publicId}/approve',
                [CashAdvanceRealizationController::class, 'approve'],
            );

            Route::patch(
                'cash-advance-realization/{publicId}/reject',
                [CashAdvanceRealizationController::class, 'reject'],
            );

            Route::patch(
                'cash-advance-realization/{publicId}/cancel',
                [CashAdvanceRealizationController::class, 'cancel'],
            );

            /*
            | Penerimaan mendahului penyelesaian selisih: berkasnya dinyatakan
            | sudah di tangan, baru setelah itu uangnya berpindah.
            */
            Route::post(
                'cash-advance-realization/bulk-receive',
                [CashAdvanceRealizationController::class, 'bulkReceive'],
            );

            Route::patch(
                'cash-advance-realization/{publicId}/receive',
                [CashAdvanceRealizationController::class, 'receive'],
            );

            /*
            | Dua peristiwa berbeda, bukan satu aksi generik: sisa dikembalikan
            | pemohon, atau kekurangan dibayarkan Finance.
            */
            Route::post(
                'cash-advance-realization/bulk-return-difference',
                [CashAdvanceRealizationController::class, 'bulkReturnDifference'],
            );

            Route::post(
                'cash-advance-realization/bulk-reimburse-difference',
                [CashAdvanceRealizationController::class, 'bulkReimburseDifference'],
            );

            Route::patch(
                'cash-advance-realization/{publicId}/return-difference',
                [CashAdvanceRealizationController::class, 'returnDifference'],
            );

            Route::patch(
                'cash-advance-realization/{publicId}/reimburse-difference',
                [CashAdvanceRealizationController::class, 'reimburseDifference'],
            );

            Route::apiResource(
                'cash-advance-realization',
                CashAdvanceRealizationController::class,
            )->parameters([
                'cash-advance-realization' => 'publicId',
            ]);

            /*
            |------------------------------------------------------------------
            | CLAIM
            |------------------------------------------------------------------
            | Route pembayaran oleh Finance, pembatalan, export, dan cetak
            | menyusul bersama tahapnya.
            |
            | Route statis didaftarkan sebelum apiResource, kalau tidak
            | segmennya akan tertangkap sebagai publicId.
            |------------------------------------------------------------------
            */
            Route::get(
                'claim/export-excel',
                [ClaimController::class, 'exportExcel'],
            );

            Route::post(
                'claim/{publicId}/print-url',
                [ClaimController::class, 'generatePrintUrl'],
            );

            Route::get(
                'claim/{publicId}/edit',
                [ClaimController::class, 'edit'],
            );

            Route::patch(
                'claim/{publicId}/submit',
                [ClaimController::class, 'submit'],
            );

            Route::patch(
                'claim/{publicId}/approve',
                [ClaimController::class, 'approve'],
            );

            Route::patch(
                'claim/{publicId}/reject',
                [ClaimController::class, 'reject'],
            );

            Route::patch(
                'claim/{publicId}/cancel',
                [ClaimController::class, 'cancel'],
            );

            /*
            | Penerimaan mendahului pembayaran: berkasnya dinyatakan sudah di
            | tangan, baru setelah itu Finance membayarkan.
            */
            Route::post(
                'claim/bulk-receive',
                [ClaimController::class, 'bulkReceive'],
            );

            Route::patch(
                'claim/{publicId}/receive',
                [ClaimController::class, 'receive'],
            );

            Route::post(
                'claim/bulk-pay',
                [ClaimController::class, 'bulkPay'],
            );

            Route::patch(
                'claim/{publicId}/pay',
                [ClaimController::class, 'pay'],
            );

            Route::apiResource(
                'claim',
                ClaimController::class,
            )->parameters([
                'claim' => 'publicId',
            ]);
        });

    /*
    |--------------------------------------------------------------------------
    | PERJALANAN DINAS
    |--------------------------------------------------------------------------
    | Perdin berdiri sendiri, terpisah dari pengajuan dana. Yang menautkannya
    | ke FPU nanti adalah keterangan transaksi, bukan rute ini.
    |--------------------------------------------------------------------------
    */
    Route::prefix('business-trip')
        ->name('business-trip.')
        ->group(function () {
            /*
            | Rute statis didaftarkan sebelum apiResource, kalau tidak segmen
            | "options" akan tertangkap sebagai publicId.
            */
            Route::get(
                'perdin/export-excel',
                [BusinessTripController::class, 'exportExcel'],
            );

            Route::get(
                'perdin/options',
                [BusinessTripController::class, 'options'],
            );

            /*
            | Dipanggil layar FPU: perdin milik pemohon yang sudah disetujui
            | dan belum dipegang FPU lain yang masih hidup.
            */
            Route::get(
                'perdin/eligible',
                [BusinessTripController::class, 'eligible'],
            );

            /*
            | Alur dokumennya. Semua memakai publicId, dan semua didaftarkan
            | sebelum apiResource supaya segmennya tidak tertangkap sebagai id.
            */
            Route::patch(
                'perdin/{publicId}/submit',
                [BusinessTripController::class, 'submit'],
            );

            Route::patch(
                'perdin/{publicId}/approve',
                [BusinessTripController::class, 'approve'],
            );

            Route::patch(
                'perdin/{publicId}/reject',
                [BusinessTripController::class, 'reject'],
            );

            Route::patch(
                'perdin/{publicId}/cancel',
                [BusinessTripController::class, 'cancel'],
            );

            /*
            | Tautan cetak berumur pendek. PDF-nya sendiri dilayani di luar
            | grup ini, lewat tautan bertanda tangan -- lihat di bawah.
            */
            Route::get(
                'perdin/{publicId}/print-url',
                [BusinessTripController::class, 'generatePrintUrl'],
            );

            Route::apiResource(
                'perdin',
                BusinessTripController::class,
            )
                ->only(['index', 'store', 'show', 'update', 'destroy'])
                ->parameters([
                    'perdin' => 'publicId',
                ]);
        });

    //API ACCURATE
    Route::get('accurate/products', [AccurateController::class, 'products']);
    Route::get('accurate/accounts', [AccurateController::class, 'accounts']);
    Route::get('accurate/detail-po', [AccurateController::class, 'getDetailPO']);

    // ===================== PURCHASE ORDER INVENTORY =========================
    Route::prefix('inventory')->name('inventory.')->group(function () {
        Route::get('purchase-order/export', [PurchaseOrderInventoryController::class, 'export']);
        Route::apiResource('purchase-order', PurchaseOrderInventoryController::class);
        Route::post('purchase-order/{id}/approve-cfo', [PurchaseOrderInventoryController::class, 'approveCFO']);
        Route::post('purchase-order/{id}/approve-ceo', [PurchaseOrderInventoryController::class, 'approveCEO']);
        Route::get('purchase-order/print/{id}', [PurchaseOrderInventoryController::class, 'print']);
        Route::get('purchase-order/print-gain-loss/{id}', [PurchaseOrderInventoryController::class, 'printGainLoss']);
        Route::get('purchase-order/{id}/history', [PurchaseOrderInventoryController::class, 'history']);
        Route::post('purchase-order/{id}/cancel', [PurchaseOrderInventoryController::class, 'cancel']);
        Route::post('purchase-order/{id}/close', [PurchaseOrderInventoryController::class, 'close']);
        Route::post('purchase-order/{id}/changePrice', [PurchaseOrderInventoryController::class, 'changePrice']);

        //Goods Receipt
        Route::apiResource('goods-receipt', GoodsReceiptInventoryController::class);
        Route::get('goods-receipt/history/{id}', [GoodsReceiptInventoryController::class, 'grHistory']);

        //Gain Loss
        Route::apiResource('gain-loss', GainLossInventoryController::class);
        Route::post('gain-loss/approval', [GainLossInventoryController::class, 'approval']);

        //Shipping Instruction
        Route::apiResource('shipping-instruction', ShippingInstructionController::class);
        Route::get('shipping-instruction/by-po/{id}', [ShippingInstructionController::class, 'byPo']);
        Route::post('shipping-instruction/{id}/cancel', [ShippingInstructionController::class, 'cancel']);
        Route::post('shipping-instruction/{id}/approve', [ShippingInstructionController::class, 'approve']);
        Route::get('shipping-instruction/print/{id}', [ShippingInstructionController::class, 'print']);
    });
});
Route::get(
    '/transaction/purchase-request/{publicId}/print-signed',
    [PurchaseRequestController::class, 'printSigned']
)->name('transaction.purchase-request.print-signed')->middleware('signed:relative');

Route::get(
    '/transaction/purchase-order/{publicId}/print-signed',
    [PurchaseOrderController::class, 'printSigned']
)->name('transaction.purchase-order.print-signed')->middleware('signed:relative');

/*
|--------------------------------------------------------------------------
| Cetakan Pengajuan Dana
|--------------------------------------------------------------------------
| Di luar group auth, sama seperti cetakan PR dan PO: yang menjaga bukan
| token, melainkan tanda tangan URL yang hanya berlaku sepuluh menit.
|--------------------------------------------------------------------------
*/
Route::get(
    '/fund-request/cash-advance/{publicId}/print-signed',
    [CashAdvanceController::class, 'printSigned']
)->name('fund-request.cash-advance.print-signed')->middleware('signed:relative');

Route::get(
    '/fund-request/cash-advance-realization/{publicId}/print-signed',
    [CashAdvanceRealizationController::class, 'printSigned']
)->name('fund-request.cash-advance-realization.print-signed')->middleware('signed:relative');

Route::get(
    '/fund-request/claim/{publicId}/print-signed',
    [ClaimController::class, 'printSigned']
)->name('fund-request.claim.print-signed')->middleware('signed:relative');

Route::get(
    '/business-trip/perdin/{publicId}/print-signed',
    [BusinessTripController::class, 'printSigned']
)->name('business-trip.perdin.print-signed')->middleware('signed:relative');
