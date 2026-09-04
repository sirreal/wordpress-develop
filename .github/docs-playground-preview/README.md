# Code Reference Playground preview

This directory holds the pinned inputs for the WordPress Core Code Reference preview: the dependency manifest, the Node version, and the npm tools the build installs. The implementation is in `.github/scripts/docs-playground-preview`.

A preview is the Core Code Reference built from PHPDoc at one commit, imported into a wporg-developer site and packaged as a WordPress Playground snapshot. Adding the `docs-preview` label to a pull request against `trunk` builds one and posts a link in a single comment on the pull request. Every push to `trunk` refreshes the preview that the repository `README.md` links.

Pull request source is parsed, never executed. The build job runs with `contents: read` and nothing else; only default-branch code, in a separate workflow, has the write access to publish. A pull request build may warm the build cache because GitHub scopes an entry written from a pull request ref to that ref, where it cannot replace the entry the default branch reads.

## What runs where

| Workflow | Trigger | What it does |
| --- | --- | --- |
| Build | The `docs-preview` label is added, a labeled pull request is pushed to, or `trunk` is pushed to | Builds and validates the snapshot and uploads it as an artifact kept for one day. A first attempt on a pull request reuses a published snapshot for the same commit instead of building one. |
| Publish | The build workflow finishes | Uploads the snapshot to the release. For a pull request it removes the label and updates the comment; for `trunk` it moves the stable Blueprint. |
| Lifecycle | A pull request against `trunk` is pushed to or closed | Marks the comment stale when the pull request is no longer labeled. On close, deletes the pull request's assets and caches and marks the comment expired. |
| Tests | A change under `.github/scripts/docs-playground-preview` or this directory | Runs the script tests and type checking. |

The comment is identified by the marker `<!-- code-reference-docs-preview -->` and is separate from the general Core Playground comment. It is created once and updated in place.

## Local build

```sh
npm --prefix .github/docs-playground-preview run build
```

Needs `git`, `node` at the version in `.nvmrc`, `php` 8.4, `composer` and `zip` on `PATH`. The command installs the pinned npm tools itself. It then takes the repository and commit from the checkout, resolves the current WordPress beta, builds or restores the site base, parses the PHP under `src`, imports the Code Reference, packages the snapshot and runs the same validation as CI. Everything is written below `.cache/docs-playground-preview`: the snapshot and its `build.json` in `output`, the cached base in `cache`.

The first build clones and builds the six pinned repositories and takes a long time. Later builds restore that base from the cache directory while the cache key is unchanged.

CI runs the same command twice. `--resolve-only` writes the cache key and directory for the cache restore step, plus the resolved inputs the second run reads back, so both runs agree on the WordPress version. The build run adds the source identity, `--source-repository`, `--source-sha` and `--run-url`, which appear in the snapshot's provenance banner. Both runs write into `--handoff`, the directory the publish job downloads; a failure in either run is reported through it.

## Tests and type checking

```sh
npm --prefix .github/docs-playground-preview test
npm --prefix .github/docs-playground-preview run typecheck
```

The `Code Reference Playground Preview Tests` workflow runs both. Type checking runs even when the tests fail, so one run reports both.

## Updating pins

`dependencies.json` pins each upstream repository to a full commit, and the build refuses anything else, because a branch or a tag would let the base change under a cache key that stays the same. To move a pin, change its commit, run a local build, then exercise a cold and a warm build in the staging repository.

The other pins live where the tool that reads them looks:

- **Node:** `.nvmrc`. All seven `setup-node` steps read it. Update `engines.node` and `@types/node` in `package.json` to match.
- **npm packages** (`@wp-playground/cli`, `@wordpress/scripts`, `@wordpress/i18n`, `yarn`): `package.json`, then regenerate `package-lock.json`.
- **PHP:** two versions that must agree. `playground.phpVersion` in `dependencies.json` is the PHP inside the snapshot; `php-version` in both `setup-php` steps of the build workflow is the PHP that runs phpdoc-parser.
- **Composer:** the `tools: composer:` version in both `setup-php` steps of the build workflow.
- **A GitHub Action:** its full commit SHA and the version comment beside it, in every workflow that uses it.

The cache key covers every file under this directory and under `.github/scripts/docs-playground-preview`, apart from `node_modules`, the type-check output and the tests, plus the resolved WordPress version and the runner image. Editing any of them builds a new base, whether or not that file shapes what the base contains: rebuilding for nothing costs one build, while a file left out of the key would be served from a stale cache forever. Increment `cacheSchemaVersion` to force a new base when nothing in the repository changed.

`validation.minimumSymbols`, `validation.routes` and `validation.search` in `dependencies.json` are what the finished snapshot is checked against. A route renamed upstream is changed there.

## Repository variables

The workflows run in `WordPress/wordpress-develop`. In `sirreal/wordpress-develop` they run only while the Actions variable `DOCS_PREVIEW_STAGING` is exactly `true`. Every job that writes anything tests that variable, and `assertDeploymentEnabled()` tests it again inside the publish and lifecycle scripts. To disable staging, delete the variable or give it any other value.

The pull request build job is the exception: it is allowlisted by repository name and does not consult the variable, because GitHub does not expose the base repository's Actions variables to a fork's `pull_request` run. That job cannot publish, comment, label or delete anything. With staging off, labeling a pull request there costs a read-only build and a one-day artifact. Other forks run nothing.

`DOCS_PREVIEW_ENFORCE` decides what a validation failure costs. Unset, the failures are reported as warnings, the comment says the attempt failed, and the preview published earlier stays linked. Set to exactly `true`, the build fails, and so does the publisher after it has reported the failure. Set either variable under **Settings > Secrets and variables > Actions > Variables**.

## Published assets

Snapshots are assets on the `code-reference-playground-preview` prerelease, each beside a JSON metadata asset with the same stem. The publisher names them after its own workflow run, so no build can name another pull request's asset.

- `code-reference-pr-<number>-<sha>-<run>-<attempt>.zip`. Publishing deletes every other asset of that pull request. Closing the pull request deletes them all.
- `code-reference-trunk-<sha>-<run>-<attempt>.zip`. The Blueprint `code-reference-trunk.json`, committed to the `docs-preview-code-reference` branch, names the newest one, and the repository `README.md` links that file through the Playground CORS proxy. The two newest generations are kept, because a cached copy of the Blueprint can name the previous snapshot for minutes after the pointer moves.

A snapshot over 100 MiB (104857600 bytes) fails the build, and the publisher checks the size again before uploading.

## When something fails

The comment's status line is one of Ready, Latest attempt failed, Stale or Expired. A failed attempt keeps the link to the last preview that worked.

- Read the run linked from the comment. Validation failures are also `::warning::` annotations on the build job.
- Publishing removes the label, so a rebuild means adding `docs-preview` again. A push while the preview is publishing keeps the label, because that push already started a build of its own. **Re-run all jobs** on the original build rebuilds the same commit and skips the same-commit reuse.
- For `trunk`, re-run the newest trunk build or push another commit. The next successful publication moves the pointer and prunes older assets. Do not delete the `docs-preview-code-reference` branch or the snapshot it names.
