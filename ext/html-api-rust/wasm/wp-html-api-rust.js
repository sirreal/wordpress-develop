const TOKEN_TYPE_TAG = 1;
const TOKEN_TYPE_TEXT = 2;
const TOKEN_TYPE_COMMENT = 3;
const TOKEN_TYPE_DOCTYPE = 4;
const TOKEN_TYPE_CDATA = 5;
const TOKEN_TYPE_PRESUMPTUOUS_TAG = 6;
const TOKEN_TYPE_FUNKY_COMMENT = 7;

const STATE_READY = "STATE_READY";
const STATE_COMPLETE = "STATE_COMPLETE";
const STATE_INCOMPLETE_INPUT = "STATE_INCOMPLETE_INPUT";
const STATE_MATCHED_TAG = "STATE_MATCHED_TAG";
const STATE_TEXT_NODE = "STATE_TEXT_NODE";
const STATE_CDATA_NODE = "STATE_CDATA_NODE";
const STATE_COMMENT = "STATE_COMMENT";
const STATE_DOCTYPE = "STATE_DOCTYPE";
const STATE_PRESUMPTUOUS_TAG = "STATE_PRESUMPTUOUS_TAG";
const STATE_FUNKY_COMMENT = "STATE_WP_FUNKY";

const COMMENT_TYPES = new Map([
	[1, "COMMENT_AS_ABRUPTLY_CLOSED_COMMENT"],
	[2, "COMMENT_AS_CDATA_LOOKALIKE"],
	[3, "COMMENT_AS_HTML_COMMENT"],
	[4, "COMMENT_AS_PI_NODE_LOOKALIKE"],
	[5, "COMMENT_AS_INVALID_HTML"],
]);

const TOKEN_NAMES = new Map([
	[STATE_TEXT_NODE, "#text"],
	[STATE_COMMENT, "#comment"],
	[STATE_DOCTYPE, "html"],
	[STATE_CDATA_NODE, "#cdata-section"],
	[STATE_PRESUMPTUOUS_TAG, "#presumptuous-tag"],
	[STATE_FUNKY_COMMENT, "#funky-comment"],
]);

const TOKEN_TYPES = new Map([
	[STATE_MATCHED_TAG, "#tag"],
	[STATE_DOCTYPE, "#doctype"],
	[STATE_TEXT_NODE, "#text"],
	[STATE_COMMENT, "#comment"],
	[STATE_CDATA_NODE, "#cdata-section"],
	[STATE_PRESUMPTUOUS_TAG, "#presumptuous-tag"],
	[STATE_FUNKY_COMMENT, "#funky-comment"],
]);

const VOID_ELEMENTS = new Set([
	"AREA",
	"BASE",
	"BR",
	"COL",
	"EMBED",
	"HR",
	"IMG",
	"INPUT",
	"LINK",
	"META",
	"PARAM",
	"SOURCE",
	"TRACK",
	"WBR",
]);

const textEncoder = new TextEncoder();
const textDecoder = new TextDecoder();

async function bytesFromInput(input) {
	if (input instanceof WebAssembly.Module) {
		return input;
	}

	if (input instanceof ArrayBuffer) {
		return input;
	}

	if (ArrayBuffer.isView(input)) {
		return input;
	}

	if (input instanceof URL && input.protocol === "file:") {
		const [{ readFile }, { fileURLToPath }] = await Promise.all([
			import("node:fs/promises"),
			import("node:url"),
		]);
		return readFile(fileURLToPath(input));
	}

	if (typeof input === "string") {
		if (/^https?:\/\//.test(input) && typeof fetch === "function") {
			const response = await fetch(input);
			if (!response.ok) {
				throw new Error(`Failed to load WASM: ${response.status} ${response.statusText}`);
			}
			return response.arrayBuffer();
		}

		const { readFile } = await import("node:fs/promises");
		return readFile(input);
	}

	if (typeof fetch === "function") {
		const response = await fetch(input);
		if (!response.ok) {
			throw new Error(`Failed to load WASM: ${response.status} ${response.statusText}`);
		}
		return response.arrayBuffer();
	}

	throw new TypeError("Unsupported WASM input.");
}

