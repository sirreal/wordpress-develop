import assert from "node:assert/strict";
import { readFile } from "node:fs/promises";
import { loadWasm } from "./wp-html-api-rust.js";

const {
	WP_HTML_Doctype_Info,
	WP_HTML_Tag_Processor,
	WP_HTML_Processor,
	scanNextTag,
	version,
	wasm,
} = await loadWasm(new URL("./dist/wp_html_api_rust_core.wasm", import.meta.url));

assert.equal(version(), "0.1.0");
assert.equal(typeof wasm.wp_html_api_rust_core_version, "function");

const wasmBytes = await readFile(new URL("./dist/wp_html_api_rust_core.wasm", import.meta.url));
const apiFromDataView = await loadWasm(new DataView(wasmBytes.buffer, wasmBytes.byteOffset, wasmBytes.byteLength));
assert.equal(apiFromDataView.version(), "0.1.0");

for (const method of [
	"change_parsing_namespace",
	"next_tag",
	"next_token",
	"paused_at_incomplete_token",
	"class_list",
	"has_class",
	"set_bookmark",
	"release_bookmark",
	"has_bookmark",
	"seek",
	"get_attribute",
	"get_attribute_names_with_prefix",
	"get_namespace",
	"get_tag",
	"get_qualified_tag_name",
	"get_qualified_attribute_name",
	"has_self_closing_flag",
	"is_tag_closer",
	"get_token_type",
	"get_token_name",
	"get_comment_type",
	"get_full_comment_text",
	"subdivide_text_appropriately",
	"get_modifiable_text",
	"set_modifiable_text",
	"set_attribute",
	"remove_attribute",
	"add_class",
	"remove_class",
	"get_updated_html",
	"get_doctype_info",
]) {
	assert.equal(typeof WP_HTML_Tag_Processor.prototype[method], "function", `Missing tag processor method ${method}`);
	assert.equal(typeof WP_HTML_Processor.prototype[method], "function", `Missing inherited processor method ${method}`);
}

for (const method of [
	"get_last_error",
	"get_unsupported_exception",
	"matches_breadcrumbs",
	"expects_closer",
	"step",
	"get_breadcrumbs",
	"get_current_depth",
	"serialize",
	"serialize_token",
]) {
	assert.equal(typeof WP_HTML_Processor.prototype[method], "function", `Missing processor method ${method}`);
}

for (const method of ["create_fragment", "create_full_parser", "normalize", "is_special", "is_void"]) {
	assert.equal(typeof WP_HTML_Processor[method], "function", `Missing processor static method ${method}`);
}

assert.equal(WP_HTML_Tag_Processor.COMMENT_AS_HTML_COMMENT, "COMMENT_AS_HTML_COMMENT");
assert.equal(WP_HTML_Tag_Processor.COMMENT_AS_PI_NODE_LOOKALIKE, "COMMENT_AS_PI_NODE_LOOKALIKE");

assert.deepEqual(
	scanNextTag('<p class="intro">Hi</p>'),
	{
		tag_start: 0,
		tag_end: 17,
		name_start: 1,
		name_len: 1,
		name_length: 1,
		tag_name: "P",
		is_closing: false,
		has_self_closing_flag: false,
		token_end: 17,
		token_type: 1,
	},
);
assert.equal(scanNextTag("<span>", -10).tag_name, "SPAN");
assert.equal(scanNextTag("plain text"), false);

const tags = new WP_HTML_Tag_Processor('<div class="one"><span data-id="7">Hi</span></div>');
assert.equal(tags.next_tag({ tag_name: "span" }), true);
assert.equal(tags.get_tag(), "SPAN");
assert.equal(tags.get_attribute("data-id"), "7");
assert.equal(tags.set_attribute("data-id", "8"), true);
assert.equal(tags.add_class("active"), true);
assert.equal(tags.has_class("active"), true);
assert.deepEqual(tags.class_list(), ["active"]);
assert.equal(tags.get_updated_html(), '<div class="one"><span class="active" data-id="8">Hi</span></div>');
tags.destroy();

const tagMatchOffset = new WP_HTML_Tag_Processor("<div one></div><div two></div>");
assert.equal(tagMatchOffset.next_tag({ tag_name: "div", match_offset: 2 }), true);
assert.equal(tagMatchOffset.get_attribute("two"), true);
tagMatchOffset.destroy();

for (const invalidHtml of [null, 123]) {
	const invalidTags = new WP_HTML_Tag_Processor(invalidHtml);
	assert.equal(invalidTags.get_updated_html(), "");
	assert.equal(invalidTags.next_token(), false);
	invalidTags.destroy();
}

const text = new WP_HTML_Tag_Processor(" \0<p>Hi</p>");
assert.equal(text.get_modifiable_text(), "");
assert.equal(text.get_qualified_attribute_name("data-id"), null);
assert.equal(text.next_token(), true);
assert.equal(text.get_token_type(), "#text");
assert.equal(text.get_qualified_attribute_name("data-id"), null);
assert.equal(text.subdivide_text_appropriately(), true);
assert.equal(text.text_node_classification, WP_HTML_Tag_Processor.TEXT_IS_WHITESPACE);
text.destroy();

const nonText = new WP_HTML_Tag_Processor("<div></div>");
assert.equal(nonText.next_tag("div"), true);
assert.equal(nonText.get_qualified_attribute_name("DATA-ID"), "data-id");
assert.equal(nonText.get_modifiable_text(), "");
assert.equal(nonText.next_tag({ tag_name: "div", tag_closers: "visit" }), true);
assert.equal(nonText.is_tag_closer(), true);
assert.equal(nonText.get_qualified_attribute_name("DATA-ID"), "data-id");
nonText.destroy();

const textarea = new WP_HTML_Tag_Processor("<textarea>One</textarea>");
assert.equal(textarea.next_token(), true);
assert.equal(textarea.get_modifiable_text(), "One");
assert.equal(textarea.set_modifiable_text("Two"), true);
assert.equal(textarea.get_updated_html(), "<textarea>Two</textarea>");
textarea.destroy();

const doctype = new WP_HTML_Tag_Processor('<!DOCTYPE HTML PUBLIC "-//W3C//DTD HTML 4.01//EN" "http://www.w3.org/TR/html4/strict.dtd"><p>');
assert.equal(doctype.next_token(), true);
const doctypeInfo = doctype.get_doctype_info();
assert.ok(doctypeInfo instanceof WP_HTML_Doctype_Info);
assert.equal(doctypeInfo.name, "html");
assert.equal(doctypeInfo.public_identifier, "-//W3C//DTD HTML 4.01//EN");
assert.equal(doctypeInfo.system_identifier, "http://www.w3.org/TR/html4/strict.dtd");
assert.equal(doctypeInfo.indicated_compatibility_mode, "no-quirks");
doctype.destroy();

const comment = new WP_HTML_Tag_Processor("<?xml-stylesheet href='x'?>");
assert.equal(comment.next_token(), true);
assert.equal(comment.get_tag(), "xml-stylesheet");
assert.equal(comment.get_full_comment_text(), "?xml-stylesheet href='x'?");
comment.destroy();

const tagBookmarkLimit = new WP_HTML_Tag_Processor("<div>");
assert.equal(tagBookmarkLimit.next_tag("div"), true);
for (let i = 0; i < WP_HTML_Tag_Processor.MAX_BOOKMARKS; i += 1) {
	assert.equal(tagBookmarkLimit.set_bookmark(`tag-${i}`), true);
}
assert.equal(tagBookmarkLimit.set_bookmark("tag-over-limit"), false);
tagBookmarkLimit.destroy();

const processor = WP_HTML_Processor.create_fragment("<img><p>Hi");
assert.equal(processor.next_tag("p"), true);
assert.equal(processor.expects_closer(), true);
assert.deepEqual(processor.get_breadcrumbs(), ["HTML", "BODY", "P"]);
processor.destroy();

const completedProcessor = WP_HTML_Processor.create_fragment('<div class="test">Test</div>');
assert.equal(completedProcessor.next_tag(), true);
assert.equal(completedProcessor.get_tag(), "DIV");
assert.equal(completedProcessor.next_tag(), false);
assert.equal(completedProcessor.get_tag(), null);
completedProcessor.destroy();

const processorMatchOffsetWithoutBreadcrumbs = WP_HTML_Processor.create_fragment("<div one></div><div two></div>");
assert.equal(processorMatchOffsetWithoutBreadcrumbs.next_tag({ tag_name: "div", match_offset: 2 }), true);
assert.equal(processorMatchOffsetWithoutBreadcrumbs.get_attribute("one"), true);
assert.equal(processorMatchOffsetWithoutBreadcrumbs.get_attribute("two"), null);
processorMatchOffsetWithoutBreadcrumbs.destroy();

const processorBreadcrumbMatchOffset = WP_HTML_Processor.create_fragment("<div><span one></span><span two></span></div>");
assert.equal(processorBreadcrumbMatchOffset.next_tag({ breadcrumbs: ["DIV", "SPAN"], match_offset: "2nd" }), true);
assert.equal(processorBreadcrumbMatchOffset.get_attribute("one"), null);
assert.equal(processorBreadcrumbMatchOffset.get_attribute("two"), true);
processorBreadcrumbMatchOffset.destroy();

const processorBreadcrumbIgnoresTagName = WP_HTML_Processor.create_fragment("<span></span><div></div>");
assert.equal(processorBreadcrumbIgnoresTagName.next_tag({ tag_name: "span", breadcrumbs: ["DIV"] }), true);
assert.equal(processorBreadcrumbIgnoresTagName.get_tag(), "DIV");
processorBreadcrumbIgnoresTagName.destroy();

const processorZeroBreadcrumbMatchOffset = WP_HTML_Processor.create_fragment("<div><span></span></div>");
assert.equal(processorZeroBreadcrumbMatchOffset.next_tag({ breadcrumbs: ["DIV", "SPAN"], match_offset: 0 }), false);
assert.equal(processorZeroBreadcrumbMatchOffset.get_tag(), null);
processorZeroBreadcrumbMatchOffset.destroy();

const imageNamespaceProcessor = WP_HTML_Processor.create_fragment("<image/><svg><image/></svg>");
assert.equal(imageNamespaceProcessor.next_tag(), true);
assert.equal(imageNamespaceProcessor.get_tag(), "IMG");
assert.equal(imageNamespaceProcessor.get_namespace(), "html");
assert.equal(imageNamespaceProcessor.expects_closer(), false);
assert.deepEqual(imageNamespaceProcessor.get_breadcrumbs(), ["HTML", "BODY", "IMG"]);
assert.equal(imageNamespaceProcessor.next_tag("svg"), true);
assert.equal(imageNamespaceProcessor.next_tag(), true);
assert.equal(imageNamespaceProcessor.get_tag(), "IMAGE");
assert.equal(imageNamespaceProcessor.get_namespace(), "svg");
assert.equal(imageNamespaceProcessor.expects_closer(), false);
assert.deepEqual(imageNamespaceProcessor.get_breadcrumbs(), ["HTML", "BODY", "SVG", "IMAGE"]);
imageNamespaceProcessor.destroy();

assert.equal(WP_HTML_Processor.PROCESS_NEXT_NODE, "process-next-node");
assert.equal(WP_HTML_Processor.REPROCESS_CURRENT_NODE, "reprocess-current-node");
assert.equal(WP_HTML_Processor.PROCESS_CURRENT_NODE, "process-current-node");
assert.equal(WP_HTML_Processor.ERROR_UNSUPPORTED, "unsupported");
assert.equal(WP_HTML_Processor.ERROR_EXCEEDED_MAX_BOOKMARKS, "exceeded-max-bookmarks");
assert.equal(WP_HTML_Processor.MAX_BOOKMARKS, 10000);
assert.equal(WP_HTML_Processor.create_fragment(null), null);
assert.equal(WP_HTML_Processor.create_fragment("", "<div>"), null);
assert.equal(WP_HTML_Processor.create_fragment("", "<body>", "ISO-8859-1"), null);
assert.equal(WP_HTML_Processor.create_full_parser(null), null);
assert.equal(WP_HTML_Processor.create_full_parser("", "ISO-8859-1"), null);
assert.equal(WP_HTML_Processor.normalize(null), null);
assert.equal(WP_HTML_Processor.is_special("div"), true);
assert.equal(WP_HTML_Processor.is_special("span"), false);
assert.equal(WP_HTML_Processor.is_special("math mi"), true);
assert.equal(WP_HTML_Processor.is_special({ namespace: "svg", node_name: "foreignObject" }), true);

