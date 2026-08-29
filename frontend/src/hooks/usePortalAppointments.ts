import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { z } from 'zod';
import { apiClient } from '@/api/client';

export const portalAppointmentSchema = z.object({ department:z.enum(['clinic','counselling']),id:z.number(),provider_user_id:z.number(),provider_name:z.string().nullable().optional(),starts_at:z.string(),ends_at:z.string(),status:z.string(),reason:z.string().nullable(),type:z.string().nullable(),queue_entry_id:z.number().nullable().optional() });
export const appointmentSlotSchema = z.object({ department:z.enum(['clinic','counselling']),provider_user_id:z.number(),provider_name:z.string(),starts_at:z.string(),ends_at:z.string(),duration_minutes:z.number() });
export type PortalAppointment=z.infer<typeof portalAppointmentSchema>; export type AppointmentSlot=z.infer<typeof appointmentSlotSchema>;
export function usePortalAppointments(){return useQuery({queryKey:['me','appointments'],queryFn:async()=>z.object({appointments:z.array(portalAppointmentSchema)}).parse((await apiClient.get('/me/appointments',{params:{department:'all'}})).data).appointments});}
export function useAppointmentSlots(department:string,date:string){return useQuery({queryKey:['me','appointment-slots',department,date],enabled:date!==''&&department!=='',queryFn:async()=>z.object({slots:z.array(appointmentSlotSchema)}).parse((await apiClient.get('/me/appointment-slots',{params:{department,from:date,to:date}})).data).slots});}
export function useBookPortalAppointment(){const q=useQueryClient();return useMutation({mutationFn:async(input:{department:string;provider_user_id:number;starts_at:string;reason?:string;type?:string})=>portalAppointmentSchema.parse((await apiClient.post('/me/appointments',input)).data),onSuccess:()=>void q.invalidateQueries({queryKey:['me','appointments']})});}
export function useCancelPortalAppointment(){const q=useQueryClient();return useMutation({mutationFn:async(a:PortalAppointment)=>portalAppointmentSchema.parse((await apiClient.post(`/me/appointments/${a.department}/${a.id}/cancel`)).data),onSuccess:()=>void q.invalidateQueries({queryKey:['me','appointments']})});}
