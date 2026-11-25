# Records

A record is one entry in a table: one item in Items, one recipe in Recipes. Each record holds a value for each field of its table, such as its Name, the Room it is in, and the date it was Purchased.

## Adding a record

1. Open the table from the sidebar.
2. Choose **Add item**. (The button names what one record in the table is called, so in a Recipes table it may say "Add recipe".)
3. The **New item** page shows the table's fields. Fill in what you know; you can change it later.
4. Choose **Save**.

TiffinDB saves the record and shows it, with the notice that it has been added. **Cancel** goes back to the list without saving.

Some fields are filled in for you or need a particular kind of value:

- An auto-number field shows "Given when you save". TiffinDB gives each new record the next number.
- A duration is written as hours and minutes, for example 1:30.
- A rating is chosen from a row of numbers; "Not rated" leaves it empty.
- A choice field offers the choices an Admin has set; "Not chosen" leaves it empty.
- A currency field shows its currency in brackets after the field's name, for example "Price (US dollars)".

Files and links to other records can be added only once the record has been saved. Until then, those fields say so (see [Files](files.md) and [Linked records](#linked-records) below).

## Opening and changing a record

Choose a record's title in a list to open it. The record screen shows its fields in a form you can change straight away. Above the form, TiffinDB shows where the record is in the list ("Item 2 of 3") and when it was last saved; below it, who added it and who last changed it.

To change the record, edit the fields and choose **Save**. The notice says that your changes have been saved.

- **Previous** and **Next** go to the records before and after this one, in the order of the list you came from. If you had found or filtered records, they stay found or filtered.
- **Back to list** returns to the list.
- Neither of those saves what you typed. Choose **Save** first.

Some buttons on the record screen, such as those for files and links, take you to another page. The form says "Save your changes first: the buttons here leave this page." Save before you use them, or what you typed is lost.

## Required fields

A field that must be filled in has "(required)" after its name. If you save without filling it in, the record is not saved: the page lists the problems at the top, and the field says "Fill this in." Fill it in and save again.

A required link field or file field is checked from the second save on: a new record can be saved without them, because they can be added only after the first save. After that, **Save** asks you to link at least one record, or add at least one file, before it saves.

If an Admin has left a required field off the table's form, that field is not required when you save through the form (see [Forms](forms.md)).

## When someone else changed the record

Two people can open the same record at the same time. If someone saves a change after you opened the record, and you then choose **Save**, TiffinDB does not overwrite their change. Instead it shows **This item was changed while you were editing it**, which says who saved and when, and lists the fields that differ: the saved version beside your version.

Nothing you typed has been saved yet. Choose one of:

- **Keep the saved version**: go back to the record as it is now saved. What you typed is dropped.
- **Save my version anyway**: save what you typed in place of the other change.

If the other change was to fields you did not change, the page says so, and saving keeps both changes.

## Linked records

A link field connects records, for example an item and the room it is in. A link field can hold one record, or several, depending on how an Admin set it up. The link also shows from the other side: the linked room lists the items that link to it.

In the record screen, a link field shows the records it links to, each with **Unlink**. Unlinking does not delete the other record; it only removes the link.

To link records, choose **Link a record** (or **Link another item** when there are links already). For a field that holds one record, the button is **Choose a record**, or **Choose a different item** when one is chosen. Either takes you to the page for choosing:

- **Linked now** lists what the field already links to, each with **Unlink**.
- Below it are the records you can link, with **Link** (or **Choose**) beside each one. Each press saves at once; you do not need to choose **Save** afterwards.
- Find a record by typing part of its name, or other text it holds, in the box and choosing **Find**. **Show all** lists every record again.
- If the record you want does not exist yet, choose **Add a new item and link it** (or **Add a new item and choose it**). Fill in the new record and save it; TiffinDB links it and brings you back to choosing.
- A record that can be linked from only one record at a time shows which record it is already linked to, and cannot be linked again until that link is removed.
- **Back to Stand mixer** (the title of your record) returns to the record.

A link to a record that is in the trash is not shown, but it is not lost: it comes back when the record is restored. A field that holds one record cannot be given another while its linked record is in the trash; restore that record and unlink it, or ask an Admin to empty the trash, first.

## Moving a record to the trash

On the record screen, choose **Move to trash**. TiffinDB goes back to the list and says the record has been moved to the trash, with an **Undo** button. Choose **Undo** to bring the record straight back.

Records in the trash can also be restored later from the trash (see [The trash](trash.md)). Their files and links come back with them.

If you open a record that is in the trash, for example from a bookmark, the page says **This item is in the trash** and offers **Restore**.
