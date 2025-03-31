<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

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

    public function employmentDetails()
    {
        return $this->hasMany(EmploymentDetail::class, 'departmentId');
    }
}
