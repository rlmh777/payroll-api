<?php

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use App\Modules\Payroll\Http\Controllers\EmployeeCompensationController;
use App\Http\Controllers\AuthController;
use App\Http\Controllers\RoleController;
use App\Modules\Payroll\Http\Controllers\AllowanceController;
use App\Http\Controllers\ContactTypeController;
use App\Modules\Payroll\Http\Controllers\AccountController;
use App\Modules\Payroll\Http\Controllers\AccountTypeController;
use App\Modules\Payroll\Http\Controllers\DeductionTypeController;
use App\Http\Controllers\HonorificController;
use App\Http\Controllers\CountryController;
use App\Modules\Hr\Http\Controllers\DepartmentController;
use App\Modules\Hr\Http\Controllers\DepartmentHeadAssignmentController;
use App\Modules\Hr\Http\Controllers\WorksiteController;
use App\Http\Controllers\DegreeController;
use App\Http\Controllers\LocalityController;
use App\Http\Controllers\DistrictController;
use App\Http\Controllers\GenderController;
use App\Http\Controllers\CitizenshipStatusController;
use App\Modules\Hr\Http\Controllers\EmployeeStatusController;
use App\Http\Controllers\InstitutionController;
use App\Modules\Hr\Http\Controllers\QualificationController;
use App\Http\Controllers\RelationshipController;
use App\Modules\Hr\Http\Controllers\EmployeeContactController;
use App\Modules\Hr\Http\Controllers\EmployeeCertificationController;
use App\Modules\Hr\Http\Controllers\EmployeeSkillController;
use App\Modules\Hr\Http\Controllers\DocumentTagController;
use App\Modules\Hr\Http\Controllers\EmployeeDocumentController;
use App\Modules\Hr\Http\Controllers\EmployeeIncidentController;
use App\Modules\Payroll\Http\Controllers\EmployeeDefaultAllowanceController;
use App\Modules\Payroll\Http\Controllers\EmployeeBankController;
use App\Http\Controllers\EmployeeController;
use App\Modules\Hr\Http\Controllers\EmployeeImportController;
use App\Modules\Payroll\Http\Controllers\EmployeeDefaultDeductionController;
use App\Modules\Hr\Http\Controllers\EmploymentDetailsController;
use App\Modules\Hr\Http\Controllers\ContractTypeController;
use App\Modules\Hr\Http\Controllers\EmploymentStatusController;
use App\Modules\Payroll\Http\Controllers\EmployeeHoursWorkedController;
use App\Modules\Hr\Http\Controllers\EmploymentHistoryController;
use App\Modules\Payroll\Http\Controllers\LoanTypeController;
use App\Http\Controllers\PermissionController;
use App\Modules\Payroll\Http\Controllers\HistoricalEmployeeDeductionController;
use App\Modules\Hr\Http\Controllers\LeaveStatusController;
use App\Modules\Hr\Http\Controllers\LeaveTypeController;
use App\Modules\Payroll\Http\Controllers\LoanController;
use App\Modules\Payroll\Http\Controllers\PaymentMethodController;
use App\Modules\Payroll\Http\Controllers\PayrateFrequencyController;
use App\Modules\Hr\Http\Controllers\EmployeeWorkPermitController;
use App\Http\Controllers\BankAccountTypeController;
use App\Http\Controllers\BankController;
use App\Http\Controllers\MenuController;
use App\Http\Controllers\NotificationController;
use App\Http\Controllers\UserController;
use App\Modules\Payroll\Http\Controllers\PayPeriodController;
use App\Modules\Payroll\Http\Controllers\PayPeriodGroupController;
use App\Modules\Payroll\Http\Controllers\PayPeriodScheduleAiController;
use App\Modules\Payroll\Http\Controllers\PayrollController;
use App\Modules\Payroll\Http\Controllers\PayrollEarningCodeController;
use App\Modules\Payroll\Http\Controllers\PayrollEarningLineController;
use App\Modules\Payroll\Http\Controllers\PayrollRunAllowanceDeductionImportController;
use App\Modules\Payroll\Http\Controllers\PayrollRunController;
use App\Modules\Payroll\Http\Controllers\PayrollRunPayslipController;
use App\Modules\Payroll\Http\Controllers\ReportController;
use App\Modules\Payroll\Http\Controllers\PayrollContributionController;
use App\Modules\Payroll\Http\Controllers\JournalEntryController;
use App\Modules\Payroll\Http\Controllers\JournalLineController;
use App\Modules\Payroll\Http\Controllers\SocialSecurityController;
use App\Modules\Payroll\Http\Controllers\SocialSecurityContributionController;
use App\Modules\Payroll\Http\Controllers\SocialSecurityContributionRuleController;
use App\Modules\Payroll\Http\Controllers\SsBenefitTypeController;
use App\Modules\Payroll\Http\Controllers\EmployeeSsBenefitStatusController;
use App\Modules\Payroll\Http\Controllers\PayrollSettingController;
use App\Modules\Payroll\Http\Controllers\PersonalReliefController;
use App\Modules\Payroll\Http\Controllers\VendorController;
use App\Http\Controllers\CompanyController;
use App\Modules\Hr\Http\Controllers\CalendarGroupController;
use App\Modules\Hr\Http\Controllers\PublicHolidayController;
use App\Modules\Hr\Http\Controllers\ScheduledWorkController;
use App\Modules\Hr\Http\Controllers\SchedulerEventController;
use App\Modules\Hr\Http\Controllers\ScheduleEmployeeTimesheetController;
use App\Modules\Hr\Http\Controllers\TimesheetTemplateController;
use App\Modules\Hr\Http\Controllers\TimesheetTemplateDepartmentController;
use App\Modules\Hr\Http\Controllers\EmployeeLeaveBalanceController;
use App\Modules\Hr\Http\Controllers\EmployeeLeaveController;
use App\Modules\Hr\Http\Controllers\EmploymentLeaveEntitlementController;
use App\Modules\Hr\Http\Controllers\EmployeeReportingController;
use App\Modules\Hr\Http\Controllers\Attendance\ClockingLogController;
use App\Modules\Hr\Http\Controllers\Attendance\AttendanceSettingController;
use App\Modules\Hr\Http\Controllers\Attendance\TimesheetController;
use App\Support\AuthUserPresenter;

