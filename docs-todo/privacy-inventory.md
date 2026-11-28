# Personal data inventory and outgoing requests (GD-1)

**What:** every feature of SightPress says what personal data it keeps, why, for how long and what happens to it when
someone is erased, and which outside services (processors) it sends data to. *Your data*, *Download your data*,
*Delete your account*, the generated Privacy Policy and the record of processing (GD-3 … GD-5) are built from this.
And the site calls no outside service that isn't declared: a fresh site sends nothing to anyone apart from its email.

**Who:** module developers (they declare their data), operators (they see which hosts a site may call), site owners
(they will read the result in *Settings → Privacy*, GD-2/GD-5). This page isn't legal advice.

**What core keeps** (data set key → what): `site.sessions` sign-in sessions; `user.account` accounts and profiles;
`user.avatar` profile pictures; `user.phone` mobile number and text choices; `rbac.roles` roles;
`auth.remember` "Keep me signed in" devices; `auth.codes` 6-digit codes (hashes only); `auth.throttle` attempt limits
(keyed hashes only, end within a day); `people.invitations` invitations; `audit.log` audit log; `sms.log` texts sent;
`assistant.usage` AI assistant usage (counts, never prompts); `media.uploads` who added files and unfinished uploads;
`page.authors` who wrote pages; `feature.changes` who turned features on or off. Analytics, search and contact form
messages keep none (the inventory says why).

**Processors** (declared only while configured): the mail server (`MAILER_DSN`, when it is someone else's), Amazon S3,
Cloudflare R2 or another S3 service and the CDN (site storage), Twilio or Vonage (SMS), Anthropic, OpenAI, Mistral or
an OpenAI-compatible service (assistant), IndexNow (only while on, in production).

**Outgoing requests:** every request goes through one client that refuses a host no declared processor (or the
operator's own storage or AI server) uses, before anything is sent, and never follows redirects. The error says
"The request to <host> was refused: no service the site uses declares this address." Tenant-wide, only
`api.cloudflare.com` (for `site:storage:provision`) is allowed. The mailer and the AWS SDK don't use this client.

**For module developers** (module-interface 0.6): `$registrar->privacy()->addProvider(MyData::class)` for each data set
(`describe()` must not use the provider's services), `addCookie()`, `addProcessors(MySource::class)` returning a
`ProcessorDeclaration` only while your feature is set up (its `hosts` are what your module may call), and
`#[PersonalData('my_module.data_set')]` on entity fields that hold personal data.

**Checks:** `composer check` runs `tools/privacy-check.php`: it fails on a `#[PersonalData]` field no provider
describes, an entity column that looks like a person (`user_id`, `*_by`, `email`, `phone`) without the attribute,
and a module whose migrations add such a column without a provider.

**Rate limits:** the limiter cache now keeps a keyed hash (from `APP_SECRET`) of each key, never an email address or
IP address. Existing limits start again from zero after the upgrade.
