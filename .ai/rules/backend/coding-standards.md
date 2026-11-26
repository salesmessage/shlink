---
description: Use this rule for any backend PHP change - coding standards (mago rules, logging, strictness) and architecture principles (SOLID, layering, dependency direction).
---

> **Shared rule.** Copied from `core/.ai/rules/backend/coding-standards.md` and kept in sync with it by hand.
> Change shared text in both places; anything specific to this service is marked *In this repo*.

# Backend Coding Standards & Architecture Principles

## Coding standards

- Do not use `logger()` or `init_logger()` to get a logger instance. Use dependency injection if possible with the PSR interface `\Psr\Log\LoggerInterface`.
  - *In this repo*: there is no global helper to fall back on anyway. The logger is a container service named `'Logger_Shlink'`, listed among a service's dependencies in the `ConfigAbstractFactory` array in a `dependencies.config.php` and type-hinted as `LoggerInterface` in the constructor. Follow that; do not instantiate a logger.
- Do not use strict array-emptiness checks like `$var === []`; use `empty($var)` instead.

### In this repo: the enforced tools

Upstream 5.x replaced phpcs and phpstan with [mago](https://mago.carthage.software/). `mago.toml` extends `vendor/shlinkio/shlink-mago-config/mago.toml` over `bin`, `config` and `module`:

- `composer cs` = `mago lint` + `mago format --check`; `composer cs:fix` applies the fixes. Format only the files you change.
- `composer analyze` = `mago analyze` with `APP_ENV=test`, filtered by `mago-analyze-baseline.toml`.

The baseline is a suppression list for upstream's code, not a target: never regenerate it. When a finding cannot be fixed in the code, put `// @mago-expect <category>:<code> (reason)` on the line above it, as in `module/CLI/src/Command/Visit/VisitsCommandUtils.php`. `composer.lock` pins `shlinkio/shlink-mago-config` to v1.1.1: v1.2.0 turns on `check-missing-override` and flags upstream's whole test tree.

Upstream style to match: `declare(strict_types=1);`, imported global functions and constants (`use function sprintf;`), strict comparisons, trailing commas, `final readonly` value objects with named static constructors (`fromRawData`, `fromRequest`), enums for closed sets, `#[Override]` where a method overrides.

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
