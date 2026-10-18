import 'dart:convert';
import 'dart:typed_data';

import 'package:flutter/material.dart';
import 'package:hugeicons/hugeicons.dart';
import 'package:provider/provider.dart';

import '../../core/api/api_client.dart';
import '../../core/services/api_service.dart';
import '../../core/services/auth_controller.dart';
import '../../core/utils/dates.dart';
import '../common/crud_form.dart';
import '../common/widgets.dart';

/// Guidance survey management — staff list, draft metadata, publish/archive,
/// and response inspection. Mirrors the SPA `SurveysPage`.
///
/// The question builder itself is not yet on mobile; the screen manages
/// survey metadata and lifecycle, and opens the published/draft questions
/// read-only with the response list and verbatim answers.
class SurveyManagementScreen extends StatefulWidget {
  const SurveyManagementScreen({super.key});

  @override
  State<SurveyManagementScreen> createState() =>
      _SurveyManagementScreenState();
}

class _SurveyManagementScreenState extends State<SurveyManagementScreen> {
  bool _archived = false;
  bool _loading = true;
  bool _busy = false;
  String? _error;
  List<Map<String, dynamic>> _items = const [];
  final _selected = <int>{};

  @override
  void initState() {
    super.initState();
    _load();
  }

  Future<void> _load() async {
    setState(() {
      _loading = _items.isEmpty;
      _error = null;
    });
    try {
      final items =
          await ApiService.I.adminGuidanceSurveys(archived: _archived);
      if (!mounted) return;
      setState(() {
        _items = items;
        _loading = false;
        _selected.clear();
      });
    } catch (e) {
      if (!mounted) return;
      setState(() {
        _error = mapDioError(e).message;
        _loading = false;
      });
    }
  }

  Future<void> _edit([Map<String, dynamic>? survey]) async {
    final isEdit = survey != null;
    final payload = await showCrudForm(
      context,
      title: isEdit ? 'Edit survey' : 'New survey draft',
      fields: [
        CrudField.text(
          'title',
          'Title',
          initial: survey?['title'] as String?,
          maxLength: 200,
        ),
        CrudField.text(
          'description',
          'Description',
          required: false,
          initial: survey?['description'] as String?,
          maxLength: 1000,
        ),
        CrudField.dropdown(
          'category',
          'Category',
          const ['survey', 'interview'],
          initial: (survey?['category'] as String?) ?? 'survey',
        ),
        CrudField.dropdown(
          'audience',
          'Audience',
          const [
            'all',
            'new_students',
            'continuing_students',
            'graduating_students',
          ],
          initial: (survey?['audience'] as String?) ?? 'all',
        ),
        CrudField.bool(
          'is_required',
          'Required for clearance',
          boolInitial: survey?['is_required'] == true,
        ),
        CrudField.text(
          'publish_at',
          'Publish at (optional)',
          required: false,
          hint: 'YYYY-MM-DD HH:MM:SS',
          initial: survey?['publish_at'] as String?,
        ),
        CrudField.text(
          'close_at',
          'Close at (optional)',
          required: false,
          hint: 'YYYY-MM-DD HH:MM:SS',
          initial: survey?['close_at'] as String?,
        ),
      ],
      submitLabel: isEdit ? 'Save draft' : 'Create draft',
    );
    if (payload == null || !mounted) return;
    final body = <String, dynamic>{
      'title': payload['title'],
      'description': (payload['description'] as String?)?.trim() ?? '',
      'category': payload['category'],
      'audience': payload['audience'],
      'is_required': payload['is_required'] == true,
      if ((payload['publish_at'] as String?)?.trim().isNotEmpty ?? false)
        'publish_at': payload['publish_at'],
      if ((payload['close_at'] as String?)?.trim().isNotEmpty ?? false)
        'close_at': payload['close_at'],
    };
    final ok = await runCrudAction(
      context,
      () => isEdit
          ? ApiService.I.updateGuidanceSurvey(
              (survey['id'] as num).toInt(),
              body,
            )
          : ApiService.I.createGuidanceSurvey(body),
      successMessage: isEdit ? 'Draft updated.' : 'Survey draft created.',
    );
    if (ok) _load();
  }

  Future<void> _publish(Map<String, dynamic> survey) async {
    final confirmed = await showCrudConfirm(
      context,
      title: 'Publish survey?',
      message: 'Publish “${survey['title']}”? Questions freeze on publish and '
          'students can start answering.',
      confirmLabel: 'Publish',
    );
    if (!confirmed || !mounted) return;
    final ok = await runCrudAction(
      context,
      () => ApiService.I.publishGuidanceSurvey((survey['id'] as num).toInt()),
      successMessage: 'Survey published.',
    );
    if (ok) _load();
  }

  Future<void> _toggleArchive(Map<String, dynamic> survey) async {
    final archived = survey['archived_at'] != null;
    final ok = await runCrudAction(
      context,
      () => ApiService.I.archiveGuidanceSurvey(
        (survey['id'] as num).toInt(),
        archived: !archived,
      ),
      successMessage: archived ? 'Survey restored.' : 'Survey archived.',
    );
    if (ok) _load();
  }

