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
- `admin-ajax`: bounded admin-AJAX response helper coverage, including
  captured `wp_die()` handlers, JSON response helpers, `WP_Ajax_Response`
  XML boundaries, nonce/capability failures, selected safe AJAX handlers, and
  superglobal/output-buffer restoration.
- `admin-bar`: no-DB toolbar node lifecycle, default root/submenu binding,
  group/container behavior, render escaping/raw HTML contracts, and
  `show_admin_bar()` filter/global restoration plus default menu hook
  registration.
- `admin-dashboard`: no-live-DB admin dashboard API coverage, including
  dashboard widget registration/control callbacks, meta-box context and
  priority normalization, dashboard container rendering across column counts,
  safe recent draft/comment output helpers, explicit skips for redirect/remote
  paths, and filter/global/superglobal/output-buffer restoration.
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
- `admin-list-tables`: no-live-DB concrete admin list-table subclass coverage
  for posts, media, comments, terms, users, plugins, themes, and guarded
  network sites/users, including columns/hidden/sortable/default-primary logic,
  views, actions, bulk actions, row URL and HTML escaping, pagination/counts,
  synthetic object/pre-query fixtures, capability gates, and state/filter
  restoration.
- `admin-media-chrome`: no-DB admin media chrome helper coverage, including
  attachment edit field preparation, media item and compat markup escaping,
  image form controls, image editor chrome from cache-seeded metadata,
  thumbnail/icon helper filters, and safe media button/uploader bypass output.
- `ai-client`: no-DB WordPress AI Client API coverage for SDK DTO
  round-trips, enum strictness, provider registry isolation, prompt builder
  ability integration, cache and event adapters, and deterministic in-memory
  generation without network calls.
- `assets`: script/style registration lifecycle, dependency ordering, inline
  assets, loading strategies, script modules, and printed tag escaping.
- `script-loader-runtime`: server-side script-loader runtime helpers, including
  default script/style/module registrations, handle normalization, duplicate
  update behavior, inline/localized data placement, tag/settings escaping,
  script translations, emoji settings/styles, style inlining, block-loader
  guards, strategy/fetchpriority/module interactions, and print side-effect
  boundaries.
- `appearance-media`: no-upload appearance media helper coverage, including
  custom background POST normalization, custom header default processing and
  selection, frontend header/background helpers, site icon sizes/meta tags, and
  state restoration without admin upload/AJAX dispatch.
- `auth-flow`: no-DB authentication and session flow coverage, including
  synthetic user rows, username/email/password authentication filters,
  sign-on cookie actions with cookie sending short-circuited, auth cookie
  validation hooks/default parsing, current-user and cookie global restoration,
  and session token lifecycle operations.
- `blocks`: block parser/serializer round trips, optimized block detection,
  dynamic render filters, block type metadata, variations, hooks, style,
  pattern/category, bindings, and supports registries, including dynamic block
  attribute preparation and wrapper attribute merging.
- `block-widgets`: block-backed widget behavior, including `WP_Widget_Block`
  rendering, dynamic legacy class mapping matrix, malformed/unknown block
  fallbacks, content sanitization on update, form escaping, widgets block
  editor support toggles, and sidebars widget mapping for block widget
  instances.
- `bookmark-links`: no-live-DB legacy bookmark/link-manager API coverage,
  including in-memory link rows and link categories for `get_bookmark()`,
  `get_bookmarks()`, `wp_list_bookmarks()` escaping, edit bookmark links,
  bookmark sanitizers, selected deprecated wrappers, safe link CRUD, argument
  filtering, ordering, limits, visibility, ratings, and bookmark caches.
- `block-templates`: no-DB block template and block theme resolution coverage,
  including template registry lifecycle, file-backed templates and parts,
  parent/child theme precedence, theme.json metadata, hierarchy resolution, and
  malformed filename/path guards with template CPT queries short-circuited.
- `block-editor-adjuncts`: no-DB block editor adjunct API coverage, including
  editor context objects, category and allowed-block filters, legacy widget and
  merged editor settings, local theme style helpers, iframe asset collection,
  REST preload path normalization with dispatch short-circuited, and global
  restoration.
- `capabilities`: no-DB role registry lifecycle and mutation idempotence,
  numeric/associative and boundary capability grants, `WP_User` role/direct cap
  aggregation and mutators, role and user capability filter locality, generated
  `map_meta_cap()` filter contexts, cheap meta-cap mappings, and primitive/meta
  cap monotonicity.
