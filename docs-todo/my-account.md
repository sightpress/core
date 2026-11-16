# My account, avatars and time zones (C-3.6)

## My account

Everyone with an account on a site, members included, has a **My account** page at `/account`. It uses the site's
own design (not the admin panel); people who use the admin reach it from the **My account** link in the admin
header. Visitors who aren't signed in are asked to sign in first and then come back.

On the page:

- **Your details**: first name and last name (both needed), an optional display name, and a time zone.
  - The display name is shown next to your work, in the admin and in emails. Left empty, your first name is
    used.
  - Dates and times are shown in your time zone, across the admin and in emails. They are stored in UTC, so
    changing your time zone changes how they are shown, never the dates themselves.
  - The time zone list is grouped by region (Africa, America, Asia, Europe …), with UTC first.
  - **Language** appears only when the site is written in more than one language: choose one of the site's
    languages, or "Site default".
- **Profile picture**: until you upload one, a picture of your initials is used. Upload a JPEG, PNG or WebP
  picture of up to 5 MB; it is cropped to a square from the middle and saved without hidden information such as
  where the photo was taken. **Remove picture** goes back to your initials. SVG pictures are not accepted.

After each change a message such as "Your details have been saved." is read out by screen readers. Problems are
listed at the top of the page, and the browser tab starts with "Error:".

## Avatars

- Every account gets an avatar when it is created (the first admin of a new site included): white initials on a
  coloured square. The colour comes from a set of colours that all have at least 4.5:1 contrast with white, is
  picked from the account's number, and never changes.
- Initials are the first letter of the first and last name as a reader sees it, accents included: `Zoë Ødegaard`
  → `ZØ`, `李 小龙` → `李小`.
- A new name makes new initials. An uploaded picture stays until it is removed.
- Avatars are stored in the site's storage under `avatars/` (not in the media library) and served from
  `/avatars/…` with names that change with every new picture, so browsers can keep them for a year.

## For developers

- Changes to an account dispatch `User\Contract\AccountChanged` (user, who changed it, which fields) for the audit
  log.
- `User\Contract\AccountPreferences` gives a person's time zone and language; sign-in puts them on every request
  (`Template\ViewerPreferences`), so `user_datetime` and `t()` follow them.
- Forms: `FieldType::File` (with `accept`), and select choices can be grouped into `<optgroup>`s.
