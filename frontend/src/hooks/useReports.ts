import { keepPreviousData, useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import axios from 'axios';
import { toast } from 'sonner';
import { apiClient } from '@/api/client';
import type { ApiEnvelope, ApiEnvelopeError } from '@/api/envelope';
import {
  clinicReportSchema,
  counsellingReportSchema,
  facilitiesReportSchema,
  generatedReportPageSchema,
  generatedReportSchema,
  inventoryReportSchema,
  referralReportSchema,
  reportConfigPageSchema,
  reportConfigSchema,
  reportNarrativeSchema,
  reportSummarySchema,
  type ClinicReport,
  type CounsellingReport,
  type FacilitiesReport,
  type GeneratedReport,
  type GeneratedReportPage,
  type InventoryReport,
  type ReferralReport,
  type ReportConfig,
  type ReportConfigPage,
  type ReportModule,
  type ReportNarrative,
  type ReportSummary,
} from '@/schemas/reports';
import { useAuthStore } from '@/store/auth';

const API_BASE_URL = (import.meta.env.VITE_API_BASE_URL as string | undefined) ?? '/api/v1';
const REPORT_STALE_MS = 30_000;

function rangeParams(start: string, end: string): string {
  return new URLSearchParams({ start, end }).toString();
}

function queryPath(path: string, params: URLSearchParams): string {
  const query = params.toString();
  return query === '' ? path : path + '?' + query;
}

export function useReportSummary(start: string, end: string, enabled = true) {
  return useQuery<ReportSummary, ApiEnvelopeError>({
    queryKey: ['reports', 'summary', { start, end }],
    enabled,
    staleTime: REPORT_STALE_MS,
    placeholderData: keepPreviousData,
    queryFn: async () => {
      const res = await apiClient.get<unknown>('/reports/summary?' + rangeParams(start, end));
      return reportSummarySchema.parse(res.data);
    },
  });
}

export function useClinicReport(start: string, end: string, enabled = true) {
  return useQuery<ClinicReport, ApiEnvelopeError>({
    queryKey: ['reports', 'clinic', { start, end }],
    enabled,
    staleTime: REPORT_STALE_MS,
    placeholderData: keepPreviousData,
    queryFn: async () => {
      const res = await apiClient.get<unknown>('/reports/clinic?' + rangeParams(start, end));
      return clinicReportSchema.parse(res.data);
    },
  });
}

export function useCounsellingReport(start: string, end: string, enabled = true) {
  return useQuery<CounsellingReport, ApiEnvelopeError>({
    queryKey: ['reports', 'counselling', { start, end }],
    enabled,
    staleTime: REPORT_STALE_MS,
    placeholderData: keepPreviousData,
    queryFn: async () => {
      const res = await apiClient.get<unknown>('/reports/counselling?' + rangeParams(start, end));
      return counsellingReportSchema.parse(res.data);
    },
  });
}

export function useInventoryReport(start: string, end: string, enabled = true) {
  return useQuery<InventoryReport, ApiEnvelopeError>({
    queryKey: ['reports', 'inventory', { start, end }],
    enabled,
    staleTime: REPORT_STALE_MS,
    placeholderData: keepPreviousData,
    queryFn: async () => {
      const res = await apiClient.get<unknown>('/reports/inventory?' + rangeParams(start, end));
      return inventoryReportSchema.parse(res.data);
    },
  });
}

export function useReferralReport(start: string, end: string, enabled = true) {
  return useQuery<ReferralReport, ApiEnvelopeError>({
    queryKey: ['reports', 'referrals', { start, end }],
    enabled,
    staleTime: REPORT_STALE_MS,
    placeholderData: keepPreviousData,
    queryFn: async () => {
      const res = await apiClient.get<unknown>('/reports/referrals?' + rangeParams(start, end));
      return referralReportSchema.parse(res.data);
    },
  });
}

export function useFacilitiesReport(start: string, end: string, enabled = true) {
  return useQuery<FacilitiesReport, ApiEnvelopeError>({
    queryKey: ['reports', 'facilities', { start, end }],
    enabled,
    staleTime: REPORT_STALE_MS,
    placeholderData: keepPreviousData,
    queryFn: async () => {
      const res = await apiClient.get<unknown>('/reports/facilities?' + rangeParams(start, end));
      return facilitiesReportSchema.parse(res.data);
    },
  });
}

export function useReportNarrative() {
  return useMutation<ReportNarrative, ApiEnvelopeError, { module: ReportModule; start: string; end: string }>({
    mutationFn: async ({ module, start, end }) => {
      const res = await apiClient.post<unknown>('/reports/narratives/' + module, { start, end });
      return reportNarrativeSchema.parse(res.data);
    },
    onError: (error) => toast.error(error.errors[0]?.message ?? 'Failed to generate summary.'),
  });
}

async function parseBlobError(error: unknown): Promise<Error> {
  if (!axios.isAxiosError<Blob>(error)) {
    return error instanceof Error ? error : new Error('Download failed.');
  }
  const body: Blob | undefined = error.response?.data;
  if (body instanceof Blob) {
    try {
      const parsed = JSON.parse(await body.text()) as ApiEnvelope<unknown>;
      const message = parsed.errors?.[0]?.message;
      if (message !== undefined) return new Error(message);
    } catch {
      // The response was not a JSON API envelope.
    }
  }
  return new Error(error.message || 'Download failed.');
}

function saveBlob(blob: Blob, disposition: string | undefined, fallback: string): { filename: string; size: number } {
  const match = disposition !== undefined ? /filename="?([^"]+)"?/i.exec(disposition) : null;
  const filename = match?.[1] ?? fallback;
  const url = URL.createObjectURL(blob);
  const anchor = document.createElement('a');
  anchor.href = url;
  anchor.download = filename;
  document.body.appendChild(anchor);
  anchor.click();
  anchor.remove();
  URL.revokeObjectURL(url);
  return { filename, size: blob.size };
}

