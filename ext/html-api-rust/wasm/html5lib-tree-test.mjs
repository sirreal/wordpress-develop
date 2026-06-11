import assert from "node:assert/strict";
import { readdir, readFile } from "node:fs/promises";
import { loadWasm } from "./wp-html-api-rust.js";

const fixturesDirectory = new URL("../../../tests/phpunit/data/html5lib-tests/tree-construction/", import.meta.url);
const treeIndent = "  ";
const supportedFragmentContexts = new Set([
	"body",
	"div",
	"frameset",
	"head",
	"html",
	"math math",
	"math mi",
	"math mn",
	"math mo",
	"math ms",
	"math mtext",
	"select",
	"svg path",
	"svg svg",
	"template",
]);

const skippedTests = new Set([
	"foreign-fragment/line0001",
	"foreign-fragment/line0032",
	"foreign-fragment/line0169",
	"foreign-fragment/line0211",
	"foreign-fragment/line0253",
	"foreign-fragment/line0295",
	"foreign-fragment/line0337",
	"foreign-fragment/line0453",
	"foreign-fragment/line0590",
	"foreign-fragment/line0602",
	"foreign-fragment/line0614",
	"foreign-fragment/line0625",
	"foreign-fragment/line0636",
	"noscript01/line0014",
	"tests14/line0022",
	"tests14/line0055",
	"tests19/line0488",
	"tests19/line0500",
	"tests19/line1079",
	"tests2/line0207",
	"tests2/line0686",
	"tests2/line0697",
	"tests2/line0709",
	"tests20/line0536",
	"tests23/line0135",
	"tests26/line0001",
	"tests26/line0043",
	"tests26/line0106",
	"tests26/line0169",
	"tests26/line0198",
	"tests26/line0221",
	"tests26/line0242",
	"tests3/line0023",
	"webkit01/line0515",
	"webkit01/line0231",
]);

const {
	WP_HTML_Processor,
} = await loadWasm(new URL("./dist/wp_html_api_rust_core.wasm", import.meta.url));

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

function html5libTreeIndentLevel(path, baseDepth) {
	let level = Math.max(0, path.length - 1 - baseDepth);
	for (let i = baseDepth; i < path.length - 1; i += 1) {
		if (path[i].name === "TEMPLATE" && path[i].namespace === "html") {
			level += 1;
		}
	}
	return level;
}

function html5libFragmentContextMarkup(fragmentContext) {
	if (fragmentContext.startsWith("math ")) {
		const tagName = fragmentContext.slice("math ".length);
		return tagName === "math" ? "<math>" : `<math><${tagName}>`;
	}

	if (fragmentContext.startsWith("svg ")) {
		const tagName = fragmentContext.slice("svg ".length);
		return tagName === "svg" ? "<svg>" : `<svg><${tagName}>`;
	}

	return `<${fragmentContext}>`;
}

function html5libFragmentBaseDepth(fragmentContext) {
	if (fragmentContext === null) {
		return 0;
	}

	if (fragmentContext === "html") {
		return 1;
	}

	if (fragmentContext.startsWith("math ")) {
		return fragmentContext === "math math" ? 2 : 3;
	}

	if (fragmentContext.startsWith("svg ")) {
		return fragmentContext === "svg svg" ? 2 : 3;
	}

	return 2;
}

function buildHtml5libTree(fragmentContext, html) {
	const processor = fragmentContext === null
		? WP_HTML_Processor.create_full_parser(html)
		: WP_HTML_Processor.create_fragment(html, html5libFragmentContextMarkup(fragmentContext));
	assert.notEqual(processor, null);

	const baseDepth = html5libFragmentBaseDepth(fragmentContext);
	let output = "";
	let wasText = false;
	let textNode = "";
	let openElementPath = [];
	const indent = (level) => treeIndent.repeat(level);
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
				const tagIndent = html5libTreeIndentLevel(path, baseDepth);
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
				if (textContent !== "") {
					wasText = true;
					if (textNode === "") {
						textNode += `${indent(html5libTreeIndentLevel(path, baseDepth))}"`;
					}
					textNode += textContent;
				}
				openElementPath = path.slice(0, -1);
				break;
			}

			case "#funky-comment": {
				const path = pathFromBreadcrumbs(namespace, false);
				output += `${indent(html5libTreeIndentLevel(path, baseDepth))}<!-- ${processor.get_modifiable_text()} -->\n`;
				openElementPath = path.slice(0, -1);
				break;
			}

			case "#comment": {
				const path = pathFromBreadcrumbs(namespace, false);
				output += `${indent(html5libTreeIndentLevel(path, baseDepth))}<!-- ${processor.get_full_comment_text()} -->\n`;
				openElementPath = path.slice(0, -1);
				break;
			}

			default:
				throw new Error(`Unhandled token type for html5lib tree test: ${tokenType}`);
		}
	}

	if (textNode !== "") {
		output += `${textNode}"\n`;
	}

	const result = {
		tree: `${output}\n`,
		unsupported: processor.get_unsupported_exception(),
		error: processor.get_last_error(),
		incomplete: processor.paused_at_incomplete_token(),
	};
	processor.destroy();
	return result;
}

