/** Per-device sound preferences for the public lobby-TV queue display. */
export type ChimePreset = 'bell' | 'chime' | 'soft' | 'voice';

export interface KioskSettings {
  enabled: boolean;
  preset: ChimePreset;
  volume: number;
}

export const DEFAULT_KIOSK_SETTINGS: KioskSettings = {
  enabled: true,
  preset: 'bell',
  volume: 0.5,
};

export const CHIME_PRESETS: ReadonlyArray<{
  value: ChimePreset;
  label: string;
  description: string;
}> = [
  {
    value: 'bell',
    label: 'Bell',
    description: 'A clear two-note ascending call.',
  },
  {
    value: 'chime',
    label: 'Chime',
    description: 'A bright three-note sequence with a quick finish.',
  },
  {
    value: 'soft',
    label: 'Soft',
    description: 'A single low note for a quieter call.',
  },
  {
    value: 'voice',
    label: 'Voice',
    description: 'Announces the queue number using this browser’s speech synthesis.',
  },
];

const STORAGE_KEY = 'synapse_kiosk_settings';

function isChimePreset(value: unknown): value is ChimePreset {
  return CHIME_PRESETS.some((preset) => preset.value === value);
}

function clampVolume(value: unknown): number {
  if (typeof value !== 'number' || !Number.isFinite(value)) return DEFAULT_KIOSK_SETTINGS.volume;
  return Math.min(1, Math.max(0, value));
}

function normalizeSettings(value: unknown): KioskSettings {
  if (typeof value !== 'object' || value === null) return { ...DEFAULT_KIOSK_SETTINGS };

  const candidate = value as Partial<Record<keyof KioskSettings, unknown>>;
  return {
    enabled: typeof candidate.enabled === 'boolean' ? candidate.enabled : DEFAULT_KIOSK_SETTINGS.enabled,
    preset: isChimePreset(candidate.preset) ? candidate.preset : DEFAULT_KIOSK_SETTINGS.preset,
    volume: clampVolume(candidate.volume),
  };
}

export function loadKioskSettings(): KioskSettings {
  if (typeof window === 'undefined') return { ...DEFAULT_KIOSK_SETTINGS };

  try {
    const raw = window.localStorage.getItem(STORAGE_KEY);
    return raw === null ? { ...DEFAULT_KIOSK_SETTINGS } : normalizeSettings(JSON.parse(raw) as unknown);
  } catch {
    return { ...DEFAULT_KIOSK_SETTINGS };
  }
}

export function saveKioskSettings(settings: KioskSettings): void {
  if (typeof window === 'undefined') return;

  try {
    window.localStorage.setItem(STORAGE_KEY, JSON.stringify(normalizeSettings(settings)));
  } catch {
    // Storage can be unavailable in private or restricted browser contexts.
  }
}