const explicitTokenExpectationsProcessor = WP_HTML_Processor.create_fragment("");
assert.equal(explicitTokenExpectationsProcessor.expects_closer({ node_name: "img", namespace: "html" }), false);
assert.equal(explicitTokenExpectationsProcessor.expects_closer({ nodeName: "DIV", namespaceName: "html" }), true);
assert.equal(explicitTokenExpectationsProcessor.expects_closer({ node_name: "TITLE", namespace: "html" }), false);
assert.equal(explicitTokenExpectationsProcessor.expects_closer({ node_name: "#text", namespace: "html" }), false);
assert.equal(explicitTokenExpectationsProcessor.expects_closer({ node_name: "html", namespace: "html" }), false);
assert.equal(explicitTokenExpectationsProcessor.expects_closer({
	node_name: "rect",
	namespace: "svg",
	has_self_closing_flag: true,
}), false);
assert.equal(explicitTokenExpectationsProcessor.expects_closer({
	node_name: "rect",
	namespace: "svg",
	has_self_closing_flag: false,
}), true);
explicitTokenExpectationsProcessor.destroy();

for (const [html, message] of [
	['<!DOCTYPE html><meta charset="utf8">', "Cannot yet process META tags with charset to determine encoding."],
	['<!DOCTYPE html><meta http-equiv="content-type" content="">', "Cannot yet process META tags with http-equiv Content-Type to determine encoding."],
]) {
	const supportedMetaProcessor = WP_HTML_Processor.create_full_parser(html);
	assert.equal(supportedMetaProcessor.next_tag("meta"), true);
	assert.equal(supportedMetaProcessor.get_last_error(), null);
	supportedMetaProcessor.destroy();

	const unsupportedMetaProcessor = new WP_HTML_Processor(html, { fullParser: true });
	assert.equal(unsupportedMetaProcessor.next_tag("meta"), false);
	assert.equal(unsupportedMetaProcessor.get_last_error(), WP_HTML_Processor.ERROR_UNSUPPORTED);
	assert.equal(unsupportedMetaProcessor.get_unsupported_exception().message, message);
	unsupportedMetaProcessor.destroy();
}

const fragmentMetaProcessor = WP_HTML_Processor.create_fragment('<meta charset="utf8">');
assert.equal(fragmentMetaProcessor.next_tag("meta"), true);
assert.equal(fragmentMetaProcessor.get_last_error(), null);
fragmentMetaProcessor.destroy();

const plaintextProcessor = WP_HTML_Processor.create_fragment("<plaintext>raw <b>markup</b>");
assert.equal(plaintextProcessor.next_tag("plaintext"), false);
assert.equal(plaintextProcessor.get_last_error(), WP_HTML_Processor.ERROR_UNSUPPORTED);
assert.equal(plaintextProcessor.get_unsupported_exception().message, "Cannot process PLAINTEXT elements.");
plaintextProcessor.destroy();

const fragmentDoctypeProcessor = WP_HTML_Processor.create_fragment("<!doctype html><p>x");
assert.equal(fragmentDoctypeProcessor.next_token(), true);
assert.equal(fragmentDoctypeProcessor.get_tag(), "P");
assert.deepEqual(fragmentDoctypeProcessor.get_breadcrumbs(), ["HTML", "BODY", "P"]);
fragmentDoctypeProcessor.destroy();
assert.equal(WP_HTML_Processor.normalize("<!doctype html><p>x"), "<p>x</p>");

const processorBookmarkLimit = WP_HTML_Processor.create_fragment("<div>");
assert.equal(processorBookmarkLimit.next_tag("div"), true);
for (let i = 0; i <= WP_HTML_Tag_Processor.MAX_BOOKMARKS; i += 1) {
	assert.equal(processorBookmarkLimit.set_bookmark(`processor-${i}`), true);
}
processorBookmarkLimit.destroy();

for (const html of [
	"<i>".repeat(WP_HTML_Processor.MAX_BOOKMARKS + 1),
	"<table><td>".repeat(Math.ceil(WP_HTML_Processor.MAX_BOOKMARKS / 4) + 1),
]) {
	const deepNestingProcessor = WP_HTML_Processor.create_fragment(html);
	while (deepNestingProcessor.next_token()) {
	}
	assert.equal(deepNestingProcessor.get_last_error(), WP_HTML_Processor.ERROR_EXCEEDED_MAX_BOOKMARKS);
	deepNestingProcessor.destroy();
}

const processorSeekBreadcrumbs = WP_HTML_Processor.create_fragment("<div><img></div><div><hr></div>");
assert.equal(processorSeekBreadcrumbs.next_tag("img"), true);
assert.deepEqual(processorSeekBreadcrumbs.get_breadcrumbs(), ["HTML", "BODY", "DIV", "IMG"]);
assert.equal(processorSeekBreadcrumbs.set_bookmark("first"), true);
assert.equal(processorSeekBreadcrumbs.next_tag("hr"), true);
assert.deepEqual(processorSeekBreadcrumbs.get_breadcrumbs(), ["HTML", "BODY", "DIV", "HR"]);
assert.equal(processorSeekBreadcrumbs.seek("first"), true);
assert.equal(processorSeekBreadcrumbs.get_tag(), "IMG");
assert.deepEqual(processorSeekBreadcrumbs.get_breadcrumbs(), ["HTML", "BODY", "DIV", "IMG"]);
processorSeekBreadcrumbs.destroy();

const processorSeekNamespace = WP_HTML_Processor.create_fragment("<custom-element /><svg><rect />");
assert.equal(processorSeekNamespace.next_tag("custom-element"), true);
assert.equal(processorSeekNamespace.has_self_closing_flag(), true);
assert.equal(processorSeekNamespace.expects_closer(), true);
assert.equal(processorSeekNamespace.set_bookmark("custom"), true);
assert.equal(processorSeekNamespace.next_tag("rect"), true);
assert.equal(processorSeekNamespace.get_namespace(), "svg");
assert.equal(processorSeekNamespace.has_self_closing_flag(), true);
assert.equal(processorSeekNamespace.expects_closer(), false);
assert.equal(processorSeekNamespace.seek("custom"), true);
assert.equal(processorSeekNamespace.get_tag(), "CUSTOM-ELEMENT");
assert.equal(processorSeekNamespace.get_namespace(), "html");
assert.equal(processorSeekNamespace.has_self_closing_flag(), true);
assert.equal(processorSeekNamespace.expects_closer(), true);
assert.equal(processorSeekNamespace.next_tag("rect"), true);
assert.deepEqual(processorSeekNamespace.get_breadcrumbs(), ["HTML", "BODY", "CUSTOM-ELEMENT", "SVG", "RECT"]);
processorSeekNamespace.destroy();

assert.equal(WP_HTML_Processor.normalize("<A><I><A>"), null);
const reconstructedFormattingProcessor = WP_HTML_Processor.create_fragment('<p><em class="tone">One<p>Two');
assert.equal(reconstructedFormattingProcessor.next_tag("em"), true);
assert.equal(reconstructedFormattingProcessor.get_attribute("class"), "tone");
assert.equal(reconstructedFormattingProcessor.next_tag("em"), true);
assert.equal(reconstructedFormattingProcessor.is_virtual(), true);
assert.equal(reconstructedFormattingProcessor.get_attribute("class"), "tone");
assert.equal(reconstructedFormattingProcessor.has_class("tone"), true);
assert.deepEqual(reconstructedFormattingProcessor.class_list(), ["tone"]);
assert.deepEqual(reconstructedFormattingProcessor.get_breadcrumbs(), ["HTML", "BODY", "P", "EM"]);
reconstructedFormattingProcessor.destroy();
assert.equal(
	WP_HTML_Processor.normalize('<p><em class="tone">One<p>Two'),
	'<p><em class="tone">One</em></p><p><em class="tone">Two</em></p>',
);

const repeatedFormattingProcessor = WP_HTML_Processor.create_full_parser("<p><b><b><b><b><p>x");
while (repeatedFormattingProcessor.next_token()) {
	if (
		repeatedFormattingProcessor.get_token_type() === "#text" &&
		repeatedFormattingProcessor.get_modifiable_text() === "x"
	) {
		break;
	}
}
assert.deepEqual(repeatedFormattingProcessor.get_breadcrumbs(), ["HTML", "BODY", "P", "B", "B", "B", "#text"]);
repeatedFormattingProcessor.destroy();
assert.equal(
	WP_HTML_Processor.normalize("<p><b><b><b><b><p>x"),
	"<p><b><b><b><b></b></b></b></b></p><p><b><b><b>x</b></b></b></p>",
);

const menuitemReconstructsFormattingProcessor = WP_HTML_Processor.create_full_parser("<!DOCTYPE html><p><b></p><menuitem>");
assert.equal(menuitemReconstructsFormattingProcessor.next_tag("menuitem"), true);
assert.deepEqual(menuitemReconstructsFormattingProcessor.get_breadcrumbs(), ["HTML", "BODY", "B", "MENUITEM"]);
menuitemReconstructsFormattingProcessor.destroy();

const closedFormattingProcessor = WP_HTML_Processor.create_fragment("<b>one</b><p>two");
assert.equal(closedFormattingProcessor.next_tag("b"), true);
assert.equal(closedFormattingProcessor.next_tag({ tag_name: "b", tag_closers: "visit" }), true);
assert.equal(closedFormattingProcessor.is_tag_closer(), true);
assert.deepEqual(closedFormattingProcessor.get_breadcrumbs(), ["HTML", "BODY"]);
assert.equal(closedFormattingProcessor.next_tag("p"), true);
assert.deepEqual(closedFormattingProcessor.get_breadcrumbs(), ["HTML", "BODY", "P"]);
closedFormattingProcessor.destroy();

assert.equal(
	WP_HTML_Processor.normalize("<b><i></b><p>x"),
	"<b><i></i></b><p><i>x</i></p>",
);

for (const html of [
	"</b><p>x",
	"<b></b></b><p>x",
]) {
	const unsupportedAdoptionFallbackProcessor = WP_HTML_Processor.create_fragment(html);
	while (unsupportedAdoptionFallbackProcessor.next_token()) {
	}
	assert.equal(unsupportedAdoptionFallbackProcessor.get_last_error(), WP_HTML_Processor.ERROR_UNSUPPORTED);
	assert.equal(
		unsupportedAdoptionFallbackProcessor.get_unsupported_exception().message,
		'Cannot run adoption agency when "any other end tag" is required.',
	);
	unsupportedAdoptionFallbackProcessor.destroy();
	assert.equal(WP_HTML_Processor.normalize(html), null);
}

for (const html of [
	"<b><div></b><p>x",
	"<a><div></a><p>x",
]) {
	const unsupportedAdoptionAgencyProcessor = WP_HTML_Processor.create_fragment(html);
	while (unsupportedAdoptionAgencyProcessor.next_token()) {
	}
	assert.equal(unsupportedAdoptionAgencyProcessor.get_last_error(), WP_HTML_Processor.ERROR_UNSUPPORTED);
	assert.equal(
		unsupportedAdoptionAgencyProcessor.get_unsupported_exception().message,
		"Cannot extract common ancestor in adoption agency algorithm.",
	);
	unsupportedAdoptionAgencyProcessor.destroy();
	assert.equal(WP_HTML_Processor.normalize(html), null);
}

for (const html of [
	'<a><strong>Click <span supported><a unsupported><big>Here</big></a></strong></a>',
	'<a><div supported><a unsupported></div></a>',
]) {
	const unsupportedAdoptionProcessor = WP_HTML_Processor.create_fragment(html);
	while (unsupportedAdoptionProcessor.next_token() && unsupportedAdoptionProcessor.get_attribute("supported") === null) {
	}
	assert.equal(unsupportedAdoptionProcessor.get_attribute("supported"), true);
	assert.equal(unsupportedAdoptionProcessor.get_last_error(), null);
	assert.equal(unsupportedAdoptionProcessor.next_token(), false);
	assert.equal(unsupportedAdoptionProcessor.get_last_error(), WP_HTML_Processor.ERROR_UNSUPPORTED);
	unsupportedAdoptionProcessor.destroy();
}

