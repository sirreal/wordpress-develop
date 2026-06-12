import assert from "node:assert/strict";
import { execFile } from "node:child_process";
import { mkdtemp, readFile, rm, writeFile } from "node:fs/promises";
import { tmpdir } from "node:os";
import { dirname, join } from "node:path";
import { promisify } from "node:util";
import { fileURLToPath } from "node:url";

const execFileAsync = promisify(execFile);
const packageRoot = dirname(fileURLToPath(import.meta.url));
const workspace = await mkdtemp(join(tmpdir(), "wp-html-api-rust-wasm-package-"));
const npmCache = join(workspace, ".npm-cache");
const npmEnv = {
	...process.env,
	npm_config_cache: npmCache,
};

function parseNpmJsonArray(stdout) {
	const start = stdout.lastIndexOf("\n[");
	return JSON.parse(stdout.slice(start === -1 ? stdout.indexOf("[") : start + 1));
}

try {
	const { stdout } = await execFileAsync(
		"npm",
		["pack", "--json", "--pack-destination", workspace],
		{
			cwd: packageRoot,
			env: npmEnv,
			maxBuffer: 1024 * 1024,
		},
	);
	const [pack] = parseNpmJsonArray(stdout);
	assert.deepEqual(pack.files.map(({ path }) => path).sort(), [
		"README.md",
		"dist/wp_html_api_rust_core.wasm",
		"package.json",
		"wp-html-api-rust.d.ts",
		"wp-html-api-rust.js",
		"wp_html_api_rust_core.wasm.d.ts",
	]);

	const tarballPath = join(workspace, pack.filename);
	await writeFile(join(workspace, "package.json"), "{\"private\":true,\"type\":\"module\"}\n");
	await execFileAsync(
		"npm",
		["install", "--ignore-scripts", "--no-audit", "--no-fund", tarballPath],
		{
			cwd: workspace,
			env: npmEnv,
			maxBuffer: 1024 * 1024,
		},
	);
	const installedReadme = await readFile(
		join(workspace, "node_modules", "wp-html-api-rust-wasm", "README.md"),
		"utf8",
	);
	assert.match(installedReadme, /loadWasm\(\)/);
	assert.match(installedReadme, /WP_HTML_Processor/);
	const installedTypes = await readFile(
		join(workspace, "node_modules", "wp-html-api-rust-wasm", "wp-html-api-rust.d.ts"),
		"utf8",
	);
	assert.match(installedTypes, /get_updated_html\(flushClassNameUpdates\?: boolean\): string;/);

	await writeFile(
		join(workspace, "consumer.mjs"),
		[
			'import assert from "node:assert/strict";',
			'import { loadWasm, WP_HTML_Span } from "wp-html-api-rust-wasm";',
			"assert.equal(new WP_HTML_Span(3, 4).start, 3);",
			"const api = await loadWasm();",
			'assert.equal(api.version(), "0.1.0");',
			'const processor = api.WP_HTML_Processor.create_fragment("<main><p>Packaged");',
			"assert.notEqual(processor, null);",
			'assert.equal(processor.next_tag("p"), true);',
			'assert.deepEqual(processor.get_breadcrumbs(), ["HTML", "BODY", "MAIN", "P"]);',
			"processor.destroy();",
			'assert.match(import.meta.resolve("wp-html-api-rust-wasm/dist/wp_html_api_rust_core.wasm"), /wp_html_api_rust_core\\.wasm$/);',
			"",
		].join("\n"),
	);
	await execFileAsync(process.execPath, ["consumer.mjs"], {
		cwd: workspace,
		env: process.env,
		maxBuffer: 1024 * 1024,
	});

	console.log("WASM package artifact tests passed.");
} finally {
	await rm(workspace, { recursive: true, force: true });
}
