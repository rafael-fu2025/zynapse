/** Versioned, per-device settings for the public lobby display. */
export type ChimePreset = 'bell' | 'chime' | 'soft' | 'voice';
export type MediaTransition = 'none' | 'fade' | 'slide';
export type MediaFit = 'contain' | 'cover';
export type MediaBackground = 'black' | 'neutral' | 'brand';
export type WaitingTextSize = 'compact' | 'standard' | 'large';
export type MediaHeightPreset = 'compact' | 'standard' | 'large';

export interface DisplaySettings {
  mediaEnabled: boolean;
  mediaHeight: MediaHeightPreset;
  transition: MediaTransition;
  transitionDurationMs: number;
  defaultItemDurationMs: number;
  background: MediaBackground;
  photoFit: MediaFit;
  videoFit: MediaFit;
  showCaptions: boolean;
  showProgress: boolean;
  waitingTextSize: WaitingTextSize;
  autoScroll: boolean;
  autoScrollSpeed: number;
}

interface PlaylistItemBase {
  id: string;
  type: 'announcement' | 'photo' | 'video';
  label: string;
  enabled: boolean;
  order: number;
  caption?: string | undefined;
  activeFrom?: string | undefined;
  activeUntil?: string | undefined;
  durationMs?: number | undefined;
}

export interface AnnouncementItem extends PlaylistItemBase {
  type: 'announcement';
  title: string;
  body: string;
  shortLabel?: string | undefined;
  alignment: 'left' | 'center';
  fontSize: 'standard' | 'large' | 'hero';
  foreground: 'light' | 'dark' | 'brand';
  background: MediaBackground;
  emphasis: 'none' | 'info' | 'important';
}

export interface PhotoItem extends PlaylistItemBase {
  type: 'photo';
  source: string;
  alt: string;
  decorative: boolean;
  fit?: MediaFit | undefined;
  focalPosition: 'center' | 'top' | 'bottom';
  background: MediaBackground;
}

export interface VideoItem extends PlaylistItemBase {
  type: 'video';
  source: string;
  poster?: string | undefined;
  fit?: MediaFit | undefined;
  muted: boolean;
  loop: boolean;
  playbackRate: 0.75 | 1 | 1.25;
  useNaturalDuration: boolean;
  maxDurationMs: number;
}

export type MediaPlaylistItem = AnnouncementItem | PhotoItem | VideoItem;

export interface KioskSettings {
  version: 2;
  // Kept at the top level so existing chime callers and sound-only saves migrate safely.
  enabled: boolean;
  preset: ChimePreset;
  volume: number;
  display: DisplaySettings;
  playlist: MediaPlaylistItem[];
}

export const KIOSK_SETTINGS_STORAGE_KEY = 'synapse_kiosk_settings';

export const DEFAULT_DISPLAY_SETTINGS: DisplaySettings = {
  mediaEnabled: true,
  mediaHeight: 'standard',
  transition: 'fade',
  transitionDurationMs: 500,
  defaultItemDurationMs: 10_000,
  background: 'black',
  photoFit: 'contain',
  videoFit: 'contain',
  showCaptions: true,
  showProgress: true,
  waitingTextSize: 'standard',
  autoScroll: false,
  autoScrollSpeed: 24,
};

export const DEFAULT_KIOSK_SETTINGS: KioskSettings = {
  version: 2,
  enabled: true,
  preset: 'bell',
  volume: 0.5,
  display: DEFAULT_DISPLAY_SETTINGS,
  playlist: [],
};

export const CHIME_PRESETS = [
  { value: 'bell', label: 'Bell', description: 'A clear two-note ascending call.' },
  { value: 'chime', label: 'Chime', description: 'A bright three-note sequence with a quick finish.' },
  { value: 'soft', label: 'Soft', description: 'A single low note for a quieter call.' },
  { value: 'voice', label: 'Voice', description: 'Announces the queue number using this browser’s speech synthesis.' },
] as const satisfies ReadonlyArray<{ value: ChimePreset; label: string; description: string }>;

const CHIME_VALUES: readonly string[] = CHIME_PRESETS.map((item) => item.value);
const TRANSITIONS: readonly string[] = ['none', 'fade', 'slide'];
const FITS: readonly string[] = ['contain', 'cover'];
const BACKGROUNDS: readonly string[] = ['black', 'neutral', 'brand'];
const HEIGHTS: readonly string[] = ['compact', 'standard', 'large'];
const TEXT_SIZES: readonly string[] = ['compact', 'standard', 'large'];

function oneOf<T extends string>(value: unknown, values: readonly string[], fallback: T): T {
  return typeof value === 'string' && values.includes(value) ? value as T : fallback;
}

function clamp(value: unknown, min: number, max: number, fallback: number): number {
  return typeof value === 'number' && Number.isFinite(value)
    ? Math.min(max, Math.max(min, value))
    : fallback;
}

