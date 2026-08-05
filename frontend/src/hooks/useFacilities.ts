/**
 * Facilities hooks — BMG state machine operations.
 *
 * Uses TanStack Query for both reads and mutations. Each transition
 * mutation uses `onMutate` to optimistically flip the unit's `status`
 * in the cache, `onError` to roll back, and `onSettled` to invalidate
 * the cache so the server's truth is reconciled.
 *
 * Mutation signatures:
 *   - startBatch({ unitId, input })
 *   - recordOutput({ unitId, batchId, input })
 *   - finishBatch({ unitId, batchId })
 *   - cancelBatch({ unitId, batchId, input })
 *
 * `unitId` is supplied by the caller so the hook can patch the right
 * row in the unit cache without coupling to a specific component.
 */
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { z } from 'zod';
import { apiClient } from '@/api/client';
import { ApiEnvelopeError } from '@/api/envelope';
import { toast } from 'sonner';
import {
  addBatchInputSchema,
  addBatchLossSchema,
  addProcessLogSchema,
  activeBatchSchema,
  batchAnalyticsSchema,
  batchComplianceSchema,
  batchHistoryItemSchema,
  blendCnSchema,
  bmgAlertSchema,
  bmgBatchSchema,
  bmgUnitSchema,
  cancelBatchSchema,
  categoryDeviationSchema,
  createSopDocumentSchema,
  createUnitSchema,
  createWasteCategorySchema,
  moveToCuringSchema,
  openAlertSchema,
  processLogSchema,
  releaseBatchSchema,
  sopDocumentSchema,
  updateUnitSchema,
  updateWasteCategorySchema,
  wasteCategorySchema,
  type ActiveBatch,
  type AddBatchInputInput,
  type AddBatchLossInput,
  type AddProcessLogInput,
  type BatchAnalytics,
  type BatchCompliance,
  type BatchHistoryItem,
  type BlendCn,
  type BmgAlert,
  type BmgBatch,
  type BmgUnit,
  type CancelBatchInput,
  type CategoryDeviation,
  type CreateSopDocumentInput,
  type CreateUnitInput,
  type CreateWasteCategoryInput,
  type MoveToCuringInput,
  type OpenAlert,
  type ProcessLog,
  type RecordOutputInput,
  type ReleaseBatchInput,
  type SopDocument,
  type StartBatchInput,
  type UpdateUnitInput,
  type UpdateWasteCategoryInput,
  type WasteCategory,
} from '@/schemas/facilities';

const UNITS_KEY = ['facilities', 'units'] as const;
const ACTIVE_BATCHES_KEY = ['facilities', 'batches', 'active'] as const;

function unitsQueryKey(cursor: string | null, limit: number, includeArchived = false) {
  return [...UNITS_KEY, { cursor, limit, includeArchived }] as const;
}

/**
 * Invalidate EVERY Facilities query that could have changed as a result
 * of a transition mutation. Centralized so a new transition can't
 * forget to refresh the "Processing Drums" widget or the unit list.
 *
 * The dashboard, the list page, and the analytics card all read from
 * these keys — invalidating `['facilities', ...]` covers them all in
 * one call.
 *
 * @param batchId When provided, also drops the per-batch analytics
 *                cache (a finished/cancelled batch may surface new
 *                yield / duration values).
 */
function invalidateFacilities(
  qc: ReturnType<typeof useQueryClient>,
  batchId: number | null = null,
): void {
  void qc.invalidateQueries({ queryKey: UNITS_KEY });
  void qc.invalidateQueries({ queryKey: ACTIVE_BATCHES_KEY });
  if (batchId !== null) {
    void qc.invalidateQueries({ queryKey: ['facilities', 'analytics', batchId] });
    void qc.invalidateQueries({ queryKey: ['facilities', 'process-logs', batchId] });
  }
}

function patchUnitInCache(
  qc: ReturnType<typeof useQueryClient>,
  unitId: number,
  patch: Partial<BmgUnit>,
) {
  qc.setQueriesData<{ data: BmgUnit[]; next: string | null }>(
    { queryKey: UNITS_KEY },
    (old) => {
      if (old === undefined) return old;
      return {
        ...old,
        data: old.data.map((u) => (u.id === unitId ? { ...u, ...patch } : u)),
      };
    },
  );
}

function snapshotUnits(
  qc: ReturnType<typeof useQueryClient>,
): Array<[readonly unknown[], { data: BmgUnit[]; next: string | null } | undefined]> {
  return qc.getQueriesData<{ data: BmgUnit[]; next: string | null }>({
    queryKey: UNITS_KEY,
  });
}

export function useBmgUnits(cursor: string | null, limit = 25, includeArchived = false) {
  return useQuery<{ data: BmgUnit[]; next: string | null }, ApiEnvelopeError>({
    queryKey: unitsQueryKey(cursor, limit, includeArchived),
    queryFn: async () => {
      const params = new URLSearchParams();
      if (cursor !== null) params.set('cursor', cursor);
      params.set('limit', String(limit));
      if (includeArchived) params.set('include_archived', '1');
      const res = await apiClient.get<{ data: BmgUnit[]; next: string | null }>(
        `/facilities/units?${params.toString()}`,
      );
      return {
        data: z.array(bmgUnitSchema).parse(res.data),
        next: res.data?.next ?? null,
      };
    },
  });
}

/** Optimistic-update context shared by all BMG mutations. */
interface UnitsMutationCtx {
  snapshots: ReturnType<typeof snapshotUnits>;
}

