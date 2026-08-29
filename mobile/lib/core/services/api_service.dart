import 'package:dio/dio.dart';

import '../api/api_client.dart';
import '../api/api_envelope.dart';
import '../models/admin_user.dart';
import '../models/appointment.dart';
import '../models/audit_event.dart';
import '../models/clinic.dart';
import '../models/counselling.dart';
import '../models/dashboard.dart';
import '../models/facilities.dart';
import '../models/inventory.dart';
import '../models/kiosk.dart';
import '../models/medicine.dart';
import '../models/notification.dart';
import '../models/profile.dart';
import '../models/queue.dart';
import '../models/appointment_portal.dart';
import '../models/referral.dart';
import '../models/report.dart';

/// A paginated page of items plus keyset cursor metadata.
class ApiPage<T> {
  ApiPage({required this.items, this.meta});

  final List<T> items;
  final PaginationMeta? meta;

  bool get hasMore => meta?.hasNext ?? false;
  String? get nextCursor => meta?.nextCursor;
}

/// Module endpoint methods — one method per API call the app uses.
///
/// Mirrors the React hooks in `frontend/src/hooks/*`: the endpoint paths,
/// query params and response shapes are copied 1:1 from those hooks and
/// the backend route tables (`backend/app/Modules/*/Routes.php`).
class ApiService {
  ApiService._();

  static final ApiService I = ApiService._();

  Dio get _dio => ApiClient.I.dio;

  // ---------------------------------------------------------------------
  // Helpers
  // ---------------------------------------------------------------------

  /// Unwraps the envelope `data` field. Non-`success` envelopes and
  /// non-2xx responses are raised as [ApiException] by the interceptor
  /// (this is a belt-and-braces guard).
  Map<String, dynamic> _unwrapObject(Response<dynamic> res) {
    final body = res.data;
    if (body is! Map<String, dynamic>) {
      throw ApiException(res.statusCode ?? 0, [
        ApiError(
            code: 'invalid.envelope', message: 'Unexpected response shape'),
      ]);
    }
    if (body['success'] != true) {
      final raw = body['errors'];
      if (raw is List && raw.isNotEmpty) {
        throw ApiException(
          res.statusCode ?? 0,
          raw.whereType<Map<String, dynamic>>().map(ApiError.fromJson).toList(),
        );
      }
      throw ApiException(res.statusCode ?? 0, [
        ApiError(code: 'api.failed', message: 'Request failed'),
      ]);
    }
    final data = body['data'];
    if (data is! Map<String, dynamic>) {
      throw ApiException(res.statusCode ?? 0, [
        ApiError(code: 'invalid.data', message: 'Expected an object payload'),
      ]);
    }
    return data;
  }

  List<dynamic> _unwrapList(Response<dynamic> res) {
    final body = res.data;
    if (body is! Map<String, dynamic>) {
      throw ApiException(res.statusCode ?? 0, [
        ApiError(
            code: 'invalid.envelope', message: 'Unexpected response shape'),
      ]);
    }
    final data = body['data'];
    if (data is! List) {
      throw ApiException(res.statusCode ?? 0, [
        ApiError(code: 'invalid.data', message: 'Expected a list payload'),
      ]);
    }
    return data;
  }

  Map<String, dynamic>? _unwrapNullableObject(Response<dynamic> res) {
    final body = res.data;
    if (body is! Map<String, dynamic> || body['success'] != true) {
      throw ApiException(res.statusCode ?? 0, [
        ApiError(
            code: 'invalid.envelope', message: 'Unexpected response shape'),
      ]);
    }
    final data = body['data'];
    if (data == null) return null;
    if (data is! Map<String, dynamic>) {
      throw ApiException(res.statusCode ?? 0, [
        ApiError(code: 'invalid.data', message: 'Expected an object payload'),
      ]);
    }
    return data;
  }

  // ---------------------------------------------------------------------
  // Dashboard
  // ---------------------------------------------------------------------

  /// `GET /dashboard/counters`
  Future<DashboardCounters> dashboardCounters() async {
    final res = await _dio.get<Map<String, dynamic>>('/dashboard/counters');
    final data = res.data?['data'];
    // Callers with no module counters (e.g. report_viewer) get an empty
    // ARRAY from the backend — treat as an empty dashboard rather than
    // failing the envelope parse.
    if (data is Map<String, dynamic>) {
      return DashboardCounters.fromJson(data);
    }
    return DashboardCounters();
  }

  // ---------------------------------------------------------------------
  // Shared kiosk configuration and admin media library
  // ---------------------------------------------------------------------

  Future<KioskSettingsSnapshot> kioskSettings() async {
    final res = await _dio.get<Map<String, dynamic>>('/kiosk-settings');
    return KioskSettingsSnapshot.fromJson(_unwrapObject(res));
  }

  Future<KioskSettingsSnapshot> updateKioskSettings(
    Map<String, dynamic> settings,
    int revision,
  ) async {
    final res = await _dio.post<Map<String, dynamic>>(
      '/admin/kiosk-settings',
      data: {'settings': settings, 'revision': revision},
    );
    return KioskSettingsSnapshot.fromJson(_unwrapObject(res));
  }

  Future<List<KioskMediaAsset>> kioskMedia({
    bool includeArchived = true,
    String? search,
  }) async {
    final res = await _dio.get<Map<String, dynamic>>(
      '/admin/kiosk-media',
      queryParameters: {
        'limit': 100,
        if (includeArchived) 'include_archived': 'true',
        if (search?.trim().isNotEmpty == true) 'search': search!.trim(),
      },
    );
    final data = _unwrapObject(res);
    return (data['items'] as List? ?? [])
        .whereType<Map<String, dynamic>>()
        .map(KioskMediaAsset.fromJson)
        .toList();
  }

  Future<List<StockTransaction>> inventoryTransactions(int itemId) async {
    final res = await _dio.get<Map<String, dynamic>>(
      '/clinic/inventory/$itemId/movements',
    );
    return _unwrapList(res)
        .whereType<Map<String, dynamic>>()
        .map(StockTransaction.fromJson)
        .toList();
  }

  Future<List<StockTransaction>> medicineTransactions(int medicineId) async {
    final res = await _dio.get<Map<String, dynamic>>(
      '/clinic/medicines/$medicineId/transactions',
    );
    return _unwrapList(res)
        .whereType<Map<String, dynamic>>()
        .map(StockTransaction.fromJson)
        .toList();
  }

  Future<KioskMediaAsset> uploadKioskMedia({
    required String filename,
    String? path,
    List<int>? bytes,
    String? label,
    ProgressCallback? onProgress,
  }) async {
    final file = path != null
        ? await MultipartFile.fromFile(path, filename: filename)
        : MultipartFile.fromBytes(bytes ?? const [], filename: filename);
    final res = await _dio.post<Map<String, dynamic>>(
      '/admin/kiosk-media',
      data: FormData.fromMap({
        'file': file,
        if (label?.trim().isNotEmpty == true) 'label': label!.trim(),
      }),
      options: Options(contentType: 'multipart/form-data'),
      onSendProgress: onProgress,
    );
    return KioskMediaAsset.fromJson(_unwrapObject(res));
  }

  Future<KioskMediaAsset> setKioskMediaArchived(int id, bool archived) async {
    final res = await _dio.post<Map<String, dynamic>>(
      '/admin/kiosk-media/$id/${archived ? 'archive' : 'unarchive'}',
    );
    return KioskMediaAsset.fromJson(_unwrapObject(res));
  }

  // ---------------------------------------------------------------------
  // Auth — self-service password rotation
  // ---------------------------------------------------------------------

  /// `POST /auth/change-password` — body `{ current_password, new_password }`.
  Future<void> changePassword({
    required String currentPassword,
    required String newPassword,
  }) async {
    await _dio.post<Map<String, dynamic>>(
      '/auth/change-password',
      data: {
        'current_password': currentPassword,
        'new_password': newPassword,
      },
    );
  }

  // ---------------------------------------------------------------------
  // Appointments
  // ---------------------------------------------------------------------

  /// `GET /clinic/appointments?status=&q=&limit=&cursor=`
  Future<ApiPage<Appointment>> appointments({
    String? status,
    String? q,
    int limit = 25,
    String? cursor,
  }) async {
    final query = <String, dynamic>{
      'limit': limit,
      if (status != null && status.isNotEmpty) 'status': status,
      if (q != null && q.isNotEmpty) 'q': q,
      if (cursor != null && cursor.isNotEmpty) 'cursor': cursor,
    };
    final res = await _dio.get<Map<String, dynamic>>('/clinic/appointments',
        queryParameters: query);
    final body = res.data;
    final items = (body?['data'] as List? ?? [])
        .whereType<Map<String, dynamic>>()
        .map(Appointment.fromJson)
        .toList();
    final meta =
        PaginationMeta.fromJson(body?['meta'] as Map<String, dynamic>?);
    return ApiPage(items: items, meta: meta);
  }

