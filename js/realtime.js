/*
 * LAB 8 (NEW FILE) - resilient WebSocket client for ZaMove.
 *
 *   <link rel="stylesheet" href="css/realtime.css">
 *   <script src="js/realtime.js"></script>          (after js/api.js and js/guard.js)
 *
 *  - Opens wss://<this host>/<this folder>/ws. The browser attaches the HttpOnly session cookie by itself:
 *    this file never sees or sends a token. (Override with data-ws="wss://..." on the <script> tag for tests.)
 *  - Shows a status badge: Connected / Reconnecting / Offline  (Task 4.1)
 *  - Reconnects after any unexpected close with exponential backoff + jitter: 1, 2, 4, 8 ... capped at 30 s (Task 4.2)
 *  - Remembers the last event id it saw and sends {"type":"hello","last_event_id":N} so the server replays what was missed.
 *  - All server text is shown with textContent (never innerHTML): a hostile chat message cannot run script.
 */
(function () {
  'use strict';

  var tag = document.currentScript;
  var BASE = 1000, CAP = 30000, OFFLINE_AFTER = 6;      // ms, ms, failed attempts in a row before the badge says Offline
  var state = { ws: null, attempt: 0, timer: null, userId: null, lastId: 0, status: '', closedByUs: false, openedAt: 0 };

  function log() { try { console.log.apply(console, ['[realtime]'].concat([].slice.call(arguments))); } catch (e) { /* no console */ } }
  function store(k, v) { try { if (v === undefined) return localStorage.getItem(k); localStorage.setItem(k, String(v)); } catch (e) { /* private mode */ } return null; }

  function wsUrl() {
    var over = tag && tag.getAttribute('data-ws');
    if (over) return over;
    var dir = location.pathname.replace(/[^\/]*$/, '');            // "/zamove/" for /zamove/bookings.html
    return (location.protocol === 'https:' ? 'wss://' : 'ws://') + location.host + dir + 'ws';
  }

  /* ---------- UI (all classes come from css/realtime.css: the CSP forbids inline styles) ---------- */
  var badge, feed, panel;
  function ui() {
    if (panel) return;
    panel = document.createElement('aside');
    panel.className = 'rt-panel';
    panel.setAttribute('aria-label', 'Live updates');
    badge = document.createElement('span');
    badge.className = 'rt-badge rt-off';
    badge.setAttribute('role', 'status');
    badge.setAttribute('aria-live', 'polite');
    badge.id = 'rt-status';
    badge.textContent = 'Offline';
    var title = document.createElement('b');
    title.textContent = 'Live updates ';
    feed = document.createElement('ul');
    feed.className = 'rt-feed';
    feed.id = 'rt-feed';
    var form = document.createElement('form');
    form.className = 'rt-form';
    var id = document.createElement('input'); id.type = 'number'; id.min = '1'; id.placeholder = 'Booking #'; id.setAttribute('aria-label', 'Booking number'); id.id = 'rt-booking';
    var tx = document.createElement('input'); tx.type = 'text'; tx.maxLength = 500; tx.placeholder = 'Message'; tx.setAttribute('aria-label', 'Message'); tx.id = 'rt-text';
    var go = document.createElement('button'); go.type = 'submit'; go.textContent = 'Send';
    form.appendChild(id); form.appendChild(tx); form.appendChild(go);
    form.addEventListener('submit', function (e) {
      e.preventDefault();
      if (api.send({ type: 'message', booking_id: parseInt(id.value, 10) || 0, text: tx.value })) tx.value = '';
    });
    panel.appendChild(title); panel.appendChild(badge); panel.appendChild(feed); panel.appendChild(form);
    document.body.appendChild(panel);
  }
  function setStatus(s) {
    if (state.status === s) return;
    state.status = s;
    ui();
    badge.className = 'rt-badge ' + (s === 'connected' ? 'rt-on' : s === 'reconnecting' ? 'rt-re' : 'rt-off');
    badge.textContent = s === 'connected' ? 'Connected' : s === 'reconnecting' ? 'Reconnecting' : 'Offline';
    log('status ->', badge.textContent);
    window.dispatchEvent(new CustomEvent('zamove:realtime-status', { detail: { status: s } }));
  }
  function addLine(text, cls) {
    ui();
    var li = document.createElement('li');
    li.className = cls || '';
    li.textContent = text;                                         // textContent: never HTML
    feed.insertBefore(li, feed.firstChild);
    while (feed.children.length > 30) feed.removeChild(feed.lastChild);
  }
  function describe(ev) {
    var p = ev.payload || {};
    if (ev.event_type === 'booking.created') return 'Booking ' + (p.booking_reference || '#' + ev.booking_id) + ' created (' + p.seats + ' seat(s), ' + p.currency + ' ' + p.total_amount + ').';
    if (ev.event_type === 'booking.status') return 'Booking #' + ev.booking_id + ': ' + p.old_status + ' → ' + p.new_status + (p.by ? ' (by ' + p.by + ')' : '') + '.';
    if (ev.event_type === 'booking.message') return (p.from || 'Someone') + ' (booking #' + ev.booking_id + '): ' + p.text;
    return ev.event_type;
  }

  /* ---------- connection ---------- */
  function delayFor(attempt) {
    var base = Math.min(CAP, BASE * Math.pow(2, attempt));        // 1, 2, 4, 8, 16, 30, 30 ...
    var d = Math.round(base / 2 + Math.random() * (base / 2));    // "equal jitter": 50-100 % of the base, so clients desynchronise
    return { base: base, wait: d };
  }
  function scheduleReconnect(reason) {
    if (state.closedByUs || state.timer) return;
    var d = delayFor(state.attempt);
    state.attempt++;
    setStatus(!navigator.onLine || state.attempt > OFFLINE_AFTER ? 'offline' : 'reconnecting');
    log('connection lost (' + reason + '). retry #' + state.attempt + ' in ' + (d.wait / 1000).toFixed(2) + ' s  [base ' + (d.base / 1000) + ' s, cap ' + (CAP / 1000) + ' s, jitter 50-100%]');
    state.timer = setTimeout(function () { state.timer = null; open(); }, d.wait);
  }
  function open() {
    if (state.ws && state.ws.readyState <= 1) return;
    if (!state.timer && state.attempt === 0) setStatus('reconnecting');
    var ws;
    try { ws = new WebSocket(wsUrl()); } catch (e) { scheduleReconnect('cannot create socket'); return; }
    state.ws = ws;
    ws.onopen = function () {
      state.openedAt = Date.now();
      log('socket open (attempt ' + (state.attempt + 1) + ')');
    };
    ws.onmessage = function (m) {
      var d;
      try { d = JSON.parse(m.data); } catch (e) { return; }
      if (d.type === 'welcome') {
        if (state.userId !== d.user_id) { state.userId = d.user_id; var k = store('zm_rt_last_' + d.user_id); state.lastId = k ? parseInt(k, 10) || 0 : 0; }
        state.attempt = 0;
        setStatus('connected');
        ws.send(JSON.stringify({ type: 'hello', last_event_id: state.lastId }));   // ask for everything missed while away
      } else if (d.type === 'event') {
        if (d.event_id > state.lastId) { state.lastId = d.event_id; store('zm_rt_last_' + state.userId, d.event_id); }
        addLine((d.replay ? '(missed) ' : '') + describe(d), d.replay ? 'rt-missed' : 'rt-new');
        window.dispatchEvent(new CustomEvent('zamove:realtime', { detail: d }));
      } else if (d.type === 'sync_done') {
        if (d.replayed) log('replayed ' + d.replayed + ' missed event(s)');
      } else if (d.type === 'error') {
        addLine('Error: ' + d.detail, 'rt-err');
      } else if (d.type === 'ok') {
        addLine(d.detail, 'rt-ok');
      }
    };
    ws.onerror = function () { log('socket error'); };               // onclose always follows; reconnect is scheduled there
    ws.onclose = function (e) {
      state.ws = null;
      if (state.closedByUs) { setStatus('offline'); return; }
      if (e.code === 4401) { setStatus('offline'); addLine('Your session ended. Sign in again.', 'rt-err'); return; }   // do not hammer the server with a dead session
      if (state.openedAt && Date.now() - state.openedAt > 10000) state.attempt = 0;   // it was healthy for a while: start the backoff from 1 s again
      state.openedAt = 0;                                             // a failed attempt must not inherit the last healthy time
      scheduleReconnect('close code ' + e.code);
    };
  }

  var api = {
    connect: function () { state.closedByUs = false; open(); },
    close: function () { state.closedByUs = true; clearTimeout(state.timer); state.timer = null; if (state.ws) state.ws.close(1000, 'bye'); },
    send: function (obj) {
      if (!state.ws || state.ws.readyState !== 1) { addLine('Not connected: message not sent.', 'rt-err'); return false; }
      state.ws.send(JSON.stringify(obj));
      return true;
    },
    status: function () { return state.status; },
    delayFor: delayFor
  };
  window.ZamoveRealtime = api;

  window.addEventListener('online', function () { if (!state.ws && !state.closedByUs) { clearTimeout(state.timer); state.timer = null; state.attempt = 0; open(); } });
  window.addEventListener('offline', function () { setStatus('offline'); });
  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', api.connect); else api.connect();
})();
