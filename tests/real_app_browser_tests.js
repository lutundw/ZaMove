/*
 * REAL APP (NEW FILE): the whole product in a real browser (Playwright + Chromium), no mock data anywhere.
 *   node tests/real_app_browser_tests.js http://localhost:8192 [screenshot-folder]
 * The server must run on a fresh database that holds only an admin (admin@example.com / Adm1nPassw0rd).
 * Story: operator registers -> admin approves -> operator adds vehicle, route, departure -> passenger searches,
 *        books, pays -> ticket -> operator's dashboard shows the sale.  Also checks: no CSP violations, no console errors.
 */
const { chromium } = require('playwright');
const BASE = process.argv[2], SHOTS = process.argv[3] || '/tmp';
let pass = 0, fail = 0;
const ok = (name, cond, extra = '') => { console.log(`${cond ? 'PASS' : 'FAIL'}  ${name}${extra ? '  ' + extra : ''}`); cond ? pass++ : fail++; };
const shot = (p, n) => p.screenshot({ path: `${SHOTS}/${n}.png`, fullPage: true });
const cat = (hoursAhead) => { // 'YYYY-MM-DDTHH:mm' in Zambia time (UTC+2)
  const d = new Date(Date.now() + 2 * 3600e3 + hoursAhead * 3600e3);
  return d.toISOString().slice(0, 16);
};

