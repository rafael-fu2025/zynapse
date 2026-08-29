import 'package:flutter/material.dart';
import 'package:flutter/services.dart';
import 'package:hugeicons/hugeicons.dart';

import '../../core/api/api_client.dart';
import '../../core/data/taxonomy.dart';
import 'widgets.dart';

/// Allows a numeric value with an optional leading `-`, digits, and at most
/// one decimal point (`-12.5`, `0.8`, `50`, `-5`). Plain integers stay valid
/// too; the backend still enforces domain rules (e.g. weights must be > 0).
/// Replaces the old `digitsOnly` formatter, which silently blocked decimals
/// and negatives needed for weights, temperatures and percentages.
class _DecimalInputFormatter extends TextInputFormatter {
  const _DecimalInputFormatter();

  static final _pattern = RegExp(r'^-?\d*\.?\d*$');

  @override
  TextEditingValue formatEditUpdate(
    TextEditingValue oldValue,
    TextEditingValue newValue,
  ) {
    if (newValue.text.isEmpty || _pattern.hasMatch(newValue.text)) {
      return newValue;
    }
    return oldValue;
  }
}

/// Title-case a dropdown option label for display (values stay lowercase
/// so the API contract is unchanged):
///   - "moisture_adjustment" → "Moisture Adjustment"; "low" → "Low";
///     "excellent" → "Excellent".
/// Already-cased strings (codes/names like "FOOD-SCRP", "DRM-01",
/// "Food Scraps") are left untouched.
String titleCaseOption(String value) {
  if (value == value.toUpperCase() || value.contains(RegExp('[A-Z]'))) {
    return value;
  }
  return value
      .split(RegExp(r'[_\s]+'))
      .where((w) => w.isNotEmpty)
      .map((w) => w[0].toUpperCase() + w.substring(1).toLowerCase())
      .join(' ');
}

/// A declarative field for a CRUD form sheet.
class CrudField {
  const CrudField.text(
    this.key,
    this.label, {
    this.initial,
    this.required = true,
    this.maxLength,
    this.hint,
    this.keyboard,
  })  : type = CrudFieldType.text,
        boolInitial = null,
        options = null,
        entries = null;

  const CrudField.number(
    this.key,
    this.label, {
    this.initial,
    this.required = true,
    this.maxLength,
    this.hint,
  })  : type = CrudFieldType.number,
        boolInitial = null,
        options = null,
        entries = null,
        keyboard = null;

  const CrudField.bool(
    this.key,
    this.label, {
    this.boolInitial = false,
    this.required = false,
  })  : type = CrudFieldType.bool,
        initial = null,
        options = null,
        entries = null,
        maxLength = null,
        hint = null,
        keyboard = null;

  const CrudField.dropdown(
    this.key,
    this.label,
    this.options, {
    this.initial,
    this.required = true,
  })  : type = CrudFieldType.dropdown,
        boolInitial = null,
        entries = null,
        maxLength = null,
        hint = null,
        keyboard = null;

  const CrudField.date(
    this.key,
    this.label, {
    this.initial,
    this.required = true,
  })  : type = CrudFieldType.date,
        boolInitial = null,
        options = null,
        entries = null,
        maxLength = null,
        hint = null,
        keyboard = null;

  const CrudField.time(
    this.key,
    this.label, {
    this.initial,
    this.required = true,
  })  : type = CrudFieldType.time,
        boolInitial = null,
        options = null,
        entries = null,
        maxLength = null,
        hint = null,
        keyboard = null;

  const CrudField.picker(
    this.key,
    this.label,
    this.entries, {
    this.initial,
    this.required = true,
  })  : type = CrudFieldType.picker,
        boolInitial = null,
        options = null,
        maxLength = null,
        hint = null,
        keyboard = null;

  final String key;
  final String label;
  final CrudFieldType type;
  final String? initial;
  final bool? boolInitial;
  final bool required;
  final int? maxLength;
  final String? hint;
  final List<String>? options;
  final List<TaxonomyEntry>? entries;
  final TextInputType? keyboard;
}