export interface UseStartBatchVars {
  unitId: number;
  input: StartBatchInput;
}

export function useStartBatch() {
  const qc = useQueryClient();
  return useMutation<BmgBatch, ApiEnvelopeError, UseStartBatchVars, UnitsMutationCtx>({
    mutationFn: async ({ unitId, input }) => {
      const res = await apiClient.post<BmgBatch>(`/facilities/units/${unitId}/start`, input);
      return bmgBatchSchema.parse(res.data);
    },
    onMutate: async ({ unitId }) => {
      await qc.cancelQueries({ queryKey: UNITS_KEY });
      const snapshots = snapshotUnits(qc);
      // Optimistic flip. We don't know the new `active_batch_id` until
      // the server responds, so we drop the field — the `onSettled`
      // refetch below will repopulate it.
      patchUnitInCache(qc, unitId, { status: 'processing', active_batch_id: null });
      return { snapshots };
    },
    onError: (err, _input, ctx) => {
      for (const [key, snap] of ctx?.snapshots ?? []) {
        qc.setQueryData(key, snap);
      }
      toast.error(err.errors[0]?.message ?? 'Failed to start batch.');
    },
    onSettled: () => {
      // No batchId yet at settle time; the unit refetch alone is enough
      // to fix the active_batch_id, and the next `useActiveBatches`
      // refetch will surface the new batch.
      invalidateFacilities(qc);
    },
    onSuccess: () => {
      toast.success('Batch started.');
    },
  });
}

export interface UseRecordOutputVars {
  unitId: number;
  batchId: number;
  input: RecordOutputInput;
}

export function useRecordOutput() {
  const qc = useQueryClient();
  return useMutation<BmgBatch, ApiEnvelopeError, UseRecordOutputVars, UnitsMutationCtx>({
    mutationFn: async ({ batchId, input }) => {
      const res = await apiClient.post<BmgBatch>(`/facilities/batches/${batchId}/output`, input);
      return bmgBatchSchema.parse(res.data);
    },
    onMutate: async ({ unitId }) => {
      await qc.cancelQueries({ queryKey: UNITS_KEY });
      const snapshots = snapshotUnits(qc);
      patchUnitInCache(qc, unitId, { status: 'awaiting_output' });
      return { snapshots };
    },
    onError: (err, _input, ctx) => {
      for (const [key, snap] of ctx?.snapshots ?? []) {
        qc.setQueryData(key, snap);
      }
      toast.error(err.errors[0]?.message ?? 'Failed to record output.');
    },
    onSettled: (_d, _e, vars) => {
      // The batch is still active (now AwaitingOutput) but its yield /
      // inputs have changed — drop both the unit and the per-batch
      // analytics so the dashboard + analytics card stay honest.
      invalidateFacilities(qc, vars.batchId);
    },
    onSuccess: () => {
      toast.success('Output recorded.');
    },
  });
}

export interface UseFinishBatchVars {
  unitId: number;
  batchId: number;
}

export function useFinishBatch() {
  const qc = useQueryClient();
  return useMutation<BmgBatch, ApiEnvelopeError, UseFinishBatchVars, UnitsMutationCtx>({
    mutationFn: async ({ batchId }) => {
      const res = await apiClient.post<BmgBatch>(`/facilities/batches/${batchId}/finish`);
      return bmgBatchSchema.parse(res.data);
    },
    onMutate: async ({ unitId }) => {
      await qc.cancelQueries({ queryKey: UNITS_KEY });
      const snapshots = snapshotUnits(qc);
      patchUnitInCache(qc, unitId, { status: 'idle', active_batch_id: null });
      return { snapshots };
    },
    onError: (err, _input, ctx) => {
      for (const [key, snap] of ctx?.snapshots ?? []) {
        qc.setQueryData(key, snap);
      }
      toast.error(err.errors[0]?.message ?? 'Failed to finish batch.');
    },
    onSettled: (_d, _e, vars) => {
      invalidateFacilities(qc, vars.batchId);
    },
    onSuccess: () => {
      toast.success('Batch finished.');
    },
  });
}

export interface UseCancelBatchVars {
  unitId: number;
  batchId: number;
  input: CancelBatchInput;
}

export function useCancelBatch() {
  const qc = useQueryClient();
  return useMutation<BmgBatch, ApiEnvelopeError, UseCancelBatchVars, UnitsMutationCtx>({
    mutationFn: async ({ batchId, input }) => {
      const valid = cancelBatchSchema.parse(input);
      const res = await apiClient.post<BmgBatch>(`/facilities/batches/${batchId}/cancel`, valid);
      return bmgBatchSchema.parse(res.data);
    },
    onMutate: async ({ unitId }) => {
      await qc.cancelQueries({ queryKey: UNITS_KEY });
      const snapshots = snapshotUnits(qc);
      // Server: cancel puts the UNIT back to Idle; only the BATCH row
      // is marked Cancelled. The "Processing Drums" widget (which reads
      // active batches, not unit.status) will drop the row once the
      // invalidate completes.
      patchUnitInCache(qc, unitId, { status: 'idle', active_batch_id: null });
      return { snapshots };
    },
    onError: (err, _input, ctx) => {
      for (const [key, snap] of ctx?.snapshots ?? []) {
        qc.setQueryData(key, snap);
      }
      toast.error(err.errors[0]?.message ?? 'Failed to cancel batch.');
    },
    onSettled: (_d, _e, vars) => {
      invalidateFacilities(qc, vars.batchId);
    },
    onSuccess: () => {
      toast.success('Batch cancelled.');
    },
  });
}

