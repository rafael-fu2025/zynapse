import { useState } from 'react';
import {
  CalendarPlus,
  Check,
  CheckCheck,
  ChevronDown,
  ChevronLeft,
  ChevronRight,
  Loader2,
  UserX,
  X,
} from 'lucide-react';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { ConfirmDialog, type ConfirmAction } from '@/components/ConfirmDialog';
import { Dialog } from '@/components/ui/dialog';
import {
  DropdownMenu,
  DropdownMenuContent,
  DropdownMenuItem,
  DropdownMenuSeparator,
  DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu';
import { Input } from '@/components/ui/input';
import { QueryErrorRow } from '@/components/QueryErrorState';
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
import { useAppointments, useAppointmentTransition } from '@/hooks/useSchedule';
import { hasPermission, useAuthStore } from '@/store/auth';
import { titleCase } from '@/lib/utils';
import {
  APPOINTMENT_STATUSES,
  type Appointment,
  type AppointmentStatus,
} from '@/schemas/schedule';
import { STATUS_VARIANT, TYPE_LABEL } from '../constants';
import { BookAppointmentDialog, CancelAppointmentDialog } from '../dialogs';

interface AppointmentsTableProps {
  status: string;
  onStatusChange: (status: string) => void;
  date: string;
  onDateChange: (date: string) => void;
}

export function AppointmentsTable({
  status,
  onStatusChange,
  date,
  onDateChange,
}: AppointmentsTableProps) {
  const auth = useAuthStore();
  const canMutate = hasPermission(auth, 'counselling.schedule.manage') || hasPermission(auth, 'counselling.schedule.team_manage');

  const [cursor, setCursor] = useState<string | null>(null);
  const [history, setHistory] = useState<Array<string | null>>([null]);
  const [openBook, setOpenBook] = useState(false);
  const [cancelling, setCancelling] = useState<Appointment | null>(null);
  const [confirm, setConfirm] = useState<ConfirmAction | null>(null);

  const statusFilter = status === 'all' ? null : (status as AppointmentStatus);
  const appointments = useAppointments(statusFilter, date !== '' ? date : null, cursor);
  const transition = useAppointmentTransition();

  function nextPage() {
    if (appointments.data?.next !== null && appointments.data?.next !== undefined) {
      const n = appointments.data.next;
      setHistory((h) => [...h, n]);
      setCursor(n);
    }
  }

  function prevPage() {
    if (history.length > 1) {
      const nextH = history.slice(0, -1);
      setHistory(nextH);
      setCursor(nextH[nextH.length - 1] ?? null);
    }
  }

  return (
    <article className="overflow-hidden rounded-xl border bg-card">
      <header className="flex flex-wrap items-center justify-between gap-2 border-b px-3 py-2">
        <p className="text-sm font-semibold text-foreground">Appointments</p>
        <div className="flex flex-wrap items-center gap-2">
          <Input
            type="date"
            aria-label="Filter by date"
            className="h-8 w-40 text-xs"
            value={date}
            onChange={(e) => { onDateChange(e.target.value); setCursor(null); setHistory([null]); }}
          />
          {date !== '' && (
            <Button
              size="sm"
              variant="ghost"
              className="h-8 px-2 text-xs"
              onClick={() => { onDateChange(''); setCursor(null); setHistory([null]); }}
            >
              Clear date
            </Button>
          )}
          <Select
            value={status}
            onValueChange={(v) => { onStatusChange(v); setCursor(null); setHistory([null]); }}
          >
            <SelectTrigger aria-label="Status filter" className="h-8 w-36">
              <SelectValue />
            </SelectTrigger>
            <SelectContent>
              <SelectItem value="all">All statuses</SelectItem>
              {APPOINTMENT_STATUSES.map((s) => (
                <SelectItem key={s} value={s}>{titleCase(s)}</SelectItem>
              ))}
            </SelectContent>
          </Select>
          {canMutate && (
            <Button size="sm" onClick={() => setOpenBook(true)}>
              <CalendarPlus className="size-3.5" /> Book
            </Button>
          )}
        </div>
      </header>

      <Table>
        <TableHeader className="bg-muted/50">
          <TableRow>
            <TableHead className="px-3">#</TableHead>
            <TableHead className="px-3">Patient</TableHead>
            <TableHead className="px-3">Date & time</TableHead>
            <TableHead className="px-3">Type</TableHead>
            <TableHead className="px-3">Status</TableHead>
            <TableHead className="px-3 text-right">Actions</TableHead>
          </TableRow>
        </TableHeader>
        <TableBody>
          {appointments.isLoading && (
            <TableRow>
              <TableCell colSpan={6} className="px-3 py-6 text-center text-muted-foreground">
                <Loader2 className="mx-auto size-4 animate-spin" />
              </TableCell>
            </TableRow>
          )}
          {!appointments.isLoading && (appointments.data?.data.length ?? 0) === 0 && (
            <TableRow>
              <TableCell colSpan={6} className="px-3 py-6 text-center text-muted-foreground">
                No appointments found.
              </TableCell>
            </TableRow>
          )}
          {appointments.isError && !appointments.isLoading && (
            <QueryErrorRow colSpan={6} message="Failed to load appointments." onRetry={() => void appointments.refetch()} pending={appointments.isFetching} />
          )}
          {appointments.data?.data.map((a) => {
            const active = a.status === 'scheduled' || a.status === 'confirmed';
            return (
              <TableRow key={a.id}>
                <TableCell className="px-3 font-mono text-xs">{a.id}</TableCell>
                <TableCell className="px-3 font-mono text-xs">{a.patient_school_id}</TableCell>
                <TableCell className="px-3 font-mono text-xs text-muted-foreground">
                  {a.appointment_date} {a.start_time.slice(0, 5)}–{a.end_time.slice(0, 5)}
                </TableCell>
                <TableCell className="px-3 text-xs">{TYPE_LABEL[a.type]}</TableCell>
                <TableCell className="px-3">
                  <Badge variant={STATUS_VARIANT[a.status]}>{titleCase(a.status)}</Badge>
                </TableCell>
                <TableCell className="px-3 text-right">
                  {canMutate && active && (
                    <div className="flex items-center justify-end gap-1">
                      {a.status === 'scheduled' && (
                        <Button
                          size="sm"
                          variant="outline"
                          aria-label={`Confirm appointment #${a.id}`}
                          disabled={transition.isPending}
                          onClick={() => transition.mutate({ id: a.id, action: 'confirm' })}
                        >
                          <Check className="size-3.5" /> Confirm
                        </Button>
                      )}
                      <Button
                        size="sm"
                        variant="secondary"
                        aria-label={`Complete appointment #${a.id}`}
                        disabled={transition.isPending}
                        onClick={() => transition.mutate({ id: a.id, action: 'complete' })}
                      >
                        <CheckCheck className="size-3.5" /> Complete
                      </Button>
                      <DropdownMenu>
                        <DropdownMenuTrigger asChild>
                          <Button size="sm" variant="outline" aria-label={`Actions for appointment #${a.id}`}>
                            Actions <ChevronDown className="size-3.5" aria-hidden />
                          </Button>
                        </DropdownMenuTrigger>
                        <DropdownMenuContent align="end" className="w-52">
                          <DropdownMenuItem
                            disabled={transition.isPending}
                            onSelect={() => setConfirm({
                              title: `Mark appointment #${a.id} as no-show?`,
                              description: 'This records a no-show, which counts toward the patient\'s three-strike counter.',
                              confirmLabel: 'Mark no-show',
                              run: () => transition.mutate({ id: a.id, action: 'no_show' }),
                            })}
                          >
                            <UserX className="size-3.5" /> Mark no-show
                          </DropdownMenuItem>
                          <DropdownMenuSeparator />
                          <DropdownMenuItem
                            className="text-destructive focus:text-destructive"
                            disabled={transition.isPending}
                            onSelect={() => setCancelling(a)}
                          >
                            <X className="size-3.5" /> Cancel appointment
                          </DropdownMenuItem>
                        </DropdownMenuContent>
                      </DropdownMenu>
                    </div>
                  )}
                </TableCell>
              </TableRow>
            );
          })}
        </TableBody>
      </Table>

      <nav className="flex items-center justify-between border-t px-3 py-2">
        <p className="text-xs text-muted-foreground">
          {appointments.data?.data.length ?? 0} appointment{(appointments.data?.data.length ?? 0) === 1 ? '' : 's'}
        </p>
        <div className="flex gap-2">
          <Button
            size="sm"
            variant="outline"
            onClick={prevPage}
            disabled={history.length <= 1}
          >
            <ChevronLeft className="size-3.5" /> Previous
          </Button>
          <Button
            size="sm"
            variant="outline"
            onClick={nextPage}
            disabled={appointments.data?.next === null || appointments.data?.next === undefined}
          >
            Next <ChevronRight className="size-3.5" />
          </Button>
        </div>
      </nav>

      <Dialog open={openBook} onOpenChange={setOpenBook}>
        {openBook && <BookAppointmentDialog onClose={() => setOpenBook(false)} />}
      </Dialog>

      <Dialog open={cancelling !== null} onOpenChange={(o) => !o && setCancelling(null)}>
        {cancelling !== null && (
          <CancelAppointmentDialog appointment={cancelling} onClose={() => setCancelling(null)} />
        )}
      </Dialog>

      <ConfirmDialog
        open={confirm !== null}
        title={confirm?.title ?? ''}
        description={confirm?.description}
        confirmLabel={confirm?.confirmLabel ?? 'Confirm'}
        pending={transition.isPending}
        onConfirm={() => {
          if (confirm) {
            confirm.run();
            setConfirm(null);
          }
        }}
        onCancel={() => setConfirm(null)}
      />
    </article>
  );
}
