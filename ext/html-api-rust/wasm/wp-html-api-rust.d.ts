export interface WasmInstantiatedSource {
	module?: WebAssembly.Module;
	instance: WebAssembly.Instance;
}

export interface WpHtmlApiRustWasmExports extends WebAssembly.Exports {
	readonly __data_end: WebAssembly.Global;
	readonly __heap_base: WebAssembly.Global;
	readonly memory: WebAssembly.Memory;
	wp_html_api_rust_alloc(len: number): number;
	wp_html_api_rust_dealloc(ptr: number, len: number): void;
	wp_html_api_rust_core_version(): number;
	wp_html_api_rust_decoder_decode(
		context: number,
		text: number,
		textLen: number,
		outPtr: number,
		outCapacity: number,
		outLen: number,
	): boolean;
	wp_html_api_rust_decoder_read_character_reference(
		context: number,
		text: number,
		textLen: number,
		at: number,
		outPtr: number,
		outCapacity: number,
		outLen: number,
		matchLen: number,
	): boolean;
	wp_html_api_rust_decoder_attribute_starts_with(
		haystack: number,
		haystackLen: number,
		searchText: number,
		searchTextLen: number,
		asciiCaseInsensitive: boolean,
	): boolean;
	wp_html_api_rust_decoder_code_point_to_utf8_bytes(
		codePoint: number,
		outPtr: number,
		outCapacity: number,
		outLen: number,
	): boolean;
	wp_html_api_rust_scan_next_tag(html: number, len: number, offset: number, out: number): boolean;
	wp_html_api_rust_tag_processor_new(html: number, len: number): number;
	wp_html_api_rust_tag_processor_free(processor: number): void;
	wp_html_api_rust_tag_processor_next_tag(
		processor: number,
		query: number,
		queryLen: number,
		visitClosers: boolean,
	): boolean;
	wp_html_api_rust_tag_processor_next_token(processor: number): boolean;
	wp_html_api_rust_tag_processor_seek(processor: number, offset: number): void;
	wp_html_api_rust_tag_processor_set_namespace(processor: number, namespace: number): void;
	wp_html_api_rust_tag_processor_apply_lexical_update(
		processor: number,
		start: number,
		length: number,
		replacement: number,
		replacementLen: number,
	): boolean;
	wp_html_api_rust_tag_processor_current_span(processor: number, start: number, length: number): boolean;
	wp_html_api_rust_tag_processor_current_token_type(processor: number): number;
	wp_html_api_rust_tag_processor_paused_at_incomplete(processor: number): boolean;
	wp_html_api_rust_tag_processor_subdivide_text_appropriately(processor: number): number;
	wp_html_api_rust_tag_processor_get_modifiable_text(processor: number, out: number): boolean;
	wp_html_api_rust_tag_processor_set_modifiable_text(
		processor: number,
		text: number,
		textLen: number,
	): boolean;
	wp_html_api_rust_tag_processor_current_comment_type(processor: number): number;
	wp_html_api_rust_tag_processor_script_content_type(processor: number): number;
	wp_html_api_rust_tag_processor_get_tag(processor: number, out: number): boolean;
	wp_html_api_rust_tag_processor_is_tag_closer(processor: number): boolean;
	wp_html_api_rust_tag_processor_has_self_closing_flag(processor: number): boolean;
	wp_html_api_rust_tag_processor_get_attribute(
		processor: number,
		name: number,
		nameLen: number,
		out: number,
	): number;
	wp_html_api_rust_tag_processor_get_attribute_names_with_prefix(
		processor: number,
		prefix: number,
		prefixLen: number,
		out: number,
	): number;
	wp_html_api_rust_tag_processor_set_attribute(
		processor: number,
		name: number,
		nameLen: number,
		value: number,
		valueLen: number,
		valueKind: number,
	): boolean;
	wp_html_api_rust_tag_processor_remove_attribute(processor: number, name: number, nameLen: number): boolean;
	wp_html_api_rust_tag_processor_add_class(
		processor: number,
		className: number,
		classNameLen: number,
		quirksMode: boolean,
	): boolean;
	wp_html_api_rust_tag_processor_remove_class(
		processor: number,
		className: number,
		classNameLen: number,
		quirksMode: boolean,
	): boolean;
	wp_html_api_rust_tag_processor_has_class(
		processor: number,
		className: number,
		classNameLen: number,
		quirksMode: boolean,
	): number;
	wp_html_api_rust_tag_processor_class_list(processor: number, out: number, quirksMode: boolean): number;
	wp_html_api_rust_tag_processor_get_html(processor: number, out: number): boolean;
}

