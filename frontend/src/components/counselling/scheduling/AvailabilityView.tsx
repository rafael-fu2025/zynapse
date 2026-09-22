import { useState } from 'react';
import { CalendarDays, List, Plus, Trash2 } from 'lucide-react';
import { Button } from '@/components/ui/button';
import { ConfirmDialog, type ConfirmAction } from '@/components/ConfirmDialog';
import { Dialog } from '@/components/ui/dialog';
import { TableStateBlock, TableStateRows } from '@/components/TableStates';
import {
  Table,
  TableBody,
  TableCell,
  TableHead,
  TableHeader,
  TableRow,
} from '@/components/ui/table';
import { useAvailability, useRemoveSlot } from '@/hooks/useSchedule';
import { hasPermission, useAuthStore } from '@/store/auth';
import { DAY_NAMES, type Availability } from '@/schemas/schedule';
import { AddSlotDialog } from '../dialogs';
import { AvailabilityCalendar } from './AvailabilityCalendar';

interface AvailabilityViewProps {
  view: string;
  onViewChange: (view: string) => void;
}

export function AvailabilityView({ view, onViewChange }: AvailabilityViewProps) {
  const auth = useAuthStore();
  const canMutate = hasPermission(auth, 'counselling.schedule.manage') || hasPermission(auth, 'counselling.schedule.team_manage');

  const [openAddSlot, setOpenAddSlot] = useState(false);
  const [confirm, setConfirm] = useState<ConfirmAction | null>(null);

  const availability = useAvailability();
  const availabilityRows = availability.data ?? [];
  const removeSlot = useRemoveSlot();

  function confirmRemove(w: Availability) {
    setConfirm({
      title: `Remove availability window on ${DAY_NAMES[w.day_of_week]}?`,
      description: 'Existing bookings in this window are not automatically cancelled, but no new bookings can be made against it.',
      confirmLabel: 'Remove window',
      run: () => removeSlot.mutate(w.id),
    });
  }

  return (
    <article className="overflow-hidden rounded-xl border bg-card">
      <header className="flex items-center justify-between border-b px-3 py-2">
        <p className="text-sm font-semibold text-foreground">Availability windows</p>
        <div className="flex items-center gap-2">
          <div className="flex rounded-md border p-0.5">
            <Button
              size="sm"
              variant={view === 'list' ? 'secondary' : 'ghost'}
              aria-pressed={view === 'list'}
              className="h-7"
              onClick={() => onViewChange('list')}
            >
              <List className="size-3.5" /> List
            </Button>
            <Button
              size="sm"
              variant={view === 'calendar' ? 'secondary' : 'ghost'}
              aria-pressed={view === 'calendar'}
              className="h-7"
              onClick={() => onViewChange('calendar')}
            >
              <CalendarDays className="size-3.5" /> Calendar
            </Button>
          </div>
          {canMutate && (
            <Button size="sm" onClick={() => setOpenAddSlot(true)}>
              <Plus className="size-3.5" /> Add
            </Button>
          )}
        </div>
      </header>

      {view === 'list' && (
        <Table ariaLabel="Weekly availability windows">
          <TableHeader className="bg-muted/50">
            <TableRow>
              <TableHead className="px-3">Day</TableHead>
              <TableHead className="px-3">Window</TableHead>
              <TableHead className="px-3">Capacity</TableHead>
              <TableHead className="px-3 text-right">Actions</TableHead>
            </TableRow>
          </TableHeader>
          <TableBody>
            <TableStateRows
              colSpan={4}
              isLoading={availability.isLoading}
              isError={availability.isError}
              isEmpty={availabilityRows.length === 0}
              onRetry={() => void availability.refetch()}
              pending={availability.isFetching}
              errorMessage="Failed to load availability windows."
              loadingLabel="Loading availability windows"
              empty={{
                title: 'No active windows.',
                description: 'Add one to accept bookings.',
              }}
            />
            {availability.data?.map((w) => (
              <TableRow key={w.id}>
                <TableCell className="px-3 text-xs font-medium">{DAY_NAMES[w.day_of_week]}</TableCell>
                <TableCell className="px-3 font-mono text-xs">
                  {w.start_time.slice(0, 5)}–{w.end_time.slice(0, 5)}
                </TableCell>
                <TableCell className="px-3 text-xs">{w.max_slots}</TableCell>
                <TableCell className="px-3 text-right">
                  {canMutate && (
                    <Button
                      size="sm"
                      variant="outline"
                      aria-label={`Remove window #${w.id}`}
                      disabled={removeSlot.isPending}
                      onClick={() => confirmRemove(w)}
                    >
                      <Trash2 className="size-3.5" /> Remove
                    </Button>
                  )}
                </TableCell>
              </TableRow>
            ))}
          </TableBody>
        </Table>
      )}

      {view === 'calendar' && (
        <div>
          <TableStateBlock
            isLoading={availability.isLoading}
            isError={availability.isError}
            isEmpty={availabilityRows.length === 0}
            onRetry={() => void availability.refetch()}
            pending={availability.isFetching}
            errorMessage="Failed to load availability windows."
            loadingLabel="Loading availability windows"
            empty={{
              title: 'No active windows.',
              description: 'Add one to accept bookings.',
            }}
          />
          {!availability.isLoading && !availability.isError && availabilityRows.length > 0 && (
            <AvailabilityCalendar
              windows={availability.data ?? []}
              onRemove={canMutate ? confirmRemove : () => undefined}
              removing={removeSlot.isPending}
            />
          )}
        </div>
      )}

      <Dialog open={openAddSlot} onOpenChange={setOpenAddSlot}>
        {openAddSlot && <AddSlotDialog onClose={() => setOpenAddSlot(false)} />}
      </Dialog>

      <ConfirmDialog
        open={confirm !== null}
        title={confirm?.title ?? ''}
        description={confirm?.description}
        confirmLabel={confirm?.confirmLabel ?? 'Confirm'}
        pending={removeSlot.isPending}
        onConfirm={() => {
          if (confirm) {
            confirm.run();
            setConfirm(null);
          }
        }}
        onCancel={() => setConfirm(null)}
      />
    </article>
  );
}
