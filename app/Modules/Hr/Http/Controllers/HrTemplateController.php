<?php

namespace App\Modules\Hr\Http\Controllers;

use App\Modules\Hr\Models\HrTemplate;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Validation\Rule;

class HrTemplateController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $channel = (string) $request->input('channel', '');
        $query = HrTemplate::query()->orderBy('name');

        if (in_array($channel, HrTemplate::channels(), true)) {
            $query->where('channel', $channel);
        }

        if ($request->boolean('active_only')) {
            $query->where('is_active', true);
        }

        if ($request->filled('search')) {
            $search = trim((string) $request->string('search'));
            $query->where(function ($inner) use ($search) {
                $inner->where('name', 'ilike', "%{$search}%")
                    ->orWhere('category', 'ilike', "%{$search}%")
                    ->orWhere('subject', 'ilike', "%{$search}%");
            });
        }

        return response()->json([
            'data' => $query->get()->map(fn (HrTemplate $template) => $template->present())->values(),
            'meta' => $this->meta($channel),
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate($this->rules());
        $template = HrTemplate::query()->create($this->normalize($validated, isSystem: false));

        return response()->json([
            'message' => 'Template created.',
            'data' => $template->present(),
        ], Response::HTTP_CREATED);
    }

    public function show(HrTemplate $hrTemplate): JsonResponse
    {
        return response()->json(['data' => $hrTemplate->present()]);
    }

    public function update(Request $request, HrTemplate $hrTemplate): JsonResponse
    {
        $validated = $request->validate($this->rules($hrTemplate));
        $hrTemplate->update($this->normalize($validated, isSystem: $hrTemplate->is_system, existing: $hrTemplate));

        return response()->json([
            'message' => 'Template saved.',
            'data' => $hrTemplate->fresh()?->present(),
        ]);
    }

    public function destroy(HrTemplate $hrTemplate): JsonResponse
    {
        if ($hrTemplate->is_system) {
            return response()->json([
                'message' => 'System templates cannot be deleted. Disable them instead.',
            ], 422);
        }

        $hrTemplate->delete();

        return response()->json(['message' => 'Template deleted.']);
    }

    /**
     * @return array<string, mixed>
     */
    private function rules(?HrTemplate $existing = null): array
    {
        $channel = request()->input('channel', $existing?->channel);

        return [
            'channel' => ['required', 'string', Rule::in(HrTemplate::channels())],
            'category' => ['required', 'string', Rule::in(HrTemplate::categoriesFor((string) $channel))],
            'name' => ['required', 'string', 'max:255'],
            'subject' => [
                Rule::requiredIf((string) $channel === HrTemplate::CHANNEL_EMAIL),
                'nullable',
                'string',
                'max:255',
            ],
            'body' => ['required', 'string'],
            'is_active' => ['sometimes', 'boolean'],
        ];
    }

    /**
     * @param  array<string, mixed>  $validated
     * @return array<string, mixed>
     */
    private function normalize(array $validated, bool $isSystem, ?HrTemplate $existing = null): array
    {
        $channel = (string) $validated['channel'];

        return [
            'channel' => $channel,
            'category' => (string) $validated['category'],
            'name' => trim((string) $validated['name']),
            'subject' => $channel === HrTemplate::CHANNEL_EMAIL
                ? trim((string) ($validated['subject'] ?? ''))
                : null,
            'body' => (string) $validated['body'],
            'is_active' => (bool) ($validated['is_active'] ?? $existing?->is_active ?? true),
            'is_system' => $isSystem,
            'system_key' => $existing?->system_key,
        ];
    }

    /**
     * @return array{placeholders: list<array{token: string, label: string}>, categories: list<array{value: string, label: string}>}
     */
    private function meta(string $channel): array
    {
        $resolved = in_array($channel, HrTemplate::channels(), true)
            ? $channel
            : HrTemplate::CHANNEL_LETTER;

        $labels = [
            'bank' => 'Bank',
            'embassy' => 'Embassy',
            'leave' => 'Leave',
            'security' => 'Password / security',
            'recruiting' => 'Job application',
            'contracts' => 'Contracts',
            'other' => 'Other',
        ];

        return [
            'placeholders' => HrTemplate::placeholdersFor($resolved),
            'categories' => array_map(
                fn (string $value) => ['value' => $value, 'label' => $labels[$value] ?? ucfirst($value)],
                HrTemplate::categoriesFor($resolved),
            ),
        ];
    }
}