export type WasmInputSource =
	| URL
	| Request
	| Response
	| string
	| ArrayBuffer
	| ArrayBufferView
	| Blob
	| WebAssembly.Module
	| WebAssembly.Exports
	| WebAssembly.Instance
	| WasmInstantiatedSource;

export type WasmInput = WasmInputSource | PromiseLike<WasmInputSource>;

export type WasmExportsInput =
	| WebAssembly.Exports
	| WebAssembly.Instance
	| WasmInstantiatedSource;

export interface ScanNextTagResult {
	tag_start: number;
	tag_end: number;
	name_start: number;
	name_len: number;
	name_length: number;
	tag_name: string;
	is_closing: boolean;
	has_self_closing_flag: boolean;
	token_end: number;
	token_type: number;
}

export interface NextTagBaseQuery {
	class_name?: unknown;
	tag_closers?: unknown;
	visit_closers?: boolean;
}

export interface TagNextTagQuery extends NextTagBaseQuery {
	tag_name?: unknown;
	match_offset?: unknown;
}

export interface ProcessorNextTagQuery extends NextTagBaseQuery {
	tag_name?: string | number | boolean | null;
	match_offset?: unknown;
	breadcrumbs?: PhpInternalStringParameter[];
}

export type NextTagQuery = ProcessorNextTagQuery;

export type CommentType =
	| "COMMENT_AS_ABRUPTLY_CLOSED_COMMENT"
	| "COMMENT_AS_CDATA_LOOKALIKE"
	| "COMMENT_AS_HTML_COMMENT"
	| "COMMENT_AS_PI_NODE_LOOKALIKE"
	| "COMMENT_AS_INVALID_HTML";

export type TokenType =
	| "#tag"
	| "#doctype"
	| "#text"
	| "#comment"
	| "#cdata-section"
	| "#presumptuous-tag"
	| "#funky-comment";

export type ParserState =
	| "STATE_READY"
	| "STATE_COMPLETE"
	| "STATE_INCOMPLETE_INPUT"
	| "STATE_MATCHED_TAG"
	| "STATE_TEXT_NODE"
	| "STATE_CDATA_NODE"
	| "STATE_COMMENT"
	| "STATE_DOCTYPE"
	| "STATE_PRESUMPTUOUS_TAG"
	| "STATE_WP_FUNKY";

export type TextNodeClassification =
	| "TEXT_IS_GENERIC"
	| "TEXT_IS_NULL_SEQUENCE"
	| "TEXT_IS_WHITESPACE";

export type HtmlNamespace = "html" | "math" | "svg";

export type EncodingConfidence = "tentative" | "certain" | "irrelevant";

export type DecoderContext = "attribute" | "data" | string;

export type PhpIntegerParameter = number | string | boolean;

export type PhpStringParameter = string | number | boolean;

export type PhpInternalStringParameter = string | number | boolean | null;

export type PhpInterpolatedStringParameter = string | number | boolean | null | unknown[];

export type PhpBooleanParameter = boolean | number | string;

export type PhpArrayKeyParameter = string | number | boolean | null;

export interface MatchByteLength {
	value?: number;
}

export interface WP_HTML_Decoder_Constructor {
	attribute_starts_with(
		haystack: PhpInternalStringParameter,
		searchText: PhpInternalStringParameter,
		caseSensitivity?: unknown,
	): boolean;
	decode_text_node(text: PhpStringParameter): string;
	decode_attribute(text: PhpStringParameter): string;
	decode(context: unknown, text: PhpStringParameter): string;
	read_character_reference(
		context: unknown,
		text: PhpInternalStringParameter,
		at?: number | string | boolean | null,
		matchByteLength?: MatchByteLength | null,
	): string | null;
	code_point_to_utf8_bytes(codePoint: PhpIntegerParameter | null): string;
}

export interface WP_HTML_Doctype_Info {
	name: string | null;
	public_identifier: string | null;
	system_identifier: string | null;
	indicated_compatibility_mode: "no-quirks" | "limited-quirks" | "quirks";
}

export interface WP_HTML_Doctype_Info_Constructor {
	from_doctype_token(doctypeHtml: PhpStringParameter): WP_HTML_Doctype_Info | null;
}

export const WP_HTML_Doctype_Info: WP_HTML_Doctype_Info_Constructor;