const fullParserText = WP_HTML_Processor.create_full_parser("text");
assert.equal(fullParserText.next_tag("body"), true);
assert.equal(fullParserText.get_tag(), "BODY");
assert.equal(fullParserText.is_virtual(), true);
assert.equal(fullParserText.is_tag_closer(), false);
assert.deepEqual(fullParserText.get_breadcrumbs(), ["HTML", "BODY"]);
assert.equal(fullParserText.set_bookmark("body"), false);
assert.equal(fullParserText.next_token(), true);
assert.equal(fullParserText.get_token_name(), "#text");
assert.deepEqual(fullParserText.get_breadcrumbs(), ["HTML", "BODY", "#text"]);
assert.equal(fullParserText.set_bookmark("text"), true);
fullParserText.destroy();

const fullParserDoctype = WP_HTML_Processor.create_full_parser("<!doctype html><p>Hi</p>");
assert.equal(fullParserDoctype.next_token(), true);
assert.equal(fullParserDoctype.get_token_type(), "#doctype");
assert.equal(fullParserDoctype.next_tag("p"), true);
assert.equal(fullParserDoctype.get_tag(), "P");
assert.deepEqual(fullParserDoctype.get_breadcrumbs(), ["HTML", "BODY", "P"]);
fullParserDoctype.destroy();

const fullParserHeadNoscriptBreakout = WP_HTML_Processor.create_full_parser("<head><noscript></br><!--foo--></noscript>");
assert.equal(fullParserHeadNoscriptBreakout.next_tag("noscript"), true);
assert.deepEqual(fullParserHeadNoscriptBreakout.get_breadcrumbs(), ["HTML", "HEAD", "NOSCRIPT"]);
assert.equal(fullParserHeadNoscriptBreakout.next_tag("br"), true);
assert.deepEqual(fullParserHeadNoscriptBreakout.get_breadcrumbs(), ["HTML", "BODY", "BR"]);
assert.equal(fullParserHeadNoscriptBreakout.next_token(), true);
assert.equal(fullParserHeadNoscriptBreakout.get_token_type(), "#comment");
assert.deepEqual(fullParserHeadNoscriptBreakout.get_breadcrumbs(), ["HTML", "BODY", "#comment"]);
fullParserHeadNoscriptBreakout.destroy();

const fullParserNestedHeadNoscript = WP_HTML_Processor.create_full_parser('<head><noscript><noscript class="foo"><!--foo--></noscript>');
assert.equal(fullParserNestedHeadNoscript.next_tag("noscript"), true);
assert.deepEqual(fullParserNestedHeadNoscript.get_breadcrumbs(), ["HTML", "HEAD", "NOSCRIPT"]);
assert.equal(fullParserNestedHeadNoscript.next_token(), true);
assert.equal(fullParserNestedHeadNoscript.get_token_type(), "#comment");
assert.deepEqual(fullParserNestedHeadNoscript.get_breadcrumbs(), ["HTML", "HEAD", "NOSCRIPT", "#comment"]);
assert.equal(fullParserNestedHeadNoscript.next_tag("noscript"), false);
assert.equal(fullParserNestedHeadNoscript.get_last_error(), null);
fullParserNestedHeadNoscript.destroy();

const fullParserHeadTemplateText = WP_HTML_Processor.create_full_parser("<template>Hello</template>");
assert.equal(fullParserHeadTemplateText.next_tag("template"), true);
assert.deepEqual(fullParserHeadTemplateText.get_breadcrumbs(), ["HTML", "HEAD", "TEMPLATE"]);
assert.equal(fullParserHeadTemplateText.next_token(), true);
assert.equal(fullParserHeadTemplateText.get_token_type(), "#text");
assert.deepEqual(fullParserHeadTemplateText.get_breadcrumbs(), ["HTML", "HEAD", "TEMPLATE", "#text"]);
assert.equal(fullParserHeadTemplateText.next_tag("body"), true);
assert.deepEqual(fullParserHeadTemplateText.get_breadcrumbs(), ["HTML", "BODY"]);
fullParserHeadTemplateText.destroy();

const fullParserExplicitHeadTemplate = WP_HTML_Processor.create_full_parser("<head><template><div></div></template></head>");
assert.equal(fullParserExplicitHeadTemplate.next_tag("div"), true);
assert.deepEqual(fullParserExplicitHeadTemplate.get_breadcrumbs(), ["HTML", "HEAD", "TEMPLATE", "DIV"]);
assert.equal(fullParserExplicitHeadTemplate.next_tag("body"), true);
assert.deepEqual(fullParserExplicitHeadTemplate.get_breadcrumbs(), ["HTML", "BODY"]);
fullParserExplicitHeadTemplate.destroy();

const fullParserBodyTemplateOuterCloser = WP_HTML_Processor.create_full_parser("<div><template></div>Hello");
assert.equal(fullParserBodyTemplateOuterCloser.next_tag("template"), true);
assert.deepEqual(fullParserBodyTemplateOuterCloser.get_breadcrumbs(), ["HTML", "BODY", "DIV", "TEMPLATE"]);
assert.equal(fullParserBodyTemplateOuterCloser.next_token(), true);
assert.equal(fullParserBodyTemplateOuterCloser.get_token_type(), "#text");
assert.equal(fullParserBodyTemplateOuterCloser.get_modifiable_text(), "Hello");
assert.deepEqual(fullParserBodyTemplateOuterCloser.get_breadcrumbs(), ["HTML", "BODY", "DIV", "TEMPLATE", "#text"]);
fullParserBodyTemplateOuterCloser.destroy();

const fullParserExplicitShell = WP_HTML_Processor.create_full_parser(
	"<html><head><title>Title</title></head><body><p>One<footer>Two</footer><ul><li>A<li>B</ul></body></html>",
);
const fullParserExplicitShellTokens = [];
while (fullParserExplicitShell.next_token()) {
	fullParserExplicitShellTokens.push(
		fullParserExplicitShell.get_token_type() === "#tag"
			? `${fullParserExplicitShell.is_tag_closer() ? "-" : "+"}${fullParserExplicitShell.get_tag()}`
			: fullParserExplicitShell.get_token_name(),
	);
}
assert.deepEqual(fullParserExplicitShellTokens, [
	"+HTML",
	"+HEAD",
	"+TITLE",
	"-HEAD",
	"+BODY",
	"+P",
	"#text",
	"-P",
	"+FOOTER",
	"#text",
	"-FOOTER",
	"+UL",
	"+LI",
	"#text",
	"-LI",
	"+LI",
	"#text",
	"-LI",
	"-UL",
	"-BODY",
	"-HTML",
]);
fullParserExplicitShell.destroy();

const fullParserFrameset = WP_HTML_Processor.create_full_parser("<frameset><frame></frameset>");
const fullParserFramesetTokens = [];
while (fullParserFrameset.next_token()) {
	fullParserFramesetTokens.push(
		fullParserFrameset.get_token_type() === "#tag"
			? `${fullParserFrameset.is_tag_closer() ? "-" : "+"}${fullParserFrameset.get_tag()}:${fullParserFrameset.get_breadcrumbs().join("/")}`
			: fullParserFrameset.get_token_name(),
	);
}
assert.deepEqual(fullParserFramesetTokens, [
	"+HTML:HTML",
	"+HEAD:HTML/HEAD",
	"-HEAD:HTML",
	"+FRAMESET:HTML/FRAMESET",
	"+FRAME:HTML/FRAMESET/FRAME",
	"-FRAMESET:HTML",
	"-HTML:",
]);
fullParserFrameset.destroy();

const fullParserFramesetNoframes = WP_HTML_Processor.create_full_parser("<frameset><noframes>x</noframes><frame></frameset>");
const fullParserFramesetNoframesTokens = [];
while (fullParserFramesetNoframes.next_token()) {
	fullParserFramesetNoframesTokens.push(
		fullParserFramesetNoframes.get_token_type() === "#tag"
			? `${fullParserFramesetNoframes.is_tag_closer() ? "-" : "+"}${fullParserFramesetNoframes.get_tag()}:${fullParserFramesetNoframes.get_breadcrumbs().join("/")}`
			: fullParserFramesetNoframes.get_token_name(),
	);
}
assert.deepEqual(fullParserFramesetNoframesTokens, [
	"+HTML:HTML",
	"+HEAD:HTML/HEAD",
	"-HEAD:HTML",
	"+FRAMESET:HTML/FRAMESET",
	"+NOFRAMES:HTML/FRAMESET/NOFRAMES",
	"+FRAME:HTML/FRAMESET/FRAME",
	"-FRAMESET:HTML",
	"-HTML:",
]);
fullParserFramesetNoframes.destroy();

for (const [html, message] of [
	["<frameset>text", "Non-whitespace characters cannot be handled in frameset."],
	["<frameset></frameset>text", "Non-whitespace characters cannot be handled in after frameset"],
	["<frameset></frameset></html>text", "Non-whitespace characters cannot be handled in after after frameset."],
]) {
	const framesetTextProcessor = WP_HTML_Processor.create_full_parser(html);
	while (framesetTextProcessor.next_token()) {
	}
	assert.equal(framesetTextProcessor.get_last_error(), WP_HTML_Processor.ERROR_UNSUPPORTED);
	assert.equal(framesetTextProcessor.get_unsupported_exception().message, message);
	framesetTextProcessor.destroy();
}

for (const html of [
	"<body><frameset></frameset><p>x</p>",
	"text<frameset></frameset><p>x</p>",
	"<div><frameset></frameset><p>x</p>",
]) {
	const ignoredFramesetProcessor = WP_HTML_Processor.create_full_parser(html);
	const visitedFramesetTags = [];
	while (ignoredFramesetProcessor.next_token()) {
		if (ignoredFramesetProcessor.get_token_type() === "#tag") {
			visitedFramesetTags.push(ignoredFramesetProcessor.get_tag());
		}
	}
	assert.equal(ignoredFramesetProcessor.get_last_error(), null);
	assert.equal(visitedFramesetTags.includes("FRAMESET"), false);
	assert.equal(visitedFramesetTags.includes("P"), true);
	ignoredFramesetProcessor.destroy();
}

const fullParserCommentAfterBody = WP_HTML_Processor.create_full_parser("<html><body></body><!--outside-->");
while (fullParserCommentAfterBody.next_token()) {
}
assert.equal(fullParserCommentAfterBody.get_last_error(), WP_HTML_Processor.ERROR_UNSUPPORTED);
assert.equal(fullParserCommentAfterBody.get_unsupported_exception().message, "Content outside of BODY is unsupported.");
fullParserCommentAfterBody.destroy();

const fullParserCommentAfterHtml = WP_HTML_Processor.create_full_parser("<html><body></body></html><!--outside-->");
while (fullParserCommentAfterHtml.next_token()) {
}
assert.equal(fullParserCommentAfterHtml.get_last_error(), WP_HTML_Processor.ERROR_UNSUPPORTED);
assert.equal(fullParserCommentAfterHtml.get_unsupported_exception().message, "Content outside of HTML is unsupported.");
fullParserCommentAfterHtml.destroy();

const noQuirksClasses = WP_HTML_Processor.create_full_parser('<!DOCTYPE html><span class="UPPER">');
assert.equal(noQuirksClasses.next_tag("span"), true);
assert.equal(noQuirksClasses.compat_mode, WP_HTML_Tag_Processor.NO_QUIRKS_MODE);
assert.equal(noQuirksClasses.has_class("upper"), false);
assert.equal(noQuirksClasses.has_class("UPPER"), true);
assert.equal(noQuirksClasses.add_class("upper"), true);
assert.equal(noQuirksClasses.get_updated_html(), '<!DOCTYPE html><span class="UPPER upper">');
noQuirksClasses.destroy();

const quirksClasses = WP_HTML_Processor.create_full_parser('<span class="UPPER">');
assert.equal(quirksClasses.next_tag("span"), true);
assert.equal(quirksClasses.compat_mode, WP_HTML_Tag_Processor.QUIRKS_MODE);
assert.equal(quirksClasses.has_class("upper"), true);
assert.equal(quirksClasses.has_class("UPPER"), true);
assert.equal(quirksClasses.add_class("upper"), true);
assert.equal(quirksClasses.get_updated_html(), '<span class="UPPER">');
assert.equal(quirksClasses.remove_class("upPer"), true);
assert.equal(quirksClasses.get_updated_html(), "<span >");
quirksClasses.destroy();

