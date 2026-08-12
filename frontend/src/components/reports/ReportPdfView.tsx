/**
 * ReportPdfView — printable, capture-friendly layout for the PDF export.
 *
 * Renders the active analytics module as a fixed-width (A4 @96dpi = 794px)
 * report with KPIs and modern CSS-based charts. It lives off-screen in the
 * page and is rasterized with `html-to-image` by `useReportPdfExport`, so
 * every chart here uses EXPLICIT hex colours (no CSS variables) and no
 * images/fonts that could fail to inline — what you see is what lands in
 * the PDF. Mirrors the on-screen sections in ReportsPage.
 */
import { moduleLabel } from '@/pages/ReportsPage';
import type {
  ClinicReport,
  CounsellingReport,
  FacilitiesReport,
  InventoryReport,
  ReferralReport,
  ReportModule,
} from '@/schemas/reports';

const MAROON = '#800000';
const INK = '#1C1917';
const MUTED = '#6b6562';
const LINE = '#e6e1de';
const PALETTE = ['#800000', '#b04545', '#4a8fc1', '#7aa85f', '#d2a13b', '#8f6fb5', '#59a18a', '#c76a93'];

export type AnyReport =
  | ClinicReport
  | CounsellingReport
  | InventoryReport
  | ReferralReport
  | FacilitiesReport;

type Breakpoint = { label: string; value: number };

function bars(data: Breakpoint[]): { row: Breakpoint; pct: number; color: string }[] {
  const max = Math.max(1, ...data.map((d) => d.value));
  return data.map((row, i) => ({
    row,
    pct: max > 0 ? (row.value / max) * 100 : 0,
    color: PALETTE[i % PALETTE.length] ?? MAROON,
  }));
}

function BarRow({ label, value, pct, color }: { label: string; value: number; pct: number; color: string }) {
  return (
    <div style={{ marginBottom: 10 }}>
      <div style={{ display: 'flex', justifyContent: 'space-between', fontSize: 12, color: INK, marginBottom: 4 }}>
        <span>{label}</span>
        <span style={{ fontWeight: 700, color: MAROON }}>{value.toLocaleString()}</span>
      </div>
      <div style={{ height: 10, background: '#f1efee', borderRadius: 5, overflow: 'hidden' }}>
        <div style={{ height: '100%', width: pct + '%', background: color, borderRadius: 5 }} />
      </div>
    </div>
  );
}

function Breakdown({ title, data }: { title: string; data: Breakpoint[] }) {
  if (data.length === 0) return null;
  return (
    <div style={{ marginBottom: 18 }}>
      <h4 style={{ fontSize: 13, fontWeight: 700, color: INK, margin: '0 0 10px' }}>{title}</h4>
      {bars(data).map((b) => (
        <BarRow key={b.row.label} label={b.row.label} value={b.row.value} pct={b.pct} color={b.color} />
      ))}
    </div>
  );
}

function TrendChart({ title, unit, points }: { title: string; unit: string; points: Breakpoint[] }) {
  const max = Math.max(1, ...points.map((p) => p.value));
  return (
    <div style={{ marginBottom: 18 }}>
      <h4 style={{ fontSize: 13, fontWeight: 700, color: INK, margin: '0 0 10px' }}>{title}</h4>
      {points.length === 0 ? (
        <p style={{ fontSize: 11, color: MUTED }}>No {unit} recorded in this range.</p>
      ) : (
        <div style={{ display: 'flex', alignItems: 'flex-end', gap: 4, height: 120, paddingTop: 4 }}>
          {points.map((p) => (
            <div key={p.label} style={{ flex: 1, display: 'flex', flexDirection: 'column', alignItems: 'center', height: '100%', justifyContent: 'flex-end' }}>
              <div style={{ width: '100%', maxWidth: 26, background: MAROON, borderRadius: '3px 3px 0 0', height: Math.max(2, (p.value / max) * 100) + '%', minHeight: 2 }} />
              <div style={{ fontSize: 9, color: MUTED, marginTop: 4, whiteSpace: 'nowrap', transform: 'rotate(-45deg)', transformOrigin: 'top left' }}>{p.label}</div>
            </div>
          ))}
        </div>
      )}
    </div>
  );
}

function Kpi({ label, value, detail }: { label: string; value: string; detail?: string }) {
  return (
    <div style={{ flex: 1, minWidth: 130, background: '#fff', border: '1px solid ' + LINE, borderRadius: 10, padding: '10px 12px' }}>
      <div style={{ fontSize: 10, color: MUTED, fontWeight: 600, textTransform: 'uppercase', letterSpacing: 0.4 }}>{label}</div>
      <div style={{ fontSize: 24, fontWeight: 800, color: INK, marginTop: 2, fontVariantNumeric: 'tabular-nums' }}>{value}</div>
      {detail !== undefined && <div style={{ fontSize: 10, color: MUTED, marginTop: 2 }}>{detail}</div>}
    </div>
  );
}

