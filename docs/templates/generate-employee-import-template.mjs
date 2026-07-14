/**
 * Generates payroll-api/docs/templates/employee-import-template.xlsx
 *
 * Usage (from repo root or payroll-web):
 *   node payroll-api/docs/templates/generate-employee-import-template.mjs
 */
import { createRequire } from 'node:module';
import { dirname, join } from 'node:path';
import { fileURLToPath } from 'node:url';
import { mkdirSync } from 'node:fs';

const __dirname = dirname(fileURLToPath(import.meta.url));
const require = createRequire(join(__dirname, '../../../payroll-web/package.json'));
const XLSX = require('xlsx');

const outPath = join(__dirname, 'employee-import-template.xlsx');

function sheetFromRows(rows) {
  const header = rows[0] ?? [''];
  const worksheet = XLSX.utils.aoa_to_sheet(rows);
  worksheet['!cols'] = header.map((cell) => ({
    wch: Math.min(40, Math.max(14, String(cell ?? '').length + 2)),
  }));
  return worksheet;
}

/** Weekdays Mon–Fri used for scheduler + timesheet samples (matches sample_biometric_logs week). */
const SAMPLE_WEEKDAYS = [
  '2026-05-25',
  '2026-05-26',
  '2026-05-27',
  '2026-05-28',
  '2026-05-29',
];

/**
 * @param {Array<[string, string, string, string, string, string, string, string, string, string, string]>} daySpecs
 * employeeCode, departmentName, worksiteName, startTime, endTime, description, rate, includeLunchHour, lunchHourHours
 */
function buildScheduledWorkRows(daySpecs) {
  const headers = [
    'employeeCode',
    'departmentName',
    'worksiteName',
    'startDate',
    'endDate',
    'startTime',
    'endTime',
    'description',
    'rate',
    'includeLunchHour',
    'lunchHourHours',
  ];
  const rows = [headers];
  for (const date of SAMPLE_WEEKDAYS) {
    for (const spec of daySpecs) {
      const [
        employeeCode,
        departmentName,
        worksiteName,
        startTime,
        endTime,
        description,
        rate,
        includeLunchHour,
        lunchHourHours,
      ] = spec;
      rows.push([
        employeeCode,
        departmentName,
        worksiteName,
        date,
        date,
        startTime,
        endTime,
        description,
        rate,
        includeLunchHour,
        lunchHourHours,
      ]);
    }
  }
  return rows;
}

/**
 * Build IN/OUT (and optional lunch) punches for clocking employees.
 * @param {Array<{ code: string, deviceId: string, inTime: string, outTime: string, lunch?: { out: string, in: string } | null, skipDates?: string[] }>} specs
 */
function buildClockingLogRows(specs) {
  const headers = ['biometricUserId', 'deviceId', 'punchDateTime', 'punchType'];
  const rows = [headers];

  for (const date of SAMPLE_WEEKDAYS) {
    for (const spec of specs) {
      if (spec.skipDates?.includes(date)) {
        continue;
      }
      rows.push([spec.code, spec.deviceId, `${date} ${spec.inTime}`, 'IN']);
      if (spec.lunch) {
        rows.push([spec.code, spec.deviceId, `${date} ${spec.lunch.out}`, 'OUT']);
        rows.push([spec.code, spec.deviceId, `${date} ${spec.lunch.in}`, 'IN']);
      }
      rows.push([spec.code, spec.deviceId, `${date} ${spec.outTime}`, 'OUT']);
    }
  }

  return rows;
}

