import assert from 'node:assert/strict';
import { mkdtemp, readFile } from 'node:fs/promises';
import { existsSync } from 'node:fs';
import os from 'node:os';
import path from 'node:path';
import { fileURLToPath } from 'node:url';
import { test } from 'node:test';

import { FakeGitHub, releaseAsset, sourceSha } from './fake-github.mjs';
import { reusePublishedSnapshot } from '../reuse.mjs';

const current = `code-reference-pr-123-${ sourceSha }-500-1`;
const older = `code-reference-pr-123-${ sourceSha }-400-1`;
const pullRequest = { number: 123, head: { sha: sourceSha } };

/**
 * The reuse check writes into a directory of its own and appends to the file
 * the runner exposes as GITHUB_OUTPUT.
 *
 * @param {import('node:test').TestContext} t
 */
async function workspace( t ) {
	const directory = await mkdtemp(
		path.join( os.tmpdir(), 'docs-preview-reuse-' )
	);
	const previous = process.env.GITHUB_OUTPUT;
	process.env.GITHUB_OUTPUT = path.join( directory, 'github-output' );
	t.after( () => {
		if ( previous === undefined ) {
			delete process.env.GITHUB_OUTPUT;
		} else {
			process.env.GITHUB_OUTPUT = previous;
		}
	} );
	return {
		handoff: path.join( directory, 'handoff' ),
		output: process.env.GITHUB_OUTPUT,
	};
}

test( 'reuse hands the newest complete snapshot over and skips the build', async ( t ) => {
	const api = new FakeGitHub( {
		assets: [
			releaseAsset( 1, `${ older }.zip`, '2026-08-01T00:00:00Z' ),
			releaseAsset( 2, `${ older }.json`, '2026-08-01T00:00:00Z' ),
			releaseAsset( 3, `${ current }.zip`, '2026-09-01T00:00:00Z' ),
			releaseAsset( 4, `${ current }.json`, '2026-09-01T00:00:00Z' ),
		],
	} );
	const { handoff, output } = await workspace( t );

	assert.equal(
		await reusePublishedSnapshot( api, pullRequest, handoff ),
		`${ current }.zip`
	);
	assert.deepEqual(
		JSON.parse(
			await readFile( path.join( handoff, 'build.json' ), 'utf8' )
		),
		{ handoffType: 'reuse' }
	);
	assert.equal( await readFile( output, 'utf8' ), 'reused=true\n' );
} );

test( 'the build job skips its four build steps on that output', async () => {
	const workflow = await readFile(
		fileURLToPath(
			new URL(
				'../../../workflows/docs-playground-preview-build.yml',
				import.meta.url
			)
		),
		'utf8'
	);

	// The name of the output is a contract between the reuse check and the
	// steps that a reused snapshot skips: install PHP, resolve the inputs,
	// restore the cache and build.
	assert.equal(
		workflow.match( /steps\.reuse\.outputs\.reused != 'true'/g )?.length,
		4
	);
} );

test( 'a snapshot without its metadata is not reused', async ( t ) => {
	const api = new FakeGitHub( {
		assets: [ releaseAsset( 1, `${ current }.zip` ) ],
	} );
	const { handoff, output } = await workspace( t );

	assert.equal(
		await reusePublishedSnapshot( api, pullRequest, handoff ),
		null
	);
	assert.equal( existsSync( handoff ), false );
	assert.equal( existsSync( output ), false );
} );

test( 'another pull request or another commit is not reused', async ( t ) => {
	const api = new FakeGitHub( {
		assets: [
			releaseAsset( 1, `code-reference-pr-999-${ sourceSha }-1-1.zip` ),
			releaseAsset( 2, `code-reference-pr-999-${ sourceSha }-1-1.json` ),
			releaseAsset(
				3,
				`code-reference-pr-123-${ 'b'.repeat( 40 ) }-1-1.zip`
			),
			releaseAsset(
				4,
				`code-reference-pr-123-${ 'b'.repeat( 40 ) }-1-1.json`
			),
		],
	} );
	const { handoff } = await workspace( t );

	assert.equal(
		await reusePublishedSnapshot( api, pullRequest, handoff ),
		null
	);
} );

test( 'no release means nothing to reuse', async ( t ) => {
	const { handoff } = await workspace( t );

	assert.equal(
		await reusePublishedSnapshot(
			new FakeGitHub( { release: null } ),
			pullRequest,
			handoff
		),
		null
	);
} );
