import { expect, test } from '@playwright/test';

test('login shows a persistent reason when credentials are rejected', async ({ page }) => {
  let refreshRequests = 0;
  await page.route('**/api/v1/auth/refresh', (route) => {
    refreshRequests += 1;
    return route.fulfill({
      status: 401,
      contentType: 'application/json',
      body: JSON.stringify({ success: false, data: null, errors: [{ code: 'auth.refresh_missing', message: 'auth.refresh_missing' }], meta: null }),
    });
  });
  await page.route('**/api/v1/auth/login', (route) => route.fulfill({
    status: 401,
    contentType: 'application/json',
    body: JSON.stringify({ success: false, data: null, errors: [{ code: 'auth.credentials_invalid', message: 'auth.credentials_invalid' }], meta: null }),
  }));

  await page.goto('/login');
  await page.getByLabel('Student / Employee number').fill('admin@example.test');
  await page.getByRole('textbox', { name: 'Password' }).fill('WrongPass1!');
  await page.getByRole('button', { name: 'Sign in' }).click();

  const alert = page.getByRole('alert').filter({ hasText: 'Unable to sign in' });
  await expect(alert).toContainText('Email or password is incorrect.');
  await expect(page).toHaveURL('/login');
  expect(refreshRequests).toBe(0);

  await page.getByRole('textbox', { name: 'Password' }).fill('AnotherPass1!');
  await expect(alert).toHaveCount(0);
});
