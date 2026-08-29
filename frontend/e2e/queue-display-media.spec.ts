import { expect, test, type BrowserContext, type Page } from '@playwright/test';

const queueState = {
  guidance: {
    now_serving: { position: 2, queue_number: 'G-002', display_name: 'Dela Cruz, Ana', patient_school_id: '2024-1001' },
    waiting: Array.from({ length: 18 }, (_, index) => ({ position: index + 3, queue_number: `G-${String(index + 3).padStart(3, '0')}`, display_name: `Guidance Patient ${index + 1}`, patient_school_id: `2024-${1100 + index}`, est_wait_minutes: (index + 1) * 5 })),
  },
  clinic: {
    now_serving: { position: 7, queue_number: 'C-007', display_name: 'Santos, Cara', patient_school_id: '2024-1003' },
    waiting: Array.from({ length: 16 }, (_, index) => ({ position: index + 8, queue_number: `C-${String(index + 8).padStart(3, '0')}`, display_name: `Clinic Patient ${index + 1}`, patient_school_id: `2024-${2100 + index}`, est_wait_minutes: (index + 1) * 4 })),
  },
  updated_at: '2026-08-14 01:00:00',
};

const defaultDisplay = {
  mediaEnabled: true,
  mediaHeight: 'standard',
  transition: 'fade',
  transitionDurationMs: 500,
  defaultItemDurationMs: 2_000,
  background: 'black',
  photoFit: 'contain',
  videoFit: 'contain',
  showCaptions: true,
  showProgress: true,
  waitingTextSize: 'standard',
  autoScroll: false,
  autoScrollSpeed: 24,
};

function settings(playlist: unknown[] = [], display: Record<string, unknown> = {}) {
  return { version: 2, enabled: true, preset: 'bell', volume: 0.5, display: { ...defaultDisplay, ...display }, playlist };
}

function announcement(id: string, order: number, title: string, overrides: Record<string, unknown> = {}) {
  return { id, type: 'announcement', label: title, enabled: true, order, durationMs: 2_000, title, body: `Body for ${title}`, alignment: 'center', fontSize: 'large', foreground: 'light', background: 'brand', emphasis: 'none', ...overrides };
}

async function mockQueue(target: Page | BrowserContext) {
  await target.route('**/api/v1/clinic/queue/state', (route) => route.fulfill({ contentType: 'application/json', body: JSON.stringify({ data: queueState }) }));
}

async function installSettings(page: Page, value: unknown) {
  await page.addInitScript((stored) => localStorage.setItem('synapse_kiosk_settings', JSON.stringify(stored)), value);
}

async function signInAsAdmin(page: Page) {
  const session = { id: 1, email: 'admin@foundationu.edu.ph', username: 'admin', is_active: true, force_reset: false, permissions: ['rbac.manage'] };
  let kioskRevision = 0;
  await page.route('**/api/v1/kiosk-settings', (route) => route.fulfill({ contentType: 'application/json', body: JSON.stringify({ success: true, data: { settings: settings(), revision: 0, updated_at: null }, errors: [], meta: null }) }));
  await page.route('**/api/v1/admin/kiosk-settings', async (route) => {
    const payload = route.request().postDataJSON() as { settings: unknown };
    kioskRevision += 1;
    await route.fulfill({ contentType: 'application/json', body: JSON.stringify({ success: true, data: { settings: payload.settings, revision: kioskRevision, updated_at: '2026-08-15 04:00:00' }, errors: [], meta: null }) });
  });
  await page.route('**/api/v1/auth/login', (route) => route.fulfill({ contentType: 'application/json', body: JSON.stringify({ success: true, data: { access_token: 'test-token', expires_in: 900 }, errors: [], meta: null }) }));
  await page.route('**/api/v1/auth/me', (route) => route.fulfill({ contentType: 'application/json', body: JSON.stringify({ success: true, data: session, errors: [], meta: null }) }));
  await page.goto('/login');
  await page.getByLabel(/email/i).fill(session.email);
  await page.getByRole('textbox', { name: 'Password' }).fill('DevPassw0rd!');
  await page.getByRole('button', { name: /sign in/i }).click();
  await page.waitForURL('/');
  await page.evaluate(() => { window.history.pushState({}, '', '/admin/kiosk-settings'); window.dispatchEvent(new PopStateEvent('popstate')); });
  await expect(page.getByRole('heading', { name: 'Kiosk Settings' })).toBeVisible();
}

