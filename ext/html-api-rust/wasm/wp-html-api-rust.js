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
	"BASEFONT",
	"BGSOUND",
	"BR",
	"COL",
	"EMBED",
	"FRAME",
	"HR",
	"IMG",
	"INPUT",
	"KEYGEN",
	"LINK",
	"META",
	"PARAM",
	"SOURCE",
	"TRACK",
	"WBR",
]);

const SPECIAL_ATOMIC_ELEMENTS = new Set([
	"IFRAME",
	"NOEMBED",
	"NOFRAMES",
	"SCRIPT",
	"STYLE",
	"TEXTAREA",
	"TITLE",
	"XMP",
]);

const HEADING_ELEMENTS = new Set(["H1", "H2", "H3", "H4", "H5", "H6"]);

const QUIRKS_PUBLIC_IDENTIFIER_PREFIXES = [
	"+//silmaril//dtd html pro v0r11 19970101//",
	"-//as//dtd html 3.0 aswedit + extensions//",
	"-//advasoft ltd//dtd html 3.0 aswedit + extensions//",
	"-//ietf//dtd html 2.0 level 1//",
	"-//ietf//dtd html 2.0 level 2//",
	"-//ietf//dtd html 2.0 strict level 1//",
	"-//ietf//dtd html 2.0 strict level 2//",
	"-//ietf//dtd html 2.0 strict//",
	"-//ietf//dtd html 2.0//",
	"-//ietf//dtd html 2.1e//",
	"-//ietf//dtd html 3.0//",
	"-//ietf//dtd html 3.2 final//",
	"-//ietf//dtd html 3.2//",
	"-//ietf//dtd html 3//",
	"-//ietf//dtd html level 0//",
	"-//ietf//dtd html level 1//",
	"-//ietf//dtd html level 2//",
	"-//ietf//dtd html level 3//",
	"-//ietf//dtd html strict level 0//",
	"-//ietf//dtd html strict level 1//",
	"-//ietf//dtd html strict level 2//",
	"-//ietf//dtd html strict level 3//",
	"-//ietf//dtd html strict//",
	"-//ietf//dtd html//",
	"-//metrius//dtd metrius presentational//",
	"-//microsoft//dtd internet explorer 2.0 html strict//",
	"-//microsoft//dtd internet explorer 2.0 html//",
	"-//microsoft//dtd internet explorer 2.0 tables//",
	"-//microsoft//dtd internet explorer 3.0 html strict//",
	"-//microsoft//dtd internet explorer 3.0 html//",
	"-//microsoft//dtd internet explorer 3.0 tables//",
	"-//netscape comm. corp.//dtd html//",
	"-//netscape comm. corp.//dtd strict html//",
	"-//o'reilly and associates//dtd html 2.0//",
	"-//o'reilly and associates//dtd html extended 1.0//",
	"-//o'reilly and associates//dtd html extended relaxed 1.0//",
	"-//sq//dtd html 2.0 hotmetal + extensions//",
	"-//softquad software//dtd hotmetal pro 6.0::19990601::extensions to html 4.0//",
	"-//softquad//dtd hotmetal pro 4.0::19971010::extensions to html 4.0//",
	"-//spyglass//dtd html 2.0 extended//",
	"-//sun microsystems corp.//dtd hotjava html//",
	"-//sun microsystems corp.//dtd hotjava strict html//",
	"-//w3c//dtd html 3 1995-03-24//",
	"-//w3c//dtd html 3.2 draft//",
	"-//w3c//dtd html 3.2 final//",
	"-//w3c//dtd html 3.2//",
	"-//w3c//dtd html 3.2s draft//",
	"-//w3c//dtd html 4.0 frameset//",
	"-//w3c//dtd html 4.0 transitional//",
	"-//w3c//dtd html experimental 19960712//",
	"-//w3c//dtd html experimental 970421//",
	"-//w3c//dtd w3 html//",
	"-//w3o//dtd w3 html 3.0//",
	"-//webtechs//dtd mozilla html 2.0//",
	"-//webtechs//dtd mozilla html//",
];

const textEncoder = new TextEncoder();
const textDecoder = new TextDecoder();

export class WP_HTML_Doctype_Info {
	constructor(name, publicIdentifier, systemIdentifier, forceQuirksFlag) {
		this.name = name;
		this.public_identifier = publicIdentifier;
		this.system_identifier = systemIdentifier;
		this.indicated_compatibility_mode = doctypeCompatibilityMode(
			name,
			publicIdentifier,
			systemIdentifier,
			forceQuirksFlag,
		);
	}

