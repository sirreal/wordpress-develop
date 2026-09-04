import assert from 'node:assert/strict';
import { mkdtemp, writeFile } from 'node:fs/promises';
import os from 'node:os';
import path from 'node:path';
import { test } from 'node:test';

import {
	FakeGitHub,
	buildRun,
	pullRequest,
	releaseAsset,
	repository,
	sourceRepository,
	sourceSha,
} from './fake-github.mjs';
import { HANDOFF_SNAPSHOT, snapshotAssetName } from '../lib/preview.mjs';
import { enforceValidation, publishPreview } from '../publish.mjs';

const PLAYGROUND_ORIGIN = 'https://playground.wordpress.net';
const pullRequestNumber = 123;
const runId = 456;
const prSnapshot = snapshotAssetName( {
	pullRequestNumber,
	sourceSha,
	workflowRunId: runId,
	workflowRunAttempt: 1,
} );
const trunkSnapshot = snapshotAssetName( {
	pullRequestNumber: null,
	sourceSha,
	workflowRunId: runId,
	workflowRunAttempt: 1,
} );

/**
 * What the build writes and the publisher reads. The build also records its own
 * idea of the source and of the snapshot; the publisher ignores both, so only
 * the tests that prove it name those fields.
 *
 * @param {Record<string, any>} [overrides]
 */
function handoff( overrides = {} ) {
	return {
		resolvedWordPressBeta: { version: '7.2-beta1' },
		phpVersion: '8.4',
		dependencyManifestDigest: 'b'.repeat( 64 ),
		generationTimestamp: '2026-09-04T11:00:00.000Z',
		buildStatus: 'success',
		validationStatus: 'passed',
		...overrides,
	};
}

/**
 * @param {Record<string, any>} metadata
 * @param {boolean} [withSnapshot]
 * @param {number} [snapshotSize]
 */
async function handoffDirectory( metadata, withSnapshot, snapshotSize = 8 ) {
	const directory = await mkdtemp(
		path.join( os.tmpdir(), 'docs-preview-publish-' )
	);
	await writeFile(
		path.join( directory, 'build.json' ),
		JSON.stringify( metadata )
	);
	if ( withSnapshot ) {
		await writeFile(
			path.join( directory, HANDOFF_SNAPSHOT ),
			Buffer.alloc( snapshotSize, 1 )
		);
	}
	return directory;
}

/**
 * @param {Record<string, any>} api
 * @param {Record<string, any>} [options]
 */
function publish( api, options = {} ) {
	return publishPreview( {
		repository,
		triggerRunId: runId,
		triggerRunAttempt: 1,
		artifactAvailable: true,
		api,
		...options,
	} );
}

test( 'a pull request preview publishes both assets and announces the link', async () => {
	const api = new FakeGitHub( {
		assets: [
			releaseAsset(
				1,
				`code-reference-pr-${ pullRequestNumber }-old.zip`
			),
			releaseAsset( 2, 'code-reference-pr-999-other.zip' ),
		],
	} );
	const result = await publish( api, {
		handoffDirectory: await handoffDirectory( handoff(), true ),
	} );

	assert.equal( result.status, 'ready' );
	assert.deepEqual(
		api.uploads.map( ( upload ) => upload.name ),
		[ prSnapshot, prSnapshot.replace( /\.zip$/, '.json' ) ]
	);
	assert.match( api.comments[ 0 ].body, /\*\*Status:\*\* Ready/ );
	assert.ok(
		api.comments[ 0 ].body.includes(
			`${ PLAYGROUND_ORIGIN }/?blueprint-url=data:application/json,`
		)
	);
	assert.equal( api.labelRemovals, 1 );
	// The superseded asset for this pull request goes; another pull request's
	// asset stays.
	assert.deepEqual( api.deletedAssets, [ 1 ] );
} );