async function download(path: string, fallback: string): Promise<{ filename: string; size: number }> {
  const access = useAuthStore.getState().accessToken;
  try {
    const response = await axios.get<Blob>(API_BASE_URL + path, {
      withCredentials: true,
      responseType: 'blob',
      headers: {
        Authorization: access !== null ? 'Bearer ' + access : '',
        Accept: 'text/csv',
      },
    });
    return saveBlob(response.data, response.headers['content-disposition'] as string | undefined, fallback);
  } catch (error) {
    throw await parseBlobError(error);
  }
}

export function useReportExport() {
  return useMutation<
    { filename: string; size: number },
    Error,
    { module: ReportModule; start: string; end: string }
  >({
    mutationFn: ({ module, start, end }) =>
      download('/reports/export/' + module + '?' + rangeParams(start, end), 'synapse-report-' + module + '.csv'),
    onSuccess: ({ filename }) => toast.success('Exported ' + filename + '.'),
    onError: (error) => toast.error(error.message),
  });
}

export function useReportConfigs(page: number, includeArchived = false, module?: ReportModule) {
  return useQuery<ReportConfigPage, ApiEnvelopeError>({
    queryKey: ['reports', 'configs', { page, includeArchived, module }],
    placeholderData: keepPreviousData,
    queryFn: async () => {
      const params = new URLSearchParams({ page: String(page), limit: '10' });
      if (includeArchived) params.set('include_archived', '1');
      if (module !== undefined) params.set('module', module);
      const res = await apiClient.get<unknown>(queryPath('/reports/configs', params));
      return reportConfigPageSchema.parse(res.data);
    },
  });
}

type ConfigInput = {
  name: string;
  module: ReportModule;
  start: string;
  end: string;
  summarize: boolean;
};

function configPayload(input: ConfigInput) {
  return {
    name: input.name,
    module: input.module,
    parameters: {
      range_mode: 'fixed',
      start: input.start,
      end: input.end,
      summarize: input.summarize,
    },
  };
}

