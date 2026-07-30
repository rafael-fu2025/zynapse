/**
 * Reports hooks (Phase 18) — read-only analytics + audited CSV export.
 */
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import axios from 'axios';
import { z } from 'zod';
import { toast } from 'sonner';
import { apiClient } from '@/api/client';
import type { ApiEnvelopeError } from '@/api/envelope';
import { ApiErrorCode, humanizeCode, variantForCode } from '@/api/errorCodes';
import {
  clinicReportSchema,
  counsellingReportSchema,
  generatedReportSchema,
  inventoryReportSchema,
  reportConfigSchema,
  reportSummarySchema,
  type ClinicReport,
  type CounsellingReport,
  type GeneratedReport,
  type InventoryReport,
  type ReportConfig,
  type ReportModule,
  type ReportSummary,
} from '@/schemas/reports';
import { useAuthStore } from '@/store/auth';

const API_BASE_URL = (import.meta.env.VITE_API_BASE_URL as string | undefined) ?? '/api/v1';

function rangeParams(start: string, end: string): string {
  const params = new URLSearchParams();
  if (start !== '') params.set('start', start);
  if (end !== '') params.set('end', end);
  return params.toString();
}

export function useReportSummary(start: string, end: string) {
  return useQuery<ReportSummary, ApiEnvelopeError>({
    queryKey: ['reports', 'summary', { start, end }],
    queryFn: async () => {
      const res = await apiClient.get<unknown>(`/reports/summary?${rangeParams(start, end)}`);
      return reportSummarySchema.parse(res.data);
    },
  });
}

export function useClinicReport(start: string, end: string) {
  return useQuery<ClinicReport, ApiEnvelopeError>({
    queryKey: ['reports', 'clinic', { start, end }],
    queryFn: async () => {
      const res = await apiClient.get<unknown>(`/reports/clinic?${rangeParams(start, end)}`);
      return clinicReportSchema.parse(res.data);
    },
  });
}

export function useCounsellingReport(start: string, end: string) {
  return useQuery<CounsellingReport, ApiEnvelopeError>({
    queryKey: ['reports', 'counselling', { start, end }],
    queryFn: async () => {
      const res = await apiClient.get<unknown>(`/reports/counselling?${rangeParams(start, end)}`);
      return counsellingReportSchema.parse(res.data);
    },
  });
}

export function useInventoryReport(start: string, end: string) {
  return useQuery<InventoryReport, ApiEnvelopeError>({
    queryKey: ['reports', 'inventory', { start, end }],
    queryFn: async () => {
      const res = await apiClient.get<unknown>(`/reports/inventory?${rangeParams(start, end)}`);
      return inventoryReportSchema.parse(res.data);
    },
  });
}

/** Generate a deterministic template-NLG narrative for a module report. */
export function useReportNarrative() {
  return useMutation<string, ApiEnvelopeError, { module: ReportModule; start: string; end: string }>({
    mutationFn: async ({ module, start, end }) => {
      const params = rangeParams(start, end);
      const res = await apiClient.get<{ narrative?: string }>(
        `/reports/${module}?${params}${params !== '' ? '&' : ''}summarize=1`,
      );
      return res.data.narrative ?? '';
    },
    onError: (err) => {
      toast.error(err.errors[0]?.message ?? 'Failed to generate summary.');
    },
  });
}

/** Blob CSV download — same pattern as useAuditExport. */
export function useReportExport() {
  return useMutation<{ filename: string; size: number }, Error, { module: ReportModule; start: string; end: string }>({
    mutationFn: async ({ module, start, end }) => {
      const access = useAuthStore.getState().accessToken;
      const res = await axios.get<Blob>(
        `${API_BASE_URL}/reports/export/${module}?${rangeParams(start, end)}`,
        {
          withCredentials: true,
          responseType: 'blob',
          headers: {
            Authorization: access !== null ? `Bearer ${access}` : '',
            Accept: 'text/csv',
          },
        },
      );

      const disposition = res.headers['content-disposition'] as string | undefined;
      const match = disposition !== undefined ? /filename="?([^"]+)"?/i.exec(disposition) : null;
      const filename = match?.[1] ?? `synapse-report-${module}-${Date.now()}.csv`;
      const url = URL.createObjectURL(res.data);
      const a = document.createElement('a');
      a.href = url;
      a.download = filename;
      document.body.appendChild(a);
      a.click();
      a.remove();
      URL.revokeObjectURL(url);

      return { filename, size: res.data.size };
    },
    onSuccess: (info) => {
      toast.success(`Exported ${info.filename} (${info.size} bytes).`);
    },
    onError: (err) => {
      const code = (err as { errors?: { code: string }[] }).errors?.[0]?.code ?? ApiErrorCode.INTERNAL_ERROR;
      toast[variantForCode(code)](humanizeCode(code));
    },
  });
}

