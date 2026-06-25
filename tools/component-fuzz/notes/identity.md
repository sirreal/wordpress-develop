# Identity surface

`IdentitySurface` is a pure-PHP, DB-free fuzz surface for identity-adjacent WordPress helpers.

It exercises:

- usernames: `sanitize_user()` and `validate_username()`
- email: `sanitize_email()` and `is_email()`
- capability-like keys: `sanitize_key()`
- author URL parsing/sanitization: `sanitize_url()`, `esc_url_raw()`, `wp_parse_url()`
- comment-ish text helpers: `sanitize_text_field()`, `sanitize_textarea_field()`, `wp_strip_all_tags()`, `wp_filter_nohtml_kses()`
- comment cookie/comment array filtering: `sanitize_comment_cookies()` and `wp_filter_comment()`
- current-user lifecycle: cache-seeded `wp_set_current_user()`, `wp_get_current_user()`, `get_current_user_id()`, `is_user_logged_in()`, legacy setup globals, `set_current_user` action dispatch, legacy object upgrades, and `determine_current_user` discovery
- scalar option sanitization branches that avoid live DB fallback on success
- auth/password helpers: `wp_generate_password()`, `wp_hash_password()`, `wp_check_password()`
- parsing: `wp_parse_str()`

The surface uses a deterministic SHA-256 PRNG seeded from common `FuzzContext` accessors when available. It installs scoped `pre_option_blog_charset`/`WPLANG` short-circuits when the filter API is available so formatting helpers do not need a live options table. Each case snapshots and restores touched global state, notably `$_COOKIE` and the current filter stack marker, while current-user lifecycle coverage also restores current-user/setup globals and hook counters locally. The outer run restores `wp_filter` after temporary filters are removed, with cloned hook snapshots so existing `WP_Hook` instances are not mutated through shallow snapshot references.
