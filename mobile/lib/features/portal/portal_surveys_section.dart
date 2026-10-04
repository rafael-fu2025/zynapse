import 'package:flutter/foundation.dart';
import 'package:flutter/material.dart';
import 'package:hugeicons/hugeicons.dart';

import '../../core/models/survey.dart';
import '../../core/services/api_service.dart';
import '../../core/utils/dates.dart';
import '../common/widgets.dart';
import 'take_survey_screen.dart';

/// "Guidance surveys" — the student's open surveys & interviews
/// (mirrors the web portal's GuidancePortalTab: required items are the
/// clearance checklist, the rest are optional forms). Hides itself when
/// nothing is open. Tapping a survey answers it in-app.
class PortalSurveysSection extends StatefulWidget {
  const PortalSurveysSection({super.key});

  @override
  State<PortalSurveysSection> createState() => _PortalSurveysSectionState();
}

class _PortalSurveysSectionState extends State<PortalSurveysSection> {
  List<MySurvey> _items = const [];
  bool _loading = true;
  bool _failed = false;

  @override
  void initState() {
    super.initState();
    _load();
  }

  Future<void> _load() async {
    try {
      final items = await ApiService.I.myGuidanceSurveys();
      if (!mounted) return;
      setState(() {
        _items = items;
        _loading = false;
        _failed = false;
      });
    } catch (e) {
      if (kDebugMode) debugPrint('PortalSurveysSection.load failed: $e');
      if (!mounted) return;
      setState(() {
        _loading = false;
        _failed = true;
      });
    }
  }

  Future<void> _take(MySurvey survey) async {
    await Navigator.of(context).push(MaterialPageRoute<bool>(
      builder: (_) => TakeSurveyScreen(surveyId: survey.id),
    ));
    // Re-fetch: a submission removes the survey from the open list.
    _load();
  }

  @override
  Widget build(BuildContext context) {
    if (_loading) {
      return const SectionCard(
        title: 'Guidance surveys',
        icon: HugeIcons.strokeRoundedClipboard,
        child: Padding(
          padding: EdgeInsets.symmetric(vertical: 8),
          child: Center(child: CircularProgressIndicator()),
        ),
      );
    }
    // A transient failure shouldn't blank out the portal — hide quietly.
    if (_failed || _items.isEmpty) return const SizedBox.shrink();

    return SectionCard(
      title: 'Guidance surveys',
      icon: HugeIcons.strokeRoundedClipboard,
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.stretch,
        children: [
          for (final (i, survey) in _items.indexed) ...[
            if (i > 0) const Divider(height: 1),
            _SurveyRow(survey: survey, onTake: () => _take(survey)),
          ],
        ],
      ),
    );
  }
}

class _SurveyRow extends StatelessWidget {
  const _SurveyRow({required this.survey, required this.onTake});

  final MySurvey survey;
  final VoidCallback onTake;

  @override
  Widget build(BuildContext context) {
    return Padding(
      padding: const EdgeInsets.symmetric(vertical: 10),
      child: Row(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          Expanded(
            child: Column(
              crossAxisAlignment: CrossAxisAlignment.start,
              children: [
                Row(
                  crossAxisAlignment: CrossAxisAlignment.start,
                  children: [
                    Expanded(
                      child: Text(
                        survey.title,
                        style: const TextStyle(fontWeight: FontWeight.w600),
                      ),
                    ),
                    if (survey.isRequired) ...[
                      const SizedBox(width: 6),
                      const StatusBadge(
                        label: 'Required',
                        color: Color(0xFFB45309),
                      ),
                    ],
                  ],
                ),
                if (survey.description != null &&
                    survey.description!.isNotEmpty)
                  Padding(
                    padding: const EdgeInsets.only(top: 2),
                    child: Text(
                      survey.description!,
                      style:
                          const TextStyle(color: Colors.black54, fontSize: 12),
                    ),
                  ),
                Padding(
                  padding: const EdgeInsets.only(top: 2),
                  child: Text(
                    survey.closeAt != null
                        ? 'Closes ${fmtUtcToApp(survey.closeAt!)}'
                        : 'No deadline set',
                    style: const TextStyle(color: Colors.black54, fontSize: 12),
                  ),
                ),
              ],
            ),
          ),
          const SizedBox(width: 8),
          FilledButton.tonal(
            onPressed: onTake,
            child: const Text('Take survey'),
          ),
        ],
      ),
    );
  }
}