export interface WP_HTML_Unsupported_Exception extends Error {
	message: string;
	token_name: string;
	token_at: number;
	token: string;
	stack_of_open_elements: unknown[];
	active_formatting_elements: unknown[];
}

export interface WP_HTML_Unsupported_Exception_Constructor {
	new (
		message: PhpStringParameter,
		tokenName: PhpStringParameter,
		tokenAt: PhpIntegerParameter,
		token: PhpStringParameter,
		stackOfOpenElements: unknown[],
		activeFormattingElements: unknown[],
	): WP_HTML_Unsupported_Exception;
}

export const WP_HTML_Unsupported_Exception: WP_HTML_Unsupported_Exception_Constructor;

export interface WP_HTML_Span {
	start: number;
	length: number;
}

export interface WP_HTML_Span_Constructor {
	new (start: PhpIntegerParameter, length: PhpIntegerParameter): WP_HTML_Span;
}

export const WP_HTML_Span: WP_HTML_Span_Constructor;

export interface WP_HTML_Text_Replacement {
	start: number;
	length: number;
	text: string;
}

export interface WP_HTML_Text_Replacement_Constructor {
	new (start: PhpIntegerParameter, length: PhpIntegerParameter, text: PhpStringParameter): WP_HTML_Text_Replacement;
}

export const WP_HTML_Text_Replacement: WP_HTML_Text_Replacement_Constructor;

export interface WP_HTML_Attribute_Token {
	name: unknown;
	value_starts_at: unknown;
	value_length: unknown;
	start: unknown;
	length: unknown;
	is_true: unknown;
}

export interface WP_HTML_Attribute_Token_Constructor {
	new (
		name: unknown,
		valueStart: unknown,
		valueLength: unknown,
		start: unknown,
		length: unknown,
		isTrue: unknown,
	): WP_HTML_Attribute_Token;
}

export const WP_HTML_Attribute_Token: WP_HTML_Attribute_Token_Constructor;

export interface WP_HTML_Token {
	bookmark_name: string | null;
	namespace: HtmlNamespace;
	node_name: string;
	has_self_closing_flag: boolean;
	integration_node_type: "math" | "html" | null;
	on_destroy: ((bookmarkName: string | null) => void) | null;
	destroy(): void;
	free(): void;
}

export interface WP_HTML_Token_Constructor {
	new (
		bookmarkName: PhpStringParameter | null,
		nodeName: PhpStringParameter,
		hasSelfClosingFlag: PhpBooleanParameter,
		onDestroy?: ((bookmarkName: string | null) => void) | null,
	): WP_HTML_Token;
}

export const WP_HTML_Token: WP_HTML_Token_Constructor;

export interface WP_HTML_Stack_Event {
	token: WP_HTML_Token;
	operation: "pop" | "push" | string;
	provenance: "virtual" | "real" | string;
}

export interface WP_HTML_Stack_Event_Constructor {
	readonly POP: "pop";
	readonly PUSH: "push";
	new (token: WP_HTML_Token, operation: PhpStringParameter, provenance: PhpStringParameter): WP_HTML_Stack_Event;
}

export const WP_HTML_Stack_Event: WP_HTML_Stack_Event_Constructor;

export interface WP_HTML_Active_Formatting_Elements {
	contains_node(token: WP_HTML_Token): boolean;
	count(): number;
	current_node(): WP_HTML_Token | null;
	insert_marker(): void;
	push(token: WP_HTML_Token): void;
	remove_node(token: WP_HTML_Token): boolean;
	walk_down(): IterableIterator<WP_HTML_Token>;
	walk_up(): IterableIterator<WP_HTML_Token>;
	clear_up_to_last_marker(): void;
}

export interface WP_HTML_Active_Formatting_Elements_Constructor {
	new (): WP_HTML_Active_Formatting_Elements;
}

export const WP_HTML_Active_Formatting_Elements: WP_HTML_Active_Formatting_Elements_Constructor;

