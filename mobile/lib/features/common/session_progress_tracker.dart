import 'package:flutter/material.dart';

enum SessionStepState { complete, current, available, optional, unavailable }

class SessionProgressStep {
  const SessionProgressStep({
    required this.id,
    required this.label,
    required this.summary,
    required this.state,
  });

  final String id;
  final String label;
  final String summary;
  final SessionStepState state;
}

/// Compact parcel-style progress points for clinic and counselling sessions.
/// The strip scrolls horizontally on small phones and keeps the selected
/// step's detail below it, avoiding a stack of dialogs or nested tabs.
class SessionProgressTracker extends StatelessWidget {
  const SessionProgressTracker({
    super.key,
    required this.steps,
    required this.selectedId,
    required this.onSelected,
  });

  final List<SessionProgressStep> steps;
  final String selectedId;
  final ValueChanged<String> onSelected;

  @override
  Widget build(BuildContext context) {
    final colors = Theme.of(context).colorScheme;
    return Semantics(
      label: 'Session progress',
      child: SizedBox(
        height: 102,
        child: ListView.builder(
          scrollDirection: Axis.horizontal,
          padding: const EdgeInsets.symmetric(horizontal: 16, vertical: 8),
          itemCount: steps.length,
          itemBuilder: (context, index) {
            final step = steps[index];
            final selected = step.id == selectedId;
            final disabled = step.state == SessionStepState.unavailable;
            final color = _stepColor(colors, step.state);
            return Semantics(
              button: true,
              selected: selected,
              enabled: !disabled,
              label: '${step.label}. ${step.summary}',
              child: InkWell(
                borderRadius: BorderRadius.circular(12),
                onTap: disabled ? null : () => onSelected(step.id),
                child: SizedBox(
                  width: 104,
                  child: Column(
                    children: [
                      Row(
                        children: [
                          if (index > 0)
                            Expanded(
                              child: Container(
                                height: 2,
                                color:
                                    _connectorColor(colors, steps[index - 1]),
                              ),
                            )
                          else
                            const Spacer(),
                          AnimatedContainer(
                            duration: const Duration(milliseconds: 180),
                            width: selected ? 34 : 30,
                            height: selected ? 34 : 30,
                            decoration: BoxDecoration(
                              shape: BoxShape.circle,
                              color: step.state == SessionStepState.complete
                                  ? color
                                  : colors.surface,
                              border: Border.all(
                                color: color,
                                width: selected ? 3 : 2,
                              ),
                            ),
                            child: Icon(
                              step.state == SessionStepState.complete
                                  ? Icons.check_rounded
                                  : _stateIcon(step.state),
                              size: 17,
                              color: step.state == SessionStepState.complete
                                  ? colors.onPrimary
                                  : color,
                            ),
                          ),
                          if (index < steps.length - 1)
                            Expanded(
                              child: Container(
                                height: 2,
                                color: _connectorColor(colors, step),
                              ),
                            )
                          else
                            const Spacer(),
                        ],
                      ),
                      const SizedBox(height: 7),
                      Text(
                        step.label,
                        maxLines: 1,
                        overflow: TextOverflow.ellipsis,
                        textAlign: TextAlign.center,
                        style: TextStyle(
                          fontSize: 12,
                          fontWeight:
                              selected ? FontWeight.w800 : FontWeight.w600,
                          color: disabled
                              ? colors.onSurfaceVariant
                              : colors.onSurface,
                        ),
                      ),
                      Text(
                        step.summary,
                        maxLines: 1,
                        overflow: TextOverflow.ellipsis,
                        textAlign: TextAlign.center,
                        style: TextStyle(
                            fontSize: 10, color: colors.onSurfaceVariant),
                      ),
                    ],
                  ),
                ),
              ),
            );
          },
        ),
      ),
    );
  }

  static Color _stepColor(ColorScheme colors, SessionStepState state) =>
      switch (state) {
        SessionStepState.complete => const Color(0xFF1B7A43),
        SessionStepState.current => colors.primary,
        SessionStepState.available => const Color(0xFF1E6FD9),
        SessionStepState.optional => const Color(0xFF8A5A00),
        SessionStepState.unavailable => colors.outline,
      };

  static Color _connectorColor(ColorScheme colors, SessionProgressStep step) =>
      step.state == SessionStepState.complete
          ? const Color(0xFF1B7A43)
          : colors.outlineVariant;

  static IconData _stateIcon(SessionStepState state) => switch (state) {
        SessionStepState.current => Icons.radio_button_checked_rounded,
        SessionStepState.optional => Icons.circle_outlined,
        SessionStepState.unavailable => Icons.lock_outline_rounded,
        _ => Icons.circle_rounded,
      };
}
