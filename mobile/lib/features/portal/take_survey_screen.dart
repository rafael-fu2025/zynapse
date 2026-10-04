import 'package:flutter/material.dart';
import 'package:flutter/services.dart';

import '../../core/api/api_client.dart';
import '../../core/models/survey.dart';
import '../../core/services/api_service.dart';
import '../common/crud_form.dart';
import '../common/widgets.dart';

/// "Take survey" — the answerable form for one survey (mirrors the web's
/// `TakeSurveyDialog`). Answers are collected per question type and
/// submitted as `[{question_id, value}]`; the backend enforces required
/// questions and rejects a second submission (409).
class TakeSurveyScreen extends StatefulWidget {
  const TakeSurveyScreen({super.key, required this.surveyId});

  final int surveyId;

  @override
  State<TakeSurveyScreen> createState() => _TakeSurveyScreenState();
}

class _TakeSurveyScreenState extends State<TakeSurveyScreen> {
  MySurveyForm? _form;
  bool _loading = true;
  String? _error;
  bool _submitting = false;
  final Map<int, Object?> _answers = {};

  @override
  void initState() {
    super.initState();
    _load();
  }

  Future<void> _load() async {
    setState(() {
      _loading = true;
      _error = null;
    });
    try {
      final form = await ApiService.I.mySurveyForm(widget.surveyId);
      if (!mounted) return;
      setState(() {
        _form = form;
        _loading = false;
      });
    } catch (e) {
      final api = mapDioError(e);
      if (!mounted) return;
      setState(() {
        _error = api.message;
        _loading = false;
      });
    }
  }

  bool _isEmpty(Object? value) =>
      value == null ||
      value == '' ||
      (value is List && value.isEmpty);

  Future<void> _submit() async {
    final form = _form;
    if (form == null || _submitting) return;

    // Required-question enforcement client-side (the backend re-checks).
    for (final q in form.questions) {
      if (q.isRequired && _isEmpty(_answers[q.id])) {
        showCrudMessage(context, 'Please answer: ${q.questionText}',
            error: true);
        return;
      }
    }

    setState(() => _submitting = true);
    try {
      final answers = <Map<String, dynamic>>[];
      _answers.forEach((questionId, value) {
        if (!_isEmpty(value)) {
          answers.add({'question_id': questionId, 'value': value});
        }
      });
      await ApiService.I.submitSurvey(widget.surveyId, answers);
      if (!mounted) return;
      showCrudMessage(context, 'Submitted — thank you!');
      Navigator.of(context).pop(true);
    } catch (e) {
      final api = mapDioError(e);
      if (!mounted) return;
      setState(() => _submitting = false);
      showCrudMessage(context, api.message, error: true);
    }
  }

  @override
  Widget build(BuildContext context) {
    final form = _form;
    return Scaffold(
      appBar: AppBar(title: const Text('Take survey')),
      body: _loading
          ? AsyncState.loading()
          : _error != null
              ? AsyncState.error(_error!, onRetry: _load)
              : form == null
                  ? AsyncState.empty('This survey is no longer available.')
                  : Column(
                      children: [
                        Expanded(
                          child: ListView(
                            padding: const EdgeInsets.all(16),
                            children: [
                              Text(
                                form.title,
                                style: Theme.of(context)
                                    .textTheme
                                    .titleMedium
                                    ?.copyWith(fontWeight: FontWeight.w700),
                              ),
                              if (form.description != null &&
                                  form.description!.isNotEmpty) ...[
                                const SizedBox(height: 4),
                                Text(
                                  form.description!,
                                  style: const TextStyle(
                                      color: Colors.black54, fontSize: 13),
                                ),
                              ],
                              const SizedBox(height: 12),
                              SurveyQuestionList(
                                questions: form.questions,
                                answers: _answers,
                                onChanged: (questionId, value) =>
                                    setState(() {
                                  if (_isEmpty(value)) {
                                    _answers.remove(questionId);
                                  } else {
                                    _answers[questionId] = value;
                                  }
                                }),
                              ),
                            ],
                          ),
                        ),
                        SafeArea(
                          child: Padding(
                            padding: const EdgeInsets.all(16),
                            child: FilledButton(
                              onPressed:
                                  _submitting ? null : () => _submit(),
                              style: FilledButton.styleFrom(
                                padding:
                                    const EdgeInsets.symmetric(vertical: 14),
                              ),
                              child: Text(_submitting
                                  ? 'Submitting…'
                                  : 'Submit answers'),
                            ),
                          ),
                        ),
                      ],
                    ),
    );
  }
}

/// The question renderer, split out so widget tests can exercise the
/// per-type editors without the network layer. Owns one TextEditingController
/// per free-text question so rebuilds (each keystroke round-trips through
/// the parent's setState) never reset the field or its cursor.
class SurveyQuestionList extends StatefulWidget {
  const SurveyQuestionList({
    super.key,
    required this.questions,
    required this.answers,
    required this.onChanged,
  });

  final List<SurveyQuestion> questions;
  final Map<int, Object?> answers;
  final void Function(int questionId, Object? value) onChanged;

