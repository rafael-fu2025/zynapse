import { useEffect, useRef, useState } from 'react';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import { Dialog, DialogContent, DialogDescription, DialogFooter, DialogHeader, DialogTitle } from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select';
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from '@/components/ui/table';
import { TimePicker } from '@/components/ui/time-picker';
import { ConfirmDialog } from '@/components/ConfirmDialog';
import { TableStateRows } from '@/components/TableStates';
import { WeekdayCheckboxes } from '@/components/WeekdayCheckboxes';
import { useUrlFilter } from '@/hooks/useUrlFilter';
import {
  schedulingStaffError, useEnrollSchedulingStaff, useSchedulingStaff,
  useSchedulingStaffStatus, useSchedulingTemplate,
} from '@/hooks/useSchedulingStaff';
import { schedulingTemplateSchema, type SchedulingStaff, type SchedulingUnit } from '@/schemas/schedulingStaff';
import { hasPermission, useAuthStore } from '@/store/auth';

const statusLabels: Record<SchedulingStaff['status'], string> = {
  not_enrolled: 'Not enrolled', ready: 'Ready to schedule', configured: 'Has schedule records',
  inactive_employee: 'Inactive employee — review', inactive_enrollment: 'Enrollment inactive',
  department_mismatch: 'Department transfer — review', needs_review: 'Needs review',
};

/** Only team managers discover or enroll directory staff; self-schedule tools stay separate. */
export function DepartmentStaffScheduling({ unit }: { unit: SchedulingUnit }) {
  const auth = useAuthStore();
  const canManage = hasPermission(auth, unit === 'clinic' ? 'clinic.schedules.manage' : 'counselling.schedule.team_manage');
  return canManage ? <DepartmentStaffDirectory unit={unit} /> : null;
}

function DepartmentStaffDirectory({ unit }: { unit: SchedulingUnit }) {
  const prefix = `${unit}-scheduling-staff`;
  const [q, setQ, draft] = useUrlFilter(`${prefix}-q`, { debounceMs: 300 });
  const [urlCursor, setCursor] = useUrlFilter(`${prefix}-cursor`);
  const previousQ = useRef(q);
  const changed = previousQ.current !== q;
  const cursor = changed ? '' : urlCursor;
  useEffect(() => {
    if (previousQ.current !== q) {
      previousQ.current = q;
      setCursor('');
    }
  }, [q, setCursor]);
  const staff = useSchedulingStaff(unit, q, cursor);
  return <DepartmentStaffPage unit={unit} q={q} draft={draft} setQ={setQ} cursor={cursor} setCursor={setCursor} staff={staff} />;
}

