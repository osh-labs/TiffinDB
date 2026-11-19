# M3 screen designs

Status: designs for milestone M3, provisional until reviewed. They say how the import review mock-up (`docs/design/model-ui/import.html`) is built in the application and design the M3 screens that have no mock-up (ui-design-plan section 6.2: import steps 1, 3, and 4; export; plugins), plus the project-archive import that M3 adds.

| Document | Screens |
|---|---|
| `components.md` | Stylesheet classes added to `app.css` for M3 |
| `import.md` | Step 1 (choose a file), reading the file, step 2 (check the fields, mock-up), reading options, step 3 (import progress), step 4 (report), import failed |
| `export.md` | Export: CSV, XLSX, JSON, and the project archive |
| `archive.md` | Import a project archive: form, name taken, result |
| `plugins.md` | Plugins list, plugin details, enable confirmation |

How to read them: as `docs/design/m1/README.md` and `docs/design/m2/README.md`. Every screen lists its elements in reading order, every string as a key with its English text, its states, keyboard and screen-reader behaviour, behaviour without JavaScript, and the narrow layout. The web-layer rules are in `docs/specs/M3-screens.md`; the core behaviour behind each action is in `docs/specs/M3-io.md`.

Conventions: `{p}` is a project key, `{job}` an import job key (also the key of the import's report), `{kind}` `import` or `export`, `{plugin}` a plugin id. Strings with a count use the plural placeholder (`{count|# row|# rows}`). Text that a plugin supplies (format names, reading options, "Read as" descriptions) is printed as escaped plain text, not as a string key (P133). Every screen in this folder is Admin-only; a Member never sees a link to it.

No static wireframes are included, for the reason given in the M1 README. All example names and data are synthetic.
