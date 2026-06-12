const TOKEN_TYPE_TAG = 1;
const TOKEN_TYPE_TEXT = 2;
const TOKEN_TYPE_COMMENT = 3;
const TOKEN_TYPE_DOCTYPE = 4;
const TOKEN_TYPE_CDATA = 5;
const TOKEN_TYPE_PRESUMPTUOUS_TAG = 6;
const TOKEN_TYPE_FUNKY_COMMENT = 7;
const DECODE_CONTEXT_DATA = 0;
const DECODE_CONTEXT_ATTRIBUTE = 1;
const CLASS_UPDATE_ADD = true;
const CLASS_UPDATE_REMOVE = false;

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
const RAW_TEXT_FRAGMENT_CONTEXT_ELEMENTS = new Set([
	"PLAINTEXT",
	"SCRIPT",
	"STYLE",
	"TEXTAREA",
	"TITLE",
]);
const RCDATA_FRAGMENT_CONTEXT_ELEMENTS = new Set(["TEXTAREA", "TITLE"]);
const RAW_TEXT_FRAGMENT_CONTEXT_END_TAGS = "</textarea></title></script></style>";

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
const AFTER_HEAD_TEMPORARY_HEAD_START_TAGS = new Set([
	"BASE",
	"BASEFONT",
	"BGSOUND",
	"LINK",
	"META",
	"NOFRAMES",
	"SCRIPT",
	"STYLE",
	"TITLE",
]);
const TEMPLATE_HEAD_START_TAGS = new Set([
	"BASE",
	"BASEFONT",
	"BGSOUND",
	"LINK",
	"META",
	"NOFRAMES",
	"SCRIPT",
	"STYLE",
	"TITLE",
]);
const IN_HEAD_NOSCRIPT_ALLOWED_START_TAGS = new Set([
	"BASEFONT",
	"BGSOUND",
	"LINK",
	"META",
	"NOFRAMES",
	"STYLE",
]);

const FOREIGN_CONTENT_HTML_BREAKOUT_START_TAGS = new Set([
	"B",
	"BIG",
	"BLOCKQUOTE",
	"BODY",
	"BR",
	"CENTER",
	"CODE",
	"DD",
	"DIV",
	"DL",
	"DT",
	"EM",
	"EMBED",
	"H1",
	"H2",
	"H3",
	"H4",
	"H5",
	"H6",
	"HEAD",
	"HR",
	"I",
	"IMG",
	"LI",
	"LISTING",
	"MENU",
	"META",
	"NOBR",
	"OL",
	"P",
	"PRE",
	"RUBY",
	"S",
	"SMALL",
	"SPAN",
	"STRIKE",
	"STRONG",
	"SUB",
	"SUP",
	"TABLE",
	"TT",
	"U",
	"UL",
	"VAR",
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
const ADOPTION_AGENCY_END_TAGS = new Set([
	...FORMATTING_ELEMENTS,
	"NOBR",
]);
const ACTIVE_FORMATTING_RECONSTRUCTING_START_TAGS = new Set([
	"APPLET",
	"BR",
	"MARQUEE",
	"MENUITEM",
	"OBJECT",
	"SPAN",
]);
const ACTIVE_FORMATTING_MARKER_ELEMENTS = new Set(["APPLET", "CAPTION", "MARQUEE", "OBJECT"]);
const FORMATTING_ELEMENT_SPECIAL_PRECLOSURE_START_TAGS = new Set(["ASIDE", "BUTTON", "DIV", "MENU", "NOBR"]);
const FORMATTING_ELEMENT_ANCESTOR_PRECLOSURE_START_TAGS = new Set(["ASIDE", "DIV"]);
const NESTED_ANCHOR_BLOCK_PRECLOSURE_START_TAGS = new Set(["ADDRESS", "BUTTON", "CENTER", "DIV", "LI"]);
const NESTED_ANCHOR_RECONSTRUCTING_START_TAGS = new Set(["STYLE", "TITLE"]);
const FONT_PARAGRAPH_ADOPTION_RECONSTRUCTING_START_TAGS = new Set(["META", "TITLE"]);
const FONT_PARAGRAPH_ADOPTION_SKIPPABLE_END_TAGS = new Set(["I", "TITLE"]);
const IN_BODY_IGNORED_START_TAGS = new Set([
	"CAPTION",
	"COL",
	"COLGROUP",
	"FRAME",
	"HEAD",
	"TBODY",
	"TD",
	"TFOOT",
	"TH",
	"THEAD",
	"TR",
]);
const AFTER_HEAD_FRAMESET_IGNORED_START_TAGS = new Set(["PARAM", "SOURCE", "TRACK"]);
const AFTER_HEAD_FRAMESET_IGNORED_CLOSED_START_TAGS = new Set(["MATH", "SVG"]);
const AFTER_HEAD_FRAMESET_IGNORED_OPEN_START_TAGS = new Set(["DIV", "FOREIGNOBJECT", "P", "SVG"]);
const TABLE_SECTION_ELEMENTS = new Set(["TBODY", "TFOOT", "THEAD"]);
const MATHML_TEXT_INTEGRATION_POINT_ELEMENTS = new Set(["MI", "MO", "MN", "MS", "MTEXT"]);
const MATHML_TEXT_INTEGRATION_FOREIGN_START_TAGS = new Set(["MALIGNMARK", "MGLYPH"]);
const SVG_HTML_INTEGRATION_POINT_ELEMENTS = new Set(["DESC", "FOREIGNOBJECT", "TITLE"]);
const MATHML_HTML_INTEGRATION_POINT_ENCODINGS = new Set(["application/xhtml+xml", "text/html"]);
const FOREIGN_CONTENT_START_TAGS = new Set(["MATH", "SVG"]);
const TABLE_TEXT_CURRENT_NODE_ELEMENTS = new Set([
	"COLGROUP",
	"TABLE",
	"TBODY",
	"TEMPLATE",
	"TFOOT",
	"THEAD",
	"TR",
]);
const TABLE_MODE_START_TAGS = new Set([
	"CAPTION",
	"COL",
	"COLGROUP",
	"FORM",
	"INPUT",
	"SCRIPT",
	"STYLE",
	"TABLE",
	"TBODY",
	"TD",
	"TEMPLATE",
	"TFOOT",
	"TH",
	"THEAD",
	"TR",
]);
const TABLE_MODE_IGNORED_END_TAGS = new Set([
	"BODY",
	"CAPTION",
	"COL",
	"COLGROUP",
	"HTML",
	"TBODY",
	"TD",
	"TFOOT",
	"TH",
	"THEAD",
	"TR",
]);
const TABLE_BODY_MODE_IGNORED_END_TAGS = new Set([
	"BODY",
	"CAPTION",
	"COL",
	"COLGROUP",
	"HTML",
	"TD",
	"TH",
	"TR",
]);
const TABLE_ROW_MODE_IGNORED_END_TAGS = new Set([
	"BODY",
	"CAPTION",
	"COL",
	"COLGROUP",
	"HTML",
	"TD",
	"TH",
]);
const TABLE_CELL_MODE_IGNORED_END_TAGS = new Set([
	"BODY",
	"CAPTION",
	"COL",
	"COLGROUP",
	"HTML",
]);
const TABLE_CELL_ELEMENTS = new Set(["TD", "TH"]);
const FRAMESET_NOT_OK_START_TAGS = new Set([
	"APPLET",
	"AREA",
	"BODY",
	"BR",
	"BUTTON",
	"DD",
	"DT",
	"EMBED",
	"HR",
	"IFRAME",
	"IMG",
	"KEYGEN",
	"LI",
	"LISTING",
	"MARQUEE",
	"OBJECT",
	"PRE",
	"SELECT",
	"TABLE",
	"TEXTAREA",
	"WBR",
	"XMP",
]);
const TEMPLATE_TABLE_WRAPPER_START_TAGS = new Set(["CAPTION", "COLGROUP", "TBODY", "TFOOT", "THEAD"]);
const FORM_TABLE_DESCENDANT_ELEMENTS = new Set([
	"CAPTION",
	"COLGROUP",
	"TABLE",
	"TBODY",
	"TD",
	"TFOOT",
	"TH",
	"THEAD",
	"TR",
]);
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
const SELECT_BREAKOUT_START_TAGS = new Set(["INPUT", "KEYGEN", "TEXTAREA"]);
const SELECT_IN_TABLE_BREAKOUT_TAGS = new Set([
	"CAPTION",
	"TABLE",
	"TBODY",
	"TFOOT",
	"THEAD",
	"TR",
	"TD",
	"TH",
]);
const SELECT_ALLOWED_START_TAGS = new Set([
	"HTML",
	"OPTION",
	"OPTGROUP",
	"HR",
	"SELECT",
	...SELECT_BREAKOUT_START_TAGS,
	"SCRIPT",
	"TEMPLATE",
]);
const SELECT_ALLOWED_END_TAGS = new Set(["OPTION", "OPTGROUP", "SELECT", "TEMPLATE"]);
const COLGROUP_CLOSING_START_TAGS = new Set([
	"CAPTION",
	"COLGROUP",
	"TABLE",
	"TBODY",
	"TD",
	"TFOOT",
	"TH",
	"THEAD",
	"TR",
]);
const CAPTION_CLOSING_START_TAGS = new Set([
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

const IMPLIED_END_TAG_ELEMENTS = new Set([
	"DD",
	"DT",
	"LI",
	"OPTGROUP",
	"OPTION",
	"P",
	"RB",
	"RP",
	"RT",
	"RTC",
]);
const RUBY_IMPLIED_END_TAG_START_TAGS = new Set([
	"RB",
	"RP",
	"RT",
	"RTC",
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
	"LISTING",
	"MAIN",
	"MENU",
	"NAV",
	"OL",
	"P",
	"PLAINTEXT",
	"PRE",
	"SEARCH",
	"SECTION",
	"SUMMARY",
	"TABLE",
	"UL",
	"XMP",
	...HEADING_ELEMENTS,
]);

const FOREIGN_SCOPE_BOUNDARIES = [
	"math MI",
	"math MO",
	"math MN",
	"math MS",
	"math MTEXT",
	"math ANNOTATION-XML",
	"svg FOREIGNOBJECT",
	"svg DESC",
	"svg TITLE",
];

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
	...FOREIGN_SCOPE_BOUNDARIES,
]);

const DEFAULT_SCOPE_BOUNDARIES = new Set([
	"APPLET",
	"CAPTION",
	"HTML",
	"MARQUEE",
	"OBJECT",
	"TABLE",
	"TD",
	"TEMPLATE",
	"TH",
	...FOREIGN_SCOPE_BOUNDARIES,
]);

const LIST_ITEM_SCOPE_BOUNDARIES = new Set([
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
	...FOREIGN_SCOPE_BOUNDARIES,
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
	"TD",
	"TH",
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
const DOCTYPE_INFO_INTERNAL = Symbol("WP_HTML_Doctype_Info internal constructor");

export class WP_HTML_Unsupported_Exception extends Error {
	constructor(message, tokenName, tokenAt, token, stackOfOpenElements, activeFormattingElements) {
		const normalizedMessage = phpStringParameterCoerce(message, "message");
		const normalizedTokenName = phpStringParameterCoerce(tokenName, "token_name");
		const normalizedTokenAt = phpIntegerParameterCoerce(tokenAt, "token_at");
		const normalizedToken = phpStringParameterCoerce(token, "token");
		const normalizedStackOfOpenElements = phpArrayParameterCoerce(
			stackOfOpenElements,
			"stack_of_open_elements",
		);
		const normalizedActiveFormattingElements = phpArrayParameterCoerce(
			activeFormattingElements,
			"active_formatting_elements",
		);

		super(normalizedMessage);
		this.name = "WP_HTML_Unsupported_Exception";
		this.token_name = normalizedTokenName;
		this.token_at = normalizedTokenAt;
		this.token = normalizedToken;
		this.stack_of_open_elements = normalizedStackOfOpenElements;
		this.active_formatting_elements = normalizedActiveFormattingElements;
	}
}

export class WP_HTML_Span {
	constructor(start, length) {
		this.start = phpIntegerParameterCoerce(start, "start");
		this.length = phpIntegerParameterCoerce(length, "length");
	}
}

export class WP_HTML_Text_Replacement {
	constructor(start, length, text) {
		this.start = phpIntegerParameterCoerce(start, "start");
		this.length = phpIntegerParameterCoerce(length, "length");
		this.text = phpStringParameterCoerce(text, "text");
	}
}

export class WP_HTML_Attribute_Token {
	constructor(name, valueStart, valueLength, start, length, isTrue) {
		this.name = name;
		this.value_starts_at = valueStart;
		this.value_length = valueLength;
		this.start = start;
		this.length = length;
		this.is_true = isTrue;
	}
}

export class WP_HTML_Token {
	constructor(bookmarkName, nodeName, hasSelfClosingFlag, onDestroy = null) {
		this.bookmark_name = phpStringParameterCoerce(bookmarkName, "bookmark_name", true);
		this.namespace = "html";
		this.node_name = phpStringParameterCoerce(nodeName, "node_name");
		this.has_self_closing_flag = phpBooleanParameterCoerce(hasSelfClosingFlag, "has_self_closing_flag");
		this.integration_node_type = null;
		if (onDestroy !== null && typeof onDestroy !== "function") {
			throw new TypeError("Argument $on_destroy must be callable or null.");
		}
		this.on_destroy = onDestroy;
	}

	destroy() {
		if (typeof this.on_destroy === "function") {
			this.on_destroy(this.bookmark_name);
		}
		this.on_destroy = null;
	}

	free() {
		this.destroy();
	}
}

export class WP_HTML_Stack_Event {
	static POP = "pop";
	static PUSH = "push";

	constructor(token, operation, provenance) {
		this.token = phpTokenParameterCoerce(token, "token");
		this.operation = phpStringParameterCoerce(operation, "operation");
		this.provenance = phpStringParameterCoerce(provenance, "provenance");
	}
}

export class WP_HTML_Active_Formatting_Elements {
	#stack = [];

	contains_node(token) {
		const normalizedToken = phpTokenParameterCoerce(token, "token");
		for (const item of this.walk_up()) {
			if (normalizedToken.bookmark_name === item.bookmark_name) {
				return true;
			}
		}
		return false;
	}

	count() {
		return this.#stack.length;
	}

	current_node() {
		return this.#stack.at(-1) ?? null;
	}

	insert_marker() {
		this.push(new WP_HTML_Token(null, "marker", false));
	}

	push(token) {
		this.#stack.push(phpTokenParameterCoerce(token, "token"));
	}

	remove_node(token) {
		const normalizedToken = phpTokenParameterCoerce(token, "token");
		for (let i = this.#stack.length - 1; i >= 0; i -= 1) {
			if (normalizedToken.bookmark_name !== this.#stack[i].bookmark_name) {
				continue;
			}
			this.#stack.splice(i, 1);
			return true;
		}
		return false;
	}

	*walk_down() {
		for (const item of this.#stack) {
			yield item;
		}
	}

	*walk_up() {
		for (let i = this.#stack.length - 1; i >= 0; i -= 1) {
			yield this.#stack[i];
		}
	}

	clear_up_to_last_marker() {
		while (this.#stack.length > 0) {
			const item = this.#stack.pop();
			if (item.node_name === "marker") {
				break;
			}
		}
	}
}

export class WP_HTML_Open_Elements {
	stack = [];

	#hasPInButtonScope = false;
	#popHandler = null;
	#pushHandler = null;

	set_pop_handler(handler) {
		if (typeof handler !== "function") {
			throw new TypeError("Argument $handler must be callable.");
		}
		this.#popHandler = handler;
	}

	set_push_handler(handler) {
		if (typeof handler !== "function") {
			throw new TypeError("Argument $handler must be callable.");
		}
		this.#pushHandler = handler;
	}

	at(nth) {
		let remaining = phpIntegerParameterCoerce(nth, "nth");
		for (const item of this.walk_down()) {
			remaining -= 1;
			if (remaining === 0) {
				return item;
			}
		}
		return null;
	}

	contains(nodeName) {
		const normalizedNodeName = phpStringParameterCoerce(nodeName, "node_name");
		for (const item of this.walk_up()) {
			if (normalizedNodeName === item.node_name) {
				return true;
			}
		}
		return false;
	}

	contains_node(token) {
		const normalizedToken = phpTokenParameterCoerce(token, "token");
		for (const item of this.walk_up()) {
			if (normalizedToken === item) {
				return true;
			}
		}
		return false;
	}

	count() {
		return this.stack.length;
	}

	current_node() {
		return this.stack.at(-1) ?? null;
	}

	current_node_is(identity) {
		const normalizedIdentity = phpStringParameterCoerce(identity, "identity");
		const currentNode = this.current_node();
		if (currentNode === null) {
			return false;
		}

		const currentNodeName = currentNode.node_name;
		return (
			currentNodeName === normalizedIdentity ||
			(normalizedIdentity === "#doctype" && currentNodeName === "html") ||
			(normalizedIdentity === "#tag" && /^[A-Z]+$/.test(currentNodeName))
		);
	}

	has_element_in_specific_scope(tagName, terminationList) {
		const normalizedTagName = phpStringParameterCoerce(tagName, "tag_name");
		let terminationSet = null;
		for (const node of this.walk_up()) {
			const namespacedName = openElementNamespacedName(node);

			if (namespacedName === normalizedTagName) {
				return true;
			}

			if (
				normalizedTagName === "(internal: H1 through H6 - do not use)" &&
				HEADING_ELEMENTS.has(namespacedName)
			) {
				return true;
			}

			if (terminationSet === null) {
				terminationSet = new Set(phpArrayParameterCoerce(terminationList, "termination_list"));
			}

			if (terminationSet.has(namespacedName)) {
				return false;
			}
		}

		return false;
	}

	has_element_in_scope(tagName) {
		const normalizedTagName = phpStringParameterCoerce(tagName, "tag_name");
		return this.has_element_in_specific_scope(normalizedTagName, [
			"APPLET",
			"CAPTION",
			"HTML",
			"TABLE",
			"TD",
			"TH",
			"MARQUEE",
			"OBJECT",
			"TEMPLATE",
			...FOREIGN_SCOPE_BOUNDARIES,
		]);
	}

	has_element_in_list_item_scope(tagName) {
		const normalizedTagName = phpStringParameterCoerce(tagName, "tag_name");
		return this.has_element_in_specific_scope(normalizedTagName, [
			"APPLET",
			"BUTTON",
			"CAPTION",
			"HTML",
			"TABLE",
			"TD",
			"TH",
			"MARQUEE",
			"OBJECT",
			"OL",
			"TEMPLATE",
			"UL",
			...FOREIGN_SCOPE_BOUNDARIES,
		]);
	}

	has_element_in_button_scope(tagName) {
		const normalizedTagName = phpStringParameterCoerce(tagName, "tag_name");
		return this.has_element_in_specific_scope(normalizedTagName, [
			"APPLET",
			"BUTTON",
			"CAPTION",
			"HTML",
			"TABLE",
			"TD",
			"TH",
			"MARQUEE",
			"OBJECT",
			"TEMPLATE",
			...FOREIGN_SCOPE_BOUNDARIES,
		]);
	}

	has_element_in_table_scope(tagName) {
		const normalizedTagName = phpStringParameterCoerce(tagName, "tag_name");
		return this.has_element_in_specific_scope(normalizedTagName, ["HTML", "TABLE", "TEMPLATE"]);
	}

	has_element_in_select_scope(tagName) {
		const normalizedTagName = phpStringParameterCoerce(tagName, "tag_name");
		for (const node of this.walk_up()) {
			if (node.node_name === normalizedTagName) {
				return true;
			}

			if (node.node_name !== "OPTION" && node.node_name !== "OPTGROUP") {
				return false;
			}
		}

		return false;
	}

	has_p_in_button_scope() {
		return this.#hasPInButtonScope;
	}

	pop() {
		const item = this.stack.pop();
		if (item === undefined) {
			return false;
		}

		this.after_element_pop(item);
		return true;
	}

	pop_until(htmlTagName) {
		const normalizedHtmlTagName = phpStringParameterCoerce(htmlTagName, "html_tag_name");
		while (this.stack.length > 0) {
			const item = this.current_node();
			this.pop();

			if (item.namespace !== "html") {
				continue;
			}

			if (
				normalizedHtmlTagName === "(internal: H1 through H6 - do not use)" &&
				HEADING_ELEMENTS.has(item.node_name)
			) {
				return true;
			}

			if (normalizedHtmlTagName === item.node_name) {
				return true;
			}
		}

		return false;
	}

	push(stackItem) {
		const normalizedStackItem = phpTokenParameterCoerce(stackItem, "stack_item");
		this.stack.push(normalizedStackItem);
		this.after_element_push(normalizedStackItem);
	}

	remove_node(token) {
		const normalizedToken = phpTokenParameterCoerce(token, "token");
		for (let i = this.stack.length - 1; i >= 0; i -= 1) {
			const item = this.stack[i];
			if (normalizedToken.bookmark_name !== item.bookmark_name) {
				continue;
			}

			this.stack.splice(i, 1);
			this.after_element_pop(item);
			return true;
		}
		return false;
	}

	*walk_down() {
		for (const item of this.stack) {
			yield item;
		}
	}

	*walk_up(aboveThisNode = null) {
		const normalizedAboveThisNode = aboveThisNode === null
			? null
			: phpTokenParameterCoerce(aboveThisNode, "above_this_node");
		let hasFoundNode = normalizedAboveThisNode === null;
		for (let i = this.stack.length - 1; i >= 0; i -= 1) {
			const node = this.stack[i];

			if (!hasFoundNode) {
				hasFoundNode = node === normalizedAboveThisNode;
				continue;
			}

			yield node;
		}
	}

	after_element_push(item) {
		const normalizedItem = phpTokenParameterCoerce(item, "item");
		switch (openElementNamespacedName(normalizedItem)) {
			case "APPLET":
			case "BUTTON":
			case "CAPTION":
			case "HTML":
			case "TABLE":
			case "TD":
			case "TH":
			case "MARQUEE":
			case "OBJECT":
			case "TEMPLATE":
			case "math MI":
			case "math MO":
			case "math MN":
			case "math MS":
			case "math MTEXT":
			case "math ANNOTATION-XML":
			case "svg FOREIGNOBJECT":
			case "svg DESC":
			case "svg TITLE":
				this.#hasPInButtonScope = false;
				break;

			case "P":
				this.#hasPInButtonScope = true;
				break;
		}

		if (typeof this.#pushHandler === "function") {
			this.#pushHandler(normalizedItem);
		}
	}

	after_element_pop(item) {
		const normalizedItem = phpTokenParameterCoerce(item, "item");
		switch (openElementNamespacedName(normalizedItem)) {
			case "APPLET":
			case "BUTTON":
			case "CAPTION":
			case "HTML":
			case "P":
			case "TABLE":
			case "TD":
			case "TH":
			case "MARQUEE":
			case "OBJECT":
			case "TEMPLATE":
			case "math MI":
			case "math MO":
			case "math MN":
			case "math MS":
			case "math MTEXT":
			case "math ANNOTATION-XML":
			case "svg FOREIGNOBJECT":
			case "svg DESC":
			case "svg TITLE":
				this.#hasPInButtonScope = this.has_element_in_button_scope("P");
				break;
		}

		if (typeof this.#popHandler === "function") {
			this.#popHandler(normalizedItem);
		}
	}

	clear_to_table_context() {
		while (this.stack.length > 0) {
			const item = this.current_node();
			if (["TABLE", "TEMPLATE", "HTML"].includes(item.node_name)) {
				break;
			}
			this.pop();
		}
	}

	clear_to_table_body_context() {
		while (this.stack.length > 0) {
			const item = this.current_node();
			if (["TBODY", "TFOOT", "THEAD", "TEMPLATE", "HTML"].includes(item.node_name)) {
				break;
			}
			this.pop();
		}
	}

	clear_to_table_row_context() {
		while (this.stack.length > 0) {
			const item = this.current_node();
			if (["TR", "TEMPLATE", "HTML"].includes(item.node_name)) {
				break;
			}
			this.pop();
		}
	}
}

function openElementNamespacedName(token) {
	return token.namespace === "html" ? token.node_name : `${token.namespace} ${token.node_name}`;
}

export class WP_HTML_Processor_State {
	static INSERTION_MODE_INITIAL = "insertion-mode-initial";
	static INSERTION_MODE_BEFORE_HTML = "insertion-mode-before-html";
	static INSERTION_MODE_BEFORE_HEAD = "insertion-mode-before-head";
	static INSERTION_MODE_IN_HEAD = "insertion-mode-in-head";
	static INSERTION_MODE_IN_HEAD_NOSCRIPT = "insertion-mode-in-head-noscript";
	static INSERTION_MODE_AFTER_HEAD = "insertion-mode-after-head";
	static INSERTION_MODE_IN_BODY = "insertion-mode-in-body";
	static INSERTION_MODE_IN_TABLE = "insertion-mode-in-table";
	static INSERTION_MODE_IN_TABLE_TEXT = "insertion-mode-in-table-text";
	static INSERTION_MODE_IN_CAPTION = "insertion-mode-in-caption";
	static INSERTION_MODE_IN_COLUMN_GROUP = "insertion-mode-in-column-group";
	static INSERTION_MODE_IN_TABLE_BODY = "insertion-mode-in-table-body";
	static INSERTION_MODE_IN_ROW = "insertion-mode-in-row";
	static INSERTION_MODE_IN_CELL = "insertion-mode-in-cell";
	static INSERTION_MODE_IN_SELECT = "insertion-mode-in-select";
	static INSERTION_MODE_IN_SELECT_IN_TABLE = "insertion-mode-in-select-in-table";
	static INSERTION_MODE_IN_TEMPLATE = "insertion-mode-in-template";
	static INSERTION_MODE_AFTER_BODY = "insertion-mode-after-body";
	static INSERTION_MODE_IN_FRAMESET = "insertion-mode-in-frameset";
	static INSERTION_MODE_AFTER_FRAMESET = "insertion-mode-after-frameset";
	static INSERTION_MODE_AFTER_AFTER_BODY = "insertion-mode-after-after-body";
	static INSERTION_MODE_AFTER_AFTER_FRAMESET = "insertion-mode-after-after-frameset";

	constructor() {
		this.stack_of_template_insertion_modes = [];
		this.stack_of_open_elements = new WP_HTML_Open_Elements();
		this.active_formatting_elements = new WP_HTML_Active_Formatting_Elements();
		this.current_token = null;
		this.insertion_mode = WP_HTML_Processor_State.INSERTION_MODE_INITIAL;
		this.context_node = null;
		this.encoding = null;
		this.encoding_confidence = "tentative";
		this.head_element = null;
		this.form_element = null;
		this.frameset_ok = true;
	}
}

export class WP_HTML_Doctype_Info {
	constructor(name, publicIdentifier, systemIdentifier, forceQuirksFlag, internalToken = null) {
		if (internalToken !== DOCTYPE_INFO_INTERNAL) {
			throw new TypeError("WP_HTML_Doctype_Info constructor is private.");
		}

		const normalizedName = phpStringParameterCoerce(name, "name", true);
		const normalizedPublicIdentifier = phpStringParameterCoerce(publicIdentifier, "public_identifier", true);
		const normalizedSystemIdentifier = phpStringParameterCoerce(systemIdentifier, "system_identifier", true);
		const normalizedForceQuirksFlag = phpBooleanParameterCoerce(forceQuirksFlag, "force_quirks_flag");

		this.name = normalizedName;
		this.public_identifier = normalizedPublicIdentifier;
		this.system_identifier = normalizedSystemIdentifier;
		this.indicated_compatibility_mode = doctypeCompatibilityMode(
			normalizedName,
			normalizedPublicIdentifier,
			normalizedSystemIdentifier,
			normalizedForceQuirksFlag,
		);
	}

	static from_doctype_token(doctypeHtml) {
		let doctype = phpStringParameterCoerce(doctypeHtml, "doctype_html");
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
			return createDoctypeInfo(null, null, null, true);
		}

		const nameStart = at;
		while (at < end && !isHtmlWhitespaceCode(doctype.charCodeAt(at))) {
			at += 1;
		}
		const name = replaceNulls(doctype.slice(nameStart, at).toLowerCase());

		at = skipHtmlWhitespace(doctype, at, end);
		if (at >= end) {
			return createDoctypeInfo(name, null, null, false);
		}

		if (at + 6 >= end) {
			return createDoctypeInfo(name, null, null, true);
		}

		if (asciiStartsWithAt(doctype, "PUBLIC", at)) {
			at = skipHtmlWhitespace(doctype, at + 6, end);
			if (at >= end) {
				return createDoctypeInfo(name, null, null, true);
			}
			return parsePublicIdentifier(doctype, at, end, name);
		}

		if (asciiStartsWithAt(doctype, "SYSTEM", at)) {
			at = skipHtmlWhitespace(doctype, at + 6, end);
			if (at >= end) {
				return createDoctypeInfo(name, null, null, true);
			}
			return parseSystemIdentifier(doctype, at, end, name, null);
		}

		return createDoctypeInfo(name, null, null, true);
	}
}

async function wasmSourceFromInput(input) {
	input = await input;

	if (isWebAssemblyInstantiatedSource(input)) {
		return input.instance;
	}

	if (isWebAssemblyExports(input)) {
		return input;
	}

	if (input instanceof WebAssembly.Instance) {
		return input;
	}

	if (input instanceof WebAssembly.Module) {
		return input;
	}

	if (input instanceof ArrayBuffer) {
		return input;
	}

	if (ArrayBuffer.isView(input)) {
		return input instanceof Uint8Array
			? input
			: new Uint8Array(input.buffer, input.byteOffset, input.byteLength);
	}

	if (input instanceof URL) {
		if (input.protocol === "file:" && isNodeLikeRuntime()) {
			return nodeFileUrlBytes(input);
		}
		if (typeof fetch === "function") {
			return fetchWasmSource(input);
		}
		throw new TypeError("Unsupported WASM input.");
	}

	if (typeof Response === "function" && input instanceof Response) {
		return responseWasmSource(input);
	}

	if (typeof Blob === "function" && input instanceof Blob) {
		return input.arrayBuffer();
	}

	if (typeof Request === "function" && input instanceof Request) {
		if (typeof fetch === "function") {
			return fetchWasmSource(input);
		}
		throw new TypeError("Unsupported WASM input.");
	}

	if (typeof input === "string") {
		if (/^file:\/\//.test(input) && isNodeLikeRuntime()) {
			return nodeFileUrlBytes(input);
		}

		if (typeof fetch === "function" && (/^https?:\/\//.test(input) || !isNodeLikeRuntime())) {
			return fetchWasmSource(input);
		}

		const { readFile } = await import("node:fs/promises");
		return readFile(input);
	}

	throw new TypeError("Unsupported WASM input.");
}

async function nodeFileUrlBytes(input) {
	const [{ readFile }, { fileURLToPath }] = await Promise.all([
		import("node:fs/promises"),
		import("node:url"),
	]);
	return readFile(fileURLToPath(input));
}

function isWebAssemblyInstantiatedSource(input) {
	return input !== null &&
		typeof input === "object" &&
		input.instance instanceof WebAssembly.Instance;
}

function isNodeLikeRuntime() {
	return typeof process === "object" && process !== null && Boolean(process.versions?.node);
}

async function fetchWasmSource(input) {
	return responseWasmSource(await fetch(input));
}

async function responseWasmSource(response) {
	if (!response.ok) {
		throw new Error(`Failed to load WASM: ${response.status} ${response.statusText}`);
	}

	if (
		typeof WebAssembly.instantiateStreaming === "function" &&
		typeof Response === "function" &&
		response instanceof Response &&
		typeof response.clone === "function"
	) {
		try {
			return (await WebAssembly.instantiateStreaming(response.clone(), {})).instance;
		} catch {
			// Fall through to byte-buffer loading when streaming compilation is unavailable for the response.
		}
	}

	return response.arrayBuffer();
}

export async function loadWasm(input = new URL("./dist/wp_html_api_rust_core.wasm", import.meta.url)) {
	const source = await wasmSourceFromInput(input);
	if (isWebAssemblyExports(source)) {
		return createHtmlApi(source);
	}
	if (source instanceof WebAssembly.Instance) {
		return createHtmlApi(source.exports);
	}
	const module = source instanceof WebAssembly.Module ? source : await WebAssembly.compile(source);
	const instance = await WebAssembly.instantiate(module, {});

	return createHtmlApi(instance.exports);
}

export function createHtmlApi(wasm) {
	const wasmExports = wasmExportsFromInput(wasm);
	const runtime = new WasmRuntime(wasmExports);

	class WP_HTML_Decoder {
		static attribute_starts_with(haystack, searchText, caseSensitivity = "case-sensitive") {
			if (typeof haystack !== "string" || typeof searchText !== "string") {
				return phpAttributeStartsWithNonStringScalar(haystack, searchText);
			}

			return runtime.decoderAttributeStartsWith(
				haystack,
				searchText,
				caseSensitivity === "ascii-case-insensitive",
			);
		}

		static decode_text_node(text) {
			return runtime.decoderDecode("data", phpStringParameterCoerce(text, "text"));
		}

		static decode_attribute(text) {
			return runtime.decoderDecode("attribute", phpStringParameterCoerce(text, "text"));
		}

		static decode(context, text) {
			return runtime.decoderDecode(
				context,
				phpStringParameterCoerce(text, "text"),
			);
		}

		static read_character_reference(context, text, at = 0, matchByteLength = null) {
			return runtime.decoderReadCharacterReference(
				context,
				phpInternalStringCoerce(text, "text"),
				at,
				matchByteLength,
			);
		}

		static code_point_to_utf8_bytes(codePoint) {
			return runtime.decoderCodePointToUtf8Bytes(codePoint);
		}
	}

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
		#reportIncompleteTokens = true;
		#pausedAtJsIncompleteToken = false;
		#classNameUpdates = new Map();

		constructor(html, options = {}) {
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
			this.#reportIncompleteTokens = options.reportIncompleteTokens !== false;
			this.#pausedAtJsIncompleteToken = false;

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

			const normalizedTagName = tagName === null ? null : asciiUpper(tagName);

			let found = 0;
			while (this.next_token()) {
				if (this.get_token_type() !== "#tag") {
					continue;
				}

				if (this.is_tag_closer() && !visitClosers) {
					continue;
				}

				if (normalizedTagName !== null && asciiUpper(this.get_tag() ?? "") !== normalizedTagName) {
					continue;
				}

				if (className !== null && this.has_class(className) !== true) {
					continue;
				}

				found += 1;
				if (found < matchOffset) {
					continue;
				}

				return true;
			}

			return false;
		}

		next_token() {
			this.#ensureLive();
			this.#syncLexicalUpdates();
			this.#pausedAtJsIncompleteToken = false;

			if (wasm.wp_html_api_rust_tag_processor_next_token(this.pointer)) {
				this.#updateParserStateFromNative();
				if (this.#reportIncompleteTokens && this.#currentTokenIsIncompleteAtEof()) {
					this.#pausedAtJsIncompleteToken = true;
					this.parser_state = STATE_INCOMPLETE_INPUT;
					return false;
				}
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

			const attributeName = phpInternalStringCoerce(name, "name");
			if (attributeName === "class") {
				this.#flushClassNameUpdates();
			}

			return this.#readNativeAttribute(attributeName);
		}

		get_attribute_names_with_prefix(prefix) {
			this.#ensureLive();
			if (this.parser_state !== STATE_MATCHED_TAG) {
				return null;
			}

			if (this.is_tag_closer() || this.#isRawTagCloser()) {
				return null;
			}

			const attributePrefix = phpInternalStringCoerce(prefix, "prefix");
			return runtime.withEncoded(attributePrefix, ({ ptr, len }) => runtime.withOutSlice((out) => {
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

			if (this.is_tag_closer() || this.#isRawTagCloser()) {
				return false;
			}

			const attributeName = phpInternalStringCoerce(name, "name");
			if (!isValidAttributeName(attributeName)) {
				return false;
			}

			let valueKind = 2;
			let encodedValue = new Uint8Array();
			if (value === false) {
				valueKind = 0;
			} else if (value === true) {
				valueKind = 1;
			} else if (value === null) {
				return false;
			} else {
				encodedValue = runtime.encode(phpStringParameterCoerce(value, "value"));
			}

			const result = this.#setNativeAttribute(attributeName, encodedValue, valueKind);
			if (result && asciiLower(attributeName) === "class") {
				this.#classNameUpdates.clear();
			}
			return result;
		}

		remove_attribute(name) {
			this.#ensureLive();
			if (this.parser_state !== STATE_MATCHED_TAG) {
				return false;
			}

			if (this.is_tag_closer() || this.#isRawTagCloser()) {
				return false;
			}

			const attributeName = phpInternalStringCoerce(name, "name");
			if (asciiLower(attributeName) === "class") {
				this.#classNameUpdates.clear();
			}
			return this.#removeNativeAttribute(attributeName);
		}

		add_class(className) {
			this.#ensureLive();
			if (this.parser_state !== STATE_MATCHED_TAG) {
				return false;
			}

			if (this.is_tag_closer() || this.#isRawTagCloser()) {
				return false;
			}

			const normalizedClassName = phpClassUpdateKey(className, "class_name");
			this.#queueClassNameUpdate(normalizedClassName, CLASS_UPDATE_ADD);
			return true;
		}

		remove_class(className) {
			this.#ensureLive();
			if (this.parser_state !== STATE_MATCHED_TAG) {
				return false;
			}

			if (this.is_tag_closer() || this.#isRawTagCloser()) {
				return false;
			}

			const normalizedClassName = phpClassUpdateKey(className, "class_name");
			this.#queueClassNameUpdate(normalizedClassName, CLASS_UPDATE_REMOVE);
			return true;
		}

		has_class(className) {
			this.#ensureLive();
			if (this.parser_state !== STATE_MATCHED_TAG) {
				return null;
			}

			const wantedClass = phpInternalStringCoerce(className, "wanted_class");
			if (this.is_tag_closer() || this.#isRawTagCloser()) {
				return false;
			}

			this.#flushClassNameUpdates();
			return runtime.withEncoded(wantedClass, ({ ptr, len }) => {
				const result = wasm.wp_html_api_rust_tag_processor_has_class(this.pointer, ptr, len, this.#isQuirksMode());
				return result === 0 ? null : result === 2;
			});
		}

		class_list() {
			this.#ensureLive();
			if (this.parser_state !== STATE_MATCHED_TAG) {
				return null;
			}

			if (this.is_tag_closer() || this.#isRawTagCloser()) {
				return [];
			}

			this.#flushClassNameUpdates();
			return runtime.withOutSlice((out) => {
				if (wasm.wp_html_api_rust_tag_processor_class_list(this.pointer, out, this.#isQuirksMode()) === 0) {
					return null;
				}

				return splitUnitSeparatedString(runtime.readStringFromOut(out));
			});
		}

		is_tag_closer() {
			this.#ensureLive();
			if (this.parser_state !== STATE_MATCHED_TAG) {
				return false;
			}
			return Boolean(wasm.wp_html_api_rust_tag_processor_is_tag_closer(this.pointer));
		}

		has_self_closing_flag() {
			this.#ensureLive();
			if (this.parser_state !== STATE_MATCHED_TAG) {
				return false;
			}
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
			return this.#pausedAtJsIncompleteToken ||
				Boolean(wasm.wp_html_api_rust_tag_processor_paused_at_incomplete(this.pointer));
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
			if (
				![
					STATE_MATCHED_TAG,
					STATE_TEXT_NODE,
					STATE_CDATA_NODE,
					STATE_COMMENT,
					STATE_DOCTYPE,
					STATE_PRESUMPTUOUS_TAG,
					STATE_FUNKY_COMMENT,
				].includes(this.parser_state)
			) {
				return "";
			}

			return runtime.readOutputString((out) => (
				wasm.wp_html_api_rust_tag_processor_get_modifiable_text(this.pointer, out)
			)) ?? "";
		}

		native_get_script_content_type() {
			this.#ensureLive();
			if (
				this.parser_state !== STATE_MATCHED_TAG ||
				this.get_tag() !== "SCRIPT" ||
				this.get_namespace() !== "html"
			) {
				return null;
			}

			switch (wasm.wp_html_api_rust_tag_processor_script_content_type(this.pointer)) {
				case 1:
					return "javascript";
				case 2:
					return "json";
				default:
					return null;
			}
		}

		set_modifiable_text(text) {
			this.#ensureLive();
			const plaintextContent = phpStringParameterCoerce(text, "plaintext_content");
			if (![STATE_MATCHED_TAG, STATE_TEXT_NODE, STATE_COMMENT].includes(this.parser_state)) {
				return false;
			}

			return this.#mutateCurrentToken(() => runtime.withEncoded(plaintextContent, ({ ptr, len }) => (
				wasm.wp_html_api_rust_tag_processor_set_modifiable_text(this.pointer, ptr, len)
			)));
		}

		get_comment_type() {
			this.#ensureLive();
			if (this.parser_state !== STATE_COMMENT) {
				return null;
			}

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
			if (
				this.parser_state === STATE_COMPLETE ||
				this.parser_state === STATE_INCOMPLETE_INPUT
			) {
				return false;
			}

			const bookmarkName = phpArrayKeyParameterCoerce(name, "name");
			const maxBookmarks = this.constructor.MAX_BOOKMARKS ?? WP_HTML_Tag_Processor.MAX_BOOKMARKS;
			if (this.bookmarks.size >= maxBookmarks && !this.bookmarks.has(bookmarkName)) {
				return false;
			}

			const span = this.#currentSpan();
			if (!span) {
				return false;
			}

			this.bookmarks.set(bookmarkName, span);
			return true;
		}

		release_bookmark(name) {
			return this.bookmarks.delete(phpArrayKeyParameterCoerce(name, "name"));
		}

		has_bookmark(name) {
			return this.bookmarks.has(phpArrayKeyParameterCoerce(name, "name"));
		}

		seek(name) {
			this.#ensureLive();
			this.#pausedAtJsIncompleteToken = false;
			const bookmarkName = phpArrayKeyParameterCoerce(name, "name");
			if (!this.bookmarks.has(bookmarkName)) {
				return false;
			}

			const bookmark = this.bookmarks.get(bookmarkName);
			const currentSpan = this.#currentSpan();
			if (
				currentSpan &&
				currentSpan.start === bookmark.start &&
				currentSpan.length === bookmark.length
			) {
				this.#updateParserStateFromNative();
				return true;
			}

			if (this.seek_count >= WP_HTML_Tag_Processor.MAX_SEEK_OPS) {
				return false;
			}

			this.seek_count += 1;
			this.#syncLexicalUpdates();
			const updatedBookmark = this.bookmarks.get(bookmarkName);
			wasm.wp_html_api_rust_tag_processor_seek(this.pointer, updatedBookmark.start);
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
			const normalizedNamespaceName = phpStringParameterCoerce(namespaceName, "new_namespace");
			if (!["html", "math", "svg"].includes(normalizedNamespaceName)) {
				return false;
			}

			this.parsing_namespace = normalizedNamespaceName;
			wasm.wp_html_api_rust_tag_processor_set_namespace(this.pointer, normalizedNamespaceName === "html" ? 0 : 1);
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
			if (attributeName === null) {
				return null;
			}

			const normalizedAttributeName = phpInternalStringCoerce(attributeName, "attribute_name");
			if (this.parsing_namespace === "html") {
				return normalizedAttributeName;
			}
			return qualifyForeignAttributeName(this.parsing_namespace, normalizedAttributeName);
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

		get_updated_html(flushClassNameUpdates = true) {
			this.#ensureLive();
			if (flushClassNameUpdates) {
				this.#flushClassNameUpdates();
			}
			return runtime.readOutputString((out) => (
				wasm.wp_html_api_rust_tag_processor_get_html(this.pointer, out)
			)) ?? "";
		}

		toString() {
			return this.get_updated_html();
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

		#currentTokenIsIncompleteAtEof() {
			const span = this.#currentSpan();
			if (span === null || span.start + span.length !== this.html.length) {
				return false;
			}

			const tokenType = this.get_token_type();
			const tokenHtml = this.html.slice(span.start);
			if (
				(tokenType === "#comment" || tokenType === "#funky-comment") &&
				incompleteBogusCommentAtEof(tokenHtml)
			) {
				return true;
			}

			if (
				tokenType !== "#tag" ||
				this.get_namespace() !== "html" ||
				this.is_tag_closer()
			) {
				return false;
			}

			const tagName = this.get_tag();
			if (!SPECIAL_ATOMIC_ELEMENTS.has(tagName)) {
				return false;
			}

			const startTag = completeStartTagAt(this.html, span.start);
			return startTag !== null &&
				startTag.tagName === tagName &&
				findSpecialAtomicCloserEnd(this.html, startTag.end, tagName) === null;
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

		#isRawTagCloser() {
			const token = this.#currentTokenBytes();
			return token !== null && token.length >= 2 && token[0] === 0x3c && token[1] === 0x2f;
		}

		#readNativeAttribute(attributeName) {
			return runtime.withEncoded(attributeName, ({ ptr, len }) => runtime.withOutSlice((out) => {
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

		#setNativeAttribute(attributeName, encodedValue, valueKind) {
			return this.#mutateCurrentToken(() => runtime.withEncoded(attributeName, (nameBytes) => (
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

		#removeNativeAttribute(attributeName) {
			return this.#mutateCurrentToken(() => runtime.withEncoded(attributeName, ({ ptr, len }) => (
				wasm.wp_html_api_rust_tag_processor_remove_attribute(this.pointer, ptr, len)
			)));
		}

		#queueClassNameUpdate(className, operation) {
			if (this.#isQuirksMode()) {
				for (const queued of this.#classNameUpdates.values()) {
					if (
						queued.name.length === className.name.length &&
						asciiLower(queued.name) === asciiLower(className.name)
					) {
						queued.operation = operation;
						return;
					}
				}
			}

			this.#classNameUpdates.set(classUpdateMapKey(className), {
				isIntegerKey: className.isIntegerKey,
				name: className.name,
				operation,
			});
		}

		#flushClassNameUpdates() {
			if (this.#classNameUpdates.size === 0 || this.parser_state !== STATE_MATCHED_TAG) {
				return false;
			}

			const updates = Array.from(this.#classNameUpdates.values());
			this.#classNameUpdates.clear();

			let existingClass = this.#readNativeAttribute("class");
			if (existingClass === null || existingClass === true) {
				existingClass = "";
			}

			const result = applyClassNameUpdates(existingClass, updates, this.#isQuirksMode());
			if (!result.modified) {
				return false;
			}

			if (result.className.length > 0) {
				return this.#setNativeAttribute("class", runtime.encode(result.className), 2);
			}
			return this.#removeNativeAttribute("class");
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
			this.html = this.get_updated_html(false);
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

		constructor(html, options = undefined) {
			if (
				options === undefined ||
				options === null ||
				typeof options !== "object" ||
				Array.isArray(options)
			) {
				options = typeof html === "string"
					? {
						fullParser: true,
						encodingConfidence: "certain",
					}
					: {};
			}

			super(html, { reportIncompleteTokens: false });
			this.last_error = null;
			this.unsupported_exception = null;
			this.current_virtual = null;
			this.current_synthetic_token = null;
			this.synthetic_eof_comment_consumed = false;
			this.delayed_synthetic_tokens = [];
			this.virtual_tokens = [];
			this.pending_real_token = false;
			this.pending_real_parser_state = null;
			this.paragraph_adoption_preclosed_formatting_elements = [];
			this.special_start_adoption_preclosed_formatting_elements = [];
			this.deep_anchor_reconstructed_div_start_offsets = new Set();
			this.table_nobr_reconstructed_start_offsets = new Set();
			this.deferred_table_opener = null;
			this.deferred_table_child_openers = [];
			this.pending_foreign_table_fostered_text_table_index = null;
			this.pending_nested_anchor_outer_closer_after_deferred_table_index = null;
			this.pending_nested_anchor_active_removal_after_deferred_table = false;
			this.pending_nested_anchor_div_active_removal_after_deferred_table = false;
			this.skip_current_token = false;
			this.is_html_fragment_context = Boolean(options.htmlFragmentContext);
			this.raw_text_fragment_context = options.rawTextFragmentContext ?? null;
			this.raw_text_fragment_consumed = false;
			this.raw_text_fragment_updated_html = null;
			this.plaintext_pending = false;
			this.plaintext_content_start = null;
			this.plaintext_text_consumed = false;
			this.plaintext_updated_html = null;
			this.is_full_parser = Boolean(options.fullParser || this.is_html_fragment_context);
			this.encoding_confidence = options.encodingConfidence ?? (this.is_full_parser ? "tentative" : "irrelevant");
			this.full_parser_insertion_mode = this.is_html_fragment_context
				? "before_head"
				: this.is_full_parser ? "initial" : "in_body";
			this.full_parser_scaffolded = !this.is_full_parser || this.is_html_fragment_context;
			this.full_parser_seen_doctype = false;
			this.frameset_ok = true;
			this.pre_frameset_paragraph_ignored = false;
			this.pre_frameset_ignored_element_depth = 0;
			this.form_element_pointer = null;
			this.preserve_in_body_ignored_start_tags = Boolean(options.preserveInBodyIgnoredStartTags);
			this.context_node = options.contextNode ?? "BODY";
			this.context_namespace = options.contextNamespace ?? contextNamespace(this.context_node);
			this.context_integration_node_type = options.contextIntegrationNodeType ?? null;
			this.context_breadcrumbs = options.contextBreadcrumbs ?? (
				this.is_full_parser || this.is_html_fragment_context ? [] : [this.context_node]
			);
			this.open_elements = this.is_html_fragment_context ? ["HTML"] : this.is_full_parser ? [] : ["HTML", this.context_node];
			this.open_element_namespaces = this.is_html_fragment_context ? ["html"] : this.is_full_parser ? [] : ["html", this.context_namespace];
			this.open_element_integration_node_types = this.is_html_fragment_context ? [null] : this.is_full_parser ? [] : [null, this.context_integration_node_type];
			this.open_element_foster_parented_table_indices = this.open_elements.map(() => null);
			this.detached_context_breadcrumbs = [];
			this.detached_breadcrumbs = [];
			this.active_formatting_elements = [];
			this.ignored_select_formatting_elements = new Map();
			this.template_insertion_modes = [];
			this.base_open_element_count = this.open_elements.length;
			this.temporary_reopened_head = false;
			this.breadcrumbs = this.#breadcrumbStack();
			this.current_namespace = this.is_html_fragment_context
				? "html"
				: this.#childNamespaceForStackEntry(
					this.context_node,
					this.context_namespace,
					this.context_integration_node_type,
				);
			this.current_token_namespace = this.current_namespace;
			this.compat_mode = options.compatMode ?? this.compat_mode;
			super.change_parsing_namespace(this.current_namespace);
		}

		static create_fragment(html, context = "<body>", encoding = "UTF-8") {
			if (encoding !== "UTF-8" || typeof html !== "string" || typeof context !== "string") {
				return null;
			}

			const plaintextContextNode = WP_HTML_Processor.#plaintextFragmentContextNode(context);
			if (plaintextContextNode !== null) {
				return new this(html, {
					compatMode: WP_HTML_Tag_Processor.NO_QUIRKS_MODE,
					contextNode: plaintextContextNode,
					contextNamespace: "html",
					fullParser: false,
					rawTextFragmentContext: plaintextContextNode,
				});
			}

			// Context discovery must preserve otherwise ignored table-context tags like TR and TD.
			const contextProcessor = new this(`<!DOCTYPE html>${context}${RAW_TEXT_FRAGMENT_CONTEXT_END_TAGS}`, {
				fullParser: true,
				encodingConfidence: "certain",
				preserveInBodyIgnoredStartTags: true,
			});

			let contextNode = null;
			let contextNamespaceName = null;
			let contextIntegrationNodeType = null;
			const contextBreadcrumbs = [];
			while (contextProcessor.next_tag()) {
				if (!contextProcessor.is_virtual() && !contextProcessor.is_tag_closer()) {
					contextNode = contextProcessor.get_tag();
					contextNamespaceName = contextProcessor.get_namespace();
					contextIntegrationNodeType = contextProcessor.#integrationNodeTypeForCurrentStartTag(
						contextNode,
						contextNamespaceName,
					);
					contextBreadcrumbs.push(contextNode);
				}
			}

			const compatMode = contextProcessor.compat_mode;
			contextProcessor.destroy();
			if (contextNode === null || contextNamespaceName === null) {
				return null;
			}

			if (
				contextNamespaceName === "html" &&
				RAW_TEXT_FRAGMENT_CONTEXT_ELEMENTS.has(contextNode)
			) {
				return new this(html, {
					compatMode,
					contextNode,
					contextNamespace: contextNamespaceName,
					contextIntegrationNodeType,
					contextBreadcrumbs,
					fullParser: false,
					rawTextFragmentContext: contextNode,
				});
			}

			if (
				contextNamespaceName === "html" &&
				(
					VOID_ELEMENTS.has(contextNode) ||
					SPECIAL_ATOMIC_ELEMENTS.has(contextNode) ||
					contextNode === "PLAINTEXT"
				)
			) {
				return null;
			}

			if (contextNamespaceName === "html" && contextNode === "HTML") {
				return new this(html, {
					compatMode,
					contextNode,
					contextNamespace: contextNamespaceName,
					contextIntegrationNodeType,
					contextBreadcrumbs,
					fullParser: false,
					htmlFragmentContext: true,
					encodingConfidence: "irrelevant",
				});
			}

			return new this(html, {
				compatMode,
				contextNode,
				contextNamespace: contextNamespaceName,
				contextIntegrationNodeType,
				contextBreadcrumbs,
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

		static #plaintextFragmentContextNode(context) {
			const contextProcessor = new WP_HTML_Tag_Processor(context);
			let contextNode = null;
			let startTagCount = 0;
			while (contextProcessor.next_tag()) {
				if (!contextProcessor.is_tag_closer()) {
					startTagCount += 1;
					contextNode = normalizeTagNameForNamespace(contextProcessor.get_tag(), "html");
				}
			}
			contextProcessor.destroy();
			return startTagCount === 1 && contextNode === "PLAINTEXT" ? contextNode : null;
		}

		static normalize(html) {
			const normalizedHtml = phpStringParameterCoerce(html, "html");
			const processor = this.create_fragment(normalizedHtml);
			if (processor === null) {
				return null;
			}

			try {
				return processor.serialize();
			} finally {
				processor.destroy();
			}
		}

		static is_void(tagName) {
			return VOID_ELEMENTS.has(asciiUpper(phpInternalStringCoerce(tagName, "tag_name")));
		}

		static is_special(tagName) {
			const normalized = normalizeSpecialTagInput(tagName);
			return isSpecialBoundary(normalized.nodeName, normalized.namespaceName);
		}

		next_tag(query = null) {
			const visitClosers = Boolean(
				query &&
				typeof query === "object" &&
				(query.tag_closers === "visit" || query.visit_closers === true)
			);

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

			const needsTag = query.tag_name == null
				? null
				: asciiUpper(phpStringParameterCoerce(query.tag_name, "tag_name"));
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
			if (this.last_error !== null) {
				return false;
			}

			if (nodeToProcess === WP_HTML_Processor.PROCESS_NEXT_NODE) {
				return this.next_token();
			}

			if (
				nodeToProcess === WP_HTML_Processor.REPROCESS_CURRENT_NODE ||
				nodeToProcess === WP_HTML_Processor.PROCESS_CURRENT_NODE
			) {
				return (
					this.parser_state !== STATE_READY &&
					this.parser_state !== STATE_COMPLETE &&
					this.parser_state !== STATE_INCOMPLETE_INPUT
				);
			}

			return false;
		}

		next_token() {
			if (this.last_error !== null) {
				return false;
			}

			if (this.current_synthetic_token !== null) {
				this.current_synthetic_token = null;
			}

			if (this.delayed_synthetic_tokens.length > 0) {
				return this.#consumeDelayedSyntheticToken();
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

			if (this.#consumeRawTextFragmentToken()) {
				return true;
			}

			if (this.raw_text_fragment_context !== null) {
				this.breadcrumbs = this.#breadcrumbStack();
				return false;
			}

			if (this.#consumePlaintextTextToken()) {
				return true;
			}

			if (this.plaintext_content_start !== null) {
				if (this.#queueFullParserMissingBodyAtEof()) {
					return this.#consumeVirtualToken();
				}

				if (this.#queueFosteredElementPopsBeforeDeferredTable()) {
					return this.#consumeVirtualToken();
				}

				if (this.#consumeDeferredTableOpener()) {
					return true;
				}

				if (this.#queueEofVirtualClosers()) {
					return this.#consumeVirtualToken();
				}

				this.breadcrumbs = this.#breadcrumbStack();
				return false;
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
				if (this.#nextDelayedSyntheticTokenIsTableOpener()) {
					return this.#consumeDelayedSyntheticToken();
				}
				if (this.virtual_tokens.length > 0) {
					return this.#consumeVirtualToken();
				}
				this.current_virtual = null;
			}

			if (super.paused_at_incomplete_token() && !this.#incompleteTokenIsEofComment()) {
				if (this.#skipIncompleteFullParserEndTag()) {
					return this.next_token();
				}

				if (this.#skipIncompleteFullParserQuotedStartTag()) {
					return this.next_token();
				}

				if (this.#skipIncompleteFullParserStartTag()) {
					return this.next_token();
				}

				if (this.#skipIncompleteSelectBreakoutStartTag()) {
					return this.next_token();
				}

				this.breadcrumbs = this.#breadcrumbStack();
				return false;
			}

			if (this.#shouldConsumeEofCommentBeforeMissingBody() && this.#consumeFullParserEofComment()) {
				return true;
			}

			if (this.is_full_parser && !this.full_parser_scaffolded) {
				this.full_parser_scaffolded = true;
				if (!this.full_parser_seen_doctype) {
					this.compat_mode = WP_HTML_Tag_Processor.QUIRKS_MODE;
				}
				this.#queueFullParserScaffold();
				return this.#consumeVirtualToken();
			}

			if (this.#queueFosteredElementPopsBeforeDeferredTable()) {
				return this.#consumeVirtualToken();
			}

			if (this.#consumeDeferredTableOpener()) {
				return true;
			}

			if (this.#queueFullParserMissingBodyAtEof()) {
				return this.#consumeVirtualToken();
			}

			if (this.#consumeFullParserEofComment()) {
				return true;
			}

			if (this.#queueEofVirtualClosers()) {
				return this.#consumeVirtualToken();
			}

			this.breadcrumbs = this.#breadcrumbStack();
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

		#isSyntheticToken() {
			return this.current_synthetic_token !== null;
		}

		is_tag_closer() {
			if (this.is_virtual()) {
				return this.current_virtual.operation === "pop";
			}
			return this.#isSyntheticToken() ? false : super.is_tag_closer();
		}

		get_tag() {
			if (this.is_virtual()) {
				return this.current_virtual.tagName;
			}

			if (this.#isSyntheticToken()) {
				return this.current_synthetic_token.tagName ?? null;
			}

			return normalizeTagNameForNamespace(super.get_tag(), this.current_token_namespace);
		}

		get_attribute(name) {
			if (this.is_virtual()) {
				return this.#getVirtualAttribute(name);
			}
			return this.#isSyntheticToken() ? this.#getSyntheticAttribute(name) : super.get_attribute(name);
		}

		get_attribute_names_with_prefix(prefix) {
			if (this.is_virtual()) {
				return this.#getVirtualAttributeNamesWithPrefix(prefix);
			}
			return this.#isSyntheticToken() ? this.#getSyntheticAttributeNamesWithPrefix(prefix) : super.get_attribute_names_with_prefix(prefix);
		}

		set_attribute(name, value) {
			return this.is_virtual() || this.#isSyntheticToken() ? false : super.set_attribute(name, value);
		}

		remove_attribute(name) {
			return this.is_virtual() || this.#isSyntheticToken() ? false : super.remove_attribute(name);
		}

		add_class(className) {
			return this.is_virtual() || this.#isSyntheticToken() ? false : super.add_class(className);
		}

		remove_class(className) {
			return this.is_virtual() || this.#isSyntheticToken() ? false : super.remove_class(className);
		}

		has_class(className) {
			if (this.is_virtual()) {
				return this.#virtualHasClass(className);
			}
			return this.#isSyntheticToken() ? null : super.has_class(className);
		}

		class_list() {
			if (this.is_virtual()) {
				return this.#virtualClassList();
			}
			return this.#isSyntheticToken() ? null : super.class_list();
		}

		has_self_closing_flag() {
			if (this.is_virtual()) {
				return false;
			}
			return this.#isSyntheticToken()
				? Boolean(this.current_synthetic_token.hasSelfClosingFlag)
				: super.has_self_closing_flag();
		}

		get_token_name() {
			if (this.is_virtual()) {
				return this.current_virtual.tagName;
			}
			return this.#isSyntheticToken() ? this.current_synthetic_token.tokenName : super.get_token_name();
		}

		get_token_type() {
			if (this.is_virtual()) {
				return "#tag";
			}
			return this.#isSyntheticToken() ? this.current_synthetic_token.tokenType : super.get_token_type();
		}

		paused_at_incomplete_token() {
			return this.synthetic_eof_comment_consumed || this.raw_text_fragment_context !== null
				? false
				: super.paused_at_incomplete_token();
		}

		get_comment_type() {
			if (this.is_virtual()) {
				return null;
			}
			if (this.#isSyntheticToken()) {
				return this.current_synthetic_token.tokenType === "#comment"
					? WP_HTML_Tag_Processor.COMMENT_AS_HTML_COMMENT
					: null;
			}
			return super.get_comment_type();
		}

		get_full_comment_text() {
			if (this.#isSyntheticToken()) {
				return this.current_synthetic_token.tokenType === "#comment"
					? this.current_synthetic_token.commentText
					: null;
			}
			return super.get_full_comment_text();
		}

		get_doctype_info() {
			return this.is_virtual() || this.#isSyntheticToken() ? null : super.get_doctype_info();
		}

		subdivide_text_appropriately() {
			return this.is_virtual() || this.#isSyntheticToken() ? false : super.subdivide_text_appropriately();
		}

		get_modifiable_text() {
			if (this.is_virtual()) {
				return "";
			}
			if (this.#isSyntheticToken() && this.current_synthetic_token.tokenType === "#tag") {
				return SPECIAL_ATOMIC_ELEMENTS.has(this.current_synthetic_token.tagName)
					? this.current_synthetic_token.modifiableText ?? ""
					: "";
			}
			return this.#isSyntheticToken()
				? this.current_synthetic_token.modifiableText ?? this.current_synthetic_token.commentText ?? ""
				: super.get_modifiable_text();
		}

		set_modifiable_text(text) {
			const plaintextContent = phpStringParameterCoerce(text, "plaintext_content");
			if (this.#isSyntheticToken()) {
				if (
					this.current_synthetic_token.tokenType !== "#text" ||
					this.current_synthetic_token.readOnly
				) {
					return false;
				}

				this.current_synthetic_token.modifiableText = replaceNulls(plaintextContent);
				if (this.raw_text_fragment_context !== null) {
					this.raw_text_fragment_updated_html = this.#serializeTextToken();
				}
				if (this.plaintext_content_start !== null) {
					this.plaintext_updated_html = this.#serializeTextToken();
				}
				return true;
			}

			if (
				this.is_virtual() ||
				(
					this.parser_state === STATE_MATCHED_TAG &&
					this.get_namespace() !== "html"
				)
			) {
				return false;
			}

			return super.set_modifiable_text(plaintextContent);
		}

		get_updated_html(flushClassNameUpdates = true) {
			if (this.plaintext_updated_html != null && this.plaintext_content_start != null) {
				return super.get_updated_html(flushClassNameUpdates).slice(0, this.plaintext_content_start) + this.plaintext_updated_html;
			}
			const html = super.get_updated_html(flushClassNameUpdates);
			return this.raw_text_fragment_updated_html ?? html;
		}

		set_bookmark(name) {
			if (this.is_virtual() || this.#isSyntheticToken()) {
				return false;
			}

			const bookmarkName = phpInterpolatedStringCoerce(name, "bookmark_name");
			const bookmarkKey = phpArrayKeyParameterCoerce(bookmarkName, "bookmark_name");
			if (!super.set_bookmark(bookmarkName)) {
				return false;
			}

			this.bookmarks.set(bookmarkKey, {
				...this.bookmarks.get(bookmarkKey),
				processorState: this.#snapshotProcessorState(),
			});
			return true;
		}

		release_bookmark(name) {
			return super.release_bookmark(phpInterpolatedStringCoerce(name, "bookmark_name"));
		}

		has_bookmark(name) {
			return super.has_bookmark(phpInterpolatedStringCoerce(name, "bookmark_name"));
		}

		seek(name) {
			const bookmarkName = phpInterpolatedStringCoerce(name, "bookmark_name");
			const bookmarkKey = phpArrayKeyParameterCoerce(bookmarkName, "bookmark_name");
			const bookmark = this.bookmarks.get(bookmarkKey);
			if (!bookmark || !super.seek(bookmarkName)) {
				return false;
			}

			if (bookmark.processorState) {
				this.#restoreProcessorState(bookmark.processorState);
			}
			return true;
		}

		change_parsing_namespace(namespaceName) {
			const normalizedNamespaceName = phpStringParameterCoerce(namespaceName, "new_namespace");
			if (!super.change_parsing_namespace(normalizedNamespaceName)) {
				return false;
			}

			this.current_namespace = normalizedNamespaceName;
			if (
				this.parser_state === STATE_READY ||
				this.parser_state === STATE_COMPLETE ||
				this.parser_state === STATE_INCOMPLETE_INPUT
			) {
				this.current_token_namespace = namespaceName;
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
			if (attributeName === null) {
				return null;
			}

			const normalizedAttributeName = phpInternalStringCoerce(attributeName, "attribute_name");
			return this.get_namespace() === "html"
				? normalizedAttributeName
				: qualifyForeignAttributeName(this.get_namespace(), normalizedAttributeName);
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

			if (node !== null) {
				const token = phpTokenParameterCoerce(node, "node");
				tokenName = token.node_name ?? this.get_token_name();
				namespaceName = token.namespace ?? this.get_namespace();
				hasSelfClosingFlag = token.has_self_closing_flag ?? this.has_self_closing_flag();
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

			const normalizedNamespace = asciiLower(phpStringParameterCoerce(namespaceName ?? "html", "namespace"));
			let normalizedTokenName = phpStringParameterCoerce(tokenName, "node_name");
			if (normalizedNamespace === "html" && normalizedTokenName !== "html" && normalizedTokenName[0] !== "#") {
				normalizedTokenName = asciiUpper(normalizedTokenName);
			}

			return {
				nodeName: normalizedTokenName,
				namespaceName: normalizedNamespace,
				hasSelfClosingFlag: phpBooleanParameterCoerce(hasSelfClosingFlag, "has_self_closing_flag"),
			};
		}

		get_breadcrumbs() {
			return [...this.breadcrumbs];
		}

		#breadcrumbStack(tokenName = null, endIndex = this.open_elements.length) {
			this.#pruneDetachedBreadcrumbs();
			const stack = [];
			const boundedEndIndex = Math.max(0, Math.min(endIndex, this.open_elements.length));
			const fosterParentedRange = this.#fosterParentedBreadcrumbRange(boundedEndIndex);
			for (let i = 0; i < boundedEndIndex; i += 1) {
				if (
					fosterParentedRange !== null &&
					i >= fosterParentedRange.tableIndex &&
					i < fosterParentedRange.fosteredIndex
				) {
					continue;
				}

				for (const detached of this.detached_breadcrumbs) {
					if (detached.index === i) {
						stack.push(detached.tagName);
					}
				}

				stack.push(this.open_elements[i]);

				if (
					i === 0 &&
					this.detached_context_breadcrumbs.length > 0 &&
					this.open_elements[0] === "HTML"
				) {
					stack.push(...this.detached_context_breadcrumbs);
				}
			}

			if (tokenName !== null) {
				stack.push(tokenName);
			}
			return stack;
		}

		#fosterParentedBreadcrumbRange(endIndex) {
			for (let i = 0; i < endIndex; i += 1) {
				const tableIndex = this.open_element_foster_parented_table_indices[i] ?? null;
				if (tableIndex !== null && tableIndex < i) {
					return {
						fosteredIndex: i,
						tableIndex,
					};
				}
			}

			return null;
		}

		get_current_depth() {
			return this.breadcrumbs.length;
		}

		matches_breadcrumbs(breadcrumbs) {
			const normalizedBreadcrumbs = phpArrayParameterCoerce(breadcrumbs, "breadcrumbs");

			if (normalizedBreadcrumbs.length === 0) {
				return true;
			}

			const normalized = normalizedBreadcrumbs.map((crumb) => {
				const normalizedCrumb = phpInternalStringCoerce(crumb, "breadcrumbs");
				return normalizedCrumb === "*" ? "*" : asciiUpper(normalizedCrumb);
			});
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
			let preserveLeadingNewlineFor = null;
			while (this.next_token()) {
				const tokenType = this.get_token_type();
				const tokenName = this.get_token_name();

				if (
					preserveLeadingNewlineFor !== null &&
					tokenType === "#text"
				) {
					if (
						this.get_namespace() === "html" &&
						this.breadcrumbs.at(-2) === preserveLeadingNewlineFor &&
						this.get_modifiable_text().startsWith("\n")
					) {
						html += "\n";
					}
					preserveLeadingNewlineFor = null;
				} else if (preserveLeadingNewlineFor !== null) {
					preserveLeadingNewlineFor = null;
				}

				if (!this.#shouldOmitTrailingIncompleteSyntaxTokenFromSerialization()) {
					html += this.serialize_token();
				}

				if (
					tokenType === "#tag" &&
					!this.is_tag_closer() &&
					this.get_namespace() === "html" &&
					(tokenName === "PRE" || tokenName === "LISTING")
				) {
					preserveLeadingNewlineFor = tokenName;
				}
			}

			return this.get_last_error() === null ? html : null;
		}

		#shouldOmitTrailingIncompleteSyntaxTokenFromSerialization() {
			const span = this.#nativeCurrentSpan();
			if (span === null || span.start + span.length !== this.html.length) {
				return false;
			}

			const tokenType = this.get_token_type();
			const tokenHtml = this.html.slice(span.start);
			if (
				(tokenType === "#comment" || tokenType === "#funky-comment") &&
				incompleteBogusCommentAtEof(tokenHtml)
			) {
				return true;
			}

			if (
				tokenType !== "#tag" ||
				this.get_namespace() !== "html" ||
				this.is_tag_closer()
			) {
				return false;
			}

			const tagName = this.get_tag();
			if (!SPECIAL_ATOMIC_ELEMENTS.has(tagName)) {
				return false;
			}

			const startTag = completeStartTagAt(this.html, span.start);
			return startTag !== null &&
				startTag.tagName === tagName &&
				findSpecialAtomicCloserEnd(this.html, startTag.end, tagName) === null;
		}

		serialize_token() {
			const tokenType = this.get_token_type();

			switch (tokenType) {
				case "#doctype":
					return serializeDoctype(this.get_doctype_info());
				case "#text":
					return this.#serializeTextToken();
				case "#presumptuous-tag":
					return "";
				case "#funky-comment":
				case "#comment":
					return `<!--${this.get_full_comment_text() ?? ""}-->`;
				case "#cdata-section":
					return `<![CDATA[${this.get_modifiable_text() ?? ""}]]>`;
				case "#tag":
					if (this.is_virtual() && this.current_virtual.skipSerialization) {
						return "";
					}
					return this.#serializeCurrentTag();
				default:
					return "";
			}
		}

		#updateTreeStateForCurrentToken(allowVirtualPreclosures = true) {
			const tokenType = this.get_token_type();
			const tokenName = this.get_token_name();

			if (tokenName === null) {
				this.breadcrumbs = this.#breadcrumbStack();
				this.current_token_namespace = this.current_namespace;
				return;
			}

			if (
				allowVirtualPreclosures &&
				this.#queueDeferredTableOpenerBeforeCurrentToken(tokenType)
			) {
				this.pending_real_token = true;
				this.pending_real_parser_state = this.parser_state;
				this.skip_current_token = true;
				return;
			}

			if (this.#applyFullParserInsertionMode(tokenType, tokenName)) {
				return;
			}

			if (!this.is_full_parser && tokenType === "#doctype") {
				this.skip_current_token = true;
				return;
			}

			if (tokenType === "#doctype" && this.current_namespace !== "html") {
				this.skip_current_token = true;
				return;
			}

			if (tokenType !== "#tag") {
				if (this.#shouldIgnoreTextInColumnGroup(tokenType)) {
					this.skip_current_token = true;
					return;
				}

				if (this.#representPendingForeignTableFosteredText(tokenType)) {
					return;
				}

				if (
					tokenType === "#text" &&
					this.text_node_classification === WP_HTML_Tag_Processor.TEXT_IS_WHITESPACE &&
					this.#currentTextChunkPrecedesDeferredTableChildOpener()
				) {
					this.#deferCurrentTextAsTableChild();
					return;
				}

				if (tokenType === "#text" && this.#isInTableTextContext()) {
					if (this.text_node_classification === WP_HTML_Tag_Processor.TEXT_IS_NULL_SEQUENCE) {
						this.skip_current_token = true;
						return;
					}

					if (
						this.text_node_classification !== WP_HTML_Tag_Processor.TEXT_IS_WHITESPACE ||
						this.#currentTextChunkPrecedesFosteredTableText()
					) {
						if (this.#representFosteredTextBeforeDeferredTable()) {
							return;
						}
						if (this.#queueReconstructActiveFormattingElementsBeforeDeferredTable()) {
							this.pending_real_token = true;
							this.pending_real_parser_state = this.parser_state;
							return;
						}
						this.#bailUnsupported("Foster parenting is not supported.");
						return;
					}
				}

				if (
					tokenType === "#text" &&
					this.#shouldDeferCurrentTextInsideDeferredTable()
				) {
					return;
				}

				if (
					allowVirtualPreclosures &&
					tokenType === "#text" &&
					this.text_node_classification !== WP_HTML_Tag_Processor.TEXT_IS_NULL_SEQUENCE &&
					(
						this.#queueNestedAnchorOuterCloserAfterDeferredTable() ||
						this.#queueSpecialStartAdoptionPreclosedFormattingElementsForText() ||
						this.#queueParagraphAdoptionPreclosedFormattingElementsForText() ||
						(
							!this.#isInTableTextContext() &&
							this.#queueReconstructActiveFormattingElements()
						)
					)
				) {
					this.pending_real_token = true;
					this.pending_real_parser_state = this.parser_state;
					return;
				}

				if (
					this.is_full_parser &&
					this.current_namespace !== "html" &&
					tokenType === "#text" &&
					this.text_node_classification === WP_HTML_Tag_Processor.TEXT_IS_GENERIC
				) {
					this.frameset_ok = false;
				}

				this.current_token_namespace = this.current_namespace;
				this.breadcrumbs = this.#breadcrumbStack(tokenName);
				return;
			}

			const tagName = this.#getCurrentTreeTagName();
			if (tagName === null) {
				this.breadcrumbs = this.#breadcrumbStack();
				this.current_token_namespace = this.current_namespace;
				return;
			}

			if (this.is_tag_closer()) {
				const closingNamespace = this.#namespaceForEndTag(tagName);

				if (
					closingNamespace === "html" &&
					this.#shouldIgnoreEndTagInTableContext(tagName)
				) {
					this.current_token_namespace = this.current_namespace;
					this.breadcrumbs = this.#breadcrumbStack();
					this.skip_current_token = true;
					return;
				}

				if (this.#skipDeferredTableTemplateCloser(tagName, closingNamespace)) {
					return;
				}

				if (
					this.#skipDeferredTableStructureCloserBeforeFosteredText(
						tagName,
						closingNamespace,
						this.#lastOpenElementIndex(tagName, closingNamespace),
					)
				) {
					return;
				}

				if (
					this.deferred_table_opener !== null &&
					closingNamespace === "html" &&
					!ADOPTION_AGENCY_END_TAGS.has(tagName) &&
					this.#isSkippedFosterLookaheadEndTag(tagName)
				) {
					this.#ignoreCurrentToken();
					return;
				}

				if (allowVirtualPreclosures && this.#queueVirtualPreclosuresForEndTag(tagName)) {
					this.pending_real_token = true;
					this.pending_real_parser_state = this.parser_state;
					return;
				}

				if (
					closingNamespace === "html" &&
					this.#hasOpenHtmlElement("SELECT") &&
					!SELECT_ALLOWED_END_TAGS.has(tagName)
				) {
					this.#ignoreCurrentToken();
					return;
				}

				let existingIndex = this.#lastOpenElementIndex(tagName, closingNamespace);
				if (tagName === "LI" && closingNamespace === "html") {
					existingIndex = this.#findOpenElementBeforeBoundary("LI", LIST_ITEM_SCOPE_BOUNDARIES);
				}
				if (tagName === "P" && closingNamespace === "html") {
					existingIndex = this.#findOpenElementBeforeBoundary("P", BUTTON_SCOPE_BOUNDARIES);
					if (existingIndex === -1) {
						if (this.#shouldBailUnsupportedTableFosterParenting(tagName, true)) {
							this.#bailUnsupported("Foster parenting is not supported.");
							return;
						}
						this.current_token_namespace = this.current_namespace;
						this.breadcrumbs = this.#breadcrumbStack();
						this.virtual_tokens.push(
							{
								operation: "push",
								tagName: "P",
								namespaceName: "html",
								fosterParentedTableIndex: this.#missingParagraphCloserFosterParentedTableIndex(),
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
				}

				if (this.#skipDeferredTableStructureCloserBeforeFosteredText(tagName, closingNamespace, existingIndex)) {
					return;
				}

				if (!this.is_full_parser && existingIndex !== -1 && existingIndex < this.base_open_element_count) {
					this.current_token_namespace = this.current_namespace;
					this.breadcrumbs = this.#breadcrumbStack();
					this.skip_current_token = true;
					return;
				}

				if (
					closingNamespace === "html" &&
					existingIndex === -1 &&
					this.#consumeIgnoredSelectFormattingElement(tagName)
				) {
					this.#ignoreCurrentToken();
					return;
				}

				if (this.#shouldIgnoreEndTagClosingOutsideTemplate(tagName, closingNamespace, existingIndex)) {
					this.current_token_namespace = this.current_namespace;
					this.breadcrumbs = this.#breadcrumbStack();
					this.skip_current_token = true;
					return;
				}

				if (
					closingNamespace === "html" &&
					tagName !== "TEMPLATE" &&
					existingIndex !== -1 &&
					this.#hasForeignIntegrationPointAfter(existingIndex)
				) {
					if (
						this.#hasElementInTableScope("TABLE") &&
						FORM_TABLE_DESCENDANT_ELEMENTS.has(tagName)
					) {
						if (this.#ignoreCrossedForeignTableStructureCloserBeforeFosteredText(tagName)) {
							return;
						}

						this.#bailUnsupported("Foster parenting is not supported.");
						return;
					}

					this.current_token_namespace = this.current_namespace;
					this.breadcrumbs = this.#breadcrumbStack();
					this.skip_current_token = true;
					return;
				}

				if (
					allowVirtualPreclosures &&
					tagName === "FORM" &&
					closingNamespace === "html" &&
					existingIndex !== -1 &&
					this.#hasOnlyTableElementsAfter(existingIndex)
				) {
					this.current_token_namespace = this.current_namespace;
					this.breadcrumbs = this.#breadcrumbStack();
					this.form_element_pointer = null;
					if (this.open_elements.length === existingIndex + 2) {
						this.skip_current_token = true;
						return;
					}
					this.#queueVirtualPopsFrom(existingIndex + 1);
					this.skip_current_token = true;
					return;
				}

				if (tagName === "FORM" && closingNamespace === "html") {
					if (this.form_element_pointer === "detached") {
						this.form_element_pointer = null;
						this.#ignoreCurrentToken();
						return;
					}
					if (this.form_element_pointer === "open") {
						this.form_element_pointer = null;
					}
				}

				if (this.#shouldDetachFormCloser(tagName, closingNamespace, existingIndex)) {
					this.#detachFormElementFromOpenStack(existingIndex);
					return;
				}

				if (
					allowVirtualPreclosures &&
					this.#queueParagraphAdoptionReconstructionForEndTag(tagName, closingNamespace, existingIndex)
				) {
					this.pending_real_token = true;
					this.pending_real_parser_state = this.parser_state;
					return;
				}

				if (
					allowVirtualPreclosures &&
					this.#queueSpecialStartAdoptionReconstructionForEndTag(tagName, closingNamespace, existingIndex)
				) {
					this.pending_real_token = true;
					this.pending_real_parser_state = this.parser_state;
					return;
				}

				if (this.#shouldIgnoreAdoptionAgencyEndTagWithStaleEntry(tagName, closingNamespace)) {
					this.#removeStaleActiveFormattingElementsForClose(tagName);
					this.current_token_namespace = this.current_namespace;
					this.breadcrumbs = this.#breadcrumbStack();
					this.skip_current_token = true;
					return;
				}

				if (this.#shouldIgnoreAdoptionAgencyEndTagOutsideScope(tagName, closingNamespace, existingIndex)) {
					this.#removeActiveFormattingElementsForClose(tagName);
					this.current_token_namespace = this.current_namespace;
					this.breadcrumbs = this.#breadcrumbStack();
					this.skip_current_token = true;
					return;
				}

				if (this.#shouldIgnoreCappedDeepAnchorEndTag(tagName, closingNamespace, existingIndex)) {
					this.#removeActiveFormattingElementsForClose(tagName);
					this.current_token_namespace = this.current_namespace;
					this.breadcrumbs = this.#breadcrumbStack();
					this.skip_current_token = true;
					return;
				}

				if (this.#shouldBailUnsupportedAdoptionAgency(tagName, closingNamespace, existingIndex)) {
					this.#bailUnsupported("Cannot extract common ancestor in adoption agency algorithm.");
					return;
				}

				if (this.#shouldIgnoreAdoptionAgencyEndTagFallback(tagName, closingNamespace, existingIndex)) {
					this.#ignoreCurrentToken();
					return;
				}

				if (HEADING_ELEMENTS.has(tagName) && closingNamespace === "html") {
					const headingIndex = this.#findOpenElementBeforeBoundary(
						(nodeName) => HEADING_ELEMENTS.has(nodeName),
						DEFAULT_SCOPE_BOUNDARIES,
					);
					if (headingIndex !== -1 && (existingIndex === -1 || headingIndex > existingIndex)) {
						this.current_token_namespace = this.current_namespace;
						this.breadcrumbs = this.#breadcrumbStack();
						this.#queueVirtualPopsFrom(headingIndex);
						this.skip_current_token = true;
						return;
					}
				}

				if (
					allowVirtualPreclosures &&
					closingNamespace !== "html" &&
					existingIndex !== -1 &&
					existingIndex < this.open_elements.length - 1
				) {
					this.#queueVirtualPopsFrom(existingIndex + 1);
					this.pending_real_token = true;
					this.pending_real_parser_state = this.parser_state;
					return;
				}

				if (
					allowVirtualPreclosures &&
					existingIndex !== -1 &&
					existingIndex < this.open_elements.length - 1 &&
					tagName !== "HTML" &&
					tagName !== "BODY" &&
					tagName !== "TEMPLATE" &&
					MODELED_SCOPED_END_TAGS.has(tagName) &&
					this.#hasHtmlScopeBoundaryAfter(existingIndex, DEFAULT_SCOPE_BOUNDARIES)
				) {
					if (this.#shouldBailUnsupportedTableFosterParenting(tagName, true)) {
						this.#bailUnsupported("Foster parenting is not supported.");
						return;
					}
					this.current_token_namespace = this.current_namespace;
					this.breadcrumbs = this.#breadcrumbStack();
					this.skip_current_token = true;
					return;
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
					if (this.#shouldBailUnsupportedTableFosterParenting(tagName, true)) {
						this.#bailUnsupported("Foster parenting is not supported.");
						return;
					}
					this.#queueVirtualPopsFrom(existingIndex + 1);
					this.pending_real_token = true;
					this.pending_real_parser_state = this.parser_state;
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
					if (closingNamespace === "html" && FORMATTING_ELEMENTS.has(tagName)) {
						this.#removeActiveFormattingElementsForClose(tagName);
					}
					this.current_token_namespace = this.current_namespace;
					this.breadcrumbs = this.#breadcrumbStack();
					this.skip_current_token = true;
					return;
				}

				if (this.#shouldBailUnsupportedTableFosterParenting(tagName, true)) {
					this.#bailUnsupported("Foster parenting is not supported.");
					return;
				}

				if (allowVirtualPreclosures && existingIndex < this.open_elements.length - 1) {
					this.#queueVirtualPopsFrom(existingIndex + 1);
					this.pending_real_token = true;
					this.pending_real_parser_state = this.parser_state;
					return;
				}

				this.current_token_namespace = this.open_element_namespaces[existingIndex];
				if (closingNamespace === "html") {
					this.#applyTemplateInsertionModeForEndTag(tagName);
				}
				if (tagName === "TEMPLATE" && closingNamespace === "html") {
					this.#clearActiveFormattingElementsForTemplateClose(existingIndex);
					this.#popTemplateInsertionMode();
				}
				if (closingNamespace === "html" && FORMATTING_ELEMENTS.has(tagName)) {
					this.#removeActiveFormattingElementsForClose(tagName);
				}
				this.open_elements = this.open_elements.slice(0, existingIndex);
				this.open_element_namespaces = this.open_element_namespaces.slice(0, existingIndex);
				this.open_element_integration_node_types = this.open_element_integration_node_types.slice(0, existingIndex);
				this.open_element_foster_parented_table_indices = this.open_element_foster_parented_table_indices.slice(0, existingIndex);
				if (closingNamespace === "html" && TABLE_CELL_ELEMENTS.has(tagName)) {
					this.#clearActiveFormattingElementsUpToLastMarker();
				}
				if (closingNamespace === "html" && ACTIVE_FORMATTING_MARKER_ELEMENTS.has(tagName)) {
					this.#clearActiveFormattingElementsUpToLastMarker();
				}
				this.breadcrumbs = this.#breadcrumbStack();
				this.#setCurrentNamespace(this.#namespaceForStackTop());
				return;
			}

			if (
				allowVirtualPreclosures &&
				this.#queueNestedAnchorOuterCloserAfterDeferredTable()
			) {
				this.pending_real_token = true;
				this.pending_real_parser_state = this.parser_state;
				return;
			}

			const fosterParentedTableIndex = this.#fosterParentedStartTableIndex(tagName);

			if (
				allowVirtualPreclosures &&
				this.#shouldReconstructActiveFormattingBeforeFosteredStart(tagName) &&
				!this.#alreadyReconstructedTableNobrForCurrentToken(tagName) &&
				this.#queueReconstructActiveFormattingElementsBeforeDeferredTable()
			) {
				this.#rememberTableNobrReconstructionForCurrentToken(tagName);
				this.pending_real_token = true;
				this.pending_real_parser_state = this.parser_state;
				return;
			}

			if (this.#representFosteredVoidStartBeforeDeferredTable(tagName)) {
				return;
			}

			if (this.#representFosteredAtomicStartBeforeDeferredTable(tagName)) {
				return;
			}

			if (this.#shouldIgnoreTableContextTableStartTag(tagName)) {
				this.#ignoreCurrentToken();
				return;
			}

			if (this.#shouldIgnoreTableRowContextBoundaryStartTag(tagName)) {
				this.#ignoreCurrentToken();
				return;
			}

			if (this.#shouldIgnoreTableSectionContextBoundaryStartTag(tagName)) {
				this.#ignoreCurrentToken();
				return;
			}

			if (this.#shouldIgnoreCaptionContextBoundaryStartTag(tagName)) {
				this.#ignoreCurrentToken();
				return;
			}

			if (this.#shouldIgnoreColgroupFragmentFosteredStartTag(tagName)) {
				this.#ignoreCurrentToken();
				return;
			}

			if (
				allowVirtualPreclosures &&
				(
					this.#queueNestedAnchorBlockAdoptionPreclosure(tagName) ||
					this.#queueDeepFormattingElementSpecialStartPreclosure(tagName) ||
					this.#queueFormattingElementSpecialStartPreclosure(tagName) ||
					this.#queueFormattingElementAncestorSpecialStartPreclosure(tagName) ||
					this.#queueParagraphAdoptionFormattingPreclosure(tagName) ||
					this.#queueVirtualPreclosuresForStartTag(tagName) ||
					this.#queueVirtualOpenersForStartTag(tagName)
				)
			) {
				this.pending_real_token = true;
				this.pending_real_parser_state = this.parser_state;
				return;
			}

			if (this.#shouldIgnoreInBodyFragmentStartTag(tagName)) {
				this.#ignoreCurrentToken();
				return;
			}

			if (
				fosterParentedTableIndex === -1 &&
				this.#shouldBailUnsupportedTableFosterParenting(tagName, false)
			) {
				this.#bailUnsupported("Foster parenting is not supported.");
				return;
			}

			if (this.#applyTemplateInsertionModeForStartTag(tagName)) {
				return;
			}

			if (
				this.current_namespace === "html" &&
				(!this.is_full_parser || this.full_parser_insertion_mode === "in_body") &&
				!this.preserve_in_body_ignored_start_tags &&
				this.template_insertion_modes.length === 0 &&
				!this.#isInTableInsertionContext() &&
				this.#shouldIgnoreInBodyStartTag(tagName)
			) {
				this.#ignoreCurrentToken();
				return;
			}

			if (
				allowVirtualPreclosures &&
				this.current_namespace === "html" &&
				tagName === "SELECT"
			) {
				const selectIndex = this.#lastOpenElementIndex("SELECT", "html");
				if (selectIndex !== -1) {
					this.current_token_namespace = this.current_namespace;
					this.breadcrumbs = this.#breadcrumbStack();
					this.#queueVirtualPopsFrom(selectIndex);
					this.skip_current_token = true;
					return;
				}
			}

			if (
				this.current_namespace === "html" &&
				SELECT_BREAKOUT_START_TAGS.has(tagName)
			) {
				const selectIndex = this.#lastOpenElementIndex("SELECT", "html");
				if (selectIndex !== -1 && selectIndex < this.base_open_element_count) {
					if (tagName === "TEXTAREA") {
						this.#seekPastCurrentStartTag();
					}
					this.#ignoreCurrentToken();
					return;
				}
			}

			if (
				this.current_namespace === "html" &&
				this.#hasOpenHtmlElement("SELECT") &&
				!SELECT_ALLOWED_START_TAGS.has(tagName)
			) {
				this.#rememberIgnoredSelectFormattingElement(tagName);
				this.#ignoreCurrentToken();
				return;
			}

			if (
				this.current_namespace === "html" &&
				tagName === "FORM" &&
				!this.#hasOpenHtmlElement("TEMPLATE") &&
				(
					this.form_element_pointer !== null ||
					(
						this.#hasOpenHtmlElement("FORM") &&
						!this.#isInTableInsertionContext()
					)
				)
			) {
				this.current_token_namespace = this.current_namespace;
				this.breadcrumbs = this.#breadcrumbStack();
				this.skip_current_token = true;
				return;
			}

			if (
				this.current_namespace === "html" &&
				tagName === "MENUITEM" &&
				this.#hasOpenHtmlElement("SELECT")
			) {
				this.current_token_namespace = this.current_namespace;
				this.breadcrumbs = this.#breadcrumbStack();
				this.skip_current_token = true;
				return;
			}

			if (
				this.is_full_parser &&
				this.encoding_confidence === "tentative" &&
				this.current_namespace === "html" &&
				tagName === "META"
			) {
				const unsupportedEncodingMessage = this.#unsupportedEncodingMetaMessage();
				if (unsupportedEncodingMessage !== null) {
					this.#bailUnsupported(unsupportedEncodingMessage);
					return;
				}
			}

			const entersPlaintext = this.current_namespace === "html" && tagName === "PLAINTEXT";

			this.#applySimpleHtmlSemanticClosures(tagName);
			if (
				allowVirtualPreclosures &&
				(
					FORMATTING_ELEMENTS.has(tagName) ||
					ACTIVE_FORMATTING_RECONSTRUCTING_START_TAGS.has(tagName) ||
					this.#shouldReconstructActiveAnchorForStartTag(tagName) ||
					this.#shouldReconstructActiveFontForStartTag(tagName)
				) &&
				!this.#alreadyReconstructedTableNobrForCurrentToken(tagName) &&
				this.#queueReconstructActiveFormattingElements()
			) {
				this.#rememberTableNobrReconstructionForCurrentToken(tagName);
				this.pending_real_token = true;
				this.pending_real_parser_state = this.parser_state;
				return;
			}
			this.current_token_namespace = this.#namespaceForCurrentStartTag(super.get_tag());
			const currentTokenIntegrationNodeType = this.#integrationNodeTypeForCurrentStartTag(
				tagName,
				this.current_token_namespace,
			);
			this.open_elements.push(tagName);
			this.open_element_namespaces.push(this.current_token_namespace);
			this.open_element_integration_node_types.push(currentTokenIntegrationNodeType);
			this.open_element_foster_parented_table_indices.push(
				fosterParentedTableIndex === -1
					? this.#currentFosterParentedTableIndex()
					: fosterParentedTableIndex,
			);
			if (this.current_token_namespace === "html" && tagName === "TEMPLATE") {
				this.template_insertion_modes.push("in_template");
			}
			if (this.current_token_namespace === "html" && TABLE_CELL_ELEMENTS.has(tagName)) {
				this.#insertActiveFormattingMarker();
			}
			if (this.current_token_namespace === "html" && ACTIVE_FORMATTING_MARKER_ELEMENTS.has(tagName)) {
				this.#insertActiveFormattingMarker();
			}
			if (this.current_token_namespace === "html" && FORMATTING_ELEMENTS.has(tagName)) {
				this.#insertActiveFormattingElement(this.#createActiveFormattingElement(tagName));
			}
			const shouldPopTableFormImmediately = this.#shouldPopTableFormImmediately(
				tagName,
				this.current_token_namespace,
			);
			if (
				this.current_token_namespace === "html" &&
				tagName === "FORM" &&
				!this.#hasOpenHtmlElement("TEMPLATE")
			) {
				this.form_element_pointer = shouldPopTableFormImmediately ? "detached" : "open";
			}
			this.breadcrumbs = this.#breadcrumbStack();

			if (this.#shouldDeferCurrentTableOpener(tagName, this.current_token_namespace)) {
				this.deferred_table_opener = {
					tokenType: "#tag",
					tokenName: tagName,
					tagName,
					namespaceName: this.current_token_namespace,
					attributes: this.#currentTokenAttributes(),
					breadcrumbs: [...this.breadcrumbs],
					hasSelfClosingFlag: this.has_self_closing_flag(),
				};
				this.skip_current_token = true;
			}

			if (this.#shouldDeferCurrentTableChildOpener(tagName, this.current_token_namespace)) {
				this.deferred_table_child_openers.push({
					tokenType: "#tag",
					tokenName: tagName,
					tagName,
					namespaceName: this.current_token_namespace,
					attributes: this.#currentTokenAttributes(),
					breadcrumbs: [...this.breadcrumbs],
					hasSelfClosingFlag: this.has_self_closing_flag(),
					modifiableText: SPECIAL_ATOMIC_ELEMENTS.has(tagName) ? this.#currentSpecialAtomicText(tagName) : "",
				});
				this.skip_current_token = true;
			}

			if (this.#shouldDeferCurrentStartInsideDeferredTable(tagName, this.current_token_namespace)) {
				this.deferred_table_child_openers.push({
					tokenType: "#tag",
					tokenName: tagName,
					tagName,
					namespaceName: this.current_token_namespace,
					attributes: this.#currentTokenAttributes(),
					breadcrumbs: [...this.breadcrumbs],
					hasSelfClosingFlag: this.has_self_closing_flag(),
				});
				this.skip_current_token = true;
			}

			if (!tokenExpectsCloser(tagName, this.current_token_namespace, this.has_self_closing_flag())) {
				this.open_elements.pop();
				this.open_element_namespaces.pop();
				this.open_element_integration_node_types.pop();
				this.open_element_foster_parented_table_indices.pop();
				this.#setCurrentNamespace(this.#namespaceForStackTop());
			} else {
				this.#setCurrentNamespace(this.#childNamespaceForStackEntry(
					tagName,
					this.current_token_namespace,
					currentTokenIntegrationNodeType,
				));
				if (shouldPopTableFormImmediately) {
					this.virtual_tokens.push({
						operation: "pop",
						tagName,
						namespaceName: this.current_token_namespace,
						skipSerialization: false,
					});
				}
			}
			if (entersPlaintext) {
				this.plaintext_pending = true;
				this.#queueReconstructActiveFormattingElements();
			}

			this.#closeTemporaryReopenedHeadAfterCurrentToken();
			this.#bailIfExceededMaxBookmarks();
		}

		#consumeDelayedSyntheticToken() {
			const token = this.delayed_synthetic_tokens.shift();
			this.current_virtual = null;
			this.current_synthetic_token = token;
			this.skip_current_token = false;
			this.parser_state = token.tokenType === "#comment"
				? STATE_COMMENT
				: token.tokenType === "#tag" ? STATE_MATCHED_TAG : STATE_TEXT_NODE;
			if (token.tokenType === "#text") {
				this.text_node_classification = token.textClassification ?? (
					splitHtmlWhitespace(token.modifiableText ?? "").length === 0
						? WP_HTML_Tag_Processor.TEXT_IS_WHITESPACE
						: WP_HTML_Tag_Processor.TEXT_IS_GENERIC
				);
			}
			this.current_token_namespace = token.namespaceName ?? this.current_namespace;
			this.breadcrumbs = [...token.breadcrumbs];
			return true;
		}

		#nextDelayedSyntheticTokenIsTableOpener() {
			const token = this.delayed_synthetic_tokens[0] ?? null;
			return (
				token !== null &&
				token.tokenType === "#tag" &&
				token.tagName === "TABLE" &&
				token.namespaceName === "html"
			);
		}

		#queueDeferredTableOpener() {
			if (this.deferred_table_opener === null && this.deferred_table_child_openers.length === 0) {
				return false;
			}

			if (this.deferred_table_opener !== null) {
				this.delayed_synthetic_tokens.push(this.deferred_table_opener);
			}
			this.delayed_synthetic_tokens.push(...this.deferred_table_child_openers);
			this.deferred_table_opener = null;
			this.deferred_table_child_openers = [];
			return true;
		}

		#consumeDeferredTableOpener() {
			return this.#queueDeferredTableOpener() && this.#consumeDelayedSyntheticToken();
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
				this.open_element_integration_node_types.push(token.integrationNodeType ?? null);
				this.open_element_foster_parented_table_indices.push(
					token.fosterParentedTableIndex ?? this.#currentFosterParentedTableIndex(),
				);
				this.breadcrumbs = this.#breadcrumbStack();
				this.#setCurrentNamespace(this.#childNamespaceForStackEntry(
					token.tagName,
					token.namespaceName,
					token.integrationNodeType ?? null,
				));
				this.#bailIfExceededMaxBookmarks();
			} else if (token.operation === "pop") {
				const existingIndex = this.#lastOpenElementIndex(token.tagName, token.namespaceName);
				if (existingIndex !== -1) {
					if (token.tagName === "TEMPLATE" && token.namespaceName === "html") {
						this.#clearActiveFormattingElementsForTemplateClose(existingIndex);
						this.#popTemplateInsertionMode();
					} else if (token.namespaceName === "html") {
						this.#applyTemplateInsertionModeForEndTag(token.tagName);
					}
					if (token.skipSerialization && existingIndex < this.base_open_element_count) {
						const detachedContextBreadcrumbs = (
							existingIndex === 1 &&
							this.base_open_element_count === 2 &&
							this.context_breadcrumbs.length > 0
						)
							? this.context_breadcrumbs
							: this.open_elements.slice(existingIndex, this.base_open_element_count);
						this.detached_context_breadcrumbs.unshift(
							...detachedContextBreadcrumbs,
						);
						this.base_open_element_count = existingIndex;
					}
					this.open_elements = this.open_elements.slice(0, existingIndex);
					this.open_element_namespaces = this.open_element_namespaces.slice(0, existingIndex);
					this.open_element_integration_node_types = this.open_element_integration_node_types.slice(0, existingIndex);
					this.open_element_foster_parented_table_indices = this.open_element_foster_parented_table_indices.slice(0, existingIndex);
					if (
						token.namespaceName === "html" &&
						(TABLE_CELL_ELEMENTS.has(token.tagName) || ACTIVE_FORMATTING_MARKER_ELEMENTS.has(token.tagName))
					) {
						this.#clearActiveFormattingElementsUpToLastMarker();
					}
					this.#setCurrentNamespace(
						token.skipSerialization && this.pending_real_token
							? "html"
							: this.#namespaceForStackTop(),
					);
				}
				this.breadcrumbs = this.#breadcrumbStack();
			}

			return this.last_error === null;
		}

		#snapshotProcessorState() {
			return {
				openElements: [...this.open_elements],
				openElementNamespaces: [...this.open_element_namespaces],
				openElementIntegrationNodeTypes: [...this.open_element_integration_node_types],
				openElementFosterParentedTableIndices: [...this.open_element_foster_parented_table_indices],
				detachedContextBreadcrumbs: [...this.detached_context_breadcrumbs],
				detachedBreadcrumbs: this.detached_breadcrumbs.map((breadcrumb) => ({ ...breadcrumb })),
				breadcrumbs: [...this.breadcrumbs],
				currentNamespace: this.current_namespace,
				currentTokenNamespace: this.current_token_namespace,
				delayedSyntheticTokens: this.delayed_synthetic_tokens.map((token) => ({
					...token,
					breadcrumbs: [...token.breadcrumbs],
					attributes: (token.attributes ?? []).map((attribute) => ({ ...attribute })),
				})),
				deferredTableOpener: this.deferred_table_opener === null
					? null
					: {
						...this.deferred_table_opener,
						breadcrumbs: [...this.deferred_table_opener.breadcrumbs],
						attributes: this.deferred_table_opener.attributes.map((attribute) => ({ ...attribute })),
					},
				deferredTableChildOpeners: this.deferred_table_child_openers.map((token) => ({
					...token,
					breadcrumbs: [...token.breadcrumbs],
					attributes: token.attributes.map((attribute) => ({ ...attribute })),
				})),
				pendingForeignTableFosteredTextTableIndex: this.pending_foreign_table_fostered_text_table_index,
				pendingNestedAnchorOuterCloserAfterDeferredTableIndex: this.pending_nested_anchor_outer_closer_after_deferred_table_index,
				pendingNestedAnchorActiveRemovalAfterDeferredTable: this.pending_nested_anchor_active_removal_after_deferred_table,
				pendingNestedAnchorDivActiveRemovalAfterDeferredTable: this.pending_nested_anchor_div_active_removal_after_deferred_table,
				activeFormattingElements: this.active_formatting_elements.map((entry) => this.#cloneActiveFormattingElement(entry)),
				paragraphAdoptionPreclosedFormattingElements: this.paragraph_adoption_preclosed_formatting_elements.map((entry) => ({ ...entry })),
				specialStartAdoptionPreclosedFormattingElements: this.special_start_adoption_preclosed_formatting_elements.map((entry) => ({ ...entry })),
				deepAnchorReconstructedDivStartOffsets: [...this.deep_anchor_reconstructed_div_start_offsets],
				tableNobrReconstructedStartOffsets: [...this.table_nobr_reconstructed_start_offsets],
				ignoredSelectFormattingElements: [...this.ignored_select_formatting_elements.entries()],
				templateInsertionModes: [...this.template_insertion_modes],
				encodingConfidence: this.encoding_confidence,
				baseOpenElementCount: this.base_open_element_count,
				fullParserInsertionMode: this.full_parser_insertion_mode,
				fullParserScaffolded: this.full_parser_scaffolded,
				fullParserSeenDoctype: this.full_parser_seen_doctype,
				framesetOk: this.frameset_ok,
				preFramesetIgnoredElementDepth: this.pre_frameset_ignored_element_depth,
				formElementPointer: this.form_element_pointer,
			};
		}

		#restoreProcessorState(state) {
			this.current_virtual = null;
			this.virtual_tokens = [];
			this.pending_real_token = false;
			this.pending_real_parser_state = null;
			this.skip_current_token = false;
			this.delayed_synthetic_tokens = (state.delayedSyntheticTokens ?? []).map((token) => ({
				...token,
				breadcrumbs: [...token.breadcrumbs],
				attributes: (token.attributes ?? []).map((attribute) => ({ ...attribute })),
			}));
			this.deferred_table_opener = state.deferredTableOpener === null || state.deferredTableOpener === undefined
				? null
				: {
					...state.deferredTableOpener,
					breadcrumbs: [...state.deferredTableOpener.breadcrumbs],
					attributes: (state.deferredTableOpener.attributes ?? []).map((attribute) => ({ ...attribute })),
				};
			this.deferred_table_child_openers = (state.deferredTableChildOpeners ?? []).map((token) => ({
				...token,
				breadcrumbs: [...token.breadcrumbs],
				attributes: (token.attributes ?? []).map((attribute) => ({ ...attribute })),
			}));
			this.pending_foreign_table_fostered_text_table_index = state.pendingForeignTableFosteredTextTableIndex ?? null;
			this.pending_nested_anchor_outer_closer_after_deferred_table_index = state.pendingNestedAnchorOuterCloserAfterDeferredTableIndex ?? null;
			this.pending_nested_anchor_active_removal_after_deferred_table = state.pendingNestedAnchorActiveRemovalAfterDeferredTable ?? false;
			this.pending_nested_anchor_div_active_removal_after_deferred_table = state.pendingNestedAnchorDivActiveRemovalAfterDeferredTable ?? false;
			this.full_parser_insertion_mode = state.fullParserInsertionMode;
			this.full_parser_scaffolded = state.fullParserScaffolded;
			this.full_parser_seen_doctype = state.fullParserSeenDoctype;
			this.frameset_ok = state.framesetOk;
			this.pre_frameset_ignored_element_depth = state.preFramesetIgnoredElementDepth ?? 0;
			this.form_element_pointer = state.formElementPointer;
			this.temporary_reopened_head = false;
			this.open_elements = [...state.openElements];
			this.open_element_namespaces = [...state.openElementNamespaces];
			this.open_element_integration_node_types = [...state.openElementIntegrationNodeTypes];
			this.open_element_foster_parented_table_indices = [...(state.openElementFosterParentedTableIndices ?? this.open_elements.map(() => null))];
			this.detached_context_breadcrumbs = [...state.detachedContextBreadcrumbs];
			this.detached_breadcrumbs = (state.detachedBreadcrumbs ?? []).map((breadcrumb) => ({ ...breadcrumb }));
			this.active_formatting_elements = state.activeFormattingElements.map((entry) => this.#cloneActiveFormattingElement(entry));
			this.paragraph_adoption_preclosed_formatting_elements = (state.paragraphAdoptionPreclosedFormattingElements ?? []).map((entry) => ({ ...entry }));
			this.special_start_adoption_preclosed_formatting_elements = (state.specialStartAdoptionPreclosedFormattingElements ?? []).map((entry) => ({ ...entry }));
			this.deep_anchor_reconstructed_div_start_offsets = new Set(state.deepAnchorReconstructedDivStartOffsets ?? []);
			this.table_nobr_reconstructed_start_offsets = new Set(state.tableNobrReconstructedStartOffsets ?? []);
			this.ignored_select_formatting_elements = new Map(state.ignoredSelectFormattingElements);
			this.template_insertion_modes = [...state.templateInsertionModes];
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

		#consumeRawTextFragmentToken() {
			if (
				this.raw_text_fragment_context === null ||
				this.raw_text_fragment_consumed ||
				this.html === ""
			) {
				return false;
			}

			let text = RCDATA_FRAGMENT_CONTEXT_ELEMENTS.has(this.raw_text_fragment_context)
				? WP_HTML_Decoder.decode_text_node(this.html)
				: this.html;
			text = replaceNulls(text);
			this.raw_text_fragment_consumed = true;
			this.current_synthetic_token = {
				tokenType: "#text",
				tokenName: "#text",
				modifiableText: text,
			};
			this.parser_state = STATE_TEXT_NODE;
			this.text_node_classification = splitHtmlWhitespace(text).length === 0
				? WP_HTML_Tag_Processor.TEXT_IS_WHITESPACE
				: WP_HTML_Tag_Processor.TEXT_IS_GENERIC;
			this.current_token_namespace = "html";
			this.breadcrumbs = this.#breadcrumbStack("#text");
			return true;
		}

		#consumePlaintextTextToken() {
			if (!this.plaintext_pending || this.plaintext_text_consumed) {
				return false;
			}

			const span = this.#nativeCurrentSpan();
			const html = super.get_updated_html();
			this.plaintext_content_start = span === null
				? html.length
				: Math.min(html.length, span.start + span.length);
			const text = replaceNulls(html.slice(this.plaintext_content_start));
			this.plaintext_pending = false;
			this.plaintext_text_consumed = true;
			if (text === "") {
				return false;
			}

			this.current_synthetic_token = {
				tokenType: "#text",
				tokenName: "#text",
				modifiableText: text,
				rawText: true,
			};
			this.current_virtual = null;
			this.parser_state = STATE_TEXT_NODE;
			this.text_node_classification = splitHtmlWhitespace(text).length === 0
				? WP_HTML_Tag_Processor.TEXT_IS_WHITESPACE
				: WP_HTML_Tag_Processor.TEXT_IS_GENERIC;
			this.current_token_namespace = "html";
			this.breadcrumbs = this.#breadcrumbStack("#text");
			return true;
		}

		#hasFutureNoframesStartTag() {
			const span = this.#nativeCurrentSpan();
			const start = span === null ? 0 : span.start + span.length;
			return /<\s*noframes(?:[\t\n\f\r />]|$)/i.test(super.get_updated_html().slice(start));
		}

		#delayCurrentCommentToken(breadcrumbs = this.#breadcrumbStack("#comment")) {
			this.delayed_synthetic_tokens.push({
				tokenType: "#comment",
				tokenName: "#comment",
				commentText: this.get_full_comment_text() ?? "",
				namespaceName: this.current_namespace,
				breadcrumbs,
			});
			this.skip_current_token = true;
			return true;
		}

		#serializeTextToken() {
			const text = this.get_modifiable_text() ?? "";
			if (this.current_synthetic_token?.rawText === true) {
				return text;
			}
			if (
				this.raw_text_fragment_context !== null &&
				!RCDATA_FRAGMENT_CONTEXT_ELEMENTS.has(this.raw_text_fragment_context)
			) {
				return text;
			}
			return htmlEscape(text);
		}

		#getVirtualAttribute(name) {
			const attributes = this.current_virtual?.attributes ?? [];
			const wantedName = phpInternalStringCoerce(name, "name");
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
			const wantedPrefix = phpInternalStringCoerce(prefix, "prefix");
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

		#getSyntheticAttribute(name) {
			const attributes = this.current_synthetic_token?.attributes ?? [];
			const wantedName = phpInternalStringCoerce(name, "name");
			const normalizedWantedName = this.current_token_namespace === "html" ? asciiLower(wantedName) : wantedName;

			for (const attribute of attributes) {
				const attributeName = this.current_token_namespace === "html" ? asciiLower(attribute.name) : attribute.name;
				if (attributeName === normalizedWantedName) {
					return attribute.value;
				}
			}

			return null;
		}

		#getSyntheticAttributeNamesWithPrefix(prefix) {
			const attributes = this.current_synthetic_token?.attributes ?? [];
			const wantedPrefix = phpInternalStringCoerce(prefix, "prefix");
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
			const wantedClass = phpInternalStringCoerce(className, "wanted_class");
			const comparableClassName = this.#comparableClassName(wantedClass.replaceAll("\0", "\uFFFD"));
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
				templateDepth: this.#countOpenHtmlElements("TEMPLATE"),
				openElementIndex: this.open_elements.length - 1,
			};
		}

		#insertActiveFormattingElement(entry) {
			let equivalentEntries = 0;
			for (let i = this.active_formatting_elements.length - 1; i >= 0; i -= 1) {
				if (this.#isActiveFormattingMarker(this.active_formatting_elements[i])) {
					break;
				}

				if (!this.#activeFormattingElementsAreEquivalent(entry, this.active_formatting_elements[i])) {
					continue;
				}

				equivalentEntries += 1;
				if (equivalentEntries === 3) {
					this.active_formatting_elements.splice(i, 1);
					break;
				}
			}

			this.active_formatting_elements.push(entry);
		}

		#insertActiveFormattingMarker() {
			this.active_formatting_elements.push({
				marker: true,
				templateDepth: this.#countOpenHtmlElements("TEMPLATE"),
			});
		}

		#clearActiveFormattingElementsUpToLastMarker() {
			while (this.active_formatting_elements.length > 0) {
				const entry = this.active_formatting_elements.pop();
				if (this.#isActiveFormattingMarker(entry)) {
					break;
				}
			}
		}

		#isActiveFormattingMarker(entry) {
			return entry?.marker === true;
		}

		#rememberIgnoredSelectFormattingElement(tagName) {
			if (!ADOPTION_AGENCY_END_TAGS.has(tagName)) {
				return;
			}

			this.ignored_select_formatting_elements.set(
				tagName,
				(this.ignored_select_formatting_elements.get(tagName) ?? 0) + 1,
			);
		}

		#consumeIgnoredSelectFormattingElement(tagName) {
			const count = this.ignored_select_formatting_elements.get(tagName) ?? 0;
			if (count < 1) {
				return false;
			}

			if (count === 1) {
				this.ignored_select_formatting_elements.delete(tagName);
			} else {
				this.ignored_select_formatting_elements.set(tagName, count - 1);
			}
			return true;
		}

		#activeFormattingElementsAreEquivalent(left, right) {
			if (
				this.#isActiveFormattingMarker(left) ||
				this.#isActiveFormattingMarker(right) ||
				left.tagName !== right.tagName ||
				left.namespaceName !== right.namespaceName ||
				(left.templateDepth ?? 0) !== (right.templateDepth ?? 0) ||
				left.attributes.length !== right.attributes.length
			) {
				return false;
			}

			const rightAttributes = new Map();
			for (const attribute of right.attributes) {
				rightAttributes.set(
					this.#activeFormattingAttributeName(right, attribute),
					attribute.value,
				);
			}

			for (const attribute of left.attributes) {
				const attributeName = this.#activeFormattingAttributeName(left, attribute);
				if (!rightAttributes.has(attributeName) || rightAttributes.get(attributeName) !== attribute.value) {
					return false;
				}
			}

			return true;
		}

		#activeFormattingAttributeName(entry, attribute) {
			return entry.namespaceName === "html" ? asciiLower(attribute.name) : attribute.name;
		}

		#cloneActiveFormattingElement(entry) {
			if (this.#isActiveFormattingMarker(entry)) {
				return {
					marker: true,
					templateDepth: entry.templateDepth ?? 0,
				};
			}

			return {
				tagName: entry.tagName,
				namespaceName: entry.namespaceName,
				templateDepth: entry.templateDepth ?? 0,
				openElementIndex: entry.openElementIndex ?? null,
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
				if (this.#isActiveFormattingMarker(entry)) {
					break;
				}
				if (this.#activeFormattingElementIsOpen(entry)) {
					break;
				}
				firstMissingIndex -= 1;
			}

			if (firstMissingIndex === this.active_formatting_elements.length) {
				return false;
			}

			for (let i = firstMissingIndex; i < this.active_formatting_elements.length; i += 1) {
				const entry = this.active_formatting_elements[i];
				if (this.#isActiveFormattingMarker(entry)) {
					continue;
				}
				this.#queueActiveFormattingElementEntry(entry);
			}

			return true;
		}

		#queueReconstructActiveFormattingElementsBeforeDeferredTable() {
			if (this.deferred_table_opener === null) {
				return false;
			}

			const tableIndex = this.#lastOpenElementIndex("TABLE", "html");
			if (tableIndex === -1) {
				return false;
			}

			let firstMissingIndex = this.active_formatting_elements.length;
			while (firstMissingIndex > 0) {
				const entry = this.active_formatting_elements[firstMissingIndex - 1];
				if (this.#isActiveFormattingMarker(entry)) {
					break;
				}
				if (this.#activeFormattingElementIsOpen(entry)) {
					break;
				}
				firstMissingIndex -= 1;
			}

			if (firstMissingIndex === this.active_formatting_elements.length) {
				return false;
			}

			for (let i = firstMissingIndex; i < this.active_formatting_elements.length; i += 1) {
				const entry = this.active_formatting_elements[i];
				if (this.#isActiveFormattingMarker(entry)) {
					continue;
				}
				this.#queueActiveFormattingElementEntry(entry, tableIndex);
			}

			return true;
		}

		#queueDeepFormattingElementSpecialStartPreclosure(tagName) {
			if (tagName !== "DIV") {
				return false;
			}

			const topIndex = this.open_elements.length - 1;
			if (
				topIndex < 0 ||
				this.open_element_namespaces[topIndex] !== "html" ||
				!FORMATTING_ELEMENTS.has(this.open_elements[topIndex])
			) {
				return false;
			}

			for (let i = topIndex - 1; i >= 0; i -= 1) {
				if (this.open_element_namespaces[i] !== "html") {
					return false;
				}

				if (!FORMATTING_ELEMENTS.has(this.open_elements[i])) {
					if (isSpecialBoundary(this.open_elements[i], this.open_element_namespaces[i])) {
						return false;
					}
					continue;
				}

				const formattingTagName = this.open_elements[i];
				if (formattingTagName === "I") {
					const activeFormattingElementIndex = this.#lastActiveFormattingElementIndex(formattingTagName);
					const activeFormattingElement = this.active_formatting_elements[activeFormattingElementIndex];
					const followingEntries = this.#activeFormattingElementsAfterIndex(activeFormattingElementIndex);
					if (
						activeFormattingElementIndex !== -1 &&
						followingEntries.length === 1 &&
						followingEntries[0].tagName === "B" &&
						followingEntries[0].namespaceName === "html" &&
						this.#hasOnlyOpenFormattingElementsAfterIndex(i) &&
						!hasSpecialBoundaryAfter(this.open_elements, this.open_element_namespaces, i) &&
						this.#formattingEndTagPrecedesElementClose(formattingTagName, tagName)
					) {
						this.#queueVirtualPopsFrom(i);
						if (
							this.deferred_table_opener !== null &&
							this.#currentFosterParentedTableIndex() !== null
						) {
							this.#queueActiveFormattingElementsAfterIndex(activeFormattingElementIndex);
						} else {
							this.#queueActiveFormattingElementsAfterIndexAsEmpty(activeFormattingElementIndex);
						}
						this.#replaceActiveFormattingElementsFromIndex(activeFormattingElementIndex, [
							...followingEntries,
							activeFormattingElement,
						]);
						return true;
					}

					continue;
				}

				if (formattingTagName === "B") {
					const activeFormattingElementIndex = this.#lastActiveFormattingElementIndex(formattingTagName);
					const followingEntries = this.#activeFormattingElementsAfterIndex(activeFormattingElementIndex);
					const preservedEntries = followingEntries.filter((entry) => (
						entry.tagName === "I" &&
						entry.namespaceName === "html"
					)).slice(-2);
					if (
						activeFormattingElementIndex !== -1 &&
						preservedEntries.length === 2 &&
						this.#hasOnlyOpenFormattingOrCiteElementsAfterIndex(i) &&
						!hasSpecialBoundaryAfter(this.open_elements, this.open_element_namespaces, i) &&
						this.#formattingEndTagPrecedesElementClose(formattingTagName, tagName)
					) {
						this.#markSpecialStartAdoptionPreclosedFormattingElement(formattingTagName, "html", tagName, "text-self");
						this.#queueVirtualPopsFrom(i);
						this.#queueActiveFormattingElementEntries(preservedEntries);
						this.#replaceActiveFormattingElementsAfterIndex(activeFormattingElementIndex, preservedEntries);
						return true;
					}

					continue;
				}

				if (formattingTagName !== "A") {
					continue;
				}

				if (!this.#hasOnlyOpenFormattingElementsAfterIndex(i)) {
					return false;
				}

				const activeFormattingElementIndex = this.#lastActiveFormattingElementIndex(formattingTagName);
				const activeFormattingElement = this.active_formatting_elements[activeFormattingElementIndex];
				const followingEntries = this.#activeFormattingElementsAfterIndex(activeFormattingElementIndex);
				if (
					activeFormattingElementIndex !== -1 &&
					followingEntries.length === 1 &&
					followingEntries[0].tagName === "B" &&
					followingEntries[0].namespaceName === "html" &&
					!hasSpecialBoundaryAfter(this.open_elements, this.open_element_namespaces, i) &&
					this.#formattingEndTagPrecedesElementClose(formattingTagName, tagName)
				) {
					this.#markSpecialStartAdoptionPreclosedFormattingElement(formattingTagName, "html", tagName, "reconstruct-before-nested-div");
					this.#queueVirtualPopsFrom(i);
					this.#queueActiveFormattingElementEntries(followingEntries);
					this.#replaceActiveFormattingElementsFromIndex(activeFormattingElementIndex, [
						...followingEntries,
						activeFormattingElement,
					]);
					return true;
				}

				if (
					activeFormattingElementIndex === -1 ||
					followingEntries.length < 4 ||
					hasSpecialBoundaryAfter(this.open_elements, this.open_element_namespaces, i) ||
					!this.#formattingEndTagPrecedesElementClose(formattingTagName, tagName)
				) {
					return false;
				}

				this.#markSpecialStartAdoptionPreclosedFormattingElement(formattingTagName, "html", tagName);
				this.#queueVirtualPopsFrom(i);
				this.#queueActiveFormattingElementEntries(followingEntries.slice(-3));
				this.#removeActiveFormattingElementsAfterIndexBeforeTail(activeFormattingElementIndex, 3);
				return true;
			}

			return false;
		}

		#queueFormattingElementAncestorSpecialStartPreclosure(tagName) {
			if (!FORMATTING_ELEMENT_ANCESTOR_PRECLOSURE_START_TAGS.has(tagName)) {
				return false;
			}

			const topIndex = this.open_elements.length - 1;
			if (
				topIndex < 1 ||
				this.open_element_namespaces[topIndex] !== "html" ||
				FORMATTING_ELEMENTS.has(this.open_elements[topIndex])
			) {
				return false;
			}

			for (let i = topIndex - 1; i >= 0; i -= 1) {
				if (this.open_element_namespaces[i] !== "html") {
					return false;
				}

				if (!FORMATTING_ELEMENTS.has(this.open_elements[i])) {
					if (isSpecialBoundary(this.open_elements[i], this.open_element_namespaces[i])) {
						return false;
					}
					continue;
				}

				const formattingTagName = this.open_elements[i];
				if (tagName === "ASIDE" && formattingTagName !== "B") {
					continue;
				}
				const activeFormattingElementIndex = this.#lastActiveFormattingElementIndex(formattingTagName);
				if (
					activeFormattingElementIndex === -1 ||
					hasSpecialBoundaryAfter(this.open_elements, this.open_element_namespaces, i)
				) {
					return false;
				}
				if (!this.#formattingEndTagPrecedesElementClose(formattingTagName, tagName)) {
					continue;
				}
				const reconstructionMode = tagName === "ASIDE"
					? this.#asideFormattingPreclosureReconstructionMode(activeFormattingElementIndex, i, tagName)
					: "self";
				if (reconstructionMode === null) {
					return false;
				}

				this.#markSpecialStartAdoptionPreclosedFormattingElement(formattingTagName, "html", tagName, reconstructionMode);
				this.#queueVirtualPopsFrom(i);
				if (
					tagName === "ASIDE" &&
					reconstructionMode === "wrap-following"
				) {
					this.#queueActiveFormattingElementsAfterIndex(activeFormattingElementIndex);
				} else if (reconstructionMode === "empty-following-then-self") {
					this.#queueActiveFormattingElementsAfterIndexAsEmpty(activeFormattingElementIndex);
				}
				return true;
			}

			return false;
		}

		#queueFormattingElementSpecialStartPreclosure(tagName) {
			if (!FORMATTING_ELEMENT_SPECIAL_PRECLOSURE_START_TAGS.has(tagName)) {
				return false;
			}

			if (this.#shouldKeepDeepAnchorAroundCurrentNestedDiv(tagName)) {
				return false;
			}

			const topIndex = this.open_elements.length - 1;
			if (
				topIndex < 0 ||
				this.open_element_namespaces[topIndex] !== "html" ||
				!FORMATTING_ELEMENTS.has(this.open_elements[topIndex])
			) {
				return false;
			}

			const formattingTagName = this.open_elements[topIndex];
			const openNobrIndex = tagName === "NOBR" ? this.#lastOpenElementIndex("NOBR", "html") : -1;
			if (
				tagName === "NOBR" &&
				formattingTagName !== "NOBR" &&
				openNobrIndex !== -1 &&
				this.#lastOpenElementIndex("TABLE", "html") > openNobrIndex
			) {
				return false;
			}
			if (
				tagName === "DIV" &&
				formattingTagName !== "NOBR" &&
				this.deferred_table_opener !== null &&
				this.#currentFosterParentedTableIndex() !== null &&
				!this.#formattingEndTagPrecedesTableModeStart(formattingTagName)
			) {
				return false;
			}

			if (
				(
					tagName === "NOBR" &&
					(openNobrIndex === -1 || openNobrIndex >= topIndex)
				) ||
				this.#lastActiveFormattingElementIndex(formattingTagName) === -1 ||
				(
					tagName === "DIV" &&
					formattingTagName !== "NOBR" &&
					this.#hasOpenFormattingElementBeforeIndex(topIndex)
				) ||
				(
					!this.#formattingEndTagPrecedesElementClose(formattingTagName, tagName) &&
					(tagName !== "NOBR" || !this.#currentTokenHasNoFollowingTags()) &&
					(
						tagName !== "DIV" ||
						formattingTagName !== "NOBR" ||
						!this.#openFormattingElementBeforeIndexPrecedesElementClose(topIndex, tagName)
					)
				)
			) {
				return false;
			}

			this.#markSpecialStartAdoptionPreclosedFormattingElement(formattingTagName, "html", tagName);
			this.#queueVirtualPopsFrom(topIndex);
			return true;
		}

		#asideFormattingPreclosureReconstructionMode(activeIndex, openIndex, elementTagName) {
			const openElementsAfterIndex = this.open_elements.length - openIndex - 1;
			let activeFormattingElementsAfterIndex = 0;
			let followingFormattingEndPrecedesElementClose = false;
			for (let i = activeIndex + 1; i < this.active_formatting_elements.length; i += 1) {
				const entry = this.active_formatting_elements[i];
				if (this.#isActiveFormattingMarker(entry)) {
					break;
				}
				activeFormattingElementsAfterIndex += 1;
				if (this.#formattingEndTagPrecedesElementClose(entry.tagName, elementTagName)) {
					followingFormattingEndPrecedesElementClose = true;
				}
			}

			if (activeFormattingElementsAfterIndex !== 1 || openElementsAfterIndex < 3) {
				return null;
			}

			if (openElementsAfterIndex > 3) {
				return "self";
			}

			return followingFormattingEndPrecedesElementClose
				? "empty-following-then-self"
				: "wrap-following";
		}

		#formattingEndTagPrecedesTableModeStart(formattingTagName) {
			const span = this.#currentRealTokenSpan();
			if (span === null) {
				return false;
			}

			let at = span.start + span.length;
			while (true) {
				const nextTag = runtime.scanNextTag(this.html, at);
				if (nextTag === false) {
					return false;
				}

				if (nextTag.is_closing) {
					if (nextTag.tag_name === formattingTagName) {
						return true;
					}
					if (nextTag.tag_name === "DIV") {
						return false;
					}
					at = nextTag.token_end;
					continue;
				}

				if (TABLE_MODE_START_TAGS.has(nextTag.tag_name)) {
					return false;
				}

				at = nextTag.token_end;
			}
		}

		#queueActiveFormattingElementsAfterIndex(index) {
			this.#queueActiveFormattingElementEntries(this.#activeFormattingElementsAfterIndex(index));
		}

		#queueActiveFormattingElementEntries(entries) {
			for (const entry of entries) {
				this.#queueActiveFormattingElementEntry(entry);
			}
		}

		#queueActiveFormattingElementEntry(entry, fosterParentedTableIndex = undefined) {
			entry.openElementIndex = this.#futureOpenElementIndexForVirtualPush();
			if (fosterParentedTableIndex === undefined) {
				this.#queueVirtualPush(entry.tagName, entry.namespaceName, entry.attributes);
				return;
			}

			this.#queueVirtualPush(entry.tagName, entry.namespaceName, entry.attributes, null, fosterParentedTableIndex);
		}

		#futureOpenElementIndexForVirtualPush() {
			let length = this.open_elements.length;
			for (const token of this.virtual_tokens) {
				length += token.operation === "push" ? 1 : -1;
			}
			return Math.max(0, length);
		}

		#activeFormattingElementsAfterIndex(index) {
			const entries = [];
			for (let i = index + 1; i < this.active_formatting_elements.length; i += 1) {
				const entry = this.active_formatting_elements[i];
				if (this.#isActiveFormattingMarker(entry)) {
					break;
				}
				entries.push(entry);
			}
			return entries;
		}

		#hasOnlyOpenFormattingElementsAfterIndex(index) {
			for (let i = index + 1; i < this.open_elements.length; i += 1) {
				if (
					this.open_element_namespaces[i] !== "html" ||
					!FORMATTING_ELEMENTS.has(this.open_elements[i])
				) {
					return false;
				}
			}
			return true;
		}

		#hasOnlyOpenFormattingOrCiteElementsAfterIndex(index) {
			for (let i = index + 1; i < this.open_elements.length; i += 1) {
				if (
					this.open_element_namespaces[i] !== "html" ||
					(
						this.open_elements[i] !== "CITE" &&
						!FORMATTING_ELEMENTS.has(this.open_elements[i])
					)
				) {
					return false;
				}
			}
			return true;
		}

		#removeActiveFormattingElementsAfterIndexBeforeTail(index, tailCount) {
			let endIndex = index + 1;
			while (
				endIndex < this.active_formatting_elements.length &&
				!this.#isActiveFormattingMarker(this.active_formatting_elements[endIndex])
			) {
				endIndex += 1;
			}

			const removeEndIndex = Math.max(index + 1, endIndex - tailCount);
			for (let i = removeEndIndex - 1; i > index; i -= 1) {
				const entry = this.active_formatting_elements[i];
				this.active_formatting_elements.splice(i, 1);
				this.#clearParagraphAdoptionPreclosedFormattingElements(entry.tagName, entry.namespaceName);
				this.#clearSpecialStartAdoptionPreclosedFormattingElements(entry.tagName, entry.namespaceName);
			}
		}

		#replaceActiveFormattingElementsFromIndex(index, entries) {
			let endIndex = index;
			while (
				endIndex < this.active_formatting_elements.length &&
				!this.#isActiveFormattingMarker(this.active_formatting_elements[endIndex])
			) {
				endIndex += 1;
			}

			this.active_formatting_elements.splice(
				index,
				endIndex - index,
				...entries.map((entry) => this.#cloneActiveFormattingElement(entry)),
			);
		}

		#replaceActiveFormattingElementsAfterIndex(index, entries) {
			let endIndex = index + 1;
			while (
				endIndex < this.active_formatting_elements.length &&
				!this.#isActiveFormattingMarker(this.active_formatting_elements[endIndex])
			) {
				endIndex += 1;
			}

			this.active_formatting_elements.splice(
				index + 1,
				endIndex - index - 1,
				...entries.map((entry) => this.#cloneActiveFormattingElement(entry)),
			);
		}

		#queueActiveFormattingElementsAfterIndexAsEmpty(index) {
			const queued = [];
			for (let i = index + 1; i < this.active_formatting_elements.length; i += 1) {
				const entry = this.active_formatting_elements[i];
				if (this.#isActiveFormattingMarker(entry)) {
					break;
				}
				this.#queueActiveFormattingElementEntry(entry);
				queued.push(entry);
			}

			for (let i = queued.length - 1; i >= 0; i -= 1) {
				this.#queueVirtualPop(queued[i].tagName, queued[i].namespaceName);
			}
		}

		#queueActiveFormattingElement(tagName, namespaceName) {
			for (let i = this.active_formatting_elements.length - 1; i >= 0; i -= 1) {
				const entry = this.active_formatting_elements[i];
				if (this.#isActiveFormattingMarker(entry)) {
					return false;
				}
				if (entry.tagName === tagName && entry.namespaceName === namespaceName) {
					this.#queueActiveFormattingElementEntry(entry);
					return true;
				}
			}

			return false;
		}

		#queueActiveFormattingElementWithFollowingElements(tagName, namespaceName) {
			for (let i = this.active_formatting_elements.length - 1; i >= 0; i -= 1) {
				const entry = this.active_formatting_elements[i];
				if (this.#isActiveFormattingMarker(entry)) {
					return false;
				}
				if (entry.tagName !== tagName || entry.namespaceName !== namespaceName) {
					continue;
				}

				for (let j = i + 1; j < this.active_formatting_elements.length; j += 1) {
					const followingEntry = this.active_formatting_elements[j];
					if (this.#isActiveFormattingMarker(followingEntry)) {
						break;
					}
					this.#queueActiveFormattingElementEntry(followingEntry);
				}
				this.#queueActiveFormattingElementEntry(entry);
				return true;
			}

			return false;
		}

		#hasOpenFormattingElementBeforeIndex(index) {
			for (let i = index - 1; i >= 0; i -= 1) {
				if (this.open_element_namespaces[i] !== "html") {
					return false;
				}
				if (FORMATTING_ELEMENTS.has(this.open_elements[i])) {
					return true;
				}
				if (isSpecialBoundary(this.open_elements[i], this.open_element_namespaces[i])) {
					return false;
				}
			}

			return false;
		}

		#openFormattingElementBeforeIndexPrecedesElementClose(index, elementTagName) {
			for (let i = index - 1; i >= 0; i -= 1) {
				if (this.open_element_namespaces[i] !== "html") {
					return false;
				}
				if (FORMATTING_ELEMENTS.has(this.open_elements[i])) {
					return (
						this.#lastActiveFormattingElementIndex(this.open_elements[i]) !== -1 &&
						this.#formattingEndTagPrecedesElementClose(this.open_elements[i], elementTagName)
					);
				}
				if (isSpecialBoundary(this.open_elements[i], this.open_element_namespaces[i])) {
					return false;
				}
			}

			return false;
		}

		#currentTokenHasNoFollowingTags() {
			const span = this.#currentRealTokenSpan();
			return span !== null && runtime.scanNextTag(this.html, span.start + span.length) === false;
		}

		#formattingEndTagPrecedesElementClose(formattingTagName, elementTagName) {
			const span = this.#currentRealTokenSpan();
			if (span === null) {
				return false;
			}

			let at = span.start + span.length;
			while (true) {
				const nextTag = runtime.scanNextTag(this.html, at);
				if (nextTag === false) {
					return false;
				}

				if (nextTag.is_closing && nextTag.tag_name === formattingTagName) {
					return true;
				}

				if (
					(nextTag.is_closing && nextTag.tag_name === elementTagName) ||
					(!nextTag.is_closing && nextTag.tag_name === "TABLE")
				) {
					return false;
				}

				at = nextTag.token_end;
			}
		}

		#queueNestedAnchorBlockAdoptionPreclosure(tagName) {
			if (!NESTED_ANCHOR_BLOCK_PRECLOSURE_START_TAGS.has(tagName)) {
				return false;
			}

			const topIndex = this.open_elements.length - 1;
			if (
				topIndex < 0 ||
				this.open_elements[topIndex] !== "A" ||
				this.open_element_namespaces[topIndex] !== "html" ||
				this.#lastActiveFormattingElementIndex("A") === -1 ||
				this.#shouldKeepDeepAnchorAroundCurrentNestedDiv(tagName) ||
				!this.#nestedAnchorStartPrecedesBlockEnd(tagName)
			) {
				return false;
			}

			this.#queueVirtualPopsFrom(topIndex);
			return true;
		}

		#nestedAnchorStartPrecedesBlockEnd(tagName) {
			const span = this.#currentRealTokenSpan();
			if (span === null) {
				return false;
			}

			let at = span.start + span.length;
			let sameTagDepth = 0;
			while (true) {
				const nextTag = runtime.scanNextTag(this.html, at);
				if (nextTag === false) {
					return false;
				}

				if (!nextTag.is_closing && nextTag.tag_name === tagName && tagName === "DIV") {
					sameTagDepth += 1;
					at = nextTag.token_end;
					continue;
				}

				if (
					!nextTag.is_closing &&
					(
						nextTag.tag_name === "A" ||
						NESTED_ANCHOR_RECONSTRUCTING_START_TAGS.has(nextTag.tag_name)
					)
				) {
					return true;
				}

				if (nextTag.is_closing && nextTag.tag_name === "A") {
					return true;
				}

				if (
					nextTag.is_closing &&
					nextTag.tag_name === tagName &&
					sameTagDepth > 0
				) {
					sameTagDepth -= 1;
					at = nextTag.token_end;
					continue;
				}

				if (
					(nextTag.is_closing && nextTag.tag_name === tagName) ||
					(!nextTag.is_closing && nextTag.tag_name === "TABLE")
				) {
					return false;
				}

				at = nextTag.token_end;
			}
		}

		#shouldReconstructActiveAnchorForStartTag(tagName) {
			if (
				this.#lastActiveFormattingElementIndex("A") === -1 ||
				this.#lastOpenElementIndex("A", "html") !== -1
			) {
				return false;
			}

			return (
				NESTED_ANCHOR_RECONSTRUCTING_START_TAGS.has(tagName) ||
				this.#shouldReconstructDeepAnchorBeforeNestedDiv(tagName) ||
				(
					tagName === "P" &&
					!this.#hasParagraphAdoptionPreclosedFormattingElement("A", "html") &&
					this.#formattingEndTagPrecedesParagraphClose("A")
				)
			);
		}

		#shouldReconstructDeepAnchorBeforeNestedDiv(tagName) {
			if (
				tagName !== "DIV" ||
				this.open_elements.at(-1) !== "DIV" ||
				this.open_element_namespaces.at(-1) !== "html" ||
				!this.#hasSpecialStartAdoptionPreclosedFormattingElement(
					"A",
					"html",
					"DIV",
					"reconstruct-before-nested-div",
				) ||
				this.#countOpenHtmlElementsAfterLast("B", "DIV") > 8
			) {
				return false;
			}

			const span = this.#currentRealTokenSpan();
			if (span === null || this.deep_anchor_reconstructed_div_start_offsets.has(span.start)) {
				return false;
			}

			this.deep_anchor_reconstructed_div_start_offsets.add(span.start);
			return true;
		}

		#shouldKeepDeepAnchorAroundCurrentNestedDiv(tagName) {
			if (
				tagName !== "DIV" ||
				this.open_elements.at(-1) !== "A" ||
				this.open_element_namespaces.at(-1) !== "html" ||
				!this.#hasSpecialStartAdoptionPreclosedFormattingElement(
					"A",
					"html",
					"DIV",
					"reconstruct-before-nested-div",
				) ||
				this.#countOpenHtmlElementsAfterLast("B", "DIV") < 8
			) {
				return false;
			}

			return true;
		}

		#alreadyReconstructedTableNobrForCurrentToken(tagName) {
			if (
				tagName !== "NOBR" ||
				this.#lastOpenElementIndex("TABLE", "html") === -1 ||
				this.#currentFosterParentedTableIndex() !== null
			) {
				return false;
			}

			const span = this.#currentRealTokenSpan();
			return span !== null && this.table_nobr_reconstructed_start_offsets.has(span.start);
		}

		#rememberTableNobrReconstructionForCurrentToken(tagName) {
			if (
				tagName !== "NOBR" ||
				this.#lastOpenElementIndex("TABLE", "html") === -1 ||
				this.#currentFosterParentedTableIndex() !== null
			) {
				return;
			}

			const span = this.#currentRealTokenSpan();
			if (span !== null) {
				this.table_nobr_reconstructed_start_offsets.add(span.start);
			}
		}

		#countOpenHtmlElementsAfterLast(afterTagName, countedTagName) {
			const afterIndex = this.#lastOpenElementIndex(afterTagName, "html");
			if (afterIndex === -1) {
				return 0;
			}

			let count = 0;
			for (let i = afterIndex + 1; i < this.open_elements.length; i += 1) {
				if (
					this.open_elements[i] === countedTagName &&
					this.open_element_namespaces[i] === "html"
				) {
					count += 1;
				}
			}
			return count;
		}

		#shouldReconstructActiveFontForStartTag(tagName) {
			return (
				FONT_PARAGRAPH_ADOPTION_RECONSTRUCTING_START_TAGS.has(tagName) &&
				this.#lastActiveFormattingElementIndex("FONT") !== -1 &&
				this.#lastOpenElementIndex("FONT", "html") === -1
			);
		}

		#queueParagraphAdoptionFormattingPreclosure(tagName) {
			if (tagName !== "P" || this.current_namespace !== "html") {
				return false;
			}

			if (this.#findClosablePInButtonScopeForStartTag(tagName) !== -1) {
				return false;
			}

			const topIndex = this.open_elements.length - 1;
			if (
				topIndex < 0 ||
				this.open_element_namespaces[topIndex] !== "html" ||
				!FORMATTING_ELEMENTS.has(this.open_elements[topIndex])
			) {
				return false;
			}

			for (let i = topIndex; i >= 0; i -= 1) {
				if (this.open_element_namespaces[i] !== "html") {
					return false;
				}

				if (!FORMATTING_ELEMENTS.has(this.open_elements[i])) {
					if (isSpecialBoundary(this.open_elements[i], this.open_element_namespaces[i])) {
						return false;
					}
					continue;
				}

				const formattingTagName = this.open_elements[i];
				const activeFormattingElementIndex = this.#lastActiveFormattingElementIndex(formattingTagName);
				if (
					activeFormattingElementIndex === -1 ||
					hasSpecialBoundaryAfter(this.open_elements, this.open_element_namespaces, i)
				) {
					return false;
				}
				if (
					!this.#formattingEndTagPrecedesParagraphClose(formattingTagName) &&
					!(
						formattingTagName === "B" &&
						!this.#hasOpenFormattingElementBeforeIndex(i) &&
						this.#formattingEndTagPrecedesElementClose(formattingTagName, tagName)
					)
				) {
					continue;
				}

				const reconstructionMode = (
					i < topIndex &&
					this.#activeFormattingElementAfterIndexPrecedesParagraphClose(activeFormattingElementIndex, formattingTagName)
				)
					? "following-inside"
					: "self";

				this.#markParagraphAdoptionPreclosedFormattingElement(formattingTagName, "html", reconstructionMode);
				this.#queueVirtualPopsFrom(i);
				if (i < topIndex) {
					if (reconstructionMode === "following-inside") {
						this.#queueActiveFormattingElementsAfterIndexAsEmpty(activeFormattingElementIndex);
					} else {
						this.#queueActiveFormattingElementsAfterIndex(activeFormattingElementIndex);
					}
				}
				return true;
			}

			return false;
		}

		#queueParagraphAdoptionPreclosedFormattingElementsForText() {
			if (
				this.open_element_namespaces.at(-1) !== "html" ||
				this.open_elements.at(-1) !== "P"
			) {
				return false;
			}

			for (let i = this.paragraph_adoption_preclosed_formatting_elements.length - 1; i >= 0; i -= 1) {
				const entry = this.paragraph_adoption_preclosed_formatting_elements[i];
				if (
					this.#lastOpenElementIndex(entry.tagName, entry.namespaceName) === -1 &&
					this.#lastActiveFormattingElementIndex(entry.tagName) !== -1
				) {
					return entry.reconstructionMode === "following-inside"
						? this.#queueActiveFormattingElementWithFollowingElements(entry.tagName, entry.namespaceName)
						: this.#queueActiveFormattingElement(entry.tagName, entry.namespaceName);
				}
			}

			return false;
		}

		#queueSpecialStartAdoptionPreclosedFormattingElementsForText() {
			if (
				this.open_element_namespaces.at(-1) !== "html" ||
				!FORMATTING_ELEMENT_SPECIAL_PRECLOSURE_START_TAGS.has(this.open_elements.at(-1))
			) {
				return false;
			}

			const containerTagName = this.open_elements.at(-1);
			for (const entry of this.special_start_adoption_preclosed_formatting_elements) {
				if (
					(entry.tagName === "A" || entry.reconstructionMode === "text-self") &&
					entry.containerTagName === containerTagName &&
					this.#lastOpenElementIndex(entry.tagName, entry.namespaceName) === -1 &&
					this.#lastActiveFormattingElementIndex(entry.tagName) !== -1
				) {
					return this.#queueActiveFormattingElement(entry.tagName, entry.namespaceName);
				}
			}

			return false;
		}

		#activeFormattingElementAfterIndexPrecedesParagraphClose(index, skippedEndTagName) {
			for (let i = index + 1; i < this.active_formatting_elements.length; i += 1) {
				const entry = this.active_formatting_elements[i];
				if (this.#isActiveFormattingMarker(entry)) {
					return false;
				}
				if (this.#formattingEndTagPrecedesParagraphCloseAfterSkippedEndTag(entry.tagName, skippedEndTagName)) {
					return true;
				}
			}

			return false;
		}

		#formattingEndTagPrecedesParagraphCloseAfterSkippedEndTag(tagName, skippedEndTagName) {
			const span = this.#currentRealTokenSpan();
			if (span === null) {
				return false;
			}

			let skipped = false;
			let at = span.start + span.length;
			while (true) {
				const nextTag = runtime.scanNextTag(this.html, at);
				if (nextTag === false) {
					return false;
				}

				if (nextTag.is_closing && nextTag.tag_name === tagName) {
					return true;
				}

				if (!skipped && nextTag.is_closing && nextTag.tag_name === skippedEndTagName) {
					skipped = true;
					at = nextTag.token_end;
					continue;
				}

				if (
					(nextTag.is_closing && nextTag.tag_name === "P") ||
					(!nextTag.is_closing && this.#shouldClosePForStartTag(nextTag.tag_name))
				) {
					return false;
				}

				if (tagName !== "A" && nextTag.is_closing) {
					return false;
				}

				at = nextTag.token_end;
			}
		}

		#formattingEndTagPrecedesParagraphClose(tagName) {
			const span = this.#currentRealTokenSpan();
			if (span === null) {
				return false;
			}

			let at = span.start + span.length;
			while (true) {
				const nextTag = runtime.scanNextTag(this.html, at);
				if (nextTag === false) {
					return false;
				}

				if (nextTag.is_closing && nextTag.tag_name === tagName) {
					return true;
				}

				if (
					(nextTag.is_closing && nextTag.tag_name === "P") ||
					(!nextTag.is_closing && this.#shouldClosePForStartTag(nextTag.tag_name))
				) {
					return false;
				}

				if (
					tagName === "FONT" &&
					nextTag.is_closing &&
					FONT_PARAGRAPH_ADOPTION_SKIPPABLE_END_TAGS.has(nextTag.tag_name)
				) {
					at = nextTag.token_end;
					continue;
				}

				if (tagName !== "A" && nextTag.is_closing) {
					return false;
				}

				if (!nextTag.is_closing) {
					if (tagName === "A" && nextTag.tag_name === "A") {
						return true;
					}
					if (
						tagName === "FONT" &&
						(
							FORMATTING_ELEMENTS.has(nextTag.tag_name) ||
							ACTIVE_FORMATTING_RECONSTRUCTING_START_TAGS.has(nextTag.tag_name) ||
							FONT_PARAGRAPH_ADOPTION_RECONSTRUCTING_START_TAGS.has(nextTag.tag_name)
						)
					) {
						at = nextTag.token_end;
						continue;
					}
					return false;
				}

				at = nextTag.token_end;
			}
		}

		#markParagraphAdoptionPreclosedFormattingElement(tagName, namespaceName, reconstructionMode = "self") {
			this.paragraph_adoption_preclosed_formatting_elements.push({ tagName, namespaceName, reconstructionMode });
		}

		#consumeParagraphAdoptionPreclosedFormattingElement(tagName, namespaceName) {
			for (let i = this.paragraph_adoption_preclosed_formatting_elements.length - 1; i >= 0; i -= 1) {
				const entry = this.paragraph_adoption_preclosed_formatting_elements[i];
				if (entry.tagName !== tagName || entry.namespaceName !== namespaceName) {
					continue;
				}

				this.paragraph_adoption_preclosed_formatting_elements.splice(i, 1);
				return entry;
			}

			return null;
		}

		#hasParagraphAdoptionPreclosedFormattingElement(tagName, namespaceName) {
			return this.paragraph_adoption_preclosed_formatting_elements.some((entry) => (
				entry.tagName === tagName && entry.namespaceName === namespaceName
			));
		}

		#clearParagraphAdoptionPreclosedFormattingElements(tagName, namespaceName) {
			this.paragraph_adoption_preclosed_formatting_elements = this.paragraph_adoption_preclosed_formatting_elements.filter((entry) => (
				entry.tagName !== tagName || entry.namespaceName !== namespaceName
			));
		}

		#markSpecialStartAdoptionPreclosedFormattingElement(tagName, namespaceName, containerTagName, reconstructionMode = "self") {
			this.special_start_adoption_preclosed_formatting_elements.push({ tagName, namespaceName, containerTagName, reconstructionMode });
		}

		#consumeSpecialStartAdoptionPreclosedFormattingElement(tagName, namespaceName, containerTagName) {
			for (let i = this.special_start_adoption_preclosed_formatting_elements.length - 1; i >= 0; i -= 1) {
				const entry = this.special_start_adoption_preclosed_formatting_elements[i];
				if (
					entry.tagName !== tagName ||
					entry.namespaceName !== namespaceName ||
					entry.containerTagName !== containerTagName
				) {
					continue;
				}

				this.special_start_adoption_preclosed_formatting_elements.splice(i, 1);
				return entry;
			}

			return null;
		}

		#hasSpecialStartAdoptionPreclosedFormattingElement(tagName, namespaceName, containerTagName, reconstructionMode = null) {
			return this.special_start_adoption_preclosed_formatting_elements.some((entry) => (
				entry.tagName === tagName &&
				entry.namespaceName === namespaceName &&
				entry.containerTagName === containerTagName &&
				(reconstructionMode === null || entry.reconstructionMode === reconstructionMode)
			));
		}

		#clearSpecialStartAdoptionPreclosedFormattingElements(tagName, namespaceName) {
			this.special_start_adoption_preclosed_formatting_elements = this.special_start_adoption_preclosed_formatting_elements.filter((entry) => (
				entry.tagName !== tagName || entry.namespaceName !== namespaceName
			));
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
			if (this.#shouldIgnoreDocumentStartTagInTemplateContent(tokenType, tagName, isCloser)) {
				if (this.#currentTemplateInsertionMode() === "in_template") {
					this.#setCurrentTemplateInsertionMode("in_body");
				}
				this.skip_current_token = true;
				return true;
			}

			if (this.#shouldIgnoreFrameStartTagInTemplateContent(tokenType, tagName, isCloser)) {
				if (this.#currentTemplateInsertionMode() === "in_template") {
					this.#setCurrentTemplateInsertionMode("in_body");
				}
				this.skip_current_token = true;
				return true;
			}

			if (this.#isInHeadTemplateContent()) {
				return false;
			}

			if (this.current_namespace !== "html") {
				return false;
			}

			const isWhitespaceText = (
				tokenType === "#text" &&
				this.text_node_classification === WP_HTML_Tag_Processor.TEXT_IS_WHITESPACE
			);
			const isNullText = (
				tokenType === "#text" &&
				this.text_node_classification === WP_HTML_Tag_Processor.TEXT_IS_NULL_SEQUENCE
			);
			const isIgnorablePreBodyText = isWhitespaceText || isNullText;

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
						if (tokenType === "#doctype" || isIgnorablePreBodyText) {
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
						if (tokenType === "#doctype" || isIgnorablePreBodyText) {
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

						if (tokenType === "#tag" && !isCloser && tagName === "NOSCRIPT") {
							this.full_parser_insertion_mode = "in_head_noscript";
							return false;
						}

						if (
							isIgnorablePreBodyText ||
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

					case "in_head_noscript":
						if (tokenType === "#doctype") {
							this.skip_current_token = true;
							return true;
						}

						if (
							isWhitespaceText ||
							tokenType === "#comment" ||
							tokenType === "#funky-comment" ||
							tokenType === "#presumptuous-tag" ||
							(tokenType === "#tag" && !isCloser && IN_HEAD_NOSCRIPT_ALLOWED_START_TAGS.has(tagName))
						) {
							return false;
						}

						if (tokenType === "#tag" && !isCloser && tagName === "HTML") {
							this.skip_current_token = true;
							return true;
						}

						if (isCloser && tagName === "NOSCRIPT") {
							this.full_parser_insertion_mode = "in_head";
							return false;
						}

						if (
							(tokenType === "#tag" && !isCloser && (tagName === "HEAD" || tagName === "NOSCRIPT")) ||
							(isCloser && tagName !== "BR")
						) {
							this.skip_current_token = true;
							return true;
						}

						this.full_parser_insertion_mode = "in_head";
						this.#queueVirtualPop("NOSCRIPT");
						return this.#reprocessCurrentTokenAfterVirtualTokens();

					case "after_head":
						if (tokenType === "#doctype") {
							this.skip_current_token = true;
							return true;
						}

						if (this.pre_frameset_ignored_element_depth > 0) {
							const skipped = this.#skipIgnoredElementBeforeFrameset(
								tokenType,
								tagName,
								isCloser,
								isIgnorablePreBodyText,
							);
							if (skipped) {
								return true;
							}
						}

						if (
							this.pre_frameset_paragraph_ignored &&
							isWhitespaceText &&
							this.#currentTokenPrecedesStartTag("FRAMESET")
						) {
							this.#ignoreCurrentToken();
							return true;
						}

						if (
							isIgnorablePreBodyText ||
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
							this.frameset_ok = false;
							this.full_parser_insertion_mode = "in_body";
							return false;
						}

						if (tokenType === "#tag" && !isCloser && tagName === "FRAMESET") {
							this.pre_frameset_paragraph_ignored = false;
							this.full_parser_insertion_mode = "in_frameset";
							return false;
						}

						if (tokenType === "#tag" && !isCloser && this.#hiddenInputPrecedesFrameset(tagName)) {
							this.#ignoreCurrentToken();
							return true;
						}

						if (tokenType === "#tag" && !isCloser && this.#ignoredStartTagPrecedesFrameset(tagName)) {
							this.#ignoreCurrentToken();
							return true;
						}

						if (tokenType === "#tag" && !isCloser && this.#ignoredFrameNoisePrecedesFrameset(tagName)) {
							this.#ignoreCurrentToken();
							return true;
						}

						if (tokenType === "#tag" && !isCloser && this.#openElementChainPrecedesFrameset(tagName)) {
							this.pre_frameset_paragraph_ignored = true;
							this.#ignoreCurrentToken();
							return true;
						}

						if (tokenType === "#tag" && !isCloser && this.#closedElementPrecedesFrameset(tagName)) {
							this.pre_frameset_ignored_element_depth = 1;
							this.#ignoreCurrentToken();
							return true;
						}

						if (tokenType === "#tag" && !isCloser && this.#paragraphPrecedesFrameset(tagName)) {
							this.pre_frameset_paragraph_ignored = true;
							this.#ignoreCurrentToken();
							return true;
						}

						if (tokenType === "#tag" && !isCloser && tagName === "TEMPLATE") {
							this.full_parser_insertion_mode = "in_head";
							this.#silentlyReopenFullParserElement("HEAD");
							return false;
						}

						if (tokenType === "#tag" && !isCloser && AFTER_HEAD_TEMPORARY_HEAD_START_TAGS.has(tagName)) {
							this.full_parser_insertion_mode = "in_head";
							this.#silentlyReopenFullParserElement("HEAD");
							this.temporary_reopened_head = true;
							return false;
						}

						if (tokenType === "#tag" && !isCloser && HEAD_CONTENT_ELEMENTS.has(tagName)) {
							this.full_parser_insertion_mode = "in_head";
							this.#silentlyReopenFullParserElement("HEAD");
							return false;
						}

						if (tokenType === "#tag" && !isCloser && tagName === "HEAD") {
							this.skip_current_token = true;
							return true;
						}

						if (
							isCloser &&
							(tagName === "BODY" || tagName === "HTML") &&
							this.#currentTokenPrecedesStartTag("FRAMESET")
						) {
							this.skip_current_token = true;
							return true;
						}

						if (isCloser && tagName !== "BODY" && tagName !== "HTML") {
							this.skip_current_token = true;
							return true;
						}

						this.full_parser_insertion_mode = "in_body";
						this.pre_frameset_paragraph_ignored = false;
						this.#queueVirtualPush("BODY");
						return this.#reprocessCurrentTokenAfterVirtualTokens();

					case "in_body":
						if (tokenType === "#doctype") {
							this.skip_current_token = true;
							return true;
						}

						if (tokenType === "#tag" && !isCloser && (tagName === "HTML" || tagName === "BODY")) {
							if (tagName === "BODY") {
								this.frameset_ok = false;
							}
							this.skip_current_token = true;
							return true;
						}

						if (
							tokenType === "#tag" &&
							!isCloser &&
							!this.preserve_in_body_ignored_start_tags &&
							this.template_insertion_modes.length === 0 &&
							!this.#isInTableInsertionContext() &&
							IN_BODY_IGNORED_START_TAGS.has(tagName)
						) {
							this.skip_current_token = true;
							return true;
						}

						if (tokenType === "#tag" && !isCloser && tagName === "FRAMESET") {
							if (this.open_elements.length <= 1 || this.open_elements[1] !== "BODY" || !this.frameset_ok) {
								this.skip_current_token = true;
								return true;
							}

							this.#bailUnsupported("Cannot process non-ignored FRAMESET tags.");
							return true;
						}

						if (isCloser && tagName === "BODY") {
							this.full_parser_insertion_mode = "after_body";
							this.skip_current_token = true;
							return true;
						}

						if (isCloser && tagName === "HTML") {
							this.full_parser_insertion_mode = "after_after_body";
							this.skip_current_token = true;
							return true;
						}

						if (tokenType === "#text" && !isWhitespaceText && !isNullText) {
							this.frameset_ok = false;
						} else if (tokenType === "#tag" && !isCloser && this.#startTagClearsFramesetOk(tagName)) {
							this.frameset_ok = false;
						}

						return false;

					case "after_body":
						if (
							tokenType === "#comment" ||
							tokenType === "#funky-comment" ||
							tokenType === "#presumptuous-tag"
						) {
							if (this.open_elements.length > 1) {
								this.#queueVirtualPopsFrom(1);
								this.pending_real_token = true;
								this.pending_real_parser_state = this.parser_state;
								return true;
							}
							return false;
						}

						if (tokenType === "#doctype") {
							this.skip_current_token = true;
							return true;
						}

						if (tokenType === "#tag" && !isCloser && tagName === "HTML") {
							this.full_parser_insertion_mode = "in_body";
							continue;
						}

						if (isCloser && tagName === "HTML") {
							this.full_parser_insertion_mode = "after_after_body";
							this.skip_current_token = true;
							return true;
						}

						if (isWhitespaceText) {
							return false;
						}

						if (tokenType === "#tag" && !isCloser && tagName === "TEMPLATE") {
							this.full_parser_insertion_mode = "in_body";
							this.#silentlyReopenFullParserElement("BODY");
							return false;
						}

						this.full_parser_insertion_mode = "in_body";
						continue;

					case "after_after_body":
						if (
							tokenType === "#comment" ||
							tokenType === "#funky-comment" ||
							tokenType === "#presumptuous-tag"
						) {
							if (this.open_elements.length > 0) {
								this.#queueVirtualPopsFrom(0);
								this.pending_real_token = true;
								this.pending_real_parser_state = this.parser_state;
								return true;
							}
							return false;
						}

						if (tokenType === "#doctype" || (tokenType === "#tag" && !isCloser && tagName === "HTML")) {
							this.full_parser_insertion_mode = "in_body";
							continue;
						}

						if (isWhitespaceText) {
							return false;
						}

						this.full_parser_insertion_mode = "in_body";
						continue;

					case "in_frameset":
						if (tokenType === "#text") {
							if (isWhitespaceText) {
								return false;
							}
							return this.#filterFramesetTextToken();
						}

						if (
							tokenType === "#comment" ||
							tokenType === "#funky-comment" ||
							tokenType === "#presumptuous-tag"
						) {
							return false;
						}

						if (tokenType === "#doctype") {
							this.skip_current_token = true;
							return true;
						}

						if (tokenType === "#tag" && !isCloser && tagName === "HTML") {
							this.full_parser_insertion_mode = "in_body";
							continue;
						}

						if (tokenType === "#tag" && !isCloser && tagName === "FRAMESET") {
							return false;
						}

						if (isCloser && tagName === "FRAMESET") {
							const topIndex = this.open_elements.length - 1;
							if (this.open_elements[topIndex] === "HTML") {
								this.skip_current_token = true;
								return true;
							}
							if (this.open_elements[topIndex - 1] !== "FRAMESET") {
								this.full_parser_insertion_mode = "after_frameset";
							}
							return false;
						}

						if (tokenType === "#tag" && !isCloser && tagName === "FRAME") {
							return false;
						}

						if (tokenType === "#tag" && !isCloser && tagName === "NOFRAMES") {
							return false;
						}

						this.skip_current_token = true;
						return true;

					case "after_frameset":
						if (tokenType === "#text") {
							if (isWhitespaceText) {
								return false;
							}
							return this.#filterFramesetTextToken();
						}

						if (
							tokenType === "#comment" ||
							tokenType === "#funky-comment" ||
							tokenType === "#presumptuous-tag"
						) {
							return false;
						}

						if (tokenType === "#doctype") {
							this.skip_current_token = true;
							return true;
						}

						if (tokenType === "#tag" && !isCloser && tagName === "HTML") {
							this.full_parser_insertion_mode = "in_body";
							continue;
						}

						if (isCloser && tagName === "HTML") {
							this.full_parser_insertion_mode = "after_after_frameset";
							this.skip_current_token = true;
							return true;
						}

						if (tokenType === "#tag" && !isCloser && tagName === "NOFRAMES") {
							return false;
						}

						this.skip_current_token = true;
						return true;

					case "after_after_frameset":
						if (
							tokenType === "#comment" ||
							tokenType === "#funky-comment" ||
							tokenType === "#presumptuous-tag"
						) {
							if (this.#hasFutureNoframesStartTag()) {
								if (tokenType === "#comment") {
									return this.#delayCurrentCommentToken(["#comment"]);
								}

								this.#bailUnsupported("Content outside of HTML is unsupported.");
								return true;
							}
							if (this.open_elements.length > 0) {
								this.#queueVirtualPopsFrom(0);
								this.pending_real_token = true;
								this.pending_real_parser_state = this.parser_state;
								return true;
							}
							return false;
						}

						if (tokenType === "#doctype" || (tokenType === "#tag" && !isCloser && tagName === "HTML")) {
							this.full_parser_insertion_mode = "in_body";
							continue;
						}

						if (tokenType === "#text") {
							if (isWhitespaceText) {
								return false;
							}
							return this.#filterFramesetTextToken();
						}

						if (tokenType === "#tag" && !isCloser && tagName === "NOFRAMES") {
							return false;
						}

						this.skip_current_token = true;
						return true;

					default:
						return false;
				}
			}
		}

		#filterFramesetTextToken() {
			const text = this.get_modifiable_text() ?? "";
			let whitespace = "";
			for (let i = 0; i < text.length; i += 1) {
				if (isHtmlWhitespaceCode(text.charCodeAt(i))) {
					whitespace += text[i];
				}
			}

			if (whitespace === "") {
				this.skip_current_token = true;
				this.breadcrumbs = this.#breadcrumbStack();
				return true;
			}

			this.current_synthetic_token = {
				tokenType: "#text",
				tokenName: "#text",
				modifiableText: whitespace,
				readOnly: true,
			};
			this.parser_state = STATE_TEXT_NODE;
			this.text_node_classification = WP_HTML_Tag_Processor.TEXT_IS_WHITESPACE;
			this.current_token_namespace = "html";
			this.breadcrumbs = this.#breadcrumbStack("#text");
			this.skip_current_token = false;
			return true;
		}

		#queueVirtualPush(
			tagName,
			namespaceName = "html",
			attributes = [],
			integrationNodeType = null,
			fosterParentedTableIndex = this.#currentFosterParentedTableIndex(),
		) {
			this.virtual_tokens.push({
				operation: "push",
				tagName,
				namespaceName,
				integrationNodeType,
				fosterParentedTableIndex,
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
				if (this.#isActiveFormattingMarker(this.active_formatting_elements[i])) {
					break;
				}

				if (this.active_formatting_elements[i].tagName === tagName) {
					this.active_formatting_elements.splice(i, 1);
					this.#clearParagraphAdoptionPreclosedFormattingElements(tagName, "html");
					this.#clearSpecialStartAdoptionPreclosedFormattingElements(tagName, "html");
					return true;
				}
			}
			return false;
		}

		#removeStaleActiveFormattingElementsForClose(tagName) {
			let openCount = this.#countOpenHtmlElements(tagName);
			let activeCount = 0;
			for (let i = this.active_formatting_elements.length - 1; i >= 0; i -= 1) {
				const entry = this.active_formatting_elements[i];
				if (this.#isActiveFormattingMarker(entry)) {
					break;
				}
				if (entry.tagName === tagName && entry.namespaceName === "html") {
					activeCount += 1;
				}
			}
			let removed = false;

			for (let i = this.active_formatting_elements.length - 1; i >= 0 && activeCount > openCount; i -= 1) {
				const entry = this.active_formatting_elements[i];
				if (this.#isActiveFormattingMarker(entry)) {
					break;
				}
				if (entry.tagName === tagName && entry.namespaceName === "html") {
					this.active_formatting_elements.splice(i, 1);
					this.#clearParagraphAdoptionPreclosedFormattingElements(tagName, "html");
					this.#clearSpecialStartAdoptionPreclosedFormattingElements(tagName, "html");
					activeCount -= 1;
					removed = true;
				}
			}

			return removed;
		}

		#removeActiveFormattingElementsForClose(tagName) {
			this.#removeStaleActiveFormattingElementsForClose(tagName);
			return this.#removeActiveFormattingElement(tagName);
		}

		#clearActiveFormattingElementsForTemplateClose(templateIndex) {
			const closedTemplateDepth = this.#countOpenHtmlElements("TEMPLATE", templateIndex + 1);
			this.active_formatting_elements = this.active_formatting_elements.filter((entry) => (
				(entry.templateDepth ?? 0) < closedTemplateDepth
			));
		}

		#applyTemplateInsertionModeForStartTag(tagName) {
			if (
				this.current_namespace !== "html" ||
				this.template_insertion_modes.length === 0 ||
				tagName === "TEMPLATE"
			) {
				return false;
			}

			const mode = this.#currentTemplateInsertionMode();
			if (mode === "in_template") {
				if (TEMPLATE_HEAD_START_TAGS.has(tagName)) {
					return false;
				}

				if (TEMPLATE_TABLE_WRAPPER_START_TAGS.has(tagName)) {
					this.#setCurrentTemplateInsertionMode("in_table");
					return this.#applyTemplateInsertionModeForStartTag(tagName);
				}

				if (tagName === "COL") {
					this.#setCurrentTemplateInsertionMode("in_column_group");
					return this.#applyTemplateInsertionModeForStartTag(tagName);
				}

				if (tagName === "TR") {
					this.#setCurrentTemplateInsertionMode("in_table_body");
					return this.#applyTemplateInsertionModeForStartTag(tagName);
				}

				if (TABLE_CELL_ELEMENTS.has(tagName)) {
					this.#setCurrentTemplateInsertionMode("in_row");
					return this.#applyTemplateInsertionModeForStartTag(tagName);
				}

				this.#setCurrentTemplateInsertionMode("in_body");
				return this.#applyTemplateInsertionModeForStartTag(tagName);
			}

			if (mode === "in_column_group") {
				if (tagName === "COL") {
					return false;
				}

				this.#ignoreCurrentToken();
				return true;
			}

			if (mode === "in_table") {
				if (tagName === "COL") {
					this.#setCurrentTemplateInsertionMode("in_column_group");
					this.#queueVirtualPush("COLGROUP");
					return this.#reprocessCurrentTokenAfterVirtualTokens();
				}

				if (tagName === "TR" || TABLE_CELL_ELEMENTS.has(tagName)) {
					this.#setCurrentTemplateInsertionMode("in_table_body");
					this.#queueVirtualPush("TBODY");
					return this.#reprocessCurrentTokenAfterVirtualTokens();
				}

				if (TABLE_SECTION_ELEMENTS.has(tagName)) {
					this.#setCurrentTemplateInsertionMode("in_table_body");
				}
				return false;
			}

			if (mode === "in_table_body") {
				if (TEMPLATE_TABLE_WRAPPER_START_TAGS.has(tagName)) {
					const topIndex = this.open_elements.length - 1;
					if (
						topIndex >= 0 &&
						this.open_element_namespaces[topIndex] === "html" &&
						TABLE_SECTION_ELEMENTS.has(this.open_elements[topIndex])
					) {
						this.#setCurrentTemplateInsertionMode("in_table");
						this.#queueVirtualPopsFrom(topIndex);
						return this.#reprocessCurrentTokenAfterVirtualTokens();
					}

					this.#ignoreCurrentToken();
					return true;
				}

				if (tagName === "SELECT") {
					const topIndex = this.open_elements.length - 1;
					if (
						topIndex >= 0 &&
						this.open_element_namespaces[topIndex] === "html" &&
						TABLE_SECTION_ELEMENTS.has(this.open_elements[topIndex])
					) {
						this.#setCurrentTemplateInsertionMode("in_table");
						this.#queueVirtualPopsFrom(topIndex);
						return this.#reprocessCurrentTokenAfterVirtualTokens();
					}
				}

				if (TABLE_CELL_ELEMENTS.has(tagName)) {
					this.#setCurrentTemplateInsertionMode("in_row");
					this.#queueVirtualPush("TR");
					return this.#reprocessCurrentTokenAfterVirtualTokens();
				}

				if (tagName === "TR") {
					this.#setCurrentTemplateInsertionMode("in_row");
				}
				return false;
			}

			if (mode === "in_row") {
				if (TEMPLATE_TABLE_WRAPPER_START_TAGS.has(tagName)) {
					this.#ignoreCurrentToken();
					return true;
				}
				if (tagName === "DIV" && this.#currentHtmlElementIs("TR")) {
					this.#setCurrentTemplateInsertionMode("in_table_body");
					this.#queueVirtualPopsFrom(this.open_elements.length - 1);
					return this.#reprocessCurrentTokenAfterVirtualTokens();
				}
				return false;
			}

			if (mode !== "in_body") {
				return false;
			}

			if (IN_BODY_IGNORED_START_TAGS.has(tagName)) {
				this.#ignoreCurrentToken();
				return true;
			}

			return false;
		}

		#applyTemplateInsertionModeForEndTag(tagName) {
			if (this.template_insertion_modes.length === 0) {
				return;
			}

			if (tagName === "TR") {
				this.#setCurrentTemplateInsertionMode("in_table_body");
			} else if (TABLE_SECTION_ELEMENTS.has(tagName)) {
				this.#setCurrentTemplateInsertionMode("in_table");
			} else if (tagName === "CAPTION") {
				this.#setCurrentTemplateInsertionMode("in_table");
			}
		}

		#shouldIgnoreTextInColumnGroup(tokenType) {
			return (
				tokenType === "#text" &&
				(
					(
						this.template_insertion_modes.length > 0 &&
						this.#currentTemplateInsertionMode() === "in_column_group"
					) ||
					(
						!this.is_full_parser &&
						this.context_namespace === "html" &&
						this.context_node === "COLGROUP" &&
						this.#currentHtmlElementIs("COLGROUP")
					)
				)
			);
		}

		#currentTemplateInsertionMode() {
			return this.template_insertion_modes[this.template_insertion_modes.length - 1] ?? "in_body";
		}

		#setCurrentTemplateInsertionMode(mode) {
			if (this.template_insertion_modes.length > 0) {
				this.template_insertion_modes[this.template_insertion_modes.length - 1] = mode;
			}
		}

		#popTemplateInsertionMode() {
			if (this.template_insertion_modes.length > 0) {
				this.template_insertion_modes.pop();
			}
		}

		#ignoreCurrentToken() {
			this.current_token_namespace = this.current_namespace;
			this.breadcrumbs = this.#breadcrumbStack();
			this.skip_current_token = true;
			this.#setCurrentNamespace(this.#namespaceForStackTop());
		}

		#bailUnsupported(message) {
			this.last_error = WP_HTML_Processor.ERROR_UNSUPPORTED;
			this.unsupported_exception = this.#createUnsupportedException(message);
			this.virtual_tokens = [];
			this.pending_real_token = false;
			this.pending_real_parser_state = null;
			this.skip_current_token = true;
		}

		#createUnsupportedException(message) {
			const span = this.is_virtual() ? null : this.#currentRealTokenSpan();
			return new WP_HTML_Unsupported_Exception(
				message,
				this.get_token_name() ?? "",
				span?.start ?? 0,
				this.is_virtual() ? "" : this.#currentRealTokenString(),
				this.open_elements,
				this.active_formatting_elements
					.filter((entry) => !this.#isActiveFormattingMarker(entry))
					.map((entry) => entry.tagName),
			);
		}

		#currentRealTokenSpan() {
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

		#currentRealTokenString() {
			const span = this.#currentRealTokenSpan();
			if (span === null) {
				return "";
			}

			const html = runtime.readOutputBytes((out) => (
				wasm.wp_html_api_rust_tag_processor_get_html(this.pointer, out)
			));
			return html === null ? "" : textDecoder.decode(html.slice(span.start, span.start + span.length));
		}

		#currentSpecialAtomicText(tagName) {
			const tokenMarkup = this.#currentRealTokenString();
			const startTag = completeStartTagAt(tokenMarkup, 0);
			if (startTag === null) {
				return this.get_modifiable_text() ?? "";
			}

			const closerEnd = findSpecialAtomicCloserEnd(tokenMarkup, startTag.end, tagName);
			if (closerEnd === null) {
				return this.get_modifiable_text() ?? "";
			}

			const closerStart = tokenMarkup.lastIndexOf("</", closerEnd - 1);
			if (closerStart < startTag.end) {
				return this.get_modifiable_text() ?? "";
			}

			return replaceNulls(tokenMarkup.slice(startTag.end, closerStart));
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

		#unsupportedEncodingMetaMessage() {
			if (typeof this.get_attribute("charset") === "string") {
				return "Cannot yet process META tags with charset to determine encoding.";
			}

			const httpEquiv = this.get_attribute("http-equiv");
			const content = this.get_attribute("content");
			if (
				typeof httpEquiv === "string" &&
				typeof content === "string" &&
				httpEquiv.toLowerCase() === "content-type"
			) {
				return "Cannot yet process META tags with http-equiv Content-Type to determine encoding.";
			}

			return null;
		}

		#queueVirtualPreclosuresForStartTag(tagName) {
			if (this.#queueForeignContentBreakoutForStartTag(tagName)) {
				return true;
			}

			if (this.current_namespace !== "html") {
				return false;
			}

			if (this.#queueFosteredFragmentFormattingElementPreclosureForTableStartTag(tagName)) {
				return true;
			}

			if (tagName === "NOBR" && this.#currentHtmlElementIs("NOBR")) {
				this.#removeActiveFormattingElementsForClose("NOBR");
				this.#queueVirtualPopsFrom(this.open_elements.length - 1);
				return true;
			}

			if (CAPTION_CLOSING_START_TAGS.has(tagName)) {
				const captionIndex = this.#findElementInTableScope("CAPTION");
				if (captionIndex !== -1) {
					this.#queueVirtualPopsFrom(captionIndex);
					return true;
				}
			}

			if (COLGROUP_CLOSING_START_TAGS.has(tagName) && this.#currentHtmlElementIs("COLGROUP")) {
				this.#queueVirtualPopsFrom(this.open_elements.length - 1);
				return true;
			}

			if (this.current_namespace === "html" && SELECT_IN_TABLE_BREAKOUT_TAGS.has(tagName)) {
				const selectIndex = this.#lastOpenElementIndex("SELECT", "html");
				if (selectIndex !== -1 && this.#openHtmlElementBefore("TABLE", selectIndex)) {
					this.#queueVirtualPopsFrom(selectIndex);
					return true;
				}
			}

			if (this.#queueForeignIntegrationPointTableBreakoutForStartTag(tagName)) {
				return true;
			}

			if (tagName === "TABLE") {
				const tableIndex = this.#tableStartTagPreclosureIndex();
				if (tableIndex !== -1) {
					this.#queueVirtualPopsFrom(tableIndex);
					return true;
				}
			}

			if (this.current_namespace === "html" && SELECT_BREAKOUT_START_TAGS.has(tagName)) {
				const selectIndex = this.#lastOpenElementIndex("SELECT", "html");
				if (selectIndex !== -1 && selectIndex >= this.base_open_element_count) {
					this.#queueVirtualPopsFrom(selectIndex);
					return true;
				}
			}

			if (
				this.current_namespace === "html" &&
				(
					tagName === "OPTION" ||
					tagName === "OPTGROUP" ||
					(tagName === "HR" && this.#hasOpenHtmlElement("SELECT"))
				)
			) {
				const topIndex = this.open_elements.length - 1;
				if (
					topIndex >= 0 &&
					this.open_elements[topIndex] === "OPTION" &&
					this.open_element_namespaces[topIndex] === "html"
				) {
					this.#queueVirtualPopsFrom(topIndex);
					return true;
				}

				if (
					(tagName === "OPTGROUP" || tagName === "HR") &&
					topIndex >= 0 &&
					this.open_elements[topIndex] === "OPTGROUP" &&
					this.open_element_namespaces[topIndex] === "html" &&
					this.#hasOpenHtmlElement("SELECT")
				) {
					this.#queueVirtualPopsFrom(topIndex);
					return true;
				}
			}

			if (
				this.current_namespace === "html" &&
				tagName === "FORM" &&
				(
					this.#hasOpenHtmlElement("TEMPLATE") ||
					!this.#hasOpenHtmlElement("FORM")
				)
			) {
				const paragraphIndex = this.#findOpenElementBeforeBoundary("P", BUTTON_SCOPE_BOUNDARIES);
				if (paragraphIndex !== -1 && !this.#hasForeignIntegrationPointAfter(paragraphIndex)) {
					this.#queueVirtualPopsFrom(paragraphIndex);
					return true;
				}
			}

			const paragraphIndex = this.#findClosablePInButtonScopeForStartTag(tagName);
			if (paragraphIndex !== -1) {
				this.#queueVirtualPopsFrom(paragraphIndex);
				return true;
			}

			if (
				RUBY_IMPLIED_END_TAG_START_TAGS.has(tagName) &&
				this.#hasOpenHtmlElement("RUBY") &&
				this.#currentHtmlElementHasRubyImpliedEndTagForStartTag(tagName)
			) {
				this.#queueVirtualPopsFrom(this.open_elements.length - 1);
				return true;
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

			if (tagName === "A" || tagName === "NOBR") {
				const formattingElementIndex = this.#lastOpenElementIndex(tagName, "html");
				if (
					tagName === "A" &&
					formattingElementIndex === -1 &&
					this.#lastActiveFormattingElementIndex("A") !== -1 &&
					this.open_elements.at(-1) === "DIV" &&
					this.pending_nested_anchor_div_active_removal_after_deferred_table
				) {
					this.pending_nested_anchor_div_active_removal_after_deferred_table = false;
					this.#removeActiveFormattingElement("A");
					return false;
				}
				if (formattingElementIndex !== -1) {
					const activeFormattingElementIndex = this.#lastActiveFormattingElementIndex(tagName);
					if (
						tagName === "A" &&
						activeFormattingElementIndex === -1
					) {
						return false;
					}
					if (this.#canRepresentNestedAnchorFosteredBeforeDeferredTable(tagName)) {
						this.pending_nested_anchor_outer_closer_after_deferred_table_index = formattingElementIndex;
						this.pending_nested_anchor_active_removal_after_deferred_table = this.#shouldRemoveNestedAnchorActiveAfterDeferredTable();
						this.#removeActiveFormattingElement(tagName);
						return false;
					}
					if (
						tagName === "NOBR" &&
						this.#lastOpenElementIndex("TABLE", "html") > formattingElementIndex
					) {
						return false;
					}
					if (
						(
							tagName === "NOBR" &&
							activeFormattingElementIndex !== -1 &&
							this.#hasOpenActiveFormattingElementAfterIndex(activeFormattingElementIndex)
						) ||
						hasSpecialBoundaryAfter(this.open_elements, this.open_element_namespaces, formattingElementIndex)
					) {
						this.#bailUnsupported(
							tagName === "A"
								? "Cannot process nested A elements which require adoption agency reconstruction."
								: "Cannot process nested NOBR elements which require adoption agency reconstruction.",
						);
						return true;
					}

					this.#queueVirtualPopsFrom(formattingElementIndex);
					this.#removeActiveFormattingElement(tagName);
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

		#queueForeignIntegrationPointTableBreakoutForStartTag(tagName) {
			if (
				this.current_namespace !== "html" ||
				tagName !== "TABLE"
			) {
				return false;
			}

			const tableIndex = this.#lastOpenElementIndex("TABLE", "html");
			if (tableIndex === -1) {
				return false;
			}

			for (let i = tableIndex + 1; i < this.open_elements.length; i += 1) {
				if (
					this.open_element_namespaces[i] !== "html" &&
					this.open_element_integration_node_types[i] !== null
				) {
					this.#queueVirtualPopsFrom(i);
					return true;
				}
			}

			return false;
		}

		#queueForeignContentBreakoutForStartTag(tagName) {
			if (
				this.current_namespace === "html" ||
				(
					!FOREIGN_CONTENT_HTML_BREAKOUT_START_TAGS.has(tagName) &&
					!this.#isFontBreakoutStartTag(tagName)
				)
			) {
				return false;
			}

			const firstForeignIndex = this.#firstForeignElementToPopForHtmlBreakout();
			if (firstForeignIndex === -1) {
				return false;
			}

			this.#queueVirtualPopsFrom(firstForeignIndex);
			return true;
		}

		#isFontBreakoutStartTag(tagName) {
			return (
				tagName === "FONT" &&
				(
					this.get_attribute("color") !== null ||
					this.get_attribute("face") !== null ||
					this.get_attribute("size") !== null
				)
			);
		}

		#isHiddenInputStartTag(tagName) {
			if (tagName !== "INPUT") {
				return false;
			}

			const typeAttribute = this.get_attribute("type");
			return typeof typeAttribute === "string" && typeAttribute.toLowerCase() === "hidden";
		}

		#hiddenInputPrecedesFrameset(tagName) {
			if (!this.#isHiddenInputStartTag(tagName)) {
				return false;
			}

			return this.#currentTokenPrecedesStartTag("FRAMESET");
		}

		#ignoredStartTagPrecedesFrameset(tagName) {
			return AFTER_HEAD_FRAMESET_IGNORED_START_TAGS.has(tagName) &&
				this.#currentTokenPrecedesStartTag("FRAMESET");
		}

		#ignoredFrameNoisePrecedesFrameset(tagName) {
			if (tagName !== "FRAME") {
				return false;
			}

			const span = this.#currentRealTokenSpan();
			if (span === null) {
				return false;
			}

			let at = span.start + span.length;
			while (true) {
				const nextTag = runtime.scanNextTag(this.html, at);
				if (nextTag === false) {
					return false;
				}

				if (!this.#isWhitespacePreFramesetText(this.html.slice(at, nextTag.tag_start))) {
					return false;
				}

				if (!nextTag.is_closing) {
					return nextTag.tag_name === "FRAMESET";
				}

				if (nextTag.tag_name !== "FRAME") {
					return false;
				}

				at = nextTag.token_end;
			}
		}

		#closedElementPrecedesFrameset(tagName) {
			if (!AFTER_HEAD_FRAMESET_IGNORED_CLOSED_START_TAGS.has(tagName)) {
				return false;
			}

			const span = this.#currentRealTokenSpan();
			if (span === null) {
				return false;
			}

			let depth = 1;
			let at = span.start + span.length;
			while (true) {
				const nextTag = runtime.scanNextTag(this.html, at);
				if (nextTag === false) {
					return false;
				}

				if (!this.#isIgnorablePreFramesetText(this.html.slice(at, nextTag.tag_start))) {
					return false;
				}

				if (nextTag.is_closing) {
					depth -= 1;
					if (depth === 0) {
						const followingTag = runtime.scanNextTag(this.html, nextTag.token_end);
						return followingTag !== false &&
							!followingTag.is_closing &&
							followingTag.tag_name === "FRAMESET" &&
							this.#isIgnorablePreFramesetText(this.html.slice(nextTag.token_end, followingTag.tag_start));
					}
				} else if (!VOID_ELEMENTS.has(nextTag.tag_name) && !nextTag.has_self_closing_flag) {
					depth += 1;
				}

				at = nextTag.token_end;
			}
		}

		#openElementChainPrecedesFrameset(tagName) {
			if (!AFTER_HEAD_FRAMESET_IGNORED_OPEN_START_TAGS.has(tagName)) {
				return false;
			}

			const span = this.#currentRealTokenSpan();
			if (span === null) {
				return false;
			}

			let at = span.start + span.length;
			while (true) {
				const nextTag = runtime.scanNextTag(this.html, at);
				if (nextTag === false) {
					return false;
				}

				if (!this.#isWhitespacePreFramesetText(this.html.slice(at, nextTag.tag_start))) {
					return false;
				}

				if (nextTag.is_closing) {
					return false;
				}

				if (nextTag.tag_name === "FRAMESET") {
					return true;
				}

				if (!AFTER_HEAD_FRAMESET_IGNORED_OPEN_START_TAGS.has(nextTag.tag_name)) {
					return false;
				}

				at = nextTag.token_end;
			}
		}

		#skipIgnoredElementBeforeFrameset(tokenType, tagName, isCloser, isIgnorablePreBodyText) {
			if (tokenType === "#text") {
				if (!isIgnorablePreBodyText) {
					this.pre_frameset_ignored_element_depth = 0;
					return false;
				}

				this.#ignoreCurrentToken();
				return true;
			}

			if (tokenType !== "#tag") {
				this.pre_frameset_ignored_element_depth = 0;
				return false;
			}

			if (isCloser) {
				this.pre_frameset_ignored_element_depth -= 1;
			} else if (!VOID_ELEMENTS.has(tagName) && !this.has_self_closing_flag()) {
				this.pre_frameset_ignored_element_depth += 1;
			}

			this.#ignoreCurrentToken();
			return true;
		}

		#isIgnorablePreFramesetText(text) {
			return text.split("").every((char) => {
				const code = char.charCodeAt(0);
				return code === 0 || isHtmlWhitespaceCode(code);
			});
		}

		#isWhitespacePreFramesetText(text) {
			return text.split("").every((char) => isHtmlWhitespaceCode(char.charCodeAt(0)));
		}

		#paragraphPrecedesFrameset(tagName) {
			return tagName === "P" && this.#currentTokenPrecedesStartTag("FRAMESET");
		}

		#currentTokenPrecedesStartTag(tagName) {
			const span = this.#currentRealTokenSpan();
			if (span === null) {
				return false;
			}

			const afterToken = span.start + span.length;
			const nextTag = runtime.scanNextTag(this.html, afterToken);
			if (
				nextTag === false ||
				nextTag.is_closing ||
				nextTag.tag_name !== tagName
			) {
				return false;
			}

			return this.html.slice(afterToken, nextTag.tag_start).split("").every((char) => (
				isHtmlWhitespaceCode(char.charCodeAt(0))
			));
		}

		#startTagClearsFramesetOk(tagName) {
			if (tagName === "INPUT") {
				return !this.#isHiddenInputStartTag(tagName);
			}

			return FRAMESET_NOT_OK_START_TAGS.has(tagName);
		}

		#firstForeignElementToPopForHtmlBreakout() {
			for (let i = this.open_elements.length - 1; i >= 0; i -= 1) {
				const namespaceName = this.open_element_namespaces[i];
				if (namespaceName === "html" || this.open_element_integration_node_types[i] !== null) {
					return i + 1;
				}
			}

			return 0;
		}

		#lastActiveFormattingElementIndex(tagName) {
			for (let i = this.active_formatting_elements.length - 1; i >= 0; i -= 1) {
				if (this.#isActiveFormattingMarker(this.active_formatting_elements[i])) {
					break;
				}

				if (this.active_formatting_elements[i].tagName === tagName) {
					return i;
				}
			}
			return -1;
		}

		#hasOpenActiveFormattingElementAfterIndex(index) {
			for (let i = index + 1; i < this.active_formatting_elements.length; i += 1) {
				const entry = this.active_formatting_elements[i];
				if (this.#isActiveFormattingMarker(entry)) {
					return false;
				}
				if (this.#lastOpenElementIndex(entry.tagName, entry.namespaceName) !== -1) {
					return true;
				}
			}

			return false;
		}

		#queueVirtualPreclosuresForEndTag(tagName) {
			if (
				(
					tagName === "BR" ||
					tagName === "P" ||
					this.#hasOpenHtmlElementBeforeForeignBreakout(tagName)
				) &&
				this.#queueForeignContentBreakoutForEndTag()
			) {
				return true;
			}

			if (!this.#hasElementInTableScope("TABLE")) {
				return false;
			}

			if (
				this.#currentHtmlElementIs("COLGROUP") &&
				tagName !== "COL" &&
				tagName !== "COLGROUP" &&
				tagName !== "TEMPLATE"
			) {
				this.#queueVirtualPopsFrom(this.open_elements.length - 1);
				return true;
			}

			if (tagName === "TABLE") {
				const captionIndex = this.#findElementInTableScope("CAPTION");
				if (captionIndex !== -1) {
					this.#queueVirtualPopsFrom(captionIndex);
					return true;
				}
			}

			if (SELECT_IN_TABLE_BREAKOUT_TAGS.has(tagName) && this.#hasElementInTableScope(tagName)) {
				const selectIndex = this.#lastOpenElementIndex("SELECT", "html");
				if (selectIndex !== -1 && this.#openHtmlElementBefore("TABLE", selectIndex)) {
					this.#queueVirtualPopsFrom(selectIndex);
					return true;
				}
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

		#queueForeignContentBreakoutForEndTag() {
			if (this.current_namespace === "html") {
				return false;
			}

			const firstForeignIndex = this.#firstForeignElementToPopForHtmlBreakout();
			if (firstForeignIndex === -1) {
				return false;
			}

			this.#queueVirtualPopsFrom(firstForeignIndex);
			return true;
		}

		#hasOpenHtmlElementBeforeForeignBreakout(tagName) {
			if (this.current_namespace === "html") {
				return false;
			}

			const firstForeignIndex = this.#firstForeignElementToPopForHtmlBreakout();
			const parentIndex = firstForeignIndex - 1;
			return (
				parentIndex >= 0 &&
				this.open_elements[parentIndex] === tagName &&
				this.open_element_namespaces[parentIndex] === "html"
			);
		}

		#queueVirtualOpenersForStartTag(tagName) {
			if (
				this.current_namespace !== "html" ||
				(
					!this.#hasElementInTableScope("TABLE") &&
					!this.#isOpenTableSectionFragmentContext()
				)
			) {
				return false;
			}

			const queued = [];
			if (tagName === "COL" && this.#currentHtmlElementIs("TABLE")) {
				queued.push("COLGROUP");
			}

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

			if (this.deferred_table_opener !== null) {
				for (const queuedTagName of queued) {
					this.#deferImplicitTableWrapperOpen(queuedTagName);
				}
				return false;
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

		#isOpenTableSectionFragmentContext() {
			return (
				!this.is_full_parser &&
				this.context_namespace === "html" &&
				TABLE_SECTION_ELEMENTS.has(this.context_node) &&
				this.#currentHtmlElementIs(this.context_node)
			);
		}

		#deferImplicitTableWrapperOpen(tagName) {
			this.open_elements.push(tagName);
			this.open_element_namespaces.push("html");
			this.open_element_integration_node_types.push(null);
			this.open_element_foster_parented_table_indices.push(this.#currentFosterParentedTableIndex());
			this.breadcrumbs = this.#breadcrumbStack();
			this.deferred_table_child_openers.push({
				tokenType: "#tag",
				tokenName: tagName,
				tagName,
				namespaceName: "html",
				attributes: [],
				breadcrumbs: [...this.breadcrumbs],
				hasSelfClosingFlag: false,
			});
			this.#setCurrentNamespace("html");
		}

		#queueFosteredFragmentFormattingElementPreclosureForTableStartTag(tagName) {
			if (
				this.is_full_parser ||
				!this.#tableFragmentContextHandlesStartTag(tagName)
			) {
				return false;
			}

			const topIndex = this.open_elements.length - 1;
			if (
				topIndex < this.base_open_element_count ||
				this.open_elements[topIndex] !== "A" ||
				this.open_element_namespaces[topIndex] !== "html"
			) {
				return false;
			}

			const fosterParentedBaseIndex = this.open_element_foster_parented_table_indices[topIndex];
			if (fosterParentedBaseIndex === null || fosterParentedBaseIndex >= topIndex) {
				return false;
			}

			this.#queueVirtualPopsFrom(topIndex);
			this.#removeActiveFormattingElement("A");
			return true;
		}

		#tableFragmentContextHandlesStartTag(tagName) {
			if (
				this.is_full_parser ||
				this.context_namespace !== "html"
			) {
				return false;
			}

			if (this.context_node === "TABLE") {
				return TABLE_MODE_START_TAGS.has(tagName);
			}

			if (this.context_node === "COLGROUP") {
				return tagName === "COL" || tagName === "COLGROUP";
			}

			if (TABLE_SECTION_ELEMENTS.has(this.context_node)) {
				return this.#isHandledInTableBodyMode(tagName, false);
			}

			if (this.context_node === "TR") {
				return this.#isHandledInTableRowMode(tagName, false);
			}

			return false;
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

		#queueFullParserMissingBodyAtEof() {
			if (
				!this.is_full_parser ||
				!["before_head", "in_head", "in_head_noscript", "after_head"].includes(this.full_parser_insertion_mode) ||
				this.#hasOpenHtmlElement("BODY") ||
				this.#hasOpenHtmlElement("FRAMESET")
			) {
				return false;
			}

			const topIndex = this.open_elements.length - 1;
			if (this.full_parser_insertion_mode === "before_head") {
				if (
					topIndex < 0 ||
					this.open_elements[topIndex] !== "HTML" ||
					this.open_element_namespaces[topIndex] !== "html"
				) {
					return false;
				}

				this.#queueVirtualPush("HEAD");
				this.#queueVirtualPop("HEAD");
			} else if (this.full_parser_insertion_mode === "in_head_noscript") {
				if (
					topIndex < 1 ||
					this.open_elements[topIndex] !== "NOSCRIPT" ||
					this.open_element_namespaces[topIndex] !== "html" ||
					this.open_elements[topIndex - 1] !== "HEAD" ||
					this.open_element_namespaces[topIndex - 1] !== "html"
				) {
					return false;
				}

				this.#queueVirtualPop("NOSCRIPT");
				this.#queueVirtualPop("HEAD");
			} else {
				if (
					topIndex < 0 ||
					this.open_elements[topIndex] !== "HEAD" ||
					this.open_element_namespaces[topIndex] !== "html"
				) {
					if (
						this.full_parser_insertion_mode === "in_head" &&
						this.#hasOpenHtmlElement("HEAD")
					) {
						this.#queueVirtualPopsFrom(this.#lastOpenElementIndex("HEAD", "html") + 1);
						this.#queueVirtualPop("HEAD");
					} else if (
						this.full_parser_insertion_mode !== "after_head" ||
						topIndex < 0 ||
						this.open_elements[topIndex] !== "HTML" ||
						this.open_element_namespaces[topIndex] !== "html"
					) {
						return false;
					}
				} else {
					this.#queueVirtualPop("HEAD");
				}
			}

			this.full_parser_insertion_mode = "in_body";
			this.#queueVirtualPush("BODY");
			return true;
		}

		#shouldConsumeEofCommentBeforeMissingBody() {
			return (
				this.is_full_parser &&
				[
					"initial",
					"before_html",
					"before_head",
					"in_head",
					"in_head_noscript",
					"after_head",
				].includes(this.full_parser_insertion_mode)
			);
		}

		#consumeFullParserEofComment() {
			if (
				!this.is_full_parser ||
				this.synthetic_eof_comment_consumed ||
				!super.paused_at_incomplete_token()
			) {
				return false;
			}

			const commentStart = this.#incompleteTokenStart();
			if (commentStart === null || !this.html.startsWith("<!--", commentStart)) {
				return false;
			}

			let commentText = this.html.slice(commentStart + 4);
			if (commentText.endsWith("--")) {
				commentText = commentText.slice(0, -2);
			}

			this.synthetic_eof_comment_consumed = true;
			this.current_synthetic_token = {
				tokenType: "#comment",
				tokenName: "#comment",
				commentText,
			};
			this.parser_state = STATE_COMMENT;
			this.current_token_namespace = this.current_namespace;
			this.breadcrumbs = this.#breadcrumbStack("#comment");
			return true;
		}

		#incompleteTokenIsEofComment() {
			const tokenStart = this.#incompleteTokenStart();
			return tokenStart !== null && this.html.startsWith("<!--", tokenStart);
		}

		#incompleteTokenStart() {
			if (!super.paused_at_incomplete_token()) {
				return null;
			}

			const span = this.#nativeCurrentSpan();
			return span === null ? 0 : span.start + span.length;
		}

		#skipIncompleteSelectBreakoutStartTag() {
			const tokenStart = this.#incompleteTokenStart();
			if (tokenStart === null) {
				return false;
			}

			const startTag = completeStartTagAt(this.html, tokenStart);
			if (startTag === null || !SELECT_BREAKOUT_START_TAGS.has(startTag.tagName)) {
				return false;
			}

			const selectIndex = this.#lastOpenElementIndex("SELECT", "html");
			if (selectIndex === -1 || selectIndex >= this.base_open_element_count) {
				return false;
			}

			wasm.wp_html_api_rust_tag_processor_seek(this.pointer, startTag.end);
			this.parser_state = STATE_READY;
			this.current_virtual = null;
			this.current_synthetic_token = null;
			this.skip_current_token = false;
			this.breadcrumbs = this.#breadcrumbStack();
			return true;
		}

		#skipIncompleteFullParserEndTag() {
			if (!this.is_full_parser) {
				return false;
			}

			const tokenStart = this.#incompleteTokenStart();
			if (tokenStart === null || !incompleteEndTagAt(this.html, tokenStart)) {
				return false;
			}

			wasm.wp_html_api_rust_tag_processor_seek(this.pointer, this.html.length);
			this.parser_state = STATE_READY;
			this.current_virtual = null;
			this.current_synthetic_token = null;
			this.skip_current_token = false;
			this.breadcrumbs = this.#breadcrumbStack();
			return true;
		}

		#skipIncompleteFullParserQuotedStartTag() {
			if (!this.is_full_parser) {
				return false;
			}

			const tokenStart = this.#incompleteTokenStart();
			if (tokenStart === null || !incompleteQuotedStartTagAt(this.html, tokenStart)) {
				return false;
			}

			wasm.wp_html_api_rust_tag_processor_seek(this.pointer, this.html.length);
			this.parser_state = STATE_READY;
			this.current_virtual = null;
			this.current_synthetic_token = null;
			this.skip_current_token = false;
			this.breadcrumbs = this.#breadcrumbStack();
			return true;
		}

		#skipIncompleteFullParserStartTag() {
			if (!this.is_full_parser) {
				return false;
			}

			const tokenStart = this.#incompleteTokenStart();
			if (tokenStart === null || !incompleteStartTagAt(this.html, tokenStart)) {
				return false;
			}

			wasm.wp_html_api_rust_tag_processor_seek(this.pointer, this.html.length);
			this.parser_state = STATE_READY;
			this.current_virtual = null;
			this.current_synthetic_token = null;
			this.skip_current_token = false;
			this.breadcrumbs = this.#breadcrumbStack();
			return true;
		}

		#seekPastCurrentStartTag() {
			const span = this.#nativeCurrentSpan();
			if (span === null) {
				return false;
			}

			const startTag = completeStartTagAt(super.get_updated_html(), span.start);
			if (
				startTag === null ||
				startTag.end <= span.start ||
				startTag.end > span.start + span.length
			) {
				return false;
			}

			wasm.wp_html_api_rust_tag_processor_seek(this.pointer, startTag.end);
			return true;
		}

		#nativeCurrentSpan() {
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
					skipSerialization: i < this.base_open_element_count,
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
				this.#namespaceForCurrentStartTag(rawTagName),
			);
		}

		#namespaceForCurrentStartTag(tagName) {
			if (
				this.current_namespace === "html" &&
				MATHML_TEXT_INTEGRATION_FOREIGN_START_TAGS.has(tagName)
			) {
				const topIndex = this.open_elements.length - 1;
				if (
					topIndex >= 0 &&
					this.open_element_namespaces[topIndex] === "math" &&
					this.open_element_integration_node_types[topIndex] === "math"
				) {
					return "math";
				}
			}

			return namespaceForTag(tagName, this.current_namespace);
		}

		#integrationNodeTypeForCurrentStartTag(tagName, namespaceName) {
			if (namespaceName === "svg") {
				return SVG_HTML_INTEGRATION_POINT_ELEMENTS.has(tagName) ? "html" : null;
			}

			if (namespaceName !== "math") {
				return null;
			}

			if (MATHML_TEXT_INTEGRATION_POINT_ELEMENTS.has(tagName)) {
				return "math";
			}

			if (tagName !== "ANNOTATION-XML") {
				return null;
			}

			const encoding = this.get_attribute("encoding");
			return (
				typeof encoding === "string" &&
				MATHML_HTML_INTEGRATION_POINT_ENCODINGS.has(encoding.toLowerCase())
			)
				? "html"
				: null;
		}

		#childNamespaceForStackEntry(tagName, tokenNamespace, integrationNodeType) {
			if (integrationNodeType !== null) {
				return "html";
			}

			return tokenNamespace;
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

		#namespaceForEndTag(tagName) {
			const topIndex = this.open_elements.length - 1;
			if (
				topIndex >= 0 &&
				this.open_elements[topIndex] === tagName &&
				this.open_element_namespaces[topIndex] !== "html"
			) {
				return this.open_element_namespaces[topIndex];
			}

			if (
				topIndex >= 0 &&
				this.current_namespace === "html" &&
				this.open_element_namespaces[topIndex] !== "html" &&
				this.open_element_integration_node_types[topIndex] !== null
			) {
				return this.open_element_namespaces[topIndex];
			}

			return this.current_namespace;
		}

		#hasElementInTableScope(match) {
			return this.#findElementInTableScope(match) !== -1;
		}

		#isInTableInsertionContext() {
			for (let i = this.open_elements.length - 1; i >= 0; i -= 1) {
				const nodeName = this.open_elements[i];
				if (this.open_element_namespaces[i] !== "html") {
					continue;
				}

				if (
					nodeName === "TABLE" ||
					nodeName === "CAPTION" ||
					nodeName === "COLGROUP" ||
					nodeName === "TR" ||
					TABLE_SECTION_ELEMENTS.has(nodeName) ||
					TABLE_CELL_ELEMENTS.has(nodeName)
				) {
					return true;
				}

				if (nodeName === "HTML" || nodeName === "TEMPLATE") {
					return false;
				}
			}

			return false;
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
			if (this.current_namespace !== "html") {
				return;
			}

			if (
				(
					tagName === "OPTION" ||
					tagName === "OPTGROUP" ||
					(tagName === "HR" && this.#hasOpenHtmlElement("SELECT"))
				)
			) {
				this.#popCurrentHtmlElementIf("OPTION");
				if ((tagName === "OPTGROUP" || tagName === "HR") && this.#hasOpenHtmlElement("SELECT")) {
					this.#popCurrentHtmlElementIf("OPTGROUP");
				}
			}

			if (this.#findClosablePInButtonScopeForStartTag(tagName) !== -1) {
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
					this.open_element_integration_node_types.pop();
					this.open_element_foster_parented_table_indices.pop();
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
				return;
			}

			if (
				RUBY_IMPLIED_END_TAG_START_TAGS.has(tagName) &&
				this.#hasOpenHtmlElement("RUBY")
			) {
				while (this.#currentHtmlElementHasRubyImpliedEndTagForStartTag(tagName)) {
					this.open_elements.pop();
					this.open_element_namespaces.pop();
					this.open_element_integration_node_types.pop();
					this.open_element_foster_parented_table_indices.pop();
				}
				this.#setCurrentNamespace(this.#namespaceForStackTop());
			}
		}

		#closePInButtonScope() {
			return this.#popLastMatchingBeforeBoundary("P", BUTTON_SCOPE_BOUNDARIES);
		}

		#shouldClosePForStartTag(tagName) {
			return (
				P_CLOSING_START_TAGS.has(tagName) &&
				(tagName !== "TABLE" || this.compat_mode !== WP_HTML_Tag_Processor.QUIRKS_MODE)
			);
		}

		#shouldIgnoreInBodyFragmentStartTag(tagName) {
			const isInBodyFragmentContext = (
				this.context_namespace === "html" &&
				(this.context_node === "BODY" || this.context_node === "DIV")
			) || this.context_integration_node_type === "html" || this.detached_context_breadcrumbs.length > 0;

			return (
				!this.is_full_parser &&
				isInBodyFragmentContext &&
				this.current_namespace === "html" &&
				this.template_insertion_modes.length === 0 &&
				(tagName === "BODY" || tagName === "FRAMESET" || tagName === "HTML")
			);
		}

		#shouldIgnoreInBodyStartTag(tagName) {
			return (
				IN_BODY_IGNORED_START_TAGS.has(tagName) &&
				!(this.context_namespace === "html" && this.context_node === "FRAMESET" && tagName === "FRAME")
			);
		}

		#shouldIgnoreTableContextTableStartTag(tagName) {
			return (
				!this.is_full_parser &&
				tagName === "TABLE" &&
				this.context_namespace === "html" &&
				this.context_node === "TABLE" &&
				this.#currentHtmlElementIs("TABLE")
			);
		}

		#shouldIgnoreTableRowContextBoundaryStartTag(tagName) {
			return (
				!this.is_full_parser &&
				TABLE_ROW_BOUNDARY_START_TAGS.has(tagName) &&
				this.context_namespace === "html" &&
				this.context_node === "TR" &&
				this.#currentHtmlElementIs("TR")
			);
		}

		#shouldIgnoreTableSectionContextBoundaryStartTag(tagName) {
			return (
				!this.is_full_parser &&
				TABLE_SECTION_BOUNDARY_START_TAGS.has(tagName) &&
				this.context_namespace === "html" &&
				TABLE_SECTION_ELEMENTS.has(this.context_node) &&
				this.#currentHtmlElementIs(this.context_node)
			);
		}

		#shouldIgnoreCaptionContextBoundaryStartTag(tagName) {
			return (
				!this.is_full_parser &&
				this.context_namespace === "html" &&
				this.context_node === "CAPTION" &&
				(tagName === "HTML" || CAPTION_CLOSING_START_TAGS.has(tagName)) &&
				this.#lastOpenElementIndex("CAPTION", "html") !== -1
			);
		}

		#shouldIgnoreColgroupFragmentFosteredStartTag(tagName) {
			const span = this.#currentRealTokenSpan();
			return (
				tagName === "A" &&
				!this.is_full_parser &&
				this.context_namespace === "html" &&
				this.context_node === "COLGROUP" &&
				this.#currentHtmlElementIs("COLGROUP") &&
				span !== null &&
				this.#currentFosteredFragmentStartIsFollowedByTableStartTag(span.start + span.length)
			);
		}

		#findClosablePInButtonScopeForStartTag(tagName) {
			if (!this.#shouldClosePForStartTag(tagName)) {
				return -1;
			}

			const paragraphIndex = this.#findOpenElementBeforeBoundary("P", BUTTON_SCOPE_BOUNDARIES);
			return paragraphIndex !== -1 && !this.#hasForeignIntegrationPointAfter(paragraphIndex)
				? paragraphIndex
				: -1;
		}

		#currentHtmlElementHasImpliedEndTag() {
			const topIndex = this.open_elements.length - 1;
			return (
				topIndex >= 0 &&
				this.open_element_namespaces[topIndex] === "html" &&
				IMPLIED_END_TAG_ELEMENTS.has(this.open_elements[topIndex])
			);
		}

		#currentHtmlElementHasRubyImpliedEndTagForStartTag(tagName) {
			const topIndex = this.open_elements.length - 1;
			if (
				topIndex < 0 ||
				this.open_element_namespaces[topIndex] !== "html" ||
				!IMPLIED_END_TAG_ELEMENTS.has(this.open_elements[topIndex])
			) {
				return false;
			}

			return (
				this.open_elements[topIndex] !== "RTC" ||
				tagName === "RB" ||
				tagName === "RTC"
			);
		}

		#shouldIgnoreEndTagInTableContext(tagName) {
			if (this.current_namespace !== "html" || !this.#hasElementInTableScope("TABLE")) {
				return false;
			}

			if (this.#currentHtmlElementIs("TABLE")) {
				return TABLE_MODE_IGNORED_END_TAGS.has(tagName);
			}

			if (this.#currentHtmlElementIs("COLGROUP")) {
				return tagName !== "COLGROUP" && TABLE_MODE_IGNORED_END_TAGS.has(tagName);
			}

			const topIndex = this.open_elements.length - 1;
			const currentNode = topIndex >= 0 && this.open_element_namespaces[topIndex] === "html"
				? this.open_elements[topIndex]
				: null;

			if (TABLE_SECTION_ELEMENTS.has(currentNode)) {
				return TABLE_BODY_MODE_IGNORED_END_TAGS.has(tagName);
			}

			if (currentNode === "TR") {
				return TABLE_ROW_MODE_IGNORED_END_TAGS.has(tagName);
			}

			if (TABLE_CELL_ELEMENTS.has(currentNode)) {
				return TABLE_CELL_MODE_IGNORED_END_TAGS.has(tagName);
			}

			return false;
		}

		#shouldIgnoreEndTagClosingOutsideTemplate(tagName, namespaceName, existingIndex) {
			if (namespaceName !== "html" || tagName === "TEMPLATE") {
				return false;
			}

			const templateIndex = this.#lastOpenElementIndex("TEMPLATE", "html");
			return templateIndex !== -1 && existingIndex !== -1 && existingIndex < templateIndex;
		}

		#hasForeignIntegrationPointAfter(index) {
			for (let i = index + 1; i < this.open_elements.length; i += 1) {
				if (
					this.open_element_namespaces[i] !== "html" &&
					this.open_element_integration_node_types[i] !== null
				) {
					return true;
				}
			}

			return false;
		}

		#popLastMatchingBeforeBoundary(match, boundaries) {
			const predicate = typeof match === "function" ? match : (nodeName) => nodeName === match;
			for (let i = this.open_elements.length - 1; i >= 0; i -= 1) {
				const nodeName = this.open_elements[i];
				const namespaceName = this.open_element_namespaces[i];
				if (namespaceName === "html" && predicate(nodeName)) {
					this.open_elements = this.open_elements.slice(0, i);
					this.open_element_namespaces = this.open_element_namespaces.slice(0, i);
					this.open_element_integration_node_types = this.open_element_integration_node_types.slice(0, i);
					this.open_element_foster_parented_table_indices = this.open_element_foster_parented_table_indices.slice(0, i);
					this.#setCurrentNamespace(this.#namespaceForStackTop());
					return true;
				}

				if (boundaries.has(this.#scopeBoundaryNameForOpenElement(i))) {
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
					this.open_element_integration_node_types = this.open_element_integration_node_types.slice(0, i);
					this.open_element_foster_parented_table_indices = this.open_element_foster_parented_table_indices.slice(0, i);
					this.#setCurrentNamespace(this.#namespaceForStackTop());
					return true;
				}
			}
			return false;
		}

		#popCurrentHtmlElementIf(tagName) {
			const topIndex = this.open_elements.length - 1;
			if (!this.#currentHtmlElementIs(tagName)) {
				return false;
			}

			this.open_elements.pop();
			this.open_element_namespaces.pop();
			this.open_element_integration_node_types.pop();
			this.open_element_foster_parented_table_indices.pop();
			this.#setCurrentNamespace(this.#namespaceForStackTop());
			return true;
		}

		#hasOpenHtmlElement(tagName) {
			return this.open_elements.some((nodeName, index) => (
				nodeName === tagName &&
				this.open_element_namespaces[index] === "html"
			));
		}

		#scopeBoundaryNameForOpenElement(index) {
			const namespaceName = this.open_element_namespaces[index];
			const nodeName = this.open_elements[index];
			return namespaceName === "html" ? nodeName : `${namespaceName} ${nodeName}`;
		}

		#countOpenHtmlElements(tagName, endIndex = this.open_elements.length) {
			let count = 0;
			for (let i = 0; i < endIndex; i += 1) {
				if (
					this.open_elements[i] === tagName &&
					this.open_element_namespaces[i] === "html"
				) {
					count += 1;
				}
			}
			return count;
		}

		#silentlyReopenFullParserElement(tagName) {
			const topIndex = this.open_elements.length - 1;
			if (
				!this.is_full_parser ||
				topIndex < 0 ||
				this.open_elements[topIndex] !== "HTML" ||
				this.open_element_namespaces[topIndex] !== "html"
			) {
				return false;
			}

			this.open_elements.push(tagName);
			this.open_element_namespaces.push("html");
			this.open_element_integration_node_types.push(null);
			this.open_element_foster_parented_table_indices.push(this.#currentFosterParentedTableIndex());
			this.#setCurrentNamespace(this.#childNamespaceForStackEntry(tagName, "html", null));
			return true;
		}

		#closeTemporaryReopenedHeadAfterCurrentToken() {
			if (!this.temporary_reopened_head) {
				return false;
			}

			this.temporary_reopened_head = false;
			this.full_parser_insertion_mode = "after_head";
			const headIndex = this.#lastOpenElementIndex("HEAD", "html");
			if (headIndex === -1) {
				return false;
			}

			this.open_elements.splice(headIndex, 1);
			this.open_element_namespaces.splice(headIndex, 1);
			this.open_element_integration_node_types.splice(headIndex, 1);
			this.open_element_foster_parented_table_indices.splice(headIndex, 1);
			this.#setCurrentNamespace(this.#namespaceForStackTop());
			return true;
		}

		#isInHeadTemplateContent() {
			const templateIndex = this.#lastOpenElementIndex("TEMPLATE", "html");
			if (templateIndex === -1) {
				return false;
			}

			for (let i = templateIndex - 1; i >= 0; i -= 1) {
				if (this.open_element_namespaces[i] !== "html") {
					continue;
				}

				if (this.open_elements[i] === "BODY") {
					return false;
				}

				if (this.open_elements[i] === "HEAD") {
					return true;
				}
			}

			return false;
		}

		#shouldIgnoreDocumentStartTagInTemplateContent(tokenType, tagName, isCloser) {
			return (
				tokenType === "#tag" &&
				!isCloser &&
				(tagName === "HTML" || tagName === "BODY") &&
				this.#hasOpenHtmlElement("TEMPLATE")
			);
		}

		#shouldIgnoreFrameStartTagInTemplateContent(tokenType, tagName, isCloser) {
			if (
				tokenType !== "#tag" ||
				isCloser ||
				(tagName !== "FRAME" && tagName !== "FRAMESET") ||
				!this.#hasOpenHtmlElement("TEMPLATE")
			) {
				return false;
			}

			return this.full_parser_insertion_mode === "in_body" || this.#isInHeadTemplateContent();
		}

		#openHtmlElementBefore(tagName, beforeIndex) {
			for (let i = beforeIndex - 1; i >= 0; i -= 1) {
				if (
					this.open_elements[i] === tagName &&
					this.open_element_namespaces[i] === "html"
				) {
					return true;
				}
			}
			return false;
		}

		#currentHtmlElementIs(tagName) {
			const topIndex = this.open_elements.length - 1;
			return (
				topIndex >= 0 &&
				this.open_elements[topIndex] === tagName &&
				this.open_element_namespaces[topIndex] === "html"
			);
		}

		#isInTableTextContext() {
			const topIndex = this.open_elements.length - 1;
			if (
				topIndex < 0 ||
				this.open_element_namespaces[topIndex] !== "html" ||
				!TABLE_TEXT_CURRENT_NODE_ELEMENTS.has(this.open_elements[topIndex])
			) {
				return false;
			}

			if (this.open_elements[topIndex] === "TEMPLATE") {
				const tableIndex = this.#lastOpenElementIndex("TABLE", "html");
				const selectIndex = this.#lastOpenElementIndex("SELECT", "html");
				if (tableIndex !== -1 && selectIndex > tableIndex && selectIndex < topIndex) {
					return false;
				}
			}

			return this.open_elements[topIndex] === "TABLE" || this.#openHtmlElementBefore("TABLE", topIndex);
		}

		#shouldDeferCurrentTableOpener(tagName, namespaceName) {
			return (
				(this.is_full_parser || this.#canDeferTableInFragment()) &&
				this.deferred_table_opener === null &&
				tagName === "TABLE" &&
				namespaceName === "html" &&
				this.#currentTableStartIsFollowedByFosteredContent()
			);
		}

		#canDeferTableInFragment() {
			return this.#canDeferBodyTableInFragment() || this.#canDeferNestedTableInFragment();
		}

		#canDeferBodyTableInFragment() {
			return (
				!this.is_full_parser &&
				this.context_namespace === "html" &&
				this.context_node === "BODY" &&
				this.#currentHtmlElementIs("TABLE") &&
				this.#currentBodyFragmentTableStartIsFollowedByDirectFosteredContent()
			);
		}

		#currentBodyFragmentTableStartIsFollowedByDirectFosteredContent() {
			const span = this.#currentRealTokenSpan();
			if (span === null) {
				return false;
			}

			const afterToken = span.start + span.length;
			const nextTag = runtime.scanNextTag(this.html, afterToken);
			const text = this.html.slice(afterToken, this.#fosterLookaheadTextEnd(afterToken, nextTag));
			if (!this.#isIgnorableTableText(text)) {
				return true;
			}

			if (this.#currentTableStartContentPrecedesFosteredStart()) {
				return true;
			}

			return (
				nextTag !== false &&
				!nextTag.is_closing &&
				(
					this.#isFosteredElementTableStartTag(nextTag.tag_name) ||
					this.#isFosteredInputStartTag(nextTag) ||
					(
						nextTag.tag_name === "COLGROUP" &&
						this.#colgroupStartPrecedesDirectFosteredContent(nextTag.token_end)
					)
				)
			);
		}

		#colgroupStartPrecedesDirectFosteredContent(at) {
			const nextTag = runtime.scanNextTag(this.html, at);
			const text = this.html.slice(at, this.#fosterLookaheadTextEnd(at, nextTag));
			return (
				!this.#isIgnorableTableText(text) ||
				(
					nextTag !== false &&
					!nextTag.is_closing &&
					this.#isFosteredTableLookaheadStartTag(nextTag)
				)
			);
		}

		#canDeferNestedTableInFragment() {
			return (
				!this.is_full_parser &&
				this.context_namespace === "html" &&
				(
					TABLE_SECTION_ELEMENTS.has(this.context_node) ||
					this.context_node === "TR" ||
					TABLE_CELL_ELEMENTS.has(this.context_node)
				) &&
				(
					this.deferred_table_opener !== null ||
					this.#currentHtmlElementIs("TABLE")
				)
			);
		}

		#currentTableStartIsFollowedByFosteredContent() {
			return (
				this.#currentTokenIsFollowedByFosteredTableContent(
					new Set(["COL", "COLGROUP", "FORM", "INPUT", "TBODY", "TEMPLATE", "TFOOT", "THEAD", "TR"]),
				) ||
				this.#currentTableStartCellOpenerPrecedesFosteredText() ||
				this.#currentTableStartCellOpenerPrecedesFosteredStart() ||
				this.#currentTableStartCellContentPrecedesFosteredText() ||
				this.#currentTableStartContentPrecedesFosteredStart() ||
				this.#currentTableStartForeignCellCloserPrecedesFosteredText()
			);
		}

		#shouldDeferCurrentTableChildOpener(tagName, namespaceName) {
			return (
				(this.is_full_parser || this.#canDeferNestedTableInFragment() || this.#canDeferBodyTableChildInFragment()) &&
				this.deferred_table_opener !== null &&
				namespaceName === "html" &&
				(
					(
						this.#isDeferredTableChildOpenerTag(tagName) &&
						(
							TABLE_CELL_ELEMENTS.has(tagName)
								? (
									this.#currentTableCellStartIsFollowedByFosteredTableContent() ||
									this.#currentTableCellStartContentPrecedesFosteredText() ||
									this.#currentTableCellStartContentPrecedesFosteredStart() ||
									this.#currentTableCellStartPrecedesForeignCellCloserFosteredText()
								)
								: this.#currentTokenIsFollowedByFosteredTableContent(this.#deferredTableChildLookaheadTags(tagName))
						)
					) ||
					(
						tagName === "TR" &&
						(
							this.#currentTableRowStartPrecedesFosteredTextAfterCell() ||
							this.#currentTableRowStartPrecedesCellContentFosteredText() ||
							this.#currentTableRowStartPrecedesCellContentFosteredStart() ||
							this.#currentTableRowStartPrecedesForeignCellCloserFosteredText()
						)
					) ||
					(
						tagName === "TABLE" &&
						this.#currentNestedTableStartPrecedesDeferredFosteredStart()
					) ||
					this.#isDeferredTableHiddenInputChildOpener(tagName)
				)
			);
		}

		#canDeferBodyTableChildInFragment() {
			return (
				!this.is_full_parser &&
				this.context_namespace === "html" &&
				this.context_node === "BODY" &&
				this.#lastOpenElementIndex("TABLE", "html") >= this.base_open_element_count
			);
		}

		#queueDeferredTableOpenerBeforeCurrentToken(tokenType) {
			if (this.deferred_table_opener === null) {
				return false;
			}

			if (this.#currentFosterParentedTableIndex() !== null && tokenType !== "#tag") {
				return false;
			}

			if (tokenType === "#text") {
				if (
					this.text_node_classification === WP_HTML_Tag_Processor.TEXT_IS_WHITESPACE &&
					this.#currentTextChunkPrecedesDeferredTableChildOpener()
				) {
					return false;
				}

				return (
					this.text_node_classification === WP_HTML_Tag_Processor.TEXT_IS_WHITESPACE &&
					!this.#currentTextChunkPrecedesFosteredTableText() &&
					this.#queueDeferredTableOpener()
				);
			}

			if (
				tokenType === "#comment" ||
				tokenType === "#funky-comment" ||
				tokenType === "#presumptuous-tag"
			) {
				return this.#queueDeferredTableOpener();
			}

			if (tokenType !== "#tag") {
				return this.#queueDeferredTableOpener();
			}

			const tagName = this.#getCurrentTreeTagName();
			if (tagName === null) {
				return false;
			}
			const isHtmlStart = this.current_namespace === "html";

			if (this.is_tag_closer()) {
				if (this.#currentFosterParentedTableIndex() !== null) {
					if (tagName === "TABLE" || this.#hasDeferredTableChildOpener(tagName)) {
						return this.#queueFosteredElementPopsBeforeDeferredTable();
					}
					return false;
				}

				if (tagName === "TABLE" && this.#currentDeferredNestedTableCloserPrecedesFosteredStart()) {
					return false;
				}

				if (tagName === "TEMPLATE" && this.#hasDeferredTableChildOpener("TEMPLATE")) {
					return false;
				}

				if (tagName === "FORM" && this.#hasDeferredTableChildOpener("FORM")) {
					return false;
				}

				if (this.#currentTableStructureCloserPrecedesFosteredTableContent(tagName)) {
					return false;
				}

				return (
					(tagName === "TABLE" || this.#hasDeferredTableChildOpener(tagName)) &&
					this.#queueDeferredTableOpener()
				);
			}

			if (this.#currentFosterParentedTableIndex() !== null) {
				if (tagName === "TABLE" || tagName === "TR" || TABLE_CELL_ELEMENTS.has(tagName)) {
					return this.#queueFosteredElementPopsBeforeDeferredTable();
				}
				return false;
			}

			if (
				(
					(
						isHtmlStart &&
						this.#isDeferredTableChildOpenerTag(tagName)
					) ||
					(
						isHtmlStart &&
						this.#isDeferredTableHiddenInputChildOpener(tagName)
					)
				) &&
				(
					TABLE_CELL_ELEMENTS.has(tagName)
						? (
							this.#currentTableCellStartIsFollowedByFosteredTableContent() ||
							this.#currentTableCellStartContentPrecedesFosteredText() ||
							this.#currentTableCellStartContentPrecedesFosteredStart() ||
							this.#currentTableCellStartPrecedesForeignCellCloserFosteredText()
						)
						: this.#currentTokenIsFollowedByFosteredTableContent(this.#deferredTableChildLookaheadTags(tagName))
				)
			) {
				return false;
			}

			if (
				isHtmlStart &&
				tagName === "TR" &&
				(
					this.#currentTableRowStartPrecedesFosteredTextAfterCell() ||
					this.#currentTableRowStartPrecedesCellContentFosteredText() ||
					this.#currentTableRowStartPrecedesCellContentFosteredStart() ||
					this.#currentTableRowStartPrecedesForeignCellCloserFosteredText()
				)
			) {
				return false;
			}

			if (isHtmlStart && SPECIAL_ATOMIC_ELEMENTS.has(tagName)) {
				if (this.#isFosteredAtomicTableStartTag(tagName)) {
					return false;
				}
				if (isHtmlStart && TABLE_MODE_START_TAGS.has(tagName)) {
					return this.#queueDeferredTableOpener();
				}
				this.#bailUnsupported("Foster parenting is not supported.");
				return true;
			}

			if (isHtmlStart && this.#isFosteredInputTableStartTag(tagName)) {
				return false;
			}

			if (isHtmlStart && tagName === "TABLE" && this.#currentNestedTableStartPrecedesDeferredFosteredStart()) {
				return false;
			}

			return isHtmlStart && TABLE_MODE_START_TAGS.has(tagName) && this.#queueDeferredTableOpener();
		}

		#representFosteredTextBeforeDeferredTable() {
			if (
				(!this.is_full_parser && !this.#canRepresentFosteredTextInFragment()) ||
				this.deferred_table_opener === null
			) {
				return false;
			}

			if (
				this.text_node_classification === WP_HTML_Tag_Processor.TEXT_IS_WHITESPACE &&
				this.#currentTextChunkPrecedesDeferredTableChildOpener()
			) {
				return this.#deferCurrentTextAsTableChild();
			}

			if (
				this.text_node_classification === WP_HTML_Tag_Processor.TEXT_IS_WHITESPACE &&
				this.#currentTextChunkPrecedesIgnoredEndTagFosteredText()
			) {
				return this.#deferCurrentTextAsTableChild();
			}

			if (
				this.#currentHtmlElementIs("TABLE") &&
				this.text_node_classification === WP_HTML_Tag_Processor.TEXT_IS_WHITESPACE &&
				this.#currentTextChunkPrecedesFosteredTableToken()
			) {
				return this.#deferCurrentTextAsTableChild();
			}

			if (
				this.#currentHtmlElementIs("TR") &&
				this.text_node_classification === WP_HTML_Tag_Processor.TEXT_IS_WHITESPACE &&
				this.#currentTextChunkPrecedesFosteredTableToken()
			) {
				return this.#deferCurrentTextAsTableChild();
			}

			if (
				this.#currentHtmlElementIs("COLGROUP") &&
				(
					this.#currentTextHasLeadingIgnorableTableText() ||
					(
						this.text_node_classification === WP_HTML_Tag_Processor.TEXT_IS_WHITESPACE &&
						this.#currentTextChunkPrecedesFosteredTableText()
					)
				)
			) {
				return this.text_node_classification === WP_HTML_Tag_Processor.TEXT_IS_WHITESPACE &&
					!this.#currentTextHasLeadingIgnorableTableText() &&
					this.#deferCurrentTextAsTableChild();
			}

			const tableIndex = this.#lastOpenElementIndex("TABLE", "html");
			if (tableIndex === -1) {
				return false;
			}

			if (this.#hasUnreconstructedActiveFormattingElement()) {
				return false;
			}

			this.current_token_namespace = "html";
			this.breadcrumbs = this.#breadcrumbStack("#text", tableIndex);
			this.frameset_ok = false;
			return true;
		}

		#canRepresentFosteredTextInFragment() {
			return (
				this.context_namespace === "html" &&
				this.context_node === "BODY" &&
				this.#lastOpenElementIndex("TABLE", "html") >= this.base_open_element_count
			);
		}

		#currentFosterParentedTableIndex() {
			return this.open_element_foster_parented_table_indices.at(-1) ?? null;
		}

		#hasUnreconstructedActiveFormattingElement() {
			for (let i = this.active_formatting_elements.length - 1; i >= 0; i -= 1) {
				const entry = this.active_formatting_elements[i];
				if (this.#isActiveFormattingMarker(entry)) {
					return false;
				}

				if (!this.#activeFormattingElementIsOpen(entry)) {
					return true;
				}
			}

			return false;
		}

		#activeFormattingElementIsOpen(entry) {
			if (this.#isActiveFormattingMarker(entry)) {
				return true;
			}

			if (Number.isInteger(entry.openElementIndex)) {
				const index = entry.openElementIndex;
				return (
					index >= 0 &&
					index < this.open_elements.length &&
					this.open_elements[index] === entry.tagName &&
					this.open_element_namespaces[index] === entry.namespaceName
				);
			}

			return this.#lastOpenElementIndex(entry.tagName, entry.namespaceName) !== -1;
		}

		#queueNestedAnchorOuterCloserAfterDeferredTable() {
			const index = this.pending_nested_anchor_outer_closer_after_deferred_table_index;
			if (index === null) {
				return false;
			}

			if (this.#lastOpenElementIndex("TABLE", "html") !== -1) {
				return false;
			}

			this.pending_nested_anchor_outer_closer_after_deferred_table_index = null;
			const shouldRemoveActiveAnchor = this.pending_nested_anchor_active_removal_after_deferred_table;
			this.pending_nested_anchor_active_removal_after_deferred_table = false;
			if (
				index < 0 ||
				index >= this.open_elements.length ||
				this.open_elements[index] !== "A" ||
				this.open_element_namespaces[index] !== "html"
			) {
				return false;
			}

			this.#queueVirtualPopsFrom(index);
			if (shouldRemoveActiveAnchor) {
				this.#removeActiveFormattingElement("A");
				this.pending_nested_anchor_div_active_removal_after_deferred_table = true;
			}
			return true;
		}

		#queueFosteredElementPopsBeforeDeferredTable() {
			if (this.deferred_table_opener === null) {
				return false;
			}

			const tableIndex = this.#currentFosterParentedTableIndex();
			if (tableIndex === null) {
				return false;
			}

			const firstFosteredIndex = this.open_element_foster_parented_table_indices.findIndex(
				(fosterParentedTableIndex, index) => (
					index > tableIndex &&
					fosterParentedTableIndex === tableIndex
				),
			);
			if (firstFosteredIndex === -1) {
				return false;
			}

			this.#queueVirtualPopsFrom(firstFosteredIndex);
			return true;
		}

		#currentTableStartCellOpenerPrecedesFosteredText() {
			const span = this.#currentRealTokenSpan();
			if (span === null) {
				return false;
			}

			const afterToken = span.start + span.length;
			const nextTag = runtime.scanNextTag(this.html, afterToken);
			const text = this.html.slice(afterToken, this.#fosterLookaheadTextEnd(afterToken, nextTag));
			return (
				this.#isIgnorableTableText(text) &&
				nextTag !== false &&
				!nextTag.is_closing &&
				TABLE_CELL_ELEMENTS.has(nextTag.tag_name) &&
				this.#tableStructureEndTagsPrecedeFosteredText(nextTag.token_end)
			);
		}

		#currentTableStartCellOpenerPrecedesFosteredStart() {
			const span = this.#currentRealTokenSpan();
			if (span === null) {
				return false;
			}

			const nextTag = this.#nextNonWhitespaceTag(span.start + span.length);
			return (
				nextTag !== false &&
				!nextTag.is_closing &&
				TABLE_CELL_ELEMENTS.has(nextTag.tag_name) &&
				this.#cellContentPrecedesFosteredStartAt(nextTag.token_end)
			);
		}

		#currentTableStartCellContentPrecedesFosteredText() {
			const span = this.#currentRealTokenSpan();
			if (span === null) {
				return false;
			}

			let at = span.start + span.length;
			const sectionTag = this.#nextNonWhitespaceTag(at);
			if (
				sectionTag !== false &&
				!sectionTag.is_closing &&
				TABLE_SECTION_ELEMENTS.has(sectionTag.tag_name)
			) {
				at = sectionTag.token_end;
			}

			return this.#rowCellContentPrecedesFosteredTextAt(at);
		}

		#currentTableStartContentPrecedesFosteredStart() {
			const span = this.#currentRealTokenSpan();
			if (span === null) {
				return false;
			}

			let at = span.start + span.length;
			const sectionTag = this.#nextNonWhitespaceTag(at);
			if (
				sectionTag !== false &&
				!sectionTag.is_closing &&
				TABLE_SECTION_ELEMENTS.has(sectionTag.tag_name)
			) {
				at = sectionTag.token_end;
			}

			return this.#rowSequencePrecedesFosteredStartAt(at);
		}

		#currentTableStartForeignCellCloserPrecedesFosteredText() {
			const span = this.#currentRealTokenSpan();
			if (span === null) {
				return false;
			}

			let at = span.start + span.length;
			const nextTag = this.#nextNonWhitespaceTag(at);
			if (
				nextTag !== false &&
				!nextTag.is_closing &&
				nextTag.tag_name === "TR"
			) {
				at = nextTag.token_end;
			}

			return this.#foreignCellCloserPrecedesFosteredTextAt(at, false);
		}

		#currentTableRowStartPrecedesCellContentFosteredText() {
			const span = this.#currentRealTokenSpan();
			return span !== null && this.#rowContentPrecedesFosteredTextAt(
				span.start + span.length,
			);
		}

		#currentTableRowStartPrecedesCellContentFosteredStart() {
			const span = this.#currentRealTokenSpan();
			return span !== null && this.#rowContentPrecedesFosteredStartAt(
				span.start + span.length,
			);
		}

		#currentTableRowStartPrecedesForeignCellCloserFosteredText() {
			const span = this.#currentRealTokenSpan();
			return span !== null && this.#foreignCellCloserPrecedesFosteredTextAt(
				span.start + span.length,
				false,
			);
		}

		#currentTableCellStartContentPrecedesFosteredText() {
			const span = this.#currentRealTokenSpan();
			return span !== null && this.#cellContentPrecedesFosteredTextAt(
				span.start + span.length,
			);
		}

		#currentTableCellStartContentPrecedesFosteredStart() {
			const span = this.#currentRealTokenSpan();
			return span !== null && this.#cellContentPrecedesFosteredStartAt(
				span.start + span.length,
			);
		}

		#currentTableCellStartPrecedesForeignCellCloserFosteredText() {
			const span = this.#currentRealTokenSpan();
			return span !== null && this.#foreignCellCloserPrecedesFosteredTextAt(
				span.start + span.length,
				true,
			);
		}

		#nextNonWhitespaceTag(at) {
			const nextTag = runtime.scanNextTag(this.html, at);
			const text = this.html.slice(at, this.#fosterLookaheadTextEnd(at, nextTag));
			return this.#isIgnorableTableText(text) ? nextTag : false;
		}

		#rowCellContentPrecedesFosteredTextAt(at) {
			const rowTag = this.#nextNonWhitespaceTag(at);
			if (
				rowTag === false ||
				rowTag.is_closing ||
				rowTag.tag_name !== "TR"
			) {
				return false;
			}

			return this.#rowContentPrecedesFosteredTextAt(rowTag.token_end);
		}

		#rowSequencePrecedesFosteredStartAt(at) {
			const rowTag = this.#nextNonWhitespaceTag(at);
			if (rowTag === false || rowTag.is_closing) {
				return false;
			}

			if (this.#isFosteredTableLookaheadStartTag(rowTag)) {
				return true;
			}

			if (rowTag.tag_name !== "TR") {
				return false;
			}

			return this.#rowContentPrecedesFosteredStartAt(rowTag.token_end);
		}

		#rowContentPrecedesFosteredTextAt(at) {
			const cellTag = this.#nextNonWhitespaceTag(at);
			if (
				cellTag === false ||
				cellTag.is_closing ||
				!TABLE_CELL_ELEMENTS.has(cellTag.tag_name)
			) {
				return false;
			}

			return this.#cellContentPrecedesFosteredTextAt(cellTag.token_end);
		}

		#rowContentPrecedesFosteredStartAt(at) {
			const cellTag = this.#nextNonWhitespaceTag(at);
			if (cellTag === false || cellTag.is_closing) {
				return false;
			}

			if (this.#isFosteredTableLookaheadStartTag(cellTag)) {
				return true;
			}

			if (!TABLE_CELL_ELEMENTS.has(cellTag.tag_name)) {
				return false;
			}

			return this.#cellContentPrecedesFosteredStartAt(cellTag.token_end);
		}

		#cellContentPrecedesFosteredTextAt(at) {
			while (true) {
				const nextTag = runtime.scanNextTag(this.html, at);
				if (nextTag === false) {
					return false;
				}

				if (!nextTag.is_closing) {
					if (nextTag.tag_name === "TABLE") {
						const tableEnd = this.#matchingTableEndAfterStart(nextTag);
						if (tableEnd === null) {
							return false;
						}

						at = tableEnd;
						continue;
					}

					if (TABLE_MODE_START_TAGS.has(nextTag.tag_name)) {
						return false;
					}

					at = nextTag.token_end;
					continue;
				}

				if (
					TABLE_CELL_ELEMENTS.has(nextTag.tag_name) ||
					nextTag.tag_name === "TR" ||
					TABLE_SECTION_ELEMENTS.has(nextTag.tag_name) ||
					nextTag.tag_name === "TABLE"
				) {
					return this.#fosteredTextAfterTableStructureEnd(nextTag.token_end);
				}

				at = nextTag.token_end;
			}
		}

		#cellContentPrecedesFosteredStartAt(at) {
			while (true) {
				const nextTag = runtime.scanNextTag(this.html, at);
				if (nextTag === false) {
					return false;
				}

				if (!nextTag.is_closing) {
					if (nextTag.tag_name === "TABLE") {
						const tableEnd = this.#matchingTableEndAfterStart(nextTag);
						if (tableEnd === null) {
							return false;
						}

						at = tableEnd;
						continue;
					}

					if (TABLE_MODE_START_TAGS.has(nextTag.tag_name)) {
						return false;
					}

					at = nextTag.token_end;
					continue;
				}

				if (
					TABLE_CELL_ELEMENTS.has(nextTag.tag_name) ||
					nextTag.tag_name === "TR" ||
					TABLE_SECTION_ELEMENTS.has(nextTag.tag_name) ||
					nextTag.tag_name === "TABLE"
				) {
					return this.#tableStructureEndPrecedesFosteredStart(nextTag.token_end);
				}

				at = nextTag.token_end;
			}
		}

		#matchingTableEndAfterStart(startTag) {
			return this.#matchingTableEndAfterStartAt(startTag.token_end);
		}

		#matchingTableEndAfterStartAt(at) {
			let depth = 1;
			while (true) {
				const nextTag = runtime.scanNextTag(this.html, at);
				if (nextTag === false) {
					return null;
				}

				if (nextTag.tag_name === "TABLE") {
					depth += nextTag.is_closing ? -1 : 1;
					if (depth === 0) {
						return nextTag.token_end;
					}
				}

				at = nextTag.token_end;
			}
		}

		#currentNestedTableStartPrecedesDeferredFosteredStart() {
			if (
				this.deferred_table_opener === null ||
				this.current_namespace !== "html"
			) {
				return false;
			}

			const span = this.#currentRealTokenSpan();
			if (span === null) {
				return false;
			}

			const tableEnd = this.#matchingTableEndAfterStartAt(span.start + span.length);
			return tableEnd !== null && this.#cellContentPrecedesFosteredStartAt(tableEnd);
		}

		#currentDeferredNestedTableCloserPrecedesFosteredStart() {
			if (
				this.deferred_table_opener === null ||
				this.current_namespace !== "html"
			) {
				return false;
			}

			const deferredTableIndex = this.deferred_table_opener.breadcrumbs.length - 1;
			const currentTableIndex = this.#lastOpenElementIndex("TABLE", "html");
			if (currentTableIndex <= deferredTableIndex) {
				return false;
			}

			const span = this.#currentRealTokenSpan();
			return span !== null && this.#cellContentPrecedesFosteredStartAt(span.start + span.length);
		}

		#tableStructureEndPrecedesFosteredStart(at) {
			while (true) {
				const nextTag = runtime.scanNextTag(this.html, at);
				const text = this.html.slice(at, this.#fosterLookaheadTextEnd(at, nextTag));
				if (!this.#isIgnorableTableText(text) || nextTag === false) {
					return false;
				}

				if (nextTag.is_closing) {
					if (
						this.#isTableStructureFosterLookaheadEndTag(nextTag.tag_name) ||
						this.#isIgnoredFosterLookaheadEndTag(nextTag.tag_name)
					) {
						at = nextTag.token_end;
						continue;
					}

					return false;
				}

				if (nextTag.tag_name === "TR") {
					return this.#rowContentPrecedesFosteredStartAt(nextTag.token_end);
				}

				if (TABLE_SECTION_ELEMENTS.has(nextTag.tag_name)) {
					return this.#rowSequencePrecedesFosteredStartAt(nextTag.token_end);
				}

				return this.#isFosteredTableLookaheadStartTag(nextTag);
			}
		}

		#fosteredTextAfterTableStructureEnd(at) {
			while (true) {
				const nextTag = runtime.scanNextTag(this.html, at);
				const text = this.html.slice(at, this.#fosterLookaheadTextEnd(at, nextTag));
				if (!this.#isIgnorableTableText(text)) {
					return true;
				}

				if (
					nextTag !== false &&
					nextTag.is_closing &&
					(
						this.#isTableStructureFosterLookaheadEndTag(nextTag.tag_name) ||
						this.#isIgnoredFosterLookaheadEndTag(nextTag.tag_name)
					)
				) {
					at = nextTag.token_end;
					continue;
				}

				return false;
			}
		}

		#foreignCellCloserPrecedesFosteredTextAt(at, sawCellStart) {
			let sawForeignStart = false;
			while (true) {
				const nextTag = runtime.scanNextTag(this.html, at);
				const text = this.html.slice(at, this.#fosterLookaheadTextEnd(at, nextTag));
				if (!this.#isIgnorableTableText(text)) {
					return false;
				}

				if (nextTag === false) {
					return false;
				}

				if (!nextTag.is_closing) {
					if (!sawCellStart && TABLE_CELL_ELEMENTS.has(nextTag.tag_name)) {
						sawCellStart = true;
						at = nextTag.token_end;
						continue;
					}

					if (sawCellStart && FOREIGN_CONTENT_START_TAGS.has(nextTag.tag_name)) {
						sawForeignStart = true;
						at = nextTag.token_end;
						continue;
					}

					if (sawCellStart && sawForeignStart) {
						at = nextTag.token_end;
						continue;
					}

					return false;
				}

				if (
					sawCellStart &&
					sawForeignStart &&
					TABLE_CELL_ELEMENTS.has(nextTag.tag_name)
				) {
					return this.#fosteredTextAfterForeignCellCloser(nextTag.token_end);
				}

				if (sawCellStart && sawForeignStart) {
					at = nextTag.token_end;
					continue;
				}

				return false;
			}
		}

		#fosteredTextAfterForeignCellCloser(at) {
			return this.#fosteredTextAfterTableStructureEnd(at);
		}

		#currentTableRowStartPrecedesFosteredTextAfterCell() {
			const span = this.#currentRealTokenSpan();
			if (span === null) {
				return false;
			}

			let at = span.start + span.length;
			let sawCellStart = false;
			let sawStructureEndTag = false;
			while (true) {
				const nextTag = runtime.scanNextTag(this.html, at);
				const text = this.html.slice(at, this.#fosterLookaheadTextEnd(at, nextTag));
				if (!this.#isIgnorableTableText(text) && sawStructureEndTag) {
					return true;
				}

				if (nextTag === false) {
					return false;
				}

				if (!nextTag.is_closing) {
					if (!sawCellStart && TABLE_CELL_ELEMENTS.has(nextTag.tag_name)) {
						sawCellStart = true;
						at = nextTag.token_end;
						continue;
					}

					return false;
				}

				if (!sawCellStart || !this.#isTableStructureFosterLookaheadEndTag(nextTag.tag_name)) {
					return false;
				}

				sawStructureEndTag = true;
				at = nextTag.token_end;
			}
		}

		#currentTokenIsFollowedByFosteredTableContent(allowedWrapperTags) {
			const span = this.#currentRealTokenSpan();
			if (span === null) {
				return false;
			}

			return this.#tokenEndIsFollowedByFosteredTableContent(
				span.start + span.length,
				allowedWrapperTags,
			);
		}

		#tokenEndIsFollowedByFosteredTableContent(at, allowedWrapperTags) {
			let wrappers = allowedWrapperTags;
			while (true) {
				const nextTag = runtime.scanNextTag(this.html, at);
				const text = this.html.slice(at, this.#fosterLookaheadTextEnd(at, nextTag));
				if (!this.#isIgnorableTableText(text)) {
					return true;
				}
				if (
					nextTag !== false &&
					nextTag.is_closing &&
					this.#isSkippedFosterLookaheadEndTag(nextTag.tag_name)
				) {
					at = nextTag.token_end;
					continue;
				}
				if (
					nextTag !== false &&
					nextTag.is_closing &&
					this.#isFosteredTableEndTag(nextTag.tag_name)
				) {
					return true;
				}
				if (
					nextTag !== false &&
					nextTag.is_closing &&
					nextTag.tag_name === "TEMPLATE"
				) {
					at = nextTag.token_end;
					continue;
				}
				if (nextTag === false || nextTag.is_closing) {
					return false;
				}
				if (
					FOREIGN_CONTENT_START_TAGS.has(nextTag.tag_name) ||
					nextTag.tag_name === "SELECT" ||
					this.#isFosteredVoidTableStartTag(nextTag.tag_name) ||
					this.#isFosteredInputStartTag(nextTag) ||
					this.#isFosteredAtomicTableStartTag(nextTag.tag_name) ||
					this.#isFosteredElementTableStartTag(nextTag.tag_name)
				) {
					return true;
				}
				if (this.#isDeferredTableAtomicChildOpenerTag(nextTag.tag_name)) {
					const atomicEnd = this.#specialAtomicTagEnd(nextTag);
					if (atomicEnd === null) {
						return false;
					}
					wrappers = this.#deferredTableChildLookaheadTags(nextTag.tag_name);
					at = atomicEnd;
					continue;
				}
				if (
					nextTag.tag_name === "INPUT" &&
					wrappers.has("INPUT") &&
					!this.#isFosteredInputStartTag(nextTag)
				) {
					wrappers = this.#deferredTableChildLookaheadTags(nextTag.tag_name);
					at = nextTag.token_end;
					continue;
				}
				if (!wrappers.has(nextTag.tag_name)) {
					return false;
				}

				wrappers = this.#deferredTableChildLookaheadTags(nextTag.tag_name);
				at = nextTag.token_end;
			}
		}

		#currentTableCellStartIsFollowedByFosteredTableContent() {
			const span = this.#currentRealTokenSpan();
			if (span === null) {
				return false;
			}

			let at = span.start + span.length;
			let sawCellBoundary = false;
			while (true) {
				const nextTag = runtime.scanNextTag(this.html, at);
				const text = this.html.slice(at, this.#fosterLookaheadTextEnd(at, nextTag));
				if (!this.#isIgnorableTableText(text)) {
					if (sawCellBoundary) {
						return true;
					}
					if (
						nextTag !== false &&
						nextTag.is_closing &&
						(
							TABLE_CELL_ELEMENTS.has(nextTag.tag_name) ||
							TABLE_SECTION_ELEMENTS.has(nextTag.tag_name) ||
							nextTag.tag_name === "TABLE" ||
							nextTag.tag_name === "TR"
						)
					) {
						sawCellBoundary = true;
						at = nextTag.token_end;
						continue;
					}
					return false;
				}

				if (
					nextTag !== false &&
					nextTag.is_closing &&
					this.#isSkippedFosterLookaheadEndTag(nextTag.tag_name)
				) {
					sawCellBoundary = true;
					at = nextTag.token_end;
					continue;
				}

				if (nextTag === false || nextTag.is_closing) {
					if (
						nextTag !== false &&
						(
							TABLE_CELL_ELEMENTS.has(nextTag.tag_name) ||
							TABLE_SECTION_ELEMENTS.has(nextTag.tag_name) ||
							nextTag.tag_name === "TABLE" ||
							nextTag.tag_name === "TR"
						)
					) {
						sawCellBoundary = true;
						at = nextTag.token_end;
						continue;
					}

					return false;
				}

				return sawCellBoundary && (
					FOREIGN_CONTENT_START_TAGS.has(nextTag.tag_name) ||
					nextTag.tag_name === "SELECT" ||
					this.#isFosteredVoidTableStartTag(nextTag.tag_name) ||
					this.#isFosteredInputStartTag(nextTag) ||
					this.#isFosteredAtomicTableStartTag(nextTag.tag_name) ||
					this.#isFosteredElementTableStartTag(nextTag.tag_name)
				);
			}
		}

		#isIgnoredFosterLookaheadEndTag(tagName) {
			return (
				tagName === "BLINK" ||
				tagName === "BODY" ||
				tagName === "HTML" ||
				tagName === "KBD" ||
				tagName === "PRE" ||
				tagName === "SELECT" ||
				FORMATTING_ELEMENTS.has(tagName) ||
				HEADING_ELEMENTS.has(tagName)
			);
		}

		#isSkippedFosterLookaheadEndTag(tagName) {
			return (
				this.#isIgnoredFosterLookaheadEndTag(tagName) ||
				this.#isTableStructureFosterLookaheadEndTag(tagName)
			);
		}

		#isTableStructureFosterLookaheadEndTag(tagName) {
			return (
				TABLE_CELL_ELEMENTS.has(tagName) ||
				tagName === "TR" ||
				TABLE_SECTION_ELEMENTS.has(tagName)
			);
		}

		#isFosteredTableEndTag(tagName) {
			return tagName === "BR" || tagName === "P";
		}

		#missingParagraphCloserFosterParentedTableIndex() {
			if (this.deferred_table_opener === null) {
				return null;
			}

			const tableIndex = this.#lastOpenElementIndex("TABLE", "html");
			return tableIndex === -1 ? null : tableIndex;
		}

		#canRepresentMissingParagraphCloserBeforeDeferredTable() {
			return (
				this.deferred_table_opener !== null &&
				this.current_namespace === "html" &&
				this.#currentHtmlElementIs("TABLE") &&
				this.#lastOpenElementIndex("TABLE", "html") !== -1
			);
		}

		#isDeferredTableChildOpenerTag(tagName) {
			return (
				tagName === "COLGROUP" ||
				tagName === "COL" ||
				tagName === "FORM" ||
				tagName === "TEMPLATE" ||
				tagName === "TR" ||
				TABLE_CELL_ELEMENTS.has(tagName) ||
				TABLE_SECTION_ELEMENTS.has(tagName) ||
				this.#isDeferredTableAtomicChildOpenerTag(tagName)
			);
		}

		#deferredTableChildLookaheadTags(tagName) {
			if (TABLE_SECTION_ELEMENTS.has(tagName)) {
				return new Set(["TR"]);
			}

			if (tagName === "FORM" || tagName === "INPUT") {
				return new Set(["INPUT"]);
			}

			return new Set();
		}

		#isDeferredTableHiddenInputChildOpener(tagName) {
			return tagName === "INPUT" && !this.#isFosteredInputTableStartTag(tagName);
		}

		#isDeferredTableAtomicChildOpenerTag(tagName) {
			return (
				!this.#hasOpenHtmlElement("SELECT") &&
				SPECIAL_ATOMIC_ELEMENTS.has(tagName) &&
				TABLE_MODE_START_TAGS.has(tagName)
			);
		}

		#specialAtomicTagEnd(nextTag) {
			const startTag = completeStartTagAt(this.html, nextTag.tag_start);
			if (startTag === null) {
				return null;
			}

			return findSpecialAtomicCloserEnd(this.html, startTag.end, nextTag.tag_name);
		}

		#hasDeferredTableChildOpener(tagName) {
			return this.deferred_table_child_openers.some((token) => token.tagName === tagName);
		}

		#skipDeferredTableTemplateCloser(tagName, namespaceName) {
			if (
				tagName !== "TEMPLATE" ||
				namespaceName !== "html" ||
				this.deferred_table_opener === null ||
				!this.#hasDeferredTableChildOpener("TEMPLATE") ||
				!this.#currentHtmlElementIs("TEMPLATE")
			) {
				return false;
			}

			const templateIndex = this.open_elements.length - 1;
			this.#clearActiveFormattingElementsForTemplateClose(templateIndex);
			this.#popTemplateInsertionMode();
			this.open_elements.pop();
			this.open_element_namespaces.pop();
			this.open_element_integration_node_types.pop();
			this.open_element_foster_parented_table_indices.pop();
			this.#setCurrentNamespace(this.#namespaceForStackTop());
			this.current_token_namespace = namespaceName;
			this.breadcrumbs = this.#breadcrumbStack();
			this.skip_current_token = true;
			return true;
		}

		#ignoreCrossedForeignTableStructureCloserBeforeFosteredText(tagName) {
			if (
				!(
					TABLE_CELL_ELEMENTS.has(tagName) ||
					tagName === "TR" ||
					TABLE_SECTION_ELEMENTS.has(tagName)
				) ||
				!this.#currentForeignTableStructureCloserPrecedesFosteredText()
			) {
				return false;
			}

			const tableIndex = this.#lastOpenElementIndex("TABLE", "html");
			if (tableIndex === -1) {
				return false;
			}

			this.pending_foreign_table_fostered_text_table_index = tableIndex;
			this.current_token_namespace = this.current_namespace;
			this.breadcrumbs = this.#breadcrumbStack();
			this.skip_current_token = true;
			return true;
		}

		#currentForeignTableStructureCloserPrecedesFosteredText() {
			const span = this.#currentRealTokenSpan();
			if (span === null) {
				return false;
			}

			let at = span.start + span.length;
			while (true) {
				const nextTag = runtime.scanNextTag(this.html, at);
				const text = this.html.slice(
					at,
					this.#fosterLookaheadTextEnd(at, nextTag),
				);
				if (!this.#isIgnorableTableText(text)) {
					return true;
				}

				if (nextTag === false || !nextTag.is_closing) {
					return false;
				}

				if (
					TABLE_CELL_ELEMENTS.has(nextTag.tag_name) ||
					nextTag.tag_name === "TR" ||
					TABLE_SECTION_ELEMENTS.has(nextTag.tag_name) ||
					this.#isIgnoredFosterLookaheadEndTag(nextTag.tag_name)
				) {
					at = nextTag.token_end;
					continue;
				}

				return false;
			}
		}

		#representPendingForeignTableFosteredText(tokenType) {
			if (this.pending_foreign_table_fostered_text_table_index === null) {
				return false;
			}

			const tableIndex = this.pending_foreign_table_fostered_text_table_index;
			if (
				tokenType !== "#text" ||
				tableIndex < 0 ||
				tableIndex >= this.open_elements.length ||
				this.open_elements[tableIndex] !== "TABLE" ||
				this.open_element_namespaces[tableIndex] !== "html"
			) {
				this.pending_foreign_table_fostered_text_table_index = null;
				return false;
			}

			if (this.text_node_classification === WP_HTML_Tag_Processor.TEXT_IS_NULL_SEQUENCE) {
				this.pending_foreign_table_fostered_text_table_index = null;
				this.skip_current_token = true;
				return true;
			}

			this.pending_foreign_table_fostered_text_table_index = null;
			this.current_token_namespace = "html";
			this.breadcrumbs = this.#breadcrumbStack("#text", tableIndex);
			this.frameset_ok = false;
			return true;
		}

		#skipDeferredTableStructureCloserBeforeFosteredText(tagName, namespaceName, existingIndex) {
			if (
				namespaceName !== "html" ||
				existingIndex === -1 ||
				!this.#currentTableStructureCloserPrecedesFosteredTableContent(tagName)
			) {
				return false;
			}

			this.current_token_namespace = this.open_element_namespaces[existingIndex];
			this.#applyTemplateInsertionModeForEndTag(tagName);
			this.open_elements = this.open_elements.slice(0, existingIndex);
			this.open_element_namespaces = this.open_element_namespaces.slice(0, existingIndex);
			this.open_element_integration_node_types = this.open_element_integration_node_types.slice(0, existingIndex);
			this.open_element_foster_parented_table_indices = this.open_element_foster_parented_table_indices.slice(0, existingIndex);
			if (TABLE_CELL_ELEMENTS.has(tagName)) {
				this.#clearActiveFormattingElementsUpToLastMarker();
			}
			this.breadcrumbs = this.#breadcrumbStack();
			this.#setCurrentNamespace(this.#namespaceForStackTop());
			this.skip_current_token = true;
			return true;
		}

		#deferCurrentTextAsTableChild() {
			this.deferred_table_child_openers.push({
				tokenType: "#text",
				tokenName: "#text",
				modifiableText: this.get_modifiable_text() ?? "",
				namespaceName: this.current_namespace,
				breadcrumbs: this.#breadcrumbStack("#text"),
				attributes: [],
				textClassification: this.text_node_classification,
			});
			this.skip_current_token = true;
			return true;
		}

		#shouldDeferCurrentStartInsideDeferredTable(tagName, namespaceName) {
			if (
				this.deferred_table_opener === null ||
				this.skip_current_token ||
				this.#currentFosterParentedTableIndex() !== null ||
				(namespaceName === "html" && TABLE_MODE_START_TAGS.has(tagName))
			) {
				return false;
			}

			const tableIndex = this.#lastOpenElementIndex("TABLE", "html");
			const topIndex = this.open_elements.length - 1;
			return tableIndex !== -1 && topIndex > tableIndex;
		}

		#shouldDeferCurrentTextInsideDeferredTable() {
			if (
				this.deferred_table_opener === null ||
				this.text_node_classification === WP_HTML_Tag_Processor.TEXT_IS_NULL_SEQUENCE ||
				this.#currentFosterParentedTableIndex() !== null
			) {
				return false;
			}

			const tableIndex = this.#lastOpenElementIndex("TABLE", "html");
			const topIndex = this.open_elements.length - 1;
			return (
				tableIndex !== -1 &&
				topIndex > tableIndex &&
				this.#deferCurrentTextAsTableChild()
			);
		}

		#currentTableStructureCloserPrecedesFosteredTableText(tagName) {
			if (
				this.deferred_table_opener === null ||
				!(
					TABLE_CELL_ELEMENTS.has(tagName) ||
					tagName === "TR" ||
					TABLE_SECTION_ELEMENTS.has(tagName)
				)
			) {
				return false;
			}

			const span = this.#currentRealTokenSpan();
			if (span === null) {
				return false;
			}

			let at = span.start + span.length;
			while (true) {
				const nextTag = runtime.scanNextTag(this.html, at);
				const text = this.html.slice(
					at,
					this.#fosterLookaheadTextEnd(at, nextTag),
				);
				if (!this.#isIgnorableTableText(text)) {
					return true;
				}

				if (nextTag === false || !nextTag.is_closing) {
					return false;
				}

				if (nextTag.tag_name === "TABLE") {
					return false;
				}

				if (
					TABLE_CELL_ELEMENTS.has(nextTag.tag_name) ||
					nextTag.tag_name === "TR" ||
					TABLE_SECTION_ELEMENTS.has(nextTag.tag_name) ||
					this.#isIgnoredFosterLookaheadEndTag(nextTag.tag_name)
				) {
					at = nextTag.token_end;
					continue;
				}

				return false;
			}
		}

		#currentTableStructureCloserPrecedesFosteredTableContent(tagName) {
			return (
				this.#currentTableStructureCloserPrecedesFosteredTableText(tagName) ||
				this.#currentTableStructureCloserPrecedesFosteredTableStart(tagName)
			);
		}

		#currentTableStructureCloserPrecedesFosteredTableStart(tagName) {
			if (
				this.deferred_table_opener === null ||
				!(
					TABLE_CELL_ELEMENTS.has(tagName) ||
					tagName === "TR" ||
					TABLE_SECTION_ELEMENTS.has(tagName)
				)
			) {
				return false;
			}

			const span = this.#currentRealTokenSpan();
			return span !== null && this.#tableStructureEndPrecedesFosteredStart(
				span.start + span.length,
			);
		}

		#tableStructureEndTagsPrecedeFosteredText(at) {
			let sawStructureEndTag = false;
			while (true) {
				const nextTag = runtime.scanNextTag(this.html, at);
				const text = this.html.slice(at, this.#fosterLookaheadTextEnd(at, nextTag));
				if (!this.#isIgnorableTableText(text)) {
					return sawStructureEndTag;
				}

				if (
					nextTag === false ||
					!nextTag.is_closing ||
					!this.#isTableStructureFosterLookaheadEndTag(nextTag.tag_name)
				) {
					return false;
				}

				sawStructureEndTag = true;
				at = nextTag.token_end;
			}
		}

		#isFosteredVoidTableStartTag(tagName) {
			return VOID_ELEMENTS.has(tagName) && !TABLE_MODE_START_TAGS.has(tagName) && tagName !== "INPUT";
		}

		#isFosteredInputStartTag(nextTag) {
			return (
				nextTag.tag_name === "INPUT" &&
				!inputStartTagHasHiddenType(this.html.slice(nextTag.tag_start, nextTag.token_end))
			);
		}

		#isFosteredInputTableStartTag(tagName) {
			if (tagName !== "INPUT") {
				return false;
			}

			const typeAttribute = this.get_attribute("type");
			return !(typeof typeAttribute === "string" && typeAttribute.toLowerCase() === "hidden");
		}

		#isFosteredAtomicTableStartTag(tagName) {
			return tagName === "TITLE";
		}

		#isFosteredElementTableStartTag(tagName) {
			return tagName === "A" || tagName === "B" || tagName === "CENTER" || tagName === "DIV" || tagName === "FONT" || tagName === "I" || tagName === "LI" || tagName === "NOBR" || tagName === "P" || tagName === "PLAINTEXT" || tagName === "S";
		}

		#shouldReconstructActiveFormattingBeforeFosteredStart(tagName) {
			if (
				this.deferred_table_opener === null ||
				this.current_namespace !== "html" ||
				!this.#hasUnreconstructedActiveFormattingElement()
			) {
				return false;
			}

			return (
				FOREIGN_CONTENT_START_TAGS.has(tagName) ||
				tagName === "SELECT" ||
				this.#isFosteredVoidTableStartTag(tagName) ||
				this.#isFosteredInputTableStartTag(tagName) ||
				this.#isFosteredAtomicTableStartTag(tagName) ||
				(tagName === "NOBR" && !this.#currentHtmlElementIs("NOBR"))
			);
		}

		#fosterParentedStartTableIndex(tagName) {
			const topIndex = this.open_elements.length - 1;
			if (this.#isRepresentableStandaloneFosteredStartInTableFragment(tagName, topIndex)) {
				return topIndex;
			}

			const tableIndex = this.#lastOpenElementIndex("TABLE", "html");
			const cellIndex = Math.max(
				this.#lastOpenElementIndex("TD", "html"),
				this.#lastOpenElementIndex("TH", "html"),
			);
			if (
				this.deferred_table_opener !== null &&
				tableIndex !== -1 &&
				cellIndex > tableIndex &&
				!TABLE_MODE_START_TAGS.has(tagName) &&
				this.#currentFosterParentedTableIndex() === null
			) {
				return -1;
			}

			if (
				this.deferred_table_opener === null ||
				this.current_namespace !== "html" ||
				(
					!FOREIGN_CONTENT_START_TAGS.has(tagName) &&
					tagName !== "SELECT" &&
					!this.#isFosteredElementTableStartTag(tagName)
				)
			) {
				return -1;
			}

			return tableIndex;
		}

		#representFosteredVoidStartBeforeDeferredTable(tagName) {
			if (
				(!this.is_full_parser && !this.#canRepresentFosteredStartInFragment()) ||
				this.deferred_table_opener === null ||
				this.current_namespace !== "html" ||
				!VOID_ELEMENTS.has(tagName) ||
				(
					TABLE_MODE_START_TAGS.has(tagName) &&
					!this.#isFosteredInputTableStartTag(tagName)
				)
			) {
				return false;
			}

			const tableIndex = this.#lastOpenElementIndex("TABLE", "html");
			if (tableIndex === -1) {
				return false;
			}

			this.current_token_namespace = this.#namespaceForCurrentStartTag(super.get_tag());
			const endIndex = this.#currentFosterParentedTableIndex() === tableIndex
				? this.open_elements.length
				: tableIndex;
			this.breadcrumbs = this.#breadcrumbStack(tagName, endIndex);
			return true;
		}

		#canRepresentFosteredStartInFragment() {
			return (
				this.context_namespace === "html" &&
				this.context_node === "BODY" &&
				this.#lastOpenElementIndex("TABLE", "html") >= this.base_open_element_count
			);
		}

		#representFosteredAtomicStartBeforeDeferredTable(tagName) {
			if (
				!this.is_full_parser ||
				this.deferred_table_opener === null ||
				this.current_namespace !== "html" ||
				!this.#isFosteredAtomicTableStartTag(tagName)
			) {
				return false;
			}

			const tableIndex = this.#lastOpenElementIndex("TABLE", "html");
			if (tableIndex === -1) {
				return false;
			}

			this.current_token_namespace = "html";
			const endIndex = this.#currentFosterParentedTableIndex() === tableIndex
				? this.open_elements.length
				: tableIndex;
			this.breadcrumbs = this.#breadcrumbStack(tagName, endIndex);
			return true;
		}

		#currentTextChunkPrecedesFosteredTableText() {
			if (this.deferred_table_opener === null) {
				return false;
			}

			const span = this.#currentRealTokenSpan();
			if (span === null) {
				return false;
			}

			const afterToken = span.start + span.length;
			const nextTag = runtime.scanNextTag(this.html, afterToken);
			const text = this.html.slice(
				afterToken,
				this.#fosterLookaheadTextEnd(afterToken, nextTag),
			);
			if (!this.#isIgnorableTableText(text)) {
				return true;
			}

			if (nextTag === false) {
				return false;
			}

			if (this.#fosteredTextFollowsIgnoredEndTag(nextTag)) {
				return true;
			}

			if (nextTag.is_closing) {
				return this.#isFosteredTableEndTag(nextTag.tag_name);
			}

			return this.#isFosteredTableLookaheadStartTag(nextTag);
		}

		#currentTextChunkPrecedesFosteredTableToken() {
			if (this.deferred_table_opener === null) {
				return false;
			}

			const span = this.#currentRealTokenSpan();
			if (span === null) {
				return false;
			}

			const afterToken = span.start + span.length;
			const nextTag = runtime.scanNextTag(this.html, afterToken);
			const text = this.html.slice(
				afterToken,
				this.#fosterLookaheadTextEnd(afterToken, nextTag),
			);
			if (!this.#isIgnorableTableText(text) || nextTag === false) {
				return false;
			}

			if (nextTag.is_closing) {
				return this.#isFosteredTableEndTag(nextTag.tag_name);
			}

			return this.#isFosteredTableLookaheadStartTag(nextTag);
		}

		#currentTextChunkPrecedesDeferredTableChildOpener() {
			if (
				this.deferred_table_opener === null ||
				!this.#currentHtmlElementIs("TABLE")
			) {
				return false;
			}

			const span = this.#currentRealTokenSpan();
			if (span === null) {
				return false;
			}

			const nextTag = this.#nextNonWhitespaceTag(span.start + span.length);
			if (
				nextTag === false ||
				nextTag.is_closing ||
				!this.#isDeferredTableChildOpenerTag(nextTag.tag_name)
			) {
				return false;
			}

			return this.#tokenEndIsFollowedByFosteredTableContent(
				nextTag.token_end,
				this.#deferredTableChildLookaheadTags(nextTag.tag_name),
			);
		}

		#currentTextChunkPrecedesIgnoredEndTagFosteredText() {
			if (this.deferred_table_opener === null) {
				return false;
			}

			const span = this.#currentRealTokenSpan();
			if (span === null) {
				return false;
			}

			const afterToken = span.start + span.length;
			const nextTag = runtime.scanNextTag(this.html, afterToken);
			const text = this.html.slice(
				afterToken,
				this.#fosterLookaheadTextEnd(afterToken, nextTag),
			);
			return (
				this.#isIgnorableTableText(text) &&
				nextTag !== false &&
				this.#fosteredTextFollowsIgnoredEndTag(nextTag)
			);
		}

		#fosteredTextFollowsIgnoredEndTag(nextTag) {
			if (
				nextTag === false ||
				!nextTag.is_closing ||
				!this.#isIgnoredFosterLookaheadEndTag(nextTag.tag_name)
			) {
				return false;
			}

			let at = nextTag.token_end;
			while (true) {
				const followingTag = runtime.scanNextTag(this.html, at);
				const text = this.html.slice(
					at,
					this.#fosterLookaheadTextEnd(at, followingTag),
				);
				if (!this.#isIgnorableTableText(text)) {
					return true;
				}

				if (
					followingTag !== false &&
					followingTag.is_closing &&
					this.#isIgnoredFosterLookaheadEndTag(followingTag.tag_name)
				) {
					at = followingTag.token_end;
					continue;
				}

				return false;
			}
		}

		#isFosteredTableLookaheadStartTag(nextTag) {
			return (
				FOREIGN_CONTENT_START_TAGS.has(nextTag.tag_name) ||
				nextTag.tag_name === "SELECT" ||
				this.#isFosteredVoidTableStartTag(nextTag.tag_name) ||
				this.#isFosteredInputStartTag(nextTag) ||
				this.#isFosteredAtomicTableStartTag(nextTag.tag_name) ||
				this.#isFosteredElementTableStartTag(nextTag.tag_name)
			);
		}

		#canRepresentNestedAnchorFosteredBeforeDeferredTable(tagName) {
			return (
				tagName === "A" &&
				this.deferred_table_opener !== null &&
				this.current_namespace === "html" &&
				this.#lastOpenElementIndex("TABLE", "html") !== -1 &&
				this.#currentFosterParentedTableIndex() === null &&
				(
					this.#nestedAnchorFosteredBeforeDeferredTablePrecedesTableContent() ||
					this.#nestedAnchorFosteredBeforeDeferredTableEnd() ||
					this.#nestedAnchorFosteredBeforeTemplateDeferredTable()
				)
			);
		}

		#nestedAnchorFosteredBeforeTemplateDeferredTable() {
			const tableIndex = this.#lastOpenElementIndex("TABLE", "html");
			const templateIndex = this.#lastOpenElementIndex("TEMPLATE", "html");
			return templateIndex !== -1 && tableIndex > templateIndex;
		}

		#nestedAnchorFosteredBeforeDeferredTableEnd() {
			const span = this.#currentRealTokenSpan();
			if (span === null) {
				return false;
			}

			const nextTag = this.#nextNonWhitespaceTag(span.start + span.length);
			return (
				nextTag !== false &&
				nextTag.is_closing &&
				nextTag.tag_name === "TABLE"
			);
		}

		#shouldRemoveNestedAnchorActiveAfterDeferredTable() {
			const span = this.#currentRealTokenSpan();
			if (span === null) {
				return false;
			}

			const tableCloser = this.#nextNonWhitespaceTag(span.start + span.length);
			if (
				tableCloser === false ||
				!tableCloser.is_closing ||
				tableCloser.tag_name !== "TABLE"
			) {
				return false;
			}

			const afterTableCloser = tableCloser.token_end;
			const nextTag = runtime.scanNextTag(this.html, afterTableCloser);
			const text = this.html.slice(
				afterTableCloser,
				this.#fosterLookaheadTextEnd(afterTableCloser, nextTag),
			);
			if (!this.#isIgnorableTableText(text)) {
				return false;
			}

			return (
				nextTag === false ||
				nextTag.is_closing ||
				(
					nextTag.tag_name === "A" ||
					(
						!FORMATTING_ELEMENTS.has(nextTag.tag_name) &&
						!ACTIVE_FORMATTING_RECONSTRUCTING_START_TAGS.has(nextTag.tag_name)
					)
				)
			);
		}

		#nestedAnchorFosteredBeforeDeferredTablePrecedesTableContent() {
			const span = this.#currentRealTokenSpan();
			if (span === null) {
				return false;
			}

			let at = span.start + span.length;
			while (true) {
				const nextTag = runtime.scanNextTag(this.html, at);
				if (nextTag === false) {
					return false;
				}

				if (nextTag.is_closing) {
					return false;
				}

				if (
					nextTag.tag_name === "TR" ||
					TABLE_CELL_ELEMENTS.has(nextTag.tag_name) ||
					TABLE_SECTION_ELEMENTS.has(nextTag.tag_name)
				) {
					return true;
				}

				at = nextTag.token_end;
			}
		}

		#fosterLookaheadTextEnd(at, nextTag) {
			let end = nextTag === false ? this.html.length : nextTag.tag_start;
			const commentStart = this.html.indexOf("<!--", at);
			if (commentStart !== -1 && commentStart < end) {
				end = commentStart;
			}
			return end;
		}

		#isIgnorableTableText(text) {
			return text.split("").every((char) => {
				const code = char.charCodeAt(0);
				return code === 0 || isHtmlWhitespaceCode(code);
			});
		}

		#currentTextHasLeadingIgnorableTableText() {
			const text = this.get_modifiable_text() ?? "";
			for (let i = 0; i < text.length; i += 1) {
				const code = text.charCodeAt(i);
				if (code !== 0 && !isHtmlWhitespaceCode(code)) {
					return i > 0;
				}
			}
			return false;
		}

		#shouldBailUnsupportedTableFosterParenting(tagName, isCloser) {
			if (this.current_namespace !== "html") {
				return false;
			}

			const topIndex = this.open_elements.length - 1;
			if (topIndex < 0 || this.open_element_namespaces[topIndex] !== "html") {
				return false;
			}

			if (
				isCloser &&
				tagName === "P" &&
				this.#canRepresentMissingParagraphCloserBeforeDeferredTable()
			) {
				return false;
			}

			if (
				!isCloser &&
				this.#isRepresentableForeignStartInTableFragment(tagName, topIndex)
			) {
				return false;
			}

			if (
				!isCloser &&
				this.#isRepresentableStandaloneFosteredStartInTableFragment(tagName, topIndex)
			) {
				return false;
			}

			if (
				!isCloser &&
				this.#canCloseTemplateRowForStartTag(tagName)
			) {
				return false;
			}

			if (
				!isCloser &&
				this.#canCloseTemplateTableBodyForStartTag(tagName)
			) {
				return false;
			}

			const currentNode = this.open_elements[topIndex];
			if (currentNode === "TABLE") {
				return this.#wouldUseUnsupportedTableFosterParenting(tagName, isCloser);
			}

			if (currentNode === "COLGROUP") {
				return (
					!(tagName === "COL" || tagName === "COLGROUP") &&
					this.#wouldUseUnsupportedTableFosterParenting(tagName, isCloser)
				);
			}

			if (TABLE_SECTION_ELEMENTS.has(currentNode)) {
				return (
					!this.#isHandledInTableBodyMode(tagName, isCloser) &&
					this.#wouldUseUnsupportedTableFosterParenting(tagName, isCloser)
				);
			}

			if (currentNode === "TR") {
				return (
					!this.#isHandledInTableRowMode(tagName, isCloser) &&
					this.#wouldUseUnsupportedTableFosterParenting(tagName, isCloser)
				);
			}

			return false;
		}

		#isRepresentableForeignStartInTableFragment(tagName, topIndex) {
			return (
				FOREIGN_CONTENT_START_TAGS.has(tagName) &&
				!this.is_full_parser &&
				this.context_namespace === "html" &&
				(
					this.context_node === "TR" ||
					TABLE_SECTION_ELEMENTS.has(this.context_node)
				) &&
				topIndex === this.base_open_element_count - 1 &&
				this.open_elements[topIndex] === this.context_node
			);
		}

		#isRepresentableStandaloneFosteredStartInTableFragment(tagName, topIndex) {
			if (
				tagName !== "A" ||
				this.is_full_parser ||
				this.context_namespace !== "html" ||
				topIndex !== this.base_open_element_count - 1 ||
				!this.#currentContextCanRepresentStandaloneFosteredStart()
			) {
				return false;
			}

			const span = this.#currentRealTokenSpan();
			if (span === null) {
				return false;
			}

			if (span.start + span.length === this.html.length) {
				return true;
			}

			return this.#currentFosteredFragmentStartIsFollowedByTableStartTag(span.start + span.length);
		}

		#currentFosteredFragmentStartIsFollowedByTableStartTag(at) {
			while (true) {
				const nextTag = runtime.scanNextTag(this.html, at);
				const text = this.html.slice(at, nextTag === false ? this.html.length : nextTag.tag_start);
				if (!this.#isIgnorableTableText(text)) {
					return false;
				}

				if (nextTag === false) {
					return false;
				}

				if (
					nextTag.is_closing &&
					this.#isIgnoredFosterLookaheadEndTag(nextTag.tag_name)
				) {
					at = nextTag.token_end;
					continue;
				}

				return !nextTag.is_closing && this.#tableFragmentContextHandlesStartTag(nextTag.tag_name);
			}
		}

		#currentContextCanRepresentStandaloneFosteredStart() {
			const topIndex = this.open_elements.length - 1;
			return (
				(this.context_node === "TABLE" && this.open_elements[topIndex] === "TABLE") ||
				(
					TABLE_SECTION_ELEMENTS.has(this.context_node) &&
					this.open_elements[topIndex] === this.context_node
				)
			);
		}

		#canCloseTemplateRowForStartTag(tagName) {
			return (
				tagName === "DIV" &&
				this.template_insertion_modes.length > 0 &&
				this.#currentTemplateInsertionMode() === "in_row" &&
				this.#currentHtmlElementIs("TR")
			);
		}

		#canCloseTemplateTableBodyForStartTag(tagName) {
			const topIndex = this.open_elements.length - 1;
			return (
				tagName === "SELECT" &&
				this.template_insertion_modes.length > 0 &&
				this.#currentTemplateInsertionMode() === "in_table_body" &&
				topIndex >= 0 &&
				this.open_element_namespaces[topIndex] === "html" &&
				TABLE_SECTION_ELEMENTS.has(this.open_elements[topIndex])
			);
		}

		#wouldUseUnsupportedTableFosterParenting(tagName, isCloser) {
			if (isCloser) {
				return !(
					tagName === "TABLE" ||
					tagName === "TEMPLATE" ||
					TABLE_MODE_IGNORED_END_TAGS.has(tagName)
				);
			}

			if (!TABLE_MODE_START_TAGS.has(tagName)) {
				return true;
			}

			if (tagName !== "INPUT") {
				return false;
			}

			const typeAttribute = this.get_attribute("type");
			return !(typeof typeAttribute === "string" && typeAttribute.toLowerCase() === "hidden");
		}

		#isHandledInTableBodyMode(tagName, isCloser) {
			if (isCloser) {
				return (
					tagName === "TABLE" ||
					TABLE_SECTION_ELEMENTS.has(tagName) ||
					TABLE_BODY_MODE_IGNORED_END_TAGS.has(tagName)
				);
			}

			return tagName === "TR" || TABLE_CELL_ELEMENTS.has(tagName) || TABLE_SECTION_BOUNDARY_START_TAGS.has(tagName);
		}

		#isHandledInTableRowMode(tagName, isCloser) {
			if (isCloser) {
				return (
					tagName === "TABLE" ||
					tagName === "TR" ||
					TABLE_SECTION_ELEMENTS.has(tagName) ||
					TABLE_ROW_MODE_IGNORED_END_TAGS.has(tagName)
				);
			}

			return TABLE_CELL_ELEMENTS.has(tagName) || TABLE_ROW_BOUNDARY_START_TAGS.has(tagName);
		}

		#tableStartTagPreclosureIndex() {
			const topIndex = this.open_elements.length - 1;
			if (topIndex < 0 || this.open_element_namespaces[topIndex] !== "html") {
				return -1;
			}

			const currentNode = this.open_elements[topIndex];
			if (
				currentNode !== "TABLE" &&
				currentNode !== "TR" &&
				!TABLE_SECTION_ELEMENTS.has(currentNode)
			) {
				return -1;
			}

			return this.#lastOpenElementIndex("TABLE", "html");
		}

		#shouldDetachFormCloser(tagName, namespaceName, formIndex) {
			if (tagName !== "FORM" || namespaceName !== "html" || formIndex === -1) {
				return false;
			}

			for (let i = this.open_elements.length - 1; i > formIndex; i -= 1) {
				if (
					this.open_element_namespaces[i] !== "html" ||
					!IMPLIED_END_TAG_ELEMENTS.has(this.open_elements[i])
				) {
					return true;
				}
			}

			return false;
		}

		#detachFormElementFromOpenStack(formIndex) {
			this.current_token_namespace = this.open_element_namespaces[formIndex];
			this.detached_breadcrumbs.push({
				index: formIndex,
				tagName: this.open_elements[formIndex],
			});
			this.open_elements.splice(formIndex, 1);
			this.open_element_namespaces.splice(formIndex, 1);
			this.open_element_integration_node_types.splice(formIndex, 1);
			this.open_element_foster_parented_table_indices.splice(formIndex, 1);
			this.breadcrumbs = this.#breadcrumbStack();
			this.#setCurrentNamespace(this.#namespaceForStackTop());
		}

		#pruneDetachedBreadcrumbs() {
			this.detached_breadcrumbs = this.detached_breadcrumbs.filter(
				(breadcrumb) => breadcrumb.index < this.open_elements.length,
			);
		}

		#hasOnlyTableElementsAfter(index) {
			if (index >= this.open_elements.length - 1) {
				return false;
			}

			for (let i = index + 1; i < this.open_elements.length; i += 1) {
				if (
					this.open_element_namespaces[i] !== "html" ||
					!FORM_TABLE_DESCENDANT_ELEMENTS.has(this.open_elements[i])
				) {
					return false;
				}
			}

			return true;
		}

		#hasHtmlScopeBoundaryAfter(index, boundaries) {
			for (let i = index + 1; i < this.open_elements.length; i += 1) {
				if (boundaries.has(this.#scopeBoundaryNameForOpenElement(i))) {
					return true;
				}
			}

			return false;
		}

		#shouldIgnoreAdoptionAgencyEndTagOutsideScope(tagName, namespaceName, formattingElementIndex) {
			return (
				namespaceName === "html" &&
				formattingElementIndex !== -1 &&
				ADOPTION_AGENCY_END_TAGS.has(tagName) &&
				this.#lastActiveFormattingElementIndex(tagName) !== -1 &&
				this.#hasHtmlScopeBoundaryAfter(formattingElementIndex, DEFAULT_SCOPE_BOUNDARIES)
			);
		}

		#shouldIgnoreCappedDeepAnchorEndTag(tagName, namespaceName, formattingElementIndex) {
			return (
				tagName === "A" &&
				namespaceName === "html" &&
				formattingElementIndex !== -1 &&
				this.#lastActiveFormattingElementIndex("A") !== -1 &&
				this.#hasSpecialStartAdoptionPreclosedFormattingElement(
					"A",
					"html",
					"DIV",
					"reconstruct-before-nested-div",
				) &&
				this.#countOpenHtmlElementsAfterLast("B", "DIV") >= 8 &&
				hasSpecialBoundaryAfter(
					this.open_elements,
					this.open_element_namespaces,
					formattingElementIndex,
				)
			);
		}

		#shouldIgnoreAdoptionAgencyEndTagWithStaleEntry(tagName, namespaceName) {
			if (
				namespaceName !== "html" ||
				!ADOPTION_AGENCY_END_TAGS.has(tagName) ||
				this.#lastActiveFormattingElementIndex(tagName) === -1
			) {
				return false;
			}

			let activeCount = 0;
			for (let i = this.active_formatting_elements.length - 1; i >= 0; i -= 1) {
				const entry = this.active_formatting_elements[i];
				if (this.#isActiveFormattingMarker(entry)) {
					break;
				}
				if (entry.tagName === tagName && entry.namespaceName === "html") {
					activeCount += 1;
				}
			}

			return activeCount > this.#countOpenHtmlElements(tagName);
		}

		#queueParagraphAdoptionReconstructionForEndTag(tagName, namespaceName, formattingElementIndex) {
			if (
				namespaceName !== "html" ||
				formattingElementIndex !== -1 ||
				!ADOPTION_AGENCY_END_TAGS.has(tagName) ||
				this.#lastActiveFormattingElementIndex(tagName) === -1 ||
				this.open_element_namespaces.at(-1) !== "html" ||
				this.open_elements.at(-1) !== "P"
			) {
				return false;
			}

			const marker = this.#consumeParagraphAdoptionPreclosedFormattingElement(tagName, namespaceName);
			if (marker === null) {
				return false;
			}

			return marker.reconstructionMode === "following-inside"
				? this.#queueActiveFormattingElementWithFollowingElements(tagName, namespaceName)
				: this.#queueActiveFormattingElement(tagName, namespaceName);
		}

		#queueSpecialStartAdoptionReconstructionForEndTag(tagName, namespaceName, formattingElementIndex) {
			const containerTagName = this.open_elements.at(-1);

			if (
				namespaceName !== "html" ||
				formattingElementIndex !== -1 ||
				!ADOPTION_AGENCY_END_TAGS.has(tagName) ||
				this.#lastActiveFormattingElementIndex(tagName) === -1 ||
				this.open_element_namespaces.at(-1) !== "html" ||
				!FORMATTING_ELEMENT_SPECIAL_PRECLOSURE_START_TAGS.has(containerTagName)
			) {
				return false;
			}

			const marker = this.#consumeSpecialStartAdoptionPreclosedFormattingElement(tagName, namespaceName, containerTagName);
			if (marker === null) {
				return false;
			}

			if (marker.reconstructionMode === "empty-following-then-self") {
				return this.#queueActiveFormattingElementWithFollowingElements(tagName, namespaceName);
			}

			return this.#queueActiveFormattingElement(tagName, namespaceName);
		}

		#shouldBailUnsupportedAdoptionAgency(tagName, namespaceName, formattingElementIndex) {
			return (
				namespaceName === "html" &&
				formattingElementIndex !== -1 &&
				ADOPTION_AGENCY_END_TAGS.has(tagName) &&
				this.#lastActiveFormattingElementIndex(tagName) !== -1 &&
				hasSpecialBoundaryAfter(
					this.open_elements,
					this.open_element_namespaces,
					formattingElementIndex,
				)
			);
		}

		#shouldIgnoreAdoptionAgencyEndTagFallback(tagName, namespaceName, formattingElementIndex) {
			return (
				namespaceName === "html" &&
				formattingElementIndex === -1 &&
				ADOPTION_AGENCY_END_TAGS.has(tagName) &&
				this.#lastActiveFormattingElementIndex(tagName) === -1
			);
		}

		#findOpenElementBeforeBoundary(match, boundaries) {
			const predicate = typeof match === "function" ? match : (nodeName) => nodeName === match;
			for (let i = this.open_elements.length - 1; i >= 0; i -= 1) {
				const nodeName = this.open_elements[i];
				const namespaceName = this.open_element_namespaces[i];
				if (namespaceName === "html" && predicate(nodeName)) {
					return i;
				}

				if (boundaries.has(this.#scopeBoundaryNameForOpenElement(i))) {
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

			if (inHtml && SPECIAL_ATOMIC_ELEMENTS.has(tagName)) {
				let text = this.get_modifiable_text() ?? "";
				if (tagName === "IFRAME" || tagName === "NOEMBED" || tagName === "NOFRAMES") {
					text = "";
				} else if (tagName !== "SCRIPT" && tagName !== "STYLE") {
					text = htmlEscape(text);
				}
				if (tagName === "TEXTAREA" && text.startsWith("\n")) {
					html += "\n";
				}
				html += `${text}</${qualifiedName}>`;
			}

			return html;
		}

		#namespaceForStackTop() {
			if (
				this.open_element_namespaces.length === 1 &&
				this.open_elements[0] === "HTML" &&
				this.detached_context_breadcrumbs.length > 0
			) {
				return this.#childNamespaceForStackEntry(
					this.context_node,
					this.context_namespace,
					this.context_integration_node_type,
				);
			}

			return this.open_element_namespaces.length === 0
				? "html"
				: this.#childNamespaceForStackEntry(
					this.open_elements[this.open_elements.length - 1],
					this.open_element_namespaces[this.open_element_namespaces.length - 1],
					this.open_element_integration_node_types[this.open_element_integration_node_types.length - 1],
				);
		}

		#setCurrentNamespace(namespaceName) {
			this.current_namespace = namespaceName;
			super.change_parsing_namespace(namespaceName);
		}
	}

	return {
		WP_HTML_Decoder,
		WP_HTML_Unsupported_Exception,
		WP_HTML_Span,
		WP_HTML_Text_Replacement,
		WP_HTML_Attribute_Token,
		WP_HTML_Token,
		WP_HTML_Stack_Event,
		WP_HTML_Active_Formatting_Elements,
		WP_HTML_Open_Elements,
		WP_HTML_Processor_State,
		WP_HTML_Tag_Processor,
		WP_HTML_Processor,
		WP_HTML_Doctype_Info,
		scanNextTag: (html, offset = 0) => runtime.scanNextTag(html, offset),
		version: () => runtime.version(),
		wasm: wasmExports,
	};
}

function wasmExportsFromInput(input) {
	if (isWebAssemblyInstantiatedSource(input)) {
		return input.instance.exports;
	}

	if (input instanceof WebAssembly.Instance) {
		return input.exports;
	}

	return input;
}

const REQUIRED_WASM_FUNCTION_EXPORTS = [
	"wp_html_api_rust_alloc",
	"wp_html_api_rust_core_version",
	"wp_html_api_rust_dealloc",
	"wp_html_api_rust_decoder_attribute_starts_with",
	"wp_html_api_rust_decoder_code_point_to_utf8_bytes",
	"wp_html_api_rust_decoder_decode",
	"wp_html_api_rust_decoder_read_character_reference",
	"wp_html_api_rust_scan_next_tag",
	"wp_html_api_rust_tag_processor_add_class",
	"wp_html_api_rust_tag_processor_apply_lexical_update",
	"wp_html_api_rust_tag_processor_class_list",
	"wp_html_api_rust_tag_processor_current_comment_type",
	"wp_html_api_rust_tag_processor_current_span",
	"wp_html_api_rust_tag_processor_current_token_type",
	"wp_html_api_rust_tag_processor_free",
	"wp_html_api_rust_tag_processor_get_attribute",
	"wp_html_api_rust_tag_processor_get_attribute_names_with_prefix",
	"wp_html_api_rust_tag_processor_get_html",
	"wp_html_api_rust_tag_processor_get_modifiable_text",
	"wp_html_api_rust_tag_processor_get_tag",
	"wp_html_api_rust_tag_processor_has_class",
	"wp_html_api_rust_tag_processor_has_self_closing_flag",
	"wp_html_api_rust_tag_processor_is_tag_closer",
	"wp_html_api_rust_tag_processor_new",
	"wp_html_api_rust_tag_processor_next_tag",
	"wp_html_api_rust_tag_processor_next_token",
	"wp_html_api_rust_tag_processor_paused_at_incomplete",
	"wp_html_api_rust_tag_processor_remove_attribute",
	"wp_html_api_rust_tag_processor_remove_class",
	"wp_html_api_rust_tag_processor_script_content_type",
	"wp_html_api_rust_tag_processor_seek",
	"wp_html_api_rust_tag_processor_set_attribute",
	"wp_html_api_rust_tag_processor_set_modifiable_text",
	"wp_html_api_rust_tag_processor_set_namespace",
	"wp_html_api_rust_tag_processor_subdivide_text_appropriately",
];

function isWebAssemblyExports(input) {
	return input !== null &&
		typeof input === "object" &&
		input.memory instanceof WebAssembly.Memory &&
		typeof input.wp_html_api_rust_alloc === "function" &&
		typeof input.wp_html_api_rust_dealloc === "function";
}

function validateWasmExports(wasm) {
	const missing = [];
	if (!(wasm.memory instanceof WebAssembly.Memory)) {
		missing.push("memory");
	}
	for (const exportName of REQUIRED_WASM_FUNCTION_EXPORTS) {
		if (typeof wasm[exportName] !== "function") {
			missing.push(exportName);
		}
	}
	if (missing.length > 0) {
		throw new Error(`WASM module is missing required HTML API exports: ${missing.join(", ")}.`);
	}
}

class WasmRuntime {
	constructor(wasm) {
		this.wasm = wasm;
		if (!isWebAssemblyExports(wasm)) {
			throw new Error("WASM module does not expose the expected HTML API runtime functions.");
		}
		validateWasmExports(wasm);
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

	decoderDecode(context, text) {
		const input = this.encode(text);
		const contextKind = decodeContextKind(context);
		return this.withBytes(input, ({ ptr, len }) => {
			const output = this.allocBytes(new Uint8Array(Math.max(1, len)));
			const outLen = this.allocBytes(new Uint8Array(4));
			try {
				if (!this.wasm.wp_html_api_rust_decoder_decode(
					contextKind,
					ptr,
					len,
					output.ptr,
					output.allocationLen,
					outLen.ptr,
				)) {
					return "";
				}

				const decodedLen = this.readU32(outLen.ptr);
				return textDecoder.decode(this.bytes().subarray(output.ptr, output.ptr + decodedLen));
			} finally {
				this.freeBytes(outLen);
				this.freeBytes(output);
			}
		});
	}

	decoderReadCharacterReference(context, text, at = 0, matchByteLength = null) {
		const input = this.encode(text);
		const contextKind = decodeContextKind(context);
		const normalizedAt = phpStringOffsetParameterCoerce(at, "at");
		if (!Number.isFinite(normalizedAt) || normalizedAt < 0) {
			return null;
		}
		return this.withBytes(input, ({ ptr, len }) => {
			const outputCapacity = Math.max(4, len - normalizedAt);
			const output = this.allocBytes(new Uint8Array(outputCapacity));
			const lengths = this.allocBytes(new Uint8Array(8));
			try {
				if (!this.wasm.wp_html_api_rust_decoder_read_character_reference(
					contextKind,
					ptr,
					len,
					normalizedAt,
					output.ptr,
					output.allocationLen,
					lengths.ptr,
					lengths.ptr + 4,
				)) {
					return null;
				}

				if (matchByteLength && typeof matchByteLength === "object") {
					matchByteLength.value = this.readU32(lengths.ptr + 4);
				}

				const decodedLen = this.readU32(lengths.ptr);
				return textDecoder.decode(this.bytes().subarray(output.ptr, output.ptr + decodedLen));
			} finally {
				this.freeBytes(lengths);
				this.freeBytes(output);
			}
		});
	}

	decoderAttributeStartsWith(haystack, searchText, asciiCaseInsensitive) {
		return this.withEncoded(haystack, (haystackBytes) => (
			this.withEncoded(searchText, (searchBytes) => (
				Boolean(this.wasm.wp_html_api_rust_decoder_attribute_starts_with(
					haystackBytes.ptr,
					haystackBytes.len,
					searchBytes.ptr,
					searchBytes.len,
					Boolean(asciiCaseInsensitive),
				))
			))
		));
	}

	decoderCodePointToUtf8Bytes(codePoint) {
		const numericCodePoint = phpInternalIntegerParameterCoerce(codePoint, "code_point");
		const normalizedCodePoint = Number.isFinite(numericCodePoint) &&
			numericCodePoint >= 0 &&
			numericCodePoint <= 0x10ffff
			? Math.trunc(numericCodePoint)
			: 0x110000;
		const output = this.allocBytes(new Uint8Array(4));
		const outLen = this.allocBytes(new Uint8Array(4));
		try {
			if (!this.wasm.wp_html_api_rust_decoder_code_point_to_utf8_bytes(
				normalizedCodePoint,
				output.ptr,
				output.allocationLen,
				outLen.ptr,
			)) {
				return "\uFFFD";
			}

			const decodedLen = this.readU32(outLen.ptr);
			return textDecoder.decode(this.bytes().subarray(output.ptr, output.ptr + decodedLen));
		} finally {
			this.freeBytes(outLen);
			this.freeBytes(output);
		}
	}

	scanNextTag(html, offset = 0) {
		const input = this.encode(html);
		const normalizedOffset = Math.max(0, phpIntegerCast(offset));
		return this.withBytes(input, ({ ptr, len }) => {
			const out = this.allocBytes(new Uint8Array(32));
			try {
				if (!this.wasm.wp_html_api_rust_scan_next_tag(ptr, len, normalizedOffset, out.ptr)) {
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
					name_length: nameLen,
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

function decodeContextKind(context) {
	return context === "attribute" ? DECODE_CONTEXT_ATTRIBUTE : DECODE_CONTEXT_DATA;
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

function createDoctypeInfo(name, publicIdentifier, systemIdentifier, forceQuirksFlag) {
	return new WP_HTML_Doctype_Info(
		name,
		publicIdentifier,
		systemIdentifier,
		forceQuirksFlag,
		DOCTYPE_INFO_INTERNAL,
	);
}

function parsePublicIdentifier(doctype, at, end, name) {
	const quote = doctype[at];
	if (quote !== '"' && quote !== "'") {
		return createDoctypeInfo(name, null, null, true);
	}

	at += 1;
	const identifierStart = at;
	const identifierEnd = doctype.indexOf(quote, at);
	const boundedIdentifierEnd = identifierEnd === -1 || identifierEnd > end ? end : identifierEnd;
	const publicIdentifier = replaceNulls(doctype.slice(identifierStart, boundedIdentifierEnd));

	if (identifierEnd === -1 || identifierEnd >= end || doctype[identifierEnd] !== quote) {
		return createDoctypeInfo(name, publicIdentifier, null, true);
	}

	at = skipHtmlWhitespace(doctype, identifierEnd + 1, end);
	if (at >= end) {
		return createDoctypeInfo(name, publicIdentifier, null, false);
	}

	return parseSystemIdentifier(doctype, at, end, name, publicIdentifier);
}

function parseSystemIdentifier(doctype, at, end, name, publicIdentifier) {
	const quote = doctype[at];
	if (quote !== '"' && quote !== "'") {
		return createDoctypeInfo(name, publicIdentifier, null, true);
	}

	at += 1;
	const identifierStart = at;
	const identifierEnd = doctype.indexOf(quote, at);
	const boundedIdentifierEnd = identifierEnd === -1 || identifierEnd > end ? end : identifierEnd;
	const systemIdentifier = replaceNulls(doctype.slice(identifierStart, boundedIdentifierEnd));

	if (identifierEnd === -1 || identifierEnd >= end || doctype[identifierEnd] !== quote) {
		return createDoctypeInfo(name, publicIdentifier, systemIdentifier, true);
	}

	return createDoctypeInfo(name, publicIdentifier, systemIdentifier, false);
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

function completeStartTagAt(value, at) {
	const end = value.length;
	if (value.charCodeAt(at) !== 0x3c /* < */) {
		return null;
	}

	let nameStart = at + 1;
	if (nameStart >= end || !isAsciiAlphaCode(value.charCodeAt(nameStart))) {
		return null;
	}

	let nameEnd = nameStart + 1;
	while (nameEnd < end && !isTagNameDelimiterCode(value.charCodeAt(nameEnd))) {
		nameEnd += 1;
	}

	let quote = null;
	for (let i = nameEnd; i < end; i += 1) {
		const code = value.charCodeAt(i);
		if (quote !== null) {
			if (code === quote) {
				quote = null;
			}
			continue;
		}

		if (code === 0x22 /* " */ || code === 0x27 /* ' */) {
			quote = code;
			continue;
		}

		if (code === 0x3e /* > */) {
			return {
				tagName: asciiUpper(value.slice(nameStart, nameEnd)),
				end: i + 1,
			};
		}
	}

	return null;
}

function inputStartTagHasHiddenType(markup) {
	const match = /(?:^|[\t\n\f\r /])type(?:[\t\n\f\r ]*=[\t\n\f\r ]*(?:"([^"]*)"|'([^']*)'|([^\t\n\f\r />]*)))?/i.exec(markup);
	if (match === null) {
		return false;
	}

	const value = match[1] ?? match[2] ?? match[3] ?? "";
	return value.toLowerCase() === "hidden";
}

function incompleteBogusCommentAtEof(value) {
	if (value.includes(">")) {
		return false;
	}

	return value.startsWith("<!") || value.startsWith("<?") || /^<\/[^A-Za-z>]/.test(value);
}

function findSpecialAtomicCloserEnd(value, offset, tagName) {
	if (tagName === "SCRIPT") {
		return findScriptCloserEnd(value, offset);
	}

	let at = offset;
	while (at + tagName.length + 2 <= value.length) {
		const closerStart = value.indexOf("</", at);
		if (closerStart === -1) {
			return null;
		}

		const nameStart = closerStart + 2;
		const nameEnd = nameStart + tagName.length;
		if (
			nameEnd <= value.length &&
			asciiStartsWithAt(value, tagName, nameStart) &&
			(nameEnd === value.length || isTagNameDelimiterCode(value.charCodeAt(nameEnd)))
		) {
			return completeTagEndAfterName(value, nameEnd);
		}

		at = closerStart + 2;
	}

	return null;
}

function findScriptCloserEnd(value, offset) {
	let at = offset;
	let escaped = false;
	let doubleEscaped = false;

	while (at < value.length) {
		if (value.startsWith("<!-->", at)) {
			at += 5;
			continue;
		}

		if (value.startsWith("<!--", at)) {
			escaped = true;
			doubleEscaped = false;
			at += 4;
			continue;
		}

		if ((escaped || doubleEscaped) && value.startsWith("-->", at)) {
			escaped = false;
			doubleEscaped = false;
			at += 3;
			continue;
		}

		if (asciiStartsWithAt(value, "</script", at)) {
			const nameEnd = at + "</script".length;
			if (nameEnd === value.length || isTagNameDelimiterCode(value.charCodeAt(nameEnd))) {
				if (doubleEscaped) {
					doubleEscaped = false;
					escaped = true;
					at = nameEnd;
					continue;
				}

				return completeTagEndAfterName(value, nameEnd);
			}
		}

		if (escaped && asciiStartsWithAt(value, "<script", at)) {
			const nameEnd = at + "<script".length;
			if (nameEnd === value.length || isTagNameDelimiterCode(value.charCodeAt(nameEnd))) {
				doubleEscaped = true;
				at = nameEnd;
				continue;
			}
		}

		at += 1;
	}

	return null;
}

function completeTagEndAfterName(value, nameEnd) {
	let quote = null;
	for (let i = nameEnd; i < value.length; i += 1) {
		const code = value.charCodeAt(i);
		if (quote !== null) {
			if (code === quote) {
				quote = null;
			}
			continue;
		}

		if (code === 0x22 /* " */ || code === 0x27 /* ' */) {
			quote = code;
			continue;
		}

		if (code === 0x3e /* > */) {
			return i + 1;
		}
	}

	return null;
}

function incompleteEndTagAt(value, at) {
	const end = value.length;
	if (
		value.charCodeAt(at) !== 0x3c /* < */ ||
		value.charCodeAt(at + 1) !== 0x2f /* / */
	) {
		return false;
	}

	const nameStart = at + 2;
	if (nameStart >= end || !isAsciiAlphaCode(value.charCodeAt(nameStart))) {
		return false;
	}

	for (let i = nameStart + 1; i < end; i += 1) {
		const code = value.charCodeAt(i);
		if (code === 0x3c /* < */ || code === 0x3e /* > */) {
			return false;
		}
	}

	return true;
}

function incompleteQuotedStartTagAt(value, at) {
	const end = value.length;
	if (value.charCodeAt(at) !== 0x3c /* < */) {
		return false;
	}

	let nameStart = at + 1;
	if (nameStart >= end || !isAsciiAlphaCode(value.charCodeAt(nameStart))) {
		return false;
	}

	let nameEnd = nameStart + 1;
	while (nameEnd < end && !isTagNameDelimiterCode(value.charCodeAt(nameEnd))) {
		nameEnd += 1;
	}

	let afterEquals = false;
	let quote = null;
	for (let i = nameEnd; i < end; i += 1) {
		const code = value.charCodeAt(i);
		if (quote !== null) {
			if (code === quote) {
				quote = null;
			}
			continue;
		}

		if (afterEquals && (code === 0x22 /* " */ || code === 0x27 /* ' */)) {
			quote = code;
			afterEquals = false;
			continue;
		}

		if (code === 0x3d /* = */) {
			afterEquals = true;
			continue;
		}

		if (code === 0x3e /* > */ || code === 0x3c /* < */) {
			return false;
		}

		if (!isHtmlWhitespaceCode(code)) {
			afterEquals = false;
		}
	}

	return quote !== null;
}

function incompleteStartTagAt(value, at) {
	const end = value.length;
	if (value.charCodeAt(at) !== 0x3c /* < */) {
		return false;
	}

	const nameStart = at + 1;
	if (nameStart >= end || !isAsciiAlphaCode(value.charCodeAt(nameStart))) {
		return false;
	}

	for (let i = nameStart + 1; i < end; i += 1) {
		const code = value.charCodeAt(i);
		if (code === 0x3c /* < */ || code === 0x3e /* > */) {
			return false;
		}
	}

	return true;
}

function isHtmlWhitespaceCode(code) {
	return code === 0x20 || code === 0x09 || code === 0x0a || code === 0x0c || code === 0x0d;
}

function isAsciiAlphaCode(code) {
	return (code >= 0x41 && code <= 0x5a) || (code >= 0x61 && code <= 0x7a);
}

function isTagNameDelimiterCode(code) {
	return code === 0x20 ||
		code === 0x09 ||
		code === 0x0a ||
		code === 0x0c ||
		code === 0x0d ||
		code === 0x2f ||
		code === 0x3e;
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

function isValidAttributeName(value) {
	if (value.length === 0) {
		return false;
	}

	for (let i = 0; i < value.length; i += 1) {
		const code = value.codePointAt(i);
		if (code > 0xffff) {
			i += 1;
		}

		if (
			code <= 0x20 ||
			code === 0x22 ||
			code === 0x26 ||
			code === 0x27 ||
			code === 0x2f ||
			code === 0x3c ||
			code === 0x3d ||
			code === 0x3e ||
			isUnicodeNoncharacter(code)
		) {
			return false;
		}
	}

	return true;
}

function isUnicodeNoncharacter(code) {
	return (
		(code >= 0xfdd0 && code <= 0xfdef) ||
		(code <= 0x10ffff && (code & 0xfffe) === 0xfffe)
	);
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
	if (Array.isArray(value)) {
		return value.length === 0 ? 0 : 1;
	}
	if (value !== null && (typeof value === "object" || typeof value === "function")) {
		return 1;
	}
	return 0;
}

const PHP_INT_MIN = -9223372036854775808n;
const PHP_INT_MAX = 9223372036854775807n;
const PHP_INT_MIN_NUMBER = Number(PHP_INT_MIN);
const PHP_INT_MAX_NUMBER = Number(PHP_INT_MAX);

function isPhpIntegerArrayKeyString(value) {
	if (value === "0") {
		return true;
	}
	if (value === "" || value[0] === "+") {
		return false;
	}

	const digits = value[0] === "-" ? value.slice(1) : value;
	if (digits === "" || digits[0] === "0" || !/^\d+$/.test(digits)) {
		return false;
	}

	const integer = BigInt(value);
	return integer >= PHP_INT_MIN && integer <= PHP_INT_MAX;
}

function phpArrayKeyParameterCoerce(value, parameterName) {
	if (typeof value === "string") {
		return isPhpIntegerArrayKeyString(value) ? `i:${BigInt(value).toString()}` : `s:${value}`;
	}

	if (typeof value === "number") {
		const integer = Number.isFinite(value) ? Math.trunc(value) : 0;
		return `i:${Object.is(integer, -0) ? 0 : integer}`;
	}

	if (typeof value === "boolean") {
		return `i:${value ? 1 : 0}`;
	}

	if (value === null) {
		return "s:";
	}

	throw new TypeError(`Argument $${parameterName} must be of type array-key.`);
}

function phpClassUpdateKey(value, parameterName) {
	if (typeof value === "string") {
		if (isPhpIntegerArrayKeyString(value)) {
			return { isIntegerKey: true, name: BigInt(value).toString() };
		}
		return { isIntegerKey: false, name: value };
	}

	if (typeof value === "number") {
		const integer = phpIntegerCast(value);
		return { isIntegerKey: true, name: String(Object.is(integer, -0) ? 0 : integer) };
	}

	if (typeof value === "boolean") {
		return { isIntegerKey: true, name: value ? "1" : "0" };
	}

	if (value === null) {
		return { isIntegerKey: false, name: "" };
	}

	throw new TypeError(`Argument $${parameterName} must be of type array-key.`);
}

function classUpdateMapKey(className) {
	return `${className.isIntegerKey ? "i" : "s"}:${className.name}`;
}

function classUpdateComparable(className, isQuirksMode) {
	if (isQuirksMode) {
		return `s:${asciiLower(className.name)}`;
	}

	return classUpdateMapKey(className);
}

function classTokenComparable(className, isQuirksMode) {
	return `s:${isQuirksMode ? asciiLower(className) : className}`;
}

function applyClassNameUpdates(existingClass, updates, isQuirksMode) {
	let className = "";
	let at = 0;
	let modified = false;
	const seen = new Set();
	const toRemove = new Set(
		updates
			.filter((update) => update.operation === CLASS_UPDATE_REMOVE)
			.map((update) => classUpdateComparable(update, isQuirksMode)),
	);

	while (at < existingClass.length) {
		const whitespaceStart = at;
		while (at < existingClass.length && isHtmlClassWhitespace(existingClass[at])) {
			at += 1;
		}

		const nameStart = at;
		while (at < existingClass.length && !isHtmlClassWhitespace(existingClass[at])) {
			at += 1;
		}

		if (nameStart === at) {
			break;
		}

		const name = existingClass.slice(nameStart, at);
		const comparableName = classTokenComparable(name, isQuirksMode);
		if (toRemove.has(comparableName)) {
			modified = true;
			continue;
		}

		if (seen.has(comparableName)) {
			continue;
		}

		seen.add(comparableName);
		if (className !== "") {
			className += existingClass.slice(whitespaceStart, nameStart);
		}
		className += name;
	}

	for (const update of updates) {
		const comparableName = classUpdateComparable(update, isQuirksMode);
		if (update.operation === CLASS_UPDATE_ADD && !seen.has(comparableName)) {
			modified = true;
			className += className.length > 0 ? " " : "";
			className += update.name;
		}
	}

	return { className, modified };
}

function isHtmlClassWhitespace(value) {
	return value === " " || value === "\t" || value === "\n" || value === "\f" || value === "\r";
}

function phpIntegerParameterCoerce(value, parameterName) {
	if (value === null) {
		throw new TypeError(`Argument $${parameterName} must be of type int.`);
	}

	if (typeof value === "string") {
		const trimmed = value.trim();
		if (
			trimmed === "" ||
			!/^[+-]?(?:(?:\d+\.?\d*)|(?:\.\d+))(?:[eE][+-]?\d+)?$/.test(trimmed)
		) {
			throw new TypeError(`Argument $${parameterName} must be numeric.`);
		}
		if (/^\+?\d+$/.test(trimmed) && BigInt(trimmed) > PHP_INT_MAX) {
			throw new TypeError(`Argument $${parameterName} must be of type int.`);
		}
		const numericValue = Number(trimmed);
		if (
			!Number.isFinite(numericValue) ||
			numericValue > PHP_INT_MAX_NUMBER ||
			numericValue < PHP_INT_MIN_NUMBER
		) {
			throw new TypeError(`Argument $${parameterName} must be of type int.`);
		}
		return Math.trunc(numericValue);
	}

	if (
		typeof value === "number" &&
		(
			!Number.isFinite(value) ||
			value > PHP_INT_MAX_NUMBER ||
			value < PHP_INT_MIN_NUMBER
		)
	) {
		throw new TypeError(`Argument $${parameterName} must be of type int.`);
	}

	if (
		typeof value === "object" ||
		typeof value === "function" ||
		typeof value === "symbol" ||
		typeof value === "undefined"
	) {
		throw new TypeError(`Argument $${parameterName} must be of type int.`);
	}

	return phpIntegerCast(value);
}

function phpInternalIntegerParameterCoerce(value, parameterName) {
	if (value === null) {
		return 0;
	}

	return phpIntegerParameterCoerce(value, parameterName);
}

function phpUntypedStringLength(value, parameterName) {
	if (value === null || value === false) {
		return 0;
	}
	if (value === true) {
		return 1;
	}
	if (typeof value === "string") {
		return value.length;
	}
	if (typeof value === "number") {
		return phpNumberToString(value).length;
	}
	throw new TypeError(`Argument $${parameterName} must be of type string.`);
}

function phpAttributeStartsWithNonStringScalar(haystack, searchText) {
	const haystackLength = phpUntypedStringLength(haystack, "haystack");
	const searchLength = phpUntypedStringLength(searchText, "search_text");

	if (searchLength === 0 || haystackLength === 0) {
		return true;
	}

	return typeof haystack !== "string" && typeof searchText !== "string";
}

function phpStringOffsetParameterCoerce(value, parameterName) {
	if (value === null) {
		return 0;
	}

	if (typeof value === "number") {
		return Number.isFinite(value) ? Math.trunc(value) : value;
	}

	if (typeof value === "boolean") {
		return value ? 1 : 0;
	}

	if (typeof value === "string") {
		const trimmed = value.trim();
		if (!/^[+-]?\d+$/.test(trimmed)) {
			throw new TypeError(`Argument $${parameterName} must be of type int.`);
		}
		return Number.parseInt(trimmed, 10);
	}

	throw new TypeError(`Argument $${parameterName} must be of type int.`);
}

function phpStringParameterCoerce(value, parameterName, nullable = false) {
	if (value === null) {
		if (nullable) {
			return null;
		}
		throw new TypeError(`Argument $${parameterName} must be of type string.`);
	}

	if (
		typeof value === "object" ||
		typeof value === "function" ||
		typeof value === "symbol" ||
		typeof value === "undefined"
	) {
		throw new TypeError(`Argument $${parameterName} must be of type string.`);
	}

	if (typeof value === "boolean") {
		return value ? "1" : "";
	}

	if (typeof value === "number") {
		return phpNumberToString(value);
	}

	return String(value);
}

function phpInternalStringCoerce(value, parameterName) {
	if (value === null) {
		return "";
	}

	if (
		typeof value === "object" ||
		typeof value === "function" ||
		typeof value === "symbol" ||
		typeof value === "undefined"
	) {
		throw new TypeError(`Argument $${parameterName} must be of type string.`);
	}

	if (typeof value === "boolean") {
		return value ? "1" : "";
	}

	if (typeof value === "number") {
		return phpNumberToString(value);
	}

	return String(value);
}

function phpInterpolatedStringCoerce(value, parameterName) {
	if (Array.isArray(value)) {
		return "Array";
	}

	if (value === null) {
		return "";
	}

	if (
		typeof value === "object" ||
		typeof value === "function" ||
		typeof value === "symbol" ||
		typeof value === "undefined"
	) {
		throw new TypeError(`Argument $${parameterName} could not be converted to string.`);
	}

	if (typeof value === "boolean") {
		return value ? "1" : "";
	}

	if (typeof value === "number") {
		return phpNumberToString(value);
	}

	return String(value);
}

function phpNumberToString(value) {
	if (Number.isNaN(value)) {
		return "NAN";
	}
	if (value === Infinity) {
		return "INF";
	}
	if (value === -Infinity) {
		return "-INF";
	}
	if (Object.is(value, -0)) {
		return "-0";
	}
	if (value === 0) {
		return "0";
	}
	if (Number.isSafeInteger(value)) {
		return String(value);
	}
	return phpFloatToString(value);
}

const PHP_FLOAT_STRING_PRECISION = 14;

const cachedPowersOfTen = new Map([[0, 1n]]);

function powerOfTen(exponent) {
	let power = cachedPowersOfTen.get(exponent);
	if (power === undefined) {
		power = 10n ** BigInt(exponent);
		cachedPowersOfTen.set(exponent, power);
	}
	return power;
}

function doubleParts(value) {
	const buffer = new ArrayBuffer(8);
	const view = new DataView(buffer);
	view.setFloat64(0, Math.abs(value), false);

	const high = view.getUint32(0, false);
	const low = view.getUint32(4, false);
	const exponentBits = (high >>> 20) & 0x7ff;
	const significandBits = (BigInt(high & 0xfffff) << 32n) | BigInt(low);

	if (exponentBits === 0) {
		return {
			exponent: -1074,
			significand: significandBits,
		};
	}

	return {
		exponent: exponentBits - 1023 - 52,
		significand: (1n << 52n) | significandBits,
	};
}

function compareDoublePartsToPowerOfTen(parts, decimalExponent) {
	let leftNumerator = parts.significand;
	let leftDenominator = 1n;
	let rightNumerator = 1n;
	let rightDenominator = 1n;

	if (parts.exponent >= 0) {
		leftNumerator <<= BigInt(parts.exponent);
	} else {
		leftDenominator <<= BigInt(-parts.exponent);
	}

	if (decimalExponent >= 0) {
		rightNumerator = powerOfTen(decimalExponent);
	} else {
		rightDenominator = powerOfTen(-decimalExponent);
	}

	const left = leftNumerator * rightDenominator;
	const right = rightNumerator * leftDenominator;

	return left < right ? -1 : left > right ? 1 : 0;
}

function decimalExponentForDouble(value, parts) {
	let decimalExponent = Math.floor(Math.log10(Math.abs(value)));

	while (compareDoublePartsToPowerOfTen(parts, decimalExponent) < 0) {
		decimalExponent -= 1;
	}
	while (compareDoublePartsToPowerOfTen(parts, decimalExponent + 1) >= 0) {
		decimalExponent += 1;
	}

	return decimalExponent;
}

function roundQuotientToEven(numerator, denominator) {
	const quotient = numerator / denominator;
	const doubledRemainder = (numerator % denominator) * 2n;

	if (
		doubledRemainder > denominator ||
		(doubledRemainder === denominator && quotient % 2n === 1n)
	) {
		return quotient + 1n;
	}

	return quotient;
}

function roundedFloatSignificand(parts, decimalExponent) {
	const scale = PHP_FLOAT_STRING_PRECISION - 1 - decimalExponent;
	let numerator = parts.significand;
	let denominator = 1n;

	if (parts.exponent >= 0) {
		numerator <<= BigInt(parts.exponent);
	} else {
		denominator <<= BigInt(-parts.exponent);
	}

	if (scale >= 0) {
		numerator *= powerOfTen(scale);
	} else {
		denominator *= powerOfTen(-scale);
	}

	return roundQuotientToEven(numerator, denominator);
}

function trimTrailingZeros(value) {
	return value.replace(/0+$/, "");
}

function formatPhpScientificFloat(sign, significand, decimalExponent) {
	const digits = significand.toString().padStart(PHP_FLOAT_STRING_PRECISION, "0");
	const fraction = trimTrailingZeros(digits.slice(1)) || "0";
	const exponentSign = decimalExponent >= 0 ? "+" : "";

	return `${sign}${digits[0]}.${fraction}E${exponentSign}${decimalExponent}`;
}

function formatPhpFixedFloat(sign, significand, decimalExponent) {
	const digits = significand.toString().padStart(PHP_FLOAT_STRING_PRECISION, "0");
	const decimalPoint = decimalExponent + 1;
	let integer;
	let fraction;

	if (decimalPoint <= 0) {
		integer = "0";
		fraction = `${"0".repeat(-decimalPoint)}${digits}`;
	} else if (decimalPoint >= digits.length) {
		integer = `${digits}${"0".repeat(decimalPoint - digits.length)}`;
		fraction = "";
	} else {
		integer = digits.slice(0, decimalPoint);
		fraction = digits.slice(decimalPoint);
	}

	fraction = trimTrailingZeros(fraction);

	return fraction === "" ? `${sign}${integer}` : `${sign}${integer}.${fraction}`;
}

function phpFloatToString(value) {
	const sign = value < 0 ? "-" : "";
	const parts = doubleParts(value);
	let decimalExponent = decimalExponentForDouble(value, parts);
	let significand = roundedFloatSignificand(parts, decimalExponent);
	const significandLimit = powerOfTen(PHP_FLOAT_STRING_PRECISION);

	if (significand >= significandLimit) {
		significand /= 10n;
		decimalExponent += 1;
	}

	return decimalExponent < -4 || decimalExponent >= PHP_FLOAT_STRING_PRECISION
		? formatPhpScientificFloat(sign, significand, decimalExponent)
		: formatPhpFixedFloat(sign, significand, decimalExponent);
}

function phpBooleanParameterCoerce(value, parameterName) {
	if (
		value === null ||
		typeof value === "object" ||
		typeof value === "function" ||
		typeof value === "symbol" ||
		typeof value === "undefined"
	) {
		throw new TypeError(`Argument $${parameterName} must be of type bool.`);
	}

	if (typeof value === "string") {
		return value !== "" && value !== "0";
	}

	if (typeof value === "number" && Number.isNaN(value)) {
		return true;
	}

	return Boolean(value);
}

function phpArrayParameterCoerce(value, parameterName) {
	if (!Array.isArray(value)) {
		throw new TypeError(`Argument $${parameterName} must be of type array.`);
	}

	return [...value];
}

function phpTokenParameterCoerce(value, parameterName) {
	if (!(value instanceof WP_HTML_Token)) {
		throw new TypeError(`Argument $${parameterName} must be of type WP_HTML_Token.`);
	}

	return value;
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
	if (tagName === "SVG") {
		return "svg";
	}
	if (tagName === "MATH") {
		return "math";
	}

	if (currentNamespace === "html") {
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
		const hasNodeName = Object.prototype.hasOwnProperty.call(tagName, "node_name");
		const hasCamelNodeName = Object.prototype.hasOwnProperty.call(tagName, "nodeName");
		const hasTagName = Object.prototype.hasOwnProperty.call(tagName, "tagName");
		const hasNamespace = Object.prototype.hasOwnProperty.call(tagName, "namespace");
		const hasCamelNamespace = Object.prototype.hasOwnProperty.call(tagName, "namespaceName");
		const nodeName = phpInternalStringCoerce(
			hasNodeName ? tagName.node_name : hasCamelNodeName ? tagName.nodeName : hasTagName ? tagName.tagName : "",
			"node_name",
		);
		const namespaceName = phpInternalStringCoerce(
			hasNamespace ? tagName.namespace : hasCamelNamespace ? tagName.namespaceName : "",
			"namespace",
		);

		if (hasNodeName || hasNamespace) {
			return {
				nodeName: namespaceName === "html" ? asciiUpper(nodeName) : nodeName,
				namespaceName,
			};
		}

		return {
			nodeName: asciiUpper(nodeName),
			namespaceName: asciiLower(namespaceName),
		};
	}

	return {
		nodeName: asciiUpper(String(tagName)),
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

function qualifyForeignAttributeName(namespaceName, attributeName) {
	const lowerAttributeName = asciiLower(attributeName);

	if (namespaceName === "math" && lowerAttributeName === "definitionurl") {
		return "definitionURL";
	}

	if (namespaceName === "svg") {
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
		const adjustedName = adjusted.get(lowerAttributeName);
		if (adjustedName !== undefined) {
			return adjustedName;
		}
	}

	const foreignAdjusted = new Map([
		["xlink:actuate", "xlink actuate"],
		["xlink:arcrole", "xlink arcrole"],
		["xlink:href", "xlink href"],
		["xlink:role", "xlink role"],
		["xlink:show", "xlink show"],
		["xlink:title", "xlink title"],
		["xlink:type", "xlink type"],
		["xml:lang", "xml lang"],
		["xml:space", "xml space"],
		["xmlns", "xmlns"],
		["xmlns:xlink", "xmlns xlink"],
	]);
	return foreignAdjusted.get(lowerAttributeName) ?? attributeName;
}
