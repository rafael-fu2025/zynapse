/**
 * Client-side survey submission gating — the UI half. External links
 * are FULLY OPTIONAL: submission depends only on required in-app
 * questions (the backend re-validates with a 422); link opens and
 * screenshot uploads are informational.
 */
import type { MySurveyForm, SurveyLink } from '@/schemas/surveys';

export type SurveyAnswerValue = number | number[] | string | boolean | null;

/** Mirrors SurveyService::SCREENSHOT_* — one constant per tier. */
export const SCREENSHOT_ACCEPT = ['image/jpeg', 'image/png'];
export const SCREENSHOT_MAX_BYTES = 5 * 1024 * 1024;

export interface SurveyGating {
  requiredTotal: number;
  requiredAnswered: number;
  /** First required question missing an answer — the scroll/highlight target. */
  firstUnansweredId: number | null;
  /** Enabled links visible to this student (all optional). */
  linksTotal: number;
  linksOpened: number;
  /** Links that ask for a screenshot vs proofs uploaded so far. */
  proofsExpected: number;
  proofsUploaded: number;
  canSubmit: boolean;
}

export interface SurveyChecklistItem {
  label: string;
  done: boolean;
}

function answerIsComplete(questionType: string, value: SurveyAnswerValue | undefined): boolean {
  if (value === undefined || value === null || value === '') return false;
  if (Array.isArray(value)) return value.length > 0;
  // external_url attestations must be explicitly confirmed — false is
  // an unconfirmed checkbox, not an answer.
  if (questionType === 'external_url') return value === true;
  return true;
}

/**
 * Derives the required-question progress (the only gate) and the
 * informational optional-link counts for the take-survey flow.
 */
export function surveyGating(
  form: MySurveyForm,
  answers: Record<number, SurveyAnswerValue>,
): SurveyGating {
  const requiredQuestions = form.questions.filter((q) => q.is_required);
  const unanswered = requiredQuestions.filter((q) => !answerIsComplete(q.question_type, answers[q.id]));

  const enabledLinks = form.links.filter((l) => l.is_enabled);
  const opened = new Set(form.opened_link_ids);
  const proofs = new Set(form.screenshots.map((s) => s.link_id));

  return {
    requiredTotal: requiredQuestions.length,
    requiredAnswered: requiredQuestions.length - unanswered.length,
    firstUnansweredId: unanswered[0]?.id ?? null,
    linksTotal: enabledLinks.length,
    linksOpened: enabledLinks.filter((l) => opened.has(l.id)).length,
    proofsExpected: enabledLinks.filter((l) => l.requires_screenshot).length,
    proofsUploaded: enabledLinks.filter((l) => proofs.has(l.id)).length,
    canSubmit: unanswered.length === 0,
  };
}

/**
 * The link rows the builder sends: full replace semantics, so every
 * row must be URL-complete; blanks are dropped only when they are
 * entirely untouched (no title AND no URL).
 */
export function builderLinksPayload(links: Array<BuilderLinkRow>): Array<Record<string, unknown>> {
  return links
    .filter((l) => l.title.trim() !== '' || l.external_url.trim() !== '')
    .map((l) => ({
      type: l.type,
      title: l.title.trim(),
      description: l.description.trim() !== '' ? l.description.trim() : null,
      external_url: l.external_url.trim(),
      audience: l.type === 'survey' ? l.audience : null,
      instrument_key: l.type === 'test' ? l.instrument_key : null,
      requires_screenshot: l.requires_screenshot,
      is_enabled: l.is_enabled,
    }));
}

/** Minimal structural shape so the helper stays decoupled from the builder. */
export interface BuilderLinkRow {
  type: SurveyLink['type'];
  title: string;
  description: string;
  external_url: string;
  audience: SurveyLink['audience'];
  instrument_key: SurveyLink['instrument_key'];
  requires_screenshot: boolean;
  is_enabled: boolean;
}
