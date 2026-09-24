/**
 * SchedulingTab — counsellor availability windows.
 *
 * Appointments moved out to their own section on 2026-09-23, so the `subtab`
 * switch that used to live here is gone: this tab is now only the weekly
 * availability grid (list or calendar). `?avail_view=` is kept, and
 * CounsellingPage rewrites a legacy `?subtab=appointments` deep link to the
 * Appointments tab.
 */
import { useUrlFilter } from '@/hooks/useUrlFilter';
import { AvailabilityView } from '../scheduling';

export function SchedulingTab() {
  const [availabilityView, setAvailabilityView] = useUrlFilter('avail_view', { default: 'list' });

  return (
    <AvailabilityView view={availabilityView} onViewChange={setAvailabilityView} />
  );
}
