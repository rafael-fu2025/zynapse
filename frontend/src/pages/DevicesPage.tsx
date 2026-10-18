/**
 * DevicesPage — BMG device management as a first-class module under
 * Facilities (promoted from a dialog on the Facilities page so the
 * device fleet gets the full content area).
 *
 * Read surface: `facilities.units.read` (route guard + sidebar).
 * Mutations: `facilities.units.manage` — the action buttons are hidden
 * from operators rather than 403-ing (AuditPage's `hasPermission`
 * pattern), and the backend policy re-checks everything anyway.
 *
 * The shown-once token flow is the crown jewel of this page: a freshly
 * minted token is revealed once (CopyButton pattern from AdminUsersPage)
 * and never retrievable again — regenerate is the only recovery.
 */
import { useId, useState } from 'react';
import { Link } from 'react-router-dom';
import { ArrowLeft, Archive, ChevronDown, ChevronLeft, ChevronRight, ArchiveRestore, History, Loader2, Pencil, Play, Plus, Power, PowerOff, RotateCcw, Square } from 'lucide-react';
import { toast } from 'sonner';
import { Badge } from '@/components/ui/badge';
import { PageHeader } from '@/components/PageHeader';
import { Button } from '@/components/ui/button';
import { PermanentDeleteAction } from '@/components/PermanentDeleteAction';
import { ConfirmDialog, type ConfirmAction } from '@/components/ConfirmDialog';
import { describeHeartbeatAge, parseUtcSql } from '@/lib/bmgFormat';
import { CopyButton } from '@/components/CopyButton';
import { MobileCardList, MobileCard, MobileCardActions, MobileCardListState } from '@/components/MobileCardList';
import { TableStateRows } from '@/components/TableStates';
import {
  Dialog,
  DialogContent,
  DialogDescription,
  DialogFooter,
  DialogHeader,
  DialogTitle,
} from '@/components/ui/dialog';
import {
  DropdownMenu,
  DropdownMenuContent,
  DropdownMenuItem,
  DropdownMenuSeparator,
  DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import {
  Select,
  SelectContent,
  SelectItem,
  SelectTrigger,
  SelectValue,
} from '@/components/ui/select';
import {
  Table,
  TableBody,
  TableCell,
  TableHead,
  TableHeader,
  TableRow,
} from '@/components/ui/table';
import {
  useArchiveBmgDevice,
  useBmgDevices,
  useBmgUnits,
  useDeviceAssignments,
  useRegenerateBmgDeviceToken,
  useRegisterBmgDevice,
  useSendDeviceCommand,
  useSetBmgDeviceStatus,
  useUpdateBmgDevice,
  useUnarchiveBmgDevice,
} from '@/hooks/useFacilities';
import { useKeysetPagination } from '@/hooks/useKeysetPagination';
import { useUrlFilter } from '@/hooks/useUrlFilter';
import { registerDeviceSchema, type BmgDevice } from '@/schemas/facilities';
import { hasPermission, useAuthStore } from '@/store/auth';
import { fmtUtcToApp } from '@/utils/date';
import { titleCase } from '@/lib/utils';

/**
 * Shown-once credential reveal for a freshly minted device token —
 * same pattern as the temporary-password dialog on AdminUsersPage.
 * The plaintext is never retrievable again.
 */function DeviceTokenDialog({ device, token, onClose }: { device: BmgDevice; token: string; onClose: () => void }) {
  return (
    <Dialog open onOpenChange={(open) => !open && onClose()}>
      <DialogContent lockDismiss>
        <DialogHeader>
          <DialogTitle>Device token</DialogTitle>
          <DialogDescription>
            Paste this into <span className="font-mono">DEVICE_TOKEN</span> in the sketch and flash the board. It is
            shown once — only a SHA-256 hash is stored server-side. Losing it means regenerating (the old token stops
            working).
          </DialogDescription>
        </DialogHeader>
        <div className="flex items-center gap-2">
          <p className="min-w-0 flex-1 break-all rounded-lg bg-muted p-3 text-center font-mono text-sm">{token}</p>
          <CopyButton value={token} label="Copy device token" successMessage="Device token copied." />
        </div>
        <p className="text-xs text-muted-foreground">
          Device <span className="font-mono">{device.code}</span> registered
          {device.unit_name !== null && device.unit_name !== undefined ? ` for ${device.unit_name}` : ' without a drum binding'}.
        </p>
        <DialogFooter>
          <Button onClick={onClose}>Done</Button>
        </DialogFooter>
      </DialogContent>
    </Dialog>
  );
}

/** Register a tumbler: chip MAC (auto-codes) or explicit slug, optional drum binding. */
function RegisterDeviceDialog({ onClose }: { onClose: () => void }) {
  const register = useRegisterBmgDevice();
  const units = useBmgUnits(null, 100, false);
  const [mac, setMac] = useState('');
  const [code, setCode] = useState('');
  const [name, setName] = useState('');
  const [unitId, setUnitId] = useState('unset');
  const [errors, setErrors] = useState<Record<string, string>>({});
  const macId = useId();
  const codeId = useId();
  const nameId = useId();
  const unitIdFieldId = useId();

  function submit() {
    const parsed = registerDeviceSchema.safeParse({
      mac,
      code,
      display_name: name,
      unit_id: unitId === 'unset' ? '' : unitId,
    });
    if (!parsed.success) {
      setErrors(Object.fromEntries(parsed.error.issues.map((i) => [String(i.path[0]), i.message])));
      return;
    }
    setErrors({});
    register.mutate(parsed.data, {
      onSuccess: (result) => {
        toast.success(`Device ${result.device.code} registered.`);
        setMinted(result);
      },
    });
  }

  // The minted token needs the shown-once reveal — rendered here (not
  // the parent) so it can outlive the form's state on success.
  const [minted, setMinted] = useState<{ device: BmgDevice; token: string } | null>(null);

  // A drum integrates exactly ONE ESP32 — only drums without a device
  // can take a new binding here. Reassignment for a drum that already
  // holds a board belongs to the drum's Edit form, which swaps boards.
  const unitOptions = units.data?.data.filter((u) => u.archived_at == null && u.device_id == null) ?? [];

  if (minted !== null) {
    return <DeviceTokenDialog device={minted.device} token={minted.token} onClose={onClose} />;
  }

  return (
    <Dialog open onOpenChange={(open) => !open && onClose()}>
      <DialogContent>
        <DialogHeader>
          <DialogTitle>Register device</DialogTitle>
          <DialogDescription>
            The ESP32 tumbler reports a turning session here after every completed rotation cycle, and polls for
            queued commands — starting a batch on its drum sends it a start command.
          </DialogDescription>
        </DialogHeader>
        <div className="space-y-3">
          <div className="space-y-1.5">
            <Label htmlFor={macId}>Chip MAC address</Label>
            <Input
              id={macId}
              value={mac}
              onChange={(e) => setMac(e.target.value)}
              placeholder="b8:1f:3f:d7:ec:18"
              className="font-mono"
              aria-invalid={errors.mac !== undefined}
            />
            {errors.mac !== undefined && <p className="text-xs text-destructive">{errors.mac}</p>}
            <p className="text-xs text-muted-foreground">
              From the board itself (esptool/Arduino serial monitor). Becomes the default code, e.g.
              <span className="font-mono"> b8-1f-3f-d7-ec-18</span>. Leave empty to give a code instead.
            </p>
          </div>
          <div className="space-y-1.5">
            <Label htmlFor={codeId}>Code (override)</Label>
            <Input
              id={codeId}
              value={code}
              onChange={(e) => setCode(e.target.value.toLowerCase())}
              placeholder="compost-tumbler-1"
              aria-invalid={errors.code !== undefined}
            />
            {errors.code !== undefined && <p className="text-xs text-destructive">{errors.code}</p>}
          </div>
          <div className="space-y-1.5">
            <Label htmlFor={nameId}>Display name</Label>
            <Input
              id={nameId}
              value={name}
              onChange={(e) => setName(e.target.value)}
              placeholder="Compost Tumbler 1"
              maxLength={128}
            />
          </div>
          <div className="space-y-1.5">
            <Label id={unitIdFieldId}>Bound drum</Label>
            <Select value={unitId} onValueChange={setUnitId}>
              <SelectTrigger aria-labelledby={unitIdFieldId}><SelectValue /></SelectTrigger>
              <SelectContent>
                <SelectItem value="unset">None (reports will be rejected until bound)</SelectItem>
                {unitOptions.map((u) => (
                  <SelectItem key={u.id} value={String(u.id)}>{u.display_name} ({u.code})</SelectItem>
                ))}
              </SelectContent>
            </Select>
          </div>
          <DialogFooter>
            <Button onClick={submit} disabled={register.isPending}>
              {register.isPending && <Loader2 className="size-4 animate-spin" aria-hidden />} Register device
            </Button>
          </DialogFooter>
        </div>
      </DialogContent>
    </Dialog>
  );
}

/** Rebind a device to a different drum, or rename it. */
function EditDeviceDialog({ device, onClose }: { device: BmgDevice; onClose: () => void }) {
  const update = useUpdateBmgDevice();
  const units = useBmgUnits(null, 100, false);
  const [name, setName] = useState(device.display_name);
  const [unitId, setUnitId] = useState<string>(device.unit_id != null ? String(device.unit_id) : 'unset');
  const [error, setError] = useState('');
  const nameId = useId();
  const unitIdFieldId = useId();

  // Offer only free drums plus the one this device already holds — a
  // drum bound to ANOTHER board is hidden, because stealing that
  // binding is refused server-side (1:1 drum ↔ device).
  const unitOptions = (units.data?.data ?? []).filter(
    (u) => u.archived_at == null && (u.device_id == null || u.device_id === device.unit_id),
  );

  function submit() {
    if (name.trim() === '') {
      setError('Display name is required.');
      return;
    }
    setError('');
    update.mutate(
      {
        deviceId: device.id,
        input: {
          display_name: name.trim(),
          // `null` is meaningful here — it unbinds — so it is never
          // collapsed into "leave unchanged".
          unit_id: unitId === 'unset' ? null : Number(unitId),
        },
      },
      { onSuccess: onClose },
    );
  }

  return (
    <Dialog open onOpenChange={(open) => !open && onClose()}>
      <DialogContent lockDismiss>
        <DialogHeader>
          <DialogTitle>Edit {device.code}</DialogTitle>
          <DialogDescription>
            Rebinding does not change the device token — the board keeps reporting with the
            credential it already has.
          </DialogDescription>
        </DialogHeader>
        <div className="space-y-3">
          <div className="space-y-1.5">
            <Label htmlFor={nameId}>Display name</Label>
            <Input id={nameId} value={name} onChange={(e) => setName(e.target.value)} maxLength={128} />
          </div>
          <div className="space-y-1.5">
            <Label id={unitIdFieldId}>Bound drum</Label>
            <Select value={unitId} onValueChange={setUnitId}>
              <SelectTrigger aria-labelledby={unitIdFieldId}><SelectValue /></SelectTrigger>
              <SelectContent>
                <SelectItem value="unset">None (reports will be rejected until bound)</SelectItem>
                {unitOptions.map((u) => (
                  <SelectItem key={u.id} value={String(u.id)}>{u.display_name} ({u.code})</SelectItem>
                ))}
              </SelectContent>
            </Select>
          </div>
          {error !== '' && <p role="alert" className="text-sm text-destructive">{error}</p>}
        </div>
        <DialogFooter>
          <Button variant="outline" onClick={onClose}>Cancel</Button>
          <Button onClick={submit} disabled={update.isPending}>
            {update.isPending && <Loader2 className="size-4 animate-spin" aria-hidden />} Save changes
          </Button>
        </DialogFooter>
      </DialogContent>
    </Dialog>
  );
}

/**
 * Drum-binding ledger for one device — who moved it where, when. The
 * UNIQUE binding overwrites the previous drum, so this history is the
 * only place the previous assignment survives.
 */
function AssignmentHistoryDialog({ device, onClose }: { device: BmgDevice; onClose: () => void }) {
  const history = useDeviceAssignments(device.id);

  return (
    <Dialog open onOpenChange={(open) => !open && onClose()}>
      <DialogContent className="max-w-lg">
        <DialogHeader>
          <DialogTitle>Assignment history — {device.code}</DialogTitle>
          <DialogDescription>
            Every drum this controller has been bolted to, newest first. The open row is its current binding.
          </DialogDescription>
        </DialogHeader>
        {history.isLoading && (
          <div className="flex items-center gap-2 py-4 text-sm text-muted-foreground">
            <Loader2 className="size-4 animate-spin" /> Loading…
          </div>
        )}
        {history.isError && (
          <p className="rounded-md border border-destructive/30 bg-destructive/5 p-2 text-xs text-destructive">
            Failed to load assignment history.
          </p>
        )}
        {history.data !== undefined && history.data.length === 0 && (
          <p className="py-2 text-sm text-muted-foreground">This device has never been bound to a drum.</p>
        )}
        {history.data !== undefined && history.data.length > 0 && (
          <ul className="divide-y rounded-md border text-sm">
            {history.data.map((entry) => (
              <li key={entry.id} className="flex items-start justify-between gap-2 px-3 py-2">
                <div className="min-w-0">
                  <p className="truncate font-medium">
                    {entry.unit_display_name ?? `Drum #${entry.unit_id}`}
                    {entry.unit_code !== null && (
                      <span className="ml-2 font-mono text-xs text-muted-foreground">{entry.unit_code}</span>
                    )}
                  </p>
                  <p className="text-xs text-muted-foreground">
                    Bound {fmtUtcToApp(entry.assigned_at, 'MMM d, yyyy · h:mm a')}
                    {entry.unassigned_at !== null
                      ? ` · released ${fmtUtcToApp(entry.unassigned_at, 'MMM d, yyyy · h:mm a')}`
                      : ''}
                  </p>
                </div>
                {entry.is_active && <Badge variant="success">Current</Badge>}
              </li>
            ))}
          </ul>
        )}
        <DialogFooter>
          <Button variant="outline" onClick={onClose}>
            Close
          </Button>
        </DialogFooter>
      </DialogContent>
    </Dialog>
  );
}

export default function DevicesPage() {
  const [archivedParam, setArchivedParam] = useUrlFilter('archived');
  const showArchived = archivedParam === '1';
  const { cursor, history, nextPage: nextCursor, prevPage } = useKeysetPagination(archivedParam);
  const devices = useBmgDevices(cursor, 25, showArchived ? 'archived' : 'active');
  const setStatus = useSetBmgDeviceStatus();
  const regenerate = useRegenerateBmgDeviceToken();
  const archive = useArchiveBmgDevice();
  const restore = useUnarchiveBmgDevice();
  const sendCommand = useSendDeviceCommand();
  const auth = useAuthStore();
  const canManage = hasPermission(auth, 'facilities.units.manage');

  const [registering, setRegistering] = useState(false);
  const [editing, setEditing] = useState<BmgDevice | null>(null);
  const [minted, setMinted] = useState<{ device: BmgDevice; token: string } | null>(null);
  const [confirm, setConfirm] = useState<ConfirmAction | null>(null);
  const [historyFor, setHistoryFor] = useState<BmgDevice | null>(null);

  const rows = devices.data?.data ?? [];
  const mutationPending = archive.isPending || restore.isPending || regenerate.isPending || setStatus.isPending || sendCommand.isPending;

  /**
   * Heartbeat-derived online state. The list endpoint doesn't ship the
   * threshold, so this uses the backend default (BMG_OFFLINE_AFTER_MIN
   * 20); the unit monitor is the precise per-drum view.
   */
  const heartbeatBadge = (d: BmgDevice) => {
    const stamp = d.last_heartbeat_at ?? d.last_seen_at;
    if (stamp === null || stamp === undefined) return null;
    const parsed = parseUtcSql(stamp);
    if (parsed === null) return null;
    const minutes = Math.floor((Date.now() - parsed.getTime()) / 60000);
    const readout = describeHeartbeatAge(minutes, null);
    const variant = readout.tone === 'danger' ? 'destructive' : 'success';
    return (
      <Badge variant={variant} title={`Last check-in ${stamp} UTC`}>
        {readout.tone === 'danger' ? 'Offline' : 'Online'}
      </Badge>
    );
  };

  const deviceActions = (d: BmgDevice) => (
    <DropdownMenu>
      <DropdownMenuTrigger asChild>
        <Button className="min-h-11 md:min-h-0" size="sm" variant="outline" disabled={mutationPending} aria-label={`Actions for ${d.code}`}>
          Actions <ChevronDown className="size-3.5" aria-hidden />
        </Button>
      </DropdownMenuTrigger>
      <DropdownMenuContent align="end" className="w-56">
        {d.archived_at != null ? (
          <>
            <DropdownMenuItem
              className="min-h-11"
              disabled={restore.isPending}
              onSelect={() => setConfirm({
                title: `Restore ${d.code}?`,
                description: 'The device will be restored disabled and unbound. Its old token will no longer work. Regenerate a token explicitly, bind a drum and enable the device before using it again.',
                confirmLabel: 'Restore device',
                run: () => restore.mutate({ deviceId: d.id }),
              })}
            >
              <ArchiveRestore /> Restore device
            </DropdownMenuItem>
            <PermanentDeleteAction resource="devices" id={d.id} label={d.code} presentation="menuitem" />
            <DropdownMenuItem className="min-h-11" onSelect={() => setHistoryFor(d)}>
              <History /> Assignment history
            </DropdownMenuItem>
          </>
        ) : (
          <>
        <DropdownMenuItem className="min-h-11" onSelect={() => setEditing(d)}>
          <Pencil /> Edit device
        </DropdownMenuItem>
        <DropdownMenuItem className="min-h-11" onSelect={() => setHistoryFor(d)}>
          <History /> Assignment history
        </DropdownMenuItem>
        {d.status === 'active' && d.unit_id != null && (
          <>
            <DropdownMenuSeparator />
            <DropdownMenuItem
              className="min-h-11"
              disabled={sendCommand.isPending}
              onSelect={() => setConfirm({
                title: `Run ${d.display_name} now?`,
                description: `Queues a one-off rotation using the drum's current schedule settings. The board runs it within about 10 minutes of its next check-in, and the run is reported back.`,
                confirmLabel: 'Run now',
                destructive: false,
                run: () => sendCommand.mutate({ deviceId: d.id, command: 'run_now' }),
              })}
            >
              <RotateCcw className="size-4 text-sky-600" /> Run now
            </DropdownMenuItem>
            <DropdownMenuItem
              className="min-h-11"
              disabled={sendCommand.isPending}
              onSelect={() => setConfirm({
                title: `Start tumbler for ${d.display_name}?`,
                description: `This will enqueue a start command for ESP32 (${d.code}) to begin its rotation cycle on drum ${d.unit_name ?? d.unit_code}.`,
                confirmLabel: 'Start tumbler',
                run: () => sendCommand.mutate({ deviceId: d.id, command: 'start' }),
              })}
            >
              <Play className="size-4 text-emerald-600" /> Start tumbler
            </DropdownMenuItem>
            <DropdownMenuItem
              className="min-h-11 text-amber-600 focus:text-amber-600"
              disabled={sendCommand.isPending}
              onSelect={() => setConfirm({
                title: `Stop tumbler for ${d.display_name}?`,
                description: `This will immediately enqueue a stop command for ESP32 (${d.code}) to halt motor rotation.`,
                confirmLabel: 'Stop tumbler',
                run: () => sendCommand.mutate({ deviceId: d.id, command: 'stop' }),
              })}
            >
              <Square className="size-4" /> Stop tumbler
            </DropdownMenuItem>
            <DropdownMenuSeparator />
          </>
        )}
        <DropdownMenuItem
          className="min-h-11"
          onSelect={() => setConfirm({
            title: `Regenerate token for ${d.code}?`,
            description: 'The current token stops working immediately. The device will need the new token flashed before its next report.',
            confirmLabel: 'Regenerate',
            run: () => regenerate.mutate({ deviceId: d.id }, { onSuccess: setMinted }),
          })}
        >
          <RotateCcw /> Regenerate token
        </DropdownMenuItem>
        {d.status === 'active' ? (
          <DropdownMenuItem
            className="min-h-11 text-destructive focus:text-destructive"
            onSelect={() => setConfirm({
              title: `Disable ${d.code}?`,
              description: 'Reports will be refused until re-enabled.',
              confirmLabel: 'Disable',
              run: () => setStatus.mutate({ deviceId: d.id, status: 'disabled' }),
            })}
          >
            <PowerOff /> Disable
          </DropdownMenuItem>
        ) : (
          <DropdownMenuItem className="min-h-11" onSelect={() => setStatus.mutate({ deviceId: d.id, status: 'active' })}>
            <Power /> Enable
          </DropdownMenuItem>
        )}
        <DropdownMenuSeparator />
        <DropdownMenuItem
          className="min-h-11 text-destructive focus:text-destructive"
          disabled={archive.isPending}
          onSelect={() => setConfirm({
            title: `Archive ${d.code}?`,
            description: 'The device is retired: its reports will be refused and its drum binding cleared. The audit history is kept.',
            confirmLabel: 'Archive device',
            run: () => archive.mutate({ deviceId: d.id }),
          })}
        >
          <Archive /> Archive device
        </DropdownMenuItem>
          </>
        )}
      </DropdownMenuContent>
    </DropdownMenu>
  );

  return (
    <main className="space-y-4 p-6">
      <PageHeader
        title="Devices"
        description="Automated tumblers that mechanize drum rotation and report turning sessions to the process log. Tokens are shown once, at mint time."
        actions={
          <>
            <Button
              variant={showArchived ? 'secondary' : 'outline'}
              aria-pressed={showArchived}
              onClick={() => setArchivedParam(showArchived ? '' : '1')}
            >
              <Archive /> {showArchived ? 'Hide archived' : 'Show archived'}
            </Button>
            <Button variant="outline" asChild>
              <Link to="/facilities">
                <ArrowLeft /> Back to Facilities
              </Link>
            </Button>
            {canManage && (
              <Button onClick={() => setRegistering(true)}>
                <Plus /> Register device
              </Button>
            )}
          </>
        }
      />

      {/* Desktop table */}
      <section className="hidden overflow-hidden border bg-card md:block">
        <Table
          ariaLabel="Registered BMG devices"
          wrapperClassName="max-h-[65vh] overflow-y-auto"
          className="[&_td]:py-1.5"
        >
          <TableHeader className="sticky top-0 z-10 bg-muted shadow-[0_1px_0_0_var(--border)]">
            <TableRow>
              <TableHead className="px-3">Device</TableHead>
              <TableHead className="px-3">Status</TableHead>
              <TableHead className="px-3">Bound drum</TableHead>
              <TableHead className="px-3">Token</TableHead>
              <TableHead className="px-3">Firmware</TableHead>
              <TableHead className="px-3">Last seen</TableHead>
              {canManage && <TableHead className="px-3 text-right">Actions</TableHead>}
            </TableRow>
          </TableHeader>
          <TableBody>
            <TableStateRows
              colSpan={canManage ? 7 : 6}
              isLoading={devices.isLoading}
              isError={devices.isError}
              isEmpty={rows.length === 0}
              onRetry={() => void devices.refetch()}
              pending={devices.isFetching}
              errorMessage="Failed to load devices."
              loadingLabel="Loading devices"
              empty={{
                title: showArchived ? 'No archived devices.' : 'No active devices.',
                description: showArchived
                  ? 'Archived devices will appear here. Switch to active devices to manage the fleet.'
                  : canManage
                    ? 'Register the tumbler to start reporting turning sessions.'
                    : 'Ask a BMG administrator to register the tumbler.',
              }}
            />
            {rows.map((d) => (
              <TableRow key={d.id} className="scroll-mt-24">
                <TableCell className="px-3">
                  <p className="text-sm font-medium">{d.display_name}</p>
                  <p className="font-mono text-xs text-muted-foreground">{d.code}</p>
                </TableCell>
                <TableCell className="px-3">
                  <div className="flex flex-wrap items-center gap-1.5">
                    <Badge variant={d.status === 'active' ? 'success' : 'destructive'}>{titleCase(d.status)}</Badge>
                    {d.archived_at != null && <Badge variant="secondary">Archived</Badge>}
                    {d.archived_at == null && d.status === 'active' && heartbeatBadge(d)}
                    {/* The watchdog fires one notification per silence
                        episode; this is the standing signal that stays on
                        screen after the operator has seen it. */}
                    {d.silence_notified_at != null && d.archived_at == null && (
                      <Badge variant="warning" title={`Flagged silent on ${d.silence_notified_at}`}>
                        Silent
                      </Badge>
                    )}
                  </div>
                </TableCell>
                <TableCell className="px-3">
                  {d.unit_name !== null && d.unit_name !== undefined ? (
                    <div>
                      <p className="text-sm">{d.unit_name}</p>
                      <p className="font-mono text-xs text-muted-foreground">{d.unit_code}</p>
                    </div>
                  ) : (
                    <span className="text-xs text-muted-foreground">Not bound</span>
                  )}
                </TableCell>
                <TableCell className="px-3 font-mono text-xs text-muted-foreground">
                  {d.token_prefix !== null && d.token_prefix !== undefined ? `${d.token_prefix}…` : '—'}
                </TableCell>
                <TableCell className="px-3 text-xs">
                  {d.firmware ?? '—'}
                </TableCell>
                <TableCell className={d.silence_notified_at != null && d.archived_at == null ? 'px-3 text-xs font-medium text-destructive' : 'px-3 text-xs text-muted-foreground'}>
                  {d.last_seen_at !== null && d.last_seen_at !== undefined
                    // last_seen_at is a full UTC DATETIME — fmtUtcToApp is
                    // the datetime contract (fmtHumanDate is DATE-only).
                    ? fmtUtcToApp(d.last_seen_at, 'MMM d, yyyy · h:mm a')
                    : 'Never connected'}
                </TableCell>
                {canManage && (
                  <TableCell className="px-3 text-right">{deviceActions(d)}</TableCell>
                )}
              </TableRow>
            ))}
          </TableBody>
        </Table>
      </section>

      {/* Mobile cards */}
      <MobileCardList className="md:hidden">
        <MobileCardListState
          isLoading={devices.isLoading}
          isError={devices.isError}
          isEmpty={rows.length === 0}
          onRetry={() => void devices.refetch()}
          pending={devices.isFetching}
          errorMessage="Failed to load devices."
          loadingLabel="Loading devices"
          empty={{
            title: showArchived ? 'No archived devices.' : 'No active devices.',
            description: showArchived
              ? 'Archived devices will appear here. Switch to active devices to manage the fleet.'
              : canManage
                ? 'Register the tumbler to start reporting turning sessions.'
                : 'Ask a BMG administrator to register the tumbler.',
          }}
        />
        {rows.map((d) => (
          <MobileCard key={d.id}>
            <div className="flex items-start justify-between gap-2">
              <div className="min-w-0">
                <p className="truncate text-sm font-medium">{d.display_name}</p>
                <p className="truncate font-mono text-xs text-muted-foreground">{d.code}</p>
              </div>
              <div className="flex flex-wrap justify-end gap-1.5">
                <Badge variant={d.status === 'active' ? 'success' : 'destructive'}>{titleCase(d.status)}</Badge>
                {d.archived_at != null && <Badge variant="secondary">Archived</Badge>}
                {d.archived_at == null && d.status === 'active' && heartbeatBadge(d)}
                {d.silence_notified_at != null && d.archived_at == null && <Badge variant="warning">Silent</Badge>}
              </div>
            </div>
            <p className="mt-1.5 text-xs text-muted-foreground">
              {d.unit_name !== null && d.unit_name !== undefined ? d.unit_name : 'Not bound'} ·{' '}
              {d.last_seen_at !== null && d.last_seen_at !== undefined
                ? `last seen ${fmtUtcToApp(d.last_seen_at, 'MMM d · h:mm a')}`
                : 'never connected'}
            </p>
            {canManage && <MobileCardActions>{deviceActions(d)}</MobileCardActions>}
          </MobileCard>
        ))}
      </MobileCardList>

      {registering && <RegisterDeviceDialog onClose={() => setRegistering(false)} />}
      {editing !== null && <EditDeviceDialog device={editing} onClose={() => setEditing(null)} />}
      {minted !== null && <DeviceTokenDialog device={minted.device} token={minted.token} onClose={() => setMinted(null)} />}
      {historyFor !== null && <AssignmentHistoryDialog device={historyFor} onClose={() => setHistoryFor(null)} />}

      <nav className="flex items-center justify-between" aria-label="pagination">
        <p className="text-xs text-muted-foreground">
          Page {history.length} · {rows.length} device{rows.length === 1 ? '' : 's'} shown
        </p>
        <div className="flex gap-2">
          <Button variant="outline" size="sm" onClick={prevPage} disabled={history.length < 2}>
            <ChevronLeft /> Prev
          </Button>
          <Button
            variant="outline"
            size="sm"
            onClick={() => nextCursor(devices.data?.next)}
            disabled={devices.data?.next === null || devices.data?.next === undefined}
          >
            Next <ChevronRight />
          </Button>
        </div>
      </nav>
      <ConfirmDialog
        open={confirm !== null}
        title={confirm?.title ?? ''}
        description={confirm?.description}
        confirmLabel={confirm?.confirmLabel}
        pending={mutationPending}
        onConfirm={() => {
          confirm?.run();
          setConfirm(null);
        }}
        onCancel={() => setConfirm(null)}
      />
    </main>
  );
}
