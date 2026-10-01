import { describe, expect, it } from 'vitest';
import { decodePositionYear, positionYearOf, primaryRecordOf } from './employeeRecords';

describe('decodePositionYear', () => {
  it('decodes the DYY prefix into an issuance year', () => {
    expect(decodePositionYear('225082')).toBe(2025);
    expect(decodePositionYear('200151')).toBe(2000);
    expect(decodePositionYear('195123')).toBe(1995);
  });

  it('returns null for numbers outside the observed MIS format', () => {
    expect(decodePositionYear(null)).toBeNull();
    expect(decodePositionYear('')).toBeNull();
    expect(decodePositionYear('20260928')).toBeNull();
    expect(decodePositionYear('22A082')).toBeNull();
    expect(decodePositionYear('225')).toBeNull();
  });
});

describe('primaryRecordOf', () => {
  it('prefers the is_primary flag', () => {
    const records = [
      { id: 1, employee_number: '210453', is_primary: false },
      { id: 2, employee_number: '223076', is_primary: true },
    ];
    expect(primaryRecordOf(records)?.id).toBe(2);
  });

  it('falls back to the first record when unflagged', () => {
    const records = [
      { id: 7, employee_number: '225082' },
      { id: 9, employee_number: '210453' },
    ];
    expect(primaryRecordOf(records)?.id).toBe(7);
  });

  it('returns null for an empty group', () => {
    expect(primaryRecordOf([])).toBeNull();
  });
});

describe('positionYearOf', () => {
  it('uses the payload value when present', () => {
    expect(positionYearOf({ id: 1, employee_number: '225082', position_year: 2025 })).toBe(2025);
  });

  it('decodes from the number when the payload omits it', () => {
    expect(positionYearOf({ id: 1, employee_number: '225082' })).toBe(2025);
    expect(positionYearOf({ id: 1, employee_number: 'garbage' })).toBeNull();
  });
});
