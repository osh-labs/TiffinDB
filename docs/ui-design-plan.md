# TiffinDB UI Design Plan

Status: draft 1. Companion to `docs/plan.md`. The model UI it describes was accepted by the lead developer as the visual target for v1 (ADR 0007).

## 1. Purpose

`docs/plan.md` says what v1 does. This document says what v1 looks like and how the design is built into each milestone. Where the two disagree, section 9 lists the difference so it can be resolved; until then `docs/plan.md` and the ADRs govern behaviour and this document governs appearance.

## 2. Sources of truth

| Item | Location | Notes |
|---|---|---|
| Screen designs | `docs/design/model-ui/*.html` | Static mock-ups; open in a browser |
| Design tokens | `docs/design/tokens.css` | Colour, type, shape values for every theme and accent |
| Logo and usage rules | `docs/brand/` | SVG files and `README.md` |
| Decision record | `docs/decisions/0007-ui-appearance.md` | Typeface, accent, v1 target |
| Canvas source | `docs/design/model-ui/canvas-source/` | Editable design-canvas files |

## 3. Design principles

Taken from the priorities in plan section 1 and applied to the visual design:

1. One primary action per screen, drawn as the only filled button.
2. Words before icons. Every control has a visible text label; icons only accompany text.
3. Plain language: "Bring in", "Move to trash", "Space between lines", not technical terms.
4. Large, separate targets: 44 px minimum, 48 px for form inputs.
5. The user's appearance settings always win over form and screen styling.
6. Reading order equals visual order at every width; layouts reflow, never scroll sideways (tables scroll inside their own box).

## 4. Visual system

### 4.1 Colour

Values are in `docs/design/tokens.css`. Roles:

| Token | Role |
|---|---|
| `--bg`, `--surface`, `--sunk` | Page ground, raised surfaces, inset areas |
| `--ink`, `--ink2` | Primary and secondary text |
| `--line`, `--ctl` | Dividers; control borders |
| `--accent`, `--on-accent`, `--soft` | Primary buttons and links; text on them; selected tint |
| `--warn`, `--warn-soft` | Warnings and destructive actions |
| `--side-*` | Colour-blocked sidebar |
| `--brand2` | Second brand colour (middle tier of the mark) |

Themes: light (default), dark, high-contrast light, high-contrast dark.
Accents: Teal (default), Indigo, Plum, Graphite. High-contrast themes ignore the accent.

### 4.2 Measured contrast

WCAG 2 contrast ratios computed from the token values during the design session. Plan section 5 targets 7:1 for text.

| Pair | Light | Dark | High-contrast light | High-contrast dark |
|---|---|---|---|---|
| Primary text on page ground | 16.19 | 16.22 | 21.00 | 21.00 |
| Secondary text on page ground | 8.86 | 10.43 | 21.00 | 21.00 |
| Secondary text on inset areas | 8.05 | 11.01 | 21.00 | 21.00 |
| Accent text on surface | 9.43 | 9.70 | 12.49 | 16.39 |
| Accent text on selected tint | 7.84 | 7.51 | 10.41 | 11.24 |
| Text on accent (buttons) | 9.43 | 10.16 | 12.49 | 16.39 |
| Warning text on warning tint | 6.81 | 7.79 | 11.09 | 9.76 |
| Sidebar text on sidebar | 9.43 | 13.45 | 21.00 | 21.00 |
| Sidebar secondary text on sidebar | 7.43 | 8.65 | 21.00 | 21.00 |
| Control border on surface (3:1 needed) | 4.36 | 5.62 | 21.00 | 21.00 |

Alternative accents, lowest text ratio in each (accent text on selected tint): Indigo 8.10 light, 7.09 dark; Plum 8.54 light, 7.26 dark; Graphite 10.97 light, 9.18 dark.

One pair misses the 7:1 target: warning text on the warning tint in the light theme (6.81:1). It passes WCAG AA (4.5:1). Darken `--warn` or lighten `--warn-soft` before M2 exit. These figures must be reproduced by the automated token contrast tests planned in section 5 of the plan.

### 4.3 Type

- Default typeface: Inter. User options: Atkinson Hyperlegible, Lexend, Source Serif 4, device sans-serif, device serif. Regular (400) and bold (700) only.
- All bundled faces are self-hosted as WOFF2. No network font loading.
- Wordmark: Atkinson Hyperlegible Bold, regardless of the user's typeface.

