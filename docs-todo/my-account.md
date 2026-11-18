# My account, avatars and time zones (C-3.6, U-3)

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
- **Signing in**: the email address you sign in with, and links to change it or your password (see below).
- **Profile picture**: until you upload one, a picture of your initials is used. Upload a JPEG, PNG or WebP
  picture of up to 5 MB; it is cropped to a square from the middle and saved without hidden information such as
  where the photo was taken. **Remove picture** goes back to your initials. SVG pictures are not accepted.

- **Admin preferences** (only for people who use the admin panel): **Reduce motion** (no animations, sliding or
  smooth scrolling, even if your device doesn't ask for less motion) and **Larger text** (the admin panel's text a
  quarter larger; browser zoom still works on top). They change only how the admin panel looks to you, never the
  site, and work without JavaScript.

After each change a message such as "Your details have been saved." is read out by screen readers. Problems are
listed at the top of the page, and the browser tab starts with "Error:".

## Changing your password

**Change your password** asks for your current password and a new one that follows the site's password rules (they
are spelled out under the field) and isn't the same as the current one. Afterwards you stay signed in where you are,
and you are signed out everywhere else, including devices where you chose "Keep me signed in". A wrong current
password is explained at the top of the page; after five wrong tries in 15 minutes you have to wait, as when signing
in.

## Changing your email address

**Change your email address** asks for the new address and your current password. We email a 6-digit code to the
new address — never a link — and you type it on the next page. Only then does your address change: you sign in with
the new one from then on, and the old address gets an email saying the address changed (with the new one partly
hidden, like `m•••@new.example`, and no link). The code works for 15 minutes; **Send a new code** works once a minute.

If the new address already belongs to another account, the page looks the same but no code is sent, so nobody can
find out who has an account.

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

- Changes to an account dispatch `User\Contract\AccountChanged` (user, who changed it, which fields — including
  `password`, `email` and `admin_preferences`, never the values) for the audit log.
- `/account/password`, `/account/email` and `/account/email/code` belong to the Auth module (ADR-0033);
  `/account/preferences` to the User module. `User\Contract\Account::changeEmail()` lets Auth move an account.
- The admin shell puts the preferences on `<html>` as `admin-reduced-motion` and `admin-larger-text`
  (`ViewerPreferences::$reducedMotion` / `$largerText`).
- `User\Contract\AccountPreferences` gives a person's time zone and language; sign-in puts them on every request
  (`Template\ViewerPreferences`), so `user_datetime` and `t()` follow them.
- Forms: `FieldType::File` (with `accept`), and select choices can be grouped into `<optgroup>`s.