const noQuirksParagraphTable = WP_HTML_Processor.create_full_parser("<!DOCTYPE html><p><table>");
assert.equal(noQuirksParagraphTable.next_tag("table"), true);
assert.deepEqual(noQuirksParagraphTable.get_breadcrumbs(), ["HTML", "BODY", "TABLE"]);
noQuirksParagraphTable.destroy();

const quirksParagraphTable = WP_HTML_Processor.create_full_parser('<!DOCTYPE html PUBLIC "html"><p><table>');
assert.equal(quirksParagraphTable.next_tag("table"), true);
assert.equal(quirksParagraphTable.compat_mode, WP_HTML_Tag_Processor.QUIRKS_MODE);
assert.deepEqual(quirksParagraphTable.get_breadcrumbs(), ["HTML", "BODY", "P", "TABLE"]);
quirksParagraphTable.destroy();

const stepProcessor = WP_HTML_Processor.create_fragment("<div>Step</div>");
assert.equal(stepProcessor.step(), true);
assert.equal(stepProcessor.get_tag(), "DIV");
assert.equal(stepProcessor.step(WP_HTML_Processor.PROCESS_CURRENT_NODE), true);
assert.equal(stepProcessor.get_tag(), "DIV");
assert.equal(stepProcessor.step(), true);
assert.equal(stepProcessor.get_token_type(), "#text");
assert.equal(stepProcessor.step(), true);
assert.equal(stepProcessor.get_tag(), "DIV");
assert.equal(stepProcessor.is_tag_closer(), true);
stepProcessor.destroy();

const processorVisitClosersAlias = WP_HTML_Processor.create_fragment("<div></div>");
assert.equal(processorVisitClosersAlias.next_tag({ tag_name: "div" }), true);
assert.equal(processorVisitClosersAlias.is_tag_closer(), false);
assert.equal(processorVisitClosersAlias.next_tag({ tag_name: "div", visit_closers: true }), true);
assert.equal(processorVisitClosersAlias.is_tag_closer(), true);
processorVisitClosersAlias.destroy();

const virtualPOpenerProcessor = WP_HTML_Processor.create_fragment("</p>");
assert.equal(virtualPOpenerProcessor.next_token(), true);
assert.equal(virtualPOpenerProcessor.get_tag(), "P");
assert.equal(virtualPOpenerProcessor.is_virtual(), true);
assert.equal(virtualPOpenerProcessor.is_tag_closer(), false);
assert.deepEqual(virtualPOpenerProcessor.get_breadcrumbs(), ["HTML", "BODY", "P"]);
assert.equal(virtualPOpenerProcessor.get_current_depth(), 3);
assert.equal(virtualPOpenerProcessor.next_token(), true);
assert.equal(virtualPOpenerProcessor.get_tag(), "P");
assert.equal(virtualPOpenerProcessor.is_virtual(), true);
assert.equal(virtualPOpenerProcessor.is_tag_closer(), true);
assert.deepEqual(virtualPOpenerProcessor.get_breadcrumbs(), ["HTML", "BODY"]);
virtualPOpenerProcessor.destroy();

const virtualHeadingCloserProcessor = WP_HTML_Processor.create_fragment("<h1><h2>");
assert.equal(virtualHeadingCloserProcessor.next_token(), true);
assert.equal(virtualHeadingCloserProcessor.get_tag(), "H1");
assert.equal(virtualHeadingCloserProcessor.next_token(), true);
assert.equal(virtualHeadingCloserProcessor.get_tag(), "H1");
assert.equal(virtualHeadingCloserProcessor.is_virtual(), true);
assert.equal(virtualHeadingCloserProcessor.is_tag_closer(), true);
assert.equal(virtualHeadingCloserProcessor.get_modifiable_text(), "");
assert.deepEqual(virtualHeadingCloserProcessor.get_breadcrumbs(), ["HTML", "BODY"]);
assert.equal(virtualHeadingCloserProcessor.next_token(), true);
assert.equal(virtualHeadingCloserProcessor.get_tag(), "H2");
assert.equal(virtualHeadingCloserProcessor.is_virtual(), false);
assert.deepEqual(virtualHeadingCloserProcessor.get_breadcrumbs(), ["HTML", "BODY", "H2"]);
virtualHeadingCloserProcessor.destroy();

const virtualAnchorCloserProcessor = WP_HTML_Processor.create_fragment("<a><span><a>");
assert.equal(virtualAnchorCloserProcessor.next_token(), true);
assert.equal(virtualAnchorCloserProcessor.get_tag(), "A");
assert.equal(virtualAnchorCloserProcessor.next_token(), true);
assert.equal(virtualAnchorCloserProcessor.get_tag(), "SPAN");
assert.equal(virtualAnchorCloserProcessor.next_token(), true);
assert.equal(virtualAnchorCloserProcessor.get_tag(), "SPAN");
assert.equal(virtualAnchorCloserProcessor.is_virtual(), true);
assert.equal(virtualAnchorCloserProcessor.is_tag_closer(), true);
assert.deepEqual(virtualAnchorCloserProcessor.get_breadcrumbs(), ["HTML", "BODY", "A"]);
assert.equal(virtualAnchorCloserProcessor.next_token(), true);
assert.equal(virtualAnchorCloserProcessor.get_tag(), "A");
assert.equal(virtualAnchorCloserProcessor.is_virtual(), true);
assert.equal(virtualAnchorCloserProcessor.is_tag_closer(), true);
assert.deepEqual(virtualAnchorCloserProcessor.get_breadcrumbs(), ["HTML", "BODY"]);
assert.equal(virtualAnchorCloserProcessor.next_token(), true);
assert.equal(virtualAnchorCloserProcessor.get_tag(), "A");
assert.equal(virtualAnchorCloserProcessor.is_virtual(), false);
assert.deepEqual(virtualAnchorCloserProcessor.get_breadcrumbs(), ["HTML", "BODY", "A"]);
virtualAnchorCloserProcessor.destroy();

const nestedHeadingProcessor = WP_HTML_Processor.create_fragment("<h2><span>Major<h4 target>");
assert.equal(nestedHeadingProcessor.next_tag("h4"), true);
assert.deepEqual(nestedHeadingProcessor.get_breadcrumbs(), ["HTML", "BODY", "H2", "SPAN", "H4"]);
assert.equal(nestedHeadingProcessor.get_attribute("target"), true);
nestedHeadingProcessor.destroy();

const nestedProcessor = WP_HTML_Processor.create_fragment("<div><span><figure><img></figure></span></div>");
assert.equal(nestedProcessor.next_tag({ breadcrumbs: ["FIGURE", "IMG"] }), true);
assert.equal(nestedProcessor.get_tag(), "IMG");
assert.equal(nestedProcessor.expects_closer(), false);
assert.deepEqual(nestedProcessor.get_breadcrumbs(), ["HTML", "BODY", "DIV", "SPAN", "FIGURE", "IMG"]);
nestedProcessor.destroy();

const voidProcessor = WP_HTML_Processor.create_fragment("<img><div>");
assert.equal(voidProcessor.next_tag("div"), true);
assert.deepEqual(voidProcessor.get_breadcrumbs(), ["HTML", "BODY", "DIV"]);
voidProcessor.destroy();

const paragraphProcessor = WP_HTML_Processor.create_fragment("<p><p target>");
assert.equal(paragraphProcessor.next_tag({ breadcrumbs: ["P"], match_offset: 2 }), true);
assert.deepEqual(paragraphProcessor.get_breadcrumbs(), ["HTML", "BODY", "P"]);
assert.equal(paragraphProcessor.get_attribute("target"), true);
paragraphProcessor.destroy();

const listingClosesParagraphProcessor = WP_HTML_Processor.create_full_parser("<!doctype html><p>foo<listing>bar<p>baz");
assert.equal(listingClosesParagraphProcessor.next_tag("listing"), true);
assert.deepEqual(listingClosesParagraphProcessor.get_breadcrumbs(), ["HTML", "BODY", "LISTING"]);
assert.equal(listingClosesParagraphProcessor.next_tag("p"), true);
assert.deepEqual(listingClosesParagraphProcessor.get_breadcrumbs(), ["HTML", "BODY", "LISTING", "P"]);
listingClosesParagraphProcessor.destroy();

const articleProcessor = WP_HTML_Processor.create_fragment("<p><p><article target>");
assert.equal(articleProcessor.next_tag("article"), true);
assert.deepEqual(articleProcessor.get_breadcrumbs(), ["HTML", "BODY", "ARTICLE"]);
assert.equal(articleProcessor.get_attribute("target"), true);
articleProcessor.destroy();

const buttonProcessor = WP_HTML_Processor.create_fragment("<div><button one><p><span><button two>Two</button></span></p></div><button three>");
assert.equal(buttonProcessor.next_tag("button"), true);
assert.deepEqual(buttonProcessor.get_breadcrumbs(), ["HTML", "BODY", "DIV", "BUTTON"]);
assert.equal(buttonProcessor.get_attribute("one"), true);
assert.equal(buttonProcessor.next_tag("button"), true);
assert.deepEqual(buttonProcessor.get_breadcrumbs(), ["HTML", "BODY", "DIV", "BUTTON"]);
assert.equal(buttonProcessor.get_attribute("two"), true);
assert.equal(buttonProcessor.next_tag("button"), true);
assert.deepEqual(buttonProcessor.get_breadcrumbs(), ["HTML", "BODY", "BUTTON"]);
assert.equal(buttonProcessor.get_attribute("three"), true);
buttonProcessor.destroy();

const selectMenuitemProcessor = WP_HTML_Processor.create_full_parser("<!DOCTYPE html><select><menuitem></select>");
assert.equal(selectMenuitemProcessor.next_tag("select"), true);
assert.equal(selectMenuitemProcessor.next_tag("menuitem"), false);
assert.equal(selectMenuitemProcessor.get_last_error(), null);
selectMenuitemProcessor.destroy();

const listBoundaryProcessor = WP_HTML_Processor.create_fragment("<li><li><blockquote><li target>");
assert.equal(listBoundaryProcessor.next_tag({ breadcrumbs: ["LI"], match_offset: 3 }), true);
assert.deepEqual(listBoundaryProcessor.get_breadcrumbs(), ["HTML", "BODY", "LI", "BLOCKQUOTE", "LI"]);
assert.equal(listBoundaryProcessor.get_attribute("target"), true);
listBoundaryProcessor.destroy();

const listImpliedProcessor = WP_HTML_Processor.create_fragment("<li><li><div><li target>");
assert.equal(listImpliedProcessor.next_tag({ breadcrumbs: ["LI"], match_offset: 3 }), true);
assert.deepEqual(listImpliedProcessor.get_breadcrumbs(), ["HTML", "BODY", "LI"]);
assert.equal(listImpliedProcessor.get_attribute("target"), true);
listImpliedProcessor.destroy();

const listPInButtonScopeProcessor = WP_HTML_Processor.create_fragment("<li><li><p><button><p><li target>");
assert.equal(listPInButtonScopeProcessor.next_tag({ breadcrumbs: ["LI"], match_offset: 3 }), true);
assert.deepEqual(listPInButtonScopeProcessor.get_breadcrumbs(), ["HTML", "BODY", "LI", "P", "BUTTON", "LI"]);
assert.equal(listPInButtonScopeProcessor.get_attribute("target"), true);
listPInButtonScopeProcessor.destroy();

const ddPInButtonScopeProcessor = WP_HTML_Processor.create_fragment("<dd><dd><p><button><p><dd target>");
assert.equal(ddPInButtonScopeProcessor.next_tag({ breadcrumbs: ["DD"], match_offset: 3 }), true);
assert.deepEqual(ddPInButtonScopeProcessor.get_breadcrumbs(), ["HTML", "BODY", "DD", "P", "BUTTON", "DD"]);
assert.equal(ddPInButtonScopeProcessor.get_attribute("target"), true);
ddPInButtonScopeProcessor.destroy();

const dtPInButtonScopeProcessor = WP_HTML_Processor.create_fragment("<dt><dt><p><button><p><dt target>");
assert.equal(dtPInButtonScopeProcessor.next_tag({ breadcrumbs: ["DT"], match_offset: 3 }), true);
assert.deepEqual(dtPInButtonScopeProcessor.get_breadcrumbs(), ["HTML", "BODY", "DT", "P", "BUTTON", "DT"]);
assert.equal(dtPInButtonScopeProcessor.get_attribute("target"), true);
dtPInButtonScopeProcessor.destroy();