test('desktop TV layout stays in the viewport and scrolls each waiting column internally', async ({ page }) => {
  await page.setViewportSize({ width: 1366, height: 768 });
  await mockQueue(page);
  await page.goto('/queue-display');

  await expect(page.getByRole('heading', { name: 'Now Serving' })).toBeVisible();
  await expect(page.getByRole('heading', { name: 'Waiting List' })).toBeVisible();
  await expect(page.getByRole('article', { name: 'Guidance now serving' })).toContainText('G-002');
  await expect(page.getByRole('article', { name: 'Clinic now serving' })).toContainText('C-007');
  await expect(page.getByRole('region', { name: 'Guidance waiting' })).toContainText('G-003');
  await expect(page.getByRole('region', { name: 'Clinic waiting' })).toContainText('C-008');
  await expect(page.getByText('No active playlist item is configured.')).toBeVisible();

  const metrics = await page.evaluate(() => {
    const main = document.querySelector('main')!;
    const aside = document.querySelector('aside')!;
    const media = document.querySelector('[aria-label="Media playlist"]')!;
    const lists = [...document.querySelectorAll('aside ul')];
    return {
      mainOverflow: getComputedStyle(main).overflowY,
      mainBottom: main.getBoundingClientRect().bottom,
      asideBottom: aside.getBoundingClientRect().bottom,
      mediaBottom: media.getBoundingClientRect().bottom,
      viewport: innerHeight,
      lists: lists.map((list) => ({ overflow: getComputedStyle(list).overflowY, scrollHeight: list.scrollHeight, clientHeight: list.clientHeight })),
    };
  });
  expect(metrics.mainOverflow).toBe('hidden');
  expect(metrics.mainBottom).toBeLessThanOrEqual(metrics.viewport + 1);
  expect(metrics.asideBottom).toBeLessThanOrEqual(metrics.viewport + 1);
  expect(Math.abs(metrics.mediaBottom - metrics.asideBottom)).toBeLessThanOrEqual(1);
  expect(metrics.lists).toHaveLength(2);
  for (const list of metrics.lists) {
    expect(list.overflow).toBe('auto');
    expect(list.scrollHeight).toBeGreaterThan(list.clientHeight);
  }
});

test('mobile stacks sections and restores normal document scrolling', async ({ page }) => {
  await page.setViewportSize({ width: 390, height: 844 });
  await mockQueue(page);
  await page.goto('/queue-display');
  await expect(page.getByRole('heading', { name: 'Now Serving' })).toBeVisible();
  const layout = await page.evaluate(() => {
    const main = document.querySelector('main')!;
    const now = document.querySelector('[aria-labelledby="now-serving-heading"]')!;
    const waiting = document.querySelector('aside')!;
    return { overflow: getComputedStyle(main).overflowY, documentHeight: document.documentElement.scrollHeight, nowTop: now.getBoundingClientRect().top, waitingTop: waiting.getBoundingClientRect().top };
  });
  expect(layout.overflow).not.toBe('hidden');
  expect(layout.documentHeight).toBeGreaterThan(844);
  expect(layout.waitingTop).toBeGreaterThan(layout.nowTop);
});

test('serving and media containers keep their shape when their content changes or is empty', async ({ page }) => {
  await page.setViewportSize({ width: 1366, height: 768 });
  let state = queueState;
  await page.route('**/api/v1/clinic/queue/state', (route) => route.fulfill({ contentType: 'application/json', body: JSON.stringify({ data: state }) }));
  const longAnnouncement = announcement('long', 0, 'A campus announcement with a deliberately long heading that must stay inside the fixed display container', {
    body: 'This content is intentionally long. '.repeat(80),
    fontSize: 'hero',
  });
  await installSettings(page, settings([longAnnouncement]));
  await page.goto('/queue-display');

  const guidance = page.getByRole('article', { name: 'Guidance now serving' });
  const clinic = page.getByRole('article', { name: 'Clinic now serving' });
  const media = page.getByRole('region', { name: 'Media playlist' });
  const before = {
    guidance: await guidance.boundingBox(),
    clinic: await clinic.boundingBox(),
    media: await media.boundingBox(),
  };

  state = {
    ...queueState,
    guidance: { ...queueState.guidance, now_serving: null },
    clinic: { ...queueState.clinic, now_serving: null },
  };
  await page.reload();
  await page.evaluate((value) => {
    localStorage.setItem('synapse_kiosk_settings', JSON.stringify(value));
    window.dispatchEvent(new CustomEvent('synapse:kiosk-settings', { detail: value }));
  }, settings([]));
  await expect(guidance).toContainText('No one in service.');
  await expect(clinic).toContainText('No one in service.');
  await expect(page.getByText('No active playlist item is configured.')).toBeVisible();

  const after = {
    guidance: await guidance.boundingBox(),
    clinic: await clinic.boundingBox(),
    media: await media.boundingBox(),
  };
  expect(before.guidance?.height).toBe(after.guidance?.height);
  expect(before.guidance?.width).toBe(after.guidance?.width);
  expect(before.clinic?.height).toBe(after.clinic?.height);
  expect(before.clinic?.width).toBe(after.clinic?.width);
  expect(before.media?.height).toBe(after.media?.height);
  expect(before.media?.width).toBe(after.media?.width);
});