function Table({ title, headers, rows }: { title: string; headers: string[]; rows: (string | number)[][] }) {
  if (rows.length === 0) return null;
  return (
    <div style={{ marginBottom: 18 }}>
      <h4 style={{ fontSize: 13, fontWeight: 700, color: INK, margin: '0 0 8px' }}>{title}</h4>
      <table style={{ width: '100%', borderCollapse: 'collapse', fontSize: 11 }}>
        <thead>
          <tr>
            {headers.map((h) => (
              <th key={h} style={{ textAlign: 'left', borderBottom: '1px solid ' + LINE, padding: '4px 6px', color: MUTED, fontWeight: 600 }}>{h}</th>
            ))}
          </tr>
        </thead>
        <tbody>
          {rows.map((r, i) => (
            <tr key={i} style={{ borderBottom: '1px solid ' + LINE }}>
              {r.map((c, j) => (
                <td key={j} style={{ padding: '4px 6px', color: INK }}>{c}</td>
              ))}
            </tr>
          ))}
        </tbody>
      </table>
    </div>
  );
}

export default function ReportPdfView({
  module,
  start,
  end,
  data,
}: {
  module: ReportModule;
  start: string;
  end: string;
  data: AnyReport | undefined;
}) {
  const generated = new Date().toLocaleString(undefined, {
    dateStyle: 'medium',
    timeStyle: 'short',
  });

  return (
    <div style={{ width: 794, background: '#ffffff', color: INK, fontFamily: 'system-ui, -apple-system, "Segoe UI", sans-serif', padding: 32 }}>
      {/* Header */}
      <div style={{ display: 'flex', justifyContent: 'space-between', alignItems: 'center', borderBottom: '2px solid ' + MAROON, paddingBottom: 12, marginBottom: 16 }}>
        <div>
          <div style={{ fontSize: 22, fontWeight: 800, color: MAROON, letterSpacing: 1 }}>SYNAPSE</div>
          <div style={{ fontSize: 12, color: MUTED }}>Reports &amp; analytics</div>
        </div>
        <div style={{ textAlign: 'right' }}>
          <div style={{ fontSize: 15, fontWeight: 700, color: INK }}>{moduleLabel(module)}</div>
          <div style={{ fontSize: 11, color: MUTED }}>{start} → {end}</div>
          <div style={{ fontSize: 10, color: MUTED }}>Generated {generated}</div>
        </div>
      </div>

      {data === undefined ? (
        <p style={{ fontSize: 12, color: MUTED }}>No report data is available for this range.</p>
      ) : module === 'clinic' ? (
        <ClinicReportView data={data as ClinicReport} />
      ) : module === 'counselling' ? (
        <CounsellingReportView data={data as CounsellingReport} />
      ) : module === 'inventory' ? (
        <InventoryReportView data={data as InventoryReport} />
      ) : module === 'referrals' ? (
        <ReferralReportView data={data as ReferralReport} />
      ) : (
        <FacilitiesReportView data={data as FacilitiesReport} />
      )}

      <div style={{ marginTop: 8, borderTop: '1px solid ' + LINE, paddingTop: 8, fontSize: 9, color: MUTED }}>
        SYNAPSE analytics export · aggregated, privacy-reviewed data · generated by the institution.
      </div>
    </div>
  );
}

function ClinicReportView({ data }: { data: ClinicReport }) {
  return (
    <>
      <div style={{ display: 'flex', gap: 10, marginBottom: 16 }}>
        <Kpi label="Encounters" value={data.total_encounters.toLocaleString()} detail={data.unique_patients.toLocaleString() + ' unique patients'} />
        <Kpi label="Avg visits / patient" value={data.avg_visits_per_patient.toFixed(2)} />
        <Kpi label="Avg per day" value={data.avg_per_day.toFixed(2)} />
      </div>
      <TrendChart title="Monthly visits" unit="visits" points={(data.monthly_visits ?? []).map((p) => ({ label: p.month, value: p.cnt }))} />
      <TrendChart title="Daily trend" unit="encounters" points={data.daily_trend.map((p) => ({ label: p.day, value: p.cnt }))} />
      <Breakdown title="Status" data={data.status_breakdown.map((p) => ({ label: p.status, value: p.cnt }))} />
      <Breakdown title="Patient type" data={data.patient_type_breakdown.map((p) => ({ label: p.kind, value: p.cnt }))} />
      <Breakdown title="Complaint categories" data={data.complaint_categories.map((p) => ({ label: p.category, value: p.cnt }))} />
      <Table
        title="Most used medications"
        headers={['Medicine', 'Quantity']}
        rows={(data.most_common_medications ?? []).map((m) => [m.generic_name + (m.brand_name !== null ? ' (' + m.brand_name + ')' : ''), m.qty + ' ' + m.unit])}
      />
      <Breakdown title="Kiosk check-in outcomes" data={data.checkin_outcomes.map((p) => ({ label: p.outcome, value: p.cnt }))} />
    </>
  );
}

