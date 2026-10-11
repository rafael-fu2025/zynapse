import { renderToStaticMarkup } from 'react-dom/server';
import { describe, expect, it, vi } from 'vitest';
import { HeaderBreadcrumbs } from './HeaderBreadcrumbs';

const location = vi.hoisted(() => ({ pathname: '/patients', search: '' }));

vi.mock('react-router-dom', () => ({
  useLocation: () => location,
  Link: ({ to, children, ...props }: {
    to: string;
    children: React.ReactNode;
    className?: string;
    title?: string;
  }) => <a href={to} {...props}>{children}</a>,
}));

function renderBreadcrumbs(url: string): string {
  const parsed = new URL(url, 'https://synapse.test');
  location.pathname = parsed.pathname;
  location.search = parsed.search;
  return renderToStaticMarkup(<HeaderBreadcrumbs />);
}

function expectRecordsBreadcrumb(url: string, label: 'Students' | 'Employees'): void {
  const html = renderBreadcrumbs(url);
  expect(html).toContain('aria-label="Breadcrumbs"');
  expect(html).toContain('href="/"');
  expect(html).toContain('>Dashboard</span>');
  expect(html).toContain(`>${label}</span>`);
  expect(html).not.toContain('>Records<');
  expect(html).not.toContain('>Clinic<');
  expect(html).not.toContain('>Guidance<');
  // The home icon is the only link; the destination stays plain text.
  expect(html.match(/<a\b/g)).toHaveLength(1);
  expect(html.match(/lucide-chevron-right/g)).toHaveLength(1);
}

describe('HeaderBreadcrumbs', () => {
  it.each([
    '/patients',
    '/patients?tab=students',
    '/patients?side=clinic&tab=students',
    '/patients?side=guidance',
    '/patients?side=guidance&tab=students',
    '/patients?side=guidance&q=Dino&archive=archived',
    '/patients/',
  ])('shows Home / Students without an intermediate Records link for %s', (url) => {
    expectRecordsBreadcrumb(url, 'Students');
  });

  it.each([
    '/patients?tab=employees',
    '/patients?side=clinic&tab=employees',
    '/patients?side=guidance&tab=employees',
  ])('shows Home / Employees without an intermediate Records link for %s', (url) => {
    expectRecordsBreadcrumb(url, 'Employees');
  });

  it('does not change another module with tab breadcrumbs', () => {
    const html = renderBreadcrumbs('/inventory?tab=supplies');
    expect(html).toContain('href="/inventory"');
    expect(html).toContain('>Inventory</a>');
    expect(html).toContain('>Supplies</span>');
  });

  it('does not change nested path breadcrumbs', () => {
    const html = renderBreadcrumbs('/facilities/devices');
    expect(html).toContain('href="/facilities"');
    expect(html).toContain('>Facilities</a>');
    expect(html).toContain('>Devices</span>');
  });

  it('keeps the drum detail trail Facilities / Drums / #id', () => {
    const html = renderBreadcrumbs('/facilities/drums/4');
    expect(html).toContain('href="/facilities"');
    // `/facilities/drums` is redirected to Facilities by the router.
    expect(html).toContain('href="/facilities/drums"');
    expect(html).toContain('>#4</span>');
  });

  it('renders a grouping label with no page of its own as plain text', () => {
    const html = renderBreadcrumbs('/admin/users');
    expect(html).toContain('>Administration</span>');
    expect(html).not.toContain('href="/admin"');
    expect(html).toContain('>Users</span>');
  });

  it('does not change session breadcrumbs', () => {
    const html = renderBreadcrumbs('/counselling?tab=closed&session=42');
    expect(html).toContain('>Counselling</a>');
    expect(html).toContain('>Closed</a>');
    expect(html).toContain('>Session #42</span>');
  });

  it('does not render breadcrumbs on the dashboard', () => {
    expect(renderBreadcrumbs('/')).toBe('');
  });
});
