PHP_ARG_ENABLE(
  [wp-html-api-rust],
  [whether to enable the WordPress HTML API Rust extension],
  [AS_HELP_STRING([--enable-wp-html-api-rust], [Enable WordPress HTML API Rust extension])],
  [no]
)

if test "$PHP_WP_HTML_API_RUST" != "no"; then
  PHP_SUBST(WP_HTML_API_RUST_SHARED_LIBADD)
  PHP_ADD_LIBRARY_WITH_PATH(wp_html_api_rust_core, $abs_srcdir/target/release, WP_HTML_API_RUST_SHARED_LIBADD)

  PHP_NEW_EXTENSION([wp_html_api_rust], [wp_html_api_rust.c], [$ext_shared])
fi
