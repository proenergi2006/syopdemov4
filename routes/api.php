<?php

use App\Http\Api\Master\Controllers\ProdukController as ControllersProdukController;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\MenuController;
use App\Http\Controllers\Api\Master\WilayahController;
use App\Http\Controllers\Api\Master\CabangController;
use App\Http\Controllers\Api\Master\DepartemenController;
use App\Http\Controllers\Api\Master\ProvinsiController;
use App\Http\Controllers\Api\Master\KabupatenController;
use App\Http\Controllers\Api\Master\VendorController;
use App\Http\Controllers\Api\Master\AreaController;
use App\Http\Controllers\Api\Master\PbbkbController;
use App\Http\Controllers\Api\Master\TerminalController;
use App\Http\Controllers\Api\Master\UserController;
use App\Http\Controllers\Api\Master\RoleController;
<<<<<<< HEAD
use App\Http\Controllers\Api\Master\ProdukController;
=======
use App\Http\Controllers\Api\Master\RoleMenuController;


>>>>>>> cdc5d1f (perbaikan)

Route::post('/auth/login', [AuthController::class, 'login']);

Route::middleware('auth:sanctum')->group(function () {
    Route::get('/auth/me', [AuthController::class, 'me']);
    Route::post('/auth/logout', [AuthController::class, 'logout']);
    Route::get('/auth/my-menus', [MenuController::class, 'myMenus']);
    Route::apiResource('master/wilayah', WilayahController::class);
    Route::apiResource('master/cabang', CabangController::class);
    Route::apiResource('master/departemen', DepartemenController::class);
    Route::apiResource('master/provinsi', ProvinsiController::class);
    Route::apiResource('master/kabupaten', KabupatenController::class);
    Route::apiResource('master/vendor', VendorController::class);
    Route::apiResource('master/area', AreaController::class);
    Route::apiResource('master/terminal', TerminalController::class);
    Route::get('master/roles', [RoleController::class, 'index']);
    Route::apiResource('master/users', UserController::class);
    Route::apiResource('master/roles', RoleController::class);
<<<<<<< HEAD
    Route::apiResource('master/produk', ProdukController::class);
    Route::apiResource('master/pbbkb', PbbkbController::class);
=======
    Route::get('/master/role-menus', [RoleMenuController::class, 'index']);
    Route::post('/master/role-menus', [RoleMenuController::class, 'store']);
>>>>>>> cdc5d1f (perbaikan)
});