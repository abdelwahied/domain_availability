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

## [1.1.0] — 2026-07-26

### Added — pricing

- A pricing subsystem under `src/Pricing/`. Two modes ship: **Same price for all
  domains** (`pricing.mode: fixed`) and **Different price per extension**
  (`pricing.mode: extension`).
- `PricingStrategyInterface` and the `domain_availability_pricing_strategy`
  service tag, so a new pricing model — provider, premium, promotional,
  currency-aware — is one class plus one tagged service and no existing class
  changes. A strategy's `id()` is its `pricing.mode` value.
- `ConfigurablePricingStrategyInterface` for strategies that bring their own
  settings fields, validation and stored shape. `SettingsForm` builds its
  pricing section entirely from the registered strategies, so it is not edited
  to add a mode.
- `domain_availability.pricing_manager`, the only supported way to ask for a
  price: `getPrice('.com')`. Failure is always `NULL` — an unset mode, a mode
  left behind by an uninstalled module, an unpriced extension, or a third-party
  strategy that throws.
- `domain_availability.pricing_settings`, typed read-only access to the
  `pricing` mapping. Part of the extension contract: it is how a strategy reads
  its own configuration keys, and `PricingStrategyBase` takes it as its first
  constructor argument.
- `PriceValue`, an immutable amount with a currency, and `PricingContext`, the
  parameter object a strategy prices on.
- A per-extension price table on the settings form, generated from the enabled
  TLD list. Enable a TLD and its row appears; nothing is hardcoded.
- `DomainResult::$price` and `DomainResult::withPrice()`. Providers never set a
  price: `PricingManager` attaches one after the lookup cache, so changing a
  price takes effect on the next search rather than when the cache expires.
- `CheckReport::withResults()`, for decorating results without making the
  report mutable.
- A `price` object on each JSON result, present only when the site prices that
  extension. Additive: every key a 1.0.0 client read is unchanged, and a site
  with no pricing configured returns exactly the 1.0.0 keys.
- The results template renders `result.price.formatted` on available results,
  behind a visually hidden "Price" label.
- `domain_availability_update_10004()` adds the `pricing` configuration to
  existing sites, defaulting to a single fixed price of 35.00. Anything already
  stored under `pricing` is left untouched.

### Pricing semantics

- **An unpriced extension is blank, and blank is the only way to say it.** A
  zero is refused by the settings form and discarded by `PricingManager`,
  whatever strategy produced it. Drupal's typed configuration casts on save, so
  a malformed value imported into `extension_prices` is stored as `0.0` before
  any code can object; discarding zero is what stops one bad config import from
  advertising every domain on the site as free. Selling at zero is not a
  supported configuration in this release.
- Switching pricing mode never discards the other mode's settings. The
  fixed-price field is hidden — and therefore unvalidated — while per-extension
  pricing is selected, and an empty submission keeps whatever is stored rather
  than normalising it.

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
