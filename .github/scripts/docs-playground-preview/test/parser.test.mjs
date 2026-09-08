// Staging: touched to exercise the tests workflow and the stale-comment path.
import assert from 'node:assert/strict';
import { mkdir, mkdtemp, readFile, symlink, writeFile } from 'node:fs/promises';
import os from 'node:os';
import path from 'node:path';
import { test } from 'node:test';

import { inspectParserRecords, stageCorePhp } from '../lib/parser.mjs';

/** @returns {any[]} */
function records() {
	return [
		{
			path: 'wp-includes/example.php',
			functions: [
				{
					name: 'example',
					hooks: [
						{ type: 'filter' },
						{ type: 'action_deprecated' },
					],
				},
			],
			classes: [
				{
					name: 'Example',
					methods: [
						{
							name: 'first',
							hooks: [ { type: 'filter_reference' } ],
						},
						{ name: 'second', hooks: [] },
					],
				},
			],
			hooks: [ { type: 'action' } ],
		},
	];
}

const floors = {
	classes: 1,
	methods: 2,
	functions: 1,
	hooks: 2,
	filters: 2,
};

test( 'only Core PHP outside bundled plugins and themes is staged', async () => {
	const root = await mkdtemp(
		path.join( os.tmpdir(), 'docs-preview-source-' )
	);
	const source = path.join( root, 'src' );
	for ( const relative of [
		'wp-includes/version.php',
		'wp-admin/admin.php',
		'wp-content/plugins/hello.php',
		'wp-content/themes/twentytwenty/functions.php',
	] ) {
		await mkdir( path.dirname( path.join( source, relative ) ), {
			recursive: true,
		} );
		await writeFile(
			path.join( source, relative ),
			`<?php // ${ relative }`
		);
	}
	await writeFile( path.join( source, 'readme.html' ), 'not parser input' );
	await symlink(
		path.join( source, 'wp-admin/admin.php' ),
		path.join( source, 'wp-admin/linked.php' )
	);

	const destination = path.join( root, 'staged' );
	assert.equal( await stageCorePhp( root, destination ), 2 );
	assert.equal(
		await readFile(
			path.join( destination, 'wp-admin/admin.php' ),
			'utf8'
		),
		'<?php // wp-admin/admin.php'
	);
	for ( const excluded of [
		'wp-content/plugins/hello.php',
		'wp-content/themes/twentytwenty/functions.php',
		'wp-admin/linked.php',
		'readme.html',
	] ) {
		await assert.rejects( readFile( path.join( destination, excluded ) ) );
	}
} );

test( 'staging rejects a directory that is not a WordPress checkout', async () => {
	const root = await mkdtemp(
		path.join( os.tmpdir(), 'docs-preview-none-' )
	);
	await assert.rejects(
		stageCorePhp( root, path.join( root, 'staged' ) ),
		/No WordPress source tree found/
	);
} );

test( 'every reference type is counted', () => {
	const result = inspectParserRecords( records(), floors );
	assert.deepEqual( result, {
		counts: {
			classes: 1,
			methods: 2,
			functions: 1,
			hooks: 2,
			filters: 2,
		},
		failures: [],
	} );
} );

test( 'a parse that lost most of Core is reported as a failure', () => {
	const result = inspectParserRecords( [], floors );
	assert.equal( result.failures.length, 5 );
	assert.match( result.failures[ 0 ], /0 classes; expected at least 1/ );
} );

test( 'the importer never receives pull-request PHP as its version include', () => {
	const parsed = records();
	parsed.push( { path: 'wp-includes/version.php' } );
	inspectParserRecords( parsed, floors );
	assert.equal( parsed[ 0 ].root, '/tmp/docs-preview-source' );
	assert.equal( parsed[ 1 ].root, '/tmp/docs-preview-version' );
} );
