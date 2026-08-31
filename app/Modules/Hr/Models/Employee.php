<?php

namespace App\Modules\Hr\Models;

use App\Models\EmployeeGroupMember;
use App\Models\EmployeeCompensation;
use App\Models\EmployeeDefaultAllowance;
use App\Models\EmployeeDefaultDeduction;
use App\Models\EmployeeBank;
use App\Models\EmployeeCertification;
use App\Models\EmployeeContact;
use App\Models\EmployeeDocument;
use App\Models\EmployeeIncident;
use App\Models\EmployeeLeave;
use App\Models\EmployeeReporting;
use App\Models\EmployeeSkill;
use App\Models\EmployeeSsBenefitStatus;
use App\Models\EmployeeStatus;
use App\Models\EmploymentDetail;
use App\Models\EmploymentStatus;
use App\Models\HistoricalEmployeeAllowance;
use App\Models\HistoricalEmployeeDeduction;
use App\Models\Loan;
use App\Models\Payroll;
use App\Models\PaymentMethod;
use App\Models\PayrateFrequency;
use App\Models\Qualification;
use App\Models\Timesheet;
use App\Models\TimesheetTemplate;
use App\Models\User;
use App\Models\DepartmentHeadAssignment;
use App\Models\EmployeeWorkPermit;
use App\Models\EmploymentHistory;
use App\Modules\Core\Models\Person;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
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
        'person_id',
        'user_id',
        'leadId',
        'supervisorId',
        'code',
        'internalId1',
        'internalId2',
        'payrateFrequencyId',
        'paymentMethodId',
        'employmentStatusId',
        'employeeStatusId',
        'timesheetTemplateId',
    ];

    protected $with = ['person'];

    public function person(): BelongsTo
    {
        return $this->belongsTo(Person::class, 'person_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    public function payrateFrequency(): BelongsTo
    {
        return $this->belongsTo(PayrateFrequency::class, 'payrateFrequencyId');
    }

    public function paymentMethod(): BelongsTo
    {
        return $this->belongsTo(PaymentMethod::class, 'paymentMethodId');
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

    public function supervisor(): BelongsTo
    {
        return $this->belongsTo(self::class, 'supervisorId');
    }

    public function supervisedEmployees(): HasMany
    {
        return $this->hasMany(self::class, 'supervisorId');
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

    public function groupMemberships(): HasMany
    {
        return $this->hasMany(EmployeeGroupMember::class, 'employeeId');
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

    public function incidents(): HasMany
    {
        return $this->hasMany(EmployeeIncident::class, 'employeeId');
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

    public function scopeOrderByPersonName($query, string $column = 'lastName', string $direction = 'asc')
    {
        return $query
            ->join('person', 'employee.person_id', '=', 'person.id')
            ->orderBy("person.{$column}", $direction)
            ->select('employee.*');
    }

    public function getAttribute($key)
    {
        if (in_array($key, Person::ATTRIBUTE_KEYS, true) && $this->person) {
            return $this->person->getAttribute($key);
        }

        if (in_array($key, ['locality', 'honorific', 'gender', 'citizenshipStatus', 'nationality'], true) && $this->person) {
            return $this->person->getRelationValue($key);
        }

        return parent::getAttribute($key);
    }

    public function toArray(): array
    {
        $array = parent::toArray();

        if (!$this->relationLoaded('person')) {
            return $array;
        }

        $person = $this->person;
        if (!$person) {
            return $array;
        }

        foreach (Person::ATTRIBUTE_KEYS as $key) {
            $array[$key] = $person->{$key};
        }

        $array['personId'] = $this->person_id;
        $array['person'] = $person->toArray();

        foreach (['locality', 'honorific', 'gender', 'citizenshipStatus', 'nationality'] as $relation) {
            if ($person->relationLoaded($relation)) {
                $related = $person->{$relation};
                $array[$relation] = $related ? $related->toArray() : null;
            }
        }

        return $array;
    }
}
