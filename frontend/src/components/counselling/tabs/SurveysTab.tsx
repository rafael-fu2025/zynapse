/**
 * SurveysTab — guidance surveys builder + responses (parity plan Phase
 * B). Draft → publish (immutable version) → archive; responses per
 * survey are identity-linked (requirements context) and every answer
 * detail read is audited server-side. Dynamic links (survey / test /
 * evaluation) are configured per survey and snapshotted on publish;
 * published surveys allow a narrow URL/title hot-fix per link.
 */
import { ClipboardList, Download, Eye, Pencil, Plus, Send, Trash2 } from 'lucide-react';
import { Fragment, useEffect, useMemo, useRef, useState } from 'react';
import { DateTimeField } from '@/components/DateTimeField';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import { ConfirmDialog, type ConfirmAction } from '@/components/ConfirmDialog';
import { TableStateBlock } from '@/components/TableStates';
import {
  Dialog,
  DialogContent,
  DialogDescription,
  DialogFooter,
  DialogHeader,
  DialogTitle,
} from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import {
  Select,
  SelectContent,
  SelectItem,
  SelectTrigger,
  SelectValue,
} from '@/components/ui/select';
import { Skeleton } from '@/components/ui/skeleton';
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from '@/components/ui/table';
import { Tabs, TabsContent, TabsList, TabsTrigger } from '@/components/ui/tabs';
import { Textarea } from '@/components/ui/textarea';
import { useSurveyAggregate } from '@/hooks/useGuidanceFollowups';
import {
  useArchiveSurvey,
  useCreateSurvey,
  usePatchSurveyLink,
  usePublishSurvey,
  useResponseScreenshotUrl,
  useSurvey,
  useSurveyResponseDetail,
  useSurveyResponses,
  useSurveys,
  useUpdateSurvey,
} from '@/hooks/useSurveys';
import { apiClient } from '@/api/client';
import type {
  Survey,
  SurveyLink,
  SurveyLinkInstrument,
  SurveyLinkScreenshot,
  SurveyLinkType,
  SurveyResponseLink,
} from '@/schemas/surveys';
import {
  surveyMetaInputSchema,
  type BuilderLink,
  type BuilderQuestion,
  type QuestionType,
  type SurveyAudience,
  type SurveyMetaInput,
} from '@/schemas/surveys';
import { fmtUtcToApp } from '@/utils/date';
import { titleCase } from '@/lib/utils';

const AUDIENCE_LABELS: Record<SurveyAudience, string> = {
  all: 'All students',
  new_students: 'New students',
  continuing_students: 'Continuing students',
  graduating_students: 'Graduating students',
};

/** Registry year levels (users.year_level) a survey can target. */
const YEAR_LEVEL_OPTIONS = [1, 2, 3, 4, 5, 6] as const;

const STATUS_VARIANTS: Record<string, 'success' | 'info' | 'secondary' | 'warning'> = {
  live: 'success',
  scheduled: 'info',
  closed: 'secondary',
  draft: 'warning',
};

const TYPE_LABELS: Record<QuestionType, string> = {
  single: 'Single choice',
  multi: 'Multiple choice',
  likert: 'Scale 1-5 (agreement)',
  rating: 'Rating 1-5',
  free_text: 'Free text',
  external_url: 'External activity (attest)',
};

const LINK_TYPE_LABELS: Record<SurveyLinkType, string> = {
  survey: 'Survey link',
  test: 'Test link',
  evaluation: 'Evaluation link',
};

const INSTRUMENT_LABELS: Record<SurveyLinkInstrument, string> = {
  new_transferees: 'New & Transferees',
  mi: 'Multiple Intelligences (MI)',
  ls: 'Learning Styles (LS)',
  bfpt: 'Big Five Personality Test (BFPT)',
  custom: 'Custom assessment',
};

function emptyQuestion(): BuilderQuestion {
  return { question_text: '', question_type: 'free_text', is_required: false, options_text: '' };
}

let linkKeySeq = 0;
function nextLinkKey(): string {
  linkKeySeq += 1;
  return `link-${linkKeySeq}`;
}

function emptyLink(type: SurveyLinkType, overrides: Partial<BuilderLink> = {}): BuilderLink {
  return {
    key: nextLinkKey(),
    type,
    title: '',
    description: '',
    external_url: '',
    audience: null,
    instrument_key: type === 'test' ? 'custom' : null,
    requires_screenshot: false,
    is_enabled: true,
    ...overrides,
  };
}

/** New surveys start with the canonical assessment set; URLs are admin-entered. */
function defaultBuilderLinks(): BuilderLink[] {
  return [
    emptyLink('test', { title: 'Multiple Intelligences (MI)', instrument_key: 'mi', requires_screenshot: true }),
    emptyLink('test', { title: 'Learning Styles (LS)', instrument_key: 'ls', requires_screenshot: true }),
    emptyLink('test', { title: 'Big Five Personality Test (BFPT)', instrument_key: 'bfpt', requires_screenshot: true }),
    emptyLink('evaluation', {
      title: 'Evaluation of Guidance Services',
      description: 'Please complete the evaluation after completing the required survey and assessments.',
      requires_screenshot: false,
    }),
  ];
}

function savedToBuilderLink(link: SurveyLink): BuilderLink {
  return {
    key: `saved-${link.id}`,
    id: link.id,
    type: link.type,
    title: link.title,
    description: link.description ?? '',
    external_url: link.external_url,
    audience: link.audience,
    instrument_key: link.instrument_key,
    requires_screenshot: link.requires_screenshot,
    is_enabled: link.is_enabled,
  };
}