  /// `GET /me/student-appointments` — the caller's own appointments.
  Future<List<Appointment>> myStudentAppointments() async {
    final res =
        await _dio.get<Map<String, dynamic>>('/me/student-appointments');
    return _unwrapList(res)
        .whereType<Map<String, dynamic>>()
        .map(Appointment.fromJson)
        .toList();
  }

  /// `GET /me/student-providers` — minimal provider list for self-booking.
  Future<List<ProviderRef>> studentProviders() async {
    final res = await _dio.get<Map<String, dynamic>>('/me/student-providers');
    return _unwrapList(res)
        .whereType<Map<String, dynamic>>()
        .map(ProviderRef.fromJson)
        .toList();
  }

  /// `POST /me/student-appointments` — self-service booking.
  ///
  /// [scheduledAt] must be a UTC wall-clock string (`YYYY-MM-DD HH:mm:ss`).
  Future<Appointment> bookStudentAppointment({
    required int providerUserId,
    required String scheduledAt,
    String? reason,
  }) async {
    final res = await _dio.post<Map<String, dynamic>>(
      '/me/student-appointments',
      data: {
        'provider_user_id': providerUserId,
        'scheduled_at': scheduledAt,
        if (reason != null && reason.isNotEmpty) 'reason': reason,
      },
    );
    return Appointment.fromJson(_unwrapObject(res));
  }

  // ---------------------------------------------------------------------
  // Queue
  // ---------------------------------------------------------------------

  /// `GET /clinic/queue/state` — public waiting-room feed (no auth).
  Future<PublicQueueState> publicQueueState() async {
    final res = await _dio.get<Map<String, dynamic>>('/clinic/queue/state');
    final body = res.data;
    return PublicQueueState.fromJson(body?['data'] as Map<String, dynamic>?);
  }

  /// `GET /me/queue-status` (employee) or `/me/student-queue-status` (student).
  Future<List<MyQueueStatus>> myQueues() async {
    final res = await _dio.get<Map<String, dynamic>>('/me/queues');
    final data = res.data?['data'] as Map<String, dynamic>?;
    return (data?['queues'] as List? ?? []).whereType<Map<String, dynamic>>().map(MyQueueStatus.fromJson).toList();
  }

  Future<List<PortalAppointment>> portalAppointments() async {
    final res=await _dio.get<Map<String,dynamic>>('/me/appointments',queryParameters:{'department':'all'});
    final data=res.data?['data'] as Map<String,dynamic>?;
    return (data?['appointments'] as List? ?? []).whereType<Map<String,dynamic>>().map(PortalAppointment.fromJson).toList();
  }
  Future<List<PortalAppointmentSlot>> portalAppointmentSlots(String department,String date) async {
    final res=await _dio.get<Map<String,dynamic>>('/me/appointment-slots',queryParameters:{'department':department,'from':date,'to':date});
    final data=res.data?['data'] as Map<String,dynamic>?;
    return (data?['slots'] as List? ?? []).whereType<Map<String,dynamic>>().map(PortalAppointmentSlot.fromJson).toList();
  }
  Future<void> bookPortalAppointment(PortalAppointmentSlot slot,{String? reason,String type='initial'}) async {
    await _dio.post<Map<String,dynamic>>('/me/appointments',data:{'department':slot.department,'provider_user_id':slot.providerUserId,'starts_at':slot.startsAt.toUtc().toIso8601String(),'type':type,if(reason!=null&&reason.isNotEmpty)'reason':reason});
  }
  Future<void> cancelPortalAppointment(PortalAppointment appointment) async {
    await _dio.post<Map<String,dynamic>>('/me/appointments/${appointment.department}/${appointment.id}/cancel');
  }

  Future<List<GuidanceQueueEntry>> guidanceQueue() async {
    final res = await _dio.get<Map<String, dynamic>>('/counselling/queue');
    return _unwrapList(res).whereType<Map<String, dynamic>>().map(GuidanceQueueEntry.fromJson).toList();
  }
  Future<void> callNextGuidance() async { await _dio.post<Map<String,dynamic>>('/counselling/queue/call-next'); }
  Future<void> transitionGuidanceQueue(int id,String action) async { await _dio.post<Map<String,dynamic>>('/counselling/queue/$id/transition',data:{'action':action}); }
  Future<void> repairGuidanceQueue(int id) async { await _dio.post<Map<String,dynamic>>('/counselling/queue/$id/repair-session'); }

  // ---------------------------------------------------------------------
  // Notifications
  // ---------------------------------------------------------------------

  /// `GET /notifications?limit=&cursor=` — self-scoped in-app notifications.
  Future<ApiPage<AppNotification>> notifications({
    int limit = 25,
    String? cursor,
  }) async {
    final query = <String, dynamic>{
      'limit': limit,
      if (cursor != null && cursor.isNotEmpty) 'cursor': cursor,
    };
    final res = await _dio.get<Map<String, dynamic>>('/notifications',
        queryParameters: query);
    final body = res.data;
    final items = (body?['data'] as List? ?? [])
        .whereType<Map<String, dynamic>>()
        .map(AppNotification.fromJson)
        .toList();
    final meta =
        PaginationMeta.fromJson(body?['meta'] as Map<String, dynamic>?);
    return ApiPage(items: items, meta: meta);
  }

  /// `POST /notifications/{id}/read`
  Future<void> markNotificationRead(int id) async {
    await _dio.post<Map<String, dynamic>>('/notifications/$id/read');
  }

  /// `POST /notifications/read-all`
  Future<void> markAllNotificationsRead() async {
    await _dio.post<Map<String, dynamic>>('/notifications/read-all');
  }

  // ---------------------------------------------------------------------
  // My portal
  // ---------------------------------------------------------------------

  /// `GET /me/employee-profile`
  Future<UserProfile> employeeProfile() async {
    final res = await _dio.get<Map<String, dynamic>>('/me/employee-profile');
    return UserProfile.fromJson(_unwrapObject(res));
  }

  /// `GET /me/student-profile`
  Future<UserProfile> studentProfile() async {
    final res = await _dio.get<Map<String, dynamic>>('/me/student-profile');
    return UserProfile.fromJson(_unwrapObject(res));
  }

  /// `GET /me/clinic-visits` (employee) or `/me/student-clinic-visits` (student).
  Future<List<ClinicVisit>> clinicVisits(
      {required bool student, int limit = 50}) async {
    final path = student ? '/me/student-clinic-visits' : '/me/clinic-visits';
    final res = await _dio.get<Map<String, dynamic>>(
      path,
      queryParameters: {'limit': limit},
    );
    return _unwrapList(res)
        .whereType<Map<String, dynamic>>()
        .map(ClinicVisit.fromJson)
        .toList();
  }

  // ---------------------------------------------------------------------
  // Patients (clinic.patients.read)
  // ---------------------------------------------------------------------

  /// `GET /clinic/students?limit=&cursor=&include_archived=`
  Future<ApiPage<UserProfile>> students({
    int limit = 25,
    String? cursor,
    bool includeArchived = false,
  }) async {
    final query = <String, dynamic>{
      'limit': limit,
      if (includeArchived) 'include_archived': '1',
      if (cursor != null && cursor.isNotEmpty) 'cursor': cursor,
    };
    final res = await _dio.get<Map<String, dynamic>>(
      '/clinic/students',
      queryParameters: query,
    );
    final body = res.data;
    return ApiPage(
      items: (body?['data'] as List? ?? [])
          .whereType<Map<String, dynamic>>()
          .map(UserProfile.fromJson)
          .toList(),
      meta: PaginationMeta.fromJson(body?['meta'] as Map<String, dynamic>?),
    );
  }

  /// `GET /clinic/students/search?q=`
  Future<List<UserProfile>> studentSearch(String q) async {
    final res = await _dio.get<Map<String, dynamic>>(
      '/clinic/students/search',
      queryParameters: {'q': q.trim()},
    );
    return _unwrapList(res)
        .whereType<Map<String, dynamic>>()
        .map(UserProfile.fromJson)
        .toList();
  }

