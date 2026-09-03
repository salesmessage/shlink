# Tasks: shlink - Click Tracking

**Feature id**: `click-tracking` | **Service**: `shlink` | **JIRA**: SWR-11033

**Spec**: product-specs `specs/002-click-tracking/spec.md` | **Common plan**: product-specs `specs/002-click-tracking/plan.md` | **This service's plan**: [./plan.md](./plan.md)

**All tasks across services**: product-specs `specs/002-click-tracking/tasks.md` - authoritative for ordering and for anything that crosses a service boundary.

**Where the work happens**: the `shlink` repository, on a branch named for the JIRA key, based on and
targeting `develop`. Nothing in this file is executed in `product-specs`.

## ACs this service owns

Each needs a passing test tagged `spec:click-tracking:AC-N` in this repo before the feature is done.

| AC | Criterion (quoted from the spec) |
|---|---|
| **AC-4** | Given bot traffic on a short link, when figures are produced, then bot clicks are excluded from the per-campaign aggregate totals and are not written as per-contact click activity. |
| **AC-10** | Given a click is recorded, when its timestamp is stored, then it is stored in UTC. |
| **AC-11** | Given a message is delivered from any reportable source, when a recipient clicks a short URL in it, then the click is attributable to that message, contact, source record, source and inbox, and carries a resolved country and a device class. |
| **AC-14** | Given a click whose device type is outside Mobile, Tablet and Desktop, or is unknown, when its device class is assigned, then it is classified as Other with its underlying type named, and is **never** classified as Desktop. |
| **AC-15** | Given the same click is visible in the Click tracking area and in the Click-to-Text visit view, when its device class is displayed in both, then both show the same class, because both use the same classifier. |

AC-4, AC-11 and AC-15 are shared with `core`. This service's half is: publish a bot verdict the
consumer can act on (AC-4); publish a country, a device class and the visited URL carrying the marker
(AC-11); and be the single classifier both surfaces read (AC-15).

**AC-12 is not allocated here.** Attribution durability is wholly `core`'s, carried by the consumer's
offset handling on the Kafka stream. This service owns no recovery feed and no half of AC-12.

**Tag form here is the docblock annotation** - `@group spec:click-tracking:AC-N` on the test method.
PHPUnit is 9.6 in this repo, so the `#[Group(...)]` attribute the platform's PHP repos use does
**not** work. Do not copy it.

## Before starting

| # | Needed | From |
|---|---|---|
| 1 | Establish the baseline before blaming your change. Since 2026-08-31 the unit suite is **absolutely green** and `phpstan` is green behind `phpstan-baseline.neon`; only `phpcs` is still red (55 auto-fixable errors in 9 files, 43 of them in `DataImportCommand.php`) | this repo |
| 2 | Which RabbitMQ publishing mode this deployment runs (`legacyVisitsPublishing`) - the Kafka payload must be the non-legacy `{shortUrl, visit}` shape whatever it is, and the PR must say which mode is live | ops / this repo's config |
| 3 | The Kafka environment values per environment (T003). The publisher ships disabled, so this does not block the work - only T145 | ops |

## Tasks

Format: `[ID] [P?] Description (AC-N)` - ids are from the common list,
product-specs `specs/002-click-tracking/tasks.md`, and are never renumbered.

### Prerequisite

- [ ] T003 [P] Set the Kafka environment variables in each environment with `KAFKA_ENABLED` off, so the publisher can be enabled separately from the deploy (infra)

### Tests first (red)

