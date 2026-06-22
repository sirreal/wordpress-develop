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
- `account-security`: no-DB account recovery and security APIs, including
  application password lifecycle/hash behavior, recovery key/cookie validation,
  and paused extension storage transitions.
- `admin-bar`: no-DB toolbar node lifecycle, default root/submenu binding,
  group/container behavior, render escaping/raw HTML contracts, and
  `show_admin_bar()` filter/global restoration.
- `admin-screen`: no-DB admin screen, settings, and meta-box APIs, including
  `WP_Screen` normalization/current-screen globals, column header filter
  locality, settings registry/default/sanitize callbacks, escaped settings
  field and nonce output, meta-box ordering/removal/callback args, and
  accordion section rendering.
- `ai-client`: no-DB WordPress AI Client API coverage for SDK DTO
  round-trips, enum strictness, provider registry isolation, prompt builder
  ability integration, cache and event adapters, and deterministic in-memory
  generation without network calls.
- `assets`: script/style registration lifecycle, dependency ordering, inline
  assets, loading strategies, script modules, and printed tag escaping.
- `blocks`: block type metadata, variations, hooks, style, pattern/category,
  bindings, and supports registries, including dynamic block attribute
  preparation and wrapper attribute merging.
- `block-templates`: no-DB block template and block theme resolution coverage,
  including template registry lifecycle, file-backed templates and parts,
  parent/child theme precedence, theme.json metadata, hierarchy resolution, and
  malformed filename/path guards with template CPT queries short-circuited.
- `capabilities`: role registry mutations, numeric and explicit capability
  grants, role filters, `WP_User` role/direct cap aggregation, and cheap
  `map_meta_cap()` mappings.
- `content`: slashing, metadata serialization, post and term field sanitization,
  query variables, `WP_Date_Query`, title/class/key sanitizers.
- `comments`: comment filtering, sanitizer agreement, max-length boundaries,
  type partitioning, comment classes, email links, and comment cookies.
- `cron`: in-memory cron scheduling, recurrence lookup, ready-job partitioning,
  unscheduling and rescheduling contracts.
- `customizer`: no-DB Customizer API coverage for manager registry lifecycles,
  setting sanitize/validate/post value flows, multidimensional option previewing,
  container/control JSON exports, active callbacks, and selective refresh partial
  registration/rendering without changeset persistence.
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
- `mail`: no-delivery `wp_mail()` composition coverage, including argument
  filters, pre-send short-circuiting, PHPMailer recipient/header/content
  handoff, attachments, embedded images, success/failure actions, and emoji
  email body staticization.
- `markup`: block parse/serialize/render guards, shortcodes, text trimming,
  excerpts, balanced tags, URL extraction, embed helpers.
- `media-editor`: no-DB media image editor coverage for editor selection,
  GD/Imagick availability, output format filters, resize/save metadata,
  intermediate and generated sub-sizes, and cache/filter-backed attachment
  metadata helpers with temp-file cleanup.
- `metadata`: no-DB Metadata API registration, subtype visibility, defaults,
  sanitize/auth/protected-meta filters, cache-backed lookup shape, filtered
  CRUD short-circuits, and lazyloader queue/reset behavior.
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
- `privacy`: no-DB user request and privacy helper coverage, including
  synthetic `WP_User_Request` objects, action descriptions, request-key hash
  validation, export group HTML escaping, exporter/eraser processor shape
  contracts, anonymization helpers, and privacy policy suggestion/default text
  without mail, network, or export file writes.
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
- `site-health`: no-DB Site Health/update/HTTPS helper coverage, including
  generated update transients and dismissed core update options, aggregate
  update counts/titles, HTTPS option booleans, migration replacement, HTTPS
  detection short-circuits, and selected direct `WP_Site_Health` tests without
  remote requests.
- `state`: object cache groups and multi-operations, option and transient APIs
  backed by the no-DB stub, filters, serialization, JSON, and value helpers.
- `style`: style engine serialization, CSS declaration filtering, theme.json
  schema/data merging, block style variation declarations, selectors, presets,
  custom properties, and no-DB global style guards.
- `syndication`: oEmbed provider registration, embed handler lifecycle, oEmbed
  HTML/XML filtering, feed metadata escaping, default feed normalization, self
  links, and Atom text construction.
- `taxonomy`: taxonomy registration lifecycle, object-type associations,
  registry query consistency, argument normalization, labels, term
  sanitization, synthetic `WP_Term` behavior, and cheap term-link paths.
- `template-links`: no-DB public template and link helpers, including body and
  language attributes, document title stability, resource hints/preloads,
  pagination/search/feed/site/admin URLs, synthetic post preview/edit/delete/
  shortlink/permalink helpers, and cached bookmark field/list rendering.
- `widgets`: classic sidebar registry lifecycle, widget factory instance
  registration, widget ID parsing, sidebar assignment moves/removals, and
  render callback wrapper output.
- `xmlrpc`: no-DB IXR/XML-RPC protocol coverage, including value escaping,
  request/message round trips, invalid XML fail-closed behavior, fault XML,
  system method dispatch, method registry filters, demo helpers, and disabled
  login behavior without publishing, media, pingback, or option side effects.

Some checks deliberately skip cases that would invoke DB-backed or dynamic block
rendering side effects. The Admin Screen surface intentionally avoids admin page
submission, `options.php` writes, user preference persistence, real post objects,
and block-editor compatibility shims that would inspect installed plugins. The
block templates surface short-circuits template CPT queries through
`posts_pre_query` and records the current direct-ID traversal behavior as a
guarded skip while still asserting that file enumeration remains confined. The
Customizer surface intentionally avoids changeset save/publish, nav-menu
persistence, widget persistence, and real post/option storage beyond the
existing no-DB option stub. The `media-editor` surface short-circuits attachment
metadata updates and intentionally avoids media paths that insert attachments,
create cover-image attachments, process audio/video thumbnails, or otherwise
require real postmeta writes. The Site Health surface avoids loopback,
WordPress.org, REST availability, update download, mail, cron, and
filesystem-writing checks unless they are fully short-circuited. The mail
surface intercepts PHPMailer send calls and never attempts real delivery.
Skips are recorded in `results.ndjson` with a reason and do not mask failures
or PHP errors.

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
