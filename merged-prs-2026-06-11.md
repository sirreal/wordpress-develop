# PR merges for html-api-fuzz

Date: 2026-06-11

All included PRs were merged into `html-api-fuzz` with merge commits. Trunk was checked before and after these merges; `origin/trunk` is already an ancestor of this branch, so Git had no trunk merge commit to create.

## Merged

- PR #53, `origin/spec-compliant-getters`
  - Merge commit: `5a3cbf19df`
  - Why: Aligns HTML API input preprocessing and getter behavior with the spec, reducing fuzzer/oracle noise around NULL bytes, carriage returns, and decoded source values. This also gives the rebuilt #42 branch the helper behavior it expects.

- PR #42, `origin/html-api-fuzz-fiz/decoded-cr`
  - Merge commit: `ce2af0eff6`
  - Why: Updates the earlier #42 merge to the current PR head. It preserves decoded carriage returns as `&#13;` during serialization, normalizes NULL bytes through the shared serializer path, and removes the old `get_attribute_for_serialization()` workaround that #53 makes unnecessary.

- PR #51, `origin/html-api-normalize-restore-missing-text-content`
  - Merge commit: `ce08149069`
  - Why: Addresses issue #50 and the latest fuzzer `normalize-tree-changed` failures where raw text was dropped from `IFRAME`, `NOEMBED`, `NOFRAMES`, and related rawtext serialization paths.

- PR #17, `origin/copilot/add-script-data-filter`
  - Merge commit: `adbe354c94`
  - Why: Addresses issue #16 by adding the classic-script `script_data_{$handle}` JSON data hook. This is separate from the HTML API fuzzer stack, so it was kept in its own merge commit.

## Not merged

- Trunk: already present; no-op.
- Other open PRs: either already contained in this branch or not tied to the existing issues identified in this pass.
