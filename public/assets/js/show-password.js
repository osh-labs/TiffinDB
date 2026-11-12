// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) TiffinDB contributors

/*
 * "Show password" (M1 spec part 2 section 6; P46). The only script in M1,
 * loaded with defer on pages that set `script` to `show-password`.
 *
 * 1. Every element with class show-password that has the hidden attribute
 *    is shown (without JavaScript it stays hidden).
 * 2. Its checkbox (input[data-show-password]) switches, on change, every
 *    password input in the same form, and every input it switched before,
 *    between type password and type text.
 *
 * Nothing else: no network, no storage, no inline styles, no markup.
 */
(function () {
  'use strict';

  function setUp(row) {
    var box = row.querySelector('input[data-show-password]');
    var form = row.closest('form');
    if (box === null || form === null) {
      return;
    }

    var switched = [];
    // A browser may restore a ticked box on "Back" while the inputs are
    // password inputs again; start from the unticked state.
    box.checked = false;

    box.addEventListener('change', function () {
      var inputs = form.querySelectorAll('input[type="password"]');
      Array.prototype.forEach.call(inputs, function (input) {
        if (switched.indexOf(input) === -1) {
          switched.push(input);
        }
      });
      switched.forEach(function (input) {
        input.type = box.checked ? 'text' : 'password';
      });
    });

    row.removeAttribute('hidden');
  }

  Array.prototype.forEach.call(document.querySelectorAll('.show-password[hidden]'), setUp);
}());