const unexpectedListCloserProcessor = WP_HTML_Processor.create_fragment("<ul><li><ul></li><li target>a</li></ul></li></ul>");
assert.equal(unexpectedListCloserProcessor.next_tag({ breadcrumbs: ["LI"], match_offset: 2 }), true);
assert.deepEqual(unexpectedListCloserProcessor.get_breadcrumbs(), ["HTML", "BODY", "UL", "LI", "UL", "LI"]);
assert.equal(unexpectedListCloserProcessor.get_attribute("target"), true);
unexpectedListCloserProcessor.destroy();

const rubyImpliedEndTagsProcessor = WP_HTML_Processor.create_full_parser("<html><ruby>a<rb>b<rt></ruby></html>");
assert.equal(rubyImpliedEndTagsProcessor.next_tag("rt"), true);
assert.deepEqual(rubyImpliedEndTagsProcessor.get_breadcrumbs(), ["HTML", "BODY", "RUBY", "RT"]);
rubyImpliedEndTagsProcessor.destroy();

const rubyRtcChildrenProcessor = WP_HTML_Processor.create_full_parser("<html><ruby>a<rtc>b<rt>c<rt>d</ruby></html>");
assert.equal(rubyRtcChildrenProcessor.next_tag("rt"), true);
assert.deepEqual(rubyRtcChildrenProcessor.get_breadcrumbs(), ["HTML", "BODY", "RUBY", "RTC", "RT"]);
assert.equal(rubyRtcChildrenProcessor.next_tag("rt"), true);
assert.deepEqual(rubyRtcChildrenProcessor.get_breadcrumbs(), ["HTML", "BODY", "RUBY", "RTC", "RT"]);
rubyRtcChildrenProcessor.destroy();

const hrProcessor = WP_HTML_Processor.create_fragment("<p><hr>");
assert.equal(hrProcessor.next_tag("hr"), true);
assert.deepEqual(hrProcessor.get_breadcrumbs(), ["HTML", "BODY", "HR"]);
assert.equal(hrProcessor.expects_closer(), false);
hrProcessor.destroy();

const brEndTagProcessor = WP_HTML_Processor.create_fragment('</br id="an-opener" html>');
assert.equal(brEndTagProcessor.next_tag(), true);
assert.equal(brEndTagProcessor.get_tag(), "BR");
assert.equal(brEndTagProcessor.is_tag_closer(), false);
assert.equal(brEndTagProcessor.get_attribute_names_with_prefix(""), null);
assert.deepEqual(brEndTagProcessor.get_breadcrumbs(), ["HTML", "BODY", "BR"]);
brEndTagProcessor.destroy();

const directFormCloserProcessor = WP_HTML_Processor.create_fragment("<form></form><p>x");
assert.equal(directFormCloserProcessor.next_tag("form"), true);
assert.equal(directFormCloserProcessor.is_tag_closer(), false);
assert.equal(directFormCloserProcessor.next_tag({ tag_name: "form", tag_closers: "visit" }), true);
assert.equal(directFormCloserProcessor.is_tag_closer(), true);
assert.deepEqual(directFormCloserProcessor.get_breadcrumbs(), ["HTML", "BODY"]);
assert.equal(directFormCloserProcessor.next_tag("p"), true);
assert.deepEqual(directFormCloserProcessor.get_breadcrumbs(), ["HTML", "BODY", "P"]);
directFormCloserProcessor.destroy();

const impliedFormCloserProcessor = WP_HTML_Processor.create_fragment("<form><p></form><span>x");
assert.equal(impliedFormCloserProcessor.next_tag("p"), true);
assert.equal(impliedFormCloserProcessor.next_token(), true);
assert.equal(impliedFormCloserProcessor.get_tag(), "P");
assert.equal(impliedFormCloserProcessor.is_virtual(), true);
assert.equal(impliedFormCloserProcessor.is_tag_closer(), true);
assert.deepEqual(impliedFormCloserProcessor.get_breadcrumbs(), ["HTML", "BODY", "FORM"]);
assert.equal(impliedFormCloserProcessor.next_token(), true);
assert.equal(impliedFormCloserProcessor.get_tag(), "FORM");
assert.equal(impliedFormCloserProcessor.is_virtual(), false);
assert.equal(impliedFormCloserProcessor.is_tag_closer(), true);
assert.deepEqual(impliedFormCloserProcessor.get_breadcrumbs(), ["HTML", "BODY"]);
assert.equal(impliedFormCloserProcessor.next_tag("span"), true);
assert.deepEqual(impliedFormCloserProcessor.get_breadcrumbs(), ["HTML", "BODY", "SPAN"]);
impliedFormCloserProcessor.destroy();

for (const html of [
	"<form><div></form><p>x",
	"<form><button></form><p>x",
]) {
	const unsupportedFormCloserProcessor = WP_HTML_Processor.create_fragment(html);
	while (unsupportedFormCloserProcessor.next_token()) {
	}
	assert.equal(unsupportedFormCloserProcessor.get_last_error(), WP_HTML_Processor.ERROR_UNSUPPORTED);
	assert.equal(
		unsupportedFormCloserProcessor.get_unsupported_exception().message,
		"Cannot close a FORM when other elements remain open as this would throw off the breadcrumbs for the following tokens.",
	);
	unsupportedFormCloserProcessor.destroy();
	assert.equal(WP_HTML_Processor.normalize(html), null);
}

assert.equal(
	WP_HTML_Processor.normalize('<form id><table te"><script></script><td srce" ID/></form><form claslicate>'),
	'<form id><table te"><script></script><tbody><tr><td srce" id></td></tr></tbody></table></form>',
);
assert.equal(
	WP_HTML_Processor.normalize("<form><table><caption></form><form >"),
	"<form><table><caption></caption></table></form>",
);

const selectOptionProcessor = WP_HTML_Processor.create_fragment("<select><option>one<option>two</select>");
assert.equal(selectOptionProcessor.next_tag({ breadcrumbs: ["SELECT", "OPTION"], match_offset: 2 }), true);
assert.deepEqual(selectOptionProcessor.get_breadcrumbs(), ["HTML", "BODY", "SELECT", "OPTION"]);
selectOptionProcessor.destroy();
assert.equal(
	WP_HTML_Processor.normalize("<select><option>one<option>two</select>"),
	"<select><option>one</option><option>two</option></select>",
);

const selectOptgroupProcessor = WP_HTML_Processor.create_fragment("<select><optgroup><option>one<optgroup><option>two</select>");
assert.equal(selectOptgroupProcessor.next_tag({ breadcrumbs: ["SELECT", "OPTGROUP"], match_offset: 2 }), true);
assert.deepEqual(selectOptgroupProcessor.get_breadcrumbs(), ["HTML", "BODY", "SELECT", "OPTGROUP"]);
selectOptgroupProcessor.destroy();
assert.equal(
	WP_HTML_Processor.normalize("<select><optgroup><option>one<optgroup><option>two</select>"),
	"<select><optgroup><option>one</option></optgroup><optgroup><option>two</option></optgroup></select>",
);

const selectHrProcessor = WP_HTML_Processor.create_fragment("<select><optgroup><option>one<hr><option>two</select>");
assert.equal(selectHrProcessor.next_tag("hr"), true);
assert.deepEqual(selectHrProcessor.get_breadcrumbs(), ["HTML", "BODY", "SELECT", "HR"]);
selectHrProcessor.destroy();
assert.equal(
	WP_HTML_Processor.normalize("<select><optgroup><option>one<hr><option>two</select>"),
	"<select><optgroup><option>one</option></optgroup><hr><option>two</option></select>",
);

const selectDoctypeProcessor = WP_HTML_Processor.create_fragment("<select><!doctype html><option>one");
assert.equal(selectDoctypeProcessor.next_tag("option"), true);
assert.deepEqual(selectDoctypeProcessor.get_breadcrumbs(), ["HTML", "BODY", "SELECT", "OPTION"]);
selectDoctypeProcessor.destroy();
assert.equal(
	WP_HTML_Processor.normalize("<select><!doctype html><option>one"),
	"<select><option>one</option></select>",
);

const selectInputProcessor = WP_HTML_Processor.create_fragment("<select><option>one<input><p>after");
assert.equal(selectInputProcessor.next_tag("input"), true);
assert.deepEqual(selectInputProcessor.get_breadcrumbs(), ["HTML", "BODY", "INPUT"]);
assert.equal(selectInputProcessor.next_tag("p"), true);
assert.deepEqual(selectInputProcessor.get_breadcrumbs(), ["HTML", "BODY", "P"]);
selectInputProcessor.destroy();
assert.equal(
	WP_HTML_Processor.normalize("<select><option>one<input><p>after"),
	"<select><option>one</option></select><input><p>after</p>",
);

const selectTextareaProcessor = WP_HTML_Processor.create_fragment("<select><option>one<textarea>after</textarea><p>end");
assert.equal(selectTextareaProcessor.next_tag("textarea"), true);
assert.deepEqual(selectTextareaProcessor.get_breadcrumbs(), ["HTML", "BODY", "TEXTAREA"]);
assert.equal(selectTextareaProcessor.next_tag("p"), true);
assert.deepEqual(selectTextareaProcessor.get_breadcrumbs(), ["HTML", "BODY", "P"]);
selectTextareaProcessor.destroy();

const selectInTableProcessor = WP_HTML_Processor.create_fragment("<table><select><option>one<tr><td>cell");
assert.equal(selectInTableProcessor.next_tag("tr"), true);
assert.deepEqual(selectInTableProcessor.get_breadcrumbs(), ["HTML", "BODY", "TABLE", "TBODY", "TR"]);
assert.equal(selectInTableProcessor.next_tag("td"), true);
assert.deepEqual(selectInTableProcessor.get_breadcrumbs(), ["HTML", "BODY", "TABLE", "TBODY", "TR", "TD"]);
selectInTableProcessor.destroy();
assert.equal(
	WP_HTML_Processor.normalize("<table><select><option>one<tr><td>cell"),
	"<table><select><option>one</option></select><tbody><tr><td>cell</td></tr></tbody></table>",
);

const selectInTableEndTagProcessor = WP_HTML_Processor.create_fragment("<table><select><option>one</table><p>after");
assert.equal(selectInTableEndTagProcessor.next_tag("p"), true);
assert.deepEqual(selectInTableEndTagProcessor.get_breadcrumbs(), ["HTML", "BODY", "P"]);
selectInTableEndTagProcessor.destroy();
assert.equal(
	WP_HTML_Processor.normalize("<table><select><option>one</table><p>after"),
	"<table><select><option>one</option></select></table><p>after</p>",
);

const bareColProcessor = WP_HTML_Processor.create_fragment("<table><col><tr><td>cell");
assert.equal(bareColProcessor.next_tag("col"), true);
assert.deepEqual(bareColProcessor.get_breadcrumbs(), ["HTML", "BODY", "TABLE", "COLGROUP", "COL"]);
assert.equal(bareColProcessor.next_tag("tr"), true);
assert.deepEqual(bareColProcessor.get_breadcrumbs(), ["HTML", "BODY", "TABLE", "TBODY", "TR"]);
bareColProcessor.destroy();
assert.equal(
	WP_HTML_Processor.normalize("<table><col><tr><td>cell"),
	"<table><colgroup><col></colgroup><tbody><tr><td>cell</td></tr></tbody></table>",
);

const colgroupProcessor = WP_HTML_Processor.create_fragment("<table><colgroup><tbody><tr><td>cell");
assert.equal(colgroupProcessor.next_tag("tbody"), true);
assert.deepEqual(colgroupProcessor.get_breadcrumbs(), ["HTML", "BODY", "TABLE", "TBODY"]);
colgroupProcessor.destroy();
assert.equal(
	WP_HTML_Processor.normalize("<table><colgroup><tbody><tr><td>cell"),
	"<table><colgroup></colgroup><tbody><tr><td>cell</td></tr></tbody></table>",
);

const tableCaptionProcessor = WP_HTML_Processor.create_fragment("<table><caption><p>cap<tr><td>cell");
assert.equal(tableCaptionProcessor.next_tag("tr"), true);
assert.deepEqual(tableCaptionProcessor.get_breadcrumbs(), ["HTML", "BODY", "TABLE", "TBODY", "TR"]);
tableCaptionProcessor.destroy();
assert.equal(
	WP_HTML_Processor.normalize("<table><caption><p>cap<tr><td>cell"),
	"<table><caption><p>cap</p></caption><tbody><tr><td>cell</td></tr></tbody></table>",
);

