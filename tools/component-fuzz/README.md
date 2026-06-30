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
  schema validation, custom subclass execution, permission checks, execution,
  query pipeline ordering, validation filters, callback exception handling, and
  unregister/re-register behavior.
- `account-security`: no-DB account recovery and security APIs, including
  application password lifecycle/hash/authentication behavior, password-reset
  key lifecycle validation, recovery key/cookie validation, and paused
  extension storage transitions.
- `admin-ajax`: bounded admin-AJAX response helper coverage, including
  captured `wp_die()` handlers, JSON response helpers, `WP_Ajax_Response`
  XML boundaries, nonce/capability failures, Heartbeat nonce hook branches,
  selected safe AJAX handlers, attachment query/save workflows,
  compression-test capability/body branches, and superglobal/output-buffer
  restoration.
- `admin-bar`: no-DB toolbar node lifecycle, default root/submenu binding,
  group/container behavior, render escaping/raw HTML contracts, and
  back-compat parent alias and tabindex rendering contracts, initialization
  hook/theme-support side effects, `show_admin_bar()` filter/global
  restoration, default menu hook registration, and default callback-produced
  toolbar node graphs for WordPress logo, account, appearance, comments,
  search, and secondary groups.
- `admin-dashboard`: no-live-DB admin dashboard API coverage, including
  dashboard widget registration/control callbacks, meta-box context and
  priority normalization, dashboard container rendering across column counts,
  safe recent draft/post/comment output helpers, activity post query
  argument/link branches, cached RSS loading/AJAX/cache replay branches,
  Browser Happy remote/cache failure and rendering branches, direct
  `wp_dashboard_setup()` GET registration across site/network/user dashboard
  hooks, and filter/global/superglobal/output-buffer restoration.
- `admin-edit-metaboxes`: no-live-DB classic edit-screen meta box callback
  coverage, including publish-box status/visibility/action branches, flat and
  hierarchical taxonomy boxes with capability gates, excerpt/trackback/custom
  field/comment/slug helpers, page attributes, post formats, attachment submit
  and ID3 metadata boxes, link target/XFN/advanced/submit boxes, default
  `register_and_do_post_meta_boxes()` box registration, hook payloads, context
  ordering, and state restoration.
- `admin-screen`: no-DB admin screen, settings, and meta-box APIs, including
  `WP_Screen` normalization/current-screen globals, help tabs and screen
  options, rendered per-page/layout controls, screen meta/help sidebar and
  screen-reader content lifecycles, column header filter locality, settings
  registry/default/sanitize callbacks, escaped settings field and nonce output,
  meta-box ordering/removal/callback args, and accordion section rendering.
- `admin-workflows`: no-DB admin menu, list-table, and referer-helper
  workflows, including menu/submenu global registration and removal, hook suffix
  and menu URL behavior, parent file normalization, synthetic `WP_List_Table`
  pagination/columns/views/bulk actions/row actions/tablenav rendering, direct
  bulk-action/month-dropdown helper contracts, safe admin/AJAX nonce checks, and
  captured date/time AJAX format wrappers without process exits.
- `admin-list-tables`: no-live-DB concrete admin list-table subclass coverage
  for posts, media, comments, terms, users, plugins, plugin install search
  results, themes, selected-mode theme install API results, network themes,
  application passwords, and guarded network sites/users, including columns/
  hidden/sortable/default-primary logic, views, actions, bulk actions, exact row
  URL/nonce and HTML escaping, base `WP_List_Table` pagination/per-page output,
  pagination/counts, synthetic object/pre-query/user-meta/plugin/theme fixtures,
  capability gates, install/update/activate action rendering, screenshot/icon
  and description escaping, localized update-count and theme-root transient
  cleanup, JS row templates, and state/filter restoration.
- `admin-media-chrome`: no-DB admin media chrome helper coverage, including
  attachment edit field preparation, media item and compat markup escaping,
  image form controls, image editor chrome from cache-seeded metadata,
  edit attachment details form output, thumbnail/icon helper filters, direct
  caption/send-to-editor helper output, legacy upload tab/header/form shell
  hooks, media-view enqueue settings/string contracts, in-process iframe shell
  rendering, and safe media button/uploader bypass output.
- `admin-options-submission`: no-DB `wp-admin/options.php` update-flow
  coverage, including registered Settings API allowlists and sanitize
  callbacks, settings error transients, General Settings date/time/timezone
  branches, legacy `page_options` submissions, nonce/capability/unknown-page
  failure paths, redirect capture, and global/filter/option restoration without
  process exits.
- `ai-client`: no-DB WordPress AI Client API coverage for SDK DTO
  round-trips, enum strictness, provider registry isolation, model-selection
  preferences across provider/model collisions, prompt builder ability
  integration, cache and event adapters, deterministic in-memory generation,
  HTTPlug discovery of the WordPress HTTP adapter, PSR-7 to WordPress HTTP
  argument mapping, SDK request option merging, response body edge cases, and
  transport error propagation without network calls.
- `assets`: script/style registration lifecycle, dependency ordering, inline
  assets, style add-data output metadata, loading strategies, scoped loader-tag
  filters, script modules, and printed tag escaping.
- `script-loader-runtime`: server-side script-loader runtime helpers, including
  default script/style/module registrations, handle normalization, duplicate
  update behavior, inline/localized data placement, concatenated
  load-scripts.php/load-styles.php URL construction and exclusion boundaries,
  tag/settings escaping, script translations, emoji settings/styles, style
  JIT localization with isolated `AUTOSAVE_INTERVAL` coverage, style
  inlining, block-loader guards, strategy/fetchpriority/module interactions,
  generated classic-script module import-map/modulepreload graphs, and print
  side-effect boundaries.
- `appearance-media`: no-upload appearance media helper coverage, including
  custom background POST normalization, custom header default processing and
  selection, frontend header/background helpers, custom header video markup and
  settings, synthetic custom-logo attachment markup/filter contracts, site icon
  sizes/meta tags, and state restoration without admin upload/AJAX dispatch.
- `auth-flow`: no-DB authentication and session flow coverage, including
  synthetic user rows, username/email/password authentication filters,
  sign-on and clear-auth-cookie actions with cookie sending short-circuited,
  generated auth-cookie scheme boundaries and filter payloads, auth cookie
  validation hooks/default parsing, current-user and cookie global restoration,
  and session token lifecycle operations.
- `blocks`: block parser/serializer round trips, optimized block detection,
  dynamic render filters, render-time block bindings, block type metadata,
  variations, block hook insertion and ignored metadata, style,
  pattern/category, bindings, and supports registries, including dynamic block
  attribute preparation and wrapper attribute merging.
- `block-supports`: no-DB core block-support lifecycle coverage, including
  `WP_Block_Supports` registration and wrapper merging, auto-generated control
  markers, direct support callbacks, skip-serialization gates, background,
  dimensions, visibility, position, layout, elements, custom CSS, state-style,
  and helper-matrix render behavior, safe stored CSS, and registry/global/style
  store restoration.
- `core-block-render`: no-DB direct render-callback coverage for representative
  dynamic core blocks, including site title/tagline option handling, search
  label/query/placeholder escaping, loginout current-request redirect links,
  post title/date/excerpt/read-more context rendering, temporary excerpt filter
  cleanup, button/file/image markup transforms, lightbox filter locality, and
  global/superglobal/option restoration.
