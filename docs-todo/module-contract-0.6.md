# What an external module can declare (module-interface 0.6)

## What it does

Core requires `sightpress/module-interface` `^0.6` (MI-0.6; ADR-0076 … ADR-0080) and records what modules declare
through the new parts of the contract, tied to the module and left out on sites where it is off:

- `privacy()`: personal data providers, cookies and browser storage, processor sources, consent purposes;
- `placeholders()`: details editors can put in text;
- `blockTypes()->addCodeLanguage()`: languages for the `code` block.

Nothing is shown yet. GD-1 builds the inventory and the outgoing-host allow-list from the privacy declarations,
BL-4 offers the code languages and BL-6 the placeholders. I-9 and I-10 put `LocaleFormats`, `MoneyFormatter` and
`SiteLanguages` in the site container.

## Who uses it

Developers who write modules. Nothing changes for site admins yet.

## Errors a module author can see

- A class that doesn't implement the interface it is registered as (`PersonalDataProvider`, `ProcessorSource`,
  `PlaceholderResolver`, `CodeHighlighter`): the error names the module and the class.
- Two modules registering the same data set provider, cookie name, consent purpose, placeholder name, or code
  language id or alias: the error names both modules.

## Code and tests

- `src/Module/Registrar/PrivacyCollector.php`, `PlaceholderCollector.php`, `BlockTypeCollector::addCodeLanguage()`
- `src/Module/ModuleRegistry.php` (`personalDataProviders()`, `cookies()`, `processorSources()`,
  `consentPurposes()`, `placeholders()`, `codeLanguages()`)
- `tests/Unit/Module/ModuleRegistryTest.php`
