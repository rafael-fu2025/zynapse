/**
 * DeveloperAppsPage — register external API apps and manage their keys
 * (2026-09, D7). Superadmin surface gated by `api_apps.manage`.
 *
 * The apps list is a single-open accordion: each row expands in place
 * to reveal its keys (fetched lazily only while expanded). The full key
 * secret is displayed ONCE at issue time (hash-only storage server-
 * side); the tables show prefix + last4 only. Live keys require a
 * recorded data-sharing acknowledgement (RA 10173 posture — see
 * docs/DATA-SHARING.md).
 */
import { ChevronDown, KeyRound, Plug, Plus } from 'lucide-react';
import { Fragment, useState } from 'react';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import { ConfirmDialog, type ConfirmAction } from '@/components/ConfirmDialog';
import { CopyButton } from '@/components/CopyButton';
import { QueryErrorState } from '@/components/QueryErrorState';
import { PageHeader } from '@/components/PageHeader';
import {
  Dialog,
  DialogContent,
  DialogDescription,
  DialogFooter,
  DialogHeader,
  DialogTitle,
} from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import {
  Select,
  SelectContent,
  SelectItem,
  SelectTrigger,
  SelectValue,
} from '@/components/ui/select';
import { Skeleton } from '@/components/ui/skeleton';
import {
  Table,
  TableBody,
  TableCell,
  TableHead,
  TableHeader,
  TableRow,
} from '@/components/ui/table';
import {
  useCreateDeveloperApp,
  useDeveloperApps,
  useDeveloperKeys,
  useIssueDeveloperKey,
  useRevokeDeveloperKey,
  useSetAppStatus,
} from '@/hooks/useDeveloperApps';
import { EXTERNAL_SCOPE_CATALOG, type DeveloperApp, type DeveloperKey, type KeyEnv } from '@/schemas/developerApps';
import { fmtUtcToApp } from '@/utils/date';

function RegisterAppDialog({ onClose }: { onClose: () => void }) {
  const create = useCreateDeveloperApp();
  const [name, setName] = useState('');
  const [ownerContact, setOwnerContact] = useState('');
  const [description, setDescription] = useState('');

  return (
    <Dialog open onOpenChange={(open) => ! open && ! create.isPending && onClose()}>
      <DialogContent lockDismiss className="sm:max-w-lg">
        <DialogHeader>
          <DialogTitle>Register API app</DialogTitle>
          <DialogDescription>
            Register an external integration (e.g. a FU capstone team). Keys are issued per app after registration.
          </DialogDescription>
        </DialogHeader>
        <div className="space-y-4">
          <div className="space-y-1.5">
            <Label htmlFor="app-name">App name</Label>
            <Input id="app-name" value={name} onChange={(e) => setName(e.target.value)} maxLength={120} placeholder="Capstone Queue Board" />
          </div>
          <div className="space-y-1.5">
            <Label htmlFor="app-contact">Owner contact <span className="text-muted-foreground">(email)</span></Label>
            <Input id="app-contact" type="email" value={ownerContact} onChange={(e) => setOwnerContact(e.target.value)} placeholder="team@example.edu" />
          </div>
          <div className="space-y-1.5">
            <Label htmlFor="app-description">Purpose <span className="text-muted-foreground">(optional)</span></Label>
            <Input id="app-description" value={description} onChange={(e) => setDescription(e.target.value)} maxLength={500} />
          </div>
        </div>
        <DialogFooter>
          <Button variant="outline" onClick={onClose} disabled={create.isPending}>Cancel</Button>
          <Button
            onClick={() => create.mutate(
              { name: name.trim(), description: description.trim(), ownerContact: ownerContact.trim() },
              { onSuccess: onClose },
            )}
            disabled={create.isPending || name.trim() === ''}
          >
            <Plus aria-hidden /> {create.isPending ? 'Registering…' : 'Register app'}
          </Button>
        </DialogFooter>
      </DialogContent>
    </Dialog>
  );
}

