/**
 * PortalAppointments — shared self-service booking surface for the
 * student and employee portals.
 *
 * Formatting note: this file was previously one golfed 12-line
 * statement (2026-09 audit P2); it now reads like the rest of the
 * codebase. Booking cancels go through the shared ConfirmDialog, and
 * errors surface the ACTUAL backend reason instead of a canned
 * one-hour-cutoff message.
 */
import { useState } from 'react';
import { CalendarPlus, Loader2, XCircle } from 'lucide-react';
import { toast } from 'sonner';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/card';
import { DatePicker } from '@/components/ui/date-picker';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select';
import { ConfirmDialog } from '@/components/ConfirmDialog';
import { ApiEnvelopeError } from '@/api/envelope';
import {
  useAppointmentSlots,
  useBookPortalAppointment,
  useCancelPortalAppointment,
  usePortalAppointments,
  type AppointmentSlot,
  type PortalAppointment,
} from '@/hooks/usePortalAppointments';
import { fmtUtcToApp } from '@/utils/date';

function firstErrorMessage(err: unknown, fallback: string): string {
  return err instanceof ApiEnvelopeError ? (err.errors[0]?.message ?? fallback) : fallback;
}

export function PortalAppointments() {
  const [department, setDepartment] = useState('clinic');
  const [date, setDate] = useState('');
  const [selected, setSelected] = useState<AppointmentSlot | null>(null);
  const [reason, setReason] = useState('');
  const [type, setType] = useState('initial');
  const [cancelling, setCancelling] = useState<PortalAppointment | null>(null);

  const slots = useAppointmentSlots(department, date);
  const appointments = usePortalAppointments();
  const book = useBookPortalAppointment();
  const cancel = useCancelPortalAppointment();

  function submit() {
    if (selected === null) {
      toast.error('Choose an available slot.');
      return;
    }
    book.mutate(
      {
        department,
        provider_user_id: selected.provider_user_id,
        starts_at: selected.starts_at,
        ...(reason.trim() !== '' ? { reason: reason.trim() } : {}),
        ...(department === 'counselling' ? { type } : {}),
      },
      {
        onSuccess: () => {
          setSelected(null);
          setReason('');
          toast.success('Appointment booked.');
        },
        onError: (err) => toast.error(firstErrorMessage(err, 'That slot could not be booked. Refresh and try again.')),
      },
    );
  }

  function cancelOne() {
    const a = cancelling;
    if (a === null) return;
    cancel.mutate(a, {
      onSuccess: () => {
        setCancelling(null);
        toast.success('Appointment cancelled.');
      },
      onError: (err) => toast.error(firstErrorMessage(err, 'Cancellation closed. The slot may have already been resolved.')),
    });
  }

  return (
    <div className="grid gap-4 lg:grid-cols-2">
      <Card>
        <CardHeader>
          <CardTitle className="flex items-center gap-2 text-base"><CalendarPlus className="size-4" />Book an appointment</CardTitle>
          <CardDescription>Choose an available 60-minute Clinic or Guidance slot.</CardDescription>
        </CardHeader>
        <CardContent className="space-y-3">
          <div className="grid grid-cols-2 gap-3">
            <div>
              <Label>Department</Label>
              <Select value={department} onValueChange={(v) => { setDepartment(v); setSelected(null); }}>
                <SelectTrigger><SelectValue /></SelectTrigger>
                <SelectContent>
                  <SelectItem value="clinic">Clinic</SelectItem>
                  <SelectItem value="counselling">Guidance</SelectItem>
                </SelectContent>
              </Select>
            </div>
            <div>
              <Label>Date</Label>
              <DatePicker value={date} onChange={(v) => { setDate(v); setSelected(null); }} />
            </div>
          </div>
          {department === 'counselling' && (
            <div>
              <Label>Appointment type</Label>
              <Select value={type} onValueChange={setType}>
                <SelectTrigger><SelectValue /></SelectTrigger>
                <SelectContent>
                  <SelectItem value="initial">Initial</SelectItem>
                  <SelectItem value="follow_up">Follow-up</SelectItem>
                  <SelectItem value="crisis">Crisis</SelectItem>
                  <SelectItem value="referral_based">Referral-based</SelectItem>
                </SelectContent>
              </Select>
            </div>
          )}
          <div>
            <Label>Available slot</Label>
            <Select
              value={selected?.starts_at ?? ''}
              onValueChange={(v) => setSelected(slots.data?.find((s) => s.starts_at === v) ?? null)}
            >
              <SelectTrigger>
                <SelectValue placeholder={slots.isLoading ? 'Loading slots…' : 'Choose provider and time'} />
              </SelectTrigger>
              <SelectContent>
                {(slots.data ?? []).map((s) => (
                  <SelectItem key={`${s.provider_user_id}-${s.starts_at}`} value={s.starts_at}>
                    {fmtUtcToApp(s.starts_at)} · {s.provider_name}
                  </SelectItem>
                ))}
              </SelectContent>
            </Select>
            {date !== '' && !slots.isLoading && slots.data?.length === 0 && (
              <p className="mt-1 text-xs text-muted-foreground">No available slots on this date.</p>
            )}
          </div>
          <div>
            <Label>Reason (optional)</Label>
            <Input value={reason} onChange={(e) => setReason(e.target.value)} maxLength={255} />
          </div>
          <Button className="w-full" disabled={selected === null || book.isPending} onClick={submit}>
            {book.isPending && <Loader2 className="animate-spin" />}
            Book appointment
          </Button>
        </CardContent>
      </Card>

      <Card>
        <CardHeader>
          <CardTitle className="text-base">My appointments</CardTitle>
          <CardDescription>Upcoming and past Clinic and Guidance appointments.</CardDescription>
        </CardHeader>
        <CardContent>
          {appointments.isLoading ? (
            <p className="text-sm text-muted-foreground">Loading…</p>
          ) : (appointments.data?.length ?? 0) === 0 ? (
            <p className="text-sm text-muted-foreground">No appointments yet.</p>
          ) : (
            <ul className="max-h-96 space-y-2 overflow-y-auto">
              {appointments.data?.map((a) => (
                <li key={`${a.department}-${a.id}`} className="flex items-center gap-2 rounded-lg border p-3 text-xs">
                  <div className="min-w-0 flex-1">
                    <p className="font-medium">{fmtUtcToApp(a.starts_at)}</p>
                    <p className="truncate text-muted-foreground">
                      {a.department === 'clinic' ? 'Clinic' : 'Guidance'} · {a.provider_name ?? `Provider #${a.provider_user_id}`}
                    </p>
                  </div>
                  <Badge variant="secondary">{a.status.replace('_', ' ')}</Badge>
                  {a.status === 'scheduled' && (
                    <Button size="icon" variant="ghost" title="Cancel appointment" onClick={() => setCancelling(a)} disabled={cancel.isPending}>
                      <XCircle className="size-4" />
                    </Button>
                  )}
                </li>
              ))}
            </ul>
          )}
        </CardContent>
      </Card>

      <ConfirmDialog
        open={cancelling !== null}
        title="Cancel this appointment?"
        description={
          cancelling === null
            ? undefined
            : `Your ${cancelling.department === 'clinic' ? 'Clinic' : 'Guidance'} slot on ${fmtUtcToApp(cancelling.starts_at)} will be released. Cancellation closes one hour before the appointment.`
        }
        confirmLabel="Cancel appointment"
        pending={cancel.isPending}
        onConfirm={cancelOne}
        onCancel={() => setCancelling(null)}
      />
    </div>
  );
}