- `canonical-routing`: no-DB canonical redirect and front-end routing helpers,
  including method/search/preview bailouts, host/path/query cleanup, invalid
  date redirects, feed/pagination canonicalization, redirect filter
  cancellation, fragment stripping, and query-argument removal contracts.
- `classic-walkers`: deterministic Walker base and classic walker coverage,
  including `walk()`, `paged_walk()`, direct `display_element()` traversal,
  page/category/comment/nav rendering, current/selected classes, admin nav menu
  checklist/edit field names, bounded HTML balance, escaping contracts, and
  global/filter/superglobal/output-buffer restoration.
- `content`: slashing, metadata serialization, post and term field sanitization,
  query variables, `WP_Date_Query`, title/class/key sanitizers.
- `content-lifecycle`: in-memory wpdb-backed post, term, user, and comment CRUD
  lifecycles, including insert/update/read/delete round trips, duplicate and
  invalid-input errors, sanitizer agreement, monotonic IDs, cheap hook ordering,
  cache/count refresh behavior, and per-iteration state restoration.
- `comments`: comment filtering, sanitizer agreement, max-length boundaries,
  type partitioning, comment classes, author URL/email links, excerpt/text
  helpers, and comment cookies.
- `community-events`: no-network Community Events API client coverage,
  including IP header selection and anonymization, minimal request bodies,
  transient key/cache behavior, event trimming and WordCamp pinning, response
  normalization, and API error contracts.
- `comment-workflow`: in-memory comment submission, duplicate/flood approval
  decisions, moderation short-circuits, update/status transition hooks,
  trash/untrash and spam/unspam restoration, and WP_Error failure paths without
  process exits.
- `cron`: in-memory cron scheduling, recurrence lookup, schedule/unschedule
  and next-scheduled filter contracts, duplicate single-event windows,
  ready-job partitioning, unscheduling and rescheduling contracts.
- `default-widgets`: classic default widget subclass coverage, including
  constructor/options contracts, update sanitization, form escaping, filtered
  rendering for text/custom HTML/search/meta/list widgets, cache-backed
  calendar/archive output, and local RSS fixtures without network requests.
- `customizer`: no-DB Customizer API coverage for manager registry lifecycles,
  setting sanitize/validate/post value flows, multidimensional option previewing,
  container/control JSON exports, active callbacks, and selective refresh partial
  registration/rendering without changeset persistence.
- `customizer-persistence`: no-live-DB Customizer persistence coverage for
  changeset UUID/data normalization, stub-backed `customize_changeset` post
  content parsing, transactional changeset saves, Custom CSS setting
  validate/sanitize/preview/update behavior, custom CSS post filters, and
  global/superglobal restoration.
- `date-time`: deterministic no-DB date/time helper coverage, including
  `wp_date()`/`DateTimeImmutable` agreement, `date_i18n()` and `mysql2date()`
  timestamp oracles, timezone option filters, GMT/local round trips, ISO8601
  offset parsing and datetime conversion, week windows, `current_time()`,
  `current_datetime()`, timezone override offsets, date/human diff filter
  contracts, and safe human time diffs.
- `discovery`: robots meta directives, sitemap enablement and robots.txt
  injection, provider registration/replacement filters, query/permalink sitemap
  URL/index expansion, escaped sitemap XML rendering, and sitemap max-URL
  filters without DB-backed providers.
- `email`: Unicode email validation/sanitization, ASCII fallback filters,
  WHATWG-style validity, `WP_Email_Address` machine/readable and IDN/punycode
  views, invalid UTF-8, malformed address structure, selected boundary lengths,
  normalization-sensitive local parts, comment-author email filtering, and user
  email lookup/duplicate behavior for accent-distinct local parts/domains plus
  password-reset Unicode recipient paths.
- `environment-load`: no-network environment/load/compat helper coverage,
  including environment type cache boundaries, server/request normalization,
  Basic Auth and SSL detection, memory-limit parsing, ini mutability,
  installing/maintenance flags, request guard filters, HTTPS migration
  short-circuits, and UTF-8 compatibility oracles with state restoration.
- `error-protection`: no-shutdown error protection and recovery-mode
  infrastructure coverage, including paused-extension source normalization and
  storage, recovery key/cookie validation, recovery-link generation, filtered
  recovery email payloads without real mail, fatal-error handler formatting
  oracles, protected-endpoint gates, and explicit skips for redirects,
  loopbacks, real fatal dispatch, and process exits.
- `editor-helpers`: no-browser classic editor helper coverage for
  `_WP_Editors` settings/state normalization, default editor selection filters,
  teeny and full TinyMCE/Quicktags filter branches, captured editor markup,
  TinyMCE translation snippets, media-view stylesheet helpers, and global
  restoration.
- `fonts`: font-face CSS serialization and validation, font directory filters,
  Font Library collection registration/JSON loading, and font utility
  sanitization for family lists, face slugs, schemas, and MIME maps.
- `filesystem`: path normalization and joining, file validation classes,
  filename sanitization/uniqueness, temp names, direct filesystem sandboxing.
- `formatting`: escaping helpers, text sanitizers, whitespace normalization,
  autop/shortcode cleanup, clickable text, URL sanitization, entity
  normalization, colors, sizes, time strings, UTF-8 helpers, and accent removal.
- `feed-parsers`: local RSS/Atom parser and legacy feed utility API coverage,
  including bounded malformed fixtures, Magpie item/channel normalization,
  AtomParser local-file behavior, SimplePie raw-data parsing and KSES
  sanitization, transient-backed feed cache boundaries, date/status helpers,
  local file adapter guards, no-network assertions, and state restoration.
- `feed-rendering`: no-DB RSS2, Atom, and comments RSS2 feed template rendering
  over synthetic query loops, including feed item/entry counts, self links,
  CDATA terminator escaping, excerpt/content mode switches, enclosure metadata,
  comment feed escaping, and feed build date selection.
- `frontend-features`: no-DB frontend feature helper coverage for speculative
  loading and view transitions, including configuration eligibility,
  mode/eagerness filters, generated URL-pattern exclusions, script tag escaping,
  theme support behavior, view-transition CSS enqueueing, and global restoration.
- `html-api`: HTML tag and tree processor mutation escaping, deterministic
  rich HTML generation, normalization/recovery idempotence, token walking,
  bookmark/seek replay, modifiable text escaping, and namespace/comment/rawtext
  boundary checks.
- `http`: synthetic HTTP response arrays, request wrapper dispatch,
  response objects, header/cookie parsing, proxy decisions, redirect safety,
  URL validation, and relative URL resolution without live network requests.
- `icons-connectors`: no-DB Icons and Connectors API coverage, including
  connector registry lifecycle and init discovery, settings/REST key masking,
  script module serialization, icon manifest/search behavior, SVG sanitization
  and file caching, REST icons schema/permission/error contracts, and state
  restoration.
- `images`: image constraint and resize math, synthetic intermediate metadata,
  metadata dimension lookup, responsive `srcset`/`sizes` generation and filter
  boundaries, attachment image helpers and attribute filters, image tag
  attribute insertion, loading optimization attributes, and image
  filetype/extension helpers.
- `image-metadata`: local admin image metadata parser coverage over generated
  bounded JPEG/TIFF/PNG byte fixtures, including `wp_read_image_metadata()`
  malformed-file behavior, EXIF/IPTC field extraction and sanitization when PHP
  extensions are available, XMP alt text extraction, EXIF helper normalization,
  image metadata filters, temp-file cleanup, and state restoration.
- `identity`: usernames, emails, capabilities, text/comment filters, comment
  cookies, options, password hashing/checking, parse helpers.
- `import-diff`: importer registry and upload-form helpers, `WP_Importer`
  imported comment lookup against the in-memory stub, `wp_text_diff()`
  rendering/escaping/normalization, and `WP_Error` export/merge/remove
  transfer semantics.
- `install-schema`: no-DB install and upgrade schema coverage, including
  `wp_get_db_schema()` table sets, `dbDelta()` CREATE TABLE parsing,
  equivalent-schema no-ops, isolated column/index diffs, SQL table allowlists,
  and malformed DDL fail-closed behavior.
- `interactivity`: server-side directive processing for context, bind, class,
  style, text, and each directives, explicit namespace/negation/length
  evaluation, script-module router metadata, unsupported/unbalanced HTML
  fallbacks, derived context/element helpers, and state/config merge
  serialization.
- `hooks`: filter/action priority ordering, accepted arguments, removal,
  nested hook stack state, `current_filter()`, `doing_filter()`, `did_action()`.
- `kses`: KSES policies, wrapper agreement, protocol filtering, safe CSS,
  attribute/entity/comment handling, no-HTML filtering, custom context locality,
  strict/custom policy monotonicity, and filter/global restoration.
- `l10n`: translation fallbacks, escaped translation helpers, plural/nooped
  selection, textdomain load/unload state, translation path guards, locale and
  user-locale switching, script translation helpers, malformed string
  boundaries, localized numbers/dates, and filter/action restoration.
- `translations`: no-DB POMO and translation-file parsing/loading coverage,
  including generated `Translation_Entry` lookup and merge behavior,
  `NOOP_Translations` identity contracts, MO/PO/PHP translation file round
  trips, explicit plural-rule oracles, textdomain load/unload cycles, helper
  agreement with loaded domain entries, malformed-file closed failures, and
  state restoration across `$l10n`, `$l10n_unloaded`, registry, and controller
  globals.
- `mail`: no-delivery `wp_mail()` composition coverage, including argument
  filters, pre-send short-circuiting, PHPMailer recipient/header/content
  handoff, array and string header parsing, newline-delimited attachments and
  embeds, reusable mailer cleanup/reset behavior, success/failure actions, and
  emoji email body staticization.
- `markup`: block parse/serialize/render guards, shortcodes, text trimming,
  excerpts, balanced tags, URL extraction, embed helpers.
- `media-editor`: no-DB media image editor coverage for editor selection,
  GD/Imagick availability, output format filters, resize/save metadata,
  intermediate and generated sub-sizes, and cache/filter-backed attachment
  metadata helpers with temp-file cleanup.
- `media-ingest`: no-network media upload and sideload ingest coverage over
  generated temp fixtures, including upload directory filters, MIME/filetype
  boundaries, sanitized unique filenames, direct handle prefilter/move hooks,
  attachment row and postmeta creation in the in-memory wpdb stub, download
  short-circuit cleanup, metadata update failure paths, and cleanup restoration.
- `media-metadata`: local audio/video metadata parser coverage over generated
  bounded byte fixtures, including `wp_read_audio_metadata()` and
  `wp_read_video_metadata()` malformed-file behavior, ID3 tag helper
  sanitization, creation timestamp extraction, audio/video extension and ID3 key
  filters, `wp_attachment_is()` MIME/extension branches, and
  `wp_generate_attachment_metadata()` audio/video cover-art avoidance.
- `media-remote`: no-live-network remote media helper coverage for
  `download_url()`, `media_sideload_image()`, and selected
  `media_handle_sideload()` branches, including HTTP short-circuit fixtures,
  Content-Disposition filename sanitization, URL extension/MIME boundaries,
  temp-file cleanup, size/type rejection, filter locality, and global
  restoration.
- `metadata`: no-DB Metadata API registration, subtype visibility, defaults,
  registration argument edges, legacy callbacks, sanitize/auth/protected-meta
  filters, cache-backed lookup shape, filtered and in-memory CRUD cache
  invalidation, mid-row helpers, cache priming, and lazyloader queue/reset
  behavior.
- `multisite`: no-DB multisite/network API coverage, including synthetic
  `WP_Site` and `WP_Network` objects, site data normalization, cache-backed
  lookups, blog-switch stack/cache restoration, filter-backed network option
  reads, stub-backed network option CRUD, pre-query-short-circuited site/network
  queries, and current/switched URL helpers.
- `navigation`: nav menu location registration, theme menu assignment lookup,
  menu object and item setup filters, current-item class derivation, walker
  output, depth pruning, short-circuit/fallback behavior, container allowlists,
  attribute filter escaping, and filtered no-DB `wp_nav_menu()` rendering.
- `network-media`: URL parsing/sanitization/validation, path normalization,
  filename sanitization, filetype checks, unique filenames, sideload handling,
  multisite upload quota, remaining-space, size-limit, and over-quota helpers.
- `options-autoload`: no-DB option CRUD, cache, autoload, and filter coverage
  for generated option values, including alloptions membership, notoptions
  transitions, raw serialized cache shape, default/pre/update filters, cache
  priming stability, bulk autoload mutators, lifecycle action payload ordering,
  and safe option-name boundary cases.
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
- `utility-internals`: no-DB low-level utility coverage for `WP_List_Util`,
  list helper wrappers, `WP_Token_Map`, `WP_MatchesMapRegex`, and
  `WP_URL_Pattern_Prefixer`, including reference filter/pluck/sort oracles,
  token lookup/precomputed table round trips, rewrite match substitution,
  URL-pattern prefix escaping/idempotence boundaries, and state restoration.
- `user-preferences`: no-request-dispatch admin UI preference coverage,
  including sanitized user-setting/admin-color serialization, hidden column and
  meta-box preference defaults/saved values, postbox order/classes, screen
  option registration/rendering, filter locality, and state restoration without
  redirecting or dying request handlers.
