# ADR 0006: WYSIWYG form editor (post-v1)

Status: direction accepted by the lead developer (planning session); layout model proposed, not yet accepted

## Context

ADR 0003 gives v1 a simple form editor (reorder, show/hide, width, grouping, labels) and set aside a free-form canvas for v1 because of build effort and accessibility concerns. The lead developer has directed that a full WYSIWYG editor follows after v1 ships.

## Decision

- After v1, TiffinDB gains a full WYSIWYG form editor with drag and drop, alignment tools, and static text elements.
- It reads and writes the same stored HTML/CSS form representation defined in ADR 0003. There is one form format, not a second one for the new editor.
- The v1 simple editor remains available as the basic mode.
- The editor itself must be operable by keyboard (move, resize, and align without a pointer).

## Proposed layout model (needs maintainer confirmation)

Snap-to-grid placement using CSS grid with explicit row and column positions, rather than pixel-absolute positioning.

| Aspect | Snap-to-grid (proposed) | Pixel-absolute |
|---|---|---|
| Alignment tools | Align and distribute operate on grid tracks; results are exact | Free alignment to any pixel |
| Small screens and zoom | Collapses to a single column in reading order | Fixed canvas; requires scaling or horizontal scrolling |
| Text size settings | Rows grow with larger text | Overlap and clipping when text is enlarged |
| Screen-reader and tab order | Editor keeps DOM order equal to visual order | Order must be managed separately and can drift |
| Fidelity to a designed page | High, within the grid | Highest |

Pixel-absolute layout conflicts with the project's accessibility priorities (user text-size and contrast settings take precedence over form CSS), which is why the grid model is proposed.

## Consequences for v1

To avoid a format change later, the v1 form format must already support:

- Explicit grid placement (column, row, span) on any element, even though the v1 editor only sets order and width.
- Static elements that are not bound to a field: headings, text blocks, dividers, and images.
- A format version marker in each stored form.
- Sanitizer rules that cover these elements and placement properties from the start.

## Open points

- Exact element set for "text" (assumed: headings and free text blocks with basic inline formatting).
- Whether per-form styling (colours, borders, backgrounds) is offered, and how it is kept subordinate to user accessibility themes.
- Separate layouts per screen size, or one layout that reflows.
