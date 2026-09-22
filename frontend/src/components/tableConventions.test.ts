/**
 * Table-conventions fitness test — the structural guardrail for the table
 * work in TABLE_AUDIT.md §4 steps 1–5.
 *
 * Context: the audit found 174 column headers with no `scope`, 32 tables
 * with no accessible name, ~14 desktop empty-state guards that forgot
 * `isError` (so a failed load rendered "No medicines in the catalog."
 * directly above "Failed to load medicines."), and two tables with no
 * error state at all. Those were fixed across 32 files. This test cannot
 * make a table correct — but it makes REGRESSION VISIBLE, the same job
 * `backend/tests/unit/TenantScopeFitnessTest.php` does for tenancy.
 *
 * Scan methodology (deliberately heuristic, in the same spirit as
 * TenantScopeFitnessTest and KioskMediaSecurityTest's source assertions):
 *   - a "table block" runs from `<Table` (followed by whitespace or `>`)
 *     to the next `</Table>`; the character class is what keeps
 *     `<TableHeader>`/`<TableBody>`/`<TableRow>`/`<TableCell>` from being
 *     mistaken for the table element itself;
 *   - "header count" is the number of `<TableHead` cells in a block
 *     (excluding `<TableHeader`) PLUS any local wrapper named `<*Header>`,
 *     since a table whose headers go through an indirection — AnalyticsTab's
 *     `<SortHeader>` — would otherwise look narrower than it is;
 *   - a raw `<th` is considered scoped if `scope=` appears in its opening
 *     tag (checked over a window, since these tags are wrapped across
 *     lines);
 *   - `colSpan` is only checked when it is a numeric literal — a dynamic
 *     `colSpan={columns.length}` (AuditPage) cannot be resolved here.
 *
 * These are conventions, not proofs. A pass means "no known regression",
 * not "the tables are right".
 */
import { readdirSync, readFileSync, statSync } from 'node:fs';
import { join, relative } from 'node:path';
import { fileURLToPath } from 'node:url';

import { describe, expect, it } from 'vitest';

const SRC_ROOT = fileURLToPath(new URL('..', import.meta.url));

/** The table primitives themselves are the source of the conventions. */
const PRIMITIVE_FILE = join('components', 'ui', 'table.tsx');

/**
 * Tables whose `colSpan` is computed rather than a literal cannot be checked
 * statically. Listed explicitly with a reason so that skipping one is a
 * conscious decision — the same idea as TenantScopeFitnessTest's EXEMPT_PATHS.
 * A stale entry (one whose file no longer has a dynamic colSpan) also fails.
 */
const DYNAMIC_COLSPAN_EXEMPT = new Map<string, string>([
  ['pages/AuditPage.tsx', 'colSpan={columns.length} is derived from the TanStack column defs'],
]);

function walk(dir: string, out: string[] = []): string[] {
  for (const entry of readdirSync(dir)) {
    if (entry === 'node_modules') continue;
    const full = join(dir, entry);
    if (statSync(full).isDirectory()) {
      walk(full, out);
    } else if (entry.endsWith('.tsx') && !entry.includes('.test.')) {
      out.push(full);
    }
  }
  return out;
}

interface SourceFile {
  /** Path relative to src, with forward slashes, for stable messages. */
  path: string;
  source: string;
}

const FILES: SourceFile[] = walk(SRC_ROOT).map((full) => ({
  path: relative(SRC_ROOT, full).split('\\').join('/'),
  source: readFileSync(full, 'utf8'),
}));

/** Files that render a table (shadcn primitive or raw HTML). */
const TABLE_FILES = FILES.filter((f) => /<TableHead[\s>]/.test(f.source) || /<table[\s>]/.test(f.source));

interface TableBlock {
  file: string;
  /** Index of the block within its file, for readable messages. */
  index: number;
  text: string;
  headerCount: number;
}

/**
 * Header cells in a block. Counts `<TableHead>` plus any local wrapper
 * component named `<*Header>` (e.g. AnalyticsTab's `<SortHeader>`, which
 * renders a `<TableHead>` internally) — without this, a table whose headers
 * go through an indirection looks narrower than it is.
 */
function countHeaders(blockText: string): number {
  const direct = (blockText.match(/<TableHead[\s>]/g) ?? []).length;
  const indirect = (blockText.match(/<(\w+?)Header[\s/>]/g) ?? []).filter((tag) => !/^<TableHeader/.test(tag)).length;
  return direct + indirect;
}

function tableBlocks(file: SourceFile): TableBlock[] {
  const blocks: TableBlock[] = [];
  const opener = /<Table[\s>]/g;
  const matches = [...file.source.matchAll(opener)];
  for (const match of matches) {
    const end = file.source.indexOf('</Table>', match.index);
    if (end === -1) continue;
    const text = file.source.slice(match.index, end + '</Table>'.length);
    blocks.push({
      file: file.path,
      index: blocks.length,
      text,
      headerCount: countHeaders(text),
    });
  }
  return blocks;
}