- `block-widgets`: block-backed widget behavior, including `WP_Widget_Block`
  rendering, dynamic legacy class mapping matrix, malformed/unknown block
  fallbacks, content sanitization on update, form escaping, `the_widget()`
  display callback/action flow, registered control rendering, widgets block
  editor support toggles, widget ID parsing, unregistered-widget cleanup, and
  sidebars widget mapping, `retrieve_widgets()` remapping/customizer
  persistence boundaries, and lost/inactive block widget recovery.
- `bookmark-links`: no-live-DB legacy bookmark/link-manager API coverage,
  including in-memory link rows and link categories for `get_bookmark()`,
  `get_bookmarks()`, `wp_list_bookmarks()` and `_walk_bookmarks()` escaping,
  image/update/filter-payload rendering, edit bookmark links, bookmark
  sanitizers, selected deprecated wrappers, safe link CRUD, argument filtering,
  ordering, limits, visibility, ratings, and bookmark caches.
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
  aggregation and mutators, `WP_User::for_site()` cap-key isolation,
  `user_can_for_site()` wrapper contracts, role and user capability filter
  locality, generated `map_meta_cap()` filter contexts, cheap meta-cap
  mappings, and primitive/meta cap monotonicity.
- `canonical-routing`: no-live-DB canonical redirect and front-end routing helpers,
  including method/search/preview bailouts, host/path/query cleanup, invalid
  date redirects, DB-stub-backed 404 permalink guessing, feed/pagination
  canonicalization, redirect filter cancellation and same-host replacement
  cascades, canonical URL output helpers for status, paged, comment-page,
  plain-permalink, filter, and singular output gates, fragment stripping, and
  generated query-argument removal and fragment stripping helper matrices.
- `classic-walkers`: deterministic Walker base and classic walker coverage,
  including `walk()`, `paged_walk()`, direct `display_element()` traversal,
  page/category/comment/nav rendering, current/selected classes, admin nav menu
  checklist/edit field names, direct admin nav helper contracts, generated
  has-children oracles, bounded HTML balance, escaping contracts, and
  global/filter/superglobal/output-buffer restoration.
- `content`: slashing, metadata serialization, post and term field sanitization,
  whole-post `sanitize_post()` object/array consistency and filter locality,
  `get_extended()` more-tag splitting, post-template title/excerpt/password
  helper filters and cookie branches, direct `get_the_content()`/`the_content()`
  rendering, content pagination and `<!--more-->`/`<!--nextpage-->` handling,
  `wp_link_pages()` link/filter contracts, query variables, `WP_Date_Query`,
  and title/class/key sanitizers.
- `content-lifecycle`: in-memory wpdb-backed post, post-meta, term, user, and
  comment CRUD lifecycles, including insert/update/read/delete round trips,
  duplicate and invalid-input errors, sanitizer agreement, monotonic IDs,
  metadata cache invalidation, post-to-term relationship field modes, helper
  caches, relationship hook arguments, status-transition hook/cache/default
  side-effect branches, post-delete cleanup, cache/count refresh behavior, and
  per-iteration state restoration.
- `comments`: comment filtering, sanitizer agreement, max-length boundaries,
  type partitioning, comment classes, author URL/email links, excerpt/text
  helpers, comment cookies, reply/cancel link rendering branches, and permalink
  pagination contracts for `get_page_of_comment()`/`get_comment_link()`.
- `community-events`: no-network Community Events API client coverage,
  including IP header selection and anonymization, minimal/fail-closed request
  bodies, transient key/cache behavior, strict coordinate matching, cache
  expiration normalization, search-triggered cache refresh behavior, event
  trimming and WordCamp pinning, response normalization, and API error
  contracts.
- `comment-workflow`: in-memory comment submission, duplicate/flood approval
  decisions, direct `wp_new_comment()` preprocessing and insert hooks,
  moderation short-circuits, update/status transition hooks, trash/untrash and
  spam/unspam restoration, force-delete reparenting/meta/count/hook contracts,
  and WP_Error failure paths without process exits.
- `cron`: in-memory cron scheduling, recurrence lookup, schedule/unschedule
  and next-scheduled filter contracts, unschedule-hook pre-filter return
  contracts, duplicate single-event windows, scheduled-event lookup
  ordering/exactness, ready-job partitioning, spawn request/lock boundaries,
  unscheduling and rescheduling contracts.
- `default-widgets`: classic default widget subclass coverage, including
  constructor/options contracts, saved-instance callback lifecycles, update
  sanitization, form escaping, filtered rendering for text/custom
  HTML/search/meta/list/nav-menu widgets, cache-backed calendar/archive output,
  navigation widget argument filters, and local RSS fixtures without network
  requests.
- `customizer`: no-DB Customizer API coverage for manager registry lifecycles,
  setting sanitize/validate/post value flows, slashed customized JSON ingestion
  and programmatic post-value merge precedence, multidimensional option
  previewing, container/control JSON exports, active callbacks, and selective
  refresh partial registration/rendering without changeset persistence.
- `customizer-nav-widgets-requests`: in-memory wpdb-backed Customizer nav menu
  and widget request coverage, including loaded component/capability hook
  gates, menu available/search AJAX, auto-draft insertion and publish cleanup,
  dynamic nav menu/menu-item settings, placeholder menu remaps into locations
  and `widget_nav_menu`, preview HMAC/export metadata, signed widget instance
  round trips, widget update AJAX, and widget selective-refresh partials.
- `customizer-persistence`: no-live-DB Customizer persistence coverage for
  changeset UUID/data normalization, stub-backed `customize_changeset` post
  content parsing, changeset lock/heartbeat persistence, transactional
  changeset saves, Custom CSS setting validate/sanitize/preview/update
  behavior, custom CSS post filters, and global/superglobal restoration.
- `date-time`: deterministic no-DB date/time helper coverage, including
  `wp_date()`/`DateTimeImmutable` agreement, `date_i18n()` and `mysql2date()`
  timestamp oracles, timezone option filters, GMT/local round trips, ISO8601
  offset parsing and datetime conversion across DST boundaries, week windows,
  `current_time()`, `current_datetime()`, timezone override offsets,
  `wp_checkdate()` validity/filter contracts, date/human diff filter contracts,
  and safe human time diffs.
- `discovery`: robots meta directives, scoped public/private robots helper
  output, sitemap enablement and robots.txt injection, provider
  registration/replacement filters, query/permalink sitemap URL/index
  expansion, escaped sitemap XML rendering, unsupported sitemap field
  boundaries, stylesheet URL filters, sitemap max-URL filters, and built-in
  posts/taxonomies/users sitemap providers with fixture-backed subtype,
  lastmod, max-page, query-arg, pre-filter, and public/private gating oracles.
