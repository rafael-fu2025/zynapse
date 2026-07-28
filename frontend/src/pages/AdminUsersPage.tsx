/**
 * AdminUsersPage — administrative user lifecycle (Phase 10).
 *
 * shadcn Table of users with group chips + active badges; create
 * dialog (RHF + Zod, shadcn Checkbox group), activate/deactivate, and
 * admin password reset. The temporary password is displayed ONCE in a
 * dialog and never stored.
 */
import { zodResolver } from '@hookform/resolvers/zod';
import { KeyRound, Loader2, UserPlus } from 'lucide-react';
import { useState } from 'react';
import { useForm } from 'react-hook-form';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
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
  Table,
  TableBody,
  TableCell,
  TableHead,
  TableHeader,
  TableRow,
} from '@/components/ui/table';
import {
  createUserSchema,
  useAdminUsers,
  useCreateUser,
  useResetUserPassword,
  useSetUserActive,
  type AdminUser,
  type CreateUserInput,
} from '@/hooks/useAdminUsers';
import { useAuthStore } from '@/store/auth';

const GROUPS = ['admin', 'clinic_staff', 'counsellor', 'facilities_op', 'audit_reader'] as const;

function CreateUserDialog({ onClose }: { onClose: () => void }) {
  const create = useCreateUser();
  const {
    register,
    handleSubmit,
    formState: { errors },
    reset,
    setValue,
    watch,
  } = useForm<CreateUserInput>({
    resolver: zodResolver(createUserSchema),
    defaultValues: { groups: [] },
  });

  const groups = watch('groups') ?? [];

  function toggleGroup(group: string, checked: boolean) {
    setValue('groups', checked ? [...groups, group] : groups.filter((g) => g !== group), {
      shouldValidate: true,
    });
  }

  const onSubmit = handleSubmit((values) => {
    create.mutate(values, {
      onSuccess: () => {
        reset();
        onClose();
      },
    });
  });

  return (
    <DialogContent>
      <DialogHeader>
        <DialogTitle>New user</DialogTitle>
      </DialogHeader>
      <form onSubmit={(e) => void onSubmit(e)} className="space-y-3" noValidate>
        <div className="space-y-1.5">
          <Label htmlFor="email">Email</Label>
          <Input id="email" type="email" aria-invalid={errors.email !== undefined} {...register('email')} />
          {errors.email !== undefined && (
            <p role="alert" className="text-xs text-destructive">{errors.email.message}</p>
          )}
        </div>
        <div className="grid grid-cols-2 gap-3">
          <div className="space-y-1.5">
            <Label htmlFor="username">Username</Label>
            <Input id="username" {...register('username')} />
          </div>
          <div className="space-y-1.5">
            <Label htmlFor="password">Password (min 12)</Label>
            <Input id="password" type="password" aria-invalid={errors.password !== undefined} {...register('password')} />
            {errors.password !== undefined && (
              <p role="alert" className="text-xs text-destructive">{errors.password.message}</p>
            )}
          </div>
        </div>
        <fieldset>
          <legend className="text-sm font-medium leading-none">Groups</legend>
          <div className="mt-2 flex flex-wrap gap-4">
            {GROUPS.map((g) => (
              <div key={g} className="flex items-center gap-2">
                <Checkbox
                  id={`group-${g}`}
                  checked={groups.includes(g)}
                  onCheckedChange={(checked) => toggleGroup(g, checked === true)}
                />
                <Label htmlFor={`group-${g}`} className="text-xs font-normal">
                  {g}
                </Label>
              </div>
            ))}
          </div>
        </fieldset>
        <DialogFooter>
          <Button type="button" variant="outline" onClick={onClose}>Cancel</Button>
          <Button type="submit" disabled={create.isPending}>
            {create.isPending && <Loader2 className="animate-spin" />} Create
          </Button>
        </DialogFooter>
      </form>
    </DialogContent>
  );
}

