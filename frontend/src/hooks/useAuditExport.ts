/**
 * useAuditExport — POST-free streaming CSV download.
 *
 * Uses an authenticated axios instance with `responseType: 'blob'` so
 * the browser saves the file. Errors are normalised into the envelope
 * shape so the UI can toast them.
 */
import { useMutation } from '@tanstack/react-query';
import { toast } from 'sonner';
import axios from 'axios';
import { ApiErrorCode, humanizeCode, variantForCode } from '@/api/errorCodes';
import { useAuthStore } from '@/store/auth';

const API_BASE_URL = (import.meta.env.VITE_API_BASE_URL as string | undefined) ?? '/api/v1';

export function useAuditExport() {
  return useMutation<
    { filename: string; size: number },
    Error,
    { cursor?: string | null; limit?: number; action?: string; entity_type?: string }
  >({
    mutationFn: async ({ cursor, limit = 1000, action, entity_type }) => {
      const access = useAuthStore.getState().accessToken;
      const params = new URLSearchParams();
      if (cursor !== undefined && cursor !== null) params.set('cursor', cursor);
      params.set('limit', String(limit));
      // Mirror the list filters so a filtered on-screen view exports
      // the same slice (the backend accepts the same params).
      if (action !== undefined && action !== '') params.set('action', action);
      if (entity_type !== undefined && entity_type !== '') params.set('entity_type', entity_type);

      const res = await axios.get<Blob>(
        `${API_BASE_URL}/audit/export?${params.toString()}`,
        {
          withCredentials: true,
          responseType: 'blob',
          headers: {
            Authorization: access !== null ? `Bearer ${access}` : '',
            Accept: 'text/csv',
          },
        },
      );

      const filename =
        parseFilename(res.headers['content-disposition'] as string | undefined) ??
        `synapse-audit-${Date.now()}.csv`;
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
      // Best-effort: if the server returned a JSON envelope, surface the
      // friendly message; otherwise show the raw error.
      const code = (err as { errors?: { code: string }[] }).errors?.[0]?.code ?? ApiErrorCode.INTERNAL_ERROR;
      toast[variantForCode(code)](humanizeCode(code));
    },
  });
}

function parseFilename(value: string | undefined): string | null {
  if (value === undefined) return null;
  const match = /filename="?([^"]+)"?/i.exec(value);
  return match?.[1] ?? null;
}