  Future<void> _deletePermanently(Map<String, dynamic> survey) async {
    final confirmed = await showCrudConfirm(
      context,
      title: 'Delete permanently?',
      message: 'Permanently delete “${survey['title']}”? This cannot be '
          'undone. Surveys with versions or responses cannot be deleted.',
      confirmLabel: 'Delete permanently',
      destructive: true,
    );
    if (!confirmed || !mounted) return;
    final ok = await runCrudAction(
      context,
      () => ApiService.I.deletePermanently(
        'surveys',
        (survey['id'] as num).toInt(),
      ),
      successMessage: 'Survey permanently deleted.',
    );
    if (ok) _load();
  }

  void _openDetail(Map<String, dynamic> survey) {
    Navigator.of(context).push(
      MaterialPageRoute(
        builder: (_) => SurveyDetailScreen(
          surveyId: (survey['id'] as num).toInt(),
          title: (survey['title'] ?? 'Survey') as String,
        ),
      ),
    );
  }

  @override
  Widget build(BuildContext context) {
    return Scaffold(
      appBar: AppBar(
        title: const Text('Surveys'),
        actions: [
          IconButton(
            onPressed: _load,
            tooltip: 'Refresh',
            icon: const Icon(HugeIcons.strokeRoundedRefresh),
          ),
        ],
      ),
      body: Column(
        children: [
          Padding(
            padding: const EdgeInsets.fromLTRB(16, 10, 16, 4),
            child: Row(
              children: [
                FilterChip(
                  label: const Text('Archived'),
                  selected: _archived,
                  onSelected: (value) {
                    setState(() => _archived = value);
                    _load();
                  },
                ),
                const Spacer(),
                FilledButton.icon(
                  onPressed: _busy ? null : () => _edit(),
                  icon: const Icon(HugeIcons.strokeRoundedAdd01, size: 18),
                  label: const Text('New draft'),
                ),
              ],
            ),
          ),
          Expanded(
            child: RefreshIndicator(onRefresh: _load, child: _buildBody()),
          ),
        ],
      ),
    );
  }

  Widget _buildBody() {
    if (_loading) return AsyncState.loading();
    if (_error != null) return AsyncState.error(_error!, onRetry: _load);
    if (_items.isEmpty) {
      return AsyncState.empty(
        _archived ? 'No archived surveys.' : 'No surveys yet.',
      );
    }
    return ListView.separated(
      padding: const EdgeInsets.all(16),
      itemCount: _items.length,
      separatorBuilder: (_, __) => const SizedBox(height: 8),
      itemBuilder: (context, i) {
        final survey = _items[i];
        final archived = survey['archived_at'] != null;
        final status = (survey['status'] ?? 'draft') as String;
        return Card(
          elevation: 0,
          margin: EdgeInsets.zero,
          shape: RoundedRectangleBorder(
            borderRadius: BorderRadius.circular(12),
            side: BorderSide(
              color: Theme.of(context)
                  .colorScheme
                  .outlineVariant
                  .withValues(alpha: 0.5),
            ),
          ),
          child: ListTile(
            onTap: () => _openDetail(survey),
            title: Row(
              children: [
                Expanded(
                  child: Text(
                    (survey['title'] ?? '') as String,
                    style: const TextStyle(fontWeight: FontWeight.w700),
                  ),
                ),
                StatusBadge(
                  label: archived
                      ? 'Archived'
                      : titleCaseOption(status),
                  color: archived
                      ? Colors.black45
                      : status == 'live'
                          ? const Color(0xFF1B7A43)
                          : status == 'draft'
                              ? const Color(0xFF8A5A00)
                              : const Color(0xFF1E6FD9),
                ),
              ],
            ),
            subtitle: Padding(
              padding: const EdgeInsets.only(top: 4),
              child: Text(
                [
                  titleCaseOption((survey['category'] ?? 'survey') as String),
                  titleCaseOption((survey['audience'] ?? 'all') as String),
                  '${survey['question_count'] ?? 0} questions',
                  '${survey['response_count'] ?? 0} responses',
                ].join(' · '),
                style: const TextStyle(fontSize: 11.5),
              ),
            ),
            isThreeLine: true,
            trailing: PopupMenuButton<String>(
              enabled: !_busy,
              icon: const Icon(HugeIcons.strokeRoundedMore),
              onSelected: (action) {
                switch (action) {
                  case 'view':
                    _openDetail(survey);
                  case 'edit':
                    _edit(survey);
                  case 'publish':
                    _publish(survey);
                  case 'archive':
                    _toggleArchive(survey);
                  case 'delete':
                    _deletePermanently(survey);
                }
              },
              itemBuilder: (_) => [
                const PopupMenuItem(value: 'view', child: Text('View')),
                if (!archived && status == 'draft')
                  const PopupMenuItem(
                      value: 'edit', child: Text('Edit draft')),
                if (!archived && status == 'draft')
                  const PopupMenuItem(
                      value: 'publish', child: Text('Publish')),
                PopupMenuItem(
                  value: 'archive',
                  child: Text(archived ? 'Restore' : 'Archive'),
                ),
                if (archived)
                  const PopupMenuItem(
                      value: 'delete', child: Text('Delete permanently')),
              ],
            ),
          ),
        );
      },
    );
  }
}