function text(value: unknown, max: number, fallback = ''): string {
  return typeof value === 'string' ? value.slice(0, max) : fallback;
}

function optionalText(value: unknown, max: number): string | undefined {
  const result = text(value, max).trim();
  return result === '' ? undefined : result;
}

function optionalDate(value: unknown): string | undefined {
  if (typeof value !== 'string' || value.trim() === '' || Number.isNaN(Date.parse(value))) return undefined;
  return new Date(value).toISOString();
}

export function isSafeMediaUrl(value: string): boolean {
  const source = value.trim();
  if (source.startsWith('/') && !source.startsWith('//')) return true;
  try {
    const url = new URL(source);
    if (url.protocol === 'https:') return true;
    return typeof window !== 'undefined' && url.protocol === 'http:' && url.origin === window.location.origin;
  } catch {
    return false;
  }
}

function normalizeItem(value: unknown, index: number): MediaPlaylistItem | null {
  if (typeof value !== 'object' || value === null) return null;
  const item = value as Record<string, unknown>;
  if (!['announcement', 'photo', 'video'].includes(String(item.type))) return null;
  const base = {
    id: text(item.id, 80, `media-${index + 1}`) || `media-${index + 1}`,
    label: text(item.label, 100, `Playlist item ${index + 1}`),
    enabled: typeof item.enabled === 'boolean' ? item.enabled : true,
    order: Math.round(clamp(item.order, 0, 10_000, index)),
    caption: optionalText(item.caption, 240),
    activeFrom: optionalDate(item.activeFrom),
    activeUntil: optionalDate(item.activeUntil),
    durationMs: item.durationMs === undefined ? undefined : Math.round(clamp(item.durationMs, 2_000, 120_000, 10_000)),
  };

  if (item.type === 'announcement') {
    return {
      ...base, type: 'announcement', title: text(item.title, 120), body: text(item.body, 1_200),
      shortLabel: optionalText(item.shortLabel, 40),
      alignment: oneOf(item.alignment, ['left', 'center'], 'center'),
      fontSize: oneOf(item.fontSize, ['standard', 'large', 'hero'], 'large'),
      foreground: oneOf(item.foreground, ['light', 'dark', 'brand'], 'light'),
      background: oneOf(item.background, BACKGROUNDS, 'brand'),
      emphasis: oneOf(item.emphasis, ['none', 'info', 'important'], 'none'),
    };
  }
  if (item.type === 'photo') {
    return {
      ...base, type: 'photo', source: text(item.source, 2_048), alt: text(item.alt, 240),
      decorative: item.decorative === true, fit: oneOf(item.fit, FITS, 'contain'),
      focalPosition: oneOf(item.focalPosition, ['center', 'top', 'bottom'], 'center'),
      background: oneOf(item.background, BACKGROUNDS, 'black'),
    };
  }
  return {
    ...base, type: 'video', source: text(item.source, 2_048), poster: optionalText(item.poster, 2_048),
    fit: oneOf(item.fit, FITS, 'contain'), muted: item.muted !== false, loop: item.loop === true,
    playbackRate: [0.75, 1, 1.25].includes(Number(item.playbackRate))
      ? Number(item.playbackRate) as 0.75 | 1 | 1.25
      : 1,
    useNaturalDuration: item.useNaturalDuration === true,
    maxDurationMs: Math.round(clamp(item.maxDurationMs, 5_000, 300_000, 60_000)),
  };
}

export function normalizeKioskSettings(value: unknown): KioskSettings {
  if (typeof value !== 'object' || value === null) return structuredClone(DEFAULT_KIOSK_SETTINGS);
  const candidate = value as Record<string, unknown>;
  const displayValue = typeof candidate.display === 'object' && candidate.display !== null
    ? candidate.display as Record<string, unknown> : {};
  const display: DisplaySettings = {
    mediaEnabled: typeof displayValue.mediaEnabled === 'boolean' ? displayValue.mediaEnabled : true,
    mediaHeight: oneOf(displayValue.mediaHeight, HEIGHTS, 'standard'),
    transition: oneOf(displayValue.transition, TRANSITIONS, 'fade'),
    transitionDurationMs: Math.round(clamp(displayValue.transitionDurationMs, 0, 3_000, 500)),
    defaultItemDurationMs: Math.round(clamp(displayValue.defaultItemDurationMs, 2_000, 120_000, 10_000)),
    background: oneOf(displayValue.background, BACKGROUNDS, 'black'),
    photoFit: oneOf(displayValue.photoFit, FITS, 'contain'),
    videoFit: oneOf(displayValue.videoFit, FITS, 'contain'),
    showCaptions: typeof displayValue.showCaptions === 'boolean' ? displayValue.showCaptions : true,
    showProgress: typeof displayValue.showProgress === 'boolean' ? displayValue.showProgress : true,
    waitingTextSize: oneOf(displayValue.waitingTextSize, TEXT_SIZES, 'standard'),
    autoScroll: displayValue.autoScroll === true,
    autoScrollSpeed: Math.round(clamp(displayValue.autoScrollSpeed, 8, 80, 24)),
  };
  const rawItems = Array.isArray(candidate.playlist) ? candidate.playlist : [];
  const seen = new Set<string>();
  const playlist = rawItems.flatMap((item, index) => {
    const normalized = normalizeItem(item, index);
    if (normalized === null || seen.has(normalized.id)) return [];
    seen.add(normalized.id);
    return [normalized];
  }).sort((a, b) => a.order - b.order);
  return {
    version: 2,
    enabled: typeof candidate.enabled === 'boolean' ? candidate.enabled : true,
    preset: oneOf(candidate.preset, CHIME_VALUES, 'bell'),
    volume: clamp(candidate.volume, 0, 1, 0.5),
    display,
    playlist,
  };
}

