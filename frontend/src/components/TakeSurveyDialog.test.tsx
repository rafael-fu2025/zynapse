/**
 * TakeSurveyDialog unit tests — testing the two-tab layout, tab visibility
 * (questions only / links only / both), and the informational summary.
 *
 * Vitest environment is node: rendered with renderToStaticMarkup and asserted as HTML.
 */
import { renderToStaticMarkup } from 'react-dom/server';
import { beforeAll, beforeEach, describe, expect, it, vi } from 'vitest';
import { TakeSurveyDialog } from './TakeSurveyDialog';

const realConsoleError = console.error;
beforeAll(() => {
  vi.spyOn(console, 'error').mockImplementation((...args: unknown[]) => {
    if (String(args[0]).startsWith('Warning: useLayoutEffect does nothing on the server')) return;
    realConsoleError(...args);
  });
});

const h = vi.hoisted(() => {
  const stub = (data: unknown, opts: { isLoading?: boolean; isError?: boolean } = {}) => ({
    data,
    isLoading: opts.isLoading ?? false,
    isError: opts.isError ?? false,
    isFetching: opts.isLoading ?? false,
    error: undefined,
    refetch: () => {},
  });

  const mutationStub = () => ({
    mutate: () => {},
    mutateAsync: async () => {},
    isPending: false,
  });

  return { stub, mutationStub };
});

let mockFormData: unknown = undefined;

vi.mock('@/components/ui/dialog', () => ({
  Dialog: ({ children }: { children: React.ReactNode }) => <div data-testid="dialog">{children}</div>,
  DialogContent: ({ children }: { children: React.ReactNode }) => <div>{children}</div>,
  DialogHeader: ({ children }: { children: React.ReactNode }) => <div>{children}</div>,
  DialogTitle: ({ children }: { children: React.ReactNode }) => <h2>{children}</h2>,
  DialogDescription: ({ children }: { children: React.ReactNode }) => <p>{children}</p>,
  DialogFooter: ({ children }: { children: React.ReactNode }) => <div>{children}</div>,
  DialogClose: ({ children }: { children: React.ReactNode }) => <div>{children}</div>,
}));

vi.mock('@/hooks/useSurveys', () => ({
  useMySurveyForm: () => h.stub(mockFormData),
  useSubmitSurvey: () => h.mutationStub(),
  useOpenSurveyLink: () => h.mutationStub(),
  useUploadScreenshot: () => h.mutationStub(),
  useRemoveScreenshot: () => h.mutationStub(),
  useMyScreenshotUrl: () => h.stub(undefined),
}));

describe('TakeSurveyDialog', () => {
  beforeEach(() => {
    mockFormData = undefined;
  });

  it('renders two tabs when both questions and links exist', () => {
    mockFormData = {
      id: 1,
      title: 'College Survey',
      description: 'Term survey',
      close_at: null,
      version_id: 1,
      submitted: false,
      questions: [
        { id: 10, sort_order: 10, question_type: 'free_text', question_text: 'what is sun', is_required: true, options: [], option_ids: [] },
      ],
      links: [
        {
          id: 20,
          survey_id: 1,
          type: 'test',
          title: 'Multiple Intelligences (MI)',
          description: 'Take MI online',
          external_url: 'https://example.org/mi',
          audience: null,
          instrument_key: 'mi',
          is_required: false,
          is_enabled: true,
          requires_screenshot: true,
          sort_order: 10,
        },
      ],
      opened_link_ids: [],
      screenshots: [],
    };

    const html = renderToStaticMarkup(<TakeSurveyDialog surveyId={1} onClose={() => {}} />);
    expect(html).toContain('College Survey');
    expect(html).toContain('role="tab"');
    expect(html).toContain('>Questions</button>');
    expect(html).toContain('Optional links');
    expect(html).toContain('Optional');
    expect(html).toContain('what is sun');
    expect(html).toContain('Before you submit');
    expect(html).toContain('Required questions: 0 of 1 answered');
    expect(html).toContain('Optional links: 0 opened · 0 screenshots uploaded');
    expect(html).toContain('Submit survey');
  });

  it('renders without tab bar when questions only', () => {
    mockFormData = {
      id: 1,
      title: 'Questions Only Survey',
      description: null,
      close_at: null,
      version_id: 1,
      submitted: false,
      questions: [
        { id: 11, sort_order: 10, question_type: 'free_text', question_text: 'Your feedback', is_required: true, options: [], option_ids: [] },
      ],
      links: [],
      opened_link_ids: [],
      screenshots: [],
    };

    const html = renderToStaticMarkup(<TakeSurveyDialog surveyId={1} onClose={() => {}} />);
    expect(html).toContain('Your feedback');
    expect(html).not.toContain('Optional links');
    expect(html).toContain('Required questions: 0 of 1 answered');
    expect(html).not.toContain('Optional links:');
  });

  it('renders without tab bar when links only', () => {
    mockFormData = {
      id: 1,
      title: 'Links Only Survey',
      description: null,
      close_at: null,
      version_id: 1,
      submitted: false,
      questions: [],
      links: [
        {
          id: 21,
          survey_id: 1,
          type: 'test',
          title: 'Learning Styles (LS)',
          description: null,
          external_url: 'https://example.org/ls',
          audience: null,
          instrument_key: 'ls',
          is_required: false,
          is_enabled: true,
          requires_screenshot: true,
          sort_order: 10,
        },
      ],
      opened_link_ids: [],
      screenshots: [],
    };

    const html = renderToStaticMarkup(<TakeSurveyDialog surveyId={1} onClose={() => {}} />);
    expect(html).toContain('Learning Styles (LS)');
    expect(html).toContain('These links are optional. You can submit your survey without completing them.');
    expect(html).not.toContain('role="tab"');
    expect(html).toContain('Submit survey');
  });

  it('shows Submitted badge and disables inputs when already submitted', () => {
    mockFormData = {
      id: 1,
      title: 'Submitted Survey',
      description: null,
      close_at: null,
      version_id: 1,
      submitted: true,
      questions: [
        { id: 12, sort_order: 10, question_type: 'free_text', question_text: 'Past question', is_required: true, options: [], option_ids: [] },
      ],
      links: [],
      opened_link_ids: [],
      screenshots: [],
    };

    const html = renderToStaticMarkup(<TakeSurveyDialog surveyId={1} onClose={() => {}} />);
    expect(html).toContain('Submitted');
    expect(html).toContain('disabled=""');
    expect(html).not.toContain('Submit survey');
  });
});