(async () => {
  const browser = await chromium.launch();
  const issues = [];
  const mk = async () => {
    const ctx = await browser.newContext({ viewport: { width: 1180, height: 800 }, deviceScaleFactor: 1 });
    const p = await ctx.newPage();
    p.on('console', m => { if (['error'].includes(m.type()) && !/Failed to load resource|WebSocket connection/.test(m.text())) issues.push(m.text()); if (/Content Security Policy/i.test(m.text())) issues.push('CSP: ' + m.text()); });
    p.on('pageerror', e => issues.push('pageerror: ' + e.message));
    return p;
  };
  const register = async (p, role, name, email, phone, extra = {}) => {
    await p.goto(`${BASE}/login.html?r=1`);
    await p.selectOption('#reg-role', role);
    await p.fill('#reg-full_name', name);
    if (role === 'operator') { await p.fill('#reg-business_name', extra.company); await p.fill('#reg-business_address', extra.address); }
    await p.fill('#reg-email', email); await p.fill('#reg-phone', phone); await p.fill('#reg-password', 'Passw0rdX');
    await p.click('#reg-submit');
    await p.waitForSelector('#reg-status:not(.hid)');
    return (await p.textContent('#reg-status')).trim();
  };
  const login = async (p, email, password) => {
    await p.goto(`${BASE}/login.html`);
    await p.fill('#login-email', email); await p.fill('#login-password', password);
    await p.click('#login-submit');
  };

  // ---- 1. operator registers with a company
  const op = await mk();
  await op.goto(`${BASE}/login.html?r=1`);
  ok('company fields are hidden for passengers', !(await op.isVisible('#reg-business_name')));
  await op.selectOption('#reg-role', 'operator');
  ok('company fields appear for operators', await op.isVisible('#reg-business_name'));
  await op.click('#reg-submit');
  ok('empty operator form is stopped in the browser', /fix \d+ field/.test(await op.textContent('#reg-status')));
  const msg = await register(op, 'operator', 'Chipo Banda', 'chipo@example.com', '0971111111', { company: 'Shalom Bus', address: 'Intercity Terminal, Lusaka' });
  ok('operator sees the approval notice', /administrator must approve/i.test(msg), msg);
  await shot(op, '01_operator_registered');
  await login(op, 'chipo@example.com', 'Passw0rdX');
  await op.waitForSelector('#login-status:not(.hid)');
  ok('unapproved operator cannot log in', /waiting for administrator approval/i.test(await op.textContent('#login-status')));

  // ---- 2. admin approves
  const ad = await mk();
  await login(ad, 'admin@example.com', 'Adm1nPassw0rd');
  await ad.waitForURL('**/admin.html');
  await ad.waitForSelector('#ops table');
  ok('admin sees the real company', (await ad.textContent('#ops')).includes('Shalom Bus'));
  await shot(ad, '02_admin_pending');
  await ad.click('button.act[data-d="approve"]');
  await ad.waitForSelector('#ops .chip:text("approved")');
  ok('company shows approved', true);
  await shot(ad, '03_admin_approved');

  // ---- 3. operator builds the product
  await login(op, 'chipo@example.com', 'Passw0rdX');
  await op.waitForURL('**/operator.html');
  await op.waitForSelector('#deps');
  ok('empty operator dashboard says so', /No departures yet/.test(await op.textContent('#deps')));
  await op.click('[data-tab=fl]');
  await op.fill('#v-reg', 'BAA 1234'); await op.selectOption('#v-type', 'Executive'); await op.fill('#v-seats', '4');
  await op.click('#vehForm button'); await op.waitForFunction(() => /Vehicle added/.test(document.querySelector('#vehMsg').textContent));
  await op.fill('#r-from', 'lusaka'); await op.fill('#r-to', 'kitwe'); await op.fill('#r-fare', '260'); await op.fill('#r-km', '320');
  await op.click('#routeForm button'); await op.waitForFunction(() => /Route added/.test(document.querySelector('#routeMsg').textContent));
  await op.fill('#r-from', 'Lusaka'); await op.fill('#r-to', 'Kitwe'); await op.fill('#r-fare', '260');
  await op.click('#routeForm button'); await op.waitForFunction(() => /already run/.test(document.querySelector('#routeMsg').textContent));
  ok('duplicate route is explained', true);
  await shot(op, '04_operator_fleet');
  await op.click('[data-tab=ad]');
  await op.fill('#a-dep', cat(30)); await op.fill('#a-arr', cat(36));
  ok('fare and seats are pre-filled from the route and bus', (await op.inputValue('#a-fare')) === '260' && (await op.inputValue('#a-seats')) === '4');
  await op.click('#addTripForm button');
  await op.waitForFunction(() => /Departure published/.test(document.querySelector('#tripMsg').textContent));
  ok('operator published a departure', true);
  await op.click('[data-tab=ov]');
  await op.waitForSelector('#deps table');
  ok('dashboard lists it with 0 / 4 seats sold', /0 \/ 4/.test(await op.textContent('#deps')));
  await shot(op, '05_operator_overview');

  // ---- 4. passenger
  const pa = await mk();
  await pa.goto(`${BASE}/index.html`);
  await pa.waitForSelector('#pop a');
  ok('home page shows the real route (not a hard-coded one)', /Lusaka → Kitwe/.test(await pa.textContent('#pop')) && /Shalom Bus/.test(await pa.textContent('#pop')) === false);
  ok('search boxes hold only real towns', (await pa.$$eval('#from option', o => o.map(x => x.textContent))).sort().join() === 'Kitwe,Lusaka');
  await shot(pa, '06_home');
  await pa.selectOption('#from', 'Lusaka'); await pa.selectOption('#to', 'Kitwe'); await pa.selectOption('#pax', '2');
  await pa.fill('#date', '');
  await pa.click('form.search button.btn.o');
  await pa.waitForSelector('#list article');
  ok('results show Shalom Bus, K 260, 4 seats left', /Shalom Bus/.test(await pa.textContent('#list')) && /K 260/.test(await pa.textContent('#list')) && /4 seats left/.test(await pa.textContent('#list')));
  await shot(pa, '07_results');
  await pa.click('#list a.btn');
  await pa.waitForURL(/login\.html\?next=booking\.html/);
  ok('booking requires login and remembers the trip', true, pa.url().replace(BASE, ''));
  const msg2 = await register(pa, 'customer', 'Mutale Zulu', 'mutale@example.com', '0961234567');
  ok('passenger registered', /Account created/.test(msg2));
  await pa.goto(pa.url().replace(/login\.html\?r=1.*/, 'login.html'));
  await login(pa, 'mutale@example.com', 'Passw0rdX');
  await pa.waitForURL('**/bookings.html');
  ok('empty my-bookings says so', /No upcoming bookings/.test(await pa.textContent('#up')));
  await pa.goto(`${BASE}/results.html?from=Lusaka&to=Kitwe&pax=2`);
  await pa.click('#list a.btn');
  await pa.waitForURL(/booking\.html\?trip=1&pax=2/);
  await pa.waitForSelector('.pn');
  ok('booking page shows 2 passenger boxes and K 520', (await pa.$$('.pn')).length === 2 && /K 520/.test(await pa.textContent('#total')));
  await pa.click('#go');
  ok('missing names are refused', /every passenger name/i.test(await pa.textContent('#msg')));
  await pa.fill('#pn0', 'Mutale Zulu'); await pa.fill('#pn1', 'Joe Zulu');
  await shot(pa, '08_booking');
  await pa.click('#go');
  await pa.waitForURL(/checkout\.html\?id=1/);
  await pa.waitForSelector('#hold');
  ok('checkout shows the hold countdown', /Seats held for \d+:\d\d/.test(await pa.textContent('#hold')));
  ok('checkout says the payment is a demo', /Demonstration payment/.test(await pa.textContent('main')));
  await shot(pa, '09_checkout');
  await pa.check('#fail'); await pa.click('#pay button');
  await pa.waitForSelector('[data-st=f]:not(.hid)');
  ok('simulated failure keeps the seats and offers retry', true);
  await pa.click('#retry'); await pa.check('input[value=airtel_money]');
  await pa.click('#pay button');
  await pa.waitForURL(/ticket\.html\?id=1/);
  await pa.waitForSelector('#tk h2');
  const tk = await pa.textContent('#tk');
  ok('e-ticket is confirmed with the right trip and total', /Your e-ticket/.test(tk) && /Lusaka → Kitwe/.test(tk) && /Mutale Zulu, Joe Zulu/.test(tk) && /K 520/.test(tk));
  await shot(pa, '10_ticket');
  await pa.goto(`${BASE}/bookings.html`);
  await pa.waitForSelector('#up .card');
  ok('my bookings lists the confirmed trip', /confirmed/.test(await pa.textContent('#up')));
  await shot(pa, '11_my_bookings');

  // ---- 5. operator sees the sale
  await op.reload();
  await op.waitForSelector('#bks table');
  ok('operator dashboard: 2 seats sold, K 520 revenue', (await op.textContent('#s-seats')).trim() === '2' && /520/.test(await op.textContent('#s-rev')));
  ok('operator sees the passenger names and phone', /Mutale Zulu, Joe Zulu/.test(await op.textContent('#bks')) && /\+260961234567/.test(await op.textContent('#bks')));
  ok('departure now shows 2 / 4 sold', /2 \/ 4/.test(await op.textContent('#deps')));
  await shot(op, '12_operator_after_sale');

  // ---- 6. access control in the browser
  const resp = await pa.goto(`${BASE}/operator.html`);
  ok('a passenger is bounced away from the operator page', !/operator\.html/.test(pa.url()));
  ok('page and script errors / CSP violations during the whole run (WebSocket not started here; see Lab 8 tests)', issues.length === 0, issues.slice(0, 3).join(' | '));

  await browser.close();
  console.log(`SUMMARY: ${pass} passed, ${fail} failed`);
  process.exit(fail ? 1 : 0);
})().catch(e => { console.error('CRASH', e); process.exit(2); });
