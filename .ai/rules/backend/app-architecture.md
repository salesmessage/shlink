---
description: Use this rule when adding, changing, or explaining the backend part of the project - module layout, config-aggregation wiring, HTTP pipeline, event dispatch, persistence and the CLI.
---

> **Service-specific rule.** Unlike the other repos in the organization, this service is **not** a Laravel
> application, so this file is not a copy of `core/.ai/rules/backend/app-architecture.md` and must not be
> synced with it. Patterns named in the shared rules (Eloquent repositories, jobs, service providers) have
> different equivalents here, described below.

# Backend Architecture - shlink

## Tech stack overview

- **Language**: PHP 8.1+ (`composer.json` requires `^8.1`; upstream CI matrixes 8.1 and 8.2). Local hosts may run newer.
- **Framework**: [Mezzio](https://docs.mezzio.dev/) (PSR-15 middleware) on Laminas ServiceManager, wired by `laminas/laminas-config-aggregator`. No framework facades, no global helpers, no auto-wiring.
- **Runtimes**: openswoole, RoadRunner (`spiral/roadrunner`, `bin/roadrunner-worker.php`, `config/roadrunner/`) and classic php-fpm (`public/index.php`) all serve the same app.
- **Persistence**: Doctrine ORM 2 with **PHP-based mappings** and Happyr Doctrine Specification for query composition. Engines: sqlite, MySQL, MariaDB, PostgreSQL, MsSQL - **production here is PostgreSQL**.
- **Migrations**: `doctrine/migrations`, `data/migrations/`, configured by `migrations.php`.
- **Async / integration**: RabbitMQ, Mercure, Redis pub/sub, HTTP webhooks - all as event listeners.
- **CLI**: Symfony Console, entry point `bin/cli`.
- **Geolocation**: MaxMind GeoLite2 through `shlinkio/shlink-ip-geolocation`.
- **API docs**: OpenAPI JSON under `docs/swagger/`, validated by `composer swagger:validate`.
- **Tests**: PHPUnit **9.6** (no PHP attributes for groups/providers), plus `shlinkio/shlink-test-utils` base classes and Infection for mutation testing.

## Module layout

Three modules under `module/`, each a self-contained context with its own `src`, `config` and test directories:

```
module/
├── Core/   Shlinkio\Shlink\Core  - domain: short URLs, visits, tags, domains, redirects, events
├── Rest/   Shlinkio\Shlink\Rest  - REST actions, API keys, auth middleware
└── CLI/    Shlinkio\Shlink\CLI   - Symfony Console commands
```

Dependency direction: `Rest` → `Core` and `CLI` → `Core`. `Core` must not depend on `Rest` or `CLI`. (One upstream wart to be aware of, not to imitate: `Core` type-hints `Rest\Entity\ApiKey` in a few signatures.)

Inside `Core`, code is grouped by **concept, not by layer**: `ShortUrl/`, `Visit/`, `Tag/`, `Domain/` each hold their own `Entity/`, `Model/`, `Repository/`, `Spec/`, `Paginator/`, `Persistence/`, `Transformer/`, `Helper/`. Put new code in the concept folder it belongs to and mirror that structure; do not create a top-level `Services/` or `Repositories/` folder.

## Configuration and dependency injection

There is no service-provider auto-discovery. Everything is merged config.

1. `config/config.php` runs the `ConfigAggregator` over, in order: the env-var loader, third-party `ConfigProvider`s, each module's `ConfigProvider`, `config/autoload/{,*.}{global,local}.php`, test config when `APP_ENV=test`, and `config/autoload/routes.config.php` **last**.
2. Each module's `ConfigProvider` simply globs its own `module/<Module>/config/{,*.}config.php`. To add a config file to a module, drop it in that folder - no registration step.
3. Three post-processors then rewrite the merged array: `BasePathPrefixer`, `MultiSegmentSlugProcessor`, `ShortUrlMethodsProcessor` (`module/Core/src/Config/PostProcessor/`).
4. `config/container.php` builds the `ServiceManager` from `$config['dependencies']` and registers the merged array as the `config` service.

The merged config is cached to `data/cache/app_config.php` when `ConfigAggregator::ENABLE_CACHE` is on - on for non-CLI SAPIs, off under `APP_ENV=test`, and off in dev if `config/autoload/common.local.php` was copied from the `.dist`. **If a config change seems to have no effect, delete that cache file** (or run `composer clean:dev`, which also drops the dev sqlite database and `config/params/generated_config.php`).

### Declaring a service

Prefer `Laminas\ServiceManager\AbstractFactory\ConfigAbstractFactory`: register the class under `dependencies.factories`, then list its constructor dependencies, in order, under the `ConfigAbstractFactory::class` key of the same file. Write a dedicated factory class (`module/*/src/Factory/`) only when construction needs logic. Delegators decorate an already-built service - that is how `CloseDbConnectionEventListenerDelegator` wraps every async listener.

Config values reach services either as an `Options` object (`module/Core/src/Options/*` - plain final classes with promoted constructor properties, built from a config key by a factory) or as a dotted config service name such as `'config.redis.pub_sub_enabled'`.

### Configuration values themselves

Runtime configuration is environment variables. `Shlinkio\Shlink\Core\Config\EnvVars` is the **complete enumerated list**; the installer writes the resolved values into `config/params/generated_config.php`, and env vars take precedence over installer answers (`docs/adr/2022-01-15-...`). To add a setting: add the case to `EnvVars`, consume it in a `config/autoload/*.global.php`, and surface it through an `Options` class if application code needs it. Never read `getenv()` directly in application code.

## HTTP: pipeline, routes, actions

`config/autoload/middleware-pipeline.global.php` is the whole request flow. Points that matter:

- Everything under `/rest` gets problem-details error handling, `BodyParserMiddleware` and `AuthenticationMiddleware` (API key in the `X-Api-Key` header).
- The `not-found` branch is not just a 404 page: it is where orphan-visit tracking, extra-path redirects and the configurable 404 redirects live (`Core\ErrorHandler\*`, `Core\ShortUrl\Middleware\ExtraPathRedirectMiddleware`).
- `IpAddress` middleware is placed deliberately in front of the tracking handlers; visit tracking depends on it.

REST actions extend `AbstractRestAction`, declare `ROUTE_PATH` and `ROUTE_ALLOWED_METHODS` as class constants, and are turned into a route definition by `getRouteDef()`, which also takes pre/post middleware. The ordered list lives in `config/autoload/routes.config.php` and **the order is significant** - a broader path registered earlier will swallow a narrower one. A new endpoint means: action class, entry in that list, and a matching update to `docs/swagger/`.

Redirects, QR codes and the tracking pixel are PSR-15 middleware extending `Core\Action\AbstractTrackingAction`, which resolves the short URL, calls `RequestTracker::trackIfApplicable()`, and delegates the response to the subclass.

## Domain models and validation

Input arriving from HTTP or the CLI is validated into immutable value objects before it reaches a service: `ShortUrlCreation::fromRawData()`, `ShortUrlEdition`, `ShortUrlsParams`, `ShortUrlIdentifier`, `Visitor`. They have private constructors and named static factories, and they validate through `Laminas\InputFilter` filters under `Model/Validation/` (`ShortUrlInputFilter`), throwing `ValidationException`. Add a new field there, not in the action.

Output shaping is a `DataTransformer` (`Core\ShortUrl\Transformer\ShortUrlDataTransformer`, `Core\Visit\Transformer\OrphanVisitDataTransformer`) or the entity's own `jsonSerialize()`. Both are published contracts - see the contract gate in `.ai/rules/sdd.md`.

## Events

`module/Core/config/event_dispatcher.config.php` is the map, and it has two halves:

- `regular` listeners run in-process, inside the request. `UrlVisited` → `LocateVisit`, which resolves geolocation, writes `VisitLocation` and dispatches `VisitLocated`.
- `async` listeners are deferred to a task worker (openswoole task workers / RoadRunner jobs). `VisitLocated` → Mercure, RabbitMQ, Redis pub/sub, webhooks, GeoLite DB update. `ShortUrlCreated` → Mercure, RabbitMQ, Redis.

Every async listener is wrapped with `CloseDbConnectionEventListenerDelegator`, because the worker holds a long-lived connection. A new async listener must be registered with that delegator too.

`PublishingUpdatesGenerator` builds the payloads and `Topic` enumerates the channels. Changing either changes what downstream services receive.

## Persistence

- **Mappings are PHP files**, one per entity, at `module/*/config/entities-mappings/Fully.Qualified.Class.Name.php`, using the Doctrine `ClassMetadataBuilder`. A new entity needs its mapping file there; there are no attributes or annotations on the entity classes.
- Table and column names go through `Core\determineTableName()` (schema prefix) and `Core\fieldWithUtf8Charset()` (MySQL charset) - use them in new mappings so the five supported engines keep working.
- Repositories extend Happyr's `EntitySpecificationRepository` (set as the default repository class). Reusable query fragments are **Specification classes** in the concept's `Spec/` folder, composed with `Spec::andX()`. This is how API-key scoping works: `Rest\ApiKey\Role::toSpec()` turns a key's roles into specifications that constrain every list and count query. When you add a query path over short URLs or visits, route it through the same specs or it will leak other keys' data.
- Complex read queries live in dedicated query/persistence classes (`ShortUrl/Repository/`, `Visit/Persistence/*Filtering`), and listings are paginated with Pagerfanta adapters under `Paginator/`.
- Migrations are generated into `data/migrations/` from `data/migrations_template.txt`. They are **append-only**: never edit or delete an existing migration, and write `up()` so it is safe against the production Postgres.

## CLI

Commands live in `module/CLI/src/Command/<Area>/`, expose their name as a `public const NAME`, and are registered in `module/CLI/config/cli.config.php` plus a factory entry in `module/CLI/config/dependencies.config.php`. Commands are adapters: resolve input into the same value objects the REST side uses, call the same `Core` service, render with `SymfonyStyle`. Exit codes come from `CLI\Util\ExitCodes`. Long-running or exclusive commands extend `AbstractLockedCommand` (`symfony/lock`, `data/locks`).

## Performance and safety notes

- The app runs under long-lived workers. Static state persists between requests: the memoized statics in `module/Core/functions/functions.php` are deliberate, but do not add mutable per-request state to a service or a static property.
- Anything on the redirect path is hot. It runs on every click: keep queries there indexed and avoid adding synchronous I/O - push work to an async listener instead.
- Raw SQL must work on PostgreSQL first (production) and, where it lives in shared code, on the other four engines the test suites run.

## Running it locally

The dependencies are provided as containers; `CONTRIBUTING.md` has the upstream version of this, still accurate.

```bash
# first time
cp config/autoload/common.local.php.dist config/autoload/common.local.php   # and the other *.local.php.dist you need
cp docker-compose.override.yml.dist docker-compose.override.yml
docker-compose up -d
./indocker bin/cli db:create
./indocker bin/cli db:migrate
./indocker bin/cli api-key:generate

# day to day
./indocker_test                      # the unit suite, in a throwaway php-cli container
./indocker bin/cli list              # every command supports --help
composer clean:dev                   # drop the dev sqlite db and generated_config.php
```

Ports once up: `8000` nginx+php-fpm, `8080` openswoole, `8800` RoadRunner, `8001` Mercure proxy, `15672` RabbitMQ management, plus the five database containers.

The REST API is documented in `docs/swagger/swagger.json`; `composer swagger:validate` checks it and `composer swagger:inline` produces the single-file version. `docs/async-api/` documents the published events.
