# Upgrading

## Introduction

This document explains how to move between versions of the Domain Availability
module and what compatibility you can rely on. It complements
[CHANGELOG.md](CHANGELOG.md), which records what changed in each release;
this file records what you have to *do* about it.

## Version policy

The module follows [Semantic Versioning](https://semver.org/):

- **Patch** releases (`1.0.x`) fix bugs and never change behavior you could
  depend on.
- **Minor** releases (`1.x.0`) add functionality in a backward-compatible way.
  Existing code, configuration and the public API keep working.
- **Major** releases (`2.0.0`, …) may change or remove public API. Every
  breaking change is documented in this file, with a migration path.

The public API surface that this policy protects is the one marked `@api` and
described in [API.md](API.md): the `domain_availability.checker` service and
the provider extension point (`DomainProviderInterface` and the
`domain_availability_provider` tag), the result DTOs, the JSON endpoints, and
the `domain_availability.settings` configuration object. The service
*parameters* in `domain_availability.services.yml` (WHOIS hosts, RDAP endpoints,
response patterns) are documented override points and are treated as API too.

## Upgrade process

For a patch or minor release within the same major version:

1. Update the code (`composer update abdelwahied/domain_availability`, or replace
   the module directory).
2. Run database updates: `drush updatedb`.
3. Rebuild caches: `drush cache:rebuild`.

No manual steps are ever required for a patch or minor release.

## Version 1.1.1 — bounded DNS

**Upgrade steps: run `drush updatedb`.** No configuration or API changes.

`domain_availability_update_10005()` adds `dns_query_timeout_ms` (1500) and
raises `whois_dns_ttl` from 300 to 86400 — but only where it is still the old
shipped default. A site that chose its own value keeps it.

DNS lookups are now bounded by `max_lookup_time` like every other provider. A
resolver that stops answering degrades the affected TLDs to `unknown` instead of
running the request into PHP's `max_execution_time`.

If no nameserver can be discovered from the resolver configuration, the DNS
delegation fallback switches itself off and WHOIS connects by hostname; the
status report and the status page both say so.

## Version 1.1.0 — pricing

**Upgrade steps: run `drush updatedb`.** Nothing else is required, and nothing
existing changes behaviour.

`domain_availability_update_10004()` adds a `pricing` mapping to
`domain_availability.settings`, defaulting to the same values a fresh install
gets:

```yaml
pricing:
  mode: fixed
  fixed_price: 35.00
  extension_prices: {}
```

If your site already has a `pricing` key — because you imported configuration
that included one — the update leaves it exactly as it is.

**After updating, review the price.** The default of `35.00` is a placeholder,
and after the update every available result is shown carrying it. Set your own
at **Configuration → System → Domain Availability → Pricing**, or select
per-extension pricing and fill in the generated table.

### What this changes for existing consumers

| Surface | Change |
| --- | --- |
| `GET /domain-check` | Each result gains a `price` object. Every key a 1.0.0 client read is unchanged, in the same order. A site with no `pricing` configured returns exactly the 1.0.0 keys. |
| `DomainResult` | Gains a `$price` property, defaulting to `NULL`, as a sixth constructor parameter. Existing constructor calls and named constructors are unaffected. |
| `DomainProviderInterface` | **Unchanged.** Providers do not price; pricing is applied after the lookup. A contributed provider needs no edit. |
| `domain_availability.checker` | Unchanged signature. It now takes a `PricingManager` in its constructor — relevant only if you were instantiating it directly rather than pulling the service. |
| Result cache | Unchanged, and prices are deliberately **not** stored in it. Existing cache entries stay valid across the update. |
| Templates | `domain-availability-results.html.twig` renders a price when one is present. A theme override written for 1.0.0 keeps working, and simply shows no price until it opts in by rendering `result.price.formatted`. |

To turn pricing off entirely, clear the mode:

```bash
drush config:set domain_availability.settings pricing.mode ''
```

Results then render exactly as they did in 1.0.0, and the `price` key
disappears from the JSON payload.

## Version 1.0.0

This is the first stable release. **No upgrade steps are required** — there is
no earlier version to come from.

The optional Saudi registration-request workflow (the
`domain_registration_request` entity, its admin listing and email) is installed
with the module and needs no separate migration.

## Compatibility policy

- **Drupal**: `^10.3 || ^11`. A minor release will not raise the minimum below
  what a supported Drupal core still receives security coverage for.
- **PHP**: `>= 8.3`, with the `json`, `mbstring` and `sockets` extensions.
- The bundled `saudi_id_validator` dependency follows its own version policy;
  Domain Availability will always require a compatible published range.
- Dropping support for a Drupal or PHP version is a breaking change and will
  only happen in a major release, announced here.

Future major versions will document their breaking changes and migration steps
in this file.