class SurveyDetailScreen extends StatefulWidget {
  const SurveyDetailScreen({
    super.key,
    required this.surveyId,
    required this.title,
  });

  final int surveyId;
  final String title;

  @override
  State<SurveyDetailScreen> createState() => _SurveyDetailScreenState();
}

class _SurveyDetailScreenState extends State<SurveyDetailScreen> {
  bool _loading = true;
  String? _error;
  Map<String, dynamic>? _survey;
  List<Map<String, dynamic>> _responses = const [];

  bool get _canReadResponses =>
      context
          .read<AuthController>()
          .session
          ?.hasPermission('counselling.responses.read') ??
      false;

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
      final survey = await ApiService.I.adminGuidanceSurvey(widget.surveyId);
      List<Map<String, dynamic>> responses = const [];
      if (_canReadResponses) {
        responses = await ApiService.I.guidanceSurveyResponses(widget.surveyId);
      }
      if (!mounted) return;
      setState(() {
        _survey = survey;
        _responses = responses;
        _loading = false;
      });
    } catch (e) {
      if (!mounted) return;
      setState(() {
        _error = mapDioError(e).message;
        _loading = false;
      });
    }
  }

  Future<void> _openResponse(Map<String, dynamic> response) async {
    try {
      final detail = await ApiService.I.guidanceSurveyResponse(
        widget.surveyId,
        (response['id'] as num).toInt(),
      );
      if (!mounted) return;
      await showSynapseSheet<void>(
        context,
        builder: (_) => _ResponseDetailSheet(detail: detail),
      );
    } catch (e) {
      if (!mounted) return;
      ScaffoldMessenger.of(context).showSnackBar(
        SnackBar(content: Text(mapDioError(e).message)),
      );
    }
  }

  @override
  Widget build(BuildContext context) {
    final isDraft = (_survey?['status'] ?? '') == 'draft';
    return Scaffold(
      appBar: AppBar(
        title: Text(widget.title),
        actions: [
          if (isDraft)
            IconButton(
              tooltip: 'Edit questions',
              icon: const Icon(HugeIcons.strokeRoundedPlaylist01),
              onPressed: () async {
                final changed = await Navigator.of(context).push<bool>(
                  MaterialPageRoute(
                    builder: (_) => SurveyQuestionsEditorScreen(
                      surveyId: widget.surveyId,
                      title: widget.title,
                      initialQuestions: (_survey?['questions'] as List? ??
                              const [])
                          .whereType<Map<String, dynamic>>()
                          .toList(),
                    ),
                  ),
                );
                if (changed == true) _load();
              },
            ),
          if (isDraft)
            IconButton(
              tooltip: 'Edit links',
              icon: const Icon(HugeIcons.strokeRoundedShare01),
              onPressed: () async {
                final changed = await Navigator.of(context).push<bool>(
                  MaterialPageRoute(
                    builder: (_) => SurveyLinksEditorScreen(
                      surveyId: widget.surveyId,
                      title: widget.title,
                      initialLinks: (_survey?['links'] as List? ?? const [])
                          .whereType<Map<String, dynamic>>()
                          .toList(),
                    ),
                  ),
                );
                if (changed == true) _load();
              },
            ),
        ],
      ),
      body: _buildBody(),
    );
  }

  Widget _buildBody() {
    if (_loading) return AsyncState.loading();
    if (_error != null) return AsyncState.error(_error!, onRetry: _load);
    final survey = _survey!;
    final questions = (survey['questions'] as List? ?? const [])
        .whereType<Map<String, dynamic>>()
        .toList();
    final links = (survey['links'] as List? ?? const [])
        .whereType<Map<String, dynamic>>()
        .toList();
    return RefreshIndicator(
      onRefresh: _load,
      child: ListView(
        padding: const EdgeInsets.all(16),
        children: [
          Wrap(
            spacing: 8,
            runSpacing: 6,
            children: [
              StatusBadge(
                label: titleCaseOption((survey['status'] ?? 'draft') as String),
                color: const Color(0xFF1E6FD9),
              ),
              if (survey['is_required'] == true)
                const StatusBadge(
                  label: 'Required',
                  color: Color(0xFFB3261E),
                ),
              if (survey['version_no'] != null)
                StatusBadge(
                  label: 'v${survey['version_no']}',
                  color: const Color(0xFF5B4BA6),
                ),
            ],
          ),
          if ((survey['description'] as String?)?.isNotEmpty == true) ...[
            const SizedBox(height: 10),
            Text(
              survey['description'] as String,
              style: const TextStyle(fontSize: 13),
            ),
          ],
          const SizedBox(height: 16),
          const Text(
            'Questions',
            style: TextStyle(fontSize: 15, fontWeight: FontWeight.w700),
          ),
          const SizedBox(height: 6),
          if (questions.isEmpty)
            const Text(
              'No questions configured yet.',
              style: TextStyle(fontSize: 12.5, color: Colors.black45),
            )
          else
            for (var i = 0; i < questions.length; i++)
              ListTile(
                contentPadding: EdgeInsets.zero,
                dense: true,
                leading: CircleAvatar(
                  radius: 12,
                  backgroundColor: const Color(0xFFF1F0EE),
                  child: Text(
                    '${i + 1}',
                    style: const TextStyle(
                        fontSize: 11, fontWeight: FontWeight.w700),
                  ),
                ),
                title: Text(
                  (questions[i]['question_text'] ??
                      questions[i]['text'] ??
                      '') as String,
                  style: const TextStyle(fontSize: 12.5),
                ),
                subtitle: Text(
                  [
                    titleCaseOption(
                        (questions[i]['question_type'] ?? '') as String),
                    if (questions[i]['is_required'] == true) 'Required',
                  ].join(' · '),
                  style: const TextStyle(fontSize: 11),
                ),
              ),
          if (links.isNotEmpty) ...[
            const SizedBox(height: 12),
            const Text(
              'Links',
              style: TextStyle(fontSize: 15, fontWeight: FontWeight.w700),
            ),
            for (final link in links)
              ListTile(
                contentPadding: EdgeInsets.zero,
                dense: true,
                leading: const Icon(HugeIcons.strokeRoundedShare01, size: 18),
                title: Text(
                  (link['title'] ?? link['type'] ?? 'Link') as String,
                  style: const TextStyle(fontSize: 12.5),
                ),
                subtitle: Text(
                  [
                    (link['url'] ?? '') as String,
                    if (link['is_required'] == true) 'Required',
                  ].join(' · '),
                  style: const TextStyle(fontSize: 11),
                ),
              ),
          ],
          if (_canReadResponses) ...[
            const SizedBox(height: 16),
            Text(
              'Responses (${_responses.length})',
              style:
                  const TextStyle(fontSize: 15, fontWeight: FontWeight.w700),
            ),
            const SizedBox(height: 6),
            if (_responses.isEmpty)
              const Text(
                'No responses yet.',
                style: TextStyle(fontSize: 12.5, color: Colors.black45),
              )
            else
              for (final response in _responses)
                ListTile(
                  contentPadding: EdgeInsets.zero,
                  dense: true,
                  onTap: () => _openResponse(response),
                  leading: const Icon(
                      HugeIcons.strokeRoundedClipboard,
                      size: 18),
                  title: Text(
                    (response['student_name'] ??
                        response['email'] ??
                        'Student #${response['student_user_id']}') as String,
                    style: const TextStyle(fontSize: 12.5),
                  ),
                  subtitle: Text(
                    [
                      if (response['student_year_level'] != null)
                        'Year ${response['student_year_level']}',
                      fmtUtcShort(response['submitted_at'] as String?),
                    ].join(' · '),
                    style: const TextStyle(fontSize: 11),
                  ),
                  trailing: const Icon(
                    HugeIcons.strokeRoundedArrowRight01,
                    size: 16,
                  ),
                ),
          ],
        ],
      ),
    );
  }
}

