<?php

namespace App\Modules\Payroll\Services;

use App\Models\Company;
use App\Models\ReportDocumentHeader;
use Illuminate\Support\Collection;
use InvalidArgumentException;

class ReportDocumentHeaderService
{
    /**
     * @return array{headers: list<array<string, mixed>>, fields: array<string, string>}
     */
    public function index(): array
    {
        $this->ensureSystemHeaders();

        return [
            'headers' => $this->headers()->map(fn (ReportDocumentHeader $header) => $this->present($header))->values()->all(),
            'fields' => $this->companyFields(),
        ];
    }

    /**
     * @param array<string, mixed> $canvas
     */
    public function create(string $name, array $canvas): ReportDocumentHeader
    {
        return ReportDocumentHeader::query()->create([
            'name' => $name,
            'kind' => 'custom',
            'is_system' => false,
            'canvas' => $this->normalizeCanvas($canvas),
        ]);
    }

    /**
     * @param array<string, mixed> $canvas
     */
    public function update(ReportDocumentHeader $header, string $name, array $canvas): ReportDocumentHeader
    {
        $header->update([
            'name' => $header->is_system ? $header->name : $name,
            'canvas' => $this->normalizeCanvas($canvas),
        ]);

        return $header->fresh() ?? $header;
    }

    public function delete(ReportDocumentHeader $header): void
    {
        if ($header->is_system) {
            throw new InvalidArgumentException('The company and GOB headers cannot be deleted.');
        }

        $header->delete();
    }

    /**
     * @return array<string, mixed>
     */
    public function present(ReportDocumentHeader $header): array
    {
        return [
            'id' => $header->id,
            'name' => $header->name,
            'kind' => $header->kind,
            'isSystem' => (bool) $header->is_system,
            'canvas' => $header->canvas,
        ];
    }

    /**
     * @return array<string, string>
     */
    public function companyFields(): array
    {
        $company = Company::query()->with('locality.district')->first();
        $locality = trim((string) ($company?->locality?->name ?? ''));
        $district = trim((string) ($company?->locality?->district?->name ?? ''));
        $place = trim(implode(', ', array_filter([$locality, $district])));
        $street = trim((string) ($company?->street ?? ''));
        $address = trim(implode(', ', array_filter([$street, $place])));

        return [
            'company.name' => trim((string) ($company?->legalName ?? '')) ?: 'Company',
            'company.alias' => trim((string) ($company?->alias ?? '')),
            'company.address' => $address,
            'company.street' => $street,
            'company.email' => trim((string) ($company?->email ?? '')),
            'company.phone' => trim((string) ($company?->phoneNumber1 ?? '')),
            'company.tin' => trim((string) ($company?->taxIdentificationNumber ?? '')),
            'company.socialSecurity' => trim((string) ($company?->socialSecurityNumber ?? '')),
            'company.logo' => (string) ($company?->logoUrl ?? ''),
        ];
    }

    /**
     * @return Collection<int, ReportDocumentHeader>
     */
    private function headers(): Collection
    {
        return ReportDocumentHeader::query()
            ->orderByRaw("CASE kind WHEN 'company' THEN 0 WHEN 'government' THEN 1 ELSE 2 END")
            ->orderBy('name')
            ->get();
    }

    private function ensureSystemHeaders(): void
    {
        foreach (ReportDocumentHeaderDefaults::all() as $header) {
            $exists = ReportDocumentHeader::query()
                ->where('kind', $header['kind'])
                ->where('is_system', true)
                ->exists();
            if ($exists) {
                continue;
            }

            ReportDocumentHeader::query()->create([
                'name' => $header['name'],
                'kind' => $header['kind'],
                'is_system' => true,
                'canvas' => $header['canvas'],
            ]);
        }
    }

    /**
     * @param array<string, mixed> $canvas
     * @return array<string, mixed>
     */
    private function normalizeCanvas(array $canvas): array
    {
        $width = (int) ($canvas['width'] ?? 0);
        $height = (int) ($canvas['height'] ?? 0);
        if ($width < 200 || $width > 2000 || $height < 40 || $height > 800) {
            throw new InvalidArgumentException('Header canvas size is out of range.');
        }

        $elements = $canvas['elements'] ?? [];
        if (! is_array($elements) || count($elements) > 40) {
            throw new InvalidArgumentException('A header can contain up to 40 elements.');
        }

        $normalized = [];
        foreach ($elements as $element) {
            if (! is_array($element)) {
                throw new InvalidArgumentException('Header element is invalid.');
            }
            $type = (string) ($element['type'] ?? '');
            if (! in_array($type, ['text', 'image'], true)) {
                throw new InvalidArgumentException('Header element type must be text or image.');
            }
            $normalized[] = [
                'id' => substr((string) ($element['id'] ?? ''), 0, 80) ?: (string) \Illuminate\Support\Str::uuid(),
                'type' => $type,
                'x' => $this->boundedNumber($element['x'] ?? 0, 0, 2000),
                'y' => $this->boundedNumber($element['y'] ?? 0, 0, 800),
                'width' => $this->boundedNumber($element['width'] ?? 40, 8, 2000),
                'height' => $this->boundedNumber($element['height'] ?? 16, 8, 800),
                'text' => substr((string) ($element['text'] ?? ''), 0, 500),
                'field' => isset($element['field']) ? substr((string) $element['field'], 0, 80) : null,
                'fontSize' => $this->boundedNumber($element['fontSize'] ?? 14, 8, 72),
                'fontWeight' => ($element['fontWeight'] ?? '') === 'bold' ? 'bold' : 'normal',
                'align' => in_array($element['align'] ?? '', ['left', 'center', 'right'], true) ? $element['align'] : 'left',
                'color' => $this->color((string) ($element['color'] ?? '#000000')),
            ];
        }

        return [
            'width' => $width,
            'height' => $height,
            'background' => $this->color((string) ($canvas['background'] ?? '#ffffff')),
            'elements' => $normalized,
        ];
    }

    private function boundedNumber(mixed $value, int $min, int $max): int
    {
        return max($min, min($max, (int) $value));
    }

    private function color(string $value): string
    {
        return preg_match('/^#[0-9a-fA-F]{6}$/', $value) === 1 ? $value : '#000000';
    }
}
