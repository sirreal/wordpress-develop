# WordPress HTML API Rust WASM

WebAssembly build of the WordPress HTML API Rust implementation with an ES
module JavaScript wrapper.

## Usage

```js
import { loadWasm } from "wp-html-api-rust-wasm";

const { WP_HTML_Processor } = await loadWasm();
const processor = WP_HTML_Processor.create_fragment("<main><p>Hello");

processor.next_tag("p");
console.log(processor.get_breadcrumbs());

processor.destroy();
```

`loadWasm()` instantiates the bundled `dist/wp_html_api_rust_core.wasm` module
by default and returns the WASM-bound API:

- `WP_HTML_Decoder`
- `WP_HTML_Tag_Processor`
- `WP_HTML_Processor`
- `WP_HTML_Doctype_Info`
- `WP_HTML_Span`
- `WP_HTML_Text_Replacement`
- `WP_HTML_Attribute_Token`
- `WP_HTML_Token`
- `WP_HTML_Stack_Event`
- `WP_HTML_Active_Formatting_Elements`
- `WP_HTML_Open_Elements`
- `WP_HTML_Processor_State`
- `WP_HTML_Unsupported_Exception`
- `scanNextTag()`
- `version()`
- `wasm`

The package also exports `createHtmlApi()` and the value/helper classes that do
not require an instantiated WASM module.

## Loading WASM

`loadWasm()` accepts the default bundled WASM URL, a path or URL string, `URL`,
`Request`, `Response`, `Blob`, `ArrayBuffer`, typed array/DataView,
`WebAssembly.Module`, `WebAssembly.Instance`, raw `WebAssembly.Exports`, or an
instantiated source object returned by `WebAssembly.instantiate()`.
Browser `fetch` and `Response` inputs use streaming WASM instantiation when
available, with byte-buffer loading as a fallback.

The WASM asset is exported for consumers that need an explicit URL:

```js
const wasmUrl = import.meta.resolve(
	"wp-html-api-rust-wasm/dist/wp_html_api_rust_core.wasm",
);
const api = await loadWasm(wasmUrl);
```

## Parser Support

The JavaScript API mirrors the public WordPress HTML API classes with
JavaScript naming and TypeScript declarations. The low-level tag processor is
implemented by the Rust/WASM core. The processor layer adds JavaScript-side
tree-state tracking for common HTML breadcrumbs, namespace handling, implied
closures, serialization, and unsupported-parser diagnostics.

The html5lib tree-construction harness runs with zero unsupported cases, aside
from the known WordPress duplicate shell-attribute skips.
`WP_HTML_Unsupported_Exception` remains part of the public API for guarded
parser states, such as tentative encoding detection from unsupported META tags.

## Testing

Run the dependency-free Node/package/html5lib checks with:

```sh
npm run test:all
```

Run the browser smoke test with:

```sh
npm run test:browser
```

Target html5lib tree-construction cases by fixture name or input markup:

```sh
HTML5LIB_TEST_FILTER=tests19 npm run test:html5lib
HTML5LIB_TEST_HTML_FILTER=frameset HTML5LIB_UNSUPPORTED_SAMPLES=10 npm run test:html5lib
HTML5LIB_TEST_CONTEXT_FILTER='svg svg' npm run test:html5lib
```

Known skipped tests remain skipped in focused runs unless
`HTML5LIB_INCLUDE_KNOWN_SKIPS=1` is set. Use `document` as the context filter
for full-document html5lib cases.

The browser smoke test uses the WordPress checkout's Playwright development
dependency when available, otherwise it falls back to an installed
Chrome-compatible browser. It loads `browser-test.html` over a local server and
verifies that the ES module can fetch and instantiate the bundled WASM asset in
a browser.