| Step | Base size | Use |
|---|---|---|
| Small | 16 px | User setting |
| Standard | 18 px | Default body size |
| Large | 21 px | User setting |
| Larger | 24 px | User setting |
| Largest | 28 px | User setting |

Fixed roles relative to the base: page title 34 px at Standard, section title 22 px, small text 16 px. Implement all sizes in `rem` so the user's step scales everything. Line spacing steps: 1.5, 1.75, 2.

### 4.4 Shape and spacing

- Corner radius: 8 px controls, 12 px cards, full pill for top-bar items and the current sidebar item.
- Borders: 2 px on controls, 1 px on cards and dividers.
- Focus: 3 px outline in the accent colour, 2 px offset, on every focusable element.
- Spacing steps in use: 4, 8, 12, 16, 20, 24, 32, 48 px.
- Content widths: forms 800 px (1120 px when laid out in columns), lists full width beside the sidebar, settings and import 1120 px.

## 5. Application shell

| Part | Design |
|---|---|
| Top bar | 68 px. Left: mark and wordmark linking to the project list. Middle: project search (list view only) or Admin tabs (project list only). Right: Display link, signed-in user chip with initials, Sign out. Wraps to further lines on narrow screens |
| Sidebar (list view) | Colour block in the accent colour. Project name and "Switch project"; Tables with record counts; Collections with counts and "New collection"; Import, Export, Trash pinned at the bottom. Stacks above the content on narrow screens |
| Breadcrumb | On record, collection, and import screens in place of the sidebar: Project / Table / Item |
| Footer | Licence line and "Source code" link on every screen, including sign-in (AGPL section 13) |

## 6. Screens

### 6.1 Designed

| Screen | Mock-up | Milestone | Key elements |
|---|---|---|---|
| Sign in | `sign-in.html` | M1 | Lockup, username, password with "Show password", plain-language reset note |
| Project list | `projects.html` | M1 | Project cards (tables, record count, last change, sharing); Admin-only actions and tabs |
| List view | `list.html` | M2 | Sidebar, find box, sort, filter, table with linked first column, paging |
| Record form | `record.html` | M2, M4 | Fieldsets for details, files, linked records; relation control; record paging; Save, Back, Move to trash |
| Saved collection | `collection.html` | M4 | Name, all/any, rule rows, sort, live match preview |
| Import review | `import.html` | M3 | Step indicator, file summary, target table, field mapping table, warnings, commit note |
| Display settings | `settings.html` | M2 | Theme, accent, text size, line spacing, typeface, reduced motion, live preview |

### 6.2 Not yet designed

These are in v1 scope (plan section 8) and have no mock-up. Each should be designed with the same tokens and shell before its milestone starts.

| Screen | Milestone |
|---|---|
| Installer and requirement check | M1 |
| People (users) and per-project sharing | M1 |
| New project; table and field (schema) editor | M1 |
| Search results across tables | M2 |
| Empty states, error pages, confirmation dialogs | M2 |
| Import steps 1, 3, 4 (choose file, progress, report) | M3 |
| Export | M3 |
| Relation picker ("Choose a different room", "Link a warranty") | M4 |
| Attachment upload and image view | M4 |
| Trash and restore | M4 |
| Simple form editor and source view | M5 |
| Plugins | M3 |
| About page | M7 |

## 7. Components

Build these once as server-side partials plus stylesheet classes; every screen above is composed from them.

| Component | Variants |
|---|---|
| Button | Primary (filled accent), secondary (outlined), destructive (warning colours), disabled (dashed outline) |
| Link | Inline; top-bar pill; sidebar row |
| Text input, select, date input, text area | With label above; optional help text below; error text tied to the field |
| Checkbox, radio | 24 px control inside a 44 px row; radio card (bordered tile with sample) |
| Rating | Radio group 1 to 5 with end labels |
| Relation field | Linked value plus "choose" button |
| File row | Name, type, size, Open, Remove |
| Linked-record row | Title link, secondary line, Unlink |
| Fieldset card | Legend as section title |
| Data table | Sunk header, 14 px row padding, right-aligned numbers, horizontal scroll container |
| Step indicator | Done, current, upcoming |
| Notice | Information (sunk), warning (warning colours) |
| Top bar, sidebar, breadcrumb, footer | As section 5 |