const ALL_BLOCKS = FILES.flatMap(tableBlocks);

describe('table conventions', () => {
  it('finds tables to check (guards against a silently broken scan)', () => {
    // If the walk or the regexes break, every other assertion below would
    // vacuously pass. Fail loudly instead.
    expect(TABLE_FILES.length).toBeGreaterThan(20);
    expect(ALL_BLOCKS.length).toBeGreaterThan(25);
  });

  it('gives every table an accessible name', () => {
    const offenders = ALL_BLOCKS.filter((block) => {
      const openingTag = block.text.slice(0, 300);
      const hasAriaLabel = openingTag.includes('ariaLabel=');
      const hasCaption = block.text.includes('<TableCaption') || block.text.includes('<caption');
      return !hasAriaLabel && !hasCaption;
    }).map((block) => `${block.file} (table #${block.index + 1}, ${block.headerCount} columns)`);

    expect(offenders).toEqual([]);
  });

  it('gives every raw <table> an accessible name', () => {
    // Raw HTML tables — the off-screen PDF renderer and the waste-category
    // deviation report — bypass the `Table` primitive entirely, so they need
    // their own check. They are still in the DOM and reachable by AT.
    const offenders: string[] = [];
    for (const file of FILES) {
      const opener = /<table[\s>]/g;
      const matches = [...file.source.matchAll(opener)];
      for (const match of matches) {
        const end = file.source.indexOf('</table>', match.index);
        if (end === -1) continue;
        const block = file.source.slice(match.index, end);
        if (!/aria-label=/.test(block.slice(0, 200)) && !block.includes('<caption')) {
          const line = file.source.slice(0, match.index).split('\n').length;
          offenders.push(`${file.path}:${line}`);
        }
      }
    }
    expect(offenders).toEqual([]);
  });

  it('scopes every raw <th> element', () => {
    const offenders: string[] = [];
    for (const file of FILES) {
      const th = /<th[\s>]/g;
      const matches = [...file.source.matchAll(th)];
      for (const match of matches) {
        // These tags wrap across lines, so search a window rather than one line.
        const tag = file.source.slice(match.index, match.index + 300);
        if (!/scope=/.test(tag)) {
          const line = file.source.slice(0, match.index).split('\n').length;
          offenders.push(`${file.path}:${line}`);
        }
      }
    }
    expect(offenders).toEqual([]);
  });

  it('matches every literal TableStateRows colSpan to its table column count', () => {
    const offenders: string[] = [];
    for (const block of ALL_BLOCKS) {
      const at = block.text.indexOf('<TableStateRows');
      if (at === -1) continue;
      const colSpanPattern = /colSpan=\{(\d+)\}/;
      const colSpanMatch = block.text.slice(at).match(colSpanPattern);
      if (colSpanMatch === null) {
        if (!DYNAMIC_COLSPAN_EXEMPT.has(block.file)) {
          offenders.push(
            `${block.file} (table #${block.index + 1}): non-literal colSpan — verify by hand, or add the file to DYNAMIC_COLSPAN_EXEMPT with a reason`,
          );
        }
        continue;
      }
      const colSpan = Number(colSpanMatch[1]);
      if (colSpan !== block.headerCount) {
        offenders.push(
          `${block.file} (table #${block.index + 1}): colSpan={${colSpan}} but ${block.headerCount} column headers`,
        );
      }
    }
    expect(offenders).toEqual([]);
  });

  it('has no stale dynamic-colSpan exemptions', () => {
    // Mirrors TenantScopeFitnessTest's "the baseline only ever shrinks": an
    // exemption that is no longer needed must be deleted, not left to rot.
    const stale = [...DYNAMIC_COLSPAN_EXEMPT.keys()].filter((file) => {
      const blocks = ALL_BLOCKS.filter((block) => block.file === file);
      return !blocks.some((block) => {
        const at = block.text.indexOf('<TableStateRows');
        return at !== -1 && !/colSpan=\{\d+\}/.test(block.text.slice(at));
      });
    });
    expect(stale).toEqual([]);
  });

  it('never guards an empty table on isLoading alone', () => {
    // The defect this whole sweep existed to remove: an empty-state guard
    // that forgets `isError`, so the empty message and the error row render
    // together. TableStateRows owns the branch now; this catches anyone
    // hand-rolling it again.
    const offenders: string[] = [];
    for (const file of TABLE_FILES) {
      file.source.split('\n').forEach((line, index) => {
        if (/![\w.]*isLoading &&/.test(line) && /\.length === 0/.test(line) && !/isError/.test(line)) {
          offenders.push(`${file.path}:${index + 1}: ${line.trim()}`);
        }
      });
    }
    expect(offenders).toEqual([]);
  });

  it('keeps TableHead emitting scope="col"', () => {
    const primitive = FILES.find((f) => f.path === PRIMITIVE_FILE.split('\\').join('/'));
    expect(primitive, `${PRIMITIVE_FILE} should exist`).toBeDefined();
    expect(primitive?.source).toContain('scope="col"');
  });
});