  /// `GET /clinic/employees?limit=&cursor=&teaching=`
  Future<ApiPage<UserProfile>> employees({
    int limit = 25,
    String? cursor,
    bool includeArchived = false,
    String? teaching,
  }) async {
    final query = <String, dynamic>{
      'limit': limit,
      if (includeArchived) 'include_archived': '1',
      if (teaching != null && teaching != 'all') 'teaching': teaching,
      if (cursor != null && cursor.isNotEmpty) 'cursor': cursor,
    };
    final res = await _dio.get<Map<String, dynamic>>(
      '/clinic/employees',
      queryParameters: query,
    );
    final body = res.data;
    return ApiPage(
      items: (body?['data'] as List? ?? [])
          .whereType<Map<String, dynamic>>()
          .map(UserProfile.fromJson)
          .toList(),
      meta: PaginationMeta.fromJson(body?['meta'] as Map<String, dynamic>?),
    );
  }

  /// `GET /clinic/employees/search?q=`
  Future<List<UserProfile>> employeeSearch(String q) async {
    final res = await _dio.get<Map<String, dynamic>>(
      '/clinic/employees/search',
      queryParameters: {'q': q.trim()},
    );
    return _unwrapList(res)
        .whereType<Map<String, dynamic>>()
        .map(UserProfile.fromJson)
        .toList();
  }

  // ---------------------------------------------------------------------
  // Inventory (clinic.inventory.read)
  // ---------------------------------------------------------------------

  /// `GET /clinic/inventory?limit=&cursor=&include_archived=`
  Future<ApiPage<InventoryItem>> inventoryItems({
    int limit = 25,
    String? cursor,
    bool includeArchived = false,
  }) async {
    final query = <String, dynamic>{
      'limit': limit,
      if (includeArchived) 'include_archived': '1',
      if (cursor != null && cursor.isNotEmpty) 'cursor': cursor,
    };
    final res = await _dio.get<Map<String, dynamic>>(
      '/clinic/inventory',
      queryParameters: query,
    );
    final body = res.data;
    return ApiPage(
      items: (body?['data'] as List? ?? [])
          .whereType<Map<String, dynamic>>()
          .map(InventoryItem.fromJson)
          .toList(),
      meta: PaginationMeta.fromJson(body?['meta'] as Map<String, dynamic>?),
    );
  }

  // ---------------------------------------------------------------------
  // Medicines (clinic.inventory.read)
  // ---------------------------------------------------------------------

  /// `GET /clinic/medicines?limit=&cursor=&q=`
  Future<ApiPage<Medicine>> medicines({
    int limit = 25,
    String? cursor,
    String? q,
    bool includeArchived = false,
  }) async {
    final query = <String, dynamic>{
      'limit': limit,
      if (includeArchived) 'include_archived': '1',
      if (q != null && q.isNotEmpty) 'q': q,
      if (cursor != null && cursor.isNotEmpty) 'cursor': cursor,
    };
    final res = await _dio.get<Map<String, dynamic>>(
      '/clinic/medicines',
      queryParameters: query,
    );
    final body = res.data;
    return ApiPage(
      items: (body?['data'] as List? ?? [])
          .whereType<Map<String, dynamic>>()
          .map(Medicine.fromJson)
          .toList(),
      meta: PaginationMeta.fromJson(body?['meta'] as Map<String, dynamic>?),
    );
  }

  /// `GET /clinic/medicines/low-stock` — plain list.
  Future<List<Medicine>> medicinesLowStock() async {
    final res =
        await _dio.get<Map<String, dynamic>>('/clinic/medicines/low-stock');
    return _unwrapList(res)
        .whereType<Map<String, dynamic>>()
        .map(Medicine.fromJson)
        .toList();
  }

  /// `GET /clinic/medicines/expiring?days=` — plain list.
  Future<List<Medicine>> medicinesExpiring({int days = 90}) async {
    final res = await _dio.get<Map<String, dynamic>>(
      '/clinic/medicines/expiring',
      queryParameters: {'days': days},
    );
    return _unwrapList(res)
        .whereType<Map<String, dynamic>>()
        .map(Medicine.fromJson)
        .toList();
  }

  // ---------------------------------------------------------------------
  // Counselling (counselling.records.read)
  // ---------------------------------------------------------------------

  /// `GET /counselling/sessions?limit=&cursor=`
  Future<ApiPage<CounsellingSession>> counsellingSessions({
    int limit = 25,
    String? cursor,
  }) async {
    final query = <String, dynamic>{
      'limit': limit,
      if (cursor != null && cursor.isNotEmpty) 'cursor': cursor,
    };
    final res = await _dio.get<Map<String, dynamic>>(
      '/counselling/sessions',
      queryParameters: query,
    );
    final body = res.data;
    return ApiPage(
      items: (body?['data'] as List? ?? [])
          .whereType<Map<String, dynamic>>()
          .map(CounsellingSession.fromJson)
          .toList(),
      meta: PaginationMeta.fromJson(body?['meta'] as Map<String, dynamic>?),
    );
  }

  // ---------------------------------------------------------------------
  // Referrals (referrals.read)
  // ---------------------------------------------------------------------

  /// `GET /referrals?limit=&cursor=&status=`
  Future<ApiPage<Referral>> referrals({
    int limit = 25,
    String? cursor,
    String? status,
  }) async {
    final query = <String, dynamic>{
      'limit': limit,
      if (status != null && status.isNotEmpty) 'status': status,
      if (cursor != null && cursor.isNotEmpty) 'cursor': cursor,
    };
    final res = await _dio.get<Map<String, dynamic>>(
      '/referrals',
      queryParameters: query,
    );
    final body = res.data;
    return ApiPage(
      items: (body?['data'] as List? ?? [])
          .whereType<Map<String, dynamic>>()
          .map(Referral.fromJson)
          .toList(),
      meta: PaginationMeta.fromJson(body?['meta'] as Map<String, dynamic>?),
    );
  }

  // ---------------------------------------------------------------------
  // Facilities (facilities.units.read)
  // ---------------------------------------------------------------------

  /// `GET /facilities/units?limit=&cursor=&include_archived=`
  Future<ApiPage<BmgUnit>> facilityUnits({
    int limit = 25,
    String? cursor,
    bool includeArchived = false,
  }) async {
    final query = <String, dynamic>{
      'limit': limit,
      if (includeArchived) 'include_archived': '1',
      if (cursor != null && cursor.isNotEmpty) 'cursor': cursor,
    };
    final res = await _dio.get<Map<String, dynamic>>(
      '/facilities/units',
      queryParameters: query,
    );
    final body = res.data;
    return ApiPage(
      items: (body?['data'] as List? ?? [])
          .whereType<Map<String, dynamic>>()
          .map(BmgUnit.fromJson)
          .toList(),
      meta: PaginationMeta.fromJson(body?['meta'] as Map<String, dynamic>?),
    );
  }

  // ---------------------------------------------------------------------
  // Reports (reports.read)
  // ---------------------------------------------------------------------

  /// `GET /reports/summary?start=&end=` (YYYY-MM-DD)
  Future<ReportSummary> reportSummary({
    required String start,
    required String end,
  }) async {
    final res = await _dio.get<Map<String, dynamic>>(
      '/reports/summary',
      queryParameters: {'start': start, 'end': end},
    );
    return ReportSummary.fromJson(_unwrapObject(res));
  }

  /// `GET /reports/{module}?start=&end=` — per-module analytics
  /// (clinic | counselling | inventory | referrals | facilities).
  Future<Map<String, dynamic>> reportModule(
    String module, {
    required String start,
    required String end,
  }) async {
    final res = await _dio.get<Map<String, dynamic>>(
      '/reports/$module',
      queryParameters: {'start': start, 'end': end},
    );
    return _unwrapObject(res);
  }

  /// `GET /reports/export/{module}?start=&end=` — CSV export (text body).
  Future<String> reportExportCsv(
    String module, {
    required String start,
    required String end,
  }) async {
    final res = await _dio.get<String>(
      '/reports/export/$module',
      queryParameters: {'start': start, 'end': end},
      options: Options(responseType: ResponseType.plain),
    );
    return res.data ?? '';
  }

  // ---------------------------------------------------------------------
  // Audit (audit.read)
  // ---------------------------------------------------------------------