- `email`: Unicode email validation/sanitization, ASCII fallback filters,
  WHATWG-style validity, `WP_Email_Address` machine/readable and IDN/punycode
  views, generated mixed-script UTF-8 address-model round trips, raw getter
  invariants, ASCII-vs-Unicode construction-mode consistency with explicit IDN skips,
  optional Unicode API availability skips, disabled-filter fail-closed behavior,
  invalid UTF-8, generated malformed address variants and reserved/invalid ACE
  domain labels across filter/charset modes, selected boundary lengths,
  quoted/escaped local-part rejection,
  control-character and Unicode separator sanitization boundaries, local-part
  case/width/normalization identity preservation, hook restoration,
  normalization-sensitive local parts, generated accent-distinct local-part
  alias/index paths, byte-preserving user email search SQL, comment-author email
  filtering, UTF-8 comment submission through `wp_handle_comment_submission()`
  and direct `wp_new_comment()` sanitization paths, REST user email schema
  validation across Unicode/ASCII filter modes,
  and no-DB stub-backed user email lookup/duplicate behavior for accent-distinct
  local parts/domains, generated Unicode local-part update/collision behavior,
  exact Unicode email authentication and machine-view miss behavior,
  email-change notification recipient/body preservation, canonical Unicode-domain
  save/update collision behavior without MySQL collation/index coverage,
  profile email-change confirmation request paths, password-reset Unicode recipient paths,
  password-reset notification recipient machine/readable view overrides through
  the PHPMailer handoff, current machine-view reset lookup rejection, clickable
  mailto rendering boundaries, and generated
  mailto/rendering-context round trips for UTF-8 local parts, WHATWG delimiter
  local parts, IDN/punycode domains, escaped display hrefs, and readable text.
- `environment-load`: no-network environment/load/compat helper coverage,
  including environment type cache boundaries, isolated `WP_RUN_CORE_TESTS`
  environment-type matrices with constant precedence, server/request
  normalization, Basic Auth and SSL detection, memory-limit parsing, ini
  mutability, installing/maintenance flags, generated JSON/XML request media
  matrices, request guard filters, HTTPS migration short-circuits, and UTF-8
  compatibility oracles with state restoration.
- `error-protection`: no-shutdown error protection and recovery-mode
  infrastructure coverage, including paused-extension source normalization and
  storage, recovery key/cookie validation, recovery-link generation, filtered
  recovery email payloads and `handle_error()` protected-endpoint rate limiting
  without real mail, fatal-error handler formatting oracles, protected-endpoint
  gates, and explicit skips for redirects, loopbacks, real fatal dispatch, and
  process exits.
- `editor-helpers`: no-browser classic editor helper coverage for
  `_WP_Editors` settings/state normalization, default editor selection filters,
  teeny and full TinyMCE/Quicktags filter branches, captured editor markup,
  editor script enqueue decisions, TinyMCE translation snippets, media-view
  stylesheet helpers, and global restoration.
- `fonts`: font-face CSS serialization and validation, theme.json font-face
  resolution and default printing, font directory filters, Font Library
  collection registration/JSON loading, REST font collection pagination,
  filtering, and response boundaries, REST font-family/font-face write
  lifecycle coverage for duplicate guards, upload rewriting, force-delete
  cleanup, and cascade deletion, REST font-face preparation boundaries, and font
  utility sanitization for family lists, face slugs, schemas, and MIME maps.
- `filesystem`: path normalization and joining, file validation classes,
  filename sanitization/uniqueness, unique-filename callback and case-collision
  filters, temp names, recursive directory creation/listing and stream wrapper
  detection, direct filesystem sandboxing, metadata/time/chmod round trips, and
  missing-file failure values.
- `formatting`: escaping helpers, text sanitizers, whitespace normalization,
  autop/shortcode cleanup, clickable text, URL sanitization, entity
  normalization, title/key/class identifier sanitizers, colors, sizes, time
  strings, UTF-8 helpers, and accent removal.
- `feed-parsers`: local RSS/Atom parser and legacy feed utility API coverage,
  including bounded malformed fixtures, Magpie item/channel normalization,
  AtomParser local-file behavior, SimplePie raw-data parsing and KSES
  sanitization, `WP_SimplePie_File` HTTP response/error-state normalization,
  transient-backed feed cache boundaries, date/status helpers, local file
  adapter guards, no-network assertions, and state restoration.
- `feed-rendering`: no-DB RSS2, Atom, RDF, RSS 0.92, and comments feed template
  rendering over synthetic query loops, including feed item/entry counts, self
  links, legacy `do_feed()` dispatch normalization, self-link request URI
  host/filter escaping, CDATA terminator escaping, excerpt/content mode
  switches, enclosure metadata, comment feed escaping, and feed build date
  selection.
- `frontend-features`: no-DB frontend feature helper coverage for speculative
  loading and view transitions, including direct speculation rule validation,
  configuration eligibility, mode/eagerness filters, generated URL-pattern
  exclusions, disabled lifecycle/load-action isolation, script tag escaping,
  theme support behavior, view-transition CSS registration timing, enqueueing,
  and global restoration.
- `html-api`: HTML tag and tree processor mutation escaping, deterministic
  rich HTML generation, normalization/recovery idempotence with tree
  preservation, token walking, breadcrumb stack replay, bookmark/seek replay,
  semantic parser mode probes, modifiable text escaping, token serialization,
  and namespace/comment/rawtext boundary checks.
- `http`: synthetic HTTP response arrays, request wrapper dispatch,
  real `WP_Http::request()` to Requests success-path option mapping and
  response conversion through a fake no-network transport, response objects,
  header/cookie parsing, proxy decisions, redirect safety, chunk-transfer
  decoding, direct no-network request normalization and early error contracts,
  URL validation, and relative URL resolution without live network requests.
- `icons-connectors`: no-DB Icons and Connectors API coverage, including
  connector registry lifecycle and init discovery, settings/REST key masking,
  AI-provider update validation fail-closed behavior, API-key mask/
  file-modification policy, script module serialization, plugin install/
  activation status metadata, icon manifest/search behavior, SVG sanitization
  and file caching, REST icons schema/permission/error contracts, and state
  restoration.
- `images`: image constraint and resize math, synthetic intermediate metadata,
  metadata dimension lookup, responsive `srcset`/`sizes` generation and filter
  boundaries, attachment image helpers and attribute filters, image tag
  attribute insertion, content tag image/iframe filtering, auto-sizes helper
  gates, loading optimization attributes, and image filetype/extension helpers.
- `image-metadata`: local admin image metadata parser coverage over generated
  bounded JPEG/TIFF/PNG byte fixtures, including `wp_read_image_metadata()`
  malformed-file behavior, EXIF/IPTC field extraction and sanitization when PHP
  extensions are available, locale-aware XMP alt text extraction/fallbacks,
  EXIF helper normalization, image metadata filters, temp-file cleanup, and
  state restoration.
- `identity`: usernames, emails, identity sanitizer filter contracts,
  capabilities, generated user contact-method filters and additional-key
  propagation, generated `WP_User` identity field/cache/filter behavior, avatar
  data/URL/HTML filter pipelines, text/comment filters, comment
  cookies/current-commenter payloads, options, password hashing/checking, parse
  helpers.
- `import-diff`: importer registry and upload-form helpers, `WP_Importer`
  imported post/comment lookup against the in-memory stub, `wp_text_diff()`
  rendering/escaping/normalization, and `WP_Error` export/merge/remove
  transfer semantics.
- `install-schema`: no-DB install and upgrade schema coverage, including
  `wp_get_db_schema()` table sets, `make_db_current()`/silent wrapper scope
  expansion, global-table upgrade gate filters, `dbDelta()` CREATE TABLE
  parsing, missing-table creation and replay no-ops, equivalent-schema no-ops,
  isolated column/index diffs, SQL table allowlists, and malformed DDL
  fail-closed behavior.
- `interactivity`: server-side directive processing for context, bind, class,
  style, text, and each directives, explicit namespace/negation/length
  evaluation, bind/class/style/text directive syntax and suffix/unique-ID
  ordering, context namespace stack merge/sort/restoration, derived state
  closure tracking and fail-closed errors, script-module router metadata,
  unsupported/unbalanced HTML fallbacks, derived context/element helpers, and
  state/config merge serialization.