// Saved & generated reports (Phase P6).

export function useReportConfigs(includeArchived = false) {
  return useQuery<ReportConfig[], ApiEnvelopeError>({
    queryKey: ['reports', 'configs', { includeArchived }],
    queryFn: async () => {
      const res = await apiClient.get<unknown[]>(
        `/reports/configs${includeArchived ? '?include_archived=1' : ''}`,
      );
      return z.array(reportConfigSchema).parse(res.data);
    },
  });
}

export function useCreateReportConfig() {
  const qc = useQueryClient();
  return useMutation<ReportConfig, ApiEnvelopeError, { name: string; module: ReportModule; summarize: boolean }>({
    mutationFn: async ({ name, module, summarize }) => {
      const res = await apiClient.post<unknown>('/reports/configs', { name, module, parameters: { summarize } });
      return reportConfigSchema.parse(res.data);
    },
    onSuccess: () => {
      void qc.invalidateQueries({ queryKey: ['reports', 'configs'] });
      toast.success('Report configuration saved.');
    },
    onError: (err) => {
      toast.error(err.errors[0]?.message ?? 'Failed to save configuration.');
    },
  });
}

export function useRunReportConfig() {
  const qc = useQueryClient();
  return useMutation<GeneratedReport, ApiEnvelopeError, number>({
    mutationFn: async (configId) => {
      const res = await apiClient.post<unknown>(`/reports/configs/${configId}/run`, {});
      return generatedReportSchema.parse(res.data);
    },
    onSuccess: (g) => {
      void qc.invalidateQueries({ queryKey: ['reports', 'generated'] });
      toast.success(`Report generated (${g.row_count ?? 0} rows).`);
    },
    onError: (err) => {
      toast.error(err.errors[0]?.message ?? 'Failed to run report.');
    },
  });
}

export function useGeneratedReports() {
  return useQuery<GeneratedReport[], ApiEnvelopeError>({
    queryKey: ['reports', 'generated'],
    queryFn: async () => {
      const res = await apiClient.get<unknown[]>('/reports/generated');
      return z.array(generatedReportSchema).parse(res.data);
    },
  });
}

export function useArchiveReportConfig() {
  const qc = useQueryClient();
  return useMutation<void, ApiEnvelopeError, number>({
    mutationFn: async (id) => {
      await apiClient.post(`/reports/configs/${id}/archive`, {});
    },
    onSuccess: () => {
      void qc.invalidateQueries({ queryKey: ['reports', 'configs'] });
      toast.success('Configuration archived.');
    },
    onError: (err) => {
      toast.error(err.errors[0]?.message ?? 'Failed to archive configuration.');
    },
  });
}

/** Restore an archived report configuration back into the saved list. */
export function useUnarchiveReportConfig() {
  const qc = useQueryClient();
  return useMutation<ReportConfig, ApiEnvelopeError, number>({
    mutationFn: async (id) => {
      const res = await apiClient.post<unknown>(`/reports/configs/${id}/unarchive`, {});
      return reportConfigSchema.parse(res.data);
    },
    onSuccess: (c) => {
      void qc.invalidateQueries({ queryKey: ['reports', 'configs'] });
      toast.success(`“${c.name}” restored.`);
    },
    onError: (err) => {
      toast.error(err.errors[0]?.message ?? 'Failed to restore configuration.');
    },
  });
}

export function useDownloadGeneratedReport() {
  return useMutation<{ filename: string; size: number }, Error, number>({
    mutationFn: async (id) => {
      const access = useAuthStore.getState().accessToken;
      const res = await axios.get<Blob>(`${API_BASE_URL}/reports/generated/${id}/download`, {
        withCredentials: true,
        responseType: 'blob',
        headers: {
          Authorization: access !== null ? `Bearer ${access}` : '',
          Accept: 'text/csv',
        },
      });

      const disposition = res.headers['content-disposition'] as string | undefined;
      const match = disposition !== undefined ? /filename="?([^"]+)"?/i.exec(disposition) : null;
      const filename = match?.[1] ?? `synapse-report-${id}.csv`;
      const url = URL.createObjectURL(res.data);
      const a = document.createElement('a');
      a.href = url;
      a.download = filename;
      document.body.appendChild(a);
      a.click();
      a.remove();
      URL.revokeObjectURL(url);

      return { filename, size: res.data.size };
    },
    onSuccess: (info) => {
      toast.success(`Downloaded ${info.filename}.`);
    },
    onError: (err) => {
      const code = (err as { errors?: { code: string }[] }).errors?.[0]?.code ?? ApiErrorCode.INTERNAL_ERROR;
      toast[variantForCode(code)](humanizeCode(code));
    },
  });
}
