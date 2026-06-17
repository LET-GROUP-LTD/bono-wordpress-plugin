/**
 * Bono generic opt-in form capture.
 *
 * Listens (non-blocking) on the submit event of forms the admin opted in (via
 * CSS selectors in settings or a data-bono-capture attribute) and forwards the
 * fields to the plugin REST endpoint. It never calls preventDefault, so the
 * site's own form behavior is unaffected. The API key stays server-side.
 */
(function () {
  'use strict';

  if (typeof window.bonoGenericCapture === 'undefined') {
    return;
  }

  var cfg = window.bonoGenericCapture;
  var selectors = (cfg.selectors && cfg.selectors.length ? cfg.selectors.slice() : []);
  selectors.push('[data-bono-capture]');

  function collectForms() {
    var forms = [];
    selectors.forEach(function (selector) {
      var matches;
      try {
        matches = document.querySelectorAll(selector);
      } catch (e) {
        return; // ignore invalid selector
      }
      Array.prototype.forEach.call(matches, function (el) {
        var form = el.tagName === 'FORM' ? el : (el.closest ? el.closest('form') : null);
        if (form && forms.indexOf(form) === -1) {
          forms.push(form);
        }
      });
    });
    return forms;
  }

  function serialize(form) {
    var data = {};
    var fd;
    try {
      fd = new FormData(form);
    } catch (e) {
      return data;
    }
    fd.forEach(function (value, key) {
      if (typeof value !== 'string') {
        return; // skip files
      }
      if (key === '_bono_token' || key === '_bono_nonce' || /pass(word)?/i.test(key)) {
        return; // skip our token and password-like fields
      }
      if (data[key] === undefined) {
        data[key] = value;
      } else if (Array.isArray(data[key])) {
        data[key].push(value);
      } else {
        data[key] = [data[key], value];
      }
    });
    return data;
  }

  function formId(form) {
    return (
      form.getAttribute('id') ||
      form.getAttribute('name') ||
      form.getAttribute('data-bono-capture') ||
      'generic'
    );
  }

  function send(form) {
    var fields = serialize(form);
    if (!fields || Object.keys(fields).length === 0) {
      return;
    }
    var payload = {
      formId: formId(form),
      formName: form.getAttribute('aria-label') || form.getAttribute('name') || '',
      pageUrl: window.location.href,
      fields: fields,
      _bono_token: cfg.token
    };
    try {
      fetch(cfg.restUrl, {
        method: 'POST',
        headers: { 'Content-Type': 'application/json', 'X-Bono-Token': cfg.token },
        body: JSON.stringify(payload),
        keepalive: true,
        credentials: 'same-origin'
      }).catch(function () {});
    } catch (e) {
      /* swallow: capture must never break the page */
    }
  }

  function bind() {
    collectForms().forEach(function (form) {
      if (form.__bonoBound) {
        return;
      }
      form.__bonoBound = true;
      // Capture phase so we still run if the form stops propagation; we never
      // preventDefault, so the form's normal submission is untouched.
      form.addEventListener('submit', function () { send(form); }, true);
    });
  }

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', bind);
  } else {
    bind();
  }

  if (window.MutationObserver) {
    try {
      new window.MutationObserver(function () { bind(); }).observe(
        document.documentElement,
        { childList: true, subtree: true }
      );
    } catch (e) {
      /* observer optional */
    }
  }
})();