enum CrudFieldType { text, number, bool, dropdown, date, time, picker }

/// Opens a white bottom-sheet form for the given fields.
///
/// Returns the collected payload map on submit, or null when cancelled.
/// Validation: required text/number fields must be non-empty.
Future<Map<String, dynamic>?> showCrudForm(
  BuildContext context, {
  required String title,
  required List<CrudField> fields,
  String submitLabel = 'Save',
  bool fullScreen = false,
}) {
  if (fullScreen) {
    // Long forms (>=5 fields) get a full-screen page so many fields + the
    // keyboard aren't crammed into a bottom sheet (M3: sheets are for
    // secondary/compact content).
    return Navigator.of(context).push<Map<String, dynamic>>(
      MaterialPageRoute(
        fullscreenDialog: true,
        builder: (_) => _CrudFormSheet(
          title: title,
          fields: fields,
          submitLabel: submitLabel,
          fullScreen: true,
        ),
      ),
    );
  }
  return showModalBottomSheet<Map<String, dynamic>>(
    context: context,
    isScrollControlled: true,
    backgroundColor: Colors.white,
    shape: const RoundedRectangleBorder(
      borderRadius: BorderRadius.vertical(top: Radius.circular(20)),
    ),
    builder: (context) => _CrudFormSheet(
      title: title,
      fields: fields,
      submitLabel: submitLabel,
    ),
  );
}

class _CrudFormSheet extends StatefulWidget {
  const _CrudFormSheet({
    required this.title,
    required this.fields,
    required this.submitLabel,
    this.fullScreen = false,
  });

  final String title;
  final List<CrudField> fields;
  final String submitLabel;
  final bool fullScreen;

  @override
  State<_CrudFormSheet> createState() => _CrudFormSheetState();
}

class _CrudFormSheetState extends State<_CrudFormSheet> {
  final _controllers = <String, TextEditingController>{};
  final _boolValues = <String, bool>{};
  final _dropdownValues = <String, String?>{};
  final _fieldErrors = <String, String>{};
  final bool _busy = false;

  @override
  void initState() {
    super.initState();
    for (final f in widget.fields) {
      if (f.type == CrudFieldType.text ||
          f.type == CrudFieldType.number ||
          f.type == CrudFieldType.date ||
          f.type == CrudFieldType.time ||
          f.type == CrudFieldType.picker) {
        _controllers[f.key] = TextEditingController(text: f.initial ?? '');
      } else if (f.type == CrudFieldType.bool) {
        _boolValues[f.key] = f.boolInitial ?? false;
      } else if (f.type == CrudFieldType.dropdown) {
        _dropdownValues[f.key] =
            f.initial != null && (f.options ?? []).contains(f.initial)
                ? f.initial
                : null;
      }
    }
  }

  @override
  void dispose() {
    for (final c in _controllers.values) {
      c.dispose();
    }
    super.dispose();
  }

  Map<String, dynamic> _collect() {
    final payload = <String, dynamic>{};
    for (final f in widget.fields) {
      switch (f.type) {
        case CrudFieldType.text:
          final v = _controllers[f.key]!.text.trim();
          if (v.isNotEmpty) payload[f.key] = v;
          break;
        case CrudFieldType.number:
          final v = _controllers[f.key]!.text.trim();
          if (v.isNotEmpty) payload[f.key] = num.tryParse(v);
          break;
        case CrudFieldType.bool:
          payload[f.key] = _boolValues[f.key] ?? false;
          break;
        case CrudFieldType.dropdown:
          final v = _dropdownValues[f.key];
          if (v != null && v.isNotEmpty) payload[f.key] = v;
          break;
        case CrudFieldType.date:
        case CrudFieldType.time:
        case CrudFieldType.picker:
          final v = _controllers[f.key]!.text.trim();
          if (v.isNotEmpty) payload[f.key] = v;
          break;
      }
    }
    return payload;
  }

  void _submit() {
    final payload = _collect();
    // Per-field inline errors instead of a single bottom-line message.
    final errors = <String, String>{};
    for (final f in widget.fields) {
      if (f.required && f.type != CrudFieldType.bool) {
        final has = payload.containsKey(f.key) &&
            payload[f.key] != null &&
            '${payload[f.key]}'.isNotEmpty;
        if (!has) errors[f.key] = '${f.label} is required';
      }
    }
    if (errors.isNotEmpty) {
      setState(() {
        _fieldErrors
          ..clear()
          ..addAll(errors);
      });
      return;
    }
    Navigator.of(context).pop(payload);
  }

  void _clearError(String key) {
    if (_fieldErrors.containsKey(key)) {
      setState(() => _fieldErrors.remove(key));
    }
  }

  @override
  Widget build(BuildContext context) {
    final bottomInset = MediaQuery.of(context).viewInsets.bottom;
    final form = Column(
      mainAxisSize: MainAxisSize.min,
      crossAxisAlignment: CrossAxisAlignment.stretch,
      children: [
        if (!widget.fullScreen) ...[
          Center(
            child: Container(
              width: 40,
              height: 5,
              margin: const EdgeInsets.only(bottom: 12),
              decoration: BoxDecoration(
                color: const Color(0xFF800000),
                borderRadius: BorderRadius.circular(999),
              ),
            ),
          ),
          Text(
            widget.title,
            style: const TextStyle(fontSize: 18, fontWeight: FontWeight.w700),
          ),
          const SizedBox(height: 16),
        ],
        for (final f in widget.fields) ...[
          _buildField(f),
          const SizedBox(height: 12),
        ],
        FilledButton(
          onPressed: _busy ? null : _submit,
          style: FilledButton.styleFrom(
            backgroundColor: const Color(0xFF800000),
            padding: const EdgeInsets.symmetric(vertical: 14),
          ),
          child: _busy
              ? const SizedBox(
                  width: 20,
                  height: 20,
                  child: CircularProgressIndicator(
                    strokeWidth: 2,
                    color: Colors.white,
                  ),
                )
              : Text(widget.submitLabel),
        ),
      ],
    );

    if (widget.fullScreen) {
      return Scaffold(
        backgroundColor: Colors.white,
        appBar: AppBar(
          title: Text(widget.title),
          leading: IconButton(
            icon: const Icon(HugeIcons.strokeRoundedCancel01),
            tooltip: 'Cancel',
            onPressed: () => Navigator.of(context).pop(),
          ),
        ),
        body: SafeArea(
          child: SingleChildScrollView(
            padding: const EdgeInsets.fromLTRB(20, 16, 20, 24),
            child: form,
          ),
        ),
      );
    }

    return Padding(
      padding: EdgeInsets.only(bottom: bottomInset),
      child: SingleChildScrollView(
        padding: const EdgeInsets.fromLTRB(20, 16, 20, 24),
        child: SafeArea(top: false, child: form),
      ),
    );
  }

