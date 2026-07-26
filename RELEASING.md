# Releasing

How a release of this module is cut. Follow it top to bottom; it assumes no
prior knowledge.

Throughout, `X.Y.Z` is the version being released (for example `1.1.0`).

> **Where development happens.** This repository is the *published* form of the
> module. Development happens in a monorepo that also holds sibling modules, and
> releases are synchronised from there by a script. If you are reading this in
> the monorepo, the full procedure — including the sync step — is in
> `RELEASE-WORKFLOW.md` at its root. If you are reading this in the public
> repository, sections 1–6 still apply and the sync step is somebody else's
> first step.

## 0. Two tags, one commit

| Host | Tag | Why |
| --- | --- | --- |
| GitHub | `vX.Y.Z` | Repository convention, matching `v1.0.0` and `v1.0.1`. |
| Drupal.org | `X.Y.Z` | Drupal.org reads the tag as the version. **A `v` prefix is not recognised and the release will not build.** |

Both tags point at the same commit. Every file that *states* a version uses the
bare `X.Y.Z` form.

Drupal.org also expects a contrib branch per minor series: `1.0.x`, `1.1.x`.

## 1. Prepare

- [ ] Work from a clean checkout of the branch you are releasing from.
- [ ] Confirm the target: `main` on GitHub, `X.Y.x` on Drupal.org.

## 2. Run the test suite

Tests run inside a Drupal site with this module — and its `saudi_id_validator`
dependency — placed in `web/modules/custom/`. Functional tests need a served,
installed site. From that site's root:

```bash
SIMPLETEST_BASE_URL="http://127.0.0.1:8081" \
SIMPLETEST_DB="sqlite://localhost/db.sqlite" \
BROWSERTEST_OUTPUT_DIRECTORY="$PWD/web/sites/simpletest/browser_output" \
  ./vendor/bin/phpunit -c web/core/phpunit.xml.dist \
  web/modules/custom/domain_availability/tests
```

- [ ] Zero failures.

> Deprecation **counts** are not a signal on a shared local site: contrib
> modules installed alongside produce their own. Check the *source file* of any
> deprecation. CI builds a throwaway site containing only this module, which is
> where the count means something.

CI runs the same suite across PHP 8.3/8.5 and Drupal 10.3/11 on every pull
request; a green run on the release commit is the authoritative check.

## 3. Run the coding-standards check

```bash
./vendor/bin/phpcs --standard=Drupal,DrupalPractice \
  --extensions=php,module,inc,install,yml,js '--ignore=*/.github/*' \
  web/modules/custom/domain_availability
```

- [ ] Zero errors and zero warnings.

## 4. Review the changelog

- [ ] [CHANGELOG.md](CHANGELOG.md) has a `## [X.Y.Z] — YYYY-MM-DD` heading
      describing every notable change since the last release.
- [ ] `## [Unreleased]` sits above it and reads `Nothing yet.`
- [ ] No entry describes a fix to code that was never released. Describe the
      shipped behaviour instead; a user who never saw the bug should not have to
      read about it.

## 5. Update documentation and version fields

Four places state the version. They must agree, or Drupal.org will package an
archive that contradicts its own tag.

- [ ] `composer.json` → `extra.drupal.version` is `X.Y.Z`
- [ ] `CHANGELOG.md` → topmost released heading is `[X.Y.Z]`, dated
- [ ] `README.md` → the `**Version:**` line reads `X.Y.Z`
- [ ] [UPGRADING.md](UPGRADING.md) has a section for `X.Y.Z` if any manual step
      is needed
- [ ] [API.md](API.md) lists the current public surface

Automated: `scripts/release/preflight.sh domain_availability X.Y.Z` in the
monorepo checks all of the above, and the `release-guard` workflow re-checks
them when the tag is pushed.

## 6. Verify composer metadata

```bash
composer validate --strict --no-check-all
```

- [ ] Passes.
- [ ] `name`, `description`, `license`, `keywords`, `homepage`, `require`
      (including `ext-*` and the `saudi_id_validator` dependency) and `authors`
      are accurate.
- [ ] No `repositories`, path repositories or VCS repositories are present.

## 7. Open the pull request

`main` is protected: linear history, no force pushes, and three required status
checks. A release lands through a pull request.

```bash
git push -u origin release/X.Y.Z
gh pr create --base main --head release/X.Y.Z \
  --title "release: X.Y.Z" --body-file CHANGELOG.md
gh pr checks --watch
gh pr merge --squash --delete-branch
```

- [ ] All three checks green, PR merged.

## 8. Tag both hosts

```bash
git checkout main && git pull

git tag -a vX.Y.Z -m "domain_availability X.Y.Z"
git push origin vX.Y.Z

git remote add drupal https://git.drupalcode.org/project/domain_availability.git
git push drupal main:X.Y.x
git tag -a X.Y.Z -m "domain_availability X.Y.Z"
git push drupal X.Y.Z
```

- [ ] Both tags point at the merge commit.
- [ ] The `release-guard` workflow passed on the GitHub tag. **If it failed,
      stop** — delete both tags and fix the tree before going further.

## 9. Publish release notes

```bash
gh release create vX.Y.Z --title "vX.Y.Z" --notes-file CHANGELOG.md
```

Then, on Drupal.org — manual, because there is no supported API:

1. <https://www.drupal.org/node/add/project-release/domain_availability>
2. Select tag `X.Y.Z`.
3. Paste the `X.Y.Z` CHANGELOG entry as the release notes.
4. Set the release type to match the CHANGELOG.
5. Save; packaging runs within roughly fifteen minutes.

- [ ] The archive installs cleanly on a fresh site, with `saudi_id_validator`
      available.

## 10. Rollback

**Before the Drupal.org release node exists** — remove the tags:

```bash
git push origin :refs/tags/vX.Y.Z
git push drupal  :refs/tags/X.Y.Z
gh release delete vX.Y.Z --yes
```

**After it exists** — do not delete it. Someone may have installed it, and a
version that vanishes breaks their `composer.lock`. Unpublish the release node
and fix forward with a patch. `X.Y.Z+1` an hour later is a smaller event than a
release that disappears.

`main` has linear history, so revert rather than rewrite:

```bash
git revert <merge-sha>
```

A revert made here must be reapplied in the monorepo, or the next release will
silently undo it.

## 11. Security releases

Follow [SECURITY.md](SECURITY.md) first. Coordinate with the Drupal Security
Team **before** anything becomes public — that includes the tag, the pull
request and the CHANGELOG entry.

This project is currently **not covered** by a security advisory policy; the
coverage application is awaiting review.
