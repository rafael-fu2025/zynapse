/**
 * Capture recorder â€” writes an evidence folder for one captured session.
 *
 * Design notes
 * ------------
 * *Why a recorder and not bare `page.screenshot()` calls:* a screen capture is
 * only useful as evidence if you can see what broke. So every step also records
 * the console stream and the network stream, and the two are cross-referenced
 * by step index in `manifest.json`.
 *
 * *Zoom without resize.* Close-ups are captured at 3x device pixels while the
 * layout viewport stays at 1440x900. There are two ways to do that and only one
 * of them is honest about what it did:
 *
 *   1. CDP `Emulation.setDeviceMetricsOverride` with an unchanged width/height
 *      and a raised `deviceScaleFactor`. Layout is byte-identical â€” same media
 *      queries, same reflow-or-not decision as the 1x pass â€” and the PNG simply
 *      carries 3x the pixels. No `overflow: hidden` ancestor can clip it and
 *      nothing overlaps, because nothing moved.
 *   2. `transform: scale(3)` on the element. Cheap, but it paints over
 *      siblings and gets cropped by any scrolling/clipping ancestor, so the
 *      image can silently lose the very edges you wanted to inspect.
 *
 * We try (1) and fall back to (2), recording which one produced each file.
 * A screenshot that lies about its own geometry is worse than no screenshot.
 *
 * *Targeting a component.* Structural selectors are unreliable here. The
 * reports charts are hand-rolled SVG â€” dozens of small `<line>`/`<rect>` nodes
 * with no single large element â€” so `svg` finds the sidebar's branding mark and
 * an area comparison cannot help, because the logo really is bigger than any
 * one chart primitive. `ZoomTarget.closest` matches the visible card title and
 * climbs to the enclosing card, which survives a re-layout.
 *
 * Authored for Playwright 1.62 + Chromium.
 */
import type {
  CDPSession,
  ConsoleMessage,
  ElementHandle,
  Locator,
  Page,
  Request,
  Response,
} from '@playwright/test';
import { mkdir, readdir, rm, writeFile } from 'node:fs/promises';
import { join } from 'node:path';

export type ZoomMethod = 'cdp-3x' | 'transform-3x' | 'clip-1x' | 'skipped';

export interface ZoomTarget {
  /** Human label used in the log and the manifest. */
  label: string;
  /**
   * Selectors tried in order until one resolves to at least one visible
   * element. First hit wins; a miss is recorded, not fatal â€” a capture run
   * must not die because one component moved.
   */
  selectors: string[];
  /**
   * Pick the biggest match by area instead of the first in DOM order.
   * Useful for `nav`/`section` where several nested boxes all match.
   */
  largest?: boolean;
  /**
   * Locate a component by a heading's visible text, then climb to the nearest
   * ancestor matching `selector`. The reliable way to grab a card whose
   * contents are many small nodes rather than one identifiable element.
   */
  closest?: { text: string; selector?: string; levels?: number };
}

export interface StepResult {
  index: number;
  id: string;
  route: string;
  label: string;
  screenshot: string;
  ok: boolean;
  error?: string;
  consoleErrors: number;
  failedRequests: number;
  zooms: Array<{ label: string; file: string; method: ZoomMethod; note?: string }>;
}

interface ConsoleEntry {
  step: number;
  type: string;
  text: string;
  location?: string;
}

interface NetworkEntry {
  step: number;
  method: string;
  url: string;
  status?: number;
  failure?: string;
  apiErrorCodes?: string[];
  durationMs?: number;
}

const ZOOM_SCALE = 3;

/** Marker attribute so a restored transform is provably back to its origin. */
const MARK = 'data-capture-zoom';

export class Recorder {
  private readonly stepNo = { value: 0 };
  private readonly consoleEntries: ConsoleEntry[] = [];
  private readonly networkEntries: NetworkEntry[] = [];
  private readonly steps: StepResult[] = [];
  private readonly logLines: string[] = [];
  private cdp: CDPSession | null = null;
  private metricsOverridden = false;
  private readonly requestStarted = new WeakMap<Request, number>();

  private constructor(
    private readonly page: Page,
    private readonly outDir: string,
    private readonly shotsDir: string,
  ) {}

