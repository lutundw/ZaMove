/*
 * Client-side validation (UX layer) for the Zamove registration form.
 *
 * These rules give the user instant feedback and stop obviously bad requests
 * from being sent. They are NOT a security control: the server re-validates
 * every field (api/lib/validator.php) because anyone can bypass the browser.
 *
 * Messages are identical to the server's so users see the same wording
 * whichever layer rejects the input.
 */
(function (root) {
  'use strict';

  var RULES = {
    role: {
      check: function (v) {
        return v === 'customer' || v === 'operator' ? '' : 'Choose Passenger or Bus operator.';
      }
    },
    full_name: {
      check: function (v) {
        if (!v) return 'Enter your full name or company name.';
        if (v.length < 2) return 'Name must be at least 2 characters.';
        if (v.length > 120) return 'Name must be 120 characters or fewer.';
        // letters/numbers first, then letters, numbers, space . ' & -
        if (!/^[\p{L}\p{N}][\p{L}\p{N} .'&-]*$/u.test(v)) return "Use letters, numbers, spaces and . ' & - only.";
        return '';
      }
    },
    email: {
      check: function (v) {
        if (!v) return 'Enter your email address.';
        if (v.length > 150) return 'Email must be 150 characters or fewer.';
        if (!/^[^\s@]+@[^\s@]+\.[^\s@]{2,}$/.test(v)) return 'Enter a valid email address, for example name@example.com.';
        return '';
      }
    },
    phone: {
      check: function (v) {
        if (!v) return 'Enter your phone number.';
        // Zambian mobile: +260 or 0, then 7 or 9, then 8 more digits (spaces and dashes allowed while typing)
        var digits = v.replace(/[\s().-]+/g, '');
        if (!/^(?:\+260|0)[79]\d{8}$/.test(digits)) return 'Enter a Zambian mobile number, for example 0971234567 or +260971234567.';
        return '';
      }
    },
    // REAL APP: company profile, only checked when role is operator (see validateAll)
    business_name: {
      check: function (v) {
        if (!v) return 'Enter your company name.';
        if (v.length < 2) return 'Company name must be at least 2 characters.';
        if (v.length > 150) return 'Company name must be 150 characters or fewer.';
        if (!/^[\p{L}\p{N}][\p{L}\p{N} .,'&()-]*$/u.test(v)) return "Use letters, numbers, spaces and . , ' & ( ) - only.";
        return '';
      }
    },
    business_address: {
      check: function (v) {
        if (!v) return 'Enter your company address.';
        if (v.length < 3) return 'Address must be at least 3 characters.';
        if (v.length > 255) return 'Address must be 255 characters or fewer.';
        return '';
      }
    },
    business_type: {
      check: function (v) { return v === 'bus' || v === 'cargo' || v === 'both' ? '' : 'Choose bus, cargo or both.'; }
    },
    password: {
      check: function (v) {
        if (!v) return 'Create a password.';
        if (v.length < 8) return 'Password must be at least 8 characters.';
        if (new TextEncoder().encode(v).length > 72) return 'Password must be 72 bytes or fewer.';
        if (!/[a-z]/.test(v) || !/[A-Z]/.test(v) || !/\d/.test(v)) return 'Password needs a lowercase letter, an uppercase letter and a number.';
        return '';
      }
    }
  };

  /** Trim and collapse spaces for text fields; passwords are left exactly as typed. */
  function normalise(name, value) {
    value = value == null ? '' : String(value);
    return name === 'password' ? value : value.replace(/\s+/g, ' ').trim();
  }

  /** Returns '' when valid, otherwise the message to show. */
  function validateField(name, value) {
    var rule = RULES[name];
    return rule ? rule.check(normalise(name, value)) : '';
  }

  /** Returns an object { fieldName: message } containing only the failing fields. */
  function validateAll(values) {
    var errors = {};
    Object.keys(RULES).forEach(function (name) {
      if (name.indexOf('business_') === 0 && values.role !== 'operator') return;   // REAL APP: passengers have no company
      var msg = validateField(name, values[name]);
      if (msg) errors[name] = msg;
    });
    return errors;
  }

  var api = { RULES: RULES, normalise: normalise, validateField: validateField, validateAll: validateAll };
  root.ZamoveValidation = api;
  if (typeof module !== 'undefined' && module.exports) module.exports = api;   // lets Node unit-test it
})(typeof window !== 'undefined' ? window : globalThis);
