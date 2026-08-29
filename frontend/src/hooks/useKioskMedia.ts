import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { toast } from 'sonner';
import { apiClient } from '@/api/client';
import { ApiEnvelopeError } from '@/api/envelope';
import { kioskMediaAssetSchema, kioskMediaPageSchema, type KioskMediaAsset, type KioskMediaPage } from '@/schemas/kioskMedia';

export interface KioskMediaUploadProgress {
  loaded: number;
  total: number;
  percent: number;
  phase: 'uploading' | 'processing';
  bytesPerSecond: number | null;
  estimatedSeconds: number | null;
  fileIndex: number;
  fileCount: number;
  fileName: string;
}

export interface KioskMediaUploadBatchResult {
  assets: KioskMediaAsset[];
  failed: Array<{ fileName: string; message: string }>;
}

const configuredUploadBaseUrl = import.meta.env.VITE_KIOSK_UPLOAD_BASE_URL as string | undefined;
const defaultApiBaseUrl = (import.meta.env.VITE_API_BASE_URL as string | undefined) ?? '/api/v1';
const developmentBackendOrigin = (import.meta.env.VITE_API_PROXY_TARGET as string | undefined) ?? 'http://localhost:8090';
const developmentBackendPrefix = (import.meta.env.VITE_API_PROXY_PREFIX as string | undefined) ?? '';
const kioskUploadBaseUrl = configuredUploadBaseUrl
  ?? (import.meta.env.DEV ? `${developmentBackendOrigin}${developmentBackendPrefix}/api/v1` : defaultApiBaseUrl);

function kioskUploadUrl(): string {
  return `${kioskUploadBaseUrl.replace(/\/$/, '')}/admin/kiosk-media`;
}

function decodeApiPayload(value: unknown): unknown {
  if (typeof value === 'string') {
    try {
      return decodeApiPayload(JSON.parse(value) as unknown);
    } catch {
      return value;
    }
  }
  if (typeof value === 'object' && value !== null && 'success' in value && 'data' in value) {
    return decodeApiPayload((value as { data: unknown }).data);
  }
  return value;
}

export function useKioskMedia(includeArchived: boolean, search: string) {
  return useQuery<KioskMediaPage, ApiEnvelopeError>({
    queryKey: ['admin', 'kiosk-media', { includeArchived, search }],
    queryFn: async () => {
      const params = new URLSearchParams({ limit: '100' });
      if (includeArchived) params.set('include_archived', 'true');
      if (search.trim() !== '') params.set('search', search.trim());
      const response = await apiClient.get<unknown>(`/admin/kiosk-media?${params.toString()}`);
      return kioskMediaPageSchema.parse(decodeApiPayload(response.data));
    },
  });
}

export function useUploadKioskMedia() {
  const client = useQueryClient();
  return useMutation<KioskMediaUploadBatchResult, ApiEnvelopeError, { files: File[]; label: string; onProgress?: (progress: KioskMediaUploadProgress) => void }>({
    mutationFn: async ({ files, label, onProgress }) => {
      const assets: KioskMediaAsset[] = [];
      const failed: KioskMediaUploadBatchResult['failed'] = [];
      const totalBytes = Math.max(1, files.reduce((sum, file) => sum + file.size, 0));
      let completedBytes = 0;
      let firstError: ApiEnvelopeError | null = null;

      for (const [index, file] of files.entries()) {
        try {
          const form = new FormData();
          form.append('file', file);
          if (files.length === 1 && label.trim() !== '') form.append('label', label.trim());
          const response = await apiClient.post<unknown>(kioskUploadUrl(), form, {
            timeout: 0,
            onUploadProgress: (event) => {
              const fileTotal = Math.max(1, event.total ?? file.size);
              const fileLoaded = Math.min(event.loaded, fileTotal);
              const loaded = Math.min(totalBytes, completedBytes + fileLoaded);
              onProgress?.({
                loaded,
                total: totalBytes,
                percent: Math.min(100, Math.round((loaded / totalBytes) * 100)),
                phase: fileLoaded >= fileTotal ? 'processing' : 'uploading',
                bytesPerSecond: typeof event.rate === 'number' && event.rate > 0 ? event.rate : null,
                estimatedSeconds: typeof event.estimated === 'number' && event.estimated >= 0 ? event.estimated : null,
                fileIndex: index,
                fileCount: files.length,
                fileName: file.name,
              });
            },
          });
          const parsed = kioskMediaAssetSchema.safeParse(decodeApiPayload(response.data));
          if (parsed.success) {
            assets.push(parsed.data);
          } else {
            const galleryResponse = await apiClient.get<unknown>('/admin/kiosk-media?limit=100');
            const gallery = kioskMediaPageSchema.safeParse(decodeApiPayload(galleryResponse.data));
            const recovered = gallery.success
              ? gallery.data.items.find((asset) => asset.original_name === file.name && asset.size_bytes === file.size)
              : undefined;
            if (recovered !== undefined) assets.push(recovered);
            else throw new ApiEnvelopeError(502, [{ code: 'response.invalid', message: 'The upload response was incomplete. Refresh the gallery to check whether the file was saved.' }]);
          }
        } catch (error) {
          const normalized = error instanceof ApiEnvelopeError ? error : new ApiEnvelopeError(0, [{ code: 'upload.failed', message: 'Upload failed.' }]);
          firstError ??= normalized;
          failed.push({ fileName: file.name, message: normalized.errors[0]?.message ?? 'Upload failed.' });
        } finally {
          completedBytes += file.size;
        }
      }
      if (assets.length === 0 && firstError !== null) throw firstError;
      return { assets, failed };
    },
    onSuccess: async ({ assets, failed }) => {
      client.setQueryData<KioskMediaPage>(
        ['admin', 'kiosk-media', { includeArchived: false, search: '' }],
        (current) => {
          if (current === undefined) return { items: assets, page: 1, limit: 100, total: assets.length };
          const newIds = new Set(assets.map((asset) => asset.id));
          const addedCount = assets.filter((asset) => !current.items.some((item) => item.id === asset.id)).length;
          return {
            ...current,
            items: [...assets, ...current.items.filter((item) => !newIds.has(item.id))].slice(0, current.limit),
            total: current.total + addedCount,
          };
        },
      );
      await client.invalidateQueries({ queryKey: ['admin', 'kiosk-media'] });
      if (failed.length > 0) toast.warning(`${assets.length} uploaded; ${failed.length} failed. ${failed.map((item) => item.fileName).join(', ')}`);
      else toast.success(`${assets.length} media file${assets.length === 1 ? '' : 's'} uploaded to the gallery.`);
    },
    onError: (error) => toast.error(error.errors[0]?.message ?? 'Media upload failed.'),
  });
}

export function useSetKioskMediaArchived() {
  const client = useQueryClient();
  return useMutation<KioskMediaAsset, ApiEnvelopeError, { asset: KioskMediaAsset; archived: boolean }>({
    mutationFn: async ({ asset, archived }) => {
      const action = archived ? 'archive' : 'unarchive';
      const response = await apiClient.post<unknown>(`/admin/kiosk-media/${asset.id}/${action}`);
      return kioskMediaAssetSchema.parse(response.data);
    },
    onSuccess: async (asset) => {
      await client.invalidateQueries({ queryKey: ['admin', 'kiosk-media'] });
      toast.success(asset.archived ? 'Media archived.' : 'Media restored.');
    },
    onError: (error) => toast.error(error.errors[0]?.message ?? 'Could not update media.'),
  });
}