function DepartmentStaffPage({ unit, q, draft, setQ, cursor, setCursor, staff }: {
  unit: SchedulingUnit;
  q: string;
  draft: string;
  setQ: (q: string) => void;
  cursor: string;
  setCursor: (cursor: string) => void;
  staff: ReturnType<typeof useSchedulingStaff>;
}) {
  const [enrollIds, setEnrollIds] = useState<number[]>([]);
  const [scheduleIds, setScheduleIds] = useState<number[]>([]);
  const [templateOpen, setTemplateOpen] = useState(false);
  const [confirm, setConfirm] = useState<SchedulingStaff | null>(null);
  const scope = JSON.stringify([unit, q, draft, cursor]);
  const [selectionScope, setSelectionScope] = useState(scope);
  useEffect(() => {
    if (scope !== selectionScope) {
      setEnrollIds([]);
      setScheduleIds([]);
      setTemplateOpen(false);
      setConfirm(null);
      setSelectionScope(scope);
    }
  }, [scope, selectionScope]);
  const enroll = useEnrollSchedulingStaff(unit);
  const status = useSchedulingStaffStatus(unit);
  const template = useSchedulingTemplate(unit);
  const busy = enroll.isPending || status.isPending || template.isPending;
  const disabled = busy || staff.isFetching || staff.isError || draft !== q;
  // Re-check selections against current server data after every refetch.
  const rows = staff.isError ? [] : staff.data?.items ?? [];
  const enrollable = rows.filter((row) => row.can_enroll).map((row) => row.user_id);
  const schedulable = rows.filter((row) => row.eligible && row.enrollment_status === 'active').map((row) => row.user_id);
  const selectedEnroll = selectionScope === scope ? enrollIds.filter((id) => enrollable.includes(id)) : [];
  const selectedSchedule = selectionScope === scope ? scheduleIds.filter((id) => schedulable.includes(id)) : [];
  const toggle = (ids: number[], id: number, checked: boolean) => checked ? [...new Set([...ids, id])] : ids.filter((value) => value !== id);
  const failure = enroll.error ?? status.error ?? template.error;
  const countLabel = unit === 'clinic' ? 'Current/future duty rows' : 'Future appointments';

  function pageCheckbox(kind: 'enroll' | 'schedule') {
    const candidates = kind === 'enroll' ? enrollable : schedulable;
    const selected = kind === 'enroll' ? selectedEnroll : selectedSchedule;
    return <Checkbox
      aria-label={`Select this page for ${kind === 'enroll' ? 'enrollment' : 'scheduling'}`}
      checked={selected.length > 0 && selected.length === candidates.length ? true : selected.length > 0 ? 'indeterminate' : false}
      disabled={disabled || candidates.length === 0}
      onCheckedChange={(checked) => (kind === 'enroll' ? setEnrollIds : setScheduleIds)(checked === true ? candidates : [])}
    />;
  }

  return (
    <section className="space-y-3 border bg-card p-3" aria-label={`${unit === 'clinic' ? 'Clinic' : 'Guidance'} department staff scheduling`}>
      <div className="space-y-1">
        <h2 className="text-sm font-semibold">Department staff scheduling</h2>
        <p className="text-xs text-muted-foreground">Enroll eligible employees from the local directory, then select enrolled staff to add weekly hours. Enrollment does not grant roles, credentials, or record access.</p>
        {unit === 'clinic' && <p className="text-xs text-muted-foreground">Clinic duty roster only — this does not assign patient appointment providers.</p>}
        <p className="text-xs text-muted-foreground">Department aliases: {staff.data ? staff.data.department_keys.join(', ') || 'None configured' : staff.isError ? 'Configuration unavailable' : 'Loading configuration…'}. Missing employees? Ask an administrator to sync the local employee directory and check department aliases in backend Config/SchedulingStaff.</p>
      </div>
      <div className="flex flex-wrap items-end gap-2">
        <div className="min-w-48 flex-1 space-y-1">
          <Label htmlFor={`${unit}-staff-search`}>Find department staff</Label>
          <Input id={`${unit}-staff-search`} placeholder="Name, employee number, department or position" value={draft} disabled={busy} onChange={(event) => setQ(event.target.value)} />
        </div>
        <Button disabled={disabled || selectedEnroll.length === 0} onClick={() => enroll.mutate(selectedEnroll, { onSuccess: () => setEnrollIds([]) })}>Enroll selected ({selectedEnroll.length})</Button>
        <Button variant="outline" disabled={disabled || selectedSchedule.length === 0} onClick={() => setTemplateOpen(true)}>Schedule selected ({selectedSchedule.length})</Button>
      </div>
      <p className="text-xs text-muted-foreground">Enroll and Schedule are separate selections, limited to this page. Changing search or page clears both. Inactive enrollments must be explicitly reactivated.</p>
      {failure && <p role="alert" className="text-sm text-destructive">{schedulingStaffError(failure)}</p>}
      {staff.isError && <div role="alert" className="text-sm text-destructive">
        {schedulingStaffError(staff.error)} Scheduling staff requires the backend scheduling-staff assignments migration; ask an administrator to verify migrations if the service is unavailable.
      </div>}
      <Table ariaLabel="Department scheduling staff">
        <TableHeader><TableRow>
          <TableHead><div className="flex items-center gap-2">{pageCheckbox('enroll')} Enroll</div></TableHead>
          <TableHead><div className="flex items-center gap-2">{pageCheckbox('schedule')} Schedule</div></TableHead>
          <TableHead>Employee</TableHead><TableHead>Department / position</TableHead>
          <TableHead>Employment</TableHead><TableHead>Enrollment</TableHead><TableHead>Schedule status</TableHead>
          <TableHead>{countLabel}</TableHead><TableHead>Actions</TableHead>
        </TableRow></TableHeader>
        <TableBody>
          <TableStateRows colSpan={9} isLoading={staff.isLoading} isError={staff.isError} isEmpty={rows.length === 0}
            onRetry={() => void staff.refetch()} pending={staff.isFetching} errorMessage="Could not load department staff." loadingLabel="Loading department staff"
            empty={{ title: 'No department staff found.', description: 'Try another search or return to the first page. If staff are missing, sync the local employee directory and verify configured department aliases.' }} />
          {rows.map((row) => {
            const review = !row.eligible || row.enrollment_status === 'inactive';
            return <TableRow key={row.user_id} className={review ? 'bg-amber-500/5' : undefined}>
              <TableCell><Checkbox aria-label={`Enroll ${row.name}`} checked={selectedEnroll.includes(row.user_id)} disabled={disabled || !row.can_enroll} onCheckedChange={(checked) => setEnrollIds(toggle(selectedEnroll, row.user_id, checked === true))} /></TableCell>
              <TableCell><Checkbox aria-label={`Schedule ${row.name}`} checked={selectedSchedule.includes(row.user_id)} disabled={disabled || !schedulable.includes(row.user_id)} onCheckedChange={(checked) => setScheduleIds(toggle(selectedSchedule, row.user_id, checked === true))} /></TableCell>
              <TableCell><p className="font-medium">{row.name}</p><p className="text-xs text-muted-foreground">{row.employee_number ?? 'No employee number'}</p></TableCell>
              <TableCell>{row.department ?? 'Unknown department'}<p className="text-xs text-muted-foreground">{row.position ?? 'No position'}</p></TableCell>
              <TableCell>{row.employment_status ?? 'Unknown'}</TableCell>
              <TableCell>{row.enrollment_status ?? 'Not enrolled'}</TableCell>
              <TableCell><Badge variant={review ? 'warning' : 'secondary'}>{statusLabels[row.status]}</Badge></TableCell>
              <TableCell><span className={review && row.future_appointments > 0 ? 'font-semibold text-amber-700 dark:text-amber-400' : ''}>{row.future_appointments}</span>{review && row.future_appointments > 0 && <p className="text-xs">Review existing commitments</p>}</TableCell>
              <TableCell>{row.assignment_id !== null && <Button size="sm" variant="outline" disabled={disabled || (row.enrollment_status === 'inactive' && !row.eligible)} onClick={() => setConfirm(row)}>{row.enrollment_status === 'active' ? 'Deactivate' : 'Reactivate'}</Button>}</TableCell>
            </TableRow>;
          })}
        </TableBody>
      </Table>
      <div className="flex items-center justify-between gap-2">
        <span className="text-xs text-muted-foreground">Up to 25 employees per page</span>
        <div className="flex gap-2">
          <Button size="sm" variant="outline" disabled={busy || staff.isFetching || draft !== q || !cursor} onClick={() => setCursor('')}>First page</Button>
          <Button size="sm" variant="outline" disabled={disabled || !staff.data?.next_cursor} onClick={() => setCursor(staff.data?.next_cursor ?? '')}>Next page</Button>
        </div>
      </div>
      <ConfirmDialog open={confirm !== null} title={`${confirm?.enrollment_status === 'active' ? 'Deactivate' : 'Reactivate'} ${confirm?.name ?? 'enrollment'}?`}
        description={`This changes scheduling enrollment only. Existing schedule records and appointments are retained, not cancelled. ${confirm?.future_appointments ?? 0} ${countLabel.toLowerCase()} require review. Reactivation makes eligible staff available for scheduling again.`}
        confirmLabel={confirm?.enrollment_status === 'active' ? 'Deactivate enrollment' : 'Reactivate enrollment'} destructive={confirm?.enrollment_status === 'active'} pending={status.isPending}
        onCancel={() => { if (!busy) setConfirm(null); }}
        onConfirm={() => {
          if (busy || confirm?.assignment_id == null) return;
          status.mutate({ assignmentId: confirm.assignment_id, active: confirm.enrollment_status !== 'active' }, { onSuccess: () => { setConfirm(null); setEnrollIds([]); setScheduleIds([]); } });
        }} />
      <Dialog open={templateOpen} onOpenChange={(open) => { if (!template.isPending) setTemplateOpen(open); }}>
        {templateOpen && selectionScope === scope && <StaffTemplateDialog unit={unit} userIds={selectedSchedule} mutation={template} onClose={() => setTemplateOpen(false)} onSaved={() => { setTemplateOpen(false); setScheduleIds([]); }} />}
      </Dialog>
    </section>
  );
}