function IssueKeyDialog({
  app,
  onClose,
  onIssued,
}: {
  app: DeveloperApp;
  onClose: () => void;
  onIssued: (result: { key: DeveloperKey; secret: string }) => void;
}) {
  const issue = useIssueDeveloperKey();
  const [env, setEnv] = useState<KeyEnv>('test');
  const [scopes, setScopes] = useState<string[]>([]);
  const [dpaAcknowledged, setDpaAcknowledged] = useState(false);
  const dpaRequired = env === 'live' && app.dpa_acknowledged_at === null;

  function toggleScope(code: string, checked: boolean) {
    setScopes((current) => checked ? [...current, code] : current.filter((s) => s !== code));
  }

  return (
    <Dialog open onOpenChange={(open) => ! open && ! issue.isPending && onClose()}>
      <DialogContent lockDismiss className="sm:max-w-lg">
        <DialogHeader>
          <DialogTitle>Issue key — {app.name}</DialogTitle>
          <DialogDescription>
            Test keys run against the synthetic sandbox tenant. Live keys read production aggregates and require a
            data-sharing acknowledgement.
          </DialogDescription>
        </DialogHeader>
        <div className="space-y-4">
          <div className="space-y-1.5">
            <Label htmlFor="key-env">Environment</Label>
            <Select value={env} onValueChange={(value) => setEnv(value === 'live' ? 'live' : 'test')}>
              <SelectTrigger id="key-env"><SelectValue /></SelectTrigger>
              <SelectContent>
                <SelectItem value="test">Test — sandbox tenant (synthetic data)</SelectItem>
                <SelectItem value="live">Live — production tenant</SelectItem>
              </SelectContent>
            </Select>
          </div>
          <fieldset>
            <legend className="text-sm font-medium">Scopes</legend>
            <p className="mb-2 mt-1 text-xs text-muted-foreground">Least privilege: grant only what the integration needs.</p>
            <div className="space-y-2">
              {EXTERNAL_SCOPE_CATALOG.map((scope) => (
                <div key={scope.code} className="flex items-start gap-3 rounded-lg border bg-background p-3">
                  <Checkbox
                    id={`scope-${scope.code}`}
                    checked={scopes.includes(scope.code)}
                    onCheckedChange={(checked) => toggleScope(scope.code, checked === true)}
                  />
                  <Label htmlFor={`scope-${scope.code}`} className="min-w-0 cursor-pointer font-normal">
                    <span className="block font-mono text-xs">{scope.code}</span>
                    <span className="block text-xs text-muted-foreground">{scope.description}</span>
                  </Label>
                </div>
              ))}
            </div>
          </fieldset>
          {dpaRequired && (
            <div className="rounded-lg border border-amber-300/70 bg-amber-50/60 p-3 dark:border-amber-800 dark:bg-amber-950/30">
              <div className="flex items-start gap-3">
                <Checkbox
                  id="dpa-acknowledge"
                  checked={dpaAcknowledged}
                  onCheckedChange={(checked) => setDpaAcknowledged(checked === true)}
                />
                <Label htmlFor="dpa-acknowledge" className="font-normal">
                  I acknowledge the data-sharing terms (de-identified aggregates only, purpose limitation, 72-hour breach
                  notification — see docs/DATA-SHARING.md).
                </Label>
              </div>
            </div>
          )}
        </div>
        <DialogFooter>
          <Button variant="outline" onClick={onClose} disabled={issue.isPending}>Cancel</Button>
          <Button
            onClick={() => issue.mutate(
              { appId: app.id, env, scopes, dpaAcknowledged },
              { onSuccess: onIssued },
            )}
            disabled={issue.isPending || scopes.length === 0 || (dpaRequired && ! dpaAcknowledged)}
          >
            <KeyRound aria-hidden /> {issue.isPending ? 'Issuing…' : 'Issue key'}
          </Button>
        </DialogFooter>
      </DialogContent>
    </Dialog>
  );
}

