import { z } from 'zod';

export const workstationUnitSchema = z.enum(['clinic', 'guidance', 'facilities']);
export type WorkstationUnit = z.infer<typeof workstationUnitSchema>;
const daySchema = z.string().regex(/^\d{4}-\d{2}-\d{2}$/);
const valueSchema = z.number().finite().nonnegative();

export const workstationDashboardSchema = z.object({
  unit: workstationUnitSchema,
  day: daySchema,
  generated_at: z.string().datetime(),
  scope: z.enum(['tenant', 'own']),
  metrics: z.array(z.object({
    key: z.string(), label: z.string(), value: valueSchema,
    unit: z.string().nullable(), description: z.string(),
  })),
  trend: z.object({
    title: z.string(), description: z.string(),
    points: z.array(z.object({ day: daySchema, value: valueSchema })),
  }),
  breakdown: z.object({
    title: z.string(), description: z.string(),
    items: z.array(z.object({ key: z.string(), label: z.string(), value: valueSchema })),
  }),
  recent: z.object({
    kind: z.enum(['encounters', 'sessions', 'drums']), title: z.string(),
    rows: z.array(z.object({
      id: z.number().int().positive(), code: z.string(), title: z.string(),
      subtitle: z.string().nullable(), status: z.string(),
      occurred_at: z.string().nullable(), owner: z.string().nullable(),
    })),
  }),
});
export type WorkstationDashboard = z.infer<typeof workstationDashboardSchema>;