const instructions = [
  ['Employee import template — end-to-end test data'],
  [''],
  ['Purpose'],
  ['Populate sample employees (and related records) to exercise HR, attendance, leave, and payroll.'],
  ['There is no bulk employee importer yet; use this file as the canonical column contract / test fixture.'],
  [''],
  ['How to use'],
  ['1. Ensure the database is seeded (php artisan db:seed).'],
  ['2. Keep lookup values from the Lookups sheet (resolve by name/code, not database IDs).'],
  ['3. Import / create in order:'],
  ['   Employees → Employment → Compensation → Banks → Contacts → LeaveEntitlements → SsBenefits'],
  ['   → ScheduledWork → ClockingLogs → then POST /clocking-logs/process'],
  ['4. Employee codes in this file start at 100001 to avoid colliding with seeded codes 000001–000100.'],
  ['5. Create banks listed on the Banks sheet before importing bank accounts (no Bank seeder today).'],
  ['6. Dates use ISO format YYYY-MM-DD. Times use HH:mm or YYYY-MM-DD HH:mm:ss. Booleans use true/false.'],
  ['7. ClockingLogs.biometricUserId must match Employees.code (also works with internalId1 / internalId2).'],
  [''],
  ['Sample attendance week'],
  ['ScheduledWork + ClockingLogs cover weekdays 2026-05-25 … 2026-05-29 (Mon–Fri).'],
  ['Devices: DEV-01 = Head Office, DEV-02 = Branch Office.'],
  [''],
  ['Minimum path for payroll / timesheets'],
  ['Employees + Employment + Compensation for the same employeeCode.'],
  ['For attendance processing also add ScheduledWork (or rely on Default Template) + ClockingLogs, then process.'],
  [''],
  ['Sheets'],
  ['Instructions — this page'],
  ['Employees — person + employee master (POST /employees)'],
  ['Employment — active contract / employment detail'],
  ['Compensation — pay method and rates (linked to employment by employeeCode)'],
  ['Banks — employee bank accounts (optional; requires banks to exist)'],
  ['Contacts — emergency / dependent contacts (optional)'],
  ['LeaveEntitlements — per-contract leave overrides (optional)'],
  ['SsBenefits — social security benefit status (optional)'],
  ['ScheduledWork — calendar / shift assignments (POST /scheduled-work)'],
  ['ClockingLogs — biometric punches (POST /clocking-logs or Attendance import)'],
  ['Lookups — valid seeded reference values'],
];

const employeeHeaders = [
  'code',
  'honorificName',
  'firstName',
  'middleName',
  'lastName',
  'maidenName',
  'birthdate',
  'address1',
  'address2',
  'localityName',
  'phone',
  'email',
  'genderName',
  'socialSecurityNumber',
  'socialSecurityExpirationDate',
  'taxIdentificationNumber',
  'passportNumber',
  'votersId',
  'citizenshipStatusName',
  'nationalityName',
  'paymentMethodName',
  'employeeStatusName',
  'employmentStatusName',
  'timesheetTemplateName',
  'internalId1',
  'internalId2',
  'notes',
  'health',
  'unionMembership',
];

const employees = [
  employeeHeaders,
  [
    '100001', 'Ms', 'Maya', 'Ann', 'Garcia', '', '1990-05-15', '12 Burns Ave', '', 'San Ignacio',
    '8241001', 'maya.garcia@example.com', 'Female', '10000101', '2030-12-31', 'TIN100001', 'P100001', 'V100001',
    'Belizean Citizen', 'Belize', 'Bank Transfer', 'active', 'Full-Time', 'Default Template', 'E2E-A01', '',
    'Hourly OT operations sample', 'Good', '',
  ],
  [
    '100002', 'Mr', 'Luis', 'Carlos', 'Hernandez', '', '1985-11-02', '45 Joseph Andrews Dr', 'Apt 2', 'San Ignacio',
    '8241002', 'luis.hernandez@example.com', 'Male', '10000202', '2029-06-30', 'TIN100002', 'P100002', 'V100002',
    'Belizean Citizen', 'Belize', 'Bank Transfer', 'active', 'Full-Time', 'Default Template', 'E2E-A02', '',
    'Salaried finance sample', 'Good', '',
  ],
  [
    '100003', 'Mrs', 'Alicia', '', 'Reyes', 'Castillo', '1993-03-21', '8 Bullet Tree Rd', '', 'San Ignacio',
    '8241003', 'alicia.reyes@example.com', 'Female', '10000303', '2031-01-15', 'TIN100003', 'P100003', 'V100003',
    'Permanent Resident', 'Belize', 'Bank Transfer', 'active', 'Full-Time', 'Default Template', 'E2E-A03', '',
    'Biweekly sales sample', 'Good', '',
  ],
  [
    '100004', 'Mr', 'Omar', 'J', 'Perez', '', '1998-07-09', '22 Far West St', '', 'San Ignacio',
    '8241004', 'omar.perez@example.com', 'Male', '10000404', '', 'TIN100004', '', '',
    'Work Permit Holder', 'Belize', 'Bank Transfer', 'active', 'Part-Time', 'Default Template', 'E2E-A04', '',
    'Part-time HR sample', 'Good', '',
  ],
  [
    '100005', 'Dr', 'Nina', 'Rose', 'Santos', '', '1982-01-30', '3 Hospital St', '', 'San Ignacio',
    '8241005', 'nina.santos@example.com', 'Female', '10000505', '2028-09-01', 'TIN100005', 'P100005', 'V100005',
    'Belizean Citizen', 'Belize', 'Bank Transfer', 'active', 'Full-Time', 'Default Template', 'E2E-A05', '',
    'IT supervisor / salaried OT sample', 'Good', 'BITU',
  ],
  [
    '100006', 'Mr', 'Diego', '', 'Lopez', '', '1995-12-12', '17 Savannah St', '', 'San Ignacio',
    '8241006', 'diego.lopez@example.com', 'Male', '10000606', '2032-03-20', 'TIN100006', '', '',
    'Belizean Citizen', 'Belize', 'Bank Transfer', 'active', 'Probation', 'Default Template', 'E2E-A06', '',
    'Probation hourly no-OT sample', 'Good', '',
  ],
];