function* parseHtml5libDat(contents, suiteName) {
	const lines = contents.replace(/\r\n/g, "\n").split("\n");
	let state = null;
	let lineNumber = 0;
	let testLineNumber = 0;
	let testHtml = "";
	let expectedTree = "";
	let fragmentContext = null;
	let scripting = false;

	const finishTest = function* () {
		if (state !== null && !scripting) {
			yield {
				name: `${suiteName}/line${String(testLineNumber).padStart(4, "0")}`,
				fragmentContext,
				html: testHtml.endsWith("\n") ? testHtml.slice(0, -1) : testHtml,
				expectedTree,
			};
		}
	};

	for (const lineWithNewline of lines.map((line) => `${line}\n`)) {
		lineNumber += 1;

		if (lineWithNewline[0] === "#") {
			if (lineWithNewline === "#data\n") {
				yield* finishTest();
				testLineNumber = lineNumber;
				testHtml = "";
				expectedTree = "";
				fragmentContext = null;
				scripting = false;
			}

			if (lineWithNewline === "#script-on\n") {
				scripting = true;
			}

			state = lineWithNewline.slice(1).trim();
			continue;
		}

		switch (state) {
			case "data":
				testHtml += lineWithNewline;
				break;

			case "document-fragment":
				fragmentContext = lineWithNewline.trim();
				break;

			case "document":
				if (lineWithNewline[0] === "|") {
					expectedTree += lineWithNewline.slice(2);
				} else {
					expectedTree += lineWithNewline;
				}
				break;
		}
	}

	yield* finishTest();
}

const files = (await readdir(fixturesDirectory))
	.filter((file) => file.endsWith(".dat"))
	.sort();

const summary = {
	total: 0,
	tested: 0,
	skippedContext: 0,
	skippedKnown: 0,
	skippedUnsupported: 0,
	skippedIncomplete: 0,
	failed: 0,
};
const failures = [];
const staleSkippedTests = [];

for (const file of files) {
	const suiteName = file.slice(0, -4);
	const tests = parseHtml5libDat(
		await readFile(new URL(file, fixturesDirectory), "utf8"),
		suiteName,
	);

	for (const test of tests) {
		summary.total += 1;

		if (test.fragmentContext !== null && !supportedFragmentContexts.has(test.fragmentContext)) {
			summary.skippedContext += 1;
			continue;
		}

		if (skippedTests.has(test.name)) {
			const result = buildHtml5libTree(test.fragmentContext, test.html);
			if (
				result.unsupported === null &&
				result.error === null &&
				!result.incomplete &&
				result.tree === test.expectedTree
			) {
				staleSkippedTests.push(test.name);
				continue;
			}

			summary.skippedKnown += 1;
			continue;
		}

		const result = buildHtml5libTree(test.fragmentContext, test.html);
		if (result.unsupported !== null) {
			summary.skippedUnsupported += 1;
			continue;
		}

		if (result.error !== null || result.incomplete) {
			summary.skippedIncomplete += 1;
			continue;
		}

		summary.tested += 1;
		if (result.tree !== test.expectedTree) {
			summary.failed += 1;
			if (failures.length < 200) {
				failures.push(`${test.name}\n${test.html}`);
			}
		}
	}
}

assert.deepEqual(staleSkippedTests, [], "Remove passing tests from skippedTests.");
assert.ok(summary.tested > 1000, `Expected broad html5lib coverage, only tested ${summary.tested}.`);
assert.deepEqual(failures, [], `html5lib tree mismatches: ${summary.failed}`);
console.log(`WASM html5lib tree tests passed: ${JSON.stringify(summary)}`);
