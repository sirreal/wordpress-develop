import { spawn } from 'node:child_process';
import { copyFile, mkdir, rm, writeFile } from 'node:fs/promises';
import path from 'node:path';

const BANNER_ID = 'wporg-code-reference-preview-provenance';
const HEALTH_ROUTE = '/wp-json/docs-preview/v1/health';
// The validation server is the only service the build job runs.
const PORT = 9400;
const BASE_URL = `http://127.0.0.1:${ PORT }`;
const BOOT_TIMEOUT_MS = 120_000;

/**
 * @param {number} milliseconds
 */
function delay( milliseconds ) {
	return new Promise( ( resolve ) => setTimeout( resolve, milliseconds ) );
}

/**
 * @param {unknown} error
 */
function message( error ) {
	return error instanceof Error ? error.message : String( error );
}

/**
 * @param {Record<string, any>} inputs
 */
function validationBlueprint( inputs ) {
	return {
		$schema: inputs.dependencies.playground.blueprintSchema,
		preferredVersions: {
			php: inputs.dependencies.playground.phpVersion,
			wp: inputs.wordpress.version,
		},
		landingPage: '/reference/',
		login: false,
		features: { networking: false },
		steps: [
			{
				step: 'unzip',
				zipFile: { resource: 'bundled', path: 'snapshot.zip' },
				extractToPath: '/',
			},
		],
	};
}

/**
 * Serves the finished snapshot from a temporary directory for the duration of
 * `inspect`.
 *
 * @param {Record<string, any>} inputs
 * @param {Record<string, any>} options
 * @param {(baseUrl: string) => Promise<any>} inspect
 */
async function withPlaygroundServer( inputs, options, inspect ) {
	const work = path.resolve( options.workDirectory );
	await rm( work, { recursive: true, force: true } );
	await mkdir( work, { recursive: true } );
	await copyFile( options.snapshot, path.join( work, 'snapshot.zip' ) );
	const blueprint = path.join( work, 'validation-blueprint.json' );
	await writeFile(
		blueprint,
		`${ JSON.stringify( validationBlueprint( inputs ), null, 2 ) }\n`
	);

	const server = spawn(
		options.playgroundCli,
		[
			'server',
			'--php',
			inputs.dependencies.playground.phpVersion,
			'--wp',
			inputs.wordpress.version,
			'--blueprint',
			blueprint,
			'--blueprint-may-read-adjacent-files',
			'--site-url',
			BASE_URL,
			'--port',
			String( PORT ),
			// Enough workers that one slow request cannot stall the checks.
			'--workers',
			'6',
			'--verbosity',
			'normal',
		],
		{ stdio: [ 'ignore', 'pipe', 'pipe' ] }
	);
	let log = '';
	server.stdout?.setEncoding( 'utf8' );
	server.stderr?.setEncoding( 'utf8' );
	server.stdout?.on( 'data', ( chunk ) => {
		log += chunk;
	} );
	server.stderr?.on( 'data', ( chunk ) => {
		log += chunk;
	} );
	let running = true;
	const closed = new Promise( ( resolve ) => {
		server.once( 'error', ( error ) => {
			log += message( error );
			resolve( undefined );
		} );
		server.once( 'close', resolve );
	} ).then( () => {
		running = false;
	} );

	try {
		const deadline = Date.now() + BOOT_TIMEOUT_MS;
		for (;;) {
			if ( ! running ) {
				throw new Error( `Playground exited before boot.\n${ log }` );
			}
			if ( Date.now() > deadline ) {
				throw new Error(
					`Playground did not boot within ${
						BOOT_TIMEOUT_MS / 1000
					} seconds.\n${ log }`
				);
			}
			try {
				if ( ( await fetch( `${ BASE_URL }${ HEALTH_ROUTE }` ) ).ok ) {
					break;
				}
			} catch {
				// The server is not accepting requests yet.
			}
			await delay( 250 );
		}
		return await inspect( BASE_URL );
	} finally {
		server.kill();
		// A server that ignored SIGTERM would hold the job to its timeout.
		const stopped = await Promise.race( [
			closed.then( () => true ),
			delay( 5000 ).then( () => false ),
		] );
		if ( ! stopped ) {
			server.kill( 'SIGKILL' );
			await closed;
		}
		await rm( work, { recursive: true, force: true } );
	}
}