  Widget _buildField(CrudField f) {
    final errorText = _fieldErrors[f.key];
    switch (f.type) {
      case CrudFieldType.text:
        return TextField(
          controller: _controllers[f.key],
          keyboardType: f.keyboard,
          maxLength: f.maxLength,
          onChanged: (_) => _clearError(f.key),
          decoration: InputDecoration(
            labelText: f.label,
            hintText: f.hint,
            errorText: errorText,
            border: const OutlineInputBorder(
              borderRadius: BorderRadius.all(Radius.circular(12)),
            ),
            isDense: true,
          ),
        );
      case CrudFieldType.number:
        return TextField(
          controller: _controllers[f.key],
          keyboardType: const TextInputType.numberWithOptions(
            decimal: true,
            signed: true,
          ),
          inputFormatters: const [_DecimalInputFormatter()],
          onChanged: (_) => _clearError(f.key),
          decoration: InputDecoration(
            labelText: f.label,
            hintText: f.hint,
            errorText: errorText,
            border: const OutlineInputBorder(
              borderRadius: BorderRadius.all(Radius.circular(12)),
            ),
            isDense: true,
          ),
        );
      case CrudFieldType.bool:
        return SwitchListTile(
          contentPadding: EdgeInsets.zero,
          title: Text(f.label),
          value: _boolValues[f.key] ?? false,
          activeTrackColor: const Color(0xFF800000),
          onChanged: (v) {
            setState(() => _boolValues[f.key] = v);
            _clearError(f.key);
          },
        );
      case CrudFieldType.dropdown:
        return DropdownButtonFormField<String>(
          initialValue: _dropdownValues[f.key],
          decoration: InputDecoration(
            labelText: f.label,
            errorText: errorText,
            border: const OutlineInputBorder(
              borderRadius: BorderRadius.all(Radius.circular(12)),
            ),
            isDense: true,
          ),
          items: [
            for (final o in f.options ?? <String>[])
              DropdownMenuItem(value: o, child: Text(titleCaseOption(o))),
          ],
          onChanged: (v) {
            setState(() => _dropdownValues[f.key] = v);
            _clearError(f.key);
          },
        );
      case CrudFieldType.date:
      case CrudFieldType.time:
        return _PickerField(
          controller: _controllers[f.key]!,
          label: f.label,
          mode: f.type == CrudFieldType.date
              ? _PickerMode.date
              : _PickerMode.time,
          errorText: errorText,
          onChanged: () => _clearError(f.key),
        );
      case CrudFieldType.picker:
        return _SearchPickerField(
          controller: _controllers[f.key]!,
          label: f.label,
          entries: f.entries ?? const [],
          errorText: errorText,
          onChanged: () => _clearError(f.key),
        );
    }
  }
}

/// Display text for a taxonomy entry (label when present, else title-cased
/// value) — mirrors the SPA combobox.
String _entryLabel(TaxonomyEntry e) => e.label ?? titleCaseOption(e.value);

/// A read-only field that opens a searchable picker sheet on tap. The picked
/// value (lowercase token) is stored in the controller; the sheet also offers
/// create-on-the-fly so catalogue fields stay open, matching the web
/// `ComboboxField`.
class _SearchPickerField extends StatelessWidget {
  const _SearchPickerField({
    required this.controller,
    required this.label,
    required this.entries,
    this.errorText,
    this.onChanged,
  });

  final TextEditingController controller;
  final String label;
  final List<TaxonomyEntry> entries;
  final String? errorText;
  final VoidCallback? onChanged;

  Future<void> _open(BuildContext context) async {
    final picked = await showModalBottomSheet<String>(
      context: context,
      isScrollControlled: true,
      backgroundColor: Colors.white,
      shape: const RoundedRectangleBorder(
        borderRadius: BorderRadius.vertical(top: Radius.circular(20)),
      ),
      builder: (_) => _SearchPickerSheet(
        label: label,
        entries: entries,
        initial: controller.text,
      ),
    );
    if (picked != null && picked.isNotEmpty) {
      controller.text = picked;
      onChanged?.call();
    }
  }

  String _displayValue(String value) {
    for (final e in entries) {
      if (e.value == value) return _entryLabel(e);
    }
    return titleCaseOption(value);
  }

  @override
  Widget build(BuildContext context) {
    final value = controller.text;
    return InputDecorator(
      decoration: InputDecoration(
        labelText: label,
        hintText: value.isEmpty ? 'Select…' : null,
        errorText: errorText,
        suffixIcon: const Icon(HugeIcons.strokeRoundedSearch01, size: 18),
        border: const OutlineInputBorder(
          borderRadius: BorderRadius.all(Radius.circular(12)),
        ),
        isDense: true,
      ),
      child: InkWell(
        onTap: () => _open(context),
        child: Text(
          value.isEmpty ? '' : _displayValue(value),
          style: TextStyle(
            color: value.isEmpty
                ? Theme.of(context).hintColor
                : Theme.of(context).colorScheme.onSurface,
          ),
        ),
      ),
    );
  }
}

