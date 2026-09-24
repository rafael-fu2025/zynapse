/**
 * AppointmentsTab — the Guidance appointment book, promoted out of
 * Scheduling (2026-09-23).
 *
 * It used to be the second sub-tab inside Scheduling, behind `?subtab=`,
 * which buried the module's busiest surface two clicks deep. The desk books,
 * confirms and reviews from here all day, so it is now a first-class section
 * sitting directly below Queue. Scheduling keeps availability windows, which
 * are configuration rather than daily work.
 *
 * **It no longer hosts sessions** (2026-09-23, third revision). This tab used
 * to inherit the detached session view from the retired Sessions & Notes tab,
 * which made it a second, competing way to read a session — and the one that
 * won by default, because `?session=N` resolved here. Sessions are now read on
 * the **Queue** board alone, so this tab is just the book plus its filters.
 *
 * Filters stay in the URL via `useUrlFilter` (PRODUCT principle 5) under the
 * same `appt_status` / `appt_date` keys the old sub-tab used, so existing
 * shared links keep working — CounsellingPage redirects a legacy
 * `?subtab=appointments` to this tab.
 */
import { useUrlFilter } from '@/hooks/useUrlFilter';
import { AppointmentsTable } from '../scheduling';

export function AppointmentsTab() {
  const [status, setStatus] = useUrlFilter('appt_status', { default: 'all' });
  const [date, setDate] = useUrlFilter('appt_date', { default: '' });

  return (
    <AppointmentsTable
      status={status}
      onStatusChange={setStatus}
      date={date}
      onDateChange={setDate}
    />
  );
}
