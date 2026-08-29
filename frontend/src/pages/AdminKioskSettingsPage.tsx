import { ArrowDown, ArrowUp, Eye, Image, Megaphone, Play, RotateCcw, Save, Trash2, Video } from 'lucide-react';
import { useEffect, useMemo, useState } from 'react';
import { toast } from 'sonner';
import { fetchKioskSettings, updateKioskSettings } from '@/api/kioskSettings';
import { ConfirmDialog } from '@/components/ConfirmDialog';
import { KioskMediaLibrary } from '@/components/KioskMediaLibrary';
import { MediaPlaylistPanel } from '@/components/MediaPlaylistPanel';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/card';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select';
import { Tabs, TabsContent, TabsList, TabsTrigger } from '@/components/ui/tabs';
import { Textarea } from '@/components/ui/textarea';
import { playConfiguredChime, unlockAudio } from '@/lib/chime';
import {
  CHIME_PRESETS,
  DEFAULT_KIOSK_SETTINGS,
  createPlaylistItem,
  loadKioskSettings,
  normalizeKioskSettings,
  saveKioskSettings,
  validateKioskSettings,
  type DisplaySettings,
  type KioskSettings,
  type MediaPlaylistItem,
} from '@/lib/kioskSettings';
import type { KioskMediaAsset } from '@/schemas/kioskMedia';

function Switch({ id, label, description, checked, onChange }: { id: string; label: string; description?: string; checked: boolean; onChange: (checked: boolean) => void }) {
  return <div className="flex items-center justify-between gap-4 rounded-lg border p-3"><div><Label htmlFor={id}>{label}</Label>{description !== undefined && <p className="text-xs text-muted-foreground">{description}</p>}</div><input id={id} type="checkbox" checked={checked} onChange={(event) => onChange(event.target.checked)} className="size-5 accent-primary" /></div>;
}

function SelectField<T extends string>({ id, label, value, options, onChange }: { id: string; label: string; value: T; options: readonly T[]; onChange: (value: T) => void }) {
  return <div className="space-y-1.5"><Label htmlFor={id}>{label}</Label><Select value={value} onValueChange={(next) => onChange(next as T)}><SelectTrigger id={id}><SelectValue /></SelectTrigger><SelectContent>{options.map((option) => <SelectItem key={option} value={option}><span className="capitalize">{option.replaceAll('_', ' ')}</span></SelectItem>)}</SelectContent></Select></div>;
}

function NumberField({ id, label, value, min, max, step = 1, onChange }: { id: string; label: string; value: number; min: number; max: number; step?: number; onChange: (value: number) => void }) {
  return <div className="space-y-1.5"><Label htmlFor={id}>{label}</Label><Input id={id} type="number" min={min} max={max} step={step} value={value} onChange={(event) => onChange(Number(event.target.value))} /></div>;
}

