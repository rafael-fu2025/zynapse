import { ImageOff, Megaphone, PlayCircle, VideoOff } from 'lucide-react';
import { useCallback, useEffect, useMemo, useRef, useState } from 'react';
import { QUEUE_CALL_MEDIA_EVENT } from '@/lib/chime';
import { activePlaylistItems, isSafeMediaUrl, type KioskSettings, type MediaPlaylistItem } from '@/lib/kioskSettings';
import { cn } from '@/lib/utils';

const HEIGHT_CLASS = {
  compact: 'h-[clamp(12rem,27vh,18rem)] min-h-[clamp(12rem,27vh,18rem)] max-h-[clamp(12rem,27vh,18rem)]',
  standard: 'h-[clamp(15rem,34vh,23rem)] min-h-[clamp(15rem,34vh,23rem)] max-h-[clamp(15rem,34vh,23rem)]',
  large: 'h-[clamp(18rem,40vh,28rem)] min-h-[clamp(18rem,40vh,28rem)] max-h-[clamp(18rem,40vh,28rem)]',
} as const;
const BACKGROUND_CLASS = { black: 'bg-black', neutral: 'bg-muted', brand: 'bg-primary' } as const;

export function MediaPlaceholder({ failure }: { failure?: 'photo' | 'video' }) {
  const Icon = failure === 'photo' ? ImageOff : failure === 'video' ? VideoOff : PlayCircle;
  const heading = failure === 'photo' ? 'Photo unavailable' : failure === 'video' ? 'Video unavailable' : 'Announcements and campus media';
  const detail = failure === undefined ? 'No active playlist item is configured.' : 'Moving to the next active playlist item.';
  return <div role={failure === undefined ? undefined : 'status'} className="flex size-full flex-col items-center justify-center gap-3 bg-gradient-to-br from-muted to-muted/40 p-6 text-center text-muted-foreground">
    <Icon className="size-12 opacity-60" aria-hidden />
    <div><p className="font-semibold text-foreground">{heading}</p><p className="text-sm">{detail}</p></div>
  </div>;
}

function Announcement({ item }: { item: Extract<MediaPlaylistItem, { type: 'announcement' }> }) {
  const foreground = item.background === 'brand' && item.foreground === 'brand'
    ? 'text-white'
    : item.background === 'black' && item.foreground === 'dark'
      ? 'text-white'
      : item.background === 'neutral' && item.foreground === 'light'
        ? 'text-foreground'
        : item.foreground === 'dark' ? 'text-slate-950' : item.foreground === 'brand' ? 'text-primary' : 'text-white';
  const size = item.fontSize === 'hero' ? 'text-3xl lg:text-5xl' : item.fontSize === 'large' ? 'text-2xl lg:text-4xl' : 'text-xl lg:text-3xl';
  return <article aria-label={item.label} className={cn('flex size-full min-h-0 min-w-0 flex-col justify-center overflow-hidden p-8', BACKGROUND_CLASS[item.background], foreground, item.alignment === 'center' ? 'items-center text-center' : 'items-start text-left')}>
    {item.emphasis !== 'none' && <Megaphone className="mb-3 size-8 shrink-0" aria-hidden />}
    {item.shortLabel !== undefined && <p className="mb-2 text-xs font-bold uppercase tracking-[0.24em] opacity-80">{item.shortLabel}</p>}
    <h3 className={cn('max-w-full line-clamp-2 font-bold leading-tight', size)}>{item.title}</h3>
    <p className="mt-3 line-clamp-5 max-w-4xl whitespace-pre-line text-base leading-relaxed opacity-90 lg:text-xl">{item.body}</p>
  </article>;
}

type Failure = { id: string; type: 'photo' | 'video' };