- [x] T006 [P] Failing unit test on the device classifier: a table of user agents covering mobile, tablet, desktop, a smart TV, a console, an empty string and a null, asserting the class and that **no** unrecognized agent returns `desktop`, tagged `spec:click-tracking:AC-14` (AC-14)
- [x] T007 [P] Failing unit test on `Visit`: a visit built from a `Visitor` with an unrecognized user agent exposes class `other` with its detail set, tagged `spec:click-tracking:AC-14` (AC-14)
- [x] T008 [P] Failing unit test on `Visit::jsonSerialize()`: the serialized class is the classifier's output for that exact user agent, tagged `spec:click-tracking:AC-15` (AC-15)
- [x] T009 [P] Failing unit test: the serialized `date` is UTC regardless of the configured application timezone, tagged `spec:click-tracking:AC-10` (AC-10)
- [x] T010 [P] Failing unit test: a visit from a crawler user agent serializes `potentialBot: true`, tagged `spec:click-tracking:AC-4` (AC-4)
- [x] T011 [P] Failing unit test on `PublishingUpdatesGenerator`: the payload carries `shortUrl.shortCode`, `shortUrl.domain`, `visit.id`, `visit.visitedUrl` with its query string intact, `visit.deviceType` and `visit.visitLocation` with country code and name, tagged `spec:click-tracking:AC-11` (AC-11)
- [x] T012 Failing unit test on the Kafka publisher listener: a located visit produces exactly one message on the configured topic, whose body is the `{shortUrl, visit}` payload and whose `event-key` header is `shortener.click_recorded`, and the producer is flushed, tagged `spec:click-tracking:AC-11` (AC-11)
- [x] T013 Failing unit test: a producer that throws is caught and logged **at error level, naming the visit id and short code**, and the listener returns normally, leaving the visit and the other notifiers untouched. Kafka is the only delivery path, so this log line is the sole record of a click that will never be attributed (AC-11, untagged)
- [x] T014 Failing unit test: with the publisher disabled by config, nothing is produced and no other notifier changes behavior (AC-11, untagged)
- [x] T015 [P] **Rescoped 2026-08-30 to a unit test** - was an API test; the `test-api` suite was deliberately removed from this fork, so the assertion moves to the one suite that remains. Failing unit test: a visitor built from a request whose URI carries a marker query parameter produces a visit whose `visitedUrl` still contains it, so nothing on the recording path strips or rewrites the query string, tagged `spec:click-tracking:AC-11` (AC-11)
- [x] T016 [P] **Rescoped 2026-08-30 to a unit test** - was an API test, for the same reason as T015. Failing unit test: a visit with no resolvable location serializes `visitLocation` as absent and is still a complete visit - the degradation path, not an error, tagged `spec:click-tracking:AC-11` (AC-11)

**T017, T019 and T020 are withdrawn**, not skipped: each needed persisted rows, and this fork
removed the `test-db`, `test-api` and `test-cli` suites. None is rescopable to a unit suite, so
T017 and T019 are removed from the list above rather than rewritten - see the `Permanent gaps`
note in product-specs `specs/002-click-tracking/tasks.md`. **AC-4 remains covered** by T010 here
and T039 in `core`.

### Implementation (green)

- [x] T021 Add the device classifier in `module/Core/src/Visit/`: a pure function of the user-agent string returning one of `mobile`, `tablet`, `desktop`, `other` plus a detail, with `other` as the fallback and `desktop` only on a positive match. No entity, no container dependency, no database (AC-14)
- [x] T022 Add two nullable columns for the class and detail via a new append-only migration in `data/migrations/`, map them on `Visit`, and assign them in `hydrateFromVisitor()` from the same user agent the bot verdict uses (AC-14, AC-15)
- [x] T023 Add `id`, `visitedUrl`, `deviceType` and `deviceTypeDetail` to `Visit::jsonSerialize()`, additively only - it feeds the REST responses and the Mercure, Redis and webhook notifiers as well (AC-10, AC-11, AC-15)
- [x] T024 Add `salesmessage/streaming` with its `repositories` VCS entry to `composer.json` and install `rdkafka` in `Dockerfile` and `data/infra/*.Dockerfile` the way the image already installs openswoole: `librdkafka-dev` in the temporary `.dev-deps`, `pecl install rdkafka`, `docker-php-ext-enable rdkafka`, and `librdkafka` in the runtime `apk add` (infra)
- [x] T025 Add `config/autoload/kafka.global.php` shaped like `rabbit.global.php`, with new `EnvVars` cases `KAFKA_ENABLED`, `KAFKA_BROKERS`, `KAFKA_TOPIC_PREFIX` and `KAFKA_VISITS_TOPIC` (default `shortener`), `KAFKA_ENABLED` defaulting to off. The prefix must match what `core` applies on the consumer side (infra)
- [x] T026 Wire the producer following `micro-workflows`' `config/container/infrastructure-streaming.php`: `KafkaProducerConfig` with `setOnError()` logging, `KafkaProducerOptions` for the flush policy, `KafkaProducerDriver`, and a `SimpleProducer` with the JSON encoding interceptor, registered in `dependencies.config.php` through dedicated factories (`KafkaProducerFactory`, `VisitsTopicFactory`) rather than `ConfigAbstractFactory`, because the producer falls back to a null producer when disabled and that needs logic *(wording corrected 2026-09-02)* (infra)
- [x] T027 Add the `async` `VisitLocated` listener under `events.async` in `module/Core/config/event_dispatcher.config.php`, beside `NotifyVisitToRabbitMq` and not replacing it: publish the non-legacy `{shortUrl, visit}` payload to the prefixed `shortener` topic with the `event-key` header `shortener.click_recorded`, send then `flush()` with a bounded timeout, and catch `Throwable`, log **at error level with the visit id and short code** and continue as `AbstractNotifyVisitListener::__invoke` does - the redirect must never break, but because Kafka is the only path the failure has to be greppable rather than swallowed at debug (AC-11)
- [x] T030 Update `docs/swagger/` with the new visit fields including the device-class enum, so `composer swagger:validate` passes (infra)

