/**
 * Canonical API envelope mirroring `App\Http\ApiResponse`.
 *
 * Every response from the SYNAPSE backend follows this shape. The axios
 * instance below normalises any drift into a thrown `ApiEnvelopeError`.
 */
export interface ApiError {
  code: string;
  message: string;
  field?: string;
  details?: Readonly<Record<string, unknown>>;
}

export interface ApiEnvelope<T> {
  success: boolean;
  data: T | null;
  errors: ApiError[] | null;
  meta: {
    pagination?: {
      limit: number;
      next_cursor: string | null;
      prev_cursor: string | null;
    };
    [k: string]: unknown;
  } | null;
}

export class ApiEnvelopeError extends Error {
  public readonly httpStatus: number;
  public readonly errors: ApiError[];

  constructor(httpStatus: number, errors: unknown) {
    const normalized = normalizeApiErrors(errors, httpStatus);
    super(normalized[0]?.message ?? `HTTP ${httpStatus}`);
    this.name = 'ApiEnvelopeError';
    this.httpStatus = httpStatus;
    this.errors = normalized;
  }
}

function normalizeApiErrors(value: unknown, httpStatus: number): ApiError[] {
  const entries = Array.isArray(value) ? value : value === null || value === undefined ? [] : [value];
  const errors = entries.flatMap((entry): ApiError[] => {
    if (typeof entry === 'string' && entry.trim() !== '') {
      return [{ code: 'request.failed', message: entry }];
    }
    if (typeof entry !== 'object' || entry === null) return [];
    const candidate = entry as Record<string, unknown>;
    if (typeof candidate.message !== 'string' || candidate.message.trim() === '') return [];
    return [{
      code: typeof candidate.code === 'string' && candidate.code !== '' ? candidate.code : 'request.failed',
      message: candidate.message,
      ...(typeof candidate.field === 'string' ? { field: candidate.field } : {}),
      ...(typeof candidate.details === 'object' && candidate.details !== null ? { details: candidate.details as Readonly<Record<string, unknown>> } : {}),
    }];
  });
  return errors.length > 0 ? errors : [{ code: 'request.failed', message: `Request failed (HTTP ${httpStatus}).` }];
}
