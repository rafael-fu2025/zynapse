/** Active SPC alerts for the batch — the operator can acknowledge each one. */
import { TriangleAlert, X } from 'lucide-react';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { useAcknowledgeAlert, useAlerts } from '@/hooks/useFacilities';
import { useCan } from '@/hooks/useCan';
import type { BmgAlert, BmgAlertSeverity } from '@/schemas/facilities';
import { fmtUtcToApp } from '@/utils/date';

const SEVERITY_VARIANT: Record<BmgAlertSeverity, 'default' | 'info' | 'warning' | 'destructive'> = {
  info: 'info',
  warning: 'warning',
  critical: 'destructive',
};

export function AlertBanner({ batchId }: { batchId: number }) {
  const alerts = useAlerts(batchId);
  const ack = useAcknowledgeAlert();
  const canAck = useCan('facilities.bmg.logs.record');
  const open = (alerts.data ?? []).filter((a) => a.acknowledged_at === null);

  // Collapsed entirely when there's nothing to show, so a healthy batch
  // doesn't carry an empty banner.
  if (alerts.isLoading || open.length === 0) return null;

  return (
    <section
      role="alert"
      aria-live="polite"
      className="space-y-2 rounded-md border border-amber-500/40 bg-amber-500/5 p-3"
    >
      <header className="flex items-center gap-2 text-amber-600">
        <TriangleAlert className="size-4" />
        <p className="text-sm font-medium">
          {open.length} active alert{open.length === 1 ? '' : 's'}
        </p>
      </header>
      <ul className="space-y-2">
        {open.map((a) => (
          <AlertRow
            key={a.id}
            alert={a}
            canAck={canAck}
            disabled={ack.isPending}
            onAck={() => ack.mutate({ alertId: a.id, batchId })}
          />
        ))}
      </ul>
    </section>
  );
}

function AlertRow({
  alert,
  canAck,
  disabled,
  onAck,
}: {
  alert: BmgAlert;
  canAck: boolean;
  disabled: boolean;
  onAck: () => void;
}) {
  return (
    <li className="flex flex-wrap items-start justify-between gap-2 rounded-md border bg-background/60 p-2">
      <div className="min-w-0 space-y-1">
        <div className="flex flex-wrap items-center gap-1.5">
          <Badge variant={SEVERITY_VARIANT[alert.severity]} className="uppercase">
            {alert.severity}
          </Badge>
          <span className="text-xs text-muted-foreground">{fmtUtcToApp(alert.triggered_at)}</span>
        </div>
        <p className="text-sm text-foreground">{alert.message}</p>
      </div>
      {canAck && (
        <Button size="sm" variant="outline" onClick={onAck} disabled={disabled} aria-label="Acknowledge alert">
          <X /> Acknowledge
        </Button>
      )}
    </li>
  );
}
