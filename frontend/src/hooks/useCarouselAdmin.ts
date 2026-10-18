/**
 * useCarouselAdmin — RBAC-gated CRUD hooks for the carousel admin page.
 *
 * Endpoints (under `/api/v1/admin/carousel`):
 *   GET    ''                       — list (active + inactive)
 *   POST   ''                       — create (multipart, light_image required)
 *   POST   '/:id'                   — update (multipart, all fields optional)
 *   POST   '/:id/delete'            — delete
 *   POST   '/:id/toggle'            — flip is_active
 *   POST   '/reorder'               — JSON { ordered_ids: number[] }
 *
 * All mutations invalidate the public + admin query keys so the login
 * screen reflects the change on next visit.
 */
import { useMutation, useQuery, useQueryClient, type UseMutationResult, type UseQueryResult } from '@tanstack/react-query';
import type { AxiosProgressEvent } from 'axios';
import { apiClient } from '@/api/client';
import { carouselSlideListSchema, carouselSlideSchema, type CarouselSlide } from '@/schemas/carousel';
import { carouselSlidesQueryKey } from '@/hooks/useCarouselSlides';

export const carouselAdminQueryKey = ['carousel-slides', 'admin'] as const;

/**
 * Bytes-sent → whole percent. `loaded` counts only the request body, so
 * once the body is flushed the bar sits at 100% while the server is
 * still validating / moving the file — the label says "Processing" for
 * that phase rather than claiming to measure it.
 */
function percentOf(event: AxiosProgressEvent): number {
  if (!event.total || event.total === 0) return 0;
  return Math.min(100, Math.max(0, Math.round((event.loaded / event.total) * 100)));
}

async function fetchAdminSlides(): Promise<CarouselSlide[]> {
  const res = await apiClient.get<unknown>('/admin/carousel');
  const body = res.data;
  const data: unknown =
    body !== null && typeof body === 'object' && 'data' in body
      ? body.data
      : body;
  return carouselSlideListSchema.parse(data);
}

export function useCarouselAdminList(): UseQueryResult<CarouselSlide[], Error> {
  return useQuery<CarouselSlide[], Error>({
    queryKey: [...carouselAdminQueryKey],
    queryFn: fetchAdminSlides,
    staleTime: 30_000,
  });
}

interface SlideWriteInput {
  title?: string | undefined;
  subtitle?: string | undefined;
  fitMode: 'cover' | 'contain_blur';
  lightImage?: File | string | null | undefined;
  darkImage?: File | string | null | undefined;
  isActive?: boolean | undefined;
}
export type { SlideWriteInput };

function buildSlideFormData(input: SlideWriteInput): FormData {
  const fd = new FormData();
  if (input.title !== undefined) fd.append('title', input.title);
  if (input.subtitle !== undefined) fd.append('subtitle', input.subtitle);
  fd.append('fit_mode', input.fitMode);
  if (input.isActive !== undefined) fd.append('is_active', input.isActive ? '1' : '0');
  if (input.lightImage instanceof File) fd.append('light_image', input.lightImage);
  else if (typeof input.lightImage === 'string' && input.lightImage !== '') {
    fd.append('light_image', input.lightImage);
  }
  if (input.darkImage instanceof File) fd.append('dark_image', input.darkImage);
  else if (input.darkImage === null) {
    // Empty string = clear the field. `null` vs `undefined` is the
    // contract: undefined = leave alone, null = explicitly clear.
    fd.append('dark_image', '');
  } else if (typeof input.darkImage === 'string' && input.darkImage !== '') {
    fd.append('dark_image', input.darkImage);
  }
  return fd;
}

function parseSlideResponse(res: { data: unknown }): CarouselSlide {
  const body = res.data;
  const data: unknown =
    body !== null && typeof body === 'object' && 'data' in body
      ? body.data
      : body;
  return carouselSlideSchema.parse(data);
}

function invalidateAll(qc: ReturnType<typeof useQueryClient>): void {
  void qc.invalidateQueries({ queryKey: carouselAdminQueryKey });
  void qc.invalidateQueries({ queryKey: carouselSlidesQueryKey });
}

export function useCreateCarouselSlide(
  onProgress?: (percent: number) => void,
): UseMutationResult<CarouselSlide, Error, SlideWriteInput> {
  const qc = useQueryClient();
  return useMutation<CarouselSlide, Error, SlideWriteInput>({
    mutationFn: async (input) => {
      const fd = buildSlideFormData(input);
      const res = await apiClient.post<unknown>('/admin/carousel', fd, {
        onUploadProgress: (event) => {
          onProgress?.(percentOf(event));
        },
      });
      return parseSlideResponse(res);
    },
    onSuccess: () => invalidateAll(qc),
  });
}

export function useUpdateCarouselSlide(
  onProgress?: (percent: number) => void,
): UseMutationResult<CarouselSlide, Error, { id: number; input: SlideWriteInput }> {
  const qc = useQueryClient();
  return useMutation<CarouselSlide, Error, { id: number; input: SlideWriteInput }>({
    mutationFn: async ({ id, input }) => {
      const fd = buildSlideFormData(input);
      const res = await apiClient.post<unknown>(`/admin/carousel/${id}`, fd, {
        onUploadProgress: (event) => {
          onProgress?.(percentOf(event));
        },
      });
      return parseSlideResponse(res);
    },
    onSuccess: () => invalidateAll(qc),
  });
}

export function useDeleteCarouselSlide(): UseMutationResult<void, Error, number> {
  const qc = useQueryClient();
  return useMutation<void, Error, number>({
    mutationFn: async (id) => {
      await apiClient.post(`/admin/carousel/${id}/delete`);
    },
    onSuccess: () => invalidateAll(qc),
  });
}

export function useToggleCarouselSlide(): UseMutationResult<CarouselSlide, Error, number> {
  const qc = useQueryClient();
  return useMutation<CarouselSlide, Error, number>({
    mutationFn: async (id) => {
      const res = await apiClient.post<unknown>(`/admin/carousel/${id}/toggle`);
      return parseSlideResponse(res);
    },
    onSuccess: () => invalidateAll(qc),
  });
}

export function useReorderCarouselSlides(): UseMutationResult<void, Error, number[]> {
  const qc = useQueryClient();
  return useMutation<void, Error, number[]>({
    mutationFn: async (orderedIds) => {
      await apiClient.post('/admin/carousel/reorder', { ordered_ids: orderedIds });
    },
    onSuccess: () => invalidateAll(qc),
  });
}