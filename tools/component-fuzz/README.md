# Component Fuzzers

Pure-PHP fuzzing harness for broad WordPress component surfaces. It follows the
same operating model as `tools/html-api-fuzz`: deterministic generation from a
seed, bounded inputs, structured replayable artifacts, and explicit invariants
instead of one-off example tests.

The harness intentionally boots a no-external-DB subset of WordPress. Surface
modules therefore focus on public APIs that can be exercised without a live
database, network requests, or a configured site.

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
- `admin-workflows`: no-DB admin menu, list-table, and referer-helper
  workflows, including menu/submenu global registration and removal, hook suffix
  and menu URL behavior, parent file normalization, synthetic `WP_List_Table`
  pagination/columns/views/bulk actions/row actions/tablenav rendering, and
  safe admin/AJAX nonce checks without process exits.
- `ai-client`: no-DB WordPress AI Client API coverage for SDK DTO
  round-trips, enum strictness, provider registry isolation, prompt builder
  ability integration, cache and event adapters, and deterministic in-memory
  generation without network calls.
- `assets`: script/style registration lifecycle, dependency ordering, inline
  assets, loading strategies, script modules, and printed tag escaping.
- `auth-flow`: no-DB authentication and session flow coverage, including
  synthetic user rows, username/email/password authentication filters,
  sign-on cookie actions with cookie sending short-circuited, auth cookie
  validation hooks/default parsing, current-user and cookie global restoration,
  and session token lifecycle operations.
- `blocks`: block type metadata, variations, hooks, style, pattern/category,
  bindings, and supports registries, including dynamic block attribute
  preparation and wrapper attribute merging.
- `block-widgets`: block-backed widget behavior, including `WP_Widget_Block`
  rendering, dynamic legacy class mapping, content sanitization on update,
  form escaping, widgets block editor support toggles, and sidebars widget
  mapping for block widget instances.
- `block-templates`: no-DB block template and block theme resolution coverage,
  including template registry lifecycle, file-backed templates and parts,
  parent/child theme precedence, theme.json metadata, hierarchy resolution, and
  malformed filename/path guards with template CPT queries short-circuited.
- `block-editor-adjuncts`: no-DB block editor adjunct API coverage, including
  editor context objects, category and allowed-block filters, legacy widget and
  merged editor settings, local theme style helpers, iframe asset collection,
  REST preload path normalization with dispatch short-circuited, and global
  restoration.
- `capabilities`: role registry mutations, numeric and explicit capability
  grants, role filters, `WP_User` role/direct cap aggregation, and cheap
  `map_meta_cap()` mappings.
- `canonical-routing`: no-DB canonical redirect and front-end routing helpers,
  including method/search/preview bailouts, host/path/query cleanup, invalid
  date redirects, feed/pagination canonicalization, redirect filter
  cancellation, fragment stripping, and query-argument removal contracts.
- `content`: slashing, metadata serialization, post and term field sanitization,
  query variables, `WP_Date_Query`, title/class/key sanitizers.
- `content-lifecycle`: in-memory wpdb-backed post, term, user, and comment CRUD
  lifecycles, including insert/update/read/delete round trips, duplicate and
  invalid-input errors, sanitizer agreement, monotonic IDs, cheap hook ordering,
  cache/count refresh behavior, and per-iteration state restoration.
- `comments`: comment filtering, sanitizer agreement, max-length boundaries,
  type partitioning, comment classes, email links, and comment cookies.
- `comment-workflow`: in-memory comment submission, duplicate/flood approval
  decisions, moderation short-circuits, update/status transition hooks,
  trash/untrash and spam/unspam restoration, and WP_Error failure paths without
  process exits.
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
- `feed-rendering`: no-DB RSS2, Atom, and comments RSS2 feed template rendering
  over synthetic query loops, including feed item/entry counts, self links,
  CDATA terminator escaping, excerpt/content mode switches, enclosure metadata,
  comment feed escaping, and feed build date selection.
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
- `media-ingest`: no-network media upload and sideload ingest coverage over
  generated temp fixtures, including upload directory filters, MIME/filetype
  boundaries, sanitized unique filenames, attachment row and postmeta creation
  in the in-memory wpdb stub, metadata update failure paths, and cleanup
  restoration.
- `metadata`: no-DB Metadata API registration, subtype visibility, defaults,
  sanitize/auth/protected-meta filters, cache-backed lookup shape, filtered
  CRUD short-circuits, and lazyloader queue/reset behavior.
- `multisite`: no-DB multisite/network API coverage, including synthetic
  `WP_Site` and `WP_Network` objects, site data normalization, cache-backed
  lookups, blog-switch stack/cache restoration, filter-backed network option
  reads, stub-backed network option CRUD, pre-query-short-circuited site/network
  queries, and current/switched URL helpers.
- `navigation`: nav menu location registration, theme menu assignment lookup,
  menu object and item setup filters, current-item class derivation, walker
  output, depth pruning, and filtered no-DB `wp_nav_menu()` rendering.
- `network-media`: URL parsing/sanitization/validation, path normalization,
  filename sanitization, filetype checks, unique filenames, sideload handling.
- `plugin-theme`: plugin headers, plugin path helpers, invalid plugin path
  validation, no-DB plugin dependency metadata, theme headers, parent/child
  relationships, active theme file helpers, screenshots, and broken theme errors.
- `plugin-theme-lifecycle`: no-network plugin/theme lifecycle coverage over
  generated temp fixtures, including plugin validation and requirement errors,
  dependency failure states, activation/deactivation success and output-failure
  paths, active and sitewide-active option shapes, plugin/theme deletion
  validation, theme enumeration and requirement checks, safe child-theme
  switching, theme support/template globals, and read-only REST plugin/theme
  controller paths.
