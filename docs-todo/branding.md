# Branding: logo, icon and share image (C-6.6)

## What it does

Every site has three brand pictures. A new site gets generated ones straight away, from its name; site admins can
replace any of them (in the admin from C-7.3; now with the CLI) and go back to the generated one at any time.

| Picture | Used for | Upload rules | Generated one |
| --- | --- | --- | --- |
| Logo | Top of every page (instead of the site name), search engines | SVG, PNG, JPEG or WebP; from 1:1 to 8:1 wide; 5 MB at most (SVG 512 KB) | A wordmark with the site name (for search engines; pages keep the name as text) |
| Icon | Browser tabs, bookmarks, phone home screens | Square PNG, JPEG or WebP of at least 512 × 512 pixels | The site's initials, white on deep blue |
| Share image | The picture shown with links on social networks and in messaging apps | PNG, JPEG or WebP of 1200 × 630 pixels, or larger in the same proportions | The site name, white on deep blue |

- A file that doesn't fit is refused with what was expected and what it is, for example *The icon must be a square
  picture of at least 512 × 512 pixels. This one is 300 × 200 pixels.*
- **SVG logos are cleaned**: scripts, event handlers, links to other files and anything else that could run or load
  something are removed; a file with nothing left to show, or that can't be read, is refused. SVG files are always
  served with a Content-Security-Policy that lets them run and load nothing.
- Every upload is decoded and saved again (metadata removed).
- The generated colours are white on `#1f4e79` (8.6:1, above the 4.5:1 text needs).
- **Alt text**: the logo's defaults to the site name; the share image's can be given too.
- **A page's own share image**: a page can share one of its published pictures instead (field `social_image_id`,
  set in the page editor from C-7.2).

## What browsers and social networks get

- `/favicon.ico`, `/icon.svg` (generated icon only), `/apple-touch-icon.png`, `/icon-192.png`, `/icon-512.png`,
  `/site.webmanifest`, `/branding/<file>` (logo, wordmark, share image).
- Every public page's `<head>`: icon links, the manifest, `theme-color`, Open Graph (`og:title`, `og:description`,
  `og:url`, `og:locale`, `og:image` with its size and alt text …) and Twitter card tags, and schema.org JSON-LD for
  who runs the site (`Organization` with its logo, or `Person`) and the `WebSite`.
- Picture addresses in the `<head>` are absolute and carry `?v=<version>`, which changes with every change, so
  browsers and social networks fetch the new file; with the current version a file may be kept for a year.

## CLI

```text
bin/sightpress site:branding:set demo --logo=logo.svg --logo-alt="Riverside Library"
bin/sightpress site:branding:set demo --icon=icon.png --share-image=share.jpg --share-image-alt="The reading room"
bin/sightpress site:branding:reset demo --icon        # or no flag for all three
bin/sightpress site:branding:regenerate demo          # after renaming the site
```

`site:create` makes the generated pictures: *Made the site's icons, wordmark and share image from its name; replace
them with site:branding:set.*

## For developers

- Module `branding` (required): `BrandingService` (public), `BrandImages`, `SvgCleaner`, `BrandingHead` (listens to
  `PublicPageRendering`), `DefaultBranding` (`SiteProvisioning`, priority 50). State in the site setting
  `branding.state`; files in the site storage under `branding/`.
- The PNG pictures are drawn with DejaVu Sans Bold (`resources/fonts/`); names in scripts it doesn't cover get a
  plain icon and share image (the SVG icon still shows the initials).
- Tests: `tests/Unit/Branding/`, `tests/Integration/Branding/BrandingTest.php`, `tests/A11y/starter.spec.ts`.
