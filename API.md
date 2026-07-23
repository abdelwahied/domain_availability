# Public API reference

Everything on this page is covered by semantic versioning: it will not change
incompatibly before 2.0.0. Anything not listed — in particular any class whose
docblock says `@internal` — may change in any release.

**Version 1.0.0 establishes this initial public API contract.** From 1.0.0
onward, no `@api` class, interface, service ID, provider tag, route, event name
or documented configuration key (including the documented service parameters) is
removed or changed incompatibly except in a new major version, as described in
[UPGRADING.md](UPGRADING.md).

Every example here was run against the implementation before it was written.

- [Stability contract](#stability-contract)
- [Service: DomainCheckService](#service-domaincheckservice)
- [Extension point: DomainProviderInterface](#extension-point-domainproviderinterface)
- [Result objects](#result-objects)
- [Exceptions](#exceptions)
- [Cache: DomainCacheInterface](#cache-domaincacheinterface)
- [Configuration API](#configuration-api)
- [Entity API](#entity-api)
- [Render, block and Twig APIs](#render-block-and-twig-apis)
- [Routes and permissions](#routes-and-permissions)
- [JSON endpoint](#json-endpoint)
- [Events](#events)
- [Dependency on saudi_id_validator](#dependency-on-saudi_id_validator)
- [Architecture](#architecture)
- [Backward compatibility policy](#backward-compatibility-policy)
- [Internal classes](#internal-classes)

## Stability contract

| Surface | Identifier |
| --- | --- |
| Lookup service | `domain_availability.checker` |
| Provider tag | `domain_availability_provider` |
| Provider contract | `Drupal\domain_availability\Provider\DomainProviderInterface` |
| Cache contract | `Drupal\domain_availability\Cache\DomainCacheInterface` |
| Settings services | `domain_availability.settings`, `domain_availability.registration_settings` |
| Entity type | `domain_registration_request` |
| Entity contract | `Drupal\domain_availability\Entity\DomainRegistrationRequestInterface` |
| Render element | `domain_availability_search` |
| Block plugin | `domain_availability_search` |
| Twig function | `domain_availability_search()` |
| Configuration | `domain_availability.settings`, `domain_availability.registration` |
| Routes | the ten route names below |
| Permissions | the six permission strings below |
| JSON endpoint | `/domain-check` and `/domain-check/health` response shapes |

Plus every class marked `@api`.

## Service: DomainCheckService

The module's entry point. One label in, one report out.

```php
namespace Drupal\my_module;

use Drupal\domain_availability\Service\DomainCheckService;

final class MyService {

  public function __construct(
    private readonly DomainCheckService $checker,
  ) {}

  public function look(string $label): void {
    $report = $this->checker->check($label);
  }

}
```

```yaml
# my_module.services.yml
services:
  Drupal\my_module\MyService:
    arguments:
      - '@domain_availability.checker'
```

### `check(string $label): CheckReport`

Takes the **name only**, without an extension — a full URL is reduced to its
name automatically. It sweeps every configured TLD in parallel and returns a
report.

Throws `ValidationException` when the label is unusable.

## Extension point: DomainProviderInterface

The module's main extension point. A new lookup protocol, registry or registrar
is one class plus one tagged service; no existing class changes.

```php
interface DomainProviderInterface {
  public function name(): string;
  public function priority(): int;
  public function supports(string $tld): bool;
  public function lookup(array $domains): array;
}
```

| Method | Contract |
| --- | --- |
| `name()` | Stable identifier reported in results and logs. Must be unique. |
| `priority()` | Selection order, **lowest wins**. |
| `supports($tld)` | Whether it answers for a dot-less TLD. **No network I/O** — this is the hot path. |
| `lookup($domains)` | A batch, resolved concurrently. Returns `DomainResult` keyed by domain. **Must never throw for one domain.** |

Register it:

```yaml
services:
  my_module.provider.my_registry:
    class: Drupal\my_module\Provider\MyRegistryProvider
    tags:
      - { name: domain_availability_provider }
```

**The tag's `priority` does not decide selection.** The registry collects tagged
services and re-sorts them by `priority()`, ascending. The shipped providers:

| Provider | `name()` | `priority()` | Notes |
| --- | --- | --- | --- |
| `SaudiNicProvider` | `saudinic` | 5 | Authoritative HTTP API, off by default |
| `RdapProvider` | `rdap` | 10 | |
| `WhoisProvider` | `whois` | 20 | |
| `DnsProvider` | `dns` | 30 | Fallback |

A full worked example is in [CONTRIBUTING.md](CONTRIBUTING.md#how-to-add-a-tld-provider).

Two providers reporting the same `name()` raise `ConfigurationException` at
container build time.

## Result objects

### `CheckReport`

```php
$report->query;    // string — the label that was searched
$report->results;  // array<int, DomainResult>
$report->tookMs;   // int
$report->cached;   // bool — served from cache
$report->toArray();
```

`toArray()` is exactly what the JSON endpoint returns:

```php
[
  'success' => TRUE,
  'query' => 'neixora',
  'took_ms' => 412,
  'cached' => FALSE,
  'results' => [ /* each DomainResult::toArray() */ ],
]
```

### `DomainResult`

```php
$result->domain;     // 'neixora.sa'
$result->extension;  // '.sa'
$result->status;     // DomainStatus
$result->provider;   // 'whois' | NULL
$result->reason;     // string | NULL — why it is unknown
$result->isConclusive();
```

Built through named constructors, never `new`:

```php
DomainResult::available($domain, $extension, $providerName);
DomainResult::registered($domain, $extension, $providerName);
DomainResult::unknown($domain, $extension, $providerName, $reason);
```

`toArray()` omits `reason` when there is none:

```php
['domain' => 'neixora.sa', 'extension' => '.sa', 'available' => TRUE, 'status' => 'available', 'provider' => 'whois']
```

### `DomainStatus`

```php
DomainStatus::Available;    // 'available'
DomainStatus::Registered;   // 'registered'
DomainStatus::Unknown;      // 'unknown'

$status->toAvailability();  // TRUE | FALSE | NULL
$status->isConclusive();    // FALSE only for Unknown
```

`Unknown` means no provider could answer — a throttled registry, an unreachable
host, an unparseable reply. **It is never a hint that the domain might be free.**
`toAvailability()` returns `NULL` for it precisely so a caller cannot treat it
as a boolean by accident.

## Exceptions

All extend `DomainAvailabilityException`, so one `catch` covers the module:

| Exception | Thrown when |
| --- | --- |
| `ValidationException` | A submitted label is not usable |
| `ProviderException` | A provider cannot answer |
| `RateLimitException` | A caller exceeds the rate limit |
| `ConfigurationException` | Configuration is unusable, including duplicate provider names |

```php
use Drupal\domain_availability\Exception\DomainAvailabilityException;

try {
  $report = $this->checker->check($label);
}
catch (DomainAvailabilityException $e) {
  // Anything this module throws.
}
```

## Cache: DomainCacheInterface

```php
public function get(string $key, mixed $default = NULL): mixed;
public function set(string $key, mixed $value, int $ttl, array $tags = []): bool;
public function has(string $key): bool;
public function delete(string $key): bool;
public function invalidateAll(): void;
```

Swap the implementation by binding your own class to
`domain_availability.cache`:

```yaml
services:
  domain_availability.cache:
    class: Drupal\my_module\Cache\MyDomainCache
    arguments: ['@my_module.backend']
```

## Configuration API

Read configuration through the typed services, not through the config factory —
a renamed key then changes in one place.

### `domain_availability.settings` → `ModuleSettings`

```php
$settings->tlds();                    // array<int, string>
$settings->cacheEnabled();            // bool
$settings->cacheTtl();                // int, seconds
$settings->rdapEnabled();
$settings->whoisEnabled();
$settings->dnsFallbackEnabled();
$settings->maxLookupTime();           // int, seconds
$settings->parallelRequests();        // int
$settings->rdapTimeoutMs();
$settings->rdapConnectTimeoutMs();
$settings->whoisTimeoutMs();
$settings->whoisConnectTimeoutMs();
$settings->whoisAddressFamily();      // 'ipv4' | 'ipv6' | 'any'
$settings->whoisDnsTtl();
$settings->rateLimitEnabled();
$settings->rateLimitMaxRequests();
$settings->rateLimitWindow();
$settings->rateLimitMinInterval();
$settings->loggingEnabled();
$settings->logLevel();
$settings->debug();
$settings->corsAllowedOrigins();      // array<int, string>
$settings->saudinicEnabled();
$settings->saudinicEndpoint();
$settings->saudinicApiKey();
$settings->saudinicTlds();
$settings->healthProbeTlds();
```

The configuration object is `domain_availability.settings`, edited at
`/admin/config/system/domain-availability`.

### `domain_availability.registration_settings` → `RegistrationSettings`

```php
$registration->isEnabled();               // bool
$registration->allowedTlds();             // array<int, string>, dot-less
$registration->allowsDomain('neixora.sa');// bool — enabled AND TLD allowed
$registration->maxUploadBytes();
$registration->maxUploadMegabytes();
$registration->allowedExtensions();       // e.g. 'pdf'
$registration->adminEmails();             // array<int, string>, validated
$registration->duplicateWindowSeconds();  // 0 disables duplicate detection
```

The configuration object is `domain_availability.registration`, edited at
`/admin/config/system/domain-availability/registration-settings`. Its keys:
`enabled`, `allowed_tlds`, `max_upload_size`, `allowed_extensions`,
`admin_emails`, `duplicate_window_hours`.

An empty `allowed_tlds` means *every* TLD; the shipped default is `.sa` only.

## Entity API

Entity type id: `domain_registration_request`.

```php
use Drupal\domain_availability\Entity\DomainRegistrationRequestInterface;

$storage = \Drupal::entityTypeManager()->getStorage('domain_registration_request');

/** @var \Drupal\domain_availability\Entity\DomainRegistrationRequestInterface $request */
$request->getDomain();            // 'neixora.sa'
$request->getStatus();            // one of the STATUS_* constants
$request->setStatus(DomainRegistrationRequestInterface::STATUS_APPROVED);
$request->isIndividual();         // bool
$request->getCertificateFile();   // FileInterface|NULL
$request->getCreatedTime();       // int
$request->getReferenceNumber();   // 'DRR-000042'
```

Constants:

```php
DomainRegistrationRequestInterface::APPLICANT_INDIVIDUAL;  // 'individual'
DomainRegistrationRequestInterface::APPLICANT_COMPANY;     // 'company'
DomainRegistrationRequestInterface::STATUS_PENDING;        // 'pending'
DomainRegistrationRequestInterface::STATUS_APPROVED;       // 'approved'
DomainRegistrationRequestInterface::STATUS_REJECTED;       // 'rejected'
DomainRegistrationRequestInterface::STATUS_CANCELLED;      // 'cancelled'
```

Base fields: `domain`, `applicant_type`, `registration_years`,
`company_name_ar`, `company_name_en`, `national_address`,
`commercial_registration`, `mobile`, `national_id`, `certificate`, `status`,
`notes`, `ip_address`, `user_agent`, `uid`, `created`, `changed`.

`national_id` carries the `SaudiId` constraint, so the rule holds for entity
forms, JSON:API, REST, migrations and programmatic saves alike.

Add your own fields with `hook_entity_base_field_info()`:

```php
function my_module_entity_base_field_info(EntityTypeInterface $entity_type): array {
  if ($entity_type->id() !== 'domain_registration_request') {
    return [];
  }

  return [
    'my_field' => BaseFieldDefinition::create('string')
      ->setLabel(t('My field')),
  ];
}
```

## Render, block and Twig APIs

Three ways to place the search form. All render the same form.

**Render element:**

```php
$build['search'] = ['#type' => 'domain_availability_search'];
```

**Block:** place *Domain availability search* (plugin id
`domain_availability_search`) from Block Layout.

**Twig:**

```twig
{{ domain_availability_search() }}
```

To restyle results, override `domain-availability-results.html.twig` in your
theme. The variables are `results`, `summary` and `report`.

## Routes and permissions

| Route name | Path | Access |
| --- | --- | --- |
| `domain_availability.api_check` | `/domain-check` | `use domain availability api` |
| `domain_availability.api_health` | `/domain-check/health` | `use domain availability api` |
| `domain_availability.search` | `/domain-search` | `access domain availability search` |
| `domain_availability.settings` | `/admin/config/system/domain-availability` | `administer domain availability` |
| `domain_availability.registration_settings` | `…/registration-settings` | `administer domain availability` |
| `domain_availability.registration_request.form` | `/domain-availability/register/{domain}` | custom: feature enabled |
| `entity.domain_registration_request.collection` | `…/registration-requests` | `view domain registration requests` |
| `entity.domain_registration_request.canonical` | `…/registration-requests/{id}` | entity `view` |
| `domain_availability.registration_request.status` | `…/{id}/status` | entity `update` |
| `entity.domain_registration_request.delete_form` | `…/{id}/delete` | entity `delete` |

Permissions: `access domain availability search`,
`use domain availability api`, `administer domain availability`,
`view domain registration requests`, `manage domain registration requests`,
`delete domain registration requests`. All but the first are
`restrict access: true`.

## JSON endpoint

### `GET /domain-check?domain=<label>`

```json
{
  "success": true,
  "query": "zzqexample",
  "took_ms": 412,
  "cached": false,
  "results": [
    {
      "domain": "zzqexample.sa",
      "extension": ".sa",
      "available": true,
      "status": "available",
      "provider": "whois"
    }
  ]
}
```

`available` is `null` when `status` is `unknown`. A `reason` key appears only on
an unknown result.

Errors carry `success: false` and a machine-readable `error`:

```json
{
  "success": false,
  "error": "validation_error",
  "message": "The domain name cannot start or end with a hyphen.",
  "errors": {"domain": "Remove the leading or trailing hyphen."}
}
```

| Status | `error` | Meaning |
| --- | --- | --- |
| `422` | `validation_error` | The label is not usable |
| `429` | `rate_limited` | Too many requests; carries `retry_after` |

`OPTIONS` is answered for CORS preflight.

### `GET /domain-check/health`

```json
{
  "success": true,
  "status": "ok",
  "timestamp": "2026-07-22T20:11:20+00:00",
  "checks": {
    "providers_registered": true,
    "json_available": true,
    "sockets_available": true
  },
  "diagnostics": {
    "whois_egress": {
      ".sa": {
        "reachable": true,
        "address": "86.111.192.10",
        "latency_ms": 14,
        "error": null,
        "cached": false,
        "server": "whois.nic.net.sa"
      }
    }
  }
}
```

## Events

**This module dispatches no custom events in 1.0.0.** That is a statement of
fact, not an omission from this page — nothing in `src/` calls a dispatcher.

It subscribes to Symfony kernel events internally to add CORS headers. That
subscriber is `@internal`.

If your integration needs an event, open an issue with the use case. An event is
a permanent contract and is better designed once than added twice. Meanwhile the
extension points listed in
[CONTRIBUTING.md](CONTRIBUTING.md#how-to-extend-business-logic) cover most needs.

Note that `saudi_id_validator` **does** dispatch events, and you can subscribe
to them to observe every identification number this module validates.

## Dependency on saudi_id_validator

`domain_availability.info.yml` declares:

```yaml
dependencies:
  - saudi_id_validator:saudi_id_validator
```

This is mandatory. Drupal installs the validator automatically when this module
is installed; no manual step is needed.

`composer.json` deliberately does **not** require
`abdelwahied/saudi_id_validator`. The validator is not yet published to an
official Composer repository, and requiring an unresolvable package would break
`composer install`; a `repositories` entry pointing at a VCS or a local path
would bake one machine's layout into a reusable package. The Drupal dependency
above is sufficient while both modules share a codebase — Drupal refuses to
enable this module without the validator either way. See
[README.md](README.md#why-there-is-no-composer-dependency) for when to change
this.

### Why

**No Saudi identification-number validation logic exists in this module.** Not a
regex, not a checksum, not a length check. All of it comes through the
validator's public API.

This is an architectural guarantee, and it buys three things:

1. **One answer.** A form field and a JSON:API write cannot disagree about what
   a valid number is, because they consult the same code.
2. **Rules change in one place.** Tightening the checksum is a change in the
   validator, next to the tests that prove it.
3. **The validator stays reusable.** It knows nothing about domains, and can
   drop into an unrelated project unchanged. The dependency arrow points one way
   only, and the validator has a test that fails if that ever stops being true.

### How this module consumes it

| Consumption | Where |
| --- | --- |
| `SaudiId` constraint on the `national_id` field | `DomainRegistrationRequest::baseFieldDefinitions()` |
| `SaudiIdValidatorInterface` injected | `DomainRegistrationRequestForm` |

The form uses the injected validator for two things: rendering the validator's
own message when a number is malformed, and asking `isSaudiCitizen()` to enforce
this module's **business** rule that an individual may only hold a `.sa` domain
as a Saudi citizen.

That second part is the boundary in practice. *Is this number well formed* is
the validator's question. *May this applicant have this domain* is this module's.

## Architecture

### Layers

```
HTTP / Twig / Block
        │
        ▼
Controllers · Forms · Render element        (@internal)
        │
        ▼
domain_availability.checker                 (@api)
  DomainCheckService
        │
        ├─── DomainCacheInterface           (@api, swappable)
        │
        ▼
ProviderRegistry                            (@internal)
  collects services tagged
  domain_availability_provider              (@api extension point)
        │
        ├── saudinic (5)   authoritative HTTP API, off by default
        ├── rdap    (10)   RDAP over HTTP
        ├── whois   (20)   WHOIS over TCP/43
        └── dns     (30)   DNS fallback
                │
                ▼
        DomainResult · CheckReport          (@api)
```

### Lookup flow

```
check('neixora')
  │
  ├─ DomainValidator: reject an unusable label ──► ValidationException
  │
  ├─ DomainCache: a hit returns immediately, marked cached
  │
  ├─ for each configured TLD:
  │     ProviderRegistry::chainFor($tld)
  │        └─ providers by priority(), lowest first
  │
  ├─ batch the domains per provider and look them up concurrently
  │     └─ a provider that cannot answer yields `unknown`,
  │        and the next provider in the chain is tried
  │
  └─ CheckReport { query, results[], tookMs, cached }
```

The rule that shapes this: **availability is never guessed**. A domain nobody
could answer for is `unknown`, never `available`.

### WHOIS flow

```
WhoisProvider
  │
  ├─ WhoisServerResolver ── shipped server map for known TLDs
  │        └─ otherwise ask whois.iana.org, then cache the answer
  │
  ├─ HostResolver ─ resolve the server, honouring whois_address_family
  │
  ├─ WhoisClient ─ raw TCP on port 43, connect and read timeouts
  │
  └─ WhoisResponseParser ─ match the registry's wording
           ├─ "available" patterns   ──► available
           ├─ "registered" patterns  ──► registered
           ├─ "rate limit" patterns  ──► unknown (never available)
           └─ nothing matched        ──► unknown
```

Server maps and reply patterns are container *parameters*, not configuration:
they are protocol facts a site never tunes, but a site that must — a new
registry, changed wording — can override any of them from its own
`services.yml` without patching the module.

Because a blocked outbound port 43 is invisible from outside, the module reports
WHOIS egress on the status report and on `/domain-check/health`.

### Constraint flow

```
any write to a domain_registration_request
  │  entity form · JSON:API · REST · migration · programmatic save
  ▼
entity validation
  │
  └─ national_id ─► SaudiId constraint
                      └─ saudi_id_validator's SaudiIdValidatorInterface
                           └─ format ─► leading digit ─► checksum
```

One path, whatever the caller. That is why the constraint lives on the field
definition rather than in a form handler.

### Registration workflow

```
search result: available AND TLD allowed
        │
        ▼
"+ Register this domain" ──► modal form
        │
        ├─ applicant type decides which fields are mandatory
        ├─ Saudi rules: mobile · commercial registration · national ID
        ├─ .sa + individual ──► must be a Saudi citizen
        └─ duplicate window: refuse a repeat request
        │
        ▼
domain_registration_request  (status: pending)
        │
        ├─ certificate marked permanent, usage recorded
        └─ RegistrationMailer: confirmation + admin notification
        │
        ▼
admin listing ──► detail ──► status change
                              pending → approved | rejected | cancelled
```

The module records **intent**. It does not register a domain, take payment or
contact a registrar.

### Configuration flow

```
SettingsForm ──────────► domain_availability.settings ──────► ModuleSettings ──► services
RegistrationSettingsForm ─► domain_availability.registration ─► RegistrationSettings ─► form + template
```

Both settings services read through the config factory on every call, so a
change takes effect at once with no rebuild.

## Backward compatibility policy

The module follows [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

### Stable — a change here requires a major release

- Any class, interface or method marked `@api`.
- The `domain_availability_provider` service tag and the meaning of
  `priority()` (lowest wins).
- The service ids in [the stability contract](#stability-contract).
- Route names and permission strings.
- Configuration object names and existing key names and meanings.
- The entity type id `domain_registration_request`, its `@api` interface and its
  `STATUS_*` and `APPLICANT_*` constant values.
- The render element id, block plugin id and Twig function name.
- The JSON endpoint's response shape: existing keys keep their names, types and
  meanings, and existing `error` codes keep their HTTP statuses.
- The `DomainStatus` cases.

### May change in a minor release

- Anything marked `@internal`.
- **New** keys added to a JSON response. Parse defensively.
- New configuration keys, with defaults preserving current behaviour.
- New optional parameters with defaults.
- New providers, and the WHOIS/RDAP server maps and reply patterns — these track
  reality at the registries.
- Wording of user-facing messages and log entries.
- Which provider answered a given lookup: `provider` in a result names whoever
  answered, and that may differ between releases as the maps improve.

### Deprecation policy

Nothing `@api` is removed without notice. A deprecated element:

1. is marked `@deprecated in 1.x and removed from 2.0.0`, with the replacement
   named;
2. triggers `E_USER_DEPRECATED` where a runtime warning is possible;
3. keeps working for the whole of the current major version;
4. is listed under `### Deprecated` in the CHANGELOG.

Removal happens only in a major release.

### Migration guidance

Each major release ships an upgrade section in the CHANGELOG naming every
removed element and its replacement. Schema changes arrive as `hook_update_N()`
so `drush updatedb` is the only step; there are no manual data edits.

Nothing on the deprecation path exists in 1.0.0 — it is the first release.

## Internal classes

37 of the module's 53 classes are `@internal`: controllers, forms, provider
implementations, the WHOIS and RDAP machinery, entity handlers, hooks and
transport DTOs. They may change in any release.

The ones most likely to be reached for by mistake:

| Internal | Use instead |
| --- | --- |
| `ProviderRegistry` | Tag a service `domain_availability_provider` |
| `RdapProvider`, `WhoisProvider`, `DnsProvider`, `SaudiNicProvider` | `DomainProviderInterface` |
| `DomainCache` | `DomainCacheInterface` |
| `DomainRegistrationRequest` | `DomainRegistrationRequestInterface` |
| `DomainValidator` | Catch `ValidationException` from `check()` |
| `StatusReportService` | `/domain-check/health` or the status report |
| Controllers and forms | The routes |
