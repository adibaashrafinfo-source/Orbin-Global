import axios, { type AxiosError, type AxiosInstance } from 'axios';

/** Uniform API error shape: {code, message, fields, request_id} (spec → api.errors). */
export interface ApiErrorBody {
  code: string;
  message: string;
  fields?: Record<string, string[]>;
  context?: Record<string, unknown>;
  request_id?: string;
}

export class ApiError extends Error {
  readonly status: number;
  readonly code: string;
  readonly fields: Record<string, string[]>;
  readonly context: Record<string, unknown>;
  readonly requestId: string | undefined;

  constructor(status: number, body: Partial<ApiErrorBody> | undefined) {
    super(body?.message ?? 'errors.unknown');
    this.status = status;
    this.code = body?.code ?? (status === 0 ? 'NETWORK' : 'HTTP_' + String(status));
    this.fields = body?.fields ?? {};
    this.context = body?.context ?? {};
    this.requestId = body?.request_id;
  }
}

export interface Paginated<T> {
  data: T[];
  meta: { page: number; per_page: number; total: number };
}

export interface Envelope<T> {
  data: T;
}

const CSRF_COOKIE = 'XSRF-TOKEN';

function readCookie(name: string): string | null {
  const m = document.cookie.match(new RegExp('(?:^|; )' + name + '=([^;]*)'));
  return m?.[1] ? decodeURIComponent(m[1]) : null;
}

export const http: AxiosInstance = axios.create({
  baseURL: '/api/v1',
  withCredentials: true,
  withXSRFToken: true,
  xsrfCookieName: CSRF_COOKIE,
  xsrfHeaderName: 'X-XSRF-TOKEN',
  headers: { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
});

let csrfReady: Promise<void> | null = null;
/** Sanctum SPA flow: GET /sanctum/csrf-cookie once before the first mutating request. */
export function ensureCsrf(): Promise<void> {
  if (readCookie(CSRF_COOKIE)) return Promise.resolve();
  csrfReady ??= axios
    .get('/sanctum/csrf-cookie', { withCredentials: true })
    .then(() => undefined)
    .finally(() => {
      csrfReady = null;
    });
  return csrfReady;
}

http.interceptors.request.use(async (config) => {
  const method = (config.method ?? 'get').toLowerCase();
  if (method !== 'get' && method !== 'head') await ensureCsrf();
  return config;
});

http.interceptors.response.use(
  (r) => r,
  (error: AxiosError<ApiErrorBody>) => {
    const status = error.response?.status ?? 0;
    const body = error.response?.data;
    if (status === 401) window.dispatchEvent(new CustomEvent('orbin:unauthenticated'));
    return Promise.reject(new ApiError(status, body));
  },
);

export function newIdempotencyKey(): string {
  return crypto.randomUUID();
}
