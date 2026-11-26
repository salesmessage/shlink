---
description: Use this rule when adding, changing, or explaining the backend part of the project - module layout, config-aggregation wiring, HTTP pipeline, event dispatch, persistence and the CLI.
---

> **Service-specific rule.** Unlike the other repos in the organization, this service is **not** a Laravel
> application, so this file is not a copy of `core/.ai/rules/backend/app-architecture.md` and must not be
> synced with it. Patterns named in the shared rules (Eloquent repositories, jobs, service providers) have
> different equivalents here, described below.

# Backend Architecture - shlink

## Tech stack overview

- **Language**: PHP 8.5 in production and CI (`composer.json` requires `^8.4`).
- **Base**: upstream shlinkio/shlink v5.1.9 with our commits on top.
- **Framework**: [Mezzio](https://docs.mezzio.dev/) (PSR-15 middleware) on Laminas ServiceManager 4, wired by `laminas/laminas-config-aggregator`. No facades, no global helpers, no auto-wiring.
- **Runtime**: RoadRunner (`bin/roadrunner-worker.php`, `config/roadrunner/.rr.yml`); HTTP workers plus job workers for async event listeners. Upstream also ships FrankenPHP and php-fpm entry points; we do not run them.
- **Persistence**: Doctrine ORM 3 / DBAL 4 with **PHP-based mappings** and `shlinkio/doctrine-specification` for query composition. Production is **PostgreSQL**; upstream keeps sqlite, MySQL, MariaDB and MsSQL working.
- **Migrations**: `doctrine/migrations`, `module/Core/migrations/` (namespace `ShlinkMigrations`), configured in `config/cli-config.php`.
- **Input mapping**: request bodies and queries are mapped to value objects with `cuyz/valinor` (`mezzio/mezzio-valinor`).
- **Async / integration**: Kafka for `core` (`salesmessage/streaming`), and upstream's RabbitMQ, Mercure, Redis pub/sub and Matomo, all as event listeners.
- **CLI**: Symfony Console 8, entry point `bin/cli`.
- **Geolocation**: MaxMind GeoLite2 through `shlinkio/shlink-ip-geolocation`; the database is downloaded and refreshed by the app itself (`UpdateGeoLiteDb` listener), no cron.
- **API docs**: OpenAPI JSON under `docs/swagger/`, validated by `composer openapi:validate`; events under `docs/async-api/`.
- **Tests**: PHPUnit 13 with attributes, `shlinkio/shlink-test-utils` base classes; unit, db, api and cli suites.

## Module layout

Three modules under `module/`, each with its own `src`, `config` and test directories:

```
module/
├── Core/   Shlinkio\Shlink\Core  - domain: short URLs, visits, tags, domains, redirects, events; migrations
├── Rest/   Shlinkio\Shlink\Rest  - REST actions, API keys, auth middleware
└── CLI/    Shlinkio\Shlink\CLI   - Symfony Console commands
```

Dependency direction: `Rest` → `Core` and `CLI` → `Core`. `Core` must not depend on `Rest` or `CLI`.

Inside `Core`, code is grouped by **concept, not by layer**: `ShortUrl/`, `Visit/`, `Tag/`, `Domain/`, `RedirectRule/` each hold their own `Entity/`, `Model/`, `Repository/`, `Spec/`, `Paginator/`, `Persistence/`, `Transformer/`, `Helper/`. Put new code in the concept folder it belongs to; do not create a top-level `Services/` or `Repositories/` folder.

## Configuration and dependency injection

Everything is merged config: `config/config.php` runs the `ConfigAggregator` over the env-var loader, third-party `ConfigProvider`s, each module's `ConfigProvider` (which globs `module/<Module>/config/{,*.}config.php`), `config/autoload/{,*.}{global,local}.php`, test config when `APP_ENV=test`, and `config/autoload/routes.config.php` last. `config/container.php` builds the `ServiceManager`.

Prefer `ConfigAbstractFactory`: register the class under `dependencies.factories`, then list its constructor dependencies, in order, under the `ConfigAbstractFactory::class` key of the same file. Write a factory class only when construction needs logic. Delegators decorate an already-built service.

Runtime configuration is environment variables. `Shlinkio\Shlink\Core\Config\EnvVars` is the complete list. To add a setting: add the case to `EnvVars`, consume it in a `config/autoload/*.global.php`, and surface it through an options class (`module/Core/src/Config/Options/`) if application code needs it. Never read `getenv()` in application code.

## HTTP: pipeline, routes, actions

`config/autoload/middleware-pipeline.global.php` is the whole request flow. Everything under `/rest` gets problem-details error handling, body parsing and `AuthenticationMiddleware` (API key in `X-Api-Key`). The not-found branch holds orphan-visit tracking, extra-path redirects and the 404 redirects. The `IpAddress` middleware (`config/autoload/ip-address.global.php`, `TRUSTED_PROXIES`) runs before the tracking handlers.

REST actions extend `AbstractRestAction` with `ROUTE_PATH` and `ROUTE_ALLOWED_METHODS`; the ordered list is `config/autoload/routes.config.php`, and the order is significant. A new endpoint means: action class, entry in that list, and an update to `docs/swagger/`.

Redirects and the tracking pixel extend `Core\Action\AbstractTrackingAction`, which resolves the short URL, tracks the visit through `RequestTracker` and delegates the response.

## Events

`module/Core/config/event_dispatcher.config.php` maps events to listeners in two halves: `regular` (in-process) and `async` (RoadRunner job workers). `UrlVisited` and `ShortUrlCreated` go to the async listeners. Every async listener is wrapped with `CloseDbConnectionEventListenerDelegator`; a new one must be too. `PublishingUpdatesGenerator` and `Topic` shape the published payloads: changing them changes what downstream services receive.

## Persistence

- Mappings are PHP files at `module/*/config/entities-mappings/<FQCN>.php` using `ClassMetadataBuilder`; entities carry no attributes.
- Use `Core\fieldWithUtf8Charset()` for string columns in mappings, as the existing ones do.
- Repositories extend `EntitySpecificationRepository`; reusable query fragments are Specification classes in the concept's `Spec/` folder. API-key scoping (`Rest\ApiKey\Role::toSpec()`) works through them: route every new query over short URLs or visits through the same specs.
- Migrations live in `module/Core/migrations/` and are **append-only**. Write `up()` so it is safe on the production Postgres size; a backfill over `visits` or `short_urls` belongs in a CLI command, not a migration.

## CLI

Commands live in `module/CLI/src/Command/<Area>/` with a `public const NAME`, registered in `module/CLI/config/cli.config.php` plus a factory entry. They are adapters over the same `Core` services. Long-running or exclusive commands use `symfony/lock` (`data/locks`).

## Performance and safety notes

- Workers are long-lived: do not add mutable per-request state to a service or a static property.
- The redirect path runs on every click: keep its queries indexed and push extra work to an async listener.
- Raw SQL must work on PostgreSQL first.