// ------------------------------------------------------ process logs

export function useProcessLogs(batchId: number | null) {
  return useQuery<ProcessLog[], ApiEnvelopeError>({
    queryKey: ['facilities', 'process-logs', batchId],
    queryFn: async () => {
      const res = await apiClient.get<unknown[]>(`/facilities/batches/${batchId}/logs`);
      return z.array(processLogSchema).parse(res.data);
    },
    enabled: batchId !== null && batchId > 0,
  });
}

export function useAddProcessLog() {
  const qc = useQueryClient();
  return useMutation<ProcessLog, ApiEnvelopeError, { batchId: number; input: AddProcessLogInput }>({
    mutationFn: async ({ batchId, input }) => {
      const valid = addProcessLogSchema.parse(input);
      const payload: Record<string, unknown> = {};
      if (valid.observation_note !== undefined && valid.observation_note !== '') payload['observation_note'] = valid.observation_note;
      if (valid.temperature_celsius !== undefined && valid.temperature_celsius !== '') payload['temperature_celsius'] = valid.temperature_celsius;
      if (valid.moisture_level !== undefined) payload['moisture_level'] = valid.moisture_level;
      if (valid.oxygen_pct !== undefined && valid.oxygen_pct !== '') payload['oxygen_pct'] = valid.oxygen_pct;
      if (valid.device_id !== undefined && valid.device_id !== '') payload['device_id'] = valid.device_id;
      if (valid.calibration_status !== undefined) payload['calibration_status'] = valid.calibration_status;
      const res = await apiClient.post<unknown>(`/facilities/batches/${batchId}/logs`, payload);
      return processLogSchema.parse(res.data);
    },
    onSuccess: (_d, vars) => {
      // New log entry may nudge yield / progress — drop the analytics
      // for this batch and let the active-batches list refetch so the
      // days_active counter stays current.
      void qc.invalidateQueries({ queryKey: ['facilities', 'process-logs', vars.batchId] });
      void qc.invalidateQueries({ queryKey: ACTIVE_BATCHES_KEY });
      void qc.invalidateQueries({ queryKey: ['facilities', 'analytics', vars.batchId] });
      void qc.invalidateQueries({ queryKey: ['facilities', 'alerts', vars.batchId] });
      toast.success('Observation logged.');
    },
    onError: (err) => {
      toast.error(err.errors[0]?.message ?? 'Failed to log observation.');
    },
  });
}

// ------------------------------------------------------ Phase P4

export function useWasteCategories(activeOnly = false) {
  return useQuery<WasteCategory[], ApiEnvelopeError>({
    queryKey: ['facilities', 'waste-categories', { activeOnly }],
    queryFn: async () => {
      const res = await apiClient.get<unknown[]>(`/facilities/waste-categories${activeOnly ? '?active=1' : ''}`);
      return z.array(wasteCategorySchema).parse(res.data);
    },
  });
}

export function useCreateWasteCategory() {
  const qc = useQueryClient();
  return useMutation<{ id: number }, ApiEnvelopeError, CreateWasteCategoryInput>({
    mutationFn: async (input) => {
      const valid = createWasteCategorySchema.parse(input);
      const res = await apiClient.post<{ id: number }>('/facilities/waste-categories', valid);
      return z.object({ id: z.number().int().positive() }).parse(res.data);
    },
    onSuccess: () => {
      void qc.invalidateQueries({ queryKey: ['facilities', 'waste-categories'] });
      void qc.invalidateQueries({ queryKey: ['facilities', 'units'] });
      toast.success('Waste category created.');
    },
    onError: (err) => {
      toast.error(err.errors[0]?.message ?? 'Failed to create category.');
    },
  });
}

export function useUpdateWasteCategory() {
  const qc = useQueryClient();
  return useMutation<WasteCategory, ApiEnvelopeError, { categoryId: number; input: UpdateWasteCategoryInput }>({
    mutationFn: async ({ categoryId, input }) => {
      const valid = updateWasteCategorySchema.parse(input);
      const res = await apiClient.post<WasteCategory>(`/facilities/waste-categories/${categoryId}`, valid);
      return wasteCategorySchema.parse(res.data);
    },
    onSuccess: () => {
      void qc.invalidateQueries({ queryKey: ['facilities', 'waste-categories'] });
      void qc.invalidateQueries({ queryKey: ['facilities', 'units'] });
      toast.success('Waste category updated.');
    },
    onError: (err) => {
      toast.error(err.errors[0]?.message ?? 'Failed to update category.');
    },
  });
}

export function useArchiveWasteCategory() {
  const qc = useQueryClient();
  return useMutation<WasteCategory, ApiEnvelopeError, { categoryId: number }>({
    mutationFn: async ({ categoryId }) => {
      const res = await apiClient.post<WasteCategory>(`/facilities/waste-categories/${categoryId}/archive`);
      return wasteCategorySchema.parse(res.data);
    },
    onSuccess: () => {
      void qc.invalidateQueries({ queryKey: ['facilities', 'waste-categories'] });
      void qc.invalidateQueries({ queryKey: ['facilities', 'units'] });
      toast.success('Waste category archived.');
    },
    onError: (err) => {
      toast.error(err.errors[0]?.message ?? 'Failed to archive category.');
    },
  });
}