Route::get('/user', function (Request $request) {
    return AuthUserPresenter::present($request->user());
})->middleware('auth:sanctum');

Route::post('/tokens/create', [AuthController::class, 'createToken'])->middleware('auth:sanctum');
Route::post('/tokens/revoke', [AuthController::class, 'revokeToken'])->middleware('auth:sanctum');
Route::post('/tokens/revoke-all', [AuthController::class, 'revokeAllTokens'])->middleware('auth:sanctum');
Route::delete('/tokens/{tokenId}', [AuthController::class, 'revokeSpecificToken'])->middleware('auth:sanctum');
Route::get('/tokens', [AuthController::class, 'listTokens'])->middleware('auth:sanctum');
Route::post('/login', [AuthController::class, 'authenticate']);
Route::post('/logout', [AuthController::class, 'logout'])->middleware('auth:sanctum');
Route::get('/user/menu', [UserController::class, 'topLevelMenus'])->middleware('auth:sanctum');
Route::get('/user/menus', [UserController::class, 'userMenus'])->middleware('auth:sanctum');

Route::get('banks', [BankController::class, 'index']);
Route::get('banks/{id}', [BankController::class, 'show']);
Route::post('banks', [BankController::class, 'store']);
Route::put('banks/{id}', [BankController::class, 'update']);
Route::delete('banks/{id}', [BankController::class, 'delete']);

// Employee calendar helpers
Route::get('employees/by-user/{userId}', [EmployeeController::class, 'byUser']);
Route::get('employees/{employeeId}/subordinates', [EmployeeController::class, 'subordinates']);

// Social Security Routes
Route::prefix('social-security')->group(function () {
    Route::post('/calculate-contribution', [SocialSecurityContributionController::class, 'calculate']);
    Route::get('/', [SocialSecurityController::class, 'index']);
    Route::post('/', [SocialSecurityController::class, 'store']);
    Route::get('/{id}', [SocialSecurityController::class, 'show']);
    Route::put('/{id}', [SocialSecurityController::class, 'update']);
    Route::delete('/{id}', [SocialSecurityController::class, 'destroy']);
});

Route::prefix('social-security-contribution-rules')->group(function () {
    Route::get('/', [SocialSecurityContributionRuleController::class, 'index']);
    Route::post('/', [SocialSecurityContributionRuleController::class, 'store']);
    Route::get('/{socialSecurityContributionRule}', [SocialSecurityContributionRuleController::class, 'show']);
    Route::put('/{socialSecurityContributionRule}', [SocialSecurityContributionRuleController::class, 'update']);
    Route::delete('/{socialSecurityContributionRule}', [SocialSecurityContributionRuleController::class, 'destroy']);
});

Route::prefix('ss-benefit-types')->group(function () {
    Route::get('/', [SsBenefitTypeController::class, 'index']);
    Route::post('/', [SsBenefitTypeController::class, 'store']);
    Route::get('/{ssBenefitType}', [SsBenefitTypeController::class, 'show']);
    Route::put('/{ssBenefitType}', [SsBenefitTypeController::class, 'update']);
    Route::delete('/{ssBenefitType}', [SsBenefitTypeController::class, 'destroy']);
});

Route::prefix('employee-ss-benefit-status')->group(function () {
    Route::get('/', [EmployeeSsBenefitStatusController::class, 'index']);
    Route::post('/', [EmployeeSsBenefitStatusController::class, 'store']);
    Route::get('/{employeeSsBenefitStatus}', [EmployeeSsBenefitStatusController::class, 'show']);
    Route::put('/{employeeSsBenefitStatus}', [EmployeeSsBenefitStatusController::class, 'update']);
    Route::delete('/{employeeSsBenefitStatus}', [EmployeeSsBenefitStatusController::class, 'destroy']);
});

