/*
 * Lab 6 browser tests (Playwright + Chromium): DOM-based XSS before/after, CSP enforcement, normal-use pages free of CSP violations.
 *   node tests/lab6_browser_tests.js BEFORE_URL AFTER_URL [AFTER_LOGIN_OK=1]
 *   BEFORE_URL = the Lab 4/5 tree (no encoding, no CSP);  AFTER_URL = the Lab 6 tree (served by tests/router.php).
 * Needs NODE_PATH to contain playwright. Prints RESULT: PASS/FAIL lines and a SUMMARY.
 */
const { chromium } = require('playwright');
const [BEFORE, AFTER] = [process.argv[2], process.argv[3]];
let pass = 0, fail = 0;
const ok = (name, cond, extra = '') => { console.log(`RESULT: ${cond ? 'PASS' : 'FAIL'} (${name})${extra ? ' ' + extra : ''}`); cond ? pass++ : fail++; };

// A user who follows an attacker's link walks the booking flow: the attacker controls ?from=, the passenger name and the e-mail field.
const PAYLOAD = k => `<img src=x onerror=localStorage.setItem('${k}','executed')>`;
async function xssFlow(ctx, base, label) {
  const p = await ctx.newPage();
  const logs = [];
  p.on('console', m => logs.push(m.text()));
  await p.goto(`${base}/results.html?from=${encodeURIComponent(PAYLOAD('xss_from'))}&to=Kitwe&date=2026-10-24&pax=1`);
  await p.evaluate(() => localStorage.clear());
  await p.goto(`${base}/results.html?from=${encodeURIComponent(PAYLOAD('xss_from'))}&to=Kitwe&date=2026-10-24&pax=1`);
  await p.click('a[href^="booking.html?trip=1"]');
  await p.waitForSelector('.seat:not(.t)');
  await p.click('.seat[data-n="1"]');
  await p.fill('.pn', PAYLOAD('xss_name'));
  await p.fill('#email', PAYLOAD('xss_email'));
  await p.fill('#phone', '0971234567');
  await p.click('#go');
  await p.waitForURL('**/checkout.html');
  await p.waitForTimeout(500);
  const afterCheckout = await p.evaluate(() => ({ from: localStorage.getItem('xss_from'), sumText: document.querySelector('#sum').textContent, imgs: document.querySelectorAll('#sum img').length }));
  await p.fill('#ph', '0971234567');
  await p.click('#pay button');
  await p.waitForURL('**/ticket.html', { timeout: 8000 });
  await p.waitForTimeout(500);
  const ticket = await p.evaluate(() => ({ name: localStorage.getItem('xss_name'), email: localStorage.getItem('xss_email'), imgs: document.querySelectorAll('#tk img').length, text: document.querySelector('#tk').textContent }));
  await p.screenshot({ path: `${process.env.SHOTS || '/tmp'}/xss_${label}_ticket.png` });
  await p.close();
  return { afterCheckout, ticket, logs };
}