export interface WP_HTML_Open_Elements {
	stack: WP_HTML_Token[];
	set_pop_handler(handler: (token: WP_HTML_Token) => void): void;
	set_push_handler(handler: (token: WP_HTML_Token) => void): void;
	at(nth: PhpIntegerParameter): WP_HTML_Token | null;
	contains(nodeName: PhpStringParameter): boolean;
	contains_node(token: WP_HTML_Token): boolean;
	count(): number;
	current_node(): WP_HTML_Token | null;
	current_node_is(identity: PhpStringParameter): boolean;
	has_element_in_specific_scope(tagName: PhpStringParameter, terminationList: unknown): boolean;
	has_element_in_scope(tagName: PhpStringParameter): boolean;
	has_element_in_list_item_scope(tagName: PhpStringParameter): boolean;
	has_element_in_button_scope(tagName: PhpStringParameter): boolean;
	has_element_in_table_scope(tagName: PhpStringParameter): boolean;
	has_element_in_select_scope(tagName: PhpStringParameter): boolean;
	has_p_in_button_scope(): boolean;
	pop(): boolean;
	pop_until(htmlTagName: PhpStringParameter): boolean;
	push(stackItem: WP_HTML_Token): void;
	remove_node(token: WP_HTML_Token): boolean;
	walk_down(): IterableIterator<WP_HTML_Token>;
	walk_up(aboveThisNode?: WP_HTML_Token | null): IterableIterator<WP_HTML_Token>;
	after_element_push(item: WP_HTML_Token): void;
	after_element_pop(item: WP_HTML_Token): void;
	clear_to_table_context(): void;
	clear_to_table_body_context(): void;
	clear_to_table_row_context(): void;
}

export interface WP_HTML_Open_Elements_Constructor {
	new (): WP_HTML_Open_Elements;
}

export const WP_HTML_Open_Elements: WP_HTML_Open_Elements_Constructor;

export interface WP_HTML_Processor_State {
	stack_of_template_insertion_modes: string[];
	stack_of_open_elements: WP_HTML_Open_Elements;
	active_formatting_elements: WP_HTML_Active_Formatting_Elements;
	current_token: WP_HTML_Token | null;
	insertion_mode: string;
	context_node: null;
	encoding: string | null;
	encoding_confidence: "tentative" | "certain" | "irrelevant" | string;
	head_element: WP_HTML_Token | null;
	form_element: WP_HTML_Token | null;
	frameset_ok: boolean;
}

export interface WP_HTML_Processor_State_Constructor {
	readonly INSERTION_MODE_INITIAL: "insertion-mode-initial";
	readonly INSERTION_MODE_BEFORE_HTML: "insertion-mode-before-html";
	readonly INSERTION_MODE_BEFORE_HEAD: "insertion-mode-before-head";
	readonly INSERTION_MODE_IN_HEAD: "insertion-mode-in-head";
	readonly INSERTION_MODE_IN_HEAD_NOSCRIPT: "insertion-mode-in-head-noscript";
	readonly INSERTION_MODE_AFTER_HEAD: "insertion-mode-after-head";
	readonly INSERTION_MODE_IN_BODY: "insertion-mode-in-body";
	readonly INSERTION_MODE_IN_TABLE: "insertion-mode-in-table";
	readonly INSERTION_MODE_IN_TABLE_TEXT: "insertion-mode-in-table-text";
	readonly INSERTION_MODE_IN_CAPTION: "insertion-mode-in-caption";
	readonly INSERTION_MODE_IN_COLUMN_GROUP: "insertion-mode-in-column-group";
	readonly INSERTION_MODE_IN_TABLE_BODY: "insertion-mode-in-table-body";
	readonly INSERTION_MODE_IN_ROW: "insertion-mode-in-row";
	readonly INSERTION_MODE_IN_CELL: "insertion-mode-in-cell";
	readonly INSERTION_MODE_IN_SELECT: "insertion-mode-in-select";
	readonly INSERTION_MODE_IN_SELECT_IN_TABLE: "insertion-mode-in-select-in-table";
	readonly INSERTION_MODE_IN_TEMPLATE: "insertion-mode-in-template";
	readonly INSERTION_MODE_AFTER_BODY: "insertion-mode-after-body";
	readonly INSERTION_MODE_IN_FRAMESET: "insertion-mode-in-frameset";
	readonly INSERTION_MODE_AFTER_FRAMESET: "insertion-mode-after-frameset";
	readonly INSERTION_MODE_AFTER_AFTER_BODY: "insertion-mode-after-after-body";
	readonly INSERTION_MODE_AFTER_AFTER_FRAMESET: "insertion-mode-after-after-frameset";
	new (): WP_HTML_Processor_State;
}

export const WP_HTML_Processor_State: WP_HTML_Processor_State_Constructor;

