import { afterEach, expect, it, vi } from 'vitest';
import { useInfiniteQuery } from '@tanstack/react-query';
import type * as ReactQuery from '@tanstack/react-query';
import { apiClient } from '@/api/client';
import { clinicCatalogOption, useClinicCatalog } from './useClinicCatalog';
import { medicineSchema } from '@/schemas/medicines';

vi.mock('@tanstack/react-query', async (original) => ({
  ...await original<typeof ReactQuery>(), useInfiniteQuery: vi.fn((options: unknown) => options),
}));
interface Options {
  queryKey: unknown[];
  queryFn: (context: { pageParam: unknown; signal: AbortSignal }) => Promise<{ data: unknown[]; next: string | null }>;
  getNextPageParam: (page: { next: string | null }) => string | undefined;
}
it('medicine picker retains numeric identity, strength, form and stock hint', () => {
  const row = medicineSchema.parse({ id: 501, generic_name: 'Rare medicine', brand_name: null,
    category: null, dosage_form: 'tablet', dosage_strength: '20 mg', unit: 'tablet',
    reorder_threshold: 1, target_stock: null, description: null, quantity_on_hand: 12,
    low_stock: false, stock_status: 'in_stock', earliest_expiry: null, archived: false,
    created_at: '2026-10-13 00:00:00', last_movement: null });
  expect(clinicCatalogOption(row)).toEqual({ value: '501', label: 'Rare medicine',
    hint: '20 mg · tablet · 12 tablet on hand' });
});
const adapter = apiClient.defaults.adapter!;
afterEach(() => { apiClient.defaults.adapter = adapter; vi.clearAllMocks(); });
it.each(['medicine', 'supply', 'encounter', 'equipment'] as const)('%s catalog uses real envelope cursors, bounded pages and cancellation', async (kind) => {
  const signal = new AbortController().signal;
  const requests: URLSearchParams[] = [];
  apiClient.defaults.adapter = async (config) => {
    await Promise.resolve();
    const params = new URL(config.url!, 'https://mock.test').searchParams;
    requests.push(params);
    expect(config.signal).toBe(signal);
    expect(params.get('limit')).toBe('25');
    expect(params.get('q')).toBe(kind === 'encounter' ? null : 'test');
    expect(params.get('status')).toBe(kind === 'encounter' ? 'open' : null);
    return { config, status: 200, statusText: 'OK', headers: {}, data: {
      success: true, data: [], errors: [], meta: { pagination: { next_cursor: params.has('cursor') ? null : 'next-25' } },
    } };
  };
  useClinicCatalog(kind, ' test ');
  const options = vi.mocked(useInfiniteQuery).mock.calls.at(-1)![0] as unknown as Options;
  const first = await options.queryFn({ pageParam: null, signal });
  expect(first.next).toBe('next-25');
  expect(options.getNextPageParam(first)).toBe('next-25');
  const last = await options.queryFn({ pageParam: first.next, signal });
  expect(options.getNextPageParam(last)).toBeUndefined();
  expect(requests[0]!.has('cursor')).toBe(false);
  expect(requests[1]!.get('cursor')).toBe('next-25');
  const oldKey = options.queryKey;
  useClinicCatalog(kind, 'other');
  const newKey = (vi.mocked(useInfiniteQuery).mock.calls.at(-1)![0] as unknown as Options).queryKey;
  if (kind === 'encounter') expect(newKey).toEqual(oldKey);
  else expect(newKey).not.toEqual(oldKey);
});
