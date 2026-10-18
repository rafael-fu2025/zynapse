/**
 * ServicesPage — the CHED CMO 9 s.2013 guidance service catalogue.
 *
 * Was a section inside `/counselling` until 2026-09-24 (see SurveysPage for
 * why). The body is `ServicesTab`, unchanged and prop-free.
 */
import { Archive, Plus } from 'lucide-react';
import { useState } from 'react';
import { PageHeader } from '@/components/PageHeader';
import { ServicesTab } from '@/components/counselling/tabs';
import { Button } from '@/components/ui/button';
import { useUrlFilter } from '@/hooks/useUrlFilter';
import type { GuidanceService } from '@/schemas/guidanceContent';

export default function ServicesPage() {
  const [dialogOpen, setDialogOpen] = useState(false);
  const [editing, setEditing] = useState<GuidanceService | null>(null);
  const [archivedParam, setArchivedParam] = useUrlFilter('archived');
  const showArchived = archivedParam === '1';

  return (
    <main className="space-y-4 p-6">
      <PageHeader
        title="Services"
        description="The guidance service catalogue — code, sort order and queue destination. A queue destination makes a service bookable on the student portal."
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
              New service
            </Button>
          </>
        }
      />
      <ServicesTab
        dialogOpen={dialogOpen}
        setDialogOpen={setDialogOpen}
        editing={editing}
        setEditing={setEditing}
        showArchived={showArchived}
      />
    </main>
  );
}