test('rotates active announcements in order and renders entered markup as text', async ({ page }) => {
  await mockQueue(page);
  await installSettings(page, settings([
    announcement('disabled', 0, 'Disabled', { enabled: false }),
    announcement('future', 1, 'Future', { activeFrom: '2099-01-01T00:00:00.000Z' }),
    announcement('first', 2, '<img src=x onerror=alert(1)>', { body: 'Line one\nLine two' }),
    announcement('second', 3, 'Second announcement'),
  ]));
  await page.goto('/queue-display');

  await expect(page.getByRole('heading', { name: '<img src=x onerror=alert(1)>' })).toBeVisible();
  await expect(page.locator('[aria-label="Media playlist"] img')).toHaveCount(0);
  await expect(page.getByText('Line one\nLine two')).toBeVisible();
  await expect(page.getByText('Disabled')).toHaveCount(0);
  await expect(page.getByText('Future')).toHaveCount(0);
  await expect(page.getByRole('heading', { name: 'Second announcement' })).toBeVisible({ timeout: 3_500 });
});

test('rejects unsafe media and skips failed photo and video items', async ({ page }) => {
  await mockQueue(page);
  await installSettings(page, settings([
    { id: 'unsafe', type: 'photo', label: 'Unsafe', enabled: true, order: 0, durationMs: 2_000, source: 'javascript:alert(1)', alt: 'Unsafe', decorative: false, fit: 'contain', focalPosition: 'center', background: 'black' },
    { id: 'photo', type: 'photo', label: 'Broken photo', enabled: true, order: 1, durationMs: 2_000, source: '/missing-photo.png', alt: 'Campus', decorative: false, fit: 'contain', focalPosition: 'center', background: 'black' },
    { id: 'video', type: 'video', label: 'Broken video', enabled: true, order: 2, durationMs: 2_000, source: '/missing-video.mp4', muted: true, loop: false, playbackRate: 1, useNaturalDuration: false, maxDurationMs: 5_000, fit: 'contain' },
    announcement('final', 3, 'Still showing queues'),
  ]));
  await page.route('**/missing-photo.png', (route) => route.abort());
  await page.route('**/missing-video.mp4', (route) => route.abort());
  await page.goto('/queue-display');

  await expect(page.getByText('Photo unavailable')).toBeVisible();
  await expect(page.getByText('Video unavailable')).toBeVisible({ timeout: 4_000 });
  await expect(page.getByRole('heading', { name: 'Still showing queues' })).toBeVisible({ timeout: 4_000 });
  await expect(page.getByRole('article', { name: 'Guidance now serving' })).toContainText('G-002');
  expect(await page.locator('img[src^="javascript:"]').count()).toBe(0);
});

test('cross-tab storage updates the open display and old sound-only settings migrate', async ({ context, page }) => {
  await mockQueue(context);
  await installSettings(page, { enabled: false, preset: 'soft', volume: 0.25 });
  await page.goto('/queue-display');
  await expect(page.getByText('No active playlist item is configured.')).toBeVisible();

  const editor = await context.newPage();
  await editor.goto('/queue-display');
  await editor.evaluate((value) => localStorage.setItem('synapse_kiosk_settings', JSON.stringify(value)), settings([announcement('cross-tab', 0, 'Cross-tab update')]));
  await expect(page.getByRole('heading', { name: 'Cross-tab update' })).toBeVisible();
});

