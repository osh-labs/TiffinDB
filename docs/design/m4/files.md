# M4 screen design: attachment upload, file page, removal, serving

Status: design for milestone M4 (ui-design-plan section 6.2, "Attachment upload and image view"; no mock-up). Logic: `docs/specs/M4-core.md` sections 3, 4.2, 10.2; ADR 0018. Implemented by M4-WP6. Strings: `strings/en/files.php` (WP6). Example data is synthetic.

## 1. Overview

| Screen | URL | Status |
|---|---|---|
| Upload (add) | `GET /projects/{p}/tables/{t}/records/{r}/files/{f}/new` | 200 |
| Upload (replace) | the same with `?replace={a}` | 200 |
| Save the upload | `POST .../records/{r}/files/{f}` (multipart, field `file`, hidden `replace`) | 303 to the record; 413; 422 |
| File page (image view) | `GET .../records/{r}/files/{f}/{a}` | 200 |
| Remove confirmation | `GET .../records/{r}/files/{f}/{a}/remove` | 200 |
| Remove | `POST .../records/{r}/files/{f}/{a}/remove` | 303 to the record |
| The file itself | `GET /projects/{p}/files/{a}` (`?download=1` to download an image) | 200 with the file; 404 |

Shell for the pages: signed-in shell, breadcrumb (project; table to the list; the record's title to the record; then the page's own item), `main#main.page.page-record.page-record-narrow` (M2 class: 800 wide). Open to Admins and granted Members (`records.edit` for upload and removal, `records.view` for the file page and the file). A record in the trash, a field that is not an attachment field of the table, an attachment the record does not hold in that field, and an ungranted person: 404.

## 2. Upload

Page title and `h1`: `files.upload.title` "Add a file to {title}" (add) or `files.replace.title` "Replace {name}" (replace). Breadcrumb last item `files.upload.crumb` "Add a file" or `files.replace.crumb` "Replace a file".

1. Breadcrumb; flash; error summary at 422.
2. `div.page-head`: `h1`; `p.lead`: `files.upload.lead` "The file is added to {field}." (`{field}` the slot label or field name); for replace `files.replace.lead` "The new file takes the place of {name} in {field}. {name} is deleted from TiffinDB when the new file is saved."
3. `form` POST `.../files/{f}`, `enctype="multipart/form-data"` (the M3 rule), `csrf`; for replace hidden `replace` = `{a}`; the `file-field` partial (M3) with `name` `file`, label `files.upload.label` "File", help `files.upload.help` "Any kind of file, up to {max}. Pictures in PNG, JPEG, GIF, or WebP are shown on the record; other files are kept for download." (or for an images-only field `files.upload.help_images` "A picture in PNG, JPEG, GIF, or WebP, up to {max}."), `accept` empty (any file) or `.png,.jpg,.jpeg,.gif,.webp` (images only; the server checks the bytes regardless); `{max}` from `UploadLimits::effective` with `Format::fileSize`.
4. `div.form-actions`: `button.button.button-primary.button-large` `files.upload.submit` "Add the file" (replace: `files.replace.submit` "Replace the file"); `a.button.button-large` to the record: `form.cancel` "Cancel" (M1).
5. `p.form-note` `files.upload.note` "{count|This field holds # file.|This field holds # files.} At most {max_files} per {item}." (from `UploadScreen::count`).

Success: 303 to the record with `notice.file_added` or `notice.file_replaced` (`record-controls.md` section 4). Failures: the M3 upload problems (`problem.upload.missing`, `problem.upload.too_large`, `problem.upload.failed`, `problem.upload.no_space`) and the M4 ones (`problem.upload.empty`, `problem.attachment.not_image`, `problem.attachment.too_many`, `problem.attachment.store_failed`) at the file field and in the summary, status 422; a POST larger than `post_max_size` gets the M3 413 page.

Keyboard: Tab to the file input; Enter or Space opens the browser's file chooser; Tab to "Add the file". Without script: unchanged.

## 3. File page (image view)

Page title and `h1`: the file's original name. Breadcrumb last item: the file's name.

1. Breadcrumb; flash.
2. `div.page-head`: `h1` the name; `p.lead` `files.show.lead` "In {field} of {title}."
3. For the four image types: `figure.file-figure` with `img.file-image-large` (`src` `/projects/{p}/files/{a}`, `alt` the file's name). Other files: `p.static-value` `files.show.not_shown` "TiffinDB does not show this kind of file. Download it to open it on your device."
4. `dl.facts` (M3 partial `facts` with heading `files.show.facts` "About this file"): `files.show.type` "Kind" ("PDF", "image", "file" from the M2 type words), `files.show.size` "Size" (`Format::fileSize`), `files.show.added` "Added" ("{date} by {name}" with `files.show.added_by`, or the date alone when the person is unknown).
5. `div.form-actions`: `a.button.button-primary.button-large` to `/projects/{p}/files/{a}?download=1`: `files.show.download` "Download" (for non-images the plain serving address, which always downloads); when the person can edit: `a.button.button-large` to the upload page with `replace`: `files.show.replace` "Replace", and `a.button.button-large.button-danger` to the remove confirmation: `files.show.remove` "Remove"; then `a.button.button-large` to the record: `files.show.back` "Back to {title}".

## 4. Remove confirmation

Page title and `h1`: `files.remove.title` "Remove {name}?". Breadcrumb last item `files.remove.crumb` "Remove a file". The M2 confirmation pattern (`docs/design/m2/states.md` section 3):

1. `p` `files.remove.body` "{name} will be removed from {title} and deleted from TiffinDB. This cannot be undone."; when the same content is also attached elsewhere: no extra text (the stored file then stays for the other record; the person sees only this record's change).
2. `form` POST `.../remove` with `csrf`; `div.form-actions`: `button.button.button-danger.button-large` `files.remove.submit` "Remove the file"; `a.button.button-large` to the record: `form.cancel`.

Success: 303 to the record, `notice.file_removed`.

## 5. The file itself (serving)

Not a page. `GET /projects/{p}/files/{a}` answers with the bytes and the headers of ADR 0018 decision 5 (`docs/specs/M4-screens.md` section 2): a PNG, JPEG, GIF, or WebP inline with its own type (as `img` source and when opened directly), with `?download=1` as an attachment; every other file as `application/octet-stream` and an attachment. The download name is the original name with the M3 encoding. Every failure is the plain 404 page. A signed-out request is redirected to sign-in like every page.

## 6. Narrow layout

The large image takes the column width and at most 70 % of the window's height; facts stack; buttons wrap. At 320 px Largest nothing scrolls sideways; a long file name wraps in the heading.
