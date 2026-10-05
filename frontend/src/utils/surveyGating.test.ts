import { describe, expect, it } from 'vitest';
import { builderLinksPayload, surveyGating } from './surveyGating';
import type { MySurveyForm, SurveyLink } from '@/schemas/surveys';

function link(partial: Partial<SurveyLink> & Pick<SurveyLink, 'id' | 'type'>): SurveyLink {
  return {
    survey_id: 1,
    title: 'Link',
    description: null,
    external_url: 'https://example.org/x',
    audience: null,
    instrument_key: null,
    is_required: false,
    is_enabled: true,
    requires_screenshot: false,
    sort_order: 10,
    ...partial,
  };
}

function form(partial: Partial<MySurveyForm> = {}): MySurveyForm {
  return {
    id: 1,
    title: 'Survey',
    description: null,
    close_at: null,
    version_id: 1,
    submitted: false,
    questions: [{ id: 11, sort_order: 10, question_type: 'likert', question_text: 'Q', is_required: true, options: [], option_ids: [] }],
    links: [],
    opened_link_ids: [],
    screenshots: [],
    ...partial,
  };
}

describe('surveyGating', () => {
  it('is submittable with questions answered and no links configured', () => {
    const g = surveyGating(form(), { 11: 4 });
    expect(g.canSubmit).toBe(true);
    expect(g.requiredAnswered).toBe(1);
    expect(g.linksTotal).toBe(0);
  });

  it('reports required progress and the first unanswered question', () => {
    const f = form({
      questions: [
        { id: 11, sort_order: 10, question_type: 'likert', question_text: 'Q1', is_required: true, options: [], option_ids: [] },
        { id: 12, sort_order: 20, question_type: 'free_text', question_text: 'Q2', is_required: true, options: [], option_ids: [] },
        { id: 13, sort_order: 30, question_type: 'free_text', question_text: 'Q3', is_required: false, options: [], option_ids: [] },
      ],
    });
    const g = surveyGating(f, { 11: 4 });
    expect(g.requiredTotal).toBe(2);
    expect(g.requiredAnswered).toBe(1);
    expect(g.firstUnansweredId).toBe(12);
    expect(g.canSubmit).toBe(false);

    const done = surveyGating(f, { 11: 4, 12: 'hello', 13: 'skipped voluntary' });
    expect(done.canSubmit).toBe(true);
    expect(done.firstUnansweredId).toBeNull();
  });

  it('never gates on links — they are informational only', () => {
    const f = form({
      links: [
        link({ id: 21, type: 'test', title: 'Multiple Intelligences (MI)', instrument_key: 'mi', requires_screenshot: true }),
        link({ id: 22, type: 'evaluation', title: 'Evaluation of Guidance Services' }),
      ],
    });
    const g = surveyGating(f, { 11: 3 });
    expect(g.canSubmit).toBe(true);
    expect(g.linksTotal).toBe(2);
    expect(g.linksOpened).toBe(0);
    expect(g.proofsExpected).toBe(1);
    expect(g.proofsUploaded).toBe(0);

    const after = surveyGating({ ...f, opened_link_ids: [21], screenshots: [{ id: 1, link_id: 21, original_name: 'a.png', mime_type: 'image/png', size_bytes: 70, created_at: '' }] }, { 11: 3 });
    expect(after.linksOpened).toBe(1);
    expect(after.proofsUploaded).toBe(1);
  });

  it('a survey with no questions is submittable immediately', () => {
    const f = form({ questions: [], links: [link({ id: 21, type: 'test', requires_screenshot: true })] });
    expect(surveyGating(f, {}).canSubmit).toBe(true);
  });

  it('treats an unconfirmed external_url checkbox as unanswered', () => {
    const f = form({
      questions: [{ id: 12, sort_order: 10, question_type: 'external_url', question_text: 'Attest', is_required: true, options: [], option_ids: [] }],
    });
    expect(surveyGating(f, { 12: false }).canSubmit).toBe(false);
    expect(surveyGating(f, { 12: true }).canSubmit).toBe(true);
  });

  it('ignores disabled links in the informational counts', () => {
    const f = form({ links: [link({ id: 21, type: 'test', is_enabled: false, requires_screenshot: true })] });
    const g = surveyGating(f, { 11: 4 });
    expect(g.linksTotal).toBe(0);
    expect(g.proofsExpected).toBe(0);
  });
});

describe('builderLinksPayload', () => {
  it('drops untouched rows, nulls type-specific fields, carries requires_screenshot', () => {
    const payload = builderLinksPayload([
      { type: 'test', title: 'MI', description: '', external_url: ' https://example.org/mi ', audience: null, instrument_key: 'mi', requires_screenshot: true, is_enabled: true },
      { type: 'survey', title: '', description: '', external_url: '', audience: 'new_students', instrument_key: null, requires_screenshot: false, is_enabled: true },
      { type: 'survey', title: 'New & Transferee', description: '  ', external_url: 'https://example.org/new', audience: 'new_students', instrument_key: null, requires_screenshot: false, is_enabled: true },
    ]);
    expect(payload).toHaveLength(2);
    expect(payload[0]).toMatchObject({ external_url: 'https://example.org/mi', description: null, instrument_key: 'mi', requires_screenshot: true });
    expect(payload[1]).toMatchObject({ audience: 'new_students', instrument_key: null, requires_screenshot: false });
  });
});
