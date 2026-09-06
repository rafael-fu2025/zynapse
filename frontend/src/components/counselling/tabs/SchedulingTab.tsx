import { Tabs, TabsContent, TabsList, TabsTrigger } from '@/components/ui/tabs';
import { useUrlFilter } from '@/hooks/useUrlFilter';
import { AvailabilityView, AppointmentsTable } from '../scheduling';

export function SchedulingTab() {
  const [subTab, setSubTab] = useUrlFilter('subtab', { default: 'availability' });
  const [status, setStatus] = useUrlFilter('appt_status', { default: 'all' });
  const [date, setDate] = useUrlFilter('appt_date', { default: '' });
  const [availabilityView, setAvailabilityView] = useUrlFilter('avail_view', { default: 'list' });

  return (
    <div className="space-y-4">
      <Tabs value={subTab} onValueChange={setSubTab} className="space-y-4">
        <TabsList>
          <TabsTrigger value="availability">Availability Windows</TabsTrigger>
          <TabsTrigger value="appointments">Appointments</TabsTrigger>
        </TabsList>

        <TabsContent value="availability">
          <AvailabilityView
            view={availabilityView}
            onViewChange={setAvailabilityView}
          />
        </TabsContent>

        <TabsContent value="appointments">
          <AppointmentsTable
            status={status}
            onStatusChange={setStatus}
            date={date}
            onDateChange={setDate}
          />
        </TabsContent>
      </Tabs>
    </div>
  );
}