const tableCaptionEndProcessor = WP_HTML_Processor.create_fragment("<table><caption>cap</table><p>after");
assert.equal(tableCaptionEndProcessor.next_tag("p"), true);
assert.deepEqual(tableCaptionEndProcessor.get_breadcrumbs(), ["HTML", "BODY", "P"]);
tableCaptionEndProcessor.destroy();
assert.equal(
	WP_HTML_Processor.normalize("<table><caption>cap</table><p>after"),
	"<table><caption>cap</caption></table><p>after</p>",
);

const tableTextProcessor = WP_HTML_Processor.create_fragment("<table>text<tr><td>cell");
while (tableTextProcessor.next_token()) {
}
assert.equal(tableTextProcessor.get_last_error(), WP_HTML_Processor.ERROR_UNSUPPORTED);
assert.equal(tableTextProcessor.get_unsupported_exception().message, "Foster parenting is not supported.");
tableTextProcessor.destroy();
assert.equal(WP_HTML_Processor.normalize("<table>text<tr><td>cell"), null);

const colgroupTextProcessor = WP_HTML_Processor.create_fragment("<table><colgroup> foo</colgroup></table>");
while (colgroupTextProcessor.next_token()) {
}
assert.equal(colgroupTextProcessor.get_last_error(), WP_HTML_Processor.ERROR_UNSUPPORTED);
assert.equal(colgroupTextProcessor.get_unsupported_exception().message, "Foster parenting is not supported.");
colgroupTextProcessor.destroy();
assert.equal(WP_HTML_Processor.normalize("<table><colgroup> foo</colgroup></table>"), null);

const tableWhitespaceProcessor = WP_HTML_Processor.create_fragment("<table> \n <tr><td>cell");
assert.equal(tableWhitespaceProcessor.next_token(), true);
assert.equal(tableWhitespaceProcessor.get_tag(), "TABLE");
assert.equal(tableWhitespaceProcessor.next_token(), true);
assert.equal(tableWhitespaceProcessor.get_token_name(), "#text");
assert.deepEqual(tableWhitespaceProcessor.get_breadcrumbs(), ["HTML", "BODY", "TABLE", "#text"]);
assert.equal(tableWhitespaceProcessor.next_tag("td"), true);
assert.equal(tableWhitespaceProcessor.get_last_error(), null);
tableWhitespaceProcessor.destroy();

const tableNullTextProcessor = WP_HTML_Processor.create_fragment("<table>\0<tr><td>cell");
assert.equal(tableNullTextProcessor.next_tag("tr"), true);
assert.deepEqual(tableNullTextProcessor.get_breadcrumbs(), ["HTML", "BODY", "TABLE", "TBODY", "TR"]);
assert.equal(tableNullTextProcessor.get_last_error(), null);
tableNullTextProcessor.destroy();

const tableDoctypeProcessor = WP_HTML_Processor.create_fragment("<table><!doctype html><tr><td>cell");
assert.equal(tableDoctypeProcessor.next_tag("td"), true);
assert.deepEqual(tableDoctypeProcessor.get_breadcrumbs(), ["HTML", "BODY", "TABLE", "TBODY", "TR", "TD"]);
tableDoctypeProcessor.destroy();
assert.equal(
	WP_HTML_Processor.normalize("<table><!doctype html><tr><td>cell"),
	"<table><tbody><tr><td>cell</td></tr></tbody></table>",
);

for (const html of [
	"<table><div><tr><td>cell",
	"<table><tbody><div><tr><td>cell",
	"<table><tr><div><td>cell",
	"<table><input><tr><td>cell",
]) {
	const tableFosterParentingProcessor = WP_HTML_Processor.create_fragment(html);
	while (tableFosterParentingProcessor.next_token()) {
	}
	assert.equal(tableFosterParentingProcessor.get_last_error(), WP_HTML_Processor.ERROR_UNSUPPORTED);
	assert.equal(tableFosterParentingProcessor.get_unsupported_exception().message, "Foster parenting is not supported.");
	tableFosterParentingProcessor.destroy();
	assert.equal(WP_HTML_Processor.normalize(html), null);
}

const tableHiddenInputProcessor = WP_HTML_Processor.create_fragment("<table><input type=hidden><tr><td>cell");
assert.equal(tableHiddenInputProcessor.next_tag("input"), true);
assert.equal(tableHiddenInputProcessor.get_attribute("type"), "hidden");
assert.deepEqual(tableHiddenInputProcessor.get_breadcrumbs(), ["HTML", "BODY", "TABLE", "INPUT"]);
assert.equal(tableHiddenInputProcessor.next_tag("td"), true);
assert.equal(tableHiddenInputProcessor.get_last_error(), null);
tableHiddenInputProcessor.destroy();

const tableFormProcessor = WP_HTML_Processor.create_fragment("<table><form><!--comment-->");
assert.equal(tableFormProcessor.next_tag("form"), true);
assert.equal(tableFormProcessor.get_tag(), "FORM");
assert.equal(tableFormProcessor.is_virtual(), false);
assert.deepEqual(tableFormProcessor.get_breadcrumbs(), ["HTML", "BODY", "TABLE", "FORM"]);
assert.equal(tableFormProcessor.next_token(), true);
assert.equal(tableFormProcessor.get_token_name(), "FORM");
assert.equal(tableFormProcessor.get_token_type(), "#tag");
assert.equal(tableFormProcessor.is_virtual(), true);
assert.equal(tableFormProcessor.is_tag_closer(), true);
assert.equal(tableFormProcessor.get_attribute_names_with_prefix(""), null);
assert.equal(tableFormProcessor.set_attribute("id", "ignored"), false);
assert.equal(tableFormProcessor.set_bookmark("virtual-form"), false);
assert.equal(tableFormProcessor.get_comment_type(), null);
assert.deepEqual(tableFormProcessor.get_breadcrumbs(), ["HTML", "BODY", "TABLE"]);
assert.equal(tableFormProcessor.next_token(), true);
assert.equal(tableFormProcessor.get_token_name(), "#comment");
assert.deepEqual(tableFormProcessor.get_breadcrumbs(), ["HTML", "BODY", "TABLE", "#comment"]);
tableFormProcessor.destroy();

const tableCellProcessor = WP_HTML_Processor.create_fragment("<table><td>cell");
assert.equal(tableCellProcessor.next_token(), true);
assert.equal(tableCellProcessor.get_tag(), "TABLE");
assert.equal(tableCellProcessor.is_virtual(), false);
assert.deepEqual(tableCellProcessor.get_breadcrumbs(), ["HTML", "BODY", "TABLE"]);
assert.equal(tableCellProcessor.next_token(), true);
assert.equal(tableCellProcessor.get_tag(), "TBODY");
assert.equal(tableCellProcessor.is_virtual(), true);
assert.equal(tableCellProcessor.is_tag_closer(), false);
assert.equal(tableCellProcessor.set_bookmark("tbody"), false);
assert.deepEqual(tableCellProcessor.get_breadcrumbs(), ["HTML", "BODY", "TABLE", "TBODY"]);
assert.equal(tableCellProcessor.next_token(), true);
assert.equal(tableCellProcessor.get_tag(), "TR");
assert.equal(tableCellProcessor.is_virtual(), true);
assert.equal(tableCellProcessor.is_tag_closer(), false);
assert.deepEqual(tableCellProcessor.get_breadcrumbs(), ["HTML", "BODY", "TABLE", "TBODY", "TR"]);
assert.equal(tableCellProcessor.next_token(), true);
assert.equal(tableCellProcessor.get_tag(), "TD");
assert.equal(tableCellProcessor.is_virtual(), false);
assert.deepEqual(tableCellProcessor.get_breadcrumbs(), ["HTML", "BODY", "TABLE", "TBODY", "TR", "TD"]);
tableCellProcessor.destroy();

const ignoredTableEndTagsProcessor = WP_HTML_Processor.create_fragment(
	"<table></body></caption></col></colgroup></html></tbody></td></tfoot></th></thead></tr><td>",
);
assert.equal(ignoredTableEndTagsProcessor.next_tag("td"), true);
assert.deepEqual(ignoredTableEndTagsProcessor.get_breadcrumbs(), ["HTML", "BODY", "TABLE", "TBODY", "TR", "TD"]);
ignoredTableEndTagsProcessor.destroy();

const ignoredTableCellEndTagsProcessor = WP_HTML_Processor.create_fragment(
	"<table><td></body></caption></col></colgroup></html>foo",
);
assert.equal(ignoredTableCellEndTagsProcessor.next_tag("td"), true);
assert.equal(ignoredTableCellEndTagsProcessor.next_token(), true);
assert.equal(ignoredTableCellEndTagsProcessor.get_token_name(), "#text");
assert.equal(ignoredTableCellEndTagsProcessor.get_modifiable_text(), "foo");
assert.deepEqual(ignoredTableCellEndTagsProcessor.get_breadcrumbs(), ["HTML", "BODY", "TABLE", "TBODY", "TR", "TD", "#text"]);
ignoredTableCellEndTagsProcessor.destroy();

const adjacentTableCellProcessor = WP_HTML_Processor.create_fragment("<table><td>a<td>b");
assert.equal(adjacentTableCellProcessor.next_tag("td"), true);
assert.deepEqual(adjacentTableCellProcessor.get_breadcrumbs(), ["HTML", "BODY", "TABLE", "TBODY", "TR", "TD"]);
assert.equal(adjacentTableCellProcessor.next_token(), true);
assert.equal(adjacentTableCellProcessor.get_token_name(), "#text");
assert.equal(adjacentTableCellProcessor.next_token(), true);
assert.equal(adjacentTableCellProcessor.get_tag(), "TD");
assert.equal(adjacentTableCellProcessor.is_virtual(), true);
assert.equal(adjacentTableCellProcessor.is_tag_closer(), true);
assert.deepEqual(adjacentTableCellProcessor.get_breadcrumbs(), ["HTML", "BODY", "TABLE", "TBODY", "TR"]);
assert.equal(adjacentTableCellProcessor.next_token(), true);
assert.equal(adjacentTableCellProcessor.get_tag(), "TD");
assert.equal(adjacentTableCellProcessor.is_virtual(), false);
assert.equal(adjacentTableCellProcessor.is_tag_closer(), false);
assert.deepEqual(adjacentTableCellProcessor.get_breadcrumbs(), ["HTML", "BODY", "TABLE", "TBODY", "TR", "TD"]);
adjacentTableCellProcessor.destroy();

const adjacentTableRowProcessor = WP_HTML_Processor.create_fragment("<table><tr><td>a<tr><td>b");
assert.equal(adjacentTableRowProcessor.next_tag("td"), true);
assert.deepEqual(adjacentTableRowProcessor.get_breadcrumbs(), ["HTML", "BODY", "TABLE", "TBODY", "TR", "TD"]);
assert.equal(adjacentTableRowProcessor.next_token(), true);
assert.equal(adjacentTableRowProcessor.get_token_name(), "#text");
assert.equal(adjacentTableRowProcessor.next_token(), true);
assert.equal(adjacentTableRowProcessor.get_tag(), "TD");
assert.equal(adjacentTableRowProcessor.is_virtual(), true);
assert.equal(adjacentTableRowProcessor.is_tag_closer(), true);
assert.deepEqual(adjacentTableRowProcessor.get_breadcrumbs(), ["HTML", "BODY", "TABLE", "TBODY", "TR"]);
assert.equal(adjacentTableRowProcessor.next_token(), true);
assert.equal(adjacentTableRowProcessor.get_tag(), "TR");
assert.equal(adjacentTableRowProcessor.is_virtual(), true);
assert.equal(adjacentTableRowProcessor.is_tag_closer(), true);
assert.deepEqual(adjacentTableRowProcessor.get_breadcrumbs(), ["HTML", "BODY", "TABLE", "TBODY"]);
assert.equal(adjacentTableRowProcessor.next_token(), true);
assert.equal(adjacentTableRowProcessor.get_tag(), "TR");
assert.equal(adjacentTableRowProcessor.is_virtual(), false);
assert.equal(adjacentTableRowProcessor.is_tag_closer(), false);
assert.deepEqual(adjacentTableRowProcessor.get_breadcrumbs(), ["HTML", "BODY", "TABLE", "TBODY", "TR"]);
adjacentTableRowProcessor.destroy();

