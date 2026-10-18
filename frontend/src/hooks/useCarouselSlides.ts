/**
 * useCarouselSlides — public read of the active carousel slides.
 *
 * The login page needs this BEFORE the user authenticates, so the hook
 * uses an unauthenticated axios call (no Authorization header). The
 * endpoint is rate-limited under the public bucket; the SPA caches the
 * result for 5 minutes so reloading the login screen doesn't hammer it.
 */
import { useQuery, type UseQueryResult } from '@tanstack/react-query';
import axios from 'axios';
import { carouselSlideListSchema, type CarouselSlide } from '@/schemas/carousel';

export const carouselSlidesQueryKey = ['carousel-slides', 'public'] as const;

async function fetchPublicSlides(): Promise<CarouselSlide[]> {
  // Use a bare axios instance so the shared `apiClient` interceptor
  // doesn't attach a Bearer token (the endpoint is pre-auth). The
  // /api/v1 base is added by Vite's dev proxy; in prod the SPA is
  // served from the same origin so the relative path resolves.
  const res = await axios.get<unknown>('/api/v1/auth/carousel-slides');
  const body = res.data;
  const data: unknown =
    body !== null && typeof body === 'object' && 'data' in body
      ? body.data
      : body;
  return carouselSlideListSchema.parse(data);
}

export function useCarouselSlides(): UseQueryResult<CarouselSlide[], Error> {
  return useQuery<CarouselSlide[], Error>({
    queryKey: [...carouselSlidesQueryKey],
    queryFn: fetchPublicSlides,
    // Slides rarely change mid-session; 5-minute freshness keeps the
    // login screen responsive without hitting the API on every paint.
    staleTime: 5 * 60 * 1000,
    gcTime: 30 * 60 * 1000,
    // Login is a low-stakes render — never block the screen on a
    // failure; the LoginCarousel falls back to its hard-coded defaults.
    retry: 1,
  });
}