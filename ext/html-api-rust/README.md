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

Build the WebAssembly module and JavaScript API wrapper with:

```sh
cd ext/html-api-rust
./build-wasm.sh
node wasm/smoke-test.mjs
```

The JavaScript wrapper is an ES module in `wasm/wp-html-api-rust.js`. It exposes
`loadWasm()`, `WP_HTML_Tag_Processor`, a shallow `WP_HTML_Processor` facade,
`scanNextTag()`, and `version()`. The tag processor methods call the same Rust
core used by the PHP extension. The processor facade currently exposes the
constructor/static factory shape and simple token-derived helpers; full
tree-construction behavior remains in the PHP `WP_HTML_Processor` layer.
