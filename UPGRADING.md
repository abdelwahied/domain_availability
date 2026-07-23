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
