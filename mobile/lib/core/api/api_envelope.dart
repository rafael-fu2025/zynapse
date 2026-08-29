/// Canonical API envelope — mirrors `backend/app/Http/ApiResponse.php`.
///
/// Every response from the SYNAPSE backend has this shape:
/// ```json
/// {
///   "success": true,
///   "data": { ... } | null,
///   "errors": null | [{ "code", "message", "field"? }],
///   "meta": { "pagination": { "limit", "next_cursor", "prev_cursor" } } | null
/// }
/// ```
/// `ApiClient` converts HTTP failures into [ApiException], so callers can
/// catch one type for all API errors (mirrors `ApiEnvelopeError` in the
/// React app at `frontend/src/api/envelope.ts`).
library;

/// A single structured error from the backend.
class ApiError {
  ApiError({required this.code, required this.message, this.field});

  factory ApiError.fromJson(Map<String, dynamic> json) => ApiError(
        code: json['code'] as String? ?? 'unknown',
        message: json['message'] as String? ?? 'Unknown error',
        field: json['field'] as String?,
      );

  final String code;
  final String message;
  final String? field;

  @override
  String toString() => field == null ? message : '$message ($field)';
}

/// Keyset pagination metadata (`meta.pagination`).
class PaginationMeta {
  PaginationMeta({this.limit, this.nextCursor, this.prevCursor});

  factory PaginationMeta.fromJson(Map<String, dynamic>? json) {
    final p = json?['pagination'];
    if (p is! Map<String, dynamic>) return PaginationMeta();
    return PaginationMeta(
      limit: p['limit'] as int?,
      nextCursor: p['next_cursor'] as String?,
      prevCursor: p['prev_cursor'] as String?,
    );
  }

  final int? limit;
  final String? nextCursor;
  final String? prevCursor;

  bool get hasNext => nextCursor != null && nextCursor!.isNotEmpty;
}

/// Raised for any non-2xx API response (and network failures).
class ApiException implements Exception {
  ApiException(this.statusCode, this.errors);

  final int statusCode;
  final List<ApiError> errors;

  String get message =>
      errors.isNotEmpty ? errors.first.message : 'HTTP $statusCode';

  ApiError? get first => errors.isEmpty ? null : errors.first;

  @override
  String toString() => message;
}
