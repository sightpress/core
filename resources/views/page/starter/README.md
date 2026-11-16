Starter pages written for every new site by `Page\Provisioning\StarterPages` (C-6.4), one folder per owner type
(`person`, `organization`). Each template renders the HTML of the page's rich text block, so it may only use what
the rich text allow-list keeps (paragraphs, h2–h6, lists, links, emphasis); the page title is the page's `<h1>`, so
headings start at `<h2>`. Variables: `site_name`, `owner_name` (the first admin's name), `site_url` (no trailing
slash), `date` (the day the site was created, for example "16 November 2026").
