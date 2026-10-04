import 'package:flutter/material.dart';
import 'package:flutter/services.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:synapse_mobile/core/models/survey.dart';
import 'package:synapse_mobile/features/portal/take_survey_screen.dart';

/// Survey model parsing + the per-question-type editors of the
/// take-survey screen. The JSON fixtures mirror the backend's
/// `SurveyService::getFormForStudent` payload (and the web's
/// `mySurveyFormSchema` in frontend/src/schemas/surveys.ts).
void main() {
  const formJson = {
    'id': 7,
    'title': 'Intake Interview — Grade 1',
    'description':
        'Form code: F-OGC-GIN-IIF-004-00 — Guidance Office intake interview.',
    'close_at': '2026-12-01 15:59:59',
    'version_id': 12,
    'questions': [
      {
        'id': 101,
        'sort_order': 10,
        'question_type': 'free_text',
        'question_text': 'How is your experience in FPA?',
        'is_required': true,
        'options': [],
      },
      {
        'id': 102,
        'sort_order': 20,
        'question_type': 'likert',
        'question_text': 'How satisfied are you with the guidance office?',
        'is_required': false,
        'options': [],
      },
      {
        'id': 103,
        'sort_order': 30,
        'question_type': 'single',
        'question_text': 'Do you have friends in school?',
        'is_required': false,
        'options': [
          {'id': 1, 'text': 'Yes'},
          {'id': 2, 'text': 'No'},
        ],
      },
    ],
  };

  group('MySurvey', () {
    test('parses an open required survey', () {
      final s = MySurvey.fromJson(const {
        'id': 7,
        'title': 'Intake Interview — Grade 1',
        'description': 'Form code: F-OGC-GIN-IIF-004-00',
        'category': 'interview',
        'is_required': true,
        'publish_at': null,
        'close_at': '2026-12-01 15:59:59',
      });
      expect(s.id, 7);
      expect(s.title, 'Intake Interview — Grade 1');
      expect(s.category, 'interview');
      expect(s.isRequired, isTrue);
      expect(s.closeAt, '2026-12-01 15:59:59');
    });

    test('treats missing description / deadline as nullable', () {
      final s = MySurvey.fromJson(const {
        'id': 8,
        'title': 'Evaluation',
        'category': 'survey',
        'is_required': false,
      });
      expect(s.description, isNull);
      expect(s.closeAt, isNull);
      expect(s.isRequired, isFalse);
    });
  });

  group('MySurveyForm', () {
    test('parses the version snapshot with questions and options', () {
      final form = MySurveyForm.fromJson(formJson);
      expect(form.id, 7);
      expect(form.versionId, 12);
      expect(form.questions.length, 3);

      final freeText = form.questions[0];
      expect(freeText.questionType, 'free_text');
      expect(freeText.isRequired, isTrue);
      expect(freeText.options, isEmpty);

      final single = form.questions[2];
      expect(single.questionType, 'single');
      expect(single.options.map((o) => o.text), ['Yes', 'No']);
      expect(single.options.first.id, 1);
    });
  });

  group('SurveyQuestionList', () {
    /// Mirrors the real parent (_TakeSurveyScreenState): answers live in
    /// state and every change goes through setState, so the editors see
    /// the updated map on their next build.
    Widget harness({
      required List<SurveyQuestion> questions,
      required Map<int, Object?> answers,
    }) {
      return _SurveyHarness(
        questions: questions,
        answers: answers,
      );
    }

    testWidgets('collects answers per question type', (tester) async {
      final form = MySurveyForm.fromJson(formJson);
      final answers = <int, Object?>{};

      await tester.pumpWidget(MaterialApp(
        home: Scaffold(
          body: SingleChildScrollView(
            child: harness(questions: form.questions, answers: answers),
          ),
        ),
      ));

      // free_text → TextField, single → radio tile, likert → chips 1-5.
      await tester.enterText(
        find.widgetWithText(TextField, 'Your answer'),
        'Friends and fun',
      );
      await tester.pump();

      await tester.tap(find.text('Yes'));
      await tester.pump();

      await tester.tap(find.widgetWithText(ChoiceChip, '4'));
      await tester.pump();

      expect(answers[101], 'Friends and fun');
      expect(answers[103], 1, reason: 'the radio stores the option id');
      expect(answers[102], 4, reason: 'the likert chip stores 1-5');
    });

    testWidgets('deselecting a multi option updates the list',
        (tester) async {
      final form = MySurveyForm.fromJson(const {
        'id': 9,
        'title': 'Activities',
        'description': null,
        'close_at': null,
        'version_id': 13,
        'questions': [
          {
            'id': 201,
            'sort_order': 10,
            'question_type': 'multi',
            'question_text': 'Which services did you use?',
            'is_required': false,
            'options': [
              {'id': 5, 'text': 'Counseling'},
              {'id': 6, 'text': 'Career talks'},
            ],
          },
        ],
      });
      final answers = <int, Object?>{};

      await tester.pumpWidget(MaterialApp(
        home: Scaffold(
          body: SingleChildScrollView(
            child: harness(questions: form.questions, answers: answers),
          ),
        ),
      ));

      await tester.tap(find.text('Counseling'));
      await tester.pump();
      await tester.tap(find.text('Career talks'));
      await tester.pump();
      expect(answers[201], [5, 6]);

      await tester.tap(find.text('Career talks'));
      await tester.pump();
      expect(answers[201], [5]);
    });

    testWidgets(
        'external_url shows a copy-link affordance when the text carries a URL',
        (tester) async {
      // Clipboard is a platform channel — record writes instead of
      // hitting the real (absent) platform implementation.
      final copied = <String>[];
      tester.binding.defaultBinaryMessenger.setMockMethodCallHandler(
        SystemChannels.platform,
        (call) async {
          if (call.method == 'Clipboard.setData') {
            copied.add(((call.arguments as Map)['text'] as String?) ?? '');
          }
          return null;
        },
      );
      addTearDown(() => tester.binding.defaultBinaryMessenger
          .setMockMethodCallHandler(SystemChannels.platform, null));

      final form = MySurveyForm.fromJson(const {
        'id': 9,
        'title': 'Activities',
        'description': null,
        'close_at': null,
        'version_id': 13,
        'questions': [
          {
            'id': 301,
            'sort_order': 10,
            'question_type': 'external_url',
            'question_text':
                'Please take the test at https://example.test/planner before answering.',
            'is_required': false,
            'options': [],
          },
        ],
      });
      final answers = <int, Object?>{};

      await tester.pumpWidget(MaterialApp(
        home: Scaffold(
          body: SingleChildScrollView(
            child: harness(questions: form.questions, answers: answers),
          ),
        ),
      ));

      // The attest checkbox is always present; the link button joins it
      // only when the question text embeds a URL.
      expect(find.text('I completed this activity.'), findsOneWidget);
      expect(find.text('Copy the activity link'), findsOneWidget);

      // Tapping it copies the extracted URL to the clipboard.
      await tester.tap(find.text('Copy the activity link'));
      await tester.pump();
      expect(copied, ['https://example.test/planner']);

      // Drain the confirmation SnackBar so no timers outlive the test.
      await tester.pump(const Duration(seconds: 4));
      await tester.pump(const Duration(seconds: 1));
    });

    testWidgets('external_url omits the link button when no URL is present',
        (tester) async {
      final form = MySurveyForm.fromJson(const {
        'id': 9,
        'title': 'Activities',
        'description': null,
        'close_at': null,
        'version_id': 13,
        'questions': [
          {
            'id': 302,
            'sort_order': 10,
            'question_type': 'external_url',
            'question_text': 'Did you complete the activity?',
            'is_required': false,
            'options': [],
          },
        ],
      });
      final answers = <int, Object?>{};

      await tester.pumpWidget(MaterialApp(
        home: Scaffold(
          body: SingleChildScrollView(
            child: harness(questions: form.questions, answers: answers),
          ),
        ),
      ));

      expect(find.text('I completed this activity.'), findsOneWidget);
      expect(find.text('Copy the activity link'), findsNothing);
    });
  });
}

/// Test parent that owns the answers map in state — the same lifecycle
/// _TakeSurveyScreenState gives the question list in the app.
class _SurveyHarness extends StatefulWidget {
  const _SurveyHarness({required this.questions, required this.answers});

  final List<SurveyQuestion> questions;
  final Map<int, Object?> answers;

  @override
  State<_SurveyHarness> createState() => _SurveyHarnessState();
}

class _SurveyHarnessState extends State<_SurveyHarness> {
  void _change(int id, Object? value) {
    setState(() {
      final empty = value == null ||
          value == '' ||
          (value is List && value.isEmpty);
      if (empty) {
        widget.answers.remove(id);
      } else {
        widget.answers[id] = value;
      }
    });
  }

  @override
  Widget build(BuildContext context) {
    return SurveyQuestionList(
      questions: widget.questions,
      answers: widget.answers,
      onChanged: _change,
    );
  }
}
