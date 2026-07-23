# Employee import template

Excel template for loading sample employees and related records to exercise the full payroll system (HR → attendance → leave → payroll).

## Files

| File | Purpose |
|------|---------|
| [`templates/employee-import-template.xlsx`](./templates/employee-import-template.xlsx) | Multi-sheet template with 6 sample employees |
| [`templates/generate-employee-import-template.mjs`](./templates/generate-employee-import-template.mjs) | Regenerates the `.xlsx` |

```bash
node payroll-api/docs/templates/generate-employee-import-template.mjs
```

## Status

Use **Employees ▾ → Add Employee** for a single hire, or **Employees ▾ → Import Employees** to upload this workbook.

| Action | UI | API |
|--------|----|-----|
| Single create | `/employees/new` (Employees menu dropdown) | `POST /api/employees` |
| Download template | Import page → Download template | `GET /api/employees/import/template` |
| Bulk import | `/employees/import` (Employees menu dropdown) | `POST /api/employees/import` (JSON sheets) |

**ClockingLogs** can also be uploaded via Attendance → Import (same columns as `sample_biometric_logs.csv`, plus optional `punchType`).

v1 bulk import applies: **Employees**, **Employment**, **Compensation**, **ScheduledWork**, **ClockingLogs**. Banks / Contacts / Leave / SS sheets are ignored if present.

## Import order

1. **Employees** → `POST /employees`
2. **Employment** → employment details (department, worksite, GL account, pay period group)
3. **Compensation** → pay method / rates on the active employment detail
4. **Banks** (optional) — create banks first; none are seeded
5. **Contacts** (optional)
6. **LeaveEntitlements** (optional)
7. **SsBenefits** (optional)
8. **ScheduledWork** → `POST /scheduled-work` (explicit shifts for the sample week)
9. **ClockingLogs** → `POST /clocking-logs` or Attendance file import
10. **Process** → `POST /clocking-logs/process` with the sample week dates (not a sheet)

Lookups are by **name/code**, not database IDs. Valid values are listed on the **Lookups** sheet and match a freshly seeded database.

## Sample employees

Loaded from [`templates/employee-import-template.xlsx`](./templates/employee-import-template.xlsx) by `EmployeeImportTemplateSeeder` (replaces the old Faker employee seeders).

Populate a fresh database with:

```bash
./vendor/bin/sail artisan migrate:fresh --seed
```

## Sample attendance week

**ScheduledWork** and **ClockingLogs** cover weekdays **2026-05-25 … 2026-05-29** (Mon–Fri).

| Sheet | Columns (key) |
|-------|----------------|
| ScheduledWork | `employeeCode`, `departmentName`, `worksiteName`, `startDate`, `endDate`, `startTime`, `endTime`, `description`, `rate`, `includeLunchHour`, `lunchHourHours` |
| ClockingLogs | `biometricUserId`, `deviceId`, `punchDateTime`, `punchType` |

- `biometricUserId` = `Employees.code` (processing also accepts `internalId1` / `internalId2`)
- Devices: `DEV-01` (Head Office), `DEV-02` (Branch Office)
- All employees use timesheet template **Default Template**

## Minimum paths

**Payroll-ready employee:** Employees + Employment + Compensation  

**Attendance processing:** above + ScheduledWork (or Default Template) + ClockingLogs → process  

Align:

- `payrateFrequencyName` ↔ `payPeriodGroupName` (`Monthly` ↔ `Monthly Payroll`, `Biweekly` ↔ `Biweekly Payroll`)
- Hourly methods need `hourlyRate`; base methods need `yearlyRate`

## Notes

- Dates: `YYYY-MM-DD`; punch datetimes: `YYYY-MM-DD HH:mm:ss`
- Booleans: `true` / `false`
- Locality today is only **San Ignacio** in the seeder
- Payment method today is only **Bank Transfer**
- GL wage account for employment: **`6101`** (Regular Wages)