class _ResponseDetailSheet extends StatelessWidget {
  const _ResponseDetailSheet({required this.detail});
  final Map<String, dynamic> detail;

  @override
  Widget build(BuildContext context) {
    final answers = detail['answers'];
    final links = (detail['links'] as List? ?? const [])
        .whereType<Map<String, dynamic>>()
        .toList();
    return SafeArea(
      child: Padding(
        padding: const EdgeInsets.fromLTRB(20, 12, 20, 20),
        child: Column(
          mainAxisSize: MainAxisSize.min,
          crossAxisAlignment: CrossAxisAlignment.stretch,
          children: [
            SheetHeader(
              title: (detail['student_name'] ?? 'Response') as String,
              subtitle: 'Submitted '
                  '${fmtUtcShort(detail['submitted_at'] as String?)}',
            ),
            const SizedBox(height: 8),
            Flexible(
              child: ListView(
                shrinkWrap: true,
                children: [
                  Text(
                    answers == null
                        ? 'No answer payload.'
                        : const JsonEncoder.withIndent('  ').convert(answers),
                    style: const TextStyle(fontSize: 11.5, height: 1.4),
                  ),
                  if (links.isNotEmpty) ...[
                    const SizedBox(height: 12),
                    const Text(
                      'External links',
                      style: TextStyle(
                          fontSize: 13, fontWeight: FontWeight.w700),
                    ),
                    for (final link in links)
                      ListTile(
                        contentPadding: EdgeInsets.zero,
                        dense: true,
                        title: Text(
                          (link['title'] ?? link['type'] ?? 'Link') as String,
                          style: const TextStyle(fontSize: 12.5),
                        ),
                        subtitle: Text(
                          [
                            if (link['opened'] == true) 'Opened',
                            if (link['screenshot'] != null)
                              'Screenshot attached',
                          ].join(' · '),
                          style: const TextStyle(fontSize: 11),
                        ),
                        trailing: link['screenshot'] is Map<String, dynamic>
                            ? TextButton(
                                onPressed: () {
                                  final screenshot =
                                      link['screenshot'] as Map<String, dynamic>;
                                  showDialog<void>(
                                    context: context,
                                    builder: (_) => _ScreenshotDialog(
                                      surveyId:
                                          (detail['survey_id'] as num).toInt(),
                                      responseId:
                                          (detail['id'] as num).toInt(),
                                      screenshotId:
                                          (screenshot['id'] as num).toInt(),
                                      name: screenshot['original_name']
                                              as String? ??
                                          'Screenshot',
                                    ),
                                  );
                                },
                                child: const Text('View'),
                              )
                            : null,
                      ),
                  ],
                ],
              ),
            ),
          ],
        ),
      ),
    );
  }
}