test( 'published metadata names the commit of the workflow run, not the handoff', async () => {
	const api = new FakeGitHub();
	await publish( api, {
		handoffDirectory: await handoffDirectory(
			handoff( {
				sourceSha: 'f'.repeat( 40 ),
				sourceRepository: 'attacker/wordpress-develop',
				pullRequestNumber: 999,
			} ),
			true
		),
	} );

	const published = api.assetJson.get(
		prSnapshot.replace( /\.zip$/, '.json' )
	);
	assert.equal( published.sourceSha, sourceSha );
	assert.equal( published.sourceRepository, sourceRepository );
	assert.equal( published.pullRequestNumber, pullRequestNumber );
	assert.equal( published.snapshotBytes, 8 );
	assert.ok(
		! Number.isNaN( Date.parse( published.publication.publishedAt ) )
	);
} );

test( 'a snapshot above the size limit is not published', async () => {
	const api = new FakeGitHub();
	await assert.rejects(
		publish( api, {
			handoffDirectory: await handoffDirectory(
				handoff(),
				true,
				104857601
			),
		} ),
		/the limit is 104857600/
	);
	assert.deepEqual( api.uploads, [] );
	assert.match( api.comments[ 0 ].body, /Latest attempt failed/ );
} );

test( 'a failed build reports the failure and keeps the last working link', async () => {
	const previous = 'code-reference-pr-123-old.json';
	const api = new FakeGitHub( {
		assets: [
			releaseAsset( 1, previous ),
			releaseAsset( 2, 'code-reference-pr-123-old.zip' ),
		],
		assetJson: new Map( [
			[
				previous,
				{
					sourceRepository,
					sourceSha: 'e'.repeat( 40 ),
					publication: {
						playgroundUrl: `${ PLAYGROUND_ORIGIN }/?blueprint-url=previous`,
					},
				},
			],
		] ),
	} );
	await assert.rejects(
		publish( api, {
			handoffDirectory: await handoffDirectory(
				handoff( {
					buildStatus: 'failed',
					buildError: 'The parser crashed.',
				} )
			),
		} ),
		/The parser crashed\./
	);

	assert.match(
		api.comments[ 0 ].body,
		/\*\*Status:\*\* Latest attempt failed/
	);
	assert.match( api.comments[ 0 ].body, /Latest successful docs preview/ );
	assert.equal( api.labelRemovals, 1 );
} );

test( 'a failed validation reports invalid without publishing', async () => {
	const api = new FakeGitHub();
	const result = await publish( api, {
		handoffDirectory: await handoffDirectory(
			handoff( { validationStatus: 'failed' } ),
			true
		),
	} );

	assert.equal( result.status, 'invalid' );
	assert.deepEqual( api.uploads, [] );
	assert.match( api.comments[ 0 ].body, /Latest attempt failed/ );
} );

test( 'enforcement turns an invalid candidate into a failure', () => {
	assert.deepEqual( enforceValidation( { status: 'invalid' }, 'false' ), {
		status: 'invalid',
	} );
	assert.throws(
		() => enforceValidation( { status: 'invalid' }, 'true' ),
		/DOCS_PREVIEW_ENFORCE/
	);
} );

test( 'a missing handoff artifact reports the failure', async () => {
	const api = new FakeGitHub();
	await assert.rejects(
		publish( api, {
			artifactAvailable: false,
			handoffDirectory: await handoffDirectory( handoff(), true ),
		} ),
		/handoff artifact is unavailable/
	);
	assert.deepEqual( api.uploads, [] );
} );

test( 'a superseded build publishes nothing', async () => {
	const api = new FakeGitHub( { latestRun: buildRun( { id: 999 } ) } );
	const result = await publish( api, {
		handoffDirectory: await handoffDirectory( handoff(), true ),
	} );

	assert.equal( result.status, 'superseded' );
	assert.deepEqual( api.uploads, [] );
	assert.deepEqual( api.comments, [] );
} );

test( 'a newer attempt of the same run publishes nothing', async () => {
	const api = new FakeGitHub( { run: buildRun( { run_attempt: 2 } ) } );
	const result = await publish( api, {
		handoffDirectory: await handoffDirectory( handoff(), true ),
	} );

	assert.equal( result.status, 'superseded' );
} );

test( 'a removed docs-preview label stops the first attempt', async () => {
	const api = new FakeGitHub( {
		pullRequest: pullRequest( { labels: [] } ),
	} );
	const result = await publish( api, {
		handoffDirectory: await handoffDirectory( handoff(), true ),
	} );

	assert.equal( result.status, 'superseded' );
	assert.deepEqual( api.uploads, [] );
} );