  static async create(page: Page, outDir: string): Promise<Recorder> {
    const shotsDir = join(outDir, 'shots');
    await mkdir(shotsDir, { recursive: true });
    // Clear our own output so a re-run cannot leave a stale PNG behind from a
    // previous scene list — an orphaned close-up from a renamed target is
    // indistinguishable from a current one once it is on disk.
    for (const entry of await readdir(shotsDir)) {
      await rm(join(shotsDir, entry), { force: true, recursive: true });
    }
    const rec = new Recorder(page, outDir, shotsDir);

    page.on('console', (msg: ConsoleMessage) => rec.onConsole(msg));
    page.on('request', (req: Request) => {
      rec.requestStarted.set(req, Date.now());
    });
    page.on('response', (res: Response) => void rec.onResponse(res));
    page.on('requestfailed', (req: Request) => {
      rec.networkEntries.push({
        step: rec.stepNo.value,
        method: req.method(),
        url: req.url(),
        failure: req.failure()?.errorText ?? 'failed',
      });
    });

    return rec;
  }

  private onConsole(msg: ConsoleMessage): void {
    const loc = msg.location();
    this.consoleEntries.push({
      step: this.stepNo.value,
      type: msg.type(),
      text: msg.text(),
      location: loc?.url ? `${loc.url}:${loc.lineNumber}` : undefined,
    });
  }

  private async onResponse(res: Response): Promise<void> {
    const req = res.request();
    const started = this.requestStarted.get(req);
    // Only API traffic is interesting for this app; the Vite dev server and
    // the font CDN generate hundreds of asset hits per navigation.
    if (!res.url().includes('/api/')) return;

    const entry: NetworkEntry = {
      step: this.stepNo.value,
      method: req.method(),
      url: res.url(),
      status: res.status(),
      durationMs: started ? Date.now() - started : undefined,
    };

    // The app's error envelope is `{ success, data, errors: [{ code, ... }] }`.
    // Pull the codes out so a 4xx/5xx in the log explains itself.
    if (res.status() >= 400) {
      try {
        const body = (await res.json()) as { errors?: Array<{ code?: string }> };
        if (Array.isArray(body.errors) && body.errors.length) {
          entry.apiErrorCodes = body.errors
            .map((e) => e?.code)
            .filter((c): c is string => typeof c === 'string');
        }
      } catch {
        // Non-JSON error body â€” the status is the whole story.
      }
    }
    this.networkEntries.push(entry);
  }

  private log(line: string): void {
    const stamped = `[${new Date().toISOString()}] ${line}`;
    this.logLines.push(stamped);
    console.log(stamped);
  }

  /** Attach the CDP session used for 3x close-ups. Chromium only. */
  async initCdp(): Promise<void> {
    try {
      this.cdp = await this.page.context().newCDPSession(this.page);
      this.log('CDP session attached (3x close-ups available)');
    } catch (err) {
      this.log(`CDP session unavailable, close-ups fall back to 1x clip: ${String(err)}`);
    }
  }

  /**
   * Capture one screen: navigate, settle, optionally interact, screenshot,
   * then every requested close-up. Never throws for a missing component â€” a
   * partial capture of a real screen beats a failed run.
   */
  async capture(step: {
    id: string;
    route: string;
    label: string;
    zooms?: ZoomTarget[];
    settleMs?: number;
    /**
     * Interact with the page after it settles but before anything is
     * captured â€” open a dialog, switch a tab, expand a row. Some changed
     * behaviour (the survey builder's year-level control) is only reachable
     * behind a click, and a capture that cannot click cannot show it.
     */
    before?: (page: Page) => Promise<void>;
  }): Promise<StepResult> {
    const index = (this.stepNo.value += 1);
    const before = { console: this.consoleEntries.length, net: this.networkEntries.length };
    this.log(`step ${index} â€” ${step.id}: ${step.label} (${step.route})`);

    const result: StepResult = {
      index,
      id: step.id,
      route: step.route,
      label: step.label,
      screenshot: '',
      ok: false,
      consoleErrors: 0,
      failedRequests: 0,
      zooms: [],
    };

    try {
      await this.page.goto(step.route, { waitUntil: 'domcontentloaded', timeout: 30_000 });
      // A fixed settle beat networkidle: the app polls dashboard counters and
      // keeps a refresh-token heartbeat, so the network is rarely ever truly
      // idle and waiting for it would hang until the step timeout.
      await this.page.waitForTimeout(step.settleMs ?? 1_500);
      await this.page.waitForLoadState('networkidle', { timeout: 8_000 }).catch(() => undefined);

      if (step.before) {
        await step.before(this.page);
        await this.page.waitForTimeout(600);
      }

      const shotName = `step-${String(index).padStart(2, '0')}-${step.id}.png`;
      await this.page.screenshot({ path: join(this.shotsDir, shotName), fullPage: true });
      result.screenshot = join('shots', shotName);
      result.ok = true;
      this.log(`  captured ${result.screenshot}`);

      for (const z of step.zooms ?? []) {
        result.zooms.push(await this.captureZoom(index, step.id, z));
      }
    } catch (err) {
      result.error = err instanceof Error ? err.message : String(err);
      this.log(`  FAILED: ${result.error}`);
    }

    result.consoleErrors = this.consoleEntries
      .slice(before.console)
      .filter((c) => c.type === 'error').length;
    result.failedRequests = this.networkEntries
      .slice(before.net)
      .filter((n) => (n.status !== undefined && n.status >= 400) || n.failure !== undefined)
      .length;
    if (result.consoleErrors > 0) this.log(`  console errors: ${result.consoleErrors}`);
    if (result.failedRequests > 0) this.log(`  failed requests: ${result.failedRequests}`);

    this.steps.push(result);
    return result;
  }

