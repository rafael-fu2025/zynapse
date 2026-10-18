/**
 * SurveysPage — guidance surveys builder and responses.
 *
 * Was a section inside `/counselling` until 2026-09-24; the four content
 * surfaces moved to their own routes so the Counselling page keeps only the
 * daily-working booking surfaces. The body is unchanged — `SurveysTab` owns the
 * builder and the response browser, and takes no props.
 */
import { Archive, Plus } from 'lucide-react';
import { useState } from 'react';
import { PageHeader } from '@/components/PageHeader';
import { SurveysTab } from '@/components/counselling/tabs';
import { Button } from '@/components/ui/button';
import { useUrlFilter } from '@/hooks/useUrlFilter';

export default function SurveysPage() {
  const [builderOpen, setBuilderOpen] = useState(false);
  const [editingId, setEditingId] = useState<number | null>(null);
  const [archivedParam, setArchivedParam] = useUrlFilter('archived');
  const showArchived = archivedParam === '1';

  return (
    <main className="space-y-4 p-6">
      <PageHeader
        title="Surveys"
        description="Draft, publish and archive guidance surveys. Publishing freezes the question set; a survey can be required for clearance signing. Reading a response is audited."
        actions={
          <>
            <Button
              variant={showArchived ? 'secondary' : 'outline'}
              aria-pressed={showArchived}
              onClick={() => setArchivedParam(showArchived ? '' : '1')}
            >
              <Archive /> {showArchived ? 'Hide archived' : 'Show archived'}
            </Button>
            <Button onClick={() => { setEditingId(null); setBuilderOpen(true); }}>
              <Plus aria-hidden className="size-4" />
              New survey
            </Button>
          </>
        }
      />
      <SurveysTab
        builderOpen={builderOpen}
        setBuilderOpen={setBuilderOpen}
        editingId={editingId}
        setEditingId={setEditingId}
        showArchived={showArchived}
      />
    </main>
  );
}
