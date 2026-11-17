# Site settings and branding (C-7.3)

## Site settings (`/admin/settings`, needs "Manage settings")

- **The site**: its name (shown at the top of every page, in the browser tab and in search results), its main
  language (only on sites in more than one language) and its time zone. Saving changes them at once; the generated
  logo, icon and share picture are made again with a new name. The site's languages, addresses and database are
  still changed from the command line.
- **Who runs the site**: a description (shown by search engines and at the top of `llms.txt`), a full legal name,
  a contact email, a phone number, a postal address and profiles on other sites (one `https://` address per line).
  Search engines get them in the site's structured data (`Organization` or `Person`). Leave a field empty to keep
  it private.
- **Search & AI**: the AI crawler policy (Allow all / AI search only / Block all, each explained), the verification
  codes of Google Search Console, Bing Webmaster Tools and Yandex Webmaster (only the code; it becomes a `<meta>`
  tag on the home page), and **IndexNow** (off by default: when on, the site tells search engines each time a page
  is published or unpublished; the key is at `/indexnow-key.txt`).
- **Passwords** (the site's password policy, ADR-0022): the shortest password allowed (12 by default, from 8 to
  256) and whether a password must include a capital letter, a lower-case letter, a number and a symbol (all on by
  default). It applies the next time someone chooses a password; current passwords keep working.
- **More settings**: modules can register their own settings; they appear here, grouped by module.
- Visibility (public) and theme (SightPress Default) are shown; they can't be changed yet.
- Mistakes are listed in the error summary at the top, which gets focus; "The settings have been saved." is read out.

## Branding (`/admin/settings/branding`)

- One section each for the **logo**, the **icon** and the **share picture**, with a preview whose alt text says what
  it is, whether it was uploaded or made from the site name, and for the share picture how it was cropped
  (1200 × 630 pixels).
- **New picture**: a file field. Logo: SVG, PNG or WebP. Icon: square, at least 512 × 512. Share picture: JPEG,
  PNG or WebP, at least 1200 × 630, cropped from the middle.
- **What it shows**: the alt text, in each of the site's languages (logo and share picture). An empty logo text
  falls back to the site name.
- **Reset to the generated one** once a picture was uploaded.
- A refused upload is explained in an error summary with focus; each result is read out.

## For developers

- Register settings with `$registrar->settings()->add(new Setting(...))`: they get a field on the settings screen.
- Listen to `Site\Contract\SettingsChanged` to react to changes. Read the profile with `Site\Contract\SiteProfile`.
- ADR-0019; tests in `tests/Unit/Admin/Http/SettingsHandlerTest.php`, `tests/Unit/Branding/Http`,
  `tests/Integration/Admin/SiteSettingsFlowTest.php`, `tests/A11y/settings.spec.ts`.
