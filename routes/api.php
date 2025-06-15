<?php

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use App\Http\Controllers\BankController;
use App\Http\Controllers\AuthController;
use App\Http\Controllers\RoleController;
use App\Http\Controllers\AllowanceController;
use App\Http\Controllers\ContactTypeController;
use App\Http\Controllers\ChartOfAccountController;
use App\Http\Controllers\DeductionTypeController;
use App\Http\Controllers\HonorificController;
use App\Http\Controllers\CountryController;
use App\Http\Controllers\DepartmentController;
use App\Http\Controllers\DegreeController;
use App\Http\Controllers\LocalityController;
use App\Http\Controllers\DistrictController;
use App\Http\Controllers\CalculationModeController;
use App\Http\Controllers\GenderController;
use App\Http\Controllers\EmployeeStatusController;
use App\Http\Controllers\InstitutionController;
use App\Http\Controllers\QualificationController;
use App\Http\Controllers\RelationshipController;
use App\Http\Controllers\EmployeeContactController;
use App\Http\Controllers\EmployeeAllowanceController;
use App\Http\Controllers\EmployeeBankController;
use App\Http\Controllers\EmployeeController;
use App\Http\Controllers\EmployeeDefaultDeductionController;
use App\Http\Controllers\EmploymentDetailsController;
use App\Http\Controllers\EmployeeHoursWorkedController;
use App\Http\Controllers\EmploymentHistoryController;
use App\Http\Controllers\HistoricalEmployeeDeductionController;
use App\Http\Controllers\LeaveTypeController;
use App\Http\Controllers\LoanController;
use App\Http\Controllers\PaymentMethodController;
use App\Http\Controllers\EmployeeWorkPermitController;
use App\Http\Controllers\BankAccountTypeController;

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

//Contact Type
Route::prefix('contact-type')->group(function () {
    Route::get('/', [ContactTypeController::class, 'index']);
    Route::post('/', [ContactTypeController::class, 'store']);
    Route::get('/{contactType}', [ContactTypeController::class, 'show']);
    Route::put('/{contactType}', [ContactTypeController::class, 'update']);
    Route::delete('/{contactType}', [ContactTypeController::class, 'destroy']);
});
//End Contact Type

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

// Honorific Routes
Route::prefix('honorifics')->group(function () {
    Route::get('/', [HonorificController::class, 'index']);
    Route::post('/', [HonorificController::class, 'store']);
    Route::get('/{honorific}', [HonorificController::class, 'show']);
    Route::put('/{honorific}', [HonorificController::class, 'update']);
    Route::delete('/{honorific}', [HonorificController::class, 'destroy']);
});