function ItemEditor({ item, onChange }: { item: MediaPlaylistItem; onChange: (item: MediaPlaylistItem) => void }) {
  const patch = (updates: Partial<MediaPlaylistItem>) => onChange({ ...item, ...updates } as MediaPlaylistItem);
  return <div className="space-y-4 rounded-xl border bg-muted/10 p-4">
    <div className="grid gap-3 sm:grid-cols-2"><div className="space-y-1.5"><Label htmlFor="media-label">Internal label</Label><Input id="media-label" maxLength={100} value={item.label} onChange={(event) => patch({ label: event.target.value })} /></div>{item.type !== 'video' && <NumberField id="media-duration" label="Duration (seconds)" value={(item.durationMs ?? 10_000) / 1_000} min={2} max={120} onChange={(value) => patch({ durationMs: value * 1_000 })} />}</div>
    <Switch id="media-enabled" label="Enabled" checked={item.enabled} onChange={(enabled) => patch({ enabled })} />
    <div className="grid gap-3 sm:grid-cols-2"><div className="space-y-1.5"><Label htmlFor="media-from">Active from (optional)</Label><Input id="media-from" type="datetime-local" value={item.activeFrom?.slice(0, 16) ?? ''} onChange={(event) => patch({ activeFrom: event.target.value || undefined })} /></div><div className="space-y-1.5"><Label htmlFor="media-until">Active until (optional)</Label><Input id="media-until" type="datetime-local" value={item.activeUntil?.slice(0, 16) ?? ''} onChange={(event) => patch({ activeUntil: event.target.value || undefined })} /></div></div>
    <div className="space-y-1.5"><Label htmlFor="media-caption">Caption (optional)</Label><Input id="media-caption" maxLength={240} value={item.caption ?? ''} onChange={(event) => patch({ caption: event.target.value || undefined })} /></div>
    {item.type === 'announcement' && <>
      <div className="space-y-1.5"><Label htmlFor="announcement-title">Title</Label><Input id="announcement-title" maxLength={120} value={item.title} onChange={(event) => patch({ title: event.target.value })} /></div>
      <div className="space-y-1.5"><Label htmlFor="announcement-body">Body</Label><Textarea id="announcement-body" maxLength={1_200} rows={5} value={item.body} onChange={(event) => patch({ body: event.target.value })} /></div>
      <div className="grid gap-3 sm:grid-cols-2 lg:grid-cols-3"><div className="space-y-1.5"><Label htmlFor="short-label">Short label</Label><Input id="short-label" maxLength={40} value={item.shortLabel ?? ''} onChange={(event) => patch({ shortLabel: event.target.value || undefined })} /></div><SelectField id="announcement-align" label="Text alignment" value={item.alignment} options={['left', 'center']} onChange={(alignment) => patch({ alignment })} /><SelectField id="announcement-size" label="Font size" value={item.fontSize} options={['standard', 'large', 'hero']} onChange={(fontSize) => patch({ fontSize })} /><SelectField id="announcement-fg" label="Foreground" value={item.foreground} options={['light', 'dark', 'brand']} onChange={(foreground) => patch({ foreground })} /><SelectField id="announcement-bg" label="Background" value={item.background} options={['black', 'neutral', 'brand']} onChange={(background) => patch({ background })} /><SelectField id="announcement-emphasis" label="Emphasis" value={item.emphasis} options={['none', 'info', 'important']} onChange={(emphasis) => patch({ emphasis })} /></div>
    </>}
    {item.type === 'photo' && <>
      <div className="space-y-1.5"><Label htmlFor="photo-source">Image URL or public asset path</Label><Input id="photo-source" maxLength={2_048} value={item.source} onChange={(event) => patch({ source: event.target.value })} /></div>
      <Switch id="photo-decorative" label="Decorative image" description="Decorative images may have empty alternative text." checked={item.decorative} onChange={(decorative) => patch({ decorative })} />
      <div className="space-y-1.5"><Label htmlFor="photo-alt">Alternative text</Label><Input id="photo-alt" maxLength={240} value={item.alt} disabled={item.decorative} onChange={(event) => patch({ alt: event.target.value })} /></div>
      <div className="grid gap-3 sm:grid-cols-3"><SelectField id="photo-fit" label="Fit" value={item.fit ?? 'contain'} options={['contain', 'cover']} onChange={(fit) => patch({ fit })} /><SelectField id="photo-focal" label="Focal position" value={item.focalPosition} options={['center', 'top', 'bottom']} onChange={(focalPosition) => patch({ focalPosition })} /><SelectField id="photo-bg" label="Background" value={item.background} options={['black', 'neutral', 'brand']} onChange={(background) => patch({ background })} /></div>
    </>}
    {item.type === 'video' && <>
      <div className="space-y-1.5"><Label htmlFor="video-source">Video URL or public asset path</Label><Input id="video-source" maxLength={2_048} value={item.source} onChange={(event) => patch({ source: event.target.value })} /></div>
      <div className="space-y-1.5"><Label htmlFor="video-poster">Poster image URL (optional)</Label><Input id="video-poster" maxLength={2_048} value={item.poster ?? ''} onChange={(event) => patch({ poster: event.target.value || undefined })} /></div>
      <section aria-labelledby="video-playback-heading" className="space-y-3 rounded-lg border bg-card p-3">
        <div><h3 id="video-playback-heading" className="font-semibold">Video playback</h3><p className="text-xs text-muted-foreground">Choose whether the playlist follows the uploaded file or a fixed display time.</p></div>
        <div className="grid gap-3 sm:grid-cols-2">
          <Switch id="video-natural" label="Use original file duration" description="Advance when the video file reaches its natural end." checked={item.useNaturalDuration} onChange={(useNaturalDuration) => patch({ useNaturalDuration })} />
          <Switch id="video-loop" label="Loop video" description={item.useNaturalDuration ? 'Restart the file until the maximum playback time is reached.' : 'Restart the file until the playlist duration is reached.'} checked={item.loop} onChange={(loop) => patch({ loop })} />
          {item.useNaturalDuration && item.loop && <NumberField id="video-max" label="Total loop time (seconds)" value={item.maxDurationMs / 1_000} min={5} max={300} onChange={(value) => patch({ maxDurationMs: value * 1_000 })} />}
          {item.useNaturalDuration && !item.loop && <div className="rounded-lg border border-dashed p-3 text-sm text-muted-foreground"><p className="font-medium text-foreground">Plays once at the file’s original duration</p><p>The playlist advances automatically when the video ends.</p></div>}
          {!item.useNaturalDuration && <NumberField id="video-duration" label="Playlist duration (seconds)" value={(item.durationMs ?? 10_000) / 1_000} min={2} max={120} onChange={(value) => patch({ durationMs: value * 1_000 })} />}
          <Switch id="video-muted" label="Muted" description="Recommended for reliable TV autoplay." checked={item.muted} onChange={(muted) => patch({ muted })} />
          <SelectField id="video-speed" label="Playback speed" value={String(item.playbackRate) as '0.75' | '1' | '1.25'} options={['0.75', '1', '1.25']} onChange={(speed) => patch({ playbackRate: Number(speed) as 0.75 | 1 | 1.25 })} />
          <SelectField id="video-fit" label="Fit" value={item.fit ?? 'contain'} options={['contain', 'cover']} onChange={(fit) => patch({ fit })} />
        </div>
      </section>
    </>}
  </div>;
}