- `post-types`: post type and post status registry defaults, support feature
  registration, capability generation, query/archive normalization, unregister
  cleanup, archive/feed link filters, and status filtering.
- `privacy`: no-DB user request and privacy helper coverage, including
  synthetic `WP_User_Request` objects, request lifecycle helpers, action and
  confirmation descriptions, request-key hash/expiration validation, export
  group HTML escaping, exporter/eraser registry and processor shape contracts,
  export directory/expiration cleanup filters, anonymization helpers, and
  privacy policy suggestion/default text without mail or network delivery.
- `query`: no-DB query builder and execution APIs, including meta/tax/date
  query tree sanitization, SQL fragment generation, relation normalization,
  query-var parsing, `WP_Query` SQL execution shape, and deterministic global
  restoration.
- `query-loop`: no-DB `WP_Query` execution and loop-state coverage through
  `posts_pre_query` fixtures, including loop wrapper delegation,
  setup/reset postdata globals, single/page flag behavior, found/max-page coherence,
  empty-result events, and deterministic state restoration.
- `registries`: no-DB modern registry coverage for connectors, icons, and
  speculation rules, including lifecycle validation, helper oracles, manifest
  sanitization/caching, allowlist behavior, rule serialization, and state
  restoration.
- `rest`: request normalization, parameter precedence, JSON bodies, route regexes,
  schema sanitize/validate, permissions, HEAD/GET behavior.
- `request-lifecycle`: no-DB front-controller lifecycle coverage for
  `WP::parse_request()`, rewrite/pathinfo/index matching, public/private
  query-var gates, query-var precedence, `register_globals()`, `handle_404()`
  status transitions, and `send_headers()` filters/actions with deterministic
  global restoration.
- `rest-controllers`: no-DB default REST endpoint controller coverage for
  registry-backed post types, post statuses, taxonomies, settings, block types,
  block patterns, and block pattern categories, including context/_fields
  filtering, registered additional-field get/update/schema callbacks,
  collection params, permission gates, REST links, invalid values, and state
  restoration.
- `rest-object-controllers`: in-memory wpdb-backed REST object controller
  coverage for posts, terms, comments, users, revisions, and attachments,
  including schema/context/_fields filtering, collection-param sanitization,
  permission gates, REST links, invalid IDs/types, sanitized content/meta
  fields, safe create/update/delete error paths, upload-no-data paths, and
  deterministic state restoration without live uploads, remote requests, or a
  live database.
- `rest-site-editor`: no-live-DB Site Editor REST controller coverage for
  global styles, template/template-part response shaping, template revisions
  and autosaves, navigation fallback, and edit-site export guards, including
  route normalization, schema/context behavior, permission and error contracts,
  custom CSS validation, temp theme fixtures, and state restoration.
- `revisions-autosaves`: in-memory wpdb-backed revision and autosave API
  coverage, including protected revision field/filter contracts, autosave
  create/update/delete and post-lock behavior, autosave and revision predicates,
  revision insert/save/restore/delete helpers, revisioned meta copy and restore
  behavior, post type support gates, revision title/list helpers, revision UI
  diffs, JS payload preparation, preview overlay behavior, and global/filter
  restoration.
- `rewrite`: rewrite tags, permastruct/rule generation, collision ordering,
  endpoint expansion and mask propagation, match substitution, query arg and
  build/parse helpers, URL parsing, home/site URL helpers, weird path fragments,
  and cheap no-DB `url_to_postid()` paths.
- `security`: salts and HMACs, password and fast-hash verification, nonce
  generation/verification, nonce URLs and hidden fields, admin/ajax referer
  paths, synthetic auth cookies and session tokens, redirect sanitization, and
  safe redirect filters.
- `shortcodes`: no-DB shortcode registry lifecycle, attribute parsing/default
  merging and dynamic filters, invalid registration and non-callable callback
  guards, callback argument and rendering filter contracts, escaped and
  HTML-attribute rendering, tag discovery, apply aliasing, stripping and strip
  filters, presence checks, malformed inputs, and exact global restoration.
- `site-health`: no-DB Site Health/update/HTTPS helper coverage, including
  generated update transients and dismissed core update options, aggregate
  update counts/titles, HTTPS option booleans, migration replacement, HTTPS
  detection short-circuits, and selected direct `WP_Site_Health` tests without
  remote requests.
- `site-health-debug`: bounded `WP_Debug_Data` coverage for Site Health Info
  formatting and database-size helpers, including private field/section
  suppression, debug vs info labels, `debug_information` filter locality,
  scoped `SHOW TABLE STATUS` and `SHOW VARIABLES` wpdb doubles, MySQL variable
  lookup fallbacks, and explicit skips for unsafe full debug-data scans.
