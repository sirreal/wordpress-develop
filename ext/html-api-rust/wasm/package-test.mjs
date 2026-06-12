import assert from "node:assert/strict";
import * as PackageModule from "wp-html-api-rust-wasm";
import {
	loadWasm,
	WP_HTML_Span,
} from "wp-html-api-rust-wasm";

assert.equal(PackageModule.loadWasm, loadWasm);
assert.equal(typeof PackageModule.createHtmlApi, "function");
assert.equal(new WP_HTML_Span(1, 2).length, 2);

const api = await loadWasm();
assert.equal(api.version(), "0.1.0");

const processor = api.WP_HTML_Processor.create_fragment("<p>Hello");
assert.notEqual(processor, null);
assert.equal(processor.next_tag("p"), true);
assert.deepEqual(processor.get_breadcrumbs(), ["HTML", "BODY", "P"]);
processor.destroy();

const wasmUrl = new URL(import.meta.resolve("wp-html-api-rust-wasm/dist/wp_html_api_rust_core.wasm"));
const apiFromExportedWasmPath = await loadWasm(wasmUrl);
assert.equal(apiFromExportedWasmPath.version(), "0.1.0");

console.log("WASM package tests passed.");
