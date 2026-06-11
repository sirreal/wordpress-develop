export type WasmInput =
	| URL
	| Request
	| string
	| ArrayBuffer
	| Uint8Array
	| WebAssembly.Module;

export interface ScanNextTagResult {
	tag_start: number;
	tag_end: number;
	name_start: number;
	name_len: number;
	tag_name: string;
	is_closing: boolean;
	has_self_closing_flag: boolean;
	token_end: number;
	token_type: number;
}

export interface NextTagQuery {
	tag_name?: string | null;
	class_name?: string | null;
	tag_closers?: "visit" | "skip";
	visit_closers?: boolean;
	match_offset?: number | string | boolean | null;
	breadcrumbs?: string[];
}

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

export interface WP_HTML_Doctype_Info {
	name: string | null;
	public_identifier: string | null;
	system_identifier: string | null;
	indicated_compatibility_mode: "no-quirks" | "limited-quirks" | "quirks";
}

export interface WP_HTML_Doctype_Info_Constructor {
	new (
		name: string | null,
		publicIdentifier: string | null,
		systemIdentifier: string | null,
		forceQuirksFlag: boolean,
	): WP_HTML_Doctype_Info;
	from_doctype_token(doctypeHtml: string): WP_HTML_Doctype_Info | null;
}

export interface WP_HTML_Tag_Processor {
	parser_state: ParserState;
	compat_mode: "no-quirks-mode" | "quirks-mode";
	parsing_namespace: HtmlNamespace;
	text_node_classification: TextNodeClassification;
	destroy(): void;
	free(): void;
	next_tag(query?: string | NextTagQuery | null): boolean;
	next_token(): boolean;
	get_tag(): string | null;
	get_attribute(name: string): string | true | null;
	get_attribute_names_with_prefix(prefix: string): string[] | null;
	set_attribute(name: string, value: string | boolean): boolean;
	remove_attribute(name: string): boolean;
	add_class(className: string): boolean;
	remove_class(className: string): boolean;
	has_class(className: string): boolean | null;
	class_list(): string[] | null;
	is_tag_closer(): boolean;
	has_self_closing_flag(): boolean;
	get_token_name(): string | null;
	get_token_type(): TokenType | null;
	paused_at_incomplete_token(): boolean;
	subdivide_text_appropriately(): boolean;
	get_modifiable_text(): string;
	set_modifiable_text(text: string): boolean;
	get_comment_type(): CommentType | null;
	get_doctype_info(): WP_HTML_Doctype_Info | null;
	set_bookmark(name: string): boolean;
	release_bookmark(name: string): boolean;
	has_bookmark(name: string): boolean;
	seek(name: string): boolean;
	change_parsing_namespace(namespaceName: HtmlNamespace): boolean;
	get_namespace(): HtmlNamespace;
	get_qualified_tag_name(): string | null;
	get_qualified_attribute_name(attributeName: string): string | null;
	get_full_comment_text(): string | null;
	get_updated_html(): string;
	toString(): string;
}

export interface WP_HTML_Tag_Processor_Constructor {
	new (html: string): WP_HTML_Tag_Processor;
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
	node_name?: string;
	nodeName?: string;
	tagName?: string;
	namespace?: HtmlNamespace;
	namespaceName?: HtmlNamespace;
}

export interface WP_HTML_Processor extends WP_HTML_Tag_Processor {
	next_tag(query?: string | NextTagQuery | null): boolean;
	next_token(): boolean;
	step(nodeToProcess?: ProcessorStepMode): boolean;
	get_last_error(): string | null;
	get_unsupported_exception(): unknown;
	is_virtual(): boolean;
	is_tag_closer(): boolean;
	get_namespace(): HtmlNamespace;
	expects_closer(): boolean | null;
	get_breadcrumbs(): string[];
	get_current_depth(): number;
	matches_breadcrumbs(breadcrumbs: string[]): boolean;
	serialize(): string | null;
	serialize_token(): string;
}

export interface WP_HTML_Processor_Constructor extends WP_HTML_Tag_Processor_Constructor {
	new (html: string, options?: { contextNode?: string; fullParser?: boolean }): WP_HTML_Processor;
	readonly MAX_BOOKMARKS: 10000;
	readonly PROCESS_NEXT_NODE: "process-next-node";
	readonly REPROCESS_CURRENT_NODE: "reprocess-current-node";
	readonly PROCESS_CURRENT_NODE: "process-current-node";
	readonly ERROR_UNSUPPORTED: "unsupported";
	readonly ERROR_EXCEEDED_MAX_BOOKMARKS: "exceeded-max-bookmarks";
	readonly CONSTRUCTOR_UNLOCK_CODE: string;
	create_fragment(html: string, context?: string, encoding?: string): WP_HTML_Processor | null;
	create_full_parser(html: string, encoding?: string): WP_HTML_Processor | null;
	normalize(html: string): string | null;
	is_void(tagName: string): boolean;
	is_special(tagName: string | SpecialTagInput): boolean;
}

export interface HtmlApi {
	WP_HTML_Doctype_Info: WP_HTML_Doctype_Info_Constructor;
	WP_HTML_Tag_Processor: WP_HTML_Tag_Processor_Constructor;
	WP_HTML_Processor: WP_HTML_Processor_Constructor;
	scanNextTag(html: string, offset?: number): ScanNextTagResult | null;
	version(): string;
}

export function loadWasm(input?: WasmInput): Promise<HtmlApi>;
export function createHtmlApi(wasm: WebAssembly.Exports): HtmlApi;