class SurveyQuestionsEditorScreen extends StatefulWidget {
  const SurveyQuestionsEditorScreen({
    super.key,
    required this.surveyId,
    required this.title,
    required this.initialQuestions,
  });

  final int surveyId;
  final String title;
  final List<Map<String, dynamic>> initialQuestions;

  @override
  State<SurveyQuestionsEditorScreen> createState() =>
      _SurveyQuestionsEditorScreenState();
}

class _SurveyQuestionsEditorScreenState
    extends State<SurveyQuestionsEditorScreen> {
  static const _types = [
    'single',
    'multi',
    'likert',
    'rating',
    'free_text',
    'external_url',
  ];

  late final List<Map<String, dynamic>> _questions =
      widget.initialQuestions.map((question) {
    final options = (question['options'] as List? ?? const [])
        .whereType<Map<String, dynamic>>()
        .map((option) => (option['text'] ?? '') as String)
        .where((text) => text.isNotEmpty)
        .join('\n');
    return <String, dynamic>{
      'text': (question['question_text'] ?? '') as String,
      'type': (question['question_type'] ?? 'single') as String,
      'required': question['is_required'] == true,
      'options': options,
    };
  }).toList();

  bool _saving = false;
  String? _error;

  Future<void> _addOrEdit([int? index]) async {
    final current = index == null ? null : _questions[index];
    final payload = await showCrudForm(
      context,
      title: index == null ? 'Add question' : 'Edit question',
      fields: [
        CrudField.text(
          'question_text',
          'Question',
          initial: current?['text'] as String?,
          maxLength: 500,
        ),
        CrudField.dropdown(
          'question_type',
          'Type',
          _types,
          initial: (current?['type'] as String?) ?? 'single',
        ),
        CrudField.bool(
          'is_required',
          'Required',
          boolInitial: current?['required'] == true,
        ),
        CrudField.text(
          'options',
          'Options (one per line, single/multi only)',
          required: false,
          initial: current?['options'] as String?,
        ),
      ],
      submitLabel: index == null ? 'Add' : 'Save',
    );
    if (payload == null || !mounted) return;
    final text = (payload['question_text'] as String? ?? '').trim();
    final type = (payload['question_type'] as String?) ?? 'single';
    final optionsText = (payload['options'] as String? ?? '')
        .split('\n')
        .map((line) => line.trim())
        .where((line) => line.isNotEmpty)
        .toList();
    if (text.isEmpty) {
      showCrudMessage(context, 'Question text is required.', error: true);
      return;
    }
    if ((type == 'single' || type == 'multi') && optionsText.length < 2) {
      showCrudMessage(
        context,
        'Single/multi questions need at least two options.',
        error: true,
      );
      return;
    }
    setState(() {
      final next = <String, dynamic>{
        'text': text,
        'type': type,
        'required': payload['is_required'] == true,
        'options': optionsText.join('\n'),
      };
      if (index == null) {
        _questions.add(next);
      } else {
        _questions[index] = next;
      }
    });
  }

  void _move(int index, int direction) {
    final target = index + direction;
    if (target < 0 || target >= _questions.length) return;
    setState(() {
      final moved = _questions.removeAt(index);
      _questions.insert(target, moved);
    });
  }

  void _remove(int index) {
    setState(() => _questions.removeAt(index));
  }

  Future<void> _save() async {
    if (_questions.isEmpty) {
      setState(() => _error = 'Add at least one question.');
      return;
    }
    setState(() {
      _saving = true;
      _error = null;
    });
    try {
      final questions = _questions.map((question) {
        final type = question['type'] as String;
        final options = (question['options'] as String)
            .split('\n')
            .map((line) => line.trim())
            .where((line) => line.isNotEmpty)
            .toList();
        return <String, dynamic>{
          'question_text': question['text'],
          'question_type': type,
          'is_required': question['required'] == true,
          'options': (type == 'single' || type == 'multi') ? options : [],
        };
      }).toList();
      await ApiService.I.setGuidanceSurveyQuestions(
        widget.surveyId,
        questions,
      );
      if (!mounted) return;
      Navigator.of(context).pop(true);
    } catch (e) {
      if (!mounted) return;
      setState(() {
        _error = mapDioError(e).message;
        _saving = false;
      });
    }
  }

  @override
  Widget build(BuildContext context) {
    return Scaffold(
      appBar: AppBar(
        title: const Text('Survey questions'),
        actions: [
          TextButton(
            onPressed: _saving ? null : _save,
            child: const Text('Save'),
          ),
        ],
      ),
      floatingActionButton: FloatingActionButton.extended(
        onPressed: _saving ? null : () => _addOrEdit(),
        icon: const Icon(HugeIcons.strokeRoundedAdd01),
        label: const Text('Add question'),
      ),
      body: Column(
        children: [
          Padding(
            padding: const EdgeInsets.fromLTRB(16, 10, 16, 0),
            child: Text(
              widget.title,
              style: const TextStyle(
                fontSize: 12,
                color: Colors.black54,
              ),
            ),
          ),
          if (_error != null)
            Padding(
              padding: const EdgeInsets.fromLTRB(16, 8, 16, 0),
              child: Text(
                _error!,
                style: TextStyle(
                  color: Theme.of(context).colorScheme.error,
                  fontSize: 12.5,
                ),
              ),
            ),
          Expanded(
            child: _questions.isEmpty
                ? AsyncState.empty('No questions yet. Add the first one.')
                : ListView.separated(
                    padding: const EdgeInsets.fromLTRB(16, 10, 16, 90),
                    itemCount: _questions.length,
                    separatorBuilder: (_, __) => const SizedBox(height: 8),
                    itemBuilder: (context, i) {
                      final question = _questions[i];
                      final options = (question['options'] as String)
                          .split('\n')
                          .where((line) => line.trim().isNotEmpty)
                          .length;
                      return Card(
                        elevation: 0,
                        margin: EdgeInsets.zero,
                        shape: RoundedRectangleBorder(
                          borderRadius: BorderRadius.circular(12),
                          side: BorderSide(
                            color: Theme.of(context)
                                .colorScheme
                                .outlineVariant
                                .withValues(alpha: 0.5),
                          ),
                        ),
                        child: ListTile(
                          onTap: _saving ? null : () => _addOrEdit(i),
                          leading: CircleAvatar(
                            radius: 14,
                            backgroundColor: const Color(0xFFF1F0EE),
                            child: Text(
                              '${i + 1}',
                              style: const TextStyle(
                                  fontSize: 11,
                                  fontWeight: FontWeight.w700),
                            ),
                          ),
                          title: Text(
                            question['text'] as String,
                            style: const TextStyle(fontSize: 12.5),
                          ),
                          subtitle: Text(
                            [
                              titleCaseOption(question['type'] as String),
                              if (question['required'] == true) 'Required',
                              if (question['type'] == 'single' ||
                                  question['type'] == 'multi')
                                '$options options',
                            ].join(' · '),
                            style: const TextStyle(fontSize: 11),
                          ),
                          trailing: Row(
                            mainAxisSize: MainAxisSize.min,
                            children: [
                              IconButton(
                                onPressed:
                                    _saving ? null : () => _move(i, -1),
                                icon: const Icon(
                                    Icons.keyboard_arrow_up,
                                    size: 18),
                              ),
                              IconButton(
                                onPressed:
                                    _saving ? null : () => _move(i, 1),
                                icon: const Icon(
                                    Icons.keyboard_arrow_down,
                                    size: 18),
                              ),
                              IconButton(
                                onPressed:
                                    _saving ? null : () => _remove(i),
                                color: const Color(0xFFB3261E),
                                icon: const Icon(
                                    HugeIcons.strokeRoundedDelete02,
                                    size: 18),
                              ),
                            ],
                          ),
                        ),
                      );
                    },
                  ),
          ),
        ],
      ),
    );
  }
}

