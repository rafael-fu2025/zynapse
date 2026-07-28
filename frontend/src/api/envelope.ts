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

  constructor(httpStatus: number, errors: ApiError[]) {
    super(errors[0]?.message ?? `HTTP ${httpStatus}`);
    this.name = 'ApiEnvelopeError';
    this.httpStatus = httpStatus;
    this.errors = errors;
  }
}