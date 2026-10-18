/**
 * AnnouncementsPage — targeted guidance announcements.
 *
 * Was a section inside `/counselling` until 2026-09-24 (see SurveysPage for
 * why). The body is `AnnouncementsTab`, unchanged and prop-free.
 */
import { Archive, Plus } from 'lucide-react';
import { useState } from 'react';
import { PageHeader } from '@/components/PageHeader';
import { AnnouncementsTab } from '@/components/counselling/tabs';
import { Button } from '@/components/ui/button';
import { useUrlFilter } from '@/hooks/useUrlFilter';
import type { GuidanceAnnouncement } from '@/schemas/guidanceContent';

export default function AnnouncementsPage() {
  const [dialogOpen, setDialogOpen] = useState(false);
  const [editing, setEditing] = useState<GuidanceAnnouncement | null>(null);
  const [archivedParam, setArchivedParam] = useUrlFilter('archived');
  const showArchived = archivedParam === '1';

  return (
    <main className="space-y-4 p-6">
      <PageHeader
        title="Announcements"
        description="Targeted guidance announcements — audience, publish window, and an optional action link."
        actions={
          <>
            <Button
              variant={showArchived ? 'secondary' : 'outline'}
              aria-pressed={showArchived}
              onClick={() => setArchivedParam(showArchived ? '' : '1')}
            >
              <Archive /> {showArchived ? 'Hide archived' : 'Show archived'}
            </Button>
            <Button onClick={() => { setEditing(null); setDialogOpen(true); }}>
              <Plus aria-hidden className="size-4" />
              New announcement
            </Button>
          </>
        }
      />
      <AnnouncementsTab
        dialogOpen={dialogOpen}
        setDialogOpen={setDialogOpen}
        editing={editing}
        setEditing={setEditing}
        showArchived={showArchived}
      />
    </main>
  );
}
