# Two-step confirmation

With two-step confirmation, a password alone can't make important changes on a site. Each time someone makes one,
they type a 6-digit code sent by text message to their mobile number. The code then counts for 10 minutes in that
browser.

It needs text messages (see [Text messages](text-messages.md)) and is off until an admin turns it on.

## Turning it on

**Site settings → Security** (admins, or anyone with *Manage security*):

1. Add your own mobile number on My account first.
2. Choose **On** and **Save**. You get a code by text; type it, then press **Make the change**.

Your organisation can decide for every site in `config/tenant.php`:

```php
'two_step' => 'optional', // sites choose (default); 'required' = on for every site with text messages; 'off' = never
```

With `required` the screen says so and has no switch. With `off` there is no Security screen.

## What needs a code

- your password, email address, or changing or removing your mobile number;
- inviting an admin or editor; changing someone's role, deactivating them or removing their number; editing roles;
- who can read the site; who may sign up; security settings;
- turning features on or off, deleting a feature's data;
- the AI assistant's service and key; cloud storage; deleting analytics data;
- anything a module marks as important.

## How it looks

The change waits on **Confirm it's you**: nothing has changed yet. **Send me a code**, type it, and the next page
offers **Make the change**, which sends the form again. Passwords and files are never kept, so for a password change
go back to the form and type it again.

Someone without a mobile number is asked to add one first. There is no way round it by email.

## Lost phone, or no code arrives

- Someone who manages people opens the person on **People** and chooses **Remove the mobile number of …**. The person
  then adds their new number on My account.
- The operator can do the same: `bin/sightpress site:user:phone:reset <site> <email>`.
- If no code can be sent (the provider is down or the budget is used up), the page says so. The operator can make the
  change from the console, which two-step confirmation never stops.

While two-step confirmation is on, nobody can reset their password by text message: the phone is the second step.
