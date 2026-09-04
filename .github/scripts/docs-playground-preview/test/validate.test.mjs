import assert from 'node:assert/strict';
import { createServer } from 'node:http';
import { test } from 'node:test';

import { inspectSnapshotBehavior } from '../lib/validate.mjs';

const provenance = {
	sourceRepository: 'example/wordpress-develop',
	sourceSha: 'a'.repeat( 40 ),
	generationTimestamp: '2026-08-09T12:34:56.000Z',
	runUrl: 'https://github.com/example/wordpress-develop/actions/runs/123',
};

const inputs = {
	wordpress: { version: '7.2-beta1' },
	dependencies: {
		playground: { phpVersion: '8.4' },
		validation: {
			routes: {
				index: { path: '/reference/', expectedText: 'Code Reference' },
				function: {
					path: '/reference/functions/example/',
					expectedText: 'example_function',
				},
			},
			search: {
				path: '/?s=example&post_type=wp-parser-function',
				expectedText: 'example_function',
				expectedPath: '/reference/functions/example/',
			},
		},
	},
};

const health = {
	import: { stage: 'complete-import' },
	outboundNetworkDisabled: true,
	constants: {
		DISABLE_WP_CRON: true,
		AUTOMATIC_UPDATER_DISABLED: true,
		WP_AUTO_UPDATE_CORE: true,
		DISALLOW_FILE_MODS: true,
	},
};

/**
 * @param {Record<string, any>} [overrides]
 */
function page( overrides = {} ) {
	const banner = `<aside id="wporg-code-reference-preview-provenance">${ provenance.sourceRepository } <code>${ provenance.sourceSha }</code> 2026-08-09 12:34:56 UTC <a href="${ provenance.runUrl }">Build run</a></aside>`;
	return {
		status: 200,
		body: `${ banner } example_function Code Reference <a href="/reference/functions/example/">example</a>`,
		...overrides,
	};
}

/**
 * Serves the pages the validator requests, so the checks run against a real
 * HTTP client and real redirects.
 *
 * @param {(pathname: string) => Record<string, any>} respond
 * @param {(baseUrl: string) => Promise<any>} inspect
 */
async function withServer( respond, inspect ) {
	const server = createServer( ( request, response ) => {
		const { pathname } = new URL( request.url || '/', 'http://127.0.0.1' );
		const page = respond( pathname );
		if ( page.location ) {
			response.writeHead( 302, { location: page.location } );
			response.end();
			return;
		}
		response.writeHead( page.status, {
			'content-type': page.json
				? 'application/json'
				: 'text/html; charset=utf-8',
		} );
		response.end( page.json ? JSON.stringify( page.json ) : page.body );
	} );
	await new Promise( ( resolve ) =>
		server.listen( 0, '127.0.0.1', () => resolve( undefined ) )
	);
	const address = server.address();
	const port = typeof address === 'object' && address ? address.port : 0;
	try {
		return await inspect( `http://127.0.0.1:${ port }` );
	} finally {
		server.close();
	}
}

/**
 * @param {(pathname: string) => Record<string, any>} respond
 */
function validate( respond ) {
	return withServer( respond, ( baseUrl ) =>
		inspectSnapshotBehavior( inputs, baseUrl, provenance )
	);
}

/**
 * @param {string} pathname
 */
function healthySite( pathname ) {
	return pathname === '/wp-json/docs-preview/v1/health'
		? { status: 200, json: health }
		: page();
}

test( 'a complete preview reports no failures', async () => {
	const result = await validate( healthySite );
	assert.deepEqual( result.failures, [] );
} );

test( 'an incomplete import and a relaxed runtime policy are reported', async () => {
	const result = await validate( ( pathname ) =>
		pathname === '/wp-json/docs-preview/v1/health'
			? {
					status: 200,
					json: {
						import: null,
						outboundNetworkDisabled: false,
						constants: {
							...health.constants,
							DISABLE_WP_CRON: false,
						},
					},
			  }
			: page()
	);
	assert.deepEqual( result.failures, [
		'The Code Reference import did not complete.',
		'WordPress outbound networking is not disabled.',
		'Runtime policy constant DISABLE_WP_CRON is not enforced.',
	] );
} );

test( 'a missing symbol page is reported', async () => {
	const result = await validate( ( pathname ) =>
		pathname === '/reference/functions/example/'
			? page( { status: 404, body: 'Not found' } )
			: healthySite( pathname )
	);
	assert.deepEqual( result.failures, [
		'function route returned HTTP 404: Not found',
		'function route is missing example_function.',
		`function page banner is missing id="wporg-code-reference-preview-provenance", ${ provenance.sourceRepository }, ${ provenance.sourceSha }, 2026-08-09 12:34:56 UTC, ${ provenance.runUrl }.`,
	] );
} );

test( 'a symbol page that redirects to the index is reported', async () => {
	const result = await validate( ( pathname ) =>
		pathname === '/reference/functions/example/'
			? { location: '/reference/' }
			: healthySite( pathname )
	);
	assert.deepEqual( result.failures, [
		'function route redirected away from its stable path.',
	] );
} );

test( 'search results that never reach the symbol are reported', async () => {
	const result = await validate( ( pathname ) =>
		pathname === '/'
			? page( { body: 'No results found.' } )
			: healthySite( pathname )
	);
	assert.deepEqual( result.failures, [
		'search route is missing example_function.',
		'search route is missing /reference/functions/example/.',
		`search page banner is missing id="wporg-code-reference-preview-provenance", ${ provenance.sourceRepository }, ${ provenance.sourceSha }, 2026-08-09 12:34:56 UTC, ${ provenance.runUrl }.`,
	] );
} );
