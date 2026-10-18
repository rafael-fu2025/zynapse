import { beforeEach, describe, expect, it, vi } from 'vitest';
import { useEquipmentItems as equipmentQuery } from './useEquipment';
import { useMedicines as medicineQuery } from './useMedicines';
import { useInventoryItems as supplyQuery } from './useInventory';
import { useStaffSchedules as staffQuery } from './useStaffSchedules';
import { useBmgUnits as drumQuery, useBmgDevices as deviceQuery, useWasteCategories as categoryQuery } from './useFacilities';
import { useGuidanceAnnouncements as announcementQuery, useGuidanceServices as serviceQuery,
  useUnarchiveGuidanceAnnouncement as announcementRestore, useUnarchiveGuidanceService as serviceRestore } from './useGuidanceContent';
import { useSurveys as surveyQuery } from './useSurveys';
import { useReportConfigs as configQuery } from './useReports';

type Options = { queryKey: unknown[]; queryFn: () => Promise<unknown>; placeholderData?: unknown };
const harness = vi.hoisted(() => ({
  options: undefined as Options | undefined,
  mutation: undefined as { mutationFn: (input: unknown) => Promise<unknown> } | undefined,
  get: vi.fn(), post: vi.fn(),
}));
vi.mock('@tanstack/react-query', () => ({
  useQuery: (options: Options) => { harness.options = options; return {}; },
  useMutation: (options: { mutationFn: (input: unknown) => Promise<unknown> }) => { harness.mutation = options; return {}; },
  useQueryClient: () => ({}),
  keepPreviousData: (data: unknown) => data,
}));
vi.mock('@/api/client', () => ({ apiClient: { get: harness.get, post: harness.post }, getNextCursor: () => null }));

beforeEach(() => {
  vi.clearAllMocks();
  harness.get.mockImplementation((path: string) => Promise.resolve({ data: path.startsWith('/reports/configs')
    ? { items: [], pagination: { page: 1, limit: 10, total: 0, total_pages: 1 } } : [] }));
  harness.post.mockResolvedValue({ data: { id: 42, archived: false } });
});

const lists: Array<[string, (archived: boolean) => unknown]> = [
  ['equipment', (archived) => equipmentQuery(null, 25, null, archived)],
  ['medicines', (archived) => medicineQuery(null, 25, null, archived)],
  ['supplies', (archived) => supplyQuery(null, 25, null, archived)],
  ['staff schedules', (archived) => staffQuery(archived)],
  ['drums', (archived) => drumQuery(null, 25, archived ? 'archived' : 'active')],
  ['devices', (archived) => deviceQuery(null, 25, archived ? 'archived' : 'active')],
  ['waste categories', (archived) => categoryQuery(archived ? 'archived' : 'active')],
  ['announcements', (archived) => announcementQuery(archived)],
  ['services', (archived) => serviceQuery(archived)],
  ['surveys', (archived) => surveyQuery(archived)],
  ['saved reports', (archived) => configQuery(1, archived)],
];

describe.each(lists)('%s archive scope', (_name, invoke) => {
  it('sends exclusive active/archived scopes with separate caches and no stale opposite-view rows', async () => {
    const keys: unknown[] = [];
    for (const archived of [false, true]) {
      invoke(archived);
      const options = harness.options;
      expect(options).toBeDefined();
      keys.push(options?.queryKey);
      expect(options?.placeholderData).toBeUndefined();
      await options?.queryFn();
      const [path, config] = harness.get.mock.calls.at(-1) as [string, { params?: Record<string, string> } | undefined];
      const url = new URL(path, 'https://synapse.test');
      const scope = config?.params?.['archived'] ?? url.searchParams.get('archived');
      expect(scope).toBe(archived ? '1' : '0');
      expect(url.searchParams.has('include_archived')).toBe(false);
    }
    expect(keys[0]).not.toEqual(keys[1]);
  });
});

it('announcement restore posts the row ID from its object payload', async () => {
  announcementRestore();
  await harness.mutation?.mutationFn({ id: 42, title: 'Notice' });
  expect(harness.post).toHaveBeenCalledWith('/counselling/announcements/42/unarchive');
});

it('service restore posts the row ID from its object payload', async () => {
  serviceRestore();
  await harness.mutation?.mutationFn({ id: 42, name: 'Service' });
  expect(harness.post).toHaveBeenCalledWith('/counselling/services/42/unarchive');
});
