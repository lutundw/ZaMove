// LAB 8 (NEW FILE) - minimal RFC 6455 client built on Node's net + crypto. Used only by the tests.
const net = require('net'), crypto = require('crypto');
const GUID = '258EAFA5-E914-47DA-95CA-C5AB0DC85B11';
function frame(op, payload, { mask = true, fin = true } = {}) {
  const p = Buffer.isBuffer(payload) ? payload : Buffer.from(payload);
  const h = [Buffer.from([(fin ? 0x80 : 0) | op])];
  const m = mask ? 0x80 : 0;
  if (p.length < 126) h.push(Buffer.from([m | p.length]));
  else if (p.length < 65536) { const b = Buffer.alloc(3); b[0] = m | 126; b.writeUInt16BE(p.length, 1); h.push(b); }
  else { const b = Buffer.alloc(9); b[0] = m | 127; b.writeBigUInt64BE(BigInt(p.length), 1); h.push(b); }
  if (mask) { const k = crypto.randomBytes(4); h.push(k); const q = Buffer.from(p); for (let i = 0; i < q.length; i++) q[i] ^= k[i % 4]; h.push(q); } else h.push(p);
  return Buffer.concat(h);
}
class Client {
  constructor(o) { this.o = Object.assign({ host: '127.0.0.1', port: 8081, origin: 'https://localhost', cookie: null, autoPong: true }, o); this.msgs = []; this.closed = null; this.waiters = []; this.pings = 0; this.buf = Buffer.alloc(0); this.head = null; }
  connect() {
    return new Promise((resolve) => {
      const key = crypto.randomBytes(16).toString('base64');
      this.sock = net.connect(this.o.port, this.o.host, () => {
        let r = `GET /ws HTTP/1.1\r\nHost: ${this.o.host}:${this.o.port}\r\nUpgrade: websocket\r\nConnection: Upgrade\r\nSec-WebSocket-Key: ${key}\r\nSec-WebSocket-Version: 13\r\n`;
        if (this.o.origin) r += `Origin: ${this.o.origin}\r\n`;
        if (this.o.cookie) r += `Cookie: __Host-zamove_sid=${this.o.cookie}\r\n`;
        this.sock.write(r + '\r\n');
      });
      this.sock.on('data', (d) => {
        this.buf = Buffer.concat([this.buf, d]);
        if (!this.head) {
          const i = this.buf.indexOf('\r\n\r\n'); if (i < 0) return;
          const txt = this.buf.subarray(0, i).toString(); this.buf = this.buf.subarray(i + 4);
          const status = parseInt(txt.split(' ')[1], 10); const hd = {};
          txt.split('\r\n').slice(1).forEach(l => { const j = l.indexOf(':'); hd[l.slice(0, j).toLowerCase()] = l.slice(j + 1).trim(); });
          this.head = { status, headers: hd, raw: txt };
          this.acceptOk = hd['sec-websocket-accept'] === crypto.createHash('sha1').update(key + GUID).digest('base64');
          if (status !== 101) { this.body = ''; }
          resolve(this.head);
        }
        if (this.head && this.head.status === 101) this.parse(); else if (this.head) { this.body = (this.body || '') + this.buf.toString(); this.buf = Buffer.alloc(0); }
      });
      this.sock.on('close', () => { this.closed = this.closed || { code: 1006 }; this.t = Date.now(); this.flush(); });
      this.sock.on('error', () => {});
    });
  }
  parse() {
    for (;;) {
      const b = this.buf; if (b.length < 2) return;
      const op = b[0] & 15; let len = b[1] & 127, off = 2;
      if (len === 126) { if (b.length < 4) return; len = b.readUInt16BE(2); off = 4; } else if (len === 127) { if (b.length < 10) return; len = Number(b.readBigUInt64BE(2)); off = 10; }
      if (b.length < off + len) return;
      const pl = b.subarray(off, off + len); this.buf = b.subarray(off + len);
      if (op === 1) { this.msgs.push(JSON.parse(pl.toString())); this.flush(); }
      else if (op === 9) { this.pings++; if (this.o.autoPong) this.sock.write(frame(10, pl)); }
      else if (op === 8) { this.closed = { code: pl.length >= 2 ? pl.readUInt16BE(0) : 1005 }; this.flush(); }
    }
  }
  send(obj) { this.sock.write(frame(1, typeof obj === 'string' ? obj : JSON.stringify(obj))); }
  raw(buf) { this.sock.write(buf); }
  flush() { this.waiters = this.waiters.filter(w => { const r = w.fn(); if (r) { clearTimeout(w.t); w.res(r); return false; } return true; }); }
  waitFor(fn, ms = 3000) { return new Promise((res) => { const w = { fn: () => fn(this) , res }; w.t = setTimeout(() => { this.waiters = this.waiters.filter(x => x !== w); res(null); }, ms); this.waiters.push(w); this.flush(); }); }
  next(type, ms) { return this.waitFor(c => { const i = c.msgs.findIndex(m => m.type === type); return i >= 0 ? c.msgs.splice(i, 1)[0] : null; }, ms); }
  nextEvent(pred, ms) { return this.waitFor(c => { const i = c.msgs.findIndex(m => m.type === 'event' && (!pred || pred(m))); return i >= 0 ? c.msgs.splice(i, 1)[0] : null; }, ms); }
  waitClosed(ms = 6000) { return this.waitFor(c => c.closed, ms); }
  async open() { const h = await this.connect(); if (h.status === 101) { await this.next('welcome'); } return h; }
  async ready(last = 0) { this.send({ type: 'hello', last_event_id: last }); return this.next('sync_done'); }
  close() { try { this.sock.destroy(); } catch (e) {} }
}
module.exports = { Client, frame };
