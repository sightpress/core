# The audit log (C-7.4)

## What it records

Every change people make to the site, as a short sentence with who and when:

- **Pages** (and so their blocks): started, saved (with the revision number), moved, sent for review, sent back,
  approved, published, unpublished, with the note written for the next person.
- **Media**: uploaded, its alt text, caption, transcript or publishing changed, deleted.
- **Menus**: entries added, moved, indented, outdented or removed.
- **People**: role changes, and changes to names, display name, picture or time zone.
- **Site settings and branding**: which settings changed (never their values).

Changes made from the command line or by the system show "System" as who. The log stores no IP addresses, no page
text, no setting values and no passwords.

## Who can see it

People with "View the audit log" (`audit.view`, admins). In the admin, choose **Audit log** (`/admin/audit`).

## The screen

- A table of entries, newest first, 50 at a time ("Older entries" / "Newer entries"). Its caption says how many
  entries there are and which page you are on.
- **Find entries**: who, what (pages, media, menus, people, site settings) and from / to a day, in the site's time
  zone.
- **Download as a CSV file**: the entries shown by the filters (or the whole log), with times in UTC. Cells that a
  spreadsheet could run as a formula start with an apostrophe.

Entries can't be changed or removed from the admin.

## For developers

- Module `audit` (`src/Audit`), always on. Table `audit_log` (`occurred_at`, `actor_id`, `event_action`, `entity_type`,
  `entity_id`, `summary`, `details` JSON).
- `AuditRecorder` listens to the contract events (`PageCreated`, `PageSaved`, `PageMoved`, `PageTransitioned`,
  `MediaUploaded`, `MediaChanged`, `MediaDeleted`, `MenuChanged`, `RoleChanged`, `AccountChanged`,
  `SettingsChanged`). Dispatch one of them, or `SettingsChanged` with your setting keys, to get an entry.
- Tests: `tests/Unit/Audit`, `tests/Integration/Admin/SiteSettingsFlowTest.php`, `tests/A11y/settings.spec.ts`.
