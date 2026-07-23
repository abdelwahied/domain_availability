# Releasing

A step-by-step checklist for cutting a release of the Domain Availability module.
It assumes no prior knowledge of the project: follow it top to bottom.

Throughout, `X.Y.Z` is the version being released (for example `1.0.0`).

## 1. Prepare

- [ ] Work from a clean checkout of the branch you are releasing from
      (`git status` shows nothing uncommitted).
- [ ] Confirm you are on the intended branch (`main` for the current major).

## 2. Run the test suite

Tests run inside a Drupal site with the module — and its `saudi_id_validator`
dependency — placed in `web/modules/custom/`. Functional tests need a served,
installed site. From that site's root:

```bash
SIMPLETEST_BASE_URL="http://127.0.0.1:8081" \
SIMPLETEST_DB="sqlite://localhost/db.sqlite" \
BROWSERTEST_OUTPUT_DIRECTORY="$PWD/web/sites/simpletest/browser_output" \
  ./vendor/bin/phpunit -c web/core/phpunit.xml.dist \
  web/modules/custom/domain_availability/tests
```

- [ ] Every test passes. (One upstream-core deprecation is expected; a second is
      a regression.)

CI runs the same suite across PHP 8.3/8.5 and Drupal 10.3/11 on every push; a
green run on the release commit is the authoritative check.

## 3. Run the coding-standards check

```bash
./vendor/bin/phpcs --standard=Drupal,DrupalPractice \
  --extensions=php,module,inc,install,yml,js \
  web/modules/custom/domain_availability
```

- [ ] Zero errors and zero warnings.

## 4. Review the changelog

- [ ] [CHANGELOG.md](CHANGELOG.md) has an entry for `X.Y.Z` describing every
      notable change since the last release.
- [ ] The entry is dated and the "Unreleased" heading (if any) is moved down.

## 5. Update documentation

- [ ] [README.md](README.md) examples still match the code.
- [ ] [API.md](API.md) lists the current public surface, including the provider
      extension point and the documented service parameters.
- [ ] [UPGRADING.md](UPGRADING.md) has a section for `X.Y.Z` if any manual step
      is needed (none for a patch or minor).
- [ ] Version references in prose and badges are correct.

## 6. Verify composer metadata

```bash
composer validate --strict --no-check-all
```

- [ ] Passes.
- [ ] `name`, `description`, `license`, `keywords`, `require` (including the
      `ext-*` and `saudi_id_validator` dependency) and `authors` are accurate.
- [ ] No `repositories`, path repositories or VCS repositories are present.

## 7. Verify the README examples

- [ ] Every service ID, route name (`domain_availability.search`,
      `domain_availability.api_check`, `domain_availability.api_health`) and
      configuration key named in the README exists in the code.

## 8. Tag the release

Drupal.org and Composer both read the tag as the version, so it must match
`X.Y.Z` with no `v` prefix for Drupal.org contrib.

```bash
git tag -a X.Y.Z -m "Domain Availability X.Y.Z"
```

- [ ] Tag created on the reviewed commit.

## 9. Push the tag

```bash
git push origin X.Y.Z
```

- [ ] Tag pushed. CI runs against the tag.

## 10. Publish release notes

- [ ] Create the release on the hosting platform (GitHub release or Drupal.org
      release node) using the `X.Y.Z` CHANGELOG entry as the notes.
- [ ] Confirm the packaged archive installs cleanly on a fresh site (with the
      `saudi_id_validator` dependency available).

## Notes for a repository split

This module currently lives alongside others in one repository. When it is moved
to its own repository, copy `.github/workflows/ci.yml` and
`.github/workflows/reusable-drupal-module.yml` into it and change the module
path in the reusable workflow from `modules/custom/domain_availability` to the
repository root. The workflow already copies `saudi_id_validator` alongside for
the dependency; adjust that to a `composer require` once both are published.