- `state`: object cache groups and multi-operations, option, transient, and
  cache-backed site-transient APIs, filters, serialization, JSON, and value
  helpers.
- `style`: style engine serialization, preset/classname and CSS variable
  boundaries, CSS declaration filtering, theme.json schema/data merging and
  variable resolution, block style variation declarations, selector/path
  helpers, scoped editor style helpers, custom properties, and no-DB global
  style guards.
- `syndication`: oEmbed provider registration, embed handler lifecycle, oEmbed
  wildcard/regex matching, cache-key lookup, no-network fetch short-circuits,
  HTML/XML filtering, feed metadata escaping, default feed normalization, self
  links, and Atom text construction.
- `taxonomy`: taxonomy registration lifecycle, object-type associations,
  registry query consistency, argument/callback/default-term normalization,
  query-var and rewrite side-effect boundaries, term sanitization and field
  filters, slug/name normalization, synthetic `WP_Term` behavior, hierarchy
  helper edge cases, and cheap term-link paths.
- `taxonomy-relationships`: in-memory wpdb-backed object/term assignment
  coverage, including `wp_set_object_terms()` replace/append behavior,
  field-variant agreement, scoped removal, membership/object lookup helpers,
  invalid-input fail-closed paths, and `get_the_terms()` relationship cache
  population/invalidation.
- `template-hierarchy`: no-DB classic PHP template hierarchy coverage,
  including child/parent lookup priority, query-template filters, direct
  archive/page/search/404/embed helper filters and path confinement,
  stylesheet/template root precedence, single template ordering,
  `load_template()` include semantics, template-part hooks and args, and
  guarded comments-template state handling.
- `template-links`: no-DB public template and link helpers, including body and
  language attributes, document title stability, resource hints/preloads,
  pagination/search/feed/site/admin URLs, synthetic post preview/edit/delete/
  shortlink/permalink helpers, and cached bookmark field/list rendering.
- `widgets`: classic sidebar registry lifecycle, widget factory instance
  registration, direct widget/control callbacks, generated sidebars, widget ID
  parsing, sidebar option cache/filter behavior, sidebar assignment
  moves/removals, inactive widget placement, render callback wrappers,
  no-external-DB guards, and registry restoration.
- `wpdb-sql`: no-connection real `wpdb` SQL formatting coverage, including
  placeholder count/type handling, `%i` identifier containment, literal percent
  and LIKE escaping, malformed placeholders, and captured insert/update/delete/
  replace builder SQL shape.
- `wxr-export`: subprocess-isolated WXR export coverage over deterministic
  synthetic posts, terms, authors, comments, and meta, including export
  argument filtering, XML/CDATA/UTF-8 safety, meta skip filters, author and term
  ordering, header observability, and state restoration.
- `xmlrpc`: no-DB IXR/XML-RPC protocol coverage, including value escaping,
  request/message round trips, invalid XML fail-closed behavior, fault XML,
  system method dispatch, method registry filters, demo helpers, disabled
  login behavior, legacy post title/category XML helpers, and short-circuited
  `WP_HTTP_IXR_Client` transport/error handling without publishing, media,
  pingback, option, or live network side effects.