/** Mirror of the server rule: http(s) with a host, no credentials. */
function linkRowIsComplete(row: BuilderLink): boolean {
  const url = row.external_url.trim();
  const title = row.title.trim();
  if (title === '' && url === '') return true; // untouched row — dropped on save
  if (title === '') return false;
  try {
    const parsed = new URL(url);
    if (parsed.protocol !== 'http:' && parsed.protocol !== 'https:') return false;
    return parsed.hostname !== '' && parsed.username === '' && parsed.password === '';
  } catch {
    return false;
  }
}

/**
 * Builder dialog — fetches the FULL survey detail (the list payload
 * carries no questions) so editing a draft shows its saved questions.
 * `surveyId === null` means "create new". Closing while the form has
 * unsaved edits asks for confirmation instead of discarding silently.
 */
function SurveyBuilderDialog({
  surveyId,
  onClose,
}: {
  surveyId: number | null;
  onClose: () => void;
}) {
  const detail = useSurvey(surveyId);
  const existing = surveyId === null ? null : detail.data ?? null;
  const loading = surveyId !== null && detail.isLoading;

  // Controlled open so X / Escape / overlay-click can be intercepted
  // while the form is dirty; refs carry the form's live dirty/saving
  // state up without re-rendering the dialog on every keystroke.
  const [openState, setOpenState] = useState(true);
  const [confirmDiscard, setConfirmDiscard] = useState(false);
  const stateRef = useRef({ dirty: false, saving: false });

  function requestClose() {
    if (stateRef.current.saving) return; // never dismiss mid-save
    if (stateRef.current.dirty) {
      setConfirmDiscard(true);
      return;
    }
    setOpenState(false);
    onClose();
  }

  return (
    <>
      <Dialog
        open={openState}
        onOpenChange={(next) => { if (!next) requestClose(); }}
      >
        <DialogContent
          lockDismiss
          className="max-h-[92dvh] overflow-y-auto sm:max-w-3xl"
        >
          <DialogHeader>
            <DialogTitle>{existing !== null ? 'Edit survey' : 'New survey'}</DialogTitle>
            <DialogDescription>
              Publishing snapshots the questions (immutable version). Keep forms short — completion rates drop with length.
            </DialogDescription>
          </DialogHeader>
          {loading && <Skeleton className="h-64" aria-label="Loading survey" />}
          {!loading && (
            <SurveyBuilderForm
              key={existing?.id ?? 'new'}
              existing={existing}
              onClose={onClose}
              requestClose={requestClose}
              onStateChange={(dirty, saving) => { stateRef.current = { dirty, saving }; }}
            />
          )}
        </DialogContent>
      </Dialog>

      <ConfirmDialog
        open={confirmDiscard}
        title="Discard unsaved changes?"
        description="The survey has edits that haven't been saved yet. Closing now discards them."
        confirmLabel="Discard changes"
        cancelLabel="Keep editing"
        destructive
        onConfirm={() => { setConfirmDiscard(false); setOpenState(false); onClose(); }}
        onCancel={() => setConfirmDiscard(false)}
      />
    </>
  );
}

/** Normalized snapshot of the builder state for dirty comparison. */
function surveyStateSnapshot(meta: SurveyMetaInput, questions: BuilderQuestion[], links: BuilderLink[]): string {
  return JSON.stringify({
    title: meta.title.trim(),
    description: (meta.description ?? '').trim(),
    audience: meta.audience,
    year_levels: [...(meta.year_levels ?? [])].sort((a, b) => a - b),
    category: meta.category,
    is_required: meta.is_required,
    publish_at: meta.publish_at ?? '',
    close_at: meta.close_at ?? '',
    questions: questions.map((q) => ({
      text: q.question_text.trim(),
      type: q.question_type,
      required: q.is_required,
      options: q.options_text.split('\n').map((l) => l.trim()).filter(Boolean),
    })),
    links: links.map((l) => ({
      type: l.type,
      title: l.title.trim(),
      description: l.description.trim(),
      url: l.external_url.trim(),
      audience: l.audience,
      instrument: l.instrument_key,
      requires_screenshot: l.requires_screenshot,
      enabled: l.is_enabled,
    })),
  });
}

