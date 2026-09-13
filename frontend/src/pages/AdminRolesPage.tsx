/**
 * AdminRolesPage — read-only role→permission matrix (D3).
 *
 * Production roles are CODE-DEFINED (Config\AuthGroups, reviewed in git)
 * — this page deliberately has no editing affordances. It renders the
 * authoritative catalog from GET /rbac/roles as a per-module matrix so
 * role management is no longer invisible configuration. Privileged
 * roles (set P) are highlighted; the Platform Owner's wildcard column
 * is called out.
 */
import { Check, ShieldCheck } from 'lucide-react';
import { useMemo } from 'react';
import { Badge } from '@/components/ui/badge';
import { QueryErrorState } from '@/components/QueryErrorState';
import { PageHeader } from '@/components/PageHeader';
import {
  Table,
  TableBody,
  TableCell,
  TableHead,
  TableHeader,
  TableRow,
} from '@/components/ui/table';
import { useAdminRoles, type AdminRole } from '@/hooks/useAdminUsers';

/** Module groups for the matrix sections (D3 ordering). */
const MODULE_GROUPS: ReadonlyArray<{ id: string; label: string; prefixes: readonly string[] }> = [
  { id: 'clinic', label: 'Clinic', prefixes: ['clinic.'] },
  { id: 'guidance', label: 'Guidance', prefixes: ['counselling.'] },
  { id: 'bmg', label: 'BMG / Facilities', prefixes: ['facilities.'] },
  { id: 'referrals', label: 'Referrals', prefixes: ['referrals.'] },
  { id: 'kiosk', label: 'Kiosk', prefixes: ['kiosk.'] },
  { id: 'portal', label: 'Portal', prefixes: ['portal.', 'notifications.', 'student.portal.', 'employee.portal.'] },
  { id: 'reports', label: 'Reports', prefixes: ['reports.'] },
  { id: 'audit', label: 'Audit', prefixes: ['audit.'] },
  { id: 'platform', label: 'Platform', prefixes: ['rbac.', 'api_apps.'] },
];

function moduleFor(code: string): string {
  for (const group of MODULE_GROUPS) {
    if (group.prefixes.some((prefix) => code.startsWith(prefix))) return group.id;
  }
  return 'platform';
}

/** Every permission code in the catalog, per module group. */
function codesByModule(roles: AdminRole[]): Map<string, string[]> {
  const union = new Set<string>();
  for (const role of roles) {
    for (const code of role.permissions) union.add(code);
  }
  const grouped = new Map<string, string[]>(MODULE_GROUPS.map((g) => [g.id, []]));
  for (const code of [...union].sort()) {
    grouped.get(moduleFor(code))?.push(code);
  }
  return grouped;
}

function MatrixSection({
  label,
  codes,
  roles,
}: {
  label: string;
  codes: string[];
  roles: AdminRole[];
}) {
  if (codes.length === 0) return null;
  return (
    <section aria-labelledby={`matrix-${label}`} className="overflow-hidden rounded-xl border bg-card">
      <div className="flex items-center justify-between gap-3 border-b bg-muted/50 px-4 py-3">
        <h2 id={`matrix-${label}`} className="text-sm font-semibold">{label}</h2>
        <p className="text-xs text-muted-foreground">{codes.length} permission{codes.length === 1 ? '' : 's'}</p>
      </div>
      <div className="overflow-x-auto">
        <Table>
          <TableHeader className="bg-muted/30">
            <TableRow>
              <TableHead className="min-w-56 px-3">Permission code</TableHead>
              {roles.map((role) => (
                <TableHead key={role.code} className="px-2 text-center">
                  <span className="block max-w-24 truncate text-xs" title={`${role.name} (${role.code})`}>
                    {role.name}
                  </span>
                </TableHead>
              ))}
            </TableRow>
          </TableHeader>
          <TableBody>
            {codes.map((code) => (
              <TableRow key={code}>
                <TableCell className="px-3 font-mono text-xs">{code}</TableCell>
                {roles.map((role) => (
                  <TableCell key={role.code} className="px-2 text-center">
                    {role.wildcard || role.permissions.includes(code) ? (
                      <Check
                        aria-label={`${role.name} holds ${code}`}
                        className={`mx-auto size-4 ${role.wildcard ? 'text-amber-600 dark:text-amber-400' : 'text-emerald-600 dark:text-emerald-400'}`}
                      />
                    ) : (
                      <span className="text-muted-foreground/40" aria-hidden>·</span>
                    )}
                  </TableCell>
                ))}
              </TableRow>
            ))}
          </TableBody>
        </Table>
      </div>
    </section>
  );
}

export default function AdminRolesPage() {
  const roles = useAdminRoles();
  const grouped = useMemo(() => (roles.data !== undefined ? codesByModule(roles.data) : null), [roles.data]);

  return (
    <main className="mx-auto min-w-0 max-w-7xl space-y-5 p-4 sm:p-6">
      <PageHeader
        title="Roles"
        description="The authoritative role → permission catalog. Roles are code-defined and reviewed in git; this matrix is read-only by design."
        actions={
          <Badge variant="outline" className="gap-1.5">
            <ShieldCheck className="size-3.5" aria-hidden /> {roles.data?.length ?? '…'} roles
          </Badge>
        }
      />

      {roles.isError && (
        <QueryErrorState message="Failed to load the role catalog." onRetry={() => void roles.refetch()} pending={roles.isFetching} />
      )}

      {roles.data !== undefined && grouped !== null && (
        <>
          <section aria-labelledby="roles-summary-heading" className="overflow-hidden rounded-xl border bg-card">
            <h2 id="roles-summary-heading" className="sr-only">Role summary</h2>
            <div className="overflow-x-auto">
              <Table>
                <TableHeader className="bg-muted/50">
                  <TableRow>
                    <TableHead className="px-3">Role</TableHead>
                    <TableHead className="px-3">Code</TableHead>
                    <TableHead className="px-3">Type</TableHead>
                    <TableHead className="px-3 text-right">Permissions</TableHead>
                  </TableRow>
                </TableHeader>
                <TableBody>
                  {roles.data.map((role) => (
                    <TableRow key={role.code}>
                      <TableCell className="px-3 text-sm font-medium">{role.name}</TableCell>
                      <TableCell className="px-3 font-mono text-xs text-muted-foreground">{role.code}</TableCell>
                      <TableCell className="px-3">
                        {role.wildcard ? (
                          <Badge className="bg-amber-200 text-amber-900 hover:bg-amber-200 dark:bg-amber-900 dark:text-amber-200">Platform Owner · wildcard (*)</Badge>
                        ) : role.privileged ? (
                          <Badge variant="warning">Privileged</Badge>
                        ) : (
                          <Badge variant="secondary">Standard</Badge>
                        )}
                      </TableCell>
                      <TableCell className="px-3 text-right text-sm">
                        {role.wildcard ? 'All (wildcard)' : role.permissions.length}
                      </TableCell>
                    </TableRow>
                  ))}
                </TableBody>
              </Table>
            </div>
          </section>

          <p className="text-xs text-muted-foreground">
            Privileged roles (amber) can only be granted or revoked by the Platform Owner. The wildcard column satisfies every permission.
          </p>

          {MODULE_GROUPS.map((group) => (
            <MatrixSection key={group.id} label={group.label} codes={grouped.get(group.id) ?? []} roles={roles.data} />
          ))}
        </>
      )}
    </main>
  );
}