test('reduced motion removes playlist animation and queue-call audio mutes media', async ({ page }) => {
  await page.emulateMedia({ reducedMotion: 'reduce' });
  await mockQueue(page);
  await installSettings(page, settings([
    { id: 'video', type: 'video', label: 'Priority video', enabled: true, order: 0, durationMs: 10_000, source: '/slow-video.mp4', muted: false, loop: false, playbackRate: 1, useNaturalDuration: false, maxDurationMs: 20_000, fit: 'contain' },
  ]));
  await page.route('**/slow-video.mp4', async () => new Promise(() => undefined));
  await page.goto('/queue-display');
  const video = page.locator('video');
  await expect(video).toBeVisible();
  expect(await video.evaluate((element) => getComputedStyle(element.parentElement!).animationName)).toBe('none');
  await page.evaluate(() => window.dispatchEvent(new CustomEvent('synapse:queue-call-media', { detail: { active: true } })));
  await expect.poll(() => video.evaluate((element) => element.muted)).toBe(true);
  await page.evaluate(() => window.dispatchEvent(new CustomEvent('synapse:queue-call-media', { detail: { active: false } })));
  await expect.poll(() => video.evaluate((element) => element.muted)).toBe(false);
});

test('admin migrates sound settings, validates URLs, and confirms playlist reset', async ({ page }) => {
  await installSettings(page, { enabled: false, preset: 'soft', volume: 0.25 });
  await page.route('**/api/v1/admin/kiosk-media**', (route) => route.fulfill({ contentType: 'application/json', body: JSON.stringify({ success: true, data: { items: [], page: 1, limit: 100, total: 0 }, errors: [], meta: null }) }));
  await signInAsAdmin(page);

  await expect(page.getByLabel('Enable call chime')).not.toBeChecked();
  await expect(page.getByLabel(/Soft/)).toBeChecked();
  await expect(page.getByLabel('Volume (0–100%)')).toHaveValue('25');
  await page.getByRole('tab', { name: 'Preview' }).click();
  const tvPreview = page.getByLabel('Complete TV layout preview');
  await expect(tvPreview.getByRole('heading', { name: 'Now Serving' })).toBeVisible();
  await expect(tvPreview.getByText('G-012')).toBeVisible();
  await expect(tvPreview.getByRole('region', { name: 'Media playlist' })).toBeVisible();
  const previewBottoms = await tvPreview.evaluate((preview) => ({
    media: preview.querySelector('[aria-label="Media playlist"]')?.getBoundingClientRect().bottom,
    waiting: preview.querySelector('aside')?.getBoundingClientRect().bottom,
  }));
  expect(Math.abs((previewBottoms.media ?? 0) - (previewBottoms.waiting ?? 0))).toBeLessThanOrEqual(1);

  await page.getByRole('tab', { name: 'Display' }).click();
  await expect(page.locator('select')).toHaveCount(0);
  await page.getByRole('combobox', { name: 'Media height' }).click();
  await expect(page.getByRole('option', { name: 'large', exact: true })).toBeVisible();
  await page.keyboard.press('Escape');

  await page.getByRole('tab', { name: 'Media Playlist' }).click();
  await page.getByRole('button', { name: 'Add photo' }).click();
  await page.getByLabel('Image URL or public asset path').fill('javascript:alert(1)');
  await page.getByRole('button', { name: 'Save' }).evaluate((button: HTMLButtonElement) => button.click());
  await expect(page.getByRole('alert')).toContainText('use an HTTPS or same-origin image URL');

  await page.getByLabel('Image URL or public asset path').fill('/approved-image.png');
  await page.getByRole('button', { name: 'Save' }).evaluate((button: HTMLButtonElement) => button.click());
  await expect(page.getByRole('alert')).toHaveCount(0);

  await page.getByRole('button', { name: 'Add video by URL' }).click();
  await expect(page.getByRole('heading', { name: 'Video playback' })).toBeVisible();
  await expect(page.getByLabel('Use original file duration')).toBeChecked();
  await expect(page.getByText('Plays once at the file’s original duration')).toBeVisible();
  await expect(page.getByLabel('Total loop time (seconds)')).toHaveCount(0);
  await expect(page.getByLabel('Playlist duration (seconds)')).toHaveCount(0);
  await page.getByLabel('Loop video').check();
  await expect(page.getByLabel('Loop video')).toBeChecked();
  await expect(page.getByLabel('Total loop time (seconds)')).toBeVisible();
  await page.getByLabel('Use original file duration').uncheck();
  await expect(page.getByLabel('Playlist duration (seconds)')).toBeVisible();
  await expect(page.getByLabel('Total loop time (seconds)')).toHaveCount(0);

  await page.getByRole('button', { name: 'Reset playlist' }).evaluate((button: HTMLButtonElement) => button.click());
  await expect(page.getByRole('dialog', { name: 'Reset media playlist?' })).toBeVisible();
  await page.getByRole('dialog').getByRole('button', { name: 'Reset playlist' }).evaluate((button: HTMLButtonElement) => button.click());
  await expect(page.getByText('The playlist is empty.')).toBeVisible();
  await expect(page.getByText('Unsaved changes')).toBeVisible();
  await page.getByRole('button', { name: 'Save' }).evaluate((button: HTMLButtonElement) => button.click());
  await expect(page.getByText('Unsaved changes')).toHaveCount(0);
});

