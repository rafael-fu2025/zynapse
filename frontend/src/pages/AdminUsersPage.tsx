/** Administrative account lifecycle and RBAC management. */
import { zodResolver } from '@hookform/resolvers/zod';
import {
  ChevronDown,
  ChevronLeft,
  ChevronRight,
  KeyRound,
  ShieldAlert,
  UserCheck,
  UserCog,
  UserPlus,
  UserX,
} from 'lucide-react';
import { useEffect, useMemo, useState } from 'react';
import { useForm } from 'react-hook-form';
import { useSearchParams } from 'react-router-dom';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import { ConfirmDialog, type ConfirmAction } from '@/components/ConfirmDialog';
import { CopyButton } from '@/components/CopyButton';
import { QueryErrorState } from '@/components/QueryErrorState';
import { SearchBox, highlightMatch } from '@/components/ui/SearchBox';
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
import { Skeleton } from '@/components/ui/skeleton';
import {
  Table,
  TableBody,
  TableCaption,
  TableCell,
  TableHead,
  TableHeader,
  TableRow,
} from '@/components/ui/table';
import {
  createUserSchema,
  useAdminRoles,
  useAdminUsers,
  useCreateUser,
  useResetUserPassword,
  useSetUserActive,
  useSetUserGroups,
  type AdminRole,
  type AdminUser,
  type AdminUsersFilters,
  type CreateUserInput,
} from '@/hooks/useAdminUsers';
import { useDebouncedValue } from '@/hooks/useDebouncedValue';
import { useAuthStore } from '@/store/auth';
import { fmtUtcToApp } from '@/utils/date';

interface TemporaryCredential {
  email: string;
  password: string;
}

function roleName(roles: AdminRole[], code: string): string {
  return roles.find((role) => role.code === code)?.name ?? code.replaceAll('_', ' ');
}

function RoleChecklist({
  roles,
  selected,
  onToggle,
  disabledCode,
}: {
  roles: AdminRole[];
  selected: string[];
  onToggle: (code: string, checked: boolean) => void;
  disabledCode?: string | undefined;
}) {
  return (
    <div className="grid gap-2 sm:grid-cols-2">
      {roles.map((role) => {
        const disabled = disabledCode === role.code;
        return (
          <div
            key={role.code}
            className={role.code === 'admin'
              ? 'rounded-lg border border-amber-300/70 bg-amber-50/60 p-3 dark:border-amber-800 dark:bg-amber-950/30'
              : 'rounded-lg border bg-background p-3'}
          >
            <div className="flex items-start gap-3">
              <Checkbox
                id={`role-${role.code}`}
                checked={selected.includes(role.code)}
                disabled={disabled}
                onCheckedChange={(checked) => onToggle(role.code, checked === true)}
              />
              <Label htmlFor={`role-${role.code}`} className="min-w-0 cursor-pointer font-normal">
                <span className="block text-sm font-medium text-foreground">{role.name}</span>
                <span className="block text-xs text-muted-foreground">
                  {role.permissions.length} permission{role.permissions.length === 1 ? '' : 's'}
                  {disabled ? ' · Your own administrator role is protected' : ''}
                </span>
              </Label>
            </div>
          </div>
        );
      })}
    </div>
  );
}

