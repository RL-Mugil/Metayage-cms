// Browser checks for the interactive pieces of canpatentit.com. Uses the repo's Playwright and the installed Chrome.
//   node tools/cpi-site/e2e.mjs            (builds nothing; run build.py first)
// The contact endpoint is intercepted: nothing is ever sent to a real server.
import { chromium } from 'playwright';
import http from 'node:http';
import fs from 'node:fs';
import path from 'node:path';
import { fileURLToPath } from 'node:url';

const root = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '../../marketing-canpatentit');
const out = process.env.SHOTS || path.resolve(root, '../tools/cpi-site/.shots');
fs.mkdirSync(out, { recursive: true });
const types = { '.html': 'text/html', '.css': 'text/css', '.js': 'text/javascript', '.json': 'application/json', '.png': 'image/png', '.jpg': 'image/jpeg', '.txt': 'text/plain', '.xml': 'application/xml', '.webp': 'image/webp', '.svg': 'image/svg+xml' };
const server = http.createServer((req, res) => {
  let p = decodeURIComponent(req.url.split('?')[0]);
  let f = path.join(root, p === '/' ? 'index.html' : p);
  if (!fs.existsSync(f) || fs.statSync(f).isDirectory()) { f = path.join(root, p + '.html'); }
  if (!fs.existsSync(f)) { res.writeHead(404); res.end('not found'); return; }
  res.writeHead(200, { 'Content-Type': types[path.extname(f)] || 'application/octet-stream' });
  fs.createReadStream(f).pipe(res);
}).listen(0);
const base = `http://localhost:${server.address().port}`;

let failures = 0;
const check = (ok, msg) => { console.log((ok ? 'PASS ' : 'FAIL ') + msg); if (!ok) { failures++; } };

const browser = await chromium.launch({ channel: 'chrome' });
async function session(width, height, tag) {
  const ctx = await browser.newContext({ viewport: { width, height } });
  const page = await ctx.newPage();
  const errors = [];
  page.on('pageerror', (e) => errors.push('pageerror: ' + e.message));
  page.on('console', (m) => { if (m.type() === 'error' && !/turnstile|favicon|Failed to load resource/i.test(m.text())) { errors.push('console: ' + m.text()); } });
  const sent = [];
  await page.route('https://login.myipstrategy.com/**', async (route) => {
    sent.push(JSON.parse(route.request().postData() || '{}'));
    await route.fulfill({ status: 201, contentType: 'application/json', body: '{"message":"ok"}' });
  });
  await page.route(/googletagmanager|challenges\.cloudflare/, (r) => r.abort());
  // Stand-in for Cloudflare Turnstile: hands back a token so the form can be submitted in the test.
  await page.addInitScript(() => { window.turnstile = { render: (sel, o) => { setTimeout(() => o.callback('test-token'), 50); return 1; }, reset: () => {} }; });
  return { ctx, page, errors, sent, tag };
}

// ---------- desktop ----------
{
  const { ctx, page, errors, sent } = await session(1440, 900, 'd');
  await page.goto(base + '/', { waitUntil: 'load' });
  await page.waitForTimeout(600);

  // journey
  await page.locator('#journey').scrollIntoViewIfNeeded();
  check(await page.locator('[data-journey] [role=tab]').count() === 6, 'journey has six stages');
  check(await page.locator('[data-journey] [role=tabpanel]:not([hidden])').count() === 1, 'only one journey panel visible');
  await page.locator('#j-tab-2').click();
  check((await page.locator('[data-journey] [role=tabpanel]:not([hidden]) h3').textContent()).includes('Non-provisional'), 'clicking stage 3 shows non-provisional');
  await page.keyboard.press('ArrowRight');
  check((await page.locator('#j-tab-3').getAttribute('aria-selected')) === 'true', 'arrow key moves to the next stage');
  await page.locator('#j-tab-1').click();
  await page.waitForTimeout(500);
  await page.locator('#journey').screenshot({ path: path.join(out, 'journey.png') });

  // scenarios
  await page.locator('#whats-happening').scrollIntoViewIfNeeded();
  check(await page.locator('.cpi-scn-panel:not([hidden])').count() === 0, 'no scenario open at first');
  await page.locator('[data-scn=demo]').click();
  check(await page.locator('.cpi-scn-panel:not([hidden])').count() === 1, 'scenario opens on tap');
  await page.waitForTimeout(400);
  await page.locator('#whats-happening').screenshot({ path: path.join(out, 'scenarios.png') });

  // myth card
  await page.locator('#myths').scrollIntoViewIfNeeded();
  await page.locator('.cpi-myth').first().click();
  check((await page.locator('.cpi-myth').first().getAttribute('aria-pressed')) === 'true', 'myth card flips');
  await page.waitForTimeout(700);
  await page.locator('#myths').screenshot({ path: path.join(out, 'myths.png') });

  // glossary term tooltip
  const term = page.locator('.cpi-term').first();
  check(await page.locator('.cpi-term').count() > 0, 'glossary terms are wrapped on the home page');
  await term.scrollIntoViewIfNeeded();
  await term.hover();
  check(await page.locator('#cpi-tip:not([hidden])').count() === 1, 'glossary tooltip shows on hover');

  // helper: quick actions then one question
  await page.locator('.cpi-helper-btn').click();
  check(await page.locator('.cpi-helper-action').count() >= 4, 'helper shows quick actions first');
  await page.screenshot({ path: path.join(out, 'helper-menu.png') });
  await page.locator('.cpi-helper-action.primary').click();
  await page.fill('#cpi-helper-panel textarea', 'Do I need a provisional before I demo at a conference?');
  await page.fill('#cpi-helper-panel input[type=email]', 'visitor@example.com');
  await page.waitForTimeout(3300);
  await page.screenshot({ path: path.join(out, 'helper-ask.png') });
  await page.locator('.cpi-helper-send').click();
  await page.waitForTimeout(500);
  check(sent.length === 1 && sent[0].source === 'canpatentit-ask' && sent[0].email === 'visitor@example.com' && !('name' in sent[0]) && sent[0]['cf-turnstile-response'] === 'test-token', 'helper posts only email, question and the human-check token (name omitted)');
  check(sent[0] && sent[0].elapsed_ms >= 3000, 'elapsed time is passed for the anti-bot check');
  check(await page.locator('.cpi-helper-view:not([hidden]) .cpi-helper-hi').first().textContent().then((t) => /Thank you/.test(t)), 'helper shows a thank-you');
  // nothing was sent before the visitor pressed Send
  check(sent.length === 1, 'exactly one request, and only after Send');
  check(errors.length === 0, 'no page errors on home ' + JSON.stringify(errors));
  await ctx.close();
}

