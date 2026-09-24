/**
 * SurveysPage — guidance surveys builder and responses.
 *
 * Was a section inside `/counselling` until 2026-09-24; the four content
 * surfaces moved to their own routes so the Counselling page keeps only the
 * daily-working booking surfaces. The body is unchanged — `SurveysTab` owns the
 * builder and the response browser, and takes no props.
 */
import { PageHeader } from '@/components/PageHeader';
import { SurveysTab } from '@/components/counselling/tabs';

export default function SurveysPage() {
  return (
    <main className="space-y-4 p-6">
      <PageHeader
        title="Surveys"
        description="Draft, publish and archive guidance surveys. Publishing freezes the question set; a survey can be required for clearance signing. Reading a response is audited."
      />
      <SurveysTab />
    </main>
  );
}