function SurveyBuilderForm({
  existing,
  onClose,
  requestClose,
  onStateChange,
}: {
  existing: Survey | null;
  onClose: () => void;
  requestClose: () => void;
  onStateChange: (dirty: boolean, saving: boolean) => void;
}) {
  const create = useCreateSurvey();
  const update = useUpdateSurvey();
  const pending = create.isPending || update.isPending;
  const [meta, setMeta] = useState<SurveyMetaInput>(existing !== null
    ? {
        title: existing.title,
        description: existing.description ?? '',
        audience: existing.audience,
        year_levels: existing.year_levels ?? [],
        category: existing.category,
        is_required: existing.is_required,
        publish_at: existing.publish_at ?? '',
        close_at: existing.close_at ?? '',
      }
    : {
        title: '',
        description: '',
        audience: 'all',
        year_levels: [],
        category: 'survey',
        is_required: false,
        publish_at: '',
        close_at: '',
      });
  const [questions, setQuestions] = useState<BuilderQuestion[]>(
    existing?.questions.map((q) => ({
      question_text: q.question_text,
      question_type: q.question_type,
      is_required: q.is_required,
      options_text: q.options.map((o) => o.text).join('\n'),
    })) ?? [emptyQuestion()],
  );
  const [links, setLinks] = useState<BuilderLink[]>(
    existing !== null
      ? existing.links.map(savedToBuilderLink)
      : defaultBuilderLinks(),
  );

  const immutable = existing !== null && existing.status !== 'draft';
  const validMeta = surveyMetaInputSchema.safeParse({ ...meta, description: meta.description ?? undefined }).success;
  const validQuestions = questions.every(
    (q) => q.question_text.trim() === '' ||
      (['single', 'multi'].includes(q.question_type)
        ? q.options_text.split('\n').map((l) => l.trim()).filter(Boolean).length >= 2
        : true),
  );
  const validLinks = links.every(linkRowIsComplete);
  const hasContent =
    questions.some((q) => q.question_text.trim() !== '') ||
    links.some((l) => l.is_enabled && l.title.trim() !== '' && l.external_url.trim() !== '');

  // Dirty tracking for the unsaved-changes guard. Immutable surveys are
  // never dirty (every field is disabled).
  const initialSnapshot = useMemo(() => surveyStateSnapshot(
    existing !== null
      ? {
          title: existing.title,
          description: existing.description ?? '',
          audience: existing.audience,
          year_levels: existing.year_levels ?? [],
          category: existing.category,
          is_required: existing.is_required,
          publish_at: existing.publish_at ?? '',
          close_at: existing.close_at ?? '',
        }
      : {
          title: '',
          description: '',
          audience: 'all',
          year_levels: [],
          category: 'survey',
          is_required: false,
          publish_at: '',
          close_at: '',
        },
    existing !== null
      ? existing.questions.map((q) => ({
          question_text: q.question_text,
          question_type: q.question_type,
          is_required: q.is_required,
          options_text: q.options.map((o) => o.text).join('\n'),
        }))
      : [emptyQuestion()],
    existing !== null ? existing.links.map(savedToBuilderLink) : defaultBuilderLinks(),
  ), [existing]);
  const currentSnapshot = useMemo(
    () => surveyStateSnapshot(meta, questions, links),
    [meta, questions, links],
  );
  const dirty = ! immutable && currentSnapshot !== initialSnapshot;

  useEffect(() => {
    onStateChange(dirty, pending);
  }, [dirty, pending, onStateChange]);

  function patchQuestion(index: number, patch: Partial<BuilderQuestion>) {
    setQuestions((current) => current.map((q, i) => (i === index ? { ...q, ...patch } : q)));
  }

  function patchLinkRow(key: string, patch: Partial<BuilderLink>) {
    setLinks((current) => current.map((l) => (l.key === key ? { ...l, ...patch } : l)));
  }

  function toggleYearLevel(level: number, checked: boolean) {
    const current = meta.year_levels ?? [];
    setMeta({
      ...meta,
      year_levels: checked
        ? [...current, level].sort((a, b) => a - b)
        : current.filter((y) => y !== level),
    });
  }

  function save() {
    const payload = { meta, questions, links };
    if (existing !== null) {
      update.mutate({ id: existing.id, ...payload }, { onSuccess: onClose });
    } else {
      create.mutate(payload, { onSuccess: onClose });
    }
  }

  return (
    <form onSubmit={(e) => { e.preventDefault(); save(); }} className="space-y-4" noValidate>
          <div className="grid gap-4 sm:grid-cols-2">
            <div className="space-y-1.5 sm:col-span-2">
              <Label htmlFor="survey-title">Title</Label>
              <Input
                id="survey-title"
                value={meta.title}
                disabled={immutable}
                onChange={(e) => setMeta({ ...meta, title: e.target.value })}
              />
            </div>
            <div className="space-y-1.5 sm:col-span-2">
              <Label htmlFor="survey-desc">Description <span className="text-muted-foreground">(shown to students)</span></Label>
              <Textarea
                id="survey-desc"
                rows={2}
                value={meta.description ?? ''}
                disabled={immutable}
                onChange={(e) => setMeta({ ...meta, description: e.target.value })}
              />
            </div>
            <div className="space-y-1.5">
              <Label htmlFor="survey-audience">Audience</Label>
              <Select value={meta.audience} disabled={immutable} onValueChange={(v) => setMeta({ ...meta, audience: v as SurveyAudience })}>
                <SelectTrigger id="survey-audience"><SelectValue /></SelectTrigger>
                <SelectContent>
                  {(Object.keys(AUDIENCE_LABELS) as SurveyAudience[]).map((key) => (
                    <SelectItem key={key} value={key}>{AUDIENCE_LABELS[key]}</SelectItem>
                  ))}
                </SelectContent>
              </Select>
            </div>
            {/* Outer keeps the row bottom-anchored to the grid cell (level
                with the Audience select); the inner flex centers the circle
                against the text so they share one optical midline. */}
            <div className="flex items-end pb-2">
              <div className="flex items-center gap-2">
                <Checkbox
                  id="survey-required"
                  checked={meta.is_required}
                  disabled={immutable}
                  onCheckedChange={(checked) => setMeta({ ...meta, is_required: checked === true })}
                />
                <Label htmlFor="survey-required" className="cursor-pointer font-normal">
                  Required for clearance signing
                </Label>
              </div>
            </div>
            <div className="space-y-1.5 sm:col-span-2">
              <Label>Year levels <span className="text-muted-foreground">(none selected = every year level)</span></Label>
              <div className="flex flex-wrap gap-x-4 gap-y-2">
                {YEAR_LEVEL_OPTIONS.map((level) => (
                  <div key={level} className="flex items-center gap-2">
                    <Checkbox
                      id={`survey-year-${level}`}
                      checked={(meta.year_levels ?? []).includes(level)}
                      disabled={immutable}
                      onCheckedChange={(checked) => toggleYearLevel(level, checked === true)}
                    />
                    <Label htmlFor={`survey-year-${level}`} className="cursor-pointer font-normal">Year {level}</Label>
                  </div>
                ))}
              </div>
            </div>
            <div className="space-y-1.5">
              <Label htmlFor="survey-publish">Open at <span className="text-muted-foreground">(optional)</span></Label>
              <DateTimeField
                id="survey-publish"
                value={meta.publish_at ?? ''}
                disabled={immutable}
                onChange={(v) => setMeta({ ...meta, publish_at: v })}
              />
            </div>
            <div className="space-y-1.5">
              <Label htmlFor="survey-close">Closes at <span className="text-muted-foreground">(optional)</span></Label>
              <DateTimeField
                id="survey-close"
                value={meta.close_at ?? ''}
                disabled={immutable}
                onChange={(v) => setMeta({ ...meta, close_at: v })}
              />
            </div>
          </div>

        <Tabs defaultValue="questions" className="w-full">
          <TabsList className="grid w-full grid-cols-2">
            <TabsTrigger value="questions">
              Questions ({questions.filter((q) => q.question_text.trim() !== '').length})
            </TabsTrigger>
            <TabsTrigger value="links">
              Optional links ({links.filter((l) => l.title.trim() !== '' || l.external_url.trim() !== '').length})
              <Badge variant="secondary" className="ml-1.5">Optional</Badge>
            </TabsTrigger>
          </TabsList>

          <TabsContent value="questions" className="space-y-3 pt-2">
            <div className="flex items-center justify-between">
              <div>
                <Label className="text-sm font-medium">In-app survey questions</Label>
                <p className="text-xs text-muted-foreground">Questions marked Required must be answered by students before submitting.</p>
              </div>
              {!immutable && (
                <Button type="button" size="sm" variant="outline" onClick={() => setQuestions([...questions, emptyQuestion()])}>
                  <Plus aria-hidden /> Add question
                </Button>
              )}
            </div>
            {questions.map((q, index) => (
              <div key={index} className="space-y-2.5 rounded-lg border bg-background p-3">
                <div className="flex items-start gap-2">
                  <Textarea
                    rows={1}
                    placeholder={`Question ${index + 1}`}
                    value={q.question_text}
                    disabled={immutable}
                    onChange={(e) => patchQuestion(index, { question_text: e.target.value })}
                    className="min-h-9 flex-1"
                  />
                  {!immutable && (
                    <Button
                      type="button"
                      size="icon"
                      variant="ghost"
                      aria-label={`Remove question ${index + 1}`}
                      onClick={() => setQuestions((current) => current.filter((_, i) => i !== index))}
                    >
                      <Trash2 className="size-4" aria-hidden />
                    </Button>
                  )}
                </div>
                <div className="grid items-end gap-2 sm:grid-cols-[10rem_1fr_auto]">
                  <Select
                    value={q.question_type}
                    disabled={immutable}
                    onValueChange={(v) => patchQuestion(index, { question_type: v as QuestionType, options_text: '' })}
                  >
                    <SelectTrigger aria-label={`Answer type for question ${index + 1}`}><SelectValue /></SelectTrigger>
                    <SelectContent>
                      {(Object.keys(TYPE_LABELS) as QuestionType[]).map((t) => (
                        <SelectItem key={t} value={t}>{TYPE_LABELS[t]}</SelectItem>
                      ))}
                    </SelectContent>
                  </Select>
                  {['single', 'multi'].includes(q.question_type) && (
                    <Textarea
                      rows={2}
                      placeholder="One option per line (min 2)"
                      value={q.options_text}
                      disabled={immutable}
                      onChange={(e) => patchQuestion(index, { options_text: e.target.value })}
                    />
                  )}
                  {q.question_type === 'external_url' && (
                    <p className="text-xs text-muted-foreground">
                      Paste the activity link into the question text — students get a clickable link plus a completion checkbox.
                    </p>
                  )}
                  <div className="flex items-center gap-2 pb-1">
                    <Checkbox
                      id={`q-required-${index}`}
                      checked={q.is_required}
                      disabled={immutable}
                      onCheckedChange={(checked) => patchQuestion(index, { is_required: checked === true })}
                    />
                    <Label htmlFor={`q-required-${index}`} className="cursor-pointer font-normal">Required</Label>
                  </div>
                </div>
              </div>
            ))}
            {questions.length === 0 && (
              <p className="rounded-lg border border-dashed p-4 text-center text-xs text-muted-foreground">
                No questions added. This survey will be a links-only survey.
              </p>
            )}
          </TabsContent>

          <TabsContent value="links" className="space-y-3 pt-2">
            <div>
              <Label className="text-sm font-medium">Optional links (Survey, Assessment &amp; Evaluation)</Label>
              <p className="text-xs text-muted-foreground">
                All external links are optional and never block student submission. Turn on &ldquo;Requires screenshot&rdquo; if you want proof of an assessment result.
              </p>
            </div>

            {links.map((row) => {
              const saved = existing?.links.find((l) => l.id === row.id);
              return (
                <div key={row.key} className="space-y-2 rounded-lg border bg-background p-3">
                  <div className="flex items-start justify-between gap-2">
                    <Badge variant="secondary">{LINK_TYPE_LABELS[row.type]}</Badge>
                    {!immutable && (
                      <Button
                        type="button"
                        size="icon"
                        variant="ghost"
                        aria-label={`Remove ${LINK_TYPE_LABELS[row.type]} row`}
                        onClick={() => setLinks((current) => current.filter((l) => l.key !== row.key))}
                      >
                        <Trash2 className="size-4" aria-hidden />
                      </Button>
                    )}
                  </div>
                  <div className="grid gap-2 sm:grid-cols-2">
                    <div className="space-y-1.5">
                      <Label htmlFor={`link-title-${row.key}`}>Title</Label>
                      <Input
                        id={`link-title-${row.key}`}
                        value={row.title}
                        onChange={(e) => patchLinkRow(row.key, { title: e.target.value })}
                        placeholder="e.g. Multiple Intelligences (MI)"
                      />
                    </div>
                    <div className="space-y-1.5">
                      <Label htmlFor={`link-url-${row.key}`}>External URL</Label>
                      <Input
                        id={`link-url-${row.key}`}
                        value={row.external_url}
                        onChange={(e) => patchLinkRow(row.key, { external_url: e.target.value })}
                        placeholder="https://…"
                        inputMode="url"
                      />
                    </div>
                    <div className="space-y-1.5 sm:col-span-2">
                      <Label htmlFor={`link-desc-${row.key}`}>Instructions <span className="text-muted-foreground">(shown to students)</span></Label>
                      <Input
                        id={`link-desc-${row.key}`}
                        value={row.description}
                        disabled={immutable}
                        onChange={(e) => patchLinkRow(row.key, { description: e.target.value })}
                        placeholder="Short instructions for the student"
                      />
                    </div>
                    {row.type === 'test' && (
                      <div className="space-y-1.5">
                        <Label htmlFor={`link-instrument-${row.key}`}>Instrument</Label>
                        <Select
                          value={row.instrument_key ?? 'custom'}
                          disabled={immutable}
                          onValueChange={(v) => {
                            const instrument = v as SurveyLinkInstrument;
                            const canonical = Object.values(INSTRUMENT_LABELS);
                            patchLinkRow(row.key, {
                              instrument_key: instrument,
                              title: row.title.trim() === '' || canonical.includes(row.title.trim())
                                ? INSTRUMENT_LABELS[instrument]
                                : row.title,
                            });
                          }}
                        >
                          <SelectTrigger id={`link-instrument-${row.key}`}><SelectValue /></SelectTrigger>
                          <SelectContent>
                            {(Object.keys(INSTRUMENT_LABELS) as SurveyLinkInstrument[]).map((key) => (
                              <SelectItem key={key} value={key}>{INSTRUMENT_LABELS[key]}</SelectItem>
                            ))}
                          </SelectContent>
                        </Select>
                      </div>
                    )}
                    {row.type === 'survey' && (
                      <div className="space-y-1.5">
                        <Label htmlFor={`link-audience-${row.key}`}>Audience</Label>
                        <Select
                          value={row.audience ?? 'all'}
                          disabled={immutable}
                          onValueChange={(v) => patchLinkRow(row.key, { audience: v === 'all' ? null : v as SurveyAudience })}
                        >
                          <SelectTrigger id={`link-audience-${row.key}`}><SelectValue /></SelectTrigger>
                          <SelectContent>
                            <SelectItem value="all">Any audience</SelectItem>
                            {(Object.keys(AUDIENCE_LABELS) as SurveyAudience[]).filter((a) => a !== 'all').map((key) => (
                              <SelectItem key={key} value={key}>{AUDIENCE_LABELS[key]}</SelectItem>
                            ))}
                          </SelectContent>
                        </Select>
                      </div>
                    )}
                    <div className="flex flex-wrap items-center gap-4 pb-1 sm:col-span-2">
                      {row.type === 'test' && (
                        <div className="flex items-center gap-2">
                          <Checkbox
                            id={`link-screenshot-${row.key}`}
                            checked={row.requires_screenshot}
                            disabled={immutable}
                            onCheckedChange={(checked) => patchLinkRow(row.key, { requires_screenshot: checked === true })}
                          />
                          <Label htmlFor={`link-screenshot-${row.key}`} className="cursor-pointer font-normal">Requires screenshot</Label>
                        </div>
                      )}
                      <div className="flex items-center gap-2">
                        <Checkbox
                          id={`link-enabled-${row.key}`}
                          checked={row.is_enabled}
                          disabled={immutable}
                          onCheckedChange={(checked) => patchLinkRow(row.key, { is_enabled: checked === true })}
                        />
                        <Label htmlFor={`link-enabled-${row.key}`} className="cursor-pointer font-normal">Enabled</Label>
                      </div>
                      {immutable && row.id !== undefined && (
                        <LinkFixButton surveyId={existing.id} row={row} saved={saved} />
                      )}
                    </div>
                  </div>
                </div>
              );
            })}

            {!immutable && (
              <div className="flex flex-wrap gap-2">
                <Button type="button" size="sm" variant="outline" onClick={() => setLinks((current) => [...current, emptyLink('survey')])}>
                  <Plus aria-hidden /> Add survey link
                </Button>
                <Button type="button" size="sm" variant="outline" onClick={() => setLinks((current) => [...current, emptyLink('test')])}>
                  <Plus aria-hidden /> Add assessment link
                </Button>
                {links.every((l) => l.type !== 'evaluation') && (
                  <Button type="button" size="sm" variant="outline" onClick={() => setLinks((current) => [...current, emptyLink('evaluation')])}>
                    <Plus aria-hidden /> Add evaluation link
                  </Button>
                )}
              </div>
            )}
            {!validLinks && (
              <p className="text-xs text-destructive" role="alert">
                Every link row needs a title and a public http(s) URL.
              </p>
            )}
          </TabsContent>
        </Tabs>

        <DialogFooter>
          <Button type="button" variant="outline" onClick={requestClose} disabled={pending}>Cancel</Button>
          <Button type="button" onClick={save} disabled={pending || immutable || ! validMeta || ! validQuestions || ! validLinks || ! hasContent}>
            <ClipboardList aria-hidden /> {pending ? 'Saving…' : existing !== null ? 'Save draft' : 'Create draft'}
          </Button>
        </DialogFooter>
    </form>
  );
}

