import assert from 'node:assert/strict';
import { test } from 'node:test';

import { GitHubApi, assertDeploymentEnabled } from '../lib/github.mjs';

const repository = 'WordPress/wordpress-develop';

test( 'deployment is inert outside the primary and opted-in staging repositories', () => {
	assert.doesNotThrow( () => assertDeploymentEnabled( repository ) );
	assert.doesNotThrow( () =>
		assertDeploymentEnabled( 'sirreal/wordpress-develop', 'true' )
	);
	for ( const [ candidate, staging ] of [
		[ 'sirreal/wordpress-develop', undefined ],
		[ 'sirreal/wordpress-develop', 'TRUE' ],
		[ 'somebody/wordpress-develop', 'true' ],
	] ) {
		assert.throws(
			() => assertDeploymentEnabled( candidate, staging ),
			/disabled in this repository/
		);
	}
} );

/**
 * @param {number} status
 * @param {unknown} [body]
 */
function response( status, body = {} ) {
	return {
		ok: status >= 200 && status < 300,
		status,
		json: async () => body,
		text: async () => JSON.stringify( body ),
	};
}

/**
 * @param {any[]} responses
 */
function recordingFetch( responses ) {
	/** @type {any[]} */
	const calls = [];
	/**
	 * @param {string} url
	 * @param {Record<string, any>} options
	 */
	const fetchImplementation = async ( url, options ) => {
		calls.push( { url, options } );
		const next = responses.shift();
		if ( ! next ) {
			throw new Error( `Unexpected request for ${ url }.` );
		}
		return next;
	};
	return { calls, fetchImplementation };
}

/**
 * @param {any[]} responses
 */
function apiWith( responses ) {
	const { calls, fetchImplementation } = recordingFetch( responses );
	const api = new GitHubApi( repository, 'token', fetchImplementation );
	api.backoff = async () => {};
	return { api, calls };
}

test( 'a read is retried through a server error', async () => {
	const { api, calls } = apiWith( [
		response( 500 ),
		response( 200, { id: 5 } ),
	] );

	assert.deepEqual( await api.getRun( 5 ), { id: 5 } );
	assert.equal( calls.length, 2 );
	assert.equal( calls[ 0 ].options.headers.Authorization, 'Bearer token' );
} );

test( 'a write is not repeated', async () => {
	const { api, calls } = apiWith( [ response( 500, { message: 'boom' } ) ] );

	await assert.rejects( api.createComment( 1, 'body' ), /HTTP 500/ );
	assert.equal( calls.length, 1 );
} );

test( 'a missing release reads as no release and other failures carry the status', async () => {
	const missing = apiWith( [ response( 404 ) ] );
	assert.equal( await missing.api.getRelease(), null );

	const forbidden = apiWith( [ response( 403, { message: 'no' } ) ] );
	await assert.rejects( forbidden.api.getRelease(), ( error ) => {
		assert.equal( /** @type {any} */ ( error ).status, 403 );
		return true;
	} );
} );

test( 'listings follow every page', async () => {
	const first = Array.from( { length: 100 }, ( _, index ) => ( {
		id: index,
	} ) );
	const { api, calls } = apiWith( [
		response( 200, first ),
		response( 200, [ { id: 100 } ] ),
	] );

	assert.equal( ( await api.listReleaseAssets( 9 ) ).length, 101 );
	assert.match( calls[ 0 ].url, /per_page=100&page=1/ );
	assert.match( calls[ 1 ].url, /per_page=100&page=2/ );
} );

test( 'an upload replaces the asset a previous attempt left behind', async () => {
	const { api, calls } = apiWith( [
		response( 422, { message: 'already_exists' } ),
		response( 200, [ { id: 3, name: 'snapshot.zip' } ] ),
		response( 204 ),
		response( 200, { id: 4, name: 'snapshot.zip' } ),
	] );

	assert.deepEqual(
		await api.uploadReleaseAsset(
			9,
			'snapshot.zip',
			Buffer.from( 'x' ),
			'application/zip'
		),
		{ id: 4, name: 'snapshot.zip' }
	);
	assert.equal( calls[ 2 ].options.method, 'DELETE' );
} );

test( 'a published asset is read without credentials', async () => {
	const { api, calls } = apiWith( [ response( 200, { sourceSha: 'abc' } ) ] );

	assert.deepEqual(
		await api.readAssetJson( {
			browser_download_url: 'https://github.com/download/snapshot.json',
		} ),
		{ sourceSha: 'abc' }
	);
	assert.equal( calls[ 0 ].options.headers, undefined );
} );

test( 'the pull request behind a fork build run is found by head identity', async () => {
	const run = {
		head_sha: 'a'.repeat( 40 ),
		head_branch: 'feature',
		head_repository: {
			full_name: 'contributor/wordpress-develop',
			owner: { login: 'contributor' },
		},
	};
	const { api, calls } = apiWith( [
		response( 200, [
			{
				number: 1,
				head: {
					sha: 'b'.repeat( 40 ),
					repo: { full_name: 'contributor/wordpress-develop' },
				},
			},
			{
				number: 2,
				head: {
					sha: 'a'.repeat( 40 ),
					repo: { full_name: 'contributor/wordpress-develop' },
				},
			},
		] ),
	] );

	assert.equal( ( await api.findPullRequestForRun( run ) ).number, 2 );
	assert.match( calls[ 0 ].url, /head=contributor%3Afeature/ );
} );

test( 'a run without a fork head matches no pull request', async () => {
	const { api } = apiWith( [] );
	assert.equal(
		await api.findPullRequestForRun( { head_branch: null } ),
		null
	);
} );

test( 'a build run whose build job skipped never supersedes a real build', async () => {
	const run = {
		head_sha: 'a'.repeat( 40 ),
		head_branch: 'feature',
		head_repository: { full_name: 'contributor/wordpress-develop' },
	};
	const { api } = apiWith( [
		response( 200, {
			workflow_runs: [
				{ id: 20, run_attempt: 1, ...run },
				{ id: 10, run_attempt: 1, ...run },
			],
		} ),
		response( 200, {
			jobs: [
				{
					name: 'Build Code Reference snapshot',
					conclusion: 'skipped',
				},
			],
		} ),
		response( 200, {
			jobs: [
				{
					name: 'Build Code Reference snapshot',
					conclusion: 'success',
				},
			],
		} ),
	] );

	assert.equal( ( await api.latestPullRequestBuildRun( run ) ).id, 10 );
} );

test( 'the newest trunk build run is the first the API reports', async () => {
	const { api, calls } = apiWith( [
		response( 200, { workflow_runs: [ { id: 30 } ] } ),
	] );

	assert.equal( ( await api.latestTrunkBuildRun() ).id, 30 );
	assert.match( calls[ 0 ].url, /event=push&branch=trunk&per_page=1/ );
} );