test( 'a closed pull request publishes nothing', async () => {
	const api = new FakeGitHub( { pullRequest: null } );
	const result = await publish( api, {
		handoffDirectory: await handoffDirectory( handoff(), true ),
	} );

	assert.equal( result.status, 'superseded' );
} );

test( 'a reuse handoff announces the published assets without uploading', async () => {
	const reused = `code-reference-pr-${ pullRequestNumber }-${ sourceSha }-1-1.zip`;
	const reusedMetadata = reused.replace( /\.zip$/, '.json' );
	const api = new FakeGitHub( {
		assets: [
			releaseAsset( 1, reused ),
			releaseAsset( 2, reusedMetadata ),
		],
		assetJson: new Map( [
			[
				reusedMetadata,
				{
					sourceRepository,
					sourceSha,
					publication: {
						publishedAt: '2026-09-03T10:00:00.000Z',
						playgroundUrl: `${ PLAYGROUND_ORIGIN }/?blueprint-url=reused`,
					},
				},
			],
		] ),
	} );
	const result = await publish( api, {
		handoffDirectory: await handoffDirectory( { handoffType: 'reuse' } ),
	} );

	assert.equal( result.status, 'reused' );
	assert.deepEqual( api.uploads, [] );
	assert.deepEqual( api.deletedAssets, [] );
	assert.match( api.comments[ 0 ].body, /\*\*Status:\*\* Ready/ );
	assert.equal( api.labelRemovals, 1 );
} );

test( 'a reuse handoff for a commit with no published preview is refused', async () => {
	const api = new FakeGitHub( {
		assets: [
			releaseAsset( 1, `code-reference-pr-999-${ sourceSha }-1-1.zip` ),
			releaseAsset( 2, `code-reference-pr-999-${ sourceSha }-1-1.json` ),
		],
	} );
	await assert.rejects(
		publish( api, {
			handoffDirectory: await handoffDirectory( {
				handoffType: 'reuse',
			} ),
		} ),
		/no longer published/
	);
	assert.deepEqual( api.deletedAssets, [] );
} );

test( 'trunk publishes the snapshot and points the stable Blueprint at it', async () => {
	const api = new FakeGitHub( {
		pullRequest: null,
		gitReference: { object: { sha: '9'.repeat( 40 ) } },
	} );
	const result = await publish( api, {
		trunk: true,
		handoffDirectory: await handoffDirectory( handoff(), true ),
	} );

	assert.equal( result.status, 'ready' );
	assert.deepEqual(
		api.uploads.map( ( upload ) => upload.name ),
		[ trunkSnapshot, trunkSnapshot.replace( /\.zip$/, '.json' ) ]
	);
	assert.equal( api.trees[ 0 ].path, 'code-reference-trunk.json' );
	const blueprint = JSON.parse( api.trees[ 0 ].content );
	assert.ok(
		blueprint.steps[ 0 ].zipFile.url.endsWith( `/${ trunkSnapshot }` )
	);
	assert.equal( api.commits[ 0 ].parentSha, '9'.repeat( 40 ) );
	assert.equal( api.gitReference.existed, true );
	// Trunk publishes no comment and touches no label.
	assert.deepEqual( api.comments, [] );
	assert.equal( api.labelRemovals, 0 );
} );

test( 'trunk creates the pointer branch when it does not exist', async () => {
	const api = new FakeGitHub( { pullRequest: null, gitReference: null } );
	await publish( api, {
		trunk: true,
		handoffDirectory: await handoffDirectory( handoff(), true ),
	} );

	assert.equal( api.commits[ 0 ].parentSha, null );
	assert.equal( api.gitReference.existed, false );
} );

test( 'trunk keeps the two newest generations so warm Blueprint copies resolve', async () => {
	const older = `code-reference-trunk-${ 'b'.repeat( 40 ) }-100-1`;
	const previous = `code-reference-trunk-${ 'c'.repeat( 40 ) }-200-1`;
	const api = new FakeGitHub( {
		pullRequest: null,
		assets: [
			releaseAsset( 1, `${ older }.zip` ),
			releaseAsset( 2, `${ older }.json` ),
			releaseAsset( 3, `${ previous }.zip` ),
			releaseAsset( 4, `${ previous }.json` ),
		],
	} );
	await publish( api, {
		trunk: true,
		handoffDirectory: await handoffDirectory( handoff(), true ),
	} );

	assert.deepEqual( api.deletedAssets.sort(), [ 1, 2 ] );
} );

