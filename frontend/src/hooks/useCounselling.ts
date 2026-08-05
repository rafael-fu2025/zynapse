/**
 * Counselling hooks — sessions and encrypted notes.
 */
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { z } from 'zod';
import { toast } from 'sonner';
import { apiClient } from '@/api/client';
import type { ApiEnvelopeError } from '@/api/envelope';
import {
  noteSchema,
  openSessionSchema,
  sessionSchema,
  writeNotesSchema,
  type Note,
  type OpenSessionInput,
  type Session,
  type WriteNotesInput,
} from '@/schemas/counselling';

interface SessionPage {
  data: Session[];
  next: string | null;
}

export function useSessions(cursor: string | null, limit = 25) {
  return useQuery<SessionPage, ApiEnvelopeError>({
    queryKey: ['counselling', 'sessions', { cursor, limit }],
    // Other counsellors open/close sessions; poll so the list reflects
    // their actions without a manual refresh.
    refetchInterval: 30_000,
    queryFn: async () => {
      const params = new URLSearchParams();
      if (cursor !== null) params.set('cursor', cursor);
      params.set('limit', String(limit));
      const res = await apiClient.get<{ data: unknown[]; next: string | null }>(
        `/counselling/sessions?${params.toString()}`,
      );
      const data = z.array(sessionSchema).parse(res.data);
      return { data, next: res.data?.next ?? null };
    },
  });
}

export function useOpenSession() {
  const qc = useQueryClient();
  return useMutation<Session, ApiEnvelopeError, OpenSessionInput>({
    mutationFn: async (input) => {
      const valid = openSessionSchema.parse(input);
      const res = await apiClient.post<Session>('/counselling/sessions', valid);
      return sessionSchema.parse(res.data);
    },
    onSuccess: () => {
      void qc.invalidateQueries({ queryKey: ['counselling'] });
      toast.success('Session opened.');
    },
    onError: (err) => {
      toast.error(err.errors[0]?.message ?? 'Failed to open session.');
    },
  });
}

export function useWriteNotes() {
  const qc = useQueryClient();
  return useMutation<Note, ApiEnvelopeError, { sessionId: number; input: WriteNotesInput }>({
    mutationFn: async ({ sessionId, input }) => {
      const valid = writeNotesSchema.parse(input);
      const res = await apiClient.post<Note>(
        `/counselling/sessions/${sessionId}/notes`,
        valid,
      );
      return noteSchema.parse(res.data);
    },
    onSuccess: (_d, vars) => {
      void qc.invalidateQueries({ queryKey: ['counselling', 'notes', vars.sessionId] });
      toast.success('Notes encrypted and saved.');
    },
    onError: (err) => {
      toast.error(err.errors[0]?.message ?? 'Failed to save notes.');
    },
  });
}

export function useNotes(sessionId: number) {
  return useQuery<Note[], ApiEnvelopeError>({
    queryKey: ['counselling', 'notes', sessionId],
    queryFn: async () => {
      const res = await apiClient.get<{ notes: unknown[] }>(
        `/counselling/sessions/${sessionId}/notes`,
      );
      return z.array(noteSchema).parse(res.data.notes);
    },
    enabled: sessionId > 0,
  });
}

export function useCloseSession() {
  const qc = useQueryClient();
  return useMutation<Session, ApiEnvelopeError, number>({
    mutationFn: async (sessionId) => {
      const res = await apiClient.post<Session>(`/counselling/sessions/${sessionId}/close`);
      return sessionSchema.parse(res.data);
    },
    onSuccess: () => {
      void qc.invalidateQueries({ queryKey: ['counselling'] });
      toast.success('Session closed.');
    },
    onError: (err) => {
      toast.error(err.errors[0]?.message ?? 'Failed to close session.');
    },
  });
}