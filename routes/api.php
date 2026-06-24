<?php

// use App\Http\Api\Master\Controllers\ProdukController as ControllersProdukController;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\AccurateController;
use App\Http\Controllers\Api\GainLossInventoryController;
use App\Http\Controllers\Api\GoodsReceiptInventoryController;
use App\Http\Controllers\Api\MenuController;
use App\Http\Controllers\Api\Master\WilayahController;
use App\Http\Controllers\Api\Master\CabangController;
use App\Http\Controllers\Api\Master\DepartemenController;
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

use App\Http\Controllers\Api\Master\VolumeController;
use App\Http\Controllers\Api\Master\WilayahAngkutController;

use App\Http\Controllers\Api\Master\TransportirMobilController;

use App\Http\Controllers\Api\Master\OngkosAngkutController;
use App\Http\Controllers\Api\Master\CustomerController;
use App\Http\Controllers\Api\Master\GroupCabangController;
use App\Http\Controllers\Api\Master\MasterDokumenPendukungController;
use App\Http\Controllers\Api\Master\MasterKeteranganTransaksiController;
use App\Http\Controllers\Api\Master\MasterVendorController;
use App\Http\Controllers\Api\OngkosAngkutKapalController;
use App\Http\Controllers\Api\PurchaseOrderInventoryController;
use App\Http\Controllers\Api\PurchaseRequestController;
use App\Http\Controllers\Api\ShippingInstructionController;
use App\Http\Controllers\MasterBankController;
use App\Http\Controllers\UnitController;
use Illuminate\Support\Facades\Mail;

Route::post('/auth/login', [AuthController::class, 'login']);
// routes/api.php

Route::post('/auth/sso', [AuthController::class, 'sso']);
Route::middleware('auth:sanctum')->group(function () {
    Route::get('/auth/me', [AuthController::class, 'me']);
    Route::post('/auth/logout', [AuthController::class, 'logout']);
    Route::get('/auth/my-menus', [MenuController::class, 'myMenus']);
    Route::get('master/cabang/options', [CabangController::class, 'options']);


    Route::apiResource('master/wilayah', WilayahController::class);
    Route::apiResource('master/cabang', CabangController::class);
    Route::apiResource('master/departemen', DepartemenController::class);
    Route::apiResource('master/provinsi', ProvinsiController::class);
    Route::apiResource('master/kabupaten', KabupatenController::class);
    // Route::apiResource('master/vendor', VendorController::class);
    Route::apiResource('master/area', AreaController::class);
    Route::apiResource('master/terminal', TerminalController::class);
    Route::get('master/roles', [RoleController::class, 'index']);
    Route::apiResource('master/users', UserController::class);
    Route::apiResource('master/roles', RoleController::class);

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

    Route::get('/units', [UnitController::class, 'index']);

    // ===================== VENDOR =========================
    Route::prefix('master/vendor')->group(function () {
        Route::get('dropdown-select', [MasterVendorController::class, 'dropdownSelect']);
        Route::patch('{id}/status', [MasterVendorController::class, 'updateStatus']);
    });
    Route::prefix('master')->group(function () {
        Route::apiResource('vendor', MasterVendorController::class)
            ->parameters([
                'vendor' => 'publicId',
            ]);
        Route::apiResource('group-cabang', GroupCabangController::class);
    });

    // ===================== PURCHASE REQUEST =========================
    Route::prefix('transaction')->group(function () {
        Route::apiResource('purchase-request', PurchaseRequestController::class)
            ->parameters([
                'purchase-request' => 'publicId',
            ]);
    });

    //API ACCURATE
    Route::get('accurate/products', [AccurateController::class, 'products']);
    Route::get('accurate/accounts', [AccurateController::class, 'accounts']);
    Route::get('accurate/detail-po', [AccurateController::class, 'getDetailPO']);

    // ===================== PURCHASE ORDER INVERNTORY =========================
    Route::prefix('inventory')->name('inventory.')->group(function () {
        Route::get('purchase-order/export', [PurchaseOrderInventoryController::class, 'export']);
        Route::apiResource('purchase-order', PurchaseOrderInventoryController::class);
        Route::post('purchase-order/{id}/approve-cfo',[PurchaseOrderInventoryController::class, 'approveCFO']);
        Route::post('purchase-order/{id}/approve-ceo',[PurchaseOrderInventoryController::class, 'approveCEO']);
        Route::get('purchase-order/print/{id}', [PurchaseOrderInventoryController::class, 'print']);
        Route::get('purchase-order/print-gain-loss/{id}', [PurchaseOrderInventoryController::class, 'printGainLoss']);
        Route::get('purchase-order/{id}/history', [PurchaseOrderInventoryController::class, 'history']);
        Route::post('purchase-order/{id}/cancel', [PurchaseOrderInventoryController::class, 'cancel']);
        Route::post('purchase-order/{id}/close', [PurchaseOrderInventoryController::class, 'close']);
        
        //Goods Receipt
        Route::apiResource('goods-receipt', GoodsReceiptInventoryController::class);
        Route::get('goods-receipt/history/{id}', [GoodsReceiptInventoryController::class, 'grHistory']);
        
        //Gain Loss
        Route::apiResource('gain-loss', GainLossInventoryController::class);
        Route::post('gain-loss/approval', [GainLossInventoryController::class,'approval']);
        
        //Shipping Instruction
        Route::apiResource('shipping-instruction', ShippingInstructionController::class);
        Route::get('shipping-instruction/by-po/{id}', [ShippingInstructionController::class,'byPo']);
        Route::post('shipping-instruction/{id}/cancel', [ShippingInstructionController::class,'cancel']);
        Route::post('shipping-instruction/{id}/approve', [ShippingInstructionController::class,'approve']);
        Route::get('shipping-instruction/print/{id}', [ShippingInstructionController::class, 'print']);
        });
});
