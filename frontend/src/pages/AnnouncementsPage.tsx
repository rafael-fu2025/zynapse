/**
 * AnnouncementsPage — targeted guidance announcements.
 *
 * Was a section inside `/counselling` until 2026-09-24 (see SurveysPage for
 * why). The body is `AnnouncementsTab`, unchanged and prop-free.
 */
import { PageHeader } from '@/components/PageHeader';
import { AnnouncementsTab } from '@/components/counselling/tabs';

export default function AnnouncementsPage() {
  return (
    <main className="space-y-4 p-6">
      <PageHeader
        title="Announcements"
        description="Targeted guidance announcements — audience, publish window, and an optional action link."
      />
      <AnnouncementsTab />
    </main>
  );
}
