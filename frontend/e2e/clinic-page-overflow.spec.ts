/**
 * Clinic page-level overflow regression.
 *
 * The document (html/body) is the app's only horizontal-scroll parent —
 * the shell establishes no intermediate scroll context — so any element
 * whose min-content width exceeds the viewport widens the document and
 * a horizontal scrollbar appears at the bottom of the window (seen on
 * Clinic → Encounters at ~1404px). This spec locks the page to
 * viewport-bound layout across the responsive spectrum and, on failure,
 * names the elements that poke past the right edge (with an ancestor
 * chain) plus a min-content probe down the layout chain so the culprit
 * is identifiable from the failure message alone.
 *
 * Mocked (CI-safe): no live backend needed — same envelope shapes as
 * clinic-session-workflow.spec.ts.
 */
import { expect, test, type Page } from '@playwright/test';
import { signInMocked } from './helpers/auth';

const WIDTHS = [320, 375, 768, 960, 1024, 1279, 1404] as const;

async function signIn(page: Page): Promise<void> {
  await signInMocked(page, {
    id: 4,
    email: 'clinic_admin@example.test',
    username: 'clinic-admin',
    is_active: true,
    force_reset: false,
    permissions: [
      'clinic.encounters.read',
      'clinic.encounters.write',
      'clinic.queue.read',
      'clinic.queue.manage',
      'referrals.create',
      'notifications.read',
    ],
  }, { mockRefresh: true });
}

async function mockClinicApis(page: Page): Promise<void> {
  const json = (data: unknown) => ({
    contentType: 'application/json',
    body: JSON.stringify({ success: true, data, errors: [], meta: null }),
  });
  await page.route('**/api/v1/clinic/queue', (route) => route.fulfill(json([])));
  await page.route('**/api/v1/clinic/encounters?**', (route) => route.fulfill({
    contentType: 'application/json',
    body: JSON.stringify({ success: true, data: [], errors: [], meta: { pagination: { limit: 25, next_cursor: null, prev_cursor: null } } }),
  }));
  await page.route('**/api/v1/dashboard/counters', (route) => route.fulfill(json({
    clinic: { open_encounters: 0, closed_encounters: 7 },
  })));
  await page.route('**/api/v1/notifications**', (route) => route.fulfill(json([])));
}

interface Offender {
  summary: string;
}

/** Elements extending past the right viewport edge (fixed/portal excluded). */
async function findOffenders(page: Page): Promise<Offender[]> {
  return page.evaluate(() => {
    const doc = document.documentElement;
    const vw = doc.clientWidth;
    const offenders: Offender[] = [];
    for (const el of Array.from(document.querySelectorAll('*'))) {
      const rect = el.getBoundingClientRect();
      if (rect.right <= vw + 1 || rect.width === 0) continue;
      const style = getComputedStyle(el);
      if (style.position === 'fixed') continue;
      const chain: string[] = [];
      let node: Element | null = el;
      while (node !== null && node !== document.body && chain.length < 6) {
        const c = typeof node.className === 'string' ? node.className : '';
        chain.push(`${node.tagName.toLowerCase()}.${c.split(/\s+/).filter(Boolean).slice(0, 5).join('.')}`);
        node = node.parentElement;
      }
      offenders.push({
        summary: `${el.tagName.toLowerCase()}[${Math.round(rect.left)}..${Math.round(Math.max(rect.right, 0))}] w=${Math.round(rect.width)} << ${chain.join(' << ')}`,
      });
      if (offenders.length >= 12) break;
    }
    return offenders;
  });
}

/**
 * Measure each element's intrinsic min-content width (cloned off-DOM with
 * width: min-content) so the failure message names the subtree that
 * forces the stretch, not just the stretched containers.
 */
async function probeMinContent(page: Page): Promise<string[]> {
  return page.evaluate(() => {
    const probe = (el: Element): number => {
      const clone = el.cloneNode(true) as HTMLElement;
      clone.style.cssText += ';position:absolute;width:min-content;visibility:hidden;left:-99999px;';
      document.body.appendChild(clone);
      const w = clone.getBoundingClientRect().width;
      clone.remove();
      return Math.round(w);
    };
    const describe = (el: Element): string => {
      const c = typeof el.className === 'string' ? el.className : '';
      return `${el.tagName.toLowerCase()}.${c.split(/\s+/).filter(Boolean).slice(0, 6).join('.')}`;
    };
    const lines: string[] = [];
    const pageMain = document.querySelector('main.space-y-4.p-6');
    if (pageMain === null) return ['<main.space-y-4.p-6 not found>'];
    let node: Element | null = pageMain;
    const chain: Element[] = [];
    while (node !== null && node !== document.body) {
      chain.unshift(node);
      node = node.parentElement;
    }
    for (const el of chain) {
      lines.push(`min-content=${probe(el)}  ${describe(el)}`);
    }
    lines.push('--- page main children ---');
    for (const child of Array.from(pageMain.children)) {
      lines.push(`min-content=${probe(child)}  ${describe(child)}`);
      for (const grand of Array.from(child.children)) {
        lines.push(`  min-content=${probe(grand)}  ${describe(grand)}`);
      }
    }
    return lines;
  });
}

test('Clinic page never overflows the viewport horizontally', async ({ page }) => {
  await signIn(page);
  await mockClinicApis(page);

  const failures: string[] = [];
  for (const width of WIDTHS) {
    await page.setViewportSize({ width, height: 948 });
    await page.goto('/clinic', { waitUntil: 'networkidle' });
    // Exactly one section nav is in the accessibility tree at any width
    // (the horizontal strip below lg, the vertical sidebar at lg+).
    await expect(page.getByRole('tablist')).toBeVisible({ timeout: 15_000 });
    // The page action renders once the queue tab is actually mounted
    // (data-independent), so measurements never race the lazy route or
    // React Query's post-fetch renders.
    await expect(page.getByRole('button', { name: 'Call next' })).toBeVisible({ timeout: 15_000 });

    const metrics = await page.evaluate(() => ({
      scrollWidth: document.documentElement.scrollWidth,
      clientWidth: document.documentElement.clientWidth,
    }));
    if (metrics.scrollWidth > metrics.clientWidth) {
      const offenders = await findOffenders(page);
      const probe = await probeMinContent(page);
      failures.push(
        `viewport ${width}px: scrollWidth=${metrics.scrollWidth} > clientWidth=${metrics.clientWidth}\n` +
        offenders.map((o) => `  ${o.summary}`).join('\n') +
        `\n  --- min-content probe ---\n` + probe.map((p) => `  ${p}`).join('\n'),
      );
    }
  }

  expect(failures, `document-level horizontal overflow:\n${failures.join('\n\n')}`).toHaveLength(0);
});