- `hooks`: filter/action priority ordering, accepted arguments, removal,
  nested and reentrant hook stack state, preinitialized hook normalization,
  deprecated hook wrapper fast paths and side-effect hooks, ref-array
  deprecated dispatch, `current_filter()`, `doing_filter()`, `did_action()`.
- `kses`: KSES policies, wrapper agreement, protocol filtering/helper contracts,
  low-level helper contracts, filter-aware safe CSS, attribute/entity/comment
  handling, deterministic attribute constraint matrices for required, values,
  max/min, valueless, and callback checks, serialized block attribute KSES
  filtering, PDF object policy and upload-host/port URL gates, dynamic URI
  attribute filtering, full-tag attribute parsing, no-HTML filtering, custom
  context locality, strict/custom policy monotonicity, and filter/global
  restoration.
- `l10n`: translation fallbacks, escaped translation helpers, plural/nooped
  selection, textdomain load/unload state, translation path guards, locale and
  user-locale switching including generated stack/action payload matrices,
  script translation helpers, malformed string boundaries, localized
  numbers/dates, and filter/action restoration.
- `translations`: no-DB POMO and translation-file parsing/loading coverage,
  including generated `Translation_Entry` lookup and merge behavior,
  `NOOP_Translations` identity contracts, MO/PO/PHP translation file round
  trips, explicit plural-rule oracles, short-circuited translation API,
  available/installed language metadata, dropdown language normalization,
  guarded language-pack helpers, transient-backed translation update helpers,
  direct `WP_Translation_Controller` locale/domain/file isolation and lazy
  malformed-file eviction, textdomain load/unload cycles, helper agreement with
  loaded domain entries, malformed-file closed failures, and state restoration across `$l10n`,
  `$l10n_unloaded`, registry, controller, current-user, and filter globals.
- `mail`: no-delivery `wp_mail()` composition coverage, including argument
  filters, pre-send short-circuiting, PHPMailer recipient/header/content
  handoff, UTF-8 local-part recipient/display-name preservation, IDN domain
  punycode handoff, array and string header parsing, newline-delimited
  attachments and embeds, multipart boundary/header/body preservation through
  serialized MIME output, reusable mailer cleanup/reset behavior,
  invalid From failure payloads, early failure cleanup, success/failure
  actions, and emoji email body staticization.
- `markup`: block parse/serialize/render guards, deterministic `do_blocks()`
  fixture rendering for generated block trees with synthetic callback oracles
  and block-rendering state restoration, shortcodes, text trimming, excerpts,
  balanced tags, URL extraction, link attribute helpers, and embed helpers.
- `media-editor`: no-DB media image editor coverage for editor selection,
  GD/Imagick availability, output format filters, abstract editor
  filename/quality/EXIF-orientation contracts, resize/save metadata,
  intermediate and generated sub-sizes, missing sub-size detection, and
  cache/filter-backed attachment metadata helpers with temp-file cleanup.
- `media-image-edit-requests`: admin media image-edit request coverage for
  history normalization, preview streaming, save/restore metadata, crop
  wrappers, AJAX preview/crop/sub-size boundaries, nonce/capability gates,
  file/metadata/id filters, and temp-root attachment fixtures using a
  deterministic fake image editor.
- `media-ingest`: no-network media upload and sideload ingest coverage over
  generated temp fixtures, including upload directory filters, MIME/filetype
  boundaries, sanitized unique filenames, direct handle prefilter/move hooks,
  upload override/error semantics, attachment row/post field/postmeta creation
  in the in-memory wpdb stub, download short-circuit cleanup, metadata update
  failure paths, and cleanup restoration.
- `media-metadata`: local audio/video metadata parser coverage over generated
  bounded byte fixtures, including `wp_read_audio_metadata()` and
  `wp_read_video_metadata()` malformed-file behavior, ID3 tag helper
  sanitization, creation timestamp extraction, audio/video extension and ID3 key
  filters, `wp_attachment_is()` MIME/extension branches, image/document
  classification, MIME/extension disagreement, wrapper behavior, attachment
  metadata get/update/delete filter contracts, original-image path/URL and
  image-meta matching normalization across seeded upload storage styles, and
  `wp_generate_attachment_metadata()` audio/video cover-art avoidance.
- `media-remote`: no-live-network remote media helper coverage for
  `download_url()`, `media_sideload_image()`, and selected
  `media_handle_sideload()` branches, including HTTP short-circuit fixtures,
  case-insensitive download headers, Content-Disposition/content-type filename
  derivation and sanitization matrix,
  signature soft-fail/hard-fail temp-file behavior, URL extension/MIME
  boundaries, extension-filtered sideload return types and metadata,
  temp-file cleanup, size/type rejection, sideload prefilter cleanup and
  override filter contracts, filter locality, and global restoration.
- `metadata`: no-DB Metadata API registration, subtype visibility, defaults,
  registration argument edges, legacy callbacks, sanitize/auth/protected-meta
  filters, current subtype-aware API accounting for post/term/comment/user
  metadata, cache-backed lookup shape, filtered and in-memory CRUD cache
  invalidation, by-mid short-circuit filter payloads and fail-closed inputs,
  mid-row helpers, cache priming, and duplicate-aware lazyloader queue/reset
  behavior.
- `multisite`: no-DB multisite/network API coverage, including synthetic
  `WP_Site` and `WP_Network` objects, site data normalization, cache-backed
  lookups, legacy blog identity helpers, bootstrap current-site/current-network
  resolution, blog-switch stack/cache restoration, filter-backed network option
  reads, stub-backed network option CRUD, large-network threshold/filter
  contracts, pre-query-short-circuited site/network queries, domain/path lookup
  helpers, current/switched URL helpers, and an optional true-multisite
  subprocess for DB-backed site lifecycle and `sitemeta` write paths when a
  readable `wp-tests-config.php` is available.
- `navigation`: nav menu location registration, theme menu assignment lookup,
  menu object and item setup filters, current-item class derivation, current-tree
  parent/ancestor propagation, walker output, depth pruning,
  short-circuit/fallback behavior, args/items-wrap normalization, container
  allowlists, attribute filter escaping, and filtered no-DB `wp_nav_menu()`
  rendering.
- `navigation-lifecycle`: in-memory wpdb-backed nav menu persistence and REST
  menu controller coverage, including `wp_create_nav_menu()`,
  `wp_update_nav_menu_object()`, `wp_delete_nav_menu()`,
  `wp_update_nav_menu_item()`, associated object cleanup callbacks,
  auto-add page behavior, menu-location remapping, orphan/self-parent item
  normalization, sanitized menu item meta, REST menu/menu-item/location
  permission gates, invalid location/object errors, forced-delete semantics,
  links, and state restoration.
- `network-media`: URL parsing/sanitization/validation, URL scheme
  normalization, path normalization, filename sanitization, filetype checks,
  unique filenames, generated collision/alternate-extension filename oracles,
  callback/filter contracts, sideload handling, multisite upload quota,
  remaining-space, size-limit, network upload MIME allowlists, direct
  file-too-large checks, `check_upload_size()` error/state behavior, and
  over-quota helpers.
- `options-autoload`: no-DB option CRUD, cache, autoload, and filter coverage
  for generated option values, including alloptions membership, notoptions
  transitions, raw serialized cache shape, default/pre/update filters, cache
  priming stability, bulk autoload mutators, lifecycle action payload ordering,
  and safe option-name boundary cases.