  /** Resolve a ZoomTarget to a concrete element, or null with the reason logged. */
  private async resolve(target: ZoomTarget): Promise<{ el: ElementHandle<Element>; how: string } | null> {
    if (target.closest) {
      const { text, selector, levels } = target.closest;
      const handle = await this.page.evaluateHandle(
        ({ needle, climb, climbLevels }) => {
          const nodes = Array.from(
            document.querySelectorAll('h1,h2,h3,h4,h5,h6,legend,caption,button,label,p,span,div'),
          );
          // Locate the card title, then climb to the enclosing card.
          // Smallest containing element wins -- see the note below.
          // for "this node is the title, not a wrapper".
          const wanted = needle.toLowerCase();
          // Collect every node whose text contains the needle, then keep the
          // SMALLEST. Document order is useless here: `#root` holds the whole
          // page and has exactly one child, so a `children.length <= 2`
          // "is this the title?" test waves it through and every title on the
          // page resolves to the same box. Smallest wins, because a title is
          // by definition inside its card, not wrapped around it.
          const hits = nodes.filter((el) =>
            (el.textContent ?? '').trim().toLowerCase().includes(wanted),
          );
          let hit: Element | null = null;
          let bestArea = Infinity;
          for (const el of hits) {
            const r = el.getBoundingClientRect();
            const area = r.width * r.height;
            if (area < bestArea) {
              bestArea = area;
              hit = el;
            }
          }
          if (!hit) return null;
          if (climb) return hit.closest(climb) ?? hit;
          // Fixed-depth climb, for cards whose class names are an implementation
          // detail we should not be guessing at.
          let node: Element = hit;
          for (let i = 0; i < (climbLevels ?? 0); i += 1) {
            const parent: Element | null = node.parentElement;
            if (!parent) break;
            node = parent;
          }
          return node;
        },
        { needle: text, climb: selector, climbLevels: levels },
      );
      const el = handle.asElement();
      if (!el) {
        this.log(`  zoom skip "${target.label}" â€” no element with text "${text}"`);
        return null;
      }
      return { el, how: `closest("${text}" -> ${selector ?? `+${levels ?? 0}`})` };
    }

    for (const sel of target.selectors) {
      if (target.largest === true) {
        const all: Locator = this.page.locator(sel);
        const n = await all.count();
        let best: { el: ElementHandle<Element>; area: number } | null = null;
        for (let i = 0; i < n; i += 1) {
          const cand = all.nth(i);
          if (!(await cand.isVisible().catch(() => false))) continue;
          const box = await cand.boundingBox();
          if (!box) continue;
          const area = box.width * box.height;
          if (!best || area > best.area) {
            const handle = await cand.elementHandle();
            if (handle) best = { el: handle, area };
          }
        }
        if (best && best.area > 0) return { el: best.el, how: `largest ${sel}` };
        continue;
      }
      const loc = this.page.locator(sel).first();
      if ((await loc.count()) === 0) continue;
      if (!(await loc.isVisible().catch(() => false))) continue;
      const el = await loc.elementHandle();
      if (el) return { el, how: sel };
    }

    this.log(`  zoom skip "${target.label}" â€” no selector matched ${target.selectors.join(' | ')}`);
    return null;
  }