function TvPreview({ settings }: { settings: KioskSettings }) {
  return <div aria-label="Complete TV layout preview" className="aspect-video overflow-hidden rounded-xl border bg-background p-2 shadow-inner">
    <div className="mb-2 flex h-4 items-center justify-between text-[0.55rem]"><strong>SYNAPSE Queues</strong><span>Foundation University · 10:42 AM</span></div>
    <div className="grid h-[calc(100%-1.5rem)] grid-cols-[2fr_1fr] gap-2">
      <div className="flex min-h-0 flex-col gap-2 overflow-hidden">
        <section aria-label="Now Serving preview" className="h-[44%] shrink-0 overflow-hidden rounded-md border bg-muted/20 p-1.5">
          <h3 className="mb-1 text-center text-[0.5rem] font-bold uppercase tracking-[0.16em] text-muted-foreground">Now Serving</h3>
          <div className="grid h-[calc(100%-0.875rem)] grid-cols-2 gap-1.5">
            <div className="flex min-h-0 flex-col items-center justify-center overflow-hidden rounded border bg-card px-1 text-center"><b className="text-[0.52rem] uppercase text-muted-foreground">Guidance</b><p className="font-mono text-xl font-bold leading-none text-primary">G-012</p><p className="w-full truncate text-[0.55rem]">Ana Dela Cruz</p></div>
            <div className="flex min-h-0 flex-col items-center justify-center overflow-hidden rounded border bg-card px-1 text-center"><b className="text-[0.52rem] uppercase text-muted-foreground">Clinic</b><p className="font-mono text-xl font-bold leading-none text-primary">C-008</p><p className="w-full truncate text-[0.55rem]">Ben Santos</p></div>
          </div>
        </section>
        {settings.display.mediaEnabled
          ? <MediaPlaylistPanel settings={settings} fillContainer />
          : <div className="flex min-h-0 flex-1 items-center justify-center rounded-md border border-dashed text-[0.55rem] text-muted-foreground">Media panel disabled</div>}
      </div>
      <aside className="flex min-h-0 flex-col overflow-hidden rounded-md border bg-card p-2"><b className="text-center text-xs uppercase">Waiting List</b><div className="mt-2 grid min-h-0 flex-1 grid-cols-2 gap-1 overflow-hidden text-[0.55rem]"><div><strong>Guidance · 2</strong><p className="mt-1 rounded bg-muted p-1">G-013<br />Reyes, Cara</p></div><div><strong>Clinic · 3</strong><p className="mt-1 rounded bg-muted p-1">C-009<br />Lim, Daniel</p></div></div></aside>
    </div>
  </div>;
}