- `plugin-theme`: plugin headers, plugin path helpers, invalid plugin path
  validation, no-DB plugin dependency metadata, dependency slug/name/API-data
  fallbacks, active dependency option states, theme headers, parent/child
  relationships, active theme file helpers, screenshots, and broken theme errors.
- `plugin-theme-lifecycle`: no-network plugin/theme lifecycle coverage over
  generated temp fixtures, including plugin validation and requirement errors,
  dependency failure states, activation/deactivation success and output-failure
  paths, multi-plugin deactivation scope/action payload ordering, active and
  sitewide-active option shapes, plugin/theme deletion validation, theme
  enumeration and requirement checks, safe child-theme switching, theme
  support/template globals, and read-only REST plugin/theme controller paths.
- `update-install-upgrader`: no-network update/install/upgrader coverage,
  including generated core/plugin/theme update transient shapes, aggregate
  update counts/titles, `WP_Upgrader_Skin` and `Automatic_Upgrader_Skin`
  output behavior, `WP_Upgrader` local/filtered download and temp-directory
  install-package lifecycles, temp-backup cleanup/restore rollback contracts,
  plugin/theme package validation helpers, no-update upgrade branches,
  auto-update decision filters, VCS and PHP compatibility gates, core
  version-policy decisions, and maintenance-mode writes against a temp
  filesystem only.
- `utility-internals`: no-DB low-level utility coverage for `WP_List_Util`,
  list helper wrappers, `WP_Token_Map`, `WP_MatchesMapRegex`, and
  `WP_URL_Pattern_Prefixer`, including reference filter/pluck/sort oracles,
  chained filter/sort/pluck state, parse-list and array-path helper contracts,
  token lookup/precomputed table round trips, rewrite match substitution,
  URL-pattern prefix escaping/idempotence boundaries, and state restoration.
- `user-preferences`: no-request-dispatch admin UI preference coverage,
  including sanitized user-setting/admin-color serialization, hidden column and
  meta-box preference defaults/saved values, postbox order/classes, screen
  option registration/rendering, Screen Options visibility caching and filters,
  composed Screen Options output for columns, meta boxes, layout, pagination,
  view modes, and custom settings, layout column rendering and legacy filters,
  subprocess-isolated AJAX preference handlers and `set_screen_options()`
  redirect/exit paths, filter locality, and state restoration.
- `post-embeds`: in-memory wpdb-backed WordPress-as-oEmbed-provider coverage,
  including post type embeddability predicates, public visibility fail-closed
  behavior, oEmbed response width clamps, rich iframe and thumbnail conversion,
  plain/pretty/path-conflict embed URL selection, iframe/blockquote/script
  markup contracts, discovery link output, direct `WP_oEmbed_Controller`
  item responses, same-site `pre_oembed_result` short-circuit behavior, and
  REST oEmbed proxy provider-fetch/transient-cache behavior with nonce-excluded
  cache keys, dimension cache misses, TTL/filter oracles, and no-network HTTP
  interception.
- `post-types`: post type and post status registry defaults, registration
  filter/action/meta-box lifecycle, REST route registration boundaries and
  late-route ordering, duplicate post-type replacement cleanup, support feature
  registration, capability generation, registry query operators, query/archive
  normalization, unregister cleanup, archive/feed link filters, and status
  filtering.
- `privacy`: no-DB user request and privacy helper coverage, including
  synthetic `WP_User_Request` objects, request lifecycle helpers, action and
  confirmation descriptions, request-key hash/expiration validation including
  missing-request and global-post fallback fail-closed behavior, export group
  HTML escaping, exporter/eraser registry and processor shape contracts,
  final erasure completion status/meta/action behavior,
  built-in comments exporter/eraser payload and anonymization behavior, built-in
  user exporter profile/community-location/session-token payloads and additional
  profile filter contracts, built-in media exporter author/type filtering,
  50-item pagination, URL payloads, and registration callbacks, export
  notification recipient/subject/content/header filters through an intercepted
  PHPMailer handoff, directory/expiration cleanup filters, anonymization
  helpers, privacy policy suggestion/default text, suggested-text lifecycle
  cache transitions, text-change cache and admin notices without real mail or
  network delivery.
- `privacy-admin-requests`: in-memory wpdb-backed admin privacy request
  coverage, including export and erasure request list-table views, counts,
  status filtering, prepared items, row action nonce/data attributes, checkbox
  and status markup, bulk-action and direct helper contracts, personal data
  export/erasure AJAX success flows, capability and request-shape gates,
  selected exporter/eraser/page callbacks, malformed callback responses,
  scoped Unicode email filters, runtime cache isolation, and state restoration.
- `query`: no-DB query builder and execution APIs, including meta/tax/date
  query tree sanitization, SQL fragment generation, relation normalization,
  query-var parsing, seeded `WP_Query` execution/found-row result oracles,
  classic post-search parser/order/result SQL oracles for terms, exclusions,
  columns, stopwords, attachment filename left-join branches, password gates,
  literal clause-keyword searches, empty relevance-order boundaries, and
  relevance ranking, wpdb stub status-OR branch handling,
  cache-key/cache-hit determinism, `WP_User_Query` field/order/search/role/
  capability/has-published-post SQL-shape oracles and hook mutation locality,
  user/comment pre-query short-circuits, and deterministic global restoration.
- `query-loop`: no-DB `WP_Query` execution and loop-state coverage through
  `posts_pre_query` fixtures, including loop wrapper delegation,
  setup/reset postdata globals, nested secondary-query reset and conditional
  scoping, single/page flag behavior, found/max-page coherence, empty-result
  events, offset/no-found-rows field-shape windows for `ids` and `id=>parent`,
  `the_posts` result-filter finalization, and deterministic state restoration.
- `registries`: no-DB modern registry coverage for connectors, icons, block
  metadata collections, and speculation rules, including lifecycle validation,
  helper oracles, manifest sanitization/caching, connector override
  re-registration, block metadata path-boundary/cache behavior, virtual path
  prefix preservation, allowlist behavior, rule serialization, and state
  restoration.
- `rest`: request normalization, parameter precedence, JSON bodies, route regexes,
  `register_rest_route()` wrapper merge/override semantics, schema
  sanitize/validate, permissions, HEAD/GET behavior, response links, CURIE
  compaction, embedding, envelopes, headers, response conversion, and global
  REST registration state restoration.
- `request-lifecycle`: no-DB front-controller lifecycle coverage for
  `WP::parse_request()`, rewrite/pathinfo/index matching, public/private
  query-var gates, query-var precedence and GET/POST mismatch termination,
  `WP::main()` sequencing with query short-circuits, `register_globals()`,
  `handle_404()` status transitions, and `send_headers()` filters/actions with
  feed content-type, last-modified, ETag, stale conditional request, and
  deterministic global restoration.
- `rest-controllers`: no-DB default REST endpoint controller coverage for
  registry-backed post types, post statuses, taxonomies, settings, block types,
  block patterns, block pattern categories, plugin/theme route/schema contracts,
  and REST search handlers, including context/_fields filtering, registered
  additional-field get/update/schema callbacks, collection params, permission
  gates, namespace-specific REST links, plugin/theme sanitizer contracts,
  route-dispatched defaults/schema validation, custom search handler
  result/header/link propagation, invalid subtype rejection, public search-result
  schema callback contents, post-format search term/link and pagination behavior,
  invalid values, and state restoration.
