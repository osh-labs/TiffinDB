// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) TiffinDB contributors
//
// Continues a long import by itself (M3 spec part 2 section 7). When the
// page has exactly one form marked data-auto-continue="1" and is visible,
// the form's button is disabled, the progress status shows the button's
// data-working text, and the form is submitted once, 400 ms after the page
// loads, so a keyboard or screen-reader user can still read the status.
// Nothing moves, so reduced motion needs no other behaviour. Without this
// script the person presses the button; the final "Save the rows" form is
// never marked, so saving always waits for a deliberate press.
(function () {
  'use strict';

  var DELAY = 400;

  function start() {
    var forms = document.querySelectorAll('form[data-auto-continue="1"]');
    if (forms.length !== 1 || document.visibilityState !== 'visible') {
      return;
    }
    var form = forms[0];
    var button = form.querySelector('button[type="submit"]');
    if (!button || typeof form.requestSubmit !== 'function') {
      return;
    }
    var status = document.querySelector('.progress [role="status"]');
    var working = button.getAttribute('data-working');

    button.disabled = true;
    if (status && working) {
      status.textContent = working;
    }
    window.setTimeout(function () {
      form.requestSubmit();
    }, DELAY);
    // A page restored from the back-forward cache gets its button back, so
    // the person can continue by hand.
    window.addEventListener('pageshow', function (event) {
      if (event.persisted) {
        button.disabled = false;
      }
    });
  }

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', start);
  } else {
    start();
  }
}());
