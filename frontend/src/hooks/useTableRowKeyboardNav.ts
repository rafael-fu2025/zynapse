/**
 * useTableRowKeyboardNav — keyboard-driven row navigation for tables.
 *
 * Adds WAI-ARIA-compliant Up/Down/Home/End arrow navigation between
 * table rows. Works like a desktop file manager or music library:
 * focus a row, press ↑/↓ to walk the list, Home/End to jump to the
 * ends. Clicking a row also moves focus into the keyboard-managed
 * subset so subsequent arrows work from where the user clicked.
 *
 * Usage:
 *   const rowNav = useTableRowKeyboardNav(rows.length);
 *   rows.map((r, i) => (
 *     <tr key={r.id} {...rowNav.getRowProps(i)}>...</tr>
 *   ))
 *
 * The hook owns ONE active index at a time; per-row `tabIndex` flips
 * so only the active row sits in the document tab order (a common
 * roving-tabindex pattern for composite widgets).
 */
import { useCallback, useState } from 'react';
import type { KeyboardEvent } from 'react';

export interface RowProps<T extends HTMLElement> {
  /** Tab order: only the active row is in the tab sequence. */
  tabIndex: 0 | -1;
  /** Visual highlight when this row is the keyboard focus. */
  className: string;
  /** Arrow / Home / End handler — wired by the hook. */
  onKeyDown: (e: KeyboardEvent<T>) => void;
  /** Click moves the keyboard focus to this row. */
  onClick: () => void;
}

export interface UseTableRowKeyboardNav<T extends HTMLElement> {
  /** The currently focused row index, or `-1` when none. */
  activeIndex: number;
  /** Force a focus change (e.g. after a list reload). */
  setActiveIndex: (next: number) => void;
  /** Spread onto each `<tr>` (or `<li>` if you reuse for cards). */
  getRowProps: (index: number) => RowProps<T>;
}

export function useTableRowKeyboardNav<T extends HTMLElement = HTMLTableRowElement>(
  rowCount: number,
): UseTableRowKeyboardNav<T> {
  const [activeIndex, setActiveIndex] = useState<number>(-1);

  // Stable handler — the per-row callback closes over `rowCount`.
  const handleKey = useCallback(
    (e: KeyboardEvent<T>, currentIndex: number): void => {
      // Only intercept when the row itself has focus (not when an
      // interactive child like the dropdown trigger has focus).
      if (e.target !== e.currentTarget) return;
      switch (e.key) {
        case 'ArrowDown':
          e.preventDefault();
          setActiveIndex(Math.min(currentIndex + 1, Math.max(0, rowCount - 1)));
          return;
        case 'ArrowUp':
          e.preventDefault();
          setActiveIndex(Math.max(currentIndex - 1, 0));
          return;
        case 'Home':
          e.preventDefault();
          setActiveIndex(0);
          return;
        case 'End':
          e.preventDefault();
          setActiveIndex(rowCount - 1);
          return;
        default:
          return;
      }
    },
    [rowCount],
  );

  function getRowProps(index: number): RowProps<T> {
    const isActive = index === activeIndex;
    return {
      tabIndex: isActive ? 0 : -1,
      // `outline-1 outline -outline-offset-[-2px]` keeps the focus ring
      // visible without shifting the row layout (vs `ring` which adds
      // 2px and would re-flow adjacent rows on focus).
      className: isActive
        ? 'outline outline-2 outline-offset-[-2px] outline-ring'
        : '',
      onKeyDown: (e) => { handleKey(e, index); },
      onClick: () => { setActiveIndex(index); },
    };
  }

  return { activeIndex, setActiveIndex, getRowProps };
}