/**
 * DrumDetailPage — focused screen for a single processing drum.
 *
 * Routed at `/facilities/drums/:unitId`. Opened from the "Processing
 * Drums" widget or a drum's action menu.
 *
 * Reading order, top to bottom:
 *   1. Identity — which drum, its phase, and whether its controller is up.
 *   2. Anything that needs attention — active SPC alerts.
 *   3. The batch at a glance — code, weights, ETA and progress, stated
 *      once (nothing below repeats them).
 *   4. Detail, split by task into tabs (deep-linkable via `?tab=`):
 *        Overview   — analytics + the process-log timeline
 *        Materials  — waste-loaded / compost-harvested ledgers + losses
 *        Automation — the unit's device, schedule and run history
 *
 * Entry forms (observation, input, output, loss) open in dialogs from
 * the card they belong to; nothing sits permanently expanded.
 *
 * The automation panel is the UNIT's, not the batch's, so an idle drum
 * (no active batch) renders just the header and that panel — a board
 * that is offline between batches is exactly the state staff need to see.
 * Schedule edits and run/stop commands are admin-gated
 * (`facilities.units.manage`).
 */
import { ArrowLeft, Cylinder, Loader2, MapPin } from 'lucide-react';
import { Link, useParams } from 'react-router-dom';
import { PageHeader } from '@/components/PageHeader';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card, CardContent } from '@/components/ui/card';
import { Tabs, TabsContent, TabsList, TabsTrigger } from '@/components/ui/tabs';
import { AlertBanner } from '@/components/facilities/drum/AlertBanner';
import { AnalyticsCard } from '@/components/facilities/drum/AnalyticsCard';
import { AutomationPanel, DeviceStatusPill } from '@/components/facilities/drum/AutomationPanel';
import { BatchSummary } from '@/components/facilities/drum/BatchSummary';
import {
  CompostHarvestedCard,
  LedgerBalanceNotice,
  LossCard,
  WasteLoadedCard,
} from '@/components/facilities/drum/MaterialsCards';
import { ProcessLogCard } from '@/components/facilities/drum/ProcessLogCard';
import { useActiveBatches, useUnitMonitor } from '@/hooks/useFacilities';
import { useTabParam } from '@/hooks/useTabParam';
import { describeBatchPhase } from '@/lib/bmgFormat';
import { titleCase } from '@/lib/utils';
import type { ActiveBatch } from '@/schemas/facilities';

const TABS = ['overview', 'materials', 'automation'] as const;
type DrumTab = (typeof TABS)[number];

function isDrumTab(value: string): value is DrumTab {
  return (TABS as readonly string[]).includes(value);
}

function BackToFacilities() {
  return (
    <Button variant="outline" asChild>
      <Link to="/facilities">
        <ArrowLeft /> Back to Facilities
      </Link>
    </Button>
  );
}

