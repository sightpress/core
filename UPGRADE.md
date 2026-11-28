# Upgrading SightPress

What to do when you move to a new version, newest first. Always back up each site's database and `config/` first,
then run `bin/sightpress site:migrate` before the web servers take requests again.

## To 0.6.0

### Sign-ins are kept in each site's database

Sessions (who is signed in, form tokens, messages for the next page) used to be files on the web server. They are now
kept in each site's database by default, so a site behind several web servers keeps people signed in whichever server
answers.

- Run `bin/sightpress site:migrate`: it adds the `session` table to every site.
- Everyone is signed out once, when the new version starts: the database doesn't know the old file sessions. They
  sign in again as usual; nothing else is lost.
- To keep the files (one web server only), set `'session' => ['store' => 'files']` in `config/tenant.php`.
- To use Redis instead, install PHP's redis extension, set `SESSION_REDIS_DSN` (for example `redis://redis:6379`) and
  `'session' => ['store' => 'redis']`. `bin/sightpress system:check` says whether the server answers.
- Changing `session.store` later signs everyone out once in the same way.
- `bin/sightpress schedule:run` (once a minute) now also removes ended sessions from the database or the folder.
