# 0022 — Every site has a password policy, and the Passwords contract describes it

- Status: Proposed
- Date: 2026-11-17

## Context

Until now the password rules were fixed in code: at least 12 characters, at most 256, not the email address, and
no rules about kinds of characters. Site owners need to set their own rules: some must meet an organisation's
security policy that asks for a mix of characters, others want longer passwords. Every site must have a policy that
says how long a password must be and which kinds of characters it needs.

The rules are shown in two places: the hint under every "choose a password" field (WCAG 3.3.2: people learn the
rules before they type, not from an error) and the error when a password doesn't meet them. Both must follow the
site's policy, and other modules that let people choose a password (People's invitations today, the profile in U-3)
only reach Auth through `Auth\Contract\Passwords`.

## Decision

- Every site has a password policy, stored as five site settings that the Auth module registers (so they appear on
  *Site settings* under **Passwords** and every change is in the audit log):
  - `auth.password_min_length`: the shortest password allowed, default **12**, from **8** to **256**;
  - `auth.password_upper`, `auth.password_lower`, `auth.password_digit`, `auth.password_symbol`: whether a password
    must include a capital letter, a lower-case letter, a number and a symbol; all **on** by default.
- The default policy is therefore: at least 12 characters, with upper- and lower-case letters, a number and a
  symbol. Two rules can't be switched off: at most 256 characters, and not the email address.
- Kinds of characters count in every script (`\p{Lu}`, `\p{Ll}`, `\p{Nd}`, `\p{P}`/`\p{S}`). A space is allowed but
  is not a symbol.
- When a password misses several kinds, the error names all of them in one sentence, so it can be fixed in one go.
- `Auth\Contract\Passwords` gets `hint(): string` (the policy in one sentence for the field hint) and the constants
  `MIN_LENGTH_SETTING`, `SHORTEST_MIN_LENGTH` (8) and `MAX_LENGTH` (256), which the settings screen uses to check
  the length (the Admin module now requires Auth). `problem()` now applies the site's policy.
- The policy applies whenever someone chooses a password. Existing passwords keep working until they are next
  changed; nobody is forced to change theirs when the policy changes.

## Consequences

- `PasswordPolicy` is no longer static: `PasswordPolicy::fromSettings()` reads the site's settings on each call, so
  a change applies at once. `ChoosePasswordHandler::form()` and `AcceptInvitationHandler::form()` take the hint.
- Any other implementation of `Passwords` must add `hint()`. Core's is the only one so far.
- Upper- and lower-case rules can't be met with letters of scripts that have no case (Hebrew, Arabic, Chinese …)
  alone; people need at least one letter of a script with case. Sites whose audiences write in such scripts can
  switch those two rules off.
- Mandatory mixes of characters are a common organisational requirement but not current NIST SP 800-63B advice,
  which prefers length; the settings screen recommends 12 or more and lets a site switch each kind off.