export async function loadWasm(input = new URL("./dist/wp_html_api_rust_core.wasm", import.meta.url)) {
	const source = await bytesFromInput(input);
	const module = source instanceof WebAssembly.Module ? source : await WebAssembly.compile(source);
	const instance = await WebAssembly.instantiate(module, {});

	return createHtmlApi(instance.exports);
}

export function createHtmlApi(wasm) {
	const runtime = new WasmRuntime(wasm);

	class WP_HTML_Tag_Processor {
		static MAX_BOOKMARKS = 10;
		static MAX_SEEK_OPS = 1000;
		static ADD_CLASS = true;
		static REMOVE_CLASS = false;
		static SKIP_CLASS = null;
		static STATE_READY = STATE_READY;
		static STATE_COMPLETE = STATE_COMPLETE;
		static STATE_INCOMPLETE_INPUT = STATE_INCOMPLETE_INPUT;
		static STATE_MATCHED_TAG = STATE_MATCHED_TAG;
		static STATE_TEXT_NODE = STATE_TEXT_NODE;
		static STATE_CDATA_NODE = STATE_CDATA_NODE;
		static STATE_COMMENT = STATE_COMMENT;
		static STATE_DOCTYPE = STATE_DOCTYPE;
		static STATE_PRESUMPTUOUS_TAG = STATE_PRESUMPTUOUS_TAG;
		static STATE_FUNKY_COMMENT = STATE_FUNKY_COMMENT;
		static TEXT_IS_GENERIC = "TEXT_IS_GENERIC";
		static TEXT_IS_NULL_SEQUENCE = "TEXT_IS_NULL_SEQUENCE";
		static TEXT_IS_WHITESPACE = "TEXT_IS_WHITESPACE";
		static NO_QUIRKS_MODE = "no-quirks-mode";
		static QUIRKS_MODE = "quirks-mode";

		constructor(html) {
			this.parser_state = STATE_READY;
			this.compat_mode = WP_HTML_Tag_Processor.NO_QUIRKS_MODE;
			this.parsing_namespace = "html";
			this.text_node_classification = WP_HTML_Tag_Processor.TEXT_IS_GENERIC;
			this.comment_type = null;
			this.bookmarks = new Map();
			this.seek_count = 0;

			const input = runtime.encode(html);
			const allocated = runtime.allocBytes(input);
			try {
				this.pointer = wasm.wp_html_api_rust_tag_processor_new(allocated.ptr, input.length);
			} finally {
				runtime.freeBytes(allocated);
			}

			if (!this.pointer) {
				throw new Error("Failed to initialize WP_HTML_Tag_Processor WASM state.");
			}

			this.html = this.get_updated_html();
		}

		destroy() {
			if (this.pointer) {
				wasm.wp_html_api_rust_tag_processor_free(this.pointer);
				this.pointer = 0;
			}
		}

		free() {
			this.destroy();
		}

		next_tag(query = undefined) {
			this.#ensureLive();
			this.#syncLexicalUpdates();

			let tagName = null;
			let className = null;
			let matchOffset = 1;
			let visitClosers = false;

			if (typeof query === "string") {
				tagName = query;
			} else if (query && typeof query === "object") {
				if (typeof query.tag_name === "string") {
					tagName = query.tag_name;
				}
				if (typeof query.class_name === "string") {
					className = query.class_name;
				}
				if (Number.isInteger(query.match_offset) && query.match_offset > 0) {
					matchOffset = query.match_offset;
				}
				visitClosers = query.tag_closers === "visit" || query.visit_closers === true;
			}

			let found = 0;
			while (this.#nativeNextTag(tagName, visitClosers)) {
				if (className !== null && this.has_class(className) !== true) {
					continue;
				}

				found += 1;
				if (found < matchOffset) {
					continue;
				}

				this.#updateParserStateFromNative();
				return true;
			}

			this.parser_state = wasm.wp_html_api_rust_tag_processor_paused_at_incomplete(this.pointer)
				? STATE_INCOMPLETE_INPUT
				: STATE_COMPLETE;
			return false;
		}

		next_token() {
			this.#ensureLive();
			this.#syncLexicalUpdates();

			if (wasm.wp_html_api_rust_tag_processor_next_token(this.pointer)) {
				this.#updateParserStateFromNative();
				return true;
			}

			this.parser_state = wasm.wp_html_api_rust_tag_processor_paused_at_incomplete(this.pointer)
				? STATE_INCOMPLETE_INPUT
				: STATE_COMPLETE;
			return false;
		}

		get_tag() {
			this.#ensureLive();
			if (this.parser_state === STATE_COMMENT && wasm.wp_html_api_rust_tag_processor_current_comment_type(this.pointer) !== 4) {
				return null;
			}

			const tagName = runtime.readOutputString((out) => (
				wasm.wp_html_api_rust_tag_processor_get_tag(this.pointer, out)
			));

			return tagName === null ? null : asciiUpper(tagName);
		}

		get_attribute(name) {
			this.#ensureLive();
			if (this.parser_state !== STATE_MATCHED_TAG) {
				return null;
			}

			return runtime.withEncoded(name, ({ ptr, len }) => runtime.withOutSlice((out) => {
				const result = wasm.wp_html_api_rust_tag_processor_get_attribute(this.pointer, ptr, len, out);
				if (result === 0) {
					return null;
				}
				if (result === 1) {
					return true;
				}
				return runtime.readStringFromOut(out);
			}));
		}

		get_attribute_names_with_prefix(prefix) {
			this.#ensureLive();
			if (this.parser_state !== STATE_MATCHED_TAG) {
				return null;
			}

			return runtime.withEncoded(prefix, ({ ptr, len }) => runtime.withOutSlice((out) => {
				const result = wasm.wp_html_api_rust_tag_processor_get_attribute_names_with_prefix(this.pointer, ptr, len, out);
				if (result === 0) {
					return null;
				}
				const bytes = runtime.readBytesFromOut(out);
				return splitNullSeparatedAscii(bytes);
			}));
		}

		set_attribute(name, value) {
			this.#ensureLive();
			if (this.parser_state !== STATE_MATCHED_TAG) {
				return false;
			}

			let valueKind = 2;
			let encodedValue = new Uint8Array();
			if (value === false) {
				valueKind = 0;
			} else if (value === true) {
				valueKind = 1;
			} else {
				encodedValue = runtime.encode(value);
			}

			return this.#mutateCurrentToken(() => runtime.withEncoded(name, (nameBytes) => (
				runtime.withBytes(encodedValue, (valueBytes) => (
					wasm.wp_html_api_rust_tag_processor_set_attribute(
						this.pointer,
						nameBytes.ptr,
						nameBytes.len,
						valueBytes.ptr,
						valueBytes.len,
						valueKind,
					)
				))
			)));
		}

		remove_attribute(name) {
			this.#ensureLive();
			if (this.parser_state !== STATE_MATCHED_TAG) {
				return false;
			}

			return this.#mutateCurrentToken(() => runtime.withEncoded(name, ({ ptr, len }) => (
				wasm.wp_html_api_rust_tag_processor_remove_attribute(this.pointer, ptr, len)
			)));
		}

		add_class(className) {
			this.#ensureLive();
			if (this.parser_state !== STATE_MATCHED_TAG) {
				return false;
			}

			return this.#mutateCurrentToken(() => runtime.withEncoded(className, ({ ptr, len }) => (
				wasm.wp_html_api_rust_tag_processor_add_class(this.pointer, ptr, len, this.#isQuirksMode())
			)));
		}

		remove_class(className) {
			this.#ensureLive();
			if (this.parser_state !== STATE_MATCHED_TAG) {
				return false;
			}

			return this.#mutateCurrentToken(() => runtime.withEncoded(className, ({ ptr, len }) => (
				wasm.wp_html_api_rust_tag_processor_remove_class(this.pointer, ptr, len, this.#isQuirksMode())
			)));
		}

		has_class(className) {
			this.#ensureLive();
			if (this.parser_state !== STATE_MATCHED_TAG) {
				return null;
			}

			return runtime.withEncoded(className, ({ ptr, len }) => {
				const result = wasm.wp_html_api_rust_tag_processor_has_class(this.pointer, ptr, len, this.#isQuirksMode());
				return result === 0 ? null : result === 2;
			});
		}

		class_list() {
			this.#ensureLive();
			if (this.parser_state !== STATE_MATCHED_TAG) {
				return null;
			}

			return runtime.withOutSlice((out) => {
				if (wasm.wp_html_api_rust_tag_processor_class_list(this.pointer, out, this.#isQuirksMode()) === 0) {
					return null;
				}

				return splitUnitSeparatedString(runtime.readStringFromOut(out));
			});
		}

		is_tag_closer() {
			this.#ensureLive();
			return Boolean(wasm.wp_html_api_rust_tag_processor_is_tag_closer(this.pointer));
		}

		has_self_closing_flag() {
			this.#ensureLive();
			return Boolean(wasm.wp_html_api_rust_tag_processor_has_self_closing_flag(this.pointer));
		}

		get_token_name() {
			this.#ensureLive();
			if (TOKEN_NAMES.has(this.parser_state)) {
				return TOKEN_NAMES.get(this.parser_state);
			}
			return this.parser_state === STATE_MATCHED_TAG ? this.get_tag() : null;
		}

		get_token_type() {
			this.#ensureLive();
			return TOKEN_TYPES.get(this.parser_state) ?? null;
		}

		paused_at_incomplete_token() {
			this.#ensureLive();
			return Boolean(wasm.wp_html_api_rust_tag_processor_paused_at_incomplete(this.pointer));
		}

		subdivide_text_appropriately() {
			this.#ensureLive();
			if (this.parser_state !== STATE_TEXT_NODE) {
				return false;
			}

			this.text_node_classification = WP_HTML_Tag_Processor.TEXT_IS_GENERIC;
			const classification = wasm.wp_html_api_rust_tag_processor_subdivide_text_appropriately(this.pointer);
			if (classification === 1) {
				this.text_node_classification = WP_HTML_Tag_Processor.TEXT_IS_NULL_SEQUENCE;
				return true;
			}
			if (classification === 2) {
				this.text_node_classification = WP_HTML_Tag_Processor.TEXT_IS_WHITESPACE;
				return true;
			}
			return false;
		}

		get_modifiable_text() {
			this.#ensureLive();
			return runtime.readOutputString((out) => (
				wasm.wp_html_api_rust_tag_processor_get_modifiable_text(this.pointer, out)
			));
		}

		set_modifiable_text(text) {
			this.#ensureLive();
			if (![STATE_MATCHED_TAG, STATE_TEXT_NODE, STATE_COMMENT].includes(this.parser_state)) {
				return false;
			}

			return this.#mutateCurrentToken(() => runtime.withEncoded(text, ({ ptr, len }) => (
				wasm.wp_html_api_rust_tag_processor_set_modifiable_text(this.pointer, ptr, len)
			)));
		}

		get_comment_type() {
			this.#ensureLive();
			return COMMENT_TYPES.get(wasm.wp_html_api_rust_tag_processor_current_comment_type(this.pointer)) ?? null;
		}

		set_bookmark(name) {
			this.#ensureLive();
			if (this.bookmarks.size >= WP_HTML_Tag_Processor.MAX_BOOKMARKS && !this.bookmarks.has(name)) {
				return false;
			}

			const span = this.#currentSpan();
			if (!span) {
				return false;
			}

			this.bookmarks.set(name, span);
			return true;
		}

		release_bookmark(name) {
			return this.bookmarks.delete(name);
		}

		has_bookmark(name) {
			return this.bookmarks.has(name);
		}

		seek(name) {
			this.#ensureLive();
			if (this.seek_count >= WP_HTML_Tag_Processor.MAX_SEEK_OPS || !this.bookmarks.has(name)) {
				return false;
			}

			this.seek_count += 1;
			const bookmark = this.bookmarks.get(name);
			wasm.wp_html_api_rust_tag_processor_seek(this.pointer, bookmark.start);
			if (!wasm.wp_html_api_rust_tag_processor_next_token(this.pointer)) {
				this.parser_state = wasm.wp_html_api_rust_tag_processor_paused_at_incomplete(this.pointer)
					? STATE_INCOMPLETE_INPUT
					: STATE_COMPLETE;
				return false;
			}

			this.#updateParserStateFromNative();
			return true;
		}

		change_parsing_namespace(namespaceName) {
			this.#ensureLive();
			if (!["html", "math", "svg"].includes(namespaceName)) {
				return false;
			}

			this.parsing_namespace = namespaceName;
			wasm.wp_html_api_rust_tag_processor_set_namespace(this.pointer, namespaceName === "html" ? 0 : 1);
			return true;
		}

		get_namespace() {
			return this.parsing_namespace;
		}

		get_qualified_tag_name() {
			const tagName = this.get_tag();
			if (tagName === null || this.parsing_namespace === "html") {
				return tagName;
			}

			const lower = asciiLower(tagName);
			return this.parsing_namespace === "svg" ? qualifySvgTagName(lower) : lower;
		}

		get_qualified_attribute_name(attributeName) {
			if (this.parsing_namespace === "html") {
				return asciiLower(attributeName);
			}
			return qualifyForeignAttributeName(this.parsing_namespace, asciiLower(attributeName));
		}

		get_full_comment_text() {
			if (![STATE_COMMENT, STATE_FUNKY_COMMENT].includes(this.parser_state)) {
				return null;
			}
			return this.get_modifiable_text();
		}

		get_updated_html() {
			this.#ensureLive();
			return runtime.readOutputString((out) => (
				wasm.wp_html_api_rust_tag_processor_get_html(this.pointer, out)
			)) ?? "";
		}

		toString() {
			return this.get_updated_html();
		}

		#nativeNextTag(tagName, visitClosers) {
			if (tagName === null) {
				return Boolean(wasm.wp_html_api_rust_tag_processor_next_tag(this.pointer, 0, 0, visitClosers));
			}

			return runtime.withEncoded(tagName, ({ ptr, len }) => (
				Boolean(wasm.wp_html_api_rust_tag_processor_next_tag(this.pointer, ptr, len, visitClosers))
			));
		}

		#updateParserStateFromNative() {
			this.text_node_classification = WP_HTML_Tag_Processor.TEXT_IS_GENERIC;
			const tokenType = wasm.wp_html_api_rust_tag_processor_current_token_type(this.pointer);
			switch (tokenType) {
				case TOKEN_TYPE_TAG:
					this.parser_state = STATE_MATCHED_TAG;
					break;
				case TOKEN_TYPE_TEXT:
					this.parser_state = STATE_TEXT_NODE;
					break;
				case TOKEN_TYPE_COMMENT:
					this.parser_state = STATE_COMMENT;
					break;
				case TOKEN_TYPE_DOCTYPE:
					this.parser_state = STATE_DOCTYPE;
					break;
				case TOKEN_TYPE_CDATA:
					this.parser_state = STATE_CDATA_NODE;
					break;
				case TOKEN_TYPE_PRESUMPTUOUS_TAG:
					this.parser_state = STATE_PRESUMPTUOUS_TAG;
					break;
				case TOKEN_TYPE_FUNKY_COMMENT:
					this.parser_state = STATE_FUNKY_COMMENT;
					break;
				default:
					this.parser_state = STATE_READY;
			}
			this.comment_type = this.get_comment_type();
		}

		#currentSpan() {
			return runtime.withOutPair((startPtr, lengthPtr) => {
				if (!wasm.wp_html_api_rust_tag_processor_current_span(this.pointer, startPtr, lengthPtr)) {
					return null;
				}
				return {
					start: runtime.readU32(startPtr),
					length: runtime.readU32(lengthPtr),
				};
			});
		}

		#mutateCurrentToken(callback) {
			const oldSpan = this.#currentSpan();
			const result = Boolean(callback());
			if (!result) {
				return false;
			}

			const newSpan = this.#currentSpan();
			if (oldSpan && newSpan) {
				this.#adjustBookmarks(oldSpan, newSpan);
			}
			this.html = this.get_updated_html();
			return true;
		}

		#adjustBookmarks(oldSpan, newSpan) {
			let delta = newSpan.length - oldSpan.length;
			if (oldSpan.start !== newSpan.start && delta === 0) {
				delta = newSpan.start - oldSpan.start;
			}
			if (delta === 0 && oldSpan.length === newSpan.length) {
				return;
			}

			for (const [name, bookmark] of this.bookmarks.entries()) {
				if (bookmark.start === oldSpan.start) {
					this.bookmarks.set(name, { start: bookmark.start, length: newSpan.length });
				} else if (bookmark.start > oldSpan.start) {
					this.bookmarks.set(name, { start: bookmark.start + delta, length: bookmark.length });
				}
			}
		}

		#syncLexicalUpdates() {
			this.html = this.get_updated_html();
		}

		#isQuirksMode() {
			return this.compat_mode === WP_HTML_Tag_Processor.QUIRKS_MODE;
		}

		#ensureLive() {
			if (!this.pointer) {
				throw new Error("WP_HTML_Tag_Processor has been destroyed.");
			}
		}
	}

	class WP_HTML_Processor extends WP_HTML_Tag_Processor {
		static create_fragment(html) {
			return new this(html);
		}

		static create_full_parser(html) {
			return new this(html);
		}

		get_last_error() {
			return null;
		}

		get_unsupported_exception() {
			return null;
		}

		is_virtual() {
			return false;
		}

		expects_closer() {
			const tagName = this.get_tag();
			return tagName === null || this.is_tag_closer() ? null : !VOID_ELEMENTS.has(tagName);
		}

		get_breadcrumbs() {
			const tagName = this.get_tag();
			return tagName === null ? [] : ["HTML", "BODY", tagName];
		}
	}

	return {
		WP_HTML_Tag_Processor,
		WP_HTML_Processor,
		scanNextTag: (html, offset = 0) => runtime.scanNextTag(html, offset),
		version: () => runtime.version(),
		wasm,
	};
}