  @override
  State<SurveyQuestionList> createState() => _SurveyQuestionListState();
}

class _SurveyQuestionListState extends State<SurveyQuestionList> {
  final Map<int, TextEditingController> _textControllers = {};

  @override
  void dispose() {
    for (final controller in _textControllers.values) {
      controller.dispose();
    }
    super.dispose();
  }

  TextEditingController _textControllerFor(SurveyQuestion q) {
    return _textControllers.putIfAbsent(
      q.id,
      () => TextEditingController(text: widget.answers[q.id] as String? ?? ''),
    );
  }

  @override
  Widget build(BuildContext context) {
    return Column(
      crossAxisAlignment: CrossAxisAlignment.stretch,
      children: [
        for (final (index, q) in widget.questions.indexed)
          _QuestionCard(
            index: index,
            question: q,
            child: _editorFor(q),
          ),
      ],
    );
  }

  Widget _editorFor(SurveyQuestion q) {
    switch (q.questionType) {
      case 'single':
        return RadioGroup<int>(
          groupValue: widget.answers[q.id] as int?,
          onChanged: (v) => widget.onChanged(q.id, v),
          child: Column(
            children: [
              for (final option in q.options)
                RadioListTile<int>(
                  dense: true,
                  contentPadding: EdgeInsets.zero,
                  title: Text(option.text),
                  value: option.id,
                ),
            ],
          ),
        );
      case 'multi':
        final chosen = ((widget.answers[q.id] as List<dynamic>?) ?? const [])
            .whereType<int>()
            .toList();
        return Column(
          children: [
            for (final option in q.options)
              CheckboxListTile(
                dense: true,
                contentPadding: EdgeInsets.zero,
                title: Text(option.text),
                value: chosen.contains(option.id),
                onChanged: (checked) {
                  final next = [...chosen];
                  if (checked == true) {
                    next.add(option.id);
                  } else {
                    next.remove(option.id);
                  }
                  widget.onChanged(q.id, next);
                },
              ),
          ],
        );
      case 'likert':
      case 'rating':
        final score = widget.answers[q.id] as int?;
        return Wrap(
          spacing: 8,
          children: [
            for (final v in const [1, 2, 3, 4, 5])
              ChoiceChip(
                label: Text('$v'),
                selected: score == v,
                onSelected: (_) => widget.onChanged(q.id, v),
              ),
          ],
        );
      case 'external_url':
        return Column(
          crossAxisAlignment: CrossAxisAlignment.start,
          children: [
            // The admin pastes the activity link (e.g. a Google Form or
            // EducationPlanner test) into the question text; the first URL
            // becomes the copyable link — same contract as the web's
            // TakeSurveyDialog. The app has no url_launcher dependency, so
            // the link is copied to the clipboard rather than opened.
            if (_externalUrl(q.questionText) case final url?)
              Padding(
                padding: const EdgeInsets.only(bottom: 4),
                child: TextButton.icon(
                  onPressed: () async {
                    await Clipboard.setData(ClipboardData(text: url));
                    if (!mounted) return;
                    showCrudMessage(context, 'Activity link copied');
                  },
                  icon: const Icon(Icons.link, size: 16),
                  label: const Text('Copy the activity link'),
                ),
              ),
            CheckboxListTile(
              dense: true,
              contentPadding: EdgeInsets.zero,
              controlAffinity: ListTileControlAffinity.leading,
              title: const Text('I completed this activity.'),
              value: widget.answers[q.id] == true,
              onChanged: (checked) => widget.onChanged(q.id, checked == true),
            ),
          ],
        );
      case 'free_text':
      default:
        return TextField(
          minLines: 2,
          maxLines: 5,
          controller: _textControllerFor(q),
          onChanged: (v) => widget.onChanged(q.id, v),
          decoration: const InputDecoration(hintText: 'Your answer'),
        );
    }
  }
}

/// The first http(s) URL embedded in a question's text, or null when the
/// admin never pasted one. Mirrors the web's TakeSurveyDialog extractor.
final RegExp _externalUrlPattern = RegExp(r'https?://[^\s<"]+');

String? _externalUrl(String questionText) =>
    _externalUrlPattern.firstMatch(questionText)?.group(0);

class _QuestionCard extends StatelessWidget {
  const _QuestionCard({
    required this.index,
    required this.question,
    required this.child,
  });

  final int index;
  final SurveyQuestion question;
  final Widget child;

  @override
  Widget build(BuildContext context) {
    return Padding(
      padding: const EdgeInsets.only(bottom: 16),
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          Row(
            crossAxisAlignment: CrossAxisAlignment.start,
            children: [
              Expanded(
                child: Text(
                  '${index + 1}. ${question.questionText}',
                  style: const TextStyle(fontWeight: FontWeight.w600),
                ),
              ),
              if (question.isRequired)
                const Text(' *',
                    style: TextStyle(
                        color: Color(0xFFB3261E),
                        fontWeight: FontWeight.w700)),
            ],
          ),
          const SizedBox(height: 6),
          child,
        ],
      ),
    );
  }
}
