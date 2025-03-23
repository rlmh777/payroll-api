<?php

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use App\Http\Controllers\BankController;
use App\Http\Controllers\AuthController;
use App\Http\Controllers\RoleController;
use App\Http\Controllers\AllowanceController;
use App\Http\Controllers\ChartOfAccountController;
use App\Http\Controllers\DeductionTypeController;
use App\Http\Controllers\DepartmentController;
use App\Http\Controllers\DegreeController;


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

//Allowance
Route::get('allowances', [AllowanceController::class, 'index']);
Route::get('allowances/{allowance}', action: [AllowanceController::class, 'show']);
Route::post('allowances', [AllowanceController::class, 'store']);
Route::patch('allowances/{allowance}', [AllowanceController::class, 'update']);
Route::delete('allowances/{allowance}', [AllowanceController::class, 'destroy']);
//End Allowance
// Chart of Accounts Routes
Route::prefix('chart-of-accounts')->group(function () {
    Route::get('/', [ChartOfAccountController::class, 'index']);
    Route::post('/', [ChartOfAccountController::class, 'store']);
    Route::get('/{chartOfAccount}', [ChartOfAccountController::class, 'show']);
    Route::put('/{chartOfAccount}', [ChartOfAccountController::class, 'update']);
    Route::delete('/{chartOfAccount}', [ChartOfAccountController::class, 'destroy']);
    
    // Custom endpoints
    Route::get('/{chartOfAccount}/hierarchy', [ChartOfAccountController::class, 'hierarchy']);
    Route::get('/{chartOfAccount}/balance-history', [ChartOfAccountController::class, 'balanceHistory']);
});

// Deduction Type Routes
Route::prefix('deduction-types')->group(function () {
    Route::get('/', [DeductionTypeController::class, 'index']);
    Route::post('/', [DeductionTypeController::class, 'store']);
    Route::get('/{deductionType}', [DeductionTypeController::class, 'show']);
    Route::put('/{deductionType}', [DeductionTypeController::class, 'update']);
    Route::delete('/{deductionType}', [DeductionTypeController::class, 'destroy']);
});

// Department Routes
Route::prefix('departments')->group(function () {
    Route::get('/', [DepartmentController::class, 'index']);
    Route::post('/', [DepartmentController::class, 'store']);
    Route::get('/{department}', [DepartmentController::class, 'show']);
    Route::put('/{department}', [DepartmentController::class, 'update']);
    Route::delete('/{department}', [DepartmentController::class, 'destroy']);
});

// Degree Routes
Route::prefix('degrees')->group(function () {
    Route::get('/', [DegreeController::class, 'index']);
    Route::post('/', [DegreeController::class, 'store']);
    Route::get('/{degree}', [DegreeController::class, 'show']);
    Route::put('/{degree}', [DegreeController::class, 'update']);
    Route::delete('/{degree}', [DegreeController::class, 'destroy']);
});

