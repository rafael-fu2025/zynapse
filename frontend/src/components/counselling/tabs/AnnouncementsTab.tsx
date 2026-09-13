/**
 * AnnouncementsTab — staff-owned guidance announcements (parity plan
 * Phase A): targeted, windowed, audience-matched. Replaces the kiosk's
 * static list and its duplicate "Survey Links" tab.
 */
import { Megaphone, Pencil, Plus } from 'lucide-react';
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
  useArchiveGuidanceAnnouncement,
  useCreateGuidanceAnnouncement,
  useGuidanceAnnouncements,
  useUpdateGuidanceAnnouncement,
} from '@/hooks/useGuidanceContent';
import type { GuidanceAnnouncement } from '@/schemas/guidanceContent';
import { announcementInputSchema, type AnnouncementAudience, type AnnouncementInput } from '@/schemas/guidanceContent';
import { fmtUtcToApp } from '@/utils/date';

const AUDIENCE_LABELS: Record<AnnouncementAudience, string> = {
  all: 'All students',
  new_students: 'New students',
  continuing_students: 'Continuing students',
  graduating_students: 'Graduating students',
};

const STATUS_VARIANTS: Record<string, 'success' | 'info' | 'secondary'> = {
  live: 'success',
  scheduled: 'info',
  expired: 'secondary',
};

function AnnouncementDialog({
  existing,
  onClose,
}: {
  existing: GuidanceAnnouncement | null;
  onClose: () => void;
}) {
  const create = useCreateGuidanceAnnouncement();
  const update = useUpdateGuidanceAnnouncement();
  const pending = create.isPending || update.isPending;
  const {
    register,
    handleSubmit,
    setValue,
    watch,
    formState: { errors },
  } = useForm<AnnouncementInput>({
    resolver: zodResolver(announcementInputSchema),
    defaultValues: existing !== null
      ? {
          title: existing.title,
          body: existing.body,
          audience: existing.audience,
          action_url: existing.action_url ?? '',
          action_label: existing.action_label ?? '',
          is_required: existing.is_required,
          publish_at: existing.publish_at ?? '',
          unpublish_at: existing.unpublish_at ?? '',
        }
      : {
          title: '',
          body: '',
          audience: 'all',
          action_url: '',
          action_label: '',
          is_required: false,
          publish_at: '',
          unpublish_at: '',
        },
  });

  const audience = watch('audience');
  const isRequired = watch('is_required');

  function submit(values: AnnouncementInput) {
    const payload = { ...values, audience: values.audience as AnnouncementAudience };
    if (existing !== null) {
      update.mutate({ id: existing.id, input: payload }, { onSuccess: onClose });
    } else {
      create.mutate(payload, { onSuccess: onClose });
    }
  }

  return (
    <Dialog open onOpenChange={(open) => ! open && ! pending && onClose()}>
      <DialogContent lockDismiss className="max-h-[90dvh] overflow-y-auto sm:max-w-xl">
        <DialogHeader>
          <DialogTitle>{existing !== null ? 'Edit announcement' : 'New announcement'}</DialogTitle>
          <DialogDescription>
            Publishing is driven by the windows: leave both empty for an immediately live post, or set a start and/or
            end time. Required posts surface on the student portal feed.
          </DialogDescription>
        </DialogHeader>
        <form onSubmit={handleSubmit(submit)} className="space-y-4" noValidate>
          <div className="space-y-1.5">
            <Label htmlFor="ann-title">Title</Label>
            <Input id="ann-title" {...register('title')} aria-invalid={errors.title !== undefined} />
            {errors.title !== undefined && <p role="alert" className="text-xs text-destructive">{errors.title.message}</p>}
          </div>
          <div className="space-y-1.5">
            <Label htmlFor="ann-body">Body</Label>
            <Textarea id="ann-body" rows={4} {...register('body')} aria-invalid={errors.body !== undefined} />
            {errors.body !== undefined && <p role="alert" className="text-xs text-destructive">{errors.body.message}</p>}
          </div>
          <div className="grid gap-4 sm:grid-cols-2">
            <div className="space-y-1.5">
              <Label htmlFor="ann-audience">Audience</Label>
              <Select value={audience} onValueChange={(v) => setValue('audience', v as AnnouncementAudience)}>
                <SelectTrigger id="ann-audience"><SelectValue /></SelectTrigger>
                <SelectContent>
                  {(Object.keys(AUDIENCE_LABELS) as AnnouncementAudience[]).map((key) => (
                    <SelectItem key={key} value={key}>{AUDIENCE_LABELS[key]}</SelectItem>
                  ))}
                </SelectContent>
              </Select>
            </div>
            <div className="flex items-end space-x-2 pb-2">
              <Checkbox
                id="ann-required"
                checked={isRequired}
                onCheckedChange={(checked) => setValue('is_required', checked === true)}
              />
              <Label htmlFor="ann-required" className="cursor-pointer font-normal">
                Required for clearance signing
              </Label>
            </div>
          </div>
          <div className="grid gap-4 sm:grid-cols-2">
            <div className="space-y-1.5">
              <Label htmlFor="ann-publish">Publish at <span className="text-muted-foreground">(optional)</span></Label>
              <Input id="ann-publish" type="datetime-local" {...register('publish_at')} />
            </div>
            <div className="space-y-1.5">
              <Label htmlFor="ann-unpublish">Unpublish at <span className="text-muted-foreground">(optional)</span></Label>
              <Input id="ann-unpublish" type="datetime-local" {...register('unpublish_at')} />
            </div>
          </div>
          <div className="grid gap-4 sm:grid-cols-[1fr_10rem]">
            <div className="space-y-1.5">
              <Label htmlFor="ann-url">Action link <span className="text-muted-foreground">(optional)</span></Label>
              <Input id="ann-url" type="url" placeholder="https://…" {...register('action_url')} />
            </div>
            <div className="space-y-1.5">
              <Label htmlFor="ann-label">Link label</Label>
              <Input id="ann-label" placeholder="Open form" {...register('action_label')} />
            </div>
          </div>
          <DialogFooter>
            <Button type="button" variant="outline" onClick={onClose} disabled={pending}>Cancel</Button>
            <Button type="submit" disabled={pending}>
              <Megaphone aria-hidden /> {pending ? 'Saving…' : existing !== null ? 'Save changes' : 'Create announcement'}
            </Button>
          </DialogFooter>
        </form>
      </DialogContent>
    </Dialog>
  );
}

