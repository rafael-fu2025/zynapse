/**
 * Employee record grouping — the TS mirror of the backend
 * `EmployeePersonService` heuristics. Keep both in lockstep.
 *
 * FU MIS issues one employee record (its own 6-digit number) per
 * appointment, so one human can hold several `users` rows. The backend
 * sends each person's records ordered newest-first with `is_primary`
 * flagged; these helpers only fill the gaps (a missing `position_year`
 * on legacy payloads / e2e mocks, and picking the primary when a caller
 * has an unsorted list).
 */

/** A record as sent by the backend — all group fields optional. */
export interface EmployeeRecordLike {
  id: number;
  employee_number: string | null;
  position_year?: number | null;
  is_primary?: boolean;
}

/**
 * Decode the MIS issuance year from the 6-digit `DYYNNN` employee
 * number: first digit marks the century (1 → 1900s, 2 → 2000s), next
 * two the year (`225082` → 2025). Numbers outside the observed MIS
 * format return null — a display fallback, never a merge signal.
 */
export function decodePositionYear(employeeNumber: string | null | undefined): number | null {
  if (!employeeNumber) {
    return null;
  }
  const match = /^(\d)(\d{2})\d{3}$/.exec(employeeNumber.trim());
  if (!match) {
    return null;
  }
  return 1800 + Number(match[1]) * 100 + Number(match[2]);
}

/** The record whose position the UI displays: `is_primary` if flagged, else the first. */
export function primaryRecordOf<T extends EmployeeRecordLike>(records: readonly T[]): T | null {
  if (records.length === 0) {
    return null;
  }
  return records.find((r) => r.is_primary) ?? records[0] ?? null;
}

/** Issuance year of a record: the payload value, else decoded from the number. */
export function positionYearOf(record: EmployeeRecordLike): number | null {
  return record.position_year ?? decodePositionYear(record.employee_number);
}