/** A drum with an active batch: header, alerts, summary, then tabbed detail. */
function BatchView({ batch }: { batch: ActiveBatch }) {
  const [rawTab, setTab] = useTabParam('overview');
  // An unknown `?tab=` value falls back to the default instead of
  // rendering an empty page.
  const tab: DrumTab = isDrumTab(rawTab) ? rawTab : 'overview';
  // Wording comes from the shared formatter so this page and the drum
  // grid can never describe the same batch differently.
  const phase = describeBatchPhase(batch.input_kg);

  return (
    <Tabs value={tab} onValueChange={setTab} className="space-y-4">
      <PageHeader
        title={
          <span className="flex items-center gap-2">
            <Cylinder className="size-5 text-primary" />
            <span className="tabular-nums">{batch.unit_code}</span>
            <Badge variant={phase.tone === 'info' ? 'info' : 'warning'} className="uppercase">
              {phase.label}
            </Badge>
          </span>
        }
        description={
          <>
            {batch.unit_name}
            {batch.unit_location !== null && (
              <span className="mt-0.5 flex items-center gap-1 text-xs">
                <MapPin className="size-3" /> {batch.unit_location}
              </span>
            )}
          </>
        }
        actions={
          <>
            <DeviceStatusPill unitId={batch.unit_id} onClick={() => setTab('automation')} />
            <BackToFacilities />
          </>
        }
      />

      <AlertBanner batchId={batch.batch_id} />
      <BatchSummary batch={batch} />

      <TabsList>
        <TabsTrigger value="overview">Overview</TabsTrigger>
        <TabsTrigger value="materials">Materials</TabsTrigger>
        <TabsTrigger value="automation">Automation</TabsTrigger>
      </TabsList>

      <TabsContent value="overview" className="mt-0 grid items-start gap-4 lg:grid-cols-2">
        <AnalyticsCard batchId={batch.batch_id} />
        <ProcessLogCard batchId={batch.batch_id} />
      </TabsContent>

      <TabsContent value="materials" className="mt-0 space-y-4">
        <LedgerBalanceNotice unitId={batch.unit_id} />
        <div className="grid items-start gap-4 lg:grid-cols-2">
          <WasteLoadedCard batchId={batch.batch_id} />
          <CompostHarvestedCard batchId={batch.batch_id} />
        </div>
        <LossCard batchId={batch.batch_id} />
      </TabsContent>

      <TabsContent value="automation" className="mt-0">
        <AutomationPanel unitId={batch.unit_id} />
      </TabsContent>
    </Tabs>
  );
}

/** A drum with no active batch: identity from the unit, then its automation. */
function IdleDrum({ unitId, loadFailed }: { unitId: number; loadFailed: boolean }) {
  const monitor = useUnitMonitor(unitId);
  const unit = monitor.data?.unit;

  return (
    <>
      <PageHeader
        title={
          <span className="flex items-center gap-2">
            <Cylinder className="size-5 text-primary" />
            <span className="tabular-nums">{unit?.code ?? 'Drum'}</span>
            {unit !== undefined && (
              <Badge variant="secondary" className="uppercase">
                {titleCase(unit.status)}
              </Badge>
            )}
          </span>
        }
        description={unit?.display_name}
        actions={<BackToFacilities />}
      />
      {!loadFailed && (
        <Card>
          <CardContent className="p-4 text-sm text-muted-foreground">
            No active batch on this drum — it may have been finished or cancelled. Its controller and
            run schedule are below.
          </CardContent>
        </Card>
      )}
      <AutomationPanel unitId={unitId} />
    </>
  );
}

export default function DrumDetailPage() {
  const { unitId: unitIdParam } = useParams();
  const unitId = Number(unitIdParam);
  const active = useActiveBatches();
  const batch = (active.data?.data ?? []).find((b) => b.unit_id === unitId) ?? null;
  const validUnit = Number.isInteger(unitId) && unitId > 0;

  return (
    <main className="space-y-4 p-6">
      {active.isLoading && (
        <div className="flex items-center justify-center py-16 text-muted-foreground">
          <Loader2 className="size-5 animate-spin" />
        </div>
      )}
      {active.isError && (
        <p className="rounded-md border border-destructive/30 bg-destructive/5 p-3 text-sm text-destructive">
          Failed to load active drums.
        </p>
      )}
      {!validUnit && (
        <section className="grid min-h-[40vh] place-items-center">
          <div className="max-w-md text-center">
            <Cylinder className="mx-auto size-8 text-muted-foreground" />
            <h1 className="mt-2 text-lg font-semibold text-foreground">Drum not found</h1>
            <p className="mt-1 text-sm text-muted-foreground">This link doesn't point to a valid drum.</p>
            <div className="mt-4">
              <BackToFacilities />
            </div>
          </div>
        </section>
      )}
      {validUnit && !active.isLoading && (
        batch !== null ? (
          <BatchView batch={batch} />
        ) : (
          <IdleDrum unitId={unitId} loadFailed={active.isError} />
        )
      )}
    </main>
  );
}
