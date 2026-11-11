# ADR 0003: Forms as HTML and CSS

Status: accepted by the lead developer (planning session)

## Context

Forms are the user-configurable front end for viewing and editing records. They must be quick to develop, generated automatically from a schema (including imported schemas), and adjustable by people who do not write code.

## Decision

- A form is stored as an HTML fragment plus a scoped CSS block. Elements bind to data through attributes (working name `data-tiffin-field`).
- No per-form JavaScript.
- A basic form is auto-generated for every table on creation and after import.
- A simple in-browser editor (Admin) supports reorder, show/hide, width, grouping, and label changes. It reads and writes the same HTML/CSS.
- Admins may hand-edit the source. Form source is held only in the project database; download and upload as a file is provided for external editing, with no on-disk mirror.
- Stored form HTML is sanitized against an allowlist, and user accessibility settings take precedence over form CSS.

## Alternatives considered

| Option | Reason not chosen |
|---|---|
| Hand-edited templates only | Not usable by non-technical users |
| Free-form drag-and-drop canvas | Largest build effort; absolute positioning conflicts with responsive layout, zoom, and screen-reader order |
| JSON layout description rendered by code | Loses the "plain HTML/CSS" development speed and hand-editability |

## Consequences

- A sanitizer and a binding renderer are required core components.
- The editor is constrained to grid-based layouts, which keeps reading order equal to visual order.
- The attribute vocabulary becomes part of the documented project format and must be versioned.
