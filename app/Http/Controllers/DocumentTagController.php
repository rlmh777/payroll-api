<?php

namespace App\Http\Controllers;

use App\Models\DocumentTag;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class DocumentTagController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $query = DocumentTag::query()->with('parent');

        if ($request->filled('search')) {
            $search = '%'.$request->string('search').'%';
            $query->where(function ($builder) use ($search) {
                $builder
                    ->where('name', 'ilike', $search)
                    ->orWhere('description', 'ilike', $search);
            });
        }

        if ($request->filled('parent_id') || $request->filled('parentId')) {
            $parentId = $request->input('parentId', $request->input('parent_id'));
            if ($parentId === 'null' || $parentId === '') {
                $query->whereNull('parentId');
            } else {
                $query->where('parentId', $parentId);
            }
        }

        if ($request->boolean('active_only')) {
            $query->where('isActive', true);
        }

        $sortField = $request->get('sort_by', 'sortOrder');
        $sortDirection = $request->get('sort_direction', 'asc');
        if (in_array($sortField, ['name', 'sortOrder', 'created_at'], true)) {
            $query->orderBy($sortField, $sortDirection === 'desc' ? 'desc' : 'asc');
        } else {
            $query->orderBy('sortOrder')->orderBy('name');
        }

        $perPage = max(1, min((int) $request->integer('per_page', 25), 200));

        if ($request->boolean('all')) {
            return response()->json([
                'data' => $query->get(),
            ]);
        }

        return response()->json($query->paginate($perPage));
    }

    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'parentId' => ['nullable', 'uuid', 'exists:document_tag,id'],
            'name' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string', 'max:2000'],
            'color' => ['nullable', 'string', 'regex:/^#[0-9A-Fa-f]{6}$/'],
            'sortOrder' => ['nullable', 'integer', 'min:0', 'max:9999'],
            'isActive' => ['nullable', 'boolean'],
        ]);

        $tag = DocumentTag::create([
            'parentId' => $validated['parentId'] ?? null,
            'name' => $validated['name'],
            'description' => $validated['description'] ?? null,
            'color' => $validated['color'] ?? '#1976D2',
            'sortOrder' => $validated['sortOrder'] ?? 0,
            'isActive' => $validated['isActive'] ?? true,
        ]);

        return response()->json([
            'message' => 'Document tag created successfully.',
            'data' => $tag->load('parent'),
        ], 201);
    }

    public function show(DocumentTag $documentTag): JsonResponse
    {
        return response()->json($documentTag->load(['parent', 'children']));
    }

    public function update(Request $request, DocumentTag $documentTag): JsonResponse
    {
        $validated = $request->validate([
            'parentId' => ['sometimes', 'nullable', 'uuid', 'exists:document_tag,id'],
            'name' => ['sometimes', 'string', 'max:255'],
            'description' => ['nullable', 'string', 'max:2000'],
            'color' => ['sometimes', 'nullable', 'string', 'regex:/^#[0-9A-Fa-f]{6}$/'],
            'sortOrder' => ['sometimes', 'integer', 'min:0', 'max:9999'],
            'isActive' => ['sometimes', 'boolean'],
        ]);

        if (array_key_exists('parentId', $validated)) {
            $parentId = $validated['parentId'];

            if ($parentId === $documentTag->id) {
                return response()->json(['error' => 'A tag cannot be its own parent.'], 422);
            }

            if ($parentId && $this->isDescendant($documentTag, $parentId)) {
                return response()->json(['error' => 'Cannot set parent to a descendant tag.'], 422);
            }
        }

        $documentTag->update($validated);

        return response()->json([
            'message' => 'Document tag updated successfully.',
            'data' => $documentTag->fresh()->load('parent'),
        ]);
    }

    public function destroy(DocumentTag $documentTag): JsonResponse
    {
        if ($documentTag->children()->exists()) {
            return response()->json([
                'error' => 'Cannot delete a tag that has child tags.',
            ], 422);
        }

        if ($documentTag->employeeDocuments()->exists()) {
            return response()->json([
                'error' => 'Cannot delete a tag that is used by employee documents.',
            ], 422);
        }

        $documentTag->delete();

        return response()->json([
            'message' => 'Document tag deleted successfully.',
        ]);
    }

    private function isDescendant(DocumentTag $tag, string $potentialParentId): bool
    {
        $current = DocumentTag::find($potentialParentId);

        while ($current) {
            if ($current->id === $tag->id) {
                return true;
            }

            $current = $current->parentId
                ? DocumentTag::find($current->parentId)
                : null;
        }

        return false;
    }
}