Route::get('employees/{employee}/ss-contribution-preview', [EmployeeSsBenefitStatusController::class, 'preview']);

// Personal Relief Routes
Route::prefix('personal-relief')->group(function () {
    Route::get('/', [PersonalReliefController::class, 'index']);
    Route::post('/', [PersonalReliefController::class, 'store']);
    Route::get('/{id}', [PersonalReliefController::class, 'show']);
    Route::put('/{id}', [PersonalReliefController::class, 'update']);
    Route::delete('/{id}', [PersonalReliefController::class, 'destroy']);
});

Route::prefix('payroll-settings')->middleware('auth:sanctum')->group(function () {
    Route::get('/', [PayrollSettingController::class, 'show']);
    Route::put('/', [PayrollSettingController::class, 'update']);
});

Route::post('roles', [RoleController::class, 'store']);

// Employee reporting relationships
Route::get('employee-reporting', [EmployeeReportingController::class, 'index']);
Route::post('employee-reporting', [EmployeeReportingController::class, 'store']);
Route::delete('employee-reporting/{employeeReporting}', [EmployeeReportingController::class, 'destroy']);

// Calendar Group Routes
Route::prefix('calendar-groups')->group(function () {
    Route::get('/', [CalendarGroupController::class, 'index']);
});

// Scheduled work (employee shifts)
Route::prefix('scheduled-work')->group(function () {
    Route::get('/', [ScheduledWorkController::class, 'index']);
    Route::post('/', [ScheduledWorkController::class, 'store']);
    Route::get('/{scheduledWork}', [ScheduledWorkController::class, 'show']);
    Route::put('/{scheduledWork}', [ScheduledWorkController::class, 'update']);
    Route::delete('/{scheduledWork}', [ScheduledWorkController::class, 'destroy']);
});

// Public holidays
Route::prefix('public-holidays')->group(function () {
    Route::get('/', [PublicHolidayController::class, 'index']);
    Route::post('/', [PublicHolidayController::class, 'store']);
    Route::get('/{publicHoliday}', [PublicHolidayController::class, 'show']);
    Route::put('/{publicHoliday}', [PublicHolidayController::class, 'update']);
    Route::delete('/{publicHoliday}', [PublicHolidayController::class, 'destroy']);
});

// Schedule Employee Timesheets
Route::prefix('schedule-employee-timesheets')->group(function () {
    Route::post('/', [ScheduleEmployeeTimesheetController::class, 'store']);
    Route::get('/{scheduleEmployeeTimesheet}', [ScheduleEmployeeTimesheetController::class, 'show']);
    Route::put('/{scheduleEmployeeTimesheet}', [ScheduleEmployeeTimesheetController::class, 'update']);
    Route::delete('/{scheduleEmployeeTimesheet}', [ScheduleEmployeeTimesheetController::class, 'destroy']);
    Route::post('/{scheduleEmployeeTimesheet}/copy', [ScheduleEmployeeTimesheetController::class, 'copy']);
});

// Clocking logs (raw biometric events)
Route::prefix('clocking-logs')->group(function () {
    Route::get('/', [ClockingLogController::class, 'index']);
    Route::post('/', [ClockingLogController::class, 'store']);
    Route::post('/import', [ClockingLogController::class, 'import']);
    Route::post('/process', [ClockingLogController::class, 'process']);
});

// Timesheets (processed attendance)
Route::prefix('attendance-settings')->middleware('auth:sanctum')->group(function () {
    Route::get('/', [AttendanceSettingController::class, 'show']);
    Route::put('/', [AttendanceSettingController::class, 'update']);
});

Route::prefix('timesheets')->middleware('auth:sanctum')->group(function () {
    Route::get('/', [TimesheetController::class, 'index']);
    Route::get('/employee-summary', [TimesheetController::class, 'employeeSummary']);
    Route::post('/recalculate-compensation', [TimesheetController::class, 'recalculateCompensation']);
    Route::patch('/approval/bulk', [TimesheetController::class, 'updateBulkApproval']);
    Route::patch('/{timesheet}/round-off', [TimesheetController::class, 'updateRoundOff']);
    Route::patch('/{timesheet}/paid-status', [TimesheetController::class, 'updatePaidStatus']);
    Route::patch('/{timesheet}/lunch-hours', [TimesheetController::class, 'updateLunchHours']);
    Route::patch('/{timesheet}/comment', [TimesheetController::class, 'updateComment']);
    Route::post('/{timesheet}/resolve-leave-conflict', [TimesheetController::class, 'resolveLeaveConflict']);
    Route::patch('/{timesheet}/approval', [TimesheetController::class, 'updateApproval']);
});

