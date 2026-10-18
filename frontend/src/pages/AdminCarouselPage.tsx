/**
 * AdminCarouselPage — RBAC-gated carousel management.
 *
 * Lists every slide (active + hidden) and supports create / edit /
 * delete / toggle / reorder.
 *
 * Four behaviours ported from `humus/app/Views/admin/carousel/`:
 *   1. Drag-and-drop reordering — native HTML5 DnD on the row (a
 *      `draggable` handle keeps the buttons clickable), persisting on
 *      drop via `POST /admin/carousel/reorder`.
 *   2. Drag-and-drop upload — `ImageDropzone`, no new dependency.
 *   3. Upload preview — object-URL thumbnail before submit, revoked on
 *      replace so the blob is not pinned for the tab's lifetime.
 *   4. Real upload progress — axios `onUploadProgress`, with a
 *      "Processing…" phase once the body is flushed (the server still
 *      has to sniff/move/persist, which the browser cannot measure).
 *
 * Keyboard parity: every drag action also has a button, because native
 * DnD is mouse-only. AGENTS.md forbids new dependencies without asking,
 * so no dnd-kit/sortable here.
 *
 * Image rules mirror the backend (CarouselService):
 *   - PNG / JPEG / WEBP / GIF only; NO application size ceiling — PHP's
 *     upload_max_filesize / post_max_size own the limit and report it
 *   - Light image required on create; dark optional
 *   - `dark_image: null` clears the field; `undefined` leaves it alone
 */
import { zodResolver } from '@hookform/resolvers/zod';
import {
  ArrowDown,
  ArrowUp,
  Eye,
  EyeOff,
  GripVertical,
  ImageOff,
  Loader2,
  Pencil,
  Plus,
  Trash2,
} from 'lucide-react';
import { useCallback, useRef, useState } from 'react';
import { useForm } from 'react-hook-form';
import { z } from 'zod';
import { ApiEnvelopeError } from '@/api/envelope';
import { humanizeCode } from '@/api/errorCodes';
import { ConfirmDialog } from '@/components/ConfirmDialog';
import { ImageDropzone } from '@/components/ImageDropzone';
import { PageHeader } from '@/components/PageHeader';
import { QueryErrorState } from '@/components/QueryErrorState';
import { TableStateBlock } from '@/components/TableStates';
import { UploadProgress } from '@/components/UploadProgress';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
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
import {
  useCarouselAdminList,
  useCreateCarouselSlide,
  useDeleteCarouselSlide,
  useReorderCarouselSlides,
  useToggleCarouselSlide,
  useUpdateCarouselSlide,
} from '@/hooks/useCarouselAdmin';
import { slideImageUrl, type CarouselSlide, type FitMode } from '@/schemas/carousel';
import { toast } from 'sonner';

const formSchema = z.object({
  title: z.string().max(150).optional().or(z.literal('')),
  subtitle: z.string().max(255).optional().or(z.literal('')),
  fitMode: z.enum(['cover', 'contain_blur']),
  isActive: z.boolean(),
});
type FormValues = z.infer<typeof formSchema>;

