# Attendance & Timesheet Module

## Raw Biometric Log Table

`clocking_log` stores only raw clock events:

- `id` (uuid)
- `biometricUserId` (string)
- `deviceId` (nullable string)
- `punchDateTime` (datetime)
- `punchType` (`IN`, `OUT`, or null for untyped event logs)
- `timestamps`

## API Endpoints

### 1) Receive attendance logs (API integration)

`POST /api/clocking-logs`

Example payload:

```json
{
  "logs": [
    {
      "biometricUserId": "EMP001",
      "deviceId": "DEV-01",
      "punchDateTime": "2026-05-24 08:01:10"
    },
    {
      "biometricUserId": "EMP001",
      "deviceId": "DEV-01",
      "punchDateTime": "2026-05-24 17:06:11"
    }
  ]
}
```

Single log payload is also accepted:

```json
{
  "biometricUserId": "EMP002",
  "deviceId": "DEV-02",
  "punchDateTime": "2026-05-24 08:10:00"
}
```

Paired punch rows are expanded into separate `IN` and `OUT` events:

```json
{
  "logs": [
    {
      "employeeCode": "EMP002",
      "date": "2026-05-24",
      "punchIn": "08:10:00",
      "punchOut": "17:22:08",
      "sitePunchIn": "DEV-01",
      "sitePunchOut": "DEV-02"
    }
  ]
}
```

### 2) Import biometric export file

`POST /api/clocking-logs/import` (multipart/form-data)

Fields:

- `file`: `.csv`, `.txt`, or `.xlsx`
- `hasHeader`: `1` or `0`
- `defaultDeviceId` (optional)

### 3) Process logs into timesheets

`POST /api/clocking-logs/process`

Example payload:

```json
{
  "payPeriodScheduleId": "8a444be5-7777-4f19-a513-17ed78cad588",
  "queue": false
}
```

Use `startDate` and `endDate` for an ad-hoc period. Leave `overtimeThresholdHours` blank to use each employee's
assigned work schedule; provide it only when applying a deliberate organization-wide override.

### 4) Query clocking logs

`GET /api/clocking-logs?startDate=2026-05-01&endDate=2026-05-31&biometricUserId=EMP001&deviceId=DEV-01`

### 5) Approve clean timesheets in a batch

`PATCH /api/timesheets/approval/bulk`

```json
{
  "timesheetIds": [
    "0f5cfcef-4e53-47a9-a252-62d27941f49b",
    "f8327aee-4dfb-4ebf-aaee-77bbb01be931"
  ],
  "approvalStatus": "APPROVED"
}
```

Batch approval is intentionally limited to pending timesheets without attendance warnings. Records with exceptions
must be reviewed and approved or rejected individually.

### 6) Query timesheets

`GET /api/timesheets?startDate=2026-05-01&endDate=2026-05-31&approvalStatus=PENDING&issuesOnly=1`

### 7) Query employee period summaries

`GET /api/timesheets/employee-summary?startDate=2026-05-01&endDate=2026-05-31`

Returns one row per employee with total, regular, overtime, holiday, and unpaid hours plus approval and exception
counts. Use the employee ID with the timesheet query endpoint to drill into the daily hour composition:

`GET /api/timesheets?employeeId={employeeId}&startDate=2026-05-01&endDate=2026-05-31`

### 8) Approve/reject timesheet

`PATCH /api/timesheets/{timesheetId}/approval`

```json
{
  "approvalStatus": "APPROVED",
  "remarks": "Checked against source logs."
}
```

## Sample Biometric File Format

### CSV with header

```csv
biometricUserId,deviceId,punchDateTime
EMP001,DEV-01,2026-05-24 08:01:10
EMP001,DEV-01,2026-05-24 17:06:11
EMP002,DEV-02,2026-05-24 08:10:00
EMP002,DEV-02,2026-05-24 17:22:08
```

### CSV without header

```csv
EMP001,DEV-01,2026-05-24 08:01:10
EMP001,DEV-01,2026-05-24 17:06:11
```

### CSV with punch-in and punch-out columns

```csv
employeeCode,date,punchIn,punchOut,sitePunchIn,sitePunchOut
EMP001,2026-05-24,08:01:10,12:00:00,DEV-01,DEV-01
EMP001,2026-05-24,13:00:00,17:06:11,DEV-01,DEV-02
```

## Employee Mapping Logic

`biometricUserId` maps to employee by matching (in order):

1. `employee.code`
2. `employee.internalId1`
3. `employee.internalId2`

## Processing Rules

- Group by `biometricUserId` + work date.
- Typed `IN` and `OUT` events are paired by direction.
- Untyped event logs are paired in chronological order.
- Mixed typed and untyped logs use the typed sequence, with untyped punches inferred and flagged for review.
- Multiple pairs are summed, so meal breaks and split shifts are excluded from hours worked.
- Clock-in and clock-out are taken from the first and last valid matched pair; unmatched punches do not contribute hours.
- Regular hours are capped at the schedule or supplied daily threshold, and all remaining worked time is overtime.
- Salary employees without punches receive scheduled regular hours.
- Hourly employees without punches receive scheduled unpaid hours.
- Approved unpaid leave creates unpaid scheduled hours for salary employees.
- Public holidays and overtime are identified in `workingStatus`.
- Missing or unpaired punches add warning text in `timesheet.remarks`.
- Duplicate raw logs are skipped during ingest.

## Operator Workflow

1. Upload a CSV or XLSX biometric export and correct validation issues in the editable preview.
2. Review the imported clocking events and select **Generate timesheets**.
3. Select a configured pay period, or provide an ad-hoc start and end date.
4. Leave the daily overtime override blank to use each employee's assigned schedule.
5. Review each employee's period total, then open the employee detail to verify the daily hour composition.
6. Resolve exceptions first, then batch approve clean pending workdays for payroll.
