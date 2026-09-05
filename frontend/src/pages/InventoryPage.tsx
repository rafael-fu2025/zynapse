/**
 * InventoryPage — clinic stock (Phases 8 + 12).
 *
 * Two tabs:
 *   - Medicines: batch-tracked catalog recycled from the legacy system.
 *     Lots carry expiry dates; dispensing is FEFO (earliest expiry
 *     first) on the backend. Receiving and dispensing happen in
 *     dialogs; the batches dialog shows per-lot status.
 *   - Supplies: the original generic item ledger (signed movements).
 *
 * The tab bodies, dialogs, and shared badge/format helpers live in
 * `src/components/inventory/` (one file per dialog + one per tab);
 * the keyset pagination state shared by the three list tabs is
 * `src/hooks/useKeysetPagination`.
 */
import { BarChart3, CalendarClock } from 'lucide-react';
import { Tabs, TabsContent, TabsList, TabsTrigger } from '@/components/ui/tabs';
import { TooltipProvider } from '@/components/ui/tooltip';
import { useTabParam } from '@/hooks/useTabParam';
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
    <main className="mx-auto max-w-7xl space-y-4 p-6">
      <header>
        <h1 className="text-xl font-semibold text-foreground">Inventory</h1>
        <p className="flex items-center gap-1.5 text-sm text-muted-foreground">
          <CalendarClock className="size-3.5" aria-hidden />
          Medicines are tracked by batch with expiry — earliest expiring dispensed first; supplies use signed stock movements.
        </p>
      </header>

      <InventoryStockAlertBanner onJumpToTab={setTab} />

      <Tabs value={tab} onValueChange={setTab}>
        <TabsList>
          <TabsTrigger value="medicines">Medicines</TabsTrigger>
          <TabsTrigger value="supplies">Supplies</TabsTrigger>
          <TabsTrigger value="reorders">Reorders</TabsTrigger>
          <TabsTrigger value="insights">
            <BarChart3 className="size-3.5" /> Insights
          </TabsTrigger>
        </TabsList>
        <TabsContent value="medicines">
          <MedicinesTab />
        </TabsContent>
        <TabsContent value="supplies">
          <SuppliesTab />
        </TabsContent>
        <TabsContent value="reorders">
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