// Country routes
Route::prefix('countries')->group(function () {
    Route::get('/', [CountryController::class, 'index']);
    Route::post('/', [CountryController::class, 'store']);
    Route::get('/{country}', [CountryController::class, 'show']);
    Route::put('/{country}', [CountryController::class, 'update']);
    Route::delete('/{country}', [CountryController::class, 'destroy']);
    Route::get('/{country}/districts', [CountryController::class, 'districts']);
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

// Locality routes
Route::prefix('localities')->group(function () {
    Route::get('/', [LocalityController::class, 'index']);
    Route::post('/', [LocalityController::class, 'store']);
    Route::get('/{locality}', [LocalityController::class, 'show']);
    Route::put('/{locality}', [LocalityController::class, 'update']);
    Route::delete('/{locality}', [LocalityController::class, 'destroy']);
});

// District routes
Route::prefix('districts')->group(function () {
    Route::get('/', [DistrictController::class, 'index']);
    Route::post('/', [DistrictController::class, 'store']);
    Route::get('/{district}', [DistrictController::class, 'show']);
    Route::put('/{district}', [DistrictController::class, 'update']);
    Route::delete('/{district}', [DistrictController::class, 'destroy']);
    Route::get('/{district}/localities', [DistrictController::class, 'localities']);
});

// Calculation Mode Routes
Route::prefix('calculation-modes')->group(function () {
    Route::get('/', [CalculationModeController::class, 'index']);
    Route::post('/', [CalculationModeController::class, 'store']);
    Route::get('/{calculationMode}', [CalculationModeController::class, 'show']);
    Route::put('/{calculationMode}', [CalculationModeController::class, 'update']);
    Route::delete('/{calculationMode}', [CalculationModeController::class, 'destroy']);
});

// Gender routes
Route::prefix('genders')->group(function () {
    Route::get('/', [GenderController::class, 'index']);
    Route::post('/', [GenderController::class, 'store']);
    Route::get('/{gender}', [GenderController::class, 'show']);
    Route::put('/{gender}', [GenderController::class, 'update']);
    Route::delete('/{gender}', [GenderController::class, 'destroy']);
});

// Employee Status Routes
Route::prefix('employee-statuses')->group(function () {
    Route::get('/', [EmployeeStatusController::class, 'index']);
    Route::post('/', [EmployeeStatusController::class, 'store']);
    Route::get('/{employeeStatus}', [EmployeeStatusController::class, 'show']);
    Route::put('/{employeeStatus}', [EmployeeStatusController::class, 'update']);
    Route::delete('/{employeeStatus}', [EmployeeStatusController::class, 'destroy']);
});

// Employment Details Routes
Route::prefix('employment-details')->group(function () {
    Route::get('/', [EmploymentDetailsController::class, 'index']);
    Route::post('/', [EmploymentDetailsController::class, 'store']);
    Route::get('/{employmentDetails}', [EmploymentDetailsController::class, 'show']);
    Route::put('/{employmentDetails}', [EmploymentDetailsController::class, 'update']);
    Route::delete('/{employmentDetails}', [EmploymentDetailsController::class, 'destroy']);

 // Employee Routes
Route::prefix('employees')->group(function () {
    Route::get('/', [EmployeeController::class, 'index']);
    Route::post('/', [EmployeeController::class, 'store']);
    Route::get('/{employee}', [EmployeeController::class, 'show']);
    Route::put('/{employee}', [EmployeeController::class, 'update']);
    Route::delete('/{employee}', [EmployeeController::class, 'destroy']);
});


// Institution Routes
Route::prefix('institutions')->group(function () {
    Route::get('/', [InstitutionController::class, 'index']);
    Route::post('/', [InstitutionController::class, 'store']);
    Route::get('/{institution}', [InstitutionController::class, 'show']);
    Route::put('/{institution}', [InstitutionController::class, 'update']);
    Route::delete('/{institution}', [InstitutionController::class, 'destroy']);
});


// Relationship Routes
Route::prefix('relationships')->group(function () {
    Route::get('/', [RelationshipController::class, 'index']);
    Route::post('/', [RelationshipController::class, 'store']);
    Route::get('/{relationship}', [RelationshipController::class, 'show']);
    Route::put('/{relationship}', [RelationshipController::class, 'update']);
    Route::delete('/{relationship}', [RelationshipController::class, 'destroy']);
});

  
Route::prefix('qualifications')->group(function () {
    Route::get('/', [QualificationController::class, 'index']);
    Route::post('/', [QualificationController::class, 'store']);
    Route::get('/{qualification}', [QualificationController::class, 'show']);
    Route::put('/{qualification}', [QualificationController::class, 'update']);
    Route::delete('/{qualification}', [QualificationController::class, 'destroy']);
});


// Employee Contact Routes
Route::prefix('employee-contacts')->group(function () {
    Route::get('/', [EmployeeContactController::class, 'index']);
    Route::post('/', [EmployeeContactController::class, 'store']);
    Route::get('/{employeeContact}', [EmployeeContactController::class, 'show']);
    Route::put('/{employeeContact}', [EmployeeContactController::class, 'update']);
    Route::delete('/{employeeContact}', [EmployeeContactController::class, 'destroy']);
});


Route::prefix('employee-allowances')->group(function () {
    Route::get('/', [EmployeeAllowanceController::class, 'index']);
    Route::post('/', [EmployeeAllowanceController::class, 'store']);
    Route::get('/{employeeAllowance}', [EmployeeAllowanceController::class, 'show']);
    Route::put('/{employeeAllowance}', [EmployeeAllowanceController::class, 'update']);
    Route::delete('/{employeeAllowance}', [EmployeeAllowanceController::class, 'destroy']);
});


// Employee Bank Routes
Route::prefix('employee-banks')->group(function () {
    Route::get('/', [EmployeeBankController::class, 'index']);
    Route::post('/', [EmployeeBankController::class, 'store']);
    Route::get('/{employeeBank}', [EmployeeBankController::class, 'show']);
    Route::put('/{employeeBank}', [EmployeeBankController::class, 'update']);
    Route::delete('/{employeeBank}', [EmployeeBankController::class, 'destroy']);
});


// Employee Hours Worked Routes
Route::prefix('employee-hours-worked')->group(function () {
    Route::get('/', [EmployeeHoursWorkedController::class, 'index']);
    Route::post('/', [EmployeeHoursWorkedController::class, 'store']);
    Route::get('/{hoursWorked}', [EmployeeHoursWorkedController::class, 'show']);
    Route::put('/{hoursWorked}', [EmployeeHoursWorkedController::class, 'update']);
    Route::delete('/{hoursWorked}', [EmployeeHoursWorkedController::class, 'destroy']);
});

// Employment History Routes
Route::prefix('employment-histories')->group(function () {
    Route::get('/', [EmploymentHistoryController::class, 'index']);
    Route::post('/', [EmploymentHistoryController::class, 'store']);
    Route::get('/{employmentHistory}', [EmploymentHistoryController::class, 'show']);
    Route::put('/{employmentHistory}', [EmploymentHistoryController::class, 'update']);
    Route::delete('/{employmentHistory}', [EmploymentHistoryController::class, 'destroy']);
});

// Historical Employee Deduction Routes
Route::prefix('historical-employee-deductions')->group(function () {
    Route::get('/', [HistoricalEmployeeDeductionController::class, 'index']);
    Route::post('/', [HistoricalEmployeeDeductionController::class, 'store']);
    Route::get('/{historicalEmployeeDeduction}', [HistoricalEmployeeDeductionController::class, 'show']);
    Route::put('/{historicalEmployeeDeduction}', [HistoricalEmployeeDeductionController::class, 'update']);
    Route::delete('/{historicalEmployeeDeduction}', [HistoricalEmployeeDeductionController::class, 'destroy']);

// Payment Method Routes
Route::prefix('payment-methods')->group(function () {
    Route::get('/', [PaymentMethodController::class, 'index']);
    Route::post('/', [PaymentMethodController::class, 'store']);
    Route::get('/{paymentMethod}', [PaymentMethodController::class, 'show']);
    Route::put('/{paymentMethod}', [PaymentMethodController::class, 'update']);
    Route::delete('/{paymentMethod}', [PaymentMethodController::class, 'destroy']);

// Employee Work Permit Routes
Route::prefix('employee-work-permits')->group(function () {
    Route::get('/', [EmployeeWorkPermitController::class, 'index']);
    Route::post('/', [EmployeeWorkPermitController::class, 'store']);
    Route::get('/{employeeWorkPermit}', [EmployeeWorkPermitController::class, 'show']);
    Route::put('/{employeeWorkPermit}', [EmployeeWorkPermitController::class, 'update']);
    Route::delete('/{employeeWorkPermit}', [EmployeeWorkPermitController::class, 'destroy']);
});


// Leave Type Routes
Route::prefix('leave-types')->group(function () {
    Route::get('/', [LeaveTypeController::class, 'index']);
    Route::post('/', [LeaveTypeController::class, 'store']);
    Route::get('/{leaveType}', [LeaveTypeController::class, 'show']);
    Route::put('/{leaveType}', [LeaveTypeController::class, 'update']);
    Route::delete('/{leaveType}', [LeaveTypeController::class, 'destroy']);
});
// Loan Routes
Route::prefix('loans')->group(function () {
    Route::get('/', [LoanController::class, 'index']);
    Route::post('/', [LoanController::class, 'store']);
    Route::get('/{loan}', [LoanController::class, 'show']);
    Route::put('/{loan}', [LoanController::class, 'update']);
    Route::delete('/{loan}', [LoanController::class, 'destroy']);
});

// Bank Account Type Routes
Route::prefix('bank-account-types')->group(function () {
    Route::get('/', [BankAccountTypeController::class, 'index']);
    Route::post('/', [BankAccountTypeController::class, 'store']);
    Route::get('/{bankAccountType}', [BankAccountTypeController::class, 'show']);
    Route::put('/{bankAccountType}', [BankAccountTypeController::class, 'update']);
    Route::delete('/{bankAccountType}', [BankAccountTypeController::class, 'destroy']);
});
