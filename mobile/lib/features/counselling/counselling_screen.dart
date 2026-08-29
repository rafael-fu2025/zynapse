import 'package:flutter/foundation.dart';
import 'package:flutter/material.dart';
import 'package:flutter/rendering.dart';
import 'package:hugeicons/hugeicons.dart';
import 'package:provider/provider.dart';

import '../../core/api/api_client.dart';
import '../../core/models/counselling.dart';
import '../../core/models/queue.dart';
import '../../core/services/api_service.dart';
import '../../core/services/auth_controller.dart';
import '../../core/utils/dates.dart';
import '../common/auto_polling.dart';
import '../common/crud_form.dart';
import '../common/widgets.dart';
import 'counselling_session_workspace.dart';

/// Counselling sessions — `GET /counselling/sessions` (paged).
class CounsellingScreen extends StatefulWidget {
  const CounsellingScreen({super.key});

  @override
  State<CounsellingScreen> createState() => _CounsellingScreenState();
}

class _CounsellingScreenState extends State<CounsellingScreen>
    with AutoPolling<CounsellingScreen>, SingleTickerProviderStateMixin {
  bool _loading = true;
  String? _error;
  List<CounsellingSession> _items = [];
  String? _nextCursor;
  bool _loadingMore = false;
  bool _loadedOnce = false;
  late final TabController _tabController;

  @override
  void initState() {
    super.initState();
    _tabController = TabController(length: 4, vsync: this)
      ..addListener(() => setState(() {}));
    _load();
    // Live updates — mirrors the SPA's 30s `refetchInterval`.
    startPolling(const Duration(seconds: 30), _silentRefresh);
  }

  /// Polled live update: silently refetch the first page so new/updated
  /// sessions appear without a manual refresh. Skipped once the user has
  /// paginated deeper (pull-to-refresh there) so the list never collapses.
  Future<void> _silentRefresh() async {
    if (_items.length > 25) return;
    try {
      final page = await ApiService.I.counsellingSessions();
      if (!mounted) return;
      setState(() {
        _items = page.items;
        _nextCursor = page.meta?.nextCursor;
      });
    } catch (e) {
      // Keep the current list on transient errors.
      if (kDebugMode) debugPrint('CounsellingScreen.poll failed: $e');
    }
  }

  @override
  void dispose() {
    _tabController.dispose();
    super.dispose();
  }

  Future<void> _load() async {
    setState(() {
      _loading = !_loadedOnce;
      _error = null;
    });
    try {
      final page = await ApiService.I.counsellingSessions();
      setState(() {
        _items = page.items;
        _nextCursor = page.meta?.nextCursor;
        _loadedOnce = true;
        _loading = false;
      });
    } catch (e) {
      setState(() {
        _error = mapDioError(e).message;
        _loading = false;
      });
    }
  }

  Future<void> _loadMore() async {
    if (_loadingMore || _nextCursor == null) return;
    setState(() => _loadingMore = true);
    try {
      final page = await ApiService.I.counsellingSessions(cursor: _nextCursor);
      setState(() {
        _items = [..._items, ...page.items];
        _nextCursor = page.meta?.nextCursor;
      });
    } catch (e) {
      // ignore
      if (kDebugMode) debugPrint('CounsellingScreen.loadMore failed: $e');
    } finally {
      setState(() => _loadingMore = false);
    }
  }

  bool get _canWrite =>
      context
          .read<AuthController>()
          .session
          ?.hasPermission('counselling.records.write') ??
      false;

  Future<void> _open() async {
    final payload = await showCrudForm(
      context,
      title: 'Open session',
      fields: const [
        CrudField.text('patient_school_id', 'Patient school/employee ID'),
      ],
      submitLabel: 'Open',
    );
    if (payload == null || !mounted) return;
    final ok = await runCrudAction(
      context,
      () => ApiService.I.openCounsellingSession(payload),
      successMessage: 'Session opened.',
    );
    if (ok) _load();
  }

  Future<void> _writeNotes(CounsellingSession s) async {
    await _openWorkspace(s, initialStep: 'notes');
  }

  Future<void> _close(CounsellingSession s) async {
    await _openWorkspace(s, initialStep: 'complete');
  }

  Future<void> _openWorkspace(
    CounsellingSession session, {
    String initialStep = 'notes',
  }) async {
    await Navigator.of(context).push<void>(
      MaterialPageRoute(
        builder: (_) => CounsellingSessionWorkspace(
          sessionId: session.id,
          initialStep: initialStep,
        ),
      ),
    );
    if (mounted) await _load();
  }

  @override
  Widget build(BuildContext context) {
    return Scaffold(
      appBar: AppBar(
        title: const Text('Counselling'),
        bottom: TabBar(
          controller: _tabController,
          tabs: const [
            Tab(text: 'Sessions'),
            Tab(text: 'Queue'),
            Tab(text: 'Scheduling'),
            Tab(text: 'Analytics'),
          ],
        ),
      ),
      body: TabBarView(
        controller: _tabController,
        children: [
          RefreshIndicator(onRefresh: _load, child: _buildSessions()),
          const _GuidanceQueueView(),
          const _SchedulingView(),
          const _AnalyticsView(),
        ],
      ),
      floatingActionButton: _tabController.index == 0 && _canWrite
          ? FloatingActionButton.extended(
              backgroundColor: const Color(0xFF800000),
              foregroundColor: Colors.white,
              onPressed: _open,
              icon: const Icon(HugeIcons.strokeRoundedMessage01),
              label: const Text('Open session'),
            )
          : null,
    );
  }

  Widget _buildSessions() {
    if (_loading) return AsyncState.loading();
    if (_error != null) return AsyncState.error(_error!, onRetry: _load);
    if (_items.isEmpty) return AsyncState.empty('No counselling sessions.');

    return ListView.separated(
      padding: const EdgeInsets.all(16),
      itemCount: _items.length + (_nextCursor != null ? 1 : 0),
      separatorBuilder: (_, __) => const SizedBox(height: 8),
      // Pre-build rows ahead of the viewport so fast flings don't hitch on
      // widget builds — keeps long lists smooth at the 120Hz vsync.
      scrollCacheExtent: const ScrollCacheExtent.pixels(600),
      itemBuilder: (context, i) {
        if (i >= _items.length) {
          // Load-more sentinel — defer so we never setState during build.
          WidgetsBinding.instance.addPostFrameCallback((_) {
            if (mounted) _loadMore();
          });
          return const Padding(
            padding: EdgeInsets.all(8),
            child: Center(
              child: SizedBox(
                width: 22,
                height: 22,
                child: CircularProgressIndicator(strokeWidth: 2),
              ),
            ),
          );
        }
        final s = _items[i];
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
            onTap: () => _openWorkspace(s),
            leading: CircleAvatar(
              backgroundColor: s.isOpen
                  ? const Color(0xFF1B7A43).withValues(alpha: 0.12)
                  : Colors.grey.withValues(alpha: 0.12),
              child: Icon(
                s.isOpen
                    ? HugeIcons.strokeRoundedCircle
                    : HugeIcons.strokeRoundedCheckmarkCircle01,
                color: s.isOpen ? const Color(0xFF1B7A43) : Colors.grey,
                size: 20,
              ),
            ),
            title: Text('Session #${s.id} · Patient ${s.patientSchoolId}'),
            subtitle: Text(
              '${s.isOpen ? 'Opened' : 'Ended'} '
              '${fmtUtcToApp(s.isOpen ? s.startedAt : s.endedAt)}'
              ' · Counsellor #${s.counsellorUserId}',
            ),
            trailing: Row(
              mainAxisSize: MainAxisSize.min,
              children: [
                s.isOpen
                    ? const StatusBadge(label: 'Open', color: Color(0xFF1B7A43))
                    : const StatusBadge(label: 'Closed', color: Colors.grey),
                if (_canWrite && s.isOpen) ...[
                  const SizedBox(width: 4),
                  PopupMenuButton<String>(
                    icon: const Icon(HugeIcons.strokeRoundedMore),
                    onSelected: (v) {
                      if (v == 'notes') _writeNotes(s);
                      if (v == 'close') _close(s);
                    },
                    itemBuilder: (_) => const [
                      PopupMenuItem(value: 'notes', child: Text('Write notes')),
                      PopupMenuItem(
                          value: 'close', child: Text('Close session')),
                    ],
                  ),
                ],
              ],
            ),
          ),
        );
      },
    );
  }
}