  /// `GET /audit/events?limit=&cursor=&action=&entity_type=&entity_id=
  /// &actor_user_id=&request_id=&from=&to=&q=`
  Future<ApiPage<AuditEvent>> auditEvents({
    int limit = 50,
    String? cursor,
    String? q,
    String? from,
    String? to,
    String? action,
    String? entityType,
    int? entityId,
    int? actorUserId,
    String? requestId,
  }) async {
    final query = <String, dynamic>{
      'limit': limit,
      if (q != null && q.isNotEmpty) 'q': q,
      if (from != null && from.isNotEmpty) 'from': from,
      if (to != null && to.isNotEmpty) 'to': to,
      if (action != null && action.isNotEmpty) 'action': action,
      if (entityType != null && entityType.isNotEmpty)
        'entity_type': entityType,
      if (entityId != null) 'entity_id': entityId,
      if (actorUserId != null) 'actor_user_id': actorUserId,
      if (requestId != null && requestId.isNotEmpty) 'request_id': requestId,
      if (cursor != null && cursor.isNotEmpty) 'cursor': cursor,
    };
    final res = await _dio.get<Map<String, dynamic>>(
      '/audit/events',
      queryParameters: query,
    );
    final body = res.data;
    return ApiPage(
      items: (body?['data'] as List? ?? [])
          .whereType<Map<String, dynamic>>()
          .map(AuditEvent.fromJson)
          .toList(),
      meta: PaginationMeta.fromJson(body?['meta'] as Map<String, dynamic>?),
    );
  }

  /// `GET /audit/facets` — distinct values for the filter dropdowns.
  Future<AuditFacets> auditFacets() async {
    final res = await _dio.get<Map<String, dynamic>>('/audit/facets');
    final body = res.data;
    return AuditFacets.fromJson(body?['data'] as Map<String, dynamic>?);
  }

  /// `GET /audit/events/{id}` — single event including redacted payload.
  Future<AuditEvent> auditEventDetail(int id) async {
    final res = await _dio.get<Map<String, dynamic>>('/audit/events/$id');
    final body = res.data;
    final data = body?['data'];
    if (data is! Map<String, dynamic>) {
      throw ApiException(
        res.statusCode ?? 0,
        [
          ApiError(
              code: 'invalid.envelope', message: 'Unexpected response shape')
        ],
      );
    }
    return AuditEvent.fromJson(data);
  }

  /// `GET /audit/verify` (or `GET /audit/verify/{id}`) — hash-chain check.
  Future<AuditVerification> verifyAuditChain({int? id}) async {
    final path = id == null ? '/audit/verify' : '/audit/verify/$id';
    final res = await _dio.get<Map<String, dynamic>>(path);
    final body = res.data;
    final data = body?['data'];
    if (data is! Map<String, dynamic>) {
      throw ApiException(
        res.statusCode ?? 0,
        [
          ApiError(
              code: 'invalid.envelope', message: 'Unexpected response shape')
        ],
      );
    }
    return AuditVerification.fromJson(data);
  }

  /// `GET /audit/export?limit=5000&...` — CSV text body (audit.export).
  Future<String> auditExport({
    int limit = 5000,
    String? q,
    String? from,
    String? to,
    String? action,
    String? entityType,
    int? entityId,
    int? actorUserId,
    String? requestId,
  }) async {
    final query = <String, dynamic>{
      'limit': limit,
      if (q != null && q.isNotEmpty) 'q': q,
      if (from != null && from.isNotEmpty) 'from': from,
      if (to != null && to.isNotEmpty) 'to': to,
      if (action != null && action.isNotEmpty) 'action': action,
      if (entityType != null && entityType.isNotEmpty)
        'entity_type': entityType,
      if (entityId != null) 'entity_id': entityId,
      if (actorUserId != null) 'actor_user_id': actorUserId,
      if (requestId != null && requestId.isNotEmpty) 'request_id': requestId,
    };
    final res = await _dio.get<String>(
      '/audit/export',
      queryParameters: query,
      options: Options(responseType: ResponseType.plain),
    );
    return res.data ?? '';
  }

  // ---------------------------------------------------------------------
  // Admin users (rbac.read / rbac.manage)
  // ---------------------------------------------------------------------

  /// `GET /admin/users?limit=&cursor=&q=`
  Future<ApiPage<AdminUser>> adminUsers({
    int limit = 25,
    String? cursor,
    String? q,
  }) async {
    final query = <String, dynamic>{
      'limit': limit,
      if (q != null && q.isNotEmpty) 'q': q,
      if (cursor != null && cursor.isNotEmpty) 'cursor': cursor,
    };
    final res = await _dio.get<Map<String, dynamic>>(
      '/admin/users',
      queryParameters: query,
    );
    final body = res.data;
    return ApiPage(
      items: (body?['data'] as List? ?? [])
          .whereType<Map<String, dynamic>>()
          .map(AdminUser.fromJson)
          .toList(),
      meta: PaginationMeta.fromJson(body?['meta'] as Map<String, dynamic>?),
    );
  }

  // ---------------------------------------------------------------------
  // Appointments — schedule + transition (clinic.appointments.write)
  // ---------------------------------------------------------------------

  /// `POST /clinic/appointments` — schedule a staff appointment.
  /// [scheduledAt] is a UTC wall-clock `YYYY-MM-DD HH:mm:ss`.
  Future<Appointment> scheduleAppointment({
    required String patientSchoolId,
    required int providerUserId,
    required String scheduledAt,
    String? reason,
  }) async {
    final res = await _dio.post<Map<String, dynamic>>(
      '/clinic/appointments',
      data: {
        'patient_school_id': patientSchoolId,
        'provider_user_id': providerUserId,
        'scheduled_at': scheduledAt,
        if (reason != null && reason.isNotEmpty) 'reason': reason,
      },
    );
    return Appointment.fromJson(_unwrapObject(res));
  }

  /// `POST /clinic/appointments/{id}/transition` — status: checked_in |
  /// completed | cancelled | no_show.
  Future<Appointment> appointmentTransition({
    required int id,
    required String status,
  }) async {
    final res = await _dio.post<Map<String, dynamic>>(
      '/clinic/appointments/$id/transition',
      data: {'status': status},
    );
    return Appointment.fromJson(_unwrapObject(res));
  }

  /// `POST /clinic/appointments/{id}/qr` — (re)issue the proof-of-booking QR.
  /// Staff with `clinic.appointments.write` or the booking's owner (student)
  /// may call this. Returns the plaintext token for QR rendering.
  Future<String> issueAppointmentQr(int id) async {
    final res =
        await _dio.post<Map<String, dynamic>>('/clinic/appointments/$id/qr');
    final body = _unwrapObject(res);
    final token = body['qr_token'] as String?;
    if (token == null || token.isEmpty) {
      throw ApiException(502, [
        ApiError(
          code: 'qr.token.missing',
          message: 'The backend did not return a QR token.',
        ),
      ]);
    }
    return token;
  }

  /// `POST /appointments/verify` — PUBLIC minimum-disclosure verify (no
  /// auth). Returns only validity + status + scheduled time, never PII.
  Future<AppointmentQrVerify> verifyAppointmentQr(String token) async {
    final res = await _dio.post<Map<String, dynamic>>(
      '/appointments/verify',
      data: {'token': token},
    );
    final data = _unwrapObject(res);
    return AppointmentQrVerify(
      valid: data['valid'] == true,
      status: data['status'] as String?,
      scheduledAt: data['scheduled_at'] as String?,
    );
  }

  // ---------------------------------------------------------------------
  // Patient lookup (clinic.patients.read) — `{id, kind, name, school_id}`
  // ---------------------------------------------------------------------

  /// `GET /clinic/patients/lookup?q=&limit=`
  Future<List<PatientLookupResult>> patientLookup(String q,
      {int limit = 8}) async {
    final res = await _dio.get<Map<String, dynamic>>(
      '/clinic/patients/lookup',
      queryParameters: {'q': q.trim(), 'limit': limit},
    );
    return _unwrapList(res)
        .whereType<Map<String, dynamic>>()
        .map(PatientLookupResult.fromJson)
        .toList();
  }

  /// `GET /clinic/encounters?status=open` — minimal open-encounter list for
  /// anchoring dispenses (id + patient school id).
  Future<List<Map<String, dynamic>>> openEncounters() async {
    final res = await _dio.get<Map<String, dynamic>>(
      '/clinic/encounters',
      queryParameters: {'status': 'open', 'limit': 50},
    );
    final body = res.data;
    final list = body?['data'] as List? ?? [];
    return list
        .whereType<Map<String, dynamic>>()
        .map((e) => {
              'id': e['id'],
              'patient_school_id': e['patient_school_id'] ?? '',
              'chief_complaint': e['chief_complaint'] ?? '',
            })
        .toList();
  }

