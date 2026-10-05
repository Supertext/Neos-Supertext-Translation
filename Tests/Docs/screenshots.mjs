#!/usr/bin/env node
/**
 * Regenerates docs/images from a freshly started local Neos demo whose package talks
 * to stand-in.mjs (SUPERTEXT_API_ENDPOINT=http://127.0.0.1:8765/v1/), so the Italian
 * texts are real translations from sample-it.json. See docs/DEVELOPER.md -> Docs screenshots.
 *
 *   BASE_URL (default http://127.0.0.1:8097)
 *   DEMO_EDITOR_EMAIL / DEMO_EDITOR_PASSWORD  editor account (translates and publishes)
 *   DEMO_ADMIN_EMAIL / DEMO_ADMIN_PASSWORD    admin account (configuration module)
 *   CHROMIUM_PATH                             optional Chromium binary
 *
 * The demo must not have Italian content yet (fresh database), because the flow
 * creates "Text & images" in Italian.
 */
import { chromium } from 'playwright';

const B = process.env.BASE_URL || 'http://127.0.0.1:8097';
const EDITOR = [process.env.DEMO_EDITOR_EMAIL || 'editor@example.com', process.env.DEMO_EDITOR_PASSWORD || 'Docs12345!'];
const ADMIN = [process.env.DEMO_ADMIN_EMAIL || 'admin@example.com', process.env.DEMO_ADMIN_PASSWORD || 'Docs12345!'];
const PAGE = 'Text & images';
const OUT = new URL('../../docs/images', import.meta.url).pathname;

const browser = await chromium.launch({ executablePath: process.env.CHROMIUM_PATH || undefined });
const pad = (r, p = 8) => ({ x: Math.max(0, r.x - p), y: Math.max(0, r.y - p), width: r.width + 2 * p, height: r.height + 2 * p });
const union = (...rs) => {
  const x = Math.min(...rs.map((r) => r.x)), y = Math.min(...rs.map((r) => r.y));
  return { x, y, width: Math.max(...rs.map((r) => r.x + r.width)) - x, height: Math.max(...rs.map((r) => r.y + r.height)) - y };
};

