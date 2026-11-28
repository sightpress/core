# How long personal data is kept (GD-2)

**What:** SightPress deletes personal data once it is older than the site keeps it, once a day (`schedule:run`), and
site admins choose how long on *Settings → Privacy*, within limits the operator sets. This isn't legal advice: the
suggested times are a starting point, and the site owner decides.

**Who:** site admins with `privacy.manage` (the Admin role) choose; operators set the defaults and limits.

**Kinds** (suggested default, what admins may choose by default):

| Kind | Default | Range | What is deleted |
|---|---|---|---|
| Audit log | 24 months | 6–84 | entries older than this |
| Accounts never confirmed | 7 days | 1–30 | accounts whose email address was never confirmed |
| Invitations | 30 days | 1–365 | invitations, after they expired or were accepted |
| 6-digit codes | 1 day | 1–7 | used and expired codes (only hashes are kept anyway) |
| Text messages sent | 90 days | 7–730 | the record of each text (messages still waiting stay) |
| Use of the AI assistant | 13 months | 2–25 | the record of what it was used for and how much |

Already handled elsewhere: analytics (its own retention, AN-1), unfinished uploads (M-8), ended sessions (H-9),
attempt limits (they end within a day). The audit log has no IP address or browser columns, so there is nothing to
clear after 90 days.

**Operators** (`config/tenant.php`): `'privacy' => ['retention' => ['audit_months' => ['default' => 36, 'min' => 12,
'max' => 84], 'sms_days' => 30]]`. Limits can only be narrower than the ranges above; a wrong value stops SightPress
at start with a message. A site's choice outside new limits falls back to the default.

**Commands:** `site:privacy:purge <site>` deletes now what is too old and prints counts. Each run (daily or by
command) adds one audit log entry with counts only, never the data. Deletes run in batches of 500, so a run that is
stopped halfway is finished by the next.

**Screen:** *Site settings → Privacy* (`/admin/settings/privacy`): one select per kind, each saying what it deletes;
saving is in the audit log ("How long the audit log is kept", …).