(async () => {
  const b = await chromium.launch({ channel: 'chromium' });
  console.log('=== A03 DOM-based XSS: attacker-controlled ?from=, passenger name and e-mail flow into innerHTML');
  let ctx = await b.newContext({ viewport: { width: 1280, height: 900 } });
  const before = await xssFlow(ctx, BEFORE, 'before');
  ok('BEFORE (Lab 4/5 code): script from ?from= EXECUTED on checkout.html', before.afterCheckout.from === 'executed');
  ok('BEFORE: script from the passenger name EXECUTED on ticket.html', before.ticket.name === 'executed');
  ok('BEFORE: script from the e-mail field EXECUTED on ticket.html', before.ticket.email === 'executed');
  await ctx.close();

  ctx = await b.newContext({ viewport: { width: 1280, height: 900 } });
  const after = await xssFlow(ctx, AFTER, 'after');
  ok('AFTER (Lab 6 code): ?from= payload did NOT execute', after.afterCheckout.from === null);
  ok('AFTER: no <img> element was created from the payload on checkout.html', after.afterCheckout.imgs === 0);
  ok('AFTER: the payload is displayed as harmless text', after.afterCheckout.sumText.includes('<img src=x onerror='));
  ok('AFTER: passenger-name payload did NOT execute on ticket.html', after.ticket.name === null);
  ok('AFTER: e-mail payload did NOT execute on ticket.html', after.ticket.email === null);
  ok('AFTER: no <img> element on the ticket and the text shows the payload literally', after.ticket.imgs === 0 && after.ticket.text.includes('onerror='));
  await ctx.close();

  console.log('=== A05 CSP enforcement (encoding bypassed on purpose: markup injected straight into the page)');
  ctx = await b.newContext({ viewport: { width: 1280, height: 900 } });
  const p = await ctx.newPage();
  const violations = [], consoleMsgs = [];
  p.on('console', m => consoleMsgs.push(m.text()));
  await p.addInitScript(() => { window.__v = []; document.addEventListener('securitypolicyviolation', e => window.__v.push(e.violatedDirective + '|' + e.blockedURI)); });
  await p.goto(`${AFTER}/index.html`);
  await p.evaluate(() => localStorage.clear());
  await p.evaluate(() => {
    document.body.insertAdjacentHTML('beforeend', "<img src=x onerror=\"localStorage.setItem('csp_img','executed')\">");
    const s = document.createElement('script'); s.textContent = "localStorage.setItem('csp_inline','executed')"; document.body.appendChild(s);
    const s2 = document.createElement('script'); s2.nonce = 'dGhpcy1pcy1ub3QtdGhlLW5vbmNl'; s2.textContent = "localStorage.setItem('csp_badnonce','executed')"; document.body.appendChild(s2);
    const a = document.createElement('a'); a.href = "javascript:localStorage.setItem('csp_jsurl','executed')"; document.body.appendChild(a); a.click();
    const st = document.createElement('style'); st.textContent = 'body{background:red}'; document.head.appendChild(st);
  });
  await p.waitForTimeout(600);
  const probe = await p.evaluate(() => ({ img: localStorage.getItem('csp_img'), inline: localStorage.getItem('csp_inline'), bad: localStorage.getItem('csp_badnonce'), js: localStorage.getItem('csp_jsurl'), v: window.__v, bg: getComputedStyle(document.body).backgroundColor }));
  ok('injected <img onerror=...> handler blocked', probe.img === null);
  ok('injected inline <script> blocked', probe.inline === null);
  ok('inline <script> with a WRONG nonce blocked', probe.bad === null);
  ok('javascript: URL blocked', probe.js === null);
  ok('injected inline <style> blocked (style-src has no unsafe-inline)', probe.bg !== 'rgb(255, 0, 0)');
  ok(`browser reported ${probe.v.length} CSP violations (securitypolicyviolation events)`, probe.v.length >= 5, JSON.stringify(probe.v));
  ok('console shows "Refused to execute inline script" / "Refused to apply inline style"', consoleMsgs.some(m => /Refused to execute inline/.test(m)) && consoleMsgs.some(m => /Refused to apply inline style/.test(m)));
  consoleMsgs.filter(m => /Refused/.test(m)).slice(0, 5).forEach(m => console.log('  console: ' + m.slice(0, 200)));
  await p.screenshot({ path: `${process.env.SHOTS || '/tmp'}/csp_blocked_index.png` });
  await ctx.close();

  console.log('=== Normal use of every page under the strict CSP: no violations, no page errors');
  ctx = await b.newContext({ viewport: { width: 1280, height: 900 } });
  const q = await ctx.newPage(); const bad = [];
  q.on('console', m => { if (/Refused|Content Security Policy/i.test(m.text())) bad.push(m.text()); });
  q.on('pageerror', e => bad.push('pageerror ' + e.message));
  await q.addInitScript(() => document.addEventListener('securitypolicyviolation', e => console.log('Refused (event) ' + e.violatedDirective)));
  for (const pg of ['index', 'results', 'cargo', 'login', '404', 'security-check']) { await q.goto(`${AFTER}/${pg}.html`); await q.waitForTimeout(300); }
  await q.goto(`${AFTER}/index.html`); await q.goto(`${AFTER}/results.html?from=Lusaka&to=Kitwe&date=2026-10-24&pax=1`);
  await q.click('a[href^="booking.html?trip=1"]'); await q.waitForSelector('.seat:not(.t)'); await q.click('.seat[data-n="1"]');
  await q.fill('.pn', 'Test Passenger'); await q.fill('#email', 'test@example.com'); await q.fill('#phone', '0971234567'); await q.click('#go');
  await q.waitForURL('**/checkout.html'); await q.fill('#ph', '0971234567'); await q.click('#pay button'); await q.waitForURL('**/ticket.html', { timeout: 8000 });
  const tk = await q.textContent('#tk');
  ok('purchase flow index -> results -> booking -> checkout -> ticket works', tk.includes('Test Passenger') && tk.includes('test@example.com'));
  await q.goto(`${AFTER}/bookings.html`);   // not signed in: server answers 302 to login.html
  ok('bookings.html without a session lands on login.html (server-side gate)', /login\.html/.test(q.url()));
  if (process.argv[4] === '1') {
    await q.fill('#login-email', 'alice@lab6.test'); await q.fill('#login-password', 'Lab6TestPass1');
    await Promise.all([q.waitForURL('**/bookings.html'), q.click('#login-submit')]);
    await q.waitForFunction(() => document.documentElement.style.visibility !== 'hidden');
    ok('customer logs in through the UI and reaches bookings.html (guard.js + server gate agree)', true);
    await q.goto(`${AFTER}/admin.html`); await q.waitForTimeout(500);
    ok('the same customer asking for admin.html is sent back to bookings.html', /bookings\.html/.test(q.url()));
  }
  ok('no CSP violation and no page error during normal use', bad.length === 0, bad.length ? JSON.stringify(bad.slice(0, 3)) : '');
  await q.close(); await ctx.close();

  console.log('=== Allow-list sanitiser and encoder in js/safe.js');
  ctx = await b.newContext(); const s = await ctx.newPage(); await s.goto(`${AFTER}/index.html`);
  const r = await s.evaluate(() => {
    const dirty = '<img src=x onerror=alert(1)><script>alert(1)<\/script><a href="javascript:alert(1)" onclick="x()">link</a><b style="x:y">ok</b><iframe src=//evil></iframe><input type=file><!-- c -->';
    return { clean: Safe.sanitizeHTML(dirty), esc: String(Safe.html`<b title="${'" onmouseover="x'}">${'<script>1</script>'}</b>`), nested: String(Safe.html`<ul>${[1, 2].map(n => Safe.html`<li>${n}</li>`)}</ul>`) };
  });
  console.log('  sanitizeHTML ->', r.clean); console.log('  html`` ->', r.esc);
  ok('sanitizer removed script, img/onerror, javascript: href, onclick, style, iframe, file input, comments', !/script|onerror|javascript|onclick|style|iframe|type="file"|<!--/.test(r.clean) && r.clean.includes('<b>ok</b>') && r.clean.includes('link</a>'));
  ok('html`` tag escapes quotes and angle brackets in attribute and element context', !r.esc.includes('<script>') && r.esc.includes('&quot; onmouseover=&quot;x') && r.esc.includes('&lt;script&gt;'));
  ok('html`` keeps nested html`` markup and joins arrays', r.nested === '<ul><li>1</li><li>2</li></ul>');
  await b.close();
  console.log(`SUMMARY: ${pass} passed, ${fail} failed`);
  process.exit(fail ? 1 : 0);
})().catch(e => { console.error('ERR', e); process.exit(2); });
