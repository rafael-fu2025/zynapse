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