const adjacentTableSectionProcessor = WP_HTML_Processor.create_fragment("<table><tbody><tr><td>a<tbody><tr><td>b");
assert.equal(adjacentTableSectionProcessor.next_tag("td"), true);
assert.deepEqual(adjacentTableSectionProcessor.get_breadcrumbs(), ["HTML", "BODY", "TABLE", "TBODY", "TR", "TD"]);
assert.equal(adjacentTableSectionProcessor.next_token(), true);
assert.equal(adjacentTableSectionProcessor.get_token_name(), "#text");
assert.equal(adjacentTableSectionProcessor.next_token(), true);
assert.equal(adjacentTableSectionProcessor.get_tag(), "TD");
assert.equal(adjacentTableSectionProcessor.is_virtual(), true);
assert.equal(adjacentTableSectionProcessor.is_tag_closer(), true);
assert.equal(adjacentTableSectionProcessor.next_token(), true);
assert.equal(adjacentTableSectionProcessor.get_tag(), "TR");
assert.equal(adjacentTableSectionProcessor.is_virtual(), true);
assert.equal(adjacentTableSectionProcessor.is_tag_closer(), true);
assert.equal(adjacentTableSectionProcessor.next_token(), true);
assert.equal(adjacentTableSectionProcessor.get_tag(), "TBODY");
assert.equal(adjacentTableSectionProcessor.is_virtual(), true);
assert.equal(adjacentTableSectionProcessor.is_tag_closer(), true);
assert.deepEqual(adjacentTableSectionProcessor.get_breadcrumbs(), ["HTML", "BODY", "TABLE"]);
assert.equal(adjacentTableSectionProcessor.next_token(), true);
assert.equal(adjacentTableSectionProcessor.get_tag(), "TBODY");
assert.equal(adjacentTableSectionProcessor.is_virtual(), false);
assert.equal(adjacentTableSectionProcessor.is_tag_closer(), false);
assert.deepEqual(adjacentTableSectionProcessor.get_breadcrumbs(), ["HTML", "BODY", "TABLE", "TBODY"]);
adjacentTableSectionProcessor.destroy();

const tableEndTagProcessor = WP_HTML_Processor.create_fragment("<table><tbody><tr><td>a</table><p>b");
assert.equal(tableEndTagProcessor.next_tag("td"), true);
assert.deepEqual(tableEndTagProcessor.get_breadcrumbs(), ["HTML", "BODY", "TABLE", "TBODY", "TR", "TD"]);
assert.equal(tableEndTagProcessor.next_token(), true);
assert.equal(tableEndTagProcessor.get_token_name(), "#text");
assert.equal(tableEndTagProcessor.next_token(), true);
assert.equal(tableEndTagProcessor.get_tag(), "TD");
assert.equal(tableEndTagProcessor.is_virtual(), true);
assert.equal(tableEndTagProcessor.is_tag_closer(), true);
assert.equal(tableEndTagProcessor.next_token(), true);
assert.equal(tableEndTagProcessor.get_tag(), "TR");
assert.equal(tableEndTagProcessor.is_virtual(), true);
assert.equal(tableEndTagProcessor.is_tag_closer(), true);
assert.equal(tableEndTagProcessor.next_token(), true);
assert.equal(tableEndTagProcessor.get_tag(), "TBODY");
assert.equal(tableEndTagProcessor.is_virtual(), true);
assert.equal(tableEndTagProcessor.is_tag_closer(), true);
assert.equal(tableEndTagProcessor.next_token(), true);
assert.equal(tableEndTagProcessor.get_tag(), "TABLE");
assert.equal(tableEndTagProcessor.is_virtual(), false);
assert.equal(tableEndTagProcessor.is_tag_closer(), true);
assert.deepEqual(tableEndTagProcessor.get_breadcrumbs(), ["HTML", "BODY"]);
assert.equal(tableEndTagProcessor.next_tag("p"), true);
assert.deepEqual(tableEndTagProcessor.get_breadcrumbs(), ["HTML", "BODY", "P"]);
tableEndTagProcessor.destroy();

const nestedTableStartProcessor = WP_HTML_Processor.create_fragment("<table><tbody><table><tr><td>cell");
assert.equal(nestedTableStartProcessor.next_token(), true);
assert.equal(nestedTableStartProcessor.get_tag(), "TABLE");
assert.equal(nestedTableStartProcessor.is_tag_closer(), false);
assert.deepEqual(nestedTableStartProcessor.get_breadcrumbs(), ["HTML", "BODY", "TABLE"]);
assert.equal(nestedTableStartProcessor.next_token(), true);
assert.equal(nestedTableStartProcessor.get_tag(), "TBODY");
assert.equal(nestedTableStartProcessor.is_tag_closer(), false);
assert.deepEqual(nestedTableStartProcessor.get_breadcrumbs(), ["HTML", "BODY", "TABLE", "TBODY"]);
assert.equal(nestedTableStartProcessor.next_token(), true);
assert.equal(nestedTableStartProcessor.get_tag(), "TBODY");
assert.equal(nestedTableStartProcessor.is_virtual(), true);
assert.equal(nestedTableStartProcessor.is_tag_closer(), true);
assert.deepEqual(nestedTableStartProcessor.get_breadcrumbs(), ["HTML", "BODY", "TABLE"]);
assert.equal(nestedTableStartProcessor.next_token(), true);
assert.equal(nestedTableStartProcessor.get_tag(), "TABLE");
assert.equal(nestedTableStartProcessor.is_virtual(), true);
assert.equal(nestedTableStartProcessor.is_tag_closer(), true);
assert.deepEqual(nestedTableStartProcessor.get_breadcrumbs(), ["HTML", "BODY"]);
assert.equal(nestedTableStartProcessor.next_token(), true);
assert.equal(nestedTableStartProcessor.get_tag(), "TABLE");
assert.equal(nestedTableStartProcessor.is_virtual(), false);
assert.equal(nestedTableStartProcessor.is_tag_closer(), false);
assert.deepEqual(nestedTableStartProcessor.get_breadcrumbs(), ["HTML", "BODY", "TABLE"]);
nestedTableStartProcessor.destroy();
assert.equal(
	WP_HTML_Processor.normalize("<table><tbody><table><tr><td>cell"),
	"<table><tbody></tbody></table><table><tbody><tr><td>cell</td></tr></tbody></table>",
);
assert.equal(
	WP_HTML_Processor.normalize("<table><tr><table><tr><td>cell"),
	"<table><tbody><tr></tr></tbody></table><table><tbody><tr><td>cell</td></tr></tbody></table>",
);
assert.equal(
	WP_HTML_Processor.normalize("<table><td><table><td>cell"),
	"<table><tbody><tr><td><table><tbody><tr><td>cell</td></tr></tbody></table></td></tr></tbody></table>",
);

const unexpectedCloserProcessor = WP_HTML_Processor.create_fragment("<div>Test</button></div>");
assert.equal(unexpectedCloserProcessor.next_token(), true);
assert.equal(unexpectedCloserProcessor.get_tag(), "DIV");
assert.equal(unexpectedCloserProcessor.is_tag_closer(), false);
assert.equal(unexpectedCloserProcessor.next_token(), true);
assert.equal(unexpectedCloserProcessor.get_token_type(), "#text");
assert.equal(unexpectedCloserProcessor.next_token(), true);
assert.equal(unexpectedCloserProcessor.get_tag(), "DIV");
assert.equal(unexpectedCloserProcessor.is_tag_closer(), true);
unexpectedCloserProcessor.destroy();

const eofCloserProcessor = WP_HTML_Processor.create_fragment("<div><p><span>");
assert.equal(eofCloserProcessor.next_tag("span"), true);
assert.deepEqual(eofCloserProcessor.get_breadcrumbs(), ["HTML", "BODY", "DIV", "P", "SPAN"]);
assert.equal(eofCloserProcessor.next_token(), true);
assert.equal(eofCloserProcessor.get_tag(), "SPAN");
assert.equal(eofCloserProcessor.is_virtual(), true);
assert.equal(eofCloserProcessor.is_tag_closer(), true);
assert.deepEqual(eofCloserProcessor.get_breadcrumbs(), ["HTML", "BODY", "DIV", "P"]);
assert.equal(eofCloserProcessor.next_token(), true);
assert.equal(eofCloserProcessor.get_tag(), "P");
assert.equal(eofCloserProcessor.is_virtual(), true);
assert.equal(eofCloserProcessor.is_tag_closer(), true);
assert.deepEqual(eofCloserProcessor.get_breadcrumbs(), ["HTML", "BODY", "DIV"]);
assert.equal(eofCloserProcessor.next_token(), true);
assert.equal(eofCloserProcessor.get_tag(), "DIV");
assert.equal(eofCloserProcessor.is_virtual(), true);
assert.equal(eofCloserProcessor.is_tag_closer(), true);
assert.deepEqual(eofCloserProcessor.get_breadcrumbs(), ["HTML", "BODY"]);
assert.equal(eofCloserProcessor.next_token(), false);
assert.equal(eofCloserProcessor.get_tag(), null);
eofCloserProcessor.destroy();

const specialEndTagProcessor = WP_HTML_Processor.create_fragment("<div><span><p></span><div target>");
assert.equal(specialEndTagProcessor.next_tag("p"), true);
assert.deepEqual(specialEndTagProcessor.get_breadcrumbs(), ["HTML", "BODY", "DIV", "SPAN", "P"]);
assert.equal(specialEndTagProcessor.next_tag("div"), true);
assert.deepEqual(specialEndTagProcessor.get_breadcrumbs(), ["HTML", "BODY", "DIV", "SPAN", "DIV"]);
assert.equal(specialEndTagProcessor.get_attribute("target"), true);
specialEndTagProcessor.destroy();

const nonSpecialEndTagProcessor = WP_HTML_Processor.create_fragment("<div><span><code></span><div target>");
assert.equal(nonSpecialEndTagProcessor.next_tag("code"), true);
assert.deepEqual(nonSpecialEndTagProcessor.get_breadcrumbs(), ["HTML", "BODY", "DIV", "SPAN", "CODE"]);
assert.equal(nonSpecialEndTagProcessor.next_tag({ tag_name: "span", tag_closers: "visit" }), true);
assert.equal(nonSpecialEndTagProcessor.is_tag_closer(), true);
assert.deepEqual(nonSpecialEndTagProcessor.get_breadcrumbs(), ["HTML", "BODY", "DIV"]);
assert.equal(nonSpecialEndTagProcessor.next_tag("div"), true);
assert.deepEqual(nonSpecialEndTagProcessor.get_breadcrumbs(), ["HTML", "BODY", "DIV", "DIV"]);
assert.equal(nonSpecialEndTagProcessor.get_attribute("target"), true);
nonSpecialEndTagProcessor.destroy();

const modeledScopedEndTagProcessor = WP_HTML_Processor.create_fragment("<div><p></div><span target>");
assert.equal(modeledScopedEndTagProcessor.next_tag("p"), true);
assert.deepEqual(modeledScopedEndTagProcessor.get_breadcrumbs(), ["HTML", "BODY", "DIV", "P"]);
assert.equal(modeledScopedEndTagProcessor.next_tag({ tag_name: "div", tag_closers: "visit" }), true);
assert.equal(modeledScopedEndTagProcessor.is_tag_closer(), true);
assert.deepEqual(modeledScopedEndTagProcessor.get_breadcrumbs(), ["HTML", "BODY"]);
assert.equal(modeledScopedEndTagProcessor.next_tag("span"), true);
assert.deepEqual(modeledScopedEndTagProcessor.get_breadcrumbs(), ["HTML", "BODY", "SPAN"]);
assert.equal(modeledScopedEndTagProcessor.get_attribute("target"), true);
modeledScopedEndTagProcessor.destroy();

