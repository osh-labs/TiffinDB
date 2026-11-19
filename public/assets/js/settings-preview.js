// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) TiffinDB contributors

/*
 * Live preview of the reading and display settings
 * (docs/design/m2/settings.md section 3). On every change inside
 * form#settings-form, the class list of #settings-preview becomes
 * "preview-body" plus the data-preview value of the checked radio in each
 * group, skipping empty values and, with a strong contrast theme, the
 * accent. Only that element's class list is touched; class names come
 * only from the form's own data-preview attributes. Without this script
 * the preview shows the saved settings.
 */
(function () {
  'use strict';

  var form = document.getElementById('settings-form');
  var preview = document.getElementById('settings-preview');
  if (!form || !preview) {
    return;
  }

  var groups = ['theme', 'accent', 'typeface', 'text_size', 'line_spacing'];
  var strongContrast = ['hc-light', 'hc-dark'];

  function checked(name) {
    var radios = form.querySelectorAll('input[type="radio"]');
    for (var i = 0; i < radios.length; i += 1) {
      if (radios[i].name === name && radios[i].checked) {
        return radios[i];
      }
    }
    return null;
  }

  function update() {
    var theme = checked('theme');
    var noAccent = theme !== null && strongContrast.indexOf(theme.value) !== -1;
    var wanted = ['preview-body'];

    groups.forEach(function (name) {
      var radio = checked(name);
      if (radio === null || (name === 'accent' && noAccent)) {
        return;
      }
      var value = radio.getAttribute('data-preview') || '';
      if (/^[a-z][a-z0-9-]*$/.test(value)) {
        wanted.push(value);
      }
    });

    Array.prototype.slice.call(preview.classList).forEach(function (name) {
      if (wanted.indexOf(name) === -1) {
        preview.classList.remove(name);
      }
    });
    wanted.forEach(function (name) {
      preview.classList.add(name);
    });
  }

  form.addEventListener('change', update);
}());