export function useCreateReportConfig() {
  const queryClient = useQueryClient();
  return useMutation<ReportConfig, ApiEnvelopeError, ConfigInput>({
    mutationFn: async (input) => {
      const res = await apiClient.post<unknown>('/reports/configs', configPayload(input));
      return reportConfigSchema.parse(res.data);
    },
    onSuccess: () => {
      void queryClient.invalidateQueries({ queryKey: ['reports', 'configs'] });
      toast.success('Report configuration saved.');
    },
    onError: (error) => toast.error(error.errors[0]?.message ?? 'Failed to save configuration.'),
  });
}

export function useUpdateReportConfig() {
  const queryClient = useQueryClient();
  return useMutation<ReportConfig, ApiEnvelopeError, ConfigInput & { id: number }>({
    mutationFn: async ({ id, ...input }) => {
      const res = await apiClient.post<unknown>('/reports/configs/' + id, configPayload(input));
      return reportConfigSchema.parse(res.data);
    },
    onSuccess: () => {
      void queryClient.invalidateQueries({ queryKey: ['reports', 'configs'] });
      toast.success('Report configuration updated.');
    },
    onError: (error) => toast.error(error.errors[0]?.message ?? 'Failed to update configuration.'),
  });
}

export function useRunReportConfig() {
  const queryClient = useQueryClient();
  return useMutation<GeneratedReport, ApiEnvelopeError, number>({
    mutationFn: async (configId) => {
      const res = await apiClient.post<unknown>('/reports/configs/' + configId + '/run', {});
      return generatedReportSchema.parse(res.data);
    },
    onSuccess: () => {
      void queryClient.invalidateQueries({ queryKey: ['reports', 'generated'] });
      toast.success('Report queued for generation.');
    },
    onError: (error) => toast.error(error.errors[0]?.message ?? 'Failed to queue report.'),
  });
}

export function useGeneratedReports(
  page: number,
  module?: ReportModule,
  status?: GeneratedReport['status'],
) {
  return useQuery<GeneratedReportPage, ApiEnvelopeError>({
    queryKey: ['reports', 'generated', { page, module, status }],
    placeholderData: keepPreviousData,
    queryFn: async () => {
      const params = new URLSearchParams({ page: String(page), limit: '10' });
      if (module !== undefined) params.set('module', module);
      if (status !== undefined) params.set('status', status);
      const res = await apiClient.get<unknown>(queryPath('/reports/generated', params));
      return generatedReportPageSchema.parse(res.data);
    },
    refetchInterval: (query) => {
      const data = query.state.data;
      return data?.items.some((item) => item.status === 'queued' || item.status === 'processing') === true
        ? 3_000
        : false;
    },
  });
}

function configAction(path: (id: number) => string, success: string) {
  return function useConfigAction() {
    const queryClient = useQueryClient();
    return useMutation<void, ApiEnvelopeError, number>({
      mutationFn: async (id) => {
        await apiClient.post(path(id), {});
      },
      onSuccess: () => {
        void queryClient.invalidateQueries({ queryKey: ['reports', 'configs'] });
        toast.success(success);
      },
      onError: (error) => toast.error(error.errors[0]?.message ?? 'Report action failed.'),
    });
  };
}

export const useArchiveReportConfig = configAction(
  (id) => '/reports/configs/' + id + '/archive',
  'Configuration archived.',
);

export function useUnarchiveReportConfig() {
  const queryClient = useQueryClient();
  return useMutation<ReportConfig, ApiEnvelopeError, number>({
    mutationFn: async (id) => {
      const res = await apiClient.post<unknown>('/reports/configs/' + id + '/unarchive', {});
      return reportConfigSchema.parse(res.data);
    },
    onSuccess: (config) => {
      void queryClient.invalidateQueries({ queryKey: ['reports', 'configs'] });
      toast.success(config.name + ' restored.');
    },
    onError: (error) => toast.error(error.errors[0]?.message ?? 'Failed to restore configuration.'),
  });
}

export function useDownloadGeneratedReport() {
  return useMutation<{ filename: string; size: number }, Error, number>({
    mutationFn: (id) => download('/reports/generated/' + id + '/download', 'synapse-report-' + id + '.csv'),
    onSuccess: ({ filename }) => toast.success('Downloaded ' + filename + '.'),
    onError: (error) => toast.error(error.message),
  });
}
