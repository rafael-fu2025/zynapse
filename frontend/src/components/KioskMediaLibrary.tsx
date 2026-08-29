import { Archive, Image, Loader2, RotateCcw, Search, SlidersHorizontal, Upload, Video, X } from 'lucide-react';
import { useEffect, useMemo, useRef, useState } from 'react';
import { toast } from 'sonner';
import { ConfirmDialog } from '@/components/ConfirmDialog';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select';
import { useKioskMedia, useSetKioskMediaArchived, useUploadKioskMedia } from '@/hooks/useKioskMedia';
import type { KioskMediaUploadProgress } from '@/hooks/useKioskMedia';
import type { KioskMediaAsset } from '@/schemas/kioskMedia';

function formatBytes(bytes: number): string {
  if (bytes <= 0) return '0 KB';
  if (bytes >= 1024 * 1024) return `${(bytes / (1024 * 1024)).toFixed(1)} MB`;
  return `${Math.max(1, Math.round(bytes / 1024))} KB`;
}

function formatDuration(seconds: number): string {
  if (seconds < 60) return `${Math.max(1, Math.ceil(seconds))} sec remaining`;
  return `${Math.ceil(seconds / 60)} min remaining`;
}

function KioskMediaThumbnail({ asset }: { asset: KioskMediaAsset }) {
  const [loaded, setLoaded] = useState(false);
  const [failed, setFailed] = useState(false);
  useEffect(() => { setLoaded(false); setFailed(false); }, [asset.id, asset.thumbnail_url, asset.url]);
  const source = asset.thumbnail_url ?? asset.url;
  return <div className="relative grid aspect-video place-items-center overflow-hidden bg-muted">
    {!loaded && !failed && <Loader2 className="size-5 animate-spin text-muted-foreground" aria-label="Loading thumbnail" />}
    {failed
      ? <div className="flex flex-col items-center gap-1 text-xs text-muted-foreground">{asset.kind === 'photo' ? <Image className="size-6" /> : <Video className="size-6" />}<span>Preview unavailable</span></div>
      : <img src={source} alt="" onLoad={() => setLoaded(true)} onError={() => setFailed(true)} className={`absolute inset-0 size-full object-contain transition-opacity ${loaded ? 'opacity-100' : 'opacity-0'}`} />}
  </div>;
}

