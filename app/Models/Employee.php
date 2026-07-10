<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Employee extends Model
{
    use HasUuids;

    protected $table = 'employee';
    protected $primaryKey = 'id';
    protected $keyType = 'string';
    public $incrementing = false;

    protected $fillable = [
        'user_id',
        'leadId',
        'supervisorId',
        'code',
        'internalId1',
        'internalId2',
        'honorificId',
        'firstName',
        'middleName',
        'lastName',
        'maidenName',
        'birthdate',
        'address1',
        'address2',
        'localityId',
        'phone',
        'email',
        'genderId',
        'socialSecurityNumber',
        'taxIdentificationNumber',
        'passportNumber',
        'votersId',
        'citizenshipStatusId',
        'nationalityId',
        'notes',
        'picturePath',
        'health',
        'unionMembership',
        'employmentStatusId',
        'employeeStatusId',
        'timesheetTemplateId',
    ];


    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    public function locality(): BelongsTo
    {
        return $this->belongsTo(Locality::class);
    }

    public function honorific(): BelongsTo
    {
        return $this->belongsTo(Honorific::class, 'honorificId');
    }

    public function gender(): BelongsTo
    {
        return $this->belongsTo(Gender::class, 'genderId');
    }

    public function citizenshipStatus(): BelongsTo
    {
        return $this->belongsTo(CitizenshipStatus::class, 'citizenshipStatusId');
    }

    public function nationality(): BelongsTo
    {
        return $this->belongsTo(Country::class, 'nationalityId');
    }

    public function employeeWorkPermit(): HasMany
    {
        return $this->hasMany(EmployeeWorkPermit::class);
    }

    public function employmentHistory(): HasMany
    {
        return $this->hasMany(EmploymentHistory::class);
    }

    public function allowances(): HasMany
    {
        return $this->hasMany(EmployeeDefaultAllowance::class, 'employeeId');
    }

    public function employeeBanks(): HasMany
    {
        return $this->hasMany(EmployeeBank::class, 'employeeId');
    }

    public function contacts(): HasMany
    {
        return $this->hasMany(EmployeeContact::class, 'employeeId');
    }

    public function employeeDefaultDeductions(): HasMany
    {
        return $this->hasMany(EmployeeDefaultDeduction::class, 'employeeId');
    }

    public function employmentDetails(): HasMany
    {
        return $this->hasMany(EmploymentDetail::class, 'employeeId');
    }

    public function employeeCompensations(): HasMany
    {
        return $this->hasMany(EmployeeCompensation::class, 'employeeId');
    }

    public function reportingSupervisors(): HasMany
    {
        return $this->hasMany(EmployeeReporting::class, 'subordinate_id');
    }

    public function reportingSubordinates(): HasMany
    {
        return $this->hasMany(EmployeeReporting::class, 'supervisor_id');
    }

    public function loans(): HasMany
    {
        return $this->hasMany(Loan::class, 'employeeId');
    }

    public function payrolls(): HasMany
    {
        return $this->hasMany(Payroll::class, 'employeeId');
    }

    public function historicalDeductions(): HasMany
    {
        return $this->hasMany(HistoricalEmployeeDeduction::class);
    }

    public function qualifications(): HasMany
    {
        return $this->hasMany(Qualification::class, 'employeeId');
    }

    public function certifications(): HasMany
    {
        return $this->hasMany(EmployeeCertification::class, 'employeeId');
    }

    public function skills(): HasMany
    {
        return $this->hasMany(EmployeeSkill::class, 'employeeId');
    }

    public function documents(): HasMany
    {
        return $this->hasMany(EmployeeDocument::class, 'employeeId');
    }

    public function leaves(): HasMany
    {
        return $this->hasMany(EmployeeLeave::class, 'employeeId');
    }

    public function timesheets(): HasMany
    {
        return $this->hasMany(Timesheet::class, 'employeeId');
    }

    public function headAssignments(): HasMany
    {
        return $this->hasMany(DepartmentHeadAssignment::class, 'employeeId');
    }

    public function historicalAllowances(): HasMany
    {
        return $this->hasMany(HistoricalEmployeeAllowance::class);
    }

    public function ssBenefitStatuses(): HasMany
    {
        return $this->hasMany(EmployeeSsBenefitStatus::class, 'employeeId');
    }

    public function employmentStatus(): BelongsTo
    {
        return $this->belongsTo(EmploymentStatus::class, 'employmentStatusId');
    }

    public function employeeStatus(): BelongsTo
    {
        return $this->belongsTo(EmployeeStatus::class, 'employeeStatusId');
    }

    public function timesheetTemplate(): BelongsTo
    {
        return $this->belongsTo(TimesheetTemplate::class, 'timesheetTemplateId');
    }
}