/**
 * Narrow live hot-fix: retitles / re-URLs one link on a published
 * survey without unfreezing the question set. A dead external link is
 * an operations emergency; required/enabled stay frozen.
 */
function LinkFixButton({
  surveyId,
  row,
  saved,
}: {
  surveyId: number;
  row: BuilderLink;
  saved: SurveyLink | undefined;
}) {
  const patch = usePatchSurveyLink();
  const changed = saved !== undefined
    && (row.title.trim() !== saved.title || row.external_url.trim() !== saved.external_url);

  return (
    <Button
      type="button"
      size="sm"
      variant="outline"
      disabled={!changed || ! linkRowIsComplete(row) || patch.isPending}
      title="Live surveys keep questions frozen — this rewrites only the link."
      onClick={() => patch.mutate({
        surveyId,
        linkId: row.id as number,
        title: row.title.trim(),
        external_url: row.external_url.trim(),
      })}
    >
      {patch.isPending ? 'Saving…' : 'Save link fix'}
    </Button>
  );
}

/** Streams one proof image back through the audited, authed endpoint. */
async function downloadProof(surveyId: number, responseId: number, shot: SurveyLinkScreenshot): Promise<void> {
  const res = await apiClient.get<Blob>(
    `/counselling/surveys/${surveyId}/responses/${responseId}/screenshots/${shot.id}`,
    { responseType: 'blob' },
  );
  const url = URL.createObjectURL(res.data);
  const anchor = document.createElement('a');
  anchor.href = url;
  anchor.download = shot.original_name;
  anchor.click();
  URL.revokeObjectURL(url);
}

