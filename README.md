# Domain Availability

[![CI](https://github.com/abdelwahied/domain_availability/actions/workflows/ci.yml/badge.svg?branch=main)](https://github.com/abdelwahied/domain_availability/actions/workflows/ci.yml)
[![Latest release](https://img.shields.io/github/v/release/abdelwahied/domain_availability?sort=semver)](https://github.com/abdelwahied/domain_availability/releases/latest)
[![License](https://img.shields.io/github/license/abdelwahied/domain_availability)](LICENSE.txt)
[![Drupal](https://img.shields.io/badge/Drupal-%5E10.3%20%7C%7C%20%5E11-blue.svg)](https://www.drupal.org)
[![PHP](https://img.shields.io/badge/PHP-%E2%89%A5%208.3-blue.svg)](https://www.php.net)

> **Compatibility:** Drupal `^10.3 || ^11`, PHP `>= 8.3`. **Version:** 1.0.0.

Checks a domain name across every configured TLD in one parallel sweep, using
RDAP where the registry supports it and WHOIS everywhere else — and **never
guesses**.

An unanswerable lookup reports `unknown`, never `available`. "Available" is the
answer a visitor acts on, so a wrong one costs them money and trust.

Optionally, it collects Saudi domain registration **requests**: an applicant
fills in a form from an available `.sa` result, and an administrator reviews it.
The module records intent — it does not register domains, take payment or
contact a registrar.

## Documentation

| | |
| --- | --- |
| [API.md](API.md) | Public API, architecture, and the compatibility policy |
| [CHANGELOG.md](CHANGELOG.md) | What changed, and when |
| [UPGRADING.md](UPGRADING.md) | Version and compatibility policy, and upgrade steps |
| [CONTRIBUTING.md](CONTRIBUTING.md) | How to work on it, and how to extend it |
| [RELEASING.md](RELEASING.md) | The release checklist for maintainers |
| [SECURITY.md](SECURITY.md) | Reporting a vulnerability, and what is in scope |

## Requirements

- Drupal 10.3+ or 11.x
- PHP 8.3+
- The `json`, `mbstring` and `sockets` extensions
- [`saudi_id_validator`](../saudi_id_validator) — installed automatically

Outbound TCP port 43 must be open for WHOIS-only TLDs. A blocked port is
invisible from outside and is the usual reason a TLD such as `.sa` reports
`unknown` forever, so the module checks it for you — see
[Troubleshooting](#troubleshooting).

## Installation

```bash
composer require abdelwahied/domain_availability
drush en domain_availability
```

`saudi_id_validator` is a declared dependency. Drupal installs it
automatically; there is no manual step.

## Dependencies

This module depends on `saudi_id_validator`, and that dependency is mandatory.

**No Saudi identification-number validation logic exists in this module** — not a
regex, not a checksum. Every such rule comes through the validator's public API,
so a form field and an API write can never disagree about what a valid number
is. See [API.md](API.md#dependency-on-saudi_id_validator) for why this is an
architectural guarantee rather than a convention.

### Why there is no Composer dependency

The dependency is declared in `domain_availability.info.yml` only:

```yaml
dependencies:
  - saudi_id_validator:saudi_id_validator
```

`composer.json` deliberately does **not** require
`abdelwahied/saudi_id_validator`. That is not an oversight.

`saudi_id_validator` is not yet published to an official Composer repository —
neither Packagist nor Drupal.org's. Requiring a package Composer cannot resolve
would make `composer install` fail outright. The alternatives are worse: a
`repositories` entry pointing at a VCS or a local path would hard-code one
machine's layout into a package meant to be reusable, and every consumer would
inherit it.

While both modules live in the same codebase, the Drupal dependency is
sufficient on its own. Drupal resolves it at install time and refuses to enable
this module without the validator, which is the guarantee that actually matters.

**When to change this.** Once `saudi_id_validator` is published to Packagist or
Drupal.org, add it to `require` in the normal way and delete this section:

```json
"require": {
    "abdelwahied/saudi_id_validator": "^1.0"
}
```

No `repositories` entry, no VCS reference and no path repository should ever be
added to get there.

## Configuration

**Administration → Configuration → System → Domain Availability**

TLDs to sweep, which providers are enabled, cache TTL, timeouts, rate limiting,
logging and CORS origins. Twenty TLDs ship enabled, starting with `sa`, `com`,
`net`, `org`, `io`.

**→ Registration settings**

Whether the request feature is on, which TLDs accept requests (`sa` by default;
empty means all), upload size and extensions, administrator notification
addresses, and the duplicate-request window.

Six permissions ship, five of them restricted:

| Permission | For |
| --- | --- |
| `access domain availability search` | Use the search page |
| `use domain availability api` | Call the JSON endpoint |
| `administer domain availability` | Configure the module |
| `view domain registration requests` | See requests and their personal data |
| `manage domain registration requests` | Change status and notes |
| `delete domain registration requests` | Delete requests |

One lookup fans out to every configured TLD, so grant the API permission only to
trusted roles.

## Usage

**Search page** — `/domain-search`.

**Block** — place *Domain availability search* from Block Layout.

**Twig** — `{{ domain_availability_search() }}`.

**Render element:**

```php
$build['search'] = ['#type' => 'domain_availability_search'];
```

**JSON API** — `GET /domain-check?domain=neixora`:

```json
{
  "success": true,
  "query": "neixora",
  "took_ms": 412,
  "cached": false,
  "results": [
    {
      "domain": "neixora.sa",
      "extension": ".sa",
      "available": true,
      "status": "available",
      "provider": "whois"
    }
  ]
}
```

`available` is `null` when `status` is `unknown`.

**From PHP:**

```php
$report = \Drupal::service('domain_availability.checker')->check('neixora');
```

Inject `@domain_availability.checker` rather than calling statically. Full
signatures in [API.md](API.md#service-domaincheckservice).

## Extension

The main extension point is a service tag: a new lookup protocol, registry or
registrar is one class implementing `DomainProviderInterface` plus one service
tagged `domain_availability_provider`. No existing class changes.

```yaml
services:
  my_module.provider.my_registry:
    class: Drupal\my_module\Provider\MyRegistryProvider
    tags:
      - { name: domain_availability_provider }
```

Providers are selected by their own `priority()`, **lowest first** — the tag's
priority does not decide. A worked example is in
[CONTRIBUTING.md](CONTRIBUTING.md#how-to-add-a-tld-provider); the full list of
extension points is in
[CONTRIBUTING.md](CONTRIBUTING.md#how-to-extend-business-logic).

The module dispatches no custom events in 1.0.0.

## Architecture

```
Search page · Block · Twig · JSON endpoint
        │
        ▼
DomainCheckService ──► DomainCache (swappable)
        │
        ▼
ProviderRegistry — services tagged domain_availability_provider
        │
   saudinic(5) → rdap(10) → whois(20) → dns(30)     lowest priority wins
        │
        ▼
CheckReport { query, results[], tookMs, cached }
```

Registration requests are a separate, optional layer: a content entity, a modal
form, an admin workflow and two emails. Identification numbers on that entity are
validated by `saudi_id_validator` through a field constraint, so the rule holds
for entity forms, JSON:API, REST and migrations alike.

Diagrams for the WHOIS flow, the constraint flow and the registration workflow
are in [API.md](API.md#architecture).

## Testing

```bash
vendor/bin/phpunit -c web/core/phpunit.xml.dist \
  web/modules/contrib/domain_availability/tests
```

Tests use a stub provider, so results are deterministic and no registry is
contacted.

## Troubleshooting

**Everything reports `unknown`.** Usually outbound port 43 is blocked. Check
**Reports → Status report** for the *WHOIS egress* entry, or
`GET /domain-check/health`, which reports reachability, resolved address and
latency per probed TLD.

**One TLD is always `unknown`.** Some TLDs publish no WHOIS host at all and are
RDAP-only. If RDAP is disabled, they cannot be answered.

**Rate limited immediately.** A minimum interval of one second between requests
ships enabled. Adjust it on the settings page.

## Versioning

Semantic versioning. The public API — everything marked `@api`, the provider tag,
service ids, route names, permissions, configuration keys, the entity type and
the JSON response shape — will not change incompatibly before 2.0.0. Classes
marked `@internal` may change in any release. The full contract, including the
deprecation policy, is in
[API.md](API.md#backward-compatibility-policy).

## Licence

GPL-2.0-or-later. See [LICENSE.txt](LICENSE.txt).
