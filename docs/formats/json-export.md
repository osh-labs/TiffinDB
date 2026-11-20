# TiffinDB JSON export format, version 1

Status: format version 1, written by TiffinDB from milestone M3 (export plugin `export/json`); milestone M4 adds the `collections` member (section 7) without changing the version, because the change only adds a member (section 9). Provisional until the maintainer confirms ADR 0017 and ADR 0019. This document lets anyone read a TiffinDB JSON export with ordinary tools and no TiffinDB code. Every example is synthetic.

A JSON export holds one whole project: its tables, fields, live records, links between records, the descriptions of the files attached to live records, and its saved collections (from M4). It does not hold the files themselves, records in the trash, files not attached to any live record, forms, or anything about people other than their keys. For a complete backup that TiffinDB can import again, use a project archive (`docs/formats/tiffin.md` section 13).

## 1. The document

One UTF-8 JSON text (RFC 8259), no byte order mark, one top-level object. Strings hold characters as UTF-8, not as `\u` escapes, except that `"`, `\`, control characters (U+0000 to U+001F), and the line and paragraph separators U+2028 and U+2029 are escaped; `/` is not escaped. The file is always valid UTF-8: text that TiffinDB holds as invalid UTF-8 is written with each invalid byte sequence replaced by U+FFFD (the replacement character, `�`), so such a value can differ from the stored bytes. TiffinDB writes members in the order shown, keys and record ids as given, and a line break after the top-level members and after each record so that large files can be read line by line; readers must not rely on the layout.

```json
{
  "format": "tiffindb-json",
  "format_version": 1,
  "exported_at": "2026-11-19T10:15:00Z",
  "exported_with": "0.3.0-dev",
  "project": {
    "key": "q7mzk2wbx4ae",
    "name": "Home Inventory",
    "created_at": "2026-11-12T03:15:00Z",
    "updated_at": "2026-11-19T09:58:12Z",
    "file_format_version": 3
  },
  "relations": [
    {"key": "c2vb6nxq4tkm",
     "left": {"table": "h3kq7vn2xbma", "field": "r5vk3pn7qx2m", "max": "one"},
     "right": {"table": "p6wz2kq7nm3v", "field": "k7wq2zn4mx3p", "max": "many"}}
  ],
  "attachments": [
    {"id": 1, "sha256": "9f86d081884c7d659a2feaa0c55ad015a3bf4f1b2b0b822cd15d6c15b0f00a08", "size": 48213,
     "media_type": "application/pdf", "original_name": "receipt.pdf", "created_at": "2026-11-12T03:20:00Z"}
  ],
  "tables": [
    {"key": "h3kq7vn2xbma", "name": "Items", "item_name": "item", "title_field": "m4pz7k2qsw3n",
     "fields": [
       {"key": "m4pz7k2qsw3n", "name": "Name", "type": "text", "options": {"max_length": 500}, "required": true, "help": ""},
       {"key": "b7xk2mq4nz3p", "name": "Value", "type": "currency", "options": {"code": "USD", "minor_digits": 2}, "required": false, "help": ""},
       {"key": "r5vk3pn7qx2m", "name": "Room", "type": "relation", "options": {"relation": "c2vb6nxq4tkm", "side": "left"}, "required": false, "help": ""}
     ],
     "records": [
       {"id": 9, "created_at": "2026-11-12T03:16:00Z", "created_by": "a2kq7vn3xbmz", "updated_at": "2026-11-18T14:32:00Z", "updated_by": "a2kq7vn3xbmz",
        "values": {"m4pz7k2qsw3n": "Stand mixer", "b7xk2mq4nz3p": "329.00", "r5vk3pn7qx2m": [3]}}
     ]}
  ],
  "collections": []
}
```

## 2. Top-level members

| Member | Value |
|---|---|
| `format` | Always `"tiffindb-json"` |
| `format_version` | `1` for this document. A reader should refuse a higher number it does not know |
| `exported_at` | UTC time of the export, `YYYY-MM-DDTHH:MM:SSZ` |
| `exported_with` | The TiffinDB version that wrote the file |
| `project` | `key` (12 characters from `abcdefghijklmnopqrstuvwxyz234567`), `name`, `created_at`, `updated_at`, and `file_format_version` (the project file's format version, `docs/formats/tiffin.md` section 11) |
| `relations` | One object per relation between tables (section 5) |
| `attachments` | One object per file attached to a live record (section 6) |
| `tables` | One object per table, in the order TiffinDB shows them (section 3) |
| `collections` | From M4: one object per saved collection, in the order TiffinDB shows them (section 7). Last member of the document |

## 3. Tables and fields

Each table object: `key`, `name` (plural, "Items"), `item_name` ("item"), `title_field` (the key of the field used as each record's title, or `null`), `fields`, `records`.

Each field object, in the order TiffinDB shows them: `key`, `name`, `type` (one of the identifiers in section 4), `options` (an object whose members depend on the type, exactly as in `docs/formats/tiffin.md` section 6), `required` (boolean), `help` (string, possibly empty).

## 4. Records and values

Each record object: `id` (a positive integer, unique within the table, never reused), `created_at`, `created_by`, `updated_at`, `updated_by` (times as in section 2; person keys or `null`; a person key refers to an account in the installation that wrote the file and says nothing on its own), and `values`: an object with one member per field of the table, keyed by field key, in field order. Records are listed by `id`, ascending. Only live records are exported; records in the trash are left out.

| `type` | JSON value | Empty |
|---|---|---|
| `text`, `long_text` | String (`long_text` may hold `\n`) | `null` |
| `number` | Number in plain decimal notation, never an exponent, with the field's `options.decimals` fraction digits (`12` and `-3` when `decimals` is 0; `12.00` and `-3.25` when it is 2). A value stored before the field's `decimals` was lowered keeps its digits and is written as the shortest decimal that reads back as the same 64-bit floating-point number, which can have up to 17 significant digits (`27.318581000000002`) | `null` |
| `currency` | String with exactly `minor_digits` decimals and no symbol or separators: `"329.00"`, `"-5.50"`, `"1200"` when `minor_digits` is 0. The currency is the field's `options.code` | `null` |
| `date` | String `YYYY-MM-DD` | `null` |
| `time` | String `HH:MM:SS` (24-hour) | `null` |
| `date_time` | String `YYYY-MM-DDTHH:MM:SS`, local clock time with no time zone | `null` |
| `duration` | Integer number of seconds | `null` |
| `checkbox` | `true` or `false` | never empty |
| `choice` | String: the chosen label | `null` |
| `rating` | Integer from 1 to `options.max` | `null` |
| `url`, `email`, `phone` | String | `null` |
| `auto_number` | Integer | `null` |
| `relation` | Array of the `id`s of the linked records in the other table of the relation, in the order TiffinDB shows them; only live records | `[]` |
| `attachment` | Array of attachment `id`s (section 6), in the order TiffinDB shows them | `[]` |

## 5. Relations

Each relation object: `key`, and `left` and `right`, each with `table` (table key), `field` (the relation field's key in that table), and `max` (`"one"` or `"many"`: how many records on the other side one record of this table may link to). A relation from a table to itself has the same `table` on both sides and two different fields. The links themselves are the arrays in each record's relation values; the left field's array and the right field's array describe the same links from the two sides.

## 6. Attachments

Only files attached to at least one live record are listed, by `id` ascending; a file attached only to records in the trash, or to no record, is left out, so every `id` in an `attachment` value (section 4) is in this list. Each attachment object: `id`, `sha256` (of the file content, lower-case hexadecimal), `size` (bytes), `media_type`, `original_name`, `created_at`. The files are not in the export. In a TiffinDB project folder a file is at `attachments/<first two characters of sha256>/<sha256>` (`docs/formats/tiffin.md` section 8); in a project archive, at the same path inside the zip.

## 7. Collections

From M4 the document ends with a `collections` member: an array with one object per saved collection (`docs/formats/tiffin.md` section 4.8) whose table is in `tables`, in sidebar order, possibly empty. Each object: `key`, `table` (the table key), `name`, `match` (`"all"` or `"any"`), `rules` (an array of rule objects, each with `field`, `op`, and `values` exactly as stored, `docs/formats/tiffin.md` section 4.8.1), `sort` (a sort token such as `"t5kq2zm7wn4x.desc"`, or `null` for the table's default order), `created_at`, `updated_at`. Rules are written as stored, including rules that no longer fit the table (a deleted field, a removed choice); a reader that applies them should check each against `tables`. Example (synthetic):

```json
{"collections": [
  {"key": "w4kz7mq2nx3b", "table": "h3kq7vn2xbma", "name": "Under warranty", "match": "all",
   "rules": [{"field": "q2mz7k4wnx3b", "op": "is", "values": ["1"]},
             {"field": "t5kq2zm7wn4x", "op": "after", "values": ["2023-01-01"]}],
   "sort": "t5kq2zm7wn4x.desc", "created_at": "2026-11-20T10:00:00Z", "updated_at": "2026-11-20T10:05:00Z"}
]}
```

Choice rules hold the choice's key, which is listed with its label in the field's `options.choices`.

## 8. Reading the file

- Use any JSON parser. For very large exports, a streaming parser avoids loading the whole document.
- Every `number` value is the text of a 64-bit floating-point number and reads back as that number in any parser that uses such numbers. A value entered in TiffinDB has at most 15 significant digits and reads back with exactly its decimal digits; only the older values of section 4 have more. Read the text as a decimal if trailing zeros (`12.00`) matter. Currency amounts are strings so that no rounding is ever involved.
- Example with the `jq` tool: every item's name and value, `jq -r '.tables[] | select(.name=="Items") | .records[] | [.values.m4pz7k2qsw3n, .values.b7xk2mq4nz3p] | @csv' export.json`.

## 9. Versions

| Version | Introduced in | Changes |
|---|---|---|
| 1 | M3 | First version |
| 1 | M4 | Adds the `collections` member (section 7); nothing else changes, so the version stays 1 |

A later version may add members. Readers of version 1 should ignore members they do not know.
