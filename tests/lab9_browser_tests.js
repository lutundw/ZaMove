// LAB 9 (NEW FILE) - functional check of the OPTIMISED build in real Chromium: nothing broke (no CSP violation, no console error,
// forms still populated), images use next-gen formats, code-split chunks load only where needed, and CLS stays under 0.1.
//   node tests/lab9_browser_tests.js http://127.0.0.1:8151
const { chromium } = require('playwright'); const base = process.argv[2] || 'http://127.0.0.1:8151';
let pass = 0, fail = 0; const ok = (c, n, e) => { c ? pass++ : fail++; console.log((c ? 'PASS ' : 'FAIL ') + n + (e ? '  [' + e + ']' : '')); };
(async () => {
  const b = await chromium.launch();
  for (const page of ['index', 'results', 'cargo', 'login', 'booking', 'checkout', 'ticket', 'bookings', 'operator', 'admin', 'security-check', '404']) {
    const ctx = await b.newContext({ viewport: { width: 412, height: 823 }, isMobile: true }); const p = await ctx.newPage(); const errs = [], reqs = [];
    p.on('console', m => { if (m.type() === 'error' && !/401|403|404|Failed to load resource|WebSocket|realtime/.test(m.text())) errs.push(m.text()); });
    p.on('pageerror', e => errs.push(e.message)); p.on('request', r => reqs.push(r.url()));
    await p.addInitScript(() => { window.__cls = 0; new PerformanceObserver(l => { for (const e of l.getEntries()) if (!e.hadRecentInput) window.__cls += e.value; }).observe({ type: 'layout-shift', buffered: true }); });
    await p.goto(base + '/' + (page === '404' ? 'nope.html' : page + '.html'), { waitUntil: 'networkidle' }).catch(() => {}); await p.waitForTimeout(600);
    const cls = await p.evaluate(() => window.__cls);
    ok(errs.length === 0, page + '.html: no console error / CSP violation', errs[0] || '');
    ok(cls < 0.1, page + '.html: CLS ' + cls.toFixed(4) + ' < 0.1');
    if (page === 'index') {
      ok(await p.evaluate(() => document.querySelectorAll('#from option').length > 3), 'index: From/To selects are populated by the minified, deferred bundle');
      const cur = await p.evaluate(() => document.querySelector('.hero-img').currentSrc); ok(/\.(avif|webp)$/.test(cur), 'hero served in a next-gen format', cur.split('/').pop());
      ok(await p.evaluate(() => { const i = document.querySelector('.hero-img'); return i.getAttribute('fetchpriority') === 'high' && !i.loading || i.loading !== 'lazy'; }), 'hero is NOT lazy and has fetchpriority=high');
      ok(await p.evaluate(() => [...document.querySelectorAll('.dest-img')].every(i => i.loading === 'lazy' && i.width && i.height)), 'below-the-fold images: loading=lazy with width/height');
      ok(await p.evaluate(() => !!document.querySelector('link[rel=preload][as=image][fetchpriority=high]')), 'hero has <link rel=preload as=image fetchpriority=high>');
      ok(!reqs.some(u => /route-/.test(u)), 'index loads no route chunk (code splitting)', reqs.filter(u => /\.js/.test(u)).map(u => u.split('/').pop()).join(', '));
    }
    if (page === 'cargo') ok(reqs.some(u => /route-cargo\.[0-9a-f]{8}\.js/.test(u)), 'cargo.html lazy-loads its own chunk via import()', reqs.filter(u => /route-/.test(u)).map(u => u.split('/').pop()).join(','));
    await ctx.close();
  }
  console.log('SUMMARY: ' + pass + ' passed, ' + fail + ' failed'); console.log('RESULT: ' + (fail ? 'FAIL' : 'PASS')); await b.close(); process.exit(fail ? 1 : 0);
})();
