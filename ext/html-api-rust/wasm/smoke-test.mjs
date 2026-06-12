import assert from "node:assert/strict";
import { readFile } from "node:fs/promises";
import * as HtmlApiModule from "./wp-html-api-rust.js";
import {
	createHtmlApi,
	loadWasm,
	WP_HTML_Active_Formatting_Elements as Exported_WP_HTML_Active_Formatting_Elements,
	WP_HTML_Attribute_Token as Exported_WP_HTML_Attribute_Token,
	WP_HTML_Doctype_Info as Exported_WP_HTML_Doctype_Info,
	WP_HTML_Open_Elements as Exported_WP_HTML_Open_Elements,
	WP_HTML_Processor_State as Exported_WP_HTML_Processor_State,
	WP_HTML_Span as Exported_WP_HTML_Span,
	WP_HTML_Stack_Event as Exported_WP_HTML_Stack_Event,
	WP_HTML_Text_Replacement as Exported_WP_HTML_Text_Replacement,
	WP_HTML_Token as Exported_WP_HTML_Token,
	WP_HTML_Unsupported_Exception as Exported_WP_HTML_Unsupported_Exception,
} from "./wp-html-api-rust.js";

const directModuleExports = [
	"WP_HTML_Active_Formatting_Elements",
	"WP_HTML_Attribute_Token",
	"WP_HTML_Doctype_Info",
	"WP_HTML_Open_Elements",
	"WP_HTML_Processor_State",
	"WP_HTML_Span",
	"WP_HTML_Stack_Event",
	"WP_HTML_Text_Replacement",
	"WP_HTML_Token",
	"WP_HTML_Unsupported_Exception",
	"createHtmlApi",
	"loadWasm",
];