Route::prefix('notifications')->middleware('auth:sanctum')->group(function () {
    Route::get('/', [NotificationController::class, 'index']);
    Route::post('/read-all', [NotificationController::class, 'markAllAsRead']);
    Route::post('/{notificationId}/read', [NotificationController::class, 'markAsRead']);
});

// Timesheet Templates
Route::get('timesheet-templates', [TimesheetTemplateController::class, 'index']);
Route::post('timesheet-templates', [TimesheetTemplateController::class, 'store']);
Route::put('timesheet-templates/{timesheetTemplate}', [TimesheetTemplateController::class, 'update']);

// Timesheet Template Departments
Route::get('timesheet-template-departments', [TimesheetTemplateDepartmentController::class, 'index']);
Route::post('timesheet-template-departments', [TimesheetTemplateDepartmentController::class, 'store']);

// Scheduler merged feed (work, holidays, leaves, birthdays)
Route::prefix('scheduler-events')->group(function () {
    Route::get('/', [SchedulerEventController::class, 'index']);
});

// Leave approvals for scheduler
Route::prefix('scheduler-approvals')->group(function () {
    Route::get('/', [SchedulerEventController::class, 'approvals']);
    Route::patch('/{type}/{id}', [SchedulerEventController::class, 'updateApproval']);
});

//Allowance
Route::get('allowances', [AllowanceController::class, 'index']);
Route::get('allowances/{allowance}', [AllowanceController::class, 'show']);
Route::post('allowances', [AllowanceController::class, 'store']);
Route::put('allowances/{allowance}', [AllowanceController::class, 'update']);
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

// Company Routes (single record — update only, no create)
Route::prefix('company')->group(function () {
    Route::get('/', [CompanyController::class, 'index']);
    Route::get('/{company}', [CompanyController::class, 'show']);
    Route::put('/{company}', [CompanyController::class, 'update']);
});

// Accounts Routes
Route::prefix('accounts')->group(function () {
    Route::get('/', [AccountController::class, 'index']);
    Route::get('/tree', [AccountController::class, 'tree']);
    Route::post('/', [AccountController::class, 'store']);
    Route::get('/{account}', [AccountController::class, 'show']);
    Route::put('/{account}', [AccountController::class, 'update']);
    Route::delete('/{account}', [AccountController::class, 'destroy']);
    Route::get('/{account}/sub-accounts', [AccountController::class, 'subAccounts']);
});

// Account Type Routes
Route::prefix('account-types')->group(function () {
    Route::get('/', [AccountTypeController::class, 'index']);
    Route::post('/', [AccountTypeController::class, 'store']);
    Route::get('/{accountType}', [AccountTypeController::class, 'show']);
    Route::put('/{accountType}', [AccountTypeController::class, 'update']);
    Route::delete('/{accountType}', [AccountTypeController::class, 'destroy']);
});

// Deduction Type Routes
Route::prefix('deduction-types')->group(function () {
    Route::get('/', [DeductionTypeController::class, 'index']);
    Route::post('/', [DeductionTypeController::class, 'store']);
    Route::get('/{deductionType}', [DeductionTypeController::class, 'show']);
    Route::put('/{deductionType}', [DeductionTypeController::class, 'update']);
    Route::delete('/{deductionType}', [DeductionTypeController::class, 'destroy']);
});