export default function AdminCarouselPage() {
  const list = useCarouselAdminList();
  const [progress, setProgress] = useState<{ percent: number; processing: boolean; visible: boolean }>({
    percent: 0,
    processing: false,
    visible: false,
  });
  // The mutation closes over `setProgress` at render time; a ref keeps
  // the callbacks stable so a progress tick never re-creates the hook's
  // mutation function mid-flight.
  const progressRef = useRef(progress);
  progressRef.current = progress;
  const onProgress = useCallback((percent: number) => {
    setProgress((prev) => ({ ...prev, percent, processing: percent >= 100 }));
  }, []);

  const create = useCreateCarouselSlide(onProgress);
  const update = useUpdateCarouselSlide(onProgress);
  const del = useDeleteCarouselSlide();
  const toggle = useToggleCarouselSlide();
  const reorder = useReorderCarouselSlides();

  const [editing, setEditing] = useState<CarouselSlide | null>(null);
  const [dialogOpen, setDialogOpen] = useState(false);
  const [confirmDelete, setConfirmDelete] = useState<CarouselSlide | null>(null);
  const [lightFile, setLightFile] = useState<File | null>(null);
  const [darkFile, setDarkFile] = useState<File | null>(null);
  const [clearDark, setClearDark] = useState(false);
  const [formError, setFormError] = useState<string | null>(null);
  const [draggingId, setDraggingId] = useState<number | null>(null);
  const [dragOverId, setDragOverId] = useState<number | null>(null);

  const form = useForm<FormValues>({
    resolver: zodResolver(formSchema),
    defaultValues: { title: '', subtitle: '', fitMode: 'cover', isActive: true },
  });

  const resetDialog = useCallback(() => {
    setDialogOpen(false);
    setEditing(null);
    setLightFile(null);
    setDarkFile(null);
    setClearDark(false);
    setFormError(null);
    setProgress({ percent: 0, processing: false, visible: false });
  }, []);

  const openCreate = useCallback(() => {
    setEditing(null);
    setLightFile(null);
    setDarkFile(null);
    setClearDark(false);
    setFormError(null);
    setProgress({ percent: 0, processing: false, visible: false });
    form.reset({ title: '', subtitle: '', fitMode: 'cover', isActive: true });
    setDialogOpen(true);
  }, [form]);

  const openEdit = useCallback(
    (slide: CarouselSlide) => {
      setEditing(slide);
      setLightFile(null);
      setDarkFile(null);
      setClearDark(false);
      setFormError(null);
      setProgress({ percent: 0, processing: false, visible: false });
      form.reset({
        title: slide.title ?? '',
        subtitle: slide.subtitle ?? '',
        fitMode: slide.fit_mode,
        isActive: slide.is_active !== false,
      });
      setDialogOpen(true);
    },
    [form],
  );

  const isUploading = progress.visible && !progress.processing;
  const isBusy = create.isPending || update.isPending;

  const submit = form.handleSubmit(async (values) => {
    setFormError(null);
    if (editing === null && lightFile === null) {
      setFormError('A light-mode image is required to create a slide.');
      return;
    }

    const payload = {
      title: values.title === '' ? '' : values.title,
      subtitle: values.subtitle === '' ? '' : values.subtitle,
      fitMode: values.fitMode,
      isActive: values.isActive,
      // Preserve existing images when editing without uploading replacements
      lightImage: lightFile ?? (editing !== null ? editing.light_image : undefined),
      darkImage: darkFile ?? (clearDark ? null : (editing !== null ? editing.dark_image : undefined)),
    };

    setProgress({ percent: 0, processing: false, visible: true });
    try {
      if (editing === null) {
        await create.mutateAsync(payload);
        toast.success('Slide created.');
      } else {
        await update.mutateAsync({ id: editing.id, input: payload });
        toast.success('Slide updated.');
      }
      resetDialog();
    } catch (err) {
      setProgress((prev) => ({ ...prev, visible: false }));
      const message = describeError(err);
      setFormError(message);
      toast.error(message);
    }
  });

  const onToggle = async (slide: CarouselSlide) => {
    try {
      await toggle.mutateAsync(slide.id);
      toast.success(`Slide ${slide.is_active ? 'hidden' : 'shown'}.`);
    } catch (err) {
      toast.error(describeError(err));
    }
  };

  const onDelete = async () => {
    if (confirmDelete === null) return;
    try {
      await del.mutateAsync(confirmDelete.id);
      toast.success('Slide deleted.');
      setConfirmDelete(null);
    } catch (err) {
      toast.error(describeError(err));
    }
  };

  const persistOrder = useCallback(
    async (ordered: CarouselSlide[]) => {
      try {
        await reorder.mutateAsync(ordered.map((s) => s.id));
      } catch (err) {
        toast.error(describeError(err));
        // Roll the list back to server truth so the DOM does not keep
        // showing an order the backend rejected.
        void list.refetch();
      }
    },
    [list, reorder],
  );

  /** Keyboard parity for the drag handle. */
  const onMove = useCallback(
    (slide: CarouselSlide, direction: -1 | 1) => {
      if (list.data === undefined) return;
      const rows = [...list.data];
      const idx = rows.findIndex((r) => r.id === slide.id);
      const next = idx + direction;
      if (idx < 0 || next < 0 || next >= rows.length) return;
      const tmp = rows[idx];
      const target = rows[next];
      if (tmp === undefined || target === undefined) return;
      rows[idx] = target;
      rows[next] = tmp;
      void persistOrder(rows);
    },
    [list.data, persistOrder],
  );

  /**
   * Native HTML5 DnD reorder — the same algorithm as humus's
   * `getDragAfterElement`: on `dragover`, find the first non-dragging
   * row whose vertical midpoint is below the cursor and insert before
   * it (append when there is none). Reordering the live DOM is what
   * makes the row appear to follow the cursor.
   */
  const onRowDragOver = useCallback((e: React.DragEvent, overId: number) => {
    e.preventDefault();
    setDragOverId(overId);
  }, []);

  const onRowDrop = useCallback(
    (e: React.DragEvent, overId: number) => {
      e.preventDefault();
      setDragOverId(null);
      if (draggingId === null || list.data === undefined || draggingId === overId) return;
      const rows = [...list.data];
      const from = rows.findIndex((r) => r.id === draggingId);
      const to = rows.findIndex((r) => r.id === overId);
      if (from < 0 || to < 0) return;
      const [moved] = rows.splice(from, 1);
      if (moved === undefined) return;
      rows.splice(to, 0, moved);
      void persistOrder(rows);
    },
    [draggingId, list.data, persistOrder],
  );

  const onRowDragEnd = useCallback(() => {
    setDraggingId(null);
    setDragOverId(null);
  }, []);

  const rows = list.data;

  return (
    <main className="space-y-4 p-6">
      <PageHeader
        title="Login Carousel"
        description="Curate the photos and copy shown on the welcome pane of the login screen. Drag a row to reorder."
        actions={
          <Button onClick={openCreate}>
            <Plus aria-hidden className="size-4" />
            New slide
          </Button>
        }
      />

      {list.isError && (
        <QueryErrorState message="Could not load carousel slides." onRetry={() => void list.refetch()} />
      )}

      {list.isLoading && (
        <TableStateBlock
          isLoading
          isError={false}
          isEmpty={false}
          onRetry={() => void list.refetch()}
          loadingLabel="Loading slides"
          empty={{ title: 'Loading slides' }}
        />
      )}

      {rows !== undefined && rows.length === 0 && !list.isLoading && !list.isError && (
        <TableStateBlock
          isLoading={false}
          isError={false}
          isEmpty
          onRetry={() => void list.refetch()}
          empty={{
            title: 'No slides yet',
            description: 'Add the first slide to start the carousel.',
            action: (
              <Button onClick={openCreate}>
                <Plus aria-hidden className="size-4" />
                New slide
              </Button>
            ),
          }}
        />
      )}

      {rows !== undefined && rows.length > 0 && (
        <div className="overflow-x-auto border border-border bg-card">
          <table aria-label="Carousel slides" className="w-full text-sm">
            <thead className="border-b border-border bg-muted/40 text-left text-xs uppercase tracking-wide text-muted-foreground">
              <tr>
                <th scope="col" className="w-10 px-3 py-2 font-medium">
                  <span className="sr-only">Reorder</span>
                </th>
                <th scope="col" className="w-12 px-3 py-2 font-medium">#</th>
                <th scope="col" className="px-3 py-2 font-medium">Preview</th>
                <th scope="col" className="px-3 py-2 font-medium">Title</th>
                <th scope="col" className="px-3 py-2 font-medium">Fit</th>
                <th scope="col" className="px-3 py-2 font-medium">Status</th>
                <th scope="col" className="px-3 py-2 font-medium text-right">Actions</th>
              </tr>
            </thead>
            <tbody>
              {rows.map((slide, idx) => (
                <tr
                  key={slide.id}
                  draggable={!isBusy}
                  onDragStart={(e) => {
                    // Firefox needs data set for the drag to start at all.
                    e.dataTransfer.setData('text/plain', String(slide.id));
                    e.dataTransfer.effectAllowed = 'move';
                    setDraggingId(slide.id);
                  }}
                  onDragOver={(e) => onRowDragOver(e, slide.id)}
                  onDrop={(e) => onRowDrop(e, slide.id)}
                  onDragEnd={onRowDragEnd}
                  className={[
                    'border-b border-border transition-colors last:border-0',
                    draggingId === slide.id && 'opacity-40',
                    dragOverId === slide.id && draggingId !== null && draggingId !== slide.id && 'bg-primary/5',
                  ]
                    .filter(Boolean)
                    .join(' ')}
                >
                  <td className="px-3 py-2 align-middle">
                    <span
                      className="flex cursor-grab items-center text-muted-foreground active:cursor-grabbing"
                      aria-hidden
                      title="Drag to reorder"
                    >
                      <GripVertical className="size-4" />
                    </span>
                  </td>
                  <td className="px-3 py-2 align-middle font-mono text-xs text-muted-foreground">{idx + 1}</td>
                  <td className="px-3 py-2 align-middle">
                    <Thumb src={slideImageUrl(slide.light_image)} alt={slide.title ?? ''} />
                  </td>
                  <td className="px-3 py-2 align-middle">
                    <div className="font-medium">
                      {slide.title ?? <span className="italic text-muted-foreground">(no title)</span>}
                    </div>
                    {slide.subtitle !== null && slide.subtitle !== '' && (
                      <div className="text-xs text-muted-foreground">{slide.subtitle}</div>
                    )}
                  </td>
                  <td className="px-3 py-2 align-middle">
                    <Badge variant="outline">{slide.fit_mode}</Badge>
                  </td>
                  <td className="px-3 py-2 align-middle">
                    {slide.is_active ? (
                      <Badge className="bg-emerald-600 text-white hover:bg-emerald-600">Active</Badge>
                    ) : (
                      <Badge variant="secondary">Hidden</Badge>
                    )}
                  </td>
                  <td className="px-3 py-2 align-middle">
                    <div className="flex items-center justify-end gap-1">
                      <Button
                        type="button"
                        variant="ghost"
                        size="icon"
                        disabled={idx === 0 || reorder.isPending}
                        onClick={() => onMove(slide, -1)}
                        aria-label={`Move slide ${idx + 1} up`}
                      >
                        <ArrowUp aria-hidden className="size-4" />
                      </Button>
                      <Button
                        type="button"
                        variant="ghost"
                        size="icon"
                        disabled={idx === rows.length - 1 || reorder.isPending}
                        onClick={() => onMove(slide, 1)}
                        aria-label={`Move slide ${idx + 1} down`}
                      >
                        <ArrowDown aria-hidden className="size-4" />
                      </Button>
                      <Button
                        type="button"
                        variant="ghost"
                        size="icon"
                        onClick={() => void onToggle(slide)}
                        disabled={toggle.isPending}
                        aria-label={slide.is_active ? `Hide slide ${idx + 1}` : `Show slide ${idx + 1}`}
                      >
                        {slide.is_active ? <EyeOff aria-hidden className="size-4" /> : <Eye aria-hidden className="size-4" />}
                      </Button>
                      <Button
                        type="button"
                        variant="ghost"
                        size="icon"
                        onClick={() => openEdit(slide)}
                        aria-label={`Edit slide ${idx + 1}`}
                      >
                        <Pencil aria-hidden className="size-4" />
                      </Button>
                      <Button
                        type="button"
                        variant="ghost"
                        size="icon"
                        onClick={() => setConfirmDelete(slide)}
                        aria-label={`Delete slide ${idx + 1}`}
                        className="text-destructive hover:text-destructive"
                      >
                        <Trash2 aria-hidden className="size-4" />
                      </Button>
                    </div>
                  </td>
                </tr>
              ))}
            </tbody>
          </table>
        </div>
      )}

      <Dialog
        open={dialogOpen}
        onOpenChange={(open) => {
          if (!open && !isBusy) resetDialog();
        }}
      >
        <DialogContent className="max-h-[90vh] max-w-xl overflow-y-auto">
          <DialogHeader>
            <DialogTitle>{editing === null ? 'New carousel slide' : 'Edit carousel slide'}</DialogTitle>
            <DialogDescription>
              The light image shows in light theme, the dark image (optional) shows in dark theme. Leave the dark
              image empty to reuse the light image.
            </DialogDescription>
          </DialogHeader>
          <form onSubmit={(e) => void submit(e)} className="space-y-4">
            {formError !== null && (
              <div
                role="alert"
                className="rounded-lg border border-destructive/40 bg-destructive/10 p-3 text-sm text-destructive"
              >
                {formError}
              </div>
            )}

            <div className="space-y-1.5">
              <Label htmlFor="title">Title</Label>
              <Input id="title" {...form.register('title')} maxLength={150} disabled={isBusy} />
            </div>

            <div className="space-y-1.5">
              <Label htmlFor="subtitle">Subtitle</Label>
              <Input id="subtitle" {...form.register('subtitle')} maxLength={255} disabled={isBusy} />
            </div>

            <div className="space-y-1.5">
              <Label htmlFor="fitMode">Fit mode</Label>
              <Select
                value={form.watch('fitMode')}
                onValueChange={(v) => form.setValue('fitMode', v as FitMode, { shouldDirty: true })}
                disabled={isBusy}
              >
                <SelectTrigger id="fitMode">
                  <SelectValue />
                </SelectTrigger>
                <SelectContent>
                  <SelectItem value="cover">Cover — full-bleed, no blur</SelectItem>
                  <SelectItem value="contain_blur">Contain + blur — letterboxed logo on blurred backdrop</SelectItem>
                </SelectContent>
              </Select>
            </div>

            <div className="grid gap-4 sm:grid-cols-2">
              <ImageDropzone
                file={lightFile}
                currentUrl={editing !== null ? slideImageUrl(editing.light_image) : undefined}
                label="Light mode image"
                variant="light"
                required={editing === null}
                disabled={isBusy}
                hint={editing !== null && lightFile === null ? 'Leave empty to keep the current image.' : undefined}
                onPick={setLightFile}
                onError={setFormError}
              />

              <ImageDropzone
                file={darkFile}
                currentUrl={editing !== null && editing.dark_image !== null ? slideImageUrl(editing.dark_image) : undefined}
                label="Dark mode image"
                variant="dark"
                disabled={isBusy}
                hint="Shown when dark mode is active. Leave empty to reuse the light image."
                onPick={(f) => {
                  setDarkFile(f);
                  if (f !== null) setClearDark(false);
                }}
                onError={setFormError}
              />
            </div>

            {editing !== null && editing.dark_image !== null && (
              <div className="flex items-center gap-2">
                <Checkbox
                  id="clearDark"
                  checked={clearDark}
                  disabled={isBusy || darkFile !== null}
                  onCheckedChange={(v) => setClearDark(v === true)}
                />
                <Label htmlFor="clearDark" className="text-sm font-normal">
                  Clear the stored dark image (use the light image instead)
                </Label>
              </div>
            )}

            <div className="flex items-center gap-2">
              <Checkbox
                id="isActive"
                checked={form.watch('isActive')}
                disabled={isBusy}
                onCheckedChange={(v) => form.setValue('isActive', v === true, { shouldDirty: true })}
              />
              <Label htmlFor="isActive" className="text-sm font-normal">
                Show this slide on the login screen
              </Label>
            </div>

            <UploadProgress
              percent={progress.percent}
              processing={progress.processing}
              visible={progress.visible}
            />

            <DialogFooter>
              <Button type="button" variant="ghost" onClick={resetDialog} disabled={isBusy}>
                Cancel
              </Button>
              <Button type="submit" disabled={isBusy || (isUploading && !progress.processing)}>
                {(isBusy || isUploading) && <Loader2 aria-hidden className="size-4 animate-spin" />}
                {editing === null ? 'Create slide' : 'Save changes'}
              </Button>
            </DialogFooter>
          </form>
        </DialogContent>
      </Dialog>

      <ConfirmDialog
        open={confirmDelete !== null}
        title="Delete this slide?"
        description={
          confirmDelete !== null
            ? `“${confirmDelete.title ?? 'Untitled slide'}” will be removed from the carousel. The image file is also deleted once nothing else references it.`
            : 'This slide will be removed from the carousel.'
        }
        confirmLabel="Delete"
        destructive
        pending={del.isPending}
        onConfirm={() => void onDelete()}
        onCancel={() => setConfirmDelete(null)}
      />
    </main>
  );
}

function describeError(err: unknown): string {
  if (err instanceof ApiEnvelopeError) {
    const primary = err.errors[0];
    if (primary === undefined) return 'The carousel change failed.';
    const friendly = humanizeCode(primary.code);
    return friendly !== primary.code ? friendly : (primary.message ?? 'The carousel change failed.');
  }
  return 'The carousel change failed unexpectedly.';
}

function Thumb({ src, alt }: { src: string; alt: string }): JSX.Element {
  const [failed, setFailed] = useState(false);
  if (failed) {
    return (
      <span className="grid size-12 place-items-center rounded bg-muted text-muted-foreground" aria-label={alt}>
        <ImageOff aria-hidden className="size-4" />
      </span>
    );
  }
  return (
    <img
      src={src}
      alt={alt}
      loading="lazy"
      onError={() => setFailed(true)}
      className="size-12 rounded object-cover ring-1 ring-border"
    />
  );
}