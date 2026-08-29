import { spawn } from "node:child_process";
import { createServer } from "node:http";
import { access, mkdtemp, readFile, rm } from "node:fs/promises";
import { tmpdir } from "node:os";
import { dirname, extname, join, relative } from "node:path";
import { fileURLToPath } from "node:url";

let chromium;
try {
	({ chromium } = await import("@playwright/test"));
} catch {
	chromium = null;
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

function browserCandidates() {
	const candidates = [];
	if (process.env.CHROME_BIN) {
		candidates.push(process.env.CHROME_BIN);
	}

	if (process.platform === "darwin") {
		candidates.push(
			"/Applications/Google Chrome.app/Contents/MacOS/Google Chrome",
			"/Applications/Chromium.app/Contents/MacOS/Chromium",
			"/Applications/Microsoft Edge.app/Contents/MacOS/Microsoft Edge",
		);
	} else if (process.platform === "win32") {
		const programFiles = [
			process.env.PROGRAMFILES,
			process.env["PROGRAMFILES(X86)"],
			process.env.LOCALAPPDATA,
		].filter(Boolean);
		for (const directory of programFiles) {
			candidates.push(
				join(directory, "Google", "Chrome", "Application", "chrome.exe"),
				join(directory, "Microsoft", "Edge", "Application", "msedge.exe"),
			);
		}
	} else {
		candidates.push(
			"google-chrome",
			"google-chrome-stable",
			"chromium",
			"chromium-browser",
			"microsoft-edge",
			"microsoft-edge-stable",
		);
	}

	return [...new Set(candidates)];
}

async function commandExists(command) {
	if (command.includes("/") || command.includes("\\")) {
		try {
			await access(command);
			return true;
		} catch {
			return false;
		}
	}

	return true;
}

function smokeResultFromDom(dom) {
	const status = dom.match(/<html\b[^>]*\bdata-status="([^"]+)"/)?.[1] ?? null;
	const assertions = Number.parseInt(dom.match(/Passed (\d+) assertions\./)?.[1] ?? "", 10);

	if (status === null) {
		return null;
	}

	return {
		assertions: Number.isNaN(assertions) ? 0 : assertions,
		status,
	};
}

function sleep(ms) {
	return new Promise((resolve) => {
		setTimeout(resolve, ms);
	});
}

async function removeTemporaryDirectory(directory) {
	for (let attempt = 0; attempt < 5; attempt += 1) {
		try {
			await rm(directory, { recursive: true, force: true });
			return;
		} catch (error) {
			if (attempt === 4) {
				console.warn(`Could not remove temporary browser profile ${directory}: ${error.message}`);
				return;
			}
			await sleep(100 * (attempt + 1));
		}
	}
}

function killBrowserProcess(child) {
	if (child.killed || child.exitCode !== null) {
		return;
	}

	try {
		if (process.platform !== "win32" && child.pid) {
			process.kill(-child.pid, "SIGTERM");
		} else {
			child.kill("SIGTERM");
		}
	} catch {
		child.kill("SIGTERM");
	}
}

function dumpDomWithBrowser(command, args) {
	return new Promise((resolve, reject) => {
		const child = spawn(command, args, {
			detached: process.platform !== "win32",
			stdio: ["ignore", "pipe", "pipe"],
		});
		let stdout = "";
		let stderr = "";
		let settled = false;
		let timeout = null;

		const settle = (callback, value) => {
			if (settled) {
				return;
			}
			settled = true;
			if (timeout !== null) {
				clearTimeout(timeout);
			}
			killBrowserProcess(child);
			callback(value);
		};

		timeout = setTimeout(() => {
			settle(
				reject,
				new Error(`Headless browser timed out while running WASM browser smoke test.\n${stderr}`),
			);
		}, 30_000);

		child.stdout.on("data", (chunk) => {
			stdout += chunk;
			const result = smokeResultFromDom(stdout);
			if (result?.status === "passed") {
				settle(resolve, { result, stderr, stdout });
			} else if (result?.status === "failed") {
				settle(reject, new Error(`WASM browser smoke test failed.\n${stdout}\n${stderr}`));
			}
		});
		child.stderr.on("data", (chunk) => {
			stderr += chunk;
		});
		child.on("error", (error) => {
			settle(reject, error);
		});
		child.on("close", (code, signal) => {
			if (settled) {
				return;
			}

			const result = smokeResultFromDom(stdout);
			if (result?.status === "passed") {
				settle(resolve, { result, stderr, stdout });
				return;
			}

			settle(
				reject,
				new Error(
					`Headless browser exited before the WASM browser smoke test passed: code ${code}, signal ${signal}.\n${stdout}\n${stderr}`,
				),
			);
		});
	});
}

async function runWithHeadlessBrowser(testUrl) {
	const profileDirectory = await mkdtemp(join(tmpdir(), "wp-html-api-rust-browser-"));
	const commonArgs = [
		"--disable-background-networking",
		"--disable-component-update",
		"--disable-crash-reporter",
		"--disable-extensions",
		"--disable-gpu",
		"--disable-sync",
		"--metrics-recording-only",
		"--no-default-browser-check",
		"--no-first-run",
		`--user-data-dir=${profileDirectory}`,
		"--virtual-time-budget=10000",
		"--dump-dom",
		testUrl,
	];
	let lastError = null;

	try {
		for (const command of browserCandidates()) {
			if (!await commandExists(command)) {
				continue;
			}

			for (const headlessArg of ["--headless=new", "--headless"]) {
				try {
					return await dumpDomWithBrowser(command, [headlessArg, ...commonArgs]);
				} catch (error) {
					lastError = error;
					if (error.message.startsWith("WASM browser smoke test failed.")) {
						throw error;
					}
				}
			}
		}
	} finally {
		await removeTemporaryDirectory(profileDirectory);
	}

	throw new Error(
		[
			"Browser smoke tests require either @playwright/test or an installed Chrome-compatible browser.",
			lastError?.message,
		].filter(Boolean).join("\n"),
	);
}

async function runWithPlaywright(testUrl) {
	const browser = await launchChromium();
	try {
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

		return result;
	} finally {
		await browser.close();
	}
}

await listen(server);

const { port } = server.address();
const testUrl = `http://127.0.0.1:${port}/browser-test.html`;

try {
	const result = chromium !== null
		? await runWithPlaywright(testUrl)
		: (await runWithHeadlessBrowser(testUrl)).result;

	console.log(`WASM browser smoke tests passed (${result.assertions} assertions).`);
} finally {
	await close(server);
}
