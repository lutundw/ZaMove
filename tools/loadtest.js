#!/usr/bin/env node
/* LAB 7 (NEW FILE) - tiny load generator (our counterpart of `ab` / autocannon, which need installing).
 *   node tools/loadtest.js <url> <connections> <seconds> [cookie]
 * Keep-alive connections, closed loop (each connection sends the next request when the last one finishes).
 * Prints requests, req/s, status codes, errors and latency p50/p95/p99 in ms.
 * Same test with Apache Bench:  ab -n 5000 -c 50 -C "__Host-zamove_sid=..." http://localhost/zamove/api/v1/schedules
 * Same test with autocannon:    npx autocannon -c 50 -d 10 http://localhost/zamove/api/v1/schedules */
const http = require('http'); const { URL } = require('url');
const [url, conns, secs, cookie] = [process.argv[2], +process.argv[3] || 10, +process.argv[4] || 10, process.argv[5]];
const u = new URL(url); const agent = new http.Agent({ keepAlive: true, maxSockets: conns });
const lat = [], codes = {}; let errors = 0, done = 0; const end = Date.now() + secs * 1000;
function one(cb) {
  const t = process.hrtime.bigint();
  const req = http.request({ host: u.hostname, port: u.port, path: u.pathname + u.search, agent, headers: cookie ? { Cookie: cookie } : {} }, res => {
    res.resume(); res.on('end', () => { lat.push(Number(process.hrtime.bigint() - t) / 1e6); codes[res.statusCode] = (codes[res.statusCode] || 0) + 1; done++; cb(); });
  });
  req.on('error', () => { errors++; cb(); }); req.setTimeout(10000, () => req.destroy()); req.end();
}
let running = conns; const start = Date.now();
for (let i = 0; i < conns; i++) (function loop() { if (Date.now() >= end) { if (--running === 0) report(); return; } one(loop); })();
function report() {
  const el = (Date.now() - start) / 1000; lat.sort((a, b) => a - b); const q = p => lat.length ? lat[Math.min(lat.length - 1, Math.floor(p * lat.length))] : 0;
  console.log(JSON.stringify({ url, connections: conns, seconds: +el.toFixed(1), requests: done, req_per_s: +(done / el).toFixed(1), status: codes, errors,
    p50_ms: +q(.5).toFixed(2), p95_ms: +q(.95).toFixed(2), p99_ms: +q(.99).toFixed(2), max_ms: +(lat[lat.length - 1] || 0).toFixed(2) }));
  process.exit(errors ? 1 : 0);
}
