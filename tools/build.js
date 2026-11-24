#!/usr/bin/env node
/**
 * LAB 9, Task 3 + Task 4 (DevOps): production build pipeline - the "esbuild / Vite equivalent" for this project.
 *
 *     node tools/build.js            (Node 18+; no npm packages needed)
 *     ZM_USE_ESBUILD=1 node tools/build.js     use the real esbuild instead of the built-in minifier, if it is installed
 *
 * What it does
 *   1. Minifies CSS and JavaScript (comments and whitespace removed; with esbuild also name mangling + tree-shaking).
 *   2. Merges css/style.css + css/utilities.css into ONE render-blocking file (2 requests -> 1).
 *   3. Content-hashes every file:  app.js -> assets/app.1a2b3c4d.js  (name changes when the content changes, so the
 *      browser may cache it for a year: Cache-Control: public, max-age=31536000, immutable).
 *   4. Route-based code splitting: js/routes/*.js are separate chunks, reached from app.js with import(); the import()
 *      paths are rewritten to the hashed file names.
 *   5. Rewrites every HTML page (script/link/img/srcset/preload references) into dist/*.html and writes dist/manifest.json.
 *   6. Pre-compresses every text asset (.br with Brotli quality 11, .gz with gzip level 9) so Apache can serve the
 *      smaller file without compressing on every request (see .htaccess).
 *   7. Prints a size report (raw / gzip / brotli) -> evidence for the payload benchmark.
 * page.php serves dist/<page>.html automatically when dist/manifest.json exists (set ZAMOVE_DIST=0 to use the sources).
 */
'use strict';
const fs = require('fs'), path = require('path'), zlib = require('zlib'), crypto = require('crypto');
const ROOT = path.resolve(__dirname, '..'), DIST = path.join(ROOT, 'dist'), ASSETS = path.join(DIST, 'assets');
const hash8 = (buf) => crypto.createHash('sha256').update(buf).digest('hex').slice(0, 8);
const rd = (p) => fs.readFileSync(path.join(ROOT, p));

// ---------------------------------------------------------------- minifiers
function minifyCSS(src) {
  return src.replace(/\/\*[\s\S]*?\*\//g, '')
    .replace(/\s+/g, ' ')
    .replace(/\s*([{}:;,>])\s*/g, '$1')       // spaces around punctuation are not needed in CSS
    .replace(/;}/g, '}')
    .trim();
}

/** Conservative JavaScript minifier: removes comments and indentation, collapses spaces, joins safe line breaks.
 *  It understands strings, template literals (with nested ${}) and regex literals so it never edits their content.
 *  Line breaks after } or an identifier are kept (automatic semicolon insertion depends on them). */
