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

const HEAD_CONTENT_ELEMENTS = new Set([
	"BASE",
	"BASEFONT",
	"BGSOUND",
	"LINK",
	"META",
	"NOFRAMES",
	"NOSCRIPT",
	"SCRIPT",
	"STYLE",
	"TEMPLATE",
	"TITLE",
]);

const HEADING_ELEMENTS = new Set(["H1", "H2", "H3", "H4", "H5", "H6"]);
const FORMATTING_ELEMENTS = new Set([
	"A",
	"B",
	"BIG",
	"CODE",
	"EM",
	"FONT",
	"I",
	"NOBR",
	"S",
	"SMALL",
	"STRIKE",
	"STRONG",
	"TT",
	"U",
]);
const TABLE_SECTION_ELEMENTS = new Set(["TBODY", "TFOOT", "THEAD"]);
const TABLE_CELL_ELEMENTS = new Set(["TD", "TH"]);
const TABLE_CELL_BOUNDARY_START_TAGS = new Set([
	"CAPTION",
	"COL",
	"COLGROUP",
	"TBODY",
	"TD",
	"TFOOT",
	"TH",
	"THEAD",
	"TR",
]);
const TABLE_ROW_BOUNDARY_START_TAGS = new Set([
	"CAPTION",
	"COL",
	"COLGROUP",
	"TBODY",
	"TFOOT",
	"THEAD",
	"TR",
]);
const TABLE_SECTION_BOUNDARY_START_TAGS = new Set([
	"CAPTION",
	"COL",
	"COLGROUP",
	"TBODY",
	"TFOOT",
	"THEAD",
]);

const P_CLOSING_START_TAGS = new Set([
	"ADDRESS",
	"ARTICLE",
	"ASIDE",
	"BLOCKQUOTE",
	"CENTER",
	"DETAILS",
	"DIALOG",
	"DIR",
	"DD",
	"DIV",
	"DL",
	"DT",
	"FIELDSET",
	"FIGCAPTION",
	"FIGURE",
	"FOOTER",
	"HEADER",
	"HGROUP",
	"HR",
	"LI",
	"MAIN",
	"MENU",
	"NAV",
	"OL",
	"P",
	"PRE",
	"SEARCH",
	"SECTION",
	"SUMMARY",
	"TABLE",
	"UL",
	...HEADING_ELEMENTS,
]);

const BUTTON_SCOPE_BOUNDARIES = new Set([
	"APPLET",
	"BUTTON",
	"CAPTION",
	"HTML",
	"MARQUEE",
	"OBJECT",
	"TABLE",
	"TD",
	"TEMPLATE",
	"TH",
]);

const LIST_ITEM_SCOPE_BOUNDARIES = new Set([
	"ADDRESS",
	"APPLET",
	"BLOCKQUOTE",
	"BUTTON",
	"CAPTION",
	"FIELDSET",
	"HTML",
	"MARQUEE",
	"OBJECT",
	"OL",
	"TABLE",
	"TD",
	"TEMPLATE",
	"TH",
	"UL",
]);

const END_TAG_SPECIAL_BOUNDARIES = new Set([
	"ADDRESS",
	"APPLET",
	"AREA",
	"ARTICLE",
	"ASIDE",
	"BASE",
	"BASEFONT",
	"BGSOUND",
	"BLOCKQUOTE",
	"BODY",
	"BR",
	"BUTTON",
	"CAPTION",
	"CENTER",
	"COL",
	"COLGROUP",
	"DD",
	"DETAILS",
	"DIALOG",
	"DIR",
	"DIV",
	"DL",
	"DT",
	"EMBED",
	"FIELDSET",
	"FIGCAPTION",
	"FIGURE",
	"FOOTER",
	"FORM",
	"FRAME",
	"FRAMESET",
	...HEADING_ELEMENTS,
	"HEAD",
	"HEADER",
	"HGROUP",
	"HR",
	"HTML",
	"IFRAME",
	"IMG",
	"INPUT",
	"KEYGEN",
	"LI",
	"LINK",
	"LISTING",
	"MAIN",
	"MARQUEE",
	"MENU",
	"META",
	"NAV",
	"NOEMBED",
	"NOFRAMES",
	"NOSCRIPT",
	"OBJECT",
	"OL",
	"P",
	"PARAM",
	"PLAINTEXT",
	"PRE",
	"SCRIPT",
	"SEARCH",
	"SECTION",
	"SELECT",
	"SOURCE",
	"STYLE",
	"SUMMARY",
	"TABLE",
	"TBODY",
	"TD",
	"TEMPLATE",
	"TEXTAREA",
	"TFOOT",
	"TH",
	"THEAD",
	"TITLE",
	"TR",
	"TRACK",
	"UL",
	"WBR",
	"XMP",
]);

