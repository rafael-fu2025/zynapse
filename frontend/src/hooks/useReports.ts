import { keepPreviousData, useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import axios from 'axios';
import { toPng } from 'html-to-image';
import { jsPDF } from 'jspdf';
import { toast } from 'sonner';
import { apiClient } from '@/api/client';
import type { ApiEnvelope, ApiEnvelopeError } from '@/api/envelope';
import {
  clinicReportSchema,
  counsellingReportSchema,
  facilitiesReportSchema,
  generatedReportPageSchema,
  generatedReportSchema,
  inventoryForecastSchema,
  inventoryPurchasesSchema,
  inventoryReportSchema,
  referralReportSchema,
  reportConfigPageSchema,
  reportConfigSchema,
  reportNarrativeSchema,
  reportSummarySchema,
  type AnyReport,
  type ClinicReport,
  type CounsellingReport,
  type FacilitiesReport,
  type GeneratedReport,
  type GeneratedReportPage,
  type InventoryForecast,
  type InventoryPurchases,
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

export function useInventoryForecast(enabled = true) {
  return useQuery<InventoryForecast, ApiEnvelopeError>({
    queryKey: ['reports', 'inventory-forecast'],
    enabled,
    staleTime: 5 * 60_000,
    placeholderData: keepPreviousData,
    queryFn: async () => {
      const res = await apiClient.get<unknown>('/reports/inventory/forecast?within_days=90');
      return inventoryForecastSchema.parse(res.data);
    },
  });
}

export function useInventoryPurchases(start: string, end: string, enabled = true) {
  return useQuery<InventoryPurchases, ApiEnvelopeError>({
    queryKey: ['reports', 'inventory-purchases', { start, end }],
    enabled,
    staleTime: REPORT_STALE_MS,
    placeholderData: keepPreviousData,
    queryFn: async () => {
      const res = await apiClient.get<unknown>('/reports/inventory/purchases?' + rangeParams(start, end));
      return inventoryPurchasesSchema.parse(res.data);
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

/**
 * Fetch + validate one module's analytics payload on demand. The multi-
 * module PDF export needs modules whose dashboards were never opened —
 * their query hooks stay disabled until the tab is active.
 */
export async function fetchReportModule(module: ReportModule, start: string, end: string): Promise<AnyReport> {
  const res = await apiClient.get<unknown>('/reports/' + module + '?' + rangeParams(start, end));
  switch (module) {
    case 'clinic':
      return clinicReportSchema.parse(res.data);
    case 'counselling':
      return counsellingReportSchema.parse(res.data);
    case 'inventory':
      return inventoryReportSchema.parse(res.data);
    case 'referrals':
      return referralReportSchema.parse(res.data);
    case 'facilities':
      return facilitiesReportSchema.parse(res.data);
  }
  throw new Error('Unknown report module.');
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
  const filenamePattern = /filename="?([^"]+)"?/i;
  const match = disposition !== undefined ? disposition.match(filenamePattern) : null;
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
    { files: number },
    Error,
    { modules: ReportModule[]; start: string; end: string }
  >({
    mutationFn: async ({ modules, start, end }) => {
      // The export endpoint streams ONE module per request, so a
      // multi-module export means several sequential CSV downloads.
      let files = 0;
      for (const module of modules) {
        await download('/reports/export/' + module + '?' + rangeParams(start, end), 'synapse-report-' + module + '.csv');
        files += 1;
      }
      return { files };
    },
    onSuccess: ({ files }) => toast.success(files === 1 ? 'Exported 1 file.' : 'Exported ' + files + ' files.'),
    onError: (error) => toast.error(error.message),
  });
}

export type PdfExportResult = { filename: string; size: number; shared: boolean };
export type PdfExportJob = { module: ReportModule; data: AnyReport };

/**
 * useReportPdfExport — rasterizes the printable node (`ReportPdfView`)
 * once per selected module into ONE multi-page A4 PDF (each module starts
 * on its own page), downloads it, and additionally offers the native
 * share sheet when the browser supports it.
 *
 * Generation is fully client-side (html-to-image → jsPDF). The caller
 * supplies `prepare(job)` — which renders that module's view into the
 * off-screen capture node and resolves once committed — and `getNode()`
 * to read the node back; that keeps the React render cycle on the page.
 * The file is ALWAYS saved to disk via a download anchor first; when the
 * browser supports the Web Share API with files (navigator.share({files})),
 * the same file is then handed to the native share sheet. Cancelling the
 * share sheet keeps the download.
 */
export function useReportPdfExport() {
  return useMutation<
    PdfExportResult,
    Error,
    {
      modules: ReportModule[];
      start: string;
      end: string;
      prepare: (job: PdfExportJob) => Promise<void>;
      getNode: () => HTMLElement | null;
    }
  >({
    mutationFn: async ({ modules, start, end, prepare, getNode }) => {
      const filename = 'synapse-report-'
        + (modules.length === 1 ? modules[0] : 'full')
        + '-' + start + '_' + end + '.pdf';

      const jobs: PdfExportJob[] = await Promise.all(
        modules.map(async (module) => ({ module, data: await fetchReportModule(module, start, end) })),
      );

      // A4 portrait, 210×297mm, 10mm margins.
      const pageW = 210;
      const pageH = 297;
      const margin = 10;
      const contentW = pageW - margin * 2;
      const contentH = pageH - margin * 2;

      const doc = new jsPDF({ orientation: 'portrait', unit: 'mm', format: 'a4', compress: true });
      const canvas = document.createElement('canvas');
      const ctx = canvas.getContext('2d');
      if (ctx === null) throw new Error('Canvas is not available.');
      let pagesAdded = 0;

      for (const job of jobs) {
        await prepare(job);
        // Capture the ReportPdfView root (first child of the fixed wrapper),
        // NOT the wrapper itself — the wrapper carries `left: -20000px` and
        // html-to-image clones at that offset, producing a blank PDF. The
        // inner node has its own width (794px) and captures correctly with
        // the clone style override below.
        const node = getNode();
        if (node === null) throw new Error('Report is not ready to export yet.');

        // The `style` option repositions the CLONE to 0,0 while the source
        // stays off-screen — without it the clone's offset pushes the
        // content outside the capture viewport and produces a BLANK PDF.
        // Verified: corner pixel renders maroon instead of all-white.
        const dataUrl = await toPng(node, {
          pixelRatio: 2,
          backgroundColor: '#ffffff',
          cacheBust: true,
          style: { position: 'absolute', left: '0', top: '0', margin: '0' },
        });

        const image = new Image();
        image.src = dataUrl;
        await image.decode();
        const imgW = image.width;
        const imgH = image.height;
        if (imgW === 0 || imgH === 0) throw new Error('Failed to render the report PDF.');

        // Scale the captured image to the printable width, then compute pages.
        const drawH = contentW * (imgH / imgW);
        const pages = Math.max(1, Math.ceil(drawH / contentH));
        for (let page = 0; page < pages; page++) {
          if (pagesAdded > 0) doc.addPage();
          const sliceHpx = Math.ceil((imgH * contentH) / drawH);
          const srcY = page * sliceHpx;
          const actualSlice = Math.min(sliceHpx, imgH - srcY);
          if (actualSlice > 0) {
            canvas.width = imgW;
            canvas.height = actualSlice;
            ctx.fillStyle = '#ffffff';
            ctx.fillRect(0, 0, canvas.width, canvas.height);
            ctx.drawImage(image, 0, srcY, imgW, actualSlice, 0, 0, imgW, actualSlice);
            const pageDataUrl = canvas.toDataURL('image/png');
            doc.addImage(
              pageDataUrl,
              'PNG',
              margin,
              margin,
              contentW,
              contentW * (actualSlice / imgW),
              undefined,
              'FAST',
            );
          }
          pagesAdded += 1;
        }
      }
      canvas.width = 0;
      canvas.height = 0;

      // Institutional footer + page numbers, drawn as real PDF text so it
      // stays crisp on every page (the body itself is a raster capture).
      const pageCount = doc.getNumberOfPages();
      doc.setFontSize(8);
      doc.setTextColor(107, 101, 98);
      for (let page = 1; page <= pageCount; page++) {
        doc.setPage(page);
        doc.text('Foundation University · SYNAPSE Reports & Analytics', margin, pageH - 4);
        doc.text('Page ' + page + ' of ' + pageCount, pageW - margin, pageH - 4, { align: 'right' });
      }

      const blob = doc.output('blob');
      const file = new File([blob], filename, { type: 'application/pdf' });

      // Download first so the file is on disk regardless of what the share
      // sheet does afterwards.
      const url = URL.createObjectURL(blob);
      const anchor = document.createElement('a');
      anchor.href = url;
      anchor.download = filename;
      document.body.appendChild(anchor);
      anchor.click();
      anchor.remove();
      URL.revokeObjectURL(url);

      // Share is best-effort on top of the download: a dismissed sheet
      // (AbortError) or a share failure must not surface as an error toast.
      let shared = false;
      const nav = navigator as Navigator & { canShare?: (data?: ShareData) => boolean };
      const shareData = { files: [file], title: filename };
      if (typeof nav.canShare === 'function' && nav.canShare(shareData)) {
        try {
          await nav.share(shareData);
          shared = true;
        } catch {
          // Share cancelled or failed — the download already succeeded.
        }
      }
      return { filename, size: blob.size, shared };
    },
    onSuccess: ({ filename, shared }) =>
      toast.success(shared ? 'Downloaded ' + filename + ' · share sheet opened.' : 'Downloaded ' + filename + '.'),
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
