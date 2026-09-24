import { describe, expect, it } from 'vitest';
import { clusterTabs, type TabSection } from './TabSections';

/**
 * Only `value` and `group` matter to `clusterTabs`, so the fixtures stay
 * minimal — a real `TabSection` also needs a `label` and a lucide `icon`, and
 * pulling either in would drag the component's UI imports into a node-env test
 * for no gain.
 */
function tab(value: string, group?: string): Pick<TabSection, 'value' | 'group'> {
  return group === undefined ? { value } : { value, group };
}

/** The Counselling page's eight sections, in their rendered order. */
const COUNSELLING: Pick<TabSection, 'value' | 'group'>[] = [
  tab('queue', 'Sessions & Bookings'),
  tab('appointments', 'Sessions & Bookings'),
  tab('follow-ups', 'Sessions & Bookings'),
  tab('scheduling', 'Sessions & Bookings'),
  tab('surveys', 'Communication'),
  tab('announcements', 'Communication'),
  tab('analytics', 'Insights & Services'),
  tab('services', 'Insights & Services'),
];

describe('clusterTabs', () => {
  it('collapses a consecutive same-group run into one cluster', () => {
    const clusters = clusterTabs(COUNSELLING, 't');

    expect(clusters).toHaveLength(3);
    expect(clusters.map((c) => c.group)).toEqual([
      'Sessions & Bookings',
      'Communication',
      'Insights & Services',
    ]);
    expect(clusters.map((c) => c.items.map((i) => i.value))).toEqual([
      ['queue', 'appointments', 'follow-ups', 'scheduling'],
      ['surveys', 'announcements'],
      ['analytics', 'services'],
    ]);
  });

  it('numbers heading ids by the first item in each cluster', () => {
    // The index is the *first* member's position, so the ids stay stable as
    // long as the caller does not resequence — and they are what
    // `aria-describedby` points at.
    expect(clusterTabs(COUNSELLING, 't').map((c) => c.headingId)).toEqual([
      't-group-0',
      't-group-4',
      't-group-6',
    ]);
  });

  it('leaves an ungrouped page flat, with no heading', () => {
    // Eight pages share this component and pass no `group` at all — they must
    // render exactly as they did before grouping existed.
    const clusters = clusterTabs([tab('a'), tab('b'), tab('c')], 't');

    expect(clusters).toHaveLength(1);
    expect(clusters[0]?.group).toBeNull();
    expect(clusters[0]?.headingId).toBeNull();
    expect(clusters[0]?.items.map((i) => i.value)).toEqual(['a', 'b', 'c']);
  });

  it('starts a new cluster when an ungrouped run interrupts a grouped one', () => {
    const clusters = clusterTabs([tab('a', 'G'), tab('b'), tab('c', 'G')], 't');

    expect(clusters.map((c) => c.group)).toEqual(['G', null, 'G']);
    expect(clusters.map((c) => c.headingId)).toEqual(['t-group-0', null, 't-group-2']);
  });

  it('preserves interleaving rather than merging a repeated label', () => {
    // Two headings reading "G" is the honest rendering of an interleaved list.
    // Merging them would silently resequence the nav, which this helper must
    // never do — order belongs to the caller.
    const clusters = clusterTabs([tab('a', 'G'), tab('b', 'H'), tab('c', 'G')], 't');

    expect(clusters).toHaveLength(3);
    expect(clusters.map((c) => c.items.map((i) => i.value))).toEqual([['a'], ['b'], ['c']]);
    expect(clusters.map((c) => c.headingId)).toEqual(['t-group-0', 't-group-1', 't-group-2']);
  });

  it('gives every clustered heading a distinct id', () => {
    const ids = clusterTabs(COUNSELLING, 't').map((c) => c.headingId);

    expect(new Set(ids).size).toBe(ids.length);
  });

  it('returns nothing for an empty tab list', () => {
    expect(clusterTabs([], 't')).toEqual([]);
  });
});
