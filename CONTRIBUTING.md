# Contributing

Thank you for considering a contribution.

## Architectural principles

Four rules explain most of the design. A patch that breaks one of them will be
questioned even if it works.

**1. Availability is never guessed.** A lookup that cannot be answered returns
`unknown`, never `available`. "Available" is the answer a visitor acts on — they
try to buy the domain — so a wrong one costs them money and trust. A throttled
registry, an unreachable host and an unparseable reply are all `unknown`.

**2. A provider never throws for one domain.** `lookup()` receives a batch. One
failure inside it returns an `unknown` result for that domain so the rest of the
batch still reaches the client. An exception would lose twenty good answers to
save one bad one.

**3. Adding a protocol changes no existing class.** Providers are collected from
a service tag and sorted by their own `priority()`. A new protocol is one new
class and one tagged service — the controller, the check service and the
registry stay untouched.

**4. Identification-number rules live in `saudi_id_validator`, never here.**
See [Dependency policy](#dependency-policy).

## Development environment

- PHP 8.3 or newer
- Drupal 10.3+ or 11.x
- Composer 2

The module has no build step.

```bash
composer require abdelwahied/domain_availability
drush en domain_availability
```

`saudi_id_validator` is a declared dependency, so Drupal installs it
automatically. You never need to enable it by hand.

For local work, symlink the module into a site's `web/modules/custom/` and
enable it the same way.

## Coding standards

Drupal and DrupalPractice, both clean — zero errors and zero warnings:

```bash
vendor/bin/phpcs --standard=Drupal,DrupalPractice \
  --extensions=php,module,inc,install,yml,js \
  web/modules/contrib/domain_availability
```

`vendor/bin/phpcbf` fixes most of what it finds. Read what it changed rather
than trusting it — it is good at whitespace and bad at prose.

Beyond the sniffs:

- `declare(strict_types=1);` in every PHP file.
- Constructor property promotion, `readonly` where the value never changes.
- `final` unless the class is designed for extension.
- Type hints and return types everywhere, including `void`.
- Comments explain *why*, not *what*. A comment restating the code is noise.

## Running the tests

```bash
# Unit and kernel
vendor/bin/phpunit -c web/core/phpunit.xml.dist \
  web/modules/contrib/domain_availability/tests

# Functional, which need a reachable site
SIMPLETEST_BASE_URL="http://127.0.0.1:8080" \
SIMPLETEST_DB="sqlite://localhost/db.sqlite" \
vendor/bin/phpunit -c web/core/phpunit.xml.dist \
  web/modules/contrib/domain_availability/tests
```

Everything must pass, and no new deprecation may be introduced.

Tests use the stub provider (`domain_availability_test`) so that which domains
are "available" is deterministic and no registry is contacted. **A test must
never make a real network call.**

Identification numbers in tests come from the generator, never from a literal:

```php
use Drupal\Tests\saudi_id_validator\Support\SaudiIdGenerator;

$ids = new SaudiIdGenerator();
$ids->nationalId();      // valid, starts with 1
$ids->iqama();           // valid, starts with 2
$ids->wrongChecksum();   // right shape, wrong check digit
```

A hardcoded ten-digit number cannot be checked by eye against its check digit.

## Branch strategy

- `main` — released code. Always green.
- `development` — integration branch. Work merges here first.
- `feature/<short-name>`, `fix/<short-name>` — branch from `development`.

Never commit directly to `main`. A release is a merge from `development` plus a
tag.

## Commit messages

[Conventional Commits](https://www.conventionalcommits.org/):

```
<type>(<scope>): <summary in the imperative>
```

Types: `feat`, `fix`, `docs`, `test`, `refactor`, `perf`, `chore`.
Scope: the area — `provider`, `whois`, `rdap`, `registration`, `api`, `cache`.

```
feat(provider): add a provider for the .eg registry
fix(whois): treat an empty reply as unknown rather than available
docs(api): document the health endpoint response
```

A breaking change gets a `!` and a `BREAKING CHANGE:` footer explaining the
migration.

## Pull request expectations

1. One logical change. A refactor and a fix belong in separate PRs.
2. Tests that fail before the change and pass after it.
3. PHPCS clean, PHPUnit green, no new deprecations.
4. A CHANGELOG entry under `## [Unreleased]`.
5. Documentation updated in the same PR when behaviour or an API changes.
6. No unrelated reformatting — a reviewable diff is a kindness.

If the change touches anything marked `@api`, say so in the description. It
decides whether the next release is a major one.

## Dependency policy

- **Drupal core and PHP extensions only.** The module deliberately has no
  third-party PHP runtime dependencies: RDAP goes over core's HTTP client and
  WHOIS over raw sockets. A patch adding a Composer runtime dependency needs to
  justify why core cannot do it.
- **`saudi_id_validator` is the one module dependency**, and it is mandatory. It
  is declared in `domain_availability.info.yml` and **not** in `composer.json`,
  on purpose — see
  [README.md](README.md#why-there-is-no-composer-dependency). Do not "fix" this
  by adding a `repositories` entry, a VCS reference or a path repository.
- Anything new belongs in `require-dev` unless production needs it.

### No identification-number logic in this module

All Saudi ID validation — length, leading digit, checksum, type detection —
comes from `saudi_id_validator` through its public API. This module holds none
of it, and a patch that adds a regex for a national ID will be rejected.

This is an architectural guarantee, not a convention: one validator means a
form field and an API write can never disagree about what a valid number is. If
a rule needs to change, it changes in the validator, where its tests live.

What this module *does* own is *business* rules built on top of that answer —
for instance "an individual may only hold a `.sa` domain as a Saudi citizen".
That asks the validator `isSaudiCitizen()` and decides what to do with the
answer; it does not re-implement the check.

## How to add a TLD provider

One class and one tagged service. Nothing else changes.

```php
namespace Drupal\my_module\Provider;

use Drupal\domain_availability\Dto\DomainResult;
use Drupal\domain_availability\Provider\DomainProviderInterface;
use Drupal\domain_availability\Utility\Tld;

final class MyRegistryProvider implements DomainProviderInterface {

  private const PRIORITY = 15;

  public function name(): string {
    return 'my_registry';
  }

  public function priority(): int {
    // Lowest wins. 15 sits between RDAP (10) and WHOIS (20).
    return self::PRIORITY;
  }

  public function supports(string $tld): bool {
    // No network I/O here — this is the hot path.
    return Tld::normalise($tld) === 'example';
  }

  public function lookup(array $domains): array {
    $results = [];

    foreach ($domains as $domain) {
      $extension = '.' . Tld::fromDomain($domain);

      try {
        $results[$domain] = $this->isTaken($domain)
          ? DomainResult::registered($domain, $extension, $this->name())
          : DomainResult::available($domain, $extension, $this->name());
      }
      catch (\Throwable $e) {
        // Never throw for one domain: the rest of the batch must still answer.
        $results[$domain] = DomainResult::unknown(
          $domain,
          $extension,
          $this->name(),
          $e->getMessage(),
        );
      }
    }

    return $results;
  }

}
```

```yaml
# my_module.services.yml
services:
  my_module.provider.my_registry:
    class: Drupal\my_module\Provider\MyRegistryProvider
    tags:
      - { name: domain_availability_provider }
```

Two things to know:

- **`priority()` decides selection, not the tag.** The registry collects tagged
  services and re-sorts them by `priority()`, ascending. The tag's own
  `priority` only orders collection and has no effect on which provider wins.
- **Names must be unique.** The registry throws `ConfigurationException` if two
  providers report the same `name()`.

The same recipe covers a registrar API or an alternative WHOIS source — they are
all just providers.

## How to add a validation rule

Distinguish the two kinds first.

**A domain-name rule** — what a label may contain — belongs in
`DomainValidator`, and it throws `ValidationException`. Add a test in
`tests/src/Unit/DomainValidatorTest.php` with a case in the data provider.

**A business rule** — who may request what — belongs in the form that owns the
decision, alongside the existing Saudi rules in
`DomainRegistrationRequestForm::validateForm()`, with a functional test.

**A storage-level rule** — one that must hold however the entity was created,
including through an API write or a migration — belongs on the field definition
as a constraint in `DomainRegistrationRequest::baseFieldDefinitions()`. That is
how the `SaudiId` constraint is attached.

An identification-number rule belongs in none of these. It belongs in
`saudi_id_validator`.

## How to extend business logic

Without patching this module:

| Goal | How |
| --- | --- |
| New lookup protocol or registrar | Tag a `DomainProviderInterface` service |
| Different caching | Bind your own `DomainCacheInterface` to `domain_availability.cache` |
| Change the results markup | Override `domain-availability-results.html.twig` in your theme |
| React to a validated ID | Subscribe to `saudi_id_validator`'s events |
| Extra fields on a request | `hook_entity_base_field_info()` for `domain_registration_request` |
| Extra validation on a request | `hook_form_alter()` adding a `#validate` handler |
| Pricing, or a registrar hand-off | A module that reacts to the entity's save hooks |

The module dispatches **no custom events of its own** in 1.0.0. If your
integration needs one, propose it in an issue with the use case — an event is a
permanent contract and is better designed once than added twice.

## Reporting a security issue

Do not open a public issue. See [SECURITY.md](SECURITY.md).