function ProofRow({
  surveyId,
  responseId,
  link,
  onView,
}: {
  surveyId: number;
  responseId: number;
  link: SurveyResponseLink;
  onView: (link: SurveyResponseLink) => void;
}) {
  const url = useResponseScreenshotUrl(surveyId, responseId, link.screenshot?.id ?? null);

  return (
    <div className="flex items-center justify-between gap-2 rounded-lg border bg-background p-2.5">
      <div className="min-w-0">
        <p className="truncate text-xs font-medium text-foreground">{link.title}</p>
        <p className="text-xs text-muted-foreground">
          Optional{link.screenshot !== null ? ' · Screenshot uploaded' : ''} · {link.opened ? 'Opened' : 'Not opened'}
        </p>
      </div>
      {link.screenshot !== null ? (
        <div className="flex shrink-0 items-center gap-1.5">
          {url.data !== undefined && (
            <img
              src={url.data}
              alt={`Result screenshot for ${link.title}`}
              className="h-10 w-14 rounded border object-cover"
            />
          )}
          <Button size="sm" variant="ghost" onClick={() => onView(link)}>View Result</Button>
          <Button
            size="sm"
            variant="ghost"
            aria-label={`Download ${link.screenshot.original_name}`}
            onClick={() => void downloadProof(surveyId, responseId, link.screenshot as SurveyLinkScreenshot)}
          >
            <Download className="size-3.5" aria-hidden />
          </Button>
        </div>
      ) : (
        <span className="shrink-0 text-xs text-muted-foreground">No screenshot</span>
      )}
    </div>
  );
}

