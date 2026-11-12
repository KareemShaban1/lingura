/**
 * Thin fetch client for the Laravel API.
 * Uses Sanctum SPA authentication: HTTP-only session cookie + XSRF token. No tokens or secrets in JS storage.
 */
export const API_URL = (process.env.NEXT_PUBLIC_API_URL ?? "http://localhost:8000").replace(/\/$/, "");

export class ApiError extends Error {
  constructor(
    public status: number,
    message: string,
    public code?: string,
    public errors?: Record<string, string[]>,
  ) {
    super(message);
  }

  get isUnauthenticated() {
    return this.status === 401 || this.status === 419;
  }
}

function readCookie(name: string): string | null {
  if (typeof document === "undefined") return null;
  const match = document.cookie.split("; ").find((c) => c.startsWith(`${name}=`));
  return match ? decodeURIComponent(match.split("=")[1]) : null;
}

let csrfPromise: Promise<void> | null = null;
async function ensureCsrf() {
  if (readCookie("XSRF-TOKEN")) return;
  csrfPromise ??= fetch(`${API_URL}/sanctum/csrf-cookie`, { credentials: "include" }).then(() => {
    csrfPromise = null;
  });
  await csrfPromise;
}

type Options = { method?: string; body?: unknown; signal?: AbortSignal };

export async function api<T>(path: string, { method = "GET", body, signal }: Options = {}): Promise<T> {
  const mutating = method !== "GET";
  if (mutating) await ensureCsrf();

  const headers: Record<string, string> = { Accept: "application/json" };
  if (body !== undefined) headers["Content-Type"] = "application/json";
  const xsrf = readCookie("XSRF-TOKEN");
  if (xsrf) headers["X-XSRF-TOKEN"] = xsrf;
  if (typeof document !== "undefined") headers["Accept-Language"] = document.documentElement.lang || "en";

  let response: Response;
  try {
    response = await fetch(`${API_URL}/api/v1${path}`, {
      method,
      headers,
      credentials: "include",
      body: body === undefined ? undefined : JSON.stringify(body),
      signal,
    });
  } catch {
    throw new ApiError(0, "We couldn't reach the server. Check your connection and try again.", "network_error");
  }

  if (response.status === 204) return undefined as T;
  const data = await response.json().catch(() => ({}));
  if (!response.ok) {
    throw new ApiError(response.status, data.message ?? "Something went wrong.", data.code, data.errors);
  }
  return data as T;
}

/** Multipart upload (e.g. voice recordings) with the same CSRF/session handling as api(). */
export async function apiUpload<T>(path: string, form: FormData): Promise<T> {
  await ensureCsrf();
  const xsrf = readCookie("XSRF-TOKEN");
  const response = await fetch(`${API_URL}/api/v1${path}`, {
    method: "POST",
    credentials: "include",
    headers: { Accept: "application/json", ...(xsrf ? { "X-XSRF-TOKEN": xsrf } : {}) },
    body: form,
  }).catch(() => {
    throw new ApiError(0, "We couldn't reach the server. Check your connection and try again.", "network_error");
  });
  const data = await response.json().catch(() => ({}));
  if (!response.ok) throw new ApiError(response.status, data.message ?? "Something went wrong.", data.code, data.errors);
  return data as T;
}

/** Applies Laravel validation errors to a react-hook-form instance. */
export function applyServerErrors(
  error: unknown,
  setError: (name: never, e: { type: string; message: string }) => void,
): string | null {
  if (error instanceof ApiError && error.errors) {
    for (const [field, messages] of Object.entries(error.errors)) {
      setError(field as never, { type: "server", message: messages[0] });
    }
    return null;
  }
  return error instanceof Error ? error.message : "Something went wrong.";
}
