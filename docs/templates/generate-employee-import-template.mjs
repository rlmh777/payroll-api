/**
 * Generates payroll-api/docs/templates/employee-import-template.xlsx
 * Sample rows come from database/seeders/data/employee-import-payload.json.
 *
 * Usage (from repo root or payroll-web):
 *   node payroll-api/docs/templates/generate-employee-import-template.mjs
 */
import { createRequire } from 'node:module';
import { dirname, join } from 'node:path';
import { fileURLToPath } from 'node:url';
import { mkdirSync, readFileSync } from 'node:fs';

const __dirname = dirname(fileURLToPath(import.meta.url));
const require = createRequire(join(__dirname, '../../../payroll-web/package.json'));
const XLSX = require('xlsx');

const outPath = join(__dirname, 'employee-import-template.xlsx');
const payloadPath = join(__dirname, '../../database/seeders/data/employee-import-payload.json');
const payload = JSON.parse(readFileSync(payloadPath, 'utf8'));

function cellValue(value) {
  if (value === null || value === undefined) {
    return '';
  }
  if (typeof value === 'boolean') {
    return value ? 'true' : 'false';
  }
  return value;
}

function sheetFromObjects(objects, headers) {
  const rows = [
    headers,
    ...(objects ?? []).map((row) => headers.map((key) => cellValue(row?.[key]))),
  ];
  const worksheet = XLSX.utils.aoa_to_sheet(rows);
  worksheet['!cols'] = headers.map((cell) => ({
    wch: Math.min(44, Math.max(14, String(cell ?? '').length + 2)),
  }));
  return worksheet;
}

function sheetFromRows(rows) {
  const header = rows[0] ?? [''];
  const worksheet = XLSX.utils.aoa_to_sheet(rows);
  worksheet['!cols'] = header.map((cell) => ({
    wch: Math.min(44, Math.max(14, String(cell ?? '').length + 2)),
  }));
  return worksheet;
}

const instructions = [
  ['Employee import template — complete employee profile'],
  [''],
  ['Purpose'],
  ['Load employees and related records to exercise HR, attendance, leave, and payroll.'],
  ['Download this workbook from Employees → Import Employees, fill every sheet that applies, then upload it.'],
  [''],
  ['How to use'],
  ['1. Keep lookup values from the Lookups sheet (resolve by name/code, not database IDs).'],
  ['2. Import order:'],
  ['   Employees → Employment → Compensation → Banks → Contacts → LeaveEntitlements → SsBenefits'],
  ['   → PoolPoints → Allowances → Deductions → DepartmentHeads → ScheduledWork → ClockingLogs'],
  ['3. Employee codes in this file start at 100001 to avoid colliding with seeded codes 000001–000100.'],
  ['4. Banks on the Banks sheet are created automatically if they do not exist yet.'],
  ['5. Dates use ISO format YYYY-MM-DD. Times use HH:mm or YYYY-MM-DD HH:mm:ss. Booleans use true/false.'],
  ['6. ClockingLogs.biometricUserId must match Employees.code (also works with internalId1 / internalId2).'],
  ['7. supervisorEmployeeCode / leadEmployeeCode must match another Employees.code in this file.'],
  ['8. PoolPoints.poolTypeName must be a configured pool (seeded: Shares, Tips).'],
  ['9. Allowances and deductions are created by name if missing. Default GL accounts: 6106 (other payments), 2100 (deductions).'],
  ['10. One Allowances/Deductions/PoolPoints row per item. Do not put comma-separated lists in a single cell.'],
  [''],
  ['Mapping from Additional Info workbooks'],
  ['# Shares → PoolPoints (poolTypeName = Shares, points = share count). Skip N/A.'],
  ['Allowances (Commission - $522.74, Web Assist - $200.00) → one Allowances row per named amount.'],
  ['Deductions (EDC - $2.00, SMCU Loan - $200.00) → one Deductions row per named amount.'],
  ['Tips dollar amounts are period earnings, not pool points. Mark participation with PoolPoints poolTypeName = Tips, points = 1.'],
  ['Department Changes (Bar on the 1st–5th) are mid-period notes, not a department-head appointment. Put them in Employees.notes.'],
  ['Supervisor → Employees.supervisorEmployeeCode (and optional leadEmployeeCode).'],
  ['Department heads → DepartmentHeads (employeeCode + departmentName + startDate).'],
  [''],
  ['Sample attendance'],
  ['ClockingLogs in this file cover 2026-06-29 … 2026-07-12. ScheduledWork is optional when the Default Template applies.'],
  ['Devices: DEV-01 = Business Office, DEV-02 = Resort, DEV-03 = Guava Limb Café.'],
  [''],
  ['Minimum path for a complete employee profile'],
  ['Employees + Employment + Compensation, plus any of: supervisor, banks, contacts, leave, SS, pool shares, allowances, deductions, department heads.'],
  ['For attendance processing also add ScheduledWork (or rely on Default Template) + ClockingLogs, then process.'],
  [''],
  ['Sheets'],
  ['Instructions — this page'],
  ['Employees — person + employee master (include supervisorEmployeeCode / leadEmployeeCode)'],
  ['Employment — active contract / employment detail'],
  ['Compensation — pay method and rates (linked to employment by employeeCode)'],
  ['Banks — employee bank accounts (optional; bank records are created from bankName)'],
  ['Contacts — emergency / dependent contacts (optional)'],
  ['LeaveEntitlements — per-contract leave overrides (optional)'],
  ['SsBenefits — social security benefit status (optional)'],
  ['PoolPoints — share / tip pool points (optional; one row per employee per pool type)'],
  ['Allowances — default other payments (optional; repeating payments such as Commission)'],
  ['Deductions — default deductions (optional; EDC, loans, staff charges)'],
  ['DepartmentHeads — appoint an employee as current head of a department (optional)'],
  ['ScheduledWork — calendar / shift assignments (POST /scheduled-work)'],
  ['ClockingLogs — biometric punches (POST /clocking-logs or Attendance import)'],
  ['Lookups — valid seeded reference values'],
];