class WasmRuntime {
	constructor(wasm) {
		this.wasm = wasm;
		if (!wasm.memory || !wasm.wp_html_api_rust_alloc || !wasm.wp_html_api_rust_dealloc) {
			throw new Error("WASM module does not expose the expected HTML API runtime functions.");
		}
	}

	version() {
		return this.readCString(this.wasm.wp_html_api_rust_core_version());
	}

	encode(value) {
		if (value instanceof Uint8Array) {
			return value;
		}
		if (value instanceof ArrayBuffer) {
			return new Uint8Array(value);
		}
		if (ArrayBuffer.isView(value)) {
			return new Uint8Array(value.buffer, value.byteOffset, value.byteLength);
		}
		return textEncoder.encode(String(value));
	}

	allocBytes(bytes) {
		const len = bytes.length;
		const allocationLen = Math.max(1, len);
		const ptr = this.wasm.wp_html_api_rust_alloc(allocationLen);
		if (!ptr) {
			throw new Error(`Failed to allocate ${allocationLen} bytes in WASM memory.`);
		}
		this.bytes().set(bytes, ptr);
		return { ptr, len, allocationLen };
	}

	freeBytes(allocation) {
		if (allocation && allocation.ptr) {
			this.wasm.wp_html_api_rust_dealloc(allocation.ptr, allocation.allocationLen);
		}
	}

