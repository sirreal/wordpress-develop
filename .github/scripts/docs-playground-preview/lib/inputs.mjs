import { createHash } from 'node:crypto';
import { mkdir, readdir, readFile, writeFile } from 'node:fs/promises';
import path from 'node:path';

const DEPENDENCY_MANIFEST = '.github/docs-playground-preview/dependencies.json';
const VERSION_API =
	'https://api.wordpress.org/core/version-check/1.7/?channel=beta';
const BETA_VERSION = /^\d+\.\d+(?:\.\d+)?-(?:beta\d+|RC\d+)$/;
const STABLE_VERSION = /^\d+\.\d+(?:\.\d+)?$/;

// Anything under these two directories can change what the cached base
// contains, so the cache key covers them whole instead of naming the files that
// shape it. A file that turns out not to shape it costs one rebuild; a file
// left out of such a list would be restored from a stale cache instead.
const BASE_INPUT_ROOTS = [
	'.github/docs-playground-preview',
	'.github/scripts/docs-playground-preview',
];

// Installed packages are already pinned by package-lock.json, the type-check
// output is not an input, and the tests never run during a build.
const BASE_INPUT_SKIPPED = new Set( [
	'node_modules',
	'test',
	'tsconfig.tsbuildinfo',
] );

/**
 * Hashes every file below `directory` under its path relative to `root`, in a
 * fixed order so that two checkouts of the same content agree.
 *
 * @param {import('node:crypto').Hash} digest
 * @param {string} root
 * @param {string} directory
 */
async function digestTree( digest, root, directory ) {
	const entries = await readdir( directory, { withFileTypes: true } );
	for ( const entry of entries.sort( ( left, right ) =>
		left.name < right.name ? -1 : 1
	) ) {
		if ( BASE_INPUT_SKIPPED.has( entry.name ) ) {
			continue;
		}
		const filename = path.join( directory, entry.name );
		if ( entry.isDirectory() ) {
			await digestTree( digest, root, filename );
		} else if ( entry.isFile() ) {
			digest.update( path.relative( root, filename ) );
			digest.update( await readFile( filename ) );
		}
	}
}

/**
 * The preview runs the WordPress beta channel. Between releases that channel
 * offers no beta or RC build, so the preview follows the current stable release
 * until the next one appears.
 *
 * @param {(...args: any[]) => Promise<any>} [fetchImplementation]
 */
export async function resolveWordPressBeta(
	fetchImplementation = globalThis.fetch
) {
	const response = await fetchImplementation( VERSION_API, {
		headers: { Accept: 'application/json' },
		// A stalled connection without a deadline runs until the job timeout
		// kills it, leaving a preview reported as still building.
		signal: AbortSignal.timeout( 30_000 ),
	} );
	if ( ! response.ok ) {
		throw new Error(
			`WordPress beta API returned HTTP ${ response.status }.`
		);
	}
	const body = await response.json();
	/** @param {RegExp} versionPattern */
	const offer = ( versionPattern ) =>
		body?.offers?.find(
			/** @param {Record<string, any>} candidate */
			( candidate ) => versionPattern.test( candidate?.version )
		);
	const beta = offer( BETA_VERSION );
	if ( beta ) {
		return { channel: 'beta', version: beta.version };
	}
	const stable = offer( STABLE_VERSION );
	if ( ! stable ) {
		throw new Error(
			'The WordPress beta API returned no concrete beta or stable build.'
		);
	}
	process.stderr.write(
		`::notice::The WordPress beta channel offers no beta or RC build; tracking the stable release ${ stable.version } until the next beta appears.\n`
	);
	return { channel: 'stable', version: stable.version };
}

/**
 * Reads the pinned dependencies and resolves what the rest of the build needs,
 * including the cache key of the invariant base: two builds share a cached base
 * only when every input that shapes it agrees.
 *
 * @param {{repositoryRoot: string, cacheRoot: string, platform: string, architecture: string, runnerImage: string, fetch?: (...args: any[]) => Promise<any>}} options
 */
export async function resolveBuildInputs( options ) {
	const repositoryRoot = path.resolve( options.repositoryRoot );
	const manifest = await readFile(
		path.join( repositoryRoot, DEPENDENCY_MANIFEST )
	);
	const dependencies = JSON.parse( manifest.toString( 'utf8' ) );
	const wordpress = await resolveWordPressBeta( options.fetch );
	const files = createHash( 'sha256' );
	for ( const relative of BASE_INPUT_ROOTS ) {
		await digestTree(
			files,
			repositoryRoot,
			path.join( repositoryRoot, relative )
		);
	}
	// The PHP version and the pinned dependencies are files under those roots,
	// so only what the build reads from outside the checkout is named here.
	const identity = createHash( 'sha256' )
		.update(
			JSON.stringify( {
				platform: options.platform,
				architecture: options.architecture,
				runnerImage: options.runnerImage,
				wordpressVersion: wordpress.version,
				filesDigest: files.digest( 'hex' ),
			} )
		)
		.digest( 'hex' );
	const cacheKey = `docs-preview-base-v${ dependencies.cacheSchemaVersion }-${ identity }`;
	return {
		cacheKey,
		cacheDirectory: path.join(
			path.resolve( options.cacheRoot ),
			cacheKey
		),
		dependencyDigest: createHash( 'sha256' )
			.update( manifest )
			.digest( 'hex' ),
		dependencies,
		wordpress,
	};
}

/**
 * @param {Record<string, any>} inputs
 * @param {string} filename
 */
export async function writeResolvedInputs( inputs, filename ) {
	await mkdir( path.dirname( filename ), { recursive: true } );
	await writeFile( filename, `${ JSON.stringify( inputs, null, 2 ) }\n` );
	if ( process.env.GITHUB_OUTPUT ) {
		await writeFile(
			process.env.GITHUB_OUTPUT,
			`cache-key=${ inputs.cacheKey }\ncache-directory=${ inputs.cacheDirectory }\n`,
			{ flag: 'a' }
		);
	}
}

/**
 * @param {string} filename
 */
export async function readResolvedInputs( filename ) {
	return JSON.parse( await readFile( filename, 'utf8' ) );
}
