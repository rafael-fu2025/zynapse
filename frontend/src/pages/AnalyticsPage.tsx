/**
 * AnalyticsPage — the counselling scheduling no-show figures.
 *
 * Was a section inside `/counselling` until 2026-09-24 (see SurveysPage for
 * why). The body is `AnalyticsTab`, unchanged; its `sort_key`/`sort_dir` URL
 * filters are read by the component itself, so they keep working here.
 */
import { PageHeader } from '@/components/PageHeader';
import { AnalyticsTab } from '@/components/counselling/tabs';

export default function AnalyticsPage() {
  return (
    <main className="space-y-4 p-6">
      <PageHeader
        title="Analytics"
        description="Booked and no-show figures per counsellor, day and slot, with the deterministic overbooking recommendation."
      />
      <AnalyticsTab />
    </main>
  );
}
