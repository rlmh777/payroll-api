<?php

namespace Database\Seeders;

use App\Models\Department;
use App\Models\District;
use App\Models\Locality;
use App\Models\Worksite;
use App\Modules\Hr\Services\Employee\EmployeeImportService;
use Illuminate\Database\Seeder;
use Illuminate\Support\Str;
use Symfony\Component\Process\Process;

class EmployeeImportTemplateSeeder extends Seeder
{
    public function run(): void
    {
        $xlsxPath = base_path('docs/templates/employee-import-template.xlsx');
        if (! is_file($xlsxPath)) {
            $this->command?->error("Employee import template not found: {$xlsxPath}");

            return;
        }

        $this->ensureReferenceData();

        $payload = $this->parseWorkbook($xlsxPath);
        if ($payload === null) {
            return;
        }

        $payload = $this->normalizePayload($payload);

        $result = app(EmployeeImportService::class)->import($payload);

        $this->command?->info(sprintf(
            'Imported employees from template: created=%d employment=%d compensation=%d scheduled=%d clocking=%d timesheets=%d failed=%d',
            $result['summary']['employeesCreated'] ?? 0,
            $result['summary']['employmentCreated'] ?? 0,
            $result['summary']['compensationCreated'] ?? 0,
            $result['summary']['scheduledWorkCreated'] ?? 0,
            $result['summary']['clockingLogsCreated'] ?? 0,
            $result['summary']['timesheetsCreated'] ?? 0,
            $result['summary']['failed'] ?? 0,
        ));

        foreach (array_slice($result['errors'] ?? [], 0, 25) as $error) {
            $this->command?->warn(sprintf(
                '[%s row %s code %s] %s',
                $error['sheet'] ?? '?',
                $error['row'] ?? '?',
                $error['code'] ?? '—',
                $error['message'] ?? '',
            ));
        }

        if (($result['summary']['failed'] ?? 0) > 0) {
            $this->command?->warn('Employee import completed with errors. See messages above.');
        }
    }

    private function ensureReferenceData(): void
    {
        // Keep in sync with DepartmentSeeder / current org chart.
        $operations = Department::firstOrCreate(['name' => 'Operations'], ['parentId' => null]);
        $finance = Department::firstOrCreate(['name' => 'Finance'], ['parentId' => null]);
        $hr = Department::firstOrCreate(['name' => 'Human Resources'], ['parentId' => null]);
        $it = Department::firstOrCreate(['name' => 'IT'], ['parentId' => null]);
        $sales = Department::firstOrCreate(['name' => 'Sales'], ['parentId' => null]);
        Department::firstOrCreate(['name' => 'Marketing'], ['parentId' => null]);

        foreach ([
            'Bar',
            'Belize Rainforest Retreat',
            'Dining',
            'Gardeners',
            'Guest Services',
            'Kitchen',
            'Natural History Center',
            'Security',
            'Staff Kitchen',
            'Storeroom',
            'Tours',
        ] as $name) {
            Department::firstOrCreate(['name' => $name], ['parentId' => null]);
        }

        Department::firstOrCreate(['name' => 'Payroll'], ['parentId' => $finance->id]);
        Department::firstOrCreate(['name' => 'Accounting'], ['parentId' => $finance->id]);
        Department::firstOrCreate(['name' => 'Recruiting'], ['parentId' => $hr->id]);
        Department::firstOrCreate(['name' => 'Support'], ['parentId' => $operations->id]);
        Department::firstOrCreate(['name' => 'Infrastructure'], ['parentId' => $it->id]);
        Department::firstOrCreate(['name' => 'Field Sales'], ['parentId' => $sales->id]);

        $locality = Locality::query()->whereRaw('LOWER(name) = ?', ['san ignacio'])->first()
            ?? Locality::query()->first();

        if ($locality) {
            $town = Locality::query()->whereRaw('LOWER(name) = ?', ['san ignacio town'])->first();
            if (! $town) {
                Locality::create([
                    'id' => (string) Str::uuid(),
                    'name' => 'San Ignacio Town',
                    'districtId' => $locality->districtId ?? District::query()->value('id'),
                ]);
            }
        }

        if ($locality) {
            foreach ([
                ['name' => 'Head Office', 'address1' => '1 Administration Drive'],
                ['name' => 'Branch Office', 'address1' => '45 Commerce Street'],
                ['name' => 'Business Office', 'address1' => 'Business Office'],
                ['name' => 'Guava Limb Café', 'address1' => 'Guava Limb Café'],
                ['name' => 'Resort', 'address1' => 'Resort'],
            ] as $site) {
                Worksite::updateOrCreate(
                    ['name' => $site['name']],
                    [
                        'address1' => $site['address1'],
                        'address2' => null,
                        'localityId' => $locality->id,
                    ],
                );
            }
        }
    }