- `rest-application-passwords`: in-memory user/app-password backed REST
  application password controller coverage, including collection/item/
  introspection route and schema contracts, create/update/delete dispatch,
  one-time password response and stored hash agreement, `_fields` projection and
  links, REST pre/after/prepare hooks, capability-denied and availability error
  matrices, current-user introspection, stale UUID failures, and global/filter
  restoration.
- `rest-directory-services`: no-network REST coverage for WordPress.org-backed
  directory service controllers, including block-directory, pattern-directory,
  and URL-details route/schema contracts, plugin API and HTTP short-circuits,
  permission matrices, request validation errors, transformed response schemas,
  pattern and URL cache/transient behavior, HEAD/cache-hit boundaries, metadata
  parsing and relative media URL normalization, and global/filter restoration.
- `rest-media-attachments`: in-memory wpdb-backed REST media attachment write
  coverage, including `Content-Disposition` filename parsing, raw upload
  validation failures, raw body `create_item()` success through the upload
  directory and attachment postmeta pipeline, permission gates, client-side
  media-processing route/argument contracts, metadata finalization filters,
  `_fields` response projection, edit-media fail-closed paths, temp upload
  cleanup, and global/filter restoration.
- `rest-widgets-sidebars`: no-live-DB REST widget, widget-type, and sidebar
  controller coverage, including route/schema contracts, public
  `show_in_rest` read gates, widget type sorting/projection and
  `encode_form_data()` instance/hash round trips, isolated
  `/widget-types/{id}/render` iframe preview dispatch, text widget
  create/update persistence, sidebar reassignment/reorder semantics, legacy
  widget `form_data` updates, soft/force delete hooks, HEAD short-circuits,
  and widget/global/filter restoration.
- `rest-object-controllers`: in-memory wpdb-backed REST object controller
  coverage for posts, terms, comments, users, revisions, and attachments,
  including schema/context/_fields filtering, deterministic collection
  parameter sanitizer/validation matrices, permission gates, route
  registration/dispatch, route index/help-data
  projection, REST links, invalid IDs/types, sanitized content/meta fields,
  safe create/update/delete error paths, upload-no-data paths, and
  deterministic state restoration without live uploads, remote requests, or a
  live database.
- `rest-site-editor`: no-live-DB Site Editor REST controller coverage for
  global styles, template/template-part response shaping, template revisions
  and autosaves, bounded template item and lookup fallback route dispatch,
  navigation fallback, direct block-template ZIP export generation, edit-site
  export guards, and subprocess-isolated live edit-site export streaming,
  including route normalization, schema/context behavior, permission and error
  contracts, custom CSS validation, temp theme fixtures, streamed ZIP
  inspection, archive cleanup, and state restoration.
- `revisions-autosaves`: in-memory wpdb-backed revision and autosave API
  coverage, including protected revision field/filter contracts, autosave
  create/update/delete and post-lock behavior, autosave and revision predicates,
  latest revision count and URL helpers, user-filtered autosave lookup,
  revision insert/save/restore/delete helpers, revisioned meta copy and
  restore behavior, post type support gates, revision title/list helpers,
  revision UI diffs, JS payload preparation, direct revision template output,
  preview overlay behavior, and global/filter restoration.
- `rewrite`: rewrite tags, permastruct/rule generation, collision ordering,
  endpoint expansion and mask propagation, match substitution, query arg and
  build/parse helpers, rewrite-tag removal/query-var retention boundaries, URL
  parsing, home/site URL helpers, weird path fragments, and cheap no-DB
  `url_to_postid()` paths.
- `security`: salts and HMACs, password and fast-hash verification, nonce
  generation/verification, nonce tick/lifetime boundaries, nonce URLs and
  hidden fields, admin/ajax referer paths, synthetic auth cookies and session
  token grace/failure edges, password filter locality, redirect sanitization,
  redirect validation matrices, sanitize/validate metamorphic behavior, and
  safe redirect filters.
- `shortcodes`: no-DB shortcode registry lifecycle, attribute parsing/default
  merging and dynamic filters, invalid registration and non-callable callback
  guards, callback argument and rendering filter contracts, nested parse
  boundaries, callback mutation during rendering, scoped media image context
  hooks including priority-zero preexisting filters, escaped and HTML-attribute
  rendering, tag-name edge/collision behavior, tag discovery, apply aliasing,
  stripping preservation and strip filters, presence checks, malformed inputs,
  and exact global/hook restoration.
- `site-health`: no-DB Site Health/update/HTTPS helper coverage, including
  generated update transients and dismissed core update options, aggregate
  update counts/titles, HTTPS option booleans, migration replacement, HTTPS
  detection short-circuits, generated `site_status_tests` registry/filter
  behavior, selected direct `WP_Site_Health` tests without remote requests,
  synthetic loopback and REST availability request outcomes, scheduled event
  missed/late/future cron classification, HTTP-blocking constant checks, and
  persistent object cache threshold/filter direct-test behavior.
- `site-health-debug`: bounded `WP_Debug_Data` coverage for Site Health Info
  formatting, diagnostic size helpers, and an isolated full `debug_data()` scan,
  including private field/section suppression, debug vs info labels,
  `debug_information` filter locality, scoped `SHOW TABLE STATUS` and
  `SHOW VARIABLES` wpdb doubles, generated directory/database/total-size
  aggregation, MySQL variable lookup fallbacks, fake no-network WordPress.org
  communication, bounded Ghostscript detection, path-size loading placeholders,
  and child-process cleanup/restoration checks.
- `state`: object cache groups, multi-operations, and cache-addition
  suspension, option, transient, cache-backed and option-backed site-transient
  APIs, update/expiration cleanup, dynamic transient filters, serialization,
  JSON, and value helpers.
- `style`: style engine serialization, preset/classname and CSS variable
  boundaries, CSS declaration filtering, theme.json schema/data merging and
  variable resolution, block style variation declarations, registered block
  style `style_data` source-order injection, block-support wrapper
  serialization, selector/path helpers, scoped editor style helpers, custom
  properties, and no-DB global style guards.
- `syndication`: oEmbed provider registration, embed handler lifecycle, oEmbed
  wildcard/regex matching, cache-key lookup, no-network fetch short-circuits,
  HTML/XML filtering, feed metadata escaping, default feed normalization,
  automatic feed-link head output gates, `feed_links_extra()` branch output for
  singular, post type archive, category, tag, custom taxonomy, author, and
  search query states, self links, comment feed-link generation/filtering, and
  Atom text construction.
- `taxonomy`: taxonomy registration lifecycle, object-type associations,
  registration filter/action locality, registry query consistency,
  argument/callback/default-term normalization, query-var and rewrite
  side-effect boundaries, REST controller creation, term sanitization and field
  filters, slug/name normalization, synthetic `WP_Term` behavior, hierarchy
  helper edge cases, `get_terms()` short-circuit contracts, and cheap term-link
  paths.
- `taxonomy-relationships`: in-memory wpdb-backed object/term assignment
  coverage, including `wp_set_object_terms()` replace/append behavior,
  field-variant agreement, multi-object `all_with_object_id` mapping, scoped
  removal, membership/object lookup helpers, invalid-input fail-closed paths,
  `get_the_terms()` relationship cache population/invalidation, and generated
  multi-object `update_object_term_cache()`/`clean_object_term_cache()`
  priming, empty-entry, warm-cache, and re-prime invariants.
