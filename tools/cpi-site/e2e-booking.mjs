// Browser checks for the booking page (time zone control) and the manage page (reschedule / cancel).
//   node tools/cpi-site/e2e-booking.mjs      (run build.py first). The API is faked: nothing is ever sent anywhere.
import { chromium } from 'playwright';
import http from 'node:http';
import fs from 'node:fs';
import path from 'node:path';
import { fileURLToPath } from 'node:url';

const root = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '../../marketing-canpatentit');
const out = path.resolve(root, '../tools/cpi-site/.shots');
fs.mkdirSync(out, { recursive: true });
const types = { '.html': 'text/html', '.css': 'text/css', '.js': 'text/javascript', '.json': 'application/json', '.png': 'image/png', '.jpg': 'image/jpeg', '.txt': 'text/plain', '.xml': 'application/xml' };
const server = http.createServer((req, res) => {
  const p = decodeURIComponent(req.url.split('?')[0]);
  let f = path.join(root, p === '/' ? 'index.html' : p);
  if (!fs.existsSync(f) || fs.statSync(f).isDirectory()) { f = path.join(root, p + '.html'); }
  if (!fs.existsSync(f)) { res.writeHead(404); res.end('not found'); return; }
  res.writeHead(200, { 'Content-Type': types[path.extname(f)] || 'application/octet-stream' });
  fs.createReadStream(f).pipe(res);
}).listen(0);
const base = `http://localhost:${server.address().port}`;
let failures = 0;
const check = (ok, msg) => { console.log((ok ? 'PASS ' : 'FAIL ') + msg); if (!ok) { failures++; } };

// Slots in the company zone (IST) as UTC instants: 04:30Z, 05:00Z, 05:30Z on three days starting in three days.
const slots = [];
for (let d = 3; d <= 5; d++) { for (const t of ['04:30', '05:00', '05:30']) { const x = new Date(Date.now() + d * 86400000); slots.push(x.toISOString().slice(0, 10) + 'T' + t + ':00Z'); } }
const startIso = new Date(Date.now() + 6 * 86400000).toISOString().slice(0, 10) + 'T05:00:00Z';
const endIso = new Date(Date.now() + 6 * 86400000).toISOString().slice(0, 10) + 'T05:30:00Z';

async function ctxFor(browser, opts) {
  const ctx = await browser.newContext(opts);
  const page = await ctx.newPage();
  const errors = [], calls = [];
  page.on('pageerror', (e) => errors.push(e.message));
  await page.route(/googletagmanager|challenges\.cloudflare/, (r) => r.abort());
  await page.addInitScript(() => { window.turnstile = { render: (s, o) => { setTimeout(() => o.callback('t'), 20); return 1; }, reset: () => {} }; });
  await page.route('https://login.myipstrategy.com/**', async (route) => {
    const u = route.request().url(), m = route.request().method();
    calls.push({ m, u, body: route.request().postData() });
    const json = (o, s = 200) => route.fulfill({ status: s, contentType: 'application/json', body: JSON.stringify(o) });
    if (u.includes('/public/cpi/range')) { return json({ timezone: 'Asia/Kolkata', slot_minutes: 30, slots }); }
    if (u.includes('/public/cpi/book')) { return json({ message: 'Booked.', start: slots[0] }, 201); }
    if (/\/manage\/12\/goodtoken123$/.test(u)) { return json({ status: 'confirmed', name: 'Asha Rao', start: startIso, end: endIso, timezone: 'America/New_York', company_timezone: 'Asia/Kolkata', can_reschedule: true, reschedule_deadline: startIso, reschedule_hours: 12, join_url: 'https://meet.example.com/room', slot_minutes: 30 }); }
    if (u.includes('/manage/')) {
      if (u.endsWith('/reschedule')) { return json({ message: 'Rescheduled.', start: slots[4], end: slots[4].replace(':00Z', ':30Z') }); }
      if (u.endsWith('/cancel')) { return json({ message: 'Cancelled.' }); }
      return json({ message: 'This link is not valid.' }, 404);
    }
    return json({});
  });
  return { ctx, page, errors, calls };
}

const browser = await chromium.launch({ channel: 'chrome' });