const lookups = [
  ['Lookup', 'Valid values (seeded)', 'Used by'],
  ['genderName', 'Male | Female', 'Employees'],
  ['honorificName', 'Mr | Mrs | Miss | Ms | Dr | Prof | Professor | Rev | …', 'Employees'],
  ['localityName', 'San Ignacio | San Ignacio Town', 'Employees, Contacts'],
  ['citizenshipStatusName', 'Belizean Citizen | Permanent Resident | Work Permit Holder | Temporary Resident | Visitor | Non-Resident | Refugee | Stateless', 'Employees'],
  ['nationalityName', 'Belize (nationalityName = Belizean; use country name Belize)', 'Employees'],
  ['paymentMethodName', 'Bank Transfer', 'Employees'],
  ['employeeStatusName', 'active | inactive | suspended | fired | retired', 'Employees'],
  ['employmentStatusName', 'Full-Time | Part-Time | Probation | On Leave | Terminated', 'Employees'],
  ['timesheetTemplateName', 'Default Template (Mon–Fri 08:00–17:00)', 'Employees'],
  ['departmentName', 'Operations | Finance | Marketing | Sales | Administration | Kitchen | Bar | Dining | Tours | Guest Services | Gardeners | Security | Staff Kitchen | Storeroom | Natural History Center | Belize Rainforest Retreat', 'Employment, DepartmentHeads, ScheduledWork'],
  ['worksiteName', 'Head Office | Branch Office | Business Office | Resort | Guava Limb Café', 'Employment, ScheduledWork'],
  ['accountCode', '6101 Regular Wages | 6106 Other Payments | 2100 Wages Payable', 'Employment, Allowances, Deductions'],
  ['contractTypeName', 'Permanent | Fixed-Term Contract | Temporary | Casual | Consultant | Intern', 'Employment'],
  ['payPeriodGroupName', 'Monthly Payroll | Biweekly Payroll | Default Pay Period', 'Employment'],
  ['compensationMethod', 'HOURLY_NO_OT | HOURLY_OT | BASE_NO_OT | BASE_OT | DAILY_RATE', 'Compensation'],
  ['reasonType', 'INITIAL | INCREMENT | PROMOTION | EVALUATION | CORRECTION | OTHER', 'Compensation'],
  ['leaveTypeCode', 'VACATION | SICK | SICK_UNCERTIFIED | PTO | PATERNITY | PROFESSIONAL | WITHOUT_PAY', 'LeaveEntitlements'],
  ['accrualMethod', 'UPFRONT | MONTHLY | NONE', 'LeaveEntitlements'],
  ['ssBenefitTypeName', 'Pension | Disability | Survivor | Other', 'SsBenefits'],
  ['relationshipName', 'Spouse | Parent | Child | Sibling | Grandparent | …', 'Contacts'],
  ['accountTypeName', 'Checking | Savings | Money Market | Payroll', 'Banks'],
  ['bankName / bankCode', 'Created automatically from Banks.bankName if missing (e.g. Belize Bank / BLZ)', 'Banks, Deductions'],
  ['supervisorEmployeeCode', 'Employees.code of the supervisor (assigned after all employees are created)', 'Employees'],
  ['leadEmployeeCode', 'Employees.code of the team lead (optional)', 'Employees'],
  ['poolTypeName', 'Shares | Tips (must already exist in Settings → Pool Distribution)', 'PoolPoints'],
  ['allowanceName', 'Created automatically if missing (e.g. Commission, Web Assist, Trips, Open Hearth)', 'Allowances'],
  ['deductionTypeName', 'Created automatically if missing (e.g. EDC, SMCU Loan, NBB Loan, Staff Charge)', 'Deductions'],
  ['frequencyName', 'Monthly | Biweekly', 'Deductions'],
  ['allowance accountCode', '6106 Other Payments (default)', 'Allowances'],
  ['deduction accountCode', '2100 Wages Payable (default)', 'Deductions'],
  ['departmentHeads', 'employeeCode + departmentName + startDate appoints the current department head', 'DepartmentHeads'],
  ['biometricUserId', 'Employees.code (preferred) | internalId1 | internalId2', 'ClockingLogs'],
  ['deviceId', 'DEV-01 (Business Office) | DEV-02 (Resort) | DEV-03 (Guava Limb Café)', 'ClockingLogs'],
  ['punchType', 'IN | OUT | blank', 'ClockingLogs'],
  ['ScheduledWork times', 'startTime/endTime HH:mm (defaults 09:00 / 17:00 if omitted)', 'ScheduledWork'],
];