/** Restore an archived waste category so it reappears in pickers. */
export function useUnarchiveWasteCategory() {
  const qc = useQueryClient();
  return useMutation<WasteCategory, ApiEnvelopeError, { categoryId: number }>({
    mutationFn: async ({ categoryId }) => {
      const res = await apiClient.post<WasteCategory>(`/facilities/waste-categories/${categoryId}/unarchive`);
      return wasteCategorySchema.parse(res.data);
    },
    onSuccess: () => {
      void qc.invalidateQueries({ queryKey: ['facilities', 'waste-categories'] });
      void qc.invalidateQueries({ queryKey: ['facilities', 'units'] });
      toast.success('Waste category restored.');
    },
    onError: (err) => {
      toast.error(err.errors[0]?.message ?? 'Failed to restore category.');
    },
  });
}

/**
 * Specialised toast for delete-409. The server's contract is: a waste
 * category that has batch or unit references cannot be hard-deleted;
 * the user must archive it instead. The inline action button fires
 * `archive` so the user doesn't have to close the toast and click
 * again — the optimistic remove from `useArchiveWasteCategory` takes
 * care of the cache.
 *
 * @param err  The error returned from the delete mutation.
 * @param fallback  Called when the user clicks the toast action.
 */
function isDeleteConflict(err: unknown): boolean {
  return err instanceof ApiEnvelopeError && err.httpStatus === 409;
}

export function useDeleteWasteCategory() {
  const qc = useQueryClient();
  const archive = useArchiveWasteCategory();
  return useMutation<{ id: number; deleted: true }, ApiEnvelopeError, { categoryId: number }>({
    mutationFn: async ({ categoryId }) => {
      const res = await apiClient.delete<{ id: number; deleted: true }>(`/facilities/waste-categories/${categoryId}`);
      return res.data;
    },
    onSuccess: () => {
      void qc.invalidateQueries({ queryKey: ['facilities', 'waste-categories'] });
      void qc.invalidateQueries({ queryKey: UNITS_KEY });
      void qc.invalidateQueries({ queryKey: ACTIVE_BATCHES_KEY });
      toast.success('Waste category deleted.');
    },
    onError: (err, vars) => {
      if (isDeleteConflict(err)) {
        // 409: category is in use. Offer the user the only valid path
        // (archive) inline, so they don't have to close the toast and
        // hunt for the Archive button.
        toast.error(err.errors[0]?.message ?? 'Cannot delete this category.', {
          duration: 8_000,
          action: {
            label: 'Archive instead',
            onClick: () => {
              archive.mutate({ categoryId: vars.categoryId });
            },
          },
        });
        return;
      }
      toast.error(err.errors[0]?.message ?? 'Failed to delete category.');
    },
  });
}

export function useBatchAnalytics(batchId: number | null) {
  return useQuery<BatchAnalytics, ApiEnvelopeError>({
    queryKey: ['facilities', 'analytics', batchId],
    queryFn: async () => {
      const res = await apiClient.get<unknown>(`/facilities/batches/${batchId}/analytics`);
      return batchAnalyticsSchema.parse(res.data);
    },
    enabled: batchId !== null && batchId > 0,
  });
}

export function useAddBatchIo() {
  const qc = useQueryClient();
  return useMutation<
    unknown,
    ApiEnvelopeError,
    { batchId: number; kind: 'inputs' | 'outputs'; body: Record<string, unknown> }
  >({
    mutationFn: async ({ batchId, kind, body }) => {
      const res = await apiClient.post<unknown>(`/facilities/batches/${batchId}/${kind}`, body);
      return res.data;
    },
    onSuccess: (_d, vars) => {
      // Recording a batch I/O row can change the unit's active_batch_id
      // join result on the next refetch AND its analytics — drop both
      // so the list + dashboard stay aligned without a manual reload.
      void qc.invalidateQueries({ queryKey: ['facilities', 'analytics', vars.batchId] });
      void qc.invalidateQueries({ queryKey: UNITS_KEY });
      void qc.invalidateQueries({ queryKey: ACTIVE_BATCHES_KEY });
      toast.success(vars.kind === 'inputs' ? 'Input recorded.' : 'Output recorded.');
    },
    onError: (err) => {
      toast.error(err.errors[0]?.message ?? 'Failed to record.');
    },
  });
}

// ------------------------------------------------------ Drum CRUD

/**
 * Strip empty-string optional fields so the backend sees a clean patch
 * (it treats '' as null via `permit_empty`).
 */
function cleanOptional<T extends Record<string, unknown>>(input: T): Partial<T> {
  const out: Partial<T> = {};
  for (const [k, v] of Object.entries(input)) {
    if (v === '' || v === undefined) continue;
    out[k as keyof T] = v as T[keyof T];
  }
  return out;
}

export function useCreateUnit() {
  const qc = useQueryClient();
  return useMutation<BmgUnit, ApiEnvelopeError, CreateUnitInput, UnitsMutationCtx>({
    mutationFn: async (input) => {
      const valid = createUnitSchema.parse(input);
      const res = await apiClient.post<BmgUnit>('/facilities/units', cleanOptional(valid));
      return bmgUnitSchema.parse(res.data);
    },
    onMutate: async () => {
      await qc.cancelQueries({ queryKey: UNITS_KEY });
      const snapshots = snapshotUnits(qc);
      return { snapshots };
    },
    onError: (err, _input, ctx) => {
      for (const [key, snap] of ctx?.snapshots ?? []) {
        qc.setQueryData(key, snap);
      }
      toast.error(err.errors[0]?.message ?? 'Failed to create drum.');
    },
    onSuccess: (created) => {
      // Optimistically insert the new drum into every cached page so it
      // appears instantly, then reconcile with the server.
      qc.setQueriesData<{ data: BmgUnit[]; next: string | null }>(
        { queryKey: UNITS_KEY },
        (old) => {
          if (old === undefined) return old;
          if (old.data.some((u) => u.id === created.id)) return old;
          return { ...old, data: [created, ...old.data] };
        },
      );
      invalidateFacilities(qc);
      toast.success('Drum created.');
    },
  });
}