	withBytes(bytes, callback) {
		const allocation = this.allocBytes(bytes);
		try {
			return callback({ ptr: allocation.ptr, len: allocation.len });
		} finally {
			this.freeBytes(allocation);
		}
	}

	withEncoded(value, callback) {
		return this.withBytes(this.encode(value), callback);
	}

	withOutSlice(callback) {
		const allocation = this.allocBytes(new Uint8Array(8));
		try {
			return callback(allocation.ptr);
		} finally {
			this.freeBytes(allocation);
		}
	}

	withOutPair(callback) {
		const allocation = this.allocBytes(new Uint8Array(8));
		try {
			return callback(allocation.ptr, allocation.ptr + 4);
		} finally {
			this.freeBytes(allocation);
		}
	}

	readOutputString(callback) {
		return this.withOutSlice((out) => {
			if (!callback(out)) {
				return null;
			}
			return this.readStringFromOut(out);
		});
	}

	readStringFromOut(out) {
		const { ptr, len } = this.readSlice(out);
		return this.decode(ptr, len);
	}

	readBytesFromOut(out) {
		const { ptr, len } = this.readSlice(out);
		return this.bytes().slice(ptr, ptr + len);
	}

	readSlice(out) {
		return {
			ptr: this.readU32(out),
			len: this.readU32(out + 4),
		};
	}

