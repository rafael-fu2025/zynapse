import { afterEach, describe, expect, it, vi } from 'vitest';
import { useQuery } from '@tanstack/react-query';
import type * as ReactQuery from '@tanstack/react-query';
import type { AxiosAdapter } from 'axios';
import { apiClient } from '@/api/client';
import { useEncounters } from './useClinic';
import { useMedicines } from './useMedicines';
import { useInventoryItems } from './useInventory';
import { useEquipmentItems } from './useEquipment';
import { useReferrals } from './useReferrals';
import { useAppointmentSearch } from './useAppointments';

vi.mock('@tanstack/react-query', async (importOriginal) => ({
  ...await importOriginal<typeof ReactQuery>(),
  useQuery: vi.fn((options: unknown) => options),
}));

interface QueryOptions {
  queryFn: () => Promise<{ data: unknown[]; next: string | null }>;
  queryKey: unknown[];
  enabled?: boolean;
}
function options(invoke: () => unknown): QueryOptions {
  invoke();
  return vi.mocked(useQuery).mock.calls.at(-1)![0] as unknown as QueryOptions;
}
const originalAdapter = apiClient.defaults.adapter!;
afterEach(() => { apiClient.defaults.adapter = originalAdapter; vi.clearAllMocks(); });

const created = '2026-10-13 00:00:00';
const rows: Record<string, Record<string, unknown>> = {
  encounters: { id: 1, patient_school_id: 'TEST-1', appointment_id: null, station_id: 'Clinic',
    chief_complaint: 'Synthetic visit', triage_priority: null, triage_override: false, diagnosis: null,
    status: 'closed', attending_user_id: 8, started_at: created, closed_at: created },
  medicines: { id: 1, generic_name: 'Synthetic medicine', brand_name: null, category: null,
    dosage_form: null, dosage_strength: null, unit: 'tablet', reorder_threshold: 1, target_stock: null,
    description: null, quantity_on_hand: 5, low_stock: false, stock_status: 'in_stock',
    earliest_expiry: null, archived: false, created_at: created, last_movement: null },
  inventory: { id: 1, sku: 'TEST', name: 'Synthetic supply', unit: 'pc', quantity_on_hand: 5,
    reorder_level: 1, target_stock: null, low_stock: false, stock_status: 'in_stock',
    archived: false, created_at: created, last_movement: null },
  equipment: { id: 1, name: 'Synthetic equipment', category: null, location: null, notes: null,
    archived: false, working: 1, for_repair: 0, for_replacement: 0, retired: 0, total_units: 1,
    created_at: created },
  referrals: { id: 1, patient_school_id: 'TEST-1', source_module: 'clinic', target_module: 'counselling',
    artifact_type: 'intake_pass', status: 'submitted', reason_code: null, created_at: created, updated_at: created },
};

const pages = [
  ['encounters', (cursor: string | null) => useEncounters(cursor, 25, 'closed')],
  ['medicines', (cursor: string | null) => useMedicines(cursor, 25, 'aspirin', true)],
  ['inventory', (cursor: string | null) => useInventoryItems(cursor, 25, 'gauze', true, true)],
  ['equipment', (cursor: string | null) => useEquipmentItems(cursor, 25, 'scale', true)],
  ['referrals', (cursor: string | null) => useReferrals(cursor, 'submitted', 25)],
] as const;

describe('Clinic list pagination through the real envelope normalizer', () => {
  it.each(pages)('%s reads envelope meta and disables Next on the final page', async (name, invoke) => {
    const requests: URLSearchParams[] = [];
    apiClient.defaults.adapter = (async (config) => {
      await Promise.resolve();
      const params = new URL(config.url!, 'https://mock.test').searchParams;
      requests.push(params);
      return { config, status: 200, statusText: 'OK', headers: {}, data: {
        success: true, data: [{ ...rows[name], id: params.has('cursor') ? 2 : 1 }], errors: [],
        meta: { pagination: { limit: 25, next_cursor: params.has('cursor') ? null : 'opaque-page-2' } },
      } };
    }) satisfies AxiosAdapter;
    const first = await options(() => invoke(null)).queryFn();
    expect(first.next).toBe('opaque-page-2');
    expect(first.data).toMatchObject([{ ...rows[name], id: 1 }]);
    const final = await options(() => invoke(first.next)).queryFn();
    expect(final.next).toBeNull();
    expect(final.data).toMatchObject([{ ...rows[name], id: 2 }]);
    expect(requests[0]!.has('cursor')).toBe(false);
    expect(requests[1]!.get('cursor')).toBe('opaque-page-2');
    expect(requests[0]!.get('limit')).toBe('25');
    const filters = new URLSearchParams(requests[0]);
    filters.delete('cursor');
    const secondFilters = new URLSearchParams(requests[1]);
    secondFilters.delete('cursor');
    expect(secondFilters.toString()).toBe(filters.toString());
  });
});

describe('appointment search scope and paging', () => {
  it.each([
    ['upcoming', 'assigned'], ['upcoming', 'unassigned'], ['past', 'any'], ['all', 'any'],
  ] as const)('keeps %s/%s and status on every search page', async (scope, provider) => {
    apiClient.defaults.adapter = (async (config) => {
      await Promise.resolve();
      const params = new URL(config.url!, 'https://mock.test').searchParams;
      expect(params.get('scope')).toBe(scope);
      expect(params.get('provider')).toBe(provider);
      expect(params.get('status')).toBe('scheduled');
      expect(params.get('q')).toBe('Reyes');
      expect(params.get('limit')).toBe('25');
      return { config, status: 200, statusText: 'OK', headers: {}, data: {
        success: true, data: [{ id: 2, patient_school_id: 'TEST-2', provider_user_id: null,
          scheduled_at: '2026-10-13 00:00:00', created_at: '2026-10-12 00:00:00', status: 'scheduled', reason: null }], errors: [],
        meta: { pagination: { next_cursor: params.has('cursor') ? null : 'search-page-2' } },
      } };
    }) satisfies AxiosAdapter;
    const firstOptions = options(() => useAppointmentSearch(' Reyes ', 'scheduled', null, scope, provider));
    expect(firstOptions.enabled).toBe(true);
    expect(firstOptions.queryKey).toEqual(['appointments', 'search', {
      q: 'Reyes', status: 'scheduled', cursor: null, scope, provider, limit: 25,
    }]);
    const first = await firstOptions.queryFn();
    expect(first.data).toHaveLength(1);
    expect(first.next).toBe('search-page-2');
    const finalOptions = options(() => useAppointmentSearch('Reyes', 'scheduled', first.next, scope, provider));
    expect(finalOptions.queryKey).not.toEqual(firstOptions.queryKey);
    expect((await finalOptions.queryFn()).next).toBeNull();
  });
  it('does not enable blank or single-character searches', () => {
    for (const q of ['', ' ', ' x ']) expect(options(() => useAppointmentSearch(q)).enabled).toBe(false);
  });
});
