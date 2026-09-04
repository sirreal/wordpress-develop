import assert from 'node:assert/strict';
import { test } from 'node:test';

import {
	FakeGitHub,
	releaseAsset,
	repository,
	sourceSha,
} from './fake-github.mjs';
import {
	createPublishedMetadata,
	createTrunkBlueprint,
	findLatestPublishedPreview,
	metadataAssetName,
	snapshotAssetName,
} from '../lib/preview.mjs';

test( 'asset names carry the run that produced them', () => {
	assert.equal(
		snapshotAssetName( {
			pullRequestNumber: 123,
			sourceSha,
			workflowRunId: 456,
			workflowRunAttempt: 2,
		} ),
		`code-reference-pr-123-${ sourceSha }-456-2.zip`
	);
	assert.equal(
		snapshotAssetName( {
			pullRequestNumber: null,
			sourceSha,
			workflowRunId: 456,
			workflowRunAttempt: 1,
		} ),
		`code-reference-trunk-${ sourceSha }-456-1.zip`
	);
	assert.equal(
		metadataAssetName( `code-reference-pr-123-${ sourceSha }-456-2.zip` ),
		`code-reference-pr-123-${ sourceSha }-456-2.json`
	);
} );

test( 'a published preview opens the reference anonymously with networking off', () => {
	const published = createPublishedMetadata(
		{
			phpVersion: '8.4',
			resolvedWordPressBeta: { version: '7.2-beta1' },
			generationTimestamp: '2026-09-04T11:00:00.000Z',
		},
		{
			repository,
			pullRequestNumber: null,
			sourceRepository: repository,
			sourceSha,
			workflowRunId: 456,
			workflowRunAttempt: 1,
			runUrl: `https://github.com/${ repository }/actions/runs/456`,
		},
		{ filename: 'snapshot.zip', bytes: 10, sha256: 'b'.repeat( 64 ) }
	);

	assert.equal(
		published.publication.snapshotUrl,
		'https://github.com/WordPress/wordpress-develop/releases/download/code-reference-playground-preview/snapshot.zip'
	);
	// The repository README links this one.
	assert.equal(
		published.publication.stablePlaygroundUrl,
		'https://playground.wordpress.net/?blueprint-url=https%3A%2F%2Fwordpress-playground-cors-proxy.net%2Fhttps%3A%2F%2Fraw.githubusercontent.com%2FWordPress%2Fwordpress-develop%2Fdocs-preview-code-reference%2Fcode-reference-trunk.json'
	);
	assert.equal( published.snapshotBytes, 10 );
	assert.ok(
		published.publication.playgroundUrl.startsWith(
			'https://playground.wordpress.net/?blueprint-url=data:application/json,'
		)
	);

	const blueprint = createTrunkBlueprint( published );
	assert.equal( blueprint.landingPage, '/reference/' );
	assert.equal( blueprint.login, false );
	assert.deepEqual( blueprint.features, { networking: false } );
	assert.deepEqual( blueprint.preferredVersions, {
		php: '8.4',
		wp: '7.2-beta1',
	} );
	assert.equal(
		blueprint.steps[ 0 ].zipFile.url,
		`https://wordpress-playground-cors-proxy.net/${ published.publication.snapshotUrl }`
	);
	assert.match( blueprint.meta.description, new RegExp( sourceSha ) );
} );

test( 'the newest published preview with both assets is the one reported', async () => {
	const api = new FakeGitHub( {
		assets: [
			releaseAsset(
				1,
				'code-reference-pr-123-old.json',
				'2026-08-01T00:00:00Z'
			),
			releaseAsset(
				2,
				'code-reference-pr-123-old.zip',
				'2026-08-01T00:00:00Z'
			),
			// Newest, but its metadata upload never completed.
			releaseAsset(
				3,
				'code-reference-pr-123-new.zip',
				'2026-09-01T00:00:00Z'
			),
			releaseAsset(
				4,
				'code-reference-pr-999-other.json',
				'2026-09-02T00:00:00Z'
			),
			releaseAsset(
				5,
				'code-reference-pr-999-other.zip',
				'2026-09-02T00:00:00Z'
			),
		],
		assetJson: new Map( [
			[ 'code-reference-pr-123-old.json', { sourceSha } ],
		] ),
	} );

	assert.deepEqual( await findLatestPublishedPreview( api, 123 ), {
		sourceSha,
	} );
} );

test( 'no release means no published preview', async () => {
	assert.equal(
		await findLatestPublishedPreview(
			new FakeGitHub( { release: null } ),
			123
		),
		null
	);
} );