/// The searchable selection sheet behind `_SearchPickerField`.
class _SearchPickerSheet extends StatefulWidget {
  const _SearchPickerSheet({
    required this.label,
    required this.entries,
    required this.initial,
  });

  final String label;
  final List<TaxonomyEntry> entries;
  final String initial;

  @override
  State<_SearchPickerSheet> createState() => _SearchPickerSheetState();
}

class _SearchPickerSheetState extends State<_SearchPickerSheet> {
  late final TextEditingController _search;
  String _query = '';

  @override
  void initState() {
    super.initState();
    _search = TextEditingController(text: widget.initial);
    _query = widget.initial;
  }

  @override
  void dispose() {
    _search.dispose();
    super.dispose();
  }

  List<TaxonomyEntry> get _filtered {
    final q = _query.trim().toLowerCase();
    if (q.isEmpty) return widget.entries;
    return widget.entries
        .where((e) =>
            e.value.toLowerCase().contains(q) ||
            (e.label?.toLowerCase().contains(q) ?? false))
        .toList();
  }

  bool get _canCreate {
    final q = _query.trim();
    if (q.isEmpty) return false;
    final lower = q.toLowerCase();
    return !widget.entries.any((e) => e.value.toLowerCase() == lower);
  }

  void _select(String value) {
    Navigator.of(context).pop(value);
  }

  @override
  Widget build(BuildContext context) {
    return Padding(
      padding:
          EdgeInsets.only(bottom: MediaQuery.of(context).viewInsets.bottom),
      child: SafeArea(
        child: SingleChildScrollView(
          padding: const EdgeInsets.fromLTRB(20, 16, 20, 16),
          child: Column(
            mainAxisSize: MainAxisSize.min,
            crossAxisAlignment: CrossAxisAlignment.stretch,
            children: [
              SheetHeader(title: 'Select ${widget.label}'),
              const SizedBox(height: 12),
              TextField(
                controller: _search,
                autofocus: true,
                onChanged: (v) => setState(() => _query = v),
                decoration: const InputDecoration(
                  hintText: 'Search or create…',
                  prefixIcon: Icon(HugeIcons.strokeRoundedSearch01, size: 18),
                  border: OutlineInputBorder(
                    borderRadius: BorderRadius.all(Radius.circular(12)),
                  ),
                  isDense: true,
                ),
              ),
              const SizedBox(height: 8),
              ConstrainedBox(
                constraints: const BoxConstraints(maxHeight: 360),
                child: ListView(
                  shrinkWrap: true,
                  children: [
                    for (final e in _filtered)
                      ListTile(
                        dense: true,
                        contentPadding: EdgeInsets.zero,
                        title: Text(_entryLabel(e)),
                        onTap: () => _select(e.value),
                      ),
                    if (_canCreate)
                      ListTile(
                        dense: true,
                        contentPadding: EdgeInsets.zero,
                        leading:
                            const Icon(HugeIcons.strokeRoundedAdd01, size: 20),
                        title: Text("Create '${_query.trim()}'"),
                        onTap: () => _select(_query.trim().toLowerCase()),
                      ),
                    if (_filtered.isEmpty && !_canCreate)
                      const Padding(
                        padding: EdgeInsets.all(12),
                        child: Text('No matches.',
                            style: TextStyle(color: Colors.black54)),
                      ),
                  ],
                ),
              ),
            ],
          ),
        ),
      ),
    );
  }
}

enum _PickerMode { date, time }

/// A read-only field that opens a date or time picker on tap and stores the
/// formatted value (`YYYY-MM-DD` / `HH:MM`) in the controller — replaces
/// error-prone free-text date/time entry.
class _PickerField extends StatelessWidget {
  const _PickerField({
    required this.controller,
    required this.label,
    required this.mode,
    this.errorText,
    this.onChanged,
  });

