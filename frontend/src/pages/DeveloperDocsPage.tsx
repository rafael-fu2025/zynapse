/**
 * DeveloperDocsPage — in-app API reference for the external surface
 * (2026-09, D7). Static content kept in sync with the backend contract
 * (Modules\External + Config\ExternalApps). Gated by `api_apps.read`.
 */
import { BookOpen, ShieldCheck } from 'lucide-react';
import { Badge } from '@/components/ui/badge';
import { PageHeader } from '@/components/PageHeader';
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from '@/components/ui/table';
import { EXTERNAL_SCOPE_CATALOG } from '@/schemas/developerApps';

const ENDPOINTS: ReadonlyArray<{ method: string; path: string; scope: string | null; description: string }> = [
  { method: 'GET', path: '/ping', scope: null, description: 'Liveness probe — no scope required.' },
  { method: 'GET', path: '/me/scopes', scope: null, description: 'Self-description: app identity, key state, effective scopes, endpoint catalog.' },
  { method: 'GET', path: '/aggregates/visits?start=YYYY-MM-DD&end=YYYY-MM-DD', scope: 'reports.read', description: 'De-identified clinic visit and check-in aggregates for a date range (Manila days; defaults to the last 30 days; max 366).' },
  { method: 'GET', path: '/aggregates/referrals?start=YYYY-MM-DD&end=YYYY-MM-DD', scope: 'referrals.read', description: 'Referral flow aggregates — status breakdown, closed rate, daily trend.' },
];

const ERRORS: ReadonlyArray<{ status: string; code: string; meaning: string }> = [
  { status: '401', code: 'auth.api_key_missing / auth.api_key_invalid', meaning: 'No or malformed credential — send X-Api-Key.' },
  { status: '401', code: 'auth.api_key_expired / auth.api_key_revoked', meaning: 'The key rotated past its expiry or was revoked — issue a new key.' },
  { status: '403', code: 'api_app.suspended', meaning: 'The app owning this key is suspended by Synapse staff.' },
  { status: '403', code: 'rbac.permission_denied:<scope>', meaning: 'The key does not hold the required scope.' },
  { status: '422', code: 'request.validation_failed', meaning: 'Bad query parameters (e.g. an invalid date).' },
  { status: '429', code: 'ratelimit.exceeded', meaning: 'The key exceeded its per-minute budget — back off until the window resets.' },
];

function Code({ children }: { children: string }) {
  return <code className="rounded bg-muted px-1.5 py-0.5 font-mono text-xs">{children}</code>;
}