class _ScreenshotDialog extends StatefulWidget {
  const _ScreenshotDialog({
    required this.surveyId,
    required this.responseId,
    required this.screenshotId,
    required this.name,
  });

  final int surveyId;
  final int responseId;
  final int screenshotId;
  final String name;

  @override
  State<_ScreenshotDialog> createState() => _ScreenshotDialogState();
}

class _ScreenshotDialogState extends State<_ScreenshotDialog> {
  Uint8List? _bytes;
  bool _loading = true;
  String? _error;

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
      final bytes = await ApiService.I.guidanceSurveyScreenshot(
        widget.surveyId,
        widget.responseId,
        widget.screenshotId,
      );
      if (!mounted) return;
      setState(() {
        _bytes = Uint8List.fromList(bytes);
        _loading = false;
      });
    } catch (e) {
      if (!mounted) return;
      setState(() {
        _error = mapDioError(e).message;
        _loading = false;
      });
    }
  }

  @override
  Widget build(BuildContext context) {
    return Dialog(
      child: ConstrainedBox(
        constraints: const BoxConstraints(maxWidth: 640, maxHeight: 640),
        child: Padding(
          padding: const EdgeInsets.all(14),
          child: Column(
            mainAxisSize: MainAxisSize.min,
            crossAxisAlignment: CrossAxisAlignment.stretch,
            children: [
              Row(
                children: [
                  Expanded(
                    child: Text(
                      widget.name,
                      maxLines: 1,
                      overflow: TextOverflow.ellipsis,
                      style: const TextStyle(fontWeight: FontWeight.w700),
                    ),
                  ),
                  IconButton(
                    onPressed: () => Navigator.of(context).pop(),
                    icon: const Icon(HugeIcons.strokeRoundedCancel01),
                  ),
                ],
              ),
              const SizedBox(height: 8),
              Flexible(
                child: _loading
                    ? const Center(child: CircularProgressIndicator())
                    : _error != null
                        ? Center(
                            child: Text(
                              _error!,
                              style: const TextStyle(
                                fontSize: 12.5,
                                color: Color(0xFFB3261E),
                              ),
                            ),
                          )
                        : InteractiveViewer(
                            child: Image.memory(
                              _bytes!,
                              fit: BoxFit.contain,
                            ),
                          ),
              ),
            ],
          ),
        ),
      ),
    );
  }
}