// ---------- booking page ----------
{
  const { ctx, page, errors, calls } = await ctxFor(browser, { viewport: { width: 1280, height: 900 }, timezoneId: 'America/New_York' });
  await page.goto(base + '/book', { waitUntil: 'load' });
  await page.waitForSelector('.cpi-day');
  const txt = await page.locator('#book-tz').textContent();
  check(/America\/New York/.test(txt) && /detected/.test(txt), 'zone auto-detected from the browser: ' + txt.trim());
  const first = await page.locator('.cpi-day').first().textContent();
  await page.locator('.cpi-day').first().click();
  const nySlots = await page.locator('.cpi-slot').allTextContents();
  check(nySlots.length >= 1 && nySlots.every((t) => /AM|PM/.test(t)), 'slots listed in the visitor zone: ' + nySlots.join(', '));
  await page.locator('.cpi-slot').first().click();
  await page.locator('#tz-change').click();
  await page.fill('#tz-input', 'Asia/Tokyo');
  await page.keyboard.press('Enter');
  check((await page.locator('#book-tz').textContent()).includes('Asia/Tokyo'), 'zone can be changed with the picker');
  check(await page.locator('#book-chosen').textContent().then((t) => /Asia\/Tokyo/.test(t)), 'the chosen slot is re-labelled in the new zone');
  await page.locator('#tz-change').click();
  await page.locator('#tz-device').click();
  check((await page.locator('#book-tz').textContent()).includes('America/New York'), '"Use my device time zone" restores the detected zone');
  await page.locator('#tz-change').click(); await page.fill('#tz-input', 'Asia/Tokyo'); await page.keyboard.press('Enter');
  await page.fill('#b-name', 'Asha Rao'); await page.fill('#b-email', 'asha@example.com');
  await page.waitForTimeout(2200);
  await page.locator('#book-submit').click();
  await page.waitForTimeout(500);
  const post = calls.find((c) => c.u.includes('/public/cpi/book'));
  check(post && JSON.parse(post.body).timezone === 'Asia/Tokyo', 'the booking is sent with the chosen zone');
  await page.screenshot({ path: path.join(out, 'book-tz.png'), fullPage: true });
  check(errors.length === 0, 'no page errors on /book ' + JSON.stringify(errors));
  await ctx.close();
}

// ---------- manage page ----------
for (const scheme of ['light', 'dark']) {
  const { ctx, page, errors, calls } = await ctxFor(browser, { viewport: { width: 1100, height: 900 }, timezoneId: 'Asia/Kolkata', colorScheme: scheme });
  await page.goto(base + '/booking/manage#12.goodtoken123', { waitUntil: 'load' });
  await page.waitForSelector('#manage-view:not([hidden])');
  const tile = await page.locator('#manage-tile').textContent();
  check(/New York/.test(await page.locator('#mg-tz').textContent()), scheme + ': shows the booking in the visitor\'s saved zone');
  check(await page.locator('#manage-join a').count() === 1, scheme + ': join button present');
  await page.screenshot({ path: path.join(out, `manage-${scheme}.png`), fullPage: true });
  if (scheme === 'dark') {
    const colors = await page.evaluate(() => { const c = getComputedStyle(document.querySelector('.cpi-tile-date')).color, b = getComputedStyle(document.body).backgroundColor; return [c, b]; });
    const lum = (s) => { const n = s.match(/\d+/g).slice(0, 3).map(Number); return 0.2126 * n[0] + 0.7152 * n[1] + 0.0722 * n[2]; };
    check(Math.abs(lum(colors[0]) - lum(colors[1])) > 90, 'dark: text stays readable against the background ' + colors.join(' on '));
  }
  await page.locator('#m-resched').click();
  await page.waitForSelector('#m-days .cpi-day');
  await page.locator('#m-days .cpi-day').first().click();
  await page.locator('#m-slots .cpi-slot').first().click();
  check(await page.locator('#m-confirm').isEnabled(), scheme + ': confirm enabled after choosing a slot');
  await page.locator('#m-confirm').click();
  await page.waitForSelector('#manage-done:not([hidden])');
  check(calls.some((c) => c.u.endsWith('/reschedule') && JSON.parse(c.body).start), scheme + ': reschedule request sent with the new start');
  check(/moved/i.test(await page.locator('#done-title').textContent()), scheme + ': reschedule confirmation shown');
  check(errors.length === 0, scheme + ': no page errors ' + JSON.stringify(errors));
  await ctx.close();
}
{
  const { ctx, page, calls } = await ctxFor(browser, { viewport: { width: 1100, height: 900 } });
  await page.goto(base + '/booking/manage?action=cancel#12.goodtoken123', { waitUntil: 'load' });
  await page.waitForSelector('#manage-cancel:not([hidden])');
  await page.locator('#m-cancel-yes').click();
  await page.waitForSelector('#manage-done:not([hidden])');
  check(calls.some((c) => c.u.endsWith('/cancel') && c.m === 'POST'), 'cancel link opens the confirm step and cancels with a deliberate click');
  await page.goto(base + '/booking/manage#12.badtoken', { waitUntil: 'load' });
  await page.waitForSelector('#manage-invalid:not([hidden])');
  check(true, 'a wrong link shows the "not valid" page');
  await page.goto(base + '/booking/manage', { waitUntil: 'load' });
  check(await page.locator('#manage-invalid').isVisible(), 'no token in the URL shows the "not valid" page');
  await ctx.close();
}

await browser.close();
server.close();
console.log(failures ? `\n${failures} check(s) FAILED` : '\nall checks passed');
process.exit(failures ? 1 : 0);