	readU32(ptr) {
		return new DataView(this.wasm.memory.buffer).getUint32(ptr, true);
	}

	readCString(ptr) {
		const memory = this.bytes();
		let end = ptr;
		while (memory[end] !== 0) {
			end += 1;
		}
		return textDecoder.decode(memory.subarray(ptr, end));
	}

	decode(ptr, len) {
		return textDecoder.decode(this.bytes().subarray(ptr, ptr + len));
	}

	bytes() {
		return new Uint8Array(this.wasm.memory.buffer);
	}

	scanNextTag(html, offset = 0) {
		const input = this.encode(html);
		return this.withBytes(input, ({ ptr, len }) => {
			const out = this.allocBytes(new Uint8Array(32));
			try {
				if (!this.wasm.wp_html_api_rust_scan_next_tag(ptr, len, offset, out.ptr)) {
					return false;
				}

				const tagStart = this.readU32(out.ptr);
				const tagEnd = this.readU32(out.ptr + 4);
				const nameStart = this.readU32(out.ptr + 8);
				const nameLen = this.readU32(out.ptr + 12);
				const memory = this.bytes();
				return {
					tag_start: tagStart,
					tag_end: tagEnd,
					name_start: nameStart,
					name_len: nameLen,
					tag_name: asciiUpper(textDecoder.decode(memory.subarray(ptr + nameStart, ptr + nameStart + nameLen))),
					is_closing: Boolean(memory[out.ptr + 16]),
					has_self_closing_flag: Boolean(memory[out.ptr + 17]),
					token_end: this.readU32(out.ptr + 20),
					token_type: memory[out.ptr + 24],
				};
			} finally {
				this.freeBytes(out);
			}
		});
	}
}

