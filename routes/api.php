<?php

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use App\Http\Controllers\BankController;
use App\Http\Controllers\AuthController;
use App\Http\Controllers\RoleController;


Route::get('/user', function (Request $request) {
    return $request->user();
})->middleware('auth:sanctum');

Route::post('/tokens/create', [AuthController::class, 'createToken']);
Route::post('/login', [AuthController::class, 'authenticate']);

Route::get('banks', [BankController::class, 'index']);
Route::get('banks/{id}', [BankController::class, 'show']);
Route::post('banks', [BankController::class, 'store']);
Route::put('banks/{id}', [BankController::class, 'update']);
Route::delete('banks/{id}', [BankController::class, 'delete']);

Route::post('roles', [RoleController::class, 'store']);
