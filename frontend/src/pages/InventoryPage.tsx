/**
 * InventoryPage — clinic stock (Phases 8 + 12).
 *
 * Five surfaces:
 *   - Medicines: batch-tracked catalog recycled from the legacy system.
 *     Lots carry expiry dates; dispensing is FEFO (earliest expiry
 *     first) on the backend. Receiving and dispensing happen in
 *     dialogs; the batches dialog shows per-lot status.
 *   - Supplies: the original generic item ledger (signed movements).
 *   - Equipment: the durable-asset catalog (per-unit status tracking).
 *   - Purchases: the reorder/procurement workflow feeding both.
 *   - Insights: catalogue-wide analytics tiles and panels.
 *
 * Navigation (2026-09-15): the sections render through the shared
 * `TabSections` component — vertical secondary sidebar with icons on
 * wide screens, horizontal pills below `lg`.
 *
 * The tab bodies, dialogs, and shared badge/format helpers live in
 * `src/components/inventory/` (one file per dialog + one per tab);
 * the keyset pagination state shared by the three list tabs is
 * `src/hooks/useKeysetPagination`.
 */
import { CalendarClock } from 'lucide-react';
import { PageHeader } from '@/components/PageHeader';
import { Tabs, TabsContent } from '@/components/ui/tabs';
import { TooltipProvider } from '@/components/ui/tooltip';
import { useTabParam } from '@/hooks/useTabParam';
import { EquipmentTab } from '@/components/inventory/EquipmentTab';
import { InsightsTab } from '@/components/inventory/InsightsTab';
import { InventoryStockAlertBanner } from '@/components/inventory/InventoryStockAlertBanner';
import { MedicinesTab } from '@/components/inventory/MedicinesTab';
import { ReordersTab } from '@/components/inventory/ReordersTab';
import { SuppliesTab } from '@/components/inventory/SuppliesTab';


export default function InventoryPage() {
  const [tab, setTab] = useTabParam('medicines');
  return (
    // TooltipProvider covers every tooltip in the page (action-dropdown
    // disabled-state hints, dialog submit explanations). delayDuration=150
    // is short enough to feel snappy on a desktop click; skipDelayDuration
    // makes back-to-back hovers (moving from one row to the next) instant.
    <TooltipProvider delayDuration={150} skipDelayDuration={300}>
    {/* md+: one-viewport flex column (topbar h-14 excluded) — each stock
        table flexes to the leftover space and its pager stays in view. */}
    <main className="space-y-4 p-6 md:flex md:h-[calc(100dvh-3.5rem)] md:flex-col md:overflow-hidden">
      <div className="md:shrink-0">
      <InventoryStockAlertBanner onJumpToTab={setTab} />
      </div>

      <Tabs value={tab} onValueChange={setTab} className="space-y-4 md:min-h-0 md:flex-1 md:flex md:flex-col">
        <div className="md:shrink-0">
        <PageHeader
          title="Inventory"
          description={
            <span className="flex items-center gap-1.5">
              <CalendarClock className="size-3.5" aria-hidden />
              Medicines are tracked by batch with expiry — earliest expiring dispensed first; supplies use signed stock movements.
            </span>
          }
        />
        </div>

          <TabsContent value="medicines" className="md:min-h-0 md:flex-1 md:flex md:flex-col">
            <MedicinesTab />
          </TabsContent>
          <TabsContent value="supplies" className="md:min-h-0 md:flex-1 md:flex md:flex-col">
            <SuppliesTab />
          </TabsContent>
          <TabsContent value="equipment" className="md:min-h-0 md:flex-1 md:flex md:flex-col">
            <EquipmentTab />
          </TabsContent>
          <TabsContent value="reorders" className="md:min-h-0 md:flex-1 md:flex md:flex-col">
            <ReordersTab />
          </TabsContent>
          <TabsContent value="insights">
            <InsightsTab onJumpToTab={setTab} />
          </TabsContent>
      </Tabs>
    </main>
    </TooltipProvider>
  );
}