function ProofLightbox({
  surveyId,
  responseId,
  link,
  onClose,
}: {
  surveyId: number;
  responseId: number;
  link: SurveyResponseLink;
  onClose: () => void;
}) {
  const url = useResponseScreenshotUrl(surveyId, responseId, link.screenshot?.id ?? null);

  return (
    <Dialog open onOpenChange={(open) => ! open && onClose()}>
      <DialogContent className="sm:max-w-3xl">
        <DialogHeader>
          <DialogTitle>{link.title} — result screenshot</DialogTitle>
          <DialogDescription>{link.screenshot?.original_name}</DialogDescription>
        </DialogHeader>
        {url.data !== undefined ? (
          <img
            src={url.data}
            alt={`Result screenshot for ${link.title}`}
            className="max-h-[70dvh] w-full rounded-lg border object-contain"
          />
        ) : (
          <Skeleton className="h-64" />
        )}
      </DialogContent>
    </Dialog>
  );
}

function AggregateSummary({ survey }: { survey: Survey }) {
  const aggregate = useSurveyAggregate(survey.id);
  const questions = aggregate.data?.questions ?? [];

  return (
    <div className="space-y-3 rounded-lg border bg-muted/20 p-4" aria-label="Aggregate results">
      <p className="text-xs font-semibold uppercase tracking-wide text-muted-foreground">
        Term aggregate — {aggregate.data?.response_count ?? '…'} response{(aggregate.data?.response_count ?? 0) === 1 ? '' : 's'}
      </p>
      {aggregate.isLoading && <Skeleton className="h-16" />}
      {aggregate.isError && (
        <p className="text-xs text-destructive">Failed to load the aggregate.</p>
      )}
      {aggregate.data !== undefined && questions.length === 0 && (
        <p className="text-xs text-muted-foreground">Nothing to aggregate yet.</p>
      )}
      {questions.map((q) => (
        <div key={q.question_id}>
          <p className="text-xs font-medium text-foreground">{q.question_text}</p>
          {q.average !== null && q.average !== undefined && (
            <p className="text-xs text-muted-foreground">
              Average {q.average} / 5 · {q.answered} answered
            </p>
          )}
          {q.scale_counts !== undefined && (
            <div className="mt-1 flex gap-1.5">
              {Object.entries(q.scale_counts).map(([score, count]) => (
                <Badge key={score} variant="secondary" className="text-[0.625rem]">{score}: {count}</Badge>
              ))}
            </div>
          )}
          {q.option_counts !== undefined && (
            <div className="mt-1 flex flex-wrap gap-1.5">
              {Object.entries(q.option_counts).map(([text, count]) => (
                <Badge key={text} variant="secondary" className="text-[0.625rem]">{text}: {count}</Badge>
              ))}
            </div>
          )}
          {(q.average === null || q.average === undefined) && q.scale_counts === undefined && q.option_counts === undefined && (
            <p className="text-xs text-muted-foreground">{q.answered} answered (free-form)</p>
          )}
        </div>
      ))}
    </div>
  );
}