function CreateUserDialog({
  roles,
  onClose,
  onCreated,
}: {
  roles: AdminRole[];
  onClose: () => void;
  onCreated: (credential: TemporaryCredential) => void;
}) {
  const create = useCreateUser();
  const [adminAcknowledged, setAdminAcknowledged] = useState(false);
  const {
    register,
    handleSubmit,
    formState: { errors },
    reset,
    setValue,
    watch,
  } = useForm<CreateUserInput>({
    resolver: zodResolver(createUserSchema),
    defaultValues: { email: '', username: '', groups: [] },
  });

  const groups = watch('groups') ?? [];
  const grantsAdmin = groups.includes('admin');

  function toggleGroup(group: string, checked: boolean) {
    setValue('groups', checked ? [...groups, group] : groups.filter((item) => item !== group), {
      shouldDirty: true,
      shouldValidate: true,
    });
    if (group === 'admin' && ! checked) setAdminAcknowledged(false);
  }

  const onSubmit = handleSubmit((values) => {
    create.mutate(values, {
      onSuccess: (result) => {
        reset();
        onClose();
        onCreated({ email: result.email, password: result.temporary_password });
      },
    });
  });

  return (
    <DialogContent className="max-h-[90dvh] overflow-y-auto sm:max-w-2xl">
      <DialogHeader>
        <DialogTitle>New user</DialogTitle>
        <DialogDescription>
          The account starts active. A temporary password will be generated and must be changed before other work is allowed.
        </DialogDescription>
      </DialogHeader>
      <form onSubmit={(event) => void onSubmit(event)} className="space-y-5" noValidate>
        <div className="space-y-1.5">
          <Label htmlFor="new-user-email">Email</Label>
          <Input
            id="new-user-email"
            type="email"
            autoComplete="email"
            aria-invalid={errors.email !== undefined}
            aria-describedby={errors.email !== undefined ? 'new-user-email-error' : undefined}
            {...register('email')}
          />
          {errors.email !== undefined && (
            <p id="new-user-email-error" role="alert" className="text-xs text-destructive">{errors.email.message}</p>
          )}
        </div>
        <div className="space-y-1.5">
          <Label htmlFor="new-user-username">Username <span className="text-muted-foreground">(optional)</span></Label>
          <Input
            id="new-user-username"
            autoComplete="username"
            aria-invalid={errors.username !== undefined}
            aria-describedby={errors.username !== undefined ? 'new-user-username-error' : undefined}
            {...register('username')}
          />
          {errors.username !== undefined && (
            <p id="new-user-username-error" role="alert" className="text-xs text-destructive">{errors.username.message}</p>
          )}
        </div>
        <fieldset aria-describedby={errors.groups !== undefined ? 'new-user-roles-error' : undefined}>
          <legend className="text-sm font-medium">Roles</legend>
          <p className="mb-3 mt-1 text-xs text-muted-foreground">Choose at least one role. Access is the union of the selected permissions.</p>
          <RoleChecklist roles={roles} selected={groups} onToggle={toggleGroup} />
          {errors.groups !== undefined && (
            <p id="new-user-roles-error" role="alert" className="mt-2 text-xs text-destructive">{errors.groups.message}</p>
          )}
        </fieldset>
        {grantsAdmin && (
          <div className="rounded-lg border border-amber-300/70 bg-amber-50/60 p-3 dark:border-amber-800 dark:bg-amber-950/30">
            <div className="flex items-start gap-3">
              <Checkbox
                id="acknowledge-new-admin"
                checked={adminAcknowledged}
                onCheckedChange={(checked) => setAdminAcknowledged(checked === true)}
              />
              <Label htmlFor="acknowledge-new-admin" className="font-normal">
                I understand that Administrator grants full platform access.
              </Label>
            </div>
          </div>
        )}
        <DialogFooter>
          <Button type="button" variant="outline" onClick={onClose} disabled={create.isPending}>Cancel</Button>
          <Button type="submit" disabled={create.isPending || (grantsAdmin && ! adminAcknowledged)}>
            <UserPlus aria-hidden /> {create.isPending ? 'Creating…' : grantsAdmin ? 'Create administrator' : 'Create user'}
          </Button>
        </DialogFooter>
      </form>
    </DialogContent>
  );
}