export function useUpdateUnit() {
  const qc = useQueryClient();
  return useMutation<BmgUnit, ApiEnvelopeError, { unitId: number; input: UpdateUnitInput }, UnitsMutationCtx>({
    mutationFn: async ({ unitId, input }) => {
      const valid = updateUnitSchema.parse(input);
      const res = await apiClient.post<BmgUnit>(`/facilities/units/${unitId}`, cleanOptional(valid));
      return bmgUnitSchema.parse(res.data);
    },
    onMutate: async ({ unitId, input }) => {
      await qc.cancelQueries({ queryKey: UNITS_KEY });
      const snapshots = snapshotUnits(qc);
      patchUnitInCache(qc, unitId, input as Partial<BmgUnit>);
      return { snapshots };
    },
    onError: (err, _vars, ctx) => {
      for (const [key, snap] of ctx?.snapshots ?? []) {
        qc.setQueryData(key, snap);
      }
      toast.error(err.errors[0]?.message ?? 'Failed to update drum.');
    },
    onSuccess: () => {
      invalidateFacilities(qc);
      toast.success('Drum updated.');
    },
  });
}

export function useArchiveUnit() {
  const qc = useQueryClient();
  return useMutation<BmgUnit, ApiEnvelopeError, { unitId: number }, UnitsMutationCtx>({
    mutationFn: async ({ unitId }) => {
      // The mutation may run on a row the server has already archived
      // (e.g. two clicks in quick succession, or another tab archived
      // it). A 404 here is a no-op for the user — we swallow it and let
      // `onSettled` reconcile. Anything else propagates to onError.
      try {
        const res = await apiClient.delete<BmgUnit>(`/facilities/units/${unitId}`);
        return bmgUnitSchema.parse(res.data);
      } catch (err) {
        if (err instanceof ApiEnvelopeError && err.httpStatus === 404) {
          // Return a minimal placeholder; the snapshot + invalidate will
          // ensure the row is gone from the cache. Caller does not
          // parse the return on 404.
          return { id: unitId, code: '', display_name: '', status: 'idle', created_at: '' } as unknown as BmgUnit;
        }
        throw err;
      }
    },
    onMutate: async ({ unitId }) => {
      await qc.cancelQueries({ queryKey: UNITS_KEY });
      const snapshots = snapshotUnits(qc);
      // Optimistically remove the archived row so the user sees it
      // disappear immediately; the server reconcile re-asserts truth.
      qc.setQueriesData<{ data: BmgUnit[]; next: string | null }>(
        { queryKey: UNITS_KEY },
        (old) => {
          if (old === undefined) return old;
          return { ...old, data: old.data.filter((u) => u.id !== unitId) };
        },
      );
      return { snapshots };
    },
    onError: (err, _vars, ctx) => {
      for (const [key, snap] of ctx?.snapshots ?? []) {
        qc.setQueryData(key, snap);
      }
      toast.error(err.errors[0]?.message ?? 'Failed to archive drum.');
    },
    onSettled: () => {
      // Always reconcile, even on 404 — the optimistic remove may have
      // raced with a refetch that re-introduced the row.
      invalidateFacilities(qc);
    },
    onSuccess: () => {
      toast.success('Drum archived.');
    },
  });
}

/**
 * Restore a soft-archived drum. No optimistic patch — the archived row
 * is only visible when the list was fetched with `includeArchived`, so
 * a plain invalidate keeps every variant of the units query honest.
 */
export function useUnarchiveUnit() {
  const qc = useQueryClient();
  return useMutation<BmgUnit, ApiEnvelopeError, { unitId: number }>({
    mutationFn: async ({ unitId }) => {
      const res = await apiClient.post<BmgUnit>(`/facilities/units/${unitId}/unarchive`);
      return bmgUnitSchema.parse(res.data);
    },
    onSuccess: (u) => {
      invalidateFacilities(qc);
      toast.success(`Drum ${u.code} restored.`);
    },
    onError: (err) => {
      toast.error(err.errors[0]?.message ?? 'Failed to restore drum.');
    },
  });
}

// ------------------------------------------------------ Processing Drums

/**
 * One row per active batch (Processing or AwaitingOutput) enriched with
 * unit info, optional waste category, and computed days_active /
 * expected_completion_date / progress_pct. Powers the "Processing Drums"
 * dashboard widget — a single round-trip keeps the dashboard snappy
 * behind the single-threaded dev server.
 */
export function useActiveBatches() {
  return useQuery<ActiveBatch[], ApiEnvelopeError>({
    queryKey: ['facilities', 'batches', 'active'],
    queryFn: async () => {
      const res = await apiClient.get<unknown>('/facilities/batches/active');
      return z.array(activeBatchSchema).parse(res.data);
    },
    // Cheap query (small result set, but every page re-mounts). Refresh
    // when the tab regains focus so an operator always sees fresh state.
    staleTime: 30_000,
  });
}