function asciiUpper(value) {
	return value.replace(/[a-z]/g, (char) => char.toUpperCase());
}

function asciiLower(value) {
	return value.replace(/[A-Z]/g, (char) => char.toLowerCase());
}

function splitUnitSeparatedString(value) {
	return value === "" ? [] : value.split("\x1f");
}

function splitNullSeparatedAscii(bytes) {
	if (bytes.length === 0) {
		return [];
	}
	const parts = [];
	let start = 0;
	for (let i = 0; i <= bytes.length; i += 1) {
		if (i === bytes.length || bytes[i] === 0) {
			parts.push(textDecoder.decode(bytes.subarray(start, i)));
			start = i + 1;
		}
	}
	return parts;
}

function qualifySvgTagName(lowerTagName) {
	const adjusted = new Map([
		["altglyph", "altGlyph"],
		["altglyphdef", "altGlyphDef"],
		["altglyphitem", "altGlyphItem"],
		["animatecolor", "animateColor"],
		["animatemotion", "animateMotion"],
		["animatetransform", "animateTransform"],
		["clippath", "clipPath"],
		["feblend", "feBlend"],
		["fecolormatrix", "feColorMatrix"],
		["fecomponenttransfer", "feComponentTransfer"],
		["fecomposite", "feComposite"],
		["feconvolvematrix", "feConvolveMatrix"],
		["fediffuselighting", "feDiffuseLighting"],
		["fedisplacementmap", "feDisplacementMap"],
		["fedistantlight", "feDistantLight"],
		["fedropshadow", "feDropShadow"],
		["feflood", "feFlood"],
		["fefunca", "feFuncA"],
		["fefuncb", "feFuncB"],
		["fefuncg", "feFuncG"],
		["fefuncr", "feFuncR"],
		["fegaussianblur", "feGaussianBlur"],
		["feimage", "feImage"],
		["femerge", "feMerge"],
		["femergenode", "feMergeNode"],
		["femorphology", "feMorphology"],
		["feoffset", "feOffset"],
		["fepointlight", "fePointLight"],
		["fespecularlighting", "feSpecularLighting"],
		["fespotlight", "feSpotLight"],
		["fetile", "feTile"],
		["feturbulence", "feTurbulence"],
		["foreignobject", "foreignObject"],
		["glyphref", "glyphRef"],
		["lineargradient", "linearGradient"],
		["radialgradient", "radialGradient"],
		["textpath", "textPath"],
	]);
	return adjusted.get(lowerTagName) ?? lowerTagName;
}

