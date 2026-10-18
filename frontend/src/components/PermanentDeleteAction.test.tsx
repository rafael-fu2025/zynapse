import { renderToStaticMarkup } from 'react-dom/server';
import { QueryClient, QueryClientProvider } from '@tanstack/react-query';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import { PermanentDeleteAction, PermanentDeleteProvider, permanentDeletionBlockReason, type PermanentDeleteResource } from './PermanentDeleteAction';

const auth = vi.hoisted(() => ({ allowed: true, permissions: [] as string[] }));
vi.mock('@/hooks/useCan', () => ({ useCan: (permission: string) => {
  auth.permissions.push(permission);
  return auth.allowed;
} }));

beforeEach(() => { auth.allowed = true; auth.permissions = []; });

function renderAction(resource: PermanentDeleteResource, provider = true) {
  const action = <PermanentDeleteAction resource={resource} id={42} label="Archived record" />;
  if (!provider) return renderToStaticMarkup(action);
  return renderToStaticMarkup(
    <QueryClientProvider client={new QueryClient()}>
      <PermanentDeleteProvider>{action}</PermanentDeleteProvider>
    </QueryClientProvider>,
  );
}

describe('guarded permanent deletion actions', () => {
  it.each(['students', 'employees', 'encounters', 'counselling-sessions'] as const)(
    '%s is visibly retention-protected, disabled and accessibly explained', (resource) => {
      const html = renderAction(resource);
      expect(html).toContain('Delete permanently');
      expect(html).toContain('disabled=""');
      expect(html).toContain('aria-describedby=');
      expect(html).toContain(permanentDeletionBlockReason(resource));
      expect(html).not.toContain('sr-only');
    },
  );

  it.each(['announcements', 'services', 'surveys', 'equipment', 'medicines', 'supplies',
    'staff-schedules', 'devices', 'waste-categories', 'drums', 'report-configs'] as const)(
    '%s allows a confirmation request; dependency checks remain server-owned', (resource) => {
      const html = renderAction(resource);
      expect(html).toContain('Delete permanently');
      expect(html).not.toContain('disabled=""');
      expect(permanentDeletionBlockReason(resource)).toBeUndefined();
    },
  );

  it('hides actions without the resource permission', () => {
    auth.allowed = false;
    expect(renderAction('medicines')).toBe('');
    expect(auth.permissions).toEqual(['clinic.inventory.delete']);
  });

  it('fails closed when the global confirmation provider is absent', () => {
    expect(renderAction('announcements', false)).toContain('disabled=""');
  });
});