/// Draft dynamic-link editor — external survey / test / evaluation links.
/// Only drafts accept a full replace; published links are hot-fixed on web.
class SurveyLinksEditorScreen extends StatefulWidget {
  const SurveyLinksEditorScreen({
    super.key,
    required this.surveyId,
    required this.title,
    required this.initialLinks,
  });

  final int surveyId;
  final String title;
  final List<Map<String, dynamic>> initialLinks;

  @override
  State<SurveyLinksEditorScreen> createState() =>
      _SurveyLinksEditorScreenState();
}

class _SurveyLinksEditorScreenState extends State<SurveyLinksEditorScreen> {
  static const _types = ['survey', 'test', 'evaluation'];
  static const _audiences = [
    'all',
    'new_students',
    'continuing_students',
    'graduating_students',
  ];
  static const _instruments = [
    'new_transferees',
    'mi',
    'ls',
    'bfpt',
    'custom',
  ];

  late final List<Map<String, dynamic>> _links = widget.initialLinks
      .map((link) => <String, dynamic>{
            'type': (link['type'] ?? 'survey') as String,
            'title': (link['title'] ?? '') as String,
            'description': (link['description'] ?? '') as String,
            'url': (link['external_url'] ?? '') as String,
            'audience': (link['audience'] ?? 'all') as String?,
            'instrument': (link['instrument_key'] ?? 'custom') as String?,
            'requires_screenshot': link['requires_screenshot'] == true,
            'enabled': link['is_enabled'] != false,
          })
      .toList();

  bool _saving = false;
  String? _error;

  Future<void> _addOrEdit([int? index]) async {
    final current = index == null ? null : _links[index];
    final payload = await showCrudForm(
      context,
      title: index == null ? 'Add link' : 'Edit link',
      fields: [
        CrudField.dropdown(
          'type',
          'Type',
          _types,
          initial: (current?['type'] as String?) ?? 'survey',
        ),
        CrudField.text(
          'title',
          'Title',
          initial: current?['title'] as String?,
          maxLength: 200,
        ),
        CrudField.text(
          'description',
          'Description',
          required: false,
          initial: current?['description'] as String?,
          maxLength: 1000,
        ),
        CrudField.text(
          'external_url',
          'External URL',
          hint: 'https://…',
          initial: current?['url'] as String?,
          maxLength: 500,
        ),
        CrudField.dropdown(
          'audience',
          'Audience (survey links)',
          _audiences,
          initial: (current?['audience'] as String?) ?? 'all',
        ),
        CrudField.dropdown(
          'instrument',
          'Instrument (test links)',
          _instruments,
          initial: (current?['instrument'] as String?) ?? 'custom',
        ),
        CrudField.bool(
          'requires_screenshot',
          'Ask for a result screenshot',
          boolInitial: current?['requires_screenshot'] == true,
        ),
        CrudField.bool(
          'enabled',
          'Enabled',
          boolInitial: current?['enabled'] != false,
        ),
      ],
      submitLabel: index == null ? 'Add' : 'Save',
    );
    if (payload == null || !mounted) return;
    final title = (payload['title'] as String? ?? '').trim();
    final url = (payload['external_url'] as String? ?? '').trim();
    if (title.isEmpty || url.isEmpty) {
      showCrudMessage(context, 'Title and URL are required.', error: true);
      return;
    }
    if (!RegExp(r'^https?://', caseSensitive: false).hasMatch(url)) {
      showCrudMessage(
        context,
        'External URL must start with http:// or https://.',
        error: true,
      );
      return;
    }
    setState(() {
      final next = <String, dynamic>{
        'type': payload['type'],
        'title': title,
        'description': (payload['description'] as String? ?? '').trim(),
        'url': url,
        'audience': payload['audience'],
        'instrument': payload['instrument'],
        'requires_screenshot': payload['requires_screenshot'] == true,
        'enabled': payload['enabled'] == true,
      };
      if (index == null) {
        _links.add(next);
      } else {
        _links[index] = next;
      }
    });
  }

