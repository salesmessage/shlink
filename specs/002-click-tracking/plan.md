# Per-Service Plan: shlink

**Feature**: `click-tracking` | **Date**: 2026-08-23; amended 2026-08-30 | **JIRA**: SWR-11033

**Spec**: product-specs `specs/002-click-tracking/spec.md` | **Common plan**: product-specs `specs/002-click-tracking/plan.md` | **Contract**: product-specs `specs/002-click-tracking/contracts/click-recorded.md`

**ADRs referenced below**, all in product-specs `specs/002-click-tracking/adr/`: `ADR-001` (`ADR-001-click-capture-point-of-record.md`), `ADR-002` (`ADR-002-attribution-durability.md`), `ADR-003` (`ADR-003-single-device-classifier.md`)

Self-contained handoff. A developer in `shlink` can execute this without reading the other services'
plans.

## What this service owns in this feature

`shlink` becomes the **click-capture point of record**: the one place that sees every click together
with the country and the user agent (`click-tracking#ADR-001`).

Almost all of that is already true. This service already records a visit for every redirect it
serves, with the user agent, the referer, the **full visited URL** - which is where the platform's
per-message marker survives - its own bot verdict, the click time, and a country resolved from the
local GeoLite2 database. It already publishes each located visit to RabbitMQ, Mercure, Redis and
webhooks - none of which `core` consumes. It already answers an account-wide, date-ranged, paginated
visit listing.

Three things are missing, and they are the whole of this service's work:

1. **A device class.** `shlink` stores the user agent and classifies nothing. This feature puts the
   product's single device classifier here, so that the Click tracking area and the platform's
   Click-to-Text visit view give one answer per click (`click-tracking#ADR-003`).
2. **A complete published payload.** The published visit currently omits `visitedUrl` and the visit's
   own id, so a consumer cannot recover the marker or deduplicate. Both are added, along with the
   device class.
3. **A publisher the platform actually consumes.** The four existing notifiers reach nobody in this
   feature. The platform's cross-service event bus is **Kafka**, and there is no Kafka client in this
   repository at all, so the located visit gains one more `async` publisher - onto Kafka, beside the
   existing ones, not instead of them (`click-tracking#ADR-002`).

**What this service does not own.** It learns nothing about contacts, sources, source records,
organizations or inboxes, and it computes no metric. It does not attribute a click to a message - it
publishes the URL the recipient reached and lets the consumer parse it. It does not decide what a
figure means. Keep it that way: every product concept that leaks in here has to be carried through
every future upstream merge.

## This repo's own rules

