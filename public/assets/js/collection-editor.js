// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) TiffinDB contributors
//
// Previews a collection as its rules change (M4 spec part 2 section 8;
// docs/design/m4/collections.md section 3). When the page has exactly one
// form marked data-collection-editor="1", changing a rule's field select
// (data-rule-field) or the match radios presses the form's visible Preview
// button (data-preview), so the server receives action=preview and draws
// the rows for the new field. Nothing is sent by the script itself, and
// nothing on the page is changed by it. Without this script the person
// presses Preview, or Enter in a text box.
(function () {
  'use strict';

  function start() {
    var forms = document.querySelectorAll('form[data-collection-editor="1"]');
    if (forms.length !== 1) {
      return;
    }
    var form = forms[0];
    var previewButton = form.querySelector('button[type="submit"][data-preview="1"]');
    if (!previewButton || typeof form.requestSubmit !== 'function') {
      return;
    }
    form.addEventListener('change', function (event) {
      var target = event.target;
      if (!target || typeof target.matches !== 'function') {
        return;
      }
      if (target.matches('select[data-rule-field]') || target.matches('input[name="match"]')) {
        form.requestSubmit(previewButton);
      }
    });
  }

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', start);
  } else {
    start();
  }
}());