const loadedApiExports = [
	"WP_HTML_Active_Formatting_Elements",
	"WP_HTML_Attribute_Token",
	"WP_HTML_Decoder",
	"WP_HTML_Doctype_Info",
	"WP_HTML_Open_Elements",
	"WP_HTML_Processor",
	"WP_HTML_Processor_State",
	"WP_HTML_Span",
	"WP_HTML_Stack_Event",
	"WP_HTML_Tag_Processor",
	"WP_HTML_Text_Replacement",
	"WP_HTML_Token",
	"WP_HTML_Unsupported_Exception",
	"scanNextTag",
	"version",
	"wasm",
];
const wasmExportNames = [
	"__data_end",
	"__heap_base",
	"memory",
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
const wasmReadonlyExportNames = ["__data_end", "__heap_base", "memory"];
const wasmFunctionExportNames = wasmExportNames.filter((name) => name.startsWith("wp_html_api_rust_"));

const typeDeclarations = await readFile(new URL("./wp-html-api-rust.d.ts", import.meta.url), "utf8");
const packageJson = JSON.parse(await readFile(new URL("./package.json", import.meta.url), "utf8"));
const cargoManifest = await readFile(new URL("../Cargo.toml", import.meta.url), "utf8");
const phpHtmlApiDirectory = new URL("../../../src/wp-includes/html-api/", import.meta.url);
const rustCoreSource = await readFile(new URL("../src/lib.rs", import.meta.url), "utf8");
function declaredInterfaceBody(interfaceName) {
	const pattern = new RegExp(`^export interface ${interfaceName}(?: extends [^{]+)? \\{\\n([\\s\\S]*?)^\\}`, "m");
	const match = typeDeclarations.match(pattern);
	assert.ok(match, `Missing ${interfaceName} interface declaration.`);
	return match[1];
}

function declaredInterfaceMethodNames(interfaceName) {
	return [
		...declaredInterfaceBody(interfaceName).matchAll(/^\s*([A-Za-z_$][\w$]*)\(/gm),
	].map((match) => match[1]).sort();
}

function declaredConstructorMethodNames(interfaceName) {
	return [
		...declaredInterfaceBody(interfaceName).matchAll(/^\s*([A-Za-z_$][\w$]*)\(/gm),
	].map((match) => match[1]).filter((name) => name !== "new").sort();
}

function declaredReadonlyMemberNames(interfaceName) {
	return [
		...declaredInterfaceBody(interfaceName).matchAll(/^\s*readonly\s+([A-Za-z_$][\w$]*)\s*:/gm),
	].map((match) => match[1]).sort();
}

function declaredInterfacePropertyNames(interfaceName) {
	return [
		...declaredInterfaceBody(interfaceName).matchAll(/^\s*([A-Za-z_$][\w$]*)\??\s*:/gm),
	].map((match) => match[1]).sort();
}

function normalizePhpMethodName(methodName) {
	return methodName === "__toString" ? "toString" : methodName;
}

async function phpPublicMethodNames(fileName, { staticOnly = false, instanceOnly = false } = {}) {
	const source = await readFile(new URL(fileName, phpHtmlApiDirectory), "utf8");
	return [
		...source.matchAll(/\bpublic\s+(static\s+)?function\s+([A-Za-z_]\w*)\s*\(/g),
	]
		.filter((match) => !staticOnly || match[1] !== undefined)
		.filter((match) => !instanceOnly || match[1] === undefined)
		.map((match) => normalizePhpMethodName(match[2]))
		.filter((methodName) => !["__construct", "__destruct", "__wakeup"].includes(methodName))
		.sort();
}

async function phpClassConstantNames(fileName) {
	const source = await readFile(new URL(fileName, phpHtmlApiDirectory), "utf8");
	return [...source.matchAll(/^\s*const\s+([A-Z0-9_]+)\s*=/gm)]
		.map((match) => match[1])
		.sort();
}

async function phpPublicPropertyNames(fileName) {
	const source = await readFile(new URL(fileName, phpHtmlApiDirectory), "utf8");
	return [...source.matchAll(/\bpublic\s+\$([A-Za-z_]\w*)\b/g)]
		.map((match) => match[1])
		.sort();
}

function rustNoMangleExportNames(source) {
	return [...source.matchAll(/#\[no_mangle\]\s+pub\s+(?:unsafe\s+)?extern\s+"C"\s+fn\s+([A-Za-z_]\w*)\s*\(/g)]
		.map((match) => match[1])
		.sort();
}

function rustCoreVersion(source) {
	const match = source.match(/\bstatic\s+VERSION:\s*&\[u8\]\s*=\s*b"([^"\\]+)\\0";/);
	assert.ok(match, "Missing Rust core VERSION constant.");
	return match[1];
}

function cargoPackageVersion(manifest) {
	const match = manifest.match(/^\s*version\s*=\s*"([^"]+)"/m);
	assert.ok(match, "Missing Cargo package version.");
	return match[1];
}

function parsePhpClassConstantValue(value) {
	value = value.trim();
	if (value === "true") {
		return true;
	}
	if (value === "false") {
		return false;
	}
	if (value === "null") {
		return null;
	}
	if (/^\d[\d_]*$/.test(value)) {
		return Number(value.replaceAll("_", ""));
	}
	const stringMatch = value.match(/^'([^']*)'$/);
	assert.ok(stringMatch, `Unsupported PHP class constant value: ${value}`);
	return stringMatch[1];
}

async function phpClassConstantValues(fileName) {
	const source = await readFile(new URL(fileName, phpHtmlApiDirectory), "utf8");
	return Object.fromEntries(
		[...source.matchAll(/^\s*const\s+([A-Z0-9_]+)\s*=\s*([^;]+);/gm)]
			.map((match) => [match[1], parsePhpClassConstantValue(match[2])])
			.sort(([leftName], [rightName]) => leftName.localeCompare(rightName)),
	);
}

function staticMemberValues(classValue, memberNames) {
	return Object.fromEntries(
		memberNames
			.map((memberName) => [memberName, classValue[memberName]])
			.sort(([leftName], [rightName]) => leftName.localeCompare(rightName)),
	);
}

function runtimePrototypeMethodNames(classValue, includeInherited = false) {
	const methods = new Set();
	let prototype = classValue.prototype;
	do {
		for (const name of Object.getOwnPropertyNames(prototype)) {
			if (name !== "constructor" && typeof classValue.prototype[name] === "function") {
				methods.add(name);
			}
		}
		prototype = includeInherited ? Object.getPrototypeOf(prototype) : null;
	} while (prototype && prototype !== Object.prototype);

	return [...methods].sort();
}

function runtimeStaticMemberNames(classValue, includeInherited = false) {
	const members = new Set();
	let value = classValue;
	do {
		for (const name of Object.getOwnPropertyNames(value)) {
			if (!["length", "name", "prototype"].includes(name)) {
				members.add(name);
			}
		}
		value = includeInherited ? Object.getPrototypeOf(value) : null;
	} while (value && value !== Function.prototype);

	return [...members].sort();
}

const declaredModuleValueExports = [
	...typeDeclarations.matchAll(/^export const\s+([A-Za-z_$][\w$]*)\s*:/gm),
	...typeDeclarations.matchAll(/^export function\s+([A-Za-z_$][\w$]*)\s*\(/gm),
].map((match) => match[1]).sort();
const declaredLoadedApiExports = [
	...declaredInterfaceBody("HtmlApi").matchAll(/^\s*([A-Za-z_$][\w$]*)[(:]/gm),
].map((match) => match[1]).sort();
const publicPropertyInterfaces = [
	["class-wp-html-doctype-info.php", "WP_HTML_Doctype_Info"],
	["class-wp-html-unsupported-exception.php", "WP_HTML_Unsupported_Exception"],
	["class-wp-html-span.php", "WP_HTML_Span"],
	["class-wp-html-text-replacement.php", "WP_HTML_Text_Replacement"],
	["class-wp-html-attribute-token.php", "WP_HTML_Attribute_Token"],
	["class-wp-html-token.php", "WP_HTML_Token"],
	["class-wp-html-stack-event.php", "WP_HTML_Stack_Event"],
	["class-wp-html-active-formatting-elements.php", "WP_HTML_Active_Formatting_Elements"],
	["class-wp-html-open-elements.php", "WP_HTML_Open_Elements"],
	["class-wp-html-processor-state.php", "WP_HTML_Processor_State"],
];
const jsOnlyInterfaceProperties = {
	WP_HTML_Unsupported_Exception: new Set(["message"]),
};
const jsOnlyRuntimeProperties = {
	WP_HTML_Unsupported_Exception: new Set(["name"]),
};

assert.deepEqual(declaredModuleValueExports, directModuleExports);
assert.deepEqual(declaredLoadedApiExports, loadedApiExports);
assert.deepEqual(declaredReadonlyMemberNames("WpHtmlApiRustWasmExports"), wasmReadonlyExportNames);
assert.deepEqual(declaredInterfaceMethodNames("WpHtmlApiRustWasmExports"), wasmFunctionExportNames);
assert.deepEqual(rustNoMangleExportNames(rustCoreSource), wasmFunctionExportNames);
assert.match(typeDeclarations, /^\s*wasm: WpHtmlApiRustWasmExports;$/m);
assert.deepEqual(Object.keys(HtmlApiModule).sort(), directModuleExports);

const {
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
	WP_HTML_Doctype_Info,
	WP_HTML_Tag_Processor,
	WP_HTML_Processor,
	scanNextTag,
	version,
	wasm,
} = await loadWasm(new URL("./dist/wp_html_api_rust_core.wasm", import.meta.url)).then((api) => {
	assert.deepEqual(Object.keys(api).sort(), loadedApiExports);
	return api;
});

assert.equal(packageJson.version, cargoPackageVersion(cargoManifest));
assert.equal(rustCoreVersion(rustCoreSource), packageJson.version);
assert.equal(version(), packageJson.version);
assert.deepEqual(Object.keys(wasm).sort(), wasmExportNames);
assert.equal(typeof wasm.wp_html_api_rust_core_version, "function");
assert.equal(Exported_WP_HTML_Doctype_Info, WP_HTML_Doctype_Info);
assert.equal(Exported_WP_HTML_Span, WP_HTML_Span);
assert.equal(Exported_WP_HTML_Text_Replacement, WP_HTML_Text_Replacement);
assert.equal(Exported_WP_HTML_Attribute_Token, WP_HTML_Attribute_Token);
assert.equal(Exported_WP_HTML_Token, WP_HTML_Token);
assert.equal(Exported_WP_HTML_Stack_Event, WP_HTML_Stack_Event);
assert.equal(Exported_WP_HTML_Active_Formatting_Elements, WP_HTML_Active_Formatting_Elements);
assert.equal(Exported_WP_HTML_Open_Elements, WP_HTML_Open_Elements);
assert.equal(Exported_WP_HTML_Processor_State, WP_HTML_Processor_State);
assert.equal(Exported_WP_HTML_Unsupported_Exception, WP_HTML_Unsupported_Exception);
assert.equal(typeof WP_HTML_Decoder.decode_text_node, "function");
assert.equal(typeof WP_HTML_Unsupported_Exception, "function");
assert.equal(typeof WP_HTML_Span, "function");
assert.equal(typeof WP_HTML_Text_Replacement, "function");
assert.equal(typeof WP_HTML_Attribute_Token, "function");
assert.equal(typeof WP_HTML_Token, "function");
assert.equal(typeof WP_HTML_Stack_Event, "function");
assert.equal(typeof WP_HTML_Active_Formatting_Elements, "function");
assert.equal(typeof WP_HTML_Open_Elements, "function");
assert.equal(typeof WP_HTML_Processor_State, "function");

const coercedUnsupportedException = new WP_HTML_Unsupported_Exception(
	123,
	true,
	"5.9",
	false,
	["HTML"],
	["B"],
);
assert.equal(coercedUnsupportedException.message, "123");
assert.equal(coercedUnsupportedException.token_name, "1");
assert.equal(coercedUnsupportedException.token_at, 5);
assert.equal(coercedUnsupportedException.token, "");
assert.deepEqual(coercedUnsupportedException.stack_of_open_elements, ["HTML"]);
assert.deepEqual(coercedUnsupportedException.active_formatting_elements, ["B"]);
assert.throws(
	() => new WP_HTML_Unsupported_Exception(null, "DIV", 5, "<div>", [], []),
	TypeError,
);
assert.throws(
	() => new WP_HTML_Unsupported_Exception("Unsupported", null, 5, "<div>", [], []),
	TypeError,
);
assert.throws(
	() => new WP_HTML_Unsupported_Exception("Unsupported", "DIV", "5px", "<div>", [], []),
	TypeError,
);
assert.throws(
	() => new WP_HTML_Unsupported_Exception("Unsupported", "DIV", Infinity, "<div>", [], []),
	TypeError,
);
assert.throws(
	() => new WP_HTML_Unsupported_Exception("Unsupported", "DIV", null, "<div>", [], []),
	TypeError,
);
assert.throws(
	() => new WP_HTML_Unsupported_Exception("Unsupported", "DIV", 5, null, [], []),
	TypeError,
);
assert.throws(
	() => new WP_HTML_Unsupported_Exception("Unsupported", "DIV", 5, "<div>", "HTML", []),
	TypeError,
);
assert.throws(
	() => new WP_HTML_Unsupported_Exception("Unsupported", "DIV", 5, "<div>", [], "B"),
	TypeError,
);

assert.throws(
	() => new WP_HTML_Doctype_Info("html", null, null, false),
	TypeError,
);
assert.throws(
	() => new WP_HTML_Span(Infinity, 1),
	TypeError,
);
assert.throws(
	() => new WP_HTML_Span("9223372036854775808", 1),
	TypeError,
);
assert.throws(
	() => new WP_HTML_Text_Replacement(0, NaN, "text"),
	TypeError,
);
assert.throws(
	() => new WP_HTML_Text_Replacement(0, "1e20", "text"),
	TypeError,
);

for (const [fileName, interfaceName, instance] of [
	["class-wp-html-doctype-info.php", "WP_HTML_Doctype_Info", WP_HTML_Doctype_Info.from_doctype_token("<!DOCTYPE html>")],
	[
		"class-wp-html-unsupported-exception.php",
		"WP_HTML_Unsupported_Exception",
		new WP_HTML_Unsupported_Exception("Unsupported", "DIV", 5, "<div>", ["HTML", "BODY"], ["B"]),
	],
	["class-wp-html-span.php", "WP_HTML_Span", new WP_HTML_Span(1, 2)],
	["class-wp-html-text-replacement.php", "WP_HTML_Text_Replacement", new WP_HTML_Text_Replacement(1, 2, "text")],
	["class-wp-html-attribute-token.php", "WP_HTML_Attribute_Token", new WP_HTML_Attribute_Token("id", 1, 2, 3, 4, false)],
	["class-wp-html-token.php", "WP_HTML_Token", new WP_HTML_Token("bookmark", "DIV", false)],
	["class-wp-html-stack-event.php", "WP_HTML_Stack_Event", new WP_HTML_Stack_Event(new WP_HTML_Token("bookmark", "DIV", false), WP_HTML_Stack_Event.PUSH, "real")],
	["class-wp-html-active-formatting-elements.php", "WP_HTML_Active_Formatting_Elements", new WP_HTML_Active_Formatting_Elements()],
	["class-wp-html-open-elements.php", "WP_HTML_Open_Elements", new WP_HTML_Open_Elements()],
	["class-wp-html-processor-state.php", "WP_HTML_Processor_State", new WP_HTML_Processor_State()],
]) {
	const excluded = jsOnlyRuntimeProperties[interfaceName] ?? new Set();
	assert.deepEqual(
		Object.keys(instance).filter((propertyName) => !excluded.has(propertyName)).sort(),
		await phpPublicPropertyNames(fileName),
	);
}

assert.equal(WP_HTML_Decoder.decode_text_node("&"), "&");
assert.equal(WP_HTML_Decoder.decode_text_node("&\0b"), "&\0b");
assert.equal(WP_HTML_Decoder.decode_text_node("&#x93;&#x1f604;&#x94;"), "“😄”");
assert.equal(WP_HTML_Decoder.decode_text_node("&notin"), "¬in");
assert.equal(WP_HTML_Decoder.decode_text_node(false), "");
assert.equal(WP_HTML_Decoder.decode_text_node(true), "1");
assert.equal(WP_HTML_Decoder.decode_text_node(NaN), "NAN");
assert.equal(WP_HTML_Decoder.decode_text_node(-0), "-0");
assert.equal(WP_HTML_Decoder.decode_text_node(100000000000000), "100000000000000");
assert.equal(WP_HTML_Decoder.decode_text_node(1.23456789012345), "1.2345678901235");
assert.throws(
	() => WP_HTML_Decoder.decode_text_node({ text: "&copy;" }),
	TypeError,
);
assert.equal(WP_HTML_Decoder.decode_attribute("&notin"), "&notin");
assert.equal(WP_HTML_Decoder.decode_attribute("&notin;"), "∉");
assert.equal(WP_HTML_Decoder.decode_attribute(true), "1");
assert.equal(WP_HTML_Decoder.decode_attribute(1e-5), "1.0E-5");
assert.equal(WP_HTML_Decoder.decode_attribute(Infinity), "INF");
assert.equal(WP_HTML_Decoder.decode("data", "&copy;"), "©");
assert.equal(WP_HTML_Decoder.decode("data", false), "");
assert.equal(WP_HTML_Decoder.decode("data", 1e20), "1.0E+20");
assert.equal(WP_HTML_Decoder.decode("data", -Infinity), "-INF");
assert.equal(WP_HTML_Decoder.decode(false, "&notin"), "¬in");
assert.equal(WP_HTML_Decoder.decode(null, "&notin"), "¬in");
assert.equal(WP_HTML_Decoder.decode({}, "&notin"), "¬in");
assert.equal(WP_HTML_Decoder.decode([], "&notin"), "¬in");
assert.equal(WP_HTML_Decoder.decode("attribute", "&notit;"), "&notit;");
assert.throws(
	() => WP_HTML_Decoder.decode("data", ["&copy;"]),
	TypeError,
);
assert.equal(
	WP_HTML_Decoder.decode_text_node(
		"&reg; &trade; &mdash; &rsquo; &euro; &CounterClockwiseContourIntegral; &NotNestedGreaterGreater;",
	),
	"® ™ — ’ € ∳ ⪢̸",
);
assert.equal(
	WP_HTML_Decoder.decode_attribute("&reg=1 &reg;=1 &plusmn=1 &plusmn;=1 &apos=1 &apos;=1"),
	"&reg=1 ®=1 &plusmn=1 ±=1 &apos=1 '=1",
);
assert.equal(WP_HTML_Decoder.code_point_to_utf8_bytes(0x1f170), "🅰");
assert.equal(WP_HTML_Decoder.code_point_to_utf8_bytes(0xd83c), "�");
assert.equal(WP_HTML_Decoder.code_point_to_utf8_bytes("9223372036854775807"), "�");
assert.equal(WP_HTML_Decoder.code_point_to_utf8_bytes(null), "\0");
assert.equal(WP_HTML_Decoder.code_point_to_utf8_bytes("65.9"), "A");
assert.throws(
	() => WP_HTML_Decoder.code_point_to_utf8_bytes(NaN),
	TypeError,
);
assert.throws(
	() => WP_HTML_Decoder.code_point_to_utf8_bytes(Infinity),
	TypeError,
);
assert.throws(
	() => WP_HTML_Decoder.code_point_to_utf8_bytes("1e9999"),
	TypeError,
);
assert.throws(
	() => WP_HTML_Decoder.code_point_to_utf8_bytes("9223372036854775808"),
	TypeError,
);
assert.throws(
	() => WP_HTML_Decoder.code_point_to_utf8_bytes("65abc"),
	TypeError,
);
assert.throws(
	() => WP_HTML_Decoder.code_point_to_utf8_bytes(""),
	TypeError,
);

const hellipReferenceLength = {};
assert.equal(
	WP_HTML_Decoder.read_character_reference("attribute", "Ships&hellip;", 5, hellipReferenceLength),
	"…",
);
assert.equal(hellipReferenceLength.value, 8);
assert.equal(WP_HTML_Decoder.read_character_reference("attribute", "Ships&hellip;", 0), null);
assert.equal(WP_HTML_Decoder.read_character_reference("attribute", "&notin"), null);
const notinReferenceLength = {};
assert.equal(WP_HTML_Decoder.read_character_reference("attribute", "&notin;", 0, notinReferenceLength), "∉");
assert.equal(notinReferenceLength.value, 7);
const legacyNotReferenceLength = {};
assert.equal(WP_HTML_Decoder.read_character_reference("data", "&notin", 0, legacyNotReferenceLength), "¬");
assert.equal(legacyNotReferenceLength.value, 4);
assert.equal(WP_HTML_Decoder.read_character_reference("data", "x&copy;", " 1"), "©");
const scalarDecoderContextLength = {};
assert.equal(WP_HTML_Decoder.read_character_reference(null, "&notin", 0, scalarDecoderContextLength), "¬");
assert.equal(scalarDecoderContextLength.value, 4);
assert.equal(WP_HTML_Decoder.read_character_reference({}, "&notin"), "¬");
assert.equal(WP_HTML_Decoder.read_character_reference([], "&notin"), "¬");
assert.equal(WP_HTML_Decoder.read_character_reference("data", null), null);
assert.equal(WP_HTML_Decoder.read_character_reference("data", "x&copy;", -1), null);
assert.throws(
	() => WP_HTML_Decoder.read_character_reference("data", "x&copy;", "1.0"),
	TypeError,
);
assert.throws(
	() => WP_HTML_Decoder.read_character_reference("data", "x&copy;", ""),
	TypeError,
);
assert.throws(
	() => WP_HTML_Decoder.read_character_reference("data", "x&copy;", {}),
	TypeError,
);

const span = new WP_HTML_Span("14", 28);
assert.equal(span.start, 14);
assert.equal(span.length, 28);
assert.throws(
	() => new WP_HTML_Span("14px", 28),
	TypeError,
);
assert.throws(
	() => new WP_HTML_Span(null, 28),
	TypeError,
);

const replacement = new WP_HTML_Text_Replacement(14, "28", "updated");
assert.equal(replacement.start, 14);
assert.equal(replacement.length, 28);
assert.equal(replacement.text, "updated");
assert.equal(new WP_HTML_Text_Replacement(1, 2, 123).text, "123");
assert.throws(
	() => new WP_HTML_Text_Replacement(14, "28px", "updated"),
	TypeError,
);
assert.throws(
	() => new WP_HTML_Text_Replacement(14, 28, null),
	TypeError,
);
assert.throws(
	() => new WP_HTML_Text_Replacement(14, null, "updated"),
	TypeError,
);

const attributeToken = new WP_HTML_Attribute_Token("class", 12, "6", 5, 13, false);
assert.equal(attributeToken.name, "class");
assert.equal(attributeToken.value_starts_at, 12);
assert.equal(attributeToken.value_length, "6");
assert.equal(attributeToken.start, 5);
assert.equal(attributeToken.length, 13);
assert.equal(attributeToken.is_true, false);
const oddAttributeToken = new WP_HTML_Attribute_Token(123, "12x", null, [], "13", "0");
assert.equal(oddAttributeToken.name, 123);
assert.equal(oddAttributeToken.value_starts_at, "12x");
assert.equal(oddAttributeToken.value_length, null);
assert.deepEqual(oddAttributeToken.start, []);
assert.equal(oddAttributeToken.length, "13");
assert.equal(oddAttributeToken.is_true, "0");

for (const attributeValue of [
	"javascript:",
	"JAVASCRIPT:",
	"&#106;avascript:",
	"&#x6A;avascript:",
	"&#X6A;avascript&colon;",
	"javascript&#58;alert(1);",
	"javascript&#0000058alert(1);",
	"javascript&#x3a;alert(1);",
	"&#x6A&#x61&#x76&#x61&#x73&#x63&#x72&#x69&#x70&#x74&#x3A&#x61&#x6C&#x65&#x72&#x74&#x28&#x27&#x58&#x53&#x53&#x27&#x29",
	"javascript&#58alert(1)",
	"javascript&#x3ax=1;alert(1)",
]) {
	assert.equal(
		WP_HTML_Decoder.attribute_starts_with(attributeValue, "javascript:", "ascii-case-insensitive"),
		true,
		attributeValue,
	);
}
assert.equal(WP_HTML_Decoder.attribute_starts_with("http://wordpress.org", "HTTP"), false);
assert.equal(WP_HTML_Decoder.attribute_starts_with("http://wordpress.org", "HTTP", "ascii-case-insensitive"), true);
assert.equal(WP_HTML_Decoder.attribute_starts_with("http://wordpress.org", "https", "ascii-case-insensitive"), false);
assert.equal(WP_HTML_Decoder.attribute_starts_with("http://wordpress.org", "HTTP", null), false);
assert.equal(WP_HTML_Decoder.attribute_starts_with("http://wordpress.org", "HTTP", {}), false);
assert.equal(WP_HTML_Decoder.attribute_starts_with("http://wordpress.org", "HTTP", []), false);
assert.equal(WP_HTML_Decoder.attribute_starts_with(true, 1), true);
assert.equal(WP_HTML_Decoder.attribute_starts_with(123, "1"), false);
assert.equal(WP_HTML_Decoder.attribute_starts_with("1", 1), false);
assert.equal(WP_HTML_Decoder.attribute_starts_with(123, 9), true);
assert.equal(WP_HTML_Decoder.attribute_starts_with("", 1), true);
assert.equal(WP_HTML_Decoder.attribute_starts_with(false, "anything"), true);
assert.equal(WP_HTML_Decoder.attribute_starts_with(1e20, "1.0E"), false);
assert.equal(WP_HTML_Decoder.attribute_starts_with(null, "anything"), true);
assert.equal(WP_HTML_Decoder.attribute_starts_with("anything", null), true);
assert.throws(
	() => WP_HTML_Decoder.attribute_starts_with({}, ""),
	TypeError,
);

let destroyedTokenBookmark = null;
const token = new WP_HTML_Token("mark", "img", false, (bookmarkName) => {
	destroyedTokenBookmark = bookmarkName;
});
assert.equal(token.bookmark_name, "mark");
assert.equal(token.namespace, "html");
assert.equal(token.node_name, "img");
assert.equal(token.has_self_closing_flag, false);
const stackEvent = new WP_HTML_Stack_Event(token, WP_HTML_Stack_Event.PUSH, "real");
assert.equal(WP_HTML_Stack_Event.POP, "pop");
assert.equal(WP_HTML_Stack_Event.PUSH, "push");
assert.equal(stackEvent.token, token);
assert.equal(stackEvent.operation, "push");
assert.equal(stackEvent.provenance, "real");
token.destroy();
assert.equal(destroyedTokenBookmark, "mark");
token.free();
const coercedToken = new WP_HTML_Token(123, 456, "0");
assert.equal(coercedToken.bookmark_name, "123");
assert.equal(coercedToken.node_name, "456");
assert.equal(coercedToken.has_self_closing_flag, false);
assert.equal(new WP_HTML_Token(NaN, Infinity, false).node_name, "INF");
assert.equal(new WP_HTML_Token("mark", "DIV", NaN).has_self_closing_flag, true);
assert.equal(new WP_HTML_Token(null, "DIV", "1").bookmark_name, null);
assert.throws(
	() => new WP_HTML_Token("mark", null, false),
	TypeError,
);
assert.throws(
	() => new WP_HTML_Token([], "DIV", false),
	TypeError,
);
assert.throws(
	() => new WP_HTML_Token("mark", "DIV", null),
	TypeError,
);
assert.throws(
	() => new WP_HTML_Token("mark", "DIV", false, "not-callable"),
	TypeError,
);

const coercedStackEvent = new WP_HTML_Stack_Event(token, 123, true);
assert.equal(coercedStackEvent.operation, "123");
assert.equal(coercedStackEvent.provenance, "1");
assert.throws(
	() => new WP_HTML_Stack_Event({}, WP_HTML_Stack_Event.PUSH, "real"),
	TypeError,
);
assert.throws(
	() => new WP_HTML_Stack_Event(token, null, "real"),
	TypeError,
);

const activeFormattingElements = new WP_HTML_Active_Formatting_Elements();
const activeEm = new WP_HTML_Token("em-bookmark", "EM", false);
const activeStrong = new WP_HTML_Token("strong-bookmark", "STRONG", false);
const activeAnchor = new WP_HTML_Token("anchor-bookmark", "A", false);
activeFormattingElements.push(activeEm);
activeFormattingElements.push(activeStrong);
activeFormattingElements.push(activeAnchor);
assert.equal(activeFormattingElements.count(), 3);
assert.equal(activeFormattingElements.current_node(), activeAnchor);
assert.deepEqual([...activeFormattingElements.walk_down()].map(({ node_name }) => node_name), ["EM", "STRONG", "A"]);
assert.deepEqual([...activeFormattingElements.walk_up()].map(({ node_name }) => node_name), ["A", "STRONG", "EM"]);
assert.equal(activeFormattingElements.contains_node(new WP_HTML_Token("strong-bookmark", "B", false)), true);
assert.equal(activeFormattingElements.remove_node(new WP_HTML_Token("strong-bookmark", "B", false)), true);
assert.deepEqual([...activeFormattingElements.walk_down()].map(({ node_name }) => node_name), ["EM", "A"]);
assert.throws(
	() => activeFormattingElements.contains_node({ bookmark_name: "em-bookmark" }),
	TypeError,
);
assert.throws(
	() => activeFormattingElements.push({ bookmark_name: "plain", node_name: "B" }),
	TypeError,
);
assert.throws(
	() => activeFormattingElements.remove_node({ bookmark_name: "em-bookmark" }),
	TypeError,
);
activeFormattingElements.insert_marker();
activeFormattingElements.push(new WP_HTML_Token("after-marker", "B", false));
assert.deepEqual([...activeFormattingElements.walk_down()].map(({ node_name }) => node_name), ["EM", "A", "marker", "B"]);
activeFormattingElements.clear_up_to_last_marker();
assert.deepEqual([...activeFormattingElements.walk_down()].map(({ node_name }) => node_name), ["EM", "A"]);

const openElements = new WP_HTML_Open_Elements();
const openEvents = [];
openElements.set_push_handler(({ node_name }) => openEvents.push(`push:${node_name}`));
openElements.set_pop_handler(({ node_name }) => openEvents.push(`pop:${node_name}`));
const openHtmlToken = new WP_HTML_Token("html", "HTML", false);
const openBodyToken = new WP_HTML_Token("body", "BODY", false);
const openPToken = new WP_HTML_Token("p", "P", false);
const openButtonToken = new WP_HTML_Token("button", "BUTTON", false);
openElements.push(openHtmlToken);
openElements.push(openBodyToken);
openElements.push(openPToken);
assert.equal(openElements.stack.length, 3);
assert.equal(openElements.count(), 3);
assert.equal(openElements.at(1), openHtmlToken);
assert.equal(openElements.current_node(), openPToken);
assert.equal(openElements.current_node_is("P"), true);
assert.equal(openElements.current_node_is("#tag"), true);
assert.equal(openElements.contains("BODY"), true);
assert.equal(openElements.contains_node(openPToken), true);
assert.equal(openElements.contains_node(new WP_HTML_Token("p", "P", false)), false);
assert.deepEqual([...openElements.walk_up(openBodyToken)].map(({ node_name }) => node_name), ["HTML"]);
assert.throws(
	() => openElements.contains_node({ bookmark_name: "p", node_name: "P" }),
	TypeError,
);
assert.throws(
	() => openElements.push({ bookmark_name: "plain", node_name: "B" }),
	TypeError,
);
assert.throws(
	() => openElements.remove_node({ bookmark_name: "p", node_name: "P" }),
	TypeError,
);
assert.throws(
	() => [...openElements.walk_up({ bookmark_name: "body", node_name: "BODY" })],
	TypeError,
);
assert.throws(
	() => openElements.after_element_push({ bookmark_name: "plain", node_name: "B" }),
	TypeError,
);
assert.throws(
	() => openElements.after_element_pop({ bookmark_name: "plain", node_name: "B" }),
	TypeError,
);
assert.equal(openElements.has_p_in_button_scope(), true);
assert.equal(openElements.has_element_in_scope("P"), true);
openElements.push(openButtonToken);
assert.equal(openElements.has_p_in_button_scope(), false);
assert.equal(openElements.has_element_in_button_scope("P"), false);
assert.equal(openElements.pop(), true);
assert.equal(openElements.has_p_in_button_scope(), true);
const openTableToken = new WP_HTML_Token("table", "TABLE", false);
openElements.push(openTableToken);
assert.equal(openElements.has_element_in_scope("P"), false);
assert.equal(openElements.has_element_in_table_scope("TABLE"), true);
assert.equal(openElements.pop_until("TABLE"), true);
assert.equal(openElements.has_p_in_button_scope(), true);
assert.equal(openElements.remove_node(new WP_HTML_Token("p", "P", false)), true);
assert.deepEqual(openElements.stack.map(({ node_name }) => node_name), ["HTML", "BODY"]);
assert.deepEqual(openEvents, [
	"push:HTML",
	"push:BODY",
	"push:P",
	"push:BUTTON",
	"pop:BUTTON",
	"push:TABLE",
	"pop:TABLE",
	"pop:P",
]);

const scopedOpenElements = new WP_HTML_Open_Elements();
scopedOpenElements.push(new WP_HTML_Token("html", "HTML", false));
const mathMiToken = new WP_HTML_Token("math-mi", "MI", false);
mathMiToken.namespace = "math";
scopedOpenElements.push(mathMiToken);
assert.equal(scopedOpenElements.has_element_in_scope("math MI"), true);
assert.equal(scopedOpenElements.has_element_in_specific_scope("P", ["math MI"]), false);

const coercedOpenElements = new WP_HTML_Open_Elements();
coercedOpenElements.push(new WP_HTML_Token("html", "HTML", false));
coercedOpenElements.push(new WP_HTML_Token("one", "1", false));
coercedOpenElements.push(new WP_HTML_Token("numeric", "123", false));
assert.equal(coercedOpenElements.contains(123), true);
assert.equal(coercedOpenElements.current_node_is(123), true);
assert.equal(coercedOpenElements.has_element_in_scope(123), true);
assert.equal(coercedOpenElements.has_element_in_select_scope(123), true);
assert.equal(coercedOpenElements.pop_until(123), true);
assert.deepEqual(coercedOpenElements.stack.map(({ node_name }) => node_name), ["HTML", "1"]);
assert.equal(coercedOpenElements.at(true).node_name, "HTML");
assert.equal(coercedOpenElements.at("2").node_name, "1");
assert.equal(coercedOpenElements.has_element_in_specific_scope(true, ["HTML"]), true);
assert.throws(
	() => coercedOpenElements.contains(null),
	TypeError,
);
assert.throws(
	() => coercedOpenElements.at("2px"),
	TypeError,
);
assert.throws(
	() => coercedOpenElements.at(null),
	TypeError,
);
assert.throws(
	() => coercedOpenElements.current_node_is([]),
	TypeError,
);
assert.equal(coercedOpenElements.has_element_in_specific_scope("1", null), true);
assert.throws(
	() => coercedOpenElements.has_element_in_specific_scope("P", null),
	TypeError,
);
assert.throws(
	() => coercedOpenElements.set_push_handler("not-callable"),
	TypeError,
);
assert.throws(
	() => coercedOpenElements.set_pop_handler(null),
	TypeError,
);

const selectOpenElements = new WP_HTML_Open_Elements();
selectOpenElements.push(new WP_HTML_Token("select", "SELECT", false));
selectOpenElements.push(new WP_HTML_Token("optgroup", "OPTGROUP", false));
selectOpenElements.push(new WP_HTML_Token("option", "OPTION", false));
assert.equal(selectOpenElements.has_element_in_select_scope("SELECT"), true);
assert.equal(selectOpenElements.has_element_in_select_scope("OPTION"), true);
assert.equal(selectOpenElements.has_element_in_select_scope("DIV"), false);

const tableContextOpenElements = new WP_HTML_Open_Elements();
for (const nodeName of ["HTML", "TABLE", "TBODY", "TR", "TD"]) {
	tableContextOpenElements.push(new WP_HTML_Token(nodeName.toLowerCase(), nodeName, false));
}
tableContextOpenElements.clear_to_table_context();
assert.deepEqual(tableContextOpenElements.stack.map(({ node_name }) => node_name), ["HTML", "TABLE"]);
for (const nodeName of ["TBODY", "TR", "TD"]) {
	tableContextOpenElements.push(new WP_HTML_Token(nodeName.toLowerCase(), nodeName, false));
}
tableContextOpenElements.clear_to_table_body_context();
assert.deepEqual(tableContextOpenElements.stack.map(({ node_name }) => node_name), ["HTML", "TABLE", "TBODY"]);
for (const nodeName of ["TR", "TD"]) {
	tableContextOpenElements.push(new WP_HTML_Token(nodeName.toLowerCase(), nodeName, false));
}
tableContextOpenElements.clear_to_table_row_context();
assert.deepEqual(tableContextOpenElements.stack.map(({ node_name }) => node_name), ["HTML", "TABLE", "TBODY", "TR"]);

const processorState = new WP_HTML_Processor_State();
assert.ok(processorState.stack_of_open_elements instanceof WP_HTML_Open_Elements);
assert.ok(processorState.active_formatting_elements instanceof WP_HTML_Active_Formatting_Elements);
assert.deepEqual(processorState.stack_of_template_insertion_modes, []);
assert.equal(processorState.current_token, null);
assert.equal(processorState.insertion_mode, WP_HTML_Processor_State.INSERTION_MODE_INITIAL);
assert.equal(WP_HTML_Processor_State.INSERTION_MODE_IN_TEMPLATE, "insertion-mode-in-template");
assert.equal(WP_HTML_Processor_State.INSERTION_MODE_AFTER_AFTER_FRAMESET, "insertion-mode-after-after-frameset");
assert.equal(processorState.context_node, null);
assert.equal(processorState.encoding, null);
assert.equal(processorState.encoding_confidence, "tentative");
assert.equal(processorState.head_element, null);
assert.equal(processorState.form_element, null);
assert.equal(processorState.frameset_ok, true);

const wasmBytes = await readFile(new URL("./dist/wp_html_api_rust_core.wasm", import.meta.url));
const wasmArrayBuffer = wasmBytes.buffer.slice(wasmBytes.byteOffset, wasmBytes.byteOffset + wasmBytes.byteLength);
const apiFromArrayBuffer = await loadWasm(wasmArrayBuffer);
assert.equal(apiFromArrayBuffer.version(), "0.1.0");

const apiFromUint8Array = await loadWasm(new Uint8Array(wasmBytes));
assert.equal(apiFromUint8Array.version(), "0.1.0");

const apiFromDataView = await loadWasm(new DataView(wasmBytes.buffer, wasmBytes.byteOffset, wasmBytes.byteLength));
assert.equal(apiFromDataView.version(), "0.1.0");

const apiFromModule = await loadWasm(await WebAssembly.compile(wasmBytes));
assert.equal(apiFromModule.version(), "0.1.0");

const compiledWasmModule = await WebAssembly.compile(wasmBytes);
const wasmInstance = await WebAssembly.instantiate(compiledWasmModule, {});
const apiFromInstance = await loadWasm(wasmInstance);
assert.equal(apiFromInstance.version(), "0.1.0");
const apiFromExports = await loadWasm(wasmInstance.exports);
assert.equal(apiFromExports.version(), "0.1.0");
const apiFromPromisedInstance = await loadWasm(Promise.resolve(wasmInstance));
assert.equal(apiFromPromisedInstance.version(), "0.1.0");
const apiCreatedFromInstance = createHtmlApi(wasmInstance);
assert.equal(apiCreatedFromInstance.version(), "0.1.0");
assert.equal(apiCreatedFromInstance.wasm, wasmInstance.exports);
const apiCreatedFromExports = createHtmlApi(wasmInstance.exports);
assert.equal(apiCreatedFromExports.version(), "0.1.0");
assert.equal(apiCreatedFromExports.wasm, wasmInstance.exports);
const incompleteWasmExports = {
	...wasmInstance.exports,
	wp_html_api_rust_scan_next_tag: undefined,
};
assert.throws(
	() => createHtmlApi(incompleteWasmExports),
	/WASM module is missing required HTML API exports: wp_html_api_rust_scan_next_tag\./,
);
await assert.rejects(
	() => loadWasm(incompleteWasmExports),
	/WASM module is missing required HTML API exports: wp_html_api_rust_scan_next_tag\./,
);

const wasmInstantiatedSource = await WebAssembly.instantiate(wasmBytes, {});
const apiFromInstantiatedSource = await loadWasm(wasmInstantiatedSource);
assert.equal(apiFromInstantiatedSource.version(), "0.1.0");
const apiFromPromisedInstantiatedSource = await loadWasm(Promise.resolve(wasmInstantiatedSource));
assert.equal(apiFromPromisedInstantiatedSource.version(), "0.1.0");
const apiCreatedFromInstantiatedSource = createHtmlApi(wasmInstantiatedSource);
assert.equal(apiCreatedFromInstantiatedSource.version(), "0.1.0");
assert.equal(apiCreatedFromInstantiatedSource.wasm, wasmInstantiatedSource.instance.exports);

if (typeof Response === "function") {
	const apiFromResponse = await loadWasm(new Response(wasmArrayBuffer.slice(0)));
	assert.equal(apiFromResponse.version(), "0.1.0");
	const apiFromPromisedResponse = await loadWasm(Promise.resolve(new Response(wasmArrayBuffer.slice(0))));
	assert.equal(apiFromPromisedResponse.version(), "0.1.0");
	await assert.rejects(
		() => loadWasm(new Response("", { status: 503, statusText: "Unavailable" })),
		/Failed to load WASM: 503 Unavailable/,
	);

	const originalInstantiateStreamingDescriptor = Object.getOwnPropertyDescriptor(WebAssembly, "instantiateStreaming");
	if (
		typeof WebAssembly.instantiateStreaming === "function" &&
		originalInstantiateStreamingDescriptor !== undefined &&
		(originalInstantiateStreamingDescriptor.writable || originalInstantiateStreamingDescriptor.configurable)
	) {
		let streamingCalls = 0;
		try {
			Object.defineProperty(WebAssembly, "instantiateStreaming", {
				configurable: originalInstantiateStreamingDescriptor.configurable,
				value: async (response, imports) => {
					streamingCalls += 1;
					assert.ok(response instanceof Response);
					assert.deepEqual(imports, {});
					return WebAssembly.instantiate(await response.arrayBuffer(), imports);
				},
				writable: originalInstantiateStreamingDescriptor.writable,
			});
			const apiFromStreamingResponse = await loadWasm(new Response(
				wasmArrayBuffer.slice(0),
				{ headers: { "Content-Type": "application/wasm" } },
			));
			assert.equal(apiFromStreamingResponse.version(), "0.1.0");
			assert.equal(streamingCalls, 1);

			Object.defineProperty(WebAssembly, "instantiateStreaming", {
				configurable: originalInstantiateStreamingDescriptor.configurable,
				value: async () => {
					streamingCalls += 1;
					throw new TypeError("Cannot stream this response.");
				},
				writable: originalInstantiateStreamingDescriptor.writable,
			});
			const apiFromStreamingFallback = await loadWasm(new Response(wasmArrayBuffer.slice(0)));
			assert.equal(apiFromStreamingFallback.version(), "0.1.0");
			assert.equal(streamingCalls, 2);
		} finally {
			Object.defineProperty(WebAssembly, "instantiateStreaming", originalInstantiateStreamingDescriptor);
		}
	}
}

if (typeof Request === "function" && typeof fetch === "function") {
	const wasmDataUrl = `data:application/wasm;base64,${wasmBytes.toString("base64")}`;
	const apiFromRequest = await loadWasm(new Request(wasmDataUrl));
	assert.equal(apiFromRequest.version(), "0.1.0");
}

if (typeof Blob === "function") {
	const apiFromBlob = await loadWasm(new Blob([wasmArrayBuffer.slice(0)], { type: "application/wasm" }));
	assert.equal(apiFromBlob.version(), "0.1.0");
}

const apiFromDefaultLocation = await loadWasm();
assert.equal(apiFromDefaultLocation.version(), "0.1.0");

const apiFromFileUrlString = await loadWasm(new URL("./dist/wp_html_api_rust_core.wasm", import.meta.url).href);
assert.equal(apiFromFileUrlString.version(), "0.1.0");

for (const unsupportedWasmInput of [null, 123, {}, Promise.resolve({})]) {
	await assert.rejects(
		() => loadWasm(unsupportedWasmInput),
		/Unsupported WASM input\./,
	);
}

const originalProcessDescriptor = Object.getOwnPropertyDescriptor(globalThis, "process");
const originalFetchDescriptor = Object.getOwnPropertyDescriptor(globalThis, "fetch");
const defaultWasmUrl = new URL("./dist/wp_html_api_rust_core.wasm", import.meta.url);
const browserFetchInputs = [];
try {
	Object.defineProperty(globalThis, "process", {
		configurable: true,
		value: undefined,
		writable: true,
	});
	Object.defineProperty(globalThis, "fetch", {
		configurable: true,
		value: async (input) => {
			browserFetchInputs.push(input);
			if (input instanceof URL) {
				assert.equal(input.href, defaultWasmUrl.href);
			} else {
				assert.equal(input, "./dist/wp_html_api_rust_core.wasm");
			}
			return {
				ok: true,
				arrayBuffer: async () => wasmBytes.buffer.slice(
					wasmBytes.byteOffset,
					wasmBytes.byteOffset + wasmBytes.byteLength,
				),
			};
		},
		writable: true,
	});
	const apiFromBrowserString = await loadWasm("./dist/wp_html_api_rust_core.wasm");
	assert.equal(apiFromBrowserString.version(), "0.1.0");
	const apiFromBrowserDefaultLocation = await loadWasm();
	assert.equal(apiFromBrowserDefaultLocation.version(), "0.1.0");
	assert.deepEqual(browserFetchInputs, ["./dist/wp_html_api_rust_core.wasm", defaultWasmUrl]);
} finally {
	if (originalProcessDescriptor) {
		Object.defineProperty(globalThis, "process", originalProcessDescriptor);
	} else {
		delete globalThis.process;
	}
	if (originalFetchDescriptor) {
		Object.defineProperty(globalThis, "fetch", originalFetchDescriptor);
	} else {
		delete globalThis.fetch;
	}
}

const tagProcessorPrototypeMethods = [
	"change_parsing_namespace",
	"destroy",
	"free",
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
	"native_get_script_content_type",
	"set_modifiable_text",
	"set_attribute",
	"remove_attribute",
	"add_class",
	"remove_class",
	"get_updated_html",
	"get_doctype_info",
	"toString",
];
const tagProcessorStaticMembers = [
	"ADD_CLASS",
	"COMMENT_AS_ABRUPTLY_CLOSED_COMMENT",
	"COMMENT_AS_CDATA_LOOKALIKE",
	"COMMENT_AS_HTML_COMMENT",
	"COMMENT_AS_INVALID_HTML",
	"COMMENT_AS_PI_NODE_LOOKALIKE",
	"MAX_BOOKMARKS",
	"MAX_SEEK_OPS",
	"NO_QUIRKS_MODE",
	"QUIRKS_MODE",
	"REMOVE_CLASS",
	"SKIP_CLASS",
	"STATE_CDATA_NODE",
	"STATE_COMMENT",
	"STATE_COMPLETE",
	"STATE_DOCTYPE",
	"STATE_FUNKY_COMMENT",
	"STATE_INCOMPLETE_INPUT",
	"STATE_MATCHED_TAG",
	"STATE_PRESUMPTUOUS_TAG",
	"STATE_READY",
	"STATE_TEXT_NODE",
	"TEXT_IS_GENERIC",
	"TEXT_IS_NULL_SEQUENCE",
	"TEXT_IS_WHITESPACE",
];
const processorPrototypeMethods = [
	"expects_closer",
	"get_breadcrumbs",
	"get_current_depth",
	"get_last_error",
	"get_namespace",
	"get_unsupported_exception",
	"has_bookmark",
	"is_tag_closer",
	"is_virtual",
	"matches_breadcrumbs",
	"next_tag",
	"next_token",
	"release_bookmark",
	"seek",
	"serialize",
	"serialize_token",
	"set_bookmark",
	"step",
];
const processorStaticMethods = ["create_fragment", "create_full_parser", "is_special", "is_void", "normalize"];
const processorStaticMembers = [
	"CONSTRUCTOR_UNLOCK_CODE",
	"ERROR_EXCEEDED_MAX_BOOKMARKS",
	"ERROR_UNSUPPORTED",
	"MAX_BOOKMARKS",
	"PROCESS_CURRENT_NODE",
	"PROCESS_NEXT_NODE",
	"REPROCESS_CURRENT_NODE",
];
const decoderStaticMethods = [
	"attribute_starts_with",
	"code_point_to_utf8_bytes",
	"decode",
	"decode_attribute",
	"decode_text_node",
	"read_character_reference",
];
const doctypeStaticMethods = ["from_doctype_token"];
const tokenPrototypeMethods = ["destroy", "free"];
const activeFormattingPrototypeMethods = [
	"clear_up_to_last_marker",
	"contains_node",
	"count",
	"current_node",
	"insert_marker",
	"push",
	"remove_node",
	"walk_down",
	"walk_up",
];
const openElementsPrototypeMethods = [
	"after_element_pop",
	"after_element_push",
	"at",
	"clear_to_table_body_context",
	"clear_to_table_context",
	"clear_to_table_row_context",
	"contains",
	"contains_node",
	"count",
	"current_node",
	"current_node_is",
	"has_element_in_button_scope",
	"has_element_in_list_item_scope",
	"has_element_in_scope",
	"has_element_in_select_scope",
	"has_element_in_specific_scope",
	"has_element_in_table_scope",
	"has_p_in_button_scope",
	"pop",
	"pop_until",
	"push",
	"remove_node",
	"set_pop_handler",
	"set_push_handler",
	"walk_down",
	"walk_up",
];
const stackEventStaticMembers = ["POP", "PUSH"];
const processorStateStaticMembers = [
	"INSERTION_MODE_AFTER_AFTER_BODY",
	"INSERTION_MODE_AFTER_AFTER_FRAMESET",
	"INSERTION_MODE_AFTER_BODY",
	"INSERTION_MODE_AFTER_FRAMESET",
	"INSERTION_MODE_AFTER_HEAD",
	"INSERTION_MODE_BEFORE_HEAD",
	"INSERTION_MODE_BEFORE_HTML",
	"INSERTION_MODE_IN_BODY",
	"INSERTION_MODE_IN_CAPTION",
	"INSERTION_MODE_IN_CELL",
	"INSERTION_MODE_IN_COLUMN_GROUP",
	"INSERTION_MODE_IN_FRAMESET",
	"INSERTION_MODE_IN_HEAD",
	"INSERTION_MODE_IN_HEAD_NOSCRIPT",
	"INSERTION_MODE_IN_ROW",
	"INSERTION_MODE_IN_SELECT",
	"INSERTION_MODE_IN_SELECT_IN_TABLE",
	"INSERTION_MODE_IN_TABLE",
	"INSERTION_MODE_IN_TABLE_BODY",
	"INSERTION_MODE_IN_TABLE_TEXT",
	"INSERTION_MODE_IN_TEMPLATE",
	"INSERTION_MODE_INITIAL",
];
const jsOnlyTagProcessorPrototypeMethods = new Set([
	"destroy",
	"free",
	"native_get_script_content_type",
]);
const jsOnlyProcessorPrototypeMethods = new Set([
	...jsOnlyTagProcessorPrototypeMethods,
	"is_virtual",
]);
const phpTagProcessorPrototypeMethods = await phpPublicMethodNames(
	"class-wp-html-tag-processor.php",
	{ instanceOnly: true },
);
const phpProcessorPrototypeMethods = await phpPublicMethodNames(
	"class-wp-html-processor.php",
	{ instanceOnly: true },
);
assert.deepEqual(
	tagProcessorPrototypeMethods
		.filter((methodName) => !jsOnlyTagProcessorPrototypeMethods.has(methodName))
		.sort(),
	phpTagProcessorPrototypeMethods,
);
assert.deepEqual(
	[...new Set([...tagProcessorPrototypeMethods, ...processorPrototypeMethods])]
		.filter((methodName) => !jsOnlyProcessorPrototypeMethods.has(methodName))
		.sort(),
	[...new Set([...phpTagProcessorPrototypeMethods, ...phpProcessorPrototypeMethods])].sort(),
);
assert.deepEqual(
	await phpPublicMethodNames("class-wp-html-processor.php", { staticOnly: true }),
	[...processorStaticMethods].sort(),
);
assert.deepEqual(
	await phpPublicMethodNames("class-wp-html-decoder.php", { staticOnly: true }),
	[...decoderStaticMethods].sort(),
);
assert.deepEqual(
	await phpPublicMethodNames("class-wp-html-doctype-info.php", { staticOnly: true }),
	[...doctypeStaticMethods].sort(),
);
assert.deepEqual(
	await phpPublicMethodNames("class-wp-html-active-formatting-elements.php", { instanceOnly: true }),
	[...activeFormattingPrototypeMethods].sort(),
);
assert.deepEqual(
	await phpPublicMethodNames("class-wp-html-open-elements.php", { instanceOnly: true }),
	[...openElementsPrototypeMethods].sort(),
);
assert.deepEqual(
	await phpClassConstantNames("class-wp-html-tag-processor.php"),
	[...tagProcessorStaticMembers].sort(),
);
assert.deepEqual(
	await phpClassConstantNames("class-wp-html-processor.php"),
	[...processorStaticMembers].sort(),
);
assert.deepEqual(
	await phpClassConstantNames("class-wp-html-stack-event.php"),
	[...stackEventStaticMembers].sort(),
);
assert.deepEqual(
	await phpClassConstantNames("class-wp-html-processor-state.php"),
	[...processorStateStaticMembers].sort(),
);
const phpTagProcessorStaticMemberValues = await phpClassConstantValues("class-wp-html-tag-processor.php");
const phpProcessorStaticMemberValues = await phpClassConstantValues("class-wp-html-processor.php");
const phpStackEventStaticMemberValues = await phpClassConstantValues("class-wp-html-stack-event.php");
const phpProcessorStateStaticMemberValues = await phpClassConstantValues("class-wp-html-processor-state.php");
assert.deepEqual(
	staticMemberValues(WP_HTML_Tag_Processor, tagProcessorStaticMembers),
	phpTagProcessorStaticMemberValues,
);
assert.deepEqual(
	staticMemberValues(WP_HTML_Processor, processorStaticMembers),
	phpProcessorStaticMemberValues,
);
assert.deepEqual(
	staticMemberValues(WP_HTML_Stack_Event, stackEventStaticMembers),
	phpStackEventStaticMemberValues,
);
assert.deepEqual(
	staticMemberValues(WP_HTML_Processor_State, processorStateStaticMembers),
	phpProcessorStateStaticMemberValues,
);

for (const [fileName, interfaceName] of publicPropertyInterfaces) {
	const excluded = jsOnlyInterfaceProperties[interfaceName] ?? new Set();
	assert.deepEqual(
		declaredInterfacePropertyNames(interfaceName).filter((propertyName) => !excluded.has(propertyName)),
		await phpPublicPropertyNames(fileName),
	);
}

assert.deepEqual(declaredInterfaceMethodNames("WP_HTML_Tag_Processor"), [...tagProcessorPrototypeMethods].sort());
assert.deepEqual(declaredReadonlyMemberNames("WP_HTML_Tag_Processor_Constructor"), [...tagProcessorStaticMembers].sort());
assert.deepEqual(declaredInterfaceMethodNames("WP_HTML_Processor"), [...processorPrototypeMethods].sort());
assert.deepEqual(declaredConstructorMethodNames("WP_HTML_Processor_Constructor"), [...processorStaticMethods].sort());
assert.deepEqual(declaredReadonlyMemberNames("WP_HTML_Processor_Constructor"), [...processorStaticMembers].sort());
assert.deepEqual(declaredConstructorMethodNames("WP_HTML_Decoder_Constructor"), [...decoderStaticMethods].sort());
assert.deepEqual(declaredConstructorMethodNames("WP_HTML_Doctype_Info_Constructor"), [...doctypeStaticMethods].sort());
assert.deepEqual(declaredInterfaceMethodNames("WP_HTML_Token"), [...tokenPrototypeMethods].sort());
assert.deepEqual(declaredInterfaceMethodNames("WP_HTML_Active_Formatting_Elements"), [...activeFormattingPrototypeMethods].sort());
assert.deepEqual(declaredInterfaceMethodNames("WP_HTML_Open_Elements"), [...openElementsPrototypeMethods].sort());
assert.deepEqual(declaredReadonlyMemberNames("WP_HTML_Stack_Event_Constructor"), [...stackEventStaticMembers].sort());
assert.deepEqual(declaredReadonlyMemberNames("WP_HTML_Processor_State_Constructor"), [...processorStateStaticMembers].sort());

assert.deepEqual(runtimePrototypeMethodNames(WP_HTML_Tag_Processor), [...tagProcessorPrototypeMethods].sort());
assert.deepEqual(runtimeStaticMemberNames(WP_HTML_Tag_Processor), [...tagProcessorStaticMembers].sort());
assert.deepEqual(
	runtimePrototypeMethodNames(WP_HTML_Processor, true),
	[...new Set([...tagProcessorPrototypeMethods, ...processorPrototypeMethods])].sort(),
);
assert.deepEqual(
	runtimeStaticMemberNames(WP_HTML_Processor, true),
	[...new Set([...tagProcessorStaticMembers, ...processorStaticMethods, ...processorStaticMembers])].sort(),
);
assert.deepEqual(runtimeStaticMemberNames(WP_HTML_Decoder), [...decoderStaticMethods].sort());
assert.deepEqual(runtimeStaticMemberNames(WP_HTML_Doctype_Info), [...doctypeStaticMethods].sort());
assert.deepEqual(runtimePrototypeMethodNames(WP_HTML_Token), [...tokenPrototypeMethods].sort());
assert.deepEqual(runtimePrototypeMethodNames(WP_HTML_Active_Formatting_Elements), [...activeFormattingPrototypeMethods].sort());
assert.deepEqual(runtimePrototypeMethodNames(WP_HTML_Open_Elements), [...openElementsPrototypeMethods].sort());
assert.deepEqual(runtimeStaticMemberNames(WP_HTML_Stack_Event), [...stackEventStaticMembers].sort());
assert.deepEqual(runtimeStaticMemberNames(WP_HTML_Processor_State), [...processorStateStaticMembers].sort());

for (const method of tagProcessorPrototypeMethods) {
	assert.equal(typeof WP_HTML_Tag_Processor.prototype[method], "function", `Missing tag processor method ${method}`);
	assert.equal(typeof WP_HTML_Processor.prototype[method], "function", `Missing inherited processor method ${method}`);
}

for (const property of tagProcessorStaticMembers) {
	assert.ok(Object.prototype.hasOwnProperty.call(WP_HTML_Tag_Processor, property), `Missing tag processor static member ${property}`);
}

for (const method of processorPrototypeMethods) {
	assert.equal(typeof WP_HTML_Processor.prototype[method], "function", `Missing processor method ${method}`);
}

for (const method of processorStaticMethods) {
	assert.equal(typeof WP_HTML_Processor[method], "function", `Missing processor static method ${method}`);
}

for (const property of processorStaticMembers) {
	assert.ok(Object.prototype.hasOwnProperty.call(WP_HTML_Processor, property), `Missing processor static member ${property}`);
}

for (const method of decoderStaticMethods) {
	assert.equal(typeof WP_HTML_Decoder[method], "function", `Missing decoder static method ${method}`);
}

for (const method of doctypeStaticMethods) {
	assert.equal(typeof WP_HTML_Doctype_Info[method], "function", `Missing doctype static method ${method}`);
}

for (const method of tokenPrototypeMethods) {
	assert.equal(typeof WP_HTML_Token.prototype[method], "function", `Missing token method ${method}`);
}

for (const method of activeFormattingPrototypeMethods) {
	assert.equal(typeof WP_HTML_Active_Formatting_Elements.prototype[method], "function", `Missing active formatting method ${method}`);
}

for (const method of openElementsPrototypeMethods) {
	assert.equal(typeof WP_HTML_Open_Elements.prototype[method], "function", `Missing open elements method ${method}`);
}

for (const property of stackEventStaticMembers) {
	assert.ok(Object.prototype.hasOwnProperty.call(WP_HTML_Stack_Event, property), `Missing stack event static member ${property}`);
}

for (const property of processorStateStaticMembers) {
	assert.ok(Object.prototype.hasOwnProperty.call(WP_HTML_Processor_State, property), `Missing processor state static member ${property}`);
}

function compareHtml5libTreeAttributes(left, right) {
	const leftHasColon = left.display.includes(":");
	const rightHasColon = right.display.includes(":");
	if (leftHasColon !== rightHasColon) {
		return leftHasColon ? 1 : -1;
	}

	const leftHasNamespaceSeparator = left.display.includes(" ");
	const rightHasNamespaceSeparator = right.display.includes(" ");
	if (leftHasNamespaceSeparator !== rightHasNamespaceSeparator) {
		return leftHasNamespaceSeparator ? 1 : -1;
	}

	return left.display < right.display ? -1 : left.display > right.display ? 1 : 0;
}

function html5libTreeIndentLevel(path) {
	let level = Math.max(0, path.length - 1);
	for (let i = 0; i < path.length - 1; i += 1) {
		if (path[i].name === "TEMPLATE" && path[i].namespace === "html") {
			level += 1;
		}
	}
	return level;
}

function buildFullParserHtml5libTree(html) {
	const processor = WP_HTML_Processor.create_full_parser(html);
	assert.notEqual(processor, null);

	let output = "";
	let wasText = false;
	let textNode = "";
	let openElementPath = [];
	const indent = (level) => "  ".repeat(level);
	const pathFromBreadcrumbs = (currentNamespace = "html", currentIsElement = false) => (
		processor.get_breadcrumbs().map((name, index, breadcrumbs) => {
			if (openElementPath[index]?.name === name) {
				return openElementPath[index];
			}

			return {
				name,
				namespace: currentIsElement && index === breadcrumbs.length - 1 ? currentNamespace : "html",
			};
		})
	);

	while (processor.next_token()) {
		const tokenName = processor.get_token_name();
		const tokenType = processor.get_token_type();
		const namespace = processor.get_namespace();

		if (wasText && tokenName !== "#text") {
			if (textNode !== "") {
				output += `${textNode}"\n`;
			}
			wasText = false;
			textNode = "";
		}

		switch (tokenType) {
			case "#doctype": {
				const doctype = processor.get_doctype_info();
				output += `<!DOCTYPE ${doctype.name ?? ""}`;
				if (doctype.public_identifier !== null || doctype.system_identifier !== null) {
					output += ` "${doctype.public_identifier ?? ""}" "${doctype.system_identifier ?? ""}"`;
				}
				output += ">\n";
				break;
			}

			case "#tag": {
				if (processor.is_tag_closer()) {
					openElementPath = pathFromBreadcrumbs(namespace, false);
					break;
				}

				const path = pathFromBreadcrumbs(namespace, true);
				const tagName = namespace === "html"
					? processor.get_tag().toLowerCase()
					: `${namespace} ${processor.get_qualified_tag_name()}`;
				const tagIndent = html5libTreeIndentLevel(path);
				output += `${indent(tagIndent)}<${tagName}>\n`;

				const attributeNames = processor.get_attribute_names_with_prefix("");
				if (attributeNames) {
					const attributes = attributeNames
						.map((name) => ({ name, display: processor.get_qualified_attribute_name(name) }))
						.sort(compareHtml5libTreeAttributes);
					for (const { name, display } of attributes) {
						const value = processor.get_attribute(name) === true ? "" : processor.get_attribute(name);
						output += `${indent(tagIndent + 1)}${display}="${value}"\n`;
					}
				}

				const modifiableText = processor.get_modifiable_text();
				if (modifiableText !== "") {
					output += `${indent(tagIndent + 1)}"${modifiableText}"\n`;
				}

				if (namespace === "html" && tokenName === "TEMPLATE") {
					output += `${indent(tagIndent + 1)}content\n`;
				}
				openElementPath = processor.expects_closer() ? path : path.slice(0, -1);
				break;
			}

			case "#cdata-section":
			case "#text": {
				const path = pathFromBreadcrumbs(namespace, false);
				const textContent = processor.get_modifiable_text();
				if (textContent === "") {
					openElementPath = path.slice(0, -1);
					break;
				}
				wasText = true;
				if (textNode === "") {
					textNode += `${indent(html5libTreeIndentLevel(path))}"`;
				}
				textNode += textContent;
				openElementPath = path.slice(0, -1);
				break;
			}

			case "#funky-comment": {
				const path = pathFromBreadcrumbs(namespace, false);
				output += `${indent(html5libTreeIndentLevel(path))}<!-- ${processor.get_modifiable_text()} -->\n`;
				openElementPath = path.slice(0, -1);
				break;
			}

			case "#comment": {
				const path = pathFromBreadcrumbs(namespace, false);
				output += `${indent(html5libTreeIndentLevel(path))}<!-- ${processor.get_full_comment_text()} -->\n`;
				openElementPath = path.slice(0, -1);
				break;
			}

			default:
				throw new Error(`Unhandled token type for html5lib tree smoke test: ${tokenType}`);
		}
	}

	if (textNode !== "") {
		output += `${textNode}"\n`;
	}

	assert.equal(processor.get_unsupported_exception(), null);
	assert.equal(processor.get_last_error(), null);
	assert.equal(processor.paused_at_incomplete_token(), false);
	processor.destroy();

	return `${output}\n`;
}

for (const [html, expectedTree] of [
	[
		"<!DOCTYPE html>Hello",
		'<!DOCTYPE html>\n<html>\n  <head>\n  <body>\n    "Hello"\n\n',
	],
	[
		"<",
		'<html>\n  <head>\n  <body>\n    "<"\n\n',
	],
	[
		"</#",
		"<!-- # -->\n<html>\n  <head>\n  <body>\n\n",
	],
	[
		"<?",
		"<!-- ? -->\n<html>\n  <head>\n  <body>\n\n",
	],
	[
		"<!DOCTYPEhtml>Hello",
		'<!DOCTYPE html>\n<html>\n  <head>\n  <body>\n    "Hello"\n\n',
	],
	[
		'<!DOCTYPE potato SYSTEM "taco">Hello',
		'<!DOCTYPE potato "" "taco">\n<html>\n  <head>\n  <body>\n    "Hello"\n\n',
	],
	[
		'<!DOCTYPE potato PUBLIC "go\'of">Hello',
		'<!DOCTYPE potato "go\'of" "">\n<html>\n  <head>\n  <body>\n    "Hello"\n\n',
	],
	[
		"FOO<!-- BAR -->BAZ",
		'<html>\n  <head>\n  <body>\n    "FOO"\n    <!--  BAR  -->\n    "BAZ"\n\n',
	],
	[
		"FOO<!-- BAR --! >BAZ",
		'<html>\n  <head>\n  <body>\n    "FOO"\n    <!--  BAR --! >BAZ -->\n\n',
	],
	[
		"<frame>test",
		'<html>\n  <head>\n  <body>\n    "test"\n\n',
	],
	[
		"<!doctypehtml><p><form>",
		'<!DOCTYPE html>\n<html>\n  <head>\n  <body>\n    <p>\n    <form>\n\n',
	],
	[
		"<!DOCTYPE html>X</body>X",
		'<!DOCTYPE html>\n<html>\n  <head>\n  <body>\n    "XX"\n\n',
	],
	[
		"<!DOCTYPE html><!-- X",
		"<!DOCTYPE html>\n<!--  X -->\n<html>\n  <head>\n  <body>\n\n",
	],
	[
		"<!DOCTYPE html><head></head><!-- X",
		"<!DOCTYPE html>\n<html>\n  <head>\n  <!--  X -->\n  <body>\n\n",
	],
	[
		"<!DOCTYPE html><!--x--",
		"<!DOCTYPE html>\n<!-- x -->\n<html>\n  <head>\n  <body>\n\n",
	],
	[
		"<!DOCTYPE html><body></body><!--do-->",
		"<!DOCTYPE html>\n<html>\n  <head>\n  <body>\n  <!-- do -->\n\n",
	],
	[
		"<html><frameset></frameset></html> ",
		'<html>\n  <head>\n  <frameset>\n  " "\n\n',
	],
	[
		"<!DOCTYPE html><select><optgroup><option></optgroup><option><select><option>",
		'<!DOCTYPE html>\n<html>\n  <head>\n  <body>\n    <select>\n      <optgroup>\n        <option>\n      <option>\n    <option>\n\n',
	],
	[
		"<select><b><option><select><option></b></select>X",
		'<html>\n  <head>\n  <body>\n    <select>\n      <option>\n    <option>\n      "X"\n\n',
	],
	[
		"<b><table><td></b><i></table>X",
		'<html>\n  <head>\n  <body>\n    <b>\n      <table>\n        <tbody>\n          <tr>\n            <td>\n              <i>\n      "X"\n\n',
	],
	[
		"<!DOCTYPE html><font><table></font></table></font>",
		'<!DOCTYPE html>\n<html>\n  <head>\n  <body>\n    <font>\n      <table>\n\n',
	],
	[
		"<b>Test</i>Test",
		'<html>\n  <head>\n  <body>\n    <b>\n      "TestTest"\n\n',
	],
	[
		"<a><svg><tr><input></a>",
		"<html>\n  <head>\n  <body>\n    <a>\n      <svg svg>\n        <svg tr>\n          <svg input>\n\n",
	],
	[
		"<svg><!DOCTYPE html></svg>",
		"<html>\n  <head>\n  <body>\n    <svg svg>\n\n",
	],
	[
		"<div><svg></div>a",
		'<html>\n  <head>\n  <body>\n    <div>\n      <svg svg>\n    "a"\n\n',
	],
	[
		"<div><svg><path><foreignObject><p></div>a",
		'<html>\n  <head>\n  <body>\n    <div>\n      <svg svg>\n        <svg path>\n          <svg foreignObject>\n            <p>\n              "a"\n\n',
	],
	[
		"<!DOCTYPE html><p><svg><desc><p>",
		"<!DOCTYPE html>\n<html>\n  <head>\n  <body>\n    <p>\n      <svg svg>\n        <svg desc>\n          <p>\n\n",
	],
	[
		"<!doctype html><p><math><mn><span></p>a",
		'<!DOCTYPE html>\n<html>\n  <head>\n  <body>\n    <p>\n      <math math>\n        <math mn>\n          <span>\n            <p>\n            "a"\n\n',
	],
	[
		"<math><annotation-xml><svg></svg></annotation-xml><mi>",
		"<html>\n  <head>\n  <body>\n    <math math>\n      <math annotation-xml>\n        <svg svg>\n      <math mi>\n\n",
	],
	[
		"<!doctype html><table><td><span><font></span><span>",
		"<!DOCTYPE html>\n<html>\n  <head>\n  <body>\n    <table>\n      <tbody>\n        <tr>\n          <td>\n            <span>\n              <font>\n            <font>\n              <span>\n\n",
	],
	[
		"<!doctype html><h1><div><h3><span></h1>foo",
		'<!DOCTYPE html>\n<html>\n  <head>\n  <body>\n    <h1>\n      <div>\n        <h3>\n          <span>\n        "foo"\n\n',
	],
	[
		"<!doctype html><h3><li>abc</h2>foo",
		'<!DOCTYPE html>\n<html>\n  <head>\n  <body>\n    <h3>\n      <li>\n        "abc"\n    "foo"\n\n',
	],
	[
		"<!doctype html><p><button></p>",
		"<!DOCTYPE html>\n<html>\n  <head>\n  <body>\n    <p>\n      <button>\n        <p>\n\n",
	],
	[
		"<p><xmp></xmp>",
		"<html>\n  <head>\n  <body>\n    <p>\n    <xmp>\n\n",
	],
	[
		"<!doctype html><nobr><nobr><nobr>",
		"<!DOCTYPE html>\n<html>\n  <head>\n  <body>\n    <nobr>\n    <nobr>\n    <nobr>\n\n",
	],
	[
		"<!doctype html><nobr><nobr></nobr><nobr>",
		"<!DOCTYPE html>\n<html>\n  <head>\n  <body>\n    <nobr>\n    <nobr>\n    <nobr>\n\n",
	],
	[
		"<svg><foreignObject></foreignObject><title></svg>foo",
		'<html>\n  <head>\n  <body>\n    <svg svg>\n      <svg foreignObject>\n      <svg title>\n    "foo"\n\n',
	],
	[
		"<!doctype html><table><form><form>",
		"<!DOCTYPE html>\n<html>\n  <head>\n  <body>\n    <table>\n      <form>\n\n",
	],
	[
		"<!doctype html><table><form></table><form>",
		"<!DOCTYPE html>\n<html>\n  <head>\n  <body>\n    <table>\n      <form>\n\n",
	],
	[
		"<!doctype html><form><table></form><form></table></form>",
		"<!DOCTYPE html>\n<html>\n  <head>\n  <body>\n    <form>\n      <table>\n        <form>\n\n",
	],
	[
		"<!DOCTYPE html><table><caption><svg>foo</table>bar",
		'<!DOCTYPE html>\n<html>\n  <head>\n  <body>\n    <table>\n      <caption>\n        <svg svg>\n          "foo"\n    "bar"\n\n',
	],
	[
		"<div a=1 b><span>Hi</span></div>",
		'<html>\n  <head>\n  <body>\n    <div>\n      a="1"\n      b=""\n      <span>\n        "Hi"\n\n',
	],
]) {
	assert.equal(buildFullParserHtml5libTree(html), expectedTree);
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
assert.deepEqual(
	scanNextTag("<p>text</p>", 3),
	{
		tag_start: 7,
		tag_end: 11,
		name_start: 9,
		name_len: 1,
		name_length: 1,
		tag_name: "P",
		is_closing: true,
		has_self_closing_flag: false,
		token_end: 11,
		token_type: 1,
	},
);
assert.equal(scanNextTag("1 < 2 <!-- comment --> <span>").tag_start, 23);
assert.equal(scanNextTag('<div title="1 > 0">ok</div>').tag_end, 19);
assert.equal(scanNextTag('<div title="unterminated'), false);

const scriptScanHtml = "<script><!--<script></script><script></script><span></span></script><div>";
const scriptScan = scanNextTag(scriptScanHtml);
assert.equal(scriptScan.tag_name, "SCRIPT");
assert.equal(scanNextTag(scriptScanHtml, scriptScan.token_end).tag_name, "DIV");

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

const coercedAttributeTags = new WP_HTML_Tag_Processor('<div 1="one" class="1 0" data-=x data-1=y></div>');
assert.equal(coercedAttributeTags.next_tag("div"), true);
assert.equal(coercedAttributeTags.get_attribute(true), "one");
assert.equal(coercedAttributeTags.get_attribute(false), null);
assert.equal(coercedAttributeTags.get_attribute(null), null);
assert.deepEqual(coercedAttributeTags.get_attribute_names_with_prefix(true), ["1"]);
assert.deepEqual(coercedAttributeTags.get_attribute_names_with_prefix(false), ["1", "class", "data-", "data-1"]);
assert.deepEqual(coercedAttributeTags.get_attribute_names_with_prefix(null), ["1", "class", "data-", "data-1"]);
assert.equal(coercedAttributeTags.has_class(true), true);
assert.equal(coercedAttributeTags.has_class(false), false);
assert.equal(coercedAttributeTags.has_class(null), false);
assert.equal(coercedAttributeTags.add_class(true), true);
assert.equal(coercedAttributeTags.add_class(null), true);
assert.equal(coercedAttributeTags.remove_class(false), true);
assert.equal(coercedAttributeTags.remove_class(null), true);
assert.equal(coercedAttributeTags.set_attribute(false, "v"), false);
assert.equal(coercedAttributeTags.set_attribute(null, "v"), false);
assert.equal(coercedAttributeTags.set_attribute("data-num", 123), true);
assert.equal(coercedAttributeTags.get_attribute("data-num"), "123");
assert.equal(coercedAttributeTags.set_attribute("data-round", 1.23456789012345), true);
assert.equal(coercedAttributeTags.get_attribute("data-round"), "1.2345678901235");
assert.equal(coercedAttributeTags.set_attribute("data-safe-int", 100000000000000), true);
assert.equal(coercedAttributeTags.get_attribute("data-safe-int"), "100000000000000");
assert.equal(coercedAttributeTags.set_attribute("data-small", 1e-5), true);
assert.equal(coercedAttributeTags.get_attribute("data-small"), "1.0E-5");
assert.equal(coercedAttributeTags.set_attribute("data-large", 1e20), true);
assert.equal(coercedAttributeTags.get_attribute("data-large"), "1.0E+20");
assert.equal(coercedAttributeTags.set_attribute("data-negative-zero", -0), true);
assert.equal(coercedAttributeTags.get_attribute("data-negative-zero"), "-0");
assert.equal(coercedAttributeTags.set_attribute("data-nan", NaN), true);
assert.equal(coercedAttributeTags.get_attribute("data-nan"), "NAN");
assert.equal(coercedAttributeTags.set_attribute("data-inf", Infinity), true);
assert.equal(coercedAttributeTags.get_attribute("data-inf"), "INF");
assert.equal(coercedAttributeTags.set_attribute("data-null", null), false);
assert.equal(coercedAttributeTags.get_attribute("data-null"), null);
assert.equal(coercedAttributeTags.remove_attribute(null), false);
assert.throws(
	() => coercedAttributeTags.get_attribute({ name: "class" }),
	TypeError,
);
assert.throws(
	() => coercedAttributeTags.set_attribute("data-object", {}),
	TypeError,
);
coercedAttributeTags.destroy();

const invalidAttributeNameTags = new WP_HTML_Tag_Processor("<div></div>");
assert.equal(invalidAttributeNameTags.next_tag("div"), true);
for (const name of [
	"",
	"too late",
	'too"late',
	"too&late",
	"too'late",
	"too/late",
	"too<late",
	"too=late",
	"too>late",
	"shut\0down",
	"shut\u001Fdown",
	"shut\uFDD0down",
	"shut\uFFFEdown",
	"shut\uFFFFdown",
	"shut\u{1FFFE}down",
	"shut\u{10FFFF}down",
]) {
	assert.equal(invalidAttributeNameTags.set_attribute(name, true), false, `Should reject ${JSON.stringify(name)}`);
}
assert.equal(invalidAttributeNameTags.get_updated_html(), "<div></div>");
invalidAttributeNameTags.destroy();

const unicodeAttributeNameTags = new WP_HTML_Tag_Processor("<div></div>");
assert.equal(unicodeAttributeNameTags.next_tag("div"), true);
assert.equal(unicodeAttributeNameTags.set_attribute("data-\u00E9", "ok"), true);
assert.equal(unicodeAttributeNameTags.get_attribute("data-\u00E9"), "ok");
unicodeAttributeNameTags.destroy();

const escapedAttributeValueTags = new WP_HTML_Tag_Processor("<div></div>");
assert.equal(escapedAttributeValueTags.next_tag("div"), true);
assert.equal(
	escapedAttributeValueTags.set_attribute("test", "\" onclick=\"alert('1');\"><script>alert(\"1\")</script>"),
	true,
);
assert.equal(
	escapedAttributeValueTags.get_updated_html(),
	'<div test="&quot; onclick=&quot;alert(&apos;1&apos;);&quot;&gt;&lt;script&gt;alert(&quot;1&quot;)&lt;/script&gt;"></div>',
);
escapedAttributeValueTags.destroy();

const removedBooleanAttributeTags = new WP_HTML_Tag_Processor('<input checked type="checkbox">');
assert.equal(removedBooleanAttributeTags.next_tag("input"), true);
assert.equal(removedBooleanAttributeTags.set_attribute("checked", false), true);
assert.equal(removedBooleanAttributeTags.get_attribute("checked"), null);
assert.equal(removedBooleanAttributeTags.get_updated_html(), '<input  type="checkbox">');
removedBooleanAttributeTags.destroy();

const missingFalseAttributeTags = new WP_HTML_Tag_Processor('<input type="checkbox">');
assert.equal(missingFalseAttributeTags.next_tag("input"), true);
assert.equal(missingFalseAttributeTags.set_attribute("checked", false), false);
assert.equal(missingFalseAttributeTags.get_updated_html(), '<input type="checkbox">');
missingFalseAttributeTags.destroy();

const unselectedAttributePrefixTags = new WP_HTML_Tag_Processor('<div data-foo="bar">Test</div>');
assert.equal(unselectedAttributePrefixTags.get_attribute_names_with_prefix("data-"), null);
unselectedAttributePrefixTags.destroy();

const missingAttributePrefixTags = new WP_HTML_Tag_Processor('<div data-foo="bar">Test</div>');
assert.equal(missingAttributePrefixTags.next_tag("p"), false);
assert.equal(missingAttributePrefixTags.get_attribute_names_with_prefix("data-"), null);
missingAttributePrefixTags.destroy();

const closingAttributePrefixTags = new WP_HTML_Tag_Processor('<div data-foo="bar">Test</div>');
assert.equal(closingAttributePrefixTags.next_tag("div"), true);
assert.equal(closingAttributePrefixTags.next_tag({ tag_closers: "visit" }), true);
assert.equal(closingAttributePrefixTags.get_attribute_names_with_prefix("data-"), null);
closingAttributePrefixTags.destroy();

const emptyAttributePrefixTags = new WP_HTML_Tag_Processor("<div>Test</div>");
assert.equal(emptyAttributePrefixTags.next_tag("div"), true);
assert.deepEqual(emptyAttributePrefixTags.get_attribute_names_with_prefix("data-"), []);
emptyAttributePrefixTags.destroy();

const mixedCaseAttributePrefixTags = new WP_HTML_Tag_Processor('<div DATA-enabled class="test" data-test-ID="14">Test</div>');
assert.equal(mixedCaseAttributePrefixTags.next_tag(), true);
assert.deepEqual(mixedCaseAttributePrefixTags.get_attribute_names_with_prefix("data-"), ["data-enabled", "data-test-id"]);
mixedCaseAttributePrefixTags.destroy();

const addedAttributePrefixTags = new WP_HTML_Tag_Processor('<div data-foo="bar">Test</div>');
assert.equal(addedAttributePrefixTags.next_tag(), true);
assert.equal(addedAttributePrefixTags.set_attribute("data-test-id", "14"), true);
assert.equal(addedAttributePrefixTags.get_updated_html(), '<div data-test-id="14" data-foo="bar">Test</div>');
assert.deepEqual(addedAttributePrefixTags.get_attribute_names_with_prefix("data-"), ["data-test-id", "data-foo"]);
addedAttributePrefixTags.destroy();

const duplicateAttributeNameTags = new WP_HTML_Tag_Processor("<div DATA-x=1 data-x=2 data-y=3>");
assert.equal(duplicateAttributeNameTags.next_tag("div"), true);
assert.deepEqual(duplicateAttributeNameTags.get_attribute_names_with_prefix("data-"), ["data-x", "data-y"]);
assert.equal(duplicateAttributeNameTags.get_attribute("data-x"), "1");
duplicateAttributeNameTags.destroy();

const noAttributePrefixMatches = new WP_HTML_Tag_Processor("<div id=x>");
assert.equal(noAttributePrefixMatches.next_tag("div"), true);
assert.deepEqual(noAttributePrefixMatches.get_attribute_names_with_prefix("data-"), []);
assert.deepEqual(noAttributePrefixMatches.get_attribute_names_with_prefix(""), ["id"]);
noAttributePrefixMatches.destroy();

const decodedClassQueryTags = new WP_HTML_Tag_Processor('<div class="&notin;-class &lt;egg&gt; &#xff03;">');
assert.equal(decodedClassQueryTags.next_tag({ class_name: "<egg>" }), true);
assert.equal(decodedClassQueryTags.get_tag(), "DIV");
assert.deepEqual(decodedClassQueryTags.class_list(), ["∉-class", "<egg>", "＃"]);
decodedClassQueryTags.destroy();

const nonStringClassQueryTags = new WP_HTML_Tag_Processor('<div class="x"></div><span></span>');
assert.equal(nonStringClassQueryTags.next_tag({ class_name: null }), true);
assert.equal(nonStringClassQueryTags.get_tag(), "DIV");
nonStringClassQueryTags.destroy();

const nonStringTagQueryTags = new WP_HTML_Tag_Processor("<div one></div><div two></div>");
assert.equal(nonStringTagQueryTags.next_tag({ tag_name: 1, match_offset: "2" }), true);
assert.equal(nonStringTagQueryTags.get_attribute("one"), true);
assert.equal(nonStringTagQueryTags.get_attribute("two"), null);
nonStringTagQueryTags.destroy();

const nonVisitCloserQueryTags = new WP_HTML_Tag_Processor("<div></div>");
assert.equal(nonVisitCloserQueryTags.next_tag("div"), true);
assert.equal(nonVisitCloserQueryTags.next_tag({ tag_name: "div", tag_closers: {} }), false);
nonVisitCloserQueryTags.destroy();

const duplicateDecodedClassList = new WP_HTML_Tag_Processor('<div class="one one &#x6f;ne">');
assert.equal(duplicateDecodedClassList.next_tag("div"), true);
assert.deepEqual(duplicateDecodedClassList.class_list(), ["one"]);
duplicateDecodedClassList.destroy();

const addClassBeforeSetClassAttribute = new WP_HTML_Tag_Processor('<div class="main with-border" id="first"><span></span></div>');
assert.equal(addClassBeforeSetClassAttribute.next_tag("div"), true);
assert.equal(addClassBeforeSetClassAttribute.add_class("add_class"), true);
assert.equal(addClassBeforeSetClassAttribute.set_attribute("class", "set_attribute"), true);
assert.equal(addClassBeforeSetClassAttribute.get_attribute("class"), "set_attribute");
assert.equal(addClassBeforeSetClassAttribute.get_updated_html(), '<div class="set_attribute" id="first"><span></span></div>');
addClassBeforeSetClassAttribute.destroy();

const addClassAfterSetClassAttribute = new WP_HTML_Tag_Processor('<div class="main with-border" id="first"><span></span></div>');
assert.equal(addClassAfterSetClassAttribute.next_tag("div"), true);
assert.equal(addClassAfterSetClassAttribute.set_attribute("class", "set_attribute"), true);
assert.equal(addClassAfterSetClassAttribute.add_class("add_class"), true);
assert.equal(addClassAfterSetClassAttribute.get_attribute("class"), "set_attribute add_class");
assert.equal(addClassAfterSetClassAttribute.get_updated_html(), '<div class="set_attribute add_class" id="first"><span></span></div>');
addClassAfterSetClassAttribute.destroy();

const addClassAfterBooleanClassAttribute = new WP_HTML_Tag_Processor('<div id="first"><span></span></div>');
assert.equal(addClassAfterBooleanClassAttribute.next_tag("div"), true);
assert.equal(addClassAfterBooleanClassAttribute.set_attribute("class", true), true);
assert.equal(addClassAfterBooleanClassAttribute.add_class("add_class"), true);
assert.equal(addClassAfterBooleanClassAttribute.get_attribute("class"), "add_class");
assert.equal(addClassAfterBooleanClassAttribute.get_updated_html(), '<div class="add_class" id="first"><span></span></div>');
addClassAfterBooleanClassAttribute.destroy();

const addEmptyClassName = new WP_HTML_Tag_Processor("<div></div>");
assert.equal(addEmptyClassName.next_tag("div"), true);
assert.equal(addEmptyClassName.add_class(null), true);
assert.equal(addEmptyClassName.add_class(""), true);
assert.equal(addEmptyClassName.get_updated_html(), "<div></div>");
addEmptyClassName.destroy();

const addEmptyClassNameWithExistingClass = new WP_HTML_Tag_Processor('<div class="0 1"></div>');
assert.equal(addEmptyClassNameWithExistingClass.next_tag("div"), true);
assert.equal(addEmptyClassNameWithExistingClass.add_class(null), true);
assert.equal(addEmptyClassNameWithExistingClass.get_updated_html(), '<div class="0 1 "></div>');
addEmptyClassNameWithExistingClass.destroy();

const addFalseClassName = new WP_HTML_Tag_Processor("<div></div>");
assert.equal(addFalseClassName.next_tag("div"), true);
assert.equal(addFalseClassName.add_class(false), true);
assert.equal(addFalseClassName.get_updated_html(), '<div class="0"></div>');
addFalseClassName.destroy();

const addNumericClassName = new WP_HTML_Tag_Processor('<div class="0 1"></div>');
assert.equal(addNumericClassName.next_tag("div"), true);
assert.equal(addNumericClassName.add_class(1), true);
assert.equal(addNumericClassName.add_class("1"), true);
assert.equal(addNumericClassName.add_class(1.5), true);
assert.equal(addNumericClassName.get_updated_html(), '<div class="0 1 1"></div>');
addNumericClassName.destroy();

for (const attributeName of ["CLASS", "Class"]) {
	const pendingAddClassAttribute = new WP_HTML_Tag_Processor('<div class="one"></div>');
	assert.equal(pendingAddClassAttribute.next_tag("div"), true);
	assert.equal(pendingAddClassAttribute.add_class("two"), true);
	assert.equal(pendingAddClassAttribute.get_attribute(attributeName), "one");
	assert.equal(pendingAddClassAttribute.get_updated_html(), '<div class="one two"></div>');
	pendingAddClassAttribute.destroy();
}

for (const attributeName of ["CLASS", "Class"]) {
	const pendingRemoveClassAttribute = new WP_HTML_Tag_Processor('<div class="one two"></div>');
	assert.equal(pendingRemoveClassAttribute.next_tag("div"), true);
	assert.equal(pendingRemoveClassAttribute.remove_class("two"), true);
	assert.equal(pendingRemoveClassAttribute.get_attribute(attributeName), "one two");
	assert.equal(pendingRemoveClassAttribute.get_attribute("class"), "one");
	pendingRemoveClassAttribute.destroy();
}

const pendingSetThenAddClassAttribute = new WP_HTML_Tag_Processor('<div class="one"></div>');
assert.equal(pendingSetThenAddClassAttribute.next_tag("div"), true);
assert.equal(pendingSetThenAddClassAttribute.set_attribute("class", "set"), true);
assert.equal(pendingSetThenAddClassAttribute.add_class("two"), true);
assert.equal(pendingSetThenAddClassAttribute.get_attribute("CLASS"), "set");
assert.equal(pendingSetThenAddClassAttribute.get_attribute("class"), "set two");
pendingSetThenAddClassAttribute.destroy();

const pendingClassPrefixAttributes = new WP_HTML_Tag_Processor("<div></div>");
assert.equal(pendingClassPrefixAttributes.next_tag("div"), true);
assert.equal(pendingClassPrefixAttributes.add_class("two"), true);
assert.deepEqual(pendingClassPrefixAttributes.get_attribute_names_with_prefix("cl"), []);
assert.equal(pendingClassPrefixAttributes.get_attribute("class"), "two");
pendingClassPrefixAttributes.destroy();

const pendingEmptyThenClassName = new WP_HTML_Tag_Processor('<div class="one"></div>');
assert.equal(pendingEmptyThenClassName.next_tag("div"), true);
assert.equal(pendingEmptyThenClassName.add_class(null), true);
assert.equal(pendingEmptyThenClassName.add_class("two"), true);
assert.equal(pendingEmptyThenClassName.get_attribute("CLASS"), "one");
assert.equal(pendingEmptyThenClassName.get_attribute("class"), "one  two");
pendingEmptyThenClassName.destroy();

const removeNumericClassName = new WP_HTML_Tag_Processor('<div class="0 1 1.5 01 +1"></div>');
assert.equal(removeNumericClassName.next_tag("div"), true);
assert.equal(removeNumericClassName.remove_class(false), true);
assert.equal(removeNumericClassName.remove_class(1), true);
assert.equal(removeNumericClassName.remove_class("1"), true);
assert.equal(removeNumericClassName.remove_class(1.5), true);
assert.equal(removeNumericClassName.get_updated_html(), '<div class="0 1 1.5 01 +1"></div>');
assert.equal(removeNumericClassName.remove_class("1.5"), true);
assert.equal(removeNumericClassName.remove_class("01"), true);
assert.equal(removeNumericClassName.get_updated_html(), '<div class="0 1 +1"></div>');
removeNumericClassName.destroy();

const stagedAttributeUpdates = new WP_HTML_Tag_Processor(
	'<hr id="remove" /><div enabled class="test">Test</div><span id="span-id"></span>',
);
assert.equal(stagedAttributeUpdates.next_tag(), true);
assert.equal(stagedAttributeUpdates.remove_attribute("id"), true);
assert.equal(stagedAttributeUpdates.next_tag(), true);
assert.equal(stagedAttributeUpdates.set_attribute("id", "div-id-1"), true);
assert.equal(stagedAttributeUpdates.add_class("new_class_1"), true);
assert.equal(
	stagedAttributeUpdates.get_updated_html(),
	'<hr  /><div id="div-id-1" enabled class="test new_class_1">Test</div><span id="span-id"></span>',
);
assert.equal(stagedAttributeUpdates.toString(), stagedAttributeUpdates.get_updated_html());
assert.equal(stagedAttributeUpdates.set_attribute("id", "div-id-2"), true);
assert.equal(stagedAttributeUpdates.add_class("new_class_2"), true);
assert.equal(
	stagedAttributeUpdates.get_updated_html(),
	'<hr  /><div id="div-id-2" enabled class="test new_class_1 new_class_2">Test</div><span id="span-id"></span>',
);
assert.equal(stagedAttributeUpdates.next_tag(), true);
assert.equal(stagedAttributeUpdates.remove_attribute("id"), true);
assert.equal(
	stagedAttributeUpdates.get_updated_html(),
	'<hr  /><div id="div-id-2" enabled class="test new_class_1 new_class_2">Test</div><span ></span>',
);
stagedAttributeUpdates.destroy();

const rawClassNameUpdates = new WP_HTML_Tag_Processor('<div class="x\uFFFDy">');
assert.equal(rawClassNameUpdates.next_tag("div"), true);
assert.equal(rawClassNameUpdates.has_class("x\0y"), false);
assert.equal(rawClassNameUpdates.add_class("x\0y"), true);
assert.equal(rawClassNameUpdates.get_updated_html(), '<div class="x\uFFFDy x\0y">');
assert.deepEqual(rawClassNameUpdates.class_list(), ["x\uFFFDy"]);
assert.equal(rawClassNameUpdates.has_class("x\0y"), false);
assert.equal(rawClassNameUpdates.remove_class("x\0y"), true);
assert.equal(rawClassNameUpdates.get_updated_html(), '<div class="x\uFFFDy">');
rawClassNameUpdates.destroy();

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

const bareLessThanText = new WP_HTML_Tag_Processor("<");
assert.equal(bareLessThanText.next_token(), true);
assert.equal(bareLessThanText.get_token_type(), "#text");
assert.equal(bareLessThanText.get_modifiable_text(), "<");
assert.equal(bareLessThanText.paused_at_incomplete_token(), false);
assert.equal(bareLessThanText.next_token(), false);
bareLessThanText.destroy();

const nonText = new WP_HTML_Tag_Processor("<div></div>");
assert.equal(nonText.next_tag("div"), true);
assert.equal(nonText.get_qualified_attribute_name("DATA-ID"), "DATA-ID");
assert.equal(nonText.get_qualified_attribute_name(123), "123");
assert.equal(nonText.get_qualified_attribute_name(false), "");
assert.equal(nonText.get_qualified_attribute_name(null), null);
assert.throws(
	() => nonText.get_qualified_attribute_name([]),
	TypeError,
);
assert.equal(nonText.get_modifiable_text(), "");
assert.equal(nonText.next_tag({ tag_name: "div", tag_closers: "visit" }), true);
assert.equal(nonText.is_tag_closer(), true);
assert.equal(nonText.get_qualified_attribute_name("DATA-ID"), "DATA-ID");
assert.equal(nonText.get_attribute_names_with_prefix([]), null);
assert.equal(nonText.has_class("active"), false);
assert.throws(
	() => nonText.has_class([]),
	TypeError,
);
assert.deepEqual(nonText.class_list(), []);
assert.equal(nonText.set_attribute("id", "x"), false);
assert.equal(nonText.set_attribute([], []), false);
assert.equal(nonText.remove_attribute([]), false);
assert.equal(nonText.add_class("active"), false);
assert.equal(nonText.add_class([]), false);
assert.equal(nonText.remove_class([]), false);
assert.throws(
	() => nonText.set_modifiable_text(null),
	TypeError,
);
assert.equal(nonText.get_updated_html(), "<div></div>");
nonText.destroy();

const tagProcessorBrEndTag = new WP_HTML_Tag_Processor("</br class=x>");
assert.equal(tagProcessorBrEndTag.next_tag({ tag_name: "br", tag_closers: "visit" }), true);
assert.equal(tagProcessorBrEndTag.is_tag_closer(), false);
assert.equal(tagProcessorBrEndTag.get_attribute_names_with_prefix([]), null);
assert.equal(tagProcessorBrEndTag.has_class("x"), false);
assert.throws(
	() => tagProcessorBrEndTag.has_class([]),
	TypeError,
);
assert.deepEqual(tagProcessorBrEndTag.class_list(), []);
assert.equal(tagProcessorBrEndTag.set_attribute([], []), false);
assert.equal(tagProcessorBrEndTag.remove_attribute([]), false);
assert.equal(tagProcessorBrEndTag.add_class([]), false);
assert.equal(tagProcessorBrEndTag.remove_class([]), false);
tagProcessorBrEndTag.destroy();

const completedClosingTag = new WP_HTML_Tag_Processor("</div>");
assert.equal(completedClosingTag.next_tag({ tag_name: "div", tag_closers: "visit" }), true);
assert.equal(completedClosingTag.is_tag_closer(), true);
assert.equal(completedClosingTag.next_tag({ tag_name: "div", tag_closers: "visit" }), false);
assert.equal(completedClosingTag.get_token_type(), null);
assert.equal(completedClosingTag.is_tag_closer(), false);
completedClosingTag.destroy();

const completedSelfClosingTag = new WP_HTML_Tag_Processor("<img />");
assert.equal(completedSelfClosingTag.next_tag("img"), true);
assert.equal(completedSelfClosingTag.has_self_closing_flag(), true);
assert.equal(completedSelfClosingTag.next_tag(), false);
assert.equal(completedSelfClosingTag.get_token_type(), null);
assert.equal(completedSelfClosingTag.has_self_closing_flag(), false);
completedSelfClosingTag.destroy();

for (const [completedTextHtml, completedTextAdvance, expectedText] of [
	["text", (processor) => processor.next_token(), "text"],
	["<!--comment-->", (processor) => processor.next_token(), "comment"],
	["<script>abc</script>", (processor) => processor.next_tag("script"), "abc"],
]) {
	const completedTextProcessor = new WP_HTML_Tag_Processor(completedTextHtml);
	assert.equal(completedTextAdvance(completedTextProcessor), true);
	assert.equal(completedTextProcessor.get_modifiable_text(), expectedText);
	assert.equal(completedTextProcessor.next_token(), false);
	assert.equal(completedTextProcessor.get_token_type(), null);
	assert.equal(completedTextProcessor.get_modifiable_text(), "");
	completedTextProcessor.destroy();
}

const coercedModifiableText = new WP_HTML_Tag_Processor("abc");
assert.equal(coercedModifiableText.next_token(), true);
assert.equal(coercedModifiableText.set_modifiable_text(123), true);
assert.equal(coercedModifiableText.get_updated_html(), "123");
assert.equal(coercedModifiableText.set_modifiable_text(false), true);
assert.equal(coercedModifiableText.get_updated_html(), "");
assert.throws(
	() => coercedModifiableText.set_modifiable_text(null),
	TypeError,
);
assert.throws(
	() => coercedModifiableText.set_modifiable_text({ text: "object" }),
	TypeError,
);
coercedModifiableText.destroy();

const svgQualifiedNames = new WP_HTML_Tag_Processor('<foreignobject attributeName=1 xlink:href=2 viewbox=3>');
assert.equal(svgQualifiedNames.change_parsing_namespace(true), false);
assert.throws(
	() => svgQualifiedNames.change_parsing_namespace(null),
	TypeError,
);
assert.equal(svgQualifiedNames.change_parsing_namespace("svg"), true);
assert.equal(svgQualifiedNames.next_tag("foreignobject"), true);
assert.equal(svgQualifiedNames.get_namespace(), "svg");
assert.equal(svgQualifiedNames.get_qualified_tag_name(), "foreignObject");
assert.equal(svgQualifiedNames.get_qualified_attribute_name("attributeName"), "attributeName");
assert.equal(svgQualifiedNames.get_qualified_attribute_name("xlink:href"), "xlink href");
assert.equal(svgQualifiedNames.get_qualified_attribute_name("viewbox"), "viewBox");
assert.equal(svgQualifiedNames.get_qualified_attribute_name("DATA-ID"), "DATA-ID");
assert.equal(svgQualifiedNames.get_qualified_attribute_name(123), "123");
assert.equal(svgQualifiedNames.get_qualified_attribute_name(null), null);
svgQualifiedNames.destroy();

const mathQualifiedNames = new WP_HTML_Tag_Processor("<mi definitionurl=1 xlink:title=2>");
assert.equal(mathQualifiedNames.change_parsing_namespace("math"), true);
assert.equal(mathQualifiedNames.next_tag("mi"), true);
assert.equal(mathQualifiedNames.get_namespace(), "math");
assert.equal(mathQualifiedNames.get_qualified_tag_name(), "mi");
assert.equal(mathQualifiedNames.get_qualified_attribute_name("definitionurl"), "definitionURL");
assert.equal(mathQualifiedNames.get_qualified_attribute_name("xlink:title"), "xlink title");
assert.equal(mathQualifiedNames.get_qualified_attribute_name("viewBox"), "viewBox");
mathQualifiedNames.destroy();

const textarea = new WP_HTML_Tag_Processor("<textarea>One</textarea>");
assert.equal(textarea.next_token(), true);
assert.equal(textarea.get_modifiable_text(), "One");
assert.equal(textarea.set_modifiable_text("Two"), true);
assert.equal(textarea.get_updated_html(), "<textarea>Two</textarea>");
textarea.destroy();

for (const [name, html, advanceTokenCount, replacement, expectedHtml] of [
	["Text node (start)", "Text", 1, "Blubber", "Blubber"],
	["Text node (middle)", "<em>Bold move</em>", 2, "yo", "<em>yo</em>"],
	["Text node (end)", "<img>of a dog", 2, "of a cat", "<img>of a cat"],
	[
		"Encoded text node",
		"<figcaption>birds and dogs</figcaption>",
		2,
		"<birds> & <dogs>",
		"<figcaption>&lt;birds&gt; &amp; &lt;dogs&gt;</figcaption>",
	],
	[
		"SCRIPT tag",
		"before<script></script>after",
		2,
		'const img = "<img> & <br>";',
		'before<script>const img = "<img> & <br>";</script>after',
	],
	[
		"STYLE tag",
		"<style></style>",
		1,
		'p::before { content: "<img> & </style>"; }',
		'<style>p::before { content: "<img> & \\3c\\2fstyle>"; }</style>',
	],
	[
		"TEXTAREA tag",
		"a<textarea>has no need to escape</textarea>b",
		2,
		"so it <doesn't>",
		"a<textarea>so it <doesn't></textarea>b",
	],
	[
		"TEXTAREA (escape)",
		"a<textarea>has no need to escape</textarea>b",
		2,
		"but it does for </textarea>",
		"a<textarea>but it does for &lt;/textarea></textarea>b",
	],
	[
		"TEXTAREA (escape+attrs)",
		"a<textarea>has no need to escape</textarea>b",
		2,
		'but it does for </textarea not an="attribute">',
		'a<textarea>but it does for &lt;/textarea not an="attribute"></textarea>b',
	],
	[
		"TITLE tag",
		"a<title>has no need to escape</title>b",
		2,
		"so it <doesn't>",
		"a<title>so it <doesn't></title>b",
	],
	[
		"TITLE (escape)",
		"a<title>has no need to escape</title>b",
		2,
		"but it does for </title>",
		"a<title>but it does for &lt;/title></title>b",
	],
	[
		"TITLE (escape+attrs)",
		"a<title>has no need to escape</title>b",
		2,
		'but it does for </title not an="attribute">',
		'a<title>but it does for &lt;/title not an="attribute"></title>b',
	],
]) {
	const modifiableText = new WP_HTML_Tag_Processor(html);
	for (let i = 0; i < advanceTokenCount; i++) {
		assert.equal(modifiableText.next_token(), true, name);
	}
	assert.equal(modifiableText.set_modifiable_text(replacement), true, name);
	assert.equal(modifiableText.get_updated_html(), expectedHtml, name);
	modifiableText.destroy();
}

for (const [name, html, invalidUpdate] of [
	["Comment with -->", "<!-- this is a comment -->", "Comments end in -->"],
	["Comment with --!>", "<!-- this is a comment -->", "Invalid but legitimate comments end in --!>"],
	[
		"Non-JS SCRIPT with <script>",
		'<script type="text/html">Replace me</script>',
		"<!-- Just a <script>",
	],
	[
		"Non-JS SCRIPT with </script>",
		'<script type="text/plain">Replace me</script>',
		"Just a </script>",
	],
	[
		"Non-JS SCRIPT with <script attributes>",
		'<script language="text">Replace me</script>',
		"<!-- <script sneaky>after",
	],
	[
		"Non-JS SCRIPT with </script attributes>",
		'<script language="text">Replace me</script>',
		"before</script sneaky>after",
	],
]) {
	const dangerousTextUpdate = new WP_HTML_Tag_Processor(html);
	while (dangerousTextUpdate.get_modifiable_text() === "" && dangerousTextUpdate.next_token()) {
		continue;
	}
	const originalText = dangerousTextUpdate.get_modifiable_text();
	assert.notEqual(originalText, "", name);
	assert.equal(dangerousTextUpdate.set_modifiable_text(invalidUpdate), false, name);
	assert.equal(dangerousTextUpdate.get_updated_html(), html, name);
	assert.equal(dangerousTextUpdate.get_modifiable_text(), originalText, name);
	dangerousTextUpdate.destroy();
}

for (const [name, html, update, expectedHtml] of [
	["Simple update", "<script></script>", "{}", "<script>{}</script>"],
	["Needs no replacement", "<script></script>", "<!--<scriptish>", "<script><!--<scriptish></script>"],
	[
		"var script;1<script>0",
		"<script></script>",
		"var script;1<script>0",
		"<script>var script;1<\\u0073cript>0</script>",
	],
	["1</script>/", "<script></script>", "1</script>/", "<script>1</\\u0073cript>/</script>"],
	[
		"var SCRIPT;1<SCRIPT>0",
		"<script></script>",
		"var SCRIPT;1<SCRIPT>0",
		"<script>var SCRIPT;1<\\u0053CRIPT>0</script>",
	],
	["1</SCRIPT>/", "<script></script>", "1</SCRIPT>/", "<script>1</\\u0053CRIPT>/</script>"],
	['"</script>"', "<script></script>", '"</script>"', '<script>"</\\u0073cript>"</script>'],
	['"</ScRiPt>"', "<script></script>", '"</ScRiPt>"', '<script>"</\\u0053cRiPt>"</script>'],
	[
		"Tricky script open tag with CR",
		"<script></script>",
		"<!-- <script\r>",
		"<script><!-- <\\u0073cript\r></script>",
	],
	[
		"Tricky script open tag with CRLF",
		"<script></script>",
		"<!-- <script\r\n>",
		"<script><!-- <\\u0073cript\r\n></script>",
	],
	[
		"Tricky script close tag with CR",
		"<script></script>",
		"// </script\r>",
		"<script>// </\\u0073cript\r></script>",
	],
	[
		"Tricky script close tag with CRLF",
		"<script></script>",
		"// </script\r\n>",
		"<script>// </\\u0073cript\r\n></script>",
	],
	[
		"Module tag",
		'<script type="module"></script>',
		'"<script>"',
		'<script type="module">"<\\u0073cript>"</script>',
	],
	[
		"Tag with type",
		'<script type="text/javascript"></script>',
		'"<script>"',
		'<script type="text/javascript">"<\\u0073cript>"</script>',
	],
	[
		"Tag with language",
		'<script language="javascript"></script>',
		'"<script>"',
		'<script language="javascript">"<\\u0073cript>"</script>',
	],
	[
		"Non-JS script, save HTML-like content",
		'<script type="text/html"></script>',
		"<h1>This & that</h1>",
		'<script type="text/html"><h1>This & that</h1></script>',
	],
]) {
	const scriptTextUpdate = new WP_HTML_Tag_Processor(html);
	assert.equal(scriptTextUpdate.next_tag("SCRIPT"), true, name);
	assert.equal(scriptTextUpdate.set_modifiable_text(update), true, name);
	assert.equal(scriptTextUpdate.get_updated_html(), expectedHtml, name);
	scriptTextUpdate.destroy();
}

const complexScriptEscaping = new WP_HTML_Tag_Processor("<script></script>\n<script></script>\n<hr>");
assert.equal(complexScriptEscaping.next_tag("SCRIPT"), true);
assert.equal(complexScriptEscaping.set_attribute("type", "importmap"), true);
const importmapData = {
	imports: {
		[String.raw`</SCRIPT>\<!--\<script>`]: "./script",
	},
};
complexScriptEscaping.set_modifiable_text(`\n${JSON.stringify(importmapData)}\n`);
assert.deepEqual(JSON.parse(complexScriptEscaping.get_modifiable_text()), importmapData);
assert.equal(complexScriptEscaping.next_tag("SCRIPT"), true);
assert.equal(complexScriptEscaping.set_attribute("type", "module"), true);
complexScriptEscaping.set_modifiable_text(String.raw`
import '</SCRIPT>\\<!--\\<script>';
`);
assert.equal(
	complexScriptEscaping.get_updated_html(),
	String.raw`<script type="importmap">
{"imports":{"</\u0053CRIPT>\\<!--\\<\u0073cript>":"./script"}}
</script>
<script type="module">
import '</\u0053CRIPT>\\<!--\\<\u0073cript>';
</script>
<hr>`,
);
const complexScriptRoundTrip = new WP_HTML_Tag_Processor(complexScriptEscaping.get_updated_html());
assert.equal(complexScriptRoundTrip.next_tag("SCRIPT"), true);
assert.equal(complexScriptRoundTrip.get_attribute("type"), "importmap");
assert.deepEqual(JSON.parse(complexScriptRoundTrip.get_modifiable_text()), importmapData);
complexScriptRoundTrip.destroy();
complexScriptEscaping.destroy();

const jsonScriptEscaping = new WP_HTML_Tag_Processor('<script type="application/json"></script>');
assert.equal(jsonScriptEscaping.next_tag("SCRIPT"), true);
const jsonText = String.raw`"Escaped BS: \\; Escaped BS+LT: \\<; Unescaped LT: <; Script closer: </script>"`;
const expectedDecodedJson = String.raw`Escaped BS: \; Escaped BS+LT: \<; Unescaped LT: <; Script closer: </script>`;
assert.equal(JSON.parse(jsonText), expectedDecodedJson);
assert.equal(jsonScriptEscaping.set_modifiable_text(`\n${jsonText}\n`), true);
assert.equal(
	jsonScriptEscaping.get_updated_html(),
	String.raw`<script type="application/json">
"Escaped BS: \\; Escaped BS+LT: \\<; Unescaped LT: <; Script closer: </\u0073cript>"
</script>`,
);
const jsonScriptRoundTrip = new WP_HTML_Tag_Processor(jsonScriptEscaping.get_updated_html());
assert.equal(jsonScriptRoundTrip.next_tag("SCRIPT"), true);
assert.equal(JSON.parse(jsonScriptRoundTrip.get_modifiable_text()), expectedDecodedJson);
jsonScriptRoundTrip.destroy();
jsonScriptEscaping.destroy();

for (const [html, expectedContentType] of [
	["<script>one</script>", "javascript"],
	['<script type="module">one</script>', "javascript"],
	['<script type="application/json">{"one":1}</script>', "json"],
	['<script type="importmap">{"imports":{}}</script>', "json"],
	['<script type="text/plain">one</script>', null],
]) {
	const script = new WP_HTML_Tag_Processor(html);
	assert.equal(script.next_tag("script"), true);
	assert.equal(script.native_get_script_content_type(), expectedContentType);
	script.destroy();
}

const completedScriptContentType = new WP_HTML_Tag_Processor("<script>one</script>");
assert.equal(completedScriptContentType.native_get_script_content_type(), null);
assert.equal(completedScriptContentType.next_tag("script"), true);
assert.equal(completedScriptContentType.native_get_script_content_type(), "javascript");
assert.equal(completedScriptContentType.next_token(), false);
assert.equal(completedScriptContentType.native_get_script_content_type(), null);
completedScriptContentType.destroy();

const foreignNamespaceScript = new WP_HTML_Tag_Processor("<script>one</script>");
assert.equal(foreignNamespaceScript.change_parsing_namespace("svg"), true);
assert.equal(foreignNamespaceScript.next_tag("script"), true);
assert.equal(foreignNamespaceScript.native_get_script_content_type(), null);
foreignNamespaceScript.destroy();

const processorScript = WP_HTML_Processor.create_fragment('<script type="application/json">{"one":1}</script>');
assert.equal(processorScript.next_tag("script"), true);
assert.equal(processorScript.native_get_script_content_type(), "json");
processorScript.destroy();

const processorForeignScript = WP_HTML_Processor.create_fragment("<svg><script></script></svg>");
assert.equal(processorForeignScript.next_tag("script"), true);
assert.equal(processorForeignScript.get_namespace(), "svg");
assert.equal(processorForeignScript.native_get_script_content_type(), null);
processorForeignScript.destroy();

const legacyNamedCharacterReference = new WP_HTML_Tag_Processor("<div>ZZ&AElig=</div>");
assert.equal(legacyNamedCharacterReference.next_token(), true);
assert.equal(legacyNamedCharacterReference.get_token_type(), "#tag");
assert.equal(legacyNamedCharacterReference.next_token(), true);
assert.equal(legacyNamedCharacterReference.get_token_type(), "#text");
assert.equal(legacyNamedCharacterReference.get_modifiable_text(), "ZZÆ=");
legacyNamedCharacterReference.destroy();

const doctype = new WP_HTML_Tag_Processor('<!DOCTYPE HTML PUBLIC "-//W3C//DTD HTML 4.01//EN" "http://www.w3.org/TR/html4/strict.dtd"><p>');
assert.equal(doctype.next_token(), true);
const doctypeInfo = doctype.get_doctype_info();
assert.ok(doctypeInfo instanceof WP_HTML_Doctype_Info);
assert.equal(doctypeInfo.name, "html");
assert.equal(doctypeInfo.public_identifier, "-//W3C//DTD HTML 4.01//EN");
assert.equal(doctypeInfo.system_identifier, "http://www.w3.org/TR/html4/strict.dtd");
assert.equal(doctypeInfo.indicated_compatibility_mode, "no-quirks");
doctype.destroy();

function assertDoctypeToken(html, expected) {
	const info = WP_HTML_Doctype_Info.from_doctype_token(html);
	assert.ok(info, `Expected parsed DOCTYPE for ${JSON.stringify(html)}`);
	assert.deepEqual(
		[
			info.indicated_compatibility_mode,
			info.name,
			info.public_identifier,
			info.system_identifier,
		],
		expected,
		html,
	);
}

for (const [html, expected] of [
	["<!DOCTYPE>", ["quirks", null, null, null]],
	["<!DOCTYPE html>", ["no-quirks", "html", null, null]],
	["<!DOCTYPEhtml>", ["no-quirks", "html", null, null]],
	[
		'<!DOCTYPE HTML PUBLIC "-//W3C//DTD HTML 4.01//EN" "http://www.w3.org/TR/html4/strict.dtd">',
		["no-quirks", "html", "-//W3C//DTD HTML 4.01//EN", "http://www.w3.org/TR/html4/strict.dtd"],
	],
	[
		'<!DOCTYPE svg PUBLIC "-//W3C//DTD SVG 1.1//EN" "http://www.w3.org/Graphics/SVG/1.1/DTD/svg11.dtd">',
		["quirks", "svg", "-//W3C//DTD SVG 1.1//EN", "http://www.w3.org/Graphics/SVG/1.1/DTD/svg11.dtd"],
	],
	[
		'<!DOCTYPE math PUBLIC "-//W3C//DTD MathML 2.0//EN" "http://www.w3.org/Math/DTD/mathml2/mathml2.dtd">',
		["quirks", "math", "-//W3C//DTD MathML 2.0//EN", "http://www.w3.org/Math/DTD/mathml2/mathml2.dtd"],
	],
	["<!DOCTYPE null-\0 PUBLIC '\0' '\0\0'>", ["quirks", "null-\uFFFD", "\uFFFD", "\uFFFD\uFFFD"]],
	["<!DOCTYPE UPPERCASE>", ["quirks", "uppercase", null, null]],
	["<!doctype lowercase>", ["quirks", "lowercase", null, null]],
	["<!DOCTYPE\n\thtml\f\rPUBLIC\r\n''\t''>", ["no-quirks", "html", "", ""]],
	[
		"<!DOCTYPE html PUBLIC '' '' Anything (except closing angle bracket) is just fine here !!!>",
		["no-quirks", "html", "", ""],
	],
	["<!dOcTyPehtml\tPublIC\"pub-id\"'sysid'>", ["no-quirks", "html", "pub-id", "sysid"]],
	["<!DOCTYPE html PUBLIC>", ["quirks", "html", null, null]],
	["<!DOCTYPE html SYSTEM>", ["quirks", "html", null, null]],
	["<!DOCTYPE html PUBLIC 'xyz>", ["quirks", "html", "xyz", null]],
	["<!DOCTYPE html SYSTEM 'xyz>", ["quirks", "html", null, "xyz"]],
	["<!DOCTYPE html PUBLIC 'abc' 'xyz>", ["quirks", "html", "abc", "xyz"]],
	["<!DOCTYPE html FOOBAR>", ["quirks", "html", null, null]],
	["<!DOCTYPE html PUBLIC x ''''>", ["quirks", "html", null, null]],
	["<!DOCTYPE html SYSTEM x ''>", ["quirks", "html", null, null]],
	[
		'<!DOCTYPE \u{1F3F4}\u{E0067}\u{E0062}\u{E0065}\u{E006E}\u{E0067}\u{E007F} PUBLIC "\u{1F525}" "\u{1F608}">',
		[
			"quirks",
			"\u{1F3F4}\u{E0067}\u{E0062}\u{E0065}\u{E006E}\u{E0067}\u{E007F}",
			"\u{1F525}",
			"\u{1F608}",
		],
	],
	["<!DOCTYPE html PUBLIC ''x''>", ["quirks", "html", "", null]],
	[
		'<!DOCTYPE html PUBLIC "-//W3C//DTD HTML 4.01 Frameset//">',
		["quirks", "html", "-//W3C//DTD HTML 4.01 Frameset//", null],
	],
	[
		'<!DOCTYPE html PUBLIC "-//W3C//DTD HTML 4.01 Frameset//" "">',
		["limited-quirks", "html", "-//W3C//DTD HTML 4.01 Frameset//", ""],
	],
]) {
	assertDoctypeToken(html, expected);
}

for (const html of [
	"",
	"<div>",
	"x<!DOCTYPE>",
	"<!DOCTYPE>x",
	"<!DOCTYPE",
	'<!DOCTYPE html PUBLIC ">">',
]) {
	assert.equal(WP_HTML_Doctype_Info.from_doctype_token(html), null, html);
}
assert.equal(WP_HTML_Doctype_Info.from_doctype_token(123), null);
assert.equal(WP_HTML_Doctype_Info.from_doctype_token(false), null);
assert.throws(
	() => WP_HTML_Doctype_Info.from_doctype_token(null),
	TypeError,
);
assert.throws(
	() => WP_HTML_Doctype_Info.from_doctype_token({ html: "<!DOCTYPE html>" }),
	TypeError,
);

const comment = new WP_HTML_Tag_Processor("<?xml-stylesheet href='x'?>");
assert.equal(comment.next_token(), true);
assert.equal(comment.get_tag(), "xml-stylesheet");
assert.equal(comment.get_full_comment_text(), "?xml-stylesheet href='x'?");
comment.destroy();

const funkyComment = new WP_HTML_Tag_Processor("</%url>");
assert.equal(funkyComment.next_token(), true);
assert.equal(funkyComment.get_token_type(), "#funky-comment");
assert.equal(funkyComment.get_comment_type(), null);
assert.equal(funkyComment.get_full_comment_text(), "%url");
funkyComment.destroy();

const processorFunkyComment = WP_HTML_Processor.create_fragment("</%url>");
assert.equal(processorFunkyComment.next_token(), true);
assert.equal(processorFunkyComment.get_token_type(), "#funky-comment");
assert.equal(processorFunkyComment.get_comment_type(), null);
assert.equal(processorFunkyComment.get_full_comment_text(), "%url");
processorFunkyComment.destroy();

for (const [html, expectedType, expectedText, expectedFullText, expectedTag] of [
	["<!-- A comment. -->", WP_HTML_Processor.COMMENT_AS_HTML_COMMENT, " A comment. ", " A comment. ", null],
	["<!-->", WP_HTML_Processor.COMMENT_AS_ABRUPTLY_CLOSED_COMMENT, "", "", null],
	["<! Bang opener >", WP_HTML_Processor.COMMENT_AS_INVALID_HTML, " Bang opener ", " Bang opener ", null],
	["<?", WP_HTML_Processor.COMMENT_AS_INVALID_HTML, "", "?", null],
	["<? Question opener >", WP_HTML_Processor.COMMENT_AS_INVALID_HTML, " Question opener ", "? Question opener ", null],
	["<![CDATA[ cdata body ]]>", WP_HTML_Processor.COMMENT_AS_CDATA_LOOKALIKE, " cdata body ", "[CDATA[ cdata body ]]", null],
	["<?pi-target Instruction body. ?>", WP_HTML_Processor.COMMENT_AS_PI_NODE_LOOKALIKE, " Instruction body. ", "?pi-target Instruction body. ?", "pi-target"],
	["<?php const HTML_COMMENT = true; ?>", WP_HTML_Processor.COMMENT_AS_PI_NODE_LOOKALIKE, " const HTML_COMMENT = true; ", "?php const HTML_COMMENT = true; ?", "php"],
]) {
	const processorComment = WP_HTML_Processor.create_fragment(html);
	assert.equal(processorComment.next_token(), true);
	assert.equal(processorComment.get_token_name(), "#comment");
	assert.equal(processorComment.get_comment_type(), expectedType);
	assert.equal(processorComment.get_modifiable_text(), expectedText);
	assert.equal(processorComment.get_full_comment_text(), expectedFullText);
	assert.equal(processorComment.get_tag(), expectedTag);
	processorComment.destroy();
}

for (const [html, expectedText] of [
	["</#", "#"],
	["</#>", "#"],
	["</# foo>", "# foo"],
	["</• bar>", "• bar"],
]) {
	const processorFunkyCommentCase = WP_HTML_Processor.create_fragment(html);
	assert.equal(processorFunkyCommentCase.next_token(), true);
	assert.equal(processorFunkyCommentCase.get_token_name(), "#funky-comment");
	assert.equal(processorFunkyCommentCase.get_modifiable_text(), expectedText);
	processorFunkyCommentCase.destroy();
}

const incompleteComment = new WP_HTML_Tag_Processor("FOO<!-- BAR --! >BAZ");
assert.equal(incompleteComment.next_token(), true);
assert.equal(incompleteComment.get_token_type(), "#text");
assert.equal(incompleteComment.get_modifiable_text(), "FOO");
assert.equal(incompleteComment.next_token(), false);
assert.equal(incompleteComment.paused_at_incomplete_token(), true);
incompleteComment.destroy();

for (const incompleteSyntax of [
	"<!--",
	"<!--x",
	"<!--x--",
	"<!--x--!",
	"<!--x--! >",
	"<![sneaky[",
	"</3 is not a tag",
	"<!DOCTYPE html",
	"<!DOCTY",
	"<![CDATA[something inside of here needs to get out",
	"<![CDA",
	"<![CDATA[cannot escape]",
	"<my-custom status=\"pending\"",
	"<iframe><div>",
	"<noembed><div>",
	"<noframes><div>",
	"<script><div>",
	"<style><div>",
	"<textarea><div>",
	"<title><div>",
	"<xmp><div>",
	"<script><div></script",
]) {
	const incompleteTokenProcessor = new WP_HTML_Tag_Processor(incompleteSyntax);
	assert.equal(incompleteTokenProcessor.next_token(), false, `${incompleteSyntax} next_token`);
	assert.equal(incompleteTokenProcessor.paused_at_incomplete_token(), true, `${incompleteSyntax} next_token paused`);
	incompleteTokenProcessor.destroy();

	const incompleteTagProcessor = new WP_HTML_Tag_Processor(incompleteSyntax);
	assert.equal(incompleteTagProcessor.next_tag(), false, `${incompleteSyntax} next_tag`);
	assert.equal(incompleteTagProcessor.paused_at_incomplete_token(), true, `${incompleteSyntax} next_tag paused`);
	incompleteTagProcessor.destroy();
}

const completedTagBookmark = new WP_HTML_Tag_Processor("<div>");
assert.equal(completedTagBookmark.next_tag("div"), true);
assert.equal(completedTagBookmark.next_tag(), false);
assert.equal(completedTagBookmark.set_bookmark("after-complete"), false);
assert.equal(completedTagBookmark.set_bookmark({}), false);
assert.equal(completedTagBookmark.has_bookmark("after-complete"), false);
completedTagBookmark.destroy();

const incompleteTagBookmark = new WP_HTML_Tag_Processor("<div");
assert.equal(incompleteTagBookmark.next_tag(), false);
assert.equal(incompleteTagBookmark.paused_at_incomplete_token(), true);
assert.equal(incompleteTagBookmark.set_bookmark("after-incomplete"), false);
assert.equal(incompleteTagBookmark.set_bookmark({}), false);
assert.equal(incompleteTagBookmark.has_bookmark("after-incomplete"), false);
incompleteTagBookmark.destroy();

const tagBookmarkLimit = new WP_HTML_Tag_Processor("<div>");
assert.equal(tagBookmarkLimit.next_tag("div"), true);
for (let i = 0; i < WP_HTML_Tag_Processor.MAX_BOOKMARKS; i += 1) {
	assert.equal(tagBookmarkLimit.set_bookmark(`tag-${i}`), true);
}
assert.equal(tagBookmarkLimit.set_bookmark("tag-over-limit"), false);
tagBookmarkLimit.destroy();

const tagBookmarkScalarNames = new WP_HTML_Tag_Processor("<div></div><span></span>");
assert.equal(tagBookmarkScalarNames.next_tag("div"), true);
assert.equal(tagBookmarkScalarNames.set_bookmark(1), true);
assert.equal(tagBookmarkScalarNames.has_bookmark("1"), true);
assert.equal(tagBookmarkScalarNames.seek("1"), true);
assert.equal(tagBookmarkScalarNames.release_bookmark(true), true);
assert.equal(tagBookmarkScalarNames.has_bookmark(1), false);
assert.equal(tagBookmarkScalarNames.set_bookmark(false), true);
assert.equal(tagBookmarkScalarNames.has_bookmark(0), true);
assert.equal(tagBookmarkScalarNames.has_bookmark(""), false);
assert.equal(tagBookmarkScalarNames.release_bookmark(0), true);
assert.equal(tagBookmarkScalarNames.set_bookmark(null), true);
assert.equal(tagBookmarkScalarNames.has_bookmark(""), true);
assert.throws(
	() => tagBookmarkScalarNames.set_bookmark({}),
	TypeError,
);
tagBookmarkScalarNames.destroy();

const repeatedSameTokenSeek = new WP_HTML_Tag_Processor("<div></div>");
assert.equal(repeatedSameTokenSeek.next_tag("div"), true);
assert.equal(repeatedSameTokenSeek.set_bookmark("here"), true);
for (let i = 0; i < WP_HTML_Tag_Processor.MAX_SEEK_OPS + 2; i += 1) {
	assert.equal(repeatedSameTokenSeek.seek("here"), true);
}
repeatedSameTokenSeek.destroy();

for (const html of [
	"<div><img target></div>",
	"<div><img target></div",
]) {
	const seekAfterEnd = new WP_HTML_Tag_Processor(html);
	let targetTag = null;
	while (seekAfterEnd.next_tag()) {
		if (seekAfterEnd.get_attribute("target") !== null) {
			assert.equal(seekAfterEnd.set_bookmark("target"), true);
			targetTag = seekAfterEnd.get_tag();
		}
	}
	assert.equal(seekAfterEnd.seek("target"), true);
	assert.equal(seekAfterEnd.get_tag(), targetTag);
	seekAfterEnd.destroy();
}

const longAttributeRemovalSeek = new WP_HTML_Tag_Processor("<button twenty_one_characters 7_chars></button><button></button>");
assert.equal(longAttributeRemovalSeek.next_tag("button"), true);
assert.equal(longAttributeRemovalSeek.set_bookmark("first"), true);
assert.equal(longAttributeRemovalSeek.next_tag("button"), true);
assert.equal(longAttributeRemovalSeek.set_bookmark("second"), true);
assert.equal(longAttributeRemovalSeek.seek("first"), true);
assert.equal(longAttributeRemovalSeek.remove_attribute("twenty_one_characters"), true);
assert.equal(longAttributeRemovalSeek.remove_attribute("7_chars"), true);
assert.equal(longAttributeRemovalSeek.seek("second"), true);
assert.equal(longAttributeRemovalSeek.get_tag(), "BUTTON");
longAttributeRemovalSeek.destroy();

const bookmarkBeforeCursorUpdate = new WP_HTML_Tag_Processor(
	"<div>outside</div><section><div><img>inside</div></section>",
);
assert.equal(bookmarkBeforeCursorUpdate.next_tag(), true);
assert.equal(bookmarkBeforeCursorUpdate.add_class("foo"), true);
assert.equal(bookmarkBeforeCursorUpdate.next_tag("section"), true);
assert.equal(bookmarkBeforeCursorUpdate.set_bookmark("here"), true);
assert.equal(bookmarkBeforeCursorUpdate.next_tag("img"), true);
assert.equal(bookmarkBeforeCursorUpdate.seek("here"), true);
assert.equal(
	bookmarkBeforeCursorUpdate.get_updated_html(),
	'<div class="foo">outside</div><section><div><img>inside</div></section>',
);
assert.equal(bookmarkBeforeCursorUpdate.get_tag(), "SECTION");
assert.equal(bookmarkBeforeCursorUpdate.is_tag_closer(), false);
bookmarkBeforeCursorUpdate.destroy();

const bookmarkAdditionsAfterBothSides = new WP_HTML_Tag_Processor("<div>First</div><div>Second</div>");
assert.equal(bookmarkAdditionsAfterBothSides.next_tag(), true);
assert.equal(bookmarkAdditionsAfterBothSides.set_attribute("id", "one"), true);
assert.equal(bookmarkAdditionsAfterBothSides.set_bookmark("first"), true);
assert.equal(bookmarkAdditionsAfterBothSides.next_tag(), true);
assert.equal(bookmarkAdditionsAfterBothSides.set_attribute("id", "two"), true);
assert.equal(bookmarkAdditionsAfterBothSides.add_class("second"), true);
assert.equal(bookmarkAdditionsAfterBothSides.seek("first"), true);
assert.equal(bookmarkAdditionsAfterBothSides.add_class("first"), true);
assert.equal(bookmarkAdditionsAfterBothSides.get_attribute("id"), "one");
assert.equal(bookmarkAdditionsAfterBothSides.get_updated_html(), '<div class="first" id="one">First</div><div class="second" id="two">Second</div>');
bookmarkAdditionsAfterBothSides.destroy();

const bookmarkAdditionsBeforeBothSides = new WP_HTML_Tag_Processor("<div>First</div><div>Second</div>");
assert.equal(bookmarkAdditionsBeforeBothSides.next_tag(), true);
assert.equal(bookmarkAdditionsBeforeBothSides.set_bookmark("first"), true);
assert.equal(bookmarkAdditionsBeforeBothSides.next_tag(), true);
assert.equal(bookmarkAdditionsBeforeBothSides.set_bookmark("second"), true);
assert.equal(bookmarkAdditionsBeforeBothSides.seek("first"), true);
assert.equal(bookmarkAdditionsBeforeBothSides.add_class("first"), true);
assert.equal(bookmarkAdditionsBeforeBothSides.seek("second"), true);
assert.equal(bookmarkAdditionsBeforeBothSides.add_class("second"), true);
assert.equal(bookmarkAdditionsBeforeBothSides.get_updated_html(), '<div class="first">First</div><div class="second">Second</div>');
bookmarkAdditionsBeforeBothSides.destroy();

const bookmarkDeletionsAfterBothSides = new WP_HTML_Tag_Processor("<div>First</div><div disabled>Second</div>");
assert.equal(bookmarkDeletionsAfterBothSides.next_tag(), true);
assert.equal(bookmarkDeletionsAfterBothSides.set_bookmark("first"), true);
assert.equal(bookmarkDeletionsAfterBothSides.next_tag(), true);
assert.equal(bookmarkDeletionsAfterBothSides.remove_attribute("disabled"), true);
assert.equal(bookmarkDeletionsAfterBothSides.seek("first"), true);
assert.equal(bookmarkDeletionsAfterBothSides.set_attribute("untouched", true), true);
assert.equal(bookmarkDeletionsAfterBothSides.get_updated_html(), "<div untouched>First</div><div >Second</div>");
bookmarkDeletionsAfterBothSides.destroy();

const bookmarkDeletionsBeforeBothSides = new WP_HTML_Tag_Processor("<div disabled>First</div><div>Second</div>");
assert.equal(bookmarkDeletionsBeforeBothSides.next_tag(), true);
assert.equal(bookmarkDeletionsBeforeBothSides.set_bookmark("first"), true);
assert.equal(bookmarkDeletionsBeforeBothSides.next_tag(), true);
assert.equal(bookmarkDeletionsBeforeBothSides.set_bookmark("second"), true);
assert.equal(bookmarkDeletionsBeforeBothSides.seek("first"), true);
assert.equal(bookmarkDeletionsBeforeBothSides.remove_attribute("disabled"), true);
assert.equal(bookmarkDeletionsBeforeBothSides.seek("second"), true);
assert.equal(bookmarkDeletionsBeforeBothSides.set_attribute("safe", true), true);
assert.equal(bookmarkDeletionsBeforeBothSides.get_updated_html(), "<div >First</div><div safe>Second</div>");
bookmarkDeletionsBeforeBothSides.destroy();

const processor = WP_HTML_Processor.create_fragment("<img><p>Hi");
assert.equal(processor.next_tag("p"), true);
assert.equal(processor.expects_closer(), true);
assert.equal(processor.get_qualified_attribute_name("DATA-ID"), "DATA-ID");
assert.equal(processor.get_qualified_attribute_name(null), null);
assert.deepEqual(processor.get_breadcrumbs(), ["HTML", "BODY", "P"]);
assert.equal(processor.matches_breadcrumbs([]), true);
assert.equal(processor.matches_breadcrumbs([true]), false);
assert.equal(processor.matches_breadcrumbs([1]), false);
assert.equal(processor.matches_breadcrumbs([null]), false);
assert.throws(
	() => processor.matches_breadcrumbs("P"),
	TypeError,
);
assert.throws(
	() => processor.matches_breadcrumbs(["BODY", {}]),
	TypeError,
);
processor.destroy();

for (const [html, breadcrumbs, expected] of [
	["<div><span><figure><img target></figure></span></div>", ["span", "figure", "img"], true],
	["<div><span><figure><img target></figure></span></div>", ["span", "*", "img"], true],
	["<div><span><figure><img target></figure></span></div>", ["span", "img"], false],
	["<div><span><figure><img target></figure></span></div>", ["html", "body", "div", "span", "figure", "img"], true],
	["<div><span><figure><img target></figure></span></div>", ["html", "div", "span", "figure", "img"], false],
	["<div><span><figure><p target></figure></span></div>", ["span", "figure", "p"], true],
	["<div><span><figure><p target></figure></span></div>", ["span", "*", "p"], true],
	["<div><span><figure><p target></figure></span></div>", ["span", "p"], false],
	["<div><span><figure><p target></figure></span></div>", ["html", "body", "div", "span", "figure", "p"], true],
	["<div><span><figure><p target></figure></span></div>", ["html", "div", "span", "figure", "p"], false],
	["<div><span><figure></p target></figure></span></div>", ["span", "figure", "p"], false],
	["<figure><code><div><p><span><img target></span></p></div></code></figure>", ["*"], true],
	["<figure><code><div><p><span><img target></span></p></div></code></figure>", ["SPAN", "*"], true],
]) {
	const breadcrumbsProcessor = WP_HTML_Processor.create_fragment(html);
	while (breadcrumbsProcessor.next_tag() && breadcrumbsProcessor.get_attribute("target") === null) {
	}
	assert.equal(breadcrumbsProcessor.matches_breadcrumbs(breadcrumbs), expected);
	breadcrumbsProcessor.destroy();
}

for (const [html, expectedDepth] of [
	['<div class="target">', 3],
	['<div><span><p><b><em class="target">', 7],
	['<div><span></span><span class="target"></div>', 4],
]) {
	const elementDepthProcessor = WP_HTML_Processor.create_fragment(html);
	assert.equal(elementDepthProcessor.next_tag({ class_name: "target" }), true);
	assert.equal(elementDepthProcessor.get_current_depth(), expectedDepth);
	elementDepthProcessor.destroy();
}

for (const [html, expectedDepth] of [
	['<div class="target">One Deeper', 4],
	['<div><span><p><b><em class="target">Formatted', 8],
	['<div>a<span>b<p>c<b>e<em class="target">e', 8],
	['<div><span></span><span class="target">Here</div>', 5],
	['<p>Before<img class="target">After</p>', 4],
	['<img class="target"><!-- this is inside the BODY -->', 3],
	['<div class="target"><!-- this is inside the BODY -->', 4],
	['<div><p>What <br class="target"><//wp:post-author></p></div>', 5],
]) {
	const nextNodeDepthProcessor = WP_HTML_Processor.create_fragment(html);
	assert.equal(nextNodeDepthProcessor.next_tag({ class_name: "target" }), true);
	assert.equal(nextNodeDepthProcessor.next_token(), true);
	assert.equal(nextNodeDepthProcessor.get_current_depth(), expectedDepth);
	nextNodeDepthProcessor.destroy();
}

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

const processorNonStringClassQuery = WP_HTML_Processor.create_fragment('<div class="x"></div><span></span>');
assert.equal(processorNonStringClassQuery.next_tag({ class_name: {} }), true);
assert.equal(processorNonStringClassQuery.get_tag(), "DIV");
processorNonStringClassQuery.destroy();

const processorNumericTagName = WP_HTML_Processor.create_fragment("<div></div>");
assert.equal(processorNumericTagName.next_tag({ tag_name: 1 }), false);
assert.equal(processorNumericTagName.get_tag(), null);
processorNumericTagName.destroy();

const processorBooleanTagName = WP_HTML_Processor.create_fragment("<true></true><div></div>");
assert.equal(processorBooleanTagName.next_tag({ tag_name: true }), false);
assert.equal(processorBooleanTagName.get_tag(), null);
processorBooleanTagName.destroy();

const processorObjectTagName = WP_HTML_Processor.create_fragment("<div></div>");
assert.throws(
	() => processorObjectTagName.next_tag({ tag_name: {} }),
	TypeError,
);
processorObjectTagName.destroy();

const processorBreadcrumbMatchOffset = WP_HTML_Processor.create_fragment("<div><span one></span><span two></span></div>");
assert.equal(processorBreadcrumbMatchOffset.next_tag({ breadcrumbs: ["DIV", "SPAN"], match_offset: "2nd" }), true);
assert.equal(processorBreadcrumbMatchOffset.get_attribute("one"), null);
assert.equal(processorBreadcrumbMatchOffset.get_attribute("two"), true);
processorBreadcrumbMatchOffset.destroy();

const processorObjectMatchOffset = WP_HTML_Processor.create_fragment("<div><span one></span><span two></span></div>");
assert.equal(processorObjectMatchOffset.next_tag({ breadcrumbs: ["DIV", "SPAN"], match_offset: {} }), true);
assert.equal(processorObjectMatchOffset.get_attribute("one"), true);
assert.equal(processorObjectMatchOffset.get_attribute("two"), null);
processorObjectMatchOffset.destroy();

const processorArrayMatchOffset = WP_HTML_Processor.create_fragment("<div><span one></span><span two></span></div>");
assert.equal(processorArrayMatchOffset.next_tag({ breadcrumbs: ["DIV", "SPAN"], match_offset: [2] }), true);
assert.equal(processorArrayMatchOffset.get_attribute("one"), true);
assert.equal(processorArrayMatchOffset.get_attribute("two"), null);
processorArrayMatchOffset.destroy();

const processorNullBreadcrumb = WP_HTML_Processor.create_fragment("<div><span></span></div>");
assert.equal(processorNullBreadcrumb.next_tag({ breadcrumbs: [null] }), false);
processorNullBreadcrumb.destroy();

const processorBreadcrumbIgnoresTagName = WP_HTML_Processor.create_fragment("<span></span><div></div>");
assert.equal(processorBreadcrumbIgnoresTagName.next_tag({ tag_name: "span", breadcrumbs: ["DIV"] }), true);
assert.equal(processorBreadcrumbIgnoresTagName.get_tag(), "DIV");
processorBreadcrumbIgnoresTagName.destroy();

const processorZeroBreadcrumbMatchOffset = WP_HTML_Processor.create_fragment("<div><span></span></div>");
assert.equal(processorZeroBreadcrumbMatchOffset.next_tag({ breadcrumbs: ["DIV", "SPAN"], match_offset: 0 }), false);
assert.equal(processorZeroBreadcrumbMatchOffset.get_tag(), null);
processorZeroBreadcrumbMatchOffset.destroy();

for (const [html, expectedBreadcrumbs] of [
	["<p><p><p><p><article target>", ["HTML", "BODY", "ARTICLE"]],
	["<li><li><blockquote><li target>", ["HTML", "BODY", "LI", "BLOCKQUOTE", "LI"]],
	["<li><address><li target>", ["HTML", "BODY", "LI"]],
	["<dt><dt><div><dt target>", ["HTML", "BODY", "DT"]],
	["<dd><dd><p><button><p><dd target>", ["HTML", "BODY", "DD", "P", "BUTTON", "DD"]],
]) {
	const semanticRuleProcessor = WP_HTML_Processor.create_fragment(html);
	while (semanticRuleProcessor.next_tag() && semanticRuleProcessor.get_attribute("target") === null) {
	}
	assert.equal(semanticRuleProcessor.get_attribute("target"), true, html);
	assert.deepEqual(semanticRuleProcessor.get_breadcrumbs(), expectedBreadcrumbs, html);
	semanticRuleProcessor.destroy();
}

const semanticButtonProcessor = WP_HTML_Processor.create_fragment(
	'<div><button one><p>Click <span><button two>here</button>!</span></p></div><button three>done</button>',
);
assert.equal(semanticButtonProcessor.next_tag("BUTTON"), true);
assert.equal(semanticButtonProcessor.get_attribute("one"), true);
assert.deepEqual(semanticButtonProcessor.get_breadcrumbs(), ["HTML", "BODY", "DIV", "BUTTON"]);
assert.equal(semanticButtonProcessor.next_tag("BUTTON"), true);
assert.equal(semanticButtonProcessor.get_attribute("two"), true);
assert.deepEqual(semanticButtonProcessor.get_breadcrumbs(), ["HTML", "BODY", "DIV", "BUTTON"]);
assert.equal(semanticButtonProcessor.next_tag("BUTTON"), true);
assert.equal(semanticButtonProcessor.get_attribute("three"), true);
assert.deepEqual(semanticButtonProcessor.get_breadcrumbs(), ["HTML", "BODY", "BUTTON"]);
semanticButtonProcessor.destroy();

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

const processorQualifiedNames = WP_HTML_Processor.create_fragment(
	"<svg><foreignobject attributeName=1 xlink:href=2 viewbox=3><math><mi definitionurl=4 xlink:title=5></mi></math></foreignobject></svg>",
);
assert.equal(processorQualifiedNames.next_tag("foreignobject"), true);
assert.equal(processorQualifiedNames.get_namespace(), "svg");
assert.equal(processorQualifiedNames.get_qualified_tag_name(), "foreignObject");
assert.equal(processorQualifiedNames.get_qualified_attribute_name("xlink:href"), "xlink href");
assert.equal(processorQualifiedNames.get_qualified_attribute_name("viewbox"), "viewBox");
assert.equal(processorQualifiedNames.next_tag("mi"), true);
assert.equal(processorQualifiedNames.get_namespace(), "math");
assert.equal(processorQualifiedNames.get_qualified_tag_name(), "mi");
assert.equal(processorQualifiedNames.get_qualified_attribute_name("definitionurl"), "definitionURL");
assert.equal(processorQualifiedNames.get_qualified_attribute_name("xlink:title"), "xlink title");
processorQualifiedNames.destroy();

const processorManualNamespace = WP_HTML_Processor.create_fragment("<rect />");
assert.equal(processorManualNamespace.get_namespace(), "html");
assert.equal(processorManualNamespace.change_parsing_namespace("svg"), true);
assert.equal(processorManualNamespace.get_namespace(), "svg");
assert.equal(processorManualNamespace.change_parsing_namespace("invalid"), false);
assert.throws(
	() => processorManualNamespace.change_parsing_namespace(null),
	TypeError,
);
assert.equal(processorManualNamespace.get_namespace(), "svg");
assert.equal(processorManualNamespace.next_tag("rect"), true);
assert.equal(processorManualNamespace.get_namespace(), "svg");
assert.equal(processorManualNamespace.get_qualified_tag_name(), "rect");
assert.equal(processorManualNamespace.has_self_closing_flag(), true);
assert.equal(processorManualNamespace.expects_closer(), false);
processorManualNamespace.destroy();

const processorCoercedModifiableText = WP_HTML_Processor.create_fragment("abc");
assert.equal(processorCoercedModifiableText.next_token(), true);
assert.equal(processorCoercedModifiableText.get_token_type(), "#text");
assert.equal(processorCoercedModifiableText.set_modifiable_text(456), true);
assert.equal(processorCoercedModifiableText.get_updated_html(), "456");
assert.throws(
	() => processorCoercedModifiableText.set_modifiable_text(null),
	TypeError,
);
processorCoercedModifiableText.destroy();

assert.equal(WP_HTML_Processor.PROCESS_NEXT_NODE, "process-next-node");
assert.equal(WP_HTML_Processor.REPROCESS_CURRENT_NODE, "reprocess-current-node");
assert.equal(WP_HTML_Processor.PROCESS_CURRENT_NODE, "process-current-node");
assert.equal(WP_HTML_Processor.ERROR_UNSUPPORTED, "unsupported");
assert.equal(WP_HTML_Processor.ERROR_EXCEEDED_MAX_BOOKMARKS, "exceeded-max-bookmarks");
assert.equal(WP_HTML_Processor.MAX_BOOKMARKS, 10000);
const directFullParser = new WP_HTML_Processor("<p>Direct");
assert.equal(directFullParser.next_tag(), true);
assert.equal(directFullParser.get_tag(), "HTML");
assert.deepEqual(directFullParser.get_breadcrumbs(), ["HTML"]);
directFullParser.destroy();
const directUnlockedFullParser = new WP_HTML_Processor(
	"<p>Direct",
	WP_HTML_Processor.CONSTRUCTOR_UNLOCK_CODE,
);
assert.equal(directUnlockedFullParser.next_tag(), true);
assert.equal(directUnlockedFullParser.get_tag(), "HTML");
assert.deepEqual(directUnlockedFullParser.get_breadcrumbs(), ["HTML"]);
directUnlockedFullParser.destroy();
const directNullProcessor = new WP_HTML_Processor(null);
assert.equal(directNullProcessor.next_tag(), false);
directNullProcessor.destroy();
assert.equal(WP_HTML_Processor.create_fragment(null), null);
assert.equal(WP_HTML_Processor.create_fragment("", "<body>", "ISO-8859-1"), null);
assert.equal(WP_HTML_Processor.create_fragment("", ""), null);
assert.equal(WP_HTML_Processor.create_fragment("", "<br>"), null);
assert.equal(WP_HTML_Processor.create_fragment("", null), null);
assert.equal(WP_HTML_Processor.create_fragment("", "<body>", {}), null);
const emptyTextareaFragment = WP_HTML_Processor.create_fragment("", "<textarea>");
assert.notEqual(emptyTextareaFragment, null);
emptyTextareaFragment.destroy();
assert.equal(WP_HTML_Processor.create_full_parser(null), null);
assert.equal(WP_HTML_Processor.create_full_parser("", "ISO-8859-1"), null);
assert.equal(WP_HTML_Processor.create_full_parser("", {}), null);
assert.equal(WP_HTML_Processor.normalize(123), "123");
assert.equal(WP_HTML_Processor.normalize(false), "");
assert.throws(
	() => WP_HTML_Processor.normalize(null),
	TypeError,
);
assert.throws(
	() => WP_HTML_Processor.normalize({ html: "<p>object" }),
	TypeError,
);

class Custom_HTML_Processor extends WP_HTML_Processor {
	custom_method() {
		return "custom";
	}
}
const customFragmentProcessor = Custom_HTML_Processor.create_fragment("<div>");
assert.ok(customFragmentProcessor instanceof Custom_HTML_Processor);
assert.equal(customFragmentProcessor.custom_method(), "custom");
assert.equal(customFragmentProcessor.next_tag("div"), true);
customFragmentProcessor.destroy();
const customFullParser = Custom_HTML_Processor.create_full_parser("<!doctype html><p>");
assert.ok(customFullParser instanceof Custom_HTML_Processor);
assert.equal(customFullParser.next_tag("p"), true);
customFullParser.destroy();
assert.equal(Custom_HTML_Processor.normalize("<div>"), "<div></div>");

class Token_Counting_HTML_Processor extends WP_HTML_Processor {
	token_seen_count = new Map();

	next_token() {
		if (!super.next_token()) {
			return false;
		}

		this.token_seen_count.set(
			this.get_token_name(),
			(this.token_seen_count.get(this.get_token_name()) ?? 0) + 1,
		);
		return true;
	}
}
const tokenCountingProcessor = Token_Counting_HTML_Processor.create_full_parser(
	"<!DOCTYPE html><html><head><title>One</title></head><body><p>Two</p></body></html>",
);
while (tokenCountingProcessor.next_tag()) {
}
assert.ok(tokenCountingProcessor.token_seen_count.get("HTML") >= 1);
assert.ok(tokenCountingProcessor.token_seen_count.get("HEAD") >= 1);
assert.ok(tokenCountingProcessor.token_seen_count.get("BODY") >= 1);
assert.ok(tokenCountingProcessor.token_seen_count.get("P") >= 1);
tokenCountingProcessor.destroy();

assert.equal(WP_HTML_Processor.is_void("img"), true);
assert.equal(WP_HTML_Processor.is_void(123), false);
assert.equal(WP_HTML_Processor.is_void(null), false);
assert.throws(
	() => WP_HTML_Processor.is_void([]),
	TypeError,
);
assert.equal(WP_HTML_Processor.is_special("div"), true);
assert.equal(WP_HTML_Processor.is_special("span"), false);
assert.equal(WP_HTML_Processor.is_special("dialog"), false);
assert.equal(WP_HTML_Processor.is_special("math mi"), false);
assert.equal(WP_HTML_Processor.is_special(null), false);
assert.equal(WP_HTML_Processor.is_special(true), false);
assert.equal(WP_HTML_Processor.is_special(1), false);
assert.equal(WP_HTML_Processor.is_special([]), false);
assert.equal(WP_HTML_Processor.is_special({ namespace: "math", node_name: "mi" }), false);
assert.equal(WP_HTML_Processor.is_special({ namespace: "math", node_name: "MI" }), true);
assert.equal(WP_HTML_Processor.is_special({ namespace: "svg", node_name: "foreignObject" }), false);
assert.equal(WP_HTML_Processor.is_special({ namespace: "svg", node_name: "FOREIGNOBJECT" }), true);
assert.equal(WP_HTML_Processor.is_special({ namespace: "SVG", node_name: "FOREIGNOBJECT" }), false);
assert.equal(WP_HTML_Processor.is_special({ namespace: "math", node_name: 1 }), false);
assert.equal(WP_HTML_Processor.is_special({ node_name: "DIV" }), false);
assert.equal(WP_HTML_Processor.is_special({ namespace: null, node_name: "DIV" }), false);
assert.equal(WP_HTML_Processor.is_special({ namespace: "html", node_name: null }), false);
assert.equal(WP_HTML_Processor.is_special({ namespaceName: "svg", tagName: "foreignObject" }), true);
assert.throws(
	() => WP_HTML_Processor.is_special({ namespace: "math", node_name: {} }),
	TypeError,
);
assert.throws(
	() => WP_HTML_Processor.is_special({ namespace: {}, node_name: "MI" }),
	TypeError,
);

for (const [html, context, expected] of [
	["<span>x", "<div>", "<span>x</span>"],
	["<body><span>", "<body>", "<span></span>"],
	["<span><body>", "<body>", "<span></span>"],
	["<frameset><span>", "<body>", "<span></span>"],
	["<span><frameset>", "<body>", "<span></span>"],
	["<body><span>", "<div>", "<span></span>"],
	["<span><body>", "<div>", "<span></span>"],
	["<frameset><span>", "<div>", "<span></span>"],
	["<span><frameset>", "<div>", "<span></span>"],
	[
		"textarea content with <em>pseudo</em> <foo>markup",
		"<textarea>",
		"textarea content with &lt;em&gt;pseudo&lt;/em&gt; &lt;foo&gt;markup",
	],
	["setting html's innerHTML", "<html>", "<head></head><body>setting html&apos;s innerHTML</body>"],
	["<body><span>", "<html>", "<head></head><body><span></span></body>"],
	["<frameset><span>", "<html>", "<head></head><frameset></frameset>"],
	["</html><!--abc-->", "<html>", "<head></head><body></body><!--abc-->"],
	["", "<html>", "<head></head><body></body>"],
	["<title>setting head's innerHTML</title>", "<head>", "<title>setting head&apos;s innerHTML</title>"],
	["direct <title> content", "<title>", "direct &lt;title&gt; content"],
	["this is &#x0043;DATA inside a <style> element", "<style>", "this is &#x0043;DATA inside a <style> element"],
	["<!-- inside </script> -->", "<script>", "<!-- inside </script> -->"],
	["</plaintext>", "<plaintext>", "</plaintext>"],
	["</frameset><frame>", "<frameset>", "<frame>"],
	["<td>cell", "<tr>", "<td>cell</td>"],
	["<tr><td>cell", "<table>", "<tbody><tr><td>cell</td></tr></tbody>"],
	["<table><tr>", "<table>", "<tbody><tr></tr></tbody>"],
	["foo<col>", "<colgroup>", "<col>"],
	["<span><td><span>", "<caption>", "<span><span></span></span>"],
	["<caption><td>", "<tr>", "<td></td>"],
	["<tbody><td>", "<tr>", "<td></td>"],
	["<tr><td>", "<tr>", "<td></td>"],
	["<caption><col><colgroup><tbody><tfoot><thead><tr>", "<tbody>", "<tr></tr>"],
	[
		'<template><form><input name="q"></form><div>second</div></template>',
		"<template>",
		'<template><form><input name="q"></form><div>second</div></template>',
	],
	["<option>one", "<select>", "<option>one</option>"],
	["<input><option>", "<select>", "<option></option>"],
	["<keygen><option>", "<select>", "<option></option>"],
	["<rect />", "<svg>", "<rect />"],
	["<circle />", "<svg><g>", "<circle />"],
	["<nobr>X", "<svg><path>", "<nobr>X</nobr>"],
	["<g></path>X", "<svg><path>", "<g>X</g>"],
	["</path>X", "<svg><path>", "X"],
	["<frameset>X", "<svg><desc>", "X"],
	["<body class='foo'>X", "<svg><desc>", "X"],
	["<html class='foo'>X", "<svg><desc>", "X"],
	["<mi>x", "<math>", "<mi>x</mi>"],
]) {
	const contextProcessor = WP_HTML_Processor.create_fragment(html, context);
	assert.notEqual(contextProcessor, null, `Should create fragment in ${context}`);
	assert.equal(contextProcessor.serialize(), expected, `Should serialize fragment in ${context}`);
	contextProcessor.destroy();
}

for (const [html, context, expectedText, expectedSerialized] of [
	["A &amp;\0 B", "<textarea>", "A &\uFFFD B", "A &amp;\uFFFD B"],
	["A &lt;\0 B", "<title>", "A <\uFFFD B", "A &lt;\uFFFD B"],
	["A &amp;\0 B", "<script>", "A &amp;\uFFFD B", "A &amp;\uFFFD B"],
	["A &amp;\0 B", "<style>", "A &amp;\uFFFD B", "A &amp;\uFFFD B"],
	["A &amp;\0 B", "<plaintext>", "A &amp;\uFFFD B", "A &amp;\uFFFD B"],
]) {
	const rawTextFragmentTokenProcessor = WP_HTML_Processor.create_fragment(html, context);
	assert.notEqual(rawTextFragmentTokenProcessor, null);
	assert.equal(rawTextFragmentTokenProcessor.next_token(), true);
	assert.equal(rawTextFragmentTokenProcessor.get_token_type(), "#text");
	assert.equal(rawTextFragmentTokenProcessor.get_modifiable_text(), expectedText);
	assert.equal(rawTextFragmentTokenProcessor.serialize_token(), expectedSerialized);
	assert.equal(rawTextFragmentTokenProcessor.next_token(), false);
	rawTextFragmentTokenProcessor.destroy();

	const rawTextFragmentSerializeProcessor = WP_HTML_Processor.create_fragment(html, context);
	assert.notEqual(rawTextFragmentSerializeProcessor, null);
	assert.equal(rawTextFragmentSerializeProcessor.serialize(), expectedSerialized);
	rawTextFragmentSerializeProcessor.destroy();
}

for (const [context, expectedUpdatedHtml] of [
	["<textarea>", "Updated &amp; &lt;\uFFFD"],
	["<title>", "Updated &amp; &lt;\uFFFD"],
	["<script>", "Updated & <\uFFFD"],
	["<style>", "Updated & <\uFFFD"],
	["<plaintext>", "Updated & <\uFFFD"],
]) {
	const rawTextFragmentMutationProcessor = WP_HTML_Processor.create_fragment("Original", context);
	assert.notEqual(rawTextFragmentMutationProcessor, null);
	assert.equal(rawTextFragmentMutationProcessor.next_token(), true);
	assert.equal(rawTextFragmentMutationProcessor.set_modifiable_text("Updated & <\0"), true);
	assert.equal(rawTextFragmentMutationProcessor.get_modifiable_text(), "Updated & <\uFFFD");
	assert.equal(rawTextFragmentMutationProcessor.serialize_token(), expectedUpdatedHtml);
	assert.equal(rawTextFragmentMutationProcessor.get_updated_html(), expectedUpdatedHtml);
	rawTextFragmentMutationProcessor.destroy();
}

const explicitTokenExpectationsProcessor = WP_HTML_Processor.create_fragment("");
assert.equal(explicitTokenExpectationsProcessor.expects_closer(new WP_HTML_Token(null, "img", false)), false);
assert.equal(explicitTokenExpectationsProcessor.expects_closer(new WP_HTML_Token(null, "div", false)), true);
assert.equal(explicitTokenExpectationsProcessor.expects_closer(new WP_HTML_Token(null, "TITLE", false)), false);
assert.equal(explicitTokenExpectationsProcessor.expects_closer(new WP_HTML_Token(null, "#text", false)), false);
assert.equal(explicitTokenExpectationsProcessor.expects_closer(new WP_HTML_Token(null, "html", false)), false);
const selfClosingSvgToken = new WP_HTML_Token(null, "rect", true);
selfClosingSvgToken.namespace = "svg";
assert.equal(explicitTokenExpectationsProcessor.expects_closer(selfClosingSvgToken), false);
const openSvgToken = new WP_HTML_Token(null, "rect", false);
openSvgToken.namespace = "svg";
assert.equal(explicitTokenExpectationsProcessor.expects_closer(openSvgToken), true);
assert.throws(
	() => explicitTokenExpectationsProcessor.expects_closer({ node_name: "img", namespace: "html" }),
	TypeError,
);
assert.throws(
	() => explicitTokenExpectationsProcessor.expects_closer(["img"]),
	TypeError,
);
assert.throws(
	() => explicitTokenExpectationsProcessor.expects_closer(true),
	TypeError,
);
explicitTokenExpectationsProcessor.destroy();

for (const [html, expected] of [
	["", null],
	["<!-- comment -->", false],
	["<!-- comment --!>", false],
	["<![CDATA[ comment ]]>", false],
	["<?ok comment ?>", false],
	["<//wp:post-meta key=isbn>", false],
	["Trombone", false],
	["<img>", false],
	["<source>", false],
	["<script>content</script>", false],
	["<textarea>content</textarea>", false],
	["<div>", true],
	["<p>", true],
]) {
	const currentTokenExpectationsProcessor = WP_HTML_Processor.create_fragment(html);
	if (html !== "") {
		assert.equal(currentTokenExpectationsProcessor.next_token(), true);
	}
	assert.equal(currentTokenExpectationsProcessor.expects_closer(), expected);
	currentTokenExpectationsProcessor.destroy();
}

for (const html of [
	"<!DOCTYPE html><meta>",
	'<!DOCTYPE html><meta not-charset="OK">',
	"<!DOCTYPE html><meta charset>",
	'<!DOCTYPE html><meta http-equiv="accept" content="">',
	'<!DOCTYPE html><meta http-equiv="content-type">',
	'<!DOCTYPE html><meta http-equiv content="">',
]) {
	const supportedMetaProcessor = new WP_HTML_Processor(html, { fullParser: true });
	assert.equal(supportedMetaProcessor.next_tag("meta"), true, html);
	assert.equal(supportedMetaProcessor.get_last_error(), null, html);
	supportedMetaProcessor.destroy();
}

for (const [html, message] of [
	['<!DOCTYPE html><meta charset="utf8">', "Cannot yet process META tags with charset to determine encoding."],
	['<!DOCTYPE html><meta CHARSET="utf8">', "Cannot yet process META tags with charset to determine encoding."],
	['<!DOCTYPE html><meta http-equiv="content-type" content="">', "Cannot yet process META tags with http-equiv Content-Type to determine encoding."],
	['<!DOCTYPE html><meta http-equiv="Content-Type" content="UTF-8">', "Cannot yet process META tags with http-equiv Content-Type to determine encoding."],
]) {
	const supportedMetaProcessor = WP_HTML_Processor.create_full_parser(html);
	assert.equal(supportedMetaProcessor.next_tag("meta"), true);
	assert.equal(supportedMetaProcessor.get_last_error(), null);
	supportedMetaProcessor.destroy();

	const unsupportedMetaProcessor = new WP_HTML_Processor(html, { fullParser: true });
	assert.equal(unsupportedMetaProcessor.next_tag("meta"), false);
	assert.equal(unsupportedMetaProcessor.get_last_error(), WP_HTML_Processor.ERROR_UNSUPPORTED);
	const exception = unsupportedMetaProcessor.get_unsupported_exception();
	assert.ok(exception instanceof WP_HTML_Unsupported_Exception);
	assert.ok(exception instanceof Error);
	const tokenAt = html.indexOf("<meta");
	assert.equal(exception.message, message);
	assert.equal(exception.token_name, "META");
	assert.equal(exception.token_at, tokenAt);
	assert.equal(exception.token, html.slice(tokenAt));
	assert.deepEqual(exception.stack_of_open_elements, ["HTML", "HEAD"]);
	assert.deepEqual(exception.active_formatting_elements, []);
	unsupportedMetaProcessor.destroy();
}

const fragmentMetaProcessor = WP_HTML_Processor.create_fragment('<meta charset="utf8">');
assert.equal(fragmentMetaProcessor.next_tag("meta"), true);
assert.equal(fragmentMetaProcessor.get_last_error(), null);
fragmentMetaProcessor.destroy();

const plaintextProcessor = WP_HTML_Processor.create_fragment("<plaintext>raw <b>markup</b>");
assert.equal(plaintextProcessor.next_tag("plaintext"), true);
assert.equal(plaintextProcessor.get_last_error(), null);
assert.deepEqual(plaintextProcessor.get_breadcrumbs(), ["HTML", "BODY", "PLAINTEXT"]);
assert.equal(plaintextProcessor.next_token(), true);
assert.equal(plaintextProcessor.get_token_type(), "#text");
assert.equal(plaintextProcessor.get_modifiable_text(), "raw <b>markup</b>");
assert.equal(plaintextProcessor.serialize_token(), "raw <b>markup</b>");
assert.equal(plaintextProcessor.set_modifiable_text("updated <\0"), true);
assert.equal(plaintextProcessor.get_modifiable_text(), "updated <\uFFFD");
assert.equal(plaintextProcessor.get_updated_html(), "<plaintext>updated <\uFFFD");
plaintextProcessor.destroy();

assert.equal(
	WP_HTML_Processor.normalize("<plaintext>raw <b>markup</b>"),
	"<plaintext>raw <b>markup</b></plaintext>",
);

const incompleteStepProcessor = WP_HTML_Processor.create_fragment("<div");
assert.equal(incompleteStepProcessor.next_token(), false);
assert.equal(incompleteStepProcessor.paused_at_incomplete_token(), true);
assert.equal(incompleteStepProcessor.step(WP_HTML_Processor.PROCESS_CURRENT_NODE), false);
assert.equal(incompleteStepProcessor.step(WP_HTML_Processor.REPROCESS_CURRENT_NODE), false);
incompleteStepProcessor.destroy();

const fullParserIncompleteTagProcessor = WP_HTML_Processor.create_full_parser("<div");
assert.equal(fullParserIncompleteTagProcessor.next_token(), true);
assert.equal(fullParserIncompleteTagProcessor.get_tag(), "HTML");
assert.equal(fullParserIncompleteTagProcessor.next_token(), true);
assert.equal(fullParserIncompleteTagProcessor.get_tag(), "HEAD");
assert.equal(fullParserIncompleteTagProcessor.next_token(), true);
assert.equal(fullParserIncompleteTagProcessor.get_tag(), "HEAD");
assert.equal(fullParserIncompleteTagProcessor.is_tag_closer(), true);
assert.equal(fullParserIncompleteTagProcessor.next_token(), true);
assert.equal(fullParserIncompleteTagProcessor.get_tag(), "BODY");
assert.equal(fullParserIncompleteTagProcessor.next_token(), true);
assert.equal(fullParserIncompleteTagProcessor.get_tag(), "BODY");
assert.equal(fullParserIncompleteTagProcessor.is_tag_closer(), true);
assert.equal(fullParserIncompleteTagProcessor.next_token(), true);
assert.equal(fullParserIncompleteTagProcessor.get_tag(), "HTML");
assert.equal(fullParserIncompleteTagProcessor.is_tag_closer(), true);
assert.equal(fullParserIncompleteTagProcessor.next_token(), false);
assert.equal(fullParserIncompleteTagProcessor.paused_at_incomplete_token(), false);
assert.deepEqual(fullParserIncompleteTagProcessor.get_breadcrumbs(), []);
fullParserIncompleteTagProcessor.destroy();

const fullParserIncompleteAfterDoctypeProcessor = WP_HTML_Processor.create_full_parser("<!DOCTYPE html><div");
assert.equal(fullParserIncompleteAfterDoctypeProcessor.next_token(), true);
assert.equal(fullParserIncompleteAfterDoctypeProcessor.get_token_type(), "#doctype");
assert.equal(fullParserIncompleteAfterDoctypeProcessor.next_token(), true);
assert.equal(fullParserIncompleteAfterDoctypeProcessor.get_tag(), "HTML");
assert.equal(fullParserIncompleteAfterDoctypeProcessor.next_token(), true);
assert.equal(fullParserIncompleteAfterDoctypeProcessor.get_tag(), "HEAD");
assert.equal(fullParserIncompleteAfterDoctypeProcessor.next_token(), true);
assert.equal(fullParserIncompleteAfterDoctypeProcessor.get_tag(), "HEAD");
assert.equal(fullParserIncompleteAfterDoctypeProcessor.is_tag_closer(), true);
assert.equal(fullParserIncompleteAfterDoctypeProcessor.next_token(), true);
assert.equal(fullParserIncompleteAfterDoctypeProcessor.get_tag(), "BODY");
assert.equal(fullParserIncompleteAfterDoctypeProcessor.next_token(), true);
assert.equal(fullParserIncompleteAfterDoctypeProcessor.get_tag(), "BODY");
assert.equal(fullParserIncompleteAfterDoctypeProcessor.is_tag_closer(), true);
assert.equal(fullParserIncompleteAfterDoctypeProcessor.next_token(), true);
assert.equal(fullParserIncompleteAfterDoctypeProcessor.get_tag(), "HTML");
assert.equal(fullParserIncompleteAfterDoctypeProcessor.is_tag_closer(), true);
assert.equal(fullParserIncompleteAfterDoctypeProcessor.next_token(), false);
assert.equal(fullParserIncompleteAfterDoctypeProcessor.paused_at_incomplete_token(), false);
assert.deepEqual(fullParserIncompleteAfterDoctypeProcessor.get_breadcrumbs(), []);
fullParserIncompleteAfterDoctypeProcessor.destroy();

const fullParserIncompleteEndTagProcessor = WP_HTML_Processor.create_full_parser("</b test");
while (fullParserIncompleteEndTagProcessor.next_token()) {}
assert.equal(fullParserIncompleteEndTagProcessor.paused_at_incomplete_token(), false);
assert.equal(fullParserIncompleteEndTagProcessor.get_last_error(), null);
assert.deepEqual(fullParserIncompleteEndTagProcessor.get_breadcrumbs(), []);
fullParserIncompleteEndTagProcessor.destroy();

const fullParserIncompleteQuotedAttributeProcessor = WP_HTML_Processor.create_full_parser(
	'<html><body><img src="" border="0" alt="><div>A</div></body></html>',
);
while (fullParserIncompleteQuotedAttributeProcessor.next_token()) {}
assert.equal(fullParserIncompleteQuotedAttributeProcessor.paused_at_incomplete_token(), false);
assert.equal(fullParserIncompleteQuotedAttributeProcessor.get_last_error(), null);
assert.deepEqual(fullParserIncompleteQuotedAttributeProcessor.get_breadcrumbs(), []);
fullParserIncompleteQuotedAttributeProcessor.destroy();

const fullParserIncompleteRawtextProcessor = WP_HTML_Processor.create_full_parser('<script type="data"><!-- foo-');
assert.equal(fullParserIncompleteRawtextProcessor.next_tag("script"), true);
assert.equal(fullParserIncompleteRawtextProcessor.get_modifiable_text(), "<!-- foo-");
assert.deepEqual(fullParserIncompleteRawtextProcessor.get_breadcrumbs(), ["HTML", "HEAD", "SCRIPT"]);
while (fullParserIncompleteRawtextProcessor.next_token()) {}
assert.equal(fullParserIncompleteRawtextProcessor.paused_at_incomplete_token(), false);
assert.equal(fullParserIncompleteRawtextProcessor.get_last_error(), null);
fullParserIncompleteRawtextProcessor.destroy();

const fullParserUnclosedTextareaProcessor = WP_HTML_Processor.create_full_parser("<textarea>test</div>test");
assert.equal(fullParserUnclosedTextareaProcessor.next_tag("textarea"), true);
assert.equal(fullParserUnclosedTextareaProcessor.get_modifiable_text(), "test</div>test");
assert.deepEqual(fullParserUnclosedTextareaProcessor.get_breadcrumbs(), ["HTML", "BODY", "TEXTAREA"]);
while (fullParserUnclosedTextareaProcessor.next_token()) {}
assert.equal(fullParserUnclosedTextareaProcessor.paused_at_incomplete_token(), false);
assert.equal(fullParserUnclosedTextareaProcessor.get_last_error(), null);
fullParserUnclosedTextareaProcessor.destroy();

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

const completedProcessorBookmark = WP_HTML_Processor.create_fragment("<div>");
assert.equal(completedProcessorBookmark.next_tag("div"), true);
assert.equal(completedProcessorBookmark.next_tag(), false);
assert.equal(completedProcessorBookmark.set_bookmark("after-complete"), false);
assert.equal(completedProcessorBookmark.has_bookmark("after-complete"), false);
completedProcessorBookmark.destroy();

const incompleteProcessorBookmark = WP_HTML_Processor.create_fragment("<div");
assert.equal(incompleteProcessorBookmark.next_tag(), false);
assert.equal(incompleteProcessorBookmark.paused_at_incomplete_token(), true);
assert.equal(incompleteProcessorBookmark.set_bookmark("after-incomplete"), false);
assert.equal(incompleteProcessorBookmark.has_bookmark("after-incomplete"), false);
incompleteProcessorBookmark.destroy();

const processorBookmarkRelease = WP_HTML_Processor.create_fragment("<div><span>");
assert.equal(processorBookmarkRelease.next_tag("div"), true);
assert.equal(processorBookmarkRelease.set_bookmark("mark"), true);
assert.equal(processorBookmarkRelease.has_bookmark("mark"), true);
assert.equal(processorBookmarkRelease.release_bookmark("mark"), true);
assert.equal(processorBookmarkRelease.has_bookmark("mark"), false);
assert.equal(processorBookmarkRelease.seek("mark"), false);
processorBookmarkRelease.destroy();

const processorBookmarkScalarNames = WP_HTML_Processor.create_fragment("<div></div><span></span>");
assert.equal(processorBookmarkScalarNames.next_tag("div"), true);
assert.equal(processorBookmarkScalarNames.set_bookmark(1), true);
assert.equal(processorBookmarkScalarNames.has_bookmark("1"), true);
assert.equal(processorBookmarkScalarNames.seek("1"), true);
assert.equal(processorBookmarkScalarNames.release_bookmark(true), true);
assert.equal(processorBookmarkScalarNames.has_bookmark(1), false);
assert.equal(processorBookmarkScalarNames.set_bookmark(false), true);
assert.equal(processorBookmarkScalarNames.has_bookmark(""), true);
assert.equal(processorBookmarkScalarNames.has_bookmark(0), false);
assert.equal(processorBookmarkScalarNames.release_bookmark(""), true);
assert.equal(processorBookmarkScalarNames.set_bookmark(null), true);
assert.equal(processorBookmarkScalarNames.has_bookmark(false), true);
assert.equal(processorBookmarkScalarNames.set_bookmark(["x"]), true);
assert.equal(processorBookmarkScalarNames.has_bookmark([]), true);
assert.equal(processorBookmarkScalarNames.seek(["different"]), true);
assert.equal(processorBookmarkScalarNames.get_tag(), "DIV");
assert.equal(processorBookmarkScalarNames.release_bookmark([]), true);
assert.equal(processorBookmarkScalarNames.has_bookmark(["x"]), false);
assert.equal(processorBookmarkScalarNames.set_bookmark(NaN), true);
assert.equal(processorBookmarkScalarNames.has_bookmark("NAN"), true);
assert.equal(processorBookmarkScalarNames.release_bookmark("NAN"), true);
assert.equal(processorBookmarkScalarNames.set_bookmark(Infinity), true);
assert.equal(processorBookmarkScalarNames.has_bookmark("INF"), true);
assert.equal(processorBookmarkScalarNames.release_bookmark("INF"), true);
assert.equal(processorBookmarkScalarNames.set_bookmark(1e-5), true);
assert.equal(processorBookmarkScalarNames.has_bookmark("1.0E-5"), true);
assert.equal(processorBookmarkScalarNames.release_bookmark("1.0E-5"), true);
assert.equal(processorBookmarkScalarNames.set_bookmark(1e20), true);
assert.equal(processorBookmarkScalarNames.has_bookmark("1.0E+20"), true);
assert.equal(processorBookmarkScalarNames.release_bookmark("1.0E+20"), true);
assert.equal(processorBookmarkScalarNames.set_bookmark(100000000000000), true);
assert.equal(processorBookmarkScalarNames.has_bookmark("100000000000000"), true);
assert.equal(processorBookmarkScalarNames.release_bookmark("100000000000000"), true);
assert.equal(processorBookmarkScalarNames.set_bookmark(-0), true);
assert.equal(processorBookmarkScalarNames.has_bookmark("-0"), true);
assert.equal(processorBookmarkScalarNames.release_bookmark("-0"), true);
assert.throws(
	() => processorBookmarkScalarNames.set_bookmark({}),
	TypeError,
);
processorBookmarkScalarNames.destroy();

for (const [parserName, createProcessor] of [
	["fragment", (html) => WP_HTML_Processor.create_fragment(html)],
	["full parser", (html) => WP_HTML_Processor.create_full_parser(html)],
]) {
	const seekSameLocationProcessor = createProcessor("<div><span>");
	assert.notEqual(seekSameLocationProcessor, null, parserName);
	assert.equal(seekSameLocationProcessor.next_tag("div"), true, parserName);
	assert.equal(seekSameLocationProcessor.set_bookmark("mark"), true, parserName);
	assert.equal(seekSameLocationProcessor.has_bookmark("mark"), true, parserName);
	assert.equal(seekSameLocationProcessor.seek("mark"), true, parserName);
	assert.equal(seekSameLocationProcessor.get_tag(), "DIV", parserName);
	assert.deepEqual(seekSameLocationProcessor.get_breadcrumbs(), ["HTML", "BODY", "DIV"], parserName);
	assert.equal(seekSameLocationProcessor.next_tag(), true, parserName);
	assert.equal(seekSameLocationProcessor.get_tag(), "SPAN", parserName);
	assert.deepEqual(seekSameLocationProcessor.get_breadcrumbs(), ["HTML", "BODY", "DIV", "SPAN"], parserName);
	seekSameLocationProcessor.destroy();

	const seekForwardProcessor = createProcessor("<div one></div><span two></span><a three>");
	assert.notEqual(seekForwardProcessor, null, parserName);
	assert.equal(seekForwardProcessor.next_tag("div"), true, parserName);
	assert.equal(seekForwardProcessor.set_bookmark("one"), true, parserName);
	assert.equal(seekForwardProcessor.has_bookmark("one"), true, parserName);
	assert.equal(seekForwardProcessor.next_tag("span"), true, parserName);
	assert.equal(seekForwardProcessor.get_attribute("two"), true, parserName);
	assert.equal(seekForwardProcessor.set_bookmark("two"), true, parserName);
	assert.equal(seekForwardProcessor.has_bookmark("two"), true, parserName);
	assert.equal(seekForwardProcessor.seek("one"), true, parserName);
	assert.equal(seekForwardProcessor.get_tag(), "DIV", parserName);
	assert.equal(seekForwardProcessor.seek("two"), true, parserName);
	assert.equal(seekForwardProcessor.get_tag(), "SPAN", parserName);
	assert.equal(seekForwardProcessor.get_attribute("two"), true, parserName);
	assert.equal(seekForwardProcessor.next_tag(), true, parserName);
	assert.equal(seekForwardProcessor.get_tag(), "A", parserName);
	assert.equal(seekForwardProcessor.get_attribute("three"), true, parserName);
	seekForwardProcessor.destroy();
}

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

assert.equal(WP_HTML_Processor.normalize("<A><I><A>"), "<a><i></i></a><i><a></a></i>");
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

assert.equal(
	WP_HTML_Processor.normalize("<nobr>1<nobr>2"),
	"<nobr>1</nobr><nobr>2</nobr>",
);
assert.equal(
	WP_HTML_Processor.normalize("<b>1<nobr></b><i><nobr>2</i>"),
	"<b>1<nobr></nobr></b><nobr><i></i></nobr><i><nobr>2</nobr></i>",
);

assert.equal(
	WP_HTML_Processor.normalize("<p><b id=a><b id=a><b id=a><b><object><b id=a><b id=a>X</object><p>Y"),
	[
		'<p><b id="a"><b id="a"><b id="a"><b><object><b id="a"><b id="a">X</b></b></object></b></b></b></b></p>',
		'<p><b id="a"><b id="a"><b id="a"><b>Y</b></b></b></b></p>',
	].join(""),
);

const staleFormattingCloserProcessor = WP_HTML_Processor.create_full_parser("<p id=a><b><p id=b></b>TEST");
while (
	staleFormattingCloserProcessor.next_token() &&
	(
		staleFormattingCloserProcessor.get_token_type() !== "#text" ||
		staleFormattingCloserProcessor.get_modifiable_text() !== "TEST"
	)
) {}
assert.equal(staleFormattingCloserProcessor.get_modifiable_text(), "TEST");
assert.deepEqual(staleFormattingCloserProcessor.get_breadcrumbs(), ["HTML", "BODY", "P", "#text"]);
staleFormattingCloserProcessor.destroy();

const nestedStaleFormattingCloserProcessor = WP_HTML_Processor.create_full_parser("<b id=a><p><b id=b></p></b>TEST");
while (
	nestedStaleFormattingCloserProcessor.next_token() &&
	(
		nestedStaleFormattingCloserProcessor.get_token_type() !== "#text" ||
		nestedStaleFormattingCloserProcessor.get_modifiable_text() !== "TEST"
	)
) {}
assert.equal(nestedStaleFormattingCloserProcessor.get_modifiable_text(), "TEST");
assert.deepEqual(nestedStaleFormattingCloserProcessor.get_breadcrumbs(), ["HTML", "BODY", "B", "#text"]);
nestedStaleFormattingCloserProcessor.destroy();

const reconstructedAfterParagraphCloseProcessor = WP_HTML_Processor.create_full_parser("<p><b></p>text");
while (
	reconstructedAfterParagraphCloseProcessor.next_token() &&
	(
		reconstructedAfterParagraphCloseProcessor.get_token_type() !== "#text" ||
		reconstructedAfterParagraphCloseProcessor.get_modifiable_text() !== "text"
	)
) {}
assert.equal(reconstructedAfterParagraphCloseProcessor.get_modifiable_text(), "text");
assert.deepEqual(reconstructedAfterParagraphCloseProcessor.get_breadcrumbs(), ["HTML", "BODY", "B", "#text"]);
reconstructedAfterParagraphCloseProcessor.destroy();

const nestedFormattingCloseProcessor = WP_HTML_Processor.create_full_parser("<b><b></b>X</b>");
while (
	nestedFormattingCloseProcessor.next_token() &&
	(
		nestedFormattingCloseProcessor.get_token_type() !== "#text" ||
		nestedFormattingCloseProcessor.get_modifiable_text() !== "X"
	)
) {}
assert.equal(nestedFormattingCloseProcessor.get_modifiable_text(), "X");
assert.deepEqual(nestedFormattingCloseProcessor.get_breadcrumbs(), ["HTML", "BODY", "B", "#text"]);
while (nestedFormattingCloseProcessor.next_token()) {}
assert.equal(nestedFormattingCloseProcessor.get_last_error(), null);
assert.equal(nestedFormattingCloseProcessor.get_unsupported_exception(), null);
nestedFormattingCloseProcessor.destroy();
assert.equal(WP_HTML_Processor.normalize("<b><b></b>X</b>"), "<b><b></b>X</b>");

const marqueeReconstructsFormattingProcessor = WP_HTML_Processor.create_full_parser("<p><b><div><marquee></p></b></div>X");
assert.equal(marqueeReconstructsFormattingProcessor.next_tag("marquee"), true);
assert.deepEqual(marqueeReconstructsFormattingProcessor.get_breadcrumbs(), ["HTML", "BODY", "DIV", "B", "MARQUEE"]);
while (
	marqueeReconstructsFormattingProcessor.next_token() &&
	(
		marqueeReconstructsFormattingProcessor.get_token_type() !== "#text" ||
		marqueeReconstructsFormattingProcessor.get_modifiable_text() !== "X"
	)
) {}
assert.equal(marqueeReconstructsFormattingProcessor.get_modifiable_text(), "X");
assert.deepEqual(marqueeReconstructsFormattingProcessor.get_breadcrumbs(), ["HTML", "BODY", "DIV", "B", "MARQUEE", "#text"]);
while (marqueeReconstructsFormattingProcessor.next_token()) {}
assert.equal(marqueeReconstructsFormattingProcessor.get_last_error(), null);
assert.equal(marqueeReconstructsFormattingProcessor.get_unsupported_exception(), null);
marqueeReconstructsFormattingProcessor.destroy();
assert.equal(
	WP_HTML_Processor.normalize("<p><b><div><marquee></p></b></div>X"),
	"<p><b></b></p><div><b><marquee><p></p>X</marquee></b></div>",
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
assert.equal(
	WP_HTML_Processor.normalize("<b><button>foo</b>bar"),
	"<b></b><button><b>foo</b>bar</button>",
);
assert.equal(
	WP_HTML_Processor.normalize("<b><button></b></button></b>"),
	"<b></b><button><b></b></button>",
);
assert.equal(
	WP_HTML_Processor.normalize("<i><menu>Foo</i>"),
	"<i></i><menu><i>Foo</i></menu>",
);
assert.equal(
	WP_HTML_Processor.normalize("<a><p></a></p>"),
	"<a></a><p><a></a></p>",
);
assert.equal(
	WP_HTML_Processor.normalize("<a>1<p>2</a>3</p>"),
	"<a>1</a><p><a>2</a>3</p>",
);
assert.equal(
	WP_HTML_Processor.normalize("<a>1<button>2</a>3</button>"),
	"<a>1</a><button><a>2</a>3</button>",
);
assert.equal(
	WP_HTML_Processor.normalize("<a>1<div>2<div>3</a>4</div>5</div>"),
	"<a>1</a><div><a>2</a><div><a>3</a>4</div>5</div>",
);
assert.equal(
	WP_HTML_Processor.normalize("<a><div><p></a>"),
	"<a></a><div><a></a><p><a></a></p></div>",
);
assert.equal(
	WP_HTML_Processor.normalize("<a><p>text"),
	"<a><p>text</p></a>",
);
assert.equal(
	WP_HTML_Processor.normalize("<b><p></b>TEST"),
	"<b></b><p><b></b>TEST</p>",
);
assert.equal(
	WP_HTML_Processor.normalize("<b>1<i>2<p>3</b>4"),
	"<b>1<i>2</i></b><i><p><b>3</b>4</p></i>",
);
assert.equal(
	WP_HTML_Processor.normalize("<i>A<b>B<p></i>C</b>D"),
	"<i>A<b>B</b></i><b></b><p><b><i></i>C</b>D</p>",
);
assert.equal(
	WP_HTML_Processor.normalize("<html><body>\n<p><font size=\"7\">First paragraph.</p>\n<p>Second paragraph.</p></font>\n<b><p><i>Bold and Italic</b> Italic</p>"),
	"\n<p><font size=\"7\">First paragraph.</font></p><font size=\"7\">\n<p>Second paragraph.</p></font>\n<b></b><p><b><i>Bold and Italic</i></b><i> Italic</i></p>",
);
assert.equal(
	WP_HTML_Processor.normalize("<DIV> abc <B> def <I> ghi <P> jkl </B> mno"),
	"<div> abc <b> def <i> ghi </i></b><i><p><b> jkl </b> mno</p></i></div>",
);
assert.equal(
	WP_HTML_Processor.normalize("<div><a><b><u><i><code><div></a>"),
	"<div><a><b><u><i><code></code></i></u></b></a><u><i><code><div><a></a></div></code></i></u></div>",
);
assert.equal(
	WP_HTML_Processor.normalize("<a><b><big><em><strong><div>X</a>"),
	"<a><b><big><em><strong></strong></em></big></b></a><big><em><strong><div><a>X</a></div></strong></em></big>",
);
assert.equal(
	WP_HTML_Processor.normalize("<a><b><div id=1><div id=2><div id=3><div id=4><div id=5><div id=6><div id=7><div id=8><div id=9>A</a>"),
	'<a><b></b></a><b><div id="1"><a></a><div id="2"><a></a><div id="3"><a></a><div id="4"><a></a><div id="5"><a></a><div id="6"><a></a><div id="7"><a></a><div id="8"><a><div id="9">A</div></a></div></div></div></div></div></div></div></div></b>',
);
assert.equal(
	WP_HTML_Processor.normalize("<font><p>hello<b>cruel</font>world"),
	"<font></font><p><font>hello<b>cruel</b></font><b>world</b></p>",
);
assert.equal(
	WP_HTML_Processor.normalize("<font><p><i>x</i>y</font>z</p>"),
	"<font></font><p><font><i>x</i>y</font>z</p>",
);
assert.equal(
	WP_HTML_Processor.normalize("<font></p><p><meta><title></title></font>"),
	"<font><p></p></font><p><font><meta><title></title></font></p>",
);
assert.equal(
	WP_HTML_Processor.normalize("<b>A<cite>B<div>C</b>D"),
	"<b>A<cite>B</cite></b><div><b>C</b>D</div>",
);
assert.equal(
	WP_HTML_Processor.normalize("<cite><b><cite><i><cite><i><cite><i><div>X</b>TEST"),
	"<cite><b><cite><i><cite><i><cite><i></i></cite></i></cite></i></cite></b><i><i><div><b>X</b>TEST</div></i></i></cite>",
);
assert.equal(
	WP_HTML_Processor.normalize("<!doctype html><i>a<b>b<div>c<a>d</i>e</b>f"),
	"<i>a<b>b</b></i><b></b><div><b><i>c<a>d</a></i><a>e</a></b><a>f</a></div>",
);
assert.equal(
	WP_HTML_Processor.normalize("<a><p>X<a>Y</a>Z</p></a>"),
	"<a></a><p><a>X</a><a>Y</a>Z</p>",
);
assert.equal(
	WP_HTML_Processor.normalize("<a><p><a></a></p></a>"),
	"<a></a><p><a></a><a></a></p>",
);
assert.equal(
	WP_HTML_Processor.normalize("<a><div><style></style><address><a>"),
	"<a></a><div><a><style></style></a><address><a></a><a></a></address></div>",
);
assert.equal(
	WP_HTML_Processor.normalize("<a><div supported><a unsupported></div></a>"),
	"<a></a><div supported><a></a><a unsupported></a></div>",
);
assert.equal(
	WP_HTML_Processor.normalize("<a><center><title></title><a>"),
	"<a></a><center><a><title></title></a><a></a></center>",
);
assert.equal(
	WP_HTML_Processor.normalize("<a href=a>aa<marquee>aa<a href=b>bb</marquee>aa"),
	'<a href="a">aa<marquee>aa<a href="b">bb</a></marquee>aa</a>',
);
assert.equal(
	WP_HTML_Processor.normalize("<a><li><style></style><title></title></a>"),
	"<a></a><li><a><style></style><title></title></a></li>",
);

assert.equal(
	WP_HTML_Processor.normalize("<a><b>1<a>2"),
	"<a><b>1</b></a><b><a>2</a></b>",
);
assert.equal(
	WP_HTML_Processor.normalize("<a X>0<b>1<a Y>2"),
	"<a x>0<b>1</b></a><b><a y>2</a></b>",
);
assert.equal(
	WP_HTML_Processor.normalize("<a><strong>Click <span supported><a unsupported><big>Here</big></a></strong></a>"),
	"<a><strong>Click <span supported></span></strong></a><strong><a unsupported><big>Here</big></a></strong>",
);

assert.equal(WP_HTML_Processor.normalize("</b><p>x"), "<p>x</p>");
assert.equal(WP_HTML_Processor.normalize("<b></b></b><p>x"), "<b></b><p>x</p>");
assert.equal(WP_HTML_Processor.normalize("<b>Test</i>Test"), "<b>TestTest</b>");

assert.equal(
	WP_HTML_Processor.normalize("<b><div></b><p>x"),
	"<b></b><div><b></b><p>x</p></div>",
);
assert.equal(
	WP_HTML_Processor.normalize("<b><em><foo><foo><aside></b>"),
	"<b><em><foo><foo></foo></foo></em></b><em><aside><b></b></aside></em>",
);
assert.equal(
	WP_HTML_Processor.normalize("<b><em><foo><foo><aside></b></em>"),
	"<b><em><foo><foo></foo></foo></em></b><em></em><aside><em><b></b></em></aside>",
);
assert.equal(
	WP_HTML_Processor.normalize("<b><em><foo><foo><foo><aside></b></em>"),
	"<b><em><foo><foo><foo></foo></foo></foo></em></b><aside><b></b></aside>",
);
assert.equal(
	WP_HTML_Processor.normalize("<b>a<div></div><div></b>y"),
	"<b>a<div></div></b><div><b></b>y</div>",
);
assert.equal(
	WP_HTML_Processor.normalize("<a><div></a><p>x"),
	"<a></a><div><p>x</p></div>",
);
assert.equal(
	WP_HTML_Processor.normalize("<label><a><div>Hello<div>World</div></a></label>  "),
	"<label><a></a><div><a>Hello<div>World</div></a>  </div></label>",
);
assert.equal(
	WP_HTML_Processor.normalize("<!DOCTYPE html><body><b><nobr>1<nobr></b><i><nobr>2<nobr></i>3"),
	"<b><nobr>1</nobr><nobr></nobr></b><nobr><i></i></nobr><i><nobr>2</nobr><nobr></nobr></i><nobr>3</nobr>",
);
assert.equal(
	WP_HTML_Processor.normalize("<!DOCTYPE html><body><b><nobr>1<div><nobr></b><i><nobr>2<nobr></i>3"),
	"<b><nobr>1</nobr></b><div><b><nobr></nobr><nobr></nobr></b><nobr><i></i></nobr><i><nobr>2</nobr><nobr></nobr></i><nobr>3</nobr></div>",
);
assert.equal(
	WP_HTML_Processor.normalize("<!DOCTYPE html><body><b><nobr>1<nobr><ins></b><i><nobr>"),
	"<b><nobr>1</nobr><nobr><ins></ins></nobr></b><nobr><i></i></nobr><i><nobr></nobr></i>",
);

assert.equal(
	WP_HTML_Processor.normalize("<!DOCTYPE html><body><b><nobr>1<table><nobr></b><i><nobr>2<nobr></i>3"),
	"<b><nobr>1<nobr><i></i></nobr><i><nobr>2</nobr><nobr></nobr></i><nobr>3</nobr><table></table></nobr></b>",
);

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

const fullParserExplicitHtmlEof = WP_HTML_Processor.create_full_parser("<html><!DOCTYPE html>");
const fullParserExplicitHtmlEofTokens = [];
while (fullParserExplicitHtmlEof.next_token()) {
	fullParserExplicitHtmlEofTokens.push(
		fullParserExplicitHtmlEof.get_token_type() === "#tag"
			? `${fullParserExplicitHtmlEof.is_virtual() ? "V" : "R"}${fullParserExplicitHtmlEof.is_tag_closer() ? "-" : "+"}${fullParserExplicitHtmlEof.get_tag()}:${fullParserExplicitHtmlEof.get_breadcrumbs().join("/")}`
			: fullParserExplicitHtmlEof.get_token_name(),
	);
}
assert.deepEqual(fullParserExplicitHtmlEofTokens, [
	"R+HTML:HTML",
	"V+HEAD:HTML/HEAD",
	"V-HEAD:HTML",
	"V+BODY:HTML/BODY",
	"V-BODY:HTML",
	"V-HTML:",
]);
fullParserExplicitHtmlEof.destroy();

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

const fullParserOpenHeadNoscript = WP_HTML_Processor.create_full_parser("<head><noscript>");
assert.equal(fullParserOpenHeadNoscript.next_tag("noscript"), true);
assert.deepEqual(fullParserOpenHeadNoscript.get_breadcrumbs(), ["HTML", "HEAD", "NOSCRIPT"]);
assert.equal(fullParserOpenHeadNoscript.next_tag("body"), true);
assert.deepEqual(fullParserOpenHeadNoscript.get_breadcrumbs(), ["HTML", "BODY"]);
fullParserOpenHeadNoscript.destroy();

const fullParserHeadContentAfterHead = WP_HTML_Processor.create_full_parser(
	"<head></head><!-- --><style></style><!-- --><script></script>",
);
const fullParserHeadContentAfterHeadTokens = [];
while (fullParserHeadContentAfterHead.next_token()) {
	fullParserHeadContentAfterHeadTokens.push(
		`${fullParserHeadContentAfterHead.is_virtual() ? "V" : "R"}${fullParserHeadContentAfterHead.is_tag_closer() ? "-" : "+"}${fullParserHeadContentAfterHead.get_token_name()}:${fullParserHeadContentAfterHead.get_breadcrumbs().join("/")}`,
	);
}
assert.deepEqual(fullParserHeadContentAfterHeadTokens, [
	"V+HTML:HTML",
	"R+HEAD:HTML/HEAD",
	"R-HEAD:HTML",
	"R+#comment:HTML/#comment",
	"R+STYLE:HTML/HEAD/STYLE",
	"R+#comment:HTML/#comment",
	"R+SCRIPT:HTML/HEAD/SCRIPT",
	"V+BODY:HTML/BODY",
	"V-BODY:HTML",
	"V-HTML:",
]);
fullParserHeadContentAfterHead.destroy();

const fullParserHeadTemplateText = WP_HTML_Processor.create_full_parser("<template>Hello</template>");
assert.equal(fullParserHeadTemplateText.next_tag("template"), true);
assert.deepEqual(fullParserHeadTemplateText.get_breadcrumbs(), ["HTML", "HEAD", "TEMPLATE"]);
assert.equal(fullParserHeadTemplateText.next_token(), true);
assert.equal(fullParserHeadTemplateText.get_token_type(), "#text");
assert.deepEqual(fullParserHeadTemplateText.get_breadcrumbs(), ["HTML", "HEAD", "TEMPLATE", "#text"]);
assert.equal(fullParserHeadTemplateText.next_tag("body"), true);
assert.deepEqual(fullParserHeadTemplateText.get_breadcrumbs(), ["HTML", "BODY"]);
fullParserHeadTemplateText.destroy();

const fullParserOpenHeadTemplate = WP_HTML_Processor.create_full_parser("<template><div>");
assert.equal(fullParserOpenHeadTemplate.next_tag("div"), true);
assert.deepEqual(fullParserOpenHeadTemplate.get_breadcrumbs(), ["HTML", "HEAD", "TEMPLATE", "DIV"]);
assert.equal(fullParserOpenHeadTemplate.next_tag("body"), true);
assert.deepEqual(fullParserOpenHeadTemplate.get_breadcrumbs(), ["HTML", "BODY"]);
fullParserOpenHeadTemplate.destroy();

const fullParserTemplateNestedAnchorTable = WP_HTML_Processor.create_full_parser("<template><a><table><a>");
const fullParserTemplateNestedAnchorTableStarts = [];
while (fullParserTemplateNestedAnchorTable.next_token()) {
	if (
		fullParserTemplateNestedAnchorTable.get_token_type() === "#tag" &&
		!fullParserTemplateNestedAnchorTable.is_tag_closer() &&
		["A", "TABLE"].includes(fullParserTemplateNestedAnchorTable.get_tag())
	) {
		fullParserTemplateNestedAnchorTableStarts.push([
			fullParserTemplateNestedAnchorTable.get_tag(),
			fullParserTemplateNestedAnchorTable.get_breadcrumbs(),
		]);
	}
}
assert.equal(fullParserTemplateNestedAnchorTable.get_last_error(), null);
assert.deepEqual(fullParserTemplateNestedAnchorTableStarts, [
	["A", ["HTML", "HEAD", "TEMPLATE", "A"]],
	["A", ["HTML", "HEAD", "TEMPLATE", "A", "A"]],
	["TABLE", ["HTML", "HEAD", "TEMPLATE", "A", "TABLE"]],
]);
fullParserTemplateNestedAnchorTable.destroy();

const fullParserExplicitHeadTemplate = WP_HTML_Processor.create_full_parser("<head><template><div></div></template></head>");
assert.equal(fullParserExplicitHeadTemplate.next_tag("div"), true);
assert.deepEqual(fullParserExplicitHeadTemplate.get_breadcrumbs(), ["HTML", "HEAD", "TEMPLATE", "DIV"]);
assert.equal(fullParserExplicitHeadTemplate.next_tag("body"), true);
assert.deepEqual(fullParserExplicitHeadTemplate.get_breadcrumbs(), ["HTML", "BODY"]);
fullParserExplicitHeadTemplate.destroy();

const fullParserTemplateAfterHead = WP_HTML_Processor.create_full_parser("<head></head><template>Foo</template>");
assert.equal(fullParserTemplateAfterHead.next_tag("template"), true);
assert.deepEqual(fullParserTemplateAfterHead.get_breadcrumbs(), ["HTML", "HEAD", "TEMPLATE"]);
assert.equal(fullParserTemplateAfterHead.next_token(), true);
assert.equal(fullParserTemplateAfterHead.get_token_type(), "#text");
assert.equal(fullParserTemplateAfterHead.get_modifiable_text(), "Foo");
assert.deepEqual(fullParserTemplateAfterHead.get_breadcrumbs(), ["HTML", "HEAD", "TEMPLATE", "#text"]);
assert.equal(fullParserTemplateAfterHead.next_tag("body"), true);
assert.deepEqual(fullParserTemplateAfterHead.get_breadcrumbs(), ["HTML", "BODY"]);
fullParserTemplateAfterHead.destroy();

const fullParserLinkAfterHead = WP_HTML_Processor.create_full_parser("<head><meta></head><link><p>");
assert.equal(fullParserLinkAfterHead.next_tag("link"), true);
assert.deepEqual(fullParserLinkAfterHead.get_breadcrumbs(), ["HTML", "HEAD", "LINK"]);
assert.equal(fullParserLinkAfterHead.next_tag("p"), true);
assert.deepEqual(fullParserLinkAfterHead.get_breadcrumbs(), ["HTML", "BODY", "P"]);
fullParserLinkAfterHead.destroy();

const fullParserTemplateAfterBody = WP_HTML_Processor.create_full_parser("<body></body><template>");
assert.equal(fullParserTemplateAfterBody.next_tag("template"), true);
assert.deepEqual(fullParserTemplateAfterBody.get_breadcrumbs(), ["HTML", "BODY", "TEMPLATE"]);
fullParserTemplateAfterBody.destroy();

const fullParserBodyTemplateOuterCloser = WP_HTML_Processor.create_full_parser("<div><template></div>Hello");
assert.equal(fullParserBodyTemplateOuterCloser.next_tag("template"), true);
assert.deepEqual(fullParserBodyTemplateOuterCloser.get_breadcrumbs(), ["HTML", "BODY", "DIV", "TEMPLATE"]);
assert.equal(fullParserBodyTemplateOuterCloser.next_token(), true);
assert.equal(fullParserBodyTemplateOuterCloser.get_token_type(), "#text");
assert.equal(fullParserBodyTemplateOuterCloser.get_modifiable_text(), "Hello");
assert.deepEqual(fullParserBodyTemplateOuterCloser.get_breadcrumbs(), ["HTML", "BODY", "DIV", "TEMPLATE", "#text"]);
fullParserBodyTemplateOuterCloser.destroy();

const fullParserTemplateFrames = WP_HTML_Processor.create_full_parser("<template><frame></frame></frameset><frame></frame></template>");
const fullParserTemplateFrameTags = [];
while (fullParserTemplateFrames.next_token()) {
	if (fullParserTemplateFrames.get_token_type() === "#tag") {
		fullParserTemplateFrameTags.push(fullParserTemplateFrames.get_tag());
	}
}
assert.equal(fullParserTemplateFrames.get_last_error(), null);
assert.equal(fullParserTemplateFrameTags.includes("FRAME"), false);
assert.equal(fullParserTemplateFrameTags.includes("FRAMESET"), false);
fullParserTemplateFrames.destroy();

const fullParserTemplateIgnoredFrameset = WP_HTML_Processor.create_full_parser(
	"<template><div><frameset><span></span></div><span></span></template>",
);
const fullParserTemplateIgnoredFramesetSpans = [];
while (fullParserTemplateIgnoredFrameset.next_token()) {
	if (
		fullParserTemplateIgnoredFrameset.get_token_type() === "#tag" &&
		!fullParserTemplateIgnoredFrameset.is_tag_closer() &&
		fullParserTemplateIgnoredFrameset.get_tag() === "SPAN"
	) {
		fullParserTemplateIgnoredFramesetSpans.push(fullParserTemplateIgnoredFrameset.get_breadcrumbs());
	}
}
assert.equal(fullParserTemplateIgnoredFrameset.get_last_error(), null);
assert.deepEqual(fullParserTemplateIgnoredFramesetSpans, [
	["HTML", "HEAD", "TEMPLATE", "DIV", "SPAN"],
	["HTML", "HEAD", "TEMPLATE", "SPAN"],
]);
fullParserTemplateIgnoredFrameset.destroy();

const fullParserTemplateIgnoresHtmlStart = WP_HTML_Processor.create_full_parser(
	"<html a=b><template><div><html b=c><span></template>",
);
assert.equal(fullParserTemplateIgnoresHtmlStart.next_tag("span"), true);
assert.deepEqual(fullParserTemplateIgnoresHtmlStart.get_breadcrumbs(), ["HTML", "HEAD", "TEMPLATE", "DIV", "SPAN"]);
fullParserTemplateIgnoresHtmlStart.destroy();

const fullParserTemplateIgnoresBodyStart = WP_HTML_Processor.create_full_parser("<template><body><span></template>");
assert.equal(fullParserTemplateIgnoresBodyStart.next_tag("span"), true);
assert.deepEqual(fullParserTemplateIgnoresBodyStart.get_breadcrumbs(), ["HTML", "HEAD", "TEMPLATE", "SPAN"]);
fullParserTemplateIgnoresBodyStart.destroy();

const fullParserNestedTemplateFormatting = WP_HTML_Processor.create_full_parser(
	"<body><template><template><b><template></template></template>text</template>",
);
assert.equal(fullParserNestedTemplateFormatting.next_token(), true);
while (
	fullParserNestedTemplateFormatting.get_token_type() !== "#text" &&
	fullParserNestedTemplateFormatting.next_token()
) {
}
assert.equal(fullParserNestedTemplateFormatting.get_token_type(), "#text");
assert.equal(fullParserNestedTemplateFormatting.get_modifiable_text(), "text");
assert.deepEqual(fullParserNestedTemplateFormatting.get_breadcrumbs(), ["HTML", "BODY", "TEMPLATE", "#text"]);
fullParserNestedTemplateFormatting.destroy();

const fullParserTemplateCellAfterRow = WP_HTML_Processor.create_full_parser("<body><template><tr></tr><td></td></template>");
assert.equal(fullParserTemplateCellAfterRow.next_tag("td"), true);
assert.deepEqual(fullParserTemplateCellAfterRow.get_breadcrumbs(), ["HTML", "BODY", "TEMPLATE", "TR", "TD"]);
fullParserTemplateCellAfterRow.destroy();

const fullParserTemplateSkipsTableWrappers = WP_HTML_Processor.create_full_parser(
	"<body><template><td></td><tbody><td></td></template>",
);
const fullParserTemplateSkipsTableWrapperCells = [];
while (fullParserTemplateSkipsTableWrappers.next_tag("td")) {
	fullParserTemplateSkipsTableWrapperCells.push(fullParserTemplateSkipsTableWrappers.get_breadcrumbs());
}
assert.deepEqual(fullParserTemplateSkipsTableWrapperCells, [
	["HTML", "BODY", "TEMPLATE", "TD"],
	["HTML", "BODY", "TEMPLATE", "TD"],
]);
fullParserTemplateSkipsTableWrappers.destroy();

const fullParserTemplateIgnoresBadTableRows = WP_HTML_Processor.create_full_parser("<body><template><div><tr></tr></div></template>");
assert.equal(fullParserTemplateIgnoresBadTableRows.next_tag("tr"), false);
assert.equal(fullParserTemplateIgnoresBadTableRows.get_last_error(), null);
fullParserTemplateIgnoresBadTableRows.destroy();

const fullParserTemplateIgnoresAfterCol = WP_HTML_Processor.create_full_parser("<body><template><col><div>");
assert.equal(fullParserTemplateIgnoresAfterCol.next_tag("col"), true);
assert.deepEqual(fullParserTemplateIgnoresAfterCol.get_breadcrumbs(), ["HTML", "BODY", "TEMPLATE", "COL"]);
assert.equal(fullParserTemplateIgnoresAfterCol.next_tag("div"), false);
assert.equal(fullParserTemplateIgnoresAfterCol.get_last_error(), null);
fullParserTemplateIgnoresAfterCol.destroy();

const fullParserTemplateIgnoresTextAfterCol = WP_HTML_Processor.create_full_parser("<body><template><col>Hello");
assert.equal(fullParserTemplateIgnoresTextAfterCol.next_tag("col"), true);
assert.deepEqual(fullParserTemplateIgnoresTextAfterCol.get_breadcrumbs(), ["HTML", "BODY", "TEMPLATE", "COL"]);
assert.equal(fullParserTemplateIgnoresTextAfterCol.next_token(), true);
assert.equal(fullParserTemplateIgnoresTextAfterCol.get_token_name(), "TEMPLATE");
assert.equal(fullParserTemplateIgnoresTextAfterCol.is_tag_closer(), true);
fullParserTemplateIgnoresTextAfterCol.destroy();

assert.equal(
	buildFullParserHtml5libTree("<body><template><thead></thead><template><tr></tr></template><tr></tr><tfoot></tfoot></template>"),
	"<html>\n  <head>\n  <body>\n    <template>\n      content\n        <thead>\n        <template>\n          content\n            <tr>\n        <tbody>\n          <tr>\n        <tfoot>\n\n",
);

assert.equal(
	buildFullParserHtml5libTree("<body><template></div><div>Foo</div><template></template><tr></tr>"),
	"<html>\n  <head>\n  <body>\n    <template>\n      content\n        <div>\n          \"Foo\"\n        <template>\n          content\n\n",
);

assert.equal(
	buildFullParserHtml5libTree("<body><template><script>var i = 1;</script><td></td></template>"),
	"<html>\n  <head>\n  <body>\n    <template>\n      content\n        <script>\n          \"var i = 1;\"\n        <td>\n\n",
);

assert.equal(
	buildFullParserHtml5libTree("<body><template><thead></thead><caption></caption><tbody></tbody></template>"),
	"<html>\n  <head>\n  <body>\n    <template>\n      content\n        <thead>\n        <caption>\n        <tbody>\n\n",
);

assert.equal(
	buildFullParserHtml5libTree("<template><template><tbody><select>"),
	"<html>\n  <head>\n    <template>\n      content\n        <template>\n          content\n            <tbody>\n            <select>\n  <body>\n\n",
);

assert.equal(
	buildFullParserHtml5libTree("<body><table><tr><td><select><template>Foo</template><caption>A</table>"),
	"<html>\n  <head>\n  <body>\n    <table>\n      <tbody>\n        <tr>\n          <td>\n            <select>\n              <template>\n                content\n                  \"Foo\"\n      <caption>\n        \"A\"\n\n",
);

assert.equal(
	buildFullParserHtml5libTree("<template><td></template><body><span>Foo"),
	"<html>\n  <head>\n    <template>\n      content\n        <td>\n  <body>\n    <span>\n      \"Foo\"\n\n",
);

assert.equal(
	buildFullParserHtml5libTree("<head></head><template>"),
	"<html>\n  <head>\n    <template>\n      content\n  <body>\n\n",
);

assert.equal(
	buildFullParserHtml5libTree("<!DOCTYPE html><body t1=1><body t2=2><body t3=3 t4=4>"),
	'<!DOCTYPE html>\n<html>\n  <head>\n  <body>\n    t1="1"\n\n',
);

assert.equal(
	buildFullParserHtml5libTree("<!DOCTYPE html><html a=1><body><html b=2>"),
	'<!DOCTYPE html>\n<html>\n  a="1"\n  <head>\n  <body>\n\n',
);

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

const fullParserFramesetAfterNull = WP_HTML_Processor.create_full_parser("<html>\0<frameset></frameset>");
const fullParserFramesetAfterNullTags = [];
while (fullParserFramesetAfterNull.next_token()) {
	if (fullParserFramesetAfterNull.get_token_type() === "#tag") {
		fullParserFramesetAfterNullTags.push(
			`${fullParserFramesetAfterNull.is_tag_closer() ? "-" : "+"}${fullParserFramesetAfterNull.get_tag()}`,
		);
	}
}
assert.deepEqual(fullParserFramesetAfterNullTags, ["+HTML", "+HEAD", "-HEAD", "+FRAMESET", "-FRAMESET", "-HTML"]);
assert.equal(fullParserFramesetAfterNull.get_last_error(), null);
assert.equal(fullParserFramesetAfterNull.get_unsupported_exception(), null);
fullParserFramesetAfterNull.destroy();

const fullParserFramesetAfterHiddenInput = WP_HTML_Processor.create_full_parser('<input type="hidden"><frameset>');
const fullParserFramesetAfterHiddenInputTokens = [];
while (fullParserFramesetAfterHiddenInput.next_token()) {
	if (fullParserFramesetAfterHiddenInput.get_token_type() === "#tag") {
		fullParserFramesetAfterHiddenInputTokens.push(
			`${fullParserFramesetAfterHiddenInput.is_tag_closer() ? "-" : "+"}${fullParserFramesetAfterHiddenInput.get_tag()}`,
		);
	}
}
assert.deepEqual(fullParserFramesetAfterHiddenInputTokens, ["+HTML", "+HEAD", "-HEAD", "+FRAMESET", "-FRAMESET", "-HTML"]);
assert.equal(fullParserFramesetAfterHiddenInput.get_last_error(), null);
assert.equal(fullParserFramesetAfterHiddenInput.get_unsupported_exception(), null);
fullParserFramesetAfterHiddenInput.destroy();

for (const html of ["<param><frameset></frameset>", "<source> <frameset></frameset>", "<track><frameset></frameset>"]) {
	const fullParserFramesetAfterIgnoredStart = WP_HTML_Processor.create_full_parser(html);
	const fullParserFramesetAfterIgnoredStartTags = [];
	while (fullParserFramesetAfterIgnoredStart.next_token()) {
		if (fullParserFramesetAfterIgnoredStart.get_token_type() === "#tag") {
			fullParserFramesetAfterIgnoredStartTags.push(
				`${fullParserFramesetAfterIgnoredStart.is_tag_closer() ? "-" : "+"}${fullParserFramesetAfterIgnoredStart.get_tag()}`,
			);
		}
	}
	assert.deepEqual(fullParserFramesetAfterIgnoredStartTags, ["+HTML", "+HEAD", "-HEAD", "+FRAMESET", "-FRAMESET", "-HTML"]);
	assert.equal(fullParserFramesetAfterIgnoredStart.get_last_error(), null);
	assert.equal(fullParserFramesetAfterIgnoredStart.get_unsupported_exception(), null);
	fullParserFramesetAfterIgnoredStart.destroy();
}

const fullParserFramesetAfterIgnoredFrameNoise = WP_HTML_Processor.create_full_parser(
	"<frame></frame></frame><frameset><frame><frameset><frame></frameset><noframes></frameset><noframes>",
);
const fullParserFramesetAfterIgnoredFrameNoiseTokens = [];
while (fullParserFramesetAfterIgnoredFrameNoise.next_token()) {
	if (fullParserFramesetAfterIgnoredFrameNoise.get_token_type() === "#tag") {
		const tagPrefix = fullParserFramesetAfterIgnoredFrameNoise.is_tag_closer() ? "-" : "+";
		fullParserFramesetAfterIgnoredFrameNoiseTokens.push(
			`${tagPrefix}${fullParserFramesetAfterIgnoredFrameNoise.get_tag()}:${
				fullParserFramesetAfterIgnoredFrameNoise.get_breadcrumbs().join("/")
			}`,
		);
	}
}
assert.deepEqual(fullParserFramesetAfterIgnoredFrameNoiseTokens, [
	"+HTML:HTML",
	"+HEAD:HTML/HEAD",
	"-HEAD:HTML",
	"+FRAMESET:HTML/FRAMESET",
	"+FRAME:HTML/FRAMESET/FRAME",
	"+FRAMESET:HTML/FRAMESET/FRAMESET",
	"+FRAME:HTML/FRAMESET/FRAMESET/FRAME",
	"-FRAMESET:HTML/FRAMESET",
	"+NOFRAMES:HTML/FRAMESET/NOFRAMES",
	"-FRAMESET:HTML",
	"-HTML:",
]);
assert.equal(fullParserFramesetAfterIgnoredFrameNoise.get_last_error(), null);
assert.equal(fullParserFramesetAfterIgnoredFrameNoise.get_unsupported_exception(), null);
fullParserFramesetAfterIgnoredFrameNoise.destroy();

for (const html of [
	"<svg></svg><frameset><frame>",
	"<math></math><frameset><frame>",
	"<svg>\0 </svg><frameset><frame>",
	"<svg><path></path></svg><frameset><frame>",
]) {
	const fullParserFramesetAfterEmptyForeign = WP_HTML_Processor.create_full_parser(html);
	const fullParserFramesetAfterEmptyForeignTags = [];
	while (fullParserFramesetAfterEmptyForeign.next_token()) {
		if (fullParserFramesetAfterEmptyForeign.get_token_type() === "#tag") {
			fullParserFramesetAfterEmptyForeignTags.push(
				`${fullParserFramesetAfterEmptyForeign.is_tag_closer() ? "-" : "+"}${fullParserFramesetAfterEmptyForeign.get_tag()}`,
			);
		}
	}
	assert.deepEqual(fullParserFramesetAfterEmptyForeignTags, ["+HTML", "+HEAD", "-HEAD", "+FRAMESET", "+FRAME", "-FRAMESET", "-HTML"]);
	assert.equal(fullParserFramesetAfterEmptyForeign.get_last_error(), null);
	assert.equal(fullParserFramesetAfterEmptyForeign.get_unsupported_exception(), null);
	fullParserFramesetAfterEmptyForeign.destroy();
}

for (const html of ["<svg>\0</svg><frameset>", "<svg> </svg><frameset>"]) {
	const fullParserFramesetAfterEmptyForeign = WP_HTML_Processor.create_full_parser(html);
	const fullParserFramesetAfterEmptyForeignTags = [];
	while (fullParserFramesetAfterEmptyForeign.next_token()) {
		if (fullParserFramesetAfterEmptyForeign.get_token_type() === "#tag") {
			fullParserFramesetAfterEmptyForeignTags.push(
				`${fullParserFramesetAfterEmptyForeign.is_tag_closer() ? "-" : "+"}${fullParserFramesetAfterEmptyForeign.get_tag()}`,
			);
		}
	}
	assert.deepEqual(fullParserFramesetAfterEmptyForeignTags, ["+HTML", "+HEAD", "-HEAD", "+FRAMESET", "-FRAMESET", "-HTML"]);
	assert.equal(fullParserFramesetAfterEmptyForeign.get_last_error(), null);
	assert.equal(fullParserFramesetAfterEmptyForeign.get_unsupported_exception(), null);
	fullParserFramesetAfterEmptyForeign.destroy();
}

for (const [html, expectedTags] of [
	["<div><frameset>", ["+HTML", "+HEAD", "-HEAD", "+FRAMESET", "-FRAMESET", "-HTML"]],
	["<svg><p><frameset>", ["+HTML", "+HEAD", "-HEAD", "+FRAMESET", "-FRAMESET", "-HTML"]],
	["<svg><foreignObject><div> <frameset><frame>", ["+HTML", "+HEAD", "-HEAD", "+FRAMESET", "+FRAME", "-FRAMESET", "-HTML"]],
]) {
	const fullParserFramesetAfterIgnoredOpenChain = WP_HTML_Processor.create_full_parser(html);
	const fullParserFramesetAfterIgnoredOpenChainTags = [];
	while (fullParserFramesetAfterIgnoredOpenChain.next_token()) {
		if (fullParserFramesetAfterIgnoredOpenChain.get_token_type() === "#tag") {
			fullParserFramesetAfterIgnoredOpenChainTags.push(
				`${fullParserFramesetAfterIgnoredOpenChain.is_tag_closer() ? "-" : "+"}${fullParserFramesetAfterIgnoredOpenChain.get_tag()}`,
			);
		}
	}
	assert.deepEqual(fullParserFramesetAfterIgnoredOpenChainTags, expectedTags);
	assert.equal(fullParserFramesetAfterIgnoredOpenChain.get_last_error(), null);
	assert.equal(fullParserFramesetAfterIgnoredOpenChain.get_unsupported_exception(), null);
	fullParserFramesetAfterIgnoredOpenChain.destroy();
}

for (const html of ["</html><frameset></frameset>", "</body> <frameset></frameset>"]) {
	const fullParserFramesetAfterIgnoredCloser = WP_HTML_Processor.create_full_parser(html);
	const fullParserFramesetAfterIgnoredCloserTags = [];
	while (fullParserFramesetAfterIgnoredCloser.next_token()) {
		if (fullParserFramesetAfterIgnoredCloser.get_token_type() === "#tag") {
			fullParserFramesetAfterIgnoredCloserTags.push(
				`${fullParserFramesetAfterIgnoredCloser.is_tag_closer() ? "-" : "+"}${fullParserFramesetAfterIgnoredCloser.get_tag()}`,
			);
		}
	}
	assert.deepEqual(fullParserFramesetAfterIgnoredCloserTags, ["+HTML", "+HEAD", "-HEAD", "+FRAMESET", "-FRAMESET", "-HTML"]);
	assert.equal(fullParserFramesetAfterIgnoredCloser.get_last_error(), null);
	assert.equal(fullParserFramesetAfterIgnoredCloser.get_unsupported_exception(), null);
	fullParserFramesetAfterIgnoredCloser.destroy();
}

for (const html of ["<p><frameset><frame>", "<p> <frameset><frame>"]) {
	const fullParserFramesetAfterParagraph = WP_HTML_Processor.create_full_parser(html);
	const fullParserFramesetAfterParagraphTokens = [];
	while (fullParserFramesetAfterParagraph.next_token()) {
		if (fullParserFramesetAfterParagraph.get_token_type() === "#tag") {
			fullParserFramesetAfterParagraphTokens.push(
				`${fullParserFramesetAfterParagraph.is_tag_closer() ? "-" : "+"}${fullParserFramesetAfterParagraph.get_tag()}`,
			);
		}
	}
	assert.deepEqual(fullParserFramesetAfterParagraphTokens, ["+HTML", "+HEAD", "-HEAD", "+FRAMESET", "+FRAME", "-FRAMESET", "-HTML"]);
	assert.equal(fullParserFramesetAfterParagraph.get_last_error(), null);
	assert.equal(fullParserFramesetAfterParagraph.get_unsupported_exception(), null);
	fullParserFramesetAfterParagraph.destroy();
}

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

for (const [html, expectedTextTokens] of [
	["<frameset>text", []],
	["<frameset>\nfoo", [["\n", ["HTML", "FRAMESET", "#text"]]]],
	["<frameset></frameset> te st", [[" ", ["HTML", "#text"]], [" ", ["HTML", "#text"]]]],
	["<frameset></frameset></html>text", []],
]) {
	const framesetTextProcessor = WP_HTML_Processor.create_full_parser(html);
	const framesetTextTokens = [];
	while (framesetTextProcessor.next_token()) {
		if (framesetTextProcessor.get_token_type() === "#text") {
			framesetTextTokens.push([
				framesetTextProcessor.get_modifiable_text(),
				framesetTextProcessor.get_breadcrumbs(),
			]);
		}
	}
	assert.equal(framesetTextProcessor.get_last_error(), null);
	assert.equal(framesetTextProcessor.get_unsupported_exception(), null);
	assert.deepEqual(framesetTextTokens, expectedTextTokens);
	framesetTextProcessor.destroy();
}

for (const html of [
	"<body><frameset></frameset><p>x</p>",
	"text<frameset></frameset><p>x</p>",
	'<input type="text"><frameset></frameset><p>x</p>',
	"<svg>x</svg><frameset></frameset><p>x</p>",
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

for (const [html, expectedTokens] of [
	[
		"<svg>\0<frameset>",
		[
			"+HTML:html",
			"+HEAD:html",
			"-HEAD:html",
			"+BODY:html",
			"+SVG:svg",
			"#text:svg",
			"+FRAMESET:svg",
			"-FRAMESET:svg",
			"-SVG:svg",
			"-BODY:html",
			"-HTML:html",
		],
	],
	[
		"<svg>\0 <frameset>",
		[
			"+HTML:html",
			"+HEAD:html",
			"-HEAD:html",
			"+BODY:html",
			"+SVG:svg",
			"#text:svg",
			"#text:svg",
			"+FRAMESET:svg",
			"-FRAMESET:svg",
			"-SVG:svg",
			"-BODY:html",
			"-HTML:html",
		],
	],
]) {
	const fullParserOpenSvgFramesetProcessor = WP_HTML_Processor.create_full_parser(html);
	const visitedOpenSvgFramesetTokens = [];
	while (fullParserOpenSvgFramesetProcessor.next_token()) {
		visitedOpenSvgFramesetTokens.push(
			fullParserOpenSvgFramesetProcessor.get_token_type() === "#tag"
				? `${fullParserOpenSvgFramesetProcessor.is_tag_closer() ? "-" : "+"}${fullParserOpenSvgFramesetProcessor.get_tag()}:${fullParserOpenSvgFramesetProcessor.get_namespace()}`
				: `${fullParserOpenSvgFramesetProcessor.get_token_type()}:${fullParserOpenSvgFramesetProcessor.get_namespace()}`,
		);
	}
	assert.deepEqual(visitedOpenSvgFramesetTokens, expectedTokens);
	assert.equal(fullParserOpenSvgFramesetProcessor.get_last_error(), null);
	assert.equal(fullParserOpenSvgFramesetProcessor.get_unsupported_exception(), null);
	fullParserOpenSvgFramesetProcessor.destroy();
}

const fullParserCommentAfterBody = WP_HTML_Processor.create_full_parser("<html><body></body><!--outside-->");
while (
	fullParserCommentAfterBody.next_token() &&
	fullParserCommentAfterBody.get_token_type() !== "#comment"
) {
}
assert.equal(fullParserCommentAfterBody.get_token_type(), "#comment");
assert.deepEqual(fullParserCommentAfterBody.get_breadcrumbs(), ["HTML", "#comment"]);
assert.equal(fullParserCommentAfterBody.get_last_error(), null);
assert.equal(fullParserCommentAfterBody.get_unsupported_exception(), null);
fullParserCommentAfterBody.destroy();

const fullParserCommentAfterHtml = WP_HTML_Processor.create_full_parser("<html><body></body></html><!--outside-->");
while (
	fullParserCommentAfterHtml.next_token() &&
	fullParserCommentAfterHtml.get_token_type() !== "#comment"
) {
}
assert.equal(fullParserCommentAfterHtml.get_token_type(), "#comment");
assert.deepEqual(fullParserCommentAfterHtml.get_breadcrumbs(), ["#comment"]);
assert.equal(fullParserCommentAfterHtml.get_last_error(), null);
assert.equal(fullParserCommentAfterHtml.get_unsupported_exception(), null);
fullParserCommentAfterHtml.destroy();

const fullParserCommentAfterFramesetHtml = WP_HTML_Processor.create_full_parser("<html><frameset></frameset></html><!--outside-->");
while (
	fullParserCommentAfterFramesetHtml.next_token() &&
	fullParserCommentAfterFramesetHtml.get_token_type() !== "#comment"
) {
}
assert.equal(fullParserCommentAfterFramesetHtml.get_token_type(), "#comment");
assert.deepEqual(fullParserCommentAfterFramesetHtml.get_breadcrumbs(), ["#comment"]);
assert.equal(fullParserCommentAfterFramesetHtml.get_last_error(), null);
assert.equal(fullParserCommentAfterFramesetHtml.get_unsupported_exception(), null);
fullParserCommentAfterFramesetHtml.destroy();

const fullParserCommentAfterFramesetNoframes = WP_HTML_Processor.create_full_parser(
	"<html><frameset></frameset></html><noframes>fallback</noframes><!--outside-->",
);
while (
	fullParserCommentAfterFramesetNoframes.next_token() &&
	fullParserCommentAfterFramesetNoframes.get_token_type() !== "#comment"
) {
}
assert.equal(fullParserCommentAfterFramesetNoframes.get_token_type(), "#comment");
assert.deepEqual(fullParserCommentAfterFramesetNoframes.get_breadcrumbs(), ["#comment"]);
assert.equal(fullParserCommentAfterFramesetNoframes.get_last_error(), null);
assert.equal(fullParserCommentAfterFramesetNoframes.get_unsupported_exception(), null);
fullParserCommentAfterFramesetNoframes.destroy();

const fullParserDelayedFramesetComment = WP_HTML_Processor.create_full_parser(
	"<html><frameset></frameset></html><!--before--><noframes>fallback</noframes><!--after-->",
);
const delayedFramesetCommentTokens = [];
while (fullParserDelayedFramesetComment.next_token()) {
	if (fullParserDelayedFramesetComment.get_token_type() === "#tag" && fullParserDelayedFramesetComment.get_tag() === "NOFRAMES") {
		delayedFramesetCommentTokens.push([
			"noframes",
			fullParserDelayedFramesetComment.get_modifiable_text(),
			fullParserDelayedFramesetComment.get_breadcrumbs(),
		]);
	} else if (fullParserDelayedFramesetComment.get_token_type() === "#comment") {
		delayedFramesetCommentTokens.push([
			"comment",
			fullParserDelayedFramesetComment.get_full_comment_text(),
			fullParserDelayedFramesetComment.get_breadcrumbs(),
		]);
	}
}
assert.deepEqual(delayedFramesetCommentTokens, [
	["noframes", "fallback", ["HTML", "NOFRAMES"]],
	["comment", "before", ["#comment"]],
	["comment", "after", ["#comment"]],
]);
assert.equal(fullParserDelayedFramesetComment.get_last_error(), null);
assert.equal(fullParserDelayedFramesetComment.get_unsupported_exception(), null);
fullParserDelayedFramesetComment.destroy();

const noQuirksClasses = WP_HTML_Processor.create_full_parser('<!DOCTYPE html><span class="UPPER">');
assert.equal(noQuirksClasses.next_tag("span"), true);
assert.equal(noQuirksClasses.compat_mode, WP_HTML_Tag_Processor.NO_QUIRKS_MODE);
assert.equal(noQuirksClasses.has_class("upper"), false);
assert.equal(noQuirksClasses.has_class("UPPER"), true);
assert.equal(noQuirksClasses.add_class("upper"), true);
assert.equal(noQuirksClasses.get_updated_html(), '<!DOCTYPE html><span class="UPPER upper">');
noQuirksClasses.destroy();

const noQuirksClassList = WP_HTML_Processor.create_full_parser("<!DOCTYPE html><span class='A A a B b \u00C9 \u0045\u0301 \u00C9 é'>");
assert.equal(noQuirksClassList.next_tag("span"), true);
assert.deepEqual(noQuirksClassList.class_list(), ["A", "a", "B", "b", "É", "E\u0301", "é"]);
noQuirksClassList.destroy();

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

for (const [className, expectedHtml] of [
	[null, '<span class="0 1 ONE ">'],
	[false, '<span class="0 1 ONE">'],
	[1, '<span class="0 1 ONE">'],
	["1", '<span class="0 1 ONE">'],
	[1.5, '<span class="0 1 ONE">'],
	["1.5", '<span class="0 1 ONE 1.5">'],
	["01", '<span class="0 1 ONE 01">'],
	["one", '<span class="0 1 ONE">'],
]) {
	const quirksAddClassKey = WP_HTML_Processor.create_full_parser('<span class="0 1 ONE">');
	assert.equal(quirksAddClassKey.next_tag("span"), true);
	assert.equal(quirksAddClassKey.add_class(className), true);
	assert.equal(quirksAddClassKey.get_updated_html(), expectedHtml);
	quirksAddClassKey.destroy();
}

for (const [className, expectedHtml] of [
	[null, '<span class="0 1 ONE">'],
	[false, '<span class="1 ONE">'],
	[1, '<span class="0 ONE">'],
	["1", '<span class="0 ONE">'],
	[1.5, '<span class="0 ONE">'],
	["1.5", '<span class="0 1 ONE">'],
	["01", '<span class="0 1 ONE">'],
	["one", '<span class="0 1">'],
	["ONE", '<span class="0 1">'],
]) {
	const quirksRemoveClassKey = WP_HTML_Processor.create_full_parser('<span class="0 1 ONE">');
	assert.equal(quirksRemoveClassKey.next_tag("span"), true);
	assert.equal(quirksRemoveClassKey.remove_class(className), true);
	assert.equal(quirksRemoveClassKey.get_updated_html(), expectedHtml);
	quirksRemoveClassKey.destroy();
}

const quirksClassList = WP_HTML_Processor.create_full_parser("<span class='A A a B b \u00C9 \u0045\u0301 \u00C9 é \u0065\u0301'>");
assert.equal(quirksClassList.next_tag("span"), true);
assert.deepEqual(quirksClassList.class_list(), ["a", "b", "É", "e\u0301", "é"]);
quirksClassList.destroy();

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
assert.equal(brEndTagProcessor.has_class("html"), false);
assert.deepEqual(brEndTagProcessor.class_list(), []);
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
	const detachedFormCloserProcessor = WP_HTML_Processor.create_fragment(html);
	assert.equal(detachedFormCloserProcessor.next_tag("form"), true);
	assert.equal(detachedFormCloserProcessor.is_tag_closer(), false);
	assert.equal(detachedFormCloserProcessor.next_tag(), true);
	const descendantTag = detachedFormCloserProcessor.get_tag();
	assert.equal(detachedFormCloserProcessor.next_tag({ tag_name: "form", tag_closers: "visit" }), true);
	assert.equal(detachedFormCloserProcessor.is_tag_closer(), true);
	assert.deepEqual(detachedFormCloserProcessor.get_breadcrumbs(), ["HTML", "BODY", "FORM", descendantTag]);
	assert.equal(detachedFormCloserProcessor.next_tag("p"), true);
	assert.deepEqual(detachedFormCloserProcessor.get_breadcrumbs(), ["HTML", "BODY", "FORM", descendantTag, "P"]);
	while (detachedFormCloserProcessor.next_token()) {
	}
	assert.equal(detachedFormCloserProcessor.get_last_error(), null);
	detachedFormCloserProcessor.destroy();
	assert.notEqual(WP_HTML_Processor.normalize(html), null);
}

const detachedFormPointerProcessor = WP_HTML_Processor.create_fragment("<form><div></form><form><span>");
assert.equal(detachedFormPointerProcessor.next_tag("form"), true);
assert.equal(detachedFormPointerProcessor.next_tag("div"), true);
assert.equal(detachedFormPointerProcessor.next_tag({ tag_name: "form", tag_closers: "visit" }), true);
assert.equal(detachedFormPointerProcessor.is_tag_closer(), true);
assert.deepEqual(detachedFormPointerProcessor.get_breadcrumbs(), ["HTML", "BODY", "FORM", "DIV"]);
assert.equal(detachedFormPointerProcessor.next_tag("form"), true);
assert.equal(detachedFormPointerProcessor.is_tag_closer(), false);
assert.deepEqual(detachedFormPointerProcessor.get_breadcrumbs(), ["HTML", "BODY", "FORM", "DIV", "FORM"]);
detachedFormPointerProcessor.destroy();

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

const selectFragmentIncompleteTextareaProcessor = WP_HTML_Processor.create_fragment("<textarea><option>", "<select>");
assert.equal(selectFragmentIncompleteTextareaProcessor.next_tag("option"), true);
assert.deepEqual(selectFragmentIncompleteTextareaProcessor.get_breadcrumbs(), ["HTML", "SELECT", "OPTION"]);
assert.equal(selectFragmentIncompleteTextareaProcessor.paused_at_incomplete_token(), false);
selectFragmentIncompleteTextareaProcessor.destroy();

const selectInTableProcessor = WP_HTML_Processor.create_fragment("<table><select><option>one<tr><td>cell");
assert.equal(selectInTableProcessor.next_tag("select"), true);
assert.deepEqual(selectInTableProcessor.get_breadcrumbs(), ["HTML", "BODY", "SELECT"]);
assert.equal(selectInTableProcessor.next_tag("td"), true);
assert.deepEqual(selectInTableProcessor.get_breadcrumbs(), ["HTML", "BODY", "TABLE", "TBODY", "TR", "TD"]);
assert.equal(selectInTableProcessor.get_last_error(), null);
selectInTableProcessor.destroy();
assert.equal(
	WP_HTML_Processor.normalize("<table><select><option>one<tr><td>cell"),
	"<select><option>one</option></select><table><tbody><tr><td>cell</td></tr></tbody></table>",
);
assert.equal(
	WP_HTML_Processor.normalize("<table><select><option>one</table><p>after"),
	"<select><option>one</option></select><table></table><p>after</p>",
);
assert.equal(
	WP_HTML_Processor.normalize("<table><select><option>3</select></table>"),
	"<select><option>3</option></select><table></table>",
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

const trailingColProcessor = WP_HTML_Processor.create_fragment("<table><col></table><col>");
assert.equal(trailingColProcessor.next_tag("col"), true);
assert.deepEqual(trailingColProcessor.get_breadcrumbs(), ["HTML", "BODY", "TABLE", "COLGROUP", "COL"]);
assert.equal(trailingColProcessor.next_tag("col"), false);
trailingColProcessor.destroy();
assert.equal(
	WP_HTML_Processor.normalize("<table><col></table><col>"),
	"<table><colgroup><col></colgroup></table>",
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
assert.equal(tableTextProcessor.next_token(), true);
assert.equal(tableTextProcessor.get_token_type(), "#text");
assert.equal(tableTextProcessor.get_modifiable_text(), "text");
assert.deepEqual(tableTextProcessor.get_breadcrumbs(), ["HTML", "BODY", "#text"]);
assert.equal(tableTextProcessor.next_tag("td"), true);
assert.deepEqual(tableTextProcessor.get_breadcrumbs(), ["HTML", "BODY", "TABLE", "TBODY", "TR", "TD"]);
assert.equal(tableTextProcessor.get_last_error(), null);
tableTextProcessor.destroy();
assert.equal(
	WP_HTML_Processor.normalize("<table>text<tr><td>cell"),
	"text<table><tbody><tr><td>cell</td></tr></tbody></table>",
);

const tableEndParagraphProcessor = WP_HTML_Processor.create_full_parser("<p><table></p>");
while (tableEndParagraphProcessor.next_token()) {
}
assert.equal(tableEndParagraphProcessor.get_last_error(), null);
assert.equal(tableEndParagraphProcessor.get_unsupported_exception(), null);
tableEndParagraphProcessor.destroy();
const tableEndParagraphSerializer = WP_HTML_Processor.create_full_parser("<p><table></p>");
assert.equal(
	tableEndParagraphSerializer.serialize(),
	"<html><head></head><body><p><p></p><table></table></p></body></html>",
);
tableEndParagraphSerializer.destroy();
assert.equal(WP_HTML_Processor.normalize("<p><table></p>"), null);

const tablePresumptuousBrProcessor = WP_HTML_Processor.create_full_parser("<table><tr></br></table>");
assert.equal(
	tablePresumptuousBrProcessor.serialize(),
	"<html><head></head><body><br><table><tbody><tr></tr></tbody></table></body></html>",
);
tablePresumptuousBrProcessor.destroy();

const tablePlaintextProcessor = WP_HTML_Processor.create_full_parser("<table><plaintext><td>");
assert.equal(tablePlaintextProcessor.next_tag("plaintext"), true);
assert.deepEqual(tablePlaintextProcessor.get_breadcrumbs(), ["HTML", "BODY", "PLAINTEXT"]);
assert.equal(tablePlaintextProcessor.next_token(), true);
assert.equal(tablePlaintextProcessor.get_token_type(), "#text");
assert.equal(tablePlaintextProcessor.get_modifiable_text(), "<td>");
assert.equal(tablePlaintextProcessor.next_tag("table"), true);
assert.deepEqual(tablePlaintextProcessor.get_breadcrumbs(), ["HTML", "BODY", "TABLE"]);
assert.equal(tablePlaintextProcessor.get_last_error(), null);
tablePlaintextProcessor.destroy();

const tableStyleProcessor = WP_HTML_Processor.create_full_parser("<table><tr><style></script></style>abc");
let sawTableStyleFosteredText = false;
let sawTableStyle = false;
while (tableStyleProcessor.next_token()) {
	if (tableStyleProcessor.get_token_type() === "#text" && tableStyleProcessor.get_modifiable_text() === "abc") {
		assert.deepEqual(tableStyleProcessor.get_breadcrumbs(), ["HTML", "BODY", "#text"]);
		sawTableStyleFosteredText = true;
	}
	if (
		tableStyleProcessor.get_token_type() === "#tag" &&
		!tableStyleProcessor.is_tag_closer() &&
		tableStyleProcessor.get_tag() === "STYLE"
	) {
		assert.equal(sawTableStyleFosteredText, true);
		assert.deepEqual(tableStyleProcessor.get_breadcrumbs(), ["HTML", "BODY", "TABLE", "TBODY", "TR", "STYLE"]);
		assert.equal(tableStyleProcessor.get_modifiable_text(), "</script>");
		sawTableStyle = true;
	}
}
assert.equal(sawTableStyle, true);
assert.equal(tableStyleProcessor.get_last_error(), null);
tableStyleProcessor.destroy();

const tableFormFosterProcessor = WP_HTML_Processor.create_full_parser(
	"<table><form><input type=hidden><input></form><div></div></table>",
);
assert.equal(tableFormFosterProcessor.next_tag("input"), true);
assert.deepEqual(tableFormFosterProcessor.get_breadcrumbs(), ["HTML", "BODY", "INPUT"]);
assert.equal(tableFormFosterProcessor.next_tag("div"), true);
assert.deepEqual(tableFormFosterProcessor.get_breadcrumbs(), ["HTML", "BODY", "DIV"]);
assert.equal(tableFormFosterProcessor.next_tag("table"), true);
assert.deepEqual(tableFormFosterProcessor.get_breadcrumbs(), ["HTML", "BODY", "TABLE"]);
assert.equal(tableFormFosterProcessor.next_tag("input"), true);
assert.equal(tableFormFosterProcessor.get_attribute("type"), "hidden");
assert.deepEqual(tableFormFosterProcessor.get_breadcrumbs(), ["HTML", "BODY", "TABLE", "INPUT"]);
assert.equal(tableFormFosterProcessor.get_last_error(), null);
tableFormFosterProcessor.destroy();

const tableAnchorFosterProcessor = WP_HTML_Processor.create_full_parser(
	"<!doctype html><div><table><a>foo</a> <tr><td>bar</td></tr></table></div>",
);
assert.equal(tableAnchorFosterProcessor.next_tag("a"), true);
assert.deepEqual(tableAnchorFosterProcessor.get_breadcrumbs(), ["HTML", "BODY", "DIV", "A"]);
assert.equal(tableAnchorFosterProcessor.next_token(), true);
assert.equal(tableAnchorFosterProcessor.get_token_type(), "#text");
assert.equal(tableAnchorFosterProcessor.get_modifiable_text(), "foo");
assert.deepEqual(tableAnchorFosterProcessor.get_breadcrumbs(), ["HTML", "BODY", "DIV", "A", "#text"]);
assert.equal(tableAnchorFosterProcessor.next_tag("table"), true);
assert.deepEqual(tableAnchorFosterProcessor.get_breadcrumbs(), ["HTML", "BODY", "DIV", "TABLE"]);
assert.equal(tableAnchorFosterProcessor.get_last_error(), null);
tableAnchorFosterProcessor.destroy();

const tableColSelectProcessor = WP_HTML_Processor.create_full_parser("<kbd><table></kbd><col><select><tr>");
assert.equal(tableColSelectProcessor.next_tag("select"), true);
assert.deepEqual(tableColSelectProcessor.get_breadcrumbs(), ["HTML", "BODY", "KBD", "SELECT"]);
assert.equal(tableColSelectProcessor.next_tag("table"), true);
assert.deepEqual(tableColSelectProcessor.get_breadcrumbs(), ["HTML", "BODY", "KBD", "TABLE"]);
assert.equal(tableColSelectProcessor.next_tag("col"), true);
assert.deepEqual(tableColSelectProcessor.get_breadcrumbs(), ["HTML", "BODY", "KBD", "TABLE", "COLGROUP", "COL"]);
assert.equal(tableColSelectProcessor.next_tag("tr"), true);
assert.deepEqual(tableColSelectProcessor.get_breadcrumbs(), ["HTML", "BODY", "KBD", "TABLE", "TBODY", "TR"]);
assert.equal(tableColSelectProcessor.get_last_error(), null);
tableColSelectProcessor.destroy();

const tableFragmentAnchorFosterProcessor = WP_HTML_Processor.create_fragment(
	"<td><table><tbody><a><tr>",
	"<tbody>",
);
assert.equal(tableFragmentAnchorFosterProcessor.next_tag("a"), true);
assert.deepEqual(tableFragmentAnchorFosterProcessor.get_breadcrumbs(), ["HTML", "TBODY", "TR", "TD", "A"]);
assert.equal(tableFragmentAnchorFosterProcessor.next_tag("table"), true);
assert.deepEqual(tableFragmentAnchorFosterProcessor.get_breadcrumbs(), ["HTML", "TBODY", "TR", "TD", "TABLE"]);
assert.equal(tableFragmentAnchorFosterProcessor.next_tag("tr"), true);
assert.deepEqual(tableFragmentAnchorFosterProcessor.get_breadcrumbs(), ["HTML", "TBODY", "TR", "TD", "TABLE", "TBODY", "TR"]);
assert.equal(tableFragmentAnchorFosterProcessor.get_last_error(), null);
tableFragmentAnchorFosterProcessor.destroy();

const nestedTableMetaProcessor = WP_HTML_Processor.create_full_parser(
	"<!doctype html><table>X<tr><td><table> <meta></table></table>",
);
assert.equal(nestedTableMetaProcessor.next_tag("meta"), true);
assert.deepEqual(nestedTableMetaProcessor.get_breadcrumbs(), ["HTML", "BODY", "TABLE", "TBODY", "TR", "TD", "META"]);
assert.equal(nestedTableMetaProcessor.next_tag("table"), true);
assert.deepEqual(nestedTableMetaProcessor.get_breadcrumbs(), ["HTML", "BODY", "TABLE", "TBODY", "TR", "TD", "TABLE"]);
assert.equal(nestedTableMetaProcessor.next_token(), true);
assert.equal(nestedTableMetaProcessor.get_token_type(), "#text");
assert.equal(nestedTableMetaProcessor.get_modifiable_text(), " ");
assert.deepEqual(nestedTableMetaProcessor.get_breadcrumbs(), ["HTML", "BODY", "TABLE", "TBODY", "TR", "TD", "TABLE", "#text"]);
assert.equal(nestedTableMetaProcessor.get_last_error(), null);
nestedTableMetaProcessor.destroy();

const tableCellFosterTextProcessor = WP_HTML_Processor.create_full_parser("<table>A<td>B</td>C</table>");
assert.equal(tableCellFosterTextProcessor.next_tag("body"), true);
assert.equal(tableCellFosterTextProcessor.next_token(), true);
assert.equal(tableCellFosterTextProcessor.get_token_type(), "#text");
assert.equal(tableCellFosterTextProcessor.get_modifiable_text(), "A");
assert.deepEqual(tableCellFosterTextProcessor.get_breadcrumbs(), ["HTML", "BODY", "#text"]);
assert.equal(tableCellFosterTextProcessor.next_token(), true);
assert.equal(tableCellFosterTextProcessor.get_token_type(), "#text");
assert.equal(tableCellFosterTextProcessor.get_modifiable_text(), "C");
assert.deepEqual(tableCellFosterTextProcessor.get_breadcrumbs(), ["HTML", "BODY", "#text"]);
assert.equal(tableCellFosterTextProcessor.next_tag("table"), true);
assert.deepEqual(tableCellFosterTextProcessor.get_breadcrumbs(), ["HTML", "BODY", "TABLE"]);
assert.equal(tableCellFosterTextProcessor.next_tag("td"), true);
assert.deepEqual(tableCellFosterTextProcessor.get_breadcrumbs(), ["HTML", "BODY", "TABLE", "TBODY", "TR", "TD"]);
assert.equal(tableCellFosterTextProcessor.next_token(), true);
assert.equal(tableCellFosterTextProcessor.get_token_type(), "#text");
assert.equal(tableCellFosterTextProcessor.get_modifiable_text(), "B");
assert.deepEqual(tableCellFosterTextProcessor.get_breadcrumbs(), ["HTML", "BODY", "TABLE", "TBODY", "TR", "TD", "#text"]);
assert.equal(tableCellFosterTextProcessor.get_last_error(), null);
tableCellFosterTextProcessor.destroy();

const tableRowFosterTextProcessor = WP_HTML_Processor.create_full_parser("A<table><tr> B</tr> B</table>");
assert.equal(tableRowFosterTextProcessor.next_tag("body"), true);
for (const text of ["A", " ", "B", " ", "B"]) {
	assert.equal(tableRowFosterTextProcessor.next_token(), true);
	assert.equal(tableRowFosterTextProcessor.get_token_type(), "#text");
	assert.equal(tableRowFosterTextProcessor.get_modifiable_text(), text);
	assert.deepEqual(tableRowFosterTextProcessor.get_breadcrumbs(), ["HTML", "BODY", "#text"]);
}
assert.equal(tableRowFosterTextProcessor.next_tag("table"), true);
assert.deepEqual(tableRowFosterTextProcessor.get_breadcrumbs(), ["HTML", "BODY", "TABLE"]);
assert.equal(tableRowFosterTextProcessor.next_tag("tr"), true);
assert.deepEqual(tableRowFosterTextProcessor.get_breadcrumbs(), ["HTML", "BODY", "TABLE", "TBODY", "TR"]);
assert.equal(tableRowFosterTextProcessor.get_last_error(), null);
tableRowFosterTextProcessor.destroy();

const tableRowIgnoredEndFosterTextProcessor = WP_HTML_Processor.create_full_parser("A<table><tr> B</tr> </em>C</table>");
assert.equal(tableRowIgnoredEndFosterTextProcessor.next_tag("body"), true);
for (const text of ["A", " ", "B", "C"]) {
	assert.equal(tableRowIgnoredEndFosterTextProcessor.next_token(), true);
	assert.equal(tableRowIgnoredEndFosterTextProcessor.get_token_type(), "#text");
	assert.equal(tableRowIgnoredEndFosterTextProcessor.get_modifiable_text(), text);
	assert.deepEqual(tableRowIgnoredEndFosterTextProcessor.get_breadcrumbs(), ["HTML", "BODY", "#text"]);
}
assert.equal(tableRowIgnoredEndFosterTextProcessor.next_tag("table"), true);
assert.deepEqual(tableRowIgnoredEndFosterTextProcessor.get_breadcrumbs(), ["HTML", "BODY", "TABLE"]);
assert.equal(tableRowIgnoredEndFosterTextProcessor.next_tag("tr"), true);
assert.deepEqual(tableRowIgnoredEndFosterTextProcessor.get_breadcrumbs(), ["HTML", "BODY", "TABLE", "TBODY", "TR"]);
assert.equal(tableRowIgnoredEndFosterTextProcessor.next_token(), true);
assert.equal(tableRowIgnoredEndFosterTextProcessor.get_token_type(), "#text");
assert.equal(tableRowIgnoredEndFosterTextProcessor.get_modifiable_text(), " ");
assert.deepEqual(tableRowIgnoredEndFosterTextProcessor.get_breadcrumbs(), ["HTML", "BODY", "TABLE", "TBODY", "#text"]);
assert.equal(tableRowIgnoredEndFosterTextProcessor.get_last_error(), null);
tableRowIgnoredEndFosterTextProcessor.destroy();

const tableCellEndFosterTextProcessor = WP_HTML_Processor.create_full_parser("<table><td></tbody>A");
assert.equal(tableCellEndFosterTextProcessor.next_tag("body"), true);
assert.equal(tableCellEndFosterTextProcessor.next_token(), true);
assert.equal(tableCellEndFosterTextProcessor.get_token_type(), "#text");
assert.equal(tableCellEndFosterTextProcessor.get_modifiable_text(), "A");
assert.deepEqual(tableCellEndFosterTextProcessor.get_breadcrumbs(), ["HTML", "BODY", "#text"]);
assert.equal(tableCellEndFosterTextProcessor.next_tag("table"), true);
assert.deepEqual(tableCellEndFosterTextProcessor.get_breadcrumbs(), ["HTML", "BODY", "TABLE"]);
assert.equal(tableCellEndFosterTextProcessor.next_tag("td"), true);
assert.deepEqual(tableCellEndFosterTextProcessor.get_breadcrumbs(), ["HTML", "BODY", "TABLE", "TBODY", "TR", "TD"]);
assert.equal(tableCellEndFosterTextProcessor.get_last_error(), null);
tableCellEndFosterTextProcessor.destroy();

const tableFormattingFosterTextProcessor = WP_HTML_Processor.create_full_parser("<table><b><tr><td>aaa</td></tr>bbb</table>ccc");
assert.equal(tableFormattingFosterTextProcessor.next_tag("b"), true);
assert.deepEqual(tableFormattingFosterTextProcessor.get_breadcrumbs(), ["HTML", "BODY", "B"]);
assert.equal(tableFormattingFosterTextProcessor.next_tag("b"), true);
assert.equal(tableFormattingFosterTextProcessor.is_virtual(), true);
assert.deepEqual(tableFormattingFosterTextProcessor.get_breadcrumbs(), ["HTML", "BODY", "B"]);
assert.equal(tableFormattingFosterTextProcessor.next_token(), true);
assert.equal(tableFormattingFosterTextProcessor.get_token_type(), "#text");
assert.equal(tableFormattingFosterTextProcessor.get_modifiable_text(), "bbb");
assert.deepEqual(tableFormattingFosterTextProcessor.get_breadcrumbs(), ["HTML", "BODY", "B", "#text"]);
assert.equal(tableFormattingFosterTextProcessor.next_tag("table"), true);
assert.deepEqual(tableFormattingFosterTextProcessor.get_breadcrumbs(), ["HTML", "BODY", "TABLE"]);
assert.equal(tableFormattingFosterTextProcessor.next_tag("td"), true);
assert.deepEqual(tableFormattingFosterTextProcessor.get_breadcrumbs(), ["HTML", "BODY", "TABLE", "TBODY", "TR", "TD"]);
assert.equal(tableFormattingFosterTextProcessor.next_tag("b"), true);
assert.equal(tableFormattingFosterTextProcessor.is_virtual(), true);
assert.deepEqual(tableFormattingFosterTextProcessor.get_breadcrumbs(), ["HTML", "BODY", "B"]);
assert.equal(tableFormattingFosterTextProcessor.next_token(), true);
assert.equal(tableFormattingFosterTextProcessor.get_token_type(), "#text");
assert.equal(tableFormattingFosterTextProcessor.get_modifiable_text(), "ccc");
assert.deepEqual(tableFormattingFosterTextProcessor.get_breadcrumbs(), ["HTML", "BODY", "B", "#text"]);
assert.equal(tableFormattingFosterTextProcessor.get_last_error(), null);
tableFormattingFosterTextProcessor.destroy();

const tableAnchorFosterTextProcessor = WP_HTML_Processor.create_full_parser("<table><a>1<td>2</td>3</table>");
assert.equal(tableAnchorFosterTextProcessor.next_tag("a"), true);
assert.deepEqual(tableAnchorFosterTextProcessor.get_breadcrumbs(), ["HTML", "BODY", "A"]);
assert.equal(tableAnchorFosterTextProcessor.next_token(), true);
assert.equal(tableAnchorFosterTextProcessor.get_token_type(), "#text");
assert.equal(tableAnchorFosterTextProcessor.get_modifiable_text(), "1");
assert.deepEqual(tableAnchorFosterTextProcessor.get_breadcrumbs(), ["HTML", "BODY", "A", "#text"]);
assert.equal(tableAnchorFosterTextProcessor.next_tag("a"), true);
assert.equal(tableAnchorFosterTextProcessor.is_virtual(), true);
assert.deepEqual(tableAnchorFosterTextProcessor.get_breadcrumbs(), ["HTML", "BODY", "A"]);
assert.equal(tableAnchorFosterTextProcessor.next_token(), true);
assert.equal(tableAnchorFosterTextProcessor.get_token_type(), "#text");
assert.equal(tableAnchorFosterTextProcessor.get_modifiable_text(), "3");
assert.deepEqual(tableAnchorFosterTextProcessor.get_breadcrumbs(), ["HTML", "BODY", "A", "#text"]);
assert.equal(tableAnchorFosterTextProcessor.next_tag("table"), true);
assert.deepEqual(tableAnchorFosterTextProcessor.get_breadcrumbs(), ["HTML", "BODY", "TABLE"]);
assert.equal(tableAnchorFosterTextProcessor.next_tag("td"), true);
assert.deepEqual(tableAnchorFosterTextProcessor.get_breadcrumbs(), ["HTML", "BODY", "TABLE", "TBODY", "TR", "TD"]);
assert.equal(tableAnchorFosterTextProcessor.get_last_error(), null);
tableAnchorFosterTextProcessor.destroy();

const tableCenterFontFosterProcessor = WP_HTML_Processor.create_full_parser(
	"<table><center> <font>a</center> <img> <tr><td> </td> </tr> </table>",
);
assert.equal(tableCenterFontFosterProcessor.next_tag("center"), true);
assert.deepEqual(tableCenterFontFosterProcessor.get_breadcrumbs(), ["HTML", "BODY", "CENTER"]);
assert.equal(tableCenterFontFosterProcessor.next_token(), true);
assert.equal(tableCenterFontFosterProcessor.get_token_type(), "#text");
assert.equal(tableCenterFontFosterProcessor.get_modifiable_text(), " ");
assert.deepEqual(tableCenterFontFosterProcessor.get_breadcrumbs(), ["HTML", "BODY", "CENTER", "#text"]);
assert.equal(tableCenterFontFosterProcessor.next_tag("font"), true);
assert.deepEqual(tableCenterFontFosterProcessor.get_breadcrumbs(), ["HTML", "BODY", "CENTER", "FONT"]);
assert.equal(tableCenterFontFosterProcessor.next_token(), true);
assert.equal(tableCenterFontFosterProcessor.get_token_type(), "#text");
assert.equal(tableCenterFontFosterProcessor.get_modifiable_text(), "a");
assert.deepEqual(tableCenterFontFosterProcessor.get_breadcrumbs(), ["HTML", "BODY", "CENTER", "FONT", "#text"]);
assert.equal(tableCenterFontFosterProcessor.next_token(), true);
assert.equal(tableCenterFontFosterProcessor.get_token_type(), "#tag");
assert.equal(tableCenterFontFosterProcessor.get_tag(), "FONT");
assert.equal(tableCenterFontFosterProcessor.is_virtual(), true);
assert.deepEqual(tableCenterFontFosterProcessor.get_breadcrumbs(), ["HTML", "BODY", "CENTER"]);
assert.equal(tableCenterFontFosterProcessor.next_token(), true);
assert.equal(tableCenterFontFosterProcessor.get_token_type(), "#tag");
assert.equal(tableCenterFontFosterProcessor.get_tag(), "CENTER");
assert.deepEqual(tableCenterFontFosterProcessor.get_breadcrumbs(), ["HTML", "BODY", "TABLE"]);
assert.equal(tableCenterFontFosterProcessor.next_token(), true);
assert.equal(tableCenterFontFosterProcessor.get_token_type(), "#tag");
assert.equal(tableCenterFontFosterProcessor.get_tag(), "FONT");
assert.equal(tableCenterFontFosterProcessor.is_virtual(), true);
assert.deepEqual(tableCenterFontFosterProcessor.get_breadcrumbs(), ["HTML", "BODY", "FONT"]);
assert.equal(tableCenterFontFosterProcessor.next_token(), true);
assert.equal(tableCenterFontFosterProcessor.get_token_type(), "#tag");
assert.equal(tableCenterFontFosterProcessor.get_tag(), "IMG");
assert.deepEqual(tableCenterFontFosterProcessor.get_breadcrumbs(), ["HTML", "BODY", "FONT", "IMG"]);
assert.equal(tableCenterFontFosterProcessor.next_token(), true);
assert.equal(tableCenterFontFosterProcessor.get_token_type(), "#text");
assert.equal(tableCenterFontFosterProcessor.get_modifiable_text(), " ");
assert.deepEqual(tableCenterFontFosterProcessor.get_breadcrumbs(), ["HTML", "BODY", "FONT", "#text"]);
assert.equal(tableCenterFontFosterProcessor.next_token(), true);
assert.equal(tableCenterFontFosterProcessor.get_token_type(), "#tag");
assert.equal(tableCenterFontFosterProcessor.get_tag(), "FONT");
assert.equal(tableCenterFontFosterProcessor.is_virtual(), true);
assert.deepEqual(tableCenterFontFosterProcessor.get_breadcrumbs(), ["HTML", "BODY", "TABLE"]);
assert.equal(tableCenterFontFosterProcessor.next_token(), true);
assert.equal(tableCenterFontFosterProcessor.get_token_type(), "#tag");
assert.equal(tableCenterFontFosterProcessor.get_tag(), "TABLE");
assert.deepEqual(tableCenterFontFosterProcessor.get_breadcrumbs(), ["HTML", "BODY", "TABLE"]);
assert.equal(tableCenterFontFosterProcessor.next_token(), true);
assert.equal(tableCenterFontFosterProcessor.get_token_type(), "#text");
assert.equal(tableCenterFontFosterProcessor.get_modifiable_text(), " ");
assert.deepEqual(tableCenterFontFosterProcessor.get_breadcrumbs(), ["HTML", "BODY", "TABLE", "#text"]);
assert.equal(tableCenterFontFosterProcessor.next_tag("td"), true);
assert.deepEqual(tableCenterFontFosterProcessor.get_breadcrumbs(), ["HTML", "BODY", "TABLE", "TBODY", "TR", "TD"]);
assert.equal(tableCenterFontFosterProcessor.next_token(), true);
assert.equal(tableCenterFontFosterProcessor.get_token_type(), "#text");
assert.equal(tableCenterFontFosterProcessor.get_modifiable_text(), " ");
assert.deepEqual(tableCenterFontFosterProcessor.get_breadcrumbs(), ["HTML", "BODY", "TABLE", "TBODY", "TR", "TD", "#text"]);
assert.equal(tableCenterFontFosterProcessor.next_token(), true);
assert.equal(tableCenterFontFosterProcessor.get_token_type(), "#tag");
assert.equal(tableCenterFontFosterProcessor.get_tag(), "TD");
assert.equal(tableCenterFontFosterProcessor.next_token(), true);
assert.equal(tableCenterFontFosterProcessor.get_token_type(), "#text");
assert.equal(tableCenterFontFosterProcessor.get_modifiable_text(), " ");
assert.deepEqual(tableCenterFontFosterProcessor.get_breadcrumbs(), ["HTML", "BODY", "TABLE", "TBODY", "TR", "#text"]);
assert.equal(tableCenterFontFosterProcessor.get_last_error(), null);
tableCenterFontFosterProcessor.destroy();

const colgroupTextProcessor = WP_HTML_Processor.create_fragment("<table><colgroup> foo</colgroup></table>");
assert.equal(colgroupTextProcessor.next_token(), true);
assert.equal(colgroupTextProcessor.get_token_type(), "#text");
assert.equal(colgroupTextProcessor.get_modifiable_text(), "foo");
assert.deepEqual(colgroupTextProcessor.get_breadcrumbs(), ["HTML", "BODY", "#text"]);
assert.equal(colgroupTextProcessor.next_tag("colgroup"), true);
assert.deepEqual(colgroupTextProcessor.get_breadcrumbs(), ["HTML", "BODY", "TABLE", "COLGROUP"]);
assert.equal(colgroupTextProcessor.next_token(), true);
assert.equal(colgroupTextProcessor.get_token_type(), "#text");
assert.equal(colgroupTextProcessor.get_modifiable_text(), " ");
assert.deepEqual(colgroupTextProcessor.get_breadcrumbs(), ["HTML", "BODY", "TABLE", "COLGROUP", "#text"]);
assert.equal(colgroupTextProcessor.get_last_error(), null);
colgroupTextProcessor.destroy();
assert.equal(
	WP_HTML_Processor.normalize("<table><colgroup> foo</colgroup></table>"),
	"foo<table><colgroup> </colgroup></table>",
);

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

assert.equal(
	WP_HTML_Processor.normalize("<table><div><tr><td>cell"),
	"<div></div><table><tbody><tr><td>cell</td></tr></tbody></table>",
);

const tableInputFosterProcessor = WP_HTML_Processor.create_fragment("<table><input><tr><td>cell");
assert.equal(tableInputFosterProcessor.next_tag("input"), true);
assert.deepEqual(tableInputFosterProcessor.get_breadcrumbs(), ["HTML", "BODY", "INPUT"]);
assert.equal(tableInputFosterProcessor.next_tag("td"), true);
assert.deepEqual(tableInputFosterProcessor.get_breadcrumbs(), ["HTML", "BODY", "TABLE", "TBODY", "TR", "TD"]);
assert.equal(tableInputFosterProcessor.get_last_error(), null);
tableInputFosterProcessor.destroy();
assert.equal(
	WP_HTML_Processor.normalize("<table><input><tr><td>cell"),
	"<input><table><tbody><tr><td>cell</td></tr></tbody></table>",
);

assert.equal(
	WP_HTML_Processor.normalize("<table><tbody><div><tr><td>cell"),
	"<div></div><table><tbody><tr><td>cell</td></tr></tbody></table>",
);
assert.equal(
	WP_HTML_Processor.normalize("<table><tr><div><td>cell"),
	"<div></div><table><tbody><tr><td>cell</td></tr></tbody></table>",
);

const colgroupForeignFosterProcessor = WP_HTML_Processor.create_fragment("<table><colgroup><svg><g>cell</g>");
assert.equal(colgroupForeignFosterProcessor.next_tag("svg"), true);
assert.equal(colgroupForeignFosterProcessor.get_namespace(), "svg");
assert.deepEqual(colgroupForeignFosterProcessor.get_breadcrumbs(), ["HTML", "BODY", "SVG"]);
assert.equal(colgroupForeignFosterProcessor.next_tag("table"), true);
assert.deepEqual(colgroupForeignFosterProcessor.get_breadcrumbs(), ["HTML", "BODY", "TABLE"]);
assert.equal(colgroupForeignFosterProcessor.get_last_error(), null);
colgroupForeignFosterProcessor.destroy();
assert.equal(
	WP_HTML_Processor.normalize("<table><colgroup><svg><g>cell</g>"),
	"<svg><g>cell</g></svg><table><colgroup></colgroup></table>",
);

const tableForeignCellFosterParentingProcessor = WP_HTML_Processor.create_full_parser(
	"<body><table><tr><td><svg><td><foreignObject><span></td>Foo",
);
assert.equal(tableForeignCellFosterParentingProcessor.next_tag("body"), true);
assert.equal(tableForeignCellFosterParentingProcessor.next_token(), true);
assert.equal(tableForeignCellFosterParentingProcessor.get_token_type(), "#text");
assert.equal(tableForeignCellFosterParentingProcessor.get_modifiable_text(), "Foo");
assert.deepEqual(tableForeignCellFosterParentingProcessor.get_breadcrumbs(), ["HTML", "BODY", "#text"]);
assert.equal(tableForeignCellFosterParentingProcessor.next_tag("table"), true);
assert.deepEqual(tableForeignCellFosterParentingProcessor.get_breadcrumbs(), ["HTML", "BODY", "TABLE"]);
assert.equal(tableForeignCellFosterParentingProcessor.next_tag("svg"), true);
assert.equal(tableForeignCellFosterParentingProcessor.get_namespace(), "svg");
assert.deepEqual(tableForeignCellFosterParentingProcessor.get_breadcrumbs(), ["HTML", "BODY", "TABLE", "TBODY", "TR", "TD", "SVG"]);
assert.equal(tableForeignCellFosterParentingProcessor.next_tag("td"), true);
assert.equal(tableForeignCellFosterParentingProcessor.get_namespace(), "svg");
assert.deepEqual(tableForeignCellFosterParentingProcessor.get_breadcrumbs(), ["HTML", "BODY", "TABLE", "TBODY", "TR", "TD", "SVG", "TD"]);
assert.equal(tableForeignCellFosterParentingProcessor.next_tag("foreignobject"), true);
assert.equal(tableForeignCellFosterParentingProcessor.get_namespace(), "svg");
assert.equal(tableForeignCellFosterParentingProcessor.get_qualified_tag_name(), "foreignObject");
assert.deepEqual(tableForeignCellFosterParentingProcessor.get_breadcrumbs(), ["HTML", "BODY", "TABLE", "TBODY", "TR", "TD", "SVG", "TD", "FOREIGNOBJECT"]);
assert.equal(tableForeignCellFosterParentingProcessor.next_tag("span"), true);
assert.equal(tableForeignCellFosterParentingProcessor.get_namespace(), "html");
assert.deepEqual(tableForeignCellFosterParentingProcessor.get_breadcrumbs(), ["HTML", "BODY", "TABLE", "TBODY", "TR", "TD", "SVG", "TD", "FOREIGNOBJECT", "SPAN"]);
assert.equal(tableForeignCellFosterParentingProcessor.get_last_error(), null);
tableForeignCellFosterParentingProcessor.destroy();

const tableHiddenInputProcessor = WP_HTML_Processor.create_fragment("<table><input type=hidden><tr><td>cell");
assert.equal(tableHiddenInputProcessor.next_tag("input"), true);
assert.equal(tableHiddenInputProcessor.get_attribute("type"), "hidden");
assert.deepEqual(tableHiddenInputProcessor.get_breadcrumbs(), ["HTML", "BODY", "TABLE", "INPUT"]);
assert.equal(tableHiddenInputProcessor.next_tag("td"), true);
assert.equal(tableHiddenInputProcessor.get_last_error(), null);
tableHiddenInputProcessor.destroy();

const tableFragmentStandaloneAnchorProcessor = WP_HTML_Processor.create_fragment("<a>", "<table>");
assert.equal(tableFragmentStandaloneAnchorProcessor.serialize(), "<a></a>");
assert.equal(tableFragmentStandaloneAnchorProcessor.get_last_error(), null);
tableFragmentStandaloneAnchorProcessor.destroy();

for (const html of ["<caption><a>", "<col><a>", "<tbody><a>", "<tfoot><a>", "<thead><a>", "</table><a>"]) {
	const tableSectionFragmentStandaloneAnchorProcessor = WP_HTML_Processor.create_fragment(html, "<tbody>");
	assert.equal(tableSectionFragmentStandaloneAnchorProcessor.serialize(), "<a></a>");
	assert.equal(tableSectionFragmentStandaloneAnchorProcessor.get_last_error(), null);
	tableSectionFragmentStandaloneAnchorProcessor.destroy();
}

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

const tableRowUnmatchedFormattingCloserProcessor = WP_HTML_Processor.create_fragment("<table><tr></strong><td>cell");
assert.equal(tableRowUnmatchedFormattingCloserProcessor.next_tag("td"), true);
assert.deepEqual(
	tableRowUnmatchedFormattingCloserProcessor.get_breadcrumbs(),
	["HTML", "BODY", "TABLE", "TBODY", "TR", "TD"],
);
tableRowUnmatchedFormattingCloserProcessor.destroy();
assert.equal(
	WP_HTML_Processor.normalize("<table><tr></strong><td>cell"),
	"<table><tbody><tr><td>cell</td></tr></tbody></table>",
);

const tableRowUnmatchedEndTagProcessor = WP_HTML_Processor.create_fragment("<table><tr></blink><td>cell");
assert.equal(tableRowUnmatchedEndTagProcessor.next_tag("td"), true);
assert.deepEqual(
	tableRowUnmatchedEndTagProcessor.get_breadcrumbs(),
	["HTML", "BODY", "TABLE", "TBODY", "TR", "TD"],
);
tableRowUnmatchedEndTagProcessor.destroy();
assert.equal(
	WP_HTML_Processor.normalize("<table><tr></blink><td>cell"),
	"<table><tbody><tr><td>cell</td></tr></tbody></table>",
);

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

const qualifiedForeignAttributeProcessor = WP_HTML_Processor.create_fragment(
	'<svg><use xlink:href="#icon" xml:lang="en" xmlns:xlink="http://www.w3.org/1999/xlink" custom:attr="v"></use></svg>',
);
assert.equal(qualifiedForeignAttributeProcessor.next_tag("use"), true);
assert.equal(qualifiedForeignAttributeProcessor.get_namespace(), "svg");
assert.equal(qualifiedForeignAttributeProcessor.get_qualified_attribute_name("xlink:href"), "xlink href");
assert.equal(qualifiedForeignAttributeProcessor.get_qualified_attribute_name("xml:lang"), "xml lang");
assert.equal(qualifiedForeignAttributeProcessor.get_qualified_attribute_name("xmlns:xlink"), "xmlns xlink");
assert.equal(qualifiedForeignAttributeProcessor.get_qualified_attribute_name("custom:attr"), "custom:attr");
assert.equal(qualifiedForeignAttributeProcessor.get_qualified_attribute_name("custom:Attr"), "custom:Attr");
qualifiedForeignAttributeProcessor.destroy();

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

const mathQualifiedForeignAttributeProcessor = WP_HTML_Processor.create_fragment(
	'<math><mi definitionurl="x" xlink:show="new" viewbox="raw"></mi></math>',
);
assert.equal(mathQualifiedForeignAttributeProcessor.next_tag("mi"), true);
assert.equal(mathQualifiedForeignAttributeProcessor.get_namespace(), "math");
assert.equal(mathQualifiedForeignAttributeProcessor.get_qualified_attribute_name("definitionurl"), "definitionURL");
assert.equal(mathQualifiedForeignAttributeProcessor.get_qualified_attribute_name("xlink:show"), "xlink show");
assert.equal(mathQualifiedForeignAttributeProcessor.get_qualified_attribute_name("viewbox"), "viewbox");
assert.equal(mathQualifiedForeignAttributeProcessor.get_qualified_attribute_name("viewBox"), "viewBox");
mathQualifiedForeignAttributeProcessor.destroy();

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

const mathTextIntegrationFragmentProcessor = WP_HTML_Processor.create_fragment(
	"<b></b><mglyph/><i></i><malignmark/><u></u><ms/>X",
	"<math><ms>",
);
assert.equal(mathTextIntegrationFragmentProcessor.next_tag("mglyph"), true);
assert.equal(mathTextIntegrationFragmentProcessor.get_namespace(), "math");
assert.deepEqual(mathTextIntegrationFragmentProcessor.get_breadcrumbs(), ["HTML", "MS", "MGLYPH"]);
assert.equal(mathTextIntegrationFragmentProcessor.next_tag("malignmark"), true);
assert.equal(mathTextIntegrationFragmentProcessor.get_namespace(), "math");
assert.deepEqual(mathTextIntegrationFragmentProcessor.get_breadcrumbs(), ["HTML", "MS", "MALIGNMARK"]);
assert.equal(mathTextIntegrationFragmentProcessor.next_tag("ms"), true);
assert.equal(mathTextIntegrationFragmentProcessor.get_namespace(), "html");
assert.deepEqual(mathTextIntegrationFragmentProcessor.get_breadcrumbs(), ["HTML", "MS", "MS"]);
mathTextIntegrationFragmentProcessor.destroy();

const foreignFragmentBreakoutProcessor = WP_HTML_Processor.create_fragment("<nobr>X", "<svg><path>");
assert.equal(foreignFragmentBreakoutProcessor.next_tag("nobr"), true);
assert.equal(foreignFragmentBreakoutProcessor.get_namespace(), "html");
assert.deepEqual(foreignFragmentBreakoutProcessor.get_breadcrumbs(), ["HTML", "SVG", "PATH", "NOBR"]);
assert.equal(foreignFragmentBreakoutProcessor.next_token(), true);
assert.equal(foreignFragmentBreakoutProcessor.get_token_type(), "#text");
assert.deepEqual(foreignFragmentBreakoutProcessor.get_breadcrumbs(), ["HTML", "SVG", "PATH", "NOBR", "#text"]);
foreignFragmentBreakoutProcessor.destroy();

for (const [html, firstHtmlTag] of [
	["</p><foo>", "P"],
	["</br><foo>", "BR"],
	["<body><foo>", null],
	["<p></p><foo>", "P"],
]) {
	const svgFragmentNamespaceProcessor = WP_HTML_Processor.create_fragment(html, "<svg>");
	if (firstHtmlTag !== null) {
		assert.equal(svgFragmentNamespaceProcessor.next_tag(firstHtmlTag), true, html);
		assert.equal(svgFragmentNamespaceProcessor.get_namespace(), "html", html);
	}
	assert.equal(svgFragmentNamespaceProcessor.next_tag("foo"), true, html);
	assert.equal(svgFragmentNamespaceProcessor.get_namespace(), "svg", html);
	assert.deepEqual(svgFragmentNamespaceProcessor.get_breadcrumbs(), ["HTML", "SVG", "FOO"], html);
	svgFragmentNamespaceProcessor.destroy();
}

const mathAnnotationXmlFragmentProcessor = WP_HTML_Processor.create_fragment("<figure></figure>", "<math><annotation-xml>");
assert.equal(mathAnnotationXmlFragmentProcessor.next_tag("figure"), true);
assert.equal(mathAnnotationXmlFragmentProcessor.get_namespace(), "math");
assert.deepEqual(mathAnnotationXmlFragmentProcessor.get_breadcrumbs(), ["HTML", "ANNOTATION-XML", "FIGURE"]);
mathAnnotationXmlFragmentProcessor.destroy();

const mathAnnotationXmlBreakoutProcessor = WP_HTML_Processor.create_fragment("<div></div>", "<math><annotation-xml>");
assert.equal(mathAnnotationXmlBreakoutProcessor.next_tag("div"), true);
assert.equal(mathAnnotationXmlBreakoutProcessor.get_namespace(), "html");
assert.deepEqual(mathAnnotationXmlBreakoutProcessor.get_breadcrumbs(), ["HTML", "MATH", "ANNOTATION-XML", "DIV"]);
mathAnnotationXmlBreakoutProcessor.destroy();

const mathAnnotationXmlHtmlIntegrationProcessor = WP_HTML_Processor.create_fragment(
	"<div></div>",
	'<math><annotation-xml encoding="text/html">',
);
assert.equal(mathAnnotationXmlHtmlIntegrationProcessor.next_tag("div"), true);
assert.equal(mathAnnotationXmlHtmlIntegrationProcessor.get_namespace(), "html");
assert.deepEqual(mathAnnotationXmlHtmlIntegrationProcessor.get_breadcrumbs(), ["HTML", "ANNOTATION-XML", "DIV"]);
mathAnnotationXmlHtmlIntegrationProcessor.destroy();

const svgTableNameProcessor = WP_HTML_Processor.create_fragment("<svg><tr><td>cell");
assert.equal(svgTableNameProcessor.next_tag("tr"), true);
assert.equal(svgTableNameProcessor.get_namespace(), "svg");
assert.deepEqual(svgTableNameProcessor.get_breadcrumbs(), ["HTML", "BODY", "SVG", "TR"]);
assert.equal(svgTableNameProcessor.next_tag("td"), true);
assert.equal(svgTableNameProcessor.get_namespace(), "svg");
assert.deepEqual(svgTableNameProcessor.get_breadcrumbs(), ["HTML", "BODY", "SVG", "TR", "TD"]);
svgTableNameProcessor.destroy();
assert.equal(WP_HTML_Processor.normalize("<svg><tr><td>cell"), "<svg><tr><td>cell</td></tr></svg>");

const svgTableNameInCellProcessor = WP_HTML_Processor.create_fragment("<table><tr><td><svg><tr><circle>");
assert.equal(svgTableNameInCellProcessor.next_tag("svg"), true);
assert.equal(svgTableNameInCellProcessor.get_namespace(), "svg");
assert.deepEqual(svgTableNameInCellProcessor.get_breadcrumbs(), ["HTML", "BODY", "TABLE", "TBODY", "TR", "TD", "SVG"]);
assert.equal(svgTableNameInCellProcessor.next_tag("tr"), true);
assert.equal(svgTableNameInCellProcessor.get_namespace(), "svg");
assert.deepEqual(svgTableNameInCellProcessor.get_breadcrumbs(), ["HTML", "BODY", "TABLE", "TBODY", "TR", "TD", "SVG", "TR"]);
svgTableNameInCellProcessor.destroy();
assert.equal(
	WP_HTML_Processor.normalize("<table><tr><td><svg><tr><circle>"),
	"<table><tbody><tr><td><svg><tr><circle></circle></tr></svg></td></tr></tbody></table>",
);
assert.equal(WP_HTML_Processor.normalize("<svg><!DOCTYPE html></svg>"), "<svg></svg>");

const tableRowMathFragmentProcessor = WP_HTML_Processor.create_fragment("<math><tr><td><mo><tr>", "<tr>");
assert.equal(tableRowMathFragmentProcessor.next_tag("math"), true);
assert.equal(tableRowMathFragmentProcessor.get_namespace(), "math");
assert.deepEqual(tableRowMathFragmentProcessor.get_breadcrumbs(), ["HTML", "TR", "MATH"]);
assert.equal(tableRowMathFragmentProcessor.next_tag("tr"), true);
assert.equal(tableRowMathFragmentProcessor.get_namespace(), "math");
assert.deepEqual(tableRowMathFragmentProcessor.get_breadcrumbs(), ["HTML", "TR", "MATH", "TR"]);
tableRowMathFragmentProcessor.destroy();

const tableSectionSvgFragmentProcessor = WP_HTML_Processor.create_fragment("<svg><thead><title><tbody>", "<thead>");
assert.equal(tableSectionSvgFragmentProcessor.next_tag("svg"), true);
assert.equal(tableSectionSvgFragmentProcessor.get_namespace(), "svg");
assert.deepEqual(tableSectionSvgFragmentProcessor.get_breadcrumbs(), ["HTML", "THEAD", "SVG"]);
assert.equal(tableSectionSvgFragmentProcessor.next_tag("thead"), true);
assert.equal(tableSectionSvgFragmentProcessor.get_namespace(), "svg");
assert.deepEqual(tableSectionSvgFragmentProcessor.get_breadcrumbs(), ["HTML", "THEAD", "SVG", "THEAD"]);
tableSectionSvgFragmentProcessor.destroy();

const foreignModifiableTextProcessor = WP_HTML_Processor.create_fragment("<svg><title>One</title></svg>");
assert.equal(foreignModifiableTextProcessor.next_tag("title"), true);
assert.equal(foreignModifiableTextProcessor.get_namespace(), "svg");
assert.equal(foreignModifiableTextProcessor.get_modifiable_text(), "");
assert.equal(foreignModifiableTextProcessor.set_modifiable_text("Two"), false);
assert.equal(foreignModifiableTextProcessor.get_updated_html(), "<svg><title>One</title></svg>");
foreignModifiableTextProcessor.destroy();

for (const [setText, expectedText, expectedHtml] of [
	["\nAFTER NEWLINE", "\nAFTER NEWLINE", "<textarea>\n\nAFTER NEWLINE</textarea>"],
	["\rCR", "\nCR", "<textarea>\n\nCR</textarea>"],
	["\r\nCR-N", "\nCR-N", "<textarea>\n\nCR-N</textarea>"],
]) {
	const textareaModifiableTextProcessor = WP_HTML_Processor.create_fragment("<textarea></textarea>");
	assert.equal(textareaModifiableTextProcessor.next_token(), true);
	assert.equal(textareaModifiableTextProcessor.set_modifiable_text(setText), true);
	assert.equal(textareaModifiableTextProcessor.get_modifiable_text(), expectedText);
	assert.equal(textareaModifiableTextProcessor.get_updated_html(), expectedHtml);
	textareaModifiableTextProcessor.destroy();
}

for (const [html, targetTag] of [
	["<div>", "DIV"],
	["<svg><path></path></svg>", "PATH"],
	["<svg><path /></svg>", "PATH"],
	["<math><mtext></mtext></math>", "MTEXT"],
	["<math><mspace /></math>", "MSPACE"],
	["<svg><textarea></textarea></svg>", "TEXTAREA"],
	["<svg><title></title></svg>", "TITLE"],
	["<svg><style></style></svg>", "STYLE"],
	["<svg><script></script></svg>", "SCRIPT"],
	["<math><textarea></textarea></math>", "TEXTAREA"],
	["<math><title></title></math>", "TITLE"],
	["<math><style></style></math>", "STYLE"],
	["<math><script></script></math>", "SCRIPT"],
]) {
	const nonAtomicModifiableTextProcessor = WP_HTML_Processor.create_fragment(html);
	assert.equal(nonAtomicModifiableTextProcessor.next_tag(targetTag), true);
	assert.equal(nonAtomicModifiableTextProcessor.set_modifiable_text("test"), false);
	assert.equal(nonAtomicModifiableTextProcessor.get_updated_html(), html);
	nonAtomicModifiableTextProcessor.destroy();
}

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

function assertNormalizesToSupportedHtml(name, html) {
	const normalized = WP_HTML_Processor.normalize(html);
	assert.equal(typeof normalized, "string", name);
	assert.equal(typeof WP_HTML_Processor.normalize(normalized), "string", name);
}

function assertNormalizesIdempotently(name, html) {
	const normalized = WP_HTML_Processor.normalize(html);
	assert.equal(typeof normalized, "string", name);
	assert.equal(WP_HTML_Processor.normalize(normalized), normalized, name);
}

for (const [name, html] of [
	["Unclosed SVG TITLE after P in EM", "<em><p><svg><title>"],
	["Unclosed SVG TITLE after P in STRONG", "<strong><p><svg ><title>"],
]) {
	assertNormalizesToSupportedHtml(name, html);
}

for (const [name, html] of [
	["Malformed quoted attribute boundary", '<A "/=>'],
	["Duplicate attribute after bare attribute", '<A V=5 R V=""=>'],
	["Duplicate DATA-ID after numeric attribute", '<E DATA-ID=1 1 DATA-ID=""=>'],
	["Duplicate attribute before tag end", "<R V=5 R V=5 =>"],
	["NULL byte in foreign tag name", "<SVG><L\0 D>"],
	["Malformed closing-looking attribute", "<a </=>"],
	["Malformed self-closing attribute", "<a h/=>"],
	["Duplicate ID with quote boundary", '<d ID=""" ID=""=>'],
	["Mixed-case duplicate TITLE", '<d TITLE=""\' title=""=>'],
	["Colon before self-closing slash", "<e :/=>"],
	["Duplicate class after bare attribute", "<e class=y d class=''=>"],
	["Duplicate DATA-ID after hyphen", '<e data-id=1 - data-id="">'],
	["Duplicate title after quotes", '<e title=\'\'\' title=""=>'],
	["FORM with SVG TITLE text edge", '<form ><svg ><title "\'></form><form>'],
	["FORM with TABLE and SCRIPT", '<form id><table te"><script></script><td srce" ID/></form><form claslicate>'],
	["FORM with TABLE CAPTION", "<form><table><caption></form><form >"],
	["Short malformed G attribute C", "<g c/=>"],
	["Short malformed G attribute S", "<g s/=>"],
	["Duplicate SRC boundary", '<g src=""g src="">'],
	["Short malformed H attribute", "<h f/=>"],
	["Malformed SRC equals boundary", '<i src=""= src=""=>'],
	["Malformed slash in tag opener", "<i/t/=>"],
	["Malformed L colon attribute", "<l :/=>"],
	["Malformed L less-than attribute", "<l/</=>"],
	["Malformed N less-than attribute", "<n </=>"],
	["Unclosed SVG TITLE after P", "<p><svg><title>"],
	["Duplicate ALT boundary", '<r alt=\'\'d alt=""=>'],
	["NULL byte in SVG child tag", "<svg><l\0 '>"],
	["NULL byte before slash in SVG child tag", "<svg><l\0/r>"],
]) {
	assertNormalizesIdempotently(name, html);
}

assert.equal(
	WP_HTML_Processor.normalize('<a href=#anchor enabled>Tom & Jerry</a>'),
	'<a href="#anchor" enabled>Tom &amp; Jerry</a>',
);
assert.equal(WP_HTML_Processor.normalize("apples > or\0anges"), "apples &gt; oranges");
assert.equal(WP_HTML_Processor.normalize("<>"), "&lt;&gt;");
assert.equal(WP_HTML_Processor.normalize("</>"), "");
assert.equal(
	WP_HTML_Processor.normalize('<![CDATA[invalid comment]]> syntax < <> "oddities" \'apostrophe\''),
	"<!--[CDATA[invalid comment]]--> syntax &lt; &lt;&gt; &quot;oddities&quot; &apos;apostrophe&apos;",
);
assert.equal(WP_HTML_Processor.normalize("<input disabled>"), "<input disabled>");
assert.equal(WP_HTML_Processor.normalize("<p id=3></p>"), '<p id="3"></p>');
assert.equal(WP_HTML_Processor.normalize('<br class="clear"/>'), '<br class="clear">');
assert.equal(WP_HTML_Processor.normalize('<div one=1 one="one" one=\'won\' one>'), '<div one="1"></div>');
assert.equal(WP_HTML_Processor.normalize("<script>apples > or\0anges</script>"), "<script>apples > or\uFFFDanges</script>");
assert.equal(WP_HTML_Processor.normalize("<style>apples > or\0anges</style>"), "<style>apples > or\uFFFDanges</style>");
assert.equal(WP_HTML_Processor.normalize("one</div>two</span>three"), "onetwothree");
assert.equal(WP_HTML_Processor.normalize("<div><p>One"), "<div><p>One</p></div>");
for (const [input, expected] of [
	["<pre>\nline 1\nline 2</pre>", "<pre>line 1\nline 2</pre>"],
	["<pre>\n\nline 2\nline 3</pre>", "<pre>\n\nline 2\nline 3</pre>"],
	["<pre>\nline 1<!--comment--> still line 1</pre>", "<pre>line 1<!--comment--> still line 1</pre>"],
	["<pre>\n\nline 2<!--comment--> still line 2</pre>", "<pre>\n\nline 2<!--comment--> still line 2</pre>"],
	["<listing>\nline 1\nline 2</listing>", "<listing>line 1\nline 2</listing>"],
	["<listing>\n\nline 2\nline 3</listing>", "<listing>\n\nline 2\nline 3</listing>"],
	["<listing>\nline 1<!--comment--> still line 1</listing>", "<listing>line 1<!--comment--> still line 1</listing>"],
	["<listing>\n\nline 2<!--comment--> still line 2</listing>", "<listing>\n\nline 2<!--comment--> still line 2</listing>"],
	["<textarea>\nline 1\nline 2</textarea>", "<textarea>line 1\nline 2</textarea>"],
	["<textarea>\n\nline 2\nline 3</textarea>", "<textarea>\n\nline 2\nline 3</textarea>"],
]) {
	assert.equal(WP_HTML_Processor.normalize(input), expected);
	assert.equal(WP_HTML_Processor.normalize(expected), expected);
}
assert.equal(WP_HTML_Processor.normalize("<table><td>cell"), "<table><tbody><tr><td>cell</td></tr></tbody></table>");
assert.equal(WP_HTML_Processor.normalize("<table><tr><td>cell"), "<table><tbody><tr><td>cell</td></tr></tbody></table>");
assert.equal(WP_HTML_Processor.normalize("<table><td>a<td>b"), "<table><tbody><tr><td>a</td><td>b</td></tr></tbody></table>");
assert.equal(WP_HTML_Processor.normalize("<table><tr><td>a<tr><td>b"), "<table><tbody><tr><td>a</td></tr><tr><td>b</td></tr></tbody></table>");
assert.equal(WP_HTML_Processor.normalize("<table><tbody><tr><td>a<tbody><tr><td>b"), "<table><tbody><tr><td>a</td></tr></tbody><tbody><tr><td>b</td></tr></tbody></table>");
assert.equal(WP_HTML_Processor.normalize("<table><tbody><tr><td>a</table><p>b"), "<table><tbody><tr><td>a</td></tr></tbody></table><p>b</p>");
assert.equal(WP_HTML_Processor.normalize("<div></p>fun<table><td>cell</div>"), "<div><p></p>fun<table><tbody><tr><td>cell</td></tr></tbody></table></div>");
assert.equal(WP_HTML_Processor.normalize("<div><span></div>"), "<div><span></span></div>");
assert.equal(WP_HTML_Processor.normalize("<svg><g><g /></svg>"), "<svg><g><g /></g></svg>");

for (const [htmlWithNulls, expected] of [
	["<img\0id=5>", "<img\uFFFDid=5></img\uFFFDid=5>"],
	["<img/\0id=5>", '<img \uFFFDid="5">'],
	["<img id='5\0'>", '<img id="5\uFFFD">'],
	["one\0two", "onetwo"],
	["<svg>one\0two</svg>", "<svg>one\uFFFDtwo</svg>"],
	["<script>alert(\0)</script>", "<script>alert(\uFFFD)</script>"],
	["<style>\0 {}</style>", "<style>\uFFFD {}</style>"],
	["<!-- \0 -->", "<!-- \uFFFD -->"],
]) {
	assert.equal(WP_HTML_Processor.normalize(htmlWithNulls), expected);
}

for (const [doctypeInput, doctypeOutput] of [
	["", ""],
	["<!DOCTYPE>", "<!DOCTYPE>"],
	["<!DOCTYPE html>", "<!DOCTYPE html>"],
	["<!DOCTYPE WordPress>", "<!DOCTYPE wordpress>"],
	['<!DOCTYPE html PUBLIC "x">', '<!DOCTYPE html PUBLIC "x">'],
	['<!DOCTYPE html SYSTEM "y">', '<!DOCTYPE html SYSTEM "y">'],
	['<!DOCTYPE html PUBLIC "x" "y">', '<!DOCTYPE html PUBLIC "x" "y">'],
	['<!docType HtmL pubLIc\'xxx\'"yyy" all this is ignored>', '<!DOCTYPE html PUBLIC "xxx" "yyy">'],
	['<!DOCTYPE html PUBLIC "\'quoted\'">', '<!DOCTYPE html PUBLIC "\'quoted\'">'],
	['<!DOCTYPE html PUBLIC \'"quoted"\'>', '<!DOCTYPE html PUBLIC \'"quoted"\'>'],
	['<!DOCTYPE html SYSTEM "\'quoted\'">', '<!DOCTYPE html SYSTEM "\'quoted\'">'],
	['<!DOCTYPE html SYSTEM \'"quoted"\'>', '<!DOCTYPE html SYSTEM \'"quoted"\'>'],
]) {
	const fullParserSerializeDoctype = WP_HTML_Processor.create_full_parser(`${doctypeInput}👌`);
	assert.equal(
		fullParserSerializeDoctype.serialize(),
		`${doctypeOutput}<html><head></head><body>👌</body></html>`,
	);
	fullParserSerializeDoctype.destroy();
}

const fullParserFosteredTextBeforeTable = WP_HTML_Processor.create_full_parser(
	"<table class=x data-id=1>a<!doctype html>",
);
assert.equal(
	fullParserFosteredTextBeforeTable.serialize(),
	'<html><head></head><body>a<table class="x" data-id="1"></table></body></html>',
);
fullParserFosteredTextBeforeTable.destroy();

const fullParserFosteredTextBeforeTableHiddenInput = WP_HTML_Processor.create_full_parser(
	"<!doctype html><table>X<input type=hidDEN></table>",
);
assert.equal(
	fullParserFosteredTextBeforeTableHiddenInput.serialize(),
	'<!DOCTYPE html><html><head></head><body>X<table><input type="hidDEN"></table></body></html>',
);
fullParserFosteredTextBeforeTableHiddenInput.destroy();

const fullParserFosteredInputBeforeTable = WP_HTML_Processor.create_full_parser(
	"<table><input>",
);
assert.equal(
	fullParserFosteredInputBeforeTable.serialize(),
	"<html><head></head><body><input><table></table></body></html>",
);
fullParserFosteredInputBeforeTable.destroy();

const fullParserFosteredInputBeforeHiddenTableInput = WP_HTML_Processor.create_full_parser(
	'<!doctype html><table><input type=" hidden"><input type=hidDEN></table>',
);
assert.equal(
	fullParserFosteredInputBeforeHiddenTableInput.serialize(),
	'<!DOCTYPE html><html><head></head><body><input type=" hidden"><table><input type="hidDEN"></table></body></html>',
);
fullParserFosteredInputBeforeHiddenTableInput.destroy();

const fullParserFosteredTextBeforeTableMeta = WP_HTML_Processor.create_full_parser(
	"<!doctype html><table> X<meta></table>",
);
assert.equal(
	fullParserFosteredTextBeforeTableMeta.serialize(),
	"<!DOCTYPE html><html><head></head><body> X<meta><table></table></body></html>",
);
fullParserFosteredTextBeforeTableMeta.destroy();

const fullParserFosteredSvgBeforeTable = WP_HTML_Processor.create_full_parser(
	"<!doctype html><table><svg><g>foo</g></svg></table>",
);
assert.equal(
	fullParserFosteredSvgBeforeTable.serialize(),
	"<!DOCTYPE html><html><head></head><body><svg><g>foo</g></svg><table></table></body></html>",
);
fullParserFosteredSvgBeforeTable.destroy();

const fullParserFosteredSelectBeforeTable = WP_HTML_Processor.create_full_parser(
	"<table><select><option>3</select></table>",
);
assert.equal(
	fullParserFosteredSelectBeforeTable.serialize(),
	"<html><head></head><body><select><option>3</option></select><table></table></body></html>",
);
fullParserFosteredSelectBeforeTable.destroy();

const fullParserFosteredSvgBeforeTableSection = WP_HTML_Processor.create_full_parser(
	"<!doctype html><table><tbody><tr><svg><g>foo</g></svg></tr></tbody></table>",
);
assert.equal(
	fullParserFosteredSvgBeforeTableSection.serialize(),
	"<!DOCTYPE html><html><head></head><body><svg><g>foo</g></svg><table><tbody><tr></tr></tbody></table></body></html>",
);
fullParserFosteredSvgBeforeTableSection.destroy();

const fullParserFosteredTextBeforeTableComment = WP_HTML_Processor.create_full_parser(
	"<!doctype html><table>abc<!--foo-->",
);
assert.equal(
	fullParserFosteredTextBeforeTableComment.serialize(),
	"<!DOCTYPE html><html><head></head><body>abc<table><!--foo--></table></body></html>",
);
fullParserFosteredTextBeforeTableComment.destroy();

const fullParserFosteredMetaBeforeTable = WP_HTML_Processor.create_full_parser(
	"<!doctype html><table><meta></table>",
);
assert.equal(
	fullParserFosteredMetaBeforeTable.serialize(),
	"<!DOCTYPE html><html><head></head><body><meta><table></table></body></html>",
);
fullParserFosteredMetaBeforeTable.destroy();

const fullParserFosteredTitleBeforeTable = WP_HTML_Processor.create_full_parser(
	"<!doctype html><table><title>X</title></table>",
);
assert.equal(
	fullParserFosteredTitleBeforeTable.serialize(),
	"<!DOCTYPE html><html><head></head><body><title>X</title><table></table></body></html>",
);
fullParserFosteredTitleBeforeTable.destroy();

const fullParserFosteredTextBeforeTableRow = WP_HTML_Processor.create_full_parser(
	"<!doctype html><table><tr> x</table>",
);
assert.equal(
	fullParserFosteredTextBeforeTableRow.serialize(),
	"<!DOCTYPE html><html><head></head><body> x<table><tbody><tr></tr></tbody></table></body></html>",
);
fullParserFosteredTextBeforeTableRow.destroy();

const fullParserFosteredAnchorTextAfterTableCell = WP_HTML_Processor.create_full_parser(
	'<a href="blah">aba<table><tr><td><a href="foo">br</td></tr>x</table>aoe',
);
assert.equal(
	fullParserFosteredAnchorTextAfterTableCell.serialize(),
	'<html><head></head><body><a href="blah">abax<table><tbody><tr><td><a href="foo">br</tbody></table>aoe</a></body></html>',
);
fullParserFosteredAnchorTextAfterTableCell.destroy();

const fullParserReconstructedAnchorTextAfterTableCell = WP_HTML_Processor.create_full_parser(
	'<table><a href="blah">aba<tr><td><a href="foo">br</td></tr>x</table>aoe',
);
assert.equal(
	fullParserReconstructedAnchorTextAfterTableCell.serialize(),
	'<html><head></head><body><a href="blah">aba</a><a href="blah">x</a><table><tbody><tr><td><a href="foo">br</tbody></table><a href="blah">aoe</a></body></html>',
);
fullParserReconstructedAnchorTextAfterTableCell.destroy();

const fullParserNestedFosteredAnchorBeforeTableRow = WP_HTML_Processor.create_full_parser(
	'<a href="blah">aba<table><a href="foo">br<tr><td></td></tr>x</table>aoe',
);
assert.equal(
	fullParserNestedFosteredAnchorBeforeTableRow.serialize(),
	'<html><head></head><body><a href="blah">aba<a href="foo">br</a><a href="foo">x</a><table><tbody><tr><td></tbody></table></a><a href="foo">aoe</a></body></html>',
);
fullParserNestedFosteredAnchorBeforeTableRow.destroy();

const fullParserNestedFosteredAnchorBeforeTableEnd = WP_HTML_Processor.create_full_parser(
	"<a><table><a></table><p><a><div><a>",
);
const fullParserNestedFosteredAnchorBeforeTableEndStarts = [];
while (fullParserNestedFosteredAnchorBeforeTableEnd.next_token()) {
	if (
		fullParserNestedFosteredAnchorBeforeTableEnd.get_token_type() === "#tag" &&
		!fullParserNestedFosteredAnchorBeforeTableEnd.is_tag_closer() &&
		["A", "DIV", "P", "TABLE"].includes(fullParserNestedFosteredAnchorBeforeTableEnd.get_tag())
	) {
		fullParserNestedFosteredAnchorBeforeTableEndStarts.push([
			fullParserNestedFosteredAnchorBeforeTableEnd.get_tag(),
			fullParserNestedFosteredAnchorBeforeTableEnd.get_breadcrumbs(),
		]);
	}
}
assert.equal(fullParserNestedFosteredAnchorBeforeTableEnd.get_last_error(), null);
assert.deepEqual(fullParserNestedFosteredAnchorBeforeTableEndStarts, [
	["A", ["HTML", "BODY", "A"]],
	["A", ["HTML", "BODY", "A", "A"]],
	["TABLE", ["HTML", "BODY", "A", "TABLE"]],
	["P", ["HTML", "BODY", "P"]],
	["A", ["HTML", "BODY", "P", "A"]],
	["DIV", ["HTML", "BODY", "DIV"]],
	["A", ["HTML", "BODY", "DIV", "A"]],
]);
fullParserNestedFosteredAnchorBeforeTableEnd.destroy();

const fullParserFosteredDivBeforeTableRow = WP_HTML_Processor.create_full_parser(
	"<table><tr><div>",
);
assert.equal(
	fullParserFosteredDivBeforeTableRow.serialize(),
	"<html><head></head><body><div></div><table><tbody><tr></tr></tbody></table></body></html>",
);
fullParserFosteredDivBeforeTableRow.destroy();

const fullParserFosteredDivBeforeTableCell = WP_HTML_Processor.create_full_parser(
	"<table><tr><div><td>",
);
assert.equal(
	fullParserFosteredDivBeforeTableCell.serialize(),
	"<html><head></head><body><div></div><table><tbody><tr><td></td></tr></tbody></table></body></html>",
);
fullParserFosteredDivBeforeTableCell.destroy();

const fullParserWhitespaceBeforeFosteredCenterTableCell = WP_HTML_Processor.create_full_parser(
	"<table>\n<tr><center><td>",
);
assert.equal(
	fullParserWhitespaceBeforeFosteredCenterTableCell.serialize(),
	"<html><head></head><body><center></center><table>\n<tbody><tr><td></td></tr></tbody></table></body></html>",
);
fullParserWhitespaceBeforeFosteredCenterTableCell.destroy();

const fullParserFosteredListItemsBeforeTable = WP_HTML_Processor.create_full_parser(
	"<table><li><li></table>",
);
assert.equal(
	fullParserFosteredListItemsBeforeTable.serialize(),
	"<html><head></head><body><li></li><li></li><table></table></body></html>",
);
fullParserFosteredListItemsBeforeTable.destroy();

const fullParserFosteredParagraphsBeforeTable = WP_HTML_Processor.create_full_parser(
	"<table><tr><p><a><p>You should see this text.",
);
assert.equal(
	fullParserFosteredParagraphsBeforeTable.serialize(),
	"<html><head></head><body><p><a></a></p><p><a>You should see this text.</a></p><table><tbody><tr></tr></tbody></table></body></html>",
);
fullParserFosteredParagraphsBeforeTable.destroy();

const fullParserFosteredItalicDivBeforeTable = WP_HTML_Processor.create_full_parser(
	"<!doctype html><table><i>a<b>b<div>c</i>",
);
assert.equal(
	fullParserFosteredItalicDivBeforeTable.serialize(),
	"<!DOCTYPE html><html><head></head><body><i>a<b>b</b></i><b><div><i>c</i></div></b><table></table></body></html>",
);
fullParserFosteredItalicDivBeforeTable.destroy();

const fullParserFosteredForeignSelectTable = WP_HTML_Processor.create_full_parser(
	"<div><table><svg><foreignObject><select><table><s>",
);
assert.equal(
	fullParserFosteredForeignSelectTable.serialize(),
	"<html><head></head><body><div><svg><foreignObject><select></select></foreignObject></svg><table></table><s></s><table></table></div></body></html>",
);
fullParserFosteredForeignSelectTable.destroy();

const fullParserFosteredTextBeforeTableColgroup = WP_HTML_Processor.create_full_parser(
	"<table><colgroup>foo",
);
assert.equal(
	fullParserFosteredTextBeforeTableColgroup.serialize(),
	"<html><head></head><body>foo<table><colgroup></colgroup></table></body></html>",
);
fullParserFosteredTextBeforeTableColgroup.destroy();

const fullParserSplitFosteredTextBeforeTableColgroup = WP_HTML_Processor.create_full_parser(
	"<table><colgroup> foo</colgroup></table>",
);
assert.equal(
	fullParserSplitFosteredTextBeforeTableColgroup.serialize(),
	"<html><head></head><body>foo<table><colgroup> </colgroup></table></body></html>",
);
fullParserSplitFosteredTextBeforeTableColgroup.destroy();

const fullParserFosteredTextAfterIgnoredHtmlEndInTableColgroup = WP_HTML_Processor.create_full_parser(
	"<table><colgroup></html>foo",
);
assert.equal(
	fullParserFosteredTextAfterIgnoredHtmlEndInTableColgroup.serialize(),
	"<html><head></head><body>foo<table><colgroup></colgroup></table></body></html>",
);
fullParserFosteredTextAfterIgnoredHtmlEndInTableColgroup.destroy();

for (const incompleteToken of [
	"<!--",
	"<!--x",
	"<!--x--",
	"<!--x--!",
	"<!--x--! >",
	"<![sneaky[",
	"</3 is not a tag",
	"<!DOCTYPE html",
	"<!DOCTY",
	"<![CDATA[something inside of here needs to get out",
	"<![CDA",
	"<![CDATA[cannot escape]",
	"<my-custom status=\"pending\"",
	"<script>",
	"<script><div>",
	"<script><!--<script></script>",
	"<style><div>",
	"<textarea><div>",
	"<title><div>",
	"<xmp><div>",
]) {
	assert.equal(WP_HTML_Processor.normalize(`content${incompleteToken}`), "content");
}

const serializationProcessor = WP_HTML_Processor.create_fragment("<textarea>One & Two</textarea>");
assert.equal(serializationProcessor.next_token(), true);
assert.equal(serializationProcessor.serialize(), null);
assert.equal(serializationProcessor.serialize_token(), "<textarea>One &amp; Two</textarea>");
serializationProcessor.destroy();

console.log("WASM smoke tests passed.");