  /// `GET /clinic/encounters?limit=&cursor=&status=` — paged encounter list
  /// (status: open | closed | referred). Full rows for the Clinic screen.
  Future<ApiPage<Map<String, dynamic>>> encounters({
    String? status,
    int limit = 25,
    String? cursor,
  }) async {
    final query = <String, dynamic>{
      'limit': limit,
      if (status != null && status.isNotEmpty) 'status': status,
      if (cursor != null && cursor.isNotEmpty) 'cursor': cursor,
    };
    final res = await _dio.get<Map<String, dynamic>>(
      '/clinic/encounters',
      queryParameters: query,
    );
    final body = res.data;
    return ApiPage(
      items: (body?['data'] as List? ?? [])
          .whereType<Map<String, dynamic>>()
          .toList(),
      meta: PaginationMeta.fromJson(body?['meta'] as Map<String, dynamic>?),
    );
  }

  /// `POST /clinic/encounters` — desk "New encounter" (walk-in without the
  /// kiosk). Body `{ patient_school_id, chief_complaint }`.
  Future<void> createEncounter({
    required String patientSchoolId,
    required String chiefComplaint,
  }) async {
    await _dio.post<Map<String, dynamic>>(
      '/clinic/encounters',
      data: {
        'patient_school_id': patientSchoolId,
        'chief_complaint': chiefComplaint,
      },
    );
  }

  /// `GET /clinic/encounters/{id}/vitals` — vitals history for an encounter.
  Future<List<Map<String, dynamic>>> encounterVitals(int encounterId) async {
    final res = await _dio.get<Map<String, dynamic>>(
      '/clinic/encounters/$encounterId/vitals',
    );
    return _unwrapList(res).whereType<Map<String, dynamic>>().toList();
  }

  /// `GET /clinic/encounters/{id}/treatments` — treatments for an encounter.
  Future<List<Map<String, dynamic>>> encounterTreatments(
      int encounterId) async {
    final res = await _dio.get<Map<String, dynamic>>(
      '/clinic/encounters/$encounterId/treatments',
    );
    return _unwrapList(res).whereType<Map<String, dynamic>>().toList();
  }

  /// Complete workflow detail, including progress counts and referrals.
  Future<ClinicEncounter> encounterDetail(int encounterId) async {
    final res = await _dio.get<Map<String, dynamic>>(
      '/clinic/encounters/$encounterId',
    );
    return ClinicEncounter.fromJson(_unwrapObject(res));
  }

  Future<ClinicVitals> recordEncounterVitals(
    int encounterId,
    Map<String, dynamic> payload,
  ) async {
    final res = await _dio.post<Map<String, dynamic>>(
      '/clinic/encounters/$encounterId/vitals',
      data: payload,
    );
    return ClinicVitals.fromJson(_unwrapObject(res));
  }

  Future<List<ClinicVitals>> typedEncounterVitals(int encounterId) async {
    final res = await _dio.get<Map<String, dynamic>>(
      '/clinic/encounters/$encounterId/vitals',
    );
    return _unwrapList(res)
        .whereType<Map<String, dynamic>>()
        .map(ClinicVitals.fromJson)
        .toList();
  }

  Future<PreviousHeightWeight?> previousHeightWeight(int encounterId) async {
    final res = await _dio.get<Map<String, dynamic>>(
      '/clinic/encounters/$encounterId/previous-height-weight',
    );
    final data = _unwrapNullableObject(res);
    return data == null ? null : PreviousHeightWeight.fromJson(data);
  }

  Future<ClinicEncounter> setEncounterAssessment(
    int encounterId,
    Map<String, dynamic> payload,
  ) async {
    final res = await _dio.post<Map<String, dynamic>>(
      '/clinic/encounters/$encounterId/assessment',
      data: payload,
    );
    return ClinicEncounter.fromJson(_unwrapObject(res));
  }

  Future<List<ClinicTreatment>> typedEncounterTreatments(
      int encounterId) async {
    final res = await _dio.get<Map<String, dynamic>>(
      '/clinic/encounters/$encounterId/treatments',
    );
    return _unwrapList(res)
        .whereType<Map<String, dynamic>>()
        .map(ClinicTreatment.fromJson)
        .toList();
  }

  Future<ClinicTreatment> addEncounterTreatment(
    int encounterId,
    Map<String, dynamic> payload,
  ) async {
    final res = await _dio.post<Map<String, dynamic>>(
      '/clinic/encounters/$encounterId/treatments',
      data: payload,
    );
    return ClinicTreatment.fromJson(_unwrapObject(res));
  }

  Future<Referral> createEncounterReferral(
    int encounterId, {
    String? reasonCode,
    String? notes,
  }) async {
    final res = await _dio.post<Map<String, dynamic>>(
      '/clinic/encounters/$encounterId/referrals',
      data: {
        if (reasonCode?.trim().isNotEmpty == true)
          'reason_code': reasonCode!.trim(),
        if (notes?.trim().isNotEmpty == true) 'notes_plaintext': notes!.trim(),
      },
    );
    return Referral.fromJson(_unwrapObject(res));
  }

  Future<ClinicEncounter> completeEncounter(int encounterId) async {
    final res = await _dio.post<Map<String, dynamic>>(
      '/clinic/encounters/$encounterId/close',
    );
    return ClinicEncounter.fromJson(_unwrapObject(res));
  }

  // ---------------------------------------------------------------------
  // Staff schedules (clinic.schedules.manage / clinic.schedules.read)
  // ---------------------------------------------------------------------

  /// `GET /clinic/staff-schedules` — active staff shifts.
  Future<List<Map<String, dynamic>>> staffSchedules() async {
    final res = await _dio.get<Map<String, dynamic>>('/clinic/staff-schedules');
    return _unwrapList(res).whereType<Map<String, dynamic>>().toList();
  }

  /// `POST /clinic/staff-schedules` — add a staff shift.
  Future<void> createStaffSchedule(Map<String, dynamic> payload) async {
    await _dio.post<Map<String, dynamic>>('/clinic/staff-schedules',
        data: payload);
  }

  /// `POST /clinic/staff-schedules/{id}` — edit a staff shift.
  Future<void> updateStaffSchedule(int id, Map<String, dynamic> payload) async {
    await _dio.post<Map<String, dynamic>>('/clinic/staff-schedules/$id',
        data: payload);
  }

  // ---------------------------------------------------------------------
  // Queue management (clinic.queue.manage)
  // ---------------------------------------------------------------------

  /// `GET /clinic/queue` — today's staff queue rows.
  Future<List<QueueEntry>> queueToday() async {
    final res = await _dio.get<Map<String, dynamic>>('/clinic/queue');
    return _unwrapList(res)
        .whereType<Map<String, dynamic>>()
        .map(QueueEntry.fromJson)
        .toList();
  }

  /// `POST /clinic/queue/call-next`
  Future<void> queueCallNext() async {
    await _dio.post<Map<String, dynamic>>('/clinic/queue/call-next');
  }

  /// `POST /clinic/queue/{id}/transition` — action: start | skip | complete.
  Future<void> queueTransition(
      {required int id, required String action}) async {
    await _dio.post<Map<String, dynamic>>(
      '/clinic/queue/$id/transition',
      data: {'action': action},
    );
  }

  // ---------------------------------------------------------------------
  // Patients CRUD (clinic.patients.write)
  // ---------------------------------------------------------------------

  /// `POST /clinic/students`
  Future<UserProfile> createStudent(Map<String, dynamic> payload) async {
    final res = await _dio.post<Map<String, dynamic>>(
      '/clinic/students',
      data: payload,
    );
    return UserProfile.fromJson(_unwrapObject(res));
  }

  /// `POST /clinic/students/{id}`
  Future<UserProfile> updateStudent(
      int id, Map<String, dynamic> payload) async {
    final res = await _dio.post<Map<String, dynamic>>(
      '/clinic/students/$id',
      data: payload,
    );
    return UserProfile.fromJson(_unwrapObject(res));
  }

  /// `POST /clinic/students/{id}/archive` — body `{ archived: bool }`
  Future<void> setStudentArchived(int id, bool archived) async {
    await _dio.post<Map<String, dynamic>>(
      '/clinic/students/$id/archive',
      data: {'archived': archived},
    );
  }

  /// `POST /clinic/employees`
  Future<UserProfile> createEmployee(Map<String, dynamic> payload) async {
    final res = await _dio.post<Map<String, dynamic>>(
      '/clinic/employees',
      data: payload,
    );
    return UserProfile.fromJson(_unwrapObject(res));
  }