export default function AdminKioskSettingsPage() {
  const [saved, setSaved] = useState<KioskSettings>(() => loadKioskSettings());
  const [settings, setSettings] = useState<KioskSettings>(() => loadKioskSettings());
  const [selectedId, setSelectedId] = useState<string | null>(settings.playlist[0]?.id ?? null);
  const [confirmReset, setConfirmReset] = useState(false);
  const [errors, setErrors] = useState<string[]>([]);
  const [revision, setRevision] = useState(0);
  const [remotePending, setRemotePending] = useState(true);
  const selected = settings.playlist.find((item) => item.id === selectedId);
  const dirty = JSON.stringify(settings) !== JSON.stringify(saved);
  const updateDisplay = <Key extends keyof DisplaySettings>(key: Key, value: DisplaySettings[Key]) => setSettings((current) => ({ ...current, display: { ...current.display, [key]: value } }));
  const updateItem = (next: MediaPlaylistItem) => setSettings((current) => ({ ...current, playlist: current.playlist.map((item) => item.id === next.id ? next : item) }));
  const ordered = useMemo(() => [...settings.playlist].sort((a, b) => a.order - b.order), [settings.playlist]);

  useEffect(() => {
    let active = true;
    void fetchKioskSettings().then((snapshot) => {
      if (!active) return;
      setRevision(snapshot.revision);
      if (snapshot.revision > 0) {
        setSettings(snapshot.settings);
        setSaved(snapshot.settings);
        setSelectedId(snapshot.settings.playlist[0]?.id ?? null);
        saveKioskSettings(snapshot.settings);
      } else {
        setSaved(structuredClone(DEFAULT_KIOSK_SETTINGS));
      }
    }).catch(() => toast.warning('Using the local kiosk cache until the server is reachable.')).finally(() => {
      if (active) setRemotePending(false);
    });
    return () => { active = false; };
  }, []);

  function add(type: MediaPlaylistItem['type']) { const item = createPlaylistItem(type, settings.playlist.length); setSettings((current) => ({ ...current, playlist: [...current.playlist, item] })); setSelectedId(item.id); }
  function addAsset(asset: KioskMediaAsset) { const created = createPlaylistItem(asset.kind, settings.playlist.length); const item: MediaPlaylistItem = created.type === 'photo' ? { ...created, label: asset.label, source: asset.url, alt: asset.label } : created.type === 'video' ? { ...created, label: asset.label, source: asset.url } : created; setSettings((current) => ({ ...current, playlist: [...current.playlist, item] })); setSelectedId(item.id); toast.success(`${asset.label} added to the playlist.`); }
  function remove(id: string) { setSettings((current) => ({ ...current, playlist: current.playlist.filter((item) => item.id !== id).map((item, index) => ({ ...item, order: index })) })); if (selectedId === id) setSelectedId(null); }
  function move(id: string, direction: -1 | 1) { const list = [...ordered]; const from = list.findIndex((item) => item.id === id); const to = from + direction; if (from < 0 || to < 0 || to >= list.length) return; [list[from], list[to]] = [list[to]!, list[from]!]; setSettings((current) => ({ ...current, playlist: list.map((item, index) => ({ ...item, order: index })) })); }
  async function save() { const normalized = normalizeKioskSettings(settings); const nextErrors = validateKioskSettings(normalized); setErrors(nextErrors); if (nextErrors.length > 0) { toast.error('Fix playlist validation errors before saving.'); return; } setRemotePending(true); try { const snapshot = await updateKioskSettings(normalized, revision); const result = saveKioskSettings(snapshot.settings); if (!result.ok) throw new Error(result.errors[0] ?? 'Local cache is unavailable.'); setRevision(snapshot.revision); setSettings(snapshot.settings); setSaved(snapshot.settings); toast.success('Kiosk settings saved for every device.'); } catch (error) { toast.error(error instanceof Error ? error.message : 'Could not save kiosk settings.'); } finally { setRemotePending(false); } }
  function resetPlaylist() { setSettings((current) => ({ ...current, playlist: structuredClone(DEFAULT_KIOSK_SETTINGS.playlist) })); setSelectedId(null); setErrors([]); setConfirmReset(false); toast.info('Playlist reset. Save to apply this change.'); }

  return <main className="mx-auto min-w-0 max-w-6xl space-y-5 p-4 sm:p-6">
    <header className="flex flex-wrap items-start justify-between gap-3"><div><div className="flex items-center gap-2"><h1 className="text-xl font-semibold">Kiosk Settings</h1>{dirty && <span className="rounded-full bg-amber-100 px-2 py-0.5 text-xs font-medium text-amber-900">Unsaved changes</span>}</div><p className="text-sm text-muted-foreground">Shared sound, display, and media playlist configuration for every kiosk device.</p></div><div className="flex gap-2"><Button variant="outline" disabled={!dirty || remotePending} onClick={() => { setSettings(saved); setSelectedId(saved.playlist[0]?.id ?? null); setErrors([]); toast.info('Unsaved changes discarded.'); }}>Discard</Button><Button disabled={remotePending} onClick={() => void save()}><Save /> {remotePending ? 'Syncing…' : 'Save'}</Button></div></header>
    {errors.length > 0 && <div role="alert" className="rounded-lg border border-destructive/50 bg-destructive/5 p-3 text-sm text-destructive"><p className="font-semibold">Settings need attention</p><ul className="list-disc pl-5">{errors.map((error) => <li key={error}>{error}</li>)}</ul></div>}
    <Tabs defaultValue="sound"><TabsList><TabsTrigger value="sound">Sound</TabsTrigger><TabsTrigger value="display">Display</TabsTrigger><TabsTrigger value="playlist">Media Playlist</TabsTrigger><TabsTrigger value="preview">Preview</TabsTrigger></TabsList>
      <TabsContent value="sound"><Card><CardHeader><CardTitle>Sound</CardTitle><CardDescription>Queue calls always take audio priority over playlist videos.</CardDescription></CardHeader><CardContent className="space-y-5"><Switch id="sound-enabled" label="Enable call chime" checked={settings.enabled} onChange={(enabled) => setSettings((current) => ({ ...current, enabled }))} /><div className="grid gap-3 sm:grid-cols-2">{CHIME_PRESETS.map((preset) => <label key={preset.value} className="flex gap-3 rounded-lg border p-3"><input type="radio" name="sound" checked={settings.preset === preset.value} onChange={() => setSettings((current) => ({ ...current, preset: preset.value }))} /><span><b className="block">{preset.label}</b><span className="text-xs text-muted-foreground">{preset.description}</span></span></label>)}</div><NumberField id="volume" label="Volume (0–100%)" value={Math.round(settings.volume * 100)} min={0} max={100} onChange={(value) => setSettings((current) => ({ ...current, volume: Math.min(100, Math.max(0, value)) / 100 }))} /><Button variant="outline" onClick={() => { if (!settings.enabled) return toast.warning('Enable sound first.'); unlockAudio(); playConfiguredChime(settings, 'C-008'); }}><Play /> Test sound</Button></CardContent></Card></TabsContent>
      <TabsContent value="display"><Card><CardHeader><CardTitle>Display</CardTitle><CardDescription>Safe numeric ranges are enforced again when saved.</CardDescription></CardHeader><CardContent className="grid gap-4 sm:grid-cols-2"><Switch id="media-panel" label="Enable media panel" checked={settings.display.mediaEnabled} onChange={(value) => updateDisplay('mediaEnabled', value)} /><Switch id="captions" label="Show media captions" checked={settings.display.showCaptions} onChange={(value) => updateDisplay('showCaptions', value)} /><Switch id="progress" label="Show playlist progress" checked={settings.display.showProgress} onChange={(value) => updateDisplay('showProgress', value)} /><Switch id="auto-scroll" label="Automatic waiting-list scrolling" checked={settings.display.autoScroll} onChange={(value) => updateDisplay('autoScroll', value)} /><SelectField id="media-height" label="Media height" value={settings.display.mediaHeight} options={['compact', 'standard', 'large']} onChange={(value) => updateDisplay('mediaHeight', value)} /><SelectField id="transition" label="Playlist transition" value={settings.display.transition} options={['none', 'fade', 'slide']} onChange={(value) => updateDisplay('transition', value)} /><NumberField id="transition-duration" label="Transition duration (ms)" value={settings.display.transitionDurationMs} min={0} max={3_000} step={100} onChange={(value) => updateDisplay('transitionDurationMs', value)} /><NumberField id="default-duration" label="Default item duration (seconds)" value={settings.display.defaultItemDurationMs / 1_000} min={2} max={120} onChange={(value) => updateDisplay('defaultItemDurationMs', value * 1_000)} /><SelectField id="media-background" label="Media background" value={settings.display.background} options={['black', 'neutral', 'brand']} onChange={(value) => updateDisplay('background', value)} /><SelectField id="photo-fit-default" label="Default photo fit" value={settings.display.photoFit} options={['contain', 'cover']} onChange={(value) => updateDisplay('photoFit', value)} /><SelectField id="video-fit-default" label="Default video fit" value={settings.display.videoFit} options={['contain', 'cover']} onChange={(value) => updateDisplay('videoFit', value)} /><SelectField id="waiting-size" label="Waiting-list text size" value={settings.display.waitingTextSize} options={['compact', 'standard', 'large']} onChange={(value) => updateDisplay('waitingTextSize', value)} /><NumberField id="scroll-speed" label="Auto-scroll speed (pixels/second)" value={settings.display.autoScrollSpeed} min={8} max={80} onChange={(value) => updateDisplay('autoScrollSpeed', value)} /></CardContent></Card></TabsContent>
      <TabsContent value="playlist"><Card><CardHeader><CardTitle>Media Playlist</CardTitle><CardDescription>Upload approved files to the server gallery or use HTTPS and same-origin media URLs. Saving updates every kiosk device.</CardDescription></CardHeader><CardContent className="space-y-4"><KioskMediaLibrary onUse={addAsset} /><div className="flex flex-wrap gap-2"><Button variant="outline" onClick={() => add('announcement')}><Megaphone /> Add announcement</Button><Button variant="outline" onClick={() => add('photo')}><Image /> Add photo by URL</Button><Button variant="outline" onClick={() => add('video')}><Video /> Add video by URL</Button><Button variant="outline" onClick={() => setConfirmReset(true)}><RotateCcw /> Reset playlist</Button></div>{ordered.length === 0 && <p className="rounded-lg border border-dashed p-6 text-center text-muted-foreground">The playlist is empty. The public display will show its default placeholder.</p>}<div className="grid gap-4 lg:grid-cols-[18rem_1fr]"><ul className="space-y-2">{ordered.map((item, index) => <li key={item.id} className={`rounded-lg border p-2 ${selectedId === item.id ? 'border-primary bg-primary/5' : ''}`}><button className="w-full text-left" onClick={() => setSelectedId(item.id)}><span className="block truncate font-medium">{item.label}</span><span className="text-xs capitalize text-muted-foreground">{item.type} · {item.enabled ? 'active' : 'disabled'}</span></button><div className="mt-2 flex gap-1"><Button size="icon" variant="ghost" aria-label={`Move ${item.label} up`} disabled={index === 0} onClick={() => move(item.id, -1)}><ArrowUp /></Button><Button size="icon" variant="ghost" aria-label={`Move ${item.label} down`} disabled={index === ordered.length - 1} onClick={() => move(item.id, 1)}><ArrowDown /></Button><Button size="icon" variant="ghost" aria-label={`Preview ${item.label}`} onClick={() => setSelectedId(item.id)}><Eye /></Button><Button size="icon" variant="ghost" aria-label={`Remove ${item.label}`} onClick={() => remove(item.id)}><Trash2 /></Button></div></li>)}</ul><div>{selected !== undefined ? <><ItemEditor item={selected} onChange={updateItem} /><div className="mt-4"><h3 className="mb-2 font-semibold">Item preview</h3><MediaPlaylistPanel settings={settings} previewItem={selected} /></div></> : <p className="text-sm text-muted-foreground">Select an item to edit and preview it.</p>}</div></div></CardContent></Card></TabsContent>
      <TabsContent value="preview"><Card><CardHeader><CardTitle>Complete layout preview</CardTitle><CardDescription>Representative 16:9 preview with sample Guidance and Clinic data. It does not call queue actions.</CardDescription></CardHeader><CardContent><TvPreview settings={settings} /></CardContent></Card></TabsContent>
    </Tabs>
    <ConfirmDialog open={confirmReset} title="Reset media playlist?" description="This removes all playlist items. Sound and display settings are preserved; use Save to apply the reset." confirmLabel="Reset playlist" onConfirm={resetPlaylist} onCancel={() => setConfirmReset(false)} />
  </main>;
}
