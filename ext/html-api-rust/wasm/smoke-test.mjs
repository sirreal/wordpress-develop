import assert from "node:assert/strict";
import { loadWasm } from "./wp-html-api-rust.js";

const {
	WP_HTML_Doctype_Info,
	WP_HTML_Tag_Processor,
	WP_HTML_Processor,
	scanNextTag,
	version,
} = await loadWasm(new URL("./dist/wp_html_api_rust_core.wasm", import.meta.url));

assert.equal(version(), "0.1.0");

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
		tag_name: "P",
		is_closing: false,
		has_self_closing_flag: false,
		token_end: 17,
		token_type: 1,
	},
);

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

for (const html of [
	'<!DOCTYPE html><meta charset="utf8">',
	'<!DOCTYPE html><meta http-equiv="content-type" content="">',
]) {
	const unsupportedMetaProcessor = WP_HTML_Processor.create_full_parser(html);
	assert.equal(unsupportedMetaProcessor.next_tag("meta"), false);
	assert.equal(unsupportedMetaProcessor.get_last_error(), WP_HTML_Processor.ERROR_UNSUPPORTED);
	assert.notEqual(unsupportedMetaProcessor.get_unsupported_exception(), null);
	unsupportedMetaProcessor.destroy();
}

const fragmentMetaProcessor = WP_HTML_Processor.create_fragment('<meta charset="utf8">');
assert.equal(fragmentMetaProcessor.next_tag("meta"), true);
assert.equal(fragmentMetaProcessor.get_last_error(), null);
fragmentMetaProcessor.destroy();

const processorBookmarkLimit = WP_HTML_Processor.create_fragment("<div>");
assert.equal(processorBookmarkLimit.next_tag("div"), true);
for (let i = 0; i <= WP_HTML_Tag_Processor.MAX_BOOKMARKS; i += 1) {
	assert.equal(processorBookmarkLimit.set_bookmark(`processor-${i}`), true);
}
processorBookmarkLimit.destroy();

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
const unsupportedActiveFormattingProcessor = WP_HTML_Processor.create_fragment("<p><em>One<p><em>Two");
assert.equal(unsupportedActiveFormattingProcessor.next_tag("em"), true);
assert.equal(unsupportedActiveFormattingProcessor.next_tag("em"), false);
assert.equal(unsupportedActiveFormattingProcessor.get_last_error(), WP_HTML_Processor.ERROR_UNSUPPORTED);
assert.notEqual(unsupportedActiveFormattingProcessor.get_unsupported_exception(), null);
unsupportedActiveFormattingProcessor.destroy();

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