export function MediaPlaylistPanel({ settings, previewItem, fillAvailable = false, fillContainer = false }: { settings: KioskSettings; previewItem?: MediaPlaylistItem; fillAvailable?: boolean; fillContainer?: boolean }) {
  const [clock, setClock] = useState(() => new Date());
  const previewIsSafe = previewItem === undefined || previewItem.type === 'announcement' || (
    isSafeMediaUrl(previewItem.source)
    && (previewItem.type !== 'video' || previewItem.poster === undefined || isSafeMediaUrl(previewItem.poster))
  );
  const items = useMemo(
    () => previewItem === undefined ? activePlaylistItems(settings, clock) : previewIsSafe ? [previewItem] : [],
    [settings, clock, previewItem, previewIsSafe],
  );
  const [index, setIndex] = useState(0);
  const [failed, setFailed] = useState<Set<string>>(() => new Set());
  const [failure, setFailure] = useState<Failure | null>(null);
  const [hidden, setHidden] = useState(() => typeof document !== 'undefined' && document.hidden);
  const [callActive, setCallActive] = useState(false);
  const videoRef = useRef<HTMLVideoElement>(null);
  const failureTimer = useRef<number | null>(null);
  const viable = items.filter((entry) => !failed.has(entry.id));
  const item = viable[index % Math.max(1, viable.length)];

  const advance = useCallback(() => setIndex((current) => viable.length <= 1 ? 0 : (current + 1) % viable.length), [viable.length]);
  const fail = useCallback((entry: MediaPlaylistItem) => {
    if (entry.type === 'announcement' || failureTimer.current !== null) return;
    setFailure({ id: entry.id, type: entry.type });
    failureTimer.current = window.setTimeout(() => {
      setFailed((current) => new Set(current).add(entry.id));
      setFailure(null);
      setIndex(0);
      failureTimer.current = null;
    }, 1_500);
  }, []);

  useEffect(() => {
    setIndex(0);
    setFailed(new Set());
    setFailure(null);
    if (failureTimer.current !== null) window.clearTimeout(failureTimer.current);
    failureTimer.current = null;
  }, [settings.playlist, previewItem]);
  useEffect(() => () => {
    if (failureTimer.current !== null) window.clearTimeout(failureTimer.current);
  }, []);
  useEffect(() => {
    const id = window.setInterval(() => setClock(new Date()), 30_000);
    return () => window.clearInterval(id);
  }, []);
  useEffect(() => {
    const visibility = () => setHidden(document.hidden);
    const priority = (event: Event) => setCallActive(Boolean((event as CustomEvent<{ active?: boolean }>).detail?.active));
    document.addEventListener('visibilitychange', visibility);
    window.addEventListener(QUEUE_CALL_MEDIA_EVENT, priority);
    return () => {
      document.removeEventListener('visibilitychange', visibility);
      window.removeEventListener(QUEUE_CALL_MEDIA_EVENT, priority);
    };
  }, []);
  useEffect(() => {
    const video = videoRef.current;
    if (video === null) return;
    video.muted = callActive || (item?.type === 'video' ? item.muted : true);
    if (callActive || hidden) video.pause();
    else void video.play().catch(() => undefined);
  }, [callActive, hidden, item]);
  useEffect(() => {
    if (hidden || failure !== null || item === undefined || previewItem !== undefined) return;
    if (item.type === 'video' && item.useNaturalDuration && item.loop) {
      const limit = window.setTimeout(advance, item.maxDurationMs);
      return () => window.clearTimeout(limit);
    }
    if (item.type === 'video' && item.useNaturalDuration) return;
    const duration = item.durationMs ?? settings.display.defaultItemDurationMs;
    const timer = window.setTimeout(advance, duration);
    return () => window.clearTimeout(timer);
  }, [advance, failure, hidden, item, previewItem, settings.display.defaultItemDurationMs]);

  if (!settings.display.mediaEnabled && previewItem === undefined) return null;
  const transition = settings.display.transition === 'slide' ? 'animate-in slide-in-from-right-4' : settings.display.transition === 'fade' ? 'animate-in fade-in' : '';
  let shownFailure: 'photo' | 'video' | undefined;
  if (failure !== null && failure.id === item?.id) shownFailure = failure.type;
  else if (!previewIsSafe && previewItem !== undefined) shownFailure = previewItem.type;
  return <section aria-label="Media playlist" className={cn(
    'relative isolate w-full shrink-0 overflow-hidden rounded-2xl border shadow-sm',
    !fillContainer && HEIGHT_CLASS[settings.display.mediaHeight],
    fillAvailable && 'lg:h-auto lg:min-h-0 lg:max-h-none lg:flex-1 lg:shrink',
    fillContainer && 'h-full min-h-0 max-h-none flex-1 shrink rounded-md',
    BACKGROUND_CLASS[settings.display.background],
  )}>
    {shownFailure !== undefined ? <MediaPlaceholder failure={shownFailure} /> : item === undefined ? <MediaPlaceholder /> : <div key={item.id} className={cn('relative size-full motion-reduce:animate-none', transition)} style={{ animationDuration: `${settings.display.transitionDurationMs}ms` }}>
      {item.type === 'announcement' && <Announcement item={item} />}
      {item.type === 'photo' && <div className={cn('size-full overflow-hidden', BACKGROUND_CLASS[item.background])}><img src={item.source} alt={item.decorative ? '' : item.alt} onError={() => fail(item)} className={cn('block size-full', (item.fit ?? settings.display.photoFit) === 'cover' ? 'object-cover' : 'object-contain')} style={{ objectPosition: item.focalPosition }} /></div>}
      {item.type === 'video' && <video ref={videoRef} src={item.source} poster={item.poster} autoPlay playsInline controls={false} loop={item.loop} muted={item.muted || callActive} onEnded={advance} onError={() => fail(item)} onCanPlay={(event) => { event.currentTarget.playbackRate = item.playbackRate; void event.currentTarget.play().catch(() => fail(item)); }} className={cn('block size-full', (item.fit ?? settings.display.videoFit) === 'cover' ? 'object-cover' : 'object-contain')} />}
      {settings.display.showCaptions && item.caption !== undefined && <p className="absolute inset-x-0 bottom-0 bg-black/75 px-4 py-2 text-center text-sm text-white">{item.caption}</p>}
    </div>}
    {settings.display.showProgress && viable.length > 1 && <div aria-label={`Playlist item ${index % viable.length + 1} of ${viable.length}`} className="absolute bottom-3 right-3 flex gap-1.5">{viable.map((entry, itemIndex) => <span key={entry.id} className={cn('size-2 rounded-full ring-1 ring-white/70', itemIndex === index % viable.length ? 'bg-white' : 'bg-white/30')} />)}</div>}
  </section>;
}
