import { ChevronDown, Loader2 } from 'lucide-react';
import { useState } from 'react';
import { CountBadge } from '@/components/CountBadge';
import { Button } from '@/components/ui/button';
import {
  DialogContent,
  DialogHeader,
  DialogTitle,
} from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import {
  Select,
  SelectContent,
  SelectItem,
  SelectTrigger,
  SelectValue,
} from '@/components/ui/select';
import { fmtRelativeFromNow } from './format';
import { useChangeEquipmentUnitStatus, useEquipmentDetail } from '@/hooks/useEquipment';
import type { EquipmentItem, EquipmentStatus, EquipmentStatusLogEntry, EquipmentUnit } from '@/schemas/equipment';
import { EQUIPMENT_STATUS_LABEL } from './constants';
import { EquipmentStatusBadge } from './badges';

const STATUSES: EquipmentStatus[] = ['working', 'for_repair', 'for_replacement', 'retired'];

/**
 * EquipmentUnitsDialog — manage an item's physical units. Each row is one
 * unit with a one-click status select and an optional note; every change
 * lands in the append-only status log shown under the unit. This is the
 * load-bearing action: if updating a status is buried, the data rots.
 */
export function EquipmentUnitsDialog({ item, onClose }: { item: EquipmentItem; onClose: () => void }) {
  const detail = useEquipmentDetail(item.id);
  const changeStatus = useChangeEquipmentUnitStatus();

  const data = detail.data;
  const logByUnit = new Map<number, EquipmentStatusLogEntry[]>();
  if (data !== undefined) {
    for (const entry of data.status_log) {
      const list = logByUnit.get(entry.unit_id) ?? [];
      list.push(entry);
      logByUnit.set(entry.unit_id, list);
    }
  }

  return (
    <DialogContent className="max-w-2xl">
      <DialogHeader>
        <DialogTitle>
          Units — {item.name}
          {data !== undefined && (
            <span className="ml-2 text-sm font-normal text-muted-foreground">
              {data.total_units} total
            </span>
          )}
        </DialogTitle>
      </DialogHeader>

      {detail.isLoading && <Loader2 className="mx-auto size-5 animate-spin text-muted-foreground" />}

      {detail.isError && (
        <p role="alert" className="rounded-md border border-destructive/40 bg-destructive/10 p-4 text-sm text-destructive">
          Couldn't load the units. Please close and retry.
        </p>
      )}

      {data !== undefined && data.units.length === 0 && (
        <p className="rounded-md border border-dashed p-4 text-sm text-muted-foreground">
          No units yet. Use Add units on the equipment row to register physical units.
        </p>
      )}

      {data !== undefined && data.units.length > 0 && (
        <div className="max-h-[60vh] space-y-2 overflow-y-auto pr-1">
          {data.units.map((unit) => (
            <UnitRow
              key={unit.id}
              unit={unit}
              pending={changeStatus.isPending}
              log={logByUnit.get(unit.id) ?? []}
              onChange={(status, note) =>
                changeStatus.mutate({ unitId: unit.id, input: { status, ...(note !== '' ? { note } : {}) } })
              }
            />
          ))}
        </div>
      )}

      <div className="flex justify-end">
        <Button variant="outline" onClick={onClose}>Close</Button>
      </div>
    </DialogContent>
  );
}

function UnitRow({
  unit,
  pending,
  log,
  onChange,
}: {
  unit: EquipmentUnit;
  pending: boolean;
  log: EquipmentStatusLogEntry[];
  onChange: (status: EquipmentStatus, note: string) => void;
}): JSX.Element {
  // The draft note is local to the row — it rides along with the next
  // status change made from this row.
  const [draftStatus, setDraftStatus] = useState<EquipmentStatus>(unit.status);
  const [draftNote, setDraftNote] = useState('');
  const dirty = draftStatus !== unit.status || draftNote !== '';

  return (
    <div className="rounded-lg border p-3">
      <div className="flex flex-wrap items-center gap-2">
        <span className="tabular-nums text-xs text-muted-foreground">#{unit.id}</span>
        <EquipmentStatusBadge status={unit.status} />
        {unit.condition_note !== null && (
          <span className="text-xs text-muted-foreground">{unit.condition_note}</span>
        )}
        <span className="ml-auto text-[11px] text-muted-foreground">
          {unit.status_changed_at !== '' && `status set ${fmtRelativeFromNow(unit.status_changed_at)}`}
        </span>
      </div>

      <div className="mt-2 flex flex-wrap items-end gap-2">
        <div className="space-y-1">
          <Label id={`unit-status-label-${unit.id}`} className="text-xs">Status</Label>
          <Select value={draftStatus} onValueChange={(v) => setDraftStatus(v as EquipmentStatus)}>
            <SelectTrigger
              aria-labelledby={`unit-status-label-${unit.id}`}
              className="h-9 w-40"
              disabled={pending}
            >
              <SelectValue />
            </SelectTrigger>
            <SelectContent>
              {STATUSES.map((s) => (
                <SelectItem key={s} value={s}>{EQUIPMENT_STATUS_LABEL[s]}</SelectItem>
              ))}
            </SelectContent>
          </Select>
        </div>
        <div className="flex-1 space-y-1" style={{ minWidth: 160 }}>
          <Label htmlFor={`unit-note-${unit.id}`} className="text-xs">Note (optional)</Label>
          <Input
            id={`unit-note-${unit.id}`}
            className="h-9"
            maxLength={255}
            placeholder="Why the status changed…"
            value={draftNote}
            onChange={(e) => setDraftNote(e.target.value)}
            disabled={pending}
          />
        </div>
        <Button
          size="sm"
          disabled={pending || !dirty}
          onClick={() => {
            onChange(draftStatus, draftNote);
            setDraftNote('');
          }}
        >
          {pending && <Loader2 className="animate-spin" />} Update
        </Button>
      </div>

      {log.length > 0 && (
        <details className="mt-2">
          <summary className="cursor-pointer list-none text-xs text-muted-foreground hover:text-foreground">
            <ChevronDown className="mr-1 inline-block size-3" aria-hidden />
            History
            <CountBadge count={log.length} className="ml-1.5" />
          </summary>
          <ul className="mt-1 space-y-1 border-l pl-3">
            {log.map((entry) => (
              <li key={entry.id} className="text-[11px] text-muted-foreground">
                <span className="tabular-nums">{fmtRelativeFromNow(entry.created_at)}</span>
                {' — '}
                {entry.from_status !== null ? EQUIPMENT_STATUS_LABEL[entry.from_status] : 'added'}
                {' → '}
                {EQUIPMENT_STATUS_LABEL[entry.to_status]}
                {entry.note !== null && ` — ${entry.note}`}
                {entry.user_email !== null && ` (by ${entry.user_email})`}
              </li>
            ))}
          </ul>
        </details>
      )}
    </div>
  );
}
