import { AlertTriangle, ChevronRight, PackageX } from 'lucide-react';
import { useExpiringMedicines, useLowStockMedicines } from '@/hooks/useMedicines';

export function InventoryStockAlertBanner({
  onJumpToTab,
}: {
  onJumpToTab: (tab: 'medicines' | 'supplies' | 'reorders') => void;
}) {
  const lowStock = useLowStockMedicines();
  const expiring = useExpiringMedicines(30);

  const lowStockCount = lowStock.data?.length ?? 0;
  const expiringCount = expiring.data?.length ?? 0;

  if (lowStockCount === 0 && expiringCount === 0) {
    return null;
  }

  return (
    <div
      role="region"
      aria-label="Stock warnings"
      className="flex flex-wrap items-center justify-between gap-3 rounded-lg border border-amber-500/30 bg-amber-500/10 px-4 py-2.5 text-sm text-amber-900 dark:text-amber-200"
    >
      <div className="flex items-center gap-3">
        <AlertTriangle className="size-4 shrink-0 text-amber-600 dark:text-amber-400" />
        <div className="flex flex-wrap items-center gap-x-4 gap-y-1">
          {lowStockCount > 0 && (
            <span className="font-medium">
              <PackageX className="mr-1 inline-block size-3.5" />
              {lowStockCount} {lowStockCount === 1 ? 'medicine' : 'medicines'} below minimum reorder level
            </span>
          )}
          {expiringCount > 0 && (
            <span>
              {expiringCount} {expiringCount === 1 ? 'batch' : 'batches'} expiring within 30 days
            </span>
          )}
        </div>
      </div>
      <button
        type="button"
        onClick={() => onJumpToTab('medicines')}
        className="inline-flex items-center gap-1 text-xs font-semibold text-amber-700 hover:underline dark:text-amber-300"
      >
        <span>Review in Medicines</span>
        <ChevronRight className="size-3.5" />
      </button>
    </div>
  );
}
