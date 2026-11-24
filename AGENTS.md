# Project Instructions - shlink

The URL shortener (`shlink`): it mints short URLs, serves every redirect, and is the **click-capture point of record** - the one place that sees a click together with its user agent, referer, full visited URL and resolved country. It records a visit per redirect, locates it, and publishes it (Kafka for `core`, plus upstream's RabbitMQ, Mercure, Redis and Matomo integrations when enabled). It computes no product metric and knows nothing about contacts, messages or organizations; attribution belongs to the services that consume its events.

This repository is a fork of [shlinkio/shlink](https://github.com/shlinkio/shlink), based on upstream **v5.1.9** (remote `upstream`). Our changes sit on top as separate commits so a later upstream release can be merged in; keep them narrow.

These guidelines are tool-agnostic (Claude Code, other AI agents, and humans). The detail lives in `.ai/rules/**/*.md`, which is authoritative. Keep context lean: load only the reference the current task needs, and load it before you act. If multiple rules apply, follow the most specific first, then the more general.

## Reference index (load on demand)

| When the task is | Load |
|---|---|
| Any backend PHP change (mago rules + SOLID/architecture principles) | `.ai/rules/backend/coding-standards.md` |
| Module layout, config aggregation and DI, HTTP pipeline and routes, events, Doctrine mappings and specifications, the CLI | `.ai/rules/backend/app-architecture.md` |
| A feature already planned for this service | `specs/<NNN>-<feature>/plan.md` (written for the 3.x fork; check paths against the current code) |
| Configuring the app, or adding a setting | `Shlinkio\Shlink\Core\Config\EnvVars` is the complete list of env vars; `config/autoload/*.global.php` consumes them |
| Running the heavier suites or the full upstream dev stack | `DEVELOPING.md` (upstream's) and the `./indocker` helper |

## Tests and checks

The suites are upstream's: **unit** (`module/*/test`, `phpunit.xml.dist`), **db** (`module/*/test-db`, `phpunit-db.xml`), **api** (`module/*/test-api`, `phpunit-api.xml`, a RoadRunner server on port 9999) and **cli** (`module/*/test-cli`). Production runs PostgreSQL, so the db and api suites run against Postgres here.

```bash
composer test:unit
composer test:db:postgres          # needs a Postgres reachable as config/test/test_config.global.php says
composer test:api                  # needs bin/rr: vendor/bin/rr get --no-config --location bin/
composer cs                        # mago lint + format check
composer analyze                   # mago analyze (APP_ENV=test)
composer openapi:validate          # docs/swagger/swagger.json
```

Run them in a container with PHP 8.5, never on the bare host. GitHub Actions (`.github/workflows/ci.yml`) runs all of the above on PHP 8.5 with a Postgres service, on every push and pull request to `develop`, `release`, `hotfix` and `midsprint`. Because this fork is public, the job's first step fails on pull requests opened from a different fork; they would otherwise run untrusted code with the composer token that reads our private packages (`salesmessage/streaming`).

## Running the app locally

Locally this service runs as the `micro-shortener` service of **sm-tool** (`../sm-tool`), on RoadRunner against `sm-postgres` and `sm-redis`. sm-tool owns the wiring (compose service, `dockerfiles/sm-shortener.Dockerfile`, nginx configs, `MICRO_SHORTENER_*` vars); this repo owns its env (`.env`, template in `.env.example`) and its boot script (`data/infra/micro-shortener-start.sh`, run from the bind mount at `/app`).

```bash
cd ../sm-tool
cp ../shlink/.env.example ../shlink/.env   # first time: fill in DB_PASSWORD and LOCAL_API_KEY
./sm.sh up micro-shortener
docker exec -it sm-micro-shortener php bin/cli <command>
```

Reachable directly on `:8047` and through sm-nginx at `https://core.salesmsg.local/local/v2/shortener/`. That prefix is `BASE_PATH`, so it prefixes every route **and** every generated short URL; nginx must pass it through unstripped, and the REST API lives at `<prefix>/rest/v3/...`.

- The start script runs `shlink-installer init` (database, migrations, ORM proxies, caches, the `LOCAL_API_KEY` when no key exists) and then `rr serve`.
- **nginx must set `X-Forwarded-For`** (and `Host`, `X-Forwarded-Proto`). Without it every click is recorded with the nginx container's IP - fatal for a click-capture service. Which proxies are trusted is `TRUSTED_PROXIES`.

## Always

- Keep every change additive and narrow, in the fewest files. Do not reformat, do not refactor upstream code, and do not change the shape of an existing published field (REST `rest/v3` and the Kafka visit event are read by `core`). `module/Core/migrations/` is append-only and `docs/adr/` is upstream's.
- **Done** here means: the unit, db (Postgres) and api suites green, `composer cs` and `composer analyze` clean, and any endpoint change reflected in `docs/swagger/` (`composer openapi:validate`).
- Never regenerate `mago-analyze-baseline.toml`. Silence a finding you cannot fix with an inline `// @mago-expect <category>:<code> (reason)` on the line above.
- PHPUnit is **13**: tests use attributes (`#[Test]`, `#[DataProvider]`, `#[Group]`), as upstream's do.
