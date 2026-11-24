# Features: per-site module switches (F-2)

Optional modules ("features") can be turned on or off for each site, by its admins on the **Features** screen or with
`site:module:*`. A feature that is off adds nothing to the site: its pages answer "not found", its admin screens leave
the menu, its settings, listeners and SEO contributions are gone, and templates that ask `module_enabled('<id>')` get
false. Its data is kept until someone deletes it. ADR-0034.

## Who

Admins (`module.manage`, given to the admin role only). Editors, contributors and members don't see the screen.

## The Features screen (`/admin/features`)

One section per optional module, under an `<h2>` with its name:

- what it does, its **Status** in words ("On", "Off", "On, set by your organisation", "Off, set by your organisation")
  and what turning it off does;
- what it needs ("Needs: Events.") and, when another feature that is on needs it, that one's name instead of a button;
- one button in its own form: **Turn on …** (done at once) or **Turn off …**, which asks first on a confirmation page
  (**Turn off …** / **Keep … on**);
- for a feature that is off and still has data, a link **Delete the data of …**, also confirmed first
  (**Delete the data of …** / **Keep the data**). It can't be undone.

After each change the screen comes back at the feature's heading (`#feature-<id>`), which gets focus, and the result
is in the polite live region ("Events is off. Its data is kept: turn it on again to bring it back."). Without
JavaScript the page still scrolls to the heading, and Tab continues from it. A feature the organisation set has no
button: "Your organisation chose this for every site, so it can’t be changed here."

When there are no optional modules: "There are no optional features to turn on or off."

Below the features (X-1), only when there are any:

- **Features that were removed**: modules this site had on whose package is no longer installed. The site works
  without them and their data is kept; installing the package again turns them back on.
- **Features that can’t be used**: installed modules that are unavailable on every site, each with the reason for
  whoever runs the server (a description list: the module and its package, then the reason).

## Rules

- Turning on runs the module's database migrations first, then gives the admin role the module's permissions.
  Refused while a module it needs is off, naming it.
- Turning off is refused while a module that is on needs it, naming that module. The data is kept.
- Deleting the data only while it is off: the module's migrations are undone, newest first (its tables are dropped).
  Turning it on again starts afresh.
- Every change is in the audit log: "Turned on Events.", "Turned off Events.", "Deleted the data of Events."

## Command line

- `site:module:list <slug>`: each optional module's id, name and whether it is on ("…, set by the tenant"), then the
  unavailable modules with the reason and the modules that were on but whose package was removed.
- `site:module:enable <slug> <module>` and `site:module:disable <slug> <module> [--purge-data]`. Changes are written
  to the `sites` log and the site's audit log (with no person).
- `site:create <slug> --without=<module>[,<module>…]`: the site starts with those modules off, and their migrations
  don't run.

## Tenant settings (`config/tenant.php`)

```php
'modules' => [
    // What new sites start with, instead of each module's own default. Existing sites keep theirs.
    'defaults' => ['analytics' => false],
    // On or off for every site; admins see "Set by your organisation", and the CLI refuses too.
    'locked' => ['assistant' => false],
],
```

Required module ids, and a module locked on while a module it needs is locked off, stop SightPress from starting, with
a message naming them. A module id that isn't installed (or is unavailable) is ignored and reported by `system:check`
(X-1). A module locked off isn't loaded at all.

## For module authors

Describe each optional module for the screen in the package's `composer.json`:

```json
"extra": {
    "sightpress": {
        "module": "Acme\\Events\\EventsModule",
        "features": {
            "acme.events": {
                "name": "Events",
                "description": "A calendar of events, with a page for each one.",
                "when-off": "The events pages answer “not found” and the Events screen leaves the admin panel."
            }
        }
    }
}
```

Without it the screen shows the module id. `enabledByDefault` in the manifest says whether new sites start with it
on. Migrations need a working `down()`: deleting the data runs it.
