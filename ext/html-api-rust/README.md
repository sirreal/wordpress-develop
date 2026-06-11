# WordPress HTML API Rust Extension

This directory contains the native Rust core and PHP extension shim for the
incremental Rust implementation of the WordPress HTML API.

Build locally with:

```sh
cd ext/html-api-rust
sh build.sh
```

Smoke-test the built extension with:

```sh
php -d extension="$(pwd)/modules/wp_html_api_rust.so" \
	-r 'var_dump(wp_html_api_rust_version(), wp_html_api_rust_scan_next_tag("<p class=\"x\">Hi</p>"));'
```

The first Rust surface is a tokenizer slice for locating the next tag opener.
It is intentionally narrow and will be expanded into the `WP_HTML_Tag_Processor`
state machine before replacing the PHP interface in WordPress bootstrap.
