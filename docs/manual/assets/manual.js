// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) TiffinDB contributors

/*
 * Optional comfort for the TiffinDB HTML documentation set (docs/manual/).
 * The pages are complete without this file. It does two things:
 *   1. marks the contents entry of the section being read;
 *   2. on a narrow screen, folds the contents list behind a "Contents" button.
 * It makes no network requests and stores nothing.
 */
(function () {
  'use strict';

  var side = document.querySelector('.m-side');
  if (!side) {
    return;
  }

  // 1. Current section in the contents list.
  var links = Array.prototype.slice.call(side.querySelectorAll('.m-toc a[href^="#"]'));
  var targets = [];
  links.forEach(function (link) {
    var target = document.getElementById(decodeURIComponent(link.getAttribute('href').slice(1)));
    if (target) {
      targets.push({ link: link, target: target });
    }
  });

  function markCurrent() {
    var current = null;
    var line = window.innerHeight * 0.3;
    targets.forEach(function (entry) {
      if (entry.target.getBoundingClientRect().top <= line) {
        current = entry;
      }
    });
    targets.forEach(function (entry) {
      if (entry === current) {
        entry.link.setAttribute('aria-current', 'true');
      } else {
        entry.link.removeAttribute('aria-current');
      }
    });
  }

  if (targets.length > 0) {
    var waiting = false;
    window.addEventListener('scroll', function () {
      if (!waiting) {
        waiting = true;
        window.requestAnimationFrame(function () {
          waiting = false;
          markCurrent();
        });
      }
    }, { passive: true });
    markCurrent();
  }

  // 2. Folding contents list on narrow screens.
  var menu = side.querySelector('.m-menu');
  if (menu) {
    side.classList.add('is-collapsible');
    menu.addEventListener('toggle', function () {
      side.classList.toggle('is-open', menu.open);
    });
    side.addEventListener('click', function (event) {
      if (event.target.closest && event.target.closest('.m-toc a') && menu.open) {
        menu.open = false;
      }
    });
  }
}());