const _dayNames = [
  'Sunday',
  'Monday',
  'Tuesday',
  'Wednesday',
  'Thursday',
  'Friday',
  'Saturday',
];

String _dayName(int dow) =>
    (dow >= 0 && dow <= 6) ? _dayNames[dow] : 'Day $dow';

Color _apptStatusColor(String status) => switch (status) {
      'scheduled' => const Color(0xFF1E6FD9),
      'confirmed' => const Color(0xFF5B4BA6),
      'completed' => const Color(0xFF1B7A43),
      'cancelled' => const Color(0xFFB3261E),
      'no_show' => const Color(0xFF8A5A00),
      _ => Colors.grey,
    };

class _GuidanceQueueView extends StatefulWidget {
  const _GuidanceQueueView();
  @override State<_GuidanceQueueView> createState()=>_GuidanceQueueViewState();
}
class _GuidanceQueueViewState extends State<_GuidanceQueueView> {
  List<GuidanceQueueEntry> _items=const[]; bool _loading=true; String? _error; bool _acting=false;
  @override void initState(){super.initState();_load();}
  Future<void> _load()async{try{final rows=await ApiService.I.guidanceQueue();if(mounted)setState((){_items=rows;_loading=false;_error=null;});}catch(e){if(mounted)setState((){_loading=false;_error=mapDioError(e).message;});}}
  Future<void> _run(Future<void> Function() action)async{if(_acting)return;setState(()=>_acting=true);try{await action();await _load();}catch(e){if(mounted)ScaffoldMessenger.of(context).showSnackBar(SnackBar(content:Text(mapDioError(e).message)));}finally{if(mounted)setState(()=>_acting=false);}}
  @override Widget build(BuildContext context){if(_loading)return AsyncState.loading();if(_error!=null)return AsyncState.error(_error!,onRetry:_load);final me=context.read<AuthController>().session?.id;final eligible=_items.where((e)=>e.status=='waiting'&&(e.assignedCounsellorUserId==null||e.assignedCounsellorUserId==me)).toList();return RefreshIndicator(onRefresh:_load,child:ListView(padding:const EdgeInsets.all(16),children:[FilledButton.icon(onPressed:_acting||eligible.isEmpty?null:()=>_run(ApiService.I.callNextGuidance),icon:const Icon(Icons.campaign_outlined),label:const Text('Call next in my lane')),const SizedBox(height:12),if(_items.isEmpty)const Text('No Guidance queue entries.') else ..._items.map((e)=>Card(child:ListTile(title:Text('${e.queueNumber} · ${e.displayName}'),subtitle:Text('${e.purpose}\n${e.status.replaceAll('_',' ')}${e.assignedCounsellorUserId==me?' · My lane':e.assignedCounsellorUserId==null?' · Unassigned':' · Assigned'}'),isThreeLine:true,trailing:Wrap(spacing:4,children:[if(e.status=='called'&&e.assignedCounsellorUserId==me)IconButton(tooltip:'Start',onPressed:_acting?null:()=>_run(()=>ApiService.I.transitionGuidanceQueue(e.id,'start')),icon:const Icon(Icons.play_arrow)),if(e.status=='in_session'&&e.assignedCounsellorUserId==me)...[IconButton(tooltip:'Repair session',onPressed:_acting?null:()=>_run(()=>ApiService.I.repairGuidanceQueue(e.id)),icon:const Icon(Icons.build_outlined)),IconButton(tooltip:'Complete',onPressed:_acting?null:()=>_run(()=>ApiService.I.transitionGuidanceQueue(e.id,'complete')),icon:const Icon(Icons.check_circle_outline))],if(e.status=='called'&&e.assignedCounsellorUserId==me)IconButton(tooltip:'Skip',onPressed:_acting?null:()=>_run(()=>ApiService.I.transitionGuidanceQueue(e.id,'skip')),icon:const Icon(Icons.skip_next))]))))]));}
}