/**
 * @param {string} name
 * @param {string} body
 * @param {Record<string, any>} provenance
 * @param {string[]} failures
 */
function checkBanner( name, body, provenance, failures ) {
	const timestamp = provenance.generationTimestamp
		.slice( 0, 19 )
		.replace( 'T', ' ' );
	const expected = [
		`id="${ BANNER_ID }"`,
		provenance.sourceRepository,
		provenance.sourceSha,
		`${ timestamp } UTC`,
		...( provenance.runUrl ? [ provenance.runUrl ] : [] ),
	];
	const missing = expected.filter( ( value ) => ! body.includes( value ) );
	if ( missing.length > 0 ) {
		failures.push(
			`${ name } page banner is missing ${ missing.join( ', ' ) }.`
		);
	}
}

/**
 * Requests the pages a reader of the preview actually opens and reports what
 * is wrong with them.
 *
 * @param {Record<string, any>} inputs
 * @param {string} baseUrl
 * @param {Record<string, any>} provenance
 */
export async function inspectSnapshotBehavior( inputs, baseUrl, provenance ) {
	/** @type {string[]} */
	const failures = [];

	try {
		const response = await fetch( `${ baseUrl }${ HEALTH_ROUTE }` );
		const health = await response.json();
		if ( health.import?.stage !== 'complete-import' ) {
			failures.push( 'The Code Reference import did not complete.' );
		}
		if ( health.outboundNetworkDisabled !== true ) {
			failures.push( 'WordPress outbound networking is not disabled.' );
		}
		for ( const name of [
			'DISABLE_WP_CRON',
			'AUTOMATIC_UPDATER_DISABLED',
			'WP_AUTO_UPDATE_CORE',
			'DISALLOW_FILE_MODS',
		] ) {
			if ( health.constants?.[ name ] !== true ) {
				failures.push(
					`Runtime policy constant ${ name } is not enforced.`
				);
			}
		}
	} catch ( error ) {
		failures.push( `Health route failed: ${ message( error ) }` );
	}

	const { routes, search } = inputs.dependencies.validation;
	const checks = [
		...Object.entries( routes ).map( ( [ name, route ] ) => ( {
			name,
			stablePath: true,
			...route,
		} ) ),
		{ name: 'search', stablePath: false, ...search },
	];
	for ( const check of checks ) {
		try {
			const response = await fetch( `${ baseUrl }${ check.path }` );
			const body = await response.text();
			if ( response.status !== 200 ) {
				// A Playground page that fails reports why in its own output,
				// and the build job has no other copy of that page.
				failures.push(
					`${ check.name } route returned HTTP ${
						response.status
					}: ${ body.replace( /\s+/g, ' ' ).trim().slice( 0, 300 ) }`
				);
			}
			if (
				check.stablePath &&
				new URL( response.url ).pathname !==
					new URL( check.path, baseUrl ).pathname
			) {
				failures.push(
					`${ check.name } route redirected away from its stable path.`
				);
			}
			for ( const expected of [
				check.expectedText,
				check.expectedPath,
			] ) {
				if ( expected && ! body.includes( expected ) ) {
					failures.push(
						`${ check.name } route is missing ${ expected }.`
					);
				}
			}
			checkBanner( check.name, body, provenance, failures );
		} catch ( error ) {
			failures.push(
				`${ check.name } route failed: ${ message( error ) }`
			);
		}
	}

	return { failures };
}

/**
 * @param {Record<string, any>} inputs
 * @param {{snapshot: string, workDirectory: string, playgroundCli: string, provenance: Record<string, any>}} options
 */
export async function validateSnapshot( inputs, options ) {
	try {
		return await withPlaygroundServer( inputs, options, ( baseUrl ) =>
			inspectSnapshotBehavior( inputs, baseUrl, options.provenance )
		);
	} catch ( error ) {
		return {
			failures: [ `Playground boot failed: ${ message( error ) }` ],
		};
	}
}
