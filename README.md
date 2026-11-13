# SightPress core

The core of SightPress, a powerful multi-site, multilingual CMS for everyone, designed with blind and low-vision
creators and audiences in mind. It is built on modern, object-oriented PHP.

Core holds the kernel, the module loader and the built-in modules. Every feature is a module, built on the same
contracts that third-party modules use (`sightpress/module-interface`).

To start a new site, use the [skeleton](https://github.com/sightpress/skeleton) project instead of this package.

## Requirements

- PHP 8.5 or later

## Development

Clone `module-interface` next to this repo (Composer finds it at `../module-interface`), then:

```sh
composer install
composer check
```

## Licence

GNU General Public License v3.0 or later. See [LICENSE](LICENSE).
