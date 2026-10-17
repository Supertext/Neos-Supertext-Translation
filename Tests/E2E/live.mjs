#!/usr/bin/env node
/**
 * End-to-end test against the REAL Supertext API: a fresh demo (no French or Italian
 * content yet) with a real SUPERTEXT_API_KEY. The editor opens the demo pages in the
 * Neos UI, switches to each target language and clicks "Create and copy" (the package
 * translates the new variants in that request), then the content repository is checked:
 * every page and content element has a variant in the target language, in the editor's
 * workspace only (nothing in live), fields are filled and changed, no protocol leftovers,
 * links and formatting kept, the text is in the target language.
 * See "End-to-end tests" in docs/DEVELOPER.md.
 *
 *   BASE_URL=http://127.0.0.1:8081 DATABASE_URL=mysql://root:root@127.0.0.1:3306/neos_live \
 *   DEMO_EDITOR_EMAIL=… DEMO_EDITOR_PASSWORD=… node Tests/E2E/live.mjs
 */
import { chromium } from 'playwright';
import mysql from 'mysql2/promise';
import { mkdirSync } from 'node:fs';
import { fileURLToPath } from 'node:url';

const B = (process.env.BASE_URL ?? 'http://127.0.0.1:8081').replace(/\/$/, '');
const need = (name) => process.env[name] || (() => { throw new Error(`Set ${name}`); })();
const artifacts = fileURLToPath(new URL('./artifacts/', import.meta.url));
mkdirSync(artifacts, { recursive: true });

/** Demo target languages: dimension value, label in the Neos UI. */
const TARGETS = [
	{ value: 'fr', label: 'Français', lang: 'fr' },
	{ value: 'it', label: 'Italiano', lang: 'it' },
];
const SOURCE = { value: 'en_US', label: 'English (US)' };
/** Pages the editor translates ("Create and copy" also creates and translates their parents). */
const PAGES = ['Text & images', 'Multiple columns'];
const DIMENSION_LABELS = /(English \(US\)|English \(UK\)|Deutsch|Français|Italiano)/;

const failures = [];
function check(name, ok, detail = '') {
	console.log(`${ok ? 'ok  ' : 'FAIL'} ${name}${detail ? ` — ${detail}` : ''}`);
	if (!ok) failures.push(`${name}${detail ? ` — ${detail}` : ''}`);
	return ok;
}
const note = (text) => console.log(`note ${text}`);

// Common words, to tell the languages apart. Lower case, whole words.
const STOPWORDS = {
	en: ['the', 'and', 'is', 'are', 'with', 'for', 'you', 'your', 'our', 'of', 'to', 'we', 'who', 'what'],
	de: ['der', 'die', 'das', 'und', 'ist', 'sind', 'mit', 'für', 'sie', 'ihre', 'ihr', 'unser', 'unsere', 'wir', 'eine', 'ein', 'den', 'zu', 'von', 'was'],
	fr: ['le', 'les', 'et', 'est', 'sont', 'avec', 'pour', 'vous', 'votre', 'vos', 'notre', 'nos', 'nous', 'des', 'une', 'un', 'du', 'de', 'qui', 'que'],
	it: ['il', 'lo', 'gli', 'e', 'è', 'sono', 'con', 'per', 'lei', 'sua', 'suo', 'nostro', 'nostra', 'noi', 'una', 'un', 'del', 'della', 'di', 'che', 'chi'],
};
const words = (text) => text.toLowerCase().match(/[\p{L}]+/gu) ?? [];
const score = (text, lang) => words(text).filter((w) => STOPWORDS[lang].includes(w)).length;
const plain = (v) => String(v ?? '').replace(/<[^>]+>/g, ' ').replace(/&nbsp;/g, ' ');
const count = (haystack, needle) => String(haystack ?? '').split(needle).length - 1;
const LEFTOVERS = ['data-st-id', 'data-st-ph', 'st-ph-'];
const KEPT = [['links', '<a '], ['node links', 'node://'], ['asset links', 'asset://'], ['bold', '<strong'], ['bold', '<b>'], ['italics', '<em'], ['italics', '<i>'], ['lists', '<li']];