export function validateKioskSettings(settings: KioskSettings): string[] {
  const errors: string[] = [];
  const ids = new Set<string>();
  for (const item of settings.playlist) {
    if (ids.has(item.id)) errors.push(`Duplicate playlist ID: ${item.id}.`);
    ids.add(item.id);
    if (item.label.trim() === '') errors.push('Every playlist item needs an internal label.');
    if (item.activeFrom !== undefined && item.activeUntil !== undefined && Date.parse(item.activeFrom) >= Date.parse(item.activeUntil)) {
      errors.push(`${item.label}: active-until must be later than active-from.`);
    }
    if (item.type === 'announcement' && (item.title.trim() === '' || item.body.trim() === '')) errors.push(`${item.label}: announcement title and body are required.`);
    if (item.type === 'photo') {
      if (!isSafeMediaUrl(item.source)) errors.push(`${item.label}: use an HTTPS or same-origin image URL.`);
      if (!item.decorative && item.alt.trim() === '') errors.push(`${item.label}: meaningful alternative text is required.`);
    }
    if (item.type === 'video') {
      if (!isSafeMediaUrl(item.source)) errors.push(`${item.label}: use an HTTPS or same-origin video URL.`);
      if (item.poster !== undefined && !isSafeMediaUrl(item.poster)) errors.push(`${item.label}: poster must use HTTPS or a same-origin path.`);
    }
  }
  return errors;
}

export function activePlaylistItems(settings: KioskSettings, at = new Date()): MediaPlaylistItem[] {
  const time = at.getTime();
  return settings.playlist
    .filter((item) => item.enabled)
    .filter((item) => item.activeFrom === undefined || Date.parse(item.activeFrom) <= time)
    .filter((item) => item.activeUntil === undefined || Date.parse(item.activeUntil) > time)
    .filter((item) => item.type === 'announcement' || (
      isSafeMediaUrl(item.source)
      && (item.type !== 'video' || item.poster === undefined || isSafeMediaUrl(item.poster))
    ))
    .sort((a, b) => a.order - b.order);
}

export function loadKioskSettings(): KioskSettings {
  if (typeof window === 'undefined') return structuredClone(DEFAULT_KIOSK_SETTINGS);
  try {
    const raw = window.localStorage.getItem(KIOSK_SETTINGS_STORAGE_KEY);
    return raw === null ? structuredClone(DEFAULT_KIOSK_SETTINGS) : normalizeKioskSettings(JSON.parse(raw) as unknown);
  } catch {
    return structuredClone(DEFAULT_KIOSK_SETTINGS);
  }
}

export function saveKioskSettings(settings: KioskSettings): { ok: boolean; errors: string[] } {
  const normalized = normalizeKioskSettings(settings);
  const errors = validateKioskSettings(normalized);
  if (errors.length > 0 || typeof window === 'undefined') return { ok: false, errors };
  try {
    window.localStorage.setItem(KIOSK_SETTINGS_STORAGE_KEY, JSON.stringify(normalized));
    window.dispatchEvent(new CustomEvent('synapse:kiosk-settings', { detail: normalized }));
    return { ok: true, errors: [] };
  } catch {
    return { ok: false, errors: ['Browser storage is unavailable on this device.'] };
  }
}

export function createPlaylistItem(type: MediaPlaylistItem['type'], order: number): MediaPlaylistItem {
  const base = { id: crypto.randomUUID(), label: `New ${type}`, enabled: true, order, durationMs: 10_000 };
  if (type === 'announcement') return { ...base, type, title: 'Announcement', body: 'Enter announcement details.', alignment: 'center', fontSize: 'large', foreground: 'light', background: 'brand', emphasis: 'none' };
  if (type === 'photo') return { ...base, type, source: '/synapse-maroon.png', alt: 'SYNAPSE logo', decorative: false, fit: 'contain', focalPosition: 'center', background: 'neutral' };
  return { ...base, type, source: '/sample-lobby-video.mp4', muted: true, loop: false, playbackRate: 1, useNaturalDuration: true, maxDurationMs: 60_000, fit: 'contain' };
}