    /**
     * Fill sparse template rows so required person fields exist.
     *
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    private function normalizePayload(array $payload): array
    {
        $employees = [];
        foreach ($payload['employees'] ?? [] as $row) {
            if (! is_array($row)) {
                continue;
            }

            $row['localityName'] = filled($row['localityName'] ?? null) ? $row['localityName'] : 'San Ignacio';
            $row['birthdate'] = filled($row['birthdate'] ?? null) ? $row['birthdate'] : '1990-01-01';
            $row['address1'] = filled($row['address1'] ?? null) ? $row['address1'] : 'Address pending';
            $row['genderName'] = filled($row['genderName'] ?? null) ? $row['genderName'] : 'Male';
            $row['paymentMethodName'] = filled($row['paymentMethodName'] ?? null) ? $row['paymentMethodName'] : 'Bank Transfer';
            $row['socialSecurityNumber'] = filled($row['socialSecurityNumber'] ?? null)
                ? $row['socialSecurityNumber']
                : ('SS'.($row['code'] ?? Str::upper(Str::random(6))));
            $employees[] = $row;
        }
        $payload['employees'] = $employees;

        return $payload;
    }

    /**
     * @return array<string, mixed>|null
     */
    private function parseWorkbook(string $xlsxPath): ?array
    {
        $jsonPath = database_path('seeders/data/employee-import-payload.json');
        if (is_file($jsonPath)) {
            $payload = json_decode((string) file_get_contents($jsonPath), true);
            if (is_array($payload)) {
                $this->command?->info('Loading employee import payload from database/seeders/data/employee-import-payload.json');

                return $payload;
            }
        }

        $script = base_path('docs/templates/parse-employee-import-workbook.mjs');
        $webRoot = dirname(base_path()).'/payroll-web';
        $xlsxModuleDir = $webRoot.'/node_modules/xlsx';

        if (! is_file($script)) {
            $this->command?->error("Missing parse script: {$script}");

            return null;
        }

        if (! is_dir($xlsxModuleDir)) {
            $this->command?->error(
                "Missing xlsx module at {$xlsxModuleDir} and no prebuilt JSON payload. ".
                'Run: node docs/templates/parse-employee-import-workbook.mjs docs/templates/employee-import-template.xlsx > database/seeders/data/employee-import-payload.json'
            );

            return null;
        }

        $process = new Process([
            'node',
            $script,
            $xlsxPath,
        ], base_path(), [
            'NODE_PATH' => $webRoot.'/node_modules',
        ]);
        $process->setTimeout(120);
        $process->run();

        if (! $process->isSuccessful()) {
            $this->command?->error('Failed to parse employee import workbook:');
            $this->command?->error($process->getErrorOutput() ?: $process->getOutput());

            return null;
        }

        $json = trim($process->getOutput());
        $payload = json_decode($json, true);
        if (! is_array($payload)) {
            $this->command?->error('Employee import workbook parser returned invalid JSON.');

            return null;
        }

        return $payload;
    }
}
