/**
 * TakeSurveyDialog — student survey/interview runner (Phase B). Renders
 * the published form's questions by type and submits; the backend owns
 * validation, one-submission-per-student, and the encrypted record.
 */
import { ExternalLink } from 'lucide-react';
import { useState } from 'react';
import { Button } from '@/components/ui/button';
import { Label } from '@/components/ui/label';
import { Checkbox } from '@/components/ui/checkbox';
import { Textarea } from '@/components/ui/textarea';
import {
  Dialog,
  DialogContent,
  DialogDescription,
  DialogFooter,
  DialogHeader,
  DialogTitle,
} from '@/components/ui/dialog';
import { useMySurveyForm, useSubmitSurvey } from '@/hooks/useSurveys';
import { Skeleton } from '@/components/ui/skeleton';
import { QueryErrorState } from '@/components/QueryErrorState';

type AnswerValue = number | number[] | string | boolean | null;

const LIKERT_LABELS = ['Very poor', 'Poor', 'Fair', 'Good', 'Very good'];
const AGREE_LABELS = ['Strongly disagree', 'Disagree', 'Neutral', 'Agree', 'Strongly agree'];

function ScaleRow({
  questionId,
  value,
  onChange,
  labels,
}: {
  questionId: number;
  value: number | null;
  onChange: (n: number) => void;
  labels: string[];
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
          onClick={() => onChange(i + 1)}
        >
          <span>{i + 1}</span>
          <span className="text-[10px] font-normal">{label}</span>
        </Button>
      ))}
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
  const [answers, setAnswers] = useState<Record<number, AnswerValue>>({});

  function patch(questionId: number, value: AnswerValue) {
    setAnswers((current) => ({ ...current, [questionId]: value }));
  }

  function submit() {
    if (form.data === undefined) return;
    submitSurvey.mutate(
      {
        surveyId,
        answers: form.data.questions.map((q) => ({ question_id: q.id, value: answers[q.id] ?? null })),
      },
      { onSuccess: onClose },
    );
  }

  return (
    <Dialog open onOpenChange={(open) => ! open && ! submitSurvey.isPending && onClose()}>
      <DialogContent lockDismiss className="max-h-[92dvh] overflow-y-auto sm:max-w-2xl">
        <DialogHeader>
          <DialogTitle>{form.data?.title ?? 'Survey'}</DialogTitle>
          <DialogDescription>
            Your answers are confidential to the Guidance Office. Questions marked Required must be answered; blocks
            marked voluntary may be skipped.
          </DialogDescription>
        </DialogHeader>

        {form.isLoading && <Skeleton className="h-40" />}
        {form.isError && (
          <QueryErrorState message="This survey is no longer available." onRetry={() => void form.refetch()} pending={form.isFetching} />
        )}

        {form.data !== undefined && (
          <div className="space-y-5">
            {form.data.questions.map((q) => {
              const value = answers[q.id] ?? null;
              return (
                <div key={q.id} className="space-y-2 rounded-lg border bg-background p-4">
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
                    <ScaleRow questionId={q.id} value={typeof value === 'number' ? value : null} onChange={(n) => patch(q.id, n)} labels={AGREE_LABELS} />
                  )}
                  {q.question_type === 'rating' && (
                    <ScaleRow questionId={q.id} value={typeof value === 'number' ? value : null} onChange={(n) => patch(q.id, n)} labels={LIKERT_LABELS} />
                  )}
                  {q.question_type === 'free_text' && (
                    <Textarea
                      rows={3}
                      value={typeof value === 'string' ? value : ''}
                      onChange={(e) => patch(q.id, e.target.value)}
                      maxLength={2000}
                    />
                  )}
                  {q.question_type === 'external_url' && (() => {
                    // The admin pastes the activity link (e.g. a Google
                    // Form or EducationPlanner test) into the question
                    // text; the first URL becomes the clickable link.
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
                            onCheckedChange={(checked) => patch(q.id, checked === true)}
                          />
                          <Label htmlFor={`q-${q.id}`} className="cursor-pointer text-sm font-normal">
                            I completed this activity
                          </Label>
                        </div>
                      </div>
                    );
                  })()}
                </div>
              );
            })}
          </div>
        )}

        <DialogFooter>
          <Button type="button" variant="outline" onClick={onClose} disabled={submitSurvey.isPending}>Cancel</Button>
          <Button type="button" onClick={submit} disabled={submitSurvey.isPending || form.data === undefined}>
            {submitSurvey.isPending ? 'Submitting…' : 'Submit answers'}
          </Button>
        </DialogFooter>
      </DialogContent>
    </Dialog>
  );
}
