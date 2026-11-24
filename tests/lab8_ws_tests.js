// LAB 8 (NEW FILE) - automated tests of the WebSocket daemon. Run through tests/run_local_lab8.sh (or against your own daemon with the env vars below).
const { Client, frame } = require('./ws_client');
const fs = require('fs'), cp = require('child_process');
const T = JSON.parse(fs.readFileSync(process.env.TOKENS, 'utf8'));
const PORT = +(process.env.WS_PORT || 8081), PHP = process.env.PHP_BIN || 'php';
const ROOT = __dirname + '/..';
let pass = 0, fail = 0;
function ok(c, name, extra) { if (c) { pass++; console.log('PASS ' + name + (extra ? '  [' + extra + ']' : '')); } else { fail++; console.log('FAIL ' + name + (extra ? '  [' + extra + ']' : '')); } }
const C = (u, o) => new Client(Object.assign({ port: PORT, cookie: u === null ? null : T[u] }, o || {}));
const sleep = (ms) => new Promise(r => setTimeout(r, ms));
const sql = (q, a) => JSON.parse(cp.execFileSync(PHP, ['-r', 'require "' + ROOT + '/api/lib/db.php"; $s=db()->prepare($argv[1]); $s->execute(json_decode($argv[2])); echo json_encode($s->fetchAll());', q, JSON.stringify(a || [])], { env: process.env }).toString());