const employmentHeaders = [
  'employeeCode',
  'departmentName',
  'worksiteName',
  'accountCode',
  'contractTypeName',
  'payPeriodGroupName',
  'startDate',
  'endDate',
  'jobTitle',
  'requiresClocking',
  'isActive',
  'benefits',
  'employmentPolicies',
];

const employment = [
  employmentHeaders,
  ['100001', 'Operations', 'Head Office', '6101', 'Permanent', 'Monthly Payroll', '2026-01-01', '', 'Operations Associate', 'true', 'true', 'Standard health and pension', 'Company handbook applies'],
  ['100002', 'Finance', 'Head Office', '6101', 'Permanent', 'Monthly Payroll', '2025-06-01', '', 'Payroll Accountant', 'false', 'true', 'Standard health and pension', 'Company handbook applies'],
  ['100003', 'Sales', 'Branch Office', '6101', 'Permanent', 'Biweekly Payroll', '2026-02-01', '', 'Sales Representative', 'true', 'true', 'Commission eligible', 'Sales policy applies'],
  ['100004', 'Human Resources', 'Head Office', '6101', 'Fixed-Term Contract', 'Monthly Payroll', '2026-03-01', '2027-02-28', 'HR Assistant', 'true', 'true', 'Pro-rated benefits', 'Fixed-term contract'],
  ['100005', 'IT', 'Head Office', '6101', 'Permanent', 'Monthly Payroll', '2024-01-15', '', 'IT Manager', 'false', 'true', 'Management benefits', 'Company handbook applies'],
  ['100006', 'Operations', 'Branch Office', '6101', 'Temporary', 'Biweekly Payroll', '2026-04-01', '2026-09-30', 'Warehouse Clerk', 'true', 'true', 'None', 'Temporary assignment'],
];

const compensationHeaders = [
  'employeeCode',
  'effectiveDate',
  'endDate',
  'compensationMethod',
  'reasonType',
  'hourlyRate',
  'yearlyRate',
  'standardWeeklyHours',
  'requiresClocking',
  'isActive',
  'payscale',
  'payscalePoint',
  'reasonNote',
];

const compensation = [
  compensationHeaders,
  ['100001', '2026-01-01', '', 'HOURLY_OT', 'INITIAL', '15.50', '', '40', 'true', 'true', 'OPS', 'A1', 'Initial hire'],
  ['100002', '2025-06-01', '', 'BASE_NO_OT', 'INITIAL', '', '42000.00', '40', 'false', 'true', 'FIN', 'B2', 'Initial hire'],
  ['100003', '2026-02-01', '', 'HOURLY_OT', 'INITIAL', '18.75', '', '40', 'true', 'true', 'SAL', 'A2', 'Initial hire'],
  ['100004', '2026-03-01', '', 'HOURLY_NO_OT', 'INITIAL', '14.00', '', '20', 'true', 'true', 'HR', 'P1', 'Part-time hire'],
  ['100005', '2024-01-15', '', 'BASE_OT', 'INITIAL', '', '65000.00', '40', 'false', 'true', 'IT', 'M1', 'Manager hire'],
  ['100006', '2026-04-01', '', 'HOURLY_NO_OT', 'INITIAL', '13.25', '', '40', 'true', 'true', 'OPS', 'T1', 'Temporary hire'],
];

const banks = [
  ['employeeCode', 'bankName', 'bankCode', 'accountTypeName', 'accountNumber', 'isPrimary', 'notes'],
  ['100001', 'Atlantic Bank', 'ATL', 'Checking', '0010010001', 'true', 'Primary payroll account'],
  ['100002', 'Atlantic Bank', 'ATL', 'Savings', '0010010002', 'true', 'Primary payroll account'],
  ['100003', 'Belize Bank', 'BLZ', 'Payroll', '0020020003', 'true', 'Primary payroll account'],
  ['100004', 'Atlantic Bank', 'ATL', 'Checking', '0010010004', 'true', 'Primary payroll account'],
  ['100005', 'Heritage Bank', 'HER', 'Checking', '0030030005', 'true', 'Primary payroll account'],
  ['100006', 'Belize Bank', 'BLZ', 'Checking', '0020020006', 'true', 'Primary payroll account'],
];