export default function AdminUsersPage() {
  const [cursor] = useState<string | null>(null);
  const [openCreate, setOpenCreate] = useState(false);
  const [tempPassword, setTempPassword] = useState<string | null>(null);
  const list = useAdminUsers(cursor, 25);
  const setActive = useSetUserActive();
  const resetPw = useResetUserPassword();
  const myId = useAuthStore((s) => s.userId);

  const rows: AdminUser[] = list.data?.data ?? [];

  return (
    <main className="mx-auto max-w-7xl space-y-4 p-6">
      <header className="flex items-center justify-between">
        <div>
          <h1 className="text-xl font-semibold text-foreground">Users</h1>
          <p className="text-sm text-muted-foreground">Deactivation is soft — accounts are never deleted.</p>
        </div>
        <Dialog open={openCreate} onOpenChange={setOpenCreate}>
          <Button onClick={() => setOpenCreate(true)}>
            <UserPlus /> New user
          </Button>
          {openCreate && <CreateUserDialog onClose={() => setOpenCreate(false)} />}
        </Dialog>
      </header>

      <section className="overflow-hidden rounded-xl border bg-card">
        <Table>
          <TableHeader className="bg-muted/50">
            <TableRow>
              <TableHead className="px-3">#</TableHead>
              <TableHead className="px-3">Email</TableHead>
              <TableHead className="px-3">Username</TableHead>
              <TableHead className="px-3">Groups</TableHead>
              <TableHead className="px-3">Status</TableHead>
              <TableHead className="px-3 text-right">Actions</TableHead>
            </TableRow>
          </TableHeader>
          <TableBody>
            {list.isLoading && (
              <TableRow>
                <TableCell colSpan={6} className="px-3 py-6 text-center text-muted-foreground">
                  <Loader2 className="mx-auto size-4 animate-spin" />
                </TableCell>
              </TableRow>
            )}
            {rows.map((u) => (
              <TableRow key={u.id}>
                <TableCell className="px-3 font-mono text-xs">{u.id}</TableCell>
                <TableCell className="px-3 text-xs">{u.email ?? '—'}</TableCell>
                <TableCell className="px-3 font-mono text-xs">{u.username ?? '—'}</TableCell>
                <TableCell className="px-3">
                  <div className="flex flex-wrap gap-1">
                    {u.groups.map((g) => <Badge key={g} variant="secondary">{g}</Badge>)}
                  </div>
                </TableCell>
                <TableCell className="px-3">
                  {u.active ? <Badge variant="success">Active</Badge> : <Badge variant="destructive">Disabled</Badge>}
                </TableCell>
                <TableCell className="px-3 text-right">
                  <div className="flex justify-end gap-1">
                    <Button
                      size="sm"
                      variant="outline"
                      disabled={resetPw.isPending}
                      onClick={() => resetPw.mutate(u.id, { onSuccess: (d) => setTempPassword(d.temporary_password) })}
                    >
                      <KeyRound /> Reset
                    </Button>
                    <Button
                      size="sm"
                      variant={u.active ? 'outline' : 'secondary'}
                      disabled={setActive.isPending || u.id === myId}
                      onClick={() => setActive.mutate({ id: u.id, active: !u.active })}
                    >
                      {u.active ? 'Deactivate' : 'Activate'}
                    </Button>
                  </div>
                </TableCell>
              </TableRow>
            ))}
          </TableBody>
        </Table>
      </section>

      {tempPassword !== null && (
        <Dialog open onOpenChange={(o) => !o && setTempPassword(null)}>
          <DialogContent>
            <DialogHeader>
              <DialogTitle>Temporary password</DialogTitle>
              <DialogDescription>
                Share this with the user through a secure channel. It is shown ONCE and the user
                must change it at next login.
              </DialogDescription>
            </DialogHeader>
            <p className="rounded-lg bg-muted p-3 text-center font-mono text-sm">{tempPassword}</p>
            <DialogFooter>
              <Button onClick={() => setTempPassword(null)}>Done</Button>
            </DialogFooter>
          </DialogContent>
        </Dialog>
      )}
    </main>
  );
}