export function useSetUnitMaintenance() {
  const qc = useQueryClient();
  return useMutation<{ id: number; status: string }, ApiEnvelopeError, { unitId: number; maintenance: boolean }, UnitsMutationCtx>({
    mutationFn: async ({ unitId, maintenance }) => {
      const res = await apiClient.post<{ id: number; status: string }>(`/facilities/units/${unitId}/maintenance`, { maintenance });
      return res.data;
    },
    onMutate: async ({ unitId, maintenance }) => {
      await qc.cancelQueries({ queryKey: UNITS_KEY });
      const snapshots = snapshotUnits(qc);
      patchUnitInCache(qc, unitId, {
        status: maintenance ? 'maintenance' : 'idle',
        active_batch_id: maintenance ? null : undefined,
      });
      return { snapshots };
    },
    onError: (err, _vars, ctx) => {
      for (const [key, snap] of ctx?.snapshots ?? []) {
        qc.setQueryData(key, snap);
      }
      toast.error(err.errors[0]?.message ?? 'Failed to change unit status.');
    },
    onSettled: () => {
      // A unit in Maintenance should not surface in the "Processing
      // Drums" widget even if it had a leftover active_batch_id, so
      // always refresh the active-batches list alongside the units.
      invalidateFacilities(qc);
    },
    onSuccess: (d) => {
      toast.success(`Unit → ${d.status}.`);
    },
  });
}

// ------------------------------------------------------ Curing transition

/**
 * Move a batch from `awaiting_output` to `curing` — industry practice when
 * the drum has residue that needs a slow maturation phase (1–3 months at
 * lower monitoring frequency) before final QA / output. Mirrors the
 * `useFinishBatch` / `useCancelBatch` pattern: optimistic unit status
 * patch, rollback on error, reconcile via invalidate on settle.
 *
 * Note: the unit also flips to `curing` so the dashboard widget's join
 * (which filters by `curing` as an active status) still surfaces the row.
 */
export interface UseMoveToCuringVars {
  unitId: number;
  batchId: number;
  input?: MoveToCuringInput;
}

export function useMoveToCuring() {
  const qc = useQueryClient();
  return useMutation<BmgBatch, ApiEnvelopeError, UseMoveToCuringVars, UnitsMutationCtx>({
    mutationFn: async ({ batchId, input }) => {
      // The form may pass an empty object (no AIP snapshot) — Zod
      // makes every field optional so we just always parse.
      const valid = moveToCuringSchema.parse(input ?? {});
      const payload: Record<string, unknown> = {};
      if (valid.accumulated_in_process_kg !== undefined && valid.accumulated_in_process_kg !== '') {
        payload['accumulated_in_process_kg'] = valid.accumulated_in_process_kg;
      }
      const res = await apiClient.post<BmgBatch>(`/facilities/batches/${batchId}/curing`, payload);
      return bmgBatchSchema.parse(res.data);
    },
    onMutate: async ({ unitId }) => {
      await qc.cancelQueries({ queryKey: UNITS_KEY });
      const snapshots = snapshotUnits(qc);
      // Curing is an ACTIVE state — keep `active_batch_id` populated so
      // the "Processing Drums" widget still shows the drum.
      patchUnitInCache(qc, unitId, { status: 'curing' });
      return { snapshots };
    },
    onError: (err, _vars, ctx) => {
      for (const [key, snap] of ctx?.snapshots ?? []) {
        qc.setQueryData(key, snap);
      }
      toast.error(err.errors[0]?.message ?? 'Failed to move batch to curing.');
    },
    onSettled: (_d, _e, vars) => {
      invalidateFacilities(qc, vars.batchId);
    },
    onSuccess: () => {
      toast.success('Batch moved to curing.');
    },
  });
}

// ------------------------------------------------------ Batch inputs (feedstock)

/**
 * Record a feedstock input against a batch — required for mass-balance
 * analytics. Supports the Tier 2.1 characterisation columns (C:N,
 * bulk density, pH) as optional fields; empty strings are stripped so
 * the backend sees nulls (matches the existing `permit_empty` rule).
 */
export function useAddBatchInput() {
  const qc = useQueryClient();
  return useMutation<unknown, ApiEnvelopeError, { batchId: number; input: AddBatchInputInput }>({
    mutationFn: async ({ batchId, input }) => {
      const valid = addBatchInputSchema.parse(input);
      const payload: Record<string, unknown> = { weight_kg: valid.weight_kg };
      if (valid.cn_ratio !== undefined && valid.cn_ratio !== '') payload['cn_ratio'] = valid.cn_ratio;
      if (valid.bulk_density_kg_per_m3 !== undefined && valid.bulk_density_kg_per_m3 !== '') {
        payload['bulk_density_kg_per_m3'] = valid.bulk_density_kg_per_m3;
      }
      if (valid.ph !== undefined && valid.ph !== '') payload['ph'] = valid.ph;
      if (valid.note !== undefined && valid.note !== '') payload['note'] = valid.note;
      const res = await apiClient.post<unknown>(`/facilities/batches/${batchId}/inputs`, payload);
      return res.data;
    },
    onSuccess: (_d, vars) => {
      // Recording an input shifts the analytics; the active-batches
      // widget also re-evaluates expected completion.
      void qc.invalidateQueries({ queryKey: ['facilities', 'analytics', vars.batchId] });
      void qc.invalidateQueries({ queryKey: ACTIVE_BATCHES_KEY });
      toast.success('Input recorded.');
    },
    onError: (err) => {
      toast.error(err.errors[0]?.message ?? 'Failed to record input.');
    },
  });
}