function StaffTemplateDialog({ unit, userIds, mutation, onClose, onSaved }: {
  unit: SchedulingUnit;
  userIds: number[];
  mutation: ReturnType<typeof useSchedulingTemplate>;
  onClose: () => void;
  onSaved: () => void;
}) {
  const [days, setDays] = useState<number[]>([1, 2, 3, 4, 5]);
  const [start, setStart] = useState('08:00');
  const [end, setEnd] = useState('17:00');
  const [type, setType] = useState<'regular' | 'on_call' | 'leave'>('regular');
  const [error, setError] = useState('');
  return <DialogContent lockDismiss>
    <DialogHeader><DialogTitle>Configure {userIds.length} enrolled staff</DialogTitle><DialogDescription>Add weekly hours without replacing existing schedule records.</DialogDescription></DialogHeader>
    <form className="space-y-4" onSubmit={(event) => {
      event.preventDefault();
      if (mutation.isPending) return;
      const result = schedulingTemplateSchema.safeParse({ user_ids: userIds, days_of_week: days, start_time: start, end_time: end, schedule_type: type });
      if (!result.success) { setError(result.error.issues.map((issue) => issue.message).join(' ')); return; }
      setError('');
      mutation.mutate(result.data, { onSuccess: onSaved });
    }}>
      <p className="text-xs text-muted-foreground">Any existing record for a selected employee and weekday wins, including archived, split, dated and leave records. Those days are skipped, not overwritten. Use the existing schedule editors below to change preserved windows.</p>
      <WeekdayCheckboxes idPrefix={`${unit}-staff-template`} value={days} onChange={setDays} disabled={mutation.isPending} />
      <div className="grid grid-cols-1 gap-3 sm:grid-cols-2">
        <div className="min-w-0 space-y-1"><Label htmlFor={`${unit}-template-start`}>Start time</Label><TimePicker id={`${unit}-template-start`} value={start} onChange={setStart} disabled={mutation.isPending} /></div>
        <div className="min-w-0 space-y-1"><Label htmlFor={`${unit}-template-end`}>End time</Label><TimePicker id={`${unit}-template-end`} value={end} onChange={setEnd} disabled={mutation.isPending} /></div>
      </div>
      {unit === 'clinic' && <div className="space-y-1"><Label id="staff-template-type">Schedule type</Label><Select value={type} disabled={mutation.isPending} onValueChange={(value) => setType(value as typeof type)}><SelectTrigger aria-labelledby="staff-template-type"><SelectValue /></SelectTrigger><SelectContent><SelectItem value="regular">Regular</SelectItem><SelectItem value="on_call">On call</SelectItem><SelectItem value="leave">Leave</SelectItem></SelectContent></Select></div>}
      {(error || mutation.error) && <p role="alert" className="text-sm text-destructive">{error || schedulingStaffError(mutation.error)}</p>}
      <DialogFooter><Button type="button" variant="outline" disabled={mutation.isPending} onClick={onClose}>Cancel</Button><Button type="submit" disabled={mutation.isPending || userIds.length === 0}>{mutation.isPending ? 'Adding windows…' : 'Add weekly hours'}</Button></DialogFooter>
    </form>
  </DialogContent>;
}