function EditRolesDialog({
  user,
  roles,
  myId,
  onClose,
}: {
  user: AdminUser;
  roles: AdminRole[];
  myId: number | null;
  onClose: () => void;
}) {
  const update = useSetUserGroups();
  const [selected, setSelected] = useState<string[]>(user.groups);
  const [adminAcknowledged, setAdminAcknowledged] = useState(false);
  const adminChanged = selected.includes('admin') !== user.groups.includes('admin');
  const noRoles = selected.length === 0;

  function toggle(code: string, checked: boolean) {
    setSelected((current) => checked ? [...current, code] : current.filter((item) => item !== code));
    if (code === 'admin') setAdminAcknowledged(false);
  }

  return (
    <Dialog open onOpenChange={(open) => ! open && ! update.isPending && onClose()}>
      <DialogContent className="max-h-[90dvh] overflow-y-auto sm:max-w-2xl">
        <DialogHeader>
          <DialogTitle>Edit roles</DialogTitle>
          <DialogDescription>
            Update access for {user.email ?? user.username ?? `user #${user.id}`}. Changes take effect on the next authorized request.
          </DialogDescription>
        </DialogHeader>
        <RoleChecklist
          roles={roles}
          selected={selected}
          onToggle={toggle}
          disabledCode={user.id === myId && user.groups.includes('admin') ? 'admin' : undefined}
        />
        {noRoles && (
          <p role="alert" className="text-sm text-destructive">Select at least one role.</p>
        )}
        {adminChanged && (
          <div className="rounded-lg border border-amber-300/70 bg-amber-50/60 p-3 dark:border-amber-800 dark:bg-amber-950/30">
            <div className="flex items-start gap-3">
              <Checkbox
                id="acknowledge-admin-change"
                checked={adminAcknowledged}
                onCheckedChange={(checked) => setAdminAcknowledged(checked === true)}
              />
              <Label htmlFor="acknowledge-admin-change" className="font-normal">
                I understand this changes full administrative access.
              </Label>
            </div>
          </div>
        )}
        <DialogFooter>
          <Button variant="outline" onClick={onClose} disabled={update.isPending}>Cancel</Button>
          <Button
            onClick={() => update.mutate({ id: user.id, groups: selected }, { onSuccess: onClose })}
            disabled={update.isPending || noRoles || (adminChanged && ! adminAcknowledged)}
          >
            <UserCog aria-hidden /> {update.isPending ? 'Saving…' : 'Save roles'}
          </Button>
        </DialogFooter>
      </DialogContent>
    </Dialog>
  );
}

function SecuritySummary({ user }: { user: AdminUser }) {
  return (
    <div className="space-y-1 text-xs">
      <p><span className="text-muted-foreground">Created:</span> {fmtUtcToApp(user.created_at)}</p>
      <p><span className="text-muted-foreground">Last sign-in:</span> {user.last_active === null ? 'Never' : fmtUtcToApp(user.last_active)}</p>
      {user.force_reset && (
        <p className="flex items-center gap-1 font-medium text-amber-700 dark:text-amber-300">
          <ShieldAlert className="size-3.5" aria-hidden /> Password change required
        </p>
      )}
    </div>
  );
}

function UserActions({
  user,
  myId,
  resetPending,
  statusPending,
  onEditRoles,
  onReset,
  onStatus,
}: {
  user: AdminUser;
  myId: number | null;
  resetPending: boolean;
  statusPending: boolean;
  onEditRoles: () => void;
  onReset: () => void;
  onStatus: () => void;
}) {
  const identity = user.email ?? user.username ?? `user #${user.id}`;
  const isCurrentUser = user.id === myId;
  return (
    <div className="flex justify-end">
      <DropdownMenu>
        <DropdownMenuTrigger asChild>
          <Button className="min-h-11" size="sm" variant="outline" aria-label={`Actions for ${identity}`}>
            Actions <ChevronDown className="size-3.5" aria-hidden />
          </Button>
        </DropdownMenuTrigger>
        <DropdownMenuContent align="end" className="w-52">
          <DropdownMenuItem className="min-h-11" onSelect={onEditRoles}>
            <UserCog aria-hidden /> Edit roles
          </DropdownMenuItem>
          <DropdownMenuItem className="min-h-11" disabled={resetPending} onSelect={onReset}>
            <KeyRound aria-hidden /> {resetPending ? 'Resetting password…' : 'Reset password'}
          </DropdownMenuItem>
          <DropdownMenuSeparator />
          <DropdownMenuItem
            className="min-h-11"
            disabled={statusPending || isCurrentUser}
            onSelect={onStatus}
          >
            {user.active ? <UserX aria-hidden /> : <UserCheck aria-hidden />}
            {statusPending
              ? 'Updating status…'
              : isCurrentUser
                ? 'Deactivate (current account)'
                : user.active
                  ? 'Deactivate account'
                  : 'Activate account'}
          </DropdownMenuItem>
        </DropdownMenuContent>
      </DropdownMenu>
    </div>
  );
}

