import { createContext, useContext, useId, useState, type ReactNode } from 'react';
import { useMutation, useQueryClient } from '@tanstack/react-query';
import { Trash2, Loader2 } from 'lucide-react';
import { toast } from 'sonner';
import { apiClient } from '@/api/client';
import type { ApiEnvelopeError } from '@/api/envelope';
import { useCan } from '@/hooks/useCan';
import { Button } from '@/components/ui/button';
import { DropdownMenuItem } from '@/components/ui/dropdown-menu';
import { Dialog, DialogContent, DialogDescription, DialogFooter, DialogHeader, DialogTitle } from '@/components/ui/dialog';

const permissions = {
  announcements: 'counselling.announcements.manage',
  services: 'counselling.services.manage',
  surveys: 'counselling.surveys.manage',
  equipment: 'clinic.inventory.delete',
  medicines: 'clinic.inventory.delete',
  supplies: 'clinic.inventory.delete',
  'staff-schedules': 'clinic.schedules.manage',
  devices: 'facilities.units.manage',
  'waste-categories': 'facilities.categories.manage',
  drums: 'facilities.units.manage',
  'report-configs': 'reports.configure',
  students: 'clinic.patients.write',
  employees: 'clinic.patients.write',
  encounters: 'clinic.encounters.write',
  'counselling-sessions': 'counselling.records.soft_delete',
} as const;
export type PermanentDeleteResource = keyof typeof permissions;

export function permanentDeletionBlockReason(resource: PermanentDeleteResource): string | undefined {
  switch (resource) {
    case 'students':
    case 'employees':
      return 'Patient registry records must be retained. Keep this record archived; permanent deletion is unavailable here.';
    case 'encounters':
      return 'Clinical encounters must be retained. Keep this record archived; permanent deletion is unavailable here.';
    case 'counselling-sessions':
      return 'Counselling sessions must be retained. Retention-based erasure is handled separately, not through this action.';
    default:
      return undefined;
  }
}

type DeletionRequest = { resource: PermanentDeleteResource; id: number; label: string };
type DeletionContext = { request: (row: DeletionRequest) => void; pending: boolean };
const Context = createContext<DeletionContext | null>(null);

const invalidationKeys: Record<PermanentDeleteResource, ReadonlyArray<string>> = {
  announcements: ['guidance'], services: ['guidance'], surveys: ['guidance'],
  equipment: ['equipment'], medicines: ['medicines'], supplies: ['inventory'],
  'staff-schedules': ['clinic', 'staff-schedules'], devices: ['facilities'],
  'waste-categories': ['facilities'], drums: ['facilities'], 'report-configs': ['reports'],
  students: ['patients'], employees: ['patients'], encounters: ['clinic'],
  'counselling-sessions': ['counselling'],
};

/** The dialog lives outside dropdowns so closing a row menu cannot unmount it. */
export function PermanentDeleteProvider({ children }: { children: ReactNode }) {
  const [selected, setSelected] = useState<DeletionRequest | null>(null);
  const qc = useQueryClient();
  const deletion = useMutation<{ deleted: boolean }, ApiEnvelopeError, DeletionRequest>({
    mutationFn: async ({ resource, id }) => {
      const response = await apiClient.post<{ deleted: boolean }>(`/archived-records/${resource}/${id}/delete-permanently`);
      return response.data;
    },
    onSuccess: (_result, row) => {
      void qc.invalidateQueries({ queryKey: invalidationKeys[row.resource] });
      void qc.invalidateQueries({ queryKey: ['dashboard'] });
      setSelected(null);
      toast.success('Record permanently deleted.');
    },
  });

  function close() {
    if (deletion.isPending) return;
    setSelected(null);
    deletion.reset();
  }

  return (
    <Context.Provider value={{ pending: deletion.isPending, request: (row) => {
      if (deletion.isPending || permanentDeletionBlockReason(row.resource) !== undefined) return;
      deletion.reset();
      setSelected(row);
    } }}>
      {children}
      <Dialog open={selected !== null} onOpenChange={(open) => { if (!open) close(); }}>
        <DialogContent size="sm" lockDismiss={deletion.isPending}>
          <DialogHeader>
            <DialogTitle>Delete permanently?</DialogTitle>
            <DialogDescription>
              Permanently delete {selected?.label} (#{selected?.id})? This cannot be undone. Records with retained history or dependent data cannot be deleted.
            </DialogDescription>
          </DialogHeader>
          {deletion.isError && (
            <p role="alert" className="text-sm text-destructive">
              {deletion.error.errors[0]?.message ?? 'Unable to delete this record. It has not been removed.'}
            </p>
          )}
          <DialogFooter>
            <Button variant="outline" disabled={deletion.isPending} onClick={close}>Cancel</Button>
            <Button variant="destructive" disabled={deletion.isPending || selected === null}
              onClick={() => { if (selected !== null) deletion.mutate(selected); }}>
              {deletion.isPending ? <Loader2 aria-hidden className="animate-spin" /> : <Trash2 aria-hidden />}
              {deletion.isPending ? 'Deleting…' : 'Delete permanently'}
            </Button>
          </DialogFooter>
        </DialogContent>
      </Dialog>
    </Context.Provider>
  );
}

export function PermanentDeleteAction({ resource, id, label, presentation = 'button' }: DeletionRequest & {
  presentation?: 'button' | 'menuitem';
}) {
  const canDelete = useCan(permissions[resource]);
  const context = useContext(Context);
  const reason = permanentDeletionBlockReason(resource);
  const descriptionId = useId();
  if (!canDelete) return null;
  const disabled = reason !== undefined || context === null || context.pending;
  const request = () => context?.request({ resource, id, label });
  const description = reason === undefined ? undefined : descriptionId;

  if (presentation === 'menuitem') {
    return (
      <DropdownMenuItem className="min-h-11 text-destructive focus:text-destructive"
        disabled={disabled} title={reason} aria-describedby={description} onSelect={request}>
        <Trash2 aria-hidden />
        <span className="flex flex-col gap-1">
          <span>Delete permanently</span>
          {reason !== undefined && <span id={descriptionId} className="max-w-64 text-xs font-normal text-muted-foreground">{reason}</span>}
        </span>
      </DropdownMenuItem>
    );
  }
  return (
    <span className="inline-flex flex-col items-start gap-1">
      <Button size="sm" variant="destructive" disabled={disabled} title={reason}
        aria-describedby={description} onClick={request}>
        <Trash2 aria-hidden /> Delete permanently
      </Button>
      {reason !== undefined && <span id={descriptionId} className="max-w-64 text-wrap text-left text-xs text-muted-foreground">{reason}</span>}
    </span>
  );
}
