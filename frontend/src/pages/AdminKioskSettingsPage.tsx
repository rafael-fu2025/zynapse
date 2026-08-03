/** Per-device sound controls for the public lobby-TV queue display. */
import { Play, Save } from 'lucide-react';
import { useState } from 'react';
import { toast } from 'sonner';
import { Button } from '@/components/ui/button';
import {
  Card,
  CardContent,
  CardDescription,
  CardFooter,
  CardHeader,
  CardTitle,
} from '@/components/ui/card';
import { Label } from '@/components/ui/label';
import { CHIME_PRESETS, loadKioskSettings, type KioskSettings, saveKioskSettings } from '@/lib/kioskSettings';
import { playConfiguredChime, unlockAudio } from '@/lib/chime';

function percent(volume: number): number {
  return Math.round(volume * 100);
}

export default function AdminKioskSettingsPage() {
  const [settings, setSettings] = useState<KioskSettings>(() => loadKioskSettings());

  function updateSetting<Key extends keyof KioskSettings>(key: Key, value: KioskSettings[Key]): void {
    setSettings((current) => ({ ...current, [key]: value }));
  }

  function testSound(): void {
    if (!settings.enabled) {
      toast.warning('Enable call chime before testing sound.');
      return;
    }

    unlockAudio();
    playConfiguredChime(settings, 'C-008');
    toast.success(`Tested ${settings.preset} sound at ${percent(settings.volume)}% volume.`);
  }

  function save(): void {
    saveKioskSettings(settings);
    toast.success('Kiosk settings saved for this device.');
  }

  return (
    <main className="mx-auto min-w-0 max-w-4xl space-y-5 p-4 sm:p-6">
      <header>
        <h1 className="text-xl font-semibold text-foreground">Kiosk Settings</h1>
        <p className="max-w-2xl text-sm text-muted-foreground">
          Configure the lobby TV behaviour for this device.
        </p>
      </header>

      <Card>
        <CardHeader>
          <CardTitle>Sound</CardTitle>
          <CardDescription>
            Choose what patients hear when the next queue number is called.
          </CardDescription>
        </CardHeader>
        <CardContent className="space-y-7">
          <div className="flex items-center justify-between gap-4 rounded-lg border bg-muted/20 p-4">
            <div className="space-y-1">
              <Label htmlFor="kiosk-sound-enabled">Enable call chime</Label>
              <p id="kiosk-sound-enabled-description" className="text-sm text-muted-foreground">
                Play a sound or voice announcement when the now-serving number changes.
              </p>
            </div>
            <label htmlFor="kiosk-sound-enabled" className="shrink-0 cursor-pointer">
              <input
                id="kiosk-sound-enabled"
                type="checkbox"
                checked={settings.enabled}
                onChange={(event) => updateSetting('enabled', event.target.checked)}
                aria-describedby="kiosk-sound-enabled-description"
                className="peer sr-only"
              />
              <span
                aria-hidden
                className="relative block h-6 w-11 rounded-full bg-muted transition-colors after:absolute after:left-1 after:top-1 after:block after:size-4 after:rounded-full after:bg-background after:shadow-sm after:transition-transform peer-checked:bg-primary peer-checked:after:translate-x-5 peer-focus-visible:outline-none peer-focus-visible:ring-2 peer-focus-visible:ring-ring peer-focus-visible:ring-offset-2"
              />
            </label>
          </div>

          <fieldset className="space-y-3" aria-describedby="kiosk-preset-description">
            <legend className="text-sm font-medium">Voice preset</legend>
            <p id="kiosk-preset-description" className="text-sm text-muted-foreground">
              Select the call style used by the queue display.
            </p>
            <div className="grid gap-3 sm:grid-cols-2" role="radiogroup" aria-label="Voice preset">
              {CHIME_PRESETS.map((preset) => (
                <label
                  key={preset.value}
                  htmlFor={`kiosk-preset-${preset.value}`}
                  className={`flex cursor-pointer items-start gap-3 rounded-lg border p-3 transition-colors focus-within:ring-2 focus-within:ring-ring focus-within:ring-offset-2 ${
                    settings.preset === preset.value
                      ? 'border-primary bg-primary/5'
                      : 'bg-background hover:bg-accent/50'
                  }`}
                >
                  <input
                    id={`kiosk-preset-${preset.value}`}
                    type="radio"
                    name="kiosk-preset"
                    value={preset.value}
                    checked={settings.preset === preset.value}
                    onChange={() => updateSetting('preset', preset.value)}
                    className="mt-0.5 size-4 shrink-0 accent-primary"
                  />
                  <span className="min-w-0">
                    <span className="block text-sm font-medium text-foreground">{preset.label}</span>
                    <span className="mt-1 block text-xs leading-relaxed text-muted-foreground">
                      {preset.description}
                    </span>
                  </span>
                </label>
              ))}
            </div>
          </fieldset>

          <div className="space-y-3">
            <div className="flex items-center justify-between gap-4">
              <Label htmlFor="kiosk-volume">Volume</Label>
              <output htmlFor="kiosk-volume" className="font-mono text-sm tabular-nums text-muted-foreground">
                {percent(settings.volume)}%
              </output>
            </div>
            <input
              id="kiosk-volume"
              type="range"
              min="0"
              max="100"
              step="1"
              value={percent(settings.volume)}
              onChange={(event) => updateSetting('volume', Number(event.target.value) / 100)}
              aria-label="Volume"
              aria-valuetext={`${percent(settings.volume)} percent`}
              className="h-2 w-full cursor-pointer accent-primary"
            />
            <div className="flex justify-between text-xs text-muted-foreground" aria-hidden>
              <span>0%</span>
              <span>100%</span>
            </div>
          </div>

          <div className="flex flex-col items-start justify-between gap-3 rounded-lg border bg-muted/20 p-4 sm:flex-row sm:items-center">
            <div>
              <p className="text-sm font-medium">Preview the selected sound</p>
              <p className="mt-1 text-xs text-muted-foreground">
                The preview announces C-008 when Voice is selected.
              </p>
            </div>
            <Button type="button" variant="outline" onClick={testSound}>
              <Play aria-hidden /> Test sound
            </Button>
          </div>
        </CardContent>
        <CardFooter className="justify-end border-t pt-6">
          <Button type="button" onClick={save}>
            <Save aria-hidden /> Save
          </Button>
        </CardFooter>
      </Card>
    </main>
  );
}