async function login([user, password]) {
  const context = await browser.newContext({ viewport: { width: 1400, height: 900 } });
  const page = await context.newPage();
  await page.goto(`${B}/neos/login`);
  await page.fill('input[type=text]', user);
  await page.fill('input[type=password]', password);
  await page.click('button[type=submit]');
  await page.waitForURL(/\/neos\//, { timeout: 60000 });
  return page;
}
const shot = (page, name, clip) => page.screenshot({ path: `${OUT}/${name}.png`, ...(clip ? { clip } : {}) });

// --- User guide (editor) ----------------------------------------------------
const page = await login(EDITOR);
await page.getByText('Document Tree').first().waitFor({ timeout: 60000 });
await page.getByText(PAGE, { exact: true }).first().click();
await page.frameLocator('iframe[name=neos-content-main]').getByRole('heading', { name: PAGE }).first().waitFor({ timeout: 30000 });
await page.waitForTimeout(1500);

const languageButton = page.getByRole('button', { name: /English \(US\)/ }).first();
await languageButton.click();
await page.getByText('Italiano', { exact: true }).last().waitFor();
await page.waitForTimeout(500);
{
  const menu = await page.getByText('Italiano', { exact: true }).last().locator('xpath=ancestor::*[.//*[text()="Deutsch"]][1]').boundingBox();
  await shot(page, 'language-menu', pad(union(await languageButton.boundingBox(), menu), 12));
}

await page.getByText('Italiano', { exact: true }).last().click();
await page.getByRole('button', { name: 'Create and copy' }).waitFor({ timeout: 30000 });
await page.waitForTimeout(800);
await shot(page, 'create-and-copy', pad(await page.getByText('Start with an empty or pre-filled document?').locator('xpath=ancestor::*[.//button[normalize-space()="Create and copy"]][1]').boundingBox(), 10));

await page.getByRole('button', { name: 'Create and copy' }).click();
await page.frameLocator('iframe[name=neos-content-main]').getByRole('heading', { name: 'Testo e immagini' }).first().waitFor({ timeout: 120000 });
await page.waitForTimeout(2500);
await shot(page, 'translated-page');

const publish = page.getByRole('button', { name: /Publish to/ }).first();
await shot(page, 'publish-button', pad(await publish.boundingBox(), 6));
await publish.click();
// The first page in a new language also created its parent pages there, so Neos asks
// to publish all changes in the site instead of only this page.
const publishAll = page.locator('button', { hasText: /publish all changes in site/i }).first();
const outcome = await Promise.race([
  publishAll.waitFor({ timeout: 60000 }).then(() => 'dependencies'),
  page.getByText(/^Published/).first().waitFor({ timeout: 60000 }).then(() => 'published'),
]);
if (outcome === 'dependencies') {
  await page.waitForTimeout(600);
  await shot(page, 'publish-dependencies', pad(await page.getByText(/Could not publish changes in document/).locator('xpath=ancestor::*[.//button[contains(., "publish all changes")]][1]').boundingBox(), 10));
  await publishAll.click();
}
// Then "Yes, publish" and a final result dialog; click through until the workspace is clean.
for (let i = 0; i < 10 && !(await page.getByText(/^Published/).first().isVisible().catch(() => false)); i++) {
  const next = page.locator('[role=dialog] button, [class*=dialog] button').filter({ hasText: /^(Yes, publish|OK|Ok|Close)$/ }).first();
  if (await next.isVisible().catch(() => false)) await next.click();
  await page.waitForTimeout(1500);
}
await page.getByText(/^Published/).first().waitFor({ timeout: 60000 });
await page.waitForTimeout(1500);

// Public website in Italian
const site = await page.context().newPage();
await site.goto(`${B}/it`);
await site.getByRole('link', { name: 'Funzionalità' }).first().waitFor({ timeout: 30000 });
const href = await site.locator('a', { hasText: 'Testo e immagini' }).first().getAttribute('href').catch(() => null);
await site.goto(href ? new URL(href, B).toString() : `${B}/it`);
await site.waitForTimeout(1500);
await shot(site, 'website-italian', { x: 0, y: 0, width: 1400, height: 760 });

// --- Installation guide (admin) ---------------------------------------------
const admin = await login(ADMIN);
// Administration > Configuration > Settings, expanded down to the given settings path.
async function configuration(path, name, expand = []) {
  await admin.goto(`${B}/neos/administration/configuration`);
  await admin.getByRole('link', { name: 'Settings', exact: true }).first().click();
  await admin.waitForLoadState('networkidle');
  const parts = path.split('.');
  let node;
  for (let n = 1; n <= parts.length; n++) {
    node = admin.locator(`li.neos-tree-folder[title="${parts.slice(0, n).join('.')}"]`).first();
    await node.locator(':scope > .neos-tree-expander').click();
    await admin.waitForTimeout(250);
  }
  for (const sub of expand) {
    await admin.locator(`li.neos-tree-folder[title="${path}.${sub}"] > .neos-tree-expander`).first().click();
    await admin.waitForTimeout(250);
  }
  await admin.waitForTimeout(500);
  const box = await node.boundingBox();
  await shot(admin, name, pad({ x: box.x, y: box.y, width: Math.min(1000, box.width), height: Math.min(700, box.height) }, 12));
}
await configuration('Supertext.NeosTranslation', 'configuration-supertext', ['languages', 'languages.de', 'languages.fr', 'languages.it']);
await configuration('Neos.ContentRepositoryRegistry.contentRepositories.default.contentDimensions.language.values', 'configuration-languages', ['fr', 'it']);

await browser.close();
console.log(`Screenshots written to ${OUT}`);