// --- Content repository (Neos 9, DBAL content graph of the "default" repository) ---------
const db = await mysql.createConnection(need('DATABASE_URL'));
const q = async (sql, params = []) => (await db.query(sql, params))[0];
const T = { node: 'cr_default_p_graph_node', h: 'cr_default_p_graph_hierarchyrelation', dsp: 'cr_default_p_graph_dimensionspacepoints', ws: 'cr_default_p_graph_workspace' };
const json = (v) => (typeof v === 'string' ? JSON.parse(v) : v ?? {});
const isDocument = (n) => /:Document\b/.test(n.nodetypename);
const hidden = (n) => Object.keys(json(n.subtreetags)).length > 0;

async function dspHash(value) {
	const rows = await q(`SELECT hash, dimensionspacepoint FROM ${T.dsp}`);
	return rows.find((r) => json(r.dimensionspacepoint).language === value)?.hash;
}
async function contentStream(workspace) {
	return (await q(`SELECT currentContentStreamId AS cs FROM ${T.ws} WHERE name = ?`, [workspace]))[0]?.cs;
}
/** Nodes visible in a content stream and dimension space point, by aggregate id. */
async function nodesIn(cs, hash, aggregateIds = null) {
	if (!hash || (aggregateIds && !aggregateIds.length)) return [];
	return q(
		`SELECT n.*, h.subtreetags, h.parentnodeanchor FROM ${T.h} h JOIN ${T.node} n ON n.relationanchorpoint = h.childnodeanchor
		 WHERE h.contentstreamid = ? AND h.dimensionspacepointhash = ?${aggregateIds ? ' AND n.nodeaggregateid IN (?)' : ''}`,
		aggregateIds ? [cs, hash, aggregateIds] : [cs, hash],
	);
}
/** Content below a document (not its child documents), all levels. */
async function contentBelow(cs, hash, documentAnchor) {
	const out = [];
	let level = [documentAnchor];
	while (level.length) {
		const rows = await q(
			`SELECT n.*, h.subtreetags FROM ${T.h} h JOIN ${T.node} n ON n.relationanchorpoint = h.childnodeanchor
			 WHERE h.contentstreamid = ? AND h.dimensionspacepointhash = ? AND h.parentnodeanchor IN (?)`,
			[cs, hash, level],
		);
		const content = rows.filter((n) => !isDocument(n));
		out.push(...content);
		level = content.map((n) => n.relationanchorpoint);
	}
	return out;
}
/** Text-like string properties (what an editor reads): titles, or values with spaces or markup. */
function texts(node) {
	const out = {};
	for (const [key, prop] of Object.entries(json(node.properties))) {
		const v = prop?.value;
		if (key === 'uriPathSegment' || typeof v !== 'string' || !/\p{L}/u.test(plain(v))) continue;
		if (/title/i.test(key) || /\s|</.test(v.trim())) out[key] = v;
	}
	return out;
}

// --- Setup -------------------------------------------------------------------------------
const liveCs = await contentStream('live');
const sourceHash = await dspHash(SOURCE.value);
if (!liveCs || !sourceHash) throw new Error('No live workspace or no en_US content: is this a fresh demo?');
for (const t of TARGETS) {
	t.hash = await dspHash(t.value);
	const docs = (await nodesIn(liveCs, t.hash)).filter(isDocument);
	check(`fresh demo: no ${t.label} pages yet`, docs.length === 0, `${docs.length} found`);
}

const browser = await chromium.launch({ executablePath: process.env.CHROMIUM_PATH || undefined });
const page = await browser.newPage({ viewport: { width: 1400, height: 900 }, locale: 'en-US' });
page.setDefaultTimeout(60_000);

// What the package says about each request (TranslatePendingMiddleware's headers).
const translated = [];
const errors = [];
page.on('response', (res) => {
	const h = res.headers();
	if (h['x-supertext-translated']) translated.push(...h['x-supertext-translated'].split(/,\s*/));
	if (h['x-supertext-error']) errors.push(h['x-supertext-error']);
	if (res.status() >= 500 && res.url().startsWith(`${B}/neos`)) errors.push(`HTTP ${res.status()} ${res.url()}`);
});