const contacts = [
  [
    'employeeCode', 'firstName', 'lastName', 'phoneNumber1', 'email', 'address1', 'localityName',
    'relationshipName', 'isDependent', 'notes',
  ],
  ['100001', 'Juan', 'Garcia', '8249001', 'juan.garcia@example.com', '12 Burns Ave', 'San Ignacio', 'Spouse', 'true', ''],
  ['100002', 'Maria', 'Hernandez', '8249002', 'maria.hernandez@example.com', '45 Joseph Andrews Dr', 'San Ignacio', 'Spouse', 'true', ''],
  ['100003', 'Carlos', 'Reyes', '8249003', 'carlos.reyes@example.com', '8 Bullet Tree Rd', 'San Ignacio', 'Parent', 'false', 'Emergency contact'],
  ['100005', 'Elena', 'Santos', '8249005', 'elena.santos@example.com', '3 Hospital St', 'San Ignacio', 'Child', 'true', ''],
];

const leaveEntitlements = [
  ['employeeCode', 'leaveTypeCode', 'annualEntitlementDays', 'accrualMethod', 'useOrgDefault'],
  ['100001', 'VACATION', '20', 'MONTHLY', 'false'],
  ['100001', 'SICK', '10', 'UPFRONT', 'false'],
  ['100002', 'VACATION', '25', 'UPFRONT', 'false'],
  ['100002', 'SICK', '', '', 'true'],
  ['100003', 'VACATION', '15', 'MONTHLY', 'false'],
  ['100005', 'VACATION', '30', 'UPFRONT', 'false'],
  ['100005', 'PTO', '5', 'NONE', 'false'],
];

const ssBenefits = [
  ['employeeCode', 'is_receiving_benefit', 'effective_from', 'effective_to', 'ss_benefit_type_name', 'notes'],
  ['100001', 'false', '2026-01-01', '', '', 'Not receiving SS benefit'],
  ['100002', 'false', '2025-06-01', '', '', ''],
  ['100005', 'false', '2024-01-15', '', 'Pension', 'Eligible later'],
];

// Explicit shifts for clockers + salaried staff (template fallback also applies).
const scheduledWork = buildScheduledWorkRows([
  ['100001', 'Operations', 'Head Office', '08:00', '16:00', 'Morning shift', '1', 'true', '1'],
  ['100002', 'Finance', 'Head Office', '09:00', '17:00', 'Day shift (salaried)', '1', 'false', '1'],
  ['100003', 'Sales', 'Branch Office', '09:00', '17:00', 'Day shift', '1', 'true', '1'],
  ['100004', 'Human Resources', 'Head Office', '09:00', '13:00', 'Part-time morning shift', '1', 'false', '1'],
  ['100005', 'IT', 'Head Office', '09:00', '17:00', 'Manager day shift', '1', 'false', '1'],
  ['100006', 'Operations', 'Branch Office', '08:00', '16:00', 'Warehouse shift', '1', 'false', '1'],
]);

// Punches for employees with requiresClocking=true (plus one late OUT to exercise OT).
const clockingLogs = buildClockingLogRows([
  {
    code: '100001',
    deviceId: 'DEV-01',
    inTime: '08:01:10',
    outTime: '16:06:11',
    lunch: { out: '12:00:00', in: '13:00:00' },
  },
  {
    code: '100003',
    deviceId: 'DEV-02',
    inTime: '08:55:00',
    outTime: '17:22:08',
    lunch: { out: '12:05:00', in: '13:00:00' },
  },
  {
    code: '100004',
    deviceId: 'DEV-01',
    inTime: '09:02:00',
    outTime: '13:05:00',
    lunch: null,
  },
  {
    code: '100006',
    deviceId: 'DEV-02',
    inTime: '07:58:30',
    outTime: '16:01:45',
    lunch: null,
  },
]);

