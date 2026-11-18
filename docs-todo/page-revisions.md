# Older versions of a page (P-2)

Every time a page is saved in a language, SightPress keeps a version of it. You can see who saved each version and
when, read what changed in it, and bring an older one back. ADR-0028.

## Where

In the page editor, next to the page's state, follow **Older versions**. On a site with more than one language, the
versions screen has links to the versions in each language: each translation has its own.

## The list of versions

A table, newest first: the version number (a link), when it was saved (in your own time zone), who saved it ("The
system" for changes made from the command line or on a schedule) and the state the page was in then (draft, waiting
for review …). Saving the page in another language doesn't add a version here.

## What changed

Opening a version compares it with the version before it: first the details of the page (title, address, summary,
search fields …), then each block, each under its own heading saying whether it was changed, added, removed or has
no changes. Inside a field:

- words that were taken out read **[removed: …]** and are crossed out;
- words that were put in read **[added: …]** and are underlined.

The words "removed" and "added" are always there, so the comparison reads the same with a screen reader, in high
contrast mode and with styles turned off.

## Restoring a version

**Restore version N** (people who may change the page) makes the title, address, search fields and blocks of that
version a new version of the page in that language. Nothing is deleted: the newer versions stay in the list. The
page's state doesn't change and **nothing is published**:

- on a draft (or a page waiting for review, or approved), the page simply has the older text again;
- while visitors can read the translation (the page is published), restoring is refused: take the page back to
  draft ("Unpublish"), or make the translation a draft again, restore, then publish it again.

The layout, who can see the page and its place in the site are not part of a restore. A version whose blocks no
longer pass the checks (for example a block type that was removed) can't be restored; copy what you need from it.

Every restore is in the audit log ("Restored the British English text of “About us” from revision 2").
