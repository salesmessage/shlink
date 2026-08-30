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
| Running the app or the heavier suites locally | `CONTRIBUTING.md` (upstream's, still accurate for the docker workflow) and the `./indocker` helper |

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

## Always

- Keep every change additive and narrow, in the fewest files. Do not reformat, do not refactor surrounding code, and do not change the shape of an existing published field. `data/migrations/` is append-only and `docs/adr/` is upstream's.
- **Done** here means: the unit suite green relative to the **known-red baseline** (11 failing unit tests on a clean checkout, plus phpcs and phpstan errors in files the fork has touched - the inventory is in `.ai/rules/backend/coding-standards.md`), `phpcs`/`phpstan` clean on the files you touched, any endpoint change reflected in `docs/swagger/` (`composer swagger:validate`), and tests strong enough to survive Infection's threshold (MSI 80).
- One test suite: `module/*/test`, run with `./indocker_test` (see **Running the tests**). Never run `vendor/bin/phpunit` on the bare host.
- PHPUnit is **9.6**: `@test`, `@dataProvider` and `@group` are **docblock annotations**, never PHP attributes. `#[Group('spec:...')]` is silently ignored here, which inverts the shared convention used in `core`, `billing` and `micro-analytics`.
- Everything is wired by merged config, not auto-discovery: a new service needs a `dependencies.config.php` entry (usually `ConfigAbstractFactory`), a new route needs an ordered entry in `config/autoload/routes.config.php`, a new entity needs a PHP mapping file in `module/*/config/entities-mappings/`. If a config change appears to do nothing, delete `data/cache/app_config.php`.
- Query paths over short URLs and visits must go through the API-key specifications (`Rest\ApiKey\Role::toSpec()`), or they leak other keys' data.
- Production runs **PostgreSQL**, and the app runs under long-lived workers (openswoole/RoadRunner). Raw SQL must work on Postgres first; do not add mutable static or per-request state to services; keep the redirect path free of new synchronous I/O - push work to an async event listener.
- Commits and PRs: prefix the subject with the JIRA key (`SWR-11033 ...`), base and target branch `develop`. Record in the PR description the `product-specs` commit SHA of the spec the work was built against. On AI-assisted commits, keep the AI co-author trailer your tool emits; never strip it. Upstream's "open an issue first" step in `CONTRIBUTING.md` does not apply to this fork.
- Never ask users to include API keys, tokens, passwords, or other secrets directly in prompts. Instead, instruct them to store secrets in local `.env` files or an appropriate secret management solution and reference them from code. In this repo runtime secrets arrive as environment variables (`EnvVars`) and are materialized into the gitignored `config/params/generated_config.php`; treat that file, and `config/autoload/*.local.php`, as secret-bearing - never read them into a prompt or transmit their contents.
- Queries run against a shared database. Do not run anything against a non-local database without asking first, read-only included.