function SecretRevealDialog({ result, onClose }: { result: { key: DeveloperKey; secret: string }; onClose: () => void }) {
  return (
    <Dialog open onOpenChange={(open) => ! open && onClose()}>
      <DialogContent lockDismiss className="sm:max-w-lg">
        <DialogHeader>
          <DialogTitle>Copy your key now</DialogTitle>
          <DialogDescription>
            This is the ONLY time the full secret is shown. It is stored as a hash and cannot be revealed again —
            revoke and reissue if it is lost.
          </DialogDescription>
        </DialogHeader>
        <div className="flex items-center gap-2">
          <p className="min-w-0 flex-1 break-all rounded-lg bg-muted p-3 text-center font-mono text-sm">{result.secret}</p>
          <CopyButton value={result.secret} label="Copy key secret" successMessage="Key secret copied." />
        </div>
        <DialogFooter>
          <Button onClick={onClose}>Done</Button>
        </DialogFooter>
      </DialogContent>
    </Dialog>
  );
}

/**
 * Keys table for ONE app — mounted only while its parent row is
 * expanded, so keys are fetched lazily per app. Issuing happens from
 * the parent row's action (single affordance).
 */
function AppKeysPanel({
  app,
  onRevoke,
  revokePending,
}: {
  app: DeveloperApp;
  onRevoke: (key: DeveloperKey) => void;
  revokePending: boolean;
}) {
  const keys = useDeveloperKeys(app.id);
  const rows = keys.data ?? [];

  return (
    <div className="overflow-hidden rounded-lg border">
      {keys.isLoading && <Skeleton className="m-4 h-14" />}
      {keys.isError && (
        <div className="p-4">
          <QueryErrorState message="Failed to load keys." onRetry={() => void keys.refetch()} pending={keys.isFetching} />
        </div>
      )}
      {keys.data !== undefined && rows.length === 0 && (
        <p className="p-5 text-center text-sm text-muted-foreground">No keys yet — use “Issue key” above.</p>
      )}
      {keys.data !== undefined && rows.length > 0 && (
        <div className="overflow-x-auto">
          <Table>
            <TableHeader className="bg-muted/50">
              <TableRow>
                <TableHead className="px-3">Prefix · last4</TableHead>
                <TableHead className="px-3">Env</TableHead>
                <TableHead className="px-3">Scopes</TableHead>
                <TableHead className="px-3">Limit</TableHead>
                <TableHead className="px-3">Expires</TableHead>
                <TableHead className="px-3">Last used</TableHead>
                <TableHead className="px-3">Status</TableHead>
                <TableHead className="px-3 text-right">Actions</TableHead>
              </TableRow>
            </TableHeader>
            <TableBody>
              {rows.map((key) => (
                <TableRow key={key.id}>
                  <TableCell className="px-3 font-mono text-xs">{key.prefix}··{key.last4}</TableCell>
                  <TableCell className="px-3">
                    <Badge variant={key.env === 'live' ? 'destructive' : 'info'}>{key.env}</Badge>
                  </TableCell>
                  <TableCell className="px-3">
                    <div className="flex flex-wrap gap-1">
                      {key.scopes.map((scope) => (
                        <Badge key={scope} variant="secondary" className="font-mono text-[10px]">{scope}</Badge>
                      ))}
                    </div>
                  </TableCell>
                  <TableCell className="px-3 text-xs">{key.rate_limit_per_min}/min</TableCell>
                  <TableCell className="px-3 text-xs">{key.expires_at === null ? '—' : fmtUtcToApp(key.expires_at, 'MMM d, yyyy')}</TableCell>
                  <TableCell className="px-3 text-xs">{key.last_used_at === null ? 'Never' : fmtUtcToApp(key.last_used_at)}</TableCell>
                  <TableCell className="px-3">
                    {key.status === 'active'
                      ? <Badge variant="success">Active</Badge>
                      : <Badge variant="destructive">{key.status}</Badge>}
                  </TableCell>
                  <TableCell className="px-3 text-right">
                    {key.status === 'active' && (
                      <Button
                        size="sm"
                        variant="destructive"
                        disabled={revokePending}
                        onClick={() => onRevoke(key)}
                      >
                        Revoke
                      </Button>
                    )}
                  </TableCell>
                </TableRow>
              ))}
            </TableBody>
          </Table>
        </div>
      )}
    </div>
  );
}

