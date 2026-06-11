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
npm --prefix wasm test
```

Regenerate the Rust HTML5 named-character-reference table with:

```sh
node scripts/generate-html5-named-character-references.mjs
```

The JavaScript wrapper is an ES module in `wasm/wp-html-api-rust.js`. It exposes
`loadWasm()`, `WP_HTML_Decoder`, `WP_HTML_Token`,
`WP_HTML_Unsupported_Exception`, `WP_HTML_Tag_Processor`,
`WP_HTML_Processor`, `WP_HTML_Doctype_Info`, `scanNextTag()`, and
`version()`. The tag processor methods call the same Rust core used by the PHP
extension. The JavaScript API surface mirrors the public WordPress HTML API
classes with JavaScript naming, including processor factory/static helpers,
constants, bookmark methods, doctype parsing, serialization helpers, and
inherited tag-processor methods.

```js
import { loadWasm } from "./wasm/wp-html-api-rust.js";

const { WP_HTML_Processor } = await loadWasm();
const processor = WP_HTML_Processor.create_fragment("<p>Hello</p>");
processor.next_tag("p");
console.log(processor.get_breadcrumbs());
processor.destroy();
```

The processor layer adds JavaScript-side open-element stack tracking for common
HTML breadcrumbs, breadcrumb queries, void-element handling, namespaces, scoped
end tags, and simple implied closures. Full HTML5 tree-construction behavioral
parity remains incomplete.