  /** One close-up: resolve, then try 3x-CDP, 3x-transform, 1x-clip in turn. */
  private async captureZoom(
    stepIndex: number,
    stepId: string,
    target: ZoomTarget,
  ): Promise<StepResult['zooms'][number]> {
    const fileName = `zoom-${String(stepIndex).padStart(2, '0')}-${stepId}-${slug(target.label)}.png`;
    const outPath = join(this.shotsDir, fileName);
    const relative = join('shots', fileName);

    const resolved = await this.resolve(target);
    if (!resolved) {
      return { label: target.label, file: '', method: 'skipped', note: 'target not found' };
    }
    const { el, how } = resolved;

    await el.scrollIntoViewIfNeeded().catch(() => undefined);
    await this.page.waitForTimeout(200);

    // --- attempt 1: CDP device-pixel-ratio raise, layout untouched ---
    if (this.cdp) {
      try {
        const size = this.page.viewportSize() ?? { width: 1440, height: 900 };
        await this.cdp.send('Emulation.setDeviceMetricsOverride', {
          width: size.width,
          height: size.height,
          deviceScaleFactor: ZOOM_SCALE,
          mobile: false,
        });
        this.metricsOverridden = true;
        await this.page.waitForTimeout(250);
        await el.screenshot({ path: outPath, timeout: 15_000 });
        await this.cdp.send('Emulation.clearDeviceMetricsOverride');
        this.metricsOverridden = false;
        this.log(`  zoom ${target.label} -> ${relative} (cdp-3x, ${how})`);
        return { label: target.label, file: relative, method: 'cdp-3x' };
      } catch (err) {
        await this.cdp.send('Emulation.clearDeviceMetricsOverride').catch(() => undefined);
        this.metricsOverridden = false;
        this.log(`  cdp-3x failed for "${target.label}": ${String(err)}`);
      }
    }

    // --- attempt 2: 3x transform, then restore exactly ---
    try {
      const box = await el.boundingBox();
      if (box) {
        await el.evaluate((node) => {
          const e = node as HTMLElement;
          e.setAttribute(MARK, e.style.transform);
          e.style.transformOrigin = 'top left';
          e.style.transform = `scale(${ZOOM_SCALE})`;
          e.style.zIndex = '2147483647';
        });
        await this.page.waitForTimeout(200);
        await el.screenshot({ path: outPath, timeout: 15_000 });
        await el.evaluate((node) => {
          const e = node as HTMLElement;
          e.style.transform = e.getAttribute(MARK) ?? '';
          e.style.transformOrigin = '';
          e.style.zIndex = '';
          e.removeAttribute(MARK);
        });
        this.log(`  zoom ${target.label} -> ${relative} (transform-3x, ${how})`);
        return { label: target.label, file: relative, method: 'transform-3x' };
      }
    } catch (err) {
      this.log(`  transform-3x failed for "${target.label}": ${String(err)}`);
    }

    // --- attempt 3: honest 1x crop ---
    try {
      await el.screenshot({ path: outPath, timeout: 15_000 });
      this.log(`  zoom ${target.label} -> ${relative} (clip-1x, ${how})`);
      return { label: target.label, file: relative, method: 'clip-1x' };
    } catch (err) {
      this.log(`  clip-1x failed for "${target.label}": ${String(err)}`);
      return { label: target.label, file: '', method: 'skipped', note: String(err) };
    }
  }

  /** Restore any global override still in place, then write the evidence set. */
  async finish(meta: Record<string, unknown>): Promise<{ outDir: string; steps: StepResult[] }> {
    if (this.cdp && this.metricsOverridden) {
      await this.cdp.send('Emulation.clearDeviceMetricsOverride').catch(() => undefined);
      this.metricsOverridden = false;
    }

    await writeFile(join(this.outDir, 'run.log'), this.logLines.join('\n'), 'utf8');
    await writeFile(join(this.outDir, 'console.jsonl'), toJsonl(this.consoleEntries), 'utf8');
    await writeFile(join(this.outDir, 'network.jsonl'), toJsonl(this.networkEntries), 'utf8');
    await this.page
      .content()
      .then((html) => writeFile(join(this.outDir, 'final.html'), html, 'utf8'))
      .catch(() => undefined);

    const captured = this.steps.filter((s) => s.ok).length;
    const manifest = {
      generatedAt: new Date().toISOString(),
      ...meta,
      totals: {
        steps: this.steps.length,
        captured,
        failed: this.steps.length - captured,
        consoleErrors: this.consoleEntries.filter((c) => c.type === 'error').length,
        failedRequests: this.networkEntries.filter(
          (n) => (n.status !== undefined && n.status >= 400) || n.failure !== undefined,
        ).length,
        zoomsCaptured: this.steps.reduce(
          (n, s) => n + s.zooms.filter((z) => z.method !== 'skipped').length,
          0,
        ),
        zoomsSkipped: this.steps.reduce(
          (n, s) => n + s.zooms.filter((z) => z.method === 'skipped').length,
          0,
        ),
      },
      steps: this.steps,
    };
    await writeFile(join(this.outDir, 'manifest.json'), JSON.stringify(manifest, null, 2), 'utf8');

    this.log(`done â€” ${captured}/${this.steps.length} screens captured -> ${this.outDir}`);
    return { outDir: this.outDir, steps: this.steps };
  }
}

function toJsonl(rows: unknown[]): string {
  return rows.map((r) => JSON.stringify(r)).join('\n');
}

function slug(s: string): string {
  return s
    .toLowerCase()
    .replace(/[^a-z0-9]+/g, '-')
    .replace(/^-|-$/g, '')
    .slice(0, 48);
}