Some checks deliberately skip cases that would invoke DB-backed or dynamic block
rendering side effects. The Admin Screen surface intentionally avoids admin page
submission, `options.php` writes, user preference persistence, real post objects,
and block-editor compatibility shims that would inspect installed plugins. The
`script-loader-runtime` surface complements `assets` by covering server-side
runtime helpers in `script-loader.php`, `functions.wp-scripts.php`, and
`functions.wp-styles.php` without browser execution. It directly calls the emoji
detection printer instead of the public static-once wrapper so iterations remain
isolated, and it records an explicit skip for just-in-time autosave localization
when the stripped no-DB bootstrap does not define `AUTOSAVE_INTERVAL`. It avoids
admin/page dispatch, process exits, live HTTP, live DB-backed block queries, and
browser module execution while still asserting restored globals, filters, and
output buffers. The
Admin Workflows surface intentionally avoids `admin.php`/`admin-ajax.php` request
dispatch, DB-backed core `WP_*_List_Table` subclasses, and `wp_ajax_*` wrappers
or JSON helpers that call `wp_die()`/`die()` in-process; it covers the base list
table API with synthetic items and referer helpers only where valid nonces or
`stop=false` avoid exits. The `admin-list-tables` surface complements that base
coverage by loading concrete `WP_*_List_Table` subclasses with synthetic rows,
object-cache fixtures, temporary plugin/theme metadata, and `posts_pre_query`,
`comments_pre_query`, `terms_pre_query`, `users_pre_query`, and
`sites_pre_query` short-circuits. It intentionally skips full admin dispatch,
privacy request tables, install/update tables, destructive plugin/theme
operations, real uploads, and true multisite write paths; network site/user
rows remain synthetic when the shared PHP process is not in multisite mode. The
`appearance-media` surface covers custom background/header/site icon helpers
without invoking media uploads, image crops, AJAX actions, or admin page
dispatch. The
block templates surface short-circuits template CPT queries through
`posts_pre_query` and records the current direct-ID traversal behavior as a
guarded skip while still asserting that file enumeration remains confined. The
`block-widgets` surface exercises `WP_Widget_Block` and sidebars widget option
mapping without loading the browser widgets editor, performing REST persistence,
or depending on theme files. The
Admin Media Chrome surface intentionally avoids upload dispatch, real
attachments created by browser flows, `wp_media_attach_action()` redirects,
AJAX image-editor actions, and media modal runtime behavior; it covers direct
server-side helpers with synthetic attachment rows and cache/filter-backed
metadata only. The
Customizer surface intentionally avoids changeset save/publish, nav-menu
persistence, widget persistence, and real post/option storage beyond the
existing no-DB option stub. The `options-autoload` surface uses that same
bounded in-memory option table and object cache, and deliberately focuses on
core option/autoload/cache/hook semantics rather than settings-page submission,
network options, transients, or arbitrary SQL support. The `media-editor`
surface short-circuits attachment
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
`media-metadata` surface uses malformed local fixtures and cache-seeded
attachments only; it does not download remote media, invoke codecs or external
binaries, insert real attachments, or enable audio/video cover attachment
generation. The
`image-metadata` surface complements that audio/video coverage with generated
local image byte fixtures only. It does not invoke image codecs, live uploads,
remote media, or attachment persistence; EXIF/IPTC extraction rows are recorded
as explicit skips when the PHP build lacks `exif_read_data()` or `iptcparse()`,
while malformed/no-metadata image paths and filter cleanup still run. The
`rest-controllers` surface remains registry-backed only. DB-backed posts,
terms, comments, users, revisions, and attachments are covered by
`rest-object-controllers` against the in-memory `wpdb` stub. That object
surface records explicit skip rows for template controllers that depend on
block-theme filesystem state and template CPT queries, and for broad collection
queries that exceed the small SQL parser in the stub. It documents limits for
real upload/sideload paths and invalid enum-error formatting branches that are
not warning-safe under the stripped bootstrap. The registry-backed surface also
records explicit skips for the themes and plugins controllers because their
lifecycle-heavy read and status paths are covered by `plugin-theme-lifecycle`,
while install/update/delete controller methods are still avoided. Block pattern
coverage is registry-backed only:
remote pattern and current-theme pattern loaders are short-circuited.
The `rest-site-editor` surface creates a bounded temp theme under the harness
`WP_CONTENT_DIR` and uses the in-memory `wpdb` stub for `wp_global_styles`,
`wp_template`, `wp_template_part`, `revision`, and `wp_navigation` fixtures. It
does not dispatch live REST requests, run broad template collection queries, or
call `WP_REST_Edit_Site_Export_Controller::export()` because that path
generates, streams, unlinks, and exits with a zip file; export coverage is
limited to route and permission guards.
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
metadata lifecycle APIs; it is not a general SQL engine. Rich post-to-term
relationship behavior is covered by `taxonomy-relationships`, still limited to
the recognized term relationship SQL shapes emitted by the targeted core APIs.
The `bookmark-links` surface extends that stub only for the `wp_links` shapes
emitted by bookmark APIs and safe link CRUD: ID lookups, visibility/search,
include/exclude/category joins through `link_category`, supported order/limit
clauses, and `link_id` projections. It intentionally does not emulate arbitrary
link-manager SQL, admin page dispatch, or live database behavior.
The `auth-flow` surface short-circuits auth cookie sending and avoids browser
redirect/login-form dispatch, real mail, application-password API requests, and
process-exit paths.
The `canonical-routing` surface calls `redirect_canonical()` with
`do_redirect=false`; DB-backed guessed 404 permalink resolution, old-slug
redirects, attachment-page redirects, and paths that call `wp_redirect()` and
`exit` are intentionally avoided.
The `classic-walkers` surface uses synthetic objects, object-cache fixtures, and
local filters only. It loads the single comment/admin walker class files when
available, but avoids nav menu AJAX quick-search, meta-box pagination, browser
admin page dispatch, and any DB-backed menu/page/category/comment queries.
The `import-diff` surface covers importer registry, importer form, imported
comment lookup, text diff, and WP_Error transfer/lifecycle helpers without
remote importer discovery, upload handling, or importer dispatch screens. WXR
download generation is covered separately by `wxr-export`.
The `wxr-export` surface invokes actual `export_wp()` once per isolated PHP
subprocess because core defines `wxr_*` helper functions inside that function.
It uses a surface-local in-memory `wpdb` double and deterministic fixtures only;
it does not use a live database, contact the network, or write download files.
PHP CLI usually does not expose `header()` calls through `headers_list()`, so
filename/content-type checks are asserted when observable and otherwise recorded
as explicit skips while the filename filter invocation remains captured.
The `media-ingest` surface uses local temp files only, routes uploads through a
filtered temp upload root, and passes a custom upload action for
`media_handle_upload()` so CLI fixtures use core's readable-file branch instead
of PHP SAPI uploaded-file state. It exercises direct `wp_handle_*()` hooks and
`download_url()` only through local files or `pre_http_request` short-circuits.
It avoids browser media UI flows, audio/video cover-art generation, and writes
outside the component-fuzz temp root.
The `media-remote` surface complements that local ingest coverage by exercising
remote download and sideload helpers with `pre_http_request` fixtures only. It
records every streamed temp filename observed by the HTTP short-circuit,
removes returned temp files, routes successful sideloads through a temp upload
root, and asserts that its HTTP, upload, extension, and error-body filters are
removed after each check. It never lets unregistered remote URLs fall through to
the live HTTP transport.
The `comment-workflow` surface uses the bounded content/comment rows in the
in-memory `wpdb` stub and avoids notification mail, browser cookie writes, and
`wp_die()` paths by requesting `WP_Error` returns or using non-exiting helpers.
The `revisions-autosaves` surface uses the bounded post and postmeta rows in
the in-memory `wpdb` stub. It exercises autosave creation/update/delete,
post-lock windows, protected revision field filters, revision title/list
helpers, post type support gates, and restore action/edit-user side effects
without browser dispatch. It records explicit skips for
`wp_get_latest_revision_id_and_total_count()` and `wp_get_post_revisions_url()`
because the stub does not emulate the `WP_Query` `found_posts` count SQL shape
for posts. User-specific `wp_get_post_autosave()` lookup is also skipped
because the stub does not emulate post author filtering in `WP_Query`. The
surface avoids browser/admin-template or request-dispatch helpers such as
`_show_post_preview()` and `wp_print_revision_templates()`.
The `feed-rendering` surface renders core feed templates through synthetic
`WP_Query` loops backed by the in-memory `wpdb` stub. It avoids live HTTP
headers, remote enclosures, DB-backed query execution, and arbitrary invalid
bytes so XML structure and escaping remain useful oracles.
The `community-events` surface short-circuits `wp_remote_get()` through
`pre_http_request`; it never contacts api.wordpress.org and limits coverage to
request construction, response normalization, cache behavior, and local helper
contracts rather than dashboard browser rendering.
The `editor-helpers` surface exercises classic editor settings and generated
markup without loading browser editors. It avoids live TinyMCE/Quicktags
execution, external asset fetching, admin page dispatch, DB-backed link queries,
and AJAX media-shortcode preview paths.
The `user-preferences` surface avoids `set_screen_options()` and AJAX
preference handlers because they redirect or call `wp_die()` in-process; it
covers the underlying user-setting and screen preference helpers directly.
The `update-install-upgrader` surface intentionally avoids live package
downloads, real ZIP unpacking into `wp-content/upgrade`, real plugin/theme
activation or switching, full plugin/theme/core update execution, core
`update-core.php` replacement, language-pack updates, automatic updater run
loops, fatal-error loopback checks, and any process-exit paths. It exercises
safe class/helper paths directly and only uses filters to short-circuit network
or external filesystem credentials.
The `utility-internals` surface focuses on deterministic pure-PHP helpers and
does not replace higher-level rewrite, frontend-feature, or REST coverage that
uses the same classes incidentally. Case-insensitive token-map assertions avoid
known ambiguous overlapping-token inputs and keep exact lookup coverage over the
full generated mapping.
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