### Refactor and verify

- [x] T031 **Rescoped 2026-08-30; baseline re-cut 2026-08-31.** Run `./indocker_test ci` - which in this fork is `cs`, `stan`, `swagger:validate` and the unit suite; the db, api and cli suites were deliberately removed. **The unit suite, `stan` and `swagger:validate` must now pass absolutely** - the 11 unit failures this task used to net out were fixed on 2026-08-31 (the `visitsCount` regression from `SWR-21964`, plus the upstream expectations that contradicted the fork's `isCrawler()` rule), so any red is yours. `cs` is the one check still red at the repo level; it must add no error beyond the inventory in `.ai/rules/backend/coding-standards.md`. Confirm `phpcs` and `phpstan` are clean on every file this feature touched, that `isCrawler()` and the `regular` `LocateVisit` listener are unchanged, that no existing published field changed shape, and that every owned AC has a passing `@group`-tagged test. **Infection's MSI threshold is not part of done here**: it is the fork's inherited gate, not this feature's (T163 dismissal, 2026-08-31), and `AGENTS.md`'s own definition of done names no mutation gate (AC-4, AC-10, AC-11, AC-14, AC-15)

### Rollout

- [ ] T145 Turn `KAFKA_ENABLED` on per environment once T003's variables are in place, then watch produce failures here and consumer lag in `core` (infra)

## Dependencies

| This task | Blocked by | Reason |
|---|---|---|
| T022, T023 | T021 | The class has to exist before a visit can store or serialize it |
| T026, T027 | T024, T025 | No client and no config, no publisher |
| T012, T013, T014 | T024 | The listener test needs the producer interface on the autoloader |
| T145 | T003, T027 | The publisher must exist and the environment must be configured |
| nothing here | another service | Every change is additive; this service ships first and alone |

**Blocks**: `core`'s click consumer, and therefore everything downstream. `core` can be built against
the frozen contract before this ships, but nothing integrates against a real click until it does -
and because the stream is the only delivery path, no click exists downstream at all until it does.

## Done when

- [ ] Every task above is complete
- [ ] Every AC in the table above has a passing test tagged `@group spec:click-tracking:AC-N`
- [ ] `./indocker_test ci` green: the unit suite, `stan` and `swagger:validate` absolutely, `cs` no
      worse than the documented inventory, and `phpcs`/`phpstan` clean on the files touched -
      Infection's MSI is the fork's inherited gate, not this feature's (T163 dismissal)
- [ ] The change is additive and narrow, in the smallest number of files, with no reformatting of
      surrounding code - upstream merges stay cheap
- [ ] The new divergence is called out in the PR description with the JIRA key
- [ ] The PR names the RabbitMQ publishing mode this deployment runs, references the AC ids it
      implements, and records the `product-specs` commit SHA of the spec it was built against
- [ ] Rollout and rollback steps from [./plan.md](./plan.md) are followed - the columns are nullable
      and additive, so a code rollback leaves the migration in place