## 8. Appearance settings

Per-user, stored in the system database, applied by the server as classes on the root element so they work without JavaScript.

| Setting | Values | Default | Root class |
|---|---|---|---|
| Theme | light, dark, high-contrast light, high-contrast dark | light | none, `t-dark`, `t-hcl`, `t-hcd` |
| Accent colour | teal, indigo, plum, graphite | teal | none, `a-indigo`, `a-plum`, `a-graphite` (omitted in high-contrast themes) |
| Typeface | Inter, Atkinson Hyperlegible, Lexend, Source Serif 4, device sans-serif, device serif | Inter | none, `f-atk`, `f-lexend`, `f-serif4`, `f-sans`, `f-serif` |
| Text size | small, standard, large, larger, largest | standard | sets root font size |
| Line spacing | standard, roomy, very roomy | standard | sets root line height |
| Reduce movement | on, off | off (and on whenever the device asks for reduced motion) | disables transitions |

The settings screen shows a live preview. Without JavaScript the preview updates on Save.

## 9. Differences from the current plan and ADRs

Each needs a decision from the lead developer; none has been changed in the plan.

| # | Item | Model UI | Plan or ADR | Proposed resolution |
|---|---|---|---|---|
| 1 | Record form layout at wide widths | Details fields flow into two or three columns (minimum 300 px each), one column on narrow screens | ADR 0003 and plan section 4: auto-generated form is a single column | Keep single-column source order; let the default form CSS use a reflowing grid. Confirm, then amend plan section 4 |
| 2 | Mark colour inside the application | Follows the user's accent | Not specified | Accept; fixed brand colours are for use outside the interface |
| 3 | Sidebar on record screens | Replaced by a breadcrumb | Not specified | Accept; keeps editing focused |
| 4 | Warning contrast in light theme | 6.81:1 | Plan section 5 targets 7:1 | Adjust the token before M2 exit |
| 5 | Sign out placement | Visible link in the top bar | Not specified | Accept |

## 10. Implementation rules for this stack

- No build step: one hand-written stylesheet (plus one per bundled font face declaration), starting from `docs/design/tokens.css`.
- No inline `style` attributes or inline scripts in application output; the mock-ups use inline styles only because of the design tool. Use classes so the Content-Security-Policy in plan section 7 can stay strict.
- Form CSS (ADR 0003) may reference tokens but may not redefine them.
- Fonts: WOFF2, Latin subsets, regular and bold, `font-display: swap`, served from `public/assets/fonts/` with each family's licence file.
- Every screen must work without JavaScript; JavaScript only enhances (live settings preview, autosave, pickers).
- UI strings come from the single strings file required by plan decision 11; use the wording in the mock-ups as the first draft.
- The mark in the top bar is inline SVG using `currentColor`; the favicon is `docs/brand/tiffindb-mark-small.svg`.

## 11. Work by milestone

| Milestone | UI work | Exit check added by this plan |
|---|---|---|
| M0 | Copy tokens into the application stylesheet; add fonts and licences; record them in `docs/dependencies.md` | Empty page renders the shell in all four themes |
| M1 | Shell, sign-in, project list; design and build installer, people, sharing, schema editor | Screens match the mock-ups at 1360 px and 390 px |
| M2 | List view, record form, display settings, search results, empty and error states | Token contrast tests cover all 10 theme and accent combinations; warning token fixed |
| M3 | Import review plus the three undesigned import steps; export | Import flow usable by keyboard only |
| M4 | Collection editor, relation picker, attachments, trash | Relation and attachment controls match the record mock-up |
| M5 | Form editor and source view | Editor output renders correctly under every appearance setting |
| M7 | About page; favicon and app icon wired in | Visual pass of every screen in every theme |

## 12. Open items

1. Decisions on the five differences in section 9.
2. Confirm the font licences against upstream licence files (ADR 0007).
3. Measure the size of the four bundled font families against shared-host limits.
4. Design the screens listed in section 6.2.
5. Screen-reader passes on the mock-up structure have not been done; the mock-ups were checked visually only.