const svgProcessor = WP_HTML_Processor.create_fragment("<svg><image /><rect></rect></svg><p>");
assert.equal(svgProcessor.next_tag("image"), true);
assert.equal(svgProcessor.get_namespace(), "svg");
assert.equal(svgProcessor.expects_closer(), false);
assert.deepEqual(svgProcessor.get_breadcrumbs(), ["HTML", "BODY", "SVG", "IMAGE"]);
assert.equal(svgProcessor.next_tag("rect"), true);
assert.equal(svgProcessor.get_namespace(), "svg");
assert.equal(svgProcessor.expects_closer(), true);
assert.deepEqual(svgProcessor.get_breadcrumbs(), ["HTML", "BODY", "SVG", "RECT"]);
assert.equal(svgProcessor.next_tag("p"), true);
assert.equal(svgProcessor.get_namespace(), "html");
assert.deepEqual(svgProcessor.get_breadcrumbs(), ["HTML", "BODY", "P"]);
svgProcessor.destroy();

const foreignHtmlBreakoutProcessor = WP_HTML_Processor.create_fragment("<svg><img>text");
assert.equal(foreignHtmlBreakoutProcessor.next_token(), true);
assert.equal(foreignHtmlBreakoutProcessor.get_tag(), "SVG");
assert.equal(foreignHtmlBreakoutProcessor.get_namespace(), "svg");
assert.equal(foreignHtmlBreakoutProcessor.next_token(), true);
assert.equal(foreignHtmlBreakoutProcessor.get_tag(), "SVG");
assert.equal(foreignHtmlBreakoutProcessor.is_virtual(), true);
assert.equal(foreignHtmlBreakoutProcessor.is_tag_closer(), true);
assert.deepEqual(foreignHtmlBreakoutProcessor.get_breadcrumbs(), ["HTML", "BODY"]);
assert.equal(foreignHtmlBreakoutProcessor.next_token(), true);
assert.equal(foreignHtmlBreakoutProcessor.get_tag(), "IMG");
assert.equal(foreignHtmlBreakoutProcessor.get_namespace(), "html");
assert.equal(foreignHtmlBreakoutProcessor.expects_closer(), false);
assert.deepEqual(foreignHtmlBreakoutProcessor.get_breadcrumbs(), ["HTML", "BODY", "IMG"]);
foreignHtmlBreakoutProcessor.destroy();
assert.equal(WP_HTML_Processor.normalize("<svg><img>text"), "<svg></svg><img>text");
assert.equal(WP_HTML_Processor.normalize("<svg><span>text"), "<svg></svg><span>text</span>");
assert.equal(
	WP_HTML_Processor.normalize("<svg><foreignObject><svg><img>text"),
	"<svg><foreignObject><svg></svg><img>text</foreignObject></svg>",
);
assert.equal(WP_HTML_Processor.normalize("<svg><font color=red>text"), '<svg></svg><font color="red">text</font>');
assert.equal(WP_HTML_Processor.normalize("<svg><font>text"), "<svg><font>text</font></svg>");

const foreignEndTagBreakoutProcessor = WP_HTML_Processor.create_fragment("<svg></p><span>x");
assert.equal(foreignEndTagBreakoutProcessor.next_token(), true);
assert.equal(foreignEndTagBreakoutProcessor.get_tag(), "SVG");
assert.equal(foreignEndTagBreakoutProcessor.get_namespace(), "svg");
assert.equal(foreignEndTagBreakoutProcessor.next_token(), true);
assert.equal(foreignEndTagBreakoutProcessor.get_tag(), "SVG");
assert.equal(foreignEndTagBreakoutProcessor.is_virtual(), true);
assert.equal(foreignEndTagBreakoutProcessor.is_tag_closer(), true);
assert.deepEqual(foreignEndTagBreakoutProcessor.get_breadcrumbs(), ["HTML", "BODY"]);
assert.equal(foreignEndTagBreakoutProcessor.next_token(), true);
assert.equal(foreignEndTagBreakoutProcessor.get_tag(), "P");
assert.equal(foreignEndTagBreakoutProcessor.is_virtual(), true);
assert.equal(foreignEndTagBreakoutProcessor.is_tag_closer(), false);
assert.deepEqual(foreignEndTagBreakoutProcessor.get_breadcrumbs(), ["HTML", "BODY", "P"]);
assert.equal(foreignEndTagBreakoutProcessor.next_token(), true);
assert.equal(foreignEndTagBreakoutProcessor.get_tag(), "P");
assert.equal(foreignEndTagBreakoutProcessor.is_virtual(), true);
assert.equal(foreignEndTagBreakoutProcessor.is_tag_closer(), true);
assert.deepEqual(foreignEndTagBreakoutProcessor.get_breadcrumbs(), ["HTML", "BODY"]);
foreignEndTagBreakoutProcessor.destroy();
assert.equal(WP_HTML_Processor.normalize("<svg></p><span>x"), "<svg></svg><p></p><span>x</span>");
assert.equal(WP_HTML_Processor.normalize("<svg><g></p><span>x"), "<svg><g></g></svg><p></p><span>x</span>");

const qualifiedSvgProcessor = WP_HTML_Processor.create_fragment('<svg /><svg><lineargradient gradientunits="userSpaceOnUse"></lineargradient></svg>');
assert.equal(qualifiedSvgProcessor.next_tag("svg"), true);
assert.equal(qualifiedSvgProcessor.get_namespace(), "svg");
assert.equal(qualifiedSvgProcessor.get_qualified_tag_name(), "svg");
assert.equal(qualifiedSvgProcessor.serialize_token(), "<svg />");
assert.equal(qualifiedSvgProcessor.next_tag("lineargradient"), true);
assert.equal(qualifiedSvgProcessor.get_namespace(), "svg");
assert.equal(qualifiedSvgProcessor.get_qualified_tag_name(), "linearGradient");
assert.equal(qualifiedSvgProcessor.get_qualified_attribute_name("gradientunits"), "gradientUnits");
qualifiedSvgProcessor.destroy();

const foreignObjectProcessor = WP_HTML_Processor.create_fragment("<svg><foreignObject><div></div></foreignObject></svg>");
assert.equal(foreignObjectProcessor.next_tag("div"), true);
assert.equal(foreignObjectProcessor.get_namespace(), "html");
assert.deepEqual(foreignObjectProcessor.get_breadcrumbs(), ["HTML", "BODY", "SVG", "FOREIGNOBJECT", "DIV"]);
foreignObjectProcessor.destroy();

const mathProcessor = WP_HTML_Processor.create_fragment("<mo><image /></mo><math><image /><mo><image /></mo></math>");
assert.equal(mathProcessor.next_token(), true);
assert.equal(mathProcessor.get_tag(), "MO");
assert.equal(mathProcessor.get_namespace(), "html");
assert.equal(mathProcessor.next_token(), true);
assert.equal(mathProcessor.get_tag(), "IMG");
assert.equal(mathProcessor.get_namespace(), "html");
assert.equal(mathProcessor.next_token(), true);
assert.equal(mathProcessor.is_tag_closer(), true);
assert.equal(mathProcessor.next_token(), true);
assert.equal(mathProcessor.get_tag(), "MATH");
assert.equal(mathProcessor.get_namespace(), "math");
assert.equal(mathProcessor.next_token(), true);
assert.equal(mathProcessor.get_tag(), "IMAGE");
assert.equal(mathProcessor.get_namespace(), "math");
assert.equal(mathProcessor.get_qualified_tag_name(), "image");
assert.equal(mathProcessor.next_token(), true);
assert.equal(mathProcessor.get_tag(), "MO");
assert.equal(mathProcessor.get_namespace(), "math");
assert.equal(mathProcessor.next_token(), true);
assert.equal(mathProcessor.get_tag(), "IMG");
assert.equal(mathProcessor.get_namespace(), "html");
mathProcessor.destroy();

const mathQualifiedProcessor = WP_HTML_Processor.create_fragment('<math><mi definitionurl="x"></mi></math>');
assert.equal(mathQualifiedProcessor.next_tag("mi"), true);
assert.equal(mathQualifiedProcessor.get_namespace(), "math");
assert.equal(mathQualifiedProcessor.get_qualified_attribute_name("definitionurl"), "definitionURL");
mathQualifiedProcessor.destroy();

const mathIntegrationEndTagProcessor = WP_HTML_Processor.create_fragment("<math><mi>x</mi><mn>1</mn></math>");
assert.equal(mathIntegrationEndTagProcessor.next_tag("mn"), true);
assert.equal(mathIntegrationEndTagProcessor.get_namespace(), "math");
assert.deepEqual(mathIntegrationEndTagProcessor.get_breadcrumbs(), ["HTML", "BODY", "MATH", "MN"]);
mathIntegrationEndTagProcessor.destroy();
assert.equal(
	WP_HTML_Processor.normalize("<math><mi>x</mi><mn>1</mn></math>"),
	"<math><mi>x</mi><mn>1</mn></math>",
);
assert.equal(
	WP_HTML_Processor.normalize("<math><mo><image /></mo><mn>1</mn></math>"),
	"<math><mo><img></mo><mn>1</mn></math>",
);

const foreignModifiableTextProcessor = WP_HTML_Processor.create_fragment("<svg><title>One</title></svg>");
assert.equal(foreignModifiableTextProcessor.next_tag("title"), true);
assert.equal(foreignModifiableTextProcessor.get_namespace(), "svg");
assert.equal(foreignModifiableTextProcessor.get_modifiable_text(), "");
assert.equal(foreignModifiableTextProcessor.set_modifiable_text("Two"), false);
assert.equal(foreignModifiableTextProcessor.get_updated_html(), "<svg><title>One</title></svg>");
foreignModifiableTextProcessor.destroy();

const templateNamespaceProcessor = WP_HTML_Processor.create_fragment("<template><svg><template><foreignObject><div></template><div target>");
assert.equal(templateNamespaceProcessor.next_tag("div"), true);
assert.deepEqual(
	templateNamespaceProcessor.get_breadcrumbs(),
	["HTML", "BODY", "TEMPLATE", "SVG", "TEMPLATE", "FOREIGNOBJECT", "DIV"],
);
assert.equal(templateNamespaceProcessor.next_tag("div"), true);
assert.deepEqual(templateNamespaceProcessor.get_breadcrumbs(), ["HTML", "BODY", "DIV"]);
assert.equal(templateNamespaceProcessor.get_attribute("target"), true);
templateNamespaceProcessor.destroy();

assert.equal(
	WP_HTML_Processor.normalize('<a href=#anchor enabled>Tom & Jerry</a>'),
	'<a href="#anchor" enabled>Tom &amp; Jerry</a>',
);
assert.equal(WP_HTML_Processor.normalize("<div><p>One"), "<div><p>One</p></div>");
assert.equal(WP_HTML_Processor.normalize("<table><td>cell"), "<table><tbody><tr><td>cell</td></tr></tbody></table>");
assert.equal(WP_HTML_Processor.normalize("<table><tr><td>cell"), "<table><tbody><tr><td>cell</td></tr></tbody></table>");
assert.equal(WP_HTML_Processor.normalize("<table><td>a<td>b"), "<table><tbody><tr><td>a</td><td>b</td></tr></tbody></table>");
assert.equal(WP_HTML_Processor.normalize("<table><tr><td>a<tr><td>b"), "<table><tbody><tr><td>a</td></tr><tr><td>b</td></tr></tbody></table>");
assert.equal(WP_HTML_Processor.normalize("<table><tbody><tr><td>a<tbody><tr><td>b"), "<table><tbody><tr><td>a</td></tr></tbody><tbody><tr><td>b</td></tr></tbody></table>");
assert.equal(WP_HTML_Processor.normalize("<table><tbody><tr><td>a</table><p>b"), "<table><tbody><tr><td>a</td></tr></tbody></table><p>b</p>");
assert.equal(WP_HTML_Processor.normalize("<div></p>fun<table><td>cell</div>"), "<div><p></p>fun<table><tbody><tr><td>cell</td></tr></tbody></table></div>");
assert.equal(WP_HTML_Processor.normalize("<img id='5\0'>"), '<img id="5\uFFFD">');
assert.equal(WP_HTML_Processor.normalize("<div><span></div>"), "<div><span></span></div>");
assert.equal(WP_HTML_Processor.normalize("<svg><g><g /></svg>"), "<svg><g><g /></g></svg>");

const serializationProcessor = WP_HTML_Processor.create_fragment("<textarea>One & Two</textarea>");
assert.equal(serializationProcessor.next_token(), true);
assert.equal(serializationProcessor.serialize(), null);
assert.equal(serializationProcessor.serialize_token(), "<textarea>\nOne &amp; Two</textarea>");
serializationProcessor.destroy();

console.log("WASM smoke tests passed.");