	static from_doctype_token(doctypeHtml) {
		let doctype = String(doctypeHtml);
		let end = doctype.length - 1;

		if (end < 9 || !asciiStartsWithAt(doctype, "<!DOCTYPE", 0)) {
			return null;
		}

		let at = 9;
		if (doctype[end] !== ">" || doctype.indexOf(">", at) < end) {
			return null;
		}

		doctype = doctype.replace(/\r\n/g, "\n").replace(/\r/g, "\n");
		end = doctype.length - 1;
		at = skipHtmlWhitespace(doctype, at, end);

		if (at >= end) {
			return new WP_HTML_Doctype_Info(null, null, null, true);
		}

		const nameStart = at;
		while (at < end && !isHtmlWhitespaceCode(doctype.charCodeAt(at))) {
			at += 1;
		}
		const name = replaceNulls(doctype.slice(nameStart, at).toLowerCase());

		at = skipHtmlWhitespace(doctype, at, end);
		if (at >= end) {
			return new WP_HTML_Doctype_Info(name, null, null, false);
		}

		if (at + 6 >= end) {
			return new WP_HTML_Doctype_Info(name, null, null, true);
		}

		if (asciiStartsWithAt(doctype, "PUBLIC", at)) {
			at = skipHtmlWhitespace(doctype, at + 6, end);
			if (at >= end) {
				return new WP_HTML_Doctype_Info(name, null, null, true);
			}
			return parsePublicIdentifier(doctype, at, end, name);
		}

		if (asciiStartsWithAt(doctype, "SYSTEM", at)) {
			at = skipHtmlWhitespace(doctype, at + 6, end);
			if (at >= end) {
				return new WP_HTML_Doctype_Info(name, null, null, true);
			}
			return parseSystemIdentifier(doctype, at, end, name, null);
		}

		return new WP_HTML_Doctype_Info(name, null, null, true);
	}
}

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

			if (tagName === null) {
				return null;
			}

			return this.parser_state === STATE_COMMENT && wasm.wp_html_api_rust_tag_processor_current_comment_type(this.pointer) === 4
				? tagName
				: asciiUpper(tagName);
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

		get_doctype_info() {
			this.#ensureLive();
			if (this.parser_state !== STATE_DOCTYPE) {
				return null;
			}

			return WP_HTML_Doctype_Info.from_doctype_token(this.#currentTokenString());
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

			const text = this.get_modifiable_text();
			if (text === null || this.parser_state === STATE_FUNKY_COMMENT) {
				return text;
			}

			switch (wasm.wp_html_api_rust_tag_processor_current_comment_type(this.pointer)) {
				case 1:
				case 3:
					return text;
				case 2:
					return `[CDATA[${text}]]`;
				case 4: {
					const tagName = this.get_tag();
					return tagName === null ? null : `?${tagName}${text}?`;
				}
				case 5: {
					const token = this.#currentTokenBytes();
					return token && token[1] === 0x3f ? `?${text}` : text;
				}
				default:
					return null;
			}
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

		#currentTokenBytes() {
			const span = this.#currentSpan();
			if (!span) {
				return null;
			}

			const html = runtime.readOutputBytes((out) => (
				wasm.wp_html_api_rust_tag_processor_get_html(this.pointer, out)
			));
			return html === null ? null : html.slice(span.start, span.start + span.length);
		}

		#currentTokenString() {
			const token = this.#currentTokenBytes();
			return token === null ? "" : textDecoder.decode(token);
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
		constructor(html, options = {}) {
			super(html);
			this.last_error = null;
			this.unsupported_exception = null;
			this.current_virtual = null;
			this.is_full_parser = Boolean(options.fullParser);
			this.context_node = options.contextNode ?? "BODY";
			this.open_elements = this.is_full_parser ? [] : ["HTML", this.context_node];
			this.open_element_namespaces = this.open_elements.map(() => "html");
			this.breadcrumbs = [...this.open_elements];
			this.current_namespace = contextNamespace(this.context_node);
			this.current_token_namespace = this.current_namespace;
			super.change_parsing_namespace(this.current_namespace);
		}

		static create_fragment(html, context = "<body>") {
			return new this(html, {
				contextNode: contextNodeName(context),
				fullParser: false,
			});
		}

		static create_full_parser(html) {
			return new this(html, {
				fullParser: true,
			});
		}

		static normalize(html) {
			return this.create_fragment(html).serialize();
		}

		static is_void(tagName) {
			return VOID_ELEMENTS.has(asciiUpper(String(tagName)));
		}

		next_tag(query = null) {
			const visitClosers = Boolean(query && typeof query === "object" && query.tag_closers === "visit");

			if (query === null) {
				while (this.next_token()) {
					if (this.get_token_type() !== "#tag") {
						continue;
					}

					if (!this.is_tag_closer() || visitClosers) {
						return true;
					}
				}
				return false;
			}

			if (typeof query === "string") {
				query = { breadcrumbs: [query] };
			}

			if (!query || typeof query !== "object") {
				return false;
			}

			const needsTag = typeof query.tag_name === "string" ? asciiUpper(query.tag_name) : null;
			const needsClass = typeof query.class_name === "string" ? query.class_name : null;
			const matchOffset = Number.isInteger(query.match_offset) && query.match_offset > 0 ? query.match_offset : 1;
			const hasBreadcrumbs = Array.isArray(query.breadcrumbs);

			let remaining = matchOffset;
			while (remaining > 0 && this.next_token()) {
				if (this.get_token_type() !== "#tag") {
					continue;
				}

				if (this.is_tag_closer()) {
					if (!visitClosers || hasBreadcrumbs) {
						continue;
					}
				}

				if (needsTag !== null && this.get_token_name() !== needsTag) {
					continue;
				}

				if (needsClass !== null && this.has_class(needsClass) !== true) {
					continue;
				}

				if (hasBreadcrumbs && !this.matches_breadcrumbs(query.breadcrumbs)) {
					continue;
				}

				remaining -= 1;
			}

			return remaining === 0;
		}

		next_token() {
			this.current_virtual = null;
			if (!super.next_token()) {
				this.breadcrumbs = [...this.open_elements];
				return false;
			}

			this.#updateTreeStateForCurrentToken();
			return true;
		}

		get_last_error() {
			return this.last_error;
		}

		get_unsupported_exception() {
			return this.unsupported_exception;
		}

		is_virtual() {
			return this.current_virtual !== null;
		}

		is_tag_closer() {
			return this.is_virtual() ? this.current_virtual.operation === "pop" : super.is_tag_closer();
		}

		get_namespace() {
			return this.current_token_namespace;
		}

		expects_closer() {
			const tokenName = this.get_token_name();
			if (tokenName === null) {
				return null;
			}

			return tokenExpectsCloser(
				tokenName,
				this.get_namespace(),
				this.has_self_closing_flag(),
			);
		}

		get_breadcrumbs() {
			return [...this.breadcrumbs];
		}

		get_current_depth() {
			return this.breadcrumbs.length;
		}

		matches_breadcrumbs(breadcrumbs) {
			if (!Array.isArray(breadcrumbs)) {
				return false;
			}

			if (breadcrumbs.length === 0) {
				return true;
			}

			const normalized = breadcrumbs.map((crumb) => crumb === "*" ? "*" : asciiUpper(String(crumb)));
			const lastCrumb = normalized[normalized.length - 1];
			if (lastCrumb !== "*" && this.get_tag() !== lastCrumb) {
				return false;
			}

			let crumbIndex = normalized.length - 1;
			for (let nodeIndex = this.breadcrumbs.length - 1; nodeIndex >= 0; nodeIndex -= 1) {
				const crumb = normalized[crumbIndex];
				if (crumb !== "*" && this.breadcrumbs[nodeIndex] !== crumb) {
					return false;
				}

				crumbIndex -= 1;
				if (crumbIndex < 0) {
					return true;
				}
			}

			return false;
		}

		serialize() {
			if (this.parser_state !== STATE_READY) {
				return null;
			}

			let html = "";
			while (this.next_token()) {
				html += this.serialize_token();
			}

			return this.get_last_error() === null ? html : null;
		}

		serialize_token() {
			const tokenType = this.get_token_type();

			switch (tokenType) {
				case "#doctype":
					return serializeDoctype(this.get_doctype_info());
				case "#text":
					return htmlEscape(this.get_modifiable_text() ?? "");
				case "#presumptuous-tag":
					return "";
				case "#funky-comment":
				case "#comment":
					return `<!--${this.get_full_comment_text() ?? ""}-->`;
				case "#cdata-section":
					return `<![CDATA[${this.get_modifiable_text() ?? ""}]]>`;
				case "#tag":
					return this.#serializeCurrentTag();
				default:
					return "";
			}
		}

		#updateTreeStateForCurrentToken() {
			const tokenType = this.get_token_type();
			const tokenName = this.get_token_name();

			if (tokenName === null) {
				this.breadcrumbs = [...this.open_elements];
				this.current_token_namespace = this.current_namespace;
				return;
			}

			if (tokenType !== "#tag") {
				this.current_token_namespace = this.current_namespace;
				this.breadcrumbs = [...this.open_elements, tokenName];
				return;
			}

			const tagName = this.get_tag();
			if (tagName === null) {
				this.breadcrumbs = [...this.open_elements];
				this.current_token_namespace = this.current_namespace;
				return;
			}

			if (this.is_tag_closer()) {
				const existingIndex = this.open_elements.lastIndexOf(tagName);
				this.current_token_namespace = existingIndex === -1
					? this.current_namespace
					: this.open_element_namespaces[existingIndex];
				this.breadcrumbs = existingIndex === -1
					? [...this.open_elements, tagName]
					: [...this.open_elements.slice(0, existingIndex + 1)];

				if (existingIndex !== -1) {
					this.open_elements = this.open_elements.slice(0, existingIndex);
					this.open_element_namespaces = this.open_element_namespaces.slice(0, existingIndex);
					this.#setCurrentNamespace(this.#namespaceForStackTop());
				}
				return;
			}

			this.#applySimpleHtmlSemanticClosures(tagName);
			this.current_token_namespace = namespaceForTag(tagName, this.current_namespace);
			this.open_elements.push(tagName);
			this.open_element_namespaces.push(this.current_token_namespace);
			this.breadcrumbs = [...this.open_elements];

			if (!tokenExpectsCloser(tagName, this.current_token_namespace, this.has_self_closing_flag())) {
				this.open_elements.pop();
				this.open_element_namespaces.pop();
				this.#setCurrentNamespace(this.#namespaceForStackTop());
			} else {
				this.#setCurrentNamespace(childNamespaceForTag(tagName, this.current_token_namespace));
			}
		}

		#applySimpleHtmlSemanticClosures(tagName) {
			if (tagName === "P") {
				this.#popLastMatching("P");
				return;
			}

			if (HEADING_ELEMENTS.has(tagName)) {
				this.#popLastMatching((nodeName) => HEADING_ELEMENTS.has(nodeName));
				return;
			}

			if (tagName === "LI") {
				this.#popLastMatching("LI");
				return;
			}

			if (tagName === "DD" || tagName === "DT") {
				this.#popLastMatching((nodeName) => nodeName === "DD" || nodeName === "DT");
			}
		}

		#popLastMatching(match) {
			const predicate = typeof match === "function" ? match : (nodeName) => nodeName === match;
			for (let i = this.open_elements.length - 1; i >= 0; i -= 1) {
				if (predicate(this.open_elements[i])) {
					this.open_elements = this.open_elements.slice(0, i);
					this.open_element_namespaces = this.open_element_namespaces.slice(0, i);
					this.#setCurrentNamespace(this.#namespaceForStackTop());
					return true;
				}
			}
			return false;
		}

		#serializeCurrentTag() {
			const tagName = replaceNulls(this.get_tag() ?? "");
			if (tagName === "") {
				return "";
			}

			const inHtml = this.get_namespace() === "html";
			const qualifiedName = replaceNulls(inHtml ? tagName.toLowerCase() : this.get_qualified_tag_name());

			if (this.is_tag_closer()) {
				return `</${qualifiedName}>`;
			}

			let html = `<${qualifiedName}`;
			const attributeNames = this.get_attribute_names_with_prefix("") ?? [];
			const seenAttributeNames = new Set();
			let previousAttributeWasTrue = false;

			for (const attributeName of attributeNames) {
				const qualifiedAttributeName = replaceNulls(this.get_qualified_attribute_name(attributeName));
				if (seenAttributeNames.has(qualifiedAttributeName)) {
					continue;
				}
				seenAttributeNames.add(qualifiedAttributeName);

				if (previousAttributeWasTrue && qualifiedAttributeName.startsWith("=")) {
					html += '=""';
				}

				html += ` ${qualifiedAttributeName}`;
				const value = this.get_attribute(attributeName);
				if (typeof value === "string") {
					html += `="${htmlEscape(value)}"`;
				}
				previousAttributeWasTrue = value === true;
			}

			if (!inHtml && this.has_self_closing_flag()) {
				html += " /";
			}

			html += ">";

			if (tagName === "TEXTAREA" || tagName === "PRE" || tagName === "LISTING") {
				html += "\n";
			}

			if (inHtml && SPECIAL_ATOMIC_ELEMENTS.has(tagName)) {
				let text = this.get_modifiable_text() ?? "";
				if (tagName === "IFRAME" || tagName === "NOEMBED" || tagName === "NOFRAMES") {
					text = "";
				} else if (tagName !== "SCRIPT" && tagName !== "STYLE") {
					text = htmlEscape(text);
				}
				html += `${text}</${qualifiedName}>`;
			}

			return html;
		}

		#namespaceForStackTop() {
			return this.open_element_namespaces.length === 0
				? "html"
				: childNamespaceForTag(
					this.open_elements[this.open_elements.length - 1],
					this.open_element_namespaces[this.open_element_namespaces.length - 1],
				);
		}

		#setCurrentNamespace(namespaceName) {
			this.current_namespace = namespaceName;
			super.change_parsing_namespace(namespaceName);
		}
	}

	return {
		WP_HTML_Tag_Processor,
		WP_HTML_Processor,
		WP_HTML_Doctype_Info,
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

	readOutputBytes(callback) {
		return this.withOutSlice((out) => {
			if (!callback(out)) {
				return null;
			}
			return this.readBytesFromOut(out);
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

function parsePublicIdentifier(doctype, at, end, name) {
	const quote = doctype[at];
	if (quote !== '"' && quote !== "'") {
		return new WP_HTML_Doctype_Info(name, null, null, true);
	}

	at += 1;
	const identifierStart = at;
	const identifierEnd = doctype.indexOf(quote, at);
	const boundedIdentifierEnd = identifierEnd === -1 || identifierEnd > end ? end : identifierEnd;
	const publicIdentifier = replaceNulls(doctype.slice(identifierStart, boundedIdentifierEnd));

	if (identifierEnd === -1 || identifierEnd >= end || doctype[identifierEnd] !== quote) {
		return new WP_HTML_Doctype_Info(name, publicIdentifier, null, true);
	}

	at = skipHtmlWhitespace(doctype, identifierEnd + 1, end);
	if (at >= end) {
		return new WP_HTML_Doctype_Info(name, publicIdentifier, null, false);
	}

	return parseSystemIdentifier(doctype, at, end, name, publicIdentifier);
}

function parseSystemIdentifier(doctype, at, end, name, publicIdentifier) {
	const quote = doctype[at];
	if (quote !== '"' && quote !== "'") {
		return new WP_HTML_Doctype_Info(name, publicIdentifier, null, true);
	}

	at += 1;
	const identifierStart = at;
	const identifierEnd = doctype.indexOf(quote, at);
	const boundedIdentifierEnd = identifierEnd === -1 || identifierEnd > end ? end : identifierEnd;
	const systemIdentifier = replaceNulls(doctype.slice(identifierStart, boundedIdentifierEnd));

	if (identifierEnd === -1 || identifierEnd >= end || doctype[identifierEnd] !== quote) {
		return new WP_HTML_Doctype_Info(name, publicIdentifier, systemIdentifier, true);
	}

	return new WP_HTML_Doctype_Info(name, publicIdentifier, systemIdentifier, false);
}

function doctypeCompatibilityMode(name, publicIdentifier, systemIdentifier, forceQuirksFlag) {
	if (forceQuirksFlag) {
		return "quirks";
	}

	if (name === "html" && publicIdentifier === null && systemIdentifier === null) {
		return "no-quirks";
	}

	if (name !== "html") {
		return "quirks";
	}

	const systemIdentifierIsMissing = systemIdentifier === null;
	const publicId = publicIdentifier === null ? "" : publicIdentifier.toLowerCase();
	const systemId = systemIdentifier === null ? "" : systemIdentifier.toLowerCase();

	if (
		publicId === "-//w3o//dtd w3 html strict 3.0//en//" ||
		publicId === "-/w3c/dtd html 4.0 transitional/en" ||
		publicId === "html"
	) {
		return "quirks";
	}

	if (systemId === "http://www.ibm.com/data/dtd/v11/ibmxhtml1-transitional.dtd") {
		return "quirks";
	}

	if (publicId === "") {
		return "no-quirks";
	}

	if (QUIRKS_PUBLIC_IDENTIFIER_PREFIXES.some((prefix) => publicId.startsWith(prefix))) {
		return "quirks";
	}

	if (
		systemIdentifierIsMissing &&
		(
			publicId.startsWith("-//w3c//dtd html 4.01 frameset//") ||
			publicId.startsWith("-//w3c//dtd html 4.01 transitional//")
		)
	) {
		return "quirks";
	}

	if (
		publicId.startsWith("-//w3c//dtd xhtml 1.0 frameset//") ||
		publicId.startsWith("-//w3c//dtd xhtml 1.0 transitional//")
	) {
		return "limited-quirks";
	}

	if (
		!systemIdentifierIsMissing &&
		(
			publicId.startsWith("-//w3c//dtd html 4.01 frameset//") ||
			publicId.startsWith("-//w3c//dtd html 4.01 transitional//")
		)
	) {
		return "limited-quirks";
	}

	return "no-quirks";
}

function skipHtmlWhitespace(value, at, end) {
	while (at < end && isHtmlWhitespaceCode(value.charCodeAt(at))) {
		at += 1;
	}
	return at;
}

function isHtmlWhitespaceCode(code) {
	return code === 0x20 || code === 0x09 || code === 0x0a || code === 0x0c || code === 0x0d;
}

function asciiStartsWithAt(value, needle, at) {
	return value.slice(at, at + needle.length).toLowerCase() === needle.toLowerCase();
}

function replaceNulls(value) {
	return value.replace(/\0/g, "\uFFFD");
}

function contextNodeName(context) {
	if (typeof context !== "string") {
		return "BODY";
	}

	const match = context.match(/^<\s*([A-Za-z][^\s/>]*)/);
	return match ? asciiUpper(match[1]) : "BODY";
}

function contextNamespace(nodeName) {
	if (nodeName === "SVG") {
		return "svg";
	}
	if (nodeName === "MATH") {
		return "math";
	}
	return "html";
}

function namespaceForTag(tagName, currentNamespace) {
	if (currentNamespace === "html") {
		if (tagName === "SVG") {
			return "svg";
		}
		if (tagName === "MATH") {
			return "math";
		}
		return "html";
	}

	return currentNamespace;
}

function childNamespaceForTag(tagName, tokenNamespace) {
	if (tokenNamespace === "svg" && tagName === "FOREIGNOBJECT") {
		return "html";
	}

	if (tokenNamespace === "math" && ["ANNOTATION-XML", "MI", "MO", "MN", "MS", "MTEXT"].includes(tagName)) {
		return "html";
	}

	return tokenNamespace;
}

function tokenExpectsCloser(tokenName, namespaceName, hasSelfClosingFlag) {
	if (!tokenName || tokenName[0] === "#" || tokenName === "html") {
		return false;
	}

	if (namespaceName === "html") {
		return !VOID_ELEMENTS.has(tokenName) && !SPECIAL_ATOMIC_ELEMENTS.has(tokenName);
	}

	return !hasSelfClosingFlag;
}

function serializeDoctype(doctype) {
	if (doctype === null) {
		return "";
	}

	let html = "<!DOCTYPE";
	if (doctype.name) {
		html += ` ${doctype.name}`;
	}

	if (doctype.public_identifier !== null) {
		const quote = doctype.public_identifier.includes('"') ? "'" : '"';
		html += ` PUBLIC ${quote}${doctype.public_identifier}${quote}`;
	}

	if (doctype.system_identifier !== null) {
		if (doctype.public_identifier === null) {
			html += " SYSTEM";
		}
		const quote = doctype.system_identifier.includes('"') ? "'" : '"';
		html += ` ${quote}${doctype.system_identifier}${quote}`;
	}

	return `${html}>`;
}

function htmlEscape(value) {
	return String(value).replace(/[&"'<>]/g, (char) => {
		switch (char) {
			case "&":
				return "&amp;";
			case '"':
				return "&quot;";
			case "'":
				return "&apos;";
			case "<":
				return "&lt;";
			case ">":
				return "&gt;";
			default:
				return char;
		}
	});
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