// ------------------------------------------------------ Batch losses

/**
 * Record an in-process loss against a batch. The backend recomputes
 * `total_loss_kg` on the batch row and emits `bmg.loss_recorded` audit;
 * we only need to drop the analytics cache so the UI reflects the new
 * mass balance.
 */
export function useAddBatchLoss() {
  const qc = useQueryClient();
  return useMutation<unknown, ApiEnvelopeError, { batchId: number; input: AddBatchLossInput }>({
    mutationFn: async ({ batchId, input }) => {
      const valid = addBatchLossSchema.parse(input);
      const payload: Record<string, unknown> = {
        category_code: valid.category_code,
        weight_kg: valid.weight_kg,
      };
      if (valid.note !== undefined && valid.note !== '') payload['note'] = valid.note;
      const res = await apiClient.post<unknown>(`/facilities/batches/${batchId}/losses`, payload);
      return res.data;
    },
    onSuccess: (_d, vars) => {
      void qc.invalidateQueries({ queryKey: ['facilities', 'analytics', vars.batchId] });
      void qc.invalidateQueries({ queryKey: ACTIVE_BATCHES_KEY });
      toast.success('Loss recorded.');
    },
    onError: (err) => {
      toast.error(err.errors[0]?.message ?? 'Failed to record loss.');
    },
  });
}

// ------------------------------------------------------ Alerts

/**
 * Pull every alert (acknowledged or not) for a batch. Disabled until a
 * real batchId is available so the request doesn't fire on the dashboard
 * list view. The DrumDetailPage renders the unacknowledged subset as a
 * banner.
 */
export function useAlerts(batchId: number | null) {
  return useQuery<BmgAlert[], ApiEnvelopeError>({
    queryKey: ['facilities', 'alerts', batchId],
    queryFn: async () => {
      const res = await apiClient.get<unknown[]>(`/facilities/batches/${batchId}/alerts`);
      return z.array(bmgAlertSchema).parse(res.data);
    },
    enabled: batchId !== null && batchId > 0,
  });
}

/**
 * Acknowledge a single alert. Idempotent on the server (re-acking an
 * already-acknowledged alert returns the row without a duplicate audit
 * event) so the client can safely call this from a banner dismiss
 * without debouncing.
 */
export function useAcknowledgeAlert() {
  const qc = useQueryClient();
  return useMutation<BmgAlert, ApiEnvelopeError, { alertId: number; batchId: number }>({
    mutationFn: async ({ alertId }) => {
      const res = await apiClient.post<BmgAlert>(`/facilities/alerts/${alertId}/acknowledge`, {});
      return bmgAlertSchema.parse(res.data);
    },
    onSuccess: (_d, vars) => {
      // Patch just this alert inside the cached batch list — cheaper
      // than invalidating and avoids a network round-trip.
      qc.setQueriesData<BmgAlert[]>(
        { queryKey: ['facilities', 'alerts', vars.batchId] },
        (old) => {
          if (old === undefined) return old;
          return old.map((a) => (a.id === vars.alertId ? _d : a));
        },
      );
      toast.success('Alert acknowledged.');
    },
    onError: (err) => {
      toast.error(err.errors[0]?.message ?? 'Failed to acknowledge alert.');
    },
  });
}

// ------------------------------------------------------ Audit fixes

export interface UseReleaseBatchVars {
  unitId: number;
  batchId: number;
  input: ReleaseBatchInput;
}

/**
 * Release a finished/cured batch — the final QA gate (audit #4).
 * Terminal state; the unit returns to Idle.
 */
export function useReleaseBatch() {
  const qc = useQueryClient();
  return useMutation<BmgBatch, ApiEnvelopeError, UseReleaseBatchVars, UnitsMutationCtx>({
    mutationFn: async ({ batchId, input }) => {
      const valid = releaseBatchSchema.parse(input);
      const payload: Record<string, unknown> = {
        quality_grade: valid.quality_grade,
        maturity_level: valid.maturity_level,
      };
      if (valid.notes !== undefined && valid.notes !== '') payload['notes'] = valid.notes;
      const res = await apiClient.post<BmgBatch>(`/facilities/batches/${batchId}/release`, payload);
      return bmgBatchSchema.parse(res.data);
    },
    onMutate: async ({ unitId }) => {
      await qc.cancelQueries({ queryKey: UNITS_KEY });
      const snapshots = snapshotUnits(qc);
      patchUnitInCache(qc, unitId, { status: 'idle', active_batch_id: null });
      return { snapshots };
    },
    onError: (err, _vars, ctx) => {
      for (const [key, snap] of ctx?.snapshots ?? []) {
        qc.setQueryData(key, snap);
      }
      toast.error(err.errors[0]?.message ?? 'Failed to release batch.');
    },
    onSettled: (_d, _e, vars) => {
      invalidateFacilities(qc, vars.batchId);
    },
    onSuccess: () => {
      toast.success('Batch released.');
    },
  });
}

/** PFRP compliance / certificate data for a batch (audit #2). */
export function useBatchCompliance(batchId: number | null) {
  return useQuery<BatchCompliance, ApiEnvelopeError>({
    queryKey: ['facilities', 'compliance', batchId],
    queryFn: async () => {
      const res = await apiClient.get<unknown>(`/facilities/batches/${batchId}/compliance`);
      return batchComplianceSchema.parse(res.data);
    },
    enabled: batchId !== null && batchId > 0,
  });
}

