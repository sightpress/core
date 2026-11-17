# Pages in the admin panel: the page list and the page editor (C-7.2)

## The page list (`/admin/pages`)

- Shows every page you may open as a **tree**: pages under another page are listed under it. A button before each
  group of subpages shows or hides them ("Show the pages under About"). Without JavaScript every level is shown.
- Each page says its state (Draft, Waiting for review, Approved, Published), its address, who wrote it and when it
  last changed. The home page says so.
- **Find pages**: by text in the title, by state, and "Only pages I wrote". Filtering shows a flat list, and each
  page says which pages it is under.
- Contributors see their own pages and published pages. A page they can't open is listed only as "A page you
  can't open" when one of their pages is under it.
- **Start a new page** (`/admin/pages/new`, needs "Create pages"): a title, the page it goes under and a layout
  (Standard page, or Home page). The page starts as a draft and opens in the editor.

## The page editor (`/admin/pages/{id}`)

- **About the page**: title, address (the last part of the web address; leave it empty to make one from the title;
  pages below move with it) and layout.
- **Search & AI**: summary, title in search results, description for search engines (a count of its characters is
  read out after a short pause; search engines show about 160), the picture shown when the page is shared,
  "Ask search engines and AI search engines not to list this page", and the address of the original for copies.
  "How a search result could look" shows the title, address and description as text; "Update the previews"
  refreshes it.
- **Content**: the page's blocks, one after another. Each block is a group of fields with a name such as "Block 2
  of 5: Image" and buttons **Move up**, **Move down** and **Remove**. "Type of block to add" and **Add block** add
  one at the end. Pictures of a gallery and columns have their own groups and buttons; columns hold blocks of their
  own (anything but columns).
- Text blocks are written as HTML in a text area; "Preview of block N" shows them as they will be saved (anything
  not allowed is removed).
- **These buttons don't save.** Each one sends the form, says what changed ("Moved block 3 (Image) up. It is now
  block 2 of 5.") and puts focus back on the block, or on the same button. **Save page** (at the top and the
  bottom; Enter in a field saves too) writes a new revision.
- If the page can't be saved, the error summary at the top gets focus and links to each field.
- **Publish checks** of the saved page: problems that stop publishing and suggestions, each linking to its field.
- **Review and publishing**: the workflow buttons you may use now (Submit for review, Send back to draft, Approve,
  Publish, Unpublish) with an optional note for the next person. They use the saved page.
- People who may see a page but not change it now (for example a contributor while the page waits for review) see
  the checks and buttons without the form.
- `?locale=xx` edits another language of the site.

## For developers

- Module `page_admin` (`src/Page/Admin`), always on. Fields come from each block type's JSON Schema; give your
  block type's fields a `title` (label) and `description` (hint). See ADR-0018.
- Contracts added: `Block\Contract\BlockSchema`, `MediaLibrary::choices()`, `MediaFile::id()`.
- Tests: `tests/Unit/Page/Admin`, `tests/Integration/Page/PageEditingFlowTest.php`, `tests/A11y/pages.spec.ts`.