/// Counselling scheduling — availability windows + appointments (mirrors
/// the web "Scheduling" tab: Availability / Appointments sub-views).
class _SchedulingView extends StatefulWidget {
  const _SchedulingView();

  @override
  State<_SchedulingView> createState() => _SchedulingViewState();
}

class _SchedulingViewState extends State<_SchedulingView> {
  int _section = 0; // 0 = availability, 1 = appointments
  bool _loading = true;
  String? _error;
  List<Map<String, dynamic>> _availability = [];
  List<Map<String, dynamic>> _appointments = [];
  List<Map<String, dynamic>> _counsellors = [];

  bool get _teamManage => context.read<AuthController>().session?.hasPermission('counselling.schedule.team_manage') ?? false;

  bool get _canManageSchedule =>
      (context.read<AuthController>().session?.hasPermission('counselling.schedule.manage') ?? false) ||
      (context.read<AuthController>().session?.hasPermission('counselling.schedule.team_manage') ?? false);

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
      final availability = await ApiService.I.counsellingAvailability();
      final page = await ApiService.I.counsellingAppointments();
      final counsellors = await ApiService.I.counsellingProviders();
      if (!mounted) return;
      setState(() {
        _availability = availability;
        _appointments = page.items;
        _counsellors = counsellors;
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

  Future<void> _addWindow() async {
    final payload = await showCrudForm(
      context,
      title: 'Add availability window',
      fields: [
        if (_teamManage) CrudField.dropdown('counsellor', 'Counsellor', _counsellors.map((c)=>c['name'] as String).toList()),
        const CrudField.dropdown(
          'day_of_week',
          'Day',
          _dayNames,
          initial: 'Monday',
        ),
        const CrudField.time('start_time', 'Start time', initial: '08:00'),
        const CrudField.time('end_time', 'End time', initial: '17:00'),
        const CrudField.number('max_slots', 'Capacity',
            initial: '1', required: false),
      ],
      submitLabel: 'Add',
    );
    if (payload == null || !mounted) return;
    final body = <String, dynamic>{...payload};
    body['day_of_week'] =
        _dayNames.indexOf(payload['day_of_week'] as String? ?? 'Monday');
    if (_teamManage) body['counsellor_user_id'] = _counsellors.firstWhere((c)=>c['name']==payload['counsellor'])['id'];
    body.remove('counsellor');
    final ok = await runCrudAction(
      context,
      () => ApiService.I.addCounsellingAvailability(body),
      successMessage: 'Availability window added.',
    );
    if (ok) _load();
  }

  Future<void> _removeWindow(Map<String, dynamic> w) async {
    final confirmed = await showCrudConfirm(
      context,
      title: 'Remove window?',
      message: 'Remove the ${_dayName(w['day_of_week'] as int? ?? 0)} '
          '${w['start_time']}–${w['end_time']} window?',
      confirmLabel: 'Remove',
      destructive: true,
    );
    if (!confirmed || !mounted) return;
    final ok = await runCrudAction(
      context,
      () => ApiService.I.removeCounsellingAvailability(w['id'] as int),
      successMessage: 'Window removed.',
    );
    if (ok) _load();
  }

  Future<void> _book() async {
    final payload = await showCrudForm(
      context,
      title: 'Book counselling appointment',
      fullScreen: true,
      fields: [
        if (_teamManage) CrudField.dropdown('counsellor', 'Counsellor', _counsellors.map((c)=>c['name'] as String).toList()),
        const CrudField.text('patient_school_id', 'Patient school/employee ID'),
        const CrudField.date('appointment_date', 'Date'),
        const CrudField.time('start_time', 'Start time', initial: '09:00'),
        const CrudField.time('end_time', 'End time', initial: '10:00'),
        const CrudField.dropdown('type', 'Type',
            ['initial', 'follow_up', 'crisis', 'referral_based'],
            initial: 'initial'),
        const CrudField.text('reason', 'Reason', required: false, maxLength: 255),
      ],
      submitLabel: 'Book',
    );
    if (payload == null || !mounted) return;
    if (_teamManage) payload['counsellor_user_id'] = _counsellors.firstWhere((c)=>c['name']==payload['counsellor'])['id'];
    payload.remove('counsellor');
    final ok = await runCrudAction(
      context,
      () => ApiService.I.bookCounsellingAppointment(payload),
      successMessage: 'Appointment booked.',
    );
    if (ok) _load();
  }

  Future<void> _transition(Map<String, dynamic> a, String action) async {
    if (action == 'cancel') {
      final payload = await showCrudForm(
        context,
        title: 'Cancel appointment #${a['id']}',
        fields: const [
          CrudField.text('cancellation_reason', 'Reason (optional)',
              required: false, maxLength: 255),
        ],
        submitLabel: 'Cancel',
      );
      if (payload == null || !mounted) return;
      final ok = await runCrudAction(
        context,
        () => ApiService.I.transitionCounsellingAppointment(
            a['id'] as int, 'cancel',
            cancellationReason: payload['cancellation_reason'] as String?),
        successMessage: 'Appointment cancelled.',
      );
      if (ok) _load();
      return;
    }
    final confirmed = await showCrudConfirm(
      context,
      title: '${action[0].toUpperCase()}${action.substring(1)} appointment?',
      message: 'Mark appointment #${a['id']} as “$action”?',
      confirmLabel: action[0].toUpperCase() + action.substring(1),
      destructive: action == 'no_show',
    );
    if (!confirmed || !mounted) return;
    final ok = await runCrudAction(
      context,
      () =>
          ApiService.I.transitionCounsellingAppointment(a['id'] as int, action),
      successMessage: 'Appointment ${action}d.',
    );
    if (ok) _load();
  }

  @override
  Widget build(BuildContext context) {
    return ListView(
      padding: const EdgeInsets.all(16),
      children: [
        SegmentedButton<int>(
          segments: const [
            ButtonSegment(value: 0, label: Text('Availability')),
            ButtonSegment(value: 1, label: Text('Appointments')),
          ],
          selected: {_section},
          onSelectionChanged: (s) => setState(() => _section = s.first),
        ),
        const SizedBox(height: 12),
        if (_loading)
          const Padding(
            padding: EdgeInsets.all(24),
            child: Center(child: CircularProgressIndicator()),
          )
        else if (_error != null)
          AsyncState.error(_error!, onRetry: _load)
        else if (_section == 0)
          _buildAvailability()
        else
          _buildAppointments(),
      ],
    );
  }

  Widget _buildAvailability() {
    return Column(
      crossAxisAlignment: CrossAxisAlignment.stretch,
      children: [
        if (_canManageSchedule)
          OutlinedButton.icon(
            onPressed: _addWindow,
            icon: const Icon(HugeIcons.strokeRoundedAddCircle),
            label: const Text('Add window'),
          ),
        const SizedBox(height: 8),
        if (_availability.isEmpty)
          const Text('No availability windows.',
              style: TextStyle(color: Colors.black54))
        else
          for (final w in _availability)
            ListTile(
              dense: true,
              contentPadding: EdgeInsets.zero,
              title: Text(
                '${_dayName(w['day_of_week'] as int? ?? 0)} · '
                '${w['start_time']}–${w['end_time']}',
                style: const TextStyle(fontWeight: FontWeight.w600),
              ),
              subtitle: Text('Capacity ${w['max_slots'] ?? 1}'),
              trailing: _canManageSchedule
                  ? IconButton(
                      icon:
                          const Icon(HugeIcons.strokeRoundedDelete01, size: 18),
                      onPressed: () => _removeWindow(w),
                    )
                  : null,
            ),
      ],
    );
  }

  Widget _buildAppointments() {
    return Column(
      crossAxisAlignment: CrossAxisAlignment.stretch,
      children: [
        if (_canManageSchedule)
          OutlinedButton.icon(
            onPressed: _book,
            icon: const Icon(HugeIcons.strokeRoundedCalendarAdd01),
            label: const Text('Book appointment'),
          ),
        const SizedBox(height: 8),
        if (_appointments.isEmpty)
          const Text('No counselling appointments.',
              style: TextStyle(color: Colors.black54))
        else
          for (final a in _appointments)
            Card(
              elevation: 0,
              margin: const EdgeInsets.symmetric(vertical: 4),
              shape: RoundedRectangleBorder(
                borderRadius: BorderRadius.circular(10),
                side: BorderSide(
                  color: Theme.of(context)
                      .colorScheme
                      .outlineVariant
                      .withValues(alpha: 0.5),
                ),
              ),
              child: ListTile(
                dense: true,
                title: Text(
                  '#${a['id']} · ${a['patient_school_id']} · ${a['appointment_date']} ${a['start_time']}–${a['end_time']}',
                  style: const TextStyle(fontWeight: FontWeight.w600),
                ),
                subtitle: Text(
                    '${titleCaseOption('${a['type'] ?? ''}')} · ${titleCaseOption('${a['status'] ?? ''}')}'),
                trailing: StatusBadge(
                  label: titleCaseOption(a['status'] as String? ?? '—'),
                  color: _apptStatusColor(a['status'] as String? ?? ''),
                ),
                onTap: (!_canManageSchedule || _activeActions(a) == null)
                    ? null
                    : () => _openApptMenu(a),
              ),
            ),
      ],
    );
  }

  List<String>? _activeActions(Map<String, dynamic> a) {
    final status = a['status'] as String? ?? '';
    return switch (status) {
      'scheduled' => ['confirm', 'complete', 'cancel', 'no_show'],
      'confirmed' => ['complete', 'cancel', 'no_show'],
      _ => null,
    };
  }

  Future<void> _openApptMenu(Map<String, dynamic> a) async {
    final actions = _activeActions(a);
    if (actions == null) return;
    final choice = await showModalBottomSheet<String>(
      context: context,
      backgroundColor: Colors.white,
      shape: const RoundedRectangleBorder(
        borderRadius: BorderRadius.vertical(top: Radius.circular(20)),
      ),
      builder: (ctx) => SafeArea(
        child: Column(
          mainAxisSize: MainAxisSize.min,
          children: [
            const SizedBox(height: 10),
            Container(
              width: 40,
              height: 5,
              decoration: const BoxDecoration(
                color: Color(0xFF800000),
                borderRadius: BorderRadius.all(Radius.circular(999)),
              ),
            ),
            const SizedBox(height: 6),
            for (final action in actions)
              ListTile(
                leading: Icon(
                  action == 'confirm'
                      ? HugeIcons.strokeRoundedCheckmarkCircle01
                      : action == 'complete'
                          ? HugeIcons.strokeRoundedFlag01
                          : action == 'cancel'
                              ? HugeIcons.strokeRoundedCancelCircle
                              : HugeIcons.strokeRoundedUserRemove01,
                  size: 20,
                ),
                title: Text(action[0].toUpperCase() + action.substring(1)),
                onTap: () => Navigator.pop(ctx, action),
              ),
          ],
        ),
      ),
    );
    if (choice == null || !mounted) return;
    await _transition(a, choice);
  }
}

/// Counselling analytics — no-show optimizer table (mirrors the web
/// "Analytics" tab).
class _AnalyticsView extends StatefulWidget {
  const _AnalyticsView();

  @override
  State<_AnalyticsView> createState() => _AnalyticsViewState();
}

class _AnalyticsViewState extends State<_AnalyticsView> {
  bool _loading = true;
  String? _error;
  List<Map<String, dynamic>> _rows = [];

  bool get _canManageSchedule =>
      (context.read<AuthController>().session?.hasPermission('counselling.schedule.manage') ?? false) ||
      (context.read<AuthController>().session?.hasPermission('counselling.schedule.team_manage') ?? false);

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
      final rows = await ApiService.I.counsellingAnalytics();
      if (!mounted) return;
      setState(() {
        _rows = rows;
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

  Future<void> _recompute() async {
    final ok = await runCrudAction(
      context,
      () => ApiService.I.recomputeCounsellingAnalytics(),
      successMessage: 'No-show analytics recomputed.',
    );
    if (ok) _load();
  }

  @override
  Widget build(BuildContext context) {
    return ListView(
      padding: const EdgeInsets.all(16),
      children: [
        if (_canManageSchedule)
          OutlinedButton.icon(
            onPressed: _recompute,
            icon: const Icon(HugeIcons.strokeRoundedRefresh),
            label: const Text('Recompute'),
          ),
        const SizedBox(height: 8),
        if (_loading)
          const Padding(
            padding: EdgeInsets.all(24),
            child: Center(child: CircularProgressIndicator()),
          )
        else if (_error != null)
          AsyncState.error(_error!, onRetry: _load)
        else if (_rows.isEmpty)
          const Text('No analytics yet.',
              style: TextStyle(color: Colors.black54))
        else
          for (final r in _rows)
            Card(
              elevation: 0,
              margin: const EdgeInsets.symmetric(vertical: 4),
              shape: RoundedRectangleBorder(
                borderRadius: BorderRadius.circular(10),
                side: BorderSide(
                  color: Theme.of(context)
                      .colorScheme
                      .outlineVariant
                      .withValues(alpha: 0.5),
                ),
              ),
              child: ListTile(
                dense: true,
                title: Text(
                  '#${r['counsellor_user_id']} · '
                  '${_dayName(r['day_of_week'] as int? ?? 0)} · '
                  '${r['time_slot']}',
                  style: const TextStyle(fontWeight: FontWeight.w600),
                ),
                subtitle: Text(
                  'Appts ${r['total_appointments'] ?? 0} · '
                  'No-shows ${r['total_no_shows'] ?? 0} · '
                  'Rate ${r['no_show_rate'] ?? 0}% · '
                  'Rec. overbooking ${r['recommended_overbooking'] ?? '—'}',
                  style: const TextStyle(fontSize: 12),
                ),
              ),
            ),
      ],
    );
  }
}
