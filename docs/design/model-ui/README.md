# TiffinDB model UI

Status: accepted by the lead developer as the visual target for v1 (ADR 0007).

These are design mock-ups, not application code. They show what the v1 screens should look like and how they respond to the appearance settings. The implementation plan that goes with them is `docs/ui-design-plan.md`; token values are in `docs/design/tokens.css`.

## Viewing

Open any `.html` file in a browser. No server or build step is needed. Links between screens work.

| File | Screen | Milestone |
|---|---|---|
| `sign-in.html` | Sign in | M1 |
| `projects.html` | Project list (Admin view) | M1 |
| `list.html` | List view with colour-blocked sidebar | M2 |
| `record.html` | Record form | M2, M4 |
| `collection.html` | Saved collection | M4 |
| `import.html` | Import review | M3 |
| `settings.html` | Reading and display settings | M2 |
| `brand.html` | Logo and wordmark sheet | - |

`record.html` is responsive; view it in a narrow window (about 390 px) for the small-screen layout.

## Trying the appearance options

`preview.js` reads options from the page address:

| Option | Values |
|---|---|
| `theme` | `dark`, `hc-light`, `hc-dark` (default light) |
| `accent` | `indigo`, `plum`, `graphite` (default teal) |
| `font` | `atkinson`, `lexend`, `source-serif`, `device-sans`, `device-serif` (default Inter) |
| `size` | `small`, `large`, `larger`, `largest` |
| `spacing` | `roomy`, `very-roomy` |
| `corners` | `square` |
| `sidebar` | `light`, `ink` (list view only) |
| `rows` | `compact` (tables) |

Example: `list.html?theme=dark&accent=plum&font=lexend`.

On `settings.html` the colour, accent, lettering, text size, and line spacing controls change the page directly.

## What is and is not representative

- All data is synthetic.
- The mock-ups use inline `style` attributes and load fonts from Google Fonts. Neither is allowed in the application: styles belong in a stylesheet under the planned Content-Security-Policy, and fonts are self-hosted (plan sections 5 and 7).
- The text size option scales the whole page with CSS `zoom`. The application scales text with a root font size and `rem` units.
- `corners`, `sidebar`, and `rows` are design explorations, not user settings.
- Screens not yet designed are listed in `docs/ui-design-plan.md`.

## Canvas source

`canvas-source/` holds the design-canvas files the static pages were exported from (`*.dc.html` plus `canvas.json`). They need the design canvas runtime and do not open on their own. Keep them so the canvas can be restored and edited; when the canvas changes, re-export the static pages so the two stay in step.
