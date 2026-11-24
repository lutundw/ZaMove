// LAB 9 (NEW FILE) - Core Web Vitals lab measurement with Chromium DevTools Protocol (the same engine and throttling model
// that Lighthouse uses: mobile viewport 412x823, 4x CPU slowdown, "Slow 4G" = 1.6 Mbit/s, 150 ms RTT).
//   node tests/lab9_measure.js <label> <url> [runs=5]   ->  evidence/lab9/vitals_<label>.json
// LCP / CLS: PerformanceObserver (buffered). TTFB: Navigation Timing responseStart. TBT: sum of (long task - 50 ms) after First
// Contentful Paint. INP: worst Event Timing duration of three scripted interactions (tap, select change, key press).
const { chromium } = require('playwright'); const fs = require('fs');
const [label, url, runsArg] = process.argv.slice(2); const RUNS = +(runsArg || 5);
const med = (a) => { const s = [...a].sort((x, y) => x - y); return s.length % 2 ? s[(s.length - 1) / 2] : (s[s.length / 2 - 1] + s[s.length / 2]) / 2; };
(async () => {
  const browser = await chromium.launch(); const runs = [];
  for (let i = 0; i < RUNS + 1; i++) {                                   // run 0 is a warm-up of the server (discarded)
    const ctx = await browser.newContext({ viewport: { width: 412, height: 823 }, deviceScaleFactor: 2.625, isMobile: true, hasTouch: true,
      userAgent: 'Mozilla/5.0 (Linux; Android 11; moto g power (2022)) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120 Mobile Safari/537.36' });
    const page = await ctx.newPage(); const cdp = await ctx.newCDPSession(page);
    await cdp.send('Network.enable'); await cdp.send('Network.setCacheDisabled', { cacheDisabled: false });
    await cdp.send('Network.emulateNetworkConditions', { offline: false, latency: 150, downloadThroughput: 1.6 * 1024 * 1024 / 8, uploadThroughput: 750 * 1024 / 8 });
    await cdp.send('Emulation.setCPUThrottlingRate', { rate: 4 });
    let bytes = 0, reqs = 0; const types = {};
    cdp.on('Network.loadingFinished', (e) => { bytes += e.encodedDataLength; reqs++; });
    await page.addInitScript(() => {
      window.__v = { lcp: 0, cls: 0, long: [], fcp: 0, ev: [] };
      new PerformanceObserver((l) => { for (const e of l.getEntries()) window.__v.lcp = e.startTime; }).observe({ type: 'largest-contentful-paint', buffered: true });
      new PerformanceObserver((l) => { for (const e of l.getEntries()) if (!e.hadRecentInput) window.__v.cls += e.value; }).observe({ type: 'layout-shift', buffered: true });
      new PerformanceObserver((l) => { for (const e of l.getEntries()) window.__v.long.push([e.startTime, e.duration]); }).observe({ type: 'longtask', buffered: true });
      new PerformanceObserver((l) => { for (const e of l.getEntries()) if (e.name === 'first-contentful-paint') window.__v.fcp = e.startTime; }).observe({ type: 'paint', buffered: true });
      new PerformanceObserver((l) => { for (const e of l.getEntries()) window.__v.ev.push(e.duration); }).observe({ type: 'event', durationThreshold: 16, buffered: true });
    });
    await page.goto(url, { waitUntil: 'load', timeout: 90000 }); await page.waitForTimeout(2500);
    const first = bytes, firstReqs = reqs;
    // interactions for INP
    try { await page.tap('#swap', { timeout: 3000 }); } catch (e) {}
    try { await page.selectOption('#pax', '3', { timeout: 3000 }); } catch (e) {}
    try { await page.focus('#date'); await page.keyboard.press('ArrowUp'); } catch (e) {}
    await page.waitForTimeout(800);
    const m = await page.evaluate(() => {
      const nav = performance.getEntriesByType('navigation')[0]; const v = window.__v;
      const tbt = v.long.filter(([s]) => s >= v.fcp).reduce((a, [, d]) => a + Math.max(0, d - 50), 0);
      return { ttfb: nav.responseStart, fcp: v.fcp, lcp: v.lcp, cls: +v.cls.toFixed(4), tbt: Math.round(tbt), inp: v.ev.length ? Math.max(...v.ev) : 0, longTasks: v.long.filter(x => x[1] > 50).length, domInteractive: nav.domInteractive };
    });
    // repeat view (warm HTTP cache)
    bytes = 0; reqs = 0; await page.reload({ waitUntil: 'load' }); await page.waitForTimeout(500);
    const rec = Object.assign(m, { bytes: first, requests: firstReqs, repeatBytes: bytes, repeatRequests: reqs });
    if (i > 0) { runs.push(rec); console.log(label + ' run ' + i, JSON.stringify(rec)); }
    if (i === 1 && process.env.SHOT) { await page.goto(url, { waitUntil: 'load' }); await page.screenshot({ path: process.env.SHOT, fullPage: false }); }
    await ctx.close();
  }
  const keys = ['ttfb', 'fcp', 'lcp', 'cls', 'tbt', 'inp', 'longTasks', 'bytes', 'requests', 'repeatBytes', 'repeatRequests'];
  const median = {}; keys.forEach(k => median[k] = +med(runs.map(r => r[k])).toFixed(k === 'cls' ? 4 : 0));
  fs.writeFileSync('evidence/lab9/vitals_' + label + '.json', JSON.stringify({ label, url, tool: 'Chromium ' + browser.version() + ' via Playwright + DevTools Protocol (not the Lighthouse npm package)', throttling: 'mobile 412x823, CPU x4, 1.6 Mbit/s, 150 ms RTT', runs, median }, null, 2));
  console.log(label + ' MEDIAN', JSON.stringify(median)); await browser.close();
})();
