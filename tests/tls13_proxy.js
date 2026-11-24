/*
 * Local TLS 1.3 terminator for testing WITHOUT Apache (Node.js only, no packages).
 *
 *   https://localhost:TLS_PORT  -> TLS 1.3 ONLY -> php -S 127.0.0.1:PHP_PORT
 *   http://localhost:HTTP_PORT  -> 308 redirect to https  (what Apache's port-80 VirtualHost does)
 *
 * On XAMPP you do NOT use this file: Apache does the same job with
 * xampp/httpd-ssl-zamove.conf (SSLProtocol -all +TLSv1.3 and the HSTS header).
 *
 *   TLS_CERT=localhost.pem TLS_KEY=localhost-key.pem TLS_PORT=443 HTTP_PORT=80 PHP_PORT=8111 node tests/tls13_proxy.js
 */
'use strict';
const fs = require('fs'), https = require('https'), http = require('http');

const TLS_PORT  = +process.env.TLS_PORT  || 8443;
const HTTP_PORT = +process.env.HTTP_PORT || 8080;
const PHP_PORT  = +process.env.PHP_PORT  || 8111;

const server = https.createServer({
  key:  fs.readFileSync(process.env.TLS_KEY  || 'localhost-key.pem'),
  cert: fs.readFileSync(process.env.TLS_CERT || 'localhost.pem'),
  minVersion: 'TLSv1.3',          // TLS 1.2 and older are refused during the handshake
  maxVersion: 'TLSv1.3',
}, (req, res) => {
  const headers = {};
  for (const [k, v] of Object.entries(req.headers)) {
    if (!/^x-forwarded-/i.test(k)) headers[k] = v;               // never trust forwarding headers from a client
  }
  headers['x-forwarded-proto'] = 'https';                        // the only place this header is set
  const up = http.request({ host: '127.0.0.1', port: PHP_PORT, method: req.method, path: req.url, headers }, (r) => {
    res.writeHead(r.statusCode, r.statusMessage, r.headers);
    r.pipe(res);
  });
  up.on('error', () => { res.writeHead(502); res.end('Bad gateway'); });
  req.pipe(up);
});
server.listen(TLS_PORT, '127.0.0.1', () => console.log(`TLS 1.3 only on https://localhost:${TLS_PORT} -> PHP :${PHP_PORT}`));

http.createServer((req, res) => {
  const host = (req.headers.host || 'localhost').replace(/:\d+$/, '');
  res.writeHead(308, { Location: `https://${host}${TLS_PORT === 443 ? '' : ':' + TLS_PORT}${req.url}` });
  res.end();
}).listen(HTTP_PORT, '127.0.0.1', () => console.log(`HTTP :${HTTP_PORT} -> 308 redirect to HTTPS`));