/** Weighted feedstock C:N blend for a batch (audit #5). */
export function useBlendCn(batchId: number | null) {
  return useQuery<BlendCn, ApiEnvelopeError>({
    queryKey: ['facilities', 'blend-cn', batchId],
    queryFn: async () => {
      const res = await apiClient.get<unknown>(`/facilities/batches/${batchId}/blend-cn`);
      return blendCnSchema.parse(res.data);
    },
    enabled: batchId !== null && batchId > 0,
  });
}

/** Global open-alert feed — unacknowledged alerts across all batches. */
export function useOpenAlerts() {
  return useQuery<OpenAlert[], ApiEnvelopeError>({
    queryKey: ['facilities', 'alerts', 'open'],
    queryFn: async () => {
      const res = await apiClient.get<unknown[]>('/facilities/alerts/open');
      return z.array(openAlertSchema).parse(res.data);
    },
  });
}

/** Batch-history listing (terminal + historical batches). */
export function useBatchHistory(
  unitId: number | null,
  status: string | null,
  cursor: string | null,
  limit = 25,
) {
  return useQuery<{ data: BatchHistoryItem[]; next: string | null }, ApiEnvelopeError>({
    queryKey: ['facilities', 'batches', 'history', { unitId, status, cursor, limit }],
    queryFn: async () => {
      const params = new URLSearchParams();
      if (unitId !== null) params.set('unit_id', String(unitId));
      if (status !== null) params.set('status', status);
      if (cursor !== null) params.set('cursor', cursor);
      params.set('limit', String(limit));
      const res = await apiClient.get<{ data: BatchHistoryItem[]; next: string | null }>(
        `/facilities/batches?${params.toString()}`,
      );
      return {
        data: z.array(batchHistoryItemSchema).parse(res.data),
        next: res.data?.next ?? null,
      };
    },
  });
}

/** Suggested idle drum for a batch (audit #8). */
export function useSuggestUnit(categoryId: number | null) {
  return useQuery<BmgUnit | null, ApiEnvelopeError>({
    queryKey: ['facilities', 'units', 'suggest', categoryId],
    queryFn: async () => {
      if (categoryId === null || categoryId <= 0) return null;
      const params = new URLSearchParams({ category_id: String(categoryId) });
      const res = await apiClient.get<BmgUnit | null>(`/facilities/units/suggest?${params.toString()}`);
      if (res.data === null) return null;
      return bmgUnitSchema.parse(res.data);
    },
    enabled: categoryId !== null && categoryId > 0,
  });
}

// ------------------------------------------------------ SOP register

export function useSopDocuments(includeArchived = false) {
  return useQuery<SopDocument[], ApiEnvelopeError>({
    queryKey: ['facilities', 'sop-documents', { includeArchived }],
    queryFn: async () => {
      const res = await apiClient.get<unknown[]>(
        `/facilities/sop-documents${includeArchived ? '?include_archived=1' : ''}`,
      );
      return z.array(sopDocumentSchema).parse(res.data);
    },
  });
}

export function useCreateSopDocument() {
  const qc = useQueryClient();
  return useMutation<SopDocument, ApiEnvelopeError, CreateSopDocumentInput>({
    mutationFn: async (input) => {
      const valid = createSopDocumentSchema.parse(input);
      const payload: Record<string, unknown> = {
        title: valid.title,
        document_ref: valid.document_ref,
      };
      if (valid.category !== undefined && valid.category !== '') payload['category'] = valid.category;
      if (valid.version !== undefined && valid.version !== '') payload['version'] = valid.version;
      if (valid.owner_user_id !== undefined && valid.owner_user_id !== '') {
        payload['owner_user_id'] = Number(valid.owner_user_id);
      }
      if (valid.notes !== undefined && valid.notes !== '') payload['notes'] = valid.notes;
      const res = await apiClient.post<SopDocument>('/facilities/sop-documents', payload);
      return sopDocumentSchema.parse(res.data);
    },
    onSuccess: () => {
      void qc.invalidateQueries({ queryKey: ['facilities', 'sop-documents'] });
      toast.success('SOP document saved.');
    },
    onError: (err) => {
      toast.error(err.errors[0]?.message ?? 'Failed to save SOP document.');
    },
  });
}

export function useUpdateSopDocument() {
  const qc = useQueryClient();
  return useMutation<SopDocument, ApiEnvelopeError, { docId: number; input: Record<string, unknown> }>({
    mutationFn: async ({ docId, input }) => {
      const res = await apiClient.post<SopDocument>(`/facilities/sop-documents/${docId}`, input);
      return sopDocumentSchema.parse(res.data);
    },
    onSuccess: () => {
      void qc.invalidateQueries({ queryKey: ['facilities', 'sop-documents'] });
      toast.success('SOP document updated.');
    },
    onError: (err) => {
      toast.error(err.errors[0]?.message ?? 'Failed to update SOP document.');
    },
  });
}

// ------------------------------------------------------ Deviations

/** Actual vs expected yield/duration per waste category (audit #10). */
export function useWasteCategoryDeviation() {
  return useQuery<CategoryDeviation[], ApiEnvelopeError>({
    queryKey: ['facilities', 'waste-categories', 'deviation'],
    queryFn: async () => {
      const res = await apiClient.get<unknown[]>('/facilities/waste-categories/deviation');
      return z.array(categoryDeviationSchema).parse(res.data);
    },
  });
}