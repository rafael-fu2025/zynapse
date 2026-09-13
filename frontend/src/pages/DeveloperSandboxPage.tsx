/**
 * DeveloperSandboxPage — request explorer for TEST keys (2026-09, D7),
 * modeled on the FU MIS sandbox. Superadmin-only (`api_apps.manage`).
 *
 * Execution is server-side (POST /developer/sandbox/execute) with a key
 * ID — the raw secret never exists in the browser, preserving hash-only
 * storage. The explorer re-runs the real scope checks + tenant pinning
 * against the synthetic sandbox tenant.
 */
import { FlaskConical, Send } from 'lucide-react';
import { useMemo, useState } from 'react';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { PageHeader } from '@/components/PageHeader';
import {
  Select,
  SelectContent,
  SelectItem,
  SelectTrigger,
  SelectValue,
} from '@/components/ui/select';
import { QueryErrorState } from '@/components/QueryErrorState';
import { useDeveloperApps, useDeveloperKeys, useSandboxExecute } from '@/hooks/useDeveloperApps';

const SANDBOX_PATHS: ReadonlyArray<{ path: string; label: string; query: boolean }> = [
  { path: 'ping', label: 'GET /ping', query: false },
  { path: 'me/scopes', label: 'GET /me/scopes', query: false },
  { path: 'aggregates/visits', label: 'GET /aggregates/visits', query: true },
  { path: 'aggregates/referrals', label: 'GET /aggregates/referrals', query: true },
];

export default function DeveloperSandboxPage() {
  const apps = useDeveloperApps();
  const [appId, setAppId] = useState<number | null>(null);
  const keys = useDeveloperKeys(appId);
  const testKeys = useMemo(() => (keys.data ?? []).filter((key) => key.env === 'test'), [keys.data]);
  const [keyId, setKeyId] = useState<number | null>(null);
  const [path, setPath] = useState<string>(SANDBOX_PATHS[0]?.path ?? 'ping');
  const [start, setStart] = useState('');
  const [end, setEnd] = useState('');
  const execute = useSandboxExecute();

  const activePath = SANDBOX_PATHS.find((p) => p.path === path) ?? SANDBOX_PATHS[0]!;
  const needsQuery = activePath.query;
  const query = useMemo(() => {
    const q: Record<string, string> = {};
    if (start.trim() !== '') q.start = start.trim();
    if (end.trim() !== '') q.end = end.trim();
    return q;
  }, [start, end]);

  function send() {
    if (keyId === null) return;
    execute.mutate({
      key_id: keyId,
      method: 'GET',
      path: path,
      query,
      body: {},
    });
  }

  return (
    <main className="mx-auto min-w-0 max-w-5xl space-y-5 p-4 sm:p-6">
      <PageHeader
        title="API Sandbox"
        description="Exercise the external v1 endpoints as one of your TEST keys against the synthetic sandbox tenant — no secret required."
        actions={<Badge variant="outline" className="gap-1.5"><FlaskConical className="size-3.5" aria-hidden /> sandbox tenant</Badge>}
      />

      {apps.isError && (
        <QueryErrorState message="Failed to load API apps." onRetry={() => void apps.refetch()} pending={apps.isFetching} />
      )}

      <section className="space-y-4 rounded-xl border bg-card p-5" aria-labelledby="sandbox-request">
        <h2 id="sandbox-request" className="text-sm font-semibold">Request</h2>
        <div className="grid gap-4 sm:grid-cols-2">
          <div className="space-y-1.5">
            <Label htmlFor="sandbox-app">App</Label>
            <Select
              {...(appId === null ? {} : { value: String(appId) })}
              onValueChange={(value) => { setAppId(Number(value)); setKeyId(null); }}
            >
              <SelectTrigger id="sandbox-app" disabled={apps.isLoading}>
                <SelectValue placeholder={apps.isLoading ? 'Loading…' : 'Select an app'} />
              </SelectTrigger>
              <SelectContent>
                {(apps.data ?? []).map((app) => (
                  <SelectItem key={app.id} value={String(app.id)}>{app.name}</SelectItem>
                ))}
              </SelectContent>
            </Select>
          </div>
          <div className="space-y-1.5">
            <Label htmlFor="sandbox-key">Test key</Label>
            <Select
              {...(keyId === null ? {} : { value: String(keyId) })}
              onValueChange={(value) => setKeyId(Number(value))}
              disabled={appId === null}
            >
              <SelectTrigger id="sandbox-key">
                <SelectValue placeholder={appId === null ? 'Pick an app first' : 'Select a test key'} />
              </SelectTrigger>
              <SelectContent>
                {testKeys.map((key) => (
                  <SelectItem key={key.id} value={String(key.id)}>
                    {key.prefix}··{key.last4} ({key.status})
                  </SelectItem>
                ))}
              </SelectContent>
            </Select>
          </div>
          <div className="space-y-1.5 sm:col-span-2">
            <Label htmlFor="sandbox-path">Endpoint</Label>
            <Select value={path} onValueChange={setPath}>
              <SelectTrigger id="sandbox-path"><SelectValue /></SelectTrigger>
              <SelectContent>
                {SANDBOX_PATHS.map((p) => (
                  <SelectItem key={p.path} value={p.path}>{p.label}</SelectItem>
                ))}
              </SelectContent>
            </Select>
          </div>
          {needsQuery && (
            <>
              <div className="space-y-1.5">
                <Label htmlFor="sandbox-start">start <span className="text-muted-foreground">(YYYY-MM-DD, optional)</span></Label>
                <Input id="sandbox-start" value={start} onChange={(e) => setStart(e.target.value)} placeholder="2026-09-01" />
              </div>
              <div className="space-y-1.5">
                <Label htmlFor="sandbox-end">end <span className="text-muted-foreground">(YYYY-MM-DD, optional)</span></Label>
                <Input id="sandbox-end" value={end} onChange={(e) => setEnd(e.target.value)} placeholder="2026-09-30" />
              </div>
            </>
          )}
        </div>
        <div className="flex items-center justify-between gap-3">
          <p className="text-xs text-muted-foreground">
            Execution is server-side with the selected key id — the secret itself is never needed.
          </p>
          <Button onClick={send} disabled={keyId === null || execute.isPending}>
            <Send aria-hidden /> {execute.isPending ? 'Sending…' : 'Send'}
          </Button>
        </div>
      </section>

      {execute.data !== undefined && (
        <section className="space-y-3 rounded-xl border bg-card p-5" aria-labelledby="sandbox-response">
          <div className="flex items-center justify-between gap-3">
            <h2 id="sandbox-response" className="text-sm font-semibold">Response</h2>
            <Badge variant={execute.data.status < 400 ? 'success' : 'destructive'}>HTTP {execute.data.status}</Badge>
          </div>
          {Object.keys(execute.data.headers).length > 0 && (
            <div className="rounded-lg bg-muted p-3 font-mono text-xs">
              {Object.entries(execute.data.headers).map(([name, value]) => (
                <p key={name}>{name}: {value}</p>
              ))}
            </div>
          )}
          <pre className="max-h-96 overflow-auto rounded-lg bg-muted p-4 font-mono text-xs leading-relaxed">
            {JSON.stringify(execute.data.body, null, 2)}
          </pre>
        </section>
      )}
    </main>
  );
}