export function AnnouncementsTab() {
  const announcements = useGuidanceAnnouncements();
  const archive = useArchiveGuidanceAnnouncement();
  const [dialogOpen, setDialogOpen] = useState(false);
  const [editing, setEditing] = useState<GuidanceAnnouncement | null>(null);
  const [confirm, setConfirm] = useState<ConfirmAction | null>(null);
  const rows = announcements.data ?? [];

  function requestArchive(a: GuidanceAnnouncement) {
    setConfirm({
      title: `Archive "${a.title}"?`,
      description: 'It disappears from the staff list and the student portal feed immediately. Archived posts stay in the audit trail.',
      confirmLabel: 'Archive',
      run: () => archive.mutate({ id: a.id, title: a.title }, { onSuccess: () => setConfirm(null) }),
    });
  }

  return (
    <div className="space-y-4">
      <div className="flex justify-end">
        <Button onClick={() => { setEditing(null); setDialogOpen(true); }}>
          <Plus aria-hidden /> New announcement
        </Button>
      </div>

      {announcements.isError && (
        <QueryErrorState message="Failed to load announcements." onRetry={() => void announcements.refetch()} pending={announcements.isFetching} />
      )}

      {announcements.isLoading && (
        <div role="status" aria-label="Loading announcements" className="space-y-3">
          <Skeleton className="h-14 w-full" />
          <Skeleton className="h-14 w-full" />
        </div>
      )}

      {announcements.data !== undefined && rows.length === 0 && (
        <section className="rounded-xl border bg-card p-8 text-center">
          <p className="font-medium text-foreground">No announcements yet</p>
          <p className="mt-1 text-sm text-muted-foreground">
            Create the first post — it replaces the hand-maintained kiosk list.
          </p>
        </section>
      )}

      {announcements.data !== undefined && rows.length > 0 && (
        <section aria-labelledby="ann-list-heading" className="overflow-hidden rounded-xl border bg-card">
          <h2 id="ann-list-heading" className="sr-only">Announcements</h2>
          <div className="overflow-x-auto">
            <Table>
              <TableHeader className="bg-muted/50">
                <TableRow>
                  <TableHead className="px-3">Title</TableHead>
                  <TableHead className="px-3">Audience</TableHead>
                  <TableHead className="px-3">Window</TableHead>
                  <TableHead className="px-3">Status</TableHead>
                  <TableHead className="px-3 text-right">Actions</TableHead>
                </TableRow>
              </TableHeader>
              <TableBody>
                {rows.map((a) => (
                  <TableRow key={a.id}>
                    <TableCell className="max-w-72 px-3">
                      <p className="flex items-center gap-2 truncate text-sm font-medium">
                        {a.is_required && <Badge variant="warning">Required</Badge>}
                        {a.title}
                      </p>
                      <p className="truncate text-xs text-muted-foreground">{a.body}</p>
                    </TableCell>
                    <TableCell className="px-3 text-sm">{AUDIENCE_LABELS[a.audience]}</TableCell>
                    <TableCell className="px-3 text-xs">
                      {a.publish_at === null && a.unpublish_at === null
                        ? 'Always'
                        : `${a.publish_at === null ? '—' : fmtUtcToApp(a.publish_at, 'MMM d, yyyy')} → ${a.unpublish_at === null ? '—' : fmtUtcToApp(a.unpublish_at, 'MMM d, yyyy')}`}
                    </TableCell>
                    <TableCell className="px-3"><Badge variant={STATUS_VARIANTS[a.status]}>{a.status}</Badge></TableCell>
                    <TableCell className="px-3 text-right">
                      <div className="flex justify-end gap-2">
                        <Button size="sm" variant="outline" onClick={() => { setEditing(a); setDialogOpen(true); }}>
                          <Pencil aria-hidden /> Edit
                        </Button>
                        <Button size="sm" variant="destructive" onClick={() => requestArchive(a)}>Archive</Button>
                      </div>
                    </TableCell>
                  </TableRow>
                ))}
              </TableBody>
            </Table>
          </div>
        </section>
      )}

      {dialogOpen && <AnnouncementDialog existing={editing} onClose={() => setDialogOpen(false)} />}
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
