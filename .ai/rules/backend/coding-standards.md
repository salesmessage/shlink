---
description: Use this rule for any backend PHP change - coding standards (phpcs ruleset, logging, strictness) and architecture principles (SOLID, layering, dependency direction).
---

> **Shared rule.** Copied from `core/.ai/rules/backend/coding-standards.md` and kept in sync with it by hand.
> Change shared text in both places; anything specific to this service is marked *In this repo*.

# Backend Coding Standards & Architecture Principles

## Coding standards

- Do not use `logger()` or `init_logger()` to get a logger instance. Use dependency injection if possible with the PSR interface `\Psr\Log\LoggerInterface`.
  - *In this repo*: there is no global helper to fall back on anyway. The logger is a container service named `'Logger_Shlink'`, listed among a service's dependencies in the `ConfigAbstractFactory` array in a `dependencies.config.php` and type-hinted as `LoggerInterface` in the constructor. Follow that; do not instantiate a logger.
- Do not use strict array-emptiness checks like `$var === []`; use `empty($var)` instead.

### In this repo: the enforced ruleset

`composer cs` runs phpcs with the `Shlinkio` standard (`vendor/shlinkio/php-coding-standard`), over `bin`, `module`, `config`, `data/migrations`, `docker/config` and `public/index.php`. It is PSR-12 plus, notably:

- `declare(strict_types=1);` in every PHP file, under the opening tag, with blank lines around it.
- **Every global function and constant must be imported**: `use function in_array;`, `use const PHP_EOL;`. Fallback global names are an error, and this is the single most common failure in fork-authored code.
- Import blocks are separated by one blank line in the order classes / functions / constants, and each block is **alphabetically sorted**.
- Trailing commas in multi-line arrays, calls and declarations. Short array syntax only.
- Strict comparisons only - `==` and `!=` are errors.
- Single quotes unless the string needs interpolation.
- Class constants carry a visibility modifier; parameters, returns and properties carry type hints (`iterable`/`array` value types excepted).
- Non-capturing catch (`catch (SomeException)`) where the exception variable is unused.
- No `@param`/`@return` annotation that only restates a native type hint - phpcs flags it as useless. Annotate only to add information the signature cannot express (array shapes, generics, `@throws`).
- Union types written without spaces and with `null` last: `string|int|null`.

Match the surrounding upstream style beyond that: `final` where the class is not extended, `readonly` promoted constructor properties, enums for closed sets, and named static constructors (`fromRequest`, `forValidShortUrl`) rather than public constructors on value objects.

`composer stan` is phpstan **level 8** over `module/*/src`, `module/*/test*`, `module/*/config`, `config`, `docker/config` and `data/migrations`, with the Doctrine, Symfony and PHPUnit extensions loaded.

### In this repo: the baseline is red

Neither check passes on a clean checkout. As of the last verified run, all of the offending files are ones the fork has touched:

| Check | Baseline state |
|---|---|
| `composer cs` | 58 errors in 11 files, all auto-fixable, incl. `module/Core/src/Action/RedirectAction.php`, `module/CLI/src/Command/Import/DataImportCommand.php`, `module/Core/src/ShortUrl/Transformer/ShortUrlDataTransformer.php`, `module/CLI/src/Command/Db/PostMigrationCommand.php`, both `data/migrations/Version202512*.php` |
| `composer stan` | 18 errors in 3 files: `config/autoload/dependencies.global.php`, `DataImportCommand.php`, `ShortUrlDataTransformer.php` |

So judge your change on the **files you touched**, not on a whole-repo run:

```bash
php vendor/bin/phpcs  <paths you changed>
php vendor/bin/phpcbf <paths you changed>
APP_ENV=test php vendor/bin/phpstan analyse <paths you changed> --level=8
```

Leaving a file you touched cleaner than you found it is welcome; a sweep across files your ticket does not touch is not - it is merge cost against upstream for no behavior gain.

## Architecture principles

Write code with deliberate attention to architecture, not just working behavior:

- Follow SOLID. Each class should have one clear responsibility (SRP); prefer small, focused classes over god-objects.
- Keep coupling low and cohesion high. Minimise how much one class needs to know about another's internals.
- Dependencies must flow in one direction. Do not create circular or bidirectional references between classes/modules - e.g. a service that dispatches a job while that same job calls back into the service. If two classes reference each other, treat it as a design smell and break the cycle.
- Depend on abstractions, not concretions (DIP). Inject interfaces where it adds real value (swappability, testing); don't over-abstract trivial code.
- Keep services pure and reusable: a service should not know about the jobs/commands/listeners that orchestrate it. Orchestration depends on services, never the reverse.
- Keep jobs/listeners/commands thin: they own durability, retry, routing and orchestration; business logic belongs in services.
- Before adding a dependency between two classes, ask which layer owns which - and make sure the arrow points one way.

*In this repo* those principles have a concrete shape: `Core` never depends on `CLI`, actions and commands are thin adapters over services, every collaborator arrives through the constructor (the container appears only in factories and delegators, never in application code), and the direction of the arrow is declared explicitly in the `ConfigAbstractFactory` entry. Load `.ai/rules/backend/app-architecture.md` for the module layout, the config-aggregation wiring and the persistence patterns.