const lookups = [
  ['Lookup', 'Valid values (seeded)', 'Used by'],
  ['genderName', 'Male | Female', 'Employees'],
  ['honorificName', 'Mr | Mrs | Miss | Ms | Dr | Prof | Professor | Rev | …', 'Employees'],
  ['localityName', 'San Ignacio', 'Employees, Contacts'],
  ['citizenshipStatusName', 'Belizean Citizen | Permanent Resident | Work Permit Holder | Temporary Resident | Visitor | Non-Resident | Refugee | Stateless', 'Employees'],
  ['nationalityName', 'Belize (nationalityName = Belizean; use country name Belize)', 'Employees'],
  ['paymentMethodName', 'Bank Transfer', 'Employees'],
  ['employeeStatusName', 'active | inactive | suspended | fired | retired', 'Employees'],
  ['employmentStatusName', 'Full-Time | Part-Time | Probation | On Leave | Terminated', 'Employees'],
  ['timesheetTemplateName', 'Default Template (Mon–Fri 08:00–17:00)', 'Employees'],
  ['departmentName', 'Operations | Finance | Human Resources | IT | Sales (+ child departments)', 'Employment, ScheduledWork'],
  ['worksiteName', 'Head Office | Branch Office', 'Employment, ScheduledWork'],
  ['accountCode', '6101 (Regular Wages) — also 6100–6106, 6199', 'Employment'],
  ['contractTypeName', 'Permanent | Fixed-Term Contract | Temporary | Casual | Consultant | Intern', 'Employment'],
  ['payPeriodGroupName', 'Monthly Payroll | Biweekly Payroll | Default Pay Period', 'Employment'],
  ['compensationMethod', 'HOURLY_NO_OT | HOURLY_OT | BASE_NO_OT | BASE_OT', 'Compensation'],
  ['reasonType', 'INITIAL | INCREMENT | PROMOTION | EVALUATION | CORRECTION | OTHER', 'Compensation'],
  ['leaveTypeCode', 'VACATION | SICK | SICK_UNCERTIFIED | PTO | PATERNITY | PROFESSIONAL | WITHOUT_PAY', 'LeaveEntitlements'],
  ['accrualMethod', 'UPFRONT | MONTHLY | NONE', 'LeaveEntitlements'],
  ['ss_benefit_type_name', 'Pension | Disability | Survivor | Other', 'SsBenefits'],
  ['relationshipName', 'Spouse | Parent | Child | Sibling | Grandparent | …', 'Contacts'],
  ['accountTypeName', 'Checking | Savings | Money Market | Payroll', 'Banks'],
  ['bankName / bankCode', 'Not seeded — create Atlantic Bank (ATL), Belize Bank (BLZ), Heritage Bank (HER) first', 'Banks'],
  ['biometricUserId', 'Employees.code (preferred) | internalId1 | internalId2', 'ClockingLogs'],
  ['deviceId', 'DEV-01 (Head Office) | DEV-02 (Branch Office) — arbitrary labels', 'ClockingLogs'],
  ['punchType', 'IN | OUT | blank', 'ClockingLogs'],
  ['ScheduledWork times', 'startTime/endTime HH:mm (defaults 09:00 / 17:00 if omitted)', 'ScheduledWork'],
];

const workbook = XLSX.utils.book_new();
XLSX.utils.book_append_sheet(workbook, sheetFromRows(instructions), 'Instructions');
XLSX.utils.book_append_sheet(workbook, sheetFromRows(employees), 'Employees');
XLSX.utils.book_append_sheet(workbook, sheetFromRows(employment), 'Employment');
XLSX.utils.book_append_sheet(workbook, sheetFromRows(compensation), 'Compensation');
XLSX.utils.book_append_sheet(workbook, sheetFromRows(banks), 'Banks');
XLSX.utils.book_append_sheet(workbook, sheetFromRows(contacts), 'Contacts');
XLSX.utils.book_append_sheet(workbook, sheetFromRows(leaveEntitlements), 'LeaveEntitlements');
XLSX.utils.book_append_sheet(workbook, sheetFromRows(ssBenefits), 'SsBenefits');
XLSX.utils.book_append_sheet(workbook, sheetFromRows(scheduledWork), 'ScheduledWork');
XLSX.utils.book_append_sheet(workbook, sheetFromRows(clockingLogs), 'ClockingLogs');
XLSX.utils.book_append_sheet(workbook, sheetFromRows(lookups), 'Lookups');

mkdirSync(__dirname, { recursive: true });
XLSX.writeFile(workbook, outPath);
console.log(`Wrote ${outPath}`);
console.log(`  ScheduledWork rows: ${scheduledWork.length - 1}`);
console.log(`  ClockingLogs rows: ${clockingLogs.length - 1}`);
