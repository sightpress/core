# Import files from the command line

What it does: adds files of any size from the server's disk to a site's media library.

Who uses it: whoever runs the install.

- `site:media:import <site> <path>… [--recursive] [--publish] [--alt=] [--locale=] [--memory-limit=]`.
- The type and safety checks still apply. The same file is never stored twice.
- A very large picture says how much memory it needs: run it again with `--memory-limit=`.
- The command exits with an error if any file was refused, and carries on with the others.

Decision: ADR-0052.
