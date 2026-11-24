/*
 * Lab 6, Task 2.2: output encoding and sanitising for everything the pages build as HTML.
 * (The project's replacement for DOMPurify: npm is not available, and the site only needs a small, fixed set of tags.)
 *
 *   html`<b>${userText}</b>`        tagged template: every ${value} is HTML-escaped for the element/attribute
 *                                   context; arrays are joined; nested html`...` results are kept as markup
 *   raw(markup)                     mark markup that is already safe (we build it from numbers only)
 *   put(element, safeHtml)          the ONLY way pages assign markup: it also runs the allow-list sanitiser
 *   sanitizeHTML(dirtyString)       strips scripts, event-handler attributes, javascript: URLs, styles, frames ...
 *   textContent stays the right choice whenever no markup is needed.
 *
 * Two layers: (1) context-aware encoding when the string is built, (2) allow-list sanitising when it is inserted.
 */
(function (w) {
  'use strict';

  var ESC = { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;', '`': '&#96;' };
  function escapeHTML(v) { return String(v == null ? '' : v).replace(/[&<>"'`]/g, function (c) { return ESC[c]; }); }

  function SafeHTML(s) { this.s = s; }
  SafeHTML.prototype.toString = function () { return this.s; };
  function raw(s) { return new SafeHTML(String(s)); }
  function part(v) {
    if (v instanceof SafeHTML) return v.s;
    if (Array.isArray(v)) return v.map(part).join('');
    return escapeHTML(v);
  }
  function html(strings) {
    var out = '', i;
    for (i = 0; i < strings.length; i++) {
      out += strings[i];
      if (i + 1 < strings.length) out += part(arguments[i + 1]);
    }
    return new SafeHTML(out);
  }

  /* ---- allow-list sanitiser ---- */
  var TAGS = 'a article aside b br button div fieldset footer h1 h2 h3 header i input label legend li main nav option p rect select span strong svg table tbody td th thead tr ul'.split(' ');
  var ATTRS = ['class', 'id', 'for', 'name', 'type', 'value', 'placeholder', 'required', 'disabled', 'selected', 'checked', 'role',
               'href', 'viewbox', 'x', 'y', 'width', 'height', 'colspan'];
  var INPUT_TYPES = ['text', 'tel', 'email', 'number', 'checkbox', 'radio', 'search', 'date', 'time'];

  function safeUrl(v) {
    v = String(v).replace(/[\u0000- ]/g, '');
    return /^(https?:|mailto:|[^:]*$)/i.test(v) && !/^(javascript|data|vbscript):/i.test(v);
  }

  function sanitizeHTML(dirty) {
    var t = document.createElement('template');           // inert: nothing runs and no image loads while we inspect it
    t.innerHTML = String(dirty);
    (function walk(node) {
      Array.prototype.slice.call(node.childNodes).forEach(function (n) {
        if (n.nodeType === 8) { node.removeChild(n); return; }                          // comments
        if (n.nodeType !== 1) return;                                                   // text stays (already encoded)
        var tag = n.nodeName.toLowerCase();
        if (TAGS.indexOf(tag) === -1) { node.removeChild(n); return; }
        Array.prototype.slice.call(n.attributes).forEach(function (a) {
          var name = a.name.toLowerCase(), ok = false;
          if (/^on/.test(name) || name === 'style' || name === 'srcdoc') ok = false;
          else if (name.indexOf('data-') === 0 || name.indexOf('aria-') === 0) ok = true;
          else if (ATTRS.indexOf(name) !== -1) ok = true;
          if (ok && name === 'href' && !safeUrl(a.value)) ok = false;
          if (ok && name === 'type' && tag === 'input' && INPUT_TYPES.indexOf(a.value.toLowerCase()) === -1) ok = false;
          if (ok && name === 'type' && tag === 'button' && ['button', 'submit'].indexOf(a.value.toLowerCase()) === -1) ok = false;
          if (!ok) n.removeAttribute(a.name);
        });
        walk(n);
      });
    })(t.content);
    return t.innerHTML;
  }

  function put(el, markup) { el.innerHTML = sanitizeHTML(part(markup)); }

  w.Safe = { escapeHTML: escapeHTML, html: html, raw: raw, put: put, sanitizeHTML: sanitizeHTML };
})(window);