export default function DeveloperAppsPage() {
  const apps = useDeveloperApps();
  const setStatus = useSetAppStatus();
  const revokeKey = useRevokeDeveloperKey();
  const [registerOpen, setRegisterOpen] = useState(false);
  const [issueForApp, setIssueForApp] = useState<DeveloperApp | null>(null);
  const [issuedSecret, setIssuedSecret] = useState<{ key: DeveloperKey; secret: string } | null>(null);
  const [openAppId, setOpenAppId] = useState<number | null>(null);
  const [confirm, setConfirm] = useState<ConfirmAction | null>(null);

  const rows = apps.data ?? [];

  function toggleApp(appId: number) {
    setOpenAppId((current) => current === appId ? null : appId);
  }

  function requestRevoke(key: DeveloperKey) {
    setConfirm({
      title: `Revoke key ${key.prefix}?`,
      description: 'Requests signed with this key start failing immediately. Issue a new key first for zero-downtime rotation.',
      confirmLabel: 'Revoke key',
      run: () => revokeKey.mutate({ id: key.id, prefix: key.prefix }, { onSuccess: () => setConfirm(null) }),
    });
  }

  function requestStatusToggle(app: DeveloperApp) {
    const next = app.status === 'active' ? 'suspended' : 'active';
    setConfirm({
      title: `${next === 'suspended' ? 'Suspend' : 'Reactivate'} ${app.name}?`,
      description: next === 'suspended'
        ? 'All keys of this app stop working immediately.'
        : 'The app\u2019s keys resume working (unrevoked, unexpired keys only).',
      confirmLabel: next === 'suspended' ? 'Suspend app' : 'Reactivate app',
      run: () => setStatus.mutate({ id: app.id, status: next }, { onSuccess: () => setConfirm(null) }),
    });
  }

  return (
    <main className="mx-auto min-w-0 max-w-7xl space-y-5 p-4 sm:p-6">
      <PageHeader
        title="API Apps"
        description="External integrations read de-identified aggregates through scoped, rate-limited, environment-separated API keys."
        actions={
          <Button onClick={() => setRegisterOpen(true)}>
            <Plus aria-hidden /> Register app
          </Button>
        }
      />

      {apps.isError && (
        <QueryErrorState message="Failed to load API apps." onRetry={() => void apps.refetch()} pending={apps.isFetching} />
      )}

      {apps.isLoading && (
        <div className="space-y-3 p-4" role="status" aria-label="Loading apps">
          <Skeleton className="h-16 w-full" />
          <Skeleton className="h-16 w-full" />
        </div>
      )}

      {apps.data !== undefined && rows.length === 0 && (
        <section className="rounded-xl border bg-card p-8 text-center">
          <p className="font-medium text-foreground">No API apps registered yet</p>
          <p className="mt-1 text-sm text-muted-foreground">
            Register the first external integration, then issue a scoped key for it.
          </p>
        </section>
      )}

      {apps.data !== undefined && rows.length > 0 && (
        <section aria-labelledby="apps-list-heading" className="overflow-hidden rounded-xl border bg-card">
          <h2 id="apps-list-heading" className="sr-only">Registered apps</h2>
          <div className="overflow-x-auto">
            <Table>
              <TableHeader className="bg-muted/50">
                <TableRow>
                  <TableHead className="px-3">App</TableHead>
                  <TableHead className="px-3">Owner</TableHead>
                  <TableHead className="px-3">Data-sharing ack.</TableHead>
                  <TableHead className="px-3">Keys</TableHead>
                  <TableHead className="px-3">Status</TableHead>
                  <TableHead className="px-3 text-right">Actions</TableHead>
                </TableRow>
              </TableHeader>
              <TableBody>
                {rows.map((app) => {
                  const open = openAppId === app.id;
                  return (
                    <Fragment key={app.id}>
                      {/* Accordion row — click anywhere toggles; the app
                          name is a real button for keyboard users, and the
                          action buttons stop propagation. */}
                      <TableRow
                        data-state={open ? 'selected' : undefined}
                        className="cursor-pointer"
                        onClick={() => toggleApp(app.id)}
                      >
                        <TableCell className="max-w-64 px-3">
                          <button
                            type="button"
                            onClick={(event) => {
                              event.stopPropagation();
                              toggleApp(app.id);
                            }}
                            aria-expanded={open}
                            aria-controls={`app-keys-${app.id}`}
                            className="flex min-w-0 items-center gap-2 rounded text-left focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-ring"
                          >
                            <ChevronDown
                              aria-hidden
                              className={`size-4 shrink-0 text-muted-foreground transition-transform ${open ? 'rotate-180' : ''}`}
                            />
                            <span className="min-w-0">
                              <span className="flex items-center gap-2 text-sm font-medium text-foreground">
                                <Plug className="size-3.5 shrink-0 text-muted-foreground" aria-hidden />
                                <span className="truncate">{app.name}</span>
                              </span>
                              {app.description !== null && (
                                <span className="block truncate text-xs text-muted-foreground">{app.description}</span>
                              )}
                            </span>
                          </button>
                        </TableCell>
                        <TableCell className="px-3 text-sm">{app.owner_contact ?? '—'}</TableCell>
                        <TableCell className="px-3">
                          {app.dpa_acknowledged_at !== null
                            ? <Badge variant="success">Acknowledged {fmtUtcToApp(app.dpa_acknowledged_at, 'MMM d, yyyy')}</Badge>
                            : <Badge variant="outline">Required for live keys</Badge>}
                        </TableCell>
                        <TableCell className="px-3 text-sm">{app.key_count}</TableCell>
                        <TableCell className="px-3">
                          {app.status === 'active' ? <Badge variant="success">Active</Badge> : <Badge variant="warning">Suspended</Badge>}
                        </TableCell>
                        <TableCell className="px-3 text-right">
                          {/* stopPropagation: the action buttons must not
                              toggle the row. */}
                          <div className="flex justify-end gap-2" onClick={(event) => event.stopPropagation()}>
                            <Button
                              size="sm"
                              variant="outline"
                              onClick={() => setIssueForApp(app)}
                              aria-label={`Issue key for ${app.name}`}
                            >
                              <KeyRound aria-hidden /> Issue key
                            </Button>
                            <Button
                              size="sm"
                              variant={app.status === 'active' ? 'destructive' : 'outline'}
                              onClick={() => requestStatusToggle(app)}
                            >
                              {app.status === 'active' ? 'Suspend' : 'Reactivate'}
                            </Button>
                          </div>
                        </TableCell>
                      </TableRow>
                      {/* Accordion body — a full-width row, mounted only
                          while open (lazy keys fetch). */}
                      {open && (
                        <TableRow id={`app-keys-${app.id}`} className="hover:bg-transparent">
                          <TableCell colSpan={6} className="bg-muted/20 p-4">
                            <div role="region" aria-label={`Keys — ${app.name}`}>
                              <AppKeysPanel app={app} onRevoke={requestRevoke} revokePending={revokeKey.isPending} />
                            </div>
                          </TableCell>
                        </TableRow>
                      )}
                    </Fragment>
                  );
                })}
              </TableBody>
            </Table>
          </div>
        </section>
      )}

      {registerOpen && <RegisterAppDialog onClose={() => setRegisterOpen(false)} />}
      {issueForApp !== null && (
        <IssueKeyDialog
          app={issueForApp}
          onClose={() => setIssueForApp(null)}
          onIssued={(result) => {
            setIssueForApp(null);
            setOpenAppId(result.key.app_id);
            setIssuedSecret(result);
          }}
        />
      )}
      {issuedSecret !== null && <SecretRevealDialog result={issuedSecret} onClose={() => setIssuedSecret(null)} />}

      <ConfirmDialog
        open={confirm !== null}
        title={confirm?.title ?? ''}
        description={confirm?.description}
        confirmLabel={confirm?.confirmLabel}
        pending={setStatus.isPending || revokeKey.isPending}
        onConfirm={() => confirm?.run()}
        onCancel={() => setConfirm(null)}
      />
    </main>
  );
}
