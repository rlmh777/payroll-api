# Employee import template

Excel template for loading sample employees and related records to exercise the full payroll system (HR → attendance → leave → payroll).

## Files

| File | Purpose |
|------|---------|
| [`templates/employee-import-template.xlsx`](./templates/employee-import-template.xlsx) | Multi-sheet template with 20 sample employees |
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

Bulk import applies: **Employees** (including supervisor/lead), **Employment**, **Compensation**, **Banks**, **Contacts**, **LeaveEntitlements**, **SsBenefits**, **PoolPoints**, **Allowances**, **Deductions**, **DepartmentHeads**, **ScheduledWork**, **ClockingLogs**.

## Import order

1. **Employees** → create people, then assign `supervisorEmployeeCode` / `leadEmployeeCode`
2. **Employment** → employment details (department, worksite, GL account, pay period group)
3. **Compensation** → pay method / rates on the active employment detail
4. **Banks** (optional) — bank records are created from `bankName` if missing
5. **Contacts** (optional)
6. **LeaveEntitlements** (optional)
7. **SsBenefits** (optional)
8. **PoolPoints** (optional) — share / tip pool points (`poolTypeName` = Shares or Tips)
9. **Allowances** (optional) — default other payments (Commission, Web Assist, Trips, …)
10. **Deductions** (optional) — default deductions (EDC, SMCU Loan, NBB Loan, Staff Charge, …)
11. **DepartmentHeads** (optional) — appoint current department head
12. **ScheduledWork** → `POST /scheduled-work` (explicit shifts for the sample week)
13. **ClockingLogs** → `POST /clocking-logs` or Attendance file import
14. **Process** → `POST /clocking-logs/process` with the sample week dates (not a sheet)

Lookups are by **name/code**, not database IDs. Valid values are listed on the **Lookups** sheet and match a freshly seeded database.

## Sample employees

Loaded from [`templates/employee-import-template.xlsx`](./templates/employee-import-template.xlsx) by `EmployeeImportTemplateSeeder` (replaces the old Faker employee seeders).

Populate a fresh database with:

```bash
./vendor/bin/sail artisan migrate:fresh --seed
```

## Sample attendance

**ClockingLogs** cover **2026-06-29 … 2026-07-12**. **ScheduledWork** is optional when the Default Template applies.

| Sheet | Columns (key) |
|-------|----------------|
| ScheduledWork | `employeeCode`, `departmentName`, `worksiteName`, `startDate`, `endDate`, `startTime`, `endTime`, `description`, `rate`, `includeLunchHour`, `lunchHourHours` |
| ClockingLogs | `biometricUserId`, `deviceId`, `punchDateTime`, `punchType` |

- `biometricUserId` = `Employees.code` (processing also accepts `internalId1` / `internalId2`)
- Devices: `DEV-01` (Business Office), `DEV-02` (Resort), `DEV-03` (Guava Limb Café)
- All employees use timesheet template **Default Template**
- **PoolPoints**: `Shares` uses the share count; `Tips` uses `points = 1` for participation (dollar tips are period earnings, not points)
- **Allowances** / **Deductions**: one row per named amount (Commission, EDC, SMCU Loan, …)
- **DepartmentHeads**: appoint the current head; mid-period department changes belong in `Employees.notes`

## Minimum paths

**Payroll-ready employee:** Employees + Employment + Compensation  

**Complete profile:** above + supervisor, banks, contacts, leave, SS, pool shares, allowances, deductions, department heads  

**Attendance processing:** payroll-ready + ScheduledWork (or Default Template) + ClockingLogs → process  

Align:

- `payrateFrequencyName` ↔ `payPeriodGroupName` (`Monthly` ↔ `Monthly Payroll`, `Biweekly` ↔ `Biweekly Payroll`)
- Hourly methods need `hourlyRate`; base methods need `yearlyRate`; `DAILY_RATE` needs `dailyRate`

## Notes

- Dates: `YYYY-MM-DD`; punch datetimes: `YYYY-MM-DD HH:mm:ss`
- Booleans: `true` / `false`
- Locality today is only **San Ignacio** in the seeder
- Payment method today is only **Bank Transfer**
- GL wage account for employment: **`6101`** (Regular Wages)
