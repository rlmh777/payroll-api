<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

//Employee Department and Hierarchy - Employee Department Assignment - Employee Department Transfer - Employee Department History
// start date, end date, current department, previous department, transfer reason, transfer approval status, transfer approval date, transfer approver, transfer notes
// Link to Employee model via employment details - Employee and Supervisor Relationship - 
class Department extends Model
{
    protected $table = 'department';
    protected $primaryKey = 'id';

    protected $fillable = [
        'name',
        'parentId'
    ];

    public function parent()
    {
        return $this->belongsTo(Department::class, 'parentId');
    }

    public function children()
    {
        return $this->hasMany(Department::class, 'parentId');
    }

    public function workTimesheetAssignments(): HasMany
    {
        return $this->hasMany(WorkTimesheetDepartment::class, 'department_id');
    }

    public function currentWorkTimesheetAssignment(): HasOne
    {
        return $this->hasOne(WorkTimesheetDepartment::class, 'department_id')
            ->orderByDesc('effective_date')
            ->orderByDesc('created_at');
    }

    public function employmentDetails()
    {
        return $this->hasMany(EmploymentDetail::class, 'departmentId');
    }
}