export function KioskMediaLibrary({ onUse }: { onUse: (asset: KioskMediaAsset) => void }) {
  const [search, setSearch] = useState('');
  const [kindFilter, setKindFilter] = useState<'all' | 'photo' | 'video'>('all');
  const [archiveFilter, setArchiveFilter] = useState<'active' | 'archived' | 'all'>('active');
  const [sortOrder, setSortOrder] = useState<'newest' | 'oldest' | 'name'>('newest');
  const [label, setLabel] = useState('');
  const [files, setFiles] = useState<File[]>([]);
  const [uploadProgress, setUploadProgress] = useState<KioskMediaUploadProgress | null>(null);
  const [recentAssetIds, setRecentAssetIds] = useState<number[]>([]);
  const [confirmAsset, setConfirmAsset] = useState<KioskMediaAsset | null>(null);
  const inputRef = useRef<HTMLInputElement>(null);
  const includeArchived = archiveFilter !== 'active';
  const media = useKioskMedia(includeArchived, search);
  const upload = useUploadKioskMedia();
  const archive = useSetKioskMediaArchived();
  const selectedBytes = files.reduce((sum, file) => sum + file.size, 0);
  const filteredItems = useMemo(() => {
    const items = (media.data?.items ?? []).filter((asset) =>
      (kindFilter === 'all' || asset.kind === kindFilter)
      && (archiveFilter === 'all' || (archiveFilter === 'archived' ? asset.archived : !asset.archived)));
    return [...items].sort((a, b) => {
      if (sortOrder === 'name') return a.label.localeCompare(b.label);
      const direction = sortOrder === 'newest' ? -1 : 1;
      return a.created_at.localeCompare(b.created_at) * direction;
    });
  }, [archiveFilter, kindFilter, media.data?.items, sortOrder]);
  const filtersActive = search !== '' || kindFilter !== 'all' || archiveFilter !== 'active' || sortOrder !== 'newest';

  function submit(event: React.FormEvent) {
    event.preventDefault();
    if (files.length === 0) return;
    const photoTypes = ['image/jpeg', 'image/png', 'image/webp', 'image/gif'];
    const videoTypes = ['video/mp4', 'video/webm'];
    const invalidFiles = files.filter((file) => !photoTypes.includes(file.type) && !videoTypes.includes(file.type));
    if (invalidFiles.length > 0) {
      toast.error(`Unsupported file${invalidFiles.length === 1 ? '' : 's'}: ${invalidFiles.map((file) => file.name).join(', ')}`);
      return;
    }
    setUploadProgress({ loaded: 0, total: Math.max(1, selectedBytes), percent: 0, phase: 'uploading', bytesPerSecond: null, estimatedSeconds: null, fileIndex: 0, fileCount: files.length, fileName: files[0]?.name ?? 'media file' });
    upload.mutate({ files, label, onProgress: setUploadProgress }, {
      onSuccess: ({ assets }) => {
        setUploadProgress(null);
        setFiles([]);
        setLabel('');
        setSearch('');
        setKindFilter('all');
        setArchiveFilter('active');
        setSortOrder('newest');
        setRecentAssetIds(assets.map((asset) => asset.id));
        if (inputRef.current !== null) inputRef.current.value = '';
      },
      onError: () => setUploadProgress(null),
    });
  }

  return <section aria-labelledby="media-gallery-heading" className="space-y-4 rounded-xl border bg-muted/10 p-4">
    <div><h3 id="media-gallery-heading" className="font-semibold">Upload and media gallery</h3><p className="text-sm text-muted-foreground">Allowed formats: JPEG, PNG, WebP, GIF, MP4, and WebM. There is no application upload-size limit.</p></div>
    <form onSubmit={submit} className="grid items-end gap-3 lg:grid-cols-[1.2fr_1fr_auto]">
      <div className="space-y-1.5"><Label htmlFor="kiosk-media-file">Photos or videos</Label><Input ref={inputRef} id="kiosk-media-file" aria-label="Photo or video file" type="file" multiple accept="image/jpeg,image/png,image/webp,image/gif,video/mp4,video/webm" required disabled={upload.isPending} className="sr-only" onChange={(event) => setFiles(Array.from(event.target.files ?? []))} /><label htmlFor="kiosk-media-file" className={`flex min-h-10 items-center gap-3 rounded-md border border-dashed bg-background px-3 py-2 text-sm transition-colors ${upload.isPending ? 'cursor-not-allowed opacity-50' : 'cursor-pointer hover:border-primary hover:bg-accent/40'}`}><span className="grid size-8 shrink-0 place-items-center rounded-md bg-primary/10 text-primary"><Upload className="size-4" aria-hidden /></span><span className="min-w-0"><span className="block font-medium">{files.length === 0 ? 'Choose photos or videos' : `${files.length} file${files.length === 1 ? '' : 's'} selected`}</span><span className="block truncate text-xs text-muted-foreground">{files.length === 0 ? 'Select one or multiple files' : `${files.map((file) => file.name).join(', ')} · ${formatBytes(selectedBytes)}`}</span></span></label></div>
      <div className="space-y-1.5"><Label htmlFor="kiosk-media-label">Gallery label (single file only)</Label><Input id="kiosk-media-label" aria-label="Gallery label (optional)" maxLength={120} value={label} placeholder={files.length > 1 ? 'Multiple files use their filenames' : 'Uses the filename when blank'} disabled={upload.isPending || files.length > 1} onChange={(event) => setLabel(event.target.value)} /></div>
      <div className="flex gap-2"><Button type="submit" disabled={files.length === 0 || upload.isPending}>{upload.isPending ? <Loader2 className="animate-spin" /> : <Upload />} {upload.isPending && uploadProgress !== null ? (uploadProgress.phase === 'processing' ? 'Saving media…' : `Uploading ${uploadProgress.percent}%`) : files.length > 1 ? `Upload ${files.length} files` : 'Upload'}</Button>{files.length > 0 && !upload.isPending && <Button type="button" variant="outline" size="icon" aria-label="Clear selected files" onClick={() => { setFiles([]); if (inputRef.current !== null) inputRef.current.value = ''; }}><X /></Button>}</div>
    </form>
    {upload.isPending && uploadProgress !== null && <div className="space-y-2 rounded-lg border bg-card p-3" role="status" aria-label="Media upload progress">
      <div className="flex min-w-0 items-center justify-between gap-3 text-sm"><p className="truncate font-medium">{uploadProgress.phase === 'processing' ? `Saving ${uploadProgress.fileName}` : `Uploading ${uploadProgress.fileName}`} {uploadProgress.fileCount > 1 ? `(${uploadProgress.fileIndex + 1} of ${uploadProgress.fileCount})` : ''}</p><span className="shrink-0 font-mono font-semibold tabular-nums">{uploadProgress.percent}%</span></div>
      <div role="progressbar" aria-label="File upload" aria-valuemin={0} aria-valuemax={100} aria-valuenow={uploadProgress.percent} className="h-2 overflow-hidden rounded-full bg-muted">
        <div className="h-full rounded-full bg-primary transition-[width] duration-200 motion-reduce:transition-none" style={{ width: `${uploadProgress.percent}%` }} />
      </div>
      <p className="text-xs text-muted-foreground">{uploadProgress.phase === 'processing'
        ? 'Transfer complete. The server is saving the media to the gallery…'
        : <>{formatBytes(uploadProgress.loaded)} of {formatBytes(uploadProgress.total)} transferred{uploadProgress.bytesPerSecond !== null ? ` at ${formatBytes(uploadProgress.bytesPerSecond)}/s` : ''}{uploadProgress.estimatedSeconds !== null ? ` · ${formatDuration(uploadProgress.estimatedSeconds)}` : ''}.</>}</p>
    </div>}
    <div className="space-y-3 rounded-lg border bg-card p-3">
      <div className="flex items-center gap-2"><SlidersHorizontal className="size-4 text-muted-foreground" aria-hidden /><p className="text-sm font-medium">Organize gallery</p>{media.data !== undefined && <span className="ml-auto text-xs text-muted-foreground">Showing {filteredItems.length} of {media.data.total}</span>}</div>
      <div className="grid gap-2 sm:grid-cols-2 xl:grid-cols-[minmax(14rem,1fr)_10rem_10rem_10rem_auto]">
        <div className="relative"><Search className="pointer-events-none absolute left-3 top-1/2 size-4 -translate-y-1/2 text-muted-foreground" aria-hidden /><Input aria-label="Search media gallery" value={search} onChange={(event) => setSearch(event.target.value)} placeholder="Search name or label" className="pl-9" /></div>
        <Select value={kindFilter} onValueChange={(value) => setKindFilter(value as typeof kindFilter)}><SelectTrigger aria-label="Filter by media type"><SelectValue /></SelectTrigger><SelectContent><SelectItem value="all">All media</SelectItem><SelectItem value="photo">Photos</SelectItem><SelectItem value="video">Videos</SelectItem></SelectContent></Select>
        <Select value={archiveFilter} onValueChange={(value) => setArchiveFilter(value as typeof archiveFilter)}><SelectTrigger aria-label="Filter by archive status"><SelectValue /></SelectTrigger><SelectContent><SelectItem value="active">Active</SelectItem><SelectItem value="archived">Archived</SelectItem><SelectItem value="all">All statuses</SelectItem></SelectContent></Select>
        <Select value={sortOrder} onValueChange={(value) => setSortOrder(value as typeof sortOrder)}><SelectTrigger aria-label="Sort gallery"><SelectValue /></SelectTrigger><SelectContent><SelectItem value="newest">Newest first</SelectItem><SelectItem value="oldest">Oldest first</SelectItem><SelectItem value="name">Name A–Z</SelectItem></SelectContent></Select>
        <Button type="button" variant="outline" disabled={!filtersActive} onClick={() => { setSearch(''); setKindFilter('all'); setArchiveFilter('active'); setSortOrder('newest'); }}>Clear filters</Button>
      </div>
    </div>
    {media.isLoading && <div className="flex items-center gap-2 text-sm text-muted-foreground"><Loader2 className="size-4 animate-spin" /> Loading gallery…</div>}
    {media.isError && <p role="alert" className="text-sm text-destructive">Could not load the media gallery. {media.error.errors[0]?.message}</p>}
    {media.data !== undefined && filteredItems.length === 0 && <p className="rounded-lg border border-dashed p-5 text-center text-sm text-muted-foreground">No media matches this gallery view.</p>}
    {filteredItems.length > 0 && <ul className="grid gap-3 sm:grid-cols-2 xl:grid-cols-3">{filteredItems.map((asset) => <li key={asset.id} className={`overflow-hidden rounded-lg border bg-background ${asset.archived ? 'opacity-60' : ''} ${recentAssetIds.includes(asset.id) ? 'border-primary ring-2 ring-primary/25' : ''}`}>
      <KioskMediaThumbnail asset={asset} />
      <div className="space-y-2 p-3"><div className="flex min-w-0 items-start gap-2">{asset.kind === 'photo' ? <Image className="mt-0.5 size-4 shrink-0" aria-hidden /> : <Video className="mt-0.5 size-4 shrink-0" aria-hidden />}<div className="min-w-0 flex-1"><div className="flex items-center gap-2"><p className="min-w-0 flex-1 truncate font-medium">{asset.label}</p>{recentAssetIds.includes(asset.id) && <span className="shrink-0 rounded-full bg-primary/10 px-2 py-0.5 text-[10px] font-semibold text-primary">Just uploaded</span>}</div><p className="truncate text-xs text-muted-foreground">{asset.original_name} · {formatBytes(asset.size_bytes)}</p></div></div>
        <div className="flex flex-wrap gap-2"><Button size="sm" disabled={asset.archived} onClick={() => onUse(asset)}>Add to playlist</Button>{asset.archived ? <Button size="sm" variant="outline" disabled={archive.isPending} onClick={() => archive.mutate({ asset, archived: false })}><RotateCcw /> Restore</Button> : <Button size="sm" variant="outline" onClick={() => setConfirmAsset(asset)}><Archive /> Archive</Button>}</div>
      </div>
    </li>)}</ul>}
    <ConfirmDialog open={confirmAsset !== null} title="Archive this media?" description="It will disappear from the active gallery, but existing playlist URLs continue to work. You can restore it later." confirmLabel="Archive media" onConfirm={() => { if (confirmAsset !== null) archive.mutate({ asset: confirmAsset, archived: true }); setConfirmAsset(null); }} onCancel={() => setConfirmAsset(null)} />
  </section>;
}
