/**
 * ReportPdfView — printable, capture-friendly layout for the PDF export.
 *
 * Follows the classic formal-report structure: a title block carrying the
 * Foundation University identity (seal, name, address), the report title
 * and reporting period; a "Key Figures" summary table; then numbered body
 * sections (trend → status → breakdowns → detailed tables) set in flat
 * typography — hairline rules and whitespace instead of decorated cards.
 * Section content mirrors the on-screen analytics.
 *
 * It lives off-screen in the page and is rasterized with `html-to-image`
 * by `useReportPdfExport`, so every chart uses EXPLICIT hex colours (no
 * CSS variables) and no webfonts — inline SVGs and the same-origin seal
 * PNG are the only non-text assets, both of which html-to-image inlines
 * safely. What you see is what lands in the PDF.
 */
import { format } from 'date-fns';
import type { ReactNode } from 'react';
import { moduleLabel } from '@/pages/ReportsPage';
import { titleCase } from '@/lib/utils';
import type {
  AnyReport,
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
const RULE = '#d9d2ce';
const HAIRLINE = '#e6e1de';
const PALETTE = ['#800000', '#b04545', '#4a8fc1', '#7aa85f', '#d2a13b', '#8f6fb5', '#59a18a', '#c76a93'];

/** Foundation University — the institution the clinic & guidance center serve. */
const UNIVERSITY = {
  name: 'Foundation University',
  address: 'Dr. Miciano Road, Dumaguete City, Negros Oriental, Philippines',
  unit: 'Health & Guidance Services',
};

type Breakpoint = { label: string; value: number };

function Section({ n, title, note, children }: { n: number; title: string; note?: string; children: ReactNode }) {
  return (
    <section style={{ marginBottom: 20 }}>
      <div style={{ display: 'flex', justifyContent: 'space-between', alignItems: 'baseline', borderBottom: '1px solid ' + RULE, paddingBottom: 5, marginBottom: 12 }}>
        <h3 style={{ fontSize: 12.5, fontWeight: 600, color: INK, margin: 0 }}>{n}. {title}</h3>
        {note !== undefined && <span style={{ fontSize: 9, color: MUTED }}>{note}</span>}
      </div>
      {children}
    </section>
  );
}

/** Classic key-figures table: hairline row rules, right-aligned figures. */
function KeyFigures({ rows }: { rows: Array<{ label: string; value: string; detail?: string }> }) {
  return (
    <table style={{ width: '100%', borderCollapse: 'collapse' }}>
      <caption className="sr-only">Key figures</caption>
      <tbody>
        {rows.map((row) => (
          <tr key={row.label} style={{ borderBottom: '1px solid ' + HAIRLINE }}>
            <td style={{ padding: '7px 2px', fontSize: 11, color: INK }}>{row.label}</td>
            <td style={{ padding: '7px 2px', fontSize: 12, fontWeight: 700, color: INK, textAlign: 'right', fontVariantNumeric: 'tabular-nums', whiteSpace: 'nowrap' }}>
              {row.value}
              {row.detail !== undefined && (
                <span style={{ fontSize: 9.5, fontWeight: 400, color: MUTED }}> · {row.detail}</span>
              )}
            </td>
          </tr>
        ))}
      </tbody>
    </table>
  );
}

function bars(data: Breakpoint[]): { row: Breakpoint; pct: number; color: string }[] {
  const max = Math.max(1, ...data.map((d) => d.value));
  return data.map((row, i) => ({
    row,
    pct: max > 0 ? (row.value / max) * 100 : 0,
    color: PALETTE[i % PALETTE.length] ?? MAROON,
  }));
}

/** Horizontal bar breakdown, flat on the page (no enclosing card). */
function BarBreakdown({ data }: { data: Breakpoint[] }) {
  if (data.length === 0) return null;
  return (
    <div>
      {bars(data).map((b) => (
        <div key={b.row.label} style={{ marginBottom: 9 }}>
          <div style={{ display: 'flex', justifyContent: 'space-between', fontSize: 10.5, color: INK, marginBottom: 3 }}>
            <span>{b.row.label}</span>
            <span style={{ fontWeight: 700, fontVariantNumeric: 'tabular-nums' }}>{b.row.value.toLocaleString()}</span>
          </div>
          <div style={{ height: 8, background: '#f3f0ee', borderRadius: 4, overflow: 'hidden' }}>
            <div style={{ height: '100%', width: b.pct + '%', background: b.color, borderRadius: 4 }} />
          </div>
        </div>
      ))}
    </div>
  );
}

/**
 * SVG doughnut + legend — flat, mirroring the on-screen status chart.
 * Rendered with stroke-dasharray circles and explicit hex colours so
 * html-to-image serializes it losslessly.
 */
function Doughnut({ data }: { data: Breakpoint[] }) {
  const total = data.reduce((sum, d) => sum + d.value, 0);
  const size = 120;
  const r = 44;
  const circumference = 2 * Math.PI * r;
  let offset = 0;

  return (
    <div style={{ display: 'flex', alignItems: 'center', gap: 20 }}>
      <div style={{ position: 'relative', width: size, height: size, flexShrink: 0 }}>
        <svg width={size} height={size} viewBox={`0 0 ${size} ${size}`}>
          <circle cx={size / 2} cy={size / 2} r={r} fill="none" stroke="#f3f0ee" strokeWidth={18} />
          {total > 0 && data.map((d, i) => {
            const frac = d.value / total;
            const dash = frac * circumference;
            const el = (
              <circle
                key={d.label}
                cx={size / 2}
                cy={size / 2}
                r={r}
                fill="none"
                stroke={PALETTE[i % PALETTE.length] ?? MAROON}
                strokeWidth={18}
                strokeDasharray={Math.max(dash - 1, 0.5) + ' ' + circumference}
                strokeDashoffset={-offset}
                transform={`rotate(-90 ${size / 2} ${size / 2})`}
              />
            );
            offset += dash;
            return el;
          })}
        </svg>
        <div style={{ position: 'absolute', inset: 0, display: 'flex', flexDirection: 'column', alignItems: 'center', justifyContent: 'center' }}>
          <span style={{ fontSize: 18, fontWeight: 700, color: INK, fontVariantNumeric: 'tabular-nums' }}>{total.toLocaleString()}</span>
          <span style={{ fontSize: 8, color: MUTED, textTransform: 'uppercase', letterSpacing: 0.5 }}>total</span>
        </div>
      </div>
      <div style={{ flex: 1, minWidth: 0 }}>
        {total === 0 ? (
          <p style={{ fontSize: 10.5, color: MUTED, margin: 0 }}>No records in this range.</p>
        ) : (
          data.map((d, i) => (
            <div key={d.label} style={{ display: 'flex', alignItems: 'center', gap: 6, marginBottom: 5, fontSize: 10.5, color: INK }}>
              <span style={{ width: 8, height: 8, borderRadius: 2, background: PALETTE[i % PALETTE.length] ?? MAROON, flexShrink: 0 }} />
              <span style={{ flex: 1, whiteSpace: 'nowrap', overflow: 'hidden', textOverflow: 'ellipsis' }}>{d.label}</span>
              <span style={{ fontWeight: 700, fontVariantNumeric: 'tabular-nums' }}>
                {d.value.toLocaleString()} · {Math.round((d.value / total) * 100)}%
              </span>
            </div>
          ))
        )}
      </div>
    </div>
  );
}

function TrendChart({ points, height = 110 }: { points: Breakpoint[]; height?: number }) {
  const max = Math.max(1, ...points.map((p) => p.value));
  const showValues = points.length <= 16;
  if (points.length === 0) {
    return <p style={{ fontSize: 10.5, color: MUTED }}>No activity recorded in this range.</p>;
  }
  return (
    <div>
      <div style={{ display: 'flex', alignItems: 'flex-end', gap: 3, height, borderBottom: '1.5px solid ' + RULE }}>
        {points.map((p) => (
          <div key={p.label} style={{ flex: 1, display: 'flex', flexDirection: 'column', alignItems: 'center', height: '100%', justifyContent: 'flex-end' }}>
            {showValues && p.value > 0 && (
              <span style={{ fontSize: 7.5, color: MUTED, marginBottom: 2, fontVariantNumeric: 'tabular-nums' }}>{p.value.toLocaleString()}</span>
            )}
            <div
              style={{
                width: '100%',
                maxWidth: 26,
                background: MAROON,
                borderRadius: '2px 2px 0 0',
                height: Math.max(2, (p.value / max) * 100) + '%',
                minHeight: 2,
              }}
            />
          </div>
        ))}
      </div>
      <div style={{ display: 'flex', gap: 3, marginTop: 3 }}>
        {points.map((p) => (
          <span key={p.label} style={{ flex: 1, fontSize: 7.5, color: MUTED, textAlign: 'center', whiteSpace: 'nowrap' }}>{p.label}</span>
        ))}
      </div>
    </div>
  );
}

/** Booktabs-style table: strong top/bottom rules, hairline row rules. */
function DataTable({ title, headers, rows }: { title: string; headers: string[]; rows: (string | number)[][] }) {
  if (rows.length === 0) return null;
  return (
    <table style={{ width: '100%', borderCollapse: 'collapse' }}>
      {/* Accessible name for the DOM copy. `sr-only` keeps it out of the
          captured PDF, which renders from computed styles. */}
      <caption className="sr-only">{title}</caption>
      <thead>
        <tr>
          {headers.map((h) => (
            <th
              key={h}
              scope="col"
              style={{
                textAlign: 'left',
                borderTop: '1.5px solid ' + INK,
                borderBottom: '0.75px solid ' + INK,
                padding: '5px 6px',
                fontSize: 10,
                color: MUTED,
                fontWeight: 600,
              }}
            >
              {h}
            </th>
          ))}
        </tr>
      </thead>
      <tbody>
        {rows.map((r, i) => (
          <tr key={i} style={{ borderBottom: i === rows.length - 1 ? '1.5px solid ' + INK : '1px solid ' + HAIRLINE }}>
            {r.map((c, j) => (
              <td key={j} style={{ padding: '4.5px 6px', fontSize: 10.5, color: INK }}>{c}</td>
            ))}
          </tr>
        ))}
      </tbody>
    </table>
  );
}

function formatDay(iso: string): string {
  const parsed = new Date(iso + 'T00:00:00');
  return Number.isNaN(parsed.getTime()) ? iso : format(parsed, 'MMM d, yyyy');
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
  // Explicit 12-hour pattern rather than `toLocaleString({ timeStyle: 'short' })`:
  // that follows the runtime locale, so a machine set to a 24-hour locale would
  // stamp the report in military time. The clinic reads a 12-hour clock
  // (2026-09-23), and a printed report should not vary by who exported it.
  const generated = format(new Date(), 'MMM d, yyyy · h:mm a');

  return (
    <div style={{ width: 794, background: '#ffffff', color: INK, fontFamily: 'system-ui, -apple-system, "Segoe UI", sans-serif', padding: '36px 40px 32px' }}>
      {/* Title block — masthead carries the institution; the report title,
          period, and generation stamp sit opposite. */}
      <div style={{ display: 'flex', justifyContent: 'space-between', alignItems: 'flex-start', borderBottom: '2.5px solid ' + MAROON, paddingBottom: 14 }}>
        <div style={{ display: 'flex', alignItems: 'center', gap: 12 }}>
          <img
            src="/FoundationU.png"
            alt=""
            style={{ width: 56, height: 56, borderRadius: '50%', objectFit: 'cover', border: '1.5px solid ' + MAROON }}
          />
          <div>
            <div style={{ fontSize: 15, fontWeight: 700, color: INK, lineHeight: 1.25 }}>{UNIVERSITY.name}</div>
            <div style={{ fontSize: 9, color: MUTED, marginTop: 2 }}>{UNIVERSITY.address}</div>
            <div style={{ fontSize: 9, color: MUTED, marginTop: 1 }}>{UNIVERSITY.unit}</div>
          </div>
        </div>
        <div style={{ textAlign: 'right' }}>
          <div style={{ fontSize: 17, fontWeight: 700, color: INK }}>{moduleLabel(module)} Report</div>
          <div style={{ fontSize: 10, color: INK, marginTop: 3 }}>
            Reporting period: {formatDay(start)} — {formatDay(end)}
          </div>
          <div style={{ fontSize: 9, color: MUTED, marginTop: 2 }}>Generated {generated}</div>
        </div>
      </div>

      {data === undefined ? (
        <p style={{ fontSize: 11, color: MUTED, marginTop: 18 }}>No report data is available for this range.</p>
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

      {/* Notes — methodology footnote, per formal-report conventions. */}
      <div style={{ marginTop: 6, borderTop: '1px solid ' + RULE, paddingTop: 8, fontSize: 8.5, color: MUTED, display: 'flex', justifyContent: 'space-between', gap: 16 }}>
        <span>Aggregates computed on Asia/Manila calendar days · privacy-reviewed data · generated by the institution.</span>
        <span style={{ whiteSpace: 'nowrap' }}>SYNAPSE · {UNIVERSITY.name}</span>
      </div>
    </div>
  );
}

function ClinicReportView({ data }: { data: ClinicReport }) {
  let n = 0;
  const next = () => ++n;
  return (
    <>
      <Section n={next()} title="Key Figures">
        <KeyFigures rows={[
          { label: 'Encounters', value: data.total_encounters.toLocaleString(), detail: 'in range' },
          { label: 'Unique patients', value: data.unique_patients.toLocaleString() },
          { label: 'Average visits per patient', value: data.avg_visits_per_patient.toFixed(2) },
          { label: 'Average encounters per day', value: data.avg_per_day.toFixed(2) },
        ]} />
      </Section>
      <Section n={next()} title="Daily Trend" note="encounters per day">
        <TrendChart points={data.daily_trend.map((p) => ({ label: p.day, value: p.cnt }))} />
      </Section>
      <Section n={next()} title="Monthly Visits" note="encounters per month">
        <TrendChart points={(data.monthly_visits ?? []).map((p) => ({ label: p.month, value: p.cnt }))} />
      </Section>
      <Section n={next()} title="Status Breakdown" note="share of encounters">
        <Doughnut data={data.status_breakdown.map((p) => ({ label: titleCase(p.status), value: p.cnt }))} />
      </Section>
      <Section n={next()} title="Patient Types">
        <BarBreakdown data={data.patient_type_breakdown.map((p) => ({ label: titleCase(p.kind), value: p.cnt }))} />
      </Section>
      <Section n={next()} title="Complaint Categories">
        <BarBreakdown data={data.complaint_categories.map((p) => ({ label: titleCase(p.category), value: p.cnt }))} />
      </Section>
      <Section n={next()} title="Most Used Medications" note="units dispensed">
        <DataTable
          title="Most Used Medications"
          headers={['Medicine', 'Quantity']}
          rows={(data.most_common_medications ?? []).map((m) => [m.generic_name + (m.brand_name !== null ? ' (' + m.brand_name + ')' : ''), m.qty + ' ' + m.unit])}
        />
      </Section>
    </>
  );
}

function CounsellingReportView({ data }: { data: CounsellingReport }) {
  let n = 0;
  const next = () => ++n;
  return (
    <>
      <Section n={next()} title="Key Figures">
        <KeyFigures rows={[
          { label: 'Appointments', value: data.total_appointments.toLocaleString(), detail: 'in range' },
          { label: 'Sessions opened', value: data.sessions_opened.toLocaleString() },
          { label: 'No-shows', value: data.no_show_count.toLocaleString(), detail: data.no_show_rate + '% of appointments' },
        ]} />
      </Section>
      <Section n={next()} title="Appointment Trend" note="appointments per day">
        <TrendChart points={data.daily_trend.map((p) => ({ label: p.day, value: p.cnt }))} />
      </Section>
      <Section n={next()} title="Status Breakdown" note="share of appointments">
        <Doughnut data={data.status_breakdown.map((p) => ({ label: titleCase(p.status), value: p.cnt }))} />
      </Section>
      <Section n={next()} title="Appointment Types">
        <BarBreakdown data={data.type_breakdown.map((p) => ({ label: titleCase(p.type), value: p.cnt }))} />
      </Section>
    </>
  );
}

function InventoryReportView({ data }: { data: InventoryReport }) {
  let n = 0;
  const next = () => ++n;
  return (
    <>
      <Section n={next()} title="Key Figures">
        <KeyFigures rows={[
          { label: 'Medicines tracked', value: data.total_medicines.toLocaleString() },
          { label: 'Units dispensed', value: data.total_dispensed.toLocaleString(), detail: 'in range' },
          { label: 'Low stock now', value: data.low_stock.length.toLocaleString(), detail: 'at or below reorder threshold' },
          { label: 'Expiring in 90 days', value: data.expiring.length.toLocaleString(), detail: 'active batches' },
        ]} />
      </Section>
      <Section n={next()} title="Dispensing Trend" note="units per day">
        <TrendChart points={data.dispensing_trend.map((p) => ({ label: p.day, value: p.qty }))} />
      </Section>
      <Section n={next()} title="Top Dispensed Medicines">
        <DataTable
          title="Top Dispensed Medicines"
          headers={['Medicine', 'Quantity']}
          rows={data.top_dispensed.map((m) => [m.generic_name + (m.brand_name !== null ? ' (' + m.brand_name + ')' : ''), m.qty + ' ' + m.unit])}
        />
      </Section>
      <Section n={next()} title="Equipment by Status" note="current state — not range-bound">
        <Doughnut
          data={[
            { label: 'Working', value: data.equipment.status_summary.working },
            { label: 'For Repair', value: data.equipment.status_summary.for_repair },
            { label: 'For Replacement', value: data.equipment.status_summary.for_replacement },
            { label: 'Retired', value: data.equipment.status_summary.retired },
          ]}
        />
      </Section>
      <Section n={next()} title="Low Stock Now">
        <DataTable
          title="Low Stock Now"
          headers={['Medicine', 'On Hand', 'Threshold']}
          rows={data.low_stock.map((m) => [m.generic_name, m.total_stock + ' ' + m.unit, String(m.reorder_threshold)])}
        />
      </Section>
      <Section n={next()} title="Expired Stock">
        <DataTable
          title="Expired Stock"
          headers={['Medicine', 'Batch', 'Remaining', 'Expired']}
          rows={data.expired.map((m) => [m.generic_name, m.batch_number, m.quantity_remaining + ' ' + m.unit, m.expiration_date])}
        />
      </Section>
      <Section n={next()} title="Expiring in the Next 90 Days">
        <DataTable
          title="Expiring in the Next 90 Days"
          headers={['Medicine', 'Batch', 'Remaining', 'Expires']}
          rows={data.expiring.map((m) => [m.generic_name, m.batch_number, m.quantity_remaining + ' ' + m.unit, m.expiration_date])}
        />
      </Section>
      <Section n={next()} title="Equipment for Replacement">
        <DataTable
          title="Equipment for Replacement"
          headers={['Equipment', 'Location', 'Units', 'Flagged Since']}
          rows={data.equipment.needs_replacement.map((e) => [e.name, e.location ?? '—', String(e.units), e.oldest_flagged ?? '—'])}
        />
      </Section>
    </>
  );
}

function ReferralReportView({ data }: { data: ReferralReport }) {
  let n = 0;
  const next = () => ++n;
  return (
    <>
      <Section n={next()} title="Key Figures">
        <KeyFigures rows={[
          { label: 'Referrals created', value: data.total_referrals.toLocaleString(), detail: 'in range' },
          { label: 'Closed', value: data.closed_count.toLocaleString() },
          { label: 'Closure rate', value: data.closed_rate + '%', detail: 'of all referrals in range' },
        ]} />
      </Section>
      <Section n={next()} title="Referral Trend" note="referrals per day">
        <TrendChart points={data.daily_trend.map((p) => ({ label: p.day, value: p.cnt }))} />
      </Section>
      <Section n={next()} title="Status Breakdown" note="share of referrals">
        <Doughnut data={data.status_breakdown.map((p) => ({ label: titleCase(p.status), value: p.cnt }))} />
      </Section>
      <Section n={next()} title="Referral Flows" note="source → target">
        <BarBreakdown data={data.flow_breakdown.map((p) => ({ label: titleCase(p.source_module) + ' → ' + titleCase(p.target_module), value: p.cnt }))} />
      </Section>
    </>
  );
}

function FacilitiesReportView({ data }: { data: FacilitiesReport }) {
  let n = 0;
  const next = () => ++n;
  return (
    <>
      <Section n={next()} title="Key Figures">
        <KeyFigures rows={[
          { label: 'Batches started', value: data.total_batches.toLocaleString(), detail: 'in range' },
          { label: 'Batches completed', value: data.completed_batches.toLocaleString() },
          { label: 'Yield rate', value: data.yield_rate + '%', detail: data.input_kg.toLocaleString() + ' kg in · ' + data.output_kg.toLocaleString() + ' kg out' },
        ]} />
      </Section>
      <Section n={next()} title="Batch-Start Trend" note="batches per day">
        <TrendChart points={data.daily_trend.map((p) => ({ label: p.day, value: p.cnt }))} />
      </Section>
      <Section n={next()} title="Status Breakdown" note="share of batches">
        <Doughnut data={data.status_breakdown.map((p) => ({ label: titleCase(p.status), value: p.cnt }))} />
      </Section>
      <Section n={next()} title="Waste Categories">
        <BarBreakdown data={data.category_breakdown.map((p) => ({ label: p.category, value: p.cnt }))} />
      </Section>
    </>
  );
}
