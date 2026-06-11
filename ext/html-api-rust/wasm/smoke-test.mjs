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

const text = new WP_HTML_Tag_Processor(" \0<p>Hi</p>");
assert.equal(text.next_token(), true);
assert.equal(text.get_token_type(), "#text");
assert.equal(text.subdivide_text_appropriately(), true);
assert.equal(text.text_node_classification, WP_HTML_Tag_Processor.TEXT_IS_WHITESPACE);
text.destroy();

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

const processor = WP_HTML_Processor.create_fragment("<img><p>Hi");
assert.equal(processor.next_tag("p"), true);
assert.equal(processor.expects_closer(), true);
assert.deepEqual(processor.get_breadcrumbs(), ["HTML", "BODY", "P"]);
processor.destroy();

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
assert.equal(paragraphProcessor.next_tag({ tag_name: "p", match_offset: 2 }), true);
assert.deepEqual(paragraphProcessor.get_breadcrumbs(), ["HTML", "BODY", "P"]);
assert.equal(paragraphProcessor.get_attribute("target"), true);
paragraphProcessor.destroy();

console.log("WASM smoke tests passed.");
