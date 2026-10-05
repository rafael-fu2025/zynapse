/**
 * TakeSurveyDialog — student survey/interview runner in two tabs:
 * "Questions" (in-app, the only submission gate) and "Optional links"
 * (survey links, assessments, evaluation — never gating, editable even
 * after submitting while the window is live). The backend owns
 * validation, one-submission-per-student, and the encrypted record.
 */
import { Check, Circle, ExternalLink, Upload, X } from 'lucide-react';
import { useEffect, useMemo, useRef, useState } from 'react';
import { toast } from 'sonner';
import { QueryErrorState } from '@/components/QueryErrorState';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import {
  Dialog,
  DialogContent,
  DialogDescription,
  DialogFooter,
  DialogHeader,
  DialogTitle,
} from '@/components/ui/dialog';
import { Label } from '@/components/ui/label';
import { Skeleton } from '@/components/ui/skeleton';
import { Tabs, TabsContent, TabsList, TabsTrigger } from '@/components/ui/tabs';
import { Textarea } from '@/components/ui/textarea';
import type { ApiEnvelopeError } from '@/api/envelope';
import {
  useMyScreenshotUrl,
  useMySurveyForm,
  useOpenSurveyLink,
  useRemoveScreenshot,
  useSubmitSurvey,
  useUploadScreenshot,
} from '@/hooks/useSurveys';
import type { MySurveyForm, SurveyLink, SurveyLinkScreenshot } from '@/schemas/surveys';
import {
  SCREENSHOT_ACCEPT,
  SCREENSHOT_MAX_BYTES,
  surveyGating,
  type SurveyAnswerValue,
} from '@/utils/surveyGating';

type AnswerValue = SurveyAnswerValue;
type RunnerTab = 'questions' | 'links';

const LIKERT_LABELS = ['Very poor', 'Poor', 'Fair', 'Good', 'Very good'];
const AGREE_LABELS = ['Strongly disagree', 'Disagree', 'Neutral', 'Agree', 'Strongly agree'];

function ScaleRow({
  questionId,
  value,
  onChange,
  labels,
  disabled,
}: {
  questionId: number;
  value: number | null;
  onChange: (n: number) => void;
  labels: string[];
  disabled: boolean;
}) {
  return (
    <div className="flex flex-wrap gap-2" role="radiogroup" aria-label={`Question ${questionId}`}>
      {labels.map((label, i) => (
        <Button
          key={label}
          type="button"
          size="sm"
          variant={value === i + 1 ? 'default' : 'outline'}
          className="min-w-20 flex-col gap-0 py-1.5"
          aria-pressed={value === i + 1}
          disabled={disabled}
          onClick={() => onChange(i + 1)}
        >
          <span>{i + 1}</span>
          <span className="text-[0.625rem] font-normal">{label}</span>
        </Button>
      ))}
    </div>
  );
}

/** External-link button: opens the tab synchronously (popup gesture), then logs the attestation. */
function OpenLinkButton({
  link,
  label,
  opened,
  onOpened,
}: {
  link: SurveyLink;
  label: string;
  opened: boolean;
  onOpened: (link: SurveyLink) => void;
}) {
  return (
    <div className="flex items-center gap-2">
      <Button type="button" size="sm" variant="outline" onClick={() => onOpened(link)}>
        {label} <ExternalLink className="size-3.5" aria-hidden />
      </Button>
      {opened && (
        <span className="inline-flex items-center gap-1 text-xs font-medium text-primary">
          <Check className="size-3.5" aria-hidden /> Opened
        </span>
      )}
    </div>
  );
}

