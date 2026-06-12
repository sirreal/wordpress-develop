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
npm --prefix wasm run build
npm --prefix wasm run test:all
```

Regenerate the Rust HTML5 named-character-reference table with:

```sh
node scripts/generate-html5-named-character-references.mjs
```

The JavaScript wrapper is an ES module in `wasm/wp-html-api-rust.js`. It directly
exports `loadWasm()`, `createHtmlApi()`, `WP_HTML_Span`,
`WP_HTML_Text_Replacement`, `WP_HTML_Attribute_Token`, `WP_HTML_Token`,
`WP_HTML_Stack_Event`, `WP_HTML_Active_Formatting_Elements`,
`WP_HTML_Open_Elements`, `WP_HTML_Processor_State`,
`WP_HTML_Unsupported_Exception`, and `WP_HTML_Doctype_Info`.

Call `loadWasm()` to instantiate the WASM module and receive the WASM-bound API:
`WP_HTML_Decoder`, `WP_HTML_Tag_Processor`, `WP_HTML_Processor`,
`scanNextTag()`, `version()`, the support classes, and the raw `wasm` exports.
The tag processor methods call the same Rust core used by the PHP extension. The
JavaScript API surface mirrors the public WordPress HTML API classes with
JavaScript naming, including processor factory/static helpers, constants,
bookmark methods, doctype parsing, serialization helpers, and inherited
tag-processor methods.

```js
import { loadWasm } from "./wasm/wp-html-api-rust.js";

const { WP_HTML_Processor } = await loadWasm();
const processor = WP_HTML_Processor.create_fragment("<p>Hello</p>");
processor.next_tag("p");
console.log(processor.get_breadcrumbs());
processor.destroy();
```

`loadWasm()` accepts the default bundled WASM URL, a path or URL string,
`URL`, `Request`, `Response`, `Blob`, `ArrayBuffer`, typed array/DataView,
`WebAssembly.Module`, `WebAssembly.Instance`, raw `WebAssembly.Exports`, or an
instantiated source object returned by `WebAssembly.instantiate()`.

The processor layer adds JavaScript-side open-element stack tracking for HTML
breadcrumbs, breadcrumb queries, void-element handling, namespaces, scoped end
tags, frameset handling, implied closures, foster parenting, and adoption-agency
reconstruction. The html5lib tree-construction harness runs with zero
unsupported cases, aside from the known WordPress duplicate shell-attribute
skips. `WP_HTML_Unsupported_Exception` remains part of the public API for
guarded parser states, such as tentative encoding detection from unsupported
META tags.
