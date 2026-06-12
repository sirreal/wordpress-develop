import { createServer } from "node:http";
import { readFile } from "node:fs/promises";
import { dirname, extname, join, relative } from "node:path";
import { fileURLToPath } from "node:url";

let chromium;
try {
	({ chromium } = await import("@playwright/test"));
} catch (error) {
	throw new Error(
		"Browser smoke tests require the root @playwright/test dev dependency. Run npm install from the WordPress checkout root before npm run test:browser.",
		{ cause: error },
	);
}

const packageRoot = dirname(fileURLToPath(import.meta.url));
const mimeTypes = new Map([
	[".html", "text/html; charset=utf-8"],
	[".js", "text/javascript; charset=utf-8"],
	[".mjs", "text/javascript; charset=utf-8"],
	[".wasm", "application/wasm"],
]);

function filePathForRequest(requestUrl) {
	const url = new URL(requestUrl, "http://127.0.0.1");
	const pathname = decodeURIComponent(url.pathname);
	const relativePath = pathname === "/" ? "browser-test.html" : pathname.replace(/^\/+/, "");
	const filePath = join(packageRoot, relativePath);
	const relativeFilePath = relative(packageRoot, filePath);

	if (relativeFilePath.startsWith("..") || relativeFilePath === "") {
		return null;
	}

	return filePath;
}

const server = createServer(async (request, response) => {
	const filePath = filePathForRequest(request.url);
	if (filePath === null) {
		response.writeHead(403);
		response.end("Forbidden");
		return;
	}

	try {
		const body = await readFile(filePath);
		response.writeHead(200, {
			"Content-Length": body.byteLength,
			"Content-Type": mimeTypes.get(extname(filePath)) ?? "application/octet-stream",
		});
		response.end(body);
	} catch (error) {
		response.writeHead(error.code === "ENOENT" ? 404 : 500);
		response.end(error.message);
	}
});

function listen(server) {
	return new Promise((resolve, reject) => {
		server.once("error", reject);
		server.listen(0, "127.0.0.1", () => {
			server.off("error", reject);
			resolve();
		});
	});
}

function close(server) {
	return new Promise((resolve, reject) => {
		server.close((error) => {
			if (error) {
				reject(error);
				return;
			}
			resolve();
		});
	});
}

async function launchChromium() {
	const launchOptions = process.env.CI ? [{ channel: "chrome" }] : [{}, { channel: "chrome" }];
	let lastError = null;

	for (const options of launchOptions) {
		try {
			return await chromium.launch({ headless: true, ...options });
		} catch (error) {
			lastError = error;
		}
	}

	throw lastError;
}

await listen(server);

const { port } = server.address();
const testUrl = `http://127.0.0.1:${port}/browser-test.html`;
let browser = null;

try {
	browser = await launchChromium();
	const page = await browser.newPage();
	const browserMessages = [];

	page.on("console", (message) => {
		browserMessages.push(`[${message.type()}] ${message.text()}`);
	});
	page.on("pageerror", (error) => {
		browserMessages.push(`[pageerror] ${error.stack ?? error.message}`);
	});

	await page.goto(testUrl, { waitUntil: "domcontentloaded" });
	const resultHandle = await page.waitForFunction(
		() => window.__wpHtmlApiRustWasmBrowserSmoke?.status !== "running",
		null,
		{ timeout: 30_000 },
	);
	const result = await resultHandle.jsonValue();

	if (result.status !== "passed") {
		throw new Error(
			[
				`WASM browser smoke test failed: ${result.message ?? "unknown failure"}`,
				result.stack,
				browserMessages.join("\n"),
			].filter(Boolean).join("\n\n"),
		);
	}

	console.log(`WASM browser smoke tests passed (${result.assertions} assertions).`);
} finally {
	if (browser !== null) {
		await browser.close();
	}
	await close(server);
}
