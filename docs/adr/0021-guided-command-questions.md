# 0021 — Commands ask guided questions, described by their parameters

- Status: Accepted
- Date: 2026-11-17

## Context

`site:create` asked for each missing required value with its help sentence and nothing more. That made the
command hard to use for people new to SightPress: the database had to be typed as one DSN
(`postgresql://user:password@host:5432/name`), a locale had to be written exactly (`en-GB`, not `en_gb`), one
mistyped email address threw away every answer, and nothing showed the answers before the site was made.

The same problems apply to every command, also those of external modules (ADR-0009: no back doors), so the fix
belongs in `SightPress\Module\Console\CommandParameter` and in the core console that runs commands, not in one
command.

## Decision

- `CommandParameter` gets optional, named constructor arguments (additive: `module-interface` 0.3):
  - `label`: a few words naming the value in the list of answers (default: the description without its final
    colon or full stop);
  - `default`: a string, or a closure working it out from the answers so far (`Closure(CommandInput): ?string`);
    for a flag, `yes` or `no`;
  - `choices`: the only accepted answers, matched without regard to case;
  - `normalise`: `Closure(string, CommandInput): string` that returns the value the command gets or throws an
    `InvalidArgumentException` whose message says what to type instead;
  - `describe`: `Closure(string): ?string`, the value in plain words ("British English" for `en-GB`), read back
    for the person to confirm;
  - `when`: `Closure(CommandInput): bool`, whether the parameter applies given the answers so far (follow-up
    questions such as the database server only for a server engine);
  - `secret`: typed without being shown, typed a second time to check (asked again when the two differ), never
    printed back;
  - `ask`: whether it is asked at the terminal (default: when required). Flags can be asked as yes-or-no.
- Core's console (`Cli\Questions`, `Cli\ParameterValues`) asks at a terminal when a required value is missing or
  a given one is not accepted: every asked question shows its choices and default; a refused answer is explained
  and asked again, at most 3 times, then the command stops with `INVALID` and changes nothing; a described answer
  is read back for a yes-or-no; at the end every answer is listed (secrets hidden) and "no" starts again from the
  first question with the answers so far as defaults. When nothing is missing and everything is accepted, nothing
  is asked, so scripts that run at a terminal keep working.
- Without a terminal (or with `--no-interaction`) the same defaults, `when` and `normalise` apply, so a command
  always gets normalised values, and a refused value stops the command with the same sentence.
- `site:create` and `site:db:change` ask for the database piece by piece: engine (default SQLite), then the
  SQLite file (default `<sites folder>/<slug>.sqlite`) or server, port (3306 or 5432), database
  (`sightpress_<slug>`), user (`sightpress`) and password. `--db` still takes a whole DSN and skips the pieces.
  `site:create` reads the locale in any case and with `-` or `_` (`pt_br` → `pt-BR`), refuses languages and
  regions ICU doesn't know, and reads it back by its ICU dialect name ("Brazilian Portuguese").

## Consequences

- Every command, core's or a module's, gets the same guided questions by declaring its parameters; no command
  writes its own prompts.
- Commands may rely on getting normalised values, also when run from scripts.
- Messages of `site:create` for wrong values changed (for example "short name" instead of "slug"); scripts that
  matched the old wording must be updated.
- Closures in `CommandParameter` make it unserialisable; nothing serialises it today.
- Core must require `sightpress/module-interface` `^0.3` once that is released.