(async () => {
  console.log('--- Task 1: handshake and authentication ---');
  let c = C(null); let h = await c.connect();
  ok(h.status === 401, 'no cookie -> HTTP 401', 'status ' + h.status); c.close();
  c = C(1, { cookie: 'a'.repeat(64) }); h = await c.connect(); ok(h.status === 401, 'forged cookie -> HTTP 401', 'status ' + h.status); c.close();
  c = C(1, { cookie: 'not-hex' }); h = await c.connect(); ok(h.status === 401, 'malformed cookie -> HTTP 401'); c.close();
  c = C(1, { origin: 'https://evil.example' }); h = await c.connect(); ok(h.status === 403, 'foreign Origin (CSWSH) -> HTTP 403', 'status ' + h.status); c.close();
  const alice = C(1); h = await alice.open();
  ok(h.status === 101 && h.headers.upgrade.toLowerCase() === 'websocket', 'valid session -> 101 Switching Protocols', h.raw.split('\r\n')[0]);
  ok(alice.acceptOk, 'Sec-WebSocket-Accept = base64(SHA1(key + GUID))', h.headers['sec-websocket-accept']);
  const welcome = alice.msgs.length === 0; ok(welcome, 'welcome frame consumed');
  await alice.ready(0);
  const bob = C(2), olive = C(3), oscar = C(4), ada = C(5);
  for (const x of [bob, olive, oscar, ada]) { await x.open(); await x.ready(0); }

  console.log('--- Task 1.3 / 2: rooms, live delivery, persistence ---');
  alice.send({ type: 'message', booking_id: 1, text: 'Hello operator, is the bus on time?' });
  const e1 = await alice.nextEvent(m => m.event_type === 'booking.message'); const e2 = await olive.nextEvent(m => m.event_type === 'booking.message');
  ok(e1 && e2 && e2.payload.text.startsWith('Hello operator'), 'message on booking 1 reaches customer (alice) and operator (olive)', 'event ids ' + (e1 && e1.event_id) + ',' + (e2 && e2.event_id));
  const leak = await bob.nextEvent(null, 700); const leak2 = await oscar.nextEvent(null, 300);
  ok(leak === null && leak2 === null, 'users outside the booking (bob, oscar) receive nothing');
  const rows = sql('SELECT user_id, event_type FROM realtime_events WHERE booking_id = ? ORDER BY event_id', [1]);
  ok(rows.length === 2, 'event persisted in realtime_events (one row per recipient)', JSON.stringify(rows));
  alice.send({ type: 'message', booking_id: 2, text: 'peek at bob booking' });
  const den = await alice.next('error'); ok(den && /No booking/.test(den.detail), 'customer cannot post to somebody else\'s booking (object-level authz)', den && den.detail);
  olive.send({ type: 'status', booking_id: 1, status: 'in_transit' });
  const st = await alice.nextEvent(m => m.event_type === 'booking.status');
  ok(st && st.payload.old_status === 'confirmed' && st.payload.new_status === 'in_transit', 'operator status change is pushed live to the customer', st && (st.payload.old_status + ' -> ' + st.payload.new_status));
  const hist = sql('SELECT old_status,new_status,changed_by FROM booking_status_history WHERE booking_id = 1', []);
  ok(hist.length === 1 && hist[0].new_status === 'in_transit', 'status change written to booking_status_history', JSON.stringify(hist[0]));
  alice.send({ type: 'status', booking_id: 1, status: 'completed' });
  const d2 = await alice.next('error'); ok(d2 && /Only operators/.test(d2.detail), 'customer cannot change a status (RBAC)');
  oscar.send({ type: 'status', booking_id: 1, status: 'cancelled' });
  const d3 = await oscar.next('error'); ok(d3 && /No booking/.test(d3.detail), 'operator of another company cannot change booking 1 (BOLA)');
  ok(sql('SELECT booking_status FROM bookings WHERE booking_id=1')[0].booking_status === 'in_transit', 'booking status unchanged by the denied attempts');

  console.log('--- Task 2.2: offline user receives missed history on reconnect ---');
  const lastSeen = Math.max(...[e1.event_id, st.event_id]);
  alice.close(); await sleep(300);
  olive.send({ type: 'message', booking_id: 1, text: 'Bus 14 departs from bay 3' });
  olive.send({ type: 'status', booking_id: 1, status: 'completed' });
  await olive.nextEvent(m => m.event_type === 'booking.status'); await sleep(200);
  const a2 = C(1); await a2.open(); const sync = await a2.ready(lastSeen);
  const missed = a2.msgs.filter(m => m.type === 'event');
  ok(sync.replayed === 2 && missed.every(m => m.replay === true && m.event_id > lastSeen), 'reconnect with last_event_id replays exactly the 2 missed events', 'replayed=' + sync.replayed);
  ok(missed[0].event_id < missed[1].event_id, 'replayed in order (oldest first)');
  const a3 = C(1); await a3.open(); const s3 = await a3.ready(Math.max(...missed.map(m => m.event_id)));
  ok(s3.replayed === 0, 'no duplicates when the cursor is up to date'); a3.close();

  console.log('--- Task 2.3: schema validation of incoming frames ---');
  const bad = [['not JSON', '{oops'], ['array instead of object', '[1,2]'], ['unknown type', { type: 'drop' }], ['extra property', { type: 'ping', admin: true }],
    ['booking_id as string', { type: 'message', booking_id: '1 OR 1=1', text: 'x' }], ['empty text', { type: 'message', booking_id: 1, text: '' }],
    ['text 501 chars', { type: 'message', booking_id: 1, text: 'a'.repeat(501) }], ['status not in enum', { type: 'status', booking_id: 1, status: 'hacked' }],
    ['negative cursor', { type: 'hello', last_event_id: -5 }], ['deeply nested', '{"a":'.repeat(20) + '1' + '}'.repeat(20)]];
  for (const [n, f] of bad) { a2.send(f); const r = await a2.next('error'); ok(r !== null, 'rejected: ' + n, r && r.detail); }
  const inj = "x'); DROP TABLE users;-- <script>alert(1)</script>";
  a2.send({ type: 'message', booking_id: 1, text: inj });
  const echo = await a2.nextEvent(m => m.event_type === 'booking.message' && !m.replay);
  ok(echo && echo.payload.text === inj, 'SQL/HTML in a message is stored and returned as inert text');
  ok(sql('SELECT COUNT(*) AS n FROM users')[0].n >= 5, 'users table intact after the injection attempt (parameterised queries)');
  ok(a2.closed === null, 'socket still open after 10 malformed frames (no crash)');
  const z1 = C(2); await z1.open(); z1.raw(frame(1, '{"type":"ping"}', { mask: false })); const zc = await z1.waitClosed(); ok(zc && zc.code === 1002, 'unmasked client frame -> close 1002 (protocol error)', zc && zc.code);
  const z2 = C(2); await z2.open(); z2.raw(frame(1, 'x'.repeat(20000))); const zc2 = await z2.waitClosed(); ok(zc2 && zc2.code === 1009, '20 KB frame -> close 1009 (message too big)', zc2 && zc2.code);
  const z3 = C(2); await z3.open(); z3.raw(frame(2, Buffer.from([1, 2, 3]))); const zc3 = await z3.waitClosed(); ok(zc3 && zc3.code === 1003, 'binary frame -> close 1003 (unsupported data)', zc3 && zc3.code);
  const z4 = C(2); await z4.open(); z4.raw(frame(1, Buffer.from([0xff, 0xfe, 0xfd]))); const zc4 = await z4.waitClosed(); ok(zc4 && zc4.code === 1007, 'invalid UTF-8 -> close 1007', zc4 && zc4.code);
  const z5 = C(2); await z5.open(); await z5.ready(0); let lim = 0; for (let i = 0; i < 40; i++) z5.send({ type: 'ping' }); await sleep(600);
  lim = z5.msgs.filter(m => m.type === 'error' && /too many/.test(m.detail)).length; ok(lim >= 9, 'rate limit: 30 frames / 10 s per socket', lim + ' frames refused'); z5.close();
  for (const x of [z1, z2, z3, z4]) x.close();

  console.log('--- Task 3: heartbeat (ping every 2 s, pong timeout 2 s in this test run) ---');
  const live = C(3); await live.open(); await live.ready(0);
  const dead = C(2, { autoPong: false }); await dead.open(); await dead.ready(0);
  const t0 = Date.now(); const cl = await dead.waitClosed(9000);
  ok(cl !== null && Date.now() - t0 < 8000, 'silent client (no Pong) is evicted by the server', 'closed after ' + ((Date.now() - t0) / 1000).toFixed(1) + ' s, code ' + (cl && cl.code));
  ok(dead.pings >= 1, 'server sent Ping (0x9) frames before evicting', dead.pings + ' ping(s)');
  ok(live.closed === null && live.pings >= 2, 'responsive client answers Pong and stays connected', live.pings + ' pings answered');
  const half = C(2); await half.open(); await half.ready(0); half.sock.pause();   // TCP stays open (kernel ACKs) but the application never reads or answers = half-open peer
  await sleep(6500); half.sock.resume();
  const hcl = await half.waitClosed(2000);
  ok(hcl !== null, 'half-open socket (TCP up, application silent) is terminated by the server', 'close code ' + (hcl && hcl.code) + ', ' + half.pings + ' unanswered ping(s) received'); half.close();

  console.log('--- session revoked on the server closes the socket ---');
  const rv = C(5); await rv.open(); await rv.ready(0);
  cp.execFileSync(PHP, ['-r', 'require "' + ROOT + '/api/lib/db.php"; db()->prepare("DELETE FROM sessions WHERE user_id = 5")->execute();'], { env: process.env });
  const rc = await rv.waitClosed(8000); ok(rc && rc.code === 4401, 'logout/revocation closes the socket with code 4401 at the next heartbeat', rc && rc.code);

  console.log('--- DevOps: concurrent connections and fan-out ---');
  const ids = Object.keys(T).map(Number).filter(u => u >= 6); const pid = process.env.WS_PID;
  const rss = () => { try { return +fs.readFileSync('/proc/' + pid + '/status', 'utf8').match(/VmRSS:\s+(\d+)/)[1]; } catch (e) { return 0; } };
  const m0 = rss(); const t2 = Date.now(); const many = ids.map(u => C(u));
  const hs = await Promise.all(many.map(x => x.open())); const t3 = Date.now();
  ok(hs.filter(x => x.status === 101).length === ids.length, ids.length + ' concurrent authenticated sockets accepted', 'handshakes in ' + (t3 - t2) + ' ms');
  await Promise.all(many.map(x => x.ready(0)));
  cp.execFileSync(PHP, [ROOT + '/tests/lab8_emit.php'], { env: process.env }); const t4 = Date.now();
  const got = await Promise.all(many.map(x => x.nextEvent(null, 4000))); const t5 = Date.now();
  const delivered = got.filter(Boolean).length;
  ok(delivered === ids.length, 'one event per user delivered to all ' + ids.length + ' sockets', 'last delivery ' + (t5 - t4) + ' ms after the INSERTs');
  const m1 = rss(); console.log('INFO memory: ' + m0 + ' kB -> ' + m1 + ' kB with ' + ids.length + ' sockets (' + (((m1 - m0) / ids.length) || 0).toFixed(1) + ' kB per socket)');
  many.forEach(x => x.close()); await sleep(800);
  const m2 = rss(); console.log('INFO memory after all sockets closed: ' + m2 + ' kB');
  ok(m2 <= m1 + 512, 'memory does not keep growing after sockets close (no leak)', m1 + ' -> ' + m2 + ' kB');
  [alice, bob, olive, oscar, ada, a2, live].forEach(x => x.close());
  console.log('SUMMARY: ' + pass + ' passed, ' + fail + ' failed');
  console.log('RESULT: ' + (fail === 0 ? 'PASS' : 'FAIL'));
  process.exit(fail === 0 ? 0 : 1);
})().catch(e => { console.log('FAIL harness error: ' + e.stack); console.log('RESULT: FAIL'); process.exit(1); });
