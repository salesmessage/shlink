---
contract: click-recorded
canonical: product-specs specs/002-click-tracking/contracts/click-recorded.md
canonical_version: 2
service: shlink
role: producer
feature: click-tracking
last_updated: 2026-08-28
---

# Contract participation: shlink in `click-recorded`

**Canonical contract**: product-specs `specs/002-click-tracking/contracts/click-recorded.md`, version `2`

> **Canonical v2, 2026-08-30 - no work here.** The bump corrects what `utm_sm_mid` contains: a plain
> decimal message id, decoded once by `micro-shortener-proxy`, not a hash. This service records the
> URL it receives and never interprets the marker, so nothing this repo produces changes. The
> `canonical_version` is updated because both sides of a boundary are revisited when it moves.
**This service's plan**: [../plan.md](../plan.md) | **Tasks**: [../tasks.md](../tasks.md)

> **Not redefined here.** The payload shape, the guarantees and the compatibility rules are the
> canonical contract's. This file says how `shlink` takes part in it. Where the two appear to
> disagree, the canonical contract wins and the disagreement is a defect in this file.

## Role

**Producer.** This service is the click-capture point of record (`click-tracking#ADR-001`): it records
every redirect it serves, enriches the visit with the country it already resolves and with a device
class it now produces, and publishes the located visit onto the platform's Kafka event bus. It also
serves the account-wide visit listing that is the contract's stated recovery path.

It consumes nothing at this boundary and calls no other service. It does not know what a contact, a
source, an inbox or an organization is, and this feature does not teach it - attribution is entirely
the consumer's work.

## What this service implements or calls

| Element | Kind | This service | Canonical reference |
|---|---|---|---|
| `shortener.click_recorded` on topic `shortener` | event | emits | Ownership & delivery, Transport |
| `{shortUrl, visit}` envelope | message body | emits | Schema, Response / Payload |
| `visit.id`, `visit.visitedUrl`, `visit.deviceType`, `visit.deviceTypeDetail` | payload elements | adds (the four marked **New**) | Schema; Versioning, third bullet |
| `visit.potentialBot`, `visit.date`, `visit.userAgent`, `visit.visitLocation` | payload elements | already emits, unchanged | Schema |
| Account-wide visit listing over a date range, paginated | endpoint | implements | Guarantees, second bullet |

## Mapping to this repo

| Contract element | Where it lands in this repo | New or existing |
|---|---|---|
| `{shortUrl, visit}` envelope | `module/Core/src/EventDispatcher/PublishingUpdatesGenerator.php` | existing |
| The serialized visit | `module/Core/src/Visit/Entity/Visit.php`, `jsonSerialize()` | existing, gains the four new fields |
| `visit.deviceType` / `visit.deviceTypeDetail` | a self-contained classifier under `module/Core/src/Visit/`, stored as two nullable columns on `visits` via a new migration in `data/migrations/` | added by this feature |
| `visit.visitedUrl` | already set by `Visitor::fromRequest()` to the full request URI, which is why the per-message marker survives; only the serialization is new | existing value, newly published |
| `visit.potentialBot` | `Visitor::__construct`, via `Shlinkio\Shlink\Core\isCrawler` | existing, **must not change** |
| `visit.visitLocation.countryCode` / `countryName` | `module/Core/src/EventDispatcher/LocateVisit.php` (a **`regular`** listener) writing `module/Core/src/Visit/Entity/VisitLocation.php` | existing, **must not move** |
| Persist-before-publish | `module/Core/src/Visit/VisitsTracker.php` persists and flushes, then dispatches `UrlVisited` | existing |
| Transport: topic, `event-key` header, publish and flush | a new **`async`** listener on `VisitLocated` in `module/Core/config/event_dispatcher.config.php`, beside `NotifyVisitToRabbitMq` and not replacing it | added by this feature |
| Kafka client and producer wiring | `ext-rdkafka` plus the in-house `salesmessage/streaming`, wired by merged config following `micro-workflows`' `config/container/infrastructure-streaming.php` | added by this feature |
| Topic name, prefix, brokers, enable flag | `config/autoload/kafka.global.php`, env vars declared in `Shlinkio\Shlink\Core\Config\EnvVars` | added by this feature |

## Obligations on this side

**Must uphold**

- **The visit is persisted before it is published.** `VisitsTracker` flushes before dispatching, and
  the publisher is an `async` listener on the already-located visit, so an event never references a
  visit that does not exist (Guarantees, first bullet).
- **Every located visit is published exactly once, and a publish that fails is logged at error level
  with the visit id.** The stream is the only delivery path: there is no recovery listing and no
  reconciliation, so a swallowed failure is a click that never becomes a figure. Best effort towards
  the redirect, loud towards the operator (Guarantees, second bullet).
- **`visit.id` is stable and never reused**, so the consumer can deduplicate under at-least-once
  redelivery.
- **`visit.deviceType` comes from exactly one classifier, and no unrecognized user agent is published
  as `desktop`.** Any consumer that displays a device class displays this value (AC-14, AC-15,
  `click-tracking#ADR-003`).
- **A null or empty `visitLocation` is a valid outcome, never an error**, and geolocation never delays
  or drops a redirect (Guarantees, fifth bullet; BR-46).
