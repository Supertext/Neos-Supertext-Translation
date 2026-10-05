#!/usr/bin/env node
/**
 * Stand-in for the Supertext AI file translation API, for tests and docs screenshots.
 *
 * Texts listed in sample-<lang>.json (e.g. sample-fr.json, keyed by the English
 * source) come back translated. Anything else comes back unchanged, or prefixed
 * with "[<target_lang>] " when STAND_IN_PREFIX=1, which makes untranslated text
 * easy to spot in tests. Listens on :8765 (PORT), accepts any API key.
 */
import http from 'node:http';
import { randomBytes } from 'node:crypto';
import { existsSync, readFileSync } from 'node:fs';

const norm = (s) => s.replace(/&apos;|&#39;/g, "'").replace(/&nbsp;/g, ' ').replace(/<br\s*\/?>/g, '<br>').replace(/\s+/g, ' ').trim();
const samples = new Map();
const sampleFor = (lang) => {
  const primary = lang.toLowerCase().split(/[-_]/)[0];
  if (!samples.has(primary)) {
    const file = new URL(`sample-${primary}.json`, import.meta.url);
    const entries = existsSync(file) ? Object.entries(JSON.parse(readFileSync(file))) : [];
    samples.set(primary, new Map(entries.map(([en, tr]) => [norm(en), tr])));
  }
  return samples.get(primary);
};
const files = new Map();
const prefix = process.env.STAND_IN_PREFIX === '1';

http
  .createServer(async (req, res) => {
    const path = new URL(req.url, 'http://x').pathname.replace(/^\/v1/, '');
    const send = (status, body, type = 'application/json') => {
      res.writeHead(status, { 'Content-Type': type });
      res.end(type === 'application/json' ? JSON.stringify(body) : body);
    };
    if (!/^Supertext-Auth-Key \S+$/.test(req.headers.authorization || '')) return send(401, { error: 'missing key' });
    if (path === '/features') return send(200, {});
    if (req.method === 'POST' && path === '/translate/ai/file') {
      const chunks = [];
      for await (const chunk of req) chunks.push(chunk);
      const form = await new Request('http://x', { method: 'POST', headers: req.headers, body: Buffer.concat(chunks) }).formData();
      const id = randomBytes(6).toString('hex');
      files.set(id, { html: await form.get('file').text(), lang: String(form.get('target_lang') || '') });
      console.log(`POST target_lang=${form.get('target_lang')} source_lang=${form.get('source_lang')} politeness=${form.get('politeness') ?? '-'} -> ${id}`);
      return send(200, { file_id: id });
    }
    const match = path.match(/file\/([a-f0-9]+)(\/status|\/translation)?$/);
    if (!match || !files.has(match[1])) return send(404, {});
    if (req.method === 'DELETE') { files.delete(match[1]); return send(200, {}); }
    if (match[2] === '/status') return send(200, { status: 'done' });
    const { html, lang } = files.get(match[1]);
    const sample = sampleFor(lang);
    const out = html.replace(/(<div data-st-id="\d+">)([\s\S]*?)(<\/div>\n)/g, (all, open, inner, close) => {
      const translated = sample.get(norm(inner));
      if (translated !== undefined) return open + translated + close;
      return prefix ? open + inner.replace(/^((?:\s*<[^>]+>)*)/, `$1[${lang}] `) + close : all;
    });
    send(200, out, 'text/html');
  })
  .listen(Number(process.env.PORT || 8765), () => console.log(`Stand-in API on http://127.0.0.1:${process.env.PORT || 8765}/v1/`));