| Concern | This repo's rule |
|---|---|
| Agent instructions | [`AGENTS.md`](../../AGENTS.md), pulled in by [`CLAUDE.md`](../../CLAUDE.md), with the detail in `.ai/rules/**` - authoritative, load only what the task needs. Upstream's [`CONTRIBUTING.md`](../../CONTRIBUTING.md) is still accurate for the docker workflow and the command list, but it is written for outside contributors to `shlinkio/shlink`, not for this fork |
| Fork discipline | This is a maintained fork of `shlinkio/shlink`. Keep every change under `module/`, `config/`, `data/` or `bin/` additive and narrow so upstream merges stay cheap, and call out a permanent divergence in the PR description with the JIRA key. Establish the baseline before blaming your change, and judge your work on the files you touched. As of 2026-08-31 only `phpcs` is still red (55 auto-fixable errors in 9 files); the unit suite is green and `phpstan` is green behind `phpstan-baseline.neon` |
| Run the tests | `./indocker_test` (unit suite - the only one this fork runs). A single case: `./indocker_test --filter <TestName> <path>`. See [Testing](../../README.md#testing) |
| Definition of done | `./indocker_test ci` green - it parallelises `cs` (phpcs), `stan` (**phpstan level 8**, per the `stan` script in `composer.json`), `swagger:validate` and the unit suite, then infection mutation testing with an **MSI threshold of 80**. The mutation gate is the one most likely to fail a change here; write the tests that kill the mutants, do not lower the threshold |
| Test conventions | One kind: unit, mocked, high coverage - this fork removed upstream's db, api and cli suites. Tests live in each module's own `test/` folder mirroring `src/`. `phpunit.xml.dist` declares the `Core`, `Rest` and `CLI` suites; **repository and `Spec` classes are excluded from coverage by design** |
| AC tagging | **New here - no `spec:` tag exists in this repo yet.** PHPUnit is **9.6** (`composer.json`), which does **not** support PHP attributes for groups, so the tag is the docblock annotation `@group spec:click-tracking:AC-N` on the test method, **not** `#[Group(...)]`. Do not copy the attribute form used in the platform's PHP repos |
| Commit and PR | Base and target branch `develop` ([`CONTRIBUTING.md`, "Pull request process"](../../CONTRIBUTING.md)). Upstream's "open an issue first" step does not apply to this fork - the fork's own convention, visible in its history, is a JIRA-key commit subject (`SWR-11033 ...`). Record the `product-specs` commit SHA of the spec this was built against in the PR description. On AI-assisted commits keep the AI co-author trailer your tool emits |
| Anything not to touch | This is a maintained fork of `shlinkio/shlink` that tracks upstream. Keep every change **additive and narrow**, in the smallest number of files, so upstream merges stay cheap. Do not reformat, do not refactor surrounding code, and do not change the shape of an existing published field. `docs/adr/` is upstream's ADR set - this feature's ADRs live in `product-specs`, not there. `data/migrations/` is append-only |

## Acceptance criteria owned

Quoted from the spec so they can be tagged in tests.

| AC | Criterion |
|---|---|
| **AC-4** | Given bot traffic on a short link, when figures are produced, then bot clicks are excluded from the per-campaign aggregate totals and are not written as per-contact click activity. |
| **AC-10** | Given a click is recorded, when its timestamp is stored, then it is stored in UTC. |
| **AC-11** | Given a message is delivered from any reportable source, when a recipient clicks a short URL in it, then the click is attributable to that message, contact, source record, source and inbox, and carries a resolved country and a device class. |
| **AC-14** | Given a click whose device type is outside Mobile, Tablet and Desktop, or is unknown, when its device class is assigned, then it is classified as Other with its underlying type named, and is **never** classified as Desktop. |
| **AC-15** | Given the same click is visible in the Click tracking area and in the Click-to-Text visit view, when its device class is displayed in both, then both show the same class, because both use the same classifier. |

AC-4, AC-11 and AC-15 are **shared with `core`**, which owns the other half of each. This service's
half is: publish a bot verdict the consumer can act on (AC-4); publish a country, a device class and
the visited URL carrying the marker, so the consumer can attribute without a second call (AC-11); and
be the single classifier both surfaces read (AC-15). Attribution itself, and what the Click-to-Text
view renders, are `core`'s.

**AC-12 is not allocated to this service.** Attribution durability is wholly `core`'s, carried by the
consumer's offset handling on the Kafka stream. This service publishes each located visit once and
owns no recovery feed.

## Starting point in the code

| Concern | Where it is today |
|---|---|
| The visit entity | `module/Core/src/Visit/Entity/Visit.php` - holds `userAgent`, `referer`, `remoteAddr`, `visitedUrl`, `date`, `potentialBot`, `shortUrl`, `visitLocation`. `jsonSerialize()` at the bottom is what gets published, and it currently emits only `referer`, `date`, `userAgent`, `visitLocation`, `potentialBot` |
| Where a visit is built | `Visit::forValidShortUrl()` -> `hydrateFromVisitor()`, from `module/Core/src/Visit/Model/Visitor.php`. `Visitor::fromRequest()` sets `visitedUrl` to the full request URI, which is why the marker survives |
| Bot verdict | `Visitor::__construct` sets `potentialBot = isCrawler($userAgent)` (`Shlinkio\Shlink\Core\isCrawler`) |
| Tracking and event dispatch | `module/Core/src/Visit/VisitsTracker.php` - persists, flushes, dispatches `UrlVisited` |
| Geolocation | `module/Core/src/EventDispatcher/LocateVisit.php`, wired as a **`regular`** listener of `UrlVisited` in `module/Core/config/event_dispatcher.config.php`. Writes `module/Core/src/Visit/Entity/VisitLocation.php` (country code, country name, region, city, lat/long, timezone) |
| Publishing | `module/Core/src/EventDispatcher/PublishingUpdatesGenerator.php` builds `{shortUrl, visit}`; `module/Core/src/EventDispatcher/RabbitMq/NotifyVisitToRabbitMq.php` and its `AbstractNotifyVisitListener` publish it as **`async`** listeners of `VisitLocated`, alongside the Mercure, Redis and webhook notifiers (`module/Core/config/event_dispatcher.config.php`). Topics in `module/Core/src/EventDispatcher/Topic.php` |
| Kafka | **Absent.** No `ext-rdkafka` in `composer.json`, none installed by the `Dockerfile` or `data/infra/*.Dockerfile`, and no Kafka entry under `config/autoload/`. Step 4 adds all of it. The client is the in-house `salesmessage/streaming` (`php-lib-streaming`): framework-agnostic - `php >= 8.2`, `ext-rdkafka`, `psr/log`, `psr/container`, satisfied by this repo's `php:8.3-alpine3.19` image - and already used outside Laravel by `micro-workflows`, whose `config/container/infrastructure-streaming.php` and `src/Infrastructure/EventDispatcher.php` are the working precedent for both the wiring and the send-then-flush call |
| Per-campaign totals the platform polls | `module/Rest/src/Action/Visit/ShortUrlVisitsAction.php`, route `/short-urls/{shortCode}/visits`, with `excludeBots` - this is what AC-4's per-campaign half already rests on. Index `IDX_visits_short_url_id_potential_bot` was added for it in `data/migrations/Version20251230163550.php` |
| Config precedent for a new option | `config/autoload/tracking.global.php`, `rabbit.global.php` - both read env vars declared in `Shlinkio\Shlink\Core\Config\EnvVars`, which is the complete list ([`AGENTS.md`](../../AGENTS.md), "Configuring the app"). Everything is wired by merged config, not auto-discovery; if a config change appears to do nothing, delete `data/cache/app_config.php` |

## Implementation steps, in order

1. **Add the device classifier.** A small, self-contained class in `module/Core/src/Visit/` that takes
   a user-agent string and returns a class plus a detail. Four classes only: `mobile`, `tablet`,
   `desktop`, `other`. The rule that matters, and the one AC-14 is written against: **`other` is the
   fallback, `desktop` is not.** A user agent is only `desktop` when it positively looks like one; an
   unrecognized, empty or absent user agent is `other` with the detail naming what it was, or an
   explicit unknown marker where nothing can be said. Keep it a pure function of the string - no
   entity, no container dependency, no database - so it is trivially unit-testable and cheap to carry
   across upstream merges.

2. **Store the class on the visit.** Two nullable columns on `visits` (class and detail) via a new
   append-only migration in `data/migrations/`, mapped in the Doctrine mapping for `Visit`. Assign
   them in `hydrateFromVisitor()`, from the same user agent the bot verdict already uses, so a visit
   is classified exactly once at capture. Nullable because visits captured before this migration have
   no class, and every consumer must tolerate that.

3. **Add the four fields to `Visit::jsonSerialize()`**: `id`, `visitedUrl`, `deviceType`,
   `deviceTypeDetail`. This is the step most likely to be got wrong, in two ways:
   - `jsonSerialize()` feeds **more than one** consumer - the REST responses, the Mercure, Redis and
     webhook notifiers, and the RabbitMQ payload. Adding fields is additive and safe; changing or
     reordering an existing one is a breaking change to a published API. Only add.
   - `NotifyVisitToRabbitMq` has a **legacy publishing mode** (`legacyVisitsPublishing`) that emits a
     different envelope. The consumer contract is written against the non-legacy
     `{shortUrl, visit}` shape from `PublishingUpdatesGenerator`, and the Kafka publisher in step 4
     must emit that shape whatever the RabbitMQ notifier is configured to do. Do not copy the legacy
     branch into the new listener, and say in the PR which mode this deployment runs.

4. **Publish the located visit onto the platform's Kafka event bus.** One new `async` listener on
   `VisitLocated`, beside `NotifyVisitToRabbitMq` and not replacing it, publishing the payload
   `PublishingUpdatesGenerator` already builds, per `click-recorded`:
   topic **`shortener`** with the deployment's topic prefix applied, event key
   **`shortener.click_recorded`** in the `event-key` message header, JSON body. The topic already
   exists - `core` produces its own `shortener.*` events to it - so nothing has to be created; the
   consumer selects on the event key.
   - **The client.** `salesmessage/streaming` (`php-lib-streaming`): add the `repositories` VCS entry
     and the requirement to `composer.json`, and install the extension it needs in `Dockerfile` and
     `data/infra/*.Dockerfile` the way the image already installs openswoole - `librdkafka-dev` in the
     temporary `.dev-deps`, `pecl install rdkafka`, `docker-php-ext-enable rdkafka`, and `librdkafka`
     in the runtime `apk add`. Note the library needs **PHP >= 8.2**: the image is 8.3, but this repo's
     own `composer.json` still declares `php: ^8.1`, so a developer on 8.1 will not be able to install.
   - **Wiring, following `micro-workflows`.** Build a `KafkaProducerConfig` (`metadata.broker.list`
     from config, an `acks`/timeout/idempotence set, and `setOnError()` logging), `KafkaProducerOptions`
     for the flush policy, a `KafkaProducerDriver`, and a `SimpleProducer` with the JSON encoding
     interceptor. Register them as a `dependencies.config.php` entry (`ConfigAbstractFactory`, like the
     listeners next door) and add the listener under `events.async` for `VisitLocated` in
     `module/Core/config/event_dispatcher.config.php`.
   - **Config and env.** A new `config/autoload/kafka.global.php` shaped like `rabbit.global.php`,
     reading new `EnvVars` cases - `KAFKA_ENABLED`, `KAFKA_BROKERS`, `KAFKA_TOPIC_PREFIX`,
     `KAFKA_VISITS_TOPIC` (default `shortener`). `EnvVars` is the complete list of this service's env
     vars, so a variable that is not a case there does not exist. **`KAFKA_ENABLED` defaults to off**:
     the brokers are reachable from this service but it has never been configured to use them, and the
     values are set per environment before deploy. The prefix must match what `core` applies on the
     consumer side, or the two sides will use different topics in the same cluster.
   - **Send then flush.** `send()` alone is not delivery: this service runs long-lived
     openswoole/RoadRunner workers, so follow the send with `flush()` (bounded timeout and attempts,
     as `micro-workflows` does) or a message can sit in the client buffer until the worker happens to
     poll again.
   - **Best effort towards the redirect, loud towards the operator.** Wrap the whole thing the way
     `AbstractNotifyVisitListener::__invoke` does: catch `Throwable`, log, continue. A produce failure
     must not touch the visit, the redirect, or the other notifiers. But log it **at error level,
     naming the visit id and short code**, not at the debug level the neighbouring notifiers use:
     Kafka is the only delivery path to `core`, nothing sweeps up afterwards, so this line is the
     sole record that a captured click will never become a figure. It has to be greppable.
   - **Nothing new on the redirect path.** `VisitLocated` is already dispatched on the `async`
     channel; publishing from anywhere earlier, or synchronously, is what `click-tracking#ADR-001`
     rules out.

   Call this divergence out in the PR description with the JIRA key - it is a permanent fork
   divergence, and the PR is how the next upstream merge finds it.

5. **Update the API definition.** `docs/swagger/` describes the visit shape and
   `swagger:validate` is part of `composer ci`. The new fields go in, including the enum for the
   device class.

6. **Do not change bot detection.** `isCrawler()` stays exactly as it is. AC-4's requirement here is
   that the verdict is *published* so the consumer can act on it - `potentialBot` already is. The
   over-broad bot list that causes trouble is in a different service and is out of scope.

7. **Do not move geolocation.** `LocateVisit` stays a `regular` listener doing a local file lookup.
   Moving it to `async` would mean publishing visits before they are located, and everything
   downstream assumes the country is present when the click is published.

## Contracts

Index only. This service's side of each boundary - its role, what it emits, the mapping onto this
repo, the obligations on it and its contract tests - is in the file named below. The shape itself is
neither here nor there: it lives in the canonical contract in `product-specs`.

| Contract | Role | This service's file | Canonical definition |
|---|---|---|---|
| `click-recorded` | producer | [./contracts/click-recorded.md](./contracts/click-recorded.md) | product-specs `specs/002-click-tracking/contracts/click-recorded.md` v`2` |

**Consumed**: none. This service calls nothing in this feature.

> **PACT: not required for this feature.** Decided for click-tracking on 2026-08-28, resolving what
> earlier drafts of this plan carried as an open item for the BA. `shlink` and `salesmsgapp-ui` do
> **not** need PACT contract tests for click-tracking. Neither repo has the tooling, and rather than
> ship the one-sided pact the contract gate explicitly forbids, **neither half of a pact is written**
> for the `click-recorded` and `click-tracking-reporting` boundaries. Each boundary is instead covered
> by the tests named in its contract file above, asserted against the canonical payload and response
> shapes. `link-click-dataset` never needed one: its transport is replication, so there is nothing for
> a pact to mediate. This is a recorded decision, not an unresolved gap, and it changes no specified
> behavior - so it needs no spec entry.

## Test plan

Tests first, red then green. Tag with the docblock annotation - PHPUnit 9.6, so `@group`, not an
attribute.

| AC | Test | Tag |
|---|---|---|
| AC-14 | Unit test on the classifier: a table of user agents covering mobile, tablet, desktop, a smart TV, a console, an empty string and a null. Asserts the class **and** that no unrecognized agent returns `desktop`. The negative assertion is the point of the test | `@group spec:click-tracking:AC-14` |
| AC-14 | Unit test on `Visit`: a visit built from a `Visitor` with an unrecognized user agent exposes class `other` with its detail set | `@group spec:click-tracking:AC-14` |
| AC-15 | Unit test on `Visit::jsonSerialize()`: the serialized class is the classifier's output for that exact user agent, so the one consumer that displays it cannot be reading something derived elsewhere | `@group spec:click-tracking:AC-15` |
| AC-11 | Unit test on `PublishingUpdatesGenerator`: the payload carries `shortUrl.shortCode`, `shortUrl.domain`, `visit.id`, `visit.visitedUrl` with its query string intact, `visit.deviceType` and `visit.visitLocation` with country code and name | `@group spec:click-tracking:AC-11` |
| AC-11 | Unit test on the Kafka publisher listener: a located visit produces exactly one message, on the configured topic, whose body is the `{shortUrl, visit}` payload and whose `event-key` header is `shortener.click_recorded`, and the producer is flushed | `@group spec:click-tracking:AC-11` |
| - | Unit test: a producer that throws is caught and logged and the listener returns normally, leaving the visit and the other notifiers untouched - the best-effort guarantee the contract states | untagged |
| - | Unit test: with the publisher disabled by config, nothing is produced and no other notifier changes behavior | untagged |
| AC-11 | Unit test: a redirect on a short URL whose request carries a marker query parameter produces a visit whose `visitedUrl` still contains it | `@group spec:click-tracking:AC-11` |
| AC-11 | Unit test: a visit with no resolvable location serializes `visitLocation` as absent, and the visit is still recorded - the degradation path, not an error | `@group spec:click-tracking:AC-11` |
| AC-10 | Unit test: the serialized `date` is UTC regardless of the configured application timezone | `@group spec:click-tracking:AC-10` |
| AC-4 | Unit test: a visit from a crawler user agent serializes `potentialBot: true`, so the consumer can exclude it | `@group spec:click-tracking:AC-4` |
| AC-4 | Unit test: the short-URL visit query passes `excludeBots` through to both visit filters. Wiring only - the repository is mocked, so the omission itself is not asserted here; that was T017, withdrawn with the db and api suites | `@group spec:click-tracking:AC-4` |
| - | ~~DB test: the new columns persist and read back, including nulls for pre-migration rows~~ - dropped with the db suite | untagged |
| - | ~~Migration test on all five engines via `composer test:db`~~ - dropped with the db suite | untagged |

Mutation testing will attack the classifier's branches hardest. Write the table-driven test with one
case per branch and one per boundary, or the MSI gate will fail.

## Dependencies and sequencing

> **Amended 2026-08-30 - no work changed here, but the stakes did.** `micro-shortener-proxy` stops
> writing its own click record, so this service becomes the platform's **only** capture point
> (`ADR-001`, amended). Not one implementation step, test, contract or acceptance criterion in this
> plan moves - the publisher publishes the same click to the same stream. What changes is the
> consequence of it **not** publishing: the per-campaign clicked / did-not-click lists in `core`, which
> ship today, now depend on this stream too. Switching the publisher on is also step 1 of a
> coordinated cutover rather than an independent deploy. Dependencies and Rollout below are the amended
> text.

**Position**: **First**, order 1 in the common plan's sequencing - and, since the 2026-08-30
amendment, the first step of the **cutover** (common plan, order 3) rather than a deploy that stands
alone. The publisher going on, `micro-shortener-proxy`'s write being removed, and `core`'s consumer
starting are one coordinated operation, walked in product-specs `quickstart.md`, scenario 0.

**Do not switch the publisher on and then wait indefinitely.** Between the publisher going on and the
proxy's write being removed, every click is captured twice - once here, once there - which is expected
and harmless only because nothing consumes this stream yet. Keep that window short and do not start
`core`'s consumer inside it.

**Depends on**: no other service's code. Every change here is additive, so it is safe to ship alone and
before any consumer exists. One deployment prerequisite: the Kafka environment variables from step 4
have to be set per environment before `KAFKA_ENABLED` is turned on. The topic exists already and the
brokers are reachable; only this service's configuration is new. Shipping with the publisher off is
safe but produces **nothing** downstream - there is no second path - so the flag going on is what
starts the feature, and clicks captured while it is off are not recoverable afterwards.

*(Amended 2026-08-30.)* After the cutover that sentence covers more than it did: with the proxy no
longer capturing, a publisher that is off means **no click is recorded anywhere on the platform** -
not for the account-wide figures and not for the per-campaign lists. Before the amendment the proxy
was a second, independent capture and this service's outage was invisible to the shipped screens. It
is not any more.

**Blocks**: `core`'s click consumer, and therefore everything downstream of it - which since the
2026-08-30 amendment includes `core`'s per-campaign clicked / did-not-click lists, not only the
unreleased account-wide area. `core` can be *built* against the frozen contract before this ships, but
nothing can be integration-tested against a real click until it does. It also blocks
`micro-shortener-proxy`'s deploy, which must not go ahead of this one: between the two there would be
no capture at all.

## Rollout and rollback

**Rollout**: No feature flag in this service. The account-wide Click tracking area is flagged per
organization in the web application, but **collection is deliberately not flagged** - BR-43 makes the
dataset accumulate forward only, so every day this is not deployed is history the product can never
show. Ship it early, ahead of the consumer.

**Rollback**: Revert the code; leave the migration. The columns are nullable and additive, so the
prior version runs against the new schema unchanged. Nothing else has to be unwound - no data is
rewritten and no existing field changes shape. Consumers that had started reading the new fields see
them absent, which they must already tolerate for pre-migration visits.

**Watch after deploy**:

- **Redirect latency, before and after.** The one property this feature promises is that it is
  unchanged. The classifier is a string comparison on a path that already parses the user agent, so
  any measurable move means something else got onto the hot path.
- **The class distribution.** A sudden `desktop` share close to what the platform's old classifier
  reported would mean the fallback is still wrong; a `other` share near 100 percent would mean the
  positive matches are not firing.
- **Produce failures in the log.** Logged at error level with the visit id, and worth an alert: the
  stream is the only path, so each one is a captured click that will never become a figure. The
  matching signal on the other side is consumer lag on the topic, which `core` watches.
  *(Amended 2026-08-30: after the cutover this alert covers a customer-visible screen - `core`'s
  who-clicked lists - and not only the flagged area. Set its threshold accordingly.)*
