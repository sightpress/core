# 0028 — Page revisions: versions per language, a word-by-word comparison and restoring without publishing

- Status: Accepted
- Date: 2026-11-18

## Context

Every save has written a `page_revisions` row since C-4.1 (a snapshot of the page with every translation), but
nothing showed them. P-2 lists them per translation, compares two of them so the changes can be read with a screen
reader, without colour and without styles, and restores an older one. Open points: what counts as a version of one
translation, how to compare structured blocks as text, and what "restore never publishes" means when a published
page is edited in place (there is no separate draft copy of a published page).

## Decision

- **Versions of a translation** are the revisions in which that translation was new or its snapshot changed;
  saving another language or moving a page above it (which changes its address, so it does count) are told apart
  by comparing snapshots, not by a new column. `Page\Revision\RevisionHistory` reads them from the existing table;
  no migration. The page's state in the snapshot is shown as "the state of the page then".
- **Comparison** (`Page\Revision\RevisionDiff`, `TextDiff`): the details of the page (title, address, summary, search
  fields, share picture, noindex, original address, draft) and then each block. Blocks that are exactly the same are
  paired first (longest common subsequence); between them, blocks of the same type are paired as changed and the
  others are removed or added. Each block is flattened into its fields using the labels of its type's schema
  (nested blocks and list items included); rich text is compared as the words a reader sees. Words are compared
  with a longest common subsequence after the common start and end are taken off; above 1,000,000 word pairs the
  changed middle is shown as removed and added as a whole, so comparing is always quick.
- **Accessible markup**: every change is said in words as well as marked: `[removed: <del>…</del>]` and
  `[added: <ins>…</ins>]`, each section has a heading with its status in words ("Changed", "Added", "No changes"),
  fields are a `<dl>`. Colour (the error and success surfaces of the theme) only helps.
- **Restoring** (`PageEditor::restore()`) writes the translation's title, address, summary, search fields and blocks
  from the snapshot as a new revision, through the normal save (blocks are checked and upgraded again; addresses and
  redirects are kept right). It never changes the workflow state, and a translation that is a draft stays one. It is
  **refused while visitors can read the translation** (page published and translation not a draft): restoring there
  would publish the old text at once. People take the page back to draft, or make the translation a draft, first.
  Page-wide settings (layout, visibility, place in the tree) are not restored.
- New event `Page\Contract\RevisionRestored` (after the `PageSaved` of the new revision); the audit log records it as
  `page.revision_restored` with the locale and both revision numbers, never the text.
- Screens: `/admin/pages/{id}/revisions[?locale=]` and `/admin/pages/{id}/revisions/{n}[?locale=]` (`POST` restores),
  open to whoever may see the page in the editor; restoring needs the right to change it. The editor links to them
  ("Older versions").

## Consequences

- Pages with very many revisions load every snapshot of the page on the versions screen (one query); paging can be
  added later without changing the screens' addresses.
- A block that no longer passes the checks (for example a type that was removed) makes a version impossible to
  restore; the screen says so and the comparison still shows it.
- Comparing two arbitrary versions (not just one with the one before it) is not offered yet.
