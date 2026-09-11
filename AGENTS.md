# Project Instructions - shlink

The URL shortener (`shlink`): it mints short URLs, serves every redirect, and is the **click-capture point of record** - the one place that sees a click together with its user agent, referer, full visited URL and resolved country. It records a visit per redirect, locates it, and publishes it to RabbitMQ, Mercure, Redis and webhooks. It computes no product metric and knows nothing about contacts, messages or organizations; attribution belongs to the services that consume its events.

These guidelines are tool-agnostic (Claude Code, other AI agents, and humans). The detail lives in `.ai/rules/**/*.md`, which is authoritative. Keep context lean: load only the reference the current task needs, and load it before you act. If multiple rules apply, follow the most specific first, then the more general.

## Reference index (load on demand)

| When the task is | Load |
|---|---|
| Any backend PHP change (phpcs/phpstan rules + SOLID/architecture principles) | `.ai/rules/backend/coding-standards.md` |
| Module layout, config aggregation and DI, HTTP pipeline and routes, events, Doctrine mappings and specifications, the CLI | `.ai/rules/backend/app-architecture.md` |
| A feature already planned for this service | `specs/<NNN>-<feature>/plan.md` - it records this repo's own conventions, gates and AC allocation for that feature |
| Configuring the app, or adding a setting | `Shlinkio\Shlink\Core\Config\EnvVars` is the complete list of env vars; `config/autoload/*.global.php` consumes them |
| Running the tests | the **Running the tests** section below; `./indocker_test --help` |
| Running the app locally | the **Running the app locally** section below |
| Running the heavier suites locally | `CONTRIBUTING.md` (upstream's, still accurate for the docker workflow) and the `./indocker` helper |

## Running the tests

This fork runs **one test suite: unit** (`module/*/test`, `phpunit.xml.dist`). Upstream's api, db and cli suites, their phpunit/infection configs, bootstraps, fixtures and composer scripts have been removed, and so have the CI jobs that ran them. Do not add a `test-api`, `test-db` or `test-cli` directory back; a behaviour that needs a database or a live HTTP server gets covered as a unit test with the collaborators mocked.

`./indocker_test [options] [target] [args...]` runs everything in a throwaway `sm-php-cli:8.3` container - no `docker-compose`, no database.

```bash
./indocker_test                                    # composer test:unit
./indocker_test --filter DeviceClassifierTest      # bare args -> phpunit
./indocker_test module/Core/test/Visit/XTest.php   # a single file
./indocker_test cs && ./indocker_test stan         # the gates from "Done" below
./indocker_test infect                             # unit tests + mutation testing
./indocker_test ci                                 # everything CI runs
./indocker_test --raw 'composer dump-autoload'     # any command in the container
```

Targets are composer scripts: `unit` (default), `unit:ci`, `unit:pretty`, `infect`, `cs`, `cs:fix`, `stan`, `swagger`, `ci`. Anything after the target is forwarded verbatim, so phpunit flags and paths work; an argument that is not a known target is treated as a phpunit argument for the unit suite. Options come before the target: `-p/--php VER`, `-n/--network NAME`, `-e/--env-file FILE`, `-r/--raw 'CMD'`, `-h/--help`.

The container runs as the host uid/gid, so `build/` and `.phpunit.result.cache` do not come back root-owned. `APP_ENV`, `GENERATE_COVERAGE`, `XDEBUG_MODE` and `COMPOSER_PROCESS_TIMEOUT` are forwarded from the shell when set.

GitHub Actions (`.github/workflows/ci.yml`) runs the same gates on every push and pull request to `develop`, `release`, `hotfix` and `midsprint`: `stan` and the unit suite, in that order, in a single job. `phpcs` and `swagger:validate` stay local gates - a repo-wide `phpcs` run is red on files inherited from upstream. Because this fork is public, the job's first step fails - before checkout - on pull requests opened from a different fork; they would otherwise run untrusted code with the composer token that reads our private packages.

## Running the app locally

Locally this service runs as the `micro-shortener` service of **sm-tool** (`../sm-tool`), on openswoole against `sm-postgres` and `sm-redis`. sm-tool owns the wiring (compose service, `dockerfiles/sm-shortener.Dockerfile`, nginx configs, `MICRO_SHORTENER_*` vars); this repo owns its env (`.env`, template in `.env.example`) and its boot script (`data/infra/micro-shortener-start.sh`, run from the bind mount at `/app`).

```bash
cd ../sm-tool
cp ../shlink/.env.example ../shlink/.env   # first time: fill in DB_PASSWORD and LOCAL_API_KEY
./sm.sh up micro-shortener
./sm.sh test micro-shortener               # unit suite, same image as ./indocker_test
./sm.sh analysis:run micro-shortener       # phpstan
docker exec -it sm-micro-shortener php bin/cli <command>
```

Reachable directly on `:8047` and through sm-nginx at `https://core.salesmsg.local/local/v2/shortener/`. That prefix is `BASE_PATH`, so it prefixes every route **and** every generated short URL; nginx must pass it through unstripped, and the REST API lives at `<prefix>/rest/v3/...`.

Three traps, all already handled in the start script and configs - do not undo them:

- **`LOCAL_API_KEY`, never `INITIAL_API_KEY`.** `InitialApiKeyDelegator` queries the DB while building `Mezzio\Application`, which under openswoole happens in the master **before it forks**; every worker then inherits that one connection and fails its first query. The start script creates the key over the CLI instead.
- **The FastRoute cache is enabled unconditionally** (`config/autoload/router.global.php`) and does not track `BASE_PATH` or route changes. The start script deletes `data/cache/fastroute_cached_routes.php` on boot; without it a healthy container 404s everything.
- **nginx must set `X-Forwarded-For`** (and `Host`, `X-Forwarded-Proto`). Without it every click is recorded with the nginx container's IP - fatal for a click-capture service. Shlink trusts the immediate peer, so nothing needs whitelisting.

## Always

- Keep every change additive and narrow, in the fewest files. Do not reformat, do not refactor surrounding code, and do not change the shape of an existing published field. `data/migrations/` is append-only and `docs/adr/` is upstream's.
- **Done** here means: **the whole unit suite green** - 935 tests, no known-red baseline left to net out - plus `phpcs`/`phpstan` clean on the files you touched, and any endpoint change reflected in `docs/swagger/` (`composer swagger:validate`). A repo-wide `phpcs` still reports 55 auto-fixable errors in 9 untouched files, and `phpstan` is green with 17 pre-existing errors suppressed in `phpstan-baseline.neon` - never regenerate that baseline to silence an error you introduced. The inventory is in `.ai/rules/backend/coding-standards.md`.
- One test suite: `module/*/test`, run with `./indocker_test` (see **Running the tests**). Never run `vendor/bin/phpunit` on the bare host.
- PHPUnit is **9.6**: `@test`, `@dataProvider` and `@group` are **docblock annotations**, never PHP attributes. `#[Group('spec:...')]` is silently ignored here, which inverts the shared convention used in `core`, `billing` and `micro-analytics`.
