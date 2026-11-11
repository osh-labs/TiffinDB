// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) TiffinDB contributors
//
// Preview helper for the static design mock-ups in this folder. Not application code.
// Applies appearance options from the page address, for example:
//   list.html?theme=dark&accent=plum&font=lexend&size=large&corners=square
// and makes the radio buttons on settings.html change the page.
(function () {
  'use strict';
  var root = document.querySelector('.tdb-root');
  if (!root) { return; }

  var groups = {
    theme: { 'dark': 't-dark', 'hc-light': 't-hcl', 'hc-dark': 't-hcd' },
    accent: { 'indigo': 'a-indigo', 'plum': 'a-plum', 'graphite': 'a-graphite' },
    font: { 'atkinson': 'f-atk', 'lexend': 'f-lexend', 'source-serif': 'f-serif4', 'device-sans': 'f-sans', 'device-serif': 'f-serif' },
    size: { 'small': 's-90', 'large': 's-115', 'larger': 's-130', 'largest': 's-150' },
    spacing: { 'roomy': 'lh-1', 'very-roomy': 'lh-2' },
    corners: { 'square': 'r-square' },
    sidebar: { 'light': 'sb-light', 'ink': 'sb-ink' },
    rows: { 'compact': 'd-compact' }
  };

  function setGroup(element, classes, chosen) {
    classes.forEach(function (name) { if (name) { element.classList.remove(name); } });
    if (chosen) { element.classList.add(chosen); }
  }

  var params = new URLSearchParams(window.location.search);
  Object.keys(groups).forEach(function (key) {
    var chosen = groups[key][params.get(key)];
    if (chosen) { root.classList.add(chosen); }
  });

  // High-contrast themes always use black and white, whatever accent is chosen.
  function enforceContrast() {
    if (root.classList.contains('t-hcl') || root.classList.contains('t-hcd')) {
      setGroup(root, ['a-indigo', 'a-plum', 'a-graphite', 'sb-ink'], '');
    }
  }
  enforceContrast();

  var preview = document.querySelector('.tdb-preview');
  var radioTargets = {
    theme: [root, ['t-dark', 't-hcl', 't-hcd']],
    accent: [root, ['a-indigo', 'a-plum', 'a-graphite']],
    face: [root, ['f-atk', 'f-lexend', 'f-serif4', 'f-sans', 'f-serif']],
    size: [preview, ['z0', 'z1', 'z2', 'z3', 'z4']],
    lead: [preview, ['l0', 'l1', 'l2']]
  };
  document.addEventListener('change', function (event) {
    var input = event.target;
    var target = radioTargets[input.name];
    if (input.type !== 'radio' || !target || !target[0]) { return; }
    setGroup(target[0], target[1], input.value);
    enforceContrast();
  });
}());