// ---------- deadline tools ----------
{
  const { ctx, page, errors } = await session(1280, 900, 'd');
  await page.goto(base + '/tools/deadlines', { waitUntil: 'load' });
  await page.locator('[data-calc=provisional] [data-in]').fill('2026-10-14');
  const t = await page.locator('[data-calc=provisional] .cpi-result.key .cpi-result-date').textContent();
  check(t.includes('October 14, 2027'), 'provisional filed 14 Oct 2026 -> convert by 14 Oct 2027 (' + t + ')');
  await page.locator('[data-calc=publication] [data-in]').fill('2026-10-14');
  check((await page.locator('[data-calc=publication] .cpi-result-date').textContent()).includes('April 14, 2028'), 'publication ~18 months');
  await page.locator('[data-calc=pct] [data-in]').fill('2026-01-31');
  const pct = await page.locator('[data-calc=pct] .cpi-result-date').allTextContents();
  check(pct.length === 2 && pct[0].includes('January 31, 2027') && pct[1].includes('July 31, 2028'), 'PCT 12 and 30 months (' + pct.join(' | ') + ')');
  const [dl] = await Promise.all([page.waitForEvent('download'), page.locator('[data-calc=provisional] .cpi-ghost-btn').first().click()]);
  check(dl.suggestedFilename().endsWith('.ics'), 'add-to-calendar downloads an .ics file');
  await page.waitForTimeout(300);
  await page.screenshot({ path: path.join(out, 'deadlines.png'), fullPage: true });
  check(errors.length === 0, 'no page errors on deadlines ' + JSON.stringify(errors));
  await ctx.close();
}

// ---------- other pages carry the helper and the context strip ----------
for (const p of ['/services/provisional-applications', '/technology/ai-machine-learning', '/glossary', '/about', '/services']) {
  const { ctx, page, errors } = await session(1280, 900, 'd');
  await page.goto(base + p, { waitUntil: 'load' });
  check(await page.locator('.cpi-helper-btn').count() === 1, p + ' has the helper');
  check(await page.locator('.cpi-cta-band [data-helper-open]').count() === 1, p + ' has a context-aware closing strip');
  check(errors.length === 0, p + ' has no page errors ' + JSON.stringify(errors));
  await ctx.close();
}

// ---------- mobile ----------
{
  const { ctx, page, errors } = await session(390, 800, 'm');
  await page.goto(base + '/', { waitUntil: 'load' });
  await page.waitForTimeout(500);
  const overflow = await page.evaluate(() => document.documentElement.scrollWidth - window.innerWidth);
  check(overflow <= 1, 'no horizontal overflow on mobile (' + overflow + 'px)');
  await page.locator('#journey').scrollIntoViewIfNeeded();
  await page.locator('#journey').screenshot({ path: path.join(out, 'm-journey.png') });
  await page.locator('#whats-happening').scrollIntoViewIfNeeded();
  await page.locator('[data-scn=raise]').click();
  await page.locator('#whats-happening').screenshot({ path: path.join(out, 'm-scenarios.png') });
  await page.locator('.cpi-helper-btn').click();
  await page.screenshot({ path: path.join(out, 'm-helper.png') });
  check(errors.length === 0, 'no page errors on mobile ' + JSON.stringify(errors));
  await ctx.close();
}

await browser.close();
server.close();
console.log(failures ? `\n${failures} check(s) FAILED` : '\nall checks passed');
process.exit(failures ? 1 : 0);