/** One optional assessment link: Take Test + screenshot upload when the link asks for one. */
function TestLinkCard({
  surveyId,
  link,
  opened,
  screenshot,
  localPreviewUrl,
  onOpened,
  onUpload,
  onRemove,
  busy,
}: {
  surveyId: number;
  link: SurveyLink;
  opened: boolean;
  screenshot: SurveyLinkScreenshot | undefined;
  /** Object URL of a just-picked local file (takes precedence over the server preview). */
  localPreviewUrl: string | null;
  onOpened: (link: SurveyLink) => void;
  onUpload: (link: SurveyLink, file: File) => void;
  onRemove: (link: SurveyLink) => void;
  busy: boolean;
}) {
  const inputRef = useRef<HTMLInputElement>(null);
  const [error, setError] = useState<string | null>(null);
  // Restored proofs stream back through an authed blob fetch (an
  // <img src> cannot carry the bearer token).
  const serverPreview = useMyScreenshotUrl(surveyId, localPreviewUrl === null && screenshot !== undefined ? link.id : null);
  const previewSrc = localPreviewUrl ?? serverPreview.data ?? null;

  function pick(file: File | undefined) {
    if (file === undefined) return;
    if (!SCREENSHOT_ACCEPT.includes(file.type)) {
      setError('Screenshots must be JPG or PNG images.');
      return;
    }
    if (file.size > SCREENSHOT_MAX_BYTES) {
      setError('Screenshots must be 5 MB or smaller.');
      return;
    }
    setError(null);
    onUpload(link, file);
  }

  return (
    <div className="space-y-2 rounded-lg border bg-background p-4" aria-label={link.title}>
      <div className="flex items-start justify-between gap-2">
        <div>
          <p className="text-sm font-medium text-foreground">{link.title}</p>
          {link.description !== null && link.description !== '' && (
            <p className="text-xs text-muted-foreground">{link.description}</p>
          )}
        </div>
        {screenshot !== undefined && <Badge variant="success">✓ Screenshot uploaded</Badge>}
      </div>
      <div className="flex flex-wrap items-center gap-2">
        <OpenLinkButton link={link} label="Take Test" opened={opened} onOpened={onOpened} />
        {link.requires_screenshot && (
          <>
            <Button type="button" size="sm" variant="outline" disabled={busy} onClick={() => inputRef.current?.click()}>
              <Upload className="size-3.5" aria-hidden /> Upload Screenshot Result
            </Button>
            <input
              ref={inputRef}
              type="file"
              accept={SCREENSHOT_ACCEPT.join(',')}
              className="hidden"
              onChange={(e) => {
                pick(e.target.files?.[0]);
                e.target.value = '';
              }}
            />
          </>
        )}
      </div>
      {link.requires_screenshot && (
        <div
          role="button"
          tabIndex={0}
          aria-label={`Dropzone for ${link.title}`}
          className="flex min-h-16 cursor-pointer items-center justify-center rounded-lg border border-dashed p-2 text-xs text-muted-foreground"
          onDragOver={(e) => e.preventDefault()}
          onDrop={(e) => {
            e.preventDefault();
            pick(e.dataTransfer.files?.[0]);
          }}
          onClick={() => inputRef.current?.click()}
          onKeyDown={(e) => { if (e.key === 'Enter' || e.key === ' ') inputRef.current?.click(); }}
        >
          Drag and drop your screenshot here, or click to browse — JPG, JPEG or PNG, up to 5 MB.
        </div>
      )}
      {error !== null && (
        <p className="text-xs text-destructive" role="alert">{error}</p>
      )}
      {link.requires_screenshot && previewSrc !== null && screenshot !== undefined && (
        <div className="space-y-1.5">
          <img src={previewSrc} alt={`Result screenshot for ${link.title}`} className="max-h-48 rounded-lg border" />
          <div className="flex items-center justify-between gap-2">
            <p className="truncate text-xs text-muted-foreground">{screenshot.original_name}</p>
            <div className="flex gap-1.5">
              <Button type="button" size="sm" variant="ghost" disabled={busy} onClick={() => inputRef.current?.click()}>
                Replace
              </Button>
              <Button type="button" size="sm" variant="ghost" disabled={busy} onClick={() => onRemove(link)}>
                <X className="size-3.5" aria-hidden /> Remove
              </Button>
            </div>
          </div>
        </div>
      )}
    </div>
  );
}