test( 'the first publication creates the shared release', async () => {
	const api = new FakeGitHub( { release: null } );
	const result = await publish( api, {
		handoffDirectory: await handoffDirectory( handoff(), true ),
	} );

	assert.equal( result.status, 'ready' );
	assert.deepEqual( api.release, { id: 9 } );
	assert.equal( api.uploads.length, 2 );
} );

test( 'a repository without deployment enabled refuses to publish', async () => {
	await assert.rejects(
		publish( new FakeGitHub(), {
			repository: 'sirreal/wordpress-develop',
			stagingVariable: 'false',
			handoffDirectory: await handoffDirectory( handoff(), true ),
		} ),
		/disabled in this repository/
	);
} );

test( 'a handoff whose PHP version would break the comment link is refused', async () => {
	const api = new FakeGitHub();
	await assert.rejects(
		publish( api, {
			handoffDirectory: await handoffDirectory(
				handoff( { phpVersion: '8.4)](https://evil.example/' } ),
				true
			),
		} ),
		/no valid phpVersion/
	);

	assert.deepEqual( api.uploads, [] );
	assert.match( api.comments[ 0 ].body, /Latest attempt failed/ );
} );

test( 'a handoff with no valid runtime or provenance is refused', async () => {
	/** @type {[string, unknown][]} */
	const broken = [
		[ 'resolvedWordPressBeta', { version: 'trunk' } ],
		[ 'dependencyManifestDigest', 'not-a-digest' ],
		[ 'generationTimestamp', 'yesterday' ],
	];
	for ( const [ field, value ] of broken ) {
		const api = new FakeGitHub();
		await assert.rejects(
			publish( api, {
				handoffDirectory: await handoffDirectory(
					handoff( { [ field ]: value } ),
					true
				),
			} ),
			new RegExp( `no valid ${ field }` )
		);
		assert.deepEqual( api.uploads, [] );
	}
} );

test( 'a comment deleted while it is being updated is replaced', async () => {
	const api = new FakeGitHub( {
		comments: [ { id: 7, body: 'the comment someone deletes' } ],
	} );
	api.updateComment = async () => {
		api.comments = [];
		throw Object.assign( new Error( 'Not Found' ), { status: 404 } );
	};
	const result = await publish( api, {
		handoffDirectory: await handoffDirectory( handoff(), true ),
	} );

	assert.equal( result.status, 'ready' );
	assert.equal( api.uploads.length, 2 );
	assert.match( api.comments[ 0 ].body, /\*\*Status:\*\* Ready/ );
} );

test( 'a push during the publication keeps the label, so its build can publish', async () => {
	const api = new FakeGitHub();
	api.getPullRequest = async () =>
		pullRequest( {
			head: {
				sha: 'b'.repeat( 40 ),
				repo: { full_name: sourceRepository },
			},
		} );
	const result = await publish( api, {
		handoffDirectory: await handoffDirectory( handoff(), true ),
	} );

	assert.equal( result.status, 'ready' );
	assert.match( api.comments[ 0 ].body, /\*\*Status:\*\* Ready/ );
	assert.equal( api.labelRemovals, 0 );
} );

test( 'a preview stays announced when the tidy-up after it fails', async ( t ) => {
	t.mock.method( process.stderr, 'write', () => true );
	const api = new FakeGitHub( {
		assets: [
			releaseAsset(
				1,
				`code-reference-pr-${ pullRequestNumber }-old.zip`
			),
		],
	} );
	api.deleteReleaseAsset = async () => {
		throw new Error( 'The release asset is gone.' );
	};
	const result = await publish( api, {
		handoffDirectory: await handoffDirectory( handoff(), true ),
	} );

	assert.equal( result.status, 'ready' );
	assert.match( api.comments[ 0 ].body, /\*\*Status:\*\* Ready/ );
} );
