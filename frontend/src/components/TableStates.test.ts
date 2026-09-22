import { describe, expect, it } from 'vitest';

import { resolveEmpty, resolveTableState, type TableEmptyCopy } from './TableStates';

const NO_DATA: TableEmptyCopy = { title: 'No medicines in the catalog.' };
const NO_RESULTS: TableEmptyCopy = { title: 'No medicines match "x".' };

describe('resolveTableState', () => {
  it('reports loading first, whatever else is true', () => {
    expect(resolveTableState({ isLoading: true, isError: false, isEmpty: false })).toBe('loading');
    expect(resolveTableState({ isLoading: true, isError: true, isEmpty: true })).toBe('loading');
    expect(resolveTableState({ isLoading: true, isError: true, isEmpty: false })).toBe('loading');
  });

  it('prefers the error state over the empty state when a failed load has no rows', () => {
    // The regression this component exists to prevent: both used to render.
    expect(resolveTableState({ isLoading: false, isError: true, isEmpty: true })).toBe('error');
  });

  it('keeps showing rows when a refetch fails but data is still held', () => {
    expect(resolveTableState({ isLoading: false, isError: true, isEmpty: false })).toBe('rows');
  });

  it('reports empty only when the read succeeded', () => {
    expect(resolveTableState({ isLoading: false, isError: false, isEmpty: true })).toBe('empty');
  });

  it('reports rows when there is nothing to say', () => {
    expect(resolveTableState({ isLoading: false, isError: false, isEmpty: false })).toBe('rows');
  });
});

describe('resolveEmpty', () => {
  it('uses the no-results copy when a filter is active and copy was supplied', () => {
    expect(resolveEmpty({ empty: NO_DATA, noResults: NO_RESULTS, hasFilters: true })).toBe(NO_RESULTS);
  });

  it('uses the no-data copy when no filter is active', () => {
    expect(resolveEmpty({ empty: NO_DATA, noResults: NO_RESULTS, hasFilters: false })).toBe(NO_DATA);
  });

  it('falls back to the no-data copy when the caller supplied no no-results copy', () => {
    // Tables with no filters still pass hasFilters=false; ones that forget to
    // supply noResults must not render an undefined empty state.
    expect(resolveEmpty({ empty: NO_DATA, hasFilters: true })).toBe(NO_DATA);
  });

  it('treats a missing hasFilters as no filter', () => {
    expect(resolveEmpty({ empty: NO_DATA, noResults: NO_RESULTS })).toBe(NO_DATA);
  });
});