function qualifyForeignAttributeName(namespaceName, lowerAttributeName) {
	if (namespaceName === "math" && lowerAttributeName === "definitionurl") {
		return "definitionURL";
	}

	const adjusted = new Map([
		["attributename", "attributeName"],
		["attributetype", "attributeType"],
		["basefrequency", "baseFrequency"],
		["baseprofile", "baseProfile"],
		["calcmode", "calcMode"],
		["clippathunits", "clipPathUnits"],
		["diffuseconstant", "diffuseConstant"],
		["edgemode", "edgeMode"],
		["filterunits", "filterUnits"],
		["glyphref", "glyphRef"],
		["gradienttransform", "gradientTransform"],
		["gradientunits", "gradientUnits"],
		["kernelmatrix", "kernelMatrix"],
		["kernelunitlength", "kernelUnitLength"],
		["keypoints", "keyPoints"],
		["keysplines", "keySplines"],
		["keytimes", "keyTimes"],
		["lengthadjust", "lengthAdjust"],
		["limitingconeangle", "limitingConeAngle"],
		["markerheight", "markerHeight"],
		["markerunits", "markerUnits"],
		["markerwidth", "markerWidth"],
		["maskcontentunits", "maskContentUnits"],
		["maskunits", "maskUnits"],
		["numoctaves", "numOctaves"],
		["pathlength", "pathLength"],
		["patterncontentunits", "patternContentUnits"],
		["patterntransform", "patternTransform"],
		["patternunits", "patternUnits"],
		["pointsatx", "pointsAtX"],
		["pointsaty", "pointsAtY"],
		["pointsatz", "pointsAtZ"],
		["preservealpha", "preserveAlpha"],
		["preserveaspectratio", "preserveAspectRatio"],
		["primitiveunits", "primitiveUnits"],
		["refx", "refX"],
		["refy", "refY"],
		["repeatcount", "repeatCount"],
		["repeatdur", "repeatDur"],
		["requiredextensions", "requiredExtensions"],
		["requiredfeatures", "requiredFeatures"],
		["specularconstant", "specularConstant"],
		["specularexponent", "specularExponent"],
		["spreadmethod", "spreadMethod"],
		["startoffset", "startOffset"],
		["stddeviation", "stdDeviation"],
		["stitchtiles", "stitchTiles"],
		["surfacescale", "surfaceScale"],
		["systemlanguage", "systemLanguage"],
		["tablevalues", "tableValues"],
		["targetx", "targetX"],
		["targety", "targetY"],
		["textlength", "textLength"],
		["viewbox", "viewBox"],
		["viewtarget", "viewTarget"],
		["xchannelselector", "xChannelSelector"],
		["ychannelselector", "yChannelSelector"],
		["zoomandpan", "zoomAndPan"],
	]);
	return adjusted.get(lowerAttributeName) ?? lowerAttributeName;
}