export default function DeveloperDocsPage() {
  const baseUrl = `${window.location.origin}/api/v1/external/v1`;

  return (
    <main className="mx-auto min-w-0 max-w-5xl space-y-6 p-4 sm:p-6">
      <PageHeader
        title="API Docs"
        description="The external, read-only data API for registered integrations. De-identified aggregates only — patient records are never exposed."
        actions={<Badge variant="outline" className="gap-1.5"><BookOpen className="size-3.5" aria-hidden /> External v1</Badge>}
      />

      <section className="space-y-3 rounded-xl border bg-card p-5" aria-labelledby="docs-basics">
        <h2 id="docs-basics" className="text-sm font-semibold">Basics</h2>
        <dl className="space-y-2 text-sm">
          <div>
            <dt className="text-xs font-medium uppercase tracking-wide text-muted-foreground">Base URL</dt>
            <dd><Code>{baseUrl}</Code></dd>
          </div>
          <div>
            <dt className="text-xs font-medium uppercase tracking-wide text-muted-foreground">Authentication</dt>
            <dd className="space-y-1">
              <p><Code>X-Api-Key: syn_live_ab12_xxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxx</Code></p>
              <p className="text-xs text-muted-foreground">
                Also accepted: <Code>Authorization: Bearer syn_…</Code>. Test keys (<Code>syn_test_…</Code>) run against
                the synthetic sandbox tenant; live keys (<Code>syn_live_…</Code>) read production data.
              </p>
            </dd>
          </div>
          <div>
            <dt className="text-xs font-medium uppercase tracking-wide text-muted-foreground">Key lifecycle</dt>
            <dd className="text-xs text-muted-foreground">
              Keys expire after 90 days by default and are revoked instantly on request. Issue a new key before
              revoking the old one for zero-downtime rotation. The full secret is shown once at creation and stored
              only as a hash.
            </dd>
          </div>
        </dl>
      </section>

      <section className="space-y-3 rounded-xl border bg-card p-5" aria-labelledby="docs-scopes">
        <h2 id="docs-scopes" className="text-sm font-semibold">Scopes</h2>
        <div className="overflow-x-auto">
          <Table>
            <TableHeader className="bg-muted/50">
              <TableRow>
                <TableHead className="px-3">Scope</TableHead>
                <TableHead className="px-3">Grants</TableHead>
              </TableRow>
            </TableHeader>
            <TableBody>
              {EXTERNAL_SCOPE_CATALOG.map((scope) => (
                <TableRow key={scope.code}>
                  <TableCell className="px-3 font-mono text-xs">{scope.code}</TableCell>
                  <TableCell className="px-3 text-sm">{scope.description}</TableCell>
                </TableRow>
              ))}
            </TableBody>
          </Table>
        </div>
      </section>

      <section className="space-y-3 rounded-xl border bg-card p-5" aria-labelledby="docs-endpoints">
        <h2 id="docs-endpoints" className="text-sm font-semibold">Endpoint catalog (v1)</h2>
        <div className="overflow-x-auto">
          <Table>
            <TableHeader className="bg-muted/50">
              <TableRow>
                <TableHead className="px-3">Endpoint</TableHead>
                <TableHead className="px-3">Scope</TableHead>
                <TableHead className="px-3">Description</TableHead>
              </TableRow>
            </TableHeader>
            <TableBody>
              {ENDPOINTS.map((endpoint) => (
                <TableRow key={endpoint.path}>
                  <TableCell className="px-3"><Code>{`${endpoint.method} ${endpoint.path}`}</Code></TableCell>
                  <TableCell className="px-3">
                    {endpoint.scope === null
                      ? <Badge variant="secondary">none</Badge>
                      : <Badge variant="outline" className="font-mono text-[10px]">{endpoint.scope}</Badge>}
                  </TableCell>
                  <TableCell className="px-3 text-sm">{endpoint.description}</TableCell>
                </TableRow>
              ))}
            </TableBody>
          </Table>
        </div>
      </section>

      <section className="space-y-3 rounded-xl border bg-card p-5" aria-labelledby="docs-errors">
        <h2 id="docs-errors" className="text-sm font-semibold">Error envelope & rate limits</h2>
        <p className="text-sm text-muted-foreground">
          Every response uses the canonical envelope <Code>{'{ success, data, errors, meta }'}</Code>. Failures carry
          <Code>errors[0].code</Code>:
        </p>
        <div className="overflow-x-auto">
          <Table>
            <TableHeader className="bg-muted/50">
              <TableRow>
                <TableHead className="px-3">HTTP</TableHead>
                <TableHead className="px-3">Code</TableHead>
                <TableHead className="px-3">Meaning</TableHead>
              </TableRow>
            </TableHeader>
            <TableBody>
              {ERRORS.map((error) => (
                <TableRow key={`${error.status}-${error.code}`}>
                  <TableCell className="px-3 font-mono text-xs">{error.status}</TableCell>
                  <TableCell className="px-3 font-mono text-xs">{error.code}</TableCell>
                  <TableCell className="px-3 text-sm">{error.meaning}</TableCell>
                </TableRow>
              ))}
            </TableBody>
          </Table>
        </div>
        <p className="text-sm text-muted-foreground">
          Rate limits are per key, fixed 60-second windows. Responses carry{' '}
          <Code>X-RateLimit-Limit</Code>, <Code>X-RateLimit-Remaining</Code> and <Code>X-RateLimit-Reset</Code>;
          a 429 additionally carries <Code>Retry-After</Code>.
        </p>
      </section>

      <section className="space-y-2 rounded-xl border border-amber-300/70 bg-amber-50/60 p-5 dark:border-amber-800 dark:bg-amber-950/30" aria-labelledby="docs-privacy">
        <h2 id="docs-privacy" className="flex items-center gap-2 text-sm font-semibold">
          <ShieldCheck className="size-4" aria-hidden /> Privacy posture (RA 10173)
        </h2>
        <p className="text-sm">
          The v1 surface returns de-identified, aggregate counts only. Patient-record endpoints do not exist; any
          future PHI-returning scope requires an explicit Platform Owner grant and a documented lawful basis under the
          Data Privacy Act of 2012 (RA 10173). Breach procedures follow NPC Circular 16-03 — see{' '}
          <Code>docs/DATA-SHARING.md</Code> in the repository.
        </p>
      </section>
    </main>
  );
}