- `update-install-upgrader`: no-network update/install/upgrader coverage,
  including generated core/plugin/theme update transient shapes, aggregate
  update counts/titles, `WP_Upgrader_Skin` and `Automatic_Upgrader_Skin`
  output behavior, `WP_Upgrader` local/filtered download and temp-directory
  install-package lifecycles, plugin/theme package validation helpers,
  no-update upgrade branches, auto-update decision filters, core version-policy
  decisions, and maintenance-mode writes against a temp filesystem only.
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
- `request-lifecycle`: no-DB front-controller lifecycle coverage for
  `WP::parse_request()`, rewrite-rule matching, query-var precedence,
  `register_globals()`, `handle_404()` status transitions, and `send_headers()`
  filters/actions with deterministic global restoration.
- `rest-controllers`: no-DB default REST endpoint controller coverage for
  registry-backed post types, post statuses, taxonomies, settings, block types,
  block patterns, and block pattern categories, including context/_fields
  filtering, collection params, permission gates, REST links, invalid values,
  and state restoration.
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
Admin Workflows surface intentionally avoids `admin.php`/`admin-ajax.php` request
dispatch, DB-backed core `WP_*_List_Table` subclasses, and `wp_ajax_*` wrappers
or JSON helpers that call `wp_die()`/`die()` in-process; it covers the base list
table API with synthetic items and referer helpers only where valid nonces or
`stop=false` avoid exits. The
block templates surface short-circuits template CPT queries through
`posts_pre_query` and records the current direct-ID traversal behavior as a
guarded skip while still asserting that file enumeration remains confined. The
`block-widgets` surface exercises `WP_Widget_Block` and sidebars widget option
mapping without loading the browser widgets editor, performing REST persistence,
or depending on theme files. The
Customizer surface intentionally avoids changeset save/publish, nav-menu
persistence, widget persistence, and real post/option storage beyond the
existing no-DB option stub. The `media-editor` surface short-circuits attachment
metadata updates and intentionally avoids media paths that insert attachments,
create cover-image attachments, process audio/video thumbnails, or otherwise
require real postmeta writes. The `multisite` surface leaves `MULTISITE`
disabled for the shared PHP process; true multisite `sitemeta` write paths,
site creation/update/deletion, and DB-backed query execution are skipped unless
short-circuited through filters, while non-multisite network-option CRUD remains
covered by the existing option stub. The Site Health surface avoids loopback,
WordPress.org, REST availability, update download, mail, cron, and
filesystem-writing checks unless they are fully short-circuited. The mail
surface intercepts PHPMailer send calls and never attempts real delivery. The
`rest-controllers` surface intentionally avoids DB-backed object controllers
such as posts, terms, comments, users, revisions, attachments, and templates;
it also records explicit skips for the themes and plugins controllers because
their lifecycle-heavy read and status paths are covered by
`plugin-theme-lifecycle`, while install/update/delete controller methods are
still avoided. Block pattern coverage is registry-backed only:
remote pattern and current-theme pattern loaders are short-circuited.
The `block-editor-adjuncts` surface keeps REST preloading on synthetic
`rest_pre_dispatch` responses and keeps theme styles local to temp fixtures;
it does not load editor screens, dispatch DB-backed REST controllers, fetch
remote editor styles, or render browser UI.
The `plugin-theme-lifecycle` surface uses a process-local temp `wp-content`
tree and generated minimal fixtures only; it does not activate repository
plugins or switch to repository themes. Network-wide activation is not forced
when the shared process is not running with `MULTISITE`; in that mode the
surface verifies sitewide option shapes and the non-multisite false branch.
REST plugin/theme controller coverage is limited to read/status/parameter
paths and avoids install, update, remote lookup, and destructive REST delete
methods.
The `content-lifecycle` surface uses a bounded in-memory `wpdb` stub that
recognizes the narrow SQL shapes emitted by core post, term, user, comment, and
metadata lifecycle APIs; it is not a general SQL engine. Post-to-term
relationship coverage remains limited to simple recognized term relationship
queries, and deeper taxonomy assignment behavior is not treated as fully
covered.
The `auth-flow` surface short-circuits auth cookie sending and avoids browser
redirect/login-form dispatch, real mail, application-password API requests, and
process-exit paths.
The `canonical-routing` surface calls `redirect_canonical()` with
`do_redirect=false`; DB-backed guessed 404 permalink resolution, old-slug
redirects, attachment-page redirects, and paths that call `wp_redirect()` and
`exit` are intentionally avoided.
The `media-ingest` surface uses local temp files only, routes uploads through a
filtered temp upload root, and passes a custom upload action for
`media_handle_upload()` so CLI fixtures use core's readable-file branch instead
of PHP SAPI uploaded-file state. It avoids remote sideload/download helpers,
browser media UI flows, audio/video cover-art generation, and writes outside the
component-fuzz temp root.
The `comment-workflow` surface uses the bounded content/comment rows in the
in-memory `wpdb` stub and avoids notification mail, browser cookie writes, and
`wp_die()` paths by requesting `WP_Error` returns or using non-exiting helpers.
The `feed-rendering` surface renders core feed templates through synthetic
`WP_Query` loops backed by the in-memory `wpdb` stub. It avoids live HTTP
headers, remote enclosures, DB-backed query execution, and arbitrary invalid
bytes so XML structure and escaping remain useful oracles.
The `update-install-upgrader` surface intentionally avoids live package
downloads, real ZIP unpacking into `wp-content/upgrade`, real plugin/theme
activation or switching, full plugin/theme/core update execution, core
`update-core.php` replacement, language-pack updates, automatic updater run
loops, fatal-error loopback checks, and any process-exit paths. It exercises
safe class/helper paths directly and only uses filters to short-circuit network
or external filesystem credentials.
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
