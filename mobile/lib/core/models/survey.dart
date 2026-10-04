/// Guidance survey models — mirrors the student survey payloads of
/// `Modules\Counselling\Services\SurveyService` and the web schemas in
/// `frontend/src/schemas/surveys.ts`.
library;

/// An open survey or interview from `GET /me/guidance/surveys`.
class MySurvey {
  MySurvey({
    required this.id,
    required this.title,
    required this.description,
    required this.category,
    required this.isRequired,
    required this.publishAt,
    required this.closeAt,
  });

  factory MySurvey.fromJson(Map<String, dynamic> json) => MySurvey(
        id: json['id'] as int,
        title: json['title'] as String? ?? '',
        description: json['description'] as String?,
        category: json['category'] as String? ?? 'survey',
        isRequired: json['is_required'] == true,
        publishAt: json['publish_at'] as String?,
        closeAt: json['close_at'] as String?,
      );

  final int id;
  final String title;
  final String? description;
  final String category;
  final bool isRequired;
  final String? publishAt;
  final String? closeAt;
}

/// One choice of a single/multi question.
class SurveyOption {
  SurveyOption({required this.id, required this.text});

  factory SurveyOption.fromJson(Map<String, dynamic> json) => SurveyOption(
        id: json['id'] as int,
        text: json['text'] as String? ?? '',
      );

  final int id;
  final String text;
}

/// A published question snapshot — `question_type` mirrors the backend
/// enum single|multi|likert|rating|free_text|external_url.
class SurveyQuestion {
  SurveyQuestion({
    required this.id,
    required this.sortOrder,
    required this.questionType,
    required this.questionText,
    required this.isRequired,
    required this.options,
  });

  factory SurveyQuestion.fromJson(Map<String, dynamic> json) =>
      SurveyQuestion(
        id: json['id'] as int,
        sortOrder: json['sort_order'] as int? ?? 0,
        questionType: json['question_type'] as String? ?? 'free_text',
        questionText: json['question_text'] as String? ?? '',
        isRequired: json['is_required'] == true,
        options: ((json['options'] as List<dynamic>?) ?? const [])
            .whereType<Map<String, dynamic>>()
            .map(SurveyOption.fromJson)
            .toList(),
      );

  final int id;
  final int sortOrder;
  final String questionType;
  final String questionText;
  final bool isRequired;
  final List<SurveyOption> options;
}

/// The answerable form from `GET /me/guidance/surveys/{id}` — the
/// immutable published version snapshot.
class MySurveyForm {
  MySurveyForm({
    required this.id,
    required this.title,
    required this.description,
    required this.closeAt,
    required this.versionId,
    required this.questions,
  });

  factory MySurveyForm.fromJson(Map<String, dynamic> json) => MySurveyForm(
        id: json['id'] as int,
        title: json['title'] as String? ?? '',
        description: json['description'] as String?,
        closeAt: json['close_at'] as String?,
        versionId: json['version_id'] as int,
        questions: ((json['questions'] as List<dynamic>?) ?? const [])
            .whereType<Map<String, dynamic>>()
            .map(SurveyQuestion.fromJson)
            .toList(),
      );

  final int id;
  final String title;
  final String? description;
  final String? closeAt;
  final int versionId;
  final List<SurveyQuestion> questions;
}