export function TakeSurveyDialog({
  surveyId,
  onClose,
}: {
  surveyId: number;
  onClose: () => void;
}) {
  const form = useMySurveyForm(surveyId);
  const submitSurvey = useSubmitSurvey();
  const openLinkMutation = useOpenSurveyLink();
  const uploadMutation = useUploadScreenshot();
  const removeMutation = useRemoveScreenshot();

  const [answers, setAnswers] = useState<Record<number, AnswerValue>>({});
  // Optimistic mirrors of the server-tracked attestations/proofs; seeded
  // from the form payload so a reload restores staged dropzones.
  const [locallyOpened, setLocallyOpened] = useState<number[]>([]);
  const [localShots, setLocalShots] = useState<SurveyLinkScreenshot[]>([]);
  const [localPreviews, setLocalPreviews] = useState<Record<number, string>>({});
  const seeded = useRef(false);

  const [tab, setTab] = useState<RunnerTab>('questions');
  const [highlightId, setHighlightId] = useState<number | null>(null);
  const questionRefs = useRef<Record<number, HTMLDivElement | null>>({});

  useEffect(() => {
    if (form.data !== undefined && ! seeded.current) {
      seeded.current = true;
      setLocallyOpened(form.data.opened_link_ids);
      setLocalShots(form.data.screenshots);
      // A links-only survey opens on the Optional links tab.
      if (form.data.questions.length === 0 && form.data.links.length > 0) {
        setTab('links');
      }
    }
  }, [form.data]);

  function patch(questionId: number, value: AnswerValue) {
    setAnswers((current) => ({ ...current, [questionId]: value }));
    if (highlightId === questionId) setHighlightId(null);
  }

  function openExternal(link: SurveyLink) {
    // window.open MUST run inside the click gesture — awaiting the
    // attestation POST first would let popup blockers eat the tab.
    window.open(link.external_url, '_blank', 'noopener,noreferrer');
    setLocallyOpened((current) => (current.includes(link.id) ? current : [...current, link.id]));
    void openLinkMutation.mutateAsync({ surveyId, linkId: link.id }).catch((err: ApiEnvelopeError) => {
      toast.error(err.errors[0]?.message ?? 'Could not record the link open.');
    });
  }

  function uploadProof(link: SurveyLink, file: File) {
    const previewUrl = URL.createObjectURL(file);
    uploadMutation.mutateAsync({ surveyId, linkId: link.id, file })
      .then((shot) => {
        setLocalPreviews((current) => {
          const previous = current[link.id];
          if (previous !== undefined) URL.revokeObjectURL(previous);
          return { ...current, [link.id]: previewUrl };
        });
        setLocalShots((current) => [...current.filter((s) => s.link_id !== link.id), shot]);
      })
      .catch((err: ApiEnvelopeError) => {
        URL.revokeObjectURL(previewUrl);
        toast.error(err.errors[0]?.message ?? 'Failed to upload the screenshot.');
      });
  }

  function removeProof(link: SurveyLink) {
    removeMutation.mutateAsync({ surveyId, linkId: link.id })
      .then(() => {
        setLocalPreviews((current) => {
          const previous = current[link.id];
          if (previous !== undefined) URL.revokeObjectURL(previous);
          return { ...current, [link.id]: undefined } as Record<number, string>;
        });
        setLocalShots((current) => current.filter((s) => s.link_id !== link.id));
      })
      .catch((err: ApiEnvelopeError) => toast.error(err.errors[0]?.message ?? 'Failed to remove the screenshot.'));
  }

  const gatingForm = useMemo<MySurveyForm | null>(() => {
    if (form.data === undefined) return null;
    return {
      ...form.data,
      opened_link_ids: Array.from(new Set([...form.data.opened_link_ids, ...locallyOpened])),
      screenshots: localShots,
    };
  }, [form.data, locallyOpened, localShots]);

  const gating = useMemo(
    () => (gatingForm !== null ? surveyGating(gatingForm, answers) : null),
    [gatingForm, answers],
  );

  function submit() {
    if (form.data === undefined || gating === null) return;
    // Required questions are the only gate: route the student to the
    // first missing one instead of silently doing nothing.
    if (! gating.canSubmit && gating.firstUnansweredId !== null) {
      const target = gating.firstUnansweredId;
      setTab('questions');
      setHighlightId(target);
      requestAnimationFrame(() => {
        questionRefs.current[target]?.scrollIntoView({ behavior: 'smooth', block: 'center' });
      });
      return;
    }
    submitSurvey.mutate(
      {
        surveyId,
        answers: form.data.questions.map((q) => ({ question_id: q.id, value: answers[q.id] ?? null })),
      },
      { onSuccess: onClose },
    );
  }

  const submitted = form.data?.submitted === true;
  const surveyLinks = form.data?.links.filter((l) => l.type === 'survey') ?? [];
  const testLinks = form.data?.links.filter((l) => l.type === 'test') ?? [];
  const evaluationLink = form.data?.links.find((l) => l.type === 'evaluation') ?? null;
  const showQuestions = (form.data?.questions.length ?? 0) > 0;
  const showLinks = (form.data?.links.length ?? 0) > 0;

  const questionsSection = showQuestions && form.data !== undefined && (
    <div className="space-y-4">
      {gating !== null && gating.requiredTotal > 0 && (
        <p className="text-xs text-muted-foreground" aria-live="polite">
          {gating.requiredAnswered} of {gating.requiredTotal} required answered
        </p>
      )}
      {form.data.questions.map((q) => {
        const value = answers[q.id] ?? null;
        const highlighted = highlightId === q.id;
        return (
          <div
            key={q.id}
            ref={(el) => { questionRefs.current[q.id] = el; }}
            className={`space-y-2 rounded-lg border bg-background p-4 ${highlighted ? 'border-destructive ring-2 ring-destructive/40' : ''}`}
          >
            <p className="text-sm font-medium text-foreground">
              {q.question_text}
              {q.is_required && <span className="ml-1 text-destructive">*</span>}
            </p>
            {q.question_type === 'single' && (
              <div className="space-y-1.5" role="radiogroup" aria-label={q.question_text}>
                {q.options.map((o) => (
                  <div key={o.id} className="flex items-center gap-2">
                    <Checkbox
                      id={`q-${q.id}-${o.id}`}
                      checked={value === o.id}
                      disabled={submitted}
                      onCheckedChange={() => patch(q.id, o.id)}
                    />
                    <Label htmlFor={`q-${q.id}-${o.id}`} className="cursor-pointer text-sm font-normal">{o.text}</Label>
                  </div>
                ))}
              </div>
            )}
            {q.question_type === 'multi' && (
              <div className="space-y-1.5">
                {q.options.map((o) => {
                  const chosen = Array.isArray(value) ? value.includes(o.id) : false;
                  return (
                    <div key={o.id} className="flex items-center gap-2">
                      <Checkbox
                        id={`q-${q.id}-${o.id}`}
                        checked={chosen}
                        disabled={submitted}
                        onCheckedChange={(checked) => {
                          const current = Array.isArray(value) ? value : [];
                          patch(q.id, checked === true ? [...current, o.id] : current.filter((id) => id !== o.id));
                        }}
                      />
                      <Label htmlFor={`q-${q.id}-${o.id}`} className="cursor-pointer text-sm font-normal">{o.text}</Label>
                    </div>
                  );
                })}
              </div>
            )}
            {q.question_type === 'likert' && (
              <ScaleRow questionId={q.id} value={typeof value === 'number' ? value : null} onChange={(n) => patch(q.id, n)} labels={AGREE_LABELS} disabled={submitted} />
            )}
            {q.question_type === 'rating' && (
              <ScaleRow questionId={q.id} value={typeof value === 'number' ? value : null} onChange={(n) => patch(q.id, n)} labels={LIKERT_LABELS} disabled={submitted} />
            )}
            {q.question_type === 'free_text' && (
              <Textarea
                rows={3}
                value={typeof value === 'string' ? value : ''}
                onChange={(e) => patch(q.id, e.target.value)}
                maxLength={2000}
                disabled={submitted}
              />
            )}
            {q.question_type === 'external_url' && (() => {
              // Legacy type: the admin pasted the activity link into the
              // question text; the first URL becomes the link.
              const url = q.question_text.match(/https?:\/\/[^\s<"]+/)?.[0];
              return (
                <div className="space-y-2">
                  {url !== undefined && (
                    <a
                      href={url}
                      target="_blank"
                      rel="noreferrer noopener"
                      className="inline-flex items-center gap-1 text-sm font-medium text-primary underline underline-offset-2"
                    >
                      Open the activity <ExternalLink className="size-3.5" aria-hidden />
                    </a>
                  )}
                  <div className="flex items-center gap-2">
                    <Checkbox
                      id={`q-${q.id}`}
                      checked={value === true}
                      disabled={submitted}
                      onCheckedChange={(checked) => patch(q.id, checked === true)}
                    />
                    <Label htmlFor={`q-${q.id}`} className="cursor-pointer text-sm font-normal">
                      I completed this activity
                    </Label>
                  </div>
                </div>
              );
            })()}
            {highlighted && (
              <p className="text-xs text-destructive" role="alert">This question is required.</p>
            )}
          </div>
        );
      })}
    </div>
  );

  const linksSection = showLinks && (
    <div className="space-y-3">
      <p className="rounded-lg border border-dashed bg-muted/20 p-3 text-xs text-muted-foreground">
        These links are optional. You can submit your survey without completing them.
      </p>
      {surveyLinks.length > 0 && (
        <section className="space-y-2" aria-label="Survey links">
          <p className="text-xs font-semibold uppercase tracking-wide text-muted-foreground">Survey links</p>
          {surveyLinks.map((link) => (
            <div key={link.id} className="space-y-2 rounded-lg border bg-background p-4">
              <p className="text-sm font-medium text-foreground">{link.title}</p>
              {link.description !== null && link.description !== '' && (
                <p className="text-xs text-muted-foreground">{link.description}</p>
              )}
              <OpenLinkButton
                link={link}
                label="Open Survey"
                opened={gatingForm?.opened_link_ids.includes(link.id) === true}
                onOpened={openExternal}
              />
            </div>
          ))}
        </section>
      )}
      {testLinks.length > 0 && (
        <section className="space-y-2" aria-label="Assessment links">
          <p className="text-xs font-semibold uppercase tracking-wide text-muted-foreground">Assessment links</p>
          {testLinks.map((link) => (
            <TestLinkCard
              key={link.id}
              surveyId={surveyId}
              link={link}
              opened={gatingForm?.opened_link_ids.includes(link.id) === true}
              screenshot={localShots.find((s) => s.link_id === link.id)}
              localPreviewUrl={localPreviews[link.id] ?? null}
              onOpened={openExternal}
              onUpload={uploadProof}
              onRemove={removeProof}
              busy={uploadMutation.isPending || removeMutation.isPending}
            />
          ))}
        </section>
      )}
      {evaluationLink !== null && (
        <section className="space-y-2" aria-label="Evaluation">
          <p className="text-xs font-semibold uppercase tracking-wide text-muted-foreground">Evaluation</p>
          <div className="space-y-2 rounded-lg border bg-background p-4">
            <p className="text-sm font-medium text-foreground">{evaluationLink.title}</p>
            {evaluationLink.description !== null && evaluationLink.description !== '' && (
              <p className="text-xs text-muted-foreground">{evaluationLink.description}</p>
            )}
            <OpenLinkButton
              link={evaluationLink}
              label="Evaluate Guidance Services"
              opened={gatingForm?.opened_link_ids.includes(evaluationLink.id) === true}
              onOpened={openExternal}
            />
          </div>
        </section>
      )}
    </div>
  );

  return (
    <Dialog open onOpenChange={(open) => ! open && ! submitSurvey.isPending && onClose()}>
      <DialogContent lockDismiss className="max-h-[92dvh] overflow-y-auto sm:max-w-2xl">
        <DialogHeader>
          <DialogTitle className="flex items-center gap-2">
            {form.data?.title ?? 'Survey'}
            {submitted && <Badge variant="success">Submitted</Badge>}
          </DialogTitle>
          <DialogDescription>
            Your answers are confidential to the Guidance Office. Questions marked Required must be answered; blocks
            marked voluntary may be skipped.
          </DialogDescription>
        </DialogHeader>

        {form.isLoading && <Skeleton className="h-40" />}
        {form.isError && (
          <QueryErrorState message="This survey is no longer available." onRetry={() => void form.refetch()} pending={form.isFetching} />
        )}

        {form.data !== undefined && showQuestions && showLinks && (
          <Tabs value={tab} onValueChange={(v) => setTab(v as RunnerTab)}>
            <TabsList>
              <TabsTrigger value="questions">Questions</TabsTrigger>
              <TabsTrigger value="links">
                Optional links <Badge variant="secondary" className="ml-1.5">Optional</Badge>
              </TabsTrigger>
            </TabsList>
            <TabsContent value="questions">{questionsSection}</TabsContent>
            <TabsContent value="links">{linksSection}</TabsContent>
          </Tabs>
        )}
        {form.data !== undefined && ! (showQuestions && showLinks) && (
          <div className="space-y-4">
            {showQuestions && questionsSection}
            {showLinks && linksSection}
          </div>
        )}

        {!submitted && gating !== null && (
          <div className="space-y-1.5 rounded-lg border bg-muted/20 p-3.5" aria-label="Before you submit">
            <p className="flex items-center gap-2 text-sm">
              {gating.canSubmit
                ? <Check className="size-3.5 shrink-0 text-primary" aria-hidden />
                : <Circle className="size-3.5 shrink-0 text-muted-foreground" aria-hidden />}
              <span>
                Required questions: {gating.requiredAnswered} of {gating.requiredTotal} answered
              </span>
            </p>
            {showLinks && (
              <p className="flex items-center gap-2 text-sm text-muted-foreground">
                <Circle className="size-3.5 shrink-0" aria-hidden />
                <span>
                  Optional links: {gating.linksOpened} opened · {gating.proofsUploaded} screenshot{gating.proofsUploaded === 1 ? '' : 's'} uploaded
                  <Badge variant="secondary" className="ml-1.5">Optional</Badge>
                </span>
              </p>
            )}
            {showLinks && ! gating.canSubmit && (
              <p className="text-xs text-muted-foreground">You can still complete the optional links later.</p>
            )}
          </div>
        )}

        <DialogFooter>
          <Button type="button" variant="outline" onClick={onClose} disabled={submitSurvey.isPending}>Cancel</Button>
          {!submitted && (
            <Button
              type="button"
              onClick={submit}
              className={gating?.canSubmit === false ? 'opacity-60' : undefined}
              disabled={submitSurvey.isPending || form.data === undefined}
            >
              {submitSurvey.isPending ? 'Submitting…' : 'Submit survey'}
            </Button>
          )}
        </DialogFooter>
      </DialogContent>
    </Dialog>
  );
}