  /// `POST /clinic/employees/{id}`
  Future<UserProfile> updateEmployee(
      int id, Map<String, dynamic> payload) async {
    final res = await _dio.post<Map<String, dynamic>>(
      '/clinic/employees/$id',
      data: payload,
    );
    return UserProfile.fromJson(_unwrapObject(res));
  }

  /// `POST /clinic/employees/{id}/archive` — body `{ archived: bool }`
  Future<void> setEmployeeArchived(int id, bool archived) async {
    await _dio.post<Map<String, dynamic>>(
      '/clinic/employees/$id/archive',
      data: {'archived': archived},
    );
  }

  /// `GET /clinic/students/{id}` — detail with allergies + contacts.
  Future<UserProfile> studentDetail(int id) async {
    final res = await _dio.get<Map<String, dynamic>>('/clinic/students/$id');
    return UserProfile.fromJson(_unwrapObject(res));
  }

  /// `GET /clinic/employees/{id}` — employee detail.
  Future<UserProfile> employeeDetail(int id) async {
    final res = await _dio.get<Map<String, dynamic>>('/clinic/employees/$id');
    return UserProfile.fromJson(_unwrapObject(res));
  }

  /// `POST /clinic/students/{id}/allergies`
  Future<UserProfile> addStudentAllergy(
      int id, Map<String, dynamic> payload) async {
    final res = await _dio.post<Map<String, dynamic>>(
      '/clinic/students/$id/allergies',
      data: payload,
    );
    return UserProfile.fromJson(_unwrapObject(res));
  }

  /// `POST /clinic/students/{id}/allergies/{allergyId}`
  Future<UserProfile> updateStudentAllergy(
    int id,
    int allergyId,
    Map<String, dynamic> payload,
  ) async {
    final res = await _dio.post<Map<String, dynamic>>(
      '/clinic/students/$id/allergies/$allergyId',
      data: payload,
    );
    return UserProfile.fromJson(_unwrapObject(res));
  }

  /// `POST /clinic/students/{id}/allergies/{allergyId}/delete`
  Future<UserProfile> deleteStudentAllergy(int id, int allergyId) async {
    final res = await _dio.post<Map<String, dynamic>>(
      '/clinic/students/$id/allergies/$allergyId/delete',
    );
    return UserProfile.fromJson(_unwrapObject(res));
  }

  /// `POST /clinic/students/{id}/contacts`
  Future<UserProfile> addStudentContact(
      int id, Map<String, dynamic> payload) async {
    final res = await _dio.post<Map<String, dynamic>>(
      '/clinic/students/$id/contacts',
      data: payload,
    );
    return UserProfile.fromJson(_unwrapObject(res));
  }

  /// `POST /clinic/students/{id}/contacts/{contactId}`
  Future<UserProfile> updateStudentContact(
    int id,
    int contactId,
    Map<String, dynamic> payload,
  ) async {
    final res = await _dio.post<Map<String, dynamic>>(
      '/clinic/students/$id/contacts/$contactId',
      data: payload,
    );
    return UserProfile.fromJson(_unwrapObject(res));
  }

  /// `POST /clinic/students/{id}/contacts/{contactId}/delete`
  Future<UserProfile> deleteStudentContact(int id, int contactId) async {
    final res = await _dio.post<Map<String, dynamic>>(
      '/clinic/students/$id/contacts/$contactId/delete',
    );
    return UserProfile.fromJson(_unwrapObject(res));
  }

  /// `GET /clinic/departments` — employee department options.
  Future<List<String>> departments() async {
    final res = await _dio.get<Map<String, dynamic>>('/clinic/departments');
    return _unwrapList(res)
        .whereType<Map<String, dynamic>>()
        .map((e) => (e['name'] ?? '').toString())
        .where((s) => s.isNotEmpty)
        .toList();
  }

  // ---------------------------------------------------------------------
  // Medicines CRUD (clinic.inventory.write)
  // ---------------------------------------------------------------------

  /// `POST /clinic/medicines`
  Future<Medicine> createMedicine(Map<String, dynamic> payload) async {
    final res = await _dio.post<Map<String, dynamic>>(
      '/clinic/medicines',
      data: payload,
    );
    return Medicine.fromJson(_unwrapObject(res));
  }

  /// `POST /clinic/medicines/{id}` — update reorder_threshold.
  Future<void> updateMedicine(int id, Map<String, dynamic> payload) async {
    await _dio.post<Map<String, dynamic>>('/clinic/medicines/$id',
        data: payload);
  }

  /// `POST /clinic/medicines/{id}/archive` / `.../unarchive`
  Future<void> archiveMedicine(int id, {bool archived = true}) async {
    await _dio.post<Map<String, dynamic>>(
      '/clinic/medicines/$id/${archived ? 'archive' : 'unarchive'}',
    );
  }

  /// `POST /clinic/medicines/{id}/batches`
  Future<void> addMedicineBatch(
      int medicineId, Map<String, dynamic> payload) async {
    await _dio.post<Map<String, dynamic>>(
      '/clinic/medicines/$medicineId/batches',
      data: payload,
    );
  }

  /// `POST /clinic/medicines/{id}/dispense` — requires an open encounter.
  Future<void> dispenseMedicine(
      int medicineId, Map<String, dynamic> payload) async {
    await _dio.post<Map<String, dynamic>>(
      '/clinic/medicines/$medicineId/dispense',
      data: payload,
    );
  }

  /// `GET /clinic/medicines/{id}` — returns the medicine incl. its batches.
  Future<List<Map<String, dynamic>>> medicineBatches(int medicineId) async {
    final res =
        await _dio.get<Map<String, dynamic>>('/clinic/medicines/$medicineId');
    final data = _unwrapObject(res);
    final batches = data['batches'];
    return (batches is List)
        ? batches
            .whereType<Map<String, dynamic>>()
            .map((b) => {
                  'id': b['id'],
                  'batch_number': b['batch_number'] ?? '',
                  'expiration_date': b['expiration_date'] ?? '',
                  'quantity_remaining': b['quantity_remaining'] ?? 0,
                  'status': b['status'] ?? '',
                })
            .toList()
        : <Map<String, dynamic>>[];
  }

  /// `POST /clinic/medicines/{id}/batches/{batchId}/expire|recall`
  Future<void> writeOffMedicineBatch(int medicineId, int batchId, String action,
      {String? note}) async {
    await _dio.post<Map<String, dynamic>>(
      '/clinic/medicines/$medicineId/batches/$batchId/$action',
      data: {if (note != null && note.isNotEmpty) 'note': note},
    );
  }

  // ---------------------------------------------------------------------
  // Inventory CRUD (clinic.inventory.write)
  // ---------------------------------------------------------------------

  /// `POST /clinic/inventory`
  Future<InventoryItem> createInventoryItem(
      Map<String, dynamic> payload) async {
    final res = await _dio.post<Map<String, dynamic>>(
      '/clinic/inventory',
      data: payload,
    );
    return InventoryItem.fromJson(_unwrapObject(res));
  }

  /// `POST /clinic/inventory/{id}`
  Future<void> updateInventoryItem(int id, Map<String, dynamic> payload) async {
    await _dio.post<Map<String, dynamic>>('/clinic/inventory/$id',
        data: payload);
  }

  /// `POST /clinic/inventory/{id}/archive` / `.../unarchive`
  Future<void> archiveInventoryItem(int id, {bool archived = true}) async {
    await _dio.post<Map<String, dynamic>>(
      '/clinic/inventory/$id/${archived ? 'archive' : 'unarchive'}',
    );
  }

  /// `POST /clinic/inventory/{id}/move` — qty_delta (non-zero) + note.
  Future<void> moveInventoryStock(int id, Map<String, dynamic> payload) async {
    await _dio.post<Map<String, dynamic>>('/clinic/inventory/$id/move',
        data: payload);
  }

  /// `POST /clinic/inventory/{id}/receive` — quantity + shortage_note.
  Future<void> receiveInventoryStock(
      int id, Map<String, dynamic> payload) async {
    await _dio.post<Map<String, dynamic>>('/clinic/inventory/$id/receive',
        data: payload);
  }

  // ---------------------------------------------------------------------
  // Referrals actions (referrals.read / referrals.write)
  // ---------------------------------------------------------------------

  /// `GET /referrals/patient-lookup?q=&limit=` — referral-scoped patient
  /// search (gated by `referrals.create`, NOT `clinic.patients.read`) so
  /// teaching employees — who hold `referrals.create` but not
  /// `clinic.patients.read` — can find a patient by number or name when
  /// writing a referral.
  Future<List<PatientLookupResult>> referralPatientLookup(String q,
      {int limit = 8}) async {
    final res = await _dio.get<Map<String, dynamic>>(
      '/referrals/patient-lookup',
      queryParameters: {'q': q.trim(), 'limit': limit},
    );
    return _unwrapList(res)
        .whereType<Map<String, dynamic>>()
        .map(PatientLookupResult.fromJson)
        .toList();
  }