- `template-hierarchy`: no-DB classic PHP template hierarchy coverage,
  including child/parent lookup priority, query-template filters, direct
  archive/page/search/404/embed/author/date/home/front-page/privacy/singular/
  attachment helper filters and path confinement, stylesheet/template root
  precedence, single template ordering, generated
  category/tag/taxonomy decoded-slug and term-ID ordering, `load_template()`
  include semantics, template-part hooks and args, and isolated
  `comments_template()` child/parent/custom file loading and comment-query
  contracts without leaking `COMMENTS_TEMPLATE` into the parent process.
- `template-links`: no-DB public template and link helpers, including body and
  language attributes and filter ordering/locality, document title stability,
  resource hints/preloads, pagination/search/feed/site/admin URLs, canonical and
  shortlink head output, synthetic post preview/edit/delete/shortlink/permalink
  helpers, date and author archive URL branches, previous/next adjacent post
  relation links, and cached bookmark field/list rendering.
- `widgets`: classic sidebar registry lifecycle, widget factory instance
  registration, direct widget/control callbacks, generated sidebars, widget ID
  parsing, sidebar option cache/filter behavior, sidebar assignment
  moves/removals, inactive widget placement, render callback wrappers,
  `dynamic_sidebar()` action/filter ordering, no-external-DB guards, and
  registry restoration.
- `wpdb-sql`: no-connection real `wpdb` SQL formatting coverage, including
  placeholder count/type handling, `%i` identifier containment, literal percent
  and LIKE escaping, malformed placeholders, and captured insert/update/delete/
  replace builder SQL shape, including null values across string, integer, and
  float builder formats.
- `wxr-export`: subprocess-isolated WXR export coverage over deterministic
  synthetic posts, terms, authors, comments, and meta, including export
  argument filtering, title/content/excerpt export filters, XML/CDATA/UTF-8
  safety, meta skip filters, attachment URL/file metadata serialization, author
  and term ordering, filtered filename and XML content-type header intent with
  observable-header assertions when available, and state restoration.
- `xmlrpc`: no-DB IXR/XML-RPC protocol coverage, including value escaping,
  request/message round trips, invalid XML fail-closed behavior, fault XML,
  system method dispatch, mixed success/fault multicall ordering, method
  registry filters, demo helpers, disabled login behavior, bounded pre-network
  pingback fail-closed/read-only lookup branches, legacy post title/category XML
  helpers, authenticated read-only `wp.getPost`, `wp.getPosts`,
  `wp.getMediaItem`, and `wp.getMediaLibrary` post/media field filtering,
  auth/capability/error paths, media MIME/parent filters, hook cleanup, and
  short-circuited `WP_HTTP_IXR_Client` transport/error handling without
  publishing, option, post-sleep pingback fetch, or live network side effects.

