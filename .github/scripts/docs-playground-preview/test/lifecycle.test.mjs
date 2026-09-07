import assert from 'node:assert/strict';
import { test } from 'node:test';

import {
	FakeGitHub,
	pullRequest,
	releaseAsset,
	repository,
	sourceRepository,
	sourceSha,
} from './fake-github.mjs';
import { readyComment } from '../lib/comment.mjs';
import { managePullRequest } from '../lifecycle.mjs';

const preview = {
	sourceRepository,
	sourceSha,
	publication: {
		publishedAt: '2026-09-04T12:00:00.000Z',
		playgroundUrl: 'https://playground.wordpress.net/?blueprint-url=x',
	},
};

/**
 * @param {string} action
 * @param {Record<string, any>} [overrides]
 */
function event( action, overrides = {} ) {
	return {
		action,
		pull_request: pullRequest( { labels: [], ...overrides } ),
	};
}

/**
 * @param {Record<string, any>} api
 * @param {Record<string, any>} payload
 */
function manage( api, payload ) {
	return managePullRequest( { repository, api, event: payload } );
}

test( 'a push away from the built commit marks the comment stale', async () => {
	const metadata = 'code-reference-pr-123-old.json';
	const api = new FakeGitHub( {
		comments: [ { id: 7, body: readyComment( preview, 'https://run' ) } ],
		assets: [
			releaseAsset( 1, metadata ),
			releaseAsset( 2, 'code-reference-pr-123-old.zip' ),
		],
		assetJson: new Map( [ [ metadata, preview ] ] ),
	} );
	const result = await manage(
		api,
		event( 'synchronize', {
			head: {
				sha: 'b'.repeat( 40 ),
				repo: { full_name: sourceRepository },
			},
		} )
	);

	assert.equal( result.status, 'stale' );
	assert.match( api.comments[ 0 ].body, /\*\*Status:\*\* Stale/ );
	assert.ok(
		api.comments[ 0 ].body.includes( preview.publication.playgroundUrl )
	);
} );

test( 'a comment that already describes the current head is left alone', async () => {
	const api = new FakeGitHub( {
		comments: [ { id: 7, body: readyComment( preview, 'https://run' ) } ],
	} );
	const result = await manage( api, event( 'synchronize' ) );

	assert.equal( result.status, 'ignored' );
	assert.match( api.comments[ 0 ].body, /\*\*Status:\*\* Ready/ );
} );

test( 'a labelled pull request is left to the build that is already running', async () => {
	const api = new FakeGitHub( {
		comments: [ { id: 7, body: readyComment( preview, 'https://run' ) } ],
	} );
	const result = await manage(
		api,
		event( 'synchronize', {
			labels: [ { name: 'docs-preview' } ],
			head: {
				sha: 'b'.repeat( 40 ),
				repo: { full_name: sourceRepository },
			},
		} )
	);

	assert.equal( result.status, 'ignored' );
	assert.match( api.comments[ 0 ].body, /\*\*Status:\*\* Ready/ );
} );

test( 'a stale comment with no surviving preview says so', async () => {
	const api = new FakeGitHub( {
		comments: [ { id: 7, body: readyComment( preview, 'https://run' ) } ],
	} );
	const result = await manage(
		api,
		event( 'synchronize', {
			head: {
				sha: 'b'.repeat( 40 ),
				repo: { full_name: sourceRepository },
			},
		} )
	);

	assert.equal( result.status, 'stale' );
	assert.match(
		api.comments[ 0 ].body,
		/no healthy docs preview is available/
	);
} );

test( 'a push with neither a preview nor a known source reports nothing to do', async () => {
	const api = new FakeGitHub( {
		comments: [ { id: 7, body: '<!-- code-reference-docs-preview -->' } ],
	} );
	const result = await manage(
		api,
		event( 'synchronize', {
			head: {
				sha: 'b'.repeat( 40 ),
				repo: { full_name: sourceRepository },
			},
		} )
	);

	assert.equal( result.status, 'unavailable' );
} );

test( 'closing a pull request deletes its assets and caches and expires the comment', async () => {
	const api = new FakeGitHub( {
		comments: [ { id: 7, body: readyComment( preview, 'https://run' ) } ],
		assets: [
			releaseAsset( 1, 'code-reference-pr-123-old.zip' ),
			releaseAsset( 2, 'code-reference-pr-123-old.json' ),
			releaseAsset( 3, 'code-reference-pr-999-other.zip' ),
			releaseAsset( 4, 'code-reference-trunk-x.zip' ),
		],
		caches: [
			{ id: 11, key: 'docs-preview-base-v1-abc' },
			{ id: 12, key: 'unrelated-cache' },
		],
	} );
	const result = await manage( api, event( 'closed' ) );

	assert.equal( result.status, 'expired' );
	assert.deepEqual( api.deletedAssets, [ 1, 2 ] );
	assert.deepEqual( api.deletedCaches, [ 11 ] );
	assert.match( api.comments[ 0 ].body, /\*\*Status:\*\* Expired/ );
} );

test( 'an unsupported action is refused', async () => {
	await assert.rejects(
		manage( new FakeGitHub(), event( 'opened' ) ),
		/Unsupported pull request action opened\./
	);
} );

test( 'a repository without deployment enabled runs no lifecycle', async () => {
	await assert.rejects(
		managePullRequest( {
			repository: 'sirreal/wordpress-develop',
			stagingVariable: 'false',
			api: new FakeGitHub(),
			event: event( 'closed' ),
		} ),
		/disabled in this repository/
	);
} );