- **Publishing is best effort and is not retried**, and the contract states that openly. A produce
  failure is caught, logged and swallowed: the visit, the other four notifiers and the redirect are
  all unaffected. What compensates for the loss is the listing guarantee above, not a retry here.
- **No field this service emits identifies a contact, a source or an organization**
  (`click-tracking#ADR-004`).

**May rely on**

Nothing. This service consumes no contract in this feature.

## Contract tests

Tag form is the **docblock annotation**, not an attribute: PHPUnit here is 9.6. Commands and the
definition of done are in this plan's own rules table and in `CONTRIBUTING.md`.

| What it proves | Test | Tag |
|---|---|---|
| The published envelope carries every element the consumer depends on, with the marker query string intact | Unit test on `PublishingUpdatesGenerator` | `@group spec:click-tracking:AC-11` |
| One message per located visit, on the configured topic, with `event-key` `shortener.click_recorded`, and the producer flushed | Unit test on the Kafka publisher listener | `@group spec:click-tracking:AC-11` |
| The serialized device class is the classifier's output for that exact user agent | Unit test on `Visit::jsonSerialize()` | `@group spec:click-tracking:AC-15` |
| No unrecognized user agent classifies as `desktop` | Table-driven unit test on the classifier | `@group spec:click-tracking:AC-14` |
| `visit.date` serializes as UTC regardless of application timezone | Unit test | `@group spec:click-tracking:AC-10` |
| A crawler visit serializes `potentialBot: true`, so the consumer can exclude it | Unit test | `@group spec:click-tracking:AC-4` |
| A throwing producer is caught and logged at error level with the visit id, leaving the visit and the other notifiers untouched | Unit test | untagged |
| With the publisher disabled by config, nothing is produced and no notifier changes behavior | Unit test | untagged |

**Harness**: **no PACT, and none is required for this feature.** Decided for click-tracking on
2026-08-28: this boundary is verified by the unit tests above against the canonical payload,
not by a broker-mediated pact. `shlink` is a Laminas/Mezzio application with no Pact tooling, and
`salesmessage/pact-broker-support` installs through `php artisan`, so it cannot be added here without
work this feature does not need. The consumer side records the same decision, so there is no one-sided
pact: neither half is written. Coverage of the boundary rests on this table plus the consumer's own
contract-shape tests.

## Implementation notes

- **This is a maintained fork of `shlinkio/shlink`.** Keep every change additive and narrow, across
  the smallest number of files, so upstream merges stay cheap. The Kafka client and the publisher are
  the largest divergence this feature adds, and they are called out in the PR description with the
  JIRA key.
- **The four new fields are additive to a payload this service already builds** for Mercure, Redis,
  RabbitMQ and webhooks. Those subscribers ignore unknown fields, which is why the payload stays
  compatible with what ships today: only the transport is new. Do not reorder or reshape any existing
  field. *(The canonical contract moved to v2 on 2026-08-30; that bump corrected the description of
  the marker inside `visitedUrl` and changed nothing this service emits.)*
- **The publisher ships disabled by default.** Its Kafka environment variables have to be set per
  environment before it is switched on. Until then **nothing** reaches the consumer - the stream is
  the only path - and clicks captured while it is off are not recoverable afterwards. This is a
  deployment prerequisite, not an open question.
- Everything here is wired by **merged config, not auto-discovery**. If a config change appears to do
  nothing, delete `data/cache/app_config.php`.
- Do not touch `isCrawler()` and do not move `LocateVisit` out of its `regular` listener slot. AC-4's
  requirement at this boundary is that the verdict is *published*, not that it is improved.
- `docs/swagger/` describes the visit shape and must be updated for the new fields, including the
  device-class enum, or `composer swagger:validate` fails the definition of done.
- Mutation testing will attack the classifier's branches hardest - one case per branch and one per
  boundary, or the MSI gate fails.

## Compatibility duties

- Do not remove, rename or retype any element listed above without a canonical version bump. Adding a
  field the consumer did not ask for is backward-compatible and needs no bump.
- **Adding a fifth value to the device-class enum is breaking**, even though it is additive in shape,
  because the consumer switches on it and the spec fixes the set at four (BR-32). A new device class
  is a spec change first (Principle IX), never a producer decision.
- Making the publish conditional, sampled, or silently swallowed is breaking even though no message
  shape changes: there is no second path, so a dropped publish is a lost click.
- On a canonical bump: publish the new version alongside the old, keep the old live until `core` has
  migrated, then mark it `deprecated`. This file's `canonical_version` is updated in that same change.

## Related

- Canonical contract: product-specs `specs/002-click-tracking/contracts/click-recorded.md`
- Spec and the ACs this boundary serves: product-specs `specs/002-click-tracking/spec.md` - AC-4,
  AC-10, AC-11, AC-14, AC-15
- Common plan: product-specs `specs/002-click-tracking/plan.md`
- ADRs: `click-tracking#ADR-001`, `click-tracking#ADR-002`, `click-tracking#ADR-003`
- Consumer's side of this boundary: `core` `specs/002-click-tracking/contracts/click-recorded.md`
