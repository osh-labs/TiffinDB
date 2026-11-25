# Forms (for Admins)

Each table has a form: it decides which fields the record screen shows, in what order, how wide, and in which sections. Changing the form never changes the records. A record keeps all its values, whatever the form shows, and a field that is left off the form is left unchanged when someone saves the record.

Only Admins can change forms. Members see the record screen as the form lays it out.

## The standard form

A new table starts with the standard form, which TiffinDB makes from the table's fields:

- a section called Details with every field in field order, except file fields and link fields that hold several records; long text and rating fields take the whole width;
- a section called Files with the file fields, when the table has any;
- a section for each link field that holds several records, named after the field.

While a table has the standard form, it follows the table's fields by itself: add, move, or delete a field and the form changes with it. Once you save a change to the form, it is arranged by hand. From then on, a field added to the table appears at the end of the form's first section, and a deleted field disappears from the form, but nothing else moves by itself.

## Opening the form tools

1. Choose **Tables and fields** in the sidebar, then the table.
2. Choose **Edit the form**.

The form pages share a row of tools at the top:

- **Arrange**: the form editor.
- **Source**: the form as text.
- **Upload a form file**: use a form file from your device.
- **Download the form**: save the form as a file.
- **Back to the standard form**: throw away the arranged form.

## Arranging the form

**Form for Items** lists the form's sections and fields in reading order. A note at the top says whether this is the standard form or one arranged by hand.

For each field you can:

- type a label, to show something other than the field's name. Leave it empty to use the field's name;
- choose a width: Automatic, Full width, Two thirds, Half, or One third. Widths apply where the screen is wide enough; on a narrow screen every field takes the whole width;
- choose **Move up** or **Move down** to move it, also into the section before or after;
- choose **Hide** to take it off the form.

For each section you can type its name, move it up or down with everything in it, or choose **Remove section** to remove the section but keep the fields that were in it. **Add a section** adds a new section, called "New section", at the end of the form; rename it and move fields into it.

Under **Not on this form** are the fields of the table that the form does not show. Choose **Add** beside one to put it back, at the end of the first section.

A field that must be filled in, but is not on the form, is not required when records are saved through the form. The list under **Not on this form** marks such fields.

Headings, text, dividing lines, and pictures that were added in the source show as rows too. You can move or remove them here; to change what they say, use the source.

### Saving

Nothing is saved while you arrange. Each button press shows the page again with the change made, and the changes stay on the page until you choose **Save the form**. Leaving the page, or choosing **Cancel**, drops them. If you press Enter in a label or name box, the labels and names you typed are applied without moving anything.

When you save, the notice says the form has been saved. If the result is the same as the standard form, the form becomes the standard form again and follows the table's fields by itself.

If another Admin saved the same form after you opened it, TiffinDB does not save yours straight away. It tells you, and choosing **Save the form** again replaces their version with yours.

## The source view

**Source** shows the whole form as text, in TiffinDB's form format. The format is a small part of HTML: a `div` holding `fieldset` sections, each with a `legend` for its name, and one `div` for each field, naming the field by its key. The full rules are in the project file documentation (`docs/formats/tiffin.md`, section 12). In short:

- Element and attribute names are lower case, and every element is closed.
- Besides fields and sections, a form can hold headings (`h2`, `h3`), paragraphs (`p`) with bold, italic, line breaks, and links, dividing lines (`hr`), and pictures (`img`) of files attached to records.
- Links must start with http:// or https://.
- A form can be up to 256 KB.

Edit the text in the box, then:

- **Check** looks for problems without saving. If there are none, it says "No problems found." and shows what the form shows: which fields are not on it, and anything that will show nothing.
- **Save** checks the text and, if it has no problems, saves it.
- **Cancel** goes back to the editor without saving.

When the text has a problem, nothing is saved. The page names the problem and the line it is on, for example "Line 7: The element p cannot have the attribute onclick, or is missing one it needs." Fix that line and try again.

TiffinDB keeps its own tidy copy of what you save. Comments and spacing are not kept, and the notice tells you when the text was rewritten.

## Downloading and uploading a form

**Download the form** saves the form to your device as a file, for example "Items form.html". Use it to keep a copy before you change the form, or to use the same form for a table in another project.

**Upload a form file** puts a form file in place of the current form:

1. Choose **Upload a form file**.
2. Choose the file on your device. It must be in TiffinDB's form format, saved as UTF-8, and at most 256 KB.
3. Choose **Upload and use it**.

If TiffinDB can read the file, it replaces the form at once; there is no separate confirmation, so download the current form first if you may want it back. If the file has a problem, the page names it and nothing is changed.

A form names fields by their keys, which are different in every table. A file downloaded from one table works for another table only where the keys match, such as a copy of the same project. Field elements that name a key the table does not have show nothing, and the editor marks them so you can remove them.

## Back to the standard form

**Back to the standard form** replaces the arranged form with the standard form. Labels, widths, hidden fields, sections, headings, and text you added are gone, and there is no undo.

1. Choose **Back to the standard form**.
2. The page **Go back to the standard form?** offers **Download the current form** first, so that you can upload it again later.
3. Choose **Use the standard form**, or **Cancel** to keep the form as it is.

If the table already has the standard form, the page says so and offers only **Back to the form**.

## When a saved form cannot be used

If a saved form breaks the rules of the format, for example after it was changed outside TiffinDB, records are shown with the standard layout meanwhile, and the stored text is kept as it was. The editor and the source view both say so. Fix the text in the source view, or save a new arrangement in the editor, to replace it.