// --- Log in as the editor ----------------------------------------------------------------
await page.goto(`${B}/neos/login`);
await page.fill('input[type=text]', need('DEMO_EDITOR_EMAIL'));
await page.fill('input[type=password]', need('DEMO_EDITOR_PASSWORD'));
await page.click('button[type=submit]');
const loggedIn = await page.waitForURL(/\/neos\/(?!login)/, { timeout: 60_000 }).then(() => true, () => false);
if (!check('editor can log in (DEMO_EDITOR_*)', loggedIn)) {
	await page.screenshot({ path: `${artifacts}login.png` });
	throw new Error(failures.join('\n'));
}
await page.getByText('Document Tree').first().waitFor();

const content = () => page.frameLocator('iframe[name=neos-content-main]');
const dimensionButton = () => page.getByRole('button', { name: DIMENSION_LABELS }).first();
async function switchLanguage(label) {
	await dimensionButton().click();
	await page.getByText(label, { exact: true }).last().click();
}
const slug = (s) => s.toLowerCase().replace(/[^a-z0-9]+/g, '-');

// --- Translate: "Create and copy" per page and language ---------------------------------
for (const target of TARGETS) {
	for (const title of PAGES) {
		const label = `${target.value}-${slug(title)}`;
		if (!(await dimensionButton().textContent()).includes(SOURCE.label)) await switchLanguage(SOURCE.label);
		await page.getByText(title, { exact: true }).first().click();
		await content().getByRole('heading', { name: title }).first().waitFor();
		await page.waitForTimeout(1000);
		await switchLanguage(target.label);
		const create = page.getByRole('button', { name: 'Create and copy' });
		if (!check(`${label}: Neos offers "Create and copy"`, await create.waitFor({ timeout: 30_000 }).then(() => true, () => false))) {
			await page.screenshot({ path: `${artifacts}${label}-no-create.png` });
			continue;
		}
		const errorsBefore = errors.length;
		const started = Date.now();
		// The request that created the variants answers with X-Supertext-Translated / -Error.
		const answered = page.waitForResponse((r) => r.headers()['x-supertext-translated'] !== undefined || r.headers()['x-supertext-error'] !== undefined, { timeout: 900_000 });
		await create.click();
		const res = await answered.catch(() => null);
		console.log(`${label}: ${Math.round((Date.now() - started) / 1000)} s`);
		check(`${label}: the package answered the request`, res !== null);
		await create.waitFor({ state: 'detached', timeout: 120_000 }).catch(() => {});
		await page.waitForTimeout(3000);
		await page.screenshot({ path: `${artifacts}${label}-result.png` });
		const newErrors = errors.slice(errorsBefore);
		check(`${label}: no errors (X-Supertext-Error, HTTP 5xx)`, newErrors.length === 0, newErrors.join(' | '));
		check(`${label}: still in ${target.label} after translating`, (await dimensionButton().textContent()).includes(target.label));
	}
}
await page.screenshot({ path: `${artifacts}after-translating.png` });
console.log(`X-Supertext-Translated: ${translated.join(', ') || '(none)'}`);
for (const target of TARGETS) {
	const fields = translated.filter((t) => t.split('=')[0].toLowerCase().startsWith(target.lang)).reduce((sum, t) => sum + Number(t.split('=')[1] || 0), 0);
	check(`${target.label}: the package reports translated fields`, fields > 0, `${fields} fields`);
}

// --- Checks in the content repository ----------------------------------------------------
// The editor's workspace: the personal workspace on live whose content stream has the variants.
const workspaces = await q(`SELECT name, currentContentStreamId AS cs FROM ${T.ws} WHERE baseWorkspaceName = 'live'`);