export interface WP_HTML_Tag_Processor {
	parser_state: ParserState;
	compat_mode: "no-quirks-mode" | "quirks-mode";
	parsing_namespace: HtmlNamespace;
	text_node_classification: TextNodeClassification;
	destroy(): void;
	free(): void;
	next_tag(query?: string | TagNextTagQuery | null): boolean;
	next_token(): boolean;
	get_tag(): string | null;
	get_attribute(name: PhpInternalStringParameter): string | true | null;
	get_attribute_names_with_prefix(prefix: PhpInternalStringParameter): string[] | null;
	set_attribute(name: PhpInternalStringParameter, value: PhpStringParameter | boolean | null): boolean;
	remove_attribute(name: PhpInternalStringParameter): boolean;
	add_class(className: PhpInternalStringParameter): boolean;
	remove_class(className: PhpInternalStringParameter): boolean;
	has_class(className: PhpInternalStringParameter): boolean | null;
	class_list(): string[] | null;
	is_tag_closer(): boolean;
	has_self_closing_flag(): boolean;
	get_token_name(): string | null;
	get_token_type(): TokenType | null;
	paused_at_incomplete_token(): boolean;
	subdivide_text_appropriately(): boolean;
	get_modifiable_text(): string;
	native_get_script_content_type(): "javascript" | "json" | null;
	set_modifiable_text(text: PhpStringParameter): boolean;
	get_comment_type(): CommentType | null;
	get_doctype_info(): WP_HTML_Doctype_Info | null;
	set_bookmark(name: PhpArrayKeyParameter): boolean;
	release_bookmark(name: PhpArrayKeyParameter): boolean;
	has_bookmark(name: PhpArrayKeyParameter): boolean;
	seek(name: PhpArrayKeyParameter): boolean;
	change_parsing_namespace(namespaceName: PhpStringParameter): boolean;
	get_namespace(): HtmlNamespace;
	get_qualified_tag_name(): string | null;
	get_qualified_attribute_name(attributeName: PhpInternalStringParameter): string | null;
	get_full_comment_text(): string | null;
	get_updated_html(flushClassNameUpdates?: boolean): string;
	toString(): string;
}

export interface WP_HTML_Tag_Processor_Constructor {
	new (html: unknown): WP_HTML_Tag_Processor;
	readonly MAX_BOOKMARKS: number;
	readonly MAX_SEEK_OPS: 1000;
	readonly ADD_CLASS: true;
	readonly REMOVE_CLASS: false;
	readonly SKIP_CLASS: null;
	readonly STATE_READY: "STATE_READY";
	readonly STATE_COMPLETE: "STATE_COMPLETE";
	readonly STATE_INCOMPLETE_INPUT: "STATE_INCOMPLETE_INPUT";
	readonly STATE_MATCHED_TAG: "STATE_MATCHED_TAG";
	readonly STATE_TEXT_NODE: "STATE_TEXT_NODE";
	readonly STATE_CDATA_NODE: "STATE_CDATA_NODE";
	readonly STATE_COMMENT: "STATE_COMMENT";
	readonly STATE_DOCTYPE: "STATE_DOCTYPE";
	readonly STATE_PRESUMPTUOUS_TAG: "STATE_PRESUMPTUOUS_TAG";
	readonly STATE_FUNKY_COMMENT: "STATE_WP_FUNKY";
	readonly TEXT_IS_GENERIC: "TEXT_IS_GENERIC";
	readonly TEXT_IS_NULL_SEQUENCE: "TEXT_IS_NULL_SEQUENCE";
	readonly TEXT_IS_WHITESPACE: "TEXT_IS_WHITESPACE";
	readonly NO_QUIRKS_MODE: "no-quirks-mode";
	readonly QUIRKS_MODE: "quirks-mode";
	readonly COMMENT_AS_ABRUPTLY_CLOSED_COMMENT: "COMMENT_AS_ABRUPTLY_CLOSED_COMMENT";
	readonly COMMENT_AS_CDATA_LOOKALIKE: "COMMENT_AS_CDATA_LOOKALIKE";
	readonly COMMENT_AS_HTML_COMMENT: "COMMENT_AS_HTML_COMMENT";
	readonly COMMENT_AS_PI_NODE_LOOKALIKE: "COMMENT_AS_PI_NODE_LOOKALIKE";
	readonly COMMENT_AS_INVALID_HTML: "COMMENT_AS_INVALID_HTML";
}

export type ProcessorStepMode =
	| "process-next-node"
	| "reprocess-current-node"
	| "process-current-node";