function minifyJS(src) {
  let out = '', i = 0, prev = '';
  const n = src.length, tplStack = [];
  const regexOK = () => {
    const t = out.replace(/\s+$/, '');
    if (!t) return true;
    const c = t[t.length - 1];
    if ('(,=:[!&|?{};+-*%<>~^'.includes(c)) return true;
    return /(?:^|[^\w$])(return|typeof|case|do|else|in|of|void|delete|throw|new)$/.test(t);
  };
  while (i < n) {
    const c = src[i], d = src[i + 1];
    if (c === '/' && d === '/') { while (i < n && src[i] !== '\n') i++; continue; }
    if (c === '/' && d === '*') { i = src.indexOf('*/', i + 2); i = i < 0 ? n : i + 2; out += ' '; continue; }
    if (c === '"' || c === "'") {
      let j = i + 1; while (j < n && src[j] !== c) j += src[j] === '\\' ? 2 : 1;
      out += src.slice(i, j + 1); i = j + 1; continue;
    }
    if (c === '`' || (c === '}' && tplStack.length && tplStack[tplStack.length - 1] === 0)) {
      // template literal body (or its continuation after a ${...} expression)
      if (c === '}') tplStack.pop();
      let j = i + 1;
      while (j < n && src[j] !== '`') {
        if (src[j] === '\\') { j += 2; continue; }
        if (src[j] === '$' && src[j + 1] === '{') { out += src.slice(i, j + 2); i = j + 2; tplStack.push(0); j = -1; break; }
        j++;
      }
      if (j === -1) continue;
      out += src.slice(i, j + 1); i = j + 1; continue;
    }
    if (c === '{' && tplStack.length) tplStack[tplStack.length - 1]++;
    if (c === '}' && tplStack.length && tplStack[tplStack.length - 1] > 0) tplStack[tplStack.length - 1]--;
    if (c === '/' && regexOK()) {
      let j = i + 1, cls = false;
      while (j < n && (src[j] !== '/' || cls)) { if (src[j] === '\\') j++; else if (src[j] === '[') cls = true; else if (src[j] === ']') cls = false; j++; }
      j++; while (/[a-z]/i.test(src[j] || '')) j++;
      out += src.slice(i, j); i = j; continue;
    }
    out += c; i++;
  }
  // line-level clean-up (strings / templates may contain newlines only inside template literals: keep lines that are inside them)
  const lines = out.split('\n'), res = [];
  let inTpl = false;
  for (let ln of lines) {
    const raw = ln;
    if (!inTpl) ln = ln.trim().replace(/[ \t]{2,}/g, ' ');
    const ticks = (raw.match(/(^|[^\\])`/g) || []).length;
    const wasIn = inTpl; if (ticks % 2 === 1) inTpl = !inTpl;
    if (!wasIn && !ln) continue;
    if (!wasIn && res.length && !inTplBefore(res) && /[;{,]$/.test(res[res.length - 1]) && !/^[\/]/.test(ln) && !wasIn) res[res.length - 1] += ln; else res.push(wasIn ? raw : ln);
  }
  function inTplBefore() { return false; }
  return res.join('\n').trim();
}

let esbuild = null;
if (process.env.ZM_USE_ESBUILD === '1') {
  try { esbuild = require(process.env.ZM_ESBUILD_PATH || 'esbuild'); } catch (e) { console.warn('esbuild not found, using the built-in minifier'); }
}
const minJS = (code, name) => esbuild ? esbuild.transformSync(code, { minify: true, format: name.includes('routes') ? 'esm' : 'iife', target: 'es2020' }).code : minifyJS(code);

// ---------------------------------------------------------------- build
fs.rmSync(DIST, { recursive: true, force: true });
fs.mkdirSync(path.join(ASSETS, 'img'), { recursive: true });
const manifest = {}, report = [];
const emit = (rel, buf, kind) => {
  const ext = path.extname(rel), base = (rel.includes('/routes/') ? 'route-' : '') + path.basename(rel, ext);
  const name = `${base}.${hash8(buf)}${ext}`;
  const dst = kind === 'img' ? `assets/img/${name}` : `assets/${name}`;
  fs.writeFileSync(path.join(DIST, dst), buf);
  manifest[rel] = dst;
  if (kind !== 'img') {
    const br = zlib.brotliCompressSync(buf, { params: { [zlib.constants.BROTLI_PARAM_QUALITY]: 11 } });
    fs.writeFileSync(path.join(DIST, dst + '.br'), br);
    fs.writeFileSync(path.join(DIST, dst + '.gz'), zlib.gzipSync(buf, { level: 9 }));
    report.push({ file: dst, raw: buf.length, gzip: zlib.gzipSync(buf, { level: 9 }).length, br: br.length });
  } else report.push({ file: dst, raw: buf.length, gzip: null, br: null });
  return dst;
};

// 1. route chunks first (app.js needs their hashed names)
const chunkMap = {};
for (const f of fs.readdirSync(path.join(ROOT, 'js/routes')).sort()) {
  const code = minJS(rd('js/routes/' + f).toString(), 'routes/' + f);
  chunkMap[`./routes/${f}`] = path.basename(emit('js/routes/' + f, Buffer.from(code), 'js')).replace(/^/, './');
}
// 2. the other scripts
const scripts = fs.readdirSync(path.join(ROOT, 'js')).filter((f) => f.endsWith('.js')).sort();
for (const f of scripts) {
  let code = rd('js/' + f).toString();
  if (f === 'app.js') for (const [from, to] of Object.entries(chunkMap)) code = code.split(`'${from}'`).join(`'${to}'`);
  emit('js/' + f, Buffer.from(minJS(code, f)), 'js');
}
// 3. one CSS file
const css = minifyCSS(rd('css/style.css').toString() + '\n' + rd('css/utilities.css').toString() + '\n' + rd('css/realtime.css').toString());
const cssOut = emit('css/site.css', Buffer.from(css), 'css');
manifest['css/style.css'] = manifest['css/utilities.css'] = cssOut;
// 4. images
for (const f of fs.readdirSync(path.join(ROOT, 'img/opt')).sort()) emit('img/opt/' + f, rd('img/opt/' + f), 'img');

// 5. HTML pages
let pages = 0;
for (const f of fs.readdirSync(ROOT).filter((x) => x.endsWith('.html'))) {
  let h = rd(f).toString();
  let cssSeen = false;
  h = h.replace(/<link rel="stylesheet" href="css\/(style|utilities|realtime)\.css">/g, () => (cssSeen ? '' : (cssSeen = true, `<link rel="stylesheet" href="dist/${cssOut}">`)));
  h = h.replace(/(<script\b[^>]*\bsrc=")js\/([\w.-]+\.js)"/g, (m, a, n) => `${a}dist/${manifest['js/' + n]}"`);
  h = h.replace(/img\/opt\/([\w.-]+\.(?:avif|webp))/g, (m, n) => (manifest['img/opt/' + n] ? 'dist/' + manifest['img/opt/' + n] : m));
  fs.writeFileSync(path.join(DIST, f), h); pages++;
}
fs.writeFileSync(path.join(DIST, 'manifest.json'), JSON.stringify(manifest, null, 2));
const tot = (k) => report.reduce((s, r) => s + (r[k] || 0), 0);
console.log('file'.padEnd(46) + 'raw'.padStart(9) + 'gzip'.padStart(9) + 'brotli'.padStart(9));
for (const r of report.filter((x) => x.gzip !== null)) console.log(r.file.padEnd(46) + String(r.raw).padStart(9) + String(r.gzip).padStart(9) + String(r.br).padStart(9));
console.log(`\n${pages} pages, ${Object.keys(manifest).length} manifest entries, minifier: ${esbuild ? 'esbuild ' + esbuild.version : 'built-in'}`);
fs.writeFileSync(path.join(DIST, 'build-report.json'), JSON.stringify(report, null, 2));
