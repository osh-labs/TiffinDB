# ADR 0020: HTML documentation set, its screenshots, and how they are produced

Status: Accepted (provisional, awaiting maintainer confirmation of the details). The maintainer asked for a browser-viewable documentation set (a user guide in Simplified Technical English for non-technical users, an install guide for technical users, and a roadmap and handoff for a developer new to the project), with SVG graphics and rendered screenshots. This record settles where that set lives, what it may contain, and the one change it needs in the repository rules (binary screenshot files). It brings forward the "host and user documentation" part of milestone M7 (plan section 8); the rest of M7 is unchanged.

## Context

Until now every document in the repository was Markdown, written for the build (plan, specifications, decision records, ledger). There was nothing a non-technical user, a person installing the application, or a new developer could read from start to end. The repository has rules that bear on a documentation set:

- Everything is public and permanent, and only synthetic data may appear (CLAUDE.md, Public Repository Rules). A screenshot is data.
- The SPDX test is closed-world (M0 spec section 13): a new file type fails the test until a header rule or an exemption with a reason exists. PNG files have no comment syntax, so they need an exemption.
- No build step (ADR 0001), no network calls, no third-party assets without a recorded licence.

## Decisions

### 1. Location and form

The set lives in `docs/manual/` as static HTML that opens directly from a checkout (`docs/manual/index.html`) with no server and no build step:

| File | Content |
|---|---|
| `index.html` | Entry page: the three guides, what TiffinDB is, project status |
| `user-guide.html` | User guide, written in Simplified Technical English (ASD-STE100 writing rules) |
| `install-guide.html` | Install guide: shared hosting, own Apache server, development server, operations |
| `handoff.html` | Developer handoff and roadmap |
| `assets/manual.css`, `assets/manual.js` | Shared stylesheet and one optional script; a page may add `assets/<page>.css` |
| `img/*.png` | Screenshots of the running application |

The Markdown records (`docs/plan.md`, `docs/decisions/`, `docs/specs/`, `docs/formats/`, `docs/build/`) stay the source of truth for design and build state. The HTML set explains and points to them; where the two disagree, the Markdown records and the code win and the HTML is corrected.

### 2. Self-contained pages

- No external resources: no content delivery network, no remote fonts, images, or scripts, and no analytics. Hyperlinks to other sites are ordinary links only.
- Colours come from the application design tokens (`docs/design/tokens.css`), light by default and dark when the reader's device asks for it. The pages reference the application's own font files by relative path (`public/assets/fonts/`) and fall back to device fonts when the set is copied somewhere else, so no font file is duplicated.
- Diagrams are inline SVG, drawn for this project, themed with the stylesheet's `d-` classes, each with a title and a description.
- The pages must be complete without JavaScript. `manual.js` only marks the current section and folds the contents list on a narrow screen.
- Every HTML, CSS, and JavaScript file carries the SPDX header of its type (M0 spec section 13).

### 3. Screenshots

- Screenshots show only the demo data that `tools/docs/seed-demo.js` creates: obviously synthetic household items in a project named "Home Inventory", people named "Example Admin" and "Example Member". No screenshot may show a real installation, a password, a setup code, or a session value.
- They are PNG files under `docs/manual/img/`, named `<page>-<subject>.png`, reduced to an indexed palette to keep the repository small.
- The SPDX exemption register gains one entry, `docs/manual/img/*.png`, with this reason: binary screenshot of synthetic demo data, covered by the repository licence. PNG files anywhere else still fail the closed-world test.

### 4. How the set is reproduced

`tools/docs/` holds the scripts that make the demo data and the screenshots (procedure in `tools/docs/README.md`): `seed-demo.js` installs a fresh instance through the real setup page and fills it through the real screens, and one `shots-<page>.js` script per page takes that page's screenshots. They drive a headless browser with Playwright.

- Node.js and Playwright are tools of the person who regenerates screenshots. They are not application or test dependencies: nothing under `src/`, `public/`, `plugins/`, or `tests/` uses them, `composer test` does not run them, and they are not installed by `composer install`. They are recorded in `docs/dependencies.md` as documentation tooling (Playwright: Apache-2.0).
- Passwords for the demo instance are generated at run time and written only to a state file outside the repository.

### 5. Language of the user guide

The user guide follows the writing rules of ASD-STE100 Simplified Technical English as far as they apply to software: short sentences, one instruction in each sentence, active voice, one meaning for each word, and a fixed set of technical names taken from the application's own screens. The repository does not hold the STE dictionary, and the guide is not certified against the specification; it is written to its rules. Text shown on the application's screens is quoted exactly, even where it is not STE.

### 6. Upkeep

A change to a screen, a setup step, a requirement, or the milestone state must update the page that describes it in the same change. When a screen changes, the screenshot scripts are run again and the changed PNG files are committed.

## Consequences

- The repository now contains binary files that change when the screens change. The palette reduction and cropping keep each file small.
- The M7 documentation work becomes a review and completion of this set, including the parts that only a release makes true (release archive, upgrade procedure, verified shared-host steps).
- The install guide states plainly which steps have not been run on a real Apache server or on the reference shared host; those statements are removed only when the deferred host check (ledger section 5) has been done.

## Open for the maintainer

1. Whether the set should also be published (for example from the repository's `docs/` folder by a static pages service). Nothing in the set depends on that.
2. Whether the application should link to the user guide from its footer or an About page once a release bundles the set (M7).
