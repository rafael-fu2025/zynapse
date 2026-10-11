/**
 * ReferToGuidanceDialog unit tests — the clinic-side "Refer to
 * Guidance" action: form fields, the privacy notice, required-field
 * validation, and the duplicate-referral state.
 *
 * Vitest environment is node: rendered with renderToStaticMarkup and
 * asserted as markup. The dialog is wrapped in a Dialog stub because
 * Radix portals do not render in a static server pass.
 */
import { renderToStaticMarkup } from 'react-dom/server';
import { MemoryRouter } from 'react-router-dom';
import { beforeAll, describe, expect, it, vi } from 'vitest';
import { ReferToGuidanceDialog } from './ReferToGuidanceDialog';
import { referToGuidanceSchema } from '@/schemas/referrals';

const realConsoleError = console.error;
beforeAll(() => {
  vi.spyOn(console, 'error').mockImplementation((...args: unknown[]) => {
    if (String(args[0]).startsWith('Warning: useLayoutEffect does nothing on the server')) return;
    realConsoleError(...args);
  });
});

const h = vi.hoisted(() => ({
  mutation: () => ({
    mutate: () => {},
    mutateAsync: () => Promise.resolve({}),
    isPending: false,
  }),
}));

vi.mock('@/components/ui/dialog', () => ({
  Dialog: ({ children }: { children: React.ReactNode }) => <div data-testid="dialog">{children}</div>,
  DialogContent: ({ children }: { children: React.ReactNode }) => <div>{children}</div>,
  DialogHeader: ({ children }: { children: React.ReactNode }) => <div>{children}</div>,
  DialogTitle: ({ children }: { children: React.ReactNode }) => <h2>{children}</h2>,
  DialogDescription: ({ children }: { children: React.ReactNode }) => <p>{children}</p>,
  DialogFooter: ({ children }: { children: React.ReactNode }) => <div>{children}</div>,
}));

vi.mock('@/hooks/useReferrals', () => ({
  useCreateGuidanceReferral: () => h.mutation(),
}));

const PATIENT = { number: '20219988', name: 'Dino, Gerald' };

function renderDialog(props: Partial<Parameters<typeof ReferToGuidanceDialog>[0]> = {}): string {
  return renderToStaticMarkup(
    <MemoryRouter>
      <ReferToGuidanceDialog patient={PATIENT} onClose={() => {}} {...props} />
    </MemoryRouter>,
  );
}

describe('ReferToGuidanceDialog', () => {
  it('renders the form with reason, priority, notes, date and the privacy notice', () => {
    const html = renderDialog();
    expect(html).toContain('Refer to Guidance');
    expect(html).toContain('Dino, Gerald');
    expect(html).toContain('Reason for referral');
    expect(html).toContain('Priority');
    expect(html).toContain('Notes (optional)');
    expect(html).toContain('Referral date');
    expect(html).toContain('Only the reason, notes, and priority are shared with the Guidance Center');
    expect(html).toContain('normal');
    expect(html).not.toContain('already has an active referral');
  });

  it('reason is required by schema; notes cap at 2000 chars', () => {
    const base = {
      patient_school_id: '20219988',
      source_module: 'clinic' as const,
      target_module: 'counselling' as const,
      artifact_type: 'intake_pass' as const,
      priority: 'normal' as const,
    };
    expect(referToGuidanceSchema.safeParse(base).success).toBe(false);
    expect(referToGuidanceSchema.safeParse({ ...base, reason_code: 'bullying' }).success).toBe(true);
    expect(referToGuidanceSchema.safeParse({ ...base, reason_code: 'other', notes_plaintext: 'x'.repeat(2001) }).success).toBe(false);
    expect(referToGuidanceSchema.safeParse({ ...base, reason_code: 'other', priority: 'urgent' }).success).toBe(true);
  });

  it('renders the duplicate-referral state with the existing referral', () => {
    const html = renderDialog({
      initialDuplicate: {
        id: 7,
        patient_school_id: '20219988',
        source_module: 'clinic',
        target_module: 'counselling',
        artifact_type: 'intake_pass',
        status: 'acknowledged',
        priority: 'high',
        reason_code: 'academic_stress',
        created_at: '2026-10-05 02:00:00',
        updated_at: '2026-10-05 03:00:00',
      },
    });
    expect(html).toContain('already has an active referral');
    expect(html).toContain('Referral #7');
    expect(html).toContain('Reason: Academic stress');
    expect(html).toContain('Acknowledged');
    expect(html).toContain('/referrals');
    expect(html).not.toContain('Reason for referral');
  });
});
