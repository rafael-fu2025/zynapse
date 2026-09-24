/**
 * ServicesPage — the CHED CMO 9 s.2013 guidance service catalogue.
 *
 * Was a section inside `/counselling` until 2026-09-24 (see SurveysPage for
 * why). The body is `ServicesTab`, unchanged and prop-free.
 */
import { PageHeader } from '@/components/PageHeader';
import { ServicesTab } from '@/components/counselling/tabs';

export default function ServicesPage() {
  return (
    <main className="space-y-4 p-6">
      <PageHeader
        title="Services"
        description="The guidance service catalogue — code, sort order and queue destination. A queue destination makes a service bookable on the student portal."
      />
      <ServicesTab />
    </main>
  );
}
