import assert from 'node:assert/strict';
import { appendFile, mkdir, mkdtemp, writeFile } from 'node:fs/promises';
import os from 'node:os';
import path from 'node:path';
import { test } from 'node:test';

import { resolveBuildInputs, resolveWordPressBeta } from '../lib/inputs.mjs';

/**
 * @param {Record<string, any>[]} offers
 */
function versionApi( offers ) {
	/** @param {string} url */
	return async ( url ) => {
		assert.equal(
			url,
			'https://api.wordpress.org/core/version-check/1.7/?channel=beta'
		);
		return { ok: true, json: async () => ( { offers } ) };
	};
}

test( 'the preview runs the newest beta or RC build', async () => {
	const resolved = await resolveWordPressBeta(
		versionApi( [
			{ response: 'upgrade', version: '7.1.2' },
			{ response: 'development', version: '7.2-RC2' },
		] )
	);
	assert.deepEqual( resolved, { channel: 'beta', version: '7.2-RC2' } );
} );

test( 'the preview follows the stable release between betas, with a notice', async ( t ) => {
	const write = t.mock.method( process.stderr, 'write', () => true );
	const resolved = await resolveWordPressBeta(
		versionApi( [
			{ response: 'upgrade', version: '7.1.2' },
			{ response: 'autoupdate', version: '7.0.3' },
		] )
	);
	assert.deepEqual( resolved, { channel: 'stable', version: '7.1.2' } );
	assert.equal( write.mock.callCount(), 1 );
	assert.match(
		String( write.mock.calls[ 0 ].arguments[ 0 ] ),
		/^::notice::.*tracking the stable release 7\.1\.2 until the next beta appears\./
	);
} );

test( 'a version API response with no concrete build fails the build', async () => {
	for ( const body of [
		{},
		{ offers: [] },
		{ offers: [ { response: 'upgrade', version: 'trunk' } ] },
	] ) {
		await assert.rejects(
			resolveWordPressBeta( async () => ( {
				ok: true,
				json: async () => body,
			} ) ),
			/no concrete beta or stable build/
		);
	}
} );

/**
 * A checkout with one file in each place the build reads from.
 */
async function repositoryTree() {
	const root = await mkdtemp(
		path.join( os.tmpdir(), 'docs-preview-inputs-' )
	);
	for ( const [ relative, content ] of Object.entries( {
		'.github/docs-playground-preview/dependencies.json': JSON.stringify( {
			cacheSchemaVersion: 1,
			playground: { phpVersion: '8.4' },
		} ),
		'.github/docs-playground-preview/.nvmrc': '20.20.2\n',
		'.github/docs-playground-preview/package-lock.json': '{}\n',
		'.github/scripts/docs-playground-preview/lib/base.mjs': '// base\n',
		'.github/scripts/docs-playground-preview/lib/snapshot.mjs':
			'// snapshot\n',
		'.github/scripts/docs-playground-preview/php/configure-base.php':
			'<?php\n',
		'.github/scripts/docs-playground-preview/test/base.test.mjs':
			'// test\n',
	} ) ) {
		const filename = path.join( root, relative );
		await mkdir( path.dirname( filename ), { recursive: true } );
		await writeFile( filename, content );
	}
	return root;
}

/**
 * @param {string} repositoryRoot
 */
async function cacheKey( repositoryRoot ) {
	const inputs = await resolveBuildInputs( {
		repositoryRoot,
		cacheRoot: path.join( repositoryRoot, 'cache' ),
		platform: 'linux',
		architecture: 'x64',
		runnerImage: 'ubuntu-24.04',
		fetch: versionApi( [ { version: '7.2-RC2' } ] ),
	} );
	return inputs.cacheKey;
}

test( 'a change to anything the base is built from changes the cache key', async () => {
	const root = await repositoryTree();
	let previous = await cacheKey( root );
	// The lifecycle deletes a closed pull request's caches by this prefix.
	assert.match( previous, /^docs-preview-base-v1-[0-9a-f]{64}$/ );

	for ( const relative of [
		'.github/docs-playground-preview/dependencies.json',
		'.github/docs-playground-preview/.nvmrc',
		'.github/docs-playground-preview/package-lock.json',
		'.github/scripts/docs-playground-preview/lib/base.mjs',
		'.github/scripts/docs-playground-preview/lib/snapshot.mjs',
		'.github/scripts/docs-playground-preview/php/configure-base.php',
	] ) {
		// Trailing whitespace leaves every one of these files valid.
		await appendFile( path.join( root, relative ), '\n' );
		const changed = await cacheKey( root );
		assert.notEqual( changed, previous, `${ relative } is not covered` );
		previous = changed;
	}

	// The tests never run during a build, so they do not rebuild the base.
	await appendFile(
		path.join(
			root,
			'.github/scripts/docs-playground-preview/test/base.test.mjs'
		),
		'\n'
	);
	assert.equal( await cacheKey( root ), previous );
} );

test( 'a different runner or WordPress version is a different base', async () => {
	const root = await repositoryTree();
	const baseline = await cacheKey( root );
	const inputs = {
		repositoryRoot: root,
		cacheRoot: path.join( root, 'cache' ),
		platform: 'linux',
		architecture: 'x64',
		runnerImage: 'ubuntu-24.04',
		fetch: versionApi( [ { version: '7.2-RC2' } ] ),
	};

	assert.notEqual(
		(
			await resolveBuildInputs( {
				...inputs,
				runnerImage: 'ubuntu-26.04',
			} )
		).cacheKey,
		baseline
	);
	assert.notEqual(
		(
			await resolveBuildInputs( {
				...inputs,
				fetch: versionApi( [ { version: '7.2-RC3' } ] ),
			} )
		).cacheKey,
		baseline
	);
} );