Some checks deliberately skip cases that would invoke DB-backed or dynamic block
rendering side effects. The Admin Screen surface intentionally avoids admin page
submission, `options.php` writes, user preference persistence, real post objects,
and block-editor compatibility shims that would inspect installed plugins. The
`script-loader-runtime` surface complements `assets` by covering server-side
runtime helpers in `script-loader.php`, `functions.wp-scripts.php`, and
`functions.wp-styles.php` without browser execution. It directly calls the emoji
detection printer instead of the public static-once wrapper so iterations remain
isolated, directly asserts concatenated loader query chunks and separate
strategy/external asset tags under forced concat globals, and covers
just-in-time script localization for autosave, mce-view, and word-count in an
isolated child process so `AUTOSAVE_INTERVAL` does not leak into the parent. It
avoids admin/page dispatch, process exits, live HTTP, live DB-backed block
queries, and browser module execution while still asserting restored globals,
filters, and output buffers.
The
Admin Workflows surface intentionally avoids `admin.php`/`admin-ajax.php` request
dispatch, direct DB-backed concrete `WP_*_List_Table` subclasses, and direct
`die()` or destructive `wp_ajax_*` wrappers. It covers the base list table API
with synthetic items, records scoped accounting for concrete list-table coverage
owned by `admin-list-tables` and `privacy-admin-requests`, referer helpers where
valid nonces or `stop=false` avoid exits, and deterministic date/time AJAX
wrappers through captured `wp_die()` termination. This is not full admin page
dispatch coverage. The `admin-ajax` surface calls selected `wp_ajax_*` handlers
directly with captured `wp_die()` termination; attachment workflows use
synthetic attachment rows, bounded capability grants, and narrow post MIME LIKE
support in the in-memory `wpdb` stub. The `admin-list-tables` surface complements
that base coverage by loading concrete `WP_*_List_Table` subclasses with
synthetic rows, object-cache fixtures, temporary plugin/theme/network-theme
metadata, exact row-action nonce checks, and `posts_pre_query`,
`comments_pre_query`, `terms_pre_query`, `users_pre_query`, and
`sites_pre_query` short-circuits. It intentionally skips full admin dispatch,
install/update tables, destructive plugin/theme operations, real uploads, and
true multisite write paths; privacy request tables and their AJAX handlers live
in the dedicated `privacy-admin-requests` surface, and network site/user rows
remain synthetic when the shared PHP process is not in multisite mode. The
`post-embeds` surface covers direct provider helpers and the oEmbed item
controller without loading the full embed template, dispatching theme rendering,
performing remote discovery, or requiring generated build artifacts; when the
source checkout lacks the built `wp-embed.js` file it suppresses only that
expected file-read warning while still asserting the generated embed markup
shape. The
`appearance-media` surface covers custom background/header/site icon helpers
without invoking media uploads, image crops, AJAX actions, or admin page
dispatch. The
block templates surface short-circuits template CPT queries through
`posts_pre_query` and records the current direct-ID traversal behavior as a
guarded skip while still asserting that file enumeration remains confined. The
`block-widgets` surface exercises `WP_Widget_Block`, sidebars widget option
mapping, and `retrieve_widgets()` remap/lost-widget recovery without loading the
browser widgets editor, performing REST persistence, or depending on theme
files. The
Admin Media Chrome surface intentionally avoids upload dispatch, real
attachments created by browser flows, `wp_media_attach_action()` redirects,
media modal runtime behavior, and browser-side image editor UI; server-side
image-edit AJAX save, preview, crop, restore, and sub-size request branches are
covered by `media-image-edit-requests`. The surface covers direct server-side
helpers with synthetic attachment rows and cache/filter-backed metadata only. The
base `customizer` surface intentionally avoids changeset save/publish,
nav-menu persistence, widget persistence, and real post/option storage beyond
the existing no-DB option stub. `customizer-persistence` covers changesets and
custom CSS persistence, while `customizer-nav-widgets-requests` covers bounded
nav-menu/widget request, remap, and selective-refresh persistence paths against
the in-memory `wpdb` stub. The `admin-options-submission` surface covers the
bounded `wp-admin/options.php` submission/update branch without loading the full
admin bootstrap, redirects, or process exits. The `options-autoload` surface
uses that same bounded in-memory option table and object cache, and deliberately
focuses on core option/autoload/cache/hook semantics rather than admin form
submission, network options, transients, or arbitrary SQL support. The
`media-editor` surface short-circuits attachment metadata updates and
intentionally avoids media paths that insert attachments, create cover-image
attachments, process audio/video thumbnails, or otherwise require real postmeta
writes. The `media-image-edit-requests` surface complements it with bounded
in-memory attachment rows, temp upload roots, captured AJAX termination, and a
fake `WP_Image_Editor`; it avoids codec-dependent image decoding and
subprocess-only `IMAGE_EDIT_OVERWRITE` branches. The `multisite` surface leaves `MULTISITE`
disabled for the shared PHP process; true multisite `sitemeta` write paths and
site creation/update/deletion run only in an isolated subprocess when a readable
`wp-tests-config.php` points at a real multisite test database, and that row
skips explicitly when no such config is available or the configured database is
not reachable. Broader DB-backed multisite query execution remains
short-circuited through filters, while non-multisite network-option CRUD
remains covered by the existing option stub. The Site
Health surface avoids loopback, WordPress.org, REST availability, update
download, mail, cron, and filesystem-writing checks unless they are fully
short-circuited. The mail
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
`rest-object-controllers` against the in-memory `wpdb` stub. The
`rest-application-passwords` surface complements lower-level account-security
coverage by dispatching the REST controller with synthetic users and scoped
application-password metadata.
`rest-media-attachments` covers the bounded REST attachment upload write path
using raw request bodies, temp upload roots, attachment postmeta, response
projection, metadata finalization, and permission gates. It intentionally avoids
multipart success paths that depend on PHP's `is_uploaded_file()` state, remote
sideload downloads, and admin image-edit request paths, which are covered by
`media-image-edit-requests` when they can be kept process-local and
codec-independent. `rest-widgets-sidebars` complements the lower-level widget
surfaces by exercising REST controller permissions, schemas, instance encoding,
sidebar mutation, legacy form-data paths, and the `/widget-types/{id}/render`
iframe endpoint directly. The render path runs in an isolated child process so
the global `IFRAME_REQUEST` constant cannot leak into the shared runner. The
object surface records explicit skip rows for template controllers that depend
on block-theme filesystem state and template CPT queries. Broad collection
query translation for posts, attachments, revisions, users, comments, and terms
is covered through REST query filters, query-class pre-query short-circuits,
HEAD pagination headers, no broad SQL execution, and filter restoration. It
documents limits for invalid enum-error formatting
branches that are not warning-safe under the stripped bootstrap. The
registry-backed surface covers plugin/theme controller route, schema,
collection parameter, sanitizer, and permission-gate contracts without
plugin/theme filesystem lifecycle effects. Lifecycle-heavy plugin/theme read and
status paths are covered by `plugin-theme-lifecycle`, while install/update/delete
controller methods are still avoided. Block pattern coverage is registry-backed only:
remote pattern and current-theme pattern loaders are short-circuited.
The `rest-site-editor` surface creates a bounded temp theme under the harness
`WP_CONTENT_DIR` and uses the in-memory `wpdb` stub for `wp_global_styles`,
`wp_template`, `wp_template_part`, `revision`, and `wp_navigation` fixtures. It
dispatches only bounded template/template-part item routes and the lookup
fallback route through `WP_REST_Server`; broad template collection queries and
unbounded theme/template CPT query paths remain skipped. Export coverage uses
the direct ZIP generator and a subprocess-isolated
`WP_REST_Edit_Site_Export_Controller::export()` oracle with bounded
`pre_get_block_templates` fixtures, structured stdout metadata, streamed ZIP
inspection, generated ZIP cleanup checks, and parent-process state restoration.
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
metadata lifecycle APIs; it is not a general SQL engine. Post metadata coverage
asserts add/read/update/delete, unique keys, serialized array values, cache
invalidation, metadata hooks, and post-delete cleanup. Post-to-term relationship
coverage asserts category/tag set/append/replace/remove/delete helpers, object
term field modes, relationship caches, hook payloads, and post-delete cleanup.
Post status transition coverage asserts hook order, direct-transition status
storage behavior, count/timeinfo cache invalidation and preservation branches,
empty-GUID publish repair, scheduled future-post hook clearing, and cleanup of
the generated status post type and hooks.
Broader taxonomy relationship behavior is covered by `taxonomy-relationships`,
still limited to the recognized term relationship SQL shapes emitted by the
targeted core APIs.
The `taxonomy` surface remains no-DB; term-query coverage short-circuits through
`terms_pre_query` and asserts query parsing/filter contracts rather than SQL
hydration.
The `bookmark-links` surface extends that stub only for the `wp_links` shapes
emitted by bookmark APIs and safe link CRUD: ID lookups, visibility/search,
include/exclude/category joins through `link_category`, supported order/limit
clauses, and `link_id` projections. It intentionally does not emulate arbitrary
link-manager SQL, admin page dispatch, or live database behavior.
The `auth-flow` surface short-circuits auth cookie sending and avoids browser
redirect/login-form dispatch, real mail, application-password API requests, and
process-exit paths.
The `canonical-routing` surface calls `redirect_canonical()` with
`do_redirect=false`; 404 permalink guessing uses bounded in-memory post rows,
while old-slug redirects, attachment-page redirects, and paths that call
`wp_redirect()` and `exit` are intentionally avoided.
The `classic-walkers` surface uses synthetic objects, object-cache fixtures, and
local filters only. It loads the single comment/admin walker class files when
available, but avoids nav menu AJAX quick-search, meta-box pagination, browser
admin page dispatch, and any DB-backed menu/page/category/comment queries.
The `import-diff` surface covers importer registry, importer form, imported
post/comment lookup, text diff, and WP_Error transfer/lifecycle helpers without
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
including package signature soft-fail/hard-fail paths, removes returned temp
files, routes successful sideloads through a temp upload root, and asserts that
its HTTP, signature, upload, extension, and error-body filters are removed after
each check. It never lets unregistered remote URLs fall through to the live HTTP
transport.
The `comment-workflow` surface uses the bounded content/comment rows in the
in-memory `wpdb` stub and avoids notification mail, browser cookie writes, and
`wp_die()` paths by requesting `WP_Error` returns or using non-exiting helpers.
The `revisions-autosaves` surface uses the bounded post and postmeta rows in
the in-memory `wpdb` stub. It exercises autosave creation/update/delete,
post-lock windows, protected revision field filters, revision title/list
helpers, latest revision count and URL helpers, user-filtered autosave lookup,
post type support gates, and restore action/edit-user side effects without
browser dispatch. The in-memory `wpdb` post query stub supports the bounded
`found_posts` and `post_author` equality/`IN` shapes needed by these helpers,
including author intersections across status-`OR` branches. The surface avoids
browser/admin-template or request-dispatch helpers such as `_show_post_preview()`
and `wp_print_revision_templates()`.
The `feed-rendering` surface renders core feed templates through synthetic
`WP_Query` loops backed by the in-memory `wpdb` stub. It avoids live HTTP
headers, remote enclosures, DB-backed query execution, and arbitrary invalid
bytes so XML structure and escaping remain useful oracles. Direct self-link
helper coverage mutates `REQUEST_URI`/`HTTP_HOST` without dispatching requests.
The `community-events` surface short-circuits `wp_remote_get()` through
`pre_http_request`; it never contacts api.wordpress.org and limits coverage to
request construction, response normalization, cache behavior, and local helper
contracts rather than dashboard browser rendering.
The `editor-helpers` surface exercises classic editor settings and generated
markup without loading browser editors. It avoids live TinyMCE/Quicktags
execution, external asset fetching, admin page dispatch, DB-backed link queries,
and AJAX media-shortcode preview paths.
The `user-preferences` surface exercises `set_screen_options()` and AJAX
preference handlers in a subprocess so redirect, raw `exit`, and `wp_die()`
paths cannot terminate the parent fuzz runner. It still covers the underlying
user-setting, user-option precedence/deletion, screen visibility caching, and
screen preference helpers directly in-process.
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