test('admin uploads gallery media, adds it to the playlist, and archives recoverably', async ({ page }) => {
  const asset = {
    id: 44,
    public_id: '123e4567-e89b-42d3-a456-426614174000',
    kind: 'photo',
    label: 'Campus welcome',
    original_name: 'welcome.png',
    mime_type: 'image/png',
    size_bytes: 68,
    url: '/api/v1/kiosk-media/123e4567-e89b-42d3-a456-426614174000/content',
    archived: false,
    created_at: '2026-08-14 01:00:00',
  };
  let uploaded = false;
  let archived = false;
  let uploadRequestUrl = '';
  await page.route('**/api/v1/admin/kiosk-media**', async (route) => {
    const request = route.request();
    if (request.method() === 'POST' && request.url().endsWith('/archive')) {
      archived = true;
      return route.fulfill({ contentType: 'application/json', body: JSON.stringify({ success: true, data: { ...asset, archived: true }, errors: [], meta: null }) });
    }
    if (request.method() === 'POST') {
      uploadRequestUrl = request.url();
      uploaded = /multipart\/form-data;\s*boundary=/i.test(request.headers()['content-type'] ?? '') && (request.postDataBuffer()?.length ?? 0) > 0;
      await new Promise((resolve) => setTimeout(resolve, 500));
      // Some deployments have returned an incomplete success body even though
      // the upload was committed. The client must recover it from the gallery.
      return route.fulfill({ contentType: 'application/json', status: 201, body: JSON.stringify({ success: true, data: null, errors: [], meta: null }) });
    }
    const visible = uploaded && (!archived || request.url().includes('include_archived=true'));
    return route.fulfill({ contentType: 'application/json', body: JSON.stringify({ success: true, data: { items: visible ? [{ ...asset, archived }] : [], page: 1, limit: 100, total: visible ? 1 : 0 }, errors: [], meta: null }) });
  });
  await page.route('**/api/v1/kiosk-media/*/content', (route) => route.fulfill({ contentType: 'image/png', body: Buffer.from('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNk+A8AAQUBAScY42YAAAAASUVORK5CYII=', 'base64') }));
  await signInAsAdmin(page);
  await page.getByRole('tab', { name: 'Media Playlist' }).click();
  await page.getByLabel('Search media gallery').fill('old filter');

  await page.getByLabel('Photo or video file').setInputFiles({ name: 'welcome.png', mimeType: 'image/png', buffer: Buffer.from('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNk+A8AAQUBAScY42YAAAAASUVORK5CYII=', 'base64') });
  await page.getByLabel('Gallery label (optional)').fill('Campus welcome');
  await page.getByRole('button', { name: 'Upload' }).click();
  await expect(page.getByRole('status', { name: 'Media upload progress' })).toBeVisible();
  const reportedProgress = Number(await page.getByRole('progressbar', { name: 'File upload' }).getAttribute('aria-valuenow'));
  expect(reportedProgress).toBeGreaterThanOrEqual(0);
  expect(reportedProgress).toBeLessThanOrEqual(100);
  await expect(page.getByText(/transferred|Transfer complete/)).toBeVisible();
  await expect(page.getByText('welcome.png · 1 KB')).toBeVisible();
  await expect(page.getByLabel('Search media gallery')).toHaveValue('');
  await expect(page.getByText('Just uploaded')).toBeVisible();
  await expect(page.getByRole('status', { name: 'Media upload progress' })).toHaveCount(0);
  expect(uploaded).toBe(true);
  expect(new URL(uploadRequestUrl).origin).not.toBe('http://localhost:5173');

  await page.getByRole('button', { name: 'Add to playlist' }).click();
  await expect(page.getByLabel('Image URL or public asset path')).toHaveValue(asset.url);
  await expect(page.getByLabel('Alternative text')).toHaveValue('Campus welcome');

  await page.getByRole('button', { name: 'Archive' }).click();
  await page.getByRole('dialog', { name: 'Archive this media?' }).getByRole('button', { name: 'Archive media' }).click();
  await expect.poll(() => archived).toBe(true);
  await expect(page.getByText('welcome.png · 1 KB')).toHaveCount(0);
  await expect(page.getByLabel('Image URL or public asset path')).toHaveValue(asset.url);
});

