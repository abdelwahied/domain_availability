# Changelog

All notable changes to this module are documented here.

The format follows [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and the module follows [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

What counts as a breaking change is defined in
[API.md](API.md#backward-compatibility-policy). In short: anything marked `@api`,
the `domain_availability_provider` service tag, the JSON endpoint's response
shape, the route names, the permissions, the configuration keys and the entity
type id. Classes marked `@internal` may change in any release.

## [Unreleased]

Nothing yet.

## [1.0.1] — 2026-07-23

### Fixed

- Serialization safety: injected services on the search form, settings form and
  registration list builder are now `protected` and no longer `readonly`,
  matching the `DependencySerializationTrait` contract on PHP 8.3.
- Replaced the deprecated `RendererInterface::renderPlain()` with
  `renderInIsolation()`.
- Added the Drupal 11.3 cacheability parameter to the registration list
  builder's `getDefaultOperations()` override; still compatible with Drupal 10.3.
- Removed provably-redundant assertions and null-coalesces flagged at PHPStan
  level 4.

No functional or public API changes.

## [1.0.0] — 2026-07-22

First release.

### Added — lookups

- Parallel availability lookups across every configured TLD in one sweep.
- Four providers, tried per TLD in ascending `priority()` — lowest first: an
  authoritative HTTP API (`5`, off by default), RDAP (`10`), WHOIS (`20`) and a
  DNS fallback (`30`).
- `DomainProviderInterface` and the `domain_availability_provider` service tag,
  so a new protocol is a new tagged service and no existing class changes.
- RDAP server discovery from IANA's bootstrap file, cached, with a configurable
  fallback map.
- WHOIS server discovery through `whois.iana.org` for TLDs not in the shipped
  map, cached.
- A three-state result — `available`, `registered`, `unknown` — where `unknown`
  means no provider could answer. Availability is never guessed.
- Response caching, keyed per query, with a configurable TTL.
- Rate limiting per client, by request count in a window and by minimum interval
  between requests.

### Added — interfaces

- Search page at `/domain-search`.
- JSON endpoint at `/domain-check`, and a health endpoint at
  `/domain-check/health` reporting provider registration, JSON and socket
  availability, and live WHOIS egress.
- CORS headers on the endpoint, with a configurable origin allow-list.
- A `domain_availability_search` block, a render element of the same name, and a
  `domain_availability_search()` Twig function.
- A status-report entry showing whether outbound TCP port 43 is reachable —
  the usual reason a WHOIS-only TLD reports `unknown` forever.
- The authoritative provider's API key is write-only in the settings form: it is
  stored but never rendered back, so it cannot be read from the page source.

### Added — registration requests

- A `domain_registration_request` content entity, its admin listing, detail page,
  status workflow (pending, approved, rejected, cancelled) and delete form.
- A modal request form opened from an available result whose TLD the feature
  accepts.
- Applicant type: an individual supplies a mobile number; a company must also
  supply its Arabic and English names, national address, commercial registration
  number, national ID and a PDF certificate.
- A registration period of 1–10 years.
- Certificates upload to the private file system when the site has one.
- Confirmation and administrator notification emails through Drupal's Mail API.
- A duplicate window that refuses a second request for the same domain.
- Six permissions separating search, API use, administration, and viewing,
  managing and deleting requests.

### Added — Saudi rules

- Saudi mobile, commercial registration and national address validation.
- For a `.sa` domain requested by an individual, the applicant must be a Saudi
  citizen: an Iqama holder applies as a company.

### Dependencies

- Requires `saudi_id_validator`. All identification-number validation — format,
  type and checksum — comes through that module's public API. **No Saudi ID
  validation logic exists in this module**, by design; see
  [API.md](API.md#dependency-on-saudi_id_validator).

### Notes

- The module records intent. It does not register domains, take payment or
  contact a registrar.