  /// `POST /referrals/verify` — PUBLIC, minimum-disclosure QR verify
  /// (no auth). Body `{ token }`; returns `{ status: valid|expired|
  /// revoked, artifact_type, issuer }` — never PII.
  Future<Map<String, dynamic>> verifyReferralToken(String token) async {
    final res = await _dio.post<Map<String, dynamic>>(
      '/referrals/verify',
      data: {'token': token.trim()},
    );
    return _unwrapObject(res);
  }

  /// `POST /referrals` — create a referral.
  Future<Referral> createReferral(Map<String, dynamic> payload) async {
    final res =
        await _dio.post<Map<String, dynamic>>('/referrals', data: payload);
    return Referral.fromJson(_unwrapObject(res));
  }

  /// `POST /referrals/{id}/{action}` — action: acknowledge | review | close.
  Future<Referral> transitionReferral(int id, String action,
      {Map<String, dynamic>? body}) async {
    final res = await _dio.post<Map<String, dynamic>>(
      '/referrals/$id/$action',
      data: body ?? const <String, dynamic>{},
    );
    return Referral.fromJson(_unwrapObject(res));
  }

  /// `POST /referrals/{id}/issue-qr` — body `{ ttl_seconds }`.
  Future<Map<String, dynamic>> issueReferralQr(int id,
      {int ttlSeconds = 3600}) async {
    final res = await _dio.post<Map<String, dynamic>>(
      '/referrals/$id/issue-qr',
      data: {'ttl_seconds': ttlSeconds},
    );
    return _unwrapObject(res);
  }

  /// `POST /referrals/{id}/revoke-qr`
  Future<void> revokeReferralQr(int id) async {
    await _dio.post<Map<String, dynamic>>('/referrals/$id/revoke-qr');
  }

  // ---------------------------------------------------------------------
  // Counselling actions (counselling.records.write / counselling.schedule.*)
  // ---------------------------------------------------------------------

  /// `POST /counselling/sessions` — body `{ patient_school_id }`.
  Future<CounsellingSession> openCounsellingSession(
      Map<String, dynamic> payload) async {
    final res = await _dio.post<Map<String, dynamic>>(
      '/counselling/sessions',
      data: payload,
    );
    return CounsellingSession.fromJson(_unwrapObject(res));
  }

  Future<CounsellingSession> counsellingSessionDetail(int sessionId) async {
    final res = await _dio.get<Map<String, dynamic>>(
      '/counselling/sessions/$sessionId',
    );
    return CounsellingSession.fromJson(_unwrapObject(res));
  }

  /// `POST /counselling/sessions/{id}/notes` — body `{ plaintext }`.
  Future<void> writeCounsellingNotes(int sessionId, String plaintext) async {
    await _dio.post<Map<String, dynamic>>(
      '/counselling/sessions/$sessionId/notes',
      data: {'plaintext': plaintext},
    );
  }

  Future<List<CounsellingNote>> counsellingNotes(int sessionId) async {
    final res = await _dio.get<Map<String, dynamic>>(
      '/counselling/sessions/$sessionId/notes',
    );
    final data = _unwrapObject(res);
    return (data['notes'] as List? ?? [])
        .whereType<Map<String, dynamic>>()
        .map(CounsellingNote.fromJson)
        .toList();
  }

  Future<Referral> createCounsellingReferral(
    int sessionId, {
    String? reasonCode,
    String? notes,
  }) async {
    final res = await _dio.post<Map<String, dynamic>>(
      '/counselling/sessions/$sessionId/referrals',
      data: {
        if (reasonCode?.trim().isNotEmpty == true)
          'reason_code': reasonCode!.trim(),
        if (notes?.trim().isNotEmpty == true) 'notes_plaintext': notes!.trim(),
      },
    );
    return Referral.fromJson(_unwrapObject(res));
  }

  /// `POST /counselling/sessions/{id}/close`
  Future<void> closeCounsellingSession(int sessionId) async {
    await _dio
        .post<Map<String, dynamic>>('/counselling/sessions/$sessionId/close');
  }

  // ---------------------------------------------------------------------
  // Counselling scheduling (counselling.schedule.*)
  // ---------------------------------------------------------------------

  /// `GET /counselling/availability` — active availability windows.
  Future<List<Map<String, dynamic>>> counsellingAvailability() async {
    final res =
        await _dio.get<Map<String, dynamic>>('/counselling/availability');
    return _unwrapList(res).whereType<Map<String, dynamic>>().toList();
  }
  Future<List<Map<String,dynamic>>> counsellingProviders() async {
    final res=await _dio.get<Map<String,dynamic>>('/counselling/counsellors');
    return _unwrapList(res).whereType<Map<String,dynamic>>().toList();
  }

  /// `POST /counselling/availability` — body `{ day_of_week (0-6),
  /// start_time, end_time, max_slots?, counsellor_user_id? }`.
  Future<void> addCounsellingAvailability(Map<String, dynamic> payload) async {
    await _dio.post<Map<String, dynamic>>('/counselling/availability',
        data: payload);
  }

  /// `POST /counselling/availability/{id}/remove`
  Future<void> removeCounsellingAvailability(int id) async {
    await _dio
        .post<Map<String, dynamic>>('/counselling/availability/$id/remove');
  }

  /// `GET /counselling/appointments?limit=&cursor=&status=`
  Future<ApiPage<Map<String, dynamic>>> counsellingAppointments({
    String? status,
    int limit = 25,
    String? cursor,
  }) async {
    final query = <String, dynamic>{
      'limit': limit,
      if (status != null && status.isNotEmpty) 'status': status,
      if (cursor != null && cursor.isNotEmpty) 'cursor': cursor,
    };
    final res = await _dio.get<Map<String, dynamic>>(
      '/counselling/appointments',
      queryParameters: query,
    );
    final body = res.data;
    return ApiPage(
      items: (body?['data'] as List? ?? [])
          .whereType<Map<String, dynamic>>()
          .toList(),
      meta: PaginationMeta.fromJson(body?['meta'] as Map<String, dynamic>?),
    );
  }

  /// `POST /counselling/appointments` — body `{ patient_school_id,
  /// appointment_date, start_time, end_time, type?, reason?,
  /// counsellor_user_id? }`.
  Future<void> bookCounsellingAppointment(Map<String, dynamic> payload) async {
    await _dio.post<Map<String, dynamic>>('/counselling/appointments',
        data: payload);
  }

  /// `POST /counselling/appointments/{id}/transition` — action:
  /// confirm | complete | cancel | no_show (+ optional cancellation_reason).
  Future<void> transitionCounsellingAppointment(int id, String action,
      {String? cancellationReason}) async {
    await _dio.post<Map<String, dynamic>>(
      '/counselling/appointments/$id/transition',
      data: {
        'action': action,
        if (cancellationReason != null && cancellationReason.isNotEmpty)
          'cancellation_reason': cancellationReason,
      },
    );
  }

  /// `GET /counselling/analytics` — no-show optimizer rows.
  Future<List<Map<String, dynamic>>> counsellingAnalytics() async {
    final res = await _dio.get<Map<String, dynamic>>('/counselling/analytics');
    return _unwrapList(res).whereType<Map<String, dynamic>>().toList();
  }

  /// `POST /counselling/analytics/recompute`
  Future<void> recomputeCounsellingAnalytics() async {
    await _dio.post<Map<String, dynamic>>('/counselling/analytics/recompute');
  }

  // ---------------------------------------------------------------------
  // Admin users (rbac.manage)
  // ---------------------------------------------------------------------

  /// `POST /admin/users` — email, username?, groups[], password.
  Future<void> createAdminUser(Map<String, dynamic> payload) async {
    await _dio.post<Map<String, dynamic>>('/admin/users', data: payload);
  }

  /// `POST /admin/users/{id}/status` — body `{ active: bool }`.
  Future<void> setUserStatus(int id, bool active) async {
    await _dio.post<Map<String, dynamic>>('/admin/users/$id/status',
        data: {'active': active});
  }

  /// `POST /admin/users/{id}/groups` — body `{ groups: [...] }`.
  Future<void> setUserGroups(int id, List<String> groups) async {
    await _dio.post<Map<String, dynamic>>('/admin/users/$id/groups',
        data: {'groups': groups});
  }

