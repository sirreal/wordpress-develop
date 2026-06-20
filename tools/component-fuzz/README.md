# Component Fuzzers

Pure-PHP fuzzing harness for broad WordPress component surfaces. It follows the
same operating model as `tools/html-api-fuzz`: deterministic generation from a
seed, bounded inputs, structured replayable artifacts, and explicit invariants
instead of one-off example tests.

The harness intentionally boots a no-DB subset of WordPress. Surface modules
therefore focus on public APIs that can be exercised without live inserts,
network requests, or a configured site.

## Surfaces

- `content`: slashing, metadata serialization, post and term field sanitization,
  query variables, `WP_Date_Query`, title/class/key sanitizers.
- `comments`: comment filtering, sanitizer agreement, max-length boundaries,
  type partitioning, comment classes, email links, and comment cookies.
- `cron`: in-memory cron scheduling, recurrence lookup, ready-job partitioning,
  unscheduling and rescheduling contracts.
- `email`: Unicode email validation/sanitization, ASCII fallback filters,
  punycode views, invalid UTF-8, malformed address structure.
- `identity`: usernames, emails, capabilities, text/comment filters, comment
  cookies, options, password hashing/checking, parse helpers.
- `hooks`: filter/action priority ordering, accepted arguments, removal,
  nested hook stack state, `current_filter()`, `doing_filter()`, `did_action()`.
- `kses`: KSES policies, protocol filtering, safe CSS, attribute parsing,
  no-HTML filtering, strict/custom policy monotonicity.
- `markup`: block parse/serialize/render guards, shortcodes, text trimming,
  excerpts, balanced tags, URL extraction, embed helpers.
- `network-media`: URL parsing/sanitization/validation, path normalization,
  filename sanitization, filetype checks, unique filenames, sideload handling.
- `rest`: request normalization, parameter precedence, JSON bodies, route regexes,
  schema sanitize/validate, permissions, HEAD/GET behavior.

Some checks deliberately skip cases that would invoke DB-backed or dynamic block
rendering side effects. Skips are recorded in `results.ndjson` with a reason and
do not mask failures or PHP errors.

## Commands

List registered surfaces:

```sh
php tools/component-fuzz/runner.php --list-surfaces
```

Run all surfaces for 25 generated cases each:

```sh
php tools/component-fuzz/runner.php --seed 1 --iterations 25
```

Run selected surfaces and stop on the first failure:

```sh
php tools/component-fuzz/runner.php --surface kses,rest --seed 100 --iterations 200 --fail-fast
```

Each run writes `summary.json` and `results.ndjson` under
`artifacts/component-fuzz/run-...` unless `--output-dir` is provided.

## Surface Contract

Surface modules live in `tools/component-fuzz/surfaces/*Surface.php` and define:

```php
namespace ComponentFuzz\Surfaces;

final class ExampleSurface {
	public const NAME = 'example';

	public static function run( \ComponentFuzz\FuzzContext $ctx ): array {
		return array(
			$ctx->pass( 'invariant-name', array( 'input' => '...' ) ),
		);
	}
}
```

Each returned row should be a structured invariant result. Failures should carry
enough input preview and oracle detail to reproduce the case from the surface
name, seed, and iteration in `results.ndjson`.
