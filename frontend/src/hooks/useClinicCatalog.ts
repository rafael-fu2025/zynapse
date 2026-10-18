import { useInfiniteQuery } from '@tanstack/react-query';
import { z } from 'zod';
import { apiClient, getNextCursor } from '@/api/client';
import type { ApiEnvelopeError } from '@/api/envelope';
import { encounterSchema } from '@/schemas/clinic';
import { medicineSchema } from '@/schemas/medicines';
import { inventoryItemSchema } from '@/schemas/inventory';
import { equipmentItemSchema } from '@/schemas/equipment';

export const clinicCatalogSchema = z.union([encounterSchema, medicineSchema, inventoryItemSchema, equipmentItemSchema]);
export type ClinicCatalogRow = z.infer<typeof clinicCatalogSchema>;
export type ClinicCatalogKind = 'medicine' | 'supply' | 'encounter' | 'equipment';
const paths: Record<ClinicCatalogKind, string> = {
  medicine: '/clinic/medicines', supply: '/clinic/inventory',
  encounter: '/clinic/encounters', equipment: '/clinic/equipment',
};
const schemas = { medicine: medicineSchema, supply: inventoryItemSchema, encounter: encounterSchema, equipment: equipmentItemSchema };
const keys = { medicine: 'medicines', supply: 'inventory', encounter: 'clinic', equipment: 'equipment' };

/** Cursor-backed picker/aggregate pages. Encounter API has no q filter: search loaded rows locally. */
export function useClinicCatalog(kind: ClinicCatalogKind, search = '') {
  const q = kind === 'encounter' ? '' : search.trim();
  return useInfiniteQuery<
    { data: ClinicCatalogRow[]; next: string | null }, ApiEnvelopeError
  >({
    queryKey: [keys[kind], 'catalog-pages', { kind, q }],
    initialPageParam: null as string | null,
    queryFn: async ({ pageParam, signal }) => {
      const params = new URLSearchParams({ limit: '25' });
      if (typeof pageParam === 'string') params.set('cursor', pageParam);
      if (q !== '') params.set('q', q);
      if (kind === 'encounter') params.set('status', 'open');
      const res = await apiClient.get<unknown[]>(`${paths[kind]}?${params.toString()}`, { signal });
      return { data: z.array(schemas[kind]).parse(res.data), next: getNextCursor(res) };
    },
    getNextPageParam: (page) => page.next ?? undefined,
    staleTime: 30_000,
  });
}

export function clinicCatalogOption(row: ClinicCatalogRow) {
  if ('generic_name' in row) return {
    value: String(row.id), label: row.brand_name ? `${row.generic_name} (${row.brand_name})` : row.generic_name,
    hint: [row.dosage_strength, row.dosage_form, `${row.quantity_on_hand} ${row.unit} on hand`].filter(Boolean).join(' · '),
  };
  if ('patient_school_id' in row) return {
    value: String(row.id), label: `#${row.id} · ${row.patient_name ?? row.patient_school_id} · ${row.patient_school_id} · ${row.chief_complaint}`,
    hint: row.started_at,
  };
  if ('sku' in row) return {
    value: String(row.id), label: `${row.name} (${row.sku})`, hint: `${row.quantity_on_hand} ${row.unit} on hand`,
  };
  return { value: String(row.id), label: row.name, hint: `${row.total_units} units` };
}
