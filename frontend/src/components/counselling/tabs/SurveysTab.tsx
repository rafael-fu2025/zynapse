/**
 * SurveysTab — guidance surveys builder + responses (parity plan Phase
 * B). Draft → publish (immutable version) → archive; responses per
 * survey are identity-linked (requirements context) and every answer
 * detail read is audited server-side.
 */
import { ClipboardList, Eye, Pencil, Plus, Send, Trash2 } from 'lucide-react';
import { Fragment, useEffect, useMemo, useRef, useState } from 'react';
import { DateTimeField } from '@/components/DateTimeField';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import { ConfirmDialog, type ConfirmAction } from '@/components/ConfirmDialog';
import { QueryErrorState } from '@/components/QueryErrorState';
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
import { Textarea } from '@/components/ui/textarea';
import { useSurveyAggregate } from '@/hooks/useGuidanceFollowups';
import {
  useArchiveSurvey,
  useCreateSurvey,
  usePublishSurvey,
  useSurvey,
  useSurveyResponseDetail,
  useSurveyResponses,
  useSurveys,
  useUpdateSurvey,
} from '@/hooks/useSurveys';
import type { Survey } from '@/schemas/surveys';
import {
  surveyMetaInputSchema,
  type BuilderQuestion,
  type QuestionType,
  type SurveyAudience,
  type SurveyMetaInput,
} from '@/schemas/surveys';
import { fmtUtcToApp } from '@/utils/date';

const AUDIENCE_LABELS: Record<SurveyAudience, string> = {
  all: 'All students',
  new_students: 'New students',
  continuing_students: 'Continuing students',
  graduating_students: 'Graduating students',
};

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