export interface SpecialTagInput {
	node_name?: PhpStringParameter | null;
	nodeName?: PhpStringParameter | null;
	tagName?: PhpStringParameter | null;
	namespace?: PhpStringParameter | null;
	namespaceName?: PhpStringParameter | null;
}

export interface WP_HTML_Processor_Options {
	contextNode?: string;
	contextNamespace?: HtmlNamespace;
	contextIntegrationNodeType?: "math" | "html" | null;
	contextBreadcrumbs?: string[];
	compatMode?: "no-quirks-mode" | "quirks-mode";
	fullParser?: boolean;
	htmlFragmentContext?: boolean;
	rawTextFragmentContext?: string | null;
	encodingConfidence?: EncodingConfidence;
	preserveInBodyIgnoredStartTags?: boolean;
}

export interface WP_HTML_Processor extends WP_HTML_Tag_Processor {
	next_tag(query?: string | ProcessorNextTagQuery | null): boolean;
	next_token(): boolean;
	step(nodeToProcess?: ProcessorStepMode): boolean;
	get_last_error(): string | null;
	get_unsupported_exception(): WP_HTML_Unsupported_Exception | null;
	is_virtual(): boolean;
	is_tag_closer(): boolean;
	get_namespace(): HtmlNamespace;
	expects_closer(node?: WP_HTML_Token | null): boolean | null;
	get_breadcrumbs(): string[];
	get_current_depth(): number;
	matches_breadcrumbs(breadcrumbs: PhpInternalStringParameter[]): boolean;
	set_bookmark(name: PhpInterpolatedStringParameter): boolean;
	release_bookmark(name: PhpInterpolatedStringParameter): boolean;
	has_bookmark(name: PhpInterpolatedStringParameter): boolean;
	seek(name: PhpInterpolatedStringParameter): boolean;
	serialize(): string | null;
	serialize_token(): string;
}

export interface WP_HTML_Processor_Constructor extends WP_HTML_Tag_Processor_Constructor {
	new (html: unknown, options?: unknown): WP_HTML_Processor;
	readonly MAX_BOOKMARKS: 10000;
	readonly PROCESS_NEXT_NODE: "process-next-node";
	readonly REPROCESS_CURRENT_NODE: "reprocess-current-node";
	readonly PROCESS_CURRENT_NODE: "process-current-node";
	readonly ERROR_UNSUPPORTED: "unsupported";
	readonly ERROR_EXCEEDED_MAX_BOOKMARKS: "exceeded-max-bookmarks";
	readonly CONSTRUCTOR_UNLOCK_CODE: string;
	create_fragment(html: unknown, context?: unknown, encoding?: unknown): WP_HTML_Processor | null;
	create_full_parser(html: unknown, encoding?: unknown): WP_HTML_Processor | null;
	normalize(html: PhpStringParameter): string | null;
	is_void(tagName: PhpInternalStringParameter): boolean;
	is_special(tagName: unknown): boolean;
}

export interface HtmlApi {
	WP_HTML_Decoder: WP_HTML_Decoder_Constructor;
	WP_HTML_Unsupported_Exception: WP_HTML_Unsupported_Exception_Constructor;
	WP_HTML_Span: WP_HTML_Span_Constructor;
	WP_HTML_Text_Replacement: WP_HTML_Text_Replacement_Constructor;
	WP_HTML_Attribute_Token: WP_HTML_Attribute_Token_Constructor;
	WP_HTML_Token: WP_HTML_Token_Constructor;
	WP_HTML_Stack_Event: WP_HTML_Stack_Event_Constructor;
	WP_HTML_Active_Formatting_Elements: WP_HTML_Active_Formatting_Elements_Constructor;
	WP_HTML_Open_Elements: WP_HTML_Open_Elements_Constructor;
	WP_HTML_Processor_State: WP_HTML_Processor_State_Constructor;
	WP_HTML_Doctype_Info: WP_HTML_Doctype_Info_Constructor;
	WP_HTML_Tag_Processor: WP_HTML_Tag_Processor_Constructor;
	WP_HTML_Processor: WP_HTML_Processor_Constructor;
	scanNextTag(html: unknown, offset?: number | string | boolean | null): ScanNextTagResult | false;
	version(): string;
	wasm: WpHtmlApiRustWasmExports;
}

export function loadWasm(input?: WasmInput): Promise<HtmlApi>;
export function createHtmlApi(wasm: WasmExportsInput): HtmlApi;
