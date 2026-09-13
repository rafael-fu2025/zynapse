/**
 * ServicesTab — the CHED CMO 9 s.2013 guidance service catalogue
 * (parity plan Phase A). Seeded by migration; guidance_admin can edit
 * descriptions, toggle activity, and mark services as bookable (a
 * queue_destination exposes "Book appointment" on the student portal).
 */
import { BookOpen, Pencil, Plus } from 'lucide-react';
import { useState } from 'react';
import { useForm } from 'react-hook-form';
import { zodResolver } from '@hookform/resolvers/zod';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import { ConfirmDialog, type ConfirmAction } from '@/components/ConfirmDialog';
import { QueryErrorState } from '@/components/QueryErrorState';
import {
  Dialog,
  DialogContent,
  DialogDescription,
  DialogFooter,
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
import { Skeleton } from '@/components/ui/skeleton';
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from '@/components/ui/table';
import { Textarea } from '@/components/ui/textarea';
import {
  useArchiveGuidanceService,
  useCreateGuidanceService,
  useGuidanceServices,
  useUpdateGuidanceService,
} from '@/hooks/useGuidanceContent';
import type { GuidanceService } from '@/schemas/guidanceContent';
import { serviceInputSchema, type ServiceInput } from '@/schemas/guidanceContent';

function ServiceDialog({
  existing,
  onClose,
}: {
  existing: GuidanceService | null;
  onClose: () => void;
}) {
  const create = useCreateGuidanceService();
  const update = useUpdateGuidanceService();
  const pending = create.isPending || update.isPending;
  const {
    register,
    handleSubmit,
    setValue,
    watch,
    formState: { errors },
  } = useForm<ServiceInput>({
    resolver: zodResolver(serviceInputSchema),
    defaultValues: existing !== null
      ? {
          name: existing.name,
          code: existing.code,
          description: existing.description ?? '',
          cmo_reference: existing.cmo_reference ?? '',
          sort_order: existing.sort_order,
          queue_destination: existing.queue_destination,
          is_active: existing.is_active,
        }
      : {
          name: '',
          code: '',
          description: '',
          cmo_reference: '',
          sort_order: 10,
          queue_destination: null,
          is_active: true,
        },
  });

  const destination = watch('queue_destination');

  function submit(values: ServiceInput) {
    if (existing !== null) {
      update.mutate({ id: existing.id, input: values }, { onSuccess: onClose });
    } else {
      create.mutate(values, { onSuccess: onClose });
    }
  }

  return (
    <Dialog open onOpenChange={(open) => ! open && ! pending && onClose()}>
      <DialogContent lockDismiss className="max-h-[90dvh] overflow-y-auto sm:max-w-xl">
        <DialogHeader>
          <DialogTitle>{existing !== null ? 'Edit service' : 'New service'}</DialogTitle>
          <DialogDescription>
            The catalogue follows CHED CMO 9 s.2013. Services with a queue destination show a booking affordance on the
            student portal.
          </DialogDescription>
        </DialogHeader>
        <form onSubmit={(event) => { void handleSubmit(submit)(event); }} className="space-y-4" noValidate>
          <div className="space-y-1.5">
            <Label htmlFor="svc-name">Name</Label>
            <Input id="svc-name" {...register('name')} aria-invalid={errors.name !== undefined} />
            {errors.name !== undefined && <p role="alert" className="text-xs text-destructive">{errors.name.message}</p>}
          </div>
          <div className="space-y-1.5">
            <Label htmlFor="svc-desc">Description</Label>
            <Textarea id="svc-desc" rows={3} {...register('description')} />
          </div>
          <div className="grid gap-4 sm:grid-cols-2">
            <div className="space-y-1.5">
              <Label htmlFor="svc-code">Code <span className="text-muted-foreground">(optional slug)</span></Label>
              <Input id="svc-code" {...register('code')} aria-invalid={errors.code !== undefined} />
              {errors.code !== undefined && <p role="alert" className="text-xs text-destructive">{errors.code.message}</p>}
            </div>
            <div className="space-y-1.5">
              <Label htmlFor="svc-sort">Sort order</Label>
              <Input id="svc-sort" type="number" min={0} {...register('sort_order', { valueAsNumber: true })} />
            </div>
          </div>
          <div className="grid gap-4 sm:grid-cols-2">
            <div className="space-y-1.5">
              <Label htmlFor="svc-dest">Queue destination</Label>
              <Select
                value={destination ?? 'none'}
                onValueChange={(v) => setValue('queue_destination', v === 'none' ? null : (v as 'clinic' | 'counselling'))}
              >
                <SelectTrigger id="svc-dest"><SelectValue /></SelectTrigger>
                <SelectContent>
                  <SelectItem value="none">Not bookable</SelectItem>
                  <SelectItem value="counselling">Guidance queue</SelectItem>
                  <SelectItem value="clinic">Clinic queue</SelectItem>
                </SelectContent>
              </Select>
            </div>
            <div className="flex items-end space-x-2 pb-2">
              <Checkbox
                id="svc-active"
                checked={watch('is_active')}
                onCheckedChange={(checked) => setValue('is_active', checked === true)}
              />
              <Label htmlFor="svc-active" className="cursor-pointer font-normal">Active</Label>
            </div>
          </div>
          <div className="space-y-1.5">
            <Label htmlFor="svc-cmo">CMO reference <span className="text-muted-foreground">(optional)</span></Label>
            <Input id="svc-cmo" placeholder="CHED CMO 9 s.2013 — …" {...register('cmo_reference')} />
          </div>
          <DialogFooter>
            <Button type="button" variant="outline" onClick={onClose} disabled={pending}>Cancel</Button>
            <Button type="submit" disabled={pending}>
              <BookOpen aria-hidden /> {pending ? 'Saving…' : existing !== null ? 'Save changes' : 'Create service'}
            </Button>
          </DialogFooter>
        </form>
      </DialogContent>
    </Dialog>
  );
}

export function ServicesTab() {
  const services = useGuidanceServices();
  const archive = useArchiveGuidanceService();
  const [dialogOpen, setDialogOpen] = useState(false);
  const [editing, setEditing] = useState<GuidanceService | null>(null);
  const [confirm, setConfirm] = useState<ConfirmAction | null>(null);
  const rows = services.data ?? [];

  function requestArchive(s: GuidanceService) {
    setConfirm({
      title: `Archive "${s.name}"?`,
      description: 'It disappears from the catalogue and the student portal immediately.',
      confirmLabel: 'Archive',
      run: () => archive.mutate({ id: s.id, name: s.name }, { onSuccess: () => setConfirm(null) }),
    });
  }

  return (
    <div className="space-y-4">
      <div className="flex justify-end">
        <Button onClick={() => { setEditing(null); setDialogOpen(true); }}>
          <Plus aria-hidden /> New service
        </Button>
      </div>

      {services.isError && (
        <QueryErrorState message="Failed to load the service catalogue." onRetry={() => void services.refetch()} pending={services.isFetching} />
      )}

      {services.isLoading && (
        <div role="status" aria-label="Loading services" className="space-y-3">
          <Skeleton className="h-12 w-full" />
          <Skeleton className="h-12 w-full" />
        </div>
      )}

      {services.data !== undefined && rows.length > 0 && (
        <section aria-labelledby="svc-list-heading" className="overflow-hidden rounded-xl border bg-card">
          <h2 id="svc-list-heading" className="sr-only">Service catalogue</h2>
          <div className="overflow-x-auto">
            <Table>
              <TableHeader className="bg-muted/50">
                <TableRow>
                  <TableHead className="px-3">Service</TableHead>
                  <TableHead className="px-3">Booking</TableHead>
                  <TableHead className="px-3">State</TableHead>
                  <TableHead className="px-3 text-right">Actions</TableHead>
                </TableRow>
              </TableHeader>
              <TableBody>
                {rows.map((s) => (
                  <TableRow key={s.id}>
                    <TableCell className="max-w-96 px-3">
                      <p className="truncate text-sm font-medium">{s.name}</p>
                      {s.description !== null && <p className="truncate text-xs text-muted-foreground">{s.description}</p>}
                    </TableCell>
                    <TableCell className="px-3">
                      {s.queue_destination === null
                        ? <Badge variant="outline">Informational</Badge>
                        : <Badge variant="info">{s.queue_destination === 'counselling' ? 'Guidance queue' : 'Clinic queue'}</Badge>}
                    </TableCell>
                    <TableCell className="px-3">
                      {s.is_active ? <Badge variant="success">Active</Badge> : <Badge variant="secondary">Hidden</Badge>}
                    </TableCell>
                    <TableCell className="px-3 text-right">
                      <div className="flex justify-end gap-2">
                        <Button size="sm" variant="outline" onClick={() => { setEditing(s); setDialogOpen(true); }}>
                          <Pencil aria-hidden /> Edit
                        </Button>
                        <Button size="sm" variant="destructive" onClick={() => requestArchive(s)}>Archive</Button>
                      </div>
                    </TableCell>
                  </TableRow>
                ))}
              </TableBody>
            </Table>
          </div>
        </section>
      )}

      {dialogOpen && <ServiceDialog existing={editing} onClose={() => setDialogOpen(false)} />}
      <ConfirmDialog
        open={confirm !== null}
        title={confirm?.title ?? ''}
        description={confirm?.description}
        confirmLabel={confirm?.confirmLabel}
        pending={archive.isPending}
        onConfirm={() => confirm?.run()}
        onCancel={() => setConfirm(null)}
      />
    </div>
  );
}
