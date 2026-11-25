Starter pages written for every new site by `Page\Contract\StarterContent` (C-6.4, I-4), one folder per owner type
(`person`, `organization`) and, inside it, one per language (`en-GB`, `pt-PT`, `es`). A language without a folder uses
the closest one (`pt-BR` uses `pt-PT`), otherwise `en-GB`, and `site:create` warns about it. Each template renders the
HTML of the page's rich text block, so it may only use what the rich text allow-list keeps (paragraphs, h2–h6, lists,
links, emphasis); the page title is the page's `<h1>`, so headings start at `<h2>`. Variables: `site_name`,
`owner_name` (the first admin's name), `site_url` (no trailing slash), `date` (the day the page was written, in the
language, for example "16 November 2026" or "16 de noviembre de 2026"), `analytics` (whether the analytics module is on, for the Privacy notice's section about visitor statistics), `text_messages` (whether text messages are on, for its section about mobile numbers) and `links` (the address of each starter page
in the same language: `links.about`, `links.contact`, `links.privacy`, `links.terms`, `links.accessibility`). The pages'
titles and descriptions are in the interface catalogues; their addresses per language are in `StarterContent`.
