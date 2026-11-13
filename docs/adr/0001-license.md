# 0001 — License: GPL-3.0-or-later

- Status: Proposed
- Date: 2026-11-13

## Context

SightPress is a CMS for everyone, designed with blind and low-vision creators and audiences in mind. We want the
code, and every improvement to it, to stay open for the people who rely on it. A permissive licence would let
someone ship a closed fork, for example one that drops accessibility fixes, without sharing the changes.

The project has several packages: `sightpress/module-interface`, `sightpress/core`, `sightpress/skeleton`, the docs
site and, later, `sightpress/module-template`. They need one consistent licence.

## Decision

- License every SightPress repository under the GNU General Public License, version 3 or (at your option) any
  later version: SPDX identifier `GPL-3.0-or-later`.
- Ship the full licence text as `LICENSE` in every repository and set `"license": "GPL-3.0-or-later"` in every
  `composer.json`.
- Accept contributions under the same licence (inbound = outbound). No contributor licence agreement.
- Only add dependencies whose licences are compatible with GPL-3.0 (for example MIT, BSD, Apache-2.0, LGPL,
  GPL-2.0-or-later, GPL-3.0). Check the licence before adding a new dependency.

## Consequences

- Anyone who distributes a modified SightPress must share the source under the same terms.
- Modules that link against core or `module-interface` and are distributed are, in practice, GPL as well. We say
  this plainly in the module docs and in `module-template`.
- The GPL doesn't cover software that only runs on a server. We accept that: SaaS hosts are not forced to share
  changes. If this becomes a problem, a new ADR can look at AGPL for future versions.
- Dependencies under GPL-2.0-only or other incompatible licences can't be used.
