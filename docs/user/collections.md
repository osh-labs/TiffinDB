# Collections

A collection is a saved search. It shows the records of one table that match its rules, such as "every item in the Kitchen bought before 2020". It updates by itself as records change: a record that starts to match appears in it, and one that stops matching leaves it. Nothing is copied or moved; the records stay in their table.

Collections are listed in the sidebar under **Collections**, each with the number of records it holds. Members and Admins can both make, change, and delete them. A project can have up to 50 collections.

## Making a collection

1. Open the table the collection is for, then choose **New collection** in the sidebar.
2. On **New collection**, type a name. Each collection in a project needs its own name.
3. Choose whether records must match all of the rules or any of them (see below).
4. Set the first rule: choose a field, then choose **Preview**. The rule then offers the tests that fit that field. Choose a test and, where the test needs one, the value to test against. Choose **Add a rule** for another one, up to 10 rules. **Remove** takes a rule away.
5. Choose an order in "Sort by", or leave it as the table sorts.
6. Choose **Preview** to see which records match. The preview shows how many match and the first few of them. Change the rules and preview again as often as you like; nothing is saved yet.
7. Choose **Save collection**.

TiffinDB saves the collection and opens it. **Cancel** leaves without saving.

Pressing Enter in the name box or a value box does the same as **Preview**.

## All or any

- "All of these rules": a record must pass every rule. Use this to narrow down, for example Room is Kitchen and Purchased is before 1 January 2020.
- "Any of these rules": a record must pass at least one rule. Use this to gather, for example Room is Kitchen or Room is Garage.

## Rules

Each rule tests one field. The tests depend on the kind of field:

| Field | Tests |
|---|---|
| Text, long text, web address, email, phone | contains, is exactly, is empty, is not empty |
| Number, currency, rating, duration | is, is less than, is more than, is empty |
| Date, date and time | is on, is before, is after, is empty |
| Choice | is, is not chosen |
| Checkbox | is ticked, is not ticked |
| Link | links to, has no links |

For a link rule, you choose the record from a list; only the first 500 records are listed. Time, file, and auto-number fields cannot be used in rules.

## Opening, changing, and deleting a collection

Choose the collection in the sidebar. Its page looks like the table's list, and says how many records match and how they are sorted. You can find records in it, sort it, and add records from it as you would in a table (see [Lists and search](lists-and-search.md)).

- To change the rules, the name, or the order, choose **Edit collection**, make your changes, and choose **Save collection**.
- To delete it, choose **Edit collection**, then **Delete this collection**, and confirm with **Delete the collection**. Only the collection is deleted. Its records are not changed or moved to the trash. Deleting a collection cannot be undone.

## When a rule stops working

If a field a rule uses is deleted or changes type, if a choice it uses is removed from the field's list, or if the record a link rule names is moved to the trash, that rule stops working. The collection then ignores it and says how many rules no longer work. Choose **Edit collection**: each broken rule says why it no longer works. Fix it or remove it, and save.