  /// `POST /admin/users/{id}/reset-password`
  Future<Map<String, dynamic>> resetUserPassword(int id) async {
    final res = await _dio
        .post<Map<String, dynamic>>('/admin/users/$id/reset-password');
    return _unwrapObject(res);
  }

  // ---------------------------------------------------------------------
  // Facilities actions (facilities.bmg.*)
  // ---------------------------------------------------------------------

  /// `POST /facilities/units/{id}/start` — body `{ category_id, weight_kg, ... }`.
  Future<void> startFacilityBatch(
      int unitId, Map<String, dynamic> payload) async {
    await _dio.post<Map<String, dynamic>>('/facilities/units/$unitId/start',
        data: payload);
  }

  /// `POST /facilities/batches/{id}/{action}` — action: finish | cancel | curing.
  Future<void> transitionFacilityBatch(int batchId, String action,
      {Map<String, dynamic>? body}) async {
    await _dio.post<Map<String, dynamic>>(
      '/facilities/batches/$batchId/$action',
      data: body ?? const <String, dynamic>{},
    );
  }

  /// `POST /facilities/batches/{id}/release` — QA grade/maturity.
  Future<void> releaseFacilityBatch(
      int batchId, Map<String, dynamic> payload) async {
    await _dio.post<Map<String, dynamic>>(
        '/facilities/batches/$batchId/release',
        data: payload);
  }

  /// `POST /facilities/batches/{id}/logs` — body `{ event_type,
  /// observation_note, temperature_celsius, moisture_level, ... }`.
  Future<void> addFacilityProcessLog(
      int batchId, Map<String, dynamic> payload) async {
    await _dio.post<Map<String, dynamic>>('/facilities/batches/$batchId/logs',
        data: payload);
  }

  /// `POST /facilities/batches/{id}/losses` — record mass loss (evaporation,
  /// off-gas, sampling, spill, cleaning, mechanical_holdup, other).
  Future<void> addFacilityBatchLoss(
      int batchId, Map<String, dynamic> payload) async {
    await _dio.post<Map<String, dynamic>>('/facilities/batches/$batchId/losses',
        data: payload);
  }

  /// `POST /facilities/alerts/{id}/acknowledge`
  Future<void> acknowledgeFacilityAlert(int alertId) async {
    await _dio
        .post<Map<String, dynamic>>('/facilities/alerts/$alertId/acknowledge');
  }

  /// `GET /facilities/waste-categories` — full category rows
  /// (`{id, code, name, expected_yield_pct, reference_duration_days,
  /// is_active, historical_avg_days, sample_count, expected_days}`). Used
  /// by both the start-batch picker (id/name) and the Waste Categories
  /// screen (full row).
  Future<List<Map<String, dynamic>>> facilityWasteCategories() async {
    final res =
        await _dio.get<Map<String, dynamic>>('/facilities/waste-categories');
    return _unwrapList(res).whereType<Map<String, dynamic>>().toList();
  }

  /// `GET /facilities/alerts/open` — open alerts across live batches.
  Future<List<Map<String, dynamic>>> facilityOpenAlerts() async {
    final res = await _dio.get<Map<String, dynamic>>('/facilities/alerts/open');
    return _unwrapList(res)
        .whereType<Map<String, dynamic>>()
        .map((e) => {
              'id': e['id'],
              'severity': e['severity'] ?? '',
              'message': e['message'] ?? e['alert_type'] ?? '',
              'batch_id': e['batch_id'],
            })
        .toList();
  }

  /// `POST /facilities/units` — create a BMG unit.
  Future<void> createFacilityUnit(Map<String, dynamic> payload) async {
    await _dio.post<Map<String, dynamic>>('/facilities/units', data: payload);
  }

  /// `POST /facilities/units/{id}` — edit a BMG unit.
  Future<void> updateFacilityUnit(
      int unitId, Map<String, dynamic> payload) async {
    await _dio.post<Map<String, dynamic>>('/facilities/units/$unitId',
        data: payload);
  }

  /// `DELETE /facilities/units/{id}` — archive a BMG unit (fails while it
  /// has an active batch).
  Future<void> archiveFacilityUnit(int unitId) async {
    await _dio.delete<Map<String, dynamic>>('/facilities/units/$unitId');
  }

  /// `POST /facilities/units/{id}/unarchive` — restore an archived drum
  /// back to Available.
  Future<void> unarchiveFacilityUnit(int unitId) async {
    await _dio
        .post<Map<String, dynamic>>('/facilities/units/$unitId/unarchive');
  }

  /// `POST /facilities/units/{id}/maintenance` — toggle Under Maintenance.
  Future<void> setFacilityUnitMaintenance(int unitId, bool maintenance) async {
    await _dio.post<Map<String, dynamic>>(
        '/facilities/units/$unitId/maintenance',
        data: {'maintenance': maintenance});
  }

  /// `POST /facilities/batches/{id}/output` — record output: Processing →
  /// Awaiting output. Body `{ output_weight_kg, output_items }` where
  /// `output_items` is `[{ sku, qty_kg }]` (mirrors the web dialog).
  Future<void> recordFacilityOutput(int batchId,
      {required double outputWeightKg,
      required List<Map<String, dynamic>> outputItems}) async {
    await _dio.post<Map<String, dynamic>>(
      '/facilities/batches/$batchId/output',
      data: {
        'output_weight_kg': outputWeightKg,
        'output_items': outputItems,
      },
    );
  }

  /// Unified "Add update" — one action that appends an immutable
  /// output / curing / log ledger entry. Body:
  /// `{ update_type: output|curing|log, output_weight_kg?, curing_note?,
  ///    event_type?, observation_note?, temperature_celsius?, moisture_level? }`.
  Future<Map<String, dynamic>> addFacilityBatchUpdate(
      int batchId, Map<String, dynamic> payload) async {
    final res = await _dio.post<Map<String, dynamic>>(
      '/facilities/batches/$batchId/update',
      data: payload,
    );
    return (res.data?['data'] as Map<String, dynamic>?) ?? {};
  }

  /// Combined, append-only "Updates" feed for a batch (output / curing /
  /// log), oldest → newest.
  Future<List<Map<String, dynamic>>> facilityBatchUpdates(int batchId) async {
    final res = await _dio.get<Map<String, dynamic>>(
      '/facilities/batches/$batchId/updates',
    );
    return _unwrapList(res).whereType<Map<String, dynamic>>().toList();
  }

  /// `GET /facilities/batches?limit=` — terminal + historical batch list
  /// (joined unit + category).
  Future<List<Map<String, dynamic>>> facilityBatchHistory() async {
    final res = await _dio.get<Map<String, dynamic>>(
      '/facilities/batches',
      queryParameters: {'limit': 50},
    );
    return _unwrapList(res)
        .whereType<Map<String, dynamic>>()
        .map((e) => e)
        .toList();
  }

  /// `GET /facilities/batches/{id}/compliance` — PFRP evidence + mass
  /// balance certificate.
  Future<Map<String, dynamic>> facilityBatchCompliance(int batchId) async {
    final res = await _dio.get<Map<String, dynamic>>(
      '/facilities/batches/$batchId/compliance',
    );
    return _unwrapObject(res);
  }

  /// `POST /facilities/waste-categories` — create a waste category.
  Future<Map<String, dynamic>> createWasteCategory(
      Map<String, dynamic> payload) async {
    final res = await _dio.post<Map<String, dynamic>>(
      '/facilities/waste-categories',
      data: payload,
    );
    return _unwrapObject(res);
  }

  /// `POST /facilities/waste-categories/{id}` — update a waste category.
  Future<Map<String, dynamic>> updateWasteCategory(
      int id, Map<String, dynamic> payload) async {
    final res = await _dio.post<Map<String, dynamic>>(
      '/facilities/waste-categories/$id',
      data: payload,
    );
    return _unwrapObject(res);
  }

  /// `POST /facilities/waste-categories/{id}/archive`
  Future<void> archiveWasteCategory(int id) async {
    await _dio.post<Map<String, dynamic>>(
      '/facilities/waste-categories/$id/archive',
    );
  }

  /// `GET /facilities/waste-categories/deviation` — per-category yield &
  /// duration deviation vs reference.
  Future<List<Map<String, dynamic>>> wasteCategoryDeviation() async {
    final res = await _dio.get<Map<String, dynamic>>(
      '/facilities/waste-categories/deviation',
    );
    return _unwrapList(res).whereType<Map<String, dynamic>>().toList();
  }
}