const MODELED_SCOPED_END_TAGS = new Set([
	"ADDRESS",
	"APPLET",
	"ARTICLE",
	"ASIDE",
	"BLOCKQUOTE",
	"BODY",
	"BUTTON",
	"CENTER",
	"DD",
	"DETAILS",
	"DIALOG",
	"DIR",
	"DIV",
	"DL",
	"DT",
	"FIELDSET",
	"FIGCAPTION",
	"FIGURE",
	"FOOTER",
	"FORM",
	"HEADER",
	"HGROUP",
	"HTML",
	"LI",
	"LISTING",
	"MAIN",
	"MARQUEE",
	"MENU",
	"NAV",
	"OBJECT",
	"OL",
	"P",
	"PRE",
	"SEARCH",
	"SECTION",
	"SUMMARY",
	"TEMPLATE",
	"UL",
	...HEADING_ELEMENTS,
]);

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
		static COMMENT_AS_ABRUPTLY_CLOSED_COMMENT = "COMMENT_AS_ABRUPTLY_CLOSED_COMMENT";
		static COMMENT_AS_CDATA_LOOKALIKE = "COMMENT_AS_CDATA_LOOKALIKE";
		static COMMENT_AS_HTML_COMMENT = "COMMENT_AS_HTML_COMMENT";
		static COMMENT_AS_PI_NODE_LOOKALIKE = "COMMENT_AS_PI_NODE_LOOKALIKE";
		static COMMENT_AS_INVALID_HTML = "COMMENT_AS_INVALID_HTML";

		constructor(html) {
			if (typeof html !== "string") {
				html = "";
			}

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
			if (![STATE_MATCHED_TAG, STATE_COMMENT].includes(this.parser_state)) {
				return null;
			}

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
			)) ?? "";
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
			const maxBookmarks = this.constructor.MAX_BOOKMARKS ?? WP_HTML_Tag_Processor.MAX_BOOKMARKS;
			if (this.bookmarks.size >= maxBookmarks && !this.bookmarks.has(name)) {
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
			if (this.parser_state !== STATE_MATCHED_TAG) {
				return null;
			}

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
		static MAX_BOOKMARKS = 10000;
		static PROCESS_NEXT_NODE = "process-next-node";
		static REPROCESS_CURRENT_NODE = "reprocess-current-node";
		static PROCESS_CURRENT_NODE = "process-current-node";
		static ERROR_UNSUPPORTED = "unsupported";
		static ERROR_EXCEEDED_MAX_BOOKMARKS = "exceeded-max-bookmarks";
		static CONSTRUCTOR_UNLOCK_CODE = "Use WP_HTML_Processor::create_fragment() instead of calling the class constructor directly.";

		constructor(html, options = {}) {
			super(html);
			this.last_error = null;
			this.unsupported_exception = null;
			this.current_virtual = null;
			this.virtual_tokens = [];
			this.pending_real_token = false;
			this.pending_real_parser_state = null;
			this.skip_current_token = false;
			this.is_full_parser = Boolean(options.fullParser);
			this.encoding_confidence = options.encodingConfidence ?? (this.is_full_parser ? "tentative" : "irrelevant");
			this.full_parser_insertion_mode = this.is_full_parser ? "initial" : "in_body";
			this.full_parser_scaffolded = !this.is_full_parser;
			this.full_parser_seen_doctype = false;
			this.context_node = options.contextNode ?? "BODY";
			this.open_elements = this.is_full_parser ? [] : ["HTML", this.context_node];
			this.open_element_namespaces = this.open_elements.map(() => "html");
			this.active_formatting_elements = [];
			this.base_open_element_count = this.open_elements.length;
			this.breadcrumbs = [...this.open_elements];
			this.current_namespace = contextNamespace(this.context_node);
			this.current_token_namespace = this.current_namespace;
			super.change_parsing_namespace(this.current_namespace);
		}

		static create_fragment(html, context = "<body>", encoding = "UTF-8") {
			if (context !== "<body>" || encoding !== "UTF-8" || typeof html !== "string") {
				return null;
			}

			return new this(html, {
				contextNode: contextNodeName(context),
				fullParser: false,
			});
		}

		static create_full_parser(html, encoding = "UTF-8") {
			if (encoding !== "UTF-8" || typeof html !== "string") {
				return null;
			}

			return new this(html, {
				fullParser: true,
				encodingConfidence: "certain",
			});
		}

		static normalize(html) {
			const processor = this.create_fragment(html);
			return processor === null ? null : processor.serialize();
		}

		static is_void(tagName) {
			return VOID_ELEMENTS.has(asciiUpper(String(tagName)));
		}

		static is_special(tagName) {
			const normalized = normalizeSpecialTagInput(tagName);
			return isSpecialBoundary(normalized.nodeName, normalized.namespaceName);
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
			const hasBreadcrumbs = Array.isArray(query.breadcrumbs);

			if (!hasBreadcrumbs) {
				while (this.next_token()) {
					if (this.get_token_type() !== "#tag") {
						continue;
					}

					if (this.is_tag_closer() && !visitClosers) {
						continue;
					}

					if (needsTag !== null && this.get_token_name() !== needsTag) {
						continue;
					}

					if (needsClass !== null && this.has_class(needsClass) !== true) {
						continue;
					}

					return true;
				}

				return false;
			}

			let remaining = query.match_offset == null ? 1 : phpIntegerCast(query.match_offset);
			if (remaining < 1) {
				return false;
			}

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

		step(nodeToProcess = WP_HTML_Processor.PROCESS_NEXT_NODE) {
			if (nodeToProcess === WP_HTML_Processor.PROCESS_NEXT_NODE) {
				return this.next_token();
			}

			if (
				nodeToProcess === WP_HTML_Processor.REPROCESS_CURRENT_NODE ||
				nodeToProcess === WP_HTML_Processor.PROCESS_CURRENT_NODE
			) {
				return this.parser_state !== STATE_READY && this.parser_state !== STATE_COMPLETE;
			}

			return false;
		}

		next_token() {
			if (this.last_error !== null) {
				return false;
			}

			if (this.virtual_tokens.length > 0) {
				return this.#consumeVirtualToken();
			}

			if (this.pending_real_token) {
				this.current_virtual = null;
				this.pending_real_token = false;
				if (this.pending_real_parser_state !== null) {
					this.parser_state = this.pending_real_parser_state;
					this.pending_real_parser_state = null;
				}
				this.skip_current_token = false;
				this.#subdivideCurrentTextToken();
				this.#updateTreeStateForCurrentToken(true);
				if (this.last_error !== null) {
					return false;
				}
				if (this.pending_real_token && this.virtual_tokens.length > 0) {
					return this.#consumeVirtualToken();
				}
				if (this.skip_current_token) {
					return this.next_token();
				}
				return true;
			}

			this.current_virtual = null;
			while (super.next_token()) {
				this.skip_current_token = false;
				this.#subdivideCurrentTextToken();
				this.#updateTreeStateForCurrentToken(true);
				if (this.last_error !== null) {
					return false;
				}
				if (this.pending_real_token && this.virtual_tokens.length > 0) {
					return this.#consumeVirtualToken();
				}
				if (!this.skip_current_token) {
					return true;
				}
				if (this.virtual_tokens.length > 0) {
					return this.#consumeVirtualToken();
				}
				this.current_virtual = null;
			}

			if (this.is_full_parser && !this.full_parser_scaffolded) {
				this.full_parser_scaffolded = true;
				if (!this.full_parser_seen_doctype) {
					this.compat_mode = WP_HTML_Tag_Processor.QUIRKS_MODE;
				}
				this.#queueFullParserScaffold();
				return this.#consumeVirtualToken();
			}

			if (this.#queueEofVirtualClosers()) {
				return this.#consumeVirtualToken();
			}

			this.breadcrumbs = [...this.open_elements];
			return false;
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

		get_tag() {
			if (this.is_virtual()) {
				return this.current_virtual.tagName;
			}

			return normalizeTagNameForNamespace(super.get_tag(), this.current_token_namespace);
		}

		get_attribute(name) {
			return this.is_virtual() ? this.#getVirtualAttribute(name) : super.get_attribute(name);
		}

		get_attribute_names_with_prefix(prefix) {
			return this.is_virtual() ? this.#getVirtualAttributeNamesWithPrefix(prefix) : super.get_attribute_names_with_prefix(prefix);
		}

		set_attribute(name, value) {
			return this.is_virtual() ? false : super.set_attribute(name, value);
		}

		remove_attribute(name) {
			return this.is_virtual() ? false : super.remove_attribute(name);
		}

		add_class(className) {
			return this.is_virtual() ? false : super.add_class(className);
		}

		remove_class(className) {
			return this.is_virtual() ? false : super.remove_class(className);
		}

		has_class(className) {
			return this.is_virtual() ? this.#virtualHasClass(className) : super.has_class(className);
		}

		class_list() {
			return this.is_virtual() ? this.#virtualClassList() : super.class_list();
		}

		has_self_closing_flag() {
			return this.is_virtual() ? false : super.has_self_closing_flag();
		}

		get_token_name() {
			return this.is_virtual() ? this.current_virtual.tagName : super.get_token_name();
		}

		get_token_type() {
			return this.is_virtual() ? "#tag" : super.get_token_type();
		}

		get_comment_type() {
			return this.is_virtual() ? null : super.get_comment_type();
		}

		get_doctype_info() {
			return this.is_virtual() ? null : super.get_doctype_info();
		}

		subdivide_text_appropriately() {
			return this.is_virtual() ? false : super.subdivide_text_appropriately();
		}

		get_modifiable_text() {
			return this.is_virtual() ? "" : super.get_modifiable_text();
		}

		set_modifiable_text(text) {
			if (
				this.is_virtual() ||
				(
					this.parser_state === STATE_MATCHED_TAG &&
					this.get_namespace() !== "html"
				)
			) {
				return false;
			}

			return super.set_modifiable_text(text);
		}

		set_bookmark(name) {
			if (this.is_virtual()) {
				return false;
			}

			if (!super.set_bookmark(name)) {
				return false;
			}

			this.bookmarks.set(name, {
				...this.bookmarks.get(name),
				processorState: this.#snapshotProcessorState(),
			});
			return true;
		}

		seek(name) {
			const bookmark = this.bookmarks.get(name);
			if (!bookmark || !super.seek(name)) {
				return false;
			}

			if (bookmark.processorState) {
				this.#restoreProcessorState(bookmark.processorState);
			}
			return true;
		}

		get_namespace() {
			return this.current_token_namespace;
		}

		get_qualified_tag_name() {
			const tagName = this.get_tag();
			if (tagName === null || this.get_namespace() === "html") {
				return tagName;
			}

			const lower = asciiLower(tagName);
			return this.get_namespace() === "svg" ? qualifySvgTagName(lower) : lower;
		}

		get_qualified_attribute_name(attributeName) {
			if (this.get_token_type() !== "#tag") {
				return null;
			}

			const lower = asciiLower(attributeName);
			return this.get_namespace() === "html"
				? lower
				: qualifyForeignAttributeName(this.get_namespace(), lower);
		}

		expects_closer(node = null) {
			const token = this.#normalizeExpectsCloserToken(node);
			const tokenName = token.nodeName;
			if (tokenName === null) {
				return null;
			}

			return tokenExpectsCloser(
				tokenName,
				token.namespaceName,
				token.hasSelfClosingFlag,
			);
		}

		#normalizeExpectsCloserToken(node) {
			let tokenName;
			let namespaceName;
			let hasSelfClosingFlag;

			if (node && typeof node === "object") {
				tokenName = node.node_name ?? node.nodeName ?? node.tagName ?? this.get_token_name();
				namespaceName = node.namespace ?? node.namespaceName ?? this.get_namespace();
				hasSelfClosingFlag = node.has_self_closing_flag ?? node.hasSelfClosingFlag ?? this.has_self_closing_flag();
			} else {
				tokenName = this.get_token_name();
				namespaceName = this.get_namespace();
				hasSelfClosingFlag = this.has_self_closing_flag();
			}

			if (tokenName === null || tokenName === undefined) {
				return {
					nodeName: null,
					namespaceName: null,
					hasSelfClosingFlag: false,
				};
			}

			const normalizedNamespace = asciiLower(String(namespaceName ?? "html"));
			let normalizedTokenName = String(tokenName);
			if (normalizedNamespace === "html" && normalizedTokenName !== "html" && normalizedTokenName[0] !== "#") {
				normalizedTokenName = asciiUpper(normalizedTokenName);
			}

			return {
				nodeName: normalizedTokenName,
				namespaceName: normalizedNamespace,
				hasSelfClosingFlag: Boolean(hasSelfClosingFlag),
			};
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

		#updateTreeStateForCurrentToken(allowVirtualPreclosures = true) {
			const tokenType = this.get_token_type();
			const tokenName = this.get_token_name();

			if (tokenName === null) {
				this.breadcrumbs = [...this.open_elements];
				this.current_token_namespace = this.current_namespace;
				return;
			}

			if (this.#applyFullParserInsertionMode(tokenType, tokenName)) {
				return;
			}

			if (tokenType !== "#tag") {
				if (
					allowVirtualPreclosures &&
					tokenType === "#text" &&
					this.text_node_classification !== WP_HTML_Tag_Processor.TEXT_IS_NULL_SEQUENCE &&
					this.#queueReconstructActiveFormattingElements()
				) {
					this.pending_real_token = true;
					this.pending_real_parser_state = this.parser_state;
					return;
				}

				this.current_token_namespace = this.current_namespace;
				this.breadcrumbs = [...this.open_elements, tokenName];
				return;
			}

			const tagName = this.#getCurrentTreeTagName();
			if (tagName === null) {
				this.breadcrumbs = [...this.open_elements];
				this.current_token_namespace = this.current_namespace;
				return;
			}

			if (this.is_tag_closer()) {
				const closingNamespace = this.current_namespace;

				if (allowVirtualPreclosures && this.#queueVirtualPreclosuresForEndTag(tagName)) {
					this.pending_real_token = true;
					this.pending_real_parser_state = this.parser_state;
					return;
				}

				let existingIndex = this.#lastOpenElementIndex(tagName, closingNamespace);
				if (tagName === "LI" && closingNamespace === "html") {
					existingIndex = this.#findOpenElementBeforeBoundary("LI", LIST_ITEM_SCOPE_BOUNDARIES);
				}

				if (
					allowVirtualPreclosures &&
					existingIndex !== -1 &&
					existingIndex < this.open_elements.length - 1 &&
					tagName !== "HTML" &&
					tagName !== "BODY" &&
					MODELED_SCOPED_END_TAGS.has(tagName) &&
					hasSpecialBoundaryAfter(
						this.open_elements,
						this.open_element_namespaces,
						existingIndex,
					)
				) {
					this.#queueVirtualPopsFrom(existingIndex + 1);
					this.pending_real_token = true;
					this.pending_real_parser_state = this.parser_state;
					return;
				}

				if (tagName === "P" && closingNamespace === "html" && existingIndex === -1) {
					this.current_token_namespace = this.current_namespace;
					this.breadcrumbs = [...this.open_elements];
					this.virtual_tokens.push(
						{
							operation: "push",
							tagName: "P",
							namespaceName: "html",
						},
						{
							operation: "pop",
							tagName: "P",
							namespaceName: "html",
						},
					);
					this.skip_current_token = true;
					return;
				}

				if (
					existingIndex === -1 ||
					(
						!MODELED_SCOPED_END_TAGS.has(tagName) &&
						hasSpecialBoundaryAfter(
							this.open_elements,
							this.open_element_namespaces,
							existingIndex,
						)
					)
				) {
					this.current_token_namespace = this.current_namespace;
					this.breadcrumbs = [...this.open_elements];
					this.skip_current_token = true;
					return;
				}

				if (allowVirtualPreclosures && existingIndex < this.open_elements.length - 1) {
					this.#queueVirtualPopsFrom(existingIndex + 1);
					this.pending_real_token = true;
					this.pending_real_parser_state = this.parser_state;
					return;
				}

				this.current_token_namespace = this.open_element_namespaces[existingIndex];
				this.open_elements = this.open_elements.slice(0, existingIndex);
				this.open_element_namespaces = this.open_element_namespaces.slice(0, existingIndex);
				if (FORMATTING_ELEMENTS.has(tagName)) {
					this.#removeActiveFormattingElement(tagName);
				}
				this.breadcrumbs = [...this.open_elements];
				this.#setCurrentNamespace(this.#namespaceForStackTop());
				return;
			}

			if (
				allowVirtualPreclosures &&
				(
					this.#queueVirtualPreclosuresForStartTag(tagName) ||
					this.#queueVirtualOpenersForStartTag(tagName)
				)
			) {
				this.pending_real_token = true;
				this.pending_real_parser_state = this.parser_state;
				return;
			}

			if (
				this.is_full_parser &&
				this.encoding_confidence === "tentative" &&
				this.current_namespace === "html" &&
				tagName === "META" &&
				this.#isUnsupportedEncodingMeta()
			) {
				this.#bailUnsupported("Cannot yet process META tags to determine encoding.");
				return;
			}

			this.#applySimpleHtmlSemanticClosures(tagName);
			if (
				allowVirtualPreclosures &&
				FORMATTING_ELEMENTS.has(tagName) &&
				this.#queueReconstructActiveFormattingElements()
			) {
				this.pending_real_token = true;
				this.pending_real_parser_state = this.parser_state;
				return;
			}
			this.current_token_namespace = namespaceForTag(super.get_tag(), this.current_namespace);
			this.open_elements.push(tagName);
			this.open_element_namespaces.push(this.current_token_namespace);
			if (this.current_token_namespace === "html" && FORMATTING_ELEMENTS.has(tagName)) {
				this.active_formatting_elements.push(this.#createActiveFormattingElement(tagName));
			}
			this.breadcrumbs = [...this.open_elements];

			if (!tokenExpectsCloser(tagName, this.current_token_namespace, this.has_self_closing_flag())) {
				this.open_elements.pop();
				this.open_element_namespaces.pop();
				this.#setCurrentNamespace(this.#namespaceForStackTop());
			} else {
				this.#setCurrentNamespace(childNamespaceForTag(tagName, this.current_token_namespace));
				if (this.#shouldPopTableFormImmediately(tagName, this.current_token_namespace)) {
					this.virtual_tokens.push({
						operation: "pop",
						tagName,
						namespaceName: this.current_token_namespace,
					});
				}
			}

			this.#bailIfExceededMaxBookmarks();
		}

		#consumeVirtualToken() {
			const token = this.virtual_tokens.shift();
			this.current_virtual = token;
			this.skip_current_token = false;
			this.parser_state = STATE_MATCHED_TAG;
			this.current_token_namespace = token.namespaceName;

			if (token.operation === "push") {
				this.open_elements.push(token.tagName);
				this.open_element_namespaces.push(token.namespaceName);
				this.breadcrumbs = [...this.open_elements];
				this.#setCurrentNamespace(childNamespaceForTag(token.tagName, token.namespaceName));
				this.#bailIfExceededMaxBookmarks();
			} else if (token.operation === "pop") {
				const existingIndex = this.#lastOpenElementIndex(token.tagName, token.namespaceName);
				if (existingIndex !== -1) {
					this.open_elements = this.open_elements.slice(0, existingIndex);
					this.open_element_namespaces = this.open_element_namespaces.slice(0, existingIndex);
					this.#setCurrentNamespace(this.#namespaceForStackTop());
				}
				this.breadcrumbs = [...this.open_elements];
			}

			return this.last_error === null;
		}

		#snapshotProcessorState() {
			return {
				openElements: [...this.open_elements],
				openElementNamespaces: [...this.open_element_namespaces],
				breadcrumbs: [...this.breadcrumbs],
				currentNamespace: this.current_namespace,
				currentTokenNamespace: this.current_token_namespace,
				activeFormattingElements: this.active_formatting_elements.map((entry) => this.#cloneActiveFormattingElement(entry)),
				encodingConfidence: this.encoding_confidence,
				baseOpenElementCount: this.base_open_element_count,
				fullParserInsertionMode: this.full_parser_insertion_mode,
				fullParserScaffolded: this.full_parser_scaffolded,
				fullParserSeenDoctype: this.full_parser_seen_doctype,
			};
		}

		#restoreProcessorState(state) {
			this.current_virtual = null;
			this.virtual_tokens = [];
			this.pending_real_token = false;
			this.pending_real_parser_state = null;
			this.skip_current_token = false;
			this.full_parser_insertion_mode = state.fullParserInsertionMode;
			this.full_parser_scaffolded = state.fullParserScaffolded;
			this.full_parser_seen_doctype = state.fullParserSeenDoctype;
			this.open_elements = [...state.openElements];
			this.open_element_namespaces = [...state.openElementNamespaces];
			this.active_formatting_elements = state.activeFormattingElements.map((entry) => this.#cloneActiveFormattingElement(entry));
			this.encoding_confidence = state.encodingConfidence;
			this.base_open_element_count = state.baseOpenElementCount;
			this.breadcrumbs = [...state.breadcrumbs];
			this.current_namespace = state.currentNamespace;
			this.current_token_namespace = state.currentTokenNamespace;
			super.change_parsing_namespace(this.current_namespace);
		}

		#setCompatModeFromCurrentDoctype() {
			const doctype = this.get_doctype_info();
			this.compat_mode = doctype?.indicated_compatibility_mode === "quirks"
				? WP_HTML_Tag_Processor.QUIRKS_MODE
				: WP_HTML_Tag_Processor.NO_QUIRKS_MODE;
		}

		#subdivideCurrentTextToken() {
			if (!this.is_virtual() && this.parser_state === STATE_TEXT_NODE) {
				super.subdivide_text_appropriately();
			}
		}

		#getVirtualAttribute(name) {
			const attributes = this.current_virtual?.attributes ?? [];
			const wantedName = String(name);
			const normalizedWantedName = this.current_token_namespace === "html" ? asciiLower(wantedName) : wantedName;

			for (const attribute of attributes) {
				const attributeName = this.current_token_namespace === "html" ? asciiLower(attribute.name) : attribute.name;
				if (attributeName === normalizedWantedName) {
					return attribute.value;
				}
			}

			return null;
		}

		#getVirtualAttributeNamesWithPrefix(prefix) {
			const attributes = this.current_virtual?.attributes ?? [];
			const wantedPrefix = String(prefix);
			const normalizedWantedPrefix = this.current_token_namespace === "html" ? asciiLower(wantedPrefix) : wantedPrefix;
			const names = [];

			for (const attribute of attributes) {
				const attributeName = this.current_token_namespace === "html" ? asciiLower(attribute.name) : attribute.name;
				if (attributeName.startsWith(normalizedWantedPrefix)) {
					names.push(attribute.name);
				}
			}

			return names.length === 0 ? null : names;
		}

		#virtualHasClass(className) {
			const comparableClassName = this.#comparableClassName(String(className).replaceAll("\0", "\uFFFD"));
			return this.#virtualClassEntries().some((entry) => entry.comparable === comparableClassName);
		}

		#virtualClassList() {
			return this.#virtualClassEntries().map((entry) => this.#virtualUsesQuirksMode() ? entry.comparable : entry.name);
		}

		#virtualClassEntries() {
			const classAttribute = this.#getVirtualAttribute("class");
			if (typeof classAttribute !== "string") {
				return [];
			}

			const entries = [];
			for (const className of splitHtmlWhitespace(classAttribute.replaceAll("\0", "\uFFFD"))) {
				const comparable = this.#comparableClassName(className);
				if (entries.some((entry) => entry.comparable === comparable)) {
					continue;
				}
				entries.push({ name: className, comparable });
			}
			return entries;
		}

		#comparableClassName(className) {
			return this.#virtualUsesQuirksMode() ? asciiLower(className) : className;
		}

		#virtualUsesQuirksMode() {
			return this.compat_mode === WP_HTML_Tag_Processor.QUIRKS_MODE;
		}

		#createActiveFormattingElement(tagName) {
			return {
				tagName,
				namespaceName: this.current_token_namespace,
				attributes: this.#currentTokenAttributes(),
			};
		}

		#cloneActiveFormattingElement(entry) {
			return {
				tagName: entry.tagName,
				namespaceName: entry.namespaceName,
				attributes: entry.attributes.map((attribute) => ({
					name: attribute.name,
					value: attribute.value,
				})),
			};
		}

		#currentTokenAttributes() {
			const attributeNames = super.get_attribute_names_with_prefix("") ?? [];
			const attributes = [];
			const seenAttributeNames = new Set();

			for (const attributeName of attributeNames) {
				const comparableName = this.current_token_namespace === "html" ? asciiLower(attributeName) : attributeName;
				if (seenAttributeNames.has(comparableName)) {
					continue;
				}
				seenAttributeNames.add(comparableName);
				attributes.push({
					name: attributeName,
					value: super.get_attribute(attributeName),
				});
			}

			return attributes;
		}

		#queueReconstructActiveFormattingElements() {
			let firstMissingIndex = this.active_formatting_elements.length;
			while (firstMissingIndex > 0) {
				const entry = this.active_formatting_elements[firstMissingIndex - 1];
				if (this.#lastOpenElementIndex(entry.tagName, entry.namespaceName) !== -1) {
					break;
				}
				firstMissingIndex -= 1;
			}

			if (firstMissingIndex === this.active_formatting_elements.length) {
				return false;
			}

			for (let i = firstMissingIndex; i < this.active_formatting_elements.length; i += 1) {
				const entry = this.active_formatting_elements[i];
				this.#queueVirtualPush(entry.tagName, entry.namespaceName, entry.attributes);
			}

			return true;
		}

		#applyFullParserInsertionMode(tokenType, tokenName) {
			if (!this.is_full_parser) {
				return false;
			}

			const tagName = tokenType === "#tag" ? this.#getCurrentTreeTagName() : tokenName;
			if (tagName === null) {
				return false;
			}

			const isCloser = tokenType === "#tag" && this.is_tag_closer();
			const isWhitespaceText = (
				tokenType === "#text" &&
				this.text_node_classification === WP_HTML_Tag_Processor.TEXT_IS_WHITESPACE
			);

			while (true) {
				switch (this.full_parser_insertion_mode) {
					case "initial":
						if (tokenType === "#doctype") {
							this.full_parser_seen_doctype = true;
							this.#setCompatModeFromCurrentDoctype();
							this.full_parser_insertion_mode = "before_html";
							return false;
						}

						if (isWhitespaceText) {
							this.skip_current_token = true;
							return true;
						}

						if (tokenType === "#comment" || tokenType === "#funky-comment" || tokenType === "#presumptuous-tag") {
							return false;
						}

						this.compat_mode = WP_HTML_Tag_Processor.QUIRKS_MODE;
						this.full_parser_insertion_mode = "before_html";
						continue;

					case "before_html":
						if (tokenType === "#doctype" || isWhitespaceText) {
							this.skip_current_token = true;
							return true;
						}

						if (tokenType === "#comment" || tokenType === "#funky-comment" || tokenType === "#presumptuous-tag") {
							return false;
						}

						if (tokenType === "#tag" && !isCloser && tagName === "HTML") {
							this.full_parser_scaffolded = true;
							this.full_parser_insertion_mode = "before_head";
							return false;
						}

						if (isCloser && tagName !== "HEAD" && tagName !== "BODY" && tagName !== "HTML") {
							this.skip_current_token = true;
							return true;
						}

						this.full_parser_scaffolded = true;
						this.full_parser_insertion_mode = "before_head";
						this.#queueVirtualPush("HTML");
						return this.#reprocessCurrentTokenAfterVirtualTokens();

					case "before_head":
						if (tokenType === "#doctype" || isWhitespaceText) {
							this.skip_current_token = true;
							return true;
						}

						if (tokenType === "#comment" || tokenType === "#funky-comment" || tokenType === "#presumptuous-tag") {
							return false;
						}

						if (tokenType === "#tag" && !isCloser && tagName === "HTML") {
							this.skip_current_token = true;
							return true;
						}

						if (tokenType === "#tag" && !isCloser && tagName === "HEAD") {
							this.full_parser_insertion_mode = "in_head";
							return false;
						}

						if (isCloser && tagName !== "HEAD" && tagName !== "BODY" && tagName !== "HTML") {
							this.skip_current_token = true;
							return true;
						}

						this.full_parser_insertion_mode = "in_head";
						this.#queueVirtualPush("HEAD");
						return this.#reprocessCurrentTokenAfterVirtualTokens();

					case "in_head":
						if (tokenType === "#doctype") {
							this.skip_current_token = true;
							return true;
						}

						if (
							isWhitespaceText ||
							tokenType === "#comment" ||
							tokenType === "#funky-comment" ||
							tokenType === "#presumptuous-tag" ||
							(tokenType === "#tag" && !isCloser && HEAD_CONTENT_ELEMENTS.has(tagName))
						) {
							return false;
						}

						if (tokenType === "#tag" && !isCloser && tagName === "HTML") {
							this.skip_current_token = true;
							return true;
						}

						if (isCloser && tagName === "HEAD") {
							this.full_parser_insertion_mode = "after_head";
							return false;
						}

						if ((tokenType === "#tag" && !isCloser && tagName === "HEAD") || (isCloser && tagName !== "BODY" && tagName !== "HTML")) {
							this.skip_current_token = true;
							return true;
						}

						this.full_parser_insertion_mode = "after_head";
						this.#queueVirtualPop("HEAD");
						return this.#reprocessCurrentTokenAfterVirtualTokens();

					case "after_head":
						if (tokenType === "#doctype") {
							this.skip_current_token = true;
							return true;
						}

						if (
							isWhitespaceText ||
							tokenType === "#comment" ||
							tokenType === "#funky-comment" ||
							tokenType === "#presumptuous-tag"
						) {
							return false;
						}

						if (tokenType === "#tag" && !isCloser && tagName === "HTML") {
							this.skip_current_token = true;
							return true;
						}

						if (tokenType === "#tag" && !isCloser && tagName === "BODY") {
							this.full_parser_insertion_mode = "in_body";
							return false;
						}

						if (tokenType === "#tag" && !isCloser && HEAD_CONTENT_ELEMENTS.has(tagName)) {
							this.#bailUnsupported("Cannot process elements after HEAD which reopen the HEAD element.");
							return true;
						}

						if (tokenType === "#tag" && !isCloser && tagName === "HEAD") {
							this.skip_current_token = true;
							return true;
						}

						if (isCloser && tagName !== "BODY" && tagName !== "HTML") {
							this.skip_current_token = true;
							return true;
						}

						this.full_parser_insertion_mode = "in_body";
						this.#queueVirtualPush("BODY");
						return this.#reprocessCurrentTokenAfterVirtualTokens();

					case "in_body":
						if (tokenType === "#doctype") {
							this.skip_current_token = true;
							return true;
						}

						if (tokenType === "#tag" && !isCloser && (tagName === "HTML" || tagName === "BODY")) {
							this.skip_current_token = true;
							return true;
						}

						return false;

					default:
						return false;
				}
			}
		}

		#queueVirtualPush(tagName, namespaceName = "html", attributes = []) {
			this.virtual_tokens.push({
				operation: "push",
				tagName,
				namespaceName,
				attributes: attributes.map((attribute) => ({
					name: attribute.name,
					value: attribute.value,
				})),
			});
		}

		#queueVirtualPop(tagName, namespaceName = "html") {
			this.virtual_tokens.push({
				operation: "pop",
				tagName,
				namespaceName,
			});
		}

		#reprocessCurrentTokenAfterVirtualTokens() {
			this.pending_real_token = true;
			this.pending_real_parser_state = this.parser_state;
			return true;
		}

		#removeActiveFormattingElement(tagName) {
			for (let i = this.active_formatting_elements.length - 1; i >= 0; i -= 1) {
				if (this.active_formatting_elements[i].tagName === tagName) {
					this.active_formatting_elements.splice(i, 1);
					return true;
				}
			}
			return false;
		}

		#bailUnsupported(message) {
			this.last_error = WP_HTML_Processor.ERROR_UNSUPPORTED;
			this.unsupported_exception = { message };
			this.virtual_tokens = [];
			this.pending_real_token = false;
			this.pending_real_parser_state = null;
			this.skip_current_token = true;
		}

		#bailIfExceededMaxBookmarks() {
			const maxBookmarks = this.constructor.MAX_BOOKMARKS ?? WP_HTML_Processor.MAX_BOOKMARKS;
			if (this.open_elements.length <= maxBookmarks) {
				return false;
			}

			this.last_error = WP_HTML_Processor.ERROR_EXCEEDED_MAX_BOOKMARKS;
			this.unsupported_exception = null;
			this.virtual_tokens = [];
			this.pending_real_token = false;
			this.pending_real_parser_state = null;
			this.skip_current_token = true;
			return true;
		}

		#isUnsupportedEncodingMeta() {
			if (typeof this.get_attribute("charset") === "string") {
				return true;
			}

			const httpEquiv = this.get_attribute("http-equiv");
			const content = this.get_attribute("content");
			return (
				typeof httpEquiv === "string" &&
				typeof content === "string" &&
				httpEquiv.toLowerCase() === "content-type"
			);
		}

		#queueVirtualPreclosuresForStartTag(tagName) {
			if (P_CLOSING_START_TAGS.has(tagName)) {
				const paragraphIndex = this.#findOpenElementBeforeBoundary("P", BUTTON_SCOPE_BOUNDARIES);
				if (paragraphIndex !== -1) {
					this.#queueVirtualPopsFrom(paragraphIndex);
					return true;
				}
			}

			if (tagName === "LI") {
				const listItemIndex = this.#findOpenElementBeforeBoundary("LI", LIST_ITEM_SCOPE_BOUNDARIES);
				if (listItemIndex !== -1) {
					this.#queueVirtualPopsFrom(listItemIndex);
					return true;
				}
			}

			if (tagName === "DD" || tagName === "DT") {
				const descriptionIndex = this.#findOpenElementBeforeBoundary(
					(nodeName) => nodeName === "DD" || nodeName === "DT",
					LIST_ITEM_SCOPE_BOUNDARIES,
				);
				if (descriptionIndex !== -1) {
					this.#queueVirtualPopsFrom(descriptionIndex);
					return true;
				}
			}

			if (HEADING_ELEMENTS.has(tagName)) {
				const topIndex = this.open_elements.length - 1;
				if (
					topIndex >= 0 &&
					this.open_element_namespaces[topIndex] === "html" &&
					HEADING_ELEMENTS.has(this.open_elements[topIndex])
				) {
					this.#queueVirtualPopsFrom(topIndex);
					return true;
				}
			}

			if (tagName === "A") {
				const anchorIndex = this.#lastOpenElementIndex("A", "html");
				if (anchorIndex !== -1) {
					const activeAnchorIndex = this.#lastActiveFormattingElementIndex("A");
					if (
						(activeAnchorIndex !== -1 && activeAnchorIndex < this.active_formatting_elements.length - 1) ||
						hasSpecialBoundaryAfter(this.open_elements, this.open_element_namespaces, anchorIndex)
					) {
						this.#bailUnsupported("Cannot process nested A elements which require adoption agency reconstruction.");
						return true;
					}

					this.#queueVirtualPopsFrom(anchorIndex);
					this.#removeActiveFormattingElement("A");
					return true;
				}
			}

			if (TABLE_CELL_BOUNDARY_START_TAGS.has(tagName)) {
				const cellIndex = this.#findElementInTableScope((nodeName) => TABLE_CELL_ELEMENTS.has(nodeName));
				if (cellIndex !== -1) {
					this.#queueVirtualPopsFrom(cellIndex);
					return true;
				}
			}

			if (TABLE_ROW_BOUNDARY_START_TAGS.has(tagName)) {
				const rowIndex = this.#findElementInTableScope("TR");
				if (rowIndex !== -1) {
					this.#queueVirtualPopsFrom(rowIndex);
					return true;
				}
			}

			if (TABLE_SECTION_BOUNDARY_START_TAGS.has(tagName)) {
				const sectionIndex = this.#findElementInTableScope((nodeName) => TABLE_SECTION_ELEMENTS.has(nodeName));
				if (sectionIndex !== -1) {
					this.#queueVirtualPopsFrom(sectionIndex);
					return true;
				}
			}

			return false;
		}

		#lastActiveFormattingElementIndex(tagName) {
			for (let i = this.active_formatting_elements.length - 1; i >= 0; i -= 1) {
				if (this.active_formatting_elements[i].tagName === tagName) {
					return i;
				}
			}
			return -1;
		}

		#queueVirtualPreclosuresForEndTag(tagName) {
			if (this.current_namespace !== "html" || !this.#hasElementInTableScope("TABLE")) {
				return false;
			}

			if (tagName === "TR" && !this.#hasElementInTableScope("TR")) {
				return false;
			}

			if (TABLE_SECTION_ELEMENTS.has(tagName) && !this.#hasElementInTableScope(tagName)) {
				return false;
			}

			if (tagName === "TABLE" || tagName === "TR" || TABLE_SECTION_ELEMENTS.has(tagName)) {
				const cellIndex = this.#findElementInTableScope((nodeName) => TABLE_CELL_ELEMENTS.has(nodeName));
				if (cellIndex !== -1) {
					this.#queueVirtualPopsFrom(cellIndex);
					return true;
				}
			}

			if (tagName === "TABLE" || TABLE_SECTION_ELEMENTS.has(tagName)) {
				const rowIndex = this.#findElementInTableScope("TR");
				if (rowIndex !== -1) {
					this.#queueVirtualPopsFrom(rowIndex);
					return true;
				}
			}

			if (tagName === "TABLE") {
				const sectionIndex = this.#findElementInTableScope((nodeName) => TABLE_SECTION_ELEMENTS.has(nodeName));
				if (sectionIndex !== -1) {
					this.#queueVirtualPopsFrom(sectionIndex);
					return true;
				}
			}

			return false;
		}

		#queueVirtualOpenersForStartTag(tagName) {
			if (this.current_namespace !== "html" || !this.#hasElementInTableScope("TABLE")) {
				return false;
			}

			const queued = [];
			if (
				tagName === "TR" &&
				!this.#hasElementInTableScope((nodeName) => TABLE_SECTION_ELEMENTS.has(nodeName))
			) {
				queued.push("TBODY");
			}

			if (TABLE_CELL_ELEMENTS.has(tagName)) {
				if (!this.#hasElementInTableScope((nodeName) => TABLE_SECTION_ELEMENTS.has(nodeName))) {
					queued.push("TBODY");
				}
				if (!this.#hasElementInTableScope("TR")) {
					queued.push("TR");
				}
			}

			for (const queuedTagName of queued) {
				this.virtual_tokens.push({
					operation: "push",
					tagName: queuedTagName,
					namespaceName: "html",
				});
			}

			return queued.length > 0;
		}

		#queueFullParserScaffold() {
			this.virtual_tokens.push(
				{
					operation: "push",
					tagName: "HTML",
					namespaceName: "html",
				},
				{
					operation: "push",
					tagName: "HEAD",
					namespaceName: "html",
				},
				{
					operation: "pop",
					tagName: "HEAD",
					namespaceName: "html",
				},
				{
					operation: "push",
					tagName: "BODY",
					namespaceName: "html",
				},
			);
		}

		#queueEofVirtualClosers() {
			if (this.open_elements.length <= this.base_open_element_count) {
				return false;
			}

			this.#queueVirtualPopsFrom(this.base_open_element_count);
			return true;
		}

		#queueVirtualPopsFrom(index) {
			for (let i = this.open_elements.length - 1; i >= index; i -= 1) {
				this.virtual_tokens.push({
					operation: "pop",
					tagName: this.open_elements[i],
					namespaceName: this.open_element_namespaces[i],
				});
			}
		}

		#getCurrentTreeTagName() {
			const rawTagName = super.get_tag();
			if (rawTagName === null) {
				return null;
			}

			return normalizeTagNameForNamespace(
				rawTagName,
				namespaceForTag(rawTagName, this.current_namespace),
			);
		}

		#lastOpenElementIndex(tagName, namespaceName) {
			for (let i = this.open_elements.length - 1; i >= 0; i -= 1) {
				if (
					this.open_elements[i] === tagName &&
					this.open_element_namespaces[i] === namespaceName
				) {
					return i;
				}
			}
			return -1;
		}

		#hasElementInTableScope(match) {
			return this.#findElementInTableScope(match) !== -1;
		}

		#findElementInTableScope(match) {
			const predicate = typeof match === "function" ? match : (nodeName) => nodeName === match;
			for (let i = this.open_elements.length - 1; i >= 0; i -= 1) {
				const nodeName = this.open_elements[i];
				const namespaceName = this.open_element_namespaces[i];
				if (namespaceName === "html" && predicate(nodeName)) {
					return i;
				}

				if (
					namespaceName === "html" &&
					(nodeName === "HTML" || nodeName === "TABLE" || nodeName === "TEMPLATE")
				) {
					return -1;
				}
			}
			return -1;
		}

		#shouldPopTableFormImmediately(tagName, namespaceName) {
			if (tagName !== "FORM" || namespaceName !== "html") {
				return false;
			}

			const tableIndex = this.open_elements.lastIndexOf("TABLE");
			if (tableIndex === -1) {
				return false;
			}

			for (let i = tableIndex + 1; i < this.open_elements.length; i += 1) {
				if (
					this.open_element_namespaces[i] === "html" &&
					(this.open_elements[i] === "TD" || this.open_elements[i] === "TH")
				) {
					return false;
				}
			}

			return true;
		}

		#applySimpleHtmlSemanticClosures(tagName) {
			if (P_CLOSING_START_TAGS.has(tagName)) {
				this.#closePInButtonScope();
			}

			if (tagName === "BUTTON") {
				this.#popLastMatchingBeforeBoundary("BUTTON", BUTTON_SCOPE_BOUNDARIES);
			}

			if (HEADING_ELEMENTS.has(tagName)) {
				const topIndex = this.open_elements.length - 1;
				if (
					topIndex >= 0 &&
					this.open_element_namespaces[topIndex] === "html" &&
					HEADING_ELEMENTS.has(this.open_elements[topIndex])
				) {
					this.open_elements.pop();
					this.open_element_namespaces.pop();
					this.#setCurrentNamespace(this.#namespaceForStackTop());
				}
				return;
			}

			if (tagName === "LI") {
				this.#popLastMatchingBeforeBoundary("LI", LIST_ITEM_SCOPE_BOUNDARIES);
				return;
			}

			if (tagName === "DD" || tagName === "DT") {
				this.#popLastMatchingBeforeBoundary(
					(nodeName) => nodeName === "DD" || nodeName === "DT",
					LIST_ITEM_SCOPE_BOUNDARIES,
				);
			}
		}

		#closePInButtonScope() {
			return this.#popLastMatchingBeforeBoundary("P", BUTTON_SCOPE_BOUNDARIES);
		}

		#popLastMatchingBeforeBoundary(match, boundaries) {
			const predicate = typeof match === "function" ? match : (nodeName) => nodeName === match;
			for (let i = this.open_elements.length - 1; i >= 0; i -= 1) {
				const nodeName = this.open_elements[i];
				if (predicate(nodeName)) {
					this.open_elements = this.open_elements.slice(0, i);
					this.open_element_namespaces = this.open_element_namespaces.slice(0, i);
					this.#setCurrentNamespace(this.#namespaceForStackTop());
					return true;
				}

				if (boundaries.has(nodeName)) {
					return false;
				}
			}
			return false;
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

		#findOpenElementBeforeBoundary(match, boundaries) {
			const predicate = typeof match === "function" ? match : (nodeName) => nodeName === match;
			for (let i = this.open_elements.length - 1; i >= 0; i -= 1) {
				const nodeName = this.open_elements[i];
				const namespaceName = this.open_element_namespaces[i];
				if (namespaceName === "html" && predicate(nodeName)) {
					return i;
				}

				if (namespaceName === "html" && boundaries.has(nodeName)) {
					return -1;
				}
			}
			return -1;
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
					html += `="${htmlEscape(replaceNulls(value))}"`;
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

