<?php

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\MenuController;
use App\Http\Controllers\Api\Master\WilayahController;
use App\Http\Controllers\Api\Master\CabangController;
use App\Http\Controllers\Api\Master\DepartemenController;
use App\Http\Controllers\Api\Master\ProvinsiController;
use App\Http\Controllers\Api\Master\KabupatenController;


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
});