#!/usr/bin/env node

// Keeps the preview comment honest for the rest of a pull request's life: a push
// marks the published preview stale, and closing the pull request deletes its
// snapshots and caches.

import { pathToFileURL } from 'node:url';

import {
	expiredComment,
	readPreviewCommentSource,
	staleComment,
	staleUnavailableComment,
} from './lib/comment.mjs';
import {
	GitHubApi,
	assertDeploymentEnabled,
	readWorkflowEvent,
} from './lib/github.mjs';
import { assetPrefix, findLatestPublishedPreview } from './lib/preview.mjs';

const CACHE_PREFIX = 'docs-preview-base-';

/**
 * @param {Record<string, any>} api
 * @param {Record<string, any>} context
 * @param {Record<string, any>} pullRequest
 */
async function markStale( api, context, pullRequest ) {
	// A labelled pull request is already building a preview for the new head,
	// and that build replaces the comment when it publishes.
	if (
		pullRequest.labels?.some(
			( /** @type {Record<string, any>} */ label ) =>
				label.name === 'docs-preview'
		)
	) {
		return { status: 'ignored' };
	}
	const comment = await api.findPreviewComment( context.pullRequestNumber );
	if ( ! comment ) {
		return { status: 'ignored' };
	}
	const source = readPreviewCommentSource( comment.body );
	if (
		source?.repository === context.sourceRepository &&
		source?.sha === context.sourceSha
	) {
		return { status: 'ignored' };
	}
	const preview = await findLatestPublishedPreview(
		api,
		context.pullRequestNumber
	);
	if ( preview ) {
		await api.updateComment( comment.id, staleComment( preview, context ) );
		return { status: 'stale' };
	}
	if ( ! source ) {
		return { status: 'unavailable' };
	}
	await api.updateComment(
		comment.id,
		staleUnavailableComment( source, context )
	);
	return { status: 'stale' };
}

/**
 * @param {Record<string, any>} api
 * @param {Record<string, any>} context
 */
async function expire( api, context ) {
	const release = await api.getRelease();
	const prefix = assetPrefix( context.pullRequestNumber );
	const assets = release
		? ( await api.listReleaseAssets( release.id ) ).filter(
				( /** @type {Record<string, any>} */ asset ) =>
					asset.name.startsWith( prefix )
		  )
		: [];
	for ( const asset of assets ) {
		await api.deleteReleaseAsset( asset.id );
	}
	const caches = (
		await api.listActionCaches(
			`refs/pull/${ context.pullRequestNumber }/merge`
		)
	).filter( ( /** @type {Record<string, any>} */ cache ) =>
		cache.key.startsWith( CACHE_PREFIX )
	);
	for ( const cache of caches ) {
		await api.deleteActionCache( cache.id );
	}
	// Last, so that a comment saying the preview expired is only posted once
	// the preview really is gone.
	const comment = await api.findPreviewComment( context.pullRequestNumber );
	if ( comment ) {
		await api.updateComment( comment.id, expiredComment() );
	}
	return { status: 'expired' };
}

/**
 * @param {Record<string, any>} options
 * @returns {Promise<Record<string, any>>}
 */
export async function managePullRequest( options ) {
	assertDeploymentEnabled( options.repository, options.stagingVariable );
	const action = options.event?.action;
	if ( action !== 'synchronize' && action !== 'closed' ) {
		throw new Error( `Unsupported pull request action ${ action }.` );
	}
	const pullRequest = options.event.pull_request;
	const api =
		options.api || new GitHubApi( options.repository, options.token );
	const context = {
		pullRequestNumber: pullRequest.number,
		sourceRepository: pullRequest.head.repo?.full_name || null,
		sourceSha: pullRequest.head.sha,
	};
	return action === 'synchronize'
		? markStale( api, context, pullRequest )
		: expire( api, context );
}

async function main() {
	const result = await managePullRequest( {
		repository: process.env.GITHUB_REPOSITORY,
		stagingVariable: process.env.DOCS_PREVIEW_STAGING,
		token: process.env.GITHUB_TOKEN,
		event: await readWorkflowEvent(),
	} );
	process.stdout.write( `Code Reference lifecycle: ${ result.status }.\n` );
}

if ( import.meta.url === pathToFileURL( process.argv[ 1 ] ).href ) {
	main().catch( ( error ) => {
		process.stderr.write( `${ error.stack || error }\n` );
		process.exitCode = 1;
	} );
}
