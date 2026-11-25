# Text messages (SMS)

Text messages are optional and off on every site. Your organisation (the tenant operator) sets up a provider for a
site, then turns text messages on. They are used for phone verification codes, notifications people choose to get
by text, phone sign-in and two-step confirmation.

## Providers

SightPress comes with two providers. Modules can add more.

| Provider | Account (`--account`) | Key (`--key-stdin`) | Sender (`--sender`) |
|---|---|---|---|
| Twilio (`twilio`) | Account SID (`AC…`) | Auth token | A number (`+44…`) or a name of 3–11 letters and digits |
| Vonage (`vonage`) | API key | `API secret|signature secret` | A number or a name |

Both check numbers before anything is sent (line type lookup), report deliveries, and handle STOP and START replies.

## Set up a site

```sh
printf %s "$TWILIO_AUTH_TOKEN" | bin/sightpress site:sms:set shop --provider=twilio \
    --account=AC0123… --sender=Shop --key-stdin
bin/sightpress site:sms:enable shop
bin/sightpress site:sms:test shop --to=+447700900123
```

- The key is read from standard input only, stored encrypted with `APP_SECRET`, and never shown again
  (`Key: ••••1234`).
- `site:sms:enable` refuses until the provider is set up. The Features screen does the same.
- `site:sms:remove shop` forgets the site's provider and key; `site:sms:disable shop` turns text messages off (numbers
  and the message log are kept).
- `--budget=` sets the site's monthly budget in message parts (0 = no limit); without it the tenant's applies.

## Tenant settings (`config/tenant.php`)

```php
'sms' => [
    'provider' => 'twilio',              // a default every site inherits (optional)
    'account' => 'AC0123…',
    'sender' => 'SightPress',
    // the key comes from the SMS_API_KEY environment variable
    'site_providers' => false,           // may site admins set up their own provider?
    'require_for_existing' => false,     // must existing users add a number at next sign-in?
    'monthly_budget' => 1000,            // message parts per site and month, 0 = no limit
    'reserved_for_codes' => 50,          // parts only security codes may use
    'countries' => null,                 // e.g. ['GB', 'IE']; null = the country of the site's main language
    'limits' => [
        'per_number' => 3,               // security codes per number and hour
        'per_client' => 10,              // per client address and hour
        'per_account' => 5,              // per account and hour
        'per_site' => 100,               // every message, per site and hour
        'lookups_per_day' => 100,        // paid number lookups per site and day
    ],
],
```

Every value is checked when SightPress starts; a wrong one stops it with a message saying what to fix.

## Protection against misuse

A form that sends codes to any number can be used to run up charges (SMS pumping). SightPress only sends to mobile
numbers in the allowed countries, never to premium-rate, shared-cost or toll-free numbers, checks the number with the
provider first (capped per day), and limits messages per number, client, account and site. The budget warns at 80 %
and refuses at 100 %; a reserve is kept for security codes so people can still sign in.

## Webhook

Point the provider's status callback and incoming message webhook at `https://<site>/_sms/webhook`. Requests must be
signed by the provider; others get 403, and a repeated request changes nothing. For Twilio, the address configured at
Twilio must be exactly the address the site sees (same scheme and host), because the signature covers it.

## Privacy

Numbers are personal data. They are shown masked (`+44•••••••123`) in the log, the audit log and the console, and
stored in the log only as a keyed hash. The text of a security code is never stored. The provider receives the number
and the text to deliver them; see its description in `site:sms:set` output and your Privacy Policy.