  final TextEditingController controller;
  final String label;
  final _PickerMode mode;
  final String? errorText;
  final VoidCallback? onChanged;

  static String _two(int n) => n.toString().padLeft(2, '0');

  Future<void> _pick(BuildContext context) async {
    final now = DateTime.now();
    if (mode == _PickerMode.date) {
      final initial = DateTime.tryParse(controller.text) ?? now;
      final picked = await showDatePicker(
        context: context,
        initialDate: initial,
        firstDate: DateTime(now.year - 5),
        lastDate: DateTime(now.year + 10),
      );
      if (picked != null) {
        controller.text =
            '${picked.year}-${_two(picked.month)}-${_two(picked.day)}';
        onChanged?.call();
      }
    } else {
      final parts = controller.text.split(':');
      final initial = parts.length == 2
          ? TimeOfDay(
              hour: int.tryParse(parts[0]) ?? 9,
              minute: int.tryParse(parts[1]) ?? 0,
            )
          : const TimeOfDay(hour: 9, minute: 0);
      final picked =
          await showTimePicker(context: context, initialTime: initial);
      if (picked != null) {
        controller.text = '${_two(picked.hour)}:${_two(picked.minute)}';
        onChanged?.call();
      }
    }
  }

  @override
  Widget build(BuildContext context) {
    return TextField(
      controller: controller,
      readOnly: true,
      onTap: () => _pick(context),
      decoration: InputDecoration(
        labelText: label,
        hintText: mode == _PickerMode.date ? 'YYYY-MM-DD' : 'HH:MM',
        errorText: errorText,
        suffixIcon: Icon(
          mode == _PickerMode.date
              ? HugeIcons.strokeRoundedCalendar01
              : Icons.access_time,
          size: 18,
        ),
        border: const OutlineInputBorder(
          borderRadius: BorderRadius.all(Radius.circular(12)),
        ),
        isDense: true,
      ),
    );
  }
}

/// Short helper to surface a result message as a SnackBar.
void showCrudMessage(BuildContext context, String message,
    {bool error = false}) {
  final messenger = ScaffoldMessenger.of(context);
  messenger.hideCurrentSnackBar();
  messenger.showSnackBar(
    SnackBar(
      content: Text(message),
      backgroundColor:
          error ? const Color(0xFFB3261E) : const Color(0xFF1B7A43),
    ),
  );
}

/// A confirm dialog (destructive or plain).
Future<bool> showCrudConfirm(
  BuildContext context, {
  required String title,
  required String message,
  String confirmLabel = 'Confirm',
  bool destructive = false,
}) async {
  final ok = await showDialog<bool>(
    context: context,
    builder: (context) => AlertDialog(
      backgroundColor: Colors.white,
      title: Text(title),
      content: Text(message),
      actions: [
        TextButton(
          onPressed: () => Navigator.of(context).pop(false),
          child: const Text('Cancel'),
        ),
        FilledButton(
          style: FilledButton.styleFrom(
            backgroundColor:
                destructive ? const Color(0xFFB3261E) : const Color(0xFF800000),
          ),
          onPressed: () {
            // Tactile warning on destructive actions (cancel batch, close
            // referral, sign out, etc.).
            if (destructive) HapticFeedback.mediumImpact();
            Navigator.of(context).pop(true);
          },
          child: Text(confirmLabel),
        ),
      ],
    ),
  );
  return ok ?? false;
}

/// Runs [action], surfacing success/errors as a SnackBar. Returns true on
/// success so callers can refresh their list.
Future<bool> runCrudAction(
  BuildContext context,
  Future<void> Function() action, {
  String successMessage = 'Done',
}) async {
  try {
    await action();
    if (!context.mounted) return false;
    showCrudMessage(context, successMessage);
    return true;
  } catch (e) {
    final msg = mapDioError(e).message;
    if (!context.mounted) return false;
    showCrudMessage(context, msg, error: true);
    return false;
  }
}
