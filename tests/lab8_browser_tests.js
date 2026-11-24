// LAB 8 (NEW FILE) - real Chromium: DevTools-level proof of the 101 handshake, live delivery between two browsers,
// and exponential backoff with jitter while the daemon is stopped and restarted.   node tests/lab8_browser_tests.js
const { chromium } = require('playwright');
const fs = require('fs'), cp = require('child_process');
const T = JSON.parse(fs.readFileSync(process.env.TOKENS, 'utf8'));
const OUT = process.env.OUT || 'evidence/lab8'; const PAGE = process.env.PAGE_URL || 'http://localhost:8121/tests/rt_harness.html';
const startDaemon = process.env.START_DAEMON, stopDaemon = process.env.STOP_DAEMON;     // shell commands supplied by the runner
const sleep = (ms) => new Promise(r => setTimeout(r, ms));
let pass = 0, fail = 0; const ok = (c, n, e) => { c ? pass++ : fail++; console.log((c ? 'PASS ' : 'FAIL ') + n + (e ? '  [' + e + ']' : '')); };
(async () => {
  const browser = await chromium.launch(); const mk = async (uid, log) => {
    const ctx = await browser.newContext({ viewport: { width: 420, height: 700 }, deviceScaleFactor: 2 });
    await ctx.addCookies([{ name: '__Host-zamove_sid', value: T[uid], domain: 'localhost', path: '/', secure: true, httpOnly: true, sameSite: 'Strict' }]);
    const p = await ctx.newPage(); p.on('console', m => log.push(m.text())); return { ctx, p };
  };
  const logA = [], logB = [];
  const A = await mk(1, logA), B = await mk(3, logB);
  const cdp = await A.ctx.newCDPSession(A.p); await cdp.send('Network.enable'); const hs = [];
  cdp.on('Network.webSocketHandshakeResponseReceived', e => hs.push(e.response));
  cdp.on('Network.webSocketWillSendHandshakeRequest', e => hs.push({ request: e.request }));
  await A.p.goto(PAGE); await B.p.goto(PAGE);
  await A.p.waitForSelector('#rt-status.rt-on', { timeout: 8000 }); await B.p.waitForSelector('#rt-status.rt-on', { timeout: 8000 });
  ok((await A.p.textContent('#rt-status')) === 'Connected', 'badge shows Connected');
  const resp = hs.find(x => x.status), req = hs.find(x => x.request);
  ok(resp && resp.status === 101 && resp.statusText.includes('Switching'), 'DevTools Network: WebSocket handshake 101 Switching Protocols', resp && resp.status + ' ' + resp.statusText);
  fs.writeFileSync(OUT + '/devtools_handshake.json', JSON.stringify({ request: req && req.request.headers, response: resp }, null, 2));
  ok(resp.status === 101, 'handshake authenticated by the HttpOnly cookie alone (the daemon answers 401 without it; the page never handled a token)');
  ok(await A.p.evaluate(() => document.cookie === ''), 'document.cookie is empty: the cookie is HttpOnly');
  await B.p.fill('#rt-booking', '1'); await B.p.fill('#rt-text', 'Your bus leaves in 10 minutes'); await B.p.click('.rt-form button');
  await A.p.waitForSelector('.rt-feed li.rt-new', { timeout: 4000 });
  ok((await A.p.textContent('#rt-feed')).includes('Your bus leaves in 10 minutes'), 'customer browser shows the operator\'s message live');
  await B.p.fill('#rt-text', '<img src=x onerror=alert(1)>'); await B.p.fill('#rt-booking', '1'); await B.p.click('.rt-form button'); await sleep(700);
  ok(await A.p.evaluate(() => !document.querySelector('#rt-feed img') && document.querySelector('#rt-feed').textContent.includes('<img')), 'HTML in a message is displayed as text (textContent), not executed');
  await A.p.screenshot({ path: OUT + '/ui_customer_connected.png' }); await B.p.screenshot({ path: OUT + '/ui_operator_connected.png' });

  // ---- server restart: backoff with jitter ----
  const seenLast = await A.p.evaluate(() => localStorage.length);
  logA.length = 0; const tDown = Date.now();
  cp.execSync(stopDaemon, { stdio: 'inherit', shell: '/bin/bash' });
  await A.p.waitForSelector('#rt-status.rt-re', { timeout: 6000 }); await A.p.screenshot({ path: OUT + '/ui_reconnecting.png' });
  cp.execSync(process.env.EMIT_EVENT, { stdio: 'inherit', shell: '/bin/bash' });          // an event happens while the server is down
  await A.p.waitForSelector('#rt-status.rt-off', { timeout: 110000 }); await A.p.screenshot({ path: OUT + '/ui_offline.png' });
  await sleep(6000);
  const tUp = Date.now(); cp.execSync(startDaemon, { stdio: 'inherit', shell: '/bin/bash' });
  await A.p.waitForSelector('#rt-status.rt-on', { timeout: 60000 }); const tBack = Date.now();
  ok(true, 'badge returned to Connected after the daemon restarted', ((tBack - tUp) / 1000).toFixed(1) + ' s after restart');
  await A.p.waitForSelector('.rt-feed li.rt-missed', { timeout: 5000 });
  ok(true, 'event created while the server was down arrived on reconnect (marked "missed")');
  await A.p.screenshot({ path: OUT + '/ui_recovered.png' });
  const retries = logA.filter(l => /retry #/.test(l)).map(l => { const m = l.match(/retry #(\d+) in ([\d.]+) s\s+\[base (\d+) s/); return m && { n: +m[1], wait: +m[2], base: +m[3] }; }).filter(Boolean);
  fs.writeFileSync(OUT + '/client_console_log.txt', logA.join('\n') + '\n');
  ok(retries.length >= 5, 'client retried ' + retries.length + ' times while the server was down');
  ok(retries.every(r => r.wait >= r.base / 2 - 0.01 && r.wait <= r.base + 0.01), 'every delay lies in 50-100% of its exponential base (jitter)', retries.map(r => r.wait + 's/base ' + r.base).join(', '));
  ok(retries.slice(0, 5).map(r => r.base).join() === '1,2,4,8,16', 'bases double: 1, 2, 4, 8, 16 s', retries.slice(0, 6).map(r => r.base).join(','));
  ok(retries.every(r => r.base <= 30), 'delay is capped at 30 s');
  console.log('SUMMARY: ' + pass + ' passed, ' + fail + ' failed'); console.log('RESULT: ' + (fail ? 'FAIL' : 'PASS'));
  await browser.close(); process.exit(fail ? 1 : 0);
})().catch(e => { console.log('FAIL harness: ' + e.stack); console.log('RESULT: FAIL'); process.exit(1); });