function ResponsesDialog({ survey, onClose }: { survey: Survey; onClose: () => void }) {
  // Dialog-scoped filter (not a page view — no URL param needed).
  const [yearFilter, setYearFilter] = useState<number | null>(null);
  const responses = useSurveyResponses(survey.id, yearFilter);
  // The list payload carries no questions — fetch the detail for labels.
  const surveyDetail = useSurvey(survey.id);
  const [selectedId, setSelectedId] = useState<number | null>(null);
  const [viewing, setViewing] = useState<SurveyResponseLink | null>(null);
  const detail = useSurveyResponseDetail(survey.id, selectedId);
  const rows = responses.data ?? [];
  const questionsById = new Map((surveyDetail.data?.questions ?? []).map((q) => [q.id, q]));

  return (
    <Dialog open onOpenChange={(open) => ! open && onClose()}>
      <DialogContent className="max-h-[92dvh] overflow-y-auto sm:max-w-2xl">
        <DialogHeader>
          <DialogTitle>Responses — {survey.title}</DialogTitle>
          <DialogDescription>
            {rows.length} submission{rows.length === 1 ? '' : 's'}. Opening a response records an audit event.
          </DialogDescription>
        </DialogHeader>
        <div className="flex items-center gap-2">
          <Label htmlFor="responses-year-filter" className="text-xs text-muted-foreground">Year level</Label>
          <Select
            value={yearFilter === null ? 'all' : String(yearFilter)}
            onValueChange={(v) => setYearFilter(v === 'all' ? null : Number(v))}
          >
            <SelectTrigger id="responses-year-filter" className="h-8 w-32"><SelectValue /></SelectTrigger>
            <SelectContent>
              <SelectItem value="all">All years</SelectItem>
              {YEAR_LEVEL_OPTIONS.map((y) => (
                <SelectItem key={y} value={String(y)}>Year {y}</SelectItem>
              ))}
            </SelectContent>
          </Select>
        </div>
        <AggregateSummary survey={survey} />
        <TableStateBlock
          isLoading={responses.isLoading}
          isError={responses.isError}
          isEmpty={rows.length === 0}
          onRetry={() => void responses.refetch()}
          pending={responses.isFetching}
          errorMessage="Failed to load responses."
          loadingLabel="Loading responses"
          skeletonRows={1}
          empty={{
            title: 'No submissions yet.',
            description: 'Responses appear as students complete the survey.',
          }}
        />
        {!responses.isLoading && !responses.isError && rows.length > 0 && (
          <div className="overflow-hidden rounded-lg border">
            <Table ariaLabel={`Responses to ${survey.title}`}>
              <TableHeader className="bg-muted/50">
                <TableRow>
                  <TableHead className="px-3">Student</TableHead>
                  <TableHead className="px-3">Year</TableHead>
                  <TableHead className="px-3">Submitted</TableHead>
                  <TableHead className="px-3 text-right">Answers</TableHead>
                </TableRow>
              </TableHeader>
              <TableBody>
                {rows.map((r) => (
                  <Fragment key={r.id}>
                    <TableRow
                      data-state={selectedId === r.id ? 'selected' : undefined}
                      className="cursor-pointer"
                      onClick={() => setSelectedId(r.id === selectedId ? null : r.id)}
                    >
                      <TableCell className="px-3 text-sm">
                        {r.student_name ?? r.email ?? `#${r.student_user_id}`}
                      </TableCell>
                      <TableCell className="px-3 text-sm tabular-nums">
                        {r.student_year_level ?? '—'}
                      </TableCell>
                      <TableCell className="px-3 text-xs">{fmtUtcToApp(r.submitted_at)}</TableCell>
                      <TableCell className="px-3 text-right">
                        <Button size="sm" variant="ghost" aria-label={`Open response ${r.id}`}>
                          <Eye className="size-3.5" aria-hidden />
                        </Button>
                      </TableCell>
                    </TableRow>
                    {selectedId === r.id && (
                      <TableRow>
                        <TableCell colSpan={4} className="bg-muted/20 p-4">
                          {detail.isLoading && <Skeleton className="h-16" />}
                          {detail.data !== undefined && (
                            <div className="space-y-3">
                              <ul className="space-y-2.5">
                                {detail.data.answers.map((a) => {
                                  const question = questionsById.get(a.question_id);
                                  return (
                                    <li key={a.question_id}>
                                      <p className="text-xs font-medium text-foreground">
                                        {question?.question_text ?? `Question #${a.question_id}`}
                                      </p>
                                      <p className="text-sm text-muted-foreground">
                                        {typeof a.value === 'boolean'
                                          ? 'Completed'
                                          : Array.isArray(a.value)
                                            ? (a.value as Array<number | string>)
                                                .map((id) => question?.options.find((o) => o.id === id)?.text ?? String(id))
                                                .join(', ')
                                            : String(a.value)}
                                      </p>
                                    </li>
                                  );
                                })}
                              </ul>
                              {detail.data.links.length > 0 && (
                                <div className="space-y-2">
                                  <p className="text-xs font-semibold uppercase tracking-wide text-muted-foreground">
                                    Assessment proofs
                                  </p>
                                  {detail.data.links.map((link) => (
                                    <ProofRow
                                      key={link.link_id}
                                      surveyId={survey.id}
                                      responseId={detail.data.id}
                                      link={link}
                                      onView={setViewing}
                                    />
                                  ))}
                                </div>
                              )}
                            </div>
                          )}
                        </TableCell>
                      </TableRow>
                    )}
                  </Fragment>
                ))}
              </TableBody>
            </Table>
          </div>
        )}
        <DialogFooter>
          <Button variant="outline" onClick={onClose}>Close</Button>
        </DialogFooter>
        {viewing !== null && detail.data !== undefined && (
          <ProofLightbox
            surveyId={survey.id}
            responseId={detail.data.id}
            link={viewing}
            onClose={() => setViewing(null)}
          />
        )}
      </DialogContent>
    </Dialog>
  );
}