// Payment To Routes
Route::prefix('vendors')->group(function () {
    Route::get('/', [VendorController::class, 'index']);
    Route::post('/', [VendorController::class, 'store']);
    Route::get('/{vendor}', [VendorController::class, 'show']);
    Route::put('/{vendor}', [VendorController::class, 'update']);
    Route::delete('/{vendor}', [VendorController::class, 'destroy']);
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

Route::prefix('department-head-assignments')->group(function () {
    Route::get('/', [DepartmentHeadAssignmentController::class, 'index']);
    Route::post('/', [DepartmentHeadAssignmentController::class, 'store']);
    Route::get('/{departmentHeadAssignment}', [DepartmentHeadAssignmentController::class, 'show']);
    Route::put('/{departmentHeadAssignment}', [DepartmentHeadAssignmentController::class, 'update']);
    Route::delete('/{departmentHeadAssignment}', [DepartmentHeadAssignmentController::class, 'destroy']);
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

// Worksite routes
Route::prefix('worksites')->group(function () {
    Route::get('/', [WorksiteController::class, 'index']);
    Route::post('/', [WorksiteController::class, 'store']);
    Route::get('/{worksite}', [WorksiteController::class, 'show']);
    Route::put('/{worksite}', [WorksiteController::class, 'update']);
    Route::delete('/{worksite}', [WorksiteController::class, 'destroy']);
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

// Gender routes
Route::prefix('genders')->group(function () {
    Route::get('/', [GenderController::class, 'index']);
    Route::post('/', [GenderController::class, 'store']);
    Route::get('/{gender}', [GenderController::class, 'show']);
    Route::put('/{gender}', [GenderController::class, 'update']);
    Route::delete('/{gender}', [GenderController::class, 'destroy']);
});

// Citizenship Status routes
Route::prefix('citizenship-statuses')->group(function () {
    Route::get('/', [CitizenshipStatusController::class, 'index']);
    Route::post('/', [CitizenshipStatusController::class, 'store']);
    Route::get('/{citizenshipStatus}', [CitizenshipStatusController::class, 'show']);
    Route::put('/{citizenshipStatus}', [CitizenshipStatusController::class, 'update']);
    Route::delete('/{citizenshipStatus}', [CitizenshipStatusController::class, 'destroy']);
});

// Employee Status Routes
Route::prefix('employee-statuses')->group(function () {
    Route::get('/', [EmployeeStatusController::class, 'index']);
    Route::post('/', [EmployeeStatusController::class, 'store']);
    Route::get('/{employeeStatus}', [EmployeeStatusController::class, 'show']);
    Route::put('/{employeeStatus}', [EmployeeStatusController::class, 'update']);
    Route::delete('/{employeeStatus}', [EmployeeStatusController::class, 'destroy']);
});

// Employee Default Deduction Routes
Route::prefix('employee-default-deductions')->group(function () {
    Route::get('/', [EmployeeDefaultDeductionController::class, 'index']);
    Route::post('/', [EmployeeDefaultDeductionController::class, 'store']);
    Route::get('/{defaultDeduction}', [EmployeeDefaultDeductionController::class, 'show']);
    Route::put('/{defaultDeduction}', [EmployeeDefaultDeductionController::class, 'update']);
    Route::delete('/{defaultDeduction}', [EmployeeDefaultDeductionController::class, 'destroy']);
});

// Employment Details Routes
Route::prefix('employment-details')->group(function () {
    Route::get('/', [EmploymentDetailsController::class, 'index']);
    Route::post('/', [EmploymentDetailsController::class, 'store']);
    Route::get('/{employmentDetails}', [EmploymentDetailsController::class, 'show']);
    Route::match(['put', 'post'], '/{employmentDetails}', [EmploymentDetailsController::class, 'update']);
    Route::delete('/{employmentDetails}', [EmploymentDetailsController::class, 'destroy']);
    Route::get('/{employmentDetails}/leave-entitlements', [EmploymentLeaveEntitlementController::class, 'index']);
    Route::put('/{employmentDetails}/leave-entitlements', [EmploymentLeaveEntitlementController::class, 'sync']);
});

Route::prefix('employee-compensations')->group(function () {
    Route::get('/', [EmployeeCompensationController::class, 'index']);
    Route::post('/', [EmployeeCompensationController::class, 'store']);
    Route::get('/{employeeCompensation}', [EmployeeCompensationController::class, 'show']);
    Route::match(['put', 'post'], '/{employeeCompensation}', [EmployeeCompensationController::class, 'update']);
    Route::delete('/{employeeCompensation}', [EmployeeCompensationController::class, 'destroy']);
});

Route::prefix('contract-types')->group(function () {
    Route::get('/', [ContractTypeController::class, 'index']);
    Route::post('/', [ContractTypeController::class, 'store']);
    Route::get('/{contractType}', [ContractTypeController::class, 'show']);
    Route::put('/{contractType}', [ContractTypeController::class, 'update']);
    Route::delete('/{contractType}', [ContractTypeController::class, 'destroy']);
});

Route::prefix('employment-statuses')->group(function () {
    Route::get('/', [EmploymentStatusController::class, 'index']);
    Route::post('/', [EmploymentStatusController::class, 'store']);
    Route::get('/{employmentStatus}', [EmploymentStatusController::class, 'show']);
    Route::put('/{employmentStatus}', [EmploymentStatusController::class, 'update']);
    Route::delete('/{employmentStatus}', [EmploymentStatusController::class, 'destroy']);
});

// Employee Routes
Route::prefix('employees')->group(function () {
    Route::middleware('auth:sanctum')->group(function () {
        Route::get('/import/template', [EmployeeImportController::class, 'template']);
        Route::post('/import', [EmployeeImportController::class, 'import']);
    });
    Route::get('/', [EmployeeController::class, 'index']);
    Route::post('/', [EmployeeController::class, 'store']);
    Route::get('/{employee}', [EmployeeController::class, 'show']);
    Route::put('/{employee}', [EmployeeController::class, 'update']);
    Route::delete('/{employee}', [EmployeeController::class, 'destroy']);
    Route::post('/{employee}/picture', [EmployeeController::class, 'uploadPicture']);
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

Route::prefix('employee-certifications')->group(function () {
    Route::get('/', [EmployeeCertificationController::class, 'index']);
    Route::post('/', [EmployeeCertificationController::class, 'store']);
    Route::get('/{employeeCertification}', [EmployeeCertificationController::class, 'show']);
    Route::put('/{employeeCertification}', [EmployeeCertificationController::class, 'update']);
    Route::delete('/{employeeCertification}', [EmployeeCertificationController::class, 'destroy']);
});

Route::prefix('employee-skills')->group(function () {
    Route::get('/', [EmployeeSkillController::class, 'index']);
    Route::post('/', [EmployeeSkillController::class, 'store']);
    Route::get('/{employeeSkill}', [EmployeeSkillController::class, 'show']);
    Route::put('/{employeeSkill}', [EmployeeSkillController::class, 'update']);
    Route::delete('/{employeeSkill}', [EmployeeSkillController::class, 'destroy']);
});

Route::prefix('document-tags')->group(function () {
    Route::get('/', [DocumentTagController::class, 'index']);
    Route::post('/', [DocumentTagController::class, 'store']);
    Route::get('/{documentTag}', [DocumentTagController::class, 'show']);
    Route::put('/{documentTag}', [DocumentTagController::class, 'update']);
    Route::delete('/{documentTag}', [DocumentTagController::class, 'destroy']);
});

Route::prefix('employee-documents')->group(function () {
    Route::get('/', [EmployeeDocumentController::class, 'index']);
    Route::post('/', [EmployeeDocumentController::class, 'store']);
    Route::get('/{employeeDocument}', [EmployeeDocumentController::class, 'show']);
    Route::match(['put', 'post'], '/{employeeDocument}', [EmployeeDocumentController::class, 'update']);
    Route::delete('/{employeeDocument}', [EmployeeDocumentController::class, 'destroy']);
});

Route::prefix('employee-incidents')->middleware('auth:sanctum')->group(function () {
    Route::get('/meta', [EmployeeIncidentController::class, 'meta']);
    Route::get('/', [EmployeeIncidentController::class, 'index']);
    Route::post('/', [EmployeeIncidentController::class, 'store']);
    Route::get('/{employeeIncident}', [EmployeeIncidentController::class, 'show']);
    Route::match(['put', 'post'], '/{employeeIncident}', [EmployeeIncidentController::class, 'update']);
    Route::delete('/{employeeIncident}', [EmployeeIncidentController::class, 'destroy']);
});

// Employee Contact Routes
Route::prefix('employee-contacts')->group(function () {
    Route::get('/', [EmployeeContactController::class, 'index']);
    Route::post('/', [EmployeeContactController::class, 'store']);
    Route::get('/{employeeContact}', [EmployeeContactController::class, 'show']);
    Route::put('/{employeeContact}', [EmployeeContactController::class, 'update']);
    Route::delete('/{employeeContact}', [EmployeeContactController::class, 'destroy']);
});

Route::prefix('employee-default-allowances')->group(function () {
    Route::get('/', [EmployeeDefaultAllowanceController::class, 'index']);
    Route::post('/', [EmployeeDefaultAllowanceController::class, 'store']);
    Route::get('/{employeeDefaultAllowance}', [EmployeeDefaultAllowanceController::class, 'show']);
    Route::put('/{employeeDefaultAllowance}', [EmployeeDefaultAllowanceController::class, 'update']);
    Route::delete('/{employeeDefaultAllowance}', [EmployeeDefaultAllowanceController::class, 'destroy']);
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
});

// Payment Method Routes
Route::prefix('payment-methods')->group(function () {
    Route::get('/', [PaymentMethodController::class, 'index']);
    Route::post('/', [PaymentMethodController::class, 'store']);
    Route::get('/{paymentMethod}', [PaymentMethodController::class, 'show']);
    Route::put('/{paymentMethod}', [PaymentMethodController::class, 'update']);
    Route::delete('/{paymentMethod}', [PaymentMethodController::class, 'destroy']);
});

// Payrate Frequency Routes
Route::prefix('payrate-frequencies')->group(function () {
    Route::get('/', [PayrateFrequencyController::class, 'index']);
    Route::post('/', [PayrateFrequencyController::class, 'store']);
    Route::get('/{payrateFrequency}', [PayrateFrequencyController::class, 'show']);
    Route::put('/{payrateFrequency}', [PayrateFrequencyController::class, 'update']);
    Route::delete('/{payrateFrequency}', [PayrateFrequencyController::class, 'destroy']);
});

// Loan Type Routes
Route::prefix('loan-types')->group(function () {
    Route::get('/', [LoanTypeController::class, 'index']);
    Route::post('/', [LoanTypeController::class, 'store']);
    Route::get('/{loanType}', [LoanTypeController::class, 'show']);
    Route::put('/{loanType}', [LoanTypeController::class, 'update']);
    Route::delete('/{loanType}', [LoanTypeController::class, 'destroy']);
});

// Role Routes
Route::prefix('roles')->group(function () {
    Route::get('/', [RoleController::class, 'index']);
    Route::post('/', [RoleController::class, 'store']);
    Route::get('/{role}', [RoleController::class, 'show']);
    Route::put('/{role}', [RoleController::class, 'update']);
    Route::delete('/{role}', [RoleController::class, 'destroy']);
    Route::post('/{role}/permissions', [RoleController::class, 'assignPermissions']);
    Route::delete('/{role}/permissions', [RoleController::class, 'removePermissions']);
});

// Permission Routes
Route::prefix('permissions')->group(function () {
    Route::get('/', [PermissionController::class, 'index']);
    Route::post('/', [PermissionController::class, 'store']);
    Route::get('/{permission}', [PermissionController::class, 'show']);
    Route::put('/{permission}', [PermissionController::class, 'update']);
    Route::delete('/{permission}', [PermissionController::class, 'destroy']);
});

// Employee Work Permit Routes
Route::prefix('employee-work-permits')->group(function () {
    Route::get('/', [EmployeeWorkPermitController::class, 'index']);
    Route::post('/', [EmployeeWorkPermitController::class, 'store']);
    Route::get('/{employeeWorkPermit}', [EmployeeWorkPermitController::class, 'show']);
    Route::put('/{employeeWorkPermit}', [EmployeeWorkPermitController::class, 'update']);
    Route::delete('/{employeeWorkPermit}', [EmployeeWorkPermitController::class, 'destroy']);
});

Route::get('leave-statuses', [LeaveStatusController::class, 'index']);

// Leave Type Routes
Route::prefix('leave-types')->group(function () {
    Route::get('/', [LeaveTypeController::class, 'index']);
    Route::post('/', [LeaveTypeController::class, 'store']);
    Route::get('/{leaveType}', [LeaveTypeController::class, 'show']);
    Route::put('/{leaveType}', [LeaveTypeController::class, 'update']);
    Route::delete('/{leaveType}', [LeaveTypeController::class, 'destroy']);
});

Route::middleware('auth:sanctum')->group(function () {
    Route::get('employee-leave-balances', [EmployeeLeaveBalanceController::class, 'index']);
    Route::get('employee-leave-balances/selectable-employees', [EmployeeLeaveBalanceController::class, 'selectableEmployees']);
});

// Employee Leave Routes
Route::prefix('employee-leaves')->middleware('auth:sanctum')->group(function () {
    Route::get('/', [EmployeeLeaveController::class, 'index']);
    Route::get('/team', [EmployeeLeaveController::class, 'teamIndex']);
    Route::get('/team-access', [EmployeeLeaveController::class, 'teamAccess']);
    Route::post('/', [EmployeeLeaveController::class, 'store']);
    Route::get('/{employeeLeave}', [EmployeeLeaveController::class, 'show']);
    Route::put('/{employeeLeave}', [EmployeeLeaveController::class, 'update']);
    Route::post('/{employeeLeave}', [EmployeeLeaveController::class, 'update']);
    Route::delete('/{employeeLeave}', [EmployeeLeaveController::class, 'destroy']);
    Route::patch('/{employeeLeave}/approve', [EmployeeLeaveController::class, 'approve']);
    Route::patch('/{employeeLeave}/status', [EmployeeLeaveController::class, 'updateStatus']);
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

// Menu Routes (Self-referential hierarchical structure)
Route::prefix('menus')->group(function () {
    Route::get('/', [MenuController::class, 'index']);
    Route::post('/', [MenuController::class, 'store']);
    Route::get('/{menu}', [MenuController::class, 'show']);
    Route::put('/{menu}', [MenuController::class, 'update']);
    Route::delete('/{menu}', [MenuController::class, 'destroy']);
});

// User Routes
Route::prefix('users')->group(function () {
    Route::get('/', [UserController::class, 'index']);
    Route::post('/', [UserController::class, 'store']);
    Route::get('/{user}', [UserController::class, 'show']);
    Route::put('/{user}', [UserController::class, 'update']);
    Route::delete('/{user}', [UserController::class, 'destroy']);

    // User password management
    Route::put('/{user}/password', [UserController::class, 'updatePassword']);
    Route::post('/{user}/send-password-reset', [UserController::class, 'sendPasswordResetEmail']);
    Route::post('/reset-password', [UserController::class, 'resetPassword']);

    // User employee linking
    Route::put('/{user}/employee', [UserController::class, 'linkEmployee']);

    // User role management
    Route::post('/{user}/roles', [UserController::class, 'assignRoles']);
    Route::put('/{user}/roles', [UserController::class, 'addRoles']);
    Route::delete('/{user}/roles', [UserController::class, 'removeRoles']);
    Route::delete('/{user}/roles/all', [UserController::class, 'removeAllRoles']);
    Route::get('/{user}/roles/available', [UserController::class, 'availableRoles']);
    Route::get('/by-role/{role}', [UserController::class, 'getUsersByRole']);
});

// Pay Period Routes
Route::prefix('pay-period-groups')->group(function () {
    Route::get('/', [PayPeriodGroupController::class, 'index']);
    Route::post('/', [PayPeriodGroupController::class, 'store']);
    Route::get('/{payPeriodGroup}', [PayPeriodGroupController::class, 'show']);
    Route::put('/{payPeriodGroup}', [PayPeriodGroupController::class, 'update']);
    Route::delete('/{payPeriodGroup}', [PayPeriodGroupController::class, 'destroy']);
});

Route::prefix('pay-period-schedules')->group(function () {
    Route::get('/', [PayPeriodController::class, 'index']);
    Route::post('/', [PayPeriodController::class, 'store']);
    Route::get('/{payPeriodSchedule}', [PayPeriodController::class, 'show']);
    Route::put('/{payPeriodSchedule}', [PayPeriodController::class, 'update']);
    Route::delete('/{payPeriodSchedule}', [PayPeriodController::class, 'destroy']);
    
    // AI-powered SQL generation endpoints
    Route::post('/ai/generate', [PayPeriodScheduleAiController::class, 'generateSql']);
    Route::post('/ai/confirm', [PayPeriodScheduleAiController::class, 'confirmAndExecute']);
});

// Payroll Run Routes
Route::prefix('reports')->group(function () {
    Route::get('/salary-review', [ReportController::class, 'salaryReview']);
});

Route::prefix('payroll-runs')->group(function () {
    Route::get('/', [PayrollRunController::class, 'index']);
    Route::post('/', [PayrollRunController::class, 'store']);
    Route::get('/{payrollRun}/department-earnings-report', [PayrollEarningLineController::class, 'departmentReport']);
    Route::get('/{payrollRun}/journal-entries-report', [PayrollRunController::class, 'journalEntriesReport']);
    Route::get('/{payrollRun}/payroll-summary-by-department-report', [PayrollRunController::class, 'payrollSummaryByDepartmentReport']);
    Route::get('/{payrollRun}/payroll-journal-departments-report', [PayrollRunController::class, 'payrollJournalDepartmentsReport']);
    Route::get('/{payrollRun}/employee-summary', [PayrollRunController::class, 'employeeSummary']);
    Route::post('/{payrollRun}/process', [PayrollRunPayslipController::class, 'process']);
    Route::get('/{payrollRun}/payslips', [PayrollRunPayslipController::class, 'payslips']);
    Route::post('/{payrollRun}/allowance-deduction-import/preview', [PayrollRunAllowanceDeductionImportController::class, 'preview']);
    Route::post('/{payrollRun}/allowance-deduction-import/confirm', [PayrollRunAllowanceDeductionImportController::class, 'confirm']);
    Route::get('/{payrollRun}', [PayrollRunController::class, 'show']);
    Route::put('/{payrollRun}', [PayrollRunController::class, 'update']);
    Route::delete('/{payrollRun}', [PayrollRunController::class, 'destroy']);
});

// Employee payroll summaries
Route::prefix('payrolls')->group(function () {
    Route::get('/', [PayrollController::class, 'index']);
    Route::post('/', [PayrollController::class, 'store']);
    Route::get('/{payroll}', [PayrollController::class, 'show']);
    Route::put('/{payroll}', [PayrollController::class, 'update']);
    Route::delete('/{payroll}', [PayrollController::class, 'destroy']);
});

// Payroll earning codes (settings)
Route::prefix('payroll-earning-codes')->group(function () {
    Route::get('/', [PayrollEarningCodeController::class, 'index']);
    Route::post('/', [PayrollEarningCodeController::class, 'store']);
    Route::get('/{payrollEarningCode}', [PayrollEarningCodeController::class, 'show']);
    Route::put('/{payrollEarningCode}', [PayrollEarningCodeController::class, 'update']);
    Route::delete('/{payrollEarningCode}', [PayrollEarningCodeController::class, 'destroy']);
});

// Payroll earning lines
Route::get('payroll-earning-lines', [PayrollEarningLineController::class, 'index']);

// Payroll Contribution Routes
Route::prefix('payroll-contributions')->group(function () {
    Route::get('/', [PayrollContributionController::class, 'index']);
    Route::post('/', [PayrollContributionController::class, 'store']);
    Route::get('/{payrollContribution}', [PayrollContributionController::class, 'show']);
    Route::put('/{payrollContribution}', [PayrollContributionController::class, 'update']);
    Route::delete('/{payrollContribution}', [PayrollContributionController::class, 'destroy']);
});

// Journal Entry Routes
Route::prefix('journal-entries')->group(function () {
    Route::get('/', [JournalEntryController::class, 'index']);
    Route::post('/', [JournalEntryController::class, 'store']);
    Route::get('/{journalEntry}', [JournalEntryController::class, 'show']);
    Route::put('/{journalEntry}', [JournalEntryController::class, 'update']);
    Route::delete('/{journalEntry}', [JournalEntryController::class, 'destroy']);
});

// Journal Line Routes
Route::prefix('journal-lines')->group(function () {
    Route::get('/', [JournalLineController::class, 'index']);
    Route::post('/', [JournalLineController::class, 'store']);
    Route::get('/{journalLine}', [JournalLineController::class, 'show']);
    Route::put('/{journalLine}', [JournalLineController::class, 'update']);
    Route::delete('/{journalLine}', [JournalLineController::class, 'destroy']);
});