  void _move(int index, int direction) {
    final target = index + direction;
    if (target < 0 || target >= _links.length) return;
    setState(() {
      final moved = _links.removeAt(index);
      _links.insert(target, moved);
    });
  }

  Future<void> _save() async {
    setState(() {
      _saving = true;
      _error = null;
    });
    try {
      final links = _links
          .where((link) =>
              (link['title'] as String).trim().isNotEmpty &&
              (link['url'] as String).trim().isNotEmpty)
          .map((link) => <String, dynamic>{
                'type': link['type'],
                'title': (link['title'] as String).trim(),
                'description':
                    (link['description'] as String).trim().isEmpty
                        ? null
                        : (link['description'] as String).trim(),
                'external_url': (link['url'] as String).trim(),
                'audience': link['type'] == 'survey' ? link['audience'] : null,
                'instrument_key':
                    link['type'] == 'test' ? link['instrument'] : null,
                'requires_screenshot': link['requires_screenshot'] == true,
                'is_enabled': link['enabled'] == true,
              })
          .toList();
      await ApiService.I.setGuidanceSurveyLinks(widget.surveyId, links);
      if (!mounted) return;
      Navigator.of(context).pop(true);
    } catch (e) {
      if (!mounted) return;
      setState(() {
        _error = mapDioError(e).message;
        _saving = false;
      });
    }
  }

  @override
  Widget build(BuildContext context) {
    return Scaffold(
      appBar: AppBar(
        title: const Text('Survey links'),
        actions: [
          TextButton(
            onPressed: _saving ? null : _save,
            child: const Text('Save'),
          ),
        ],
      ),
      floatingActionButton: FloatingActionButton.extended(
        onPressed: _saving ? null : () => _addOrEdit(),
        icon: const Icon(HugeIcons.strokeRoundedAdd01),
        label: const Text('Add link'),
      ),
      body: Column(
        children: [
          Padding(
            padding: const EdgeInsets.fromLTRB(16, 10, 16, 0),
            child: Text(
              widget.title,
              style:
                  const TextStyle(fontSize: 12, color: Colors.black54),
            ),
          ),
          if (_error != null)
            Padding(
              padding: const EdgeInsets.fromLTRB(16, 8, 16, 0),
              child: Text(
                _error!,
                style: TextStyle(
                  color: Theme.of(context).colorScheme.error,
                  fontSize: 12.5,
                ),
              ),
            ),
          Expanded(
            child: _links.isEmpty
                ? AsyncState.empty('No external links yet.')
                : ListView.separated(
                    padding: const EdgeInsets.fromLTRB(16, 10, 16, 90),
                    itemCount: _links.length,
                    separatorBuilder: (_, __) => const SizedBox(height: 8),
                    itemBuilder: (context, i) {
                      final link = _links[i];
                      return Card(
                        elevation: 0,
                        margin: EdgeInsets.zero,
                        shape: RoundedRectangleBorder(
                          borderRadius: BorderRadius.circular(12),
                          side: BorderSide(
                            color: Theme.of(context)
                                .colorScheme
                                .outlineVariant
                                .withValues(alpha: 0.5),
                          ),
                        ),
                        child: ListTile(
                          onTap: _saving ? null : () => _addOrEdit(i),
                          leading: const Icon(
                            HugeIcons.strokeRoundedShare01,
                            size: 18,
                          ),
                          title: Text(
                            link['title'] as String,
                            style: const TextStyle(fontSize: 12.5),
                          ),
                          subtitle: Text(
                            [
                              titleCaseOption(link['type'] as String),
                              link['url'] as String,
                              if (link['requires_screenshot'] == true)
                                'Screenshot',
                              if (link['enabled'] != true) 'Disabled',
                            ].join(' · '),
                            maxLines: 2,
                            overflow: TextOverflow.ellipsis,
                            style: const TextStyle(fontSize: 11),
                          ),
                          trailing: Row(
                            mainAxisSize: MainAxisSize.min,
                            children: [
                              IconButton(
                                onPressed:
                                    _saving ? null : () => _move(i, -1),
                                icon: const Icon(
                                    Icons.keyboard_arrow_up,
                                    size: 18),
                              ),
                              IconButton(
                                onPressed:
                                    _saving ? null : () => _move(i, 1),
                                icon: const Icon(
                                    Icons.keyboard_arrow_down,
                                    size: 18),
                              ),
                              IconButton(
                                onPressed: _saving
                                    ? null
                                    : () => setState(
                                        () => _links.removeAt(i)),
                                color: const Color(0xFFB3261E),
                                icon: const Icon(
                                    HugeIcons.strokeRoundedDelete02,
                                    size: 18),
                              ),
                            ],
                          ),
                        ),
                      );
                    },
                  ),
          ),
        ],
      ),
    );
  }
}
