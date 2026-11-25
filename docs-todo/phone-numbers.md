# Mobile numbers

While text messages are on for a site (see [Text messages](text-messages.md)), people can add a mobile number to
their account. A number is optional: it is needed to get text messages and, once two-step confirmation is on, to
confirm important changes.

## Adding a number

- After signing in without a number, people see **Add your mobile number**, with **Skip for now**. They are asked
  again at their next sign-in. Set `sms.require_for_existing: true` in `config/tenant.php` to remove *Skip for now*.
- On **My account → Mobile number and text messages** (`/account/phone`) anyone can add, change or remove their
  number at any time.
- They choose the country (only the countries text messages may go to) and type the number as they usually would
  (`07700 900123`) or with the country code (`+44 7700 900123`).
- SightPress checks it is a mobile number (with the provider's lookup), then sends a 6-digit code by text message.
  The code works for 10 minutes. The number is saved only once the code is typed. Texts never contain links.
- A number that another account on the same site already has gets the same page and no text, so nobody can find out
  which numbers are used. The same number can be used on another site.

## Text messages

Having a number subscribes nobody. **Send me text messages** is unticked until the person ticks it and chooses which
notifications to get. They can stop them on the same page, by removing their number, or by replying STOP (START
turns them back on).

## For operators

`bin/sightpress site:user:create-admin <site> --email=… --first-name=… --last-name=… --phone=+447700900123` gives a
new admin their number without a code: you confirm it is theirs.

## Privacy

The number is personal data. It is shown in full only to its owner; everywhere else (logs, the audit log, the
console) it is masked (`+44•••••••123`). The audit log records that the number changed, never the number.
