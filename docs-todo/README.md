# Docs to do

Every feature ships with a short docs stub here, named after the feature: `docs-todo/<feature>.md`.

A stub is notes, not a finished page. It should say:

- what the feature does, in one or two plain sentences;
- who uses it (site admin, editor, contributor, developer, operator) and the permissions it needs;
- the settings, CLI commands and routes it adds;
- anything about accessibility the reader needs to know;
- links to the code and tests.

The stubs are turned into pages in the `docs` repo (run `/doc-feature <feature>`). When the page is
published, the stub is deleted from this folder.
