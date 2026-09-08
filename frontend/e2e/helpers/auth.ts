/**
 * Shared e2e helpers — one implementation of each sign-in flow.
 *
 * Two families exist because they test different things:
 *
 *  - `signInLive`  — real credentials against the real backend
 *    (`POST /api/v1/auth/login` through the Vite proxy). Gated specs use
 *    this; credentials come from env with NO committed fallback.
 *  - `signInMocked` — `page.route` stubs for auth/login + auth/me so the
 *    SPA believes a session exists without any backend. Mocked specs use
 *    this; the password typed is irrelevant and never a real credential.
 *
 * History: the login flow was copy-pasted into every spec with slightly
 * divergent selectors (getByLabel vs input[name="password"], six variants
 * total). This module is the single source of truth.
 */
import { expect, type Page } from '@playwright/test';

export interface MockSession {
  id: number;
  email: string;
  username: string;
  identifier?: string | null;
  is_active: boolean;
  force_reset: boolean;
  permissions: string[];
}

/**
 * Backend origin for specs that set up state via API requests
 * (`request.post`), bypassing the Vite proxy. The dev stack runs PHP on
 * :8090; override when pointing the suite elsewhere.
 */
export function apiOrigin(): string {
  return process.env['SYNAPSE_E2E_API_URL'] ?? 'http://localhost:8090';
}

/**
 * Bearer token for direct API requests (`request` fixture). The API is
 * token-authenticated — cookies are never accepted on api_auth routes —
 * so specs that probe endpoints directly must log in first and pass an
 * Authorization header.
 */
export async function apiToken(email?: string): Promise<string> {
  const res = await fetch(`${apiOrigin()}/api/v1/auth/login`, {
    method: 'POST',
    headers: { 'Content-Type': 'application/json' },
    body: JSON.stringify({ email: email ?? liveEmail(), password: livePassword() }),
  });
  if (!res.ok) {
    throw new Error(`API login failed (${res.status}) for the live e2e account.`);
  }
  const body = (await res.json()) as { data?: { access_token?: string } };
  const token = body.data?.access_token;
  if (!token) {
    throw new Error('API login response carried no access_token.');
  }
  return token;
}

/**
 * Live credentials. There is deliberately NO default: the dev password
 * must not live in the repository (it leaked into 9 specs historically).
 * Set both variables when running the gated (SYNAPSE_E2E=1) suite.
 */
export function liveEmail(): string {
  const value = process.env['SYNAPSE_E2E_EMAIL'];
  if (!value) {
    throw new Error(
      'SYNAPSE_E2E_EMAIL is not set. The live e2e suite takes credentials from the environment — nothing is hardcoded.',
    );
  }
  return value;
}

export function livePassword(): string {
  const value = process.env['SYNAPSE_E2E_PASSWORD'];
  if (!value) {
    throw new Error('SYNAPSE_E2E_PASSWORD is not set.');
  }
  return value;
}

/**
 * The form interaction both flows share. The login field accepts either
 * a university ID number or an email (admins) — the same wire-payload
 * split the SPA performs, so live specs keep using the env email here.
 * `input[name="password"]` is the stable selector — the field is not a
 * labelled textbox in every theme state, and getByLabel(/password/i)
 * once matched the "forgot password" link text.
 */
async function submitLoginForm(page: Page, identifier: string, password: string): Promise<void> {
  await page.getByLabel(/student|employee|number|email/i).fill(identifier);
  await page.locator('input[name="password"]').fill(password);
  await page.getByRole('button', { name: /sign in/i }).click();
}

/**
 * Live sign-in against the real backend. The goto+visible probe is
 * wrapped in toPass because the single-threaded PHP dev server
 * intermittently refuses connections while a previous request drains.
 */
export async function signInLive(page: Page, email?: string): Promise<void> {
  const password = livePassword();
  await expect(async () => {
    await page.goto('/login', { waitUntil: 'domcontentloaded', timeout: 15_000 });
    await expect(page.getByLabel(/student|employee|number|email/i)).toBeVisible({ timeout: 10_000 });
  }).toPass({ timeout: 30_000 });
  await submitLoginForm(page, email ?? liveEmail(), password);
  await page.waitForURL(/\/$/, { timeout: 20_000 });
}

/**
 * Mocked sign-in: stubs the auth endpoints, then submits the real form
 * so the SPA's own login path runs. `mockRefresh` also stubs the
 * cold-load silent refresh (useBootstrapSession) — needed only when a
 * spec asserts on post-login state before manually triggering
 * navigation; leaving it off keeps the pre-login cold-load failure path
 * exercised, which the mocked queue/portal specs rely on.
 */
export async function signInMocked(
  page: Page,
  session: MockSession,
  opts?: { mockRefresh?: boolean },
): Promise<void> {
  const tokenBody = JSON.stringify({
    success: true,
    data: { access_token: 'test-token', expires_in: 900 },
    errors: [],
    meta: null,
  });

  await page.route('**/api/v1/auth/login', (route) =>
    route.fulfill({ contentType: 'application/json', body: tokenBody }));
  if (opts?.mockRefresh === true) {
    await page.route('**/api/v1/auth/refresh', (route) =>
      route.fulfill({ contentType: 'application/json', body: tokenBody }));
  }
  await page.route('**/api/v1/auth/me', (route) =>
    route.fulfill({
      contentType: 'application/json',
      body: JSON.stringify({ success: true, data: session, errors: [], meta: null }),
    }));

  await page.goto('/login');
  await submitLoginForm(page, session.email, 'mock-credentials-not-used');
  await page.waitForURL('/');
}
