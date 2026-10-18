import { readdirSync, readFileSync } from 'node:fs';
import { join, relative } from 'node:path';
import { fileURLToPath } from 'node:url';
import ts from 'typescript';
import { describe, expect, it } from 'vitest';

const SRC_ROOT = fileURLToPath(new URL('..', import.meta.url));

function sources(dir: string): string[] {
  return readdirSync(dir, { withFileTypes: true }).flatMap((entry) => {
    const path = join(dir, entry.name);
    return entry.isDirectory() ? sources(path)
      : entry.name.endsWith('.tsx') && !entry.name.includes('.test.') ? [path] : [];
  });
}

/** PageHeader emits siblings, not a wrapper. Only their immediate DOM parent
 * can space them; an outer page's space-y utility cannot reach through a div.
 * Parse JSX so nested toolbar expressions and formatting cannot fool the scan.
 */
function unspacedHeaders(source: string, file: string) {
  const tree = ts.createSourceFile(file, source, ts.ScriptTarget.Latest, true, ts.ScriptKind.TSX);
  const offenders: string[] = [];
  let checked = 0;
  function visit(node: ts.Node) {
    if ((ts.isJsxSelfClosingElement(node) || ts.isJsxOpeningElement(node))
      && node.tagName.getText(tree) === 'PageHeader'
      && node.attributes.properties.some((attr) => ts.isJsxAttribute(attr)
        && ['toolbar', 'tabs', 'tabsActions'].includes(attr.name.getText(tree)))) {
      checked++;
      let parent: ts.Node | undefined = ts.isJsxOpeningElement(node) ? node.parent.parent : node.parent;
      // Fragments do not introduce a DOM boundary.
      while (parent && !ts.isJsxElement(parent)) parent = parent.parent;
      const attr = parent && ts.isJsxElement(parent)
        ? parent.openingElement.attributes.properties.find((a) => ts.isJsxAttribute(a)
          && a.name.getText(tree) === 'className') : undefined;
      const classes = attr && ts.isJsxAttribute(attr) && attr.initializer
        && ts.isStringLiteral(attr.initializer) ? attr.initializer.text.split(/\s+/) : [];
      const spaced = classes.some((c) => /^space-y-(?:[1-9]\d*(?:\.\d+)?|0\.[1-9]\d*)$/.test(c))
        || (classes.some((c) => c === 'flex' || c === 'grid')
          && classes.some((c) => /^gap(?:-y)?-(?:[1-9]\d*(?:\.\d+)?|0\.[1-9]\d*)$/.test(c)));
      if (!spaced) {
        const line = tree.getLineAndCharacterOfPosition(node.getStart(tree)).line + 1;
        offenders.push(`${file}:${line}: multi-row PageHeader needs spacing on its immediate parent`);
      }
    }
    ts.forEachChild(node, visit);
  }
  visit(tree);
  return { checked, offenders };
}

describe('PageHeader layout boundaries', () => {
  it('spaces every multi-row PageHeader across the frontend', () => {
    const results = sources(SRC_ROOT).map((path) => unspacedHeaders(
      readFileSync(path, 'utf8'), relative(SRC_ROOT, path),
    ));
    expect(results.reduce((count, result) => count + result.checked, 0)).toBeGreaterThanOrEqual(3);
    expect(results.flatMap((result) => result.offenders)).toEqual([]);
  });

  it('detects the nested shrink-only wrapper even when the page is spaced', () => {
    const result = unspacedHeaders('<main className="space-y-4"><div className="md:shrink-0"><PageHeader title="Test" toolbar={<div />} /></div></main>', 'fixture.tsx');
    expect(result.checked).toBe(1);
    expect(result.offenders).toHaveLength(1);
  });

  it.each(['space-y-4 md:shrink-0', 'flex flex-col gap-y-4', 'grid gap-3'])(
    'accepts sibling spacing via %s without requiring a specific class string', (classes) => {
      expect(unspacedHeaders(`<div className="${classes}"><><PageHeader title="Test" tabs={<div />} toolbar={<div />} /></></div>`, 'fixture.tsx').offenders).toEqual([]);
    },
  );

  it('does not mistake internal toolbar gap or responsive-only spacing for parent spacing', () => {
    expect(unspacedHeaders('<div className="md:space-y-4"><PageHeader title="Test" toolbar={<div className="flex gap-4" />} /></div>', 'fixture.tsx').offenders).toHaveLength(1);
  });
});
