<?php

namespace App\Modules\Hr\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Models\EmployeeGroup;
use App\Models\EmployeeGroupMember;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;

class EmployeeGroupController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $query = EmployeeGroup::query()
            ->withCount([
                'members as active_member_count' => fn ($memberQuery) => $memberQuery->active(),
            ])
            ->orderBy('name');

        if ($request->boolean('active_only')) {
            $query->where('isActive', true);
        }

        if ($request->filled('search')) {
            $search = trim((string) $request->string('search'));
            $query->where(function ($inner) use ($search) {
                $inner->where('name', 'ilike', "%{$search}%")
                    ->orWhere('description', 'ilike', "%{$search}%");
            });
        }

        if ($request->boolean('with_members')) {
            $query->with([
                'activeMembers' => fn ($memberQuery) => $memberQuery
                    ->select(['id', 'employeeGroupId', 'employeeId', 'startDate', 'endDate'])
                    ->orderBy('created_at'),
            ]);
        }

        $perPage = (int) $request->input('per_page', 0);

        if ($perPage > 0) {
            return response()->json($query->paginate($perPage));
        }

        return response()->json($query->get());
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string', 'max:2000'],
            'color' => ['nullable', 'string', 'max:32'],
            'isActive' => ['sometimes', 'boolean'],
        ]);

        $group = EmployeeGroup::create([
            ...$data,
            'isActive' => (bool) ($data['isActive'] ?? true),
        ]);

        return response()->json($this->transformGroup($group), Response::HTTP_CREATED);
    }

    public function show(EmployeeGroup $employeeGroup): JsonResponse
    {
        $employeeGroup->load([
            'activeMembers.employee:id,code,person_id',
            'activeMembers.employee.person:id,firstName,lastName',
        ]);

        return response()->json($this->transformGroup($employeeGroup, includeMembers: true));
    }

    public function update(Request $request, EmployeeGroup $employeeGroup): JsonResponse
    {
        $data = $request->validate([
            'name' => ['sometimes', 'required', 'string', 'max:255'],
            'description' => ['nullable', 'string', 'max:2000'],
            'color' => ['nullable', 'string', 'max:32'],
            'isActive' => ['sometimes', 'boolean'],
        ]);

        $employeeGroup->update($data);

        return response()->json($this->transformGroup($employeeGroup->fresh()));
    }

    public function destroy(EmployeeGroup $employeeGroup): JsonResponse
    {
        $employeeGroup->delete();

        return response()->json(null, Response::HTTP_NO_CONTENT);
    }

    public function addMember(Request $request, EmployeeGroup $employeeGroup): JsonResponse
    {
        $data = $request->validate([
            'employeeId' => ['required', 'uuid', 'exists:employee,id'],
            'startDate' => ['nullable', 'date'],
            'endDate' => ['nullable', 'date', 'after_or_equal:startDate'],
        ]);

        $member = EmployeeGroupMember::query()->updateOrCreate(
            [
                'employeeGroupId' => $employeeGroup->id,
                'employeeId' => $data['employeeId'],
            ],
            [
                'startDate' => $data['startDate'] ?? null,
                'endDate' => $data['endDate'] ?? null,
            ],
        );

        $member->load([
            'employee:id,code,person_id',
            'employee.person:id,firstName,lastName',
        ]);

        return response()->json($this->transformMember($member), Response::HTTP_CREATED);
    }

    public function removeMember(EmployeeGroup $employeeGroup, EmployeeGroupMember $member): JsonResponse
    {
        if ($member->employeeGroupId !== $employeeGroup->id) {
            return response()->json(['message' => 'Member does not belong to this group.'], Response::HTTP_NOT_FOUND);
        }

        $member->delete();

        return response()->json(null, Response::HTTP_NO_CONTENT);
    }

    public function syncMembers(Request $request, EmployeeGroup $employeeGroup): JsonResponse
    {
        $data = $request->validate([
            'employeeIds' => ['present', 'array'],
            'employeeIds.*' => ['uuid', 'exists:employee,id'],
        ]);

        $employeeIds = collect($data['employeeIds'])->filter()->unique()->values();

        DB::transaction(function () use ($employeeGroup, $employeeIds) {
            $existingIds = EmployeeGroupMember::query()
                ->where('employeeGroupId', $employeeGroup->id)
                ->pluck('employeeId');

            $toRemove = $existingIds->diff($employeeIds);
            if ($toRemove->isNotEmpty()) {
                EmployeeGroupMember::query()
                    ->where('employeeGroupId', $employeeGroup->id)
                    ->whereIn('employeeId', $toRemove)
                    ->delete();
            }

            foreach ($employeeIds as $employeeId) {
                EmployeeGroupMember::query()->firstOrCreate([
                    'employeeGroupId' => $employeeGroup->id,
                    'employeeId' => $employeeId,
                ]);
            }
        });

        return $this->show($employeeGroup);
    }

    /**
     * @return array<string, mixed>
     */
    private function transformGroup(EmployeeGroup $group, bool $includeMembers = false): array
    {
        $payload = [
            'id' => $group->id,
            'name' => $group->name,
            'description' => $group->description,
            'color' => $group->color,
            'isActive' => (bool) $group->isActive,
            'activeMemberCount' => (int) ($group->active_member_count ?? $group->activeMembers()->count()),
            'createdAt' => $group->created_at?->format('Y-m-d H:i:s'),
            'updatedAt' => $group->updated_at?->format('Y-m-d H:i:s'),
        ];

        if ($includeMembers) {
            $payload['members'] = $group->relationLoaded('activeMembers')
                ? $group->activeMembers->map(fn (EmployeeGroupMember $member) => $this->transformMember($member))->values()->all()
                : [];
        }

        return $payload;
    }

    /**
     * @return array<string, mixed>
     */
    private function transformMember(EmployeeGroupMember $member): array
    {
        $employee = $member->employee;
        $person = $employee?->person;

        return [
            'id' => $member->id,
            'employeeGroupId' => $member->employeeGroupId,
            'employeeId' => $member->employeeId,
            'employeeCode' => $employee?->code,
            'employeeName' => trim(sprintf(
                '%s %s',
                $person?->firstName ?? '',
                $person?->lastName ?? '',
            )) ?: null,
            'startDate' => $member->startDate?->format('Y-m-d'),
            'endDate' => $member->endDate?->format('Y-m-d'),
        ];
    }
}