function splitHtmlWhitespace(value) {
	const parts = [];
	let start = 0;

	for (let i = 0; i <= value.length; i += 1) {
		if (i < value.length && !isHtmlWhitespaceCode(value.charCodeAt(i))) {
			continue;
		}

		if (i > start) {
			parts.push(value.slice(start, i));
		}
		start = i + 1;
	}

	return parts;
}

function asciiStartsWithAt(value, needle, at) {
	return value.slice(at, at + needle.length).toLowerCase() === needle.toLowerCase();
}

function replaceNulls(value) {
	return value.replace(/\0/g, "\uFFFD");
}

function phpIntegerCast(value) {
	if (typeof value === "number") {
		return Number.isFinite(value) ? Math.trunc(value) : 0;
	}
	if (typeof value === "boolean") {
		return value ? 1 : 0;
	}
	if (typeof value === "string") {
		const match = value.trimStart().match(/^[+-]?\d+/);
		return match ? Number.parseInt(match[0], 10) : 0;
	}
	return 0;
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

function normalizeTagNameForNamespace(tagName, namespaceName) {
	if (tagName === null) {
		return null;
	}

	return namespaceName === "html" && tagName === "IMAGE" ? "IMG" : tagName;
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

function hasSpecialBoundaryAfter(openElements, namespaces, index) {
	for (let i = index + 1; i < openElements.length; i += 1) {
		if (isSpecialBoundary(openElements[i], namespaces[i])) {
			return true;
		}
	}
	return false;
}

function normalizeSpecialTagInput(tagName) {
	if (tagName && typeof tagName === "object") {
		return {
			nodeName: asciiUpper(String(tagName.node_name ?? tagName.nodeName ?? tagName.tagName ?? "")),
			namespaceName: String(tagName.namespace ?? tagName.namespaceName ?? "html").toLowerCase(),
		};
	}

	const value = String(tagName);
	const match = value.trim().match(/^(html|math|svg)\s+(.+)$/i);
	if (match) {
		return {
			nodeName: asciiUpper(match[2]),
			namespaceName: asciiLower(match[1]),
		};
	}

	return {
		nodeName: asciiUpper(value),
		namespaceName: "html",
	};
}

function isSpecialBoundary(nodeName, namespaceName) {
	if (namespaceName === "html") {
		return END_TAG_SPECIAL_BOUNDARIES.has(nodeName);
	}

	if (namespaceName === "math") {
		return ["MI", "MO", "MN", "MS", "MTEXT", "ANNOTATION-XML"].includes(nodeName);
	}

	if (namespaceName === "svg") {
		return ["DESC", "FOREIGNOBJECT", "TITLE"].includes(nodeName);
	}

	return false;
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
