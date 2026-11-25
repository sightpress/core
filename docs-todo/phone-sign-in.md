# Signing in with a mobile number

While text messages are on for a site, people who confirmed a mobile number (see [Mobile numbers](phone-numbers.md))
can type it instead of their email address when they sign in. The password is still needed.

- The field is called **Email address or mobile number**. A number can be typed the way people usually write it
  (`07700 900123`) or with the country code (`+44 7700 900123`).
- Only a confirmed number works. After someone changes their number, the old one stops working at once.
- A wrong or unknown number gets the same message as a wrong email address, so nobody can find out which numbers have
  accounts.
- Wrong tries are counted per account: 5 in 15 minutes, whether they used the address or the number.
- When text messages are turned off, the field is **Email address** again and numbers no longer work.

## Forgot your password?

The same field takes an email address or a mobile number. A number gets a 6-digit code by text (it works for 10
minutes and contains no link); an address gets it by email.

People who manage the site (anyone who may manage users, roles, settings, features or security) always reset by
email: a code by text is never sent to them. This keeps a stolen or recycled phone number from being enough to take a
site over.

## An email after every password change

Whenever a password is changed — on My account, or after a reset by email or by text — the account's email address
gets a short message saying so, and what to do if it wasn't them. It contains no link.