function emptyQuestion(): BuilderQuestion {
  return { question_text: '', question_type: 'free_text', is_required: false, options_text: '' };
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
function surveyStateSnapshot(meta: SurveyMetaInput, questions: BuilderQuestion[]): string {
  return JSON.stringify({
    title: meta.title.trim(),
    description: (meta.description ?? '').trim(),
    audience: meta.audience,
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
        category: existing.category,
        is_required: existing.is_required,
        publish_at: existing.publish_at ?? '',
        close_at: existing.close_at ?? '',
      }
    : {
        title: '',
        description: '',
        audience: 'all',
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

  const immutable = existing !== null && existing.status !== 'draft';
  const validMeta = surveyMetaInputSchema.safeParse({ ...meta, description: meta.description ?? undefined }).success;
  const validQuestions = questions.every(
    (q) => q.question_text.trim() === '' ||
      (['single', 'multi'].includes(q.question_type)
        ? q.options_text.split('\n').map((l) => l.trim()).filter(Boolean).length >= 2
        : true),
  );

  // Dirty tracking for the unsaved-changes guard. Immutable surveys are
  // never dirty (every field is disabled).
  const initialSnapshot = useMemo(() => surveyStateSnapshot(
    existing !== null
      ? {
          title: existing.title,
          description: existing.description ?? '',
          audience: existing.audience,
          category: existing.category,
          is_required: existing.is_required,
          publish_at: existing.publish_at ?? '',
          close_at: existing.close_at ?? '',
        }
      : {
          title: '',
          description: '',
          audience: 'all',
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
  ), [existing]);
  const currentSnapshot = useMemo(
    () => surveyStateSnapshot(meta, questions),
    [meta, questions],
  );
  const dirty = ! immutable && currentSnapshot !== initialSnapshot;

  useEffect(() => {
    onStateChange(dirty, pending);
  }, [dirty, pending, onStateChange]);

  function patchQuestion(index: number, patch: Partial<BuilderQuestion>) {
    setQuestions((current) => current.map((q, i) => (i === index ? { ...q, ...patch } : q)));
  }

  function save() {
    const payload = { meta, questions };
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
            <div className="flex items-end space-x-2 pb-2">
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

          <div className="space-y-3">
            <div className="flex items-center justify-between">
              <Label className="text-sm font-medium">Questions</Label>
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
                  {!immutable && questions.length > 1 && (
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
                      Paste the activity link (e.g. a Google Form or EducationPlanner test) into the question text —
                      students get a clickable link plus a completion checkbox that feeds the clearance gate.
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
        </div>

        <DialogFooter>
          <Button type="button" variant="outline" onClick={requestClose} disabled={pending}>Cancel</Button>
          <Button type="button" onClick={save} disabled={pending || immutable || ! validMeta || ! validQuestions}>
            <ClipboardList aria-hidden /> {pending ? 'Saving…' : existing !== null ? 'Save draft' : 'Create draft'}
          </Button>
        </DialogFooter>
    </form>
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
                <Badge key={score} variant="secondary" className="text-[10px]">{score}: {count}</Badge>
              ))}
            </div>
          )}
          {q.option_counts !== undefined && (
            <div className="mt-1 flex flex-wrap gap-1.5">
              {Object.entries(q.option_counts).map(([text, count]) => (
                <Badge key={text} variant="secondary" className="text-[10px]">{text}: {count}</Badge>
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
  const responses = useSurveyResponses(survey.id);
  // The list payload carries no questions — fetch the detail for labels.
  const surveyDetail = useSurvey(survey.id);
  const [selectedId, setSelectedId] = useState<number | null>(null);
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
        <AggregateSummary survey={survey} />
        {responses.isLoading && <Skeleton className="h-24" />}
        {responses.isError && (
          <QueryErrorState message="Failed to load responses." onRetry={() => void responses.refetch()} pending={responses.isFetching} />
        )}
        {responses.data !== undefined && rows.length === 0 && (
          <p className="py-4 text-center text-sm text-muted-foreground">No submissions yet.</p>
        )}
        {rows.length > 0 && (
          <div className="overflow-hidden rounded-lg border">
            <Table>
              <TableHeader className="bg-muted/50">
                <TableRow>
                  <TableHead className="px-3">Student</TableHead>
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
                      <TableCell className="px-3 text-xs">{fmtUtcToApp(r.submitted_at)}</TableCell>
                      <TableCell className="px-3 text-right">
                        <Button size="sm" variant="ghost" aria-label={`Open response ${r.id}`}>
                          <Eye className="size-3.5" aria-hidden />
                        </Button>
                      </TableCell>
                    </TableRow>
                    {selectedId === r.id && (
                      <TableRow>
                        <TableCell colSpan={3} className="bg-muted/20 p-4">
                          {detail.isLoading && <Skeleton className="h-16" />}
                          {detail.data !== undefined && (
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

      {surveys.isError && (
        <QueryErrorState message="Failed to load surveys." onRetry={() => void surveys.refetch()} pending={surveys.isFetching} />
      )}

      {surveys.isLoading && (
        <div role="status" aria-label="Loading surveys" className="space-y-3">
          <Skeleton className="h-14 w-full" />
          <Skeleton className="h-14 w-full" />
        </div>
      )}

      {surveys.data !== undefined && rows.length === 0 && (
        <section className="rounded-xl border bg-card p-8 text-center">
          <p className="font-medium text-foreground">No surveys yet</p>
          <p className="mt-1 text-sm text-muted-foreground">
            Build the first evaluation or needs assessment — it replaces the office's Google Forms.
          </p>
        </section>
      )}

      {surveys.data !== undefined && rows.length > 0 && (
        <section aria-labelledby="survey-list-heading" className="overflow-hidden rounded-xl border bg-card">
          <h2 id="survey-list-heading" className="sr-only">Surveys</h2>
          <div className="overflow-x-auto">
            <Table>
              <TableHeader className="bg-muted/50">
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
                      <p className="flex items-center gap-2 truncate text-sm font-medium">
                        {s.is_required && <Badge variant="warning">Required</Badge>}
                        {s.title}
                      </p>
                      <p className="truncate text-xs text-muted-foreground">
                        {s.question_count} question{s.question_count === 1 ? '' : 's'}
                        {s.close_at !== null && ` · closes ${fmtUtcToApp(s.close_at, 'MMM d, yyyy')}`}
                      </p>
                    </TableCell>
                    <TableCell className="px-3 text-sm">{AUDIENCE_LABELS[s.audience]}</TableCell>
                    <TableCell className="px-3 text-sm">
                      {s.status === 'draft' ? '—' : `${s.response_count} submitted`}
                    </TableCell>
                    <TableCell className="px-3"><Badge variant={STATUS_VARIANTS[s.status]}>{s.status}</Badge></TableCell>
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
          </div>
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
