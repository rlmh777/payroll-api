#!/usr/bin/env node
/**
 * Parse employee-import-template.xlsx into the JSON payload expected by EmployeeImportService.
 * Usage: node parse-employee-import-workbook.mjs /path/to/file.xlsx
 */
import fs from 'fs';
import path from 'path';
import { createRequire } from 'module';

const require = createRequire(import.meta.url);
const candidates = [
  path.resolve(process.cwd(), '../payroll-web/node_modules/xlsx'),
  path.resolve(process.cwd(), '../../payroll-web/node_modules/xlsx'),
  path.resolve(process.cwd(), 'node_modules/xlsx'),
  'xlsx',
];

let XLSX = null;
for (const candidate of candidates) {
  try {
    XLSX = require(candidate);
    break;
  } catch {
    // try next
  }
}

if (!XLSX) {
  console.error('Unable to load xlsx module. Install dependencies in payroll-web.');
  process.exit(1);
}

const SHEET_TO_PAYLOAD_KEY = {
  Employees: 'employees',
  Employment: 'employment',
  Compensation: 'compensation',
  Banks: 'banks',
  Contacts: 'contacts',
  LeaveEntitlements: 'leaveEntitlements',
  SsBenefits: 'ssBenefits',
  ScheduledWork: 'scheduledWork',
  ClockingLogs: 'clockingLogs',
};

function normalizeHeaderKey(header) {
  const raw = String(header ?? '').trim();
  if (!raw) return '';
  if (/^[a-z][a-zA-Z0-9]*$/.test(raw)) return raw;
  const parts = raw
    .replace(/([a-z0-9])([A-Z])/g, '$1 $2')
    .split(/[^a-zA-Z0-9]+/)
    .filter(Boolean);
  if (!parts.length) return '';
  return parts
    .map((part, index) => {
      const lower = part.toLowerCase();
      return index === 0 ? lower : lower.charAt(0).toUpperCase() + lower.slice(1);
    })
    .join('');
}

function toCellValue(value) {
  if (value === null || value === undefined || value === '') return null;
  if (typeof value === 'boolean' || typeof value === 'number') return value;
  if (value instanceof Date && !Number.isNaN(value.getTime())) {
    const year = value.getFullYear();
    const month = String(value.getMonth() + 1).padStart(2, '0');
    const day = String(value.getDate()).padStart(2, '0');
    const hours = value.getHours();
    const minutes = value.getMinutes();
    const seconds = value.getSeconds();
    if (hours === 0 && minutes === 0 && seconds === 0) {
      return `${year}-${month}-${day}`;
    }
    return `${year}-${month}-${day} ${String(hours).padStart(2, '0')}:${String(minutes).padStart(2, '0')}:${String(seconds).padStart(2, '0')}`;
  }

  const text = String(value).trim();
  if (!text) return null;
  const lower = text.toLowerCase();
  if (lower === 'true') return true;
  if (lower === 'false') return false;

  // Normalize common Excel date strings (M/D/YY or M/D/YYYY).
  const usDate = text.match(/^(\d{1,2})\/(\d{1,2})\/(\d{2,4})(?:[ T](\d{1,2}):(\d{2})(?::(\d{2}))?)?$/);
  if (usDate) {
    let year = Number(usDate[3]);
    if (year < 100) year += year >= 70 ? 1900 : 2000;
    const month = String(Number(usDate[1])).padStart(2, '0');
    const day = String(Number(usDate[2])).padStart(2, '0');
    if (usDate[4] != null) {
      const hours = String(Number(usDate[4])).padStart(2, '0');
      const minutes = String(Number(usDate[5])).padStart(2, '0');
      const seconds = String(Number(usDate[6] ?? 0)).padStart(2, '0');
      return `${year}-${month}-${day} ${hours}:${minutes}:${seconds}`;
    }
    return `${year}-${month}-${day}`;
  }

  // ISO timestamps from Excel exports (require year-month-day shape).
  if (/^\d{4}-\d{2}-\d{2}/.test(text) || /^\d{4}-\d{2}-\d{2}T/.test(text)) {
    const iso = Date.parse(text);
    if (!Number.isNaN(iso)) {
      const date = new Date(iso);
      const year = date.getUTCFullYear();
      const month = String(date.getUTCMonth() + 1).padStart(2, '0');
      const day = String(date.getUTCDate()).padStart(2, '0');
      const hours = date.getUTCHours();
      const minutes = date.getUTCMinutes();
      const seconds = date.getUTCSeconds();
      if (text.includes('T') || hours || minutes || seconds) {
        return `${year}-${month}-${day} ${String(hours).padStart(2, '0')}:${String(minutes).padStart(2, '0')}:${String(seconds).padStart(2, '0')}`;
      }
      return `${year}-${month}-${day}`;
    }
  }

  return text;
}

function parseSheetRows(worksheet) {
  if (!worksheet) return [];
  const matrix = XLSX.utils.sheet_to_json(worksheet, {
    header: 1,
    raw: false,
    defval: '',
    blankrows: false,
  });
  if (!matrix.length) return [];
  const headers = (matrix[0] ?? []).map((header) => normalizeHeaderKey(header));
  const rows = [];
  for (const rawRow of matrix.slice(1)) {
    const row = {};
    let hasValue = false;
    headers.forEach((key, index) => {
      if (!key) return;
      const value = toCellValue(rawRow[index]);
      row[key] = value;
      if (value !== null && value !== '') hasValue = true;
    });
    if (hasValue) rows.push(row);
  }
  return rows;
}

function findSheetName(workbook, expected) {
  const normalizedExpected = expected.toLowerCase().replace(/[^a-z0-9]/g, '');
  return workbook.SheetNames.find(
    (name) => name.toLowerCase().replace(/[^a-z0-9]/g, '') === normalizedExpected,
  );
}

const filePath = process.argv[2];
if (!filePath || !fs.existsSync(filePath)) {
  console.error('Usage: node parse-employee-import-workbook.mjs /path/to/file.xlsx');
  process.exit(1);
}

const workbook = XLSX.read(fs.readFileSync(filePath), { type: 'buffer', cellDates: true });
const payload = {
  employees: [],
  employment: [],
  compensation: [],
  scheduledWork: [],
  clockingLogs: [],
};

for (const [sheetName, key] of Object.entries(SHEET_TO_PAYLOAD_KEY)) {
  const actual = findSheetName(workbook, sheetName);
  const rows = parseSheetRows(actual ? workbook.Sheets[actual] : undefined);
  if (key in payload) {
    payload[key] = rows;
  } else if (rows.length) {
    payload[key] = rows;
  }
}

process.stdout.write(JSON.stringify(payload));
