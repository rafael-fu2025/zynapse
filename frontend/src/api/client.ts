/**
 * Axios client with envelope normalization, silent refresh on 401, and
 * request-id propagation. The access token lives in MEMORY only — never
 * in localStorage. Refresh tokens are HttpOnly cookies set by the backend.
 */
import type {
  AxiosError,
  AxiosResponse,
} from 'axios';
import axios, {
  type AxiosInstance,
  type InternalAxiosRequestConfig,
} from 'axios';
import { useAuthStore } from '@/store/auth';
import { ApiEnvelopeError, type ApiEnvelope } from './envelope';

const API_BASE_URL = (import.meta.env.VITE_API_BASE_URL as string | undefined) ?? '/api/v1';

interface RetryConfig extends InternalAxiosRequestConfig {
  _synapseRetried?: boolean;
}

interface NormalizedResponse extends AxiosResponse {
  synapseMeta?: ApiEnvelope<unknown>['meta'];
}

/** Read pagination metadata retained by the response normalizer. */
export function getNextCursor(response: AxiosResponse): string | null {
  return (response as NormalizedResponse).synapseMeta?.pagination?.next_cursor ?? null;
}

let inflightRefresh: Promise<string | null> | null = null;

async function refreshAccessToken(): Promise<string | null> {
  if (inflightRefresh !== null) return inflightRefresh;

  inflightRefresh = axios
    .post<ApiEnvelope<{ access_token: string; expires_in: number }>>(
      `${API_BASE_URL}/auth/refresh`,
      {},
      { withCredentials: true },
    )
    .then((res) => {
      const access = res.data?.data?.access_token ?? null;
      if (access !== null) useAuthStore.getState().setAccessToken(access);
      return access;
    })
    .catch(() => {
      useAuthStore.getState().clear();
      return null;
    })
    .finally(() => {
      inflightRefresh = null;
    });

  return inflightRefresh;
}

export const apiClient: AxiosInstance = axios.create({
  baseURL: API_BASE_URL,
  withCredentials: true, // HttpOnly refresh cookie
  timeout: 15_000,
  headers: { 'Content-Type': 'application/json', Accept: 'application/json' },
});

apiClient.interceptors.request.use((config) => {
  const access = useAuthStore.getState().accessToken;
  if (access !== null) {
    config.headers.set('Authorization', `Bearer ${access}`);
  }
  // Per-request id lets the backend log lines correlate with the SPA.
  config.headers.set('X-Request-Id', crypto.randomUUID());
  return config;
});

apiClient.interceptors.response.use(
  (response) => {
    const envelope = response.data as ApiEnvelope<unknown> | undefined;
    if (envelope === undefined || typeof envelope !== 'object') {
      return response;
    }
    // Normalize: replace response.data with the unwrapped data OR rethrow.
    if (envelope.success) {
      (response as NormalizedResponse).synapseMeta = envelope.meta;
      response.data = envelope.data;
      return response;
    }
    const status = response.status;
    throw new ApiEnvelopeError(status, envelope.errors ?? []);
  },
  async (error: AxiosError<ApiEnvelope<unknown>>) => {
    const original = error.config as RetryConfig | undefined;
    const env = error.response?.data;

    // 401 — try silent refresh once and replay.
    if (error.response?.status === 401 && original !== undefined && original._synapseRetried !== true) {
      original._synapseRetried = true;
      const refreshed = await refreshAccessToken();
      if (refreshed !== null) {
        original.headers.set('Authorization', `Bearer ${refreshed}`);
        return apiClient.request(original);
      }
      // Fallthrough: clear the in-memory session. ProtectedRoute reacts
      // to the store change and issues a client-side <Navigate> to
      // /login — never a hard browser navigation, which would reload
      // the SPA and drop all in-memory state.
      useAuthStore.getState().clear();
      return Promise.reject(error);
    }

    // Anything else with envelope shape: convert to ApiEnvelopeError.
    if (env !== undefined && env !== null && typeof env === 'object' && 'errors' in env) {
      const status = error.response?.status ?? 500;
      throw new ApiEnvelopeError(status, env.errors ?? []);
    }

    // Non-envelope (network error, etc.).
    throw new ApiEnvelopeError(error.response?.status ?? 0, [
      { code: 'network.unreachable', message: error.message || 'Network error' },
    ]);
  },
);