function CounsellingReportView({ data }: { data: CounsellingReport }) {
  return (
    <>
      <div style={{ display: 'flex', gap: 10, marginBottom: 16 }}>
        <Kpi label="Appointments" value={data.total_appointments.toLocaleString()} />
        <Kpi label="Sessions opened" value={data.sessions_opened.toLocaleString()} />
        <Kpi label="No-show rate" value={data.no_show_rate + '%'} detail={data.no_show_count.toLocaleString() + ' no-shows'} />
      </div>
      <TrendChart title="Appointment trend" unit="appointments" points={data.daily_trend.map((p) => ({ label: p.day, value: p.cnt }))} />
      <Breakdown title="Status" data={data.status_breakdown.map((p) => ({ label: p.status, value: p.cnt }))} />
      <Breakdown title="Type" data={data.type_breakdown.map((p) => ({ label: p.type, value: p.cnt }))} />
    </>
  );
}

function InventoryReportView({ data }: { data: InventoryReport }) {
  return (
    <>
      <div style={{ display: 'flex', gap: 10, marginBottom: 16 }}>
        <Kpi label="Medicines" value={data.total_medicines.toLocaleString()} />
        <Kpi label="Dispensed" value={data.total_dispensed.toLocaleString() + ' units'} detail="in range" />
        <Kpi label="Low stock" value={data.low_stock.length.toLocaleString()} />
      </div>
      <TrendChart title="Dispensing trend" unit="units" points={data.dispensing_trend.map((p) => ({ label: p.day, value: p.qty }))} />
      <Table
        title="Top dispensed medicines"
        headers={['Medicine', 'Quantity']}
        rows={data.top_dispensed.map((m) => [m.generic_name + (m.brand_name !== null ? ' (' + m.brand_name + ')' : ''), m.qty + ' ' + m.unit])}
      />
      <Table
        title="Low stock now"
        headers={['Medicine', 'On hand', 'Threshold']}
        rows={data.low_stock.map((m) => [m.generic_name, m.total_stock + ' ' + m.unit, m.reorder_threshold])}
      />
      <Table
        title="Expired stock"
        headers={['Medicine', 'Batch', 'Remaining', 'Expired']}
        rows={data.expired.map((m) => [m.generic_name, m.batch_number, m.quantity_remaining + ' ' + m.unit, m.expiration_date])}
      />
      <Table
        title="Expiring in the next 90 days"
        headers={['Medicine', 'Batch', 'Remaining', 'Expires']}
        rows={data.expiring.map((m) => [m.generic_name, m.batch_number, m.quantity_remaining + ' ' + m.unit, m.expiration_date])}
      />
    </>
  );
}

function ReferralReportView({ data }: { data: ReferralReport }) {
  return (
    <>
      <div style={{ display: 'flex', gap: 10, marginBottom: 16 }}>
        <Kpi label="Referrals" value={data.total_referrals.toLocaleString()} />
        <Kpi label="Closed" value={data.closed_count.toLocaleString()} />
        <Kpi label="Closure rate" value={data.closed_rate + '%'} />
      </div>
      <TrendChart title="Referral trend" unit="referrals" points={data.daily_trend.map((p) => ({ label: p.day, value: p.cnt }))} />
      <Breakdown title="Status" data={data.status_breakdown.map((p) => ({ label: p.status, value: p.cnt }))} />
      <Breakdown title="Direction" data={data.flow_breakdown.map((p) => ({ label: p.source_module + ' → ' + p.target_module, value: p.cnt }))} />
    </>
  );
}

function FacilitiesReportView({ data }: { data: FacilitiesReport }) {
  return (
    <>
      <div style={{ display: 'flex', gap: 10, marginBottom: 16 }}>
        <Kpi label="Batches" value={data.total_batches.toLocaleString()} />
        <Kpi label="Completed" value={data.completed_batches.toLocaleString()} />
        <Kpi label="Yield" value={data.yield_rate + '%'} detail={data.input_kg.toLocaleString() + ' kg in · ' + data.output_kg.toLocaleString() + ' kg out'} />
      </div>
      <TrendChart title="Batch-start trend" unit="batches" points={data.daily_trend.map((p) => ({ label: p.day, value: p.cnt }))} />
      <Breakdown title="Status" data={data.status_breakdown.map((p) => ({ label: p.status, value: p.cnt }))} />
      <Breakdown title="Waste categories" data={data.category_breakdown.map((p) => ({ label: p.category, value: p.cnt }))} />
    </>
  );
}
