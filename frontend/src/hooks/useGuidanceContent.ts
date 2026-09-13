/**
 * useGuidanceContent — TanStack Query hooks for the guidance parity
 * Phase A (announcements + CMO service catalogue). Staff CRUD hooks
 * gate on the backend (403s stay possible); the /me feed hooks are
 * self-scoped student surfaces.
 */
import { keepPreviousData, useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { z } from 'zod';
import { toast } from 'sonner';
import { apiClient } from '@/api/client';
import type { ApiEnvelopeError } from '@/api/envelope';
import type {
  AnnouncementInput,
  GuidanceAnnouncement,
  GuidanceService,
  ServiceInput,
} from '@/schemas/guidanceContent';

const announcementListSchema = z.array(
  z.object({
    id: z.number().int().positive(),
    title: z.string(),
    body: z.string(),
    audience: z.enum(['all', 'new_students', 'continuing_students', 'graduating_students']),
    action_url: z.string().nullable(),
    action_label: z.string().nullable(),
    is_required: z.boolean(),
    publish_at: z.string().nullable(),
    unpublish_at: z.string().nullable(),
    created_at: z.string(),
    updated_at: z.string(),
    status: z.enum(['live', 'scheduled', 'expired']),
  }),
);

const serviceListSchema = z.array(
  z.object({
    id: z.number().int().positive(),
    code: z.string(),
    name: z.string(),
    description: z.string().nullable(),
    cmo_reference: z.string().nullable(),
    sort_order: z.number().int().nonnegative(),
    queue_destination: z.enum(['clinic', 'counselling']).nullable(),
    is_active: z.boolean(),
    created_at: z.string(),
  }),
);

function toAnnouncementPayload(input: AnnouncementInput): Record<string, unknown> {
  return {
    title: input.title,
    body: input.body,
    audience: input.audience,
    action_url: input.action_url?.trim() || null,
    action_label: input.action_label?.trim() || null,
    is_required: input.is_required,
    // datetime-local values are wall-clock; the backend stores UTC —
    // send as-is and let staff treat windows in app time for now
    // (documented in the parity plan; Phase B adds proper tz inputs).
    publish_at: input.publish_at?.trim() || null,
    unpublish_at: input.unpublish_at?.trim() || null,
  };
}

function toServicePayload(input: ServiceInput): Record<string, unknown> {
  return {
    name: input.name,
    code: input.code?.trim() || null,
    description: input.description?.trim() || null,
    cmo_reference: input.cmo_reference?.trim() || null,
    sort_order: input.sort_order,
    queue_destination: input.queue_destination,
    is_active: input.is_active,
  };
}

// ---- staff: announcements -------------------------------------------

export function useGuidanceAnnouncements() {
  return useQuery<GuidanceAnnouncement[], ApiEnvelopeError>({
    queryKey: ['guidance', 'announcements'],
    queryFn: async () => {
      const res = await apiClient.get<unknown[]>('/counselling/announcements');
      return announcementListSchema.parse(res.data);
    },
    placeholderData: keepPreviousData,
  });
}

export function useCreateGuidanceAnnouncement() {
  const qc = useQueryClient();
  return useMutation<GuidanceAnnouncement, ApiEnvelopeError, AnnouncementInput>({
    mutationFn: async (input) => {
      const res = await apiClient.post<GuidanceAnnouncement>('/counselling/announcements', toAnnouncementPayload(input));
      return res.data;
    },
    onSuccess: (a) => {
      void qc.invalidateQueries({ queryKey: ['guidance', 'announcements'] });
      toast.success(`Announcement "${a.title}" created.`);
    },
    onError: (err) => toast.error(err.errors[0]?.message ?? 'Failed to create the announcement.'),
  });
}

export function useUpdateGuidanceAnnouncement() {
  const qc = useQueryClient();
  return useMutation<GuidanceAnnouncement, ApiEnvelopeError, { id: number; input: AnnouncementInput }>({
    mutationFn: async ({ id, input }) => {
      const res = await apiClient.post<GuidanceAnnouncement>(`/counselling/announcements/${id}/update`, toAnnouncementPayload(input));
      return res.data;
    },
    onSuccess: (a) => {
      void qc.invalidateQueries({ queryKey: ['guidance', 'announcements'] });
      toast.success(`Announcement "${a.title}" updated.`);
    },
    onError: (err) => toast.error(err.errors[0]?.message ?? 'Failed to update the announcement.'),
  });
}

export function useArchiveGuidanceAnnouncement() {
  const qc = useQueryClient();
  return useMutation<{ id: number }, ApiEnvelopeError, { id: number; title: string }>({
    mutationFn: async ({ id }) => {
      const res = await apiClient.post<{ id: number; archived: boolean }>(`/counselling/announcements/${id}/archive`);
      return { id: res.data.id };
    },
    onSuccess: (_r, vars) => {
      void qc.invalidateQueries({ queryKey: ['guidance', 'announcements'] });
      toast.success(`Announcement "${vars.title}" archived.`);
    },
    onError: (err) => toast.error(err.errors[0]?.message ?? 'Failed to archive the announcement.'),
  });
}

// ---- staff: services -------------------------------------------------

export function useGuidanceServices() {
  return useQuery<GuidanceService[], ApiEnvelopeError>({
    queryKey: ['guidance', 'services'],
    queryFn: async () => {
      const res = await apiClient.get<unknown[]>('/counselling/services');
      return serviceListSchema.parse(res.data);
    },
    placeholderData: keepPreviousData,
  });
}

export function useCreateGuidanceService() {
  const qc = useQueryClient();
  return useMutation<GuidanceService, ApiEnvelopeError, ServiceInput>({
    mutationFn: async (input) => {
      const res = await apiClient.post<GuidanceService>('/counselling/services', toServicePayload(input));
      return res.data;
    },
    onSuccess: (s) => {
      void qc.invalidateQueries({ queryKey: ['guidance', 'services'] });
      toast.success(`Service "${s.name}" created.`);
    },
    onError: (err) => toast.error(err.errors[0]?.message ?? 'Failed to create the service.'),
  });
}

export function useUpdateGuidanceService() {
  const qc = useQueryClient();
  return useMutation<GuidanceService, ApiEnvelopeError, { id: number; input: ServiceInput }>({
    mutationFn: async ({ id, input }) => {
      const res = await apiClient.post<GuidanceService>(`/counselling/services/${id}/update`, toServicePayload(input));
      return res.data;
    },
    onSuccess: (s) => {
      void qc.invalidateQueries({ queryKey: ['guidance', 'services'] });
      toast.success(`Service "${s.name}" updated.`);
    },
    onError: (err) => toast.error(err.errors[0]?.message ?? 'Failed to update the service.'),
  });
}

export function useArchiveGuidanceService() {
  const qc = useQueryClient();
  return useMutation<{ id: number }, ApiEnvelopeError, { id: number; name: string }>({
    mutationFn: async ({ id }) => {
      const res = await apiClient.post<{ id: number; archived: boolean }>(`/counselling/services/${id}/archive`);
      return { id: res.data.id };
    },
    onSuccess: (_r, vars) => {
      void qc.invalidateQueries({ queryKey: ['guidance', 'services'] });
      toast.success(`Service "${vars.name}" archived.`);
    },
    onError: (err) => toast.error(err.errors[0]?.message ?? 'Failed to archive the service.'),
  });
}

// ---- student feed (/me/guidance/*) -----------------------------------

const feedAnnouncementSchema = z.object({
  id: z.number().int().positive(),
  title: z.string(),
  body: z.string(),
  action_url: z.string().nullable(),
  action_label: z.string().nullable(),
  is_required: z.boolean(),
  publish_at: z.string().nullable(),
});

export function useMyGuidanceAnnouncements() {
  return useQuery<
    Array<{ id: number; title: string; body: string; action_url: string | null; action_label: string | null; is_required: boolean; publish_at: string | null }>,
    ApiEnvelopeError
  >({
    queryKey: ['me', 'guidance', 'announcements'],
    queryFn: async () => {
      const res = await apiClient.get<unknown[]>('/me/guidance/announcements');
      return z.array(feedAnnouncementSchema).parse(res.data);
    },
    placeholderData: keepPreviousData,
  });
}

export function useMyGuidanceServices() {
  return useQuery<GuidanceService[], ApiEnvelopeError>({
    queryKey: ['me', 'guidance', 'services'],
    queryFn: async () => {
      const res = await apiClient.get<unknown[]>('/me/guidance/services');
      return serviceListSchema.parse(res.data);
    },
    placeholderData: keepPreviousData,
  });
}
