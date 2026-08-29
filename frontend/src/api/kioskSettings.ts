import { apiClient } from '@/api/client';
import { normalizeKioskSettings, type KioskSettings } from '@/lib/kioskSettings';

export interface KioskSettingsSnapshot {
  settings: KioskSettings;
  revision: number;
  updated_at: string | null;
}

function snapshot(value: unknown, fallbackRevision = 0): KioskSettingsSnapshot {
  const record = value as Record<string, unknown>;
  return {
    settings: normalizeKioskSettings(record.settings),
    revision: typeof record.revision === 'number' ? record.revision : fallbackRevision,
    updated_at: typeof record.updated_at === 'string' ? record.updated_at : null,
  };
}

export async function fetchKioskSettings(): Promise<KioskSettingsSnapshot> {
  return snapshot((await apiClient.get<unknown>('/kiosk-settings')).data);
}

export async function updateKioskSettings(
  settings: KioskSettings,
  revision: number,
): Promise<KioskSettingsSnapshot> {
  return snapshot((await apiClient.post<unknown>('/admin/kiosk-settings', { settings, revision })).data, revision + 1);
}
