import { useEffect, useState } from 'react';
import { fetchKioskSettings } from '@/api/kioskSettings';
import {
  KIOSK_SETTINGS_STORAGE_KEY,
  loadKioskSettings,
  normalizeKioskSettings,
  saveKioskSettings,
  type KioskSettings,
} from '@/lib/kioskSettings';

/** Keeps a display synchronized with saves and cross-tab localStorage writes. */
export function useKioskSettings(): KioskSettings {
  const [settings, setSettings] = useState<KioskSettings>(() => loadKioskSettings());
  useEffect(() => {
    let active = true;
    const sync = () => {
      void fetchKioskSettings().then((snapshot) => {
        if (!active || snapshot.revision === 0) return;
        // `normalizeKioskSettings` builds fresh arrays every poll, so the
        // object identity always differs. Pushing it into state (and
        // localStorage) on every 15 s tick reset every consumer keyed on
        // identity — the lobby playlist restarted from item 0 mid-loop
        // (2026-09 audit). Only commit when the CONTENT changed.
        const next = JSON.stringify(snapshot.settings);
        const current = JSON.stringify(loadKioskSettings());
        if (next === current) return;
        saveKioskSettings(snapshot.settings);
        setSettings(snapshot.settings);
      }).catch(() => { /* Keep the last local cache while offline. */ });
    };
    const storage = (event: StorageEvent) => {
      if (event.key === KIOSK_SETTINGS_STORAGE_KEY) setSettings(loadKioskSettings());
    };
    const local = (event: Event) => setSettings(normalizeKioskSettings((event as CustomEvent).detail));
    window.addEventListener('storage', storage);
    window.addEventListener('synapse:kiosk-settings', local);
    sync();
    const timer = window.setInterval(sync, 15_000);
    return () => {
      active = false;
      window.clearInterval(timer);
      window.removeEventListener('storage', storage);
      window.removeEventListener('synapse:kiosk-settings', local);
    };
  }, []);
  return settings;
}