test('admin selects multiple media files and filters the organized gallery', async ({ page }) => {
  const photo = {
    id: 71, public_id: '123e4567-e89b-42d3-a456-426614174071', kind: 'photo', label: 'Campus photo', original_name: 'campus.png', mime_type: 'image/png', size_bytes: 68,
    url: '/api/v1/kiosk-media/123e4567-e89b-42d3-a456-426614174071/content', thumbnail_url: '/api/v1/kiosk-media/123e4567-e89b-42d3-a456-426614174071/thumbnail', archived: false, created_at: '2026-08-15 02:00:00',
  };
  const video = {
    id: 72, public_id: '123e4567-e89b-42d3-a456-426614174072', kind: 'video', label: 'Orientation video', original_name: 'orientation.mp4', mime_type: 'video/mp4', size_bytes: 16,
    url: '/api/v1/kiosk-media/123e4567-e89b-42d3-a456-426614174072/content', thumbnail_url: '/api/v1/kiosk-media/123e4567-e89b-42d3-a456-426614174072/thumbnail', archived: false, created_at: '2026-08-15 02:01:00',
  };
  const uploadedAssets: Array<typeof photo | typeof video> = [];
  await page.route('**/api/v1/admin/kiosk-media**', async (route) => {
    if (route.request().method() === 'POST') {
      const body = route.request().postDataBuffer()?.toString('utf8') ?? '';
      const asset = body.includes('orientation.mp4') ? video : photo;
      uploadedAssets.push(asset);
      return route.fulfill({ contentType: 'application/json', status: 201, body: JSON.stringify({ success: true, data: asset, errors: [], meta: null }) });
    }
    return route.fulfill({ contentType: 'application/json', body: JSON.stringify({ success: true, data: { items: uploadedAssets, page: 1, limit: 100, total: uploadedAssets.length }, errors: [], meta: null }) });
  });
  await page.route('**/api/v1/kiosk-media/*/thumbnail', (route) => route.fulfill({ contentType: 'image/png', body: Buffer.from('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNk+A8AAQUBAScY42YAAAAASUVORK5CYII=', 'base64') }));
  await signInAsAdmin(page);
  await page.getByRole('tab', { name: 'Media Playlist' }).click();

  await page.getByLabel('Photo or video file').setInputFiles([
    { name: 'campus.png', mimeType: 'image/png', buffer: Buffer.from('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNk+A8AAQUBAScY42YAAAAASUVORK5CYII=', 'base64') },
    { name: 'orientation.mp4', mimeType: 'video/mp4', buffer: Buffer.from('mock-video-data') },
  ]);
  await expect(page.getByText('2 files selected')).toBeVisible();
  await expect(page.getByLabel('Gallery label (optional)')).toBeDisabled();
  await page.getByRole('button', { name: 'Upload 2 files' }).click();
  await expect(page.getByText('campus.png · 1 KB')).toBeVisible();
  await expect(page.getByText('orientation.mp4 · 1 KB')).toBeVisible();

  await page.getByLabel('Filter by media type').click();
  await page.getByRole('option', { name: 'Videos' }).click();
  await expect(page.getByText('orientation.mp4 · 1 KB')).toBeVisible();
  await expect(page.getByText('campus.png · 1 KB')).toHaveCount(0);

  await page.getByLabel('Filter by archive status').click();
  await page.getByRole('option', { name: 'Archived' }).click();
  await expect(page.getByText('No media matches this gallery view.')).toBeVisible();
  await page.getByRole('button', { name: 'Clear filters' }).click();
  await expect(page.getByText('campus.png · 1 KB')).toBeVisible();
  await expect(page.getByText('orientation.mp4 · 1 KB')).toBeVisible();
});