for (const target of TARGETS) {
	const tag = `${target.label} (${target.value})`;
	let ws = null;
	let targetDocs = [];
	for (const w of workspaces) {
		const docs = (await nodesIn(w.cs, target.hash)).filter(isDocument);
		if (docs.length > targetDocs.length) [ws, targetDocs] = [w, docs];
	}
	check(`${tag}: nothing published to live (stays in the editor's workspace)`, (await nodesIn(liveCs, target.hash)).filter(isDocument).length === 0);
	if (!check(`${tag}: translated pages in the editor's workspace`, ws !== null && targetDocs.length > 0, ws ? `${ws.name}: ${targetDocs.map((d) => json(d.properties).title?.value).join(', ')}` : 'none')) continue;
	const sourceDocs = await nodesIn(liveCs, sourceHash, targetDocs.map((d) => d.nodeaggregateid));
	for (const t of PAGES) check(`${tag}: page "${t}" translated`, sourceDocs.some((d) => json(d.properties).title?.value === t));

	let fields = 0;
	let changed = 0;
	let text = '';
	for (const sourceDoc of sourceDocs) {
		const targetDoc = targetDocs.find((d) => d.nodeaggregateid === sourceDoc.nodeaggregateid);
		const docTitle = json(sourceDoc.properties).title?.value ?? sourceDoc.nodeaggregateid;
		const sourceContent = await contentBelow(liveCs, sourceHash, sourceDoc.relationanchorpoint);
		const targetContent = await contentBelow(ws.cs, target.hash, targetDoc.relationanchorpoint);
		const visible = sourceContent.filter((n) => !hidden(n));
		const byId = new Map(targetContent.map((n) => [n.nodeaggregateid, n]));
		const missing = visible.filter((n) => !byId.has(n.nodeaggregateid));
		check(`${tag}: "${docTitle}": every content element has a variant`, missing.length === 0, `${visible.length - missing.length}/${visible.length}${missing.length ? `, missing ${missing.map((n) => n.nodetypename).join(', ')}` : ''}`);
		if (sourceContent.length > visible.length) note(`${tag}: "${docTitle}": ${sourceContent.length - visible.length} hidden element(s) in English not required`);
		check(`${tag}: "${docTitle}": no duplicates`, targetContent.length <= sourceContent.length && new Set(targetContent.map((n) => n.nodeaggregateid)).size === targetContent.length, `${targetContent.length} target / ${sourceContent.length} source nodes`);

		// Field by field: filled, changed, nothing of the HTML document left, markup kept.
		for (const [s, t] of [[sourceDoc, targetDoc], ...visible.filter((n) => byId.has(n.nodeaggregateid)).map((n) => [n, byId.get(n.nodeaggregateid)])]) {
			const st = texts(s);
			const tt = texts(t);
			const what = `${s.nodetypename.replace(/^.*:/, '')}#${s.nodeaggregateid.slice(0, 8)}`;
			check(`${tag}: ${what} is a ${target.value} variant`, t.origindimensionspacepointhash === target.hash);
			for (const [key, value] of Object.entries(st)) {
				fields++;
				const v = String(json(t.properties)[key]?.value ?? '');
				if (!check(`${tag}: ${what}.${key} filled`, plain(v).trim() !== '')) continue;
				if (v !== value) changed++;
				else note(`${tag}: ${what}.${key} unchanged: ${plain(value).trim().slice(0, 60)}`);
				text += ` ${plain(tt[key] ?? v)}`;
				const left = LEFTOVERS.filter((l) => v.includes(l));
				check(`${tag}: ${what}.${key} has no protocol leftovers`, left.length === 0, left.join(', '));
				for (const [name, needle] of KEPT) {
					if (count(value, needle)) check(`${tag}: ${what}.${key} keeps its ${name}`, count(v, needle) === count(value, needle), `${count(v, needle)}/${count(value, needle)}`);
				}
			}
		}
	}
	check(`${tag}: texts were changed by the translation`, fields > 0 && changed / fields >= 0.8, `${changed}/${fields} fields`);
	const scores = Object.fromEntries(Object.keys(STOPWORDS).map((l) => [l, score(text, l)]));
	const best = Object.entries(scores).sort((a, b) => b[1] - a[1])[0][0];
	check(`${tag}: text is in the target language`, best === target.lang, JSON.stringify(scores));
}
note('translating again is not tested: the package has no retranslate action (Neos only offers "Create and copy" for missing variants)');

await browser.close();
await db.end();

if (failures.length) {
	console.error(`\n${failures.length} check(s) failed:\n- ${failures.join('\n- ')}`);
	process.exit(1);
}
console.log('\nAll end-to-end checks passed.');