export function SurveysTab() {
  const surveys = useSurveys();
  const publish = usePublishSurvey();
  const archive = useArchiveSurvey();
  const [builderOpen, setBuilderOpen] = useState(false);
  const [editingId, setEditingId] = useState<number | null>(null);
  const [responsesFor, setResponsesFor] = useState<Survey | null>(null);
  const [confirm, setConfirm] = useState<ConfirmAction | null>(null);
  const rows = surveys.data ?? [];

  function requestPublish(s: Survey) {
    setConfirm({
      title: `Publish "${s.title}"?`,
      description: 'Publishing snapshots the questions — the survey becomes immutable. Students in the audience can answer while the window is open.',
      confirmLabel: 'Publish',
      run: () => publish.mutate({ id: s.id, title: s.title }, { onSuccess: () => setConfirm(null) }),
    });
  }

  function requestArchive(s: Survey) {
    setConfirm({
      title: `Archive "${s.title}"?`,
      description: 'It disappears from the catalogue; existing responses stay stored and auditable.',
      confirmLabel: 'Archive',
      run: () => archive.mutate({ id: s.id, title: s.title }, { onSuccess: () => setConfirm(null) }),
    });
  }

  return (
    <div className="space-y-4">
      <div className="flex justify-end">
        <Button onClick={() => { setEditingId(null); setBuilderOpen(true); }}>
          <Plus aria-hidden /> New survey
        </Button>
      </div>

      <TableStateBlock
        isLoading={surveys.isLoading}
        isError={surveys.isError}
        isEmpty={rows.length === 0}
        onRetry={() => void surveys.refetch()}
        pending={surveys.isFetching}
        errorMessage="Failed to load surveys."
        loadingLabel="Loading surveys"
        empty={{
          title: 'No surveys yet',
          description: "Build the first evaluation or needs assessment — it replaces the office's Google Forms.",
          action: (
            <Button
              variant="outline"
              size="sm"
              onClick={() => {
                setEditingId(null);
                setBuilderOpen(true);
              }}
            >
              <Plus aria-hidden /> New survey
            </Button>
          ),
        }}
      />

      {!surveys.isLoading && !surveys.isError && rows.length > 0 && (
        <section aria-labelledby="survey-list-heading" className="overflow-hidden rounded-xl border bg-card">
          <h2 id="survey-list-heading" className="sr-only">Surveys</h2>
          {/* The Table's own wrapper is the scroll container — an extra
              overflow-x-auto div here would defeat the sticky header. */}
          <Table
            ariaLabel="Surveys and their response counts"
            wrapperClassName="max-h-[60vh] overflow-y-auto"
            className="[&_td]:py-1.5"
          >
            <TableHeader className="sticky top-0 z-10 bg-muted shadow-[0_1px_0_0_var(--border)]">
                <TableRow>
                  <TableHead className="px-3">Survey</TableHead>
                  <TableHead className="px-3">Audience</TableHead>
                  <TableHead className="px-3">Responses</TableHead>
                  <TableHead className="px-3">Status</TableHead>
                  <TableHead className="px-3 text-right">Actions</TableHead>
                </TableRow>
              </TableHeader>
              <TableBody>
                {rows.map((s) => (
                  <TableRow key={s.id}>
                    <TableCell className="max-w-72 px-3">
                      <div className="flex items-center gap-2 truncate text-sm font-medium">
                        {s.is_required && <Badge variant="warning">Required</Badge>}
                        {s.title}
                      </div>
                      <p className="truncate text-xs text-muted-foreground">
                        {s.question_count} question{s.question_count === 1 ? '' : 's'}
                        {s.close_at !== null && ` · closes ${fmtUtcToApp(s.close_at, 'MMM d, yyyy')}`}
                      </p>
                    </TableCell>
                    <TableCell className="px-3 text-sm">
                      {AUDIENCE_LABELS[s.audience]}
                      {s.year_levels !== null && s.year_levels.length > 0 && (
                        <span className="text-muted-foreground"> · {s.year_levels.map((y) => `Year ${y}`).join(', ')}</span>
                      )}
                    </TableCell>
                    <TableCell className="px-3 text-sm">
                      {s.status === 'draft' ? '—' : `${s.response_count} submitted`}
                    </TableCell>
                    <TableCell className="px-3"><Badge variant={STATUS_VARIANTS[s.status]}>{titleCase(s.status)}</Badge></TableCell>
                    <TableCell className="px-3 text-right">
                      <div className="flex justify-end gap-2">
                        {s.status === 'draft' ? (
                          <>
                            <Button size="sm" variant="outline" onClick={() => { setEditingId(s.id); setBuilderOpen(true); }}>
                              <Pencil aria-hidden /> Edit
                            </Button>
                            <Button size="sm" onClick={() => requestPublish(s)}>
                              <Send aria-hidden /> Publish
                            </Button>
                          </>
                        ) : (
                          <Button size="sm" variant="outline" onClick={() => setResponsesFor(s)}>
                            <Eye aria-hidden /> Responses
                          </Button>
                        )}
                        <Button size="sm" variant="destructive" onClick={() => requestArchive(s)}>Archive</Button>
                      </div>
                    </TableCell>
                  </TableRow>
                ))}
              </TableBody>
            </Table>
        </section>
      )}

      {builderOpen && <SurveyBuilderDialog surveyId={editingId} onClose={() => setBuilderOpen(false)} />}
      {responsesFor !== null && <ResponsesDialog survey={responsesFor} onClose={() => setResponsesFor(null)} />}
      <ConfirmDialog
        open={confirm !== null}
        title={confirm?.title ?? ''}
        description={confirm?.description}
        confirmLabel={confirm?.confirmLabel}
        pending={publish.isPending || archive.isPending}
        onConfirm={() => confirm?.run()}
        onCancel={() => setConfirm(null)}
      />
    </div>
  );
}