const sheets = {
  Employees: [
    'code', 'honorificName', 'firstName', 'middleName', 'lastName', 'maidenName', 'birthdate',
    'address1', 'address2', 'localityName', 'phone', 'email', 'genderName', 'socialSecurityNumber',
    'socialSecurityExpirationDate', 'taxIdentificationNumber', 'passportNumber', 'votersId',
    'citizenshipStatusName', 'nationalityName', 'payrateFrequencyName', 'paymentMethodName',
    'employeeStatusName', 'employmentStatusName', 'timesheetTemplateName', 'internalId1', 'internalId2',
    'supervisorEmployeeCode', 'leadEmployeeCode', 'notes', 'health', 'unionMembership',
  ],
  Employment: [
    'employeeCode', 'departmentName', 'worksiteName', 'accountCode', 'contractTypeName',
    'payPeriodGroupName', 'startDate', 'endDate', 'jobTitle', 'requiresClocking', 'isActive',
    'benefits', 'employmentPolicies',
  ],
  Compensation: [
    'employeeCode', 'effectiveDate', 'endDate', 'compensationMethod', 'reasonType', 'hourlyRate',
    'yearlyRate', 'dailyRate', 'standardWeeklyHours', 'requiresClocking', 'isActive', 'payscale',
    'payscalePoint', 'reasonNote',
  ],
  Banks: [
    'employeeCode', 'bankName', 'bankCode', 'accountTypeName', 'accountNumber', 'isPrimary', 'notes',
  ],
  Contacts: [
    'employeeCode', 'firstName', 'lastName', 'phoneNumber1', 'email', 'address1', 'localityName',
    'relationshipName', 'isDependent', 'notes',
  ],
  LeaveEntitlements: [
    'employeeCode', 'leaveTypeCode', 'annualEntitlementDays', 'accrualMethod', 'useOrgDefault',
  ],
  SsBenefits: [
    'employeeCode', 'isReceivingBenefit', 'effectiveFrom', 'effectiveTo', 'ssBenefitTypeName', 'notes',
  ],
  PoolPoints: [
    'employeeCode', 'poolTypeName', 'points', 'weight', 'effectiveDate', 'endDate', 'notes',
  ],
  Allowances: [
    'employeeCode', 'allowanceName', 'accountCode', 'quantity', 'unitAmount', 'note',
  ],
  Deductions: [
    'employeeCode', 'deductionTypeName', 'amount', 'bankName', 'bankCode', 'accountNumber',
    'frequencyName', 'accountCode', 'allowPartialDeduction', 'priority', 'note',
  ],
  DepartmentHeads: [
    'employeeCode', 'departmentName', 'startDate', 'notes',
  ],
  ScheduledWork: [
    'employeeCode', 'departmentName', 'worksiteName', 'startDate', 'endDate', 'startTime', 'endTime',
    'description', 'rate', 'includeLunchHour', 'lunchHourHours',
  ],
  ClockingLogs: [
    'biometricUserId', 'deviceId', 'punchDateTime', 'punchType',
  ],
};

const payloadKeyBySheet = {
  Employees: 'employees',
  Employment: 'employment',
  Compensation: 'compensation',
  Banks: 'banks',
  Contacts: 'contacts',
  LeaveEntitlements: 'leaveEntitlements',
  SsBenefits: 'ssBenefits',
  PoolPoints: 'poolPoints',
  Allowances: 'allowances',
  Deductions: 'deductions',
  DepartmentHeads: 'departmentHeads',
  ScheduledWork: 'scheduledWork',
  ClockingLogs: 'clockingLogs',
};

const workbook = XLSX.utils.book_new();
XLSX.utils.book_append_sheet(workbook, sheetFromRows(instructions), 'Instructions');

for (const [sheetName, headers] of Object.entries(sheets)) {
  XLSX.utils.book_append_sheet(
    workbook,
    sheetFromObjects(payload[payloadKeyBySheet[sheetName]] ?? [], headers),
    sheetName,
  );
}

XLSX.utils.book_append_sheet(workbook, sheetFromRows(lookups), 'Lookups');

mkdirSync(__dirname, { recursive: true });
XLSX.writeFile(workbook, outPath);
console.log(`Wrote ${outPath}`);
console.log(`  Employees: ${(payload.employees ?? []).length}`);
console.log(`  PoolPoints: ${(payload.poolPoints ?? []).length}`);
console.log(`  Allowances: ${(payload.allowances ?? []).length}`);
console.log(`  Deductions: ${(payload.deductions ?? []).length}`);
console.log(`  DepartmentHeads: ${(payload.departmentHeads ?? []).length}`);
console.log(`  ClockingLogs: ${(payload.clockingLogs ?? []).length}`);
