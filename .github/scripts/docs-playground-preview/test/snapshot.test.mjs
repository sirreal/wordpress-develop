import assert from 'node:assert/strict';
import { mkdir, mkdtemp, readFile, writeFile } from 'node:fs/promises';
import os from 'node:os';
import path from 'node:path';
import { test } from 'node:test';

import { packageSnapshot } from '../lib/snapshot.mjs';

const SNAPSHOT_BYTES_LIMIT = 104857600;

const inputs = {
	dependencies: {
		playground: {
			phpVersion: '8.4',
		},
	},
	wordpress: { channel: 'beta', version: '7.2-beta1' },
};

/**
 * Stands in for the pinned Playground CLI, which packageSnapshot loads from
 * beside the executable it is given. It writes a snapshot of the size asked
 * for, as a sparse file so that the size costs nothing.
 *
 * @param {number} bytes
 */
async function playgroundCli( bytes ) {
	const root = await mkdtemp(
		path.join( os.tmpdir(), 'docs-preview-snapshot-' )
	);
	const module = path.join( root, 'node_modules/@wp-playground/cli' );
	await mkdir( module, { recursive: true } );
	await writeFile(
		path.join( module, 'index.js' ),
		`import { open } from 'node:fs/promises';
export async function runCLI( options ) {
	const handle = await open( options.outfile, 'w' );
	await handle.truncate( ${ bytes } );
	await handle.close();
}
`
	);
	await mkdir( path.join( root, 'cache' ), { recursive: true } );
	await writeFile( path.join( root, 'cache/base.zip' ), 'base' );
	await writeFile( path.join( root, 'reference.json' ), '[]' );
	return {
		inputs: { ...inputs, cacheDirectory: path.join( root, 'cache' ) },
		options: {
			workDirectory: path.join( root, 'work' ),
			output: path.join( root, 'snapshot.zip' ),
			referenceJson: path.join( root, 'reference.json' ),
			stagedSource: path.join( root, 'source' ),
			playgroundCli: path.join(
				root,
				'node_modules/.bin/wp-playground-cli'
			),
			provenance: {
				sourceRepository: 'WordPress/wordpress-develop',
				sourceSha: 'a'.repeat( 40 ),
				generationTimestamp: '2026-09-04T11:00:00.000Z',
				runUrl: null,
			},
		},
	};
}

test( 'a snapshot over the limit is not handed to the publisher', async () => {
	const { inputs: resolved, options } = await playgroundCli(
		SNAPSHOT_BYTES_LIMIT + 1
	);

	await assert.rejects(
		packageSnapshot( resolved, options ),
		/The snapshot is 104857601 bytes; the limit is 104857600\./
	);
} );

test( 'the packaged snapshot imports the reference into the cached base', async () => {
	const { inputs: resolved, options } = await playgroundCli( 1024 );
	await packageSnapshot( resolved, options );

	const blueprint = JSON.parse(
		await readFile(
			path.join( options.workDirectory, 'final-blueprint.json' ),
			'utf8'
		)
	);
	assert.equal( blueprint.login, false );
	assert.deepEqual( blueprint.features, { networking: false } );
	assert.deepEqual( blueprint.preferredVersions, {
		php: '8.4',
		wp: '7.2-beta1',
	} );
	// The provenance the preview banner reads names the commit that was built.
	assert.match(
		await readFile(
			path.join( options.workDirectory, 'provenance.php' ),
			'utf8'
		),
		new RegExp( `'sha'\\s+=> '${ 'a'.repeat( 40 ) }'` )
	);
} );
