# Component Fuzzers

Pure-PHP fuzzing harness for broad WordPress component surfaces. It follows the
same operating model as `tools/html-api-fuzz`: deterministic generation from a
seed, bounded inputs, structured replayable artifacts, and explicit invariants
instead of one-off example tests.

The harness intentionally boots a no-DB subset of WordPress. Surface modules
therefore focus on public APIs that can be exercised without live inserts,
network requests, or a configured site.

## Surfaces

- `abilities`: Abilities API category and ability registry lifecycles,
  action-gated registration, metadata/default preparation, filtered discovery,
  schema validation, permission checks, execution, and unregister behavior.
- `ai-client`: no-DB WordPress AI Client API coverage for SDK DTO
  round-trips, enum strictness, provider registry isolation, prompt builder
  ability integration, cache and event adapters, and deterministic in-memory
  generation without network calls.
- `assets`: script/style registration lifecycle, dependency ordering, inline
  assets, loading strategies, script modules, and printed tag escaping.
- `blocks`: block type metadata, variations, hooks, style, pattern/category,
  bindings, and supports registries, including dynamic block attribute
  preparation and wrapper attribute merging.
- `capabilities`: role registry mutations, numeric and explicit capability
  grants, role filters, `WP_User` role/direct cap aggregation, and cheap
  `map_meta_cap()` mappings.
- `content`: slashing, metadata serialization, post and term field sanitization,
  query variables, `WP_Date_Query`, title/class/key sanitizers.
- `comments`: comment filtering, sanitizer agreement, max-length boundaries,
  type partitioning, comment classes, email links, and comment cookies.
- `cron`: in-memory cron scheduling, recurrence lookup, ready-job partitioning,
  unscheduling and rescheduling contracts.
- `discovery`: robots meta directives, sitemap provider registration, sitemap
  URL/index expansion, escaped sitemap XML rendering, and sitemap max-URL
  filters without DB-backed providers.
- `email`: Unicode email validation/sanitization, ASCII fallback filters,
  WHATWG-style validity, `WP_Email_Address` IDN/punycode views, invalid UTF-8,
  malformed address structure, and boundary lengths.
- `fonts`: font-face CSS serialization and validation, font directory filters,
  Font Library collection registration/JSON loading, and font utility
  sanitization for family lists, face slugs, schemas, and MIME maps.
- `filesystem`: path normalization and joining, file validation classes,
  filename sanitization/uniqueness, temp names, direct filesystem sandboxing.
- `formatting`: escaping helpers, text sanitizers, whitespace normalization,
  autop/shortcode cleanup, clickable text, entity normalization, colors, sizes,
  time strings, UTF-8 helpers, and accent removal.
- `html-api`: HTML tag and tree processor updates, normalization idempotence,
  breadcrumbs, token walking, and modifiable text escaping.
- `http`: synthetic HTTP response arrays, response objects, header/cookie
  parsing, proxy decisions, redirect safety, URL validation, and relative URL
  resolution without live network requests.
- `images`: image constraint and resize math, metadata dimension lookup,
  responsive `srcset`/`sizes` generation, attachment image helpers, image tag
  attribute insertion, and loading optimization attributes.
- `identity`: usernames, emails, capabilities, text/comment filters, comment
  cookies, options, password hashing/checking, parse helpers.
- `interactivity`: server-side directive processing for context, bind, class,
  style, text, and each directives, unsupported/unbalanced HTML fallbacks,
  derived context/element helpers, and state/config merge serialization.
- `hooks`: filter/action priority ordering, accepted arguments, removal,
  nested hook stack state, `current_filter()`, `doing_filter()`, `did_action()`.
- `kses`: KSES policies, protocol filtering, safe CSS, attribute parsing,
  no-HTML filtering, strict/custom policy monotonicity.
- `l10n`: translation fallbacks, escaped translation helpers, plural selection,
  textdomain load/unload state, locale determination, localized numbers/dates.
- `markup`: block parse/serialize/render guards, shortcodes, text trimming,
  excerpts, balanced tags, URL extraction, embed helpers.
- `navigation`: nav menu location registration, theme menu assignment lookup,
  menu object and item setup filters, current-item class derivation, walker
  output, depth pruning, and filtered no-DB `wp_nav_menu()` rendering.
- `network-media`: URL parsing/sanitization/validation, path normalization,
  filename sanitization, filetype checks, unique filenames, sideload handling.
- `plugin-theme`: plugin headers, plugin path helpers, invalid plugin path
  validation, no-DB plugin dependency metadata, theme headers, parent/child
  relationships, active theme file helpers, screenshots, and broken theme errors.
- `post-types`: post type and post status registry defaults, support feature
  registration, capability generation, query/archive normalization, unregister
  cleanup, and status filtering.
- `query`: no-DB query builder APIs, including meta/tax/date query tree
  sanitization, SQL fragment generation, relation normalization, query-var
  parsing, and deterministic global restoration.
- `rest`: request normalization, parameter precedence, JSON bodies, route regexes,
  schema sanitize/validate, permissions, HEAD/GET behavior.
- `rewrite`: rewrite tags, permastruct/rule generation, endpoint expansion,
  query arg helpers, URL parsing, home/site URL helpers, and cheap no-DB
  `url_to_postid()` paths.
- `security`: salts and HMACs, nonce generation/verification, nonce URLs and
  hidden fields, admin/ajax referer paths, synthetic auth cookies and session
  tokens, redirect sanitization, and safe redirect filters.
- `state`: object cache groups and multi-operations, option and transient APIs
  backed by the no-DB stub, filters, serialization, JSON, and value helpers.
- `style`: style engine serialization, CSS declaration filtering, theme.json
  schema/data merging, block style variation declarations, selectors, presets,
  custom properties, and no-DB global style guards.
- `taxonomy`: taxonomy registration lifecycle, object-type associations,
  registry query consistency, argument normalization, labels, term
  sanitization, synthetic `WP_Term` behavior, and cheap term-link paths.
- `widgets`: classic sidebar registry lifecycle, widget factory instance
  registration, widget ID parsing, sidebar assignment moves/removals, and
  render callback wrapper output.

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
