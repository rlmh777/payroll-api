<?php

namespace App\Enums;

enum PipelineAssigneeType: string
{
    case Supervisor = 'supervisor';
    case DepartmentHead = 'department_head';
    case Admin = 'admin';
    case Role = 'role';
    case SubjectOwner = 'subject_owner';

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_map(static fn (self $case) => $case->value, self::cases());
    }
}