function LoadingState() {
  return (
    <div role="status" aria-label="Loading users" className="space-y-3 p-4">
      <Skeleton className="h-20 w-full" />
      <Skeleton className="h-20 w-full" />
      <Skeleton className="h-20 w-full" />
    </div>
  );
}

export default function AdminUsersPage() {
  const [searchParams, setSearchParams] = useSearchParams();
  const rawStatus = searchParams.get('status') ?? 'all';
  const rawSort = searchParams.get('sort') ?? 'newest';
  const filters: AdminUsersFilters = useMemo(() => ({
    search: (searchParams.get('q') ?? '').slice(0, 100),
    status: rawStatus === 'active' || rawStatus === 'disabled' ? rawStatus : 'all',
    group: searchParams.get('group') ?? 'all',
    sort: rawSort === 'oldest' ? 'oldest' : 'newest',
  }), [rawSort, rawStatus, searchParams]);
  const filterKey = `${filters.search}|${filters.status}|${filters.group}|${filters.sort}`;

  const [searchDraft, setSearchDraft] = useState(filters.search);
  const debouncedSearch = useDebouncedValue(searchDraft, 300);
  const [cursor, setCursor] = useState<string | null>(null);
  const [history, setHistory] = useState<Array<string | null>>([null]);
  const [openCreate, setOpenCreate] = useState(false);
  const [editRolesUser, setEditRolesUser] = useState<AdminUser | null>(null);
  const [tempCredential, setTempCredential] = useState<TemporaryCredential | null>(null);
  const [confirm, setConfirm] = useState<ConfirmAction | null>(null);

  const roles = useAdminRoles();
  const list = useAdminUsers(cursor, filters, 25);
  const setActive = useSetUserActive();
  const resetPassword = useResetUserPassword();
  const myId = useAuthStore((state) => state.userId);
  const rows = list.data?.data ?? [];

  // Keep the input in sync when the URL is changed externally
  // (browser back/forward, the "Clear filters" button, deep links).
  // When the change comes from the debounced search, the value is
  // already what the input shows, so React bails on the re-render.
  useEffect(() => setSearchDraft(filters.search), [filters.search]);
  useEffect(() => {
    setCursor(null);
    setHistory([null]);
  }, [filterKey]);

  // Push the debounced search into the URL so the server-side filter
  // (and the cursor reset above) react to it. The 300 ms debounce
  // means we don't fire a request per keystroke.
  useEffect(() => {
    const next = debouncedSearch.trim().slice(0, 100);
    if (filters.search === next) return;
    const params = new URLSearchParams(searchParams);
    if (next === '') params.delete('q'); else params.set('q', next);
    setSearchParams(params, { replace: true });
  }, [debouncedSearch, filters.search, searchParams, setSearchParams]);

  useEffect(() => {
    if (rawStatus === filters.status && rawSort === filters.sort) return;
    const next = new URLSearchParams(searchParams);
    if (filters.status === 'all') next.delete('status'); else next.set('status', filters.status);
    if (filters.sort === 'newest') next.delete('sort'); else next.set('sort', filters.sort);
    setSearchParams(next, { replace: true });
  }, [filters.sort, filters.status, rawSort, rawStatus, searchParams, setSearchParams]);

  useEffect(() => {
    if (roles.data === undefined || filters.group === 'all') return;
    if (roles.data.some((role) => role.code === filters.group)) return;
    const next = new URLSearchParams(searchParams);
    next.delete('group');
    setSearchParams(next, { replace: true });
  }, [filters.group, roles.data, searchParams, setSearchParams]);

  function updateFilter(key: 'status' | 'group' | 'sort', value: string) {
    const next = new URLSearchParams(searchParams);
    const defaults = { status: 'all', group: 'all', sort: 'newest' };
    if (value === defaults[key]) next.delete(key); else next.set(key, value);
    setSearchParams(next, { replace: true });
  }

  function nextPage() {
    const next = list.data?.next;
    if (next === null || next === undefined) return;
    setHistory((current) => [...current, next]);
    setCursor(next);
  }

  function prevPage() {
    if (history.length < 2) return;
    const previous = history.slice(0, -1);
    setHistory(previous);
    setCursor(previous[previous.length - 1] ?? null);
  }

  function requestReset(user: AdminUser) {
    const identity = user.email ?? `user #${user.id}`;
    setConfirm({
      title: `Reset password for ${identity}?`,
      description: 'Current sessions will be revoked. A one-time temporary password will be generated and must be changed before other work is allowed.',
      confirmLabel: 'Reset password',
      run: () => resetPassword.mutate(user.id, {
        onSuccess: (result) => {
          setConfirm(null);
          setTempCredential({ email: identity, password: result.temporary_password });
        },
      }),
    });
  }

  function requestStatusChange(user: AdminUser) {
    const identity = user.email ?? `user #${user.id}`;
    const nextActive = ! user.active;
    setConfirm({
      title: `${nextActive ? 'Activate' : 'Deactivate'} ${identity}?`,
      description: nextActive
        ? 'The account will regain the access granted by its current roles.'
        : 'The account will be disabled immediately and all refresh sessions will be revoked.',
      confirmLabel: nextActive ? 'Activate account' : 'Deactivate account',
      run: () => setActive.mutate({ id: user.id, active: nextActive }, { onSuccess: () => setConfirm(null) }),
    });
  }

  const roleOptions = roles.data ?? [];
  const hasFilters = filters.search !== '' || filters.status !== 'all' || filters.group !== 'all';

  return (
    <main className="mx-auto min-w-0 max-w-7xl space-y-5 p-4 sm:p-6">
      <header className="flex flex-col gap-4 sm:flex-row sm:items-start sm:justify-between">
        <div>
          <h1 className="text-xl font-semibold text-foreground">Users</h1>
          <p className="max-w-2xl text-sm text-muted-foreground">
            Manage account access, roles, and credential recovery. Accounts are disabled rather than deleted.
          </p>
        </div>
        <Dialog open={openCreate} onOpenChange={setOpenCreate}>
          <Button onClick={() => setOpenCreate(true)} disabled={roles.isLoading || roles.isError}>
            <UserPlus aria-hidden /> New user
          </Button>
          {openCreate && roles.data !== undefined && (
            <CreateUserDialog roles={roles.data} onClose={() => setOpenCreate(false)} onCreated={setTempCredential} />
          )}
        </Dialog>
      </header>

      {roles.isError && (
        <QueryErrorState message="Failed to load the authoritative role list." onRetry={() => void roles.refetch()} pending={roles.isFetching} />
      )}

      <section aria-labelledby="user-filters-heading" className="space-y-3 border-y py-4">
        <h2 id="user-filters-heading" className="sr-only">User filters</h2>
        <div className="flex flex-col gap-2 sm:flex-row sm:flex-wrap sm:items-center">
          <SearchBox
            value={searchDraft}
            onValueChange={setSearchDraft}
            placeholder="Search email or username"
            inputId="users-search"
            isFetching={list.isFetching && list.data !== undefined}
            className="sm:flex-[2_1_240px]"
          />
          <Select value={filters.status} onValueChange={(value) => updateFilter('status', value)}>
            <SelectTrigger aria-label="Filter users by status" className="sm:flex-1 sm:min-w-[160px]"><SelectValue /></SelectTrigger>
            <SelectContent>
              <SelectItem value="all">All statuses</SelectItem>
              <SelectItem value="active">Active</SelectItem>
              <SelectItem value="disabled">Disabled</SelectItem>
            </SelectContent>
          </Select>
          <Select value={filters.group} onValueChange={(value) => updateFilter('group', value)} disabled={roles.isLoading || roles.isError}>
            <SelectTrigger aria-label="Filter users by role" className="sm:flex-1 sm:min-w-[160px]"><SelectValue placeholder="All roles" /></SelectTrigger>
            <SelectContent>
              <SelectItem value="all">All roles</SelectItem>
              {roleOptions.map((role) => <SelectItem key={role.code} value={role.code}>{role.name}</SelectItem>)}
            </SelectContent>
          </Select>
          <Select value={filters.sort} onValueChange={(value) => updateFilter('sort', value)}>
            <SelectTrigger aria-label="Sort users" className="sm:flex-1 sm:min-w-[160px]"><SelectValue /></SelectTrigger>
            <SelectContent>
              <SelectItem value="newest">Newest first</SelectItem>
              <SelectItem value="oldest">Oldest first</SelectItem>
            </SelectContent>
          </Select>
        </div>
      </section>

      <section aria-labelledby="users-list-heading" className="overflow-hidden rounded-xl border bg-card">
        <h2 id="users-list-heading" className="sr-only">User accounts</h2>
        {list.isLoading && <LoadingState />}
        {list.isError && ! list.isLoading && (
          <div className="p-4">
            <QueryErrorState message="Failed to load users." onRetry={() => void list.refetch()} pending={list.isFetching} />
          </div>
        )}
        {! list.isLoading && ! list.isError && rows.length === 0 && (
          <div className="p-8 text-center">
            <p className="font-medium text-foreground">{hasFilters ? 'No users match these filters' : 'No user accounts yet'}</p>
            <p className="mt-1 text-sm text-muted-foreground">
              {hasFilters ? 'Adjust or clear the search and filters.' : 'Create the first account and assign at least one role.'}
            </p>
            {hasFilters && (
              <Button
                className="mt-4"
                variant="outline"
                onClick={() => {
                  setSearchDraft('');
                  setSearchParams({}, { replace: true });
                }}
              >
                Clear filters
              </Button>
            )}
          </div>
        )}

        {! list.isLoading && ! list.isError && rows.length > 0 && (
          <>
            <div className="divide-y lg:hidden">
              {rows.map((user) => (
                <article key={user.id} className="space-y-4 p-4">
                  <div className="flex items-start justify-between gap-3">
                    <div className="min-w-0">
                      <h3 className="truncate text-sm font-medium text-foreground">
                        {highlightMatch(user.email ?? user.username ?? `User #${user.id}`, filters.search)}
                      </h3>
                      <p className="truncate text-xs text-muted-foreground">
                        {highlightMatch(user.username ?? 'No username', filters.search)} · ID {user.id}
                      </p>
                    </div>
                    {user.active ? <Badge variant="success">Active</Badge> : <Badge variant="destructive">Disabled</Badge>}
                  </div>
                  <div className="flex flex-wrap gap-1.5" aria-label="Assigned roles">
                    {user.groups.map((code) => <Badge key={code} variant="secondary">{roleName(roleOptions, code)}</Badge>)}
                  </div>
                  <SecuritySummary user={user} />
                  <UserActions
                    user={user}
                    myId={myId}
                    resetPending={resetPassword.isPending && resetPassword.variables === user.id}
                    statusPending={setActive.isPending && setActive.variables?.id === user.id}
                    onEditRoles={() => setEditRolesUser(user)}
                    onReset={() => requestReset(user)}
                    onStatus={() => requestStatusChange(user)}
                  />
                </article>
              ))}
            </div>

            <div className="hidden min-w-0 lg:block">
              <Table>
                <TableCaption className="sr-only">User accounts, their roles, security state, and available actions.</TableCaption>
                <TableHeader className="bg-muted/50">
                  <TableRow>
                    <TableHead className="px-3">Account</TableHead>
                    <TableHead className="px-3">Patient</TableHead>
                    <TableHead className="px-3">Roles</TableHead>
                    <TableHead className="px-3">Security</TableHead>
                    <TableHead className="px-3">Status</TableHead>
                    <TableHead className="px-3 text-right">Actions</TableHead>
                  </TableRow>
                </TableHeader>
                <TableBody>
                  {rows.map((user) => (
                    <TableRow key={user.id}>
                      <TableCell className="max-w-52 px-3">
                        <p className="truncate text-sm font-medium">{highlightMatch(user.email ?? 'No email', filters.search)}</p>
                        <p className="truncate text-xs text-muted-foreground">
                          {highlightMatch(user.username ?? 'No username', filters.search)} · ID {user.id}
                        </p>
                      </TableCell>
                      <TableCell className="max-w-48 px-3">
                        {user.person_name !== null ? (
                          <div className="flex flex-col gap-0.5">
                            <span className="truncate text-sm">{user.person_name}</span>
                            {user.person_kind !== null && (
                              <span className="text-[10px] uppercase tracking-wide text-muted-foreground">{user.person_kind}</span>
                            )}
                          </div>
                        ) : (
                          <span className="text-muted-foreground">—</span>
                        )}
                      </TableCell>
                      <TableCell className="max-w-64 px-3">
                        <div className="flex flex-wrap gap-1">
                          {user.groups.map((code) => <Badge key={code} variant="secondary">{roleName(roleOptions, code)}</Badge>)}
                        </div>
                      </TableCell>
                      <TableCell className="px-3"><SecuritySummary user={user} /></TableCell>
                      <TableCell className="px-3">
                        {user.active ? <Badge variant="success">Active</Badge> : <Badge variant="destructive">Disabled</Badge>}
                      </TableCell>
                      <TableCell className="px-3 text-right">
                        <UserActions
                          user={user}
                          myId={myId}
                          resetPending={resetPassword.isPending && resetPassword.variables === user.id}
                          statusPending={setActive.isPending && setActive.variables?.id === user.id}
                          onEditRoles={() => setEditRolesUser(user)}
                          onReset={() => requestReset(user)}
                          onStatus={() => requestStatusChange(user)}
                        />
                      </TableCell>
                    </TableRow>
                  ))}
                </TableBody>
              </Table>
            </div>
          </>
        )}
      </section>

      <nav className="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between" aria-label="User pagination">
        <p className="text-xs text-muted-foreground" role="status">
          Page {history.length} · {rows.length} account{rows.length === 1 ? '' : 's'} shown
        </p>
        <div className="grid grid-cols-2 gap-2 sm:flex">
          <Button className="min-h-11" variant="outline" size="sm" onClick={prevPage} disabled={history.length < 2 || list.isFetching}>
            <ChevronLeft aria-hidden /> Previous
          </Button>
          <Button className="min-h-11" variant="outline" size="sm" onClick={nextPage} disabled={list.data?.next == null || list.isFetching}>
            Next <ChevronRight aria-hidden />
          </Button>
        </div>
      </nav>

      {editRolesUser !== null && roles.data !== undefined && (
        <EditRolesDialog user={editRolesUser} roles={roles.data} myId={myId} onClose={() => setEditRolesUser(null)} />
      )}

      {tempCredential !== null && (
        <Dialog open onOpenChange={(open) => ! open && setTempCredential(null)}>
          <DialogContent>
            <DialogHeader>
              <DialogTitle>Temporary password</DialogTitle>
              <DialogDescription>
                Share this with {tempCredential.email} through a secure channel. It is shown once and must be changed before other work is allowed.
              </DialogDescription>
            </DialogHeader>
            <div className="flex items-center gap-2">
              <p className="min-w-0 flex-1 break-all rounded-lg bg-muted p-3 text-center font-mono text-sm">{tempCredential.password}</p>
              <CopyButton value={tempCredential.password} label="Copy temporary password" successMessage="Temporary password copied." />
            </div>
            <DialogFooter>
              <Button onClick={() => setTempCredential(null)}>Done</Button>
            </DialogFooter>
          </DialogContent>
        </Dialog>
      )}

      <ConfirmDialog
        open={confirm !== null}
        title={confirm?.title ?? ''}
        description={confirm?.description}
        confirmLabel={confirm?.confirmLabel}
        destructive={confirm?.confirmLabel !== 'Activate account'}
        pending={resetPassword.isPending || setActive.isPending}
        onConfirm={() => confirm?.run()}
        onCancel={() => setConfirm(null)}
      />
    </main>
  );
}
