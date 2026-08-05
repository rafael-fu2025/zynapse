/**
 * StudentBooking — self-service clinic appointment booking for the
 * student portal.
 *
 * The student picks a provider (clinic staff), a date + time, and an
 * optional reason; the slot is converted to UTC via `appDateTimeToUtcSql`
 * (same as the staff scheduling screen) and booked for the CALLING
 * student. The booked slot then flows through the normal clinic
 * pipeline — at check-in the kiosk auto-opens the encounter.
 *
 * Also lists the student's own appointments so the dashboard isn't a
 * dead end.
 */
import { CalendarPlus, Clock3, Loader2, UserRound } from 'lucide-react';
import { useState } from 'react';
import { toast } from 'sonner';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/card';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select';
import { DatePicker } from '@/components/ui/date-picker';
import { TimePicker } from '@/components/ui/time-picker';
import {
  useBookStudentAppointment,
  useMyStudentAppointments,
  useStudentProviders,
} from '@/hooks/useStudentPortal';
import type { StudentAppointment } from '@/schemas/studentPortal';
import { appDateTimeToUtcSql, fmtUtcToApp } from '@/utils/date';

const STATUS_VARIANT: Record<string, 'info' | 'success' | 'warning' | 'secondary' | 'destructive'> = {
  scheduled: 'info',
  confirmed: 'success',
  checked_in: 'info',
  completed: 'success',
  no_show: 'destructive',
  cancelled: 'secondary',
};

export function StudentBookingSection() {
  const providers = useStudentProviders();
  const appointments = useMyStudentAppointments();
  const book = useBookStudentAppointment();

  const [providerId, setProviderId] = useState<number | null>(null);
  const [date, setDate] = useState('');
  const [time, setTime] = useState('');
  const [reason, setReason] = useState('');

  function submit() {
    if (providerId === null) {
      toast.error('Please pick a provider.');
      return;
    }
    const scheduledAt = appDateTimeToUtcSql(date, time);
    if (scheduledAt === '') {
      toast.error('Please pick a date and time.');
      return;
    }
    book.mutate(
      { provider_user_id: providerId, scheduled_at: scheduledAt, reason: reason.trim() !== '' ? reason.trim() : undefined },
      {
        onSuccess: () => {
          setDate('');
          setTime('');
          setReason('');
        },
      },
    );
  }

  return (
    <div className="grid gap-4 lg:grid-cols-2">
      {/* Book an appointment */}
      <Card>
        <CardHeader className="pb-2">
          <CardTitle className="flex items-center gap-2 text-base">
            <CalendarPlus className="size-4 text-primary" aria-hidden /> Book an appointment
          </CardTitle>
          <CardDescription>See the clinic at a time that suits you — no need to queue.</CardDescription>
        </CardHeader>
        <CardContent className="space-y-3">
          <div className="space-y-1.5">
            <Label id="sb-provider-label">Provider</Label>
            <Select
              value={providerId !== null ? String(providerId) : ''}
              onValueChange={(v) => setProviderId(Number(v))}
            >
              <SelectTrigger aria-labelledby="sb-provider-label">
                <SelectValue
                  placeholder={providers.isLoading ? 'Loading providers…' : providers.data?.length ? 'Who do you want to see?' : 'No providers available'}
                />
              </SelectTrigger>
              <SelectContent>
                {(providers.data ?? []).map((p) => (
                  <SelectItem key={p.id} value={String(p.id)}>
                    <span className="inline-flex items-center gap-1.5">
                      <UserRound className="size-3.5" aria-hidden /> {p.name}
                    </span>
                  </SelectItem>
                ))}
              </SelectContent>
            </Select>
            {providers.data?.length === 0 && (
              <p className="text-xs text-muted-foreground">No clinic staff are listed as providers yet.</p>
            )}
          </div>
          <div className="grid grid-cols-2 gap-3">
            <div className="space-y-1.5">
              <Label htmlFor="sb-date">Date</Label>
              <DatePicker id="sb-date" value={date} onChange={setDate} />
            </div>
            <div className="space-y-1.5">
              <Label htmlFor="sb-time">Time</Label>
              <TimePicker id="sb-time" value={time} onChange={setTime} />
            </div>
          </div>
          <div className="space-y-1.5">
            <Label htmlFor="sb-reason">Reason (optional)</Label>
            <Input id="sb-reason" placeholder="e.g. flu symptoms, check-up" value={reason} onChange={(e) => setReason(e.target.value)} maxLength={500} />
          </div>
          <Button className="w-full" onClick={submit} disabled={book.isPending}>
            {book.isPending && <Loader2 className="animate-spin" aria-hidden />}
            <CalendarPlus /> Book appointment
          </Button>
        </CardContent>
      </Card>

      {/* My appointments */}
      <Card>
        <CardHeader className="pb-2">
          <CardTitle className="flex items-center gap-2 text-base">
            <Clock3 className="size-4 text-primary" aria-hidden /> My appointments
          </CardTitle>
          <CardDescription>Your upcoming and past clinic appointments.</CardDescription>
        </CardHeader>
        <CardContent>
          {appointments.isLoading && (
            <p className="flex items-center gap-2 py-6 text-sm text-muted-foreground">
              <Loader2 className="size-3.5 animate-spin" aria-hidden /> Loading…
            </p>
          )}
          {appointments.data !== undefined && appointments.data.length === 0 && (
            <p className="py-6 text-sm text-muted-foreground">No appointments yet — book one on the left.</p>
          )}
          {appointments.data !== undefined && appointments.data.length > 0 && (
            <ul className="max-h-80 space-y-2 overflow-y-auto pr-1 text-xs">
              {appointments.data.map((a) => (
                <AppointmentRow key={a.id} a={a} />
              ))}
            </ul>
          )}
        </CardContent>
      </Card>
    </div>
  );
}

function AppointmentRow({ a }: { a: StudentAppointment }) {
  return (
    <li className="flex items-center gap-3 rounded-lg border bg-muted/30 px-3 py-2">
      <div className="min-w-0 flex-1">
        <p className="font-medium text-foreground">{fmtUtcToApp(a.scheduled_at)}</p>
        <p className="truncate text-muted-foreground">
          {a.provider_name ?? `Provider #${a.provider_user_id}`}
          {a.reason !== null && a.reason !== '' ? ` · ${a.reason}` : ''}
        </p>
      </div>
      <Badge variant={STATUS_VARIANT[a.status] ?? 'secondary'} className="shrink-0 capitalize">
        {a.status.replace('_', ' ')}
      </Badge>
    </li>
  );
}
