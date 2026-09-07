#!/usr/bin/env node

// Publishes one Code Reference snapshot built by the preview build workflow.
// Publishing a pull request preview and publishing the trunk preview are the
// same operation over a different identity: a pull request preview is announced
// in a comment on the pull request, and the trunk preview is announced by moving
// the stable Blueprint that the repository README links.
//
// This runs from the default branch with write access, and the handoff it reads
// was produced by the build job from pull request code. Nothing the handoff says
// about its own identity is used: asset names, the published commit and the
// comment all come from the workflow run instead. The four fields it does take
// describe the runtime and the build, and each is held to a shape first.

import { createHash } from 'node:crypto';
import { readFile } from 'node:fs/promises';
import { pathToFileURL } from 'node:url';
import path from 'node:path';

import { failedComment, readyComment } from './lib/comment.mjs';
import {
	GitHubApi,
	assertDeploymentEnabled,
	readWorkflowEvent,
} from './lib/github.mjs';
import {
	HANDOFF_METADATA,
	HANDOFF_REUSE,
	HANDOFF_SNAPSHOT,
	TRUNK_POINTER_ASSET,
	TRUNK_POINTER_REF,
	assertSnapshotFits,
	assetPrefix,
	createPublishedMetadata,
	createTrunkBlueprint,
	findLatestPublishedPreview,
	findLatestSnapshot,
	metadataAssetName,
	snapshotAssetName,
} from './lib/preview.mjs';

// A trunk generation is one snapshot and its metadata, named after the run that
// produced them.
const TRUNK_GENERATION =
	/^(code-reference-trunk-[0-9a-f]{40}-(\d+)-(\d+))\.(?:zip|json)$/;

class Superseded extends Error {}

/**
 * These four values come from a file the build wrote while running pull request
 * code. The first two are stringified into the Playground URL that the comment
 * renders inside a markdown link, where an unescaped ")" would end the link and
 * spill the rest into the comment body; all four are copied into the published
 * metadata asset, which is this preview's record of what it ran.
 *
 * @param {Record<string, any>} handoff
 */
function assertPublishableHandoff( handoff ) {
	/** @type {[string, unknown, RegExp][]} */
	const fields = [
		[ 'phpVersion', handoff.phpVersion, /^\d+\.\d+(?:\.\d+)?$/ ],
		[
			'resolvedWordPressBeta.version',
			handoff.resolvedWordPressBeta?.version,
			/^\d+\.\d+(?:\.\d+)?(?:-(?:beta|RC)\d+)?$/,
		],
		[
			'dependencyManifestDigest',
			handoff.dependencyManifestDigest,
			/^[0-9a-f]{64}$/,
		],
		[
			'generationTimestamp',
			handoff.generationTimestamp,
			/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}\.\d{3}Z$/,
		],
	];
	for ( const [ name, value, shape ] of fields ) {
		if ( typeof value !== 'string' || ! shape.test( value ) ) {
			throw new Error( `The build handoff reports no valid ${ name }.` );
		}
	}
}

/**
 * The run that triggered this publication must still be the current attempt and
 * the newest build for its source, and a pull request must still be asking for
 * a preview. Anything else means a later run owns the published preview.
 *
 * @param {Record<string, any>} api
 * @param {Record<string, any>} options
 */
async function resolveContext( api, options ) {
	const run = await api.getRun( options.triggerRunId );
	if ( run.run_attempt !== Number( options.triggerRunAttempt ) ) {
		throw new Superseded( 'The build run has a newer attempt.' );
	}
	const pullRequest = options.trunk
		? null
		: await api.findPullRequestForRun( run );
	if ( ! options.trunk && ! pullRequest ) {
		throw new Superseded(
			'The build run matches no open pull request against trunk.'
		);
	}
	const latest = options.trunk
		? await api.latestTrunkBuildRun()
		: await api.latestPullRequestBuildRun( run );
	if ( latest?.id !== run.id ) {
		throw new Superseded( 'A newer build superseded this attempt.' );
	}
	// The label is the request to publish, and publishing removes it, so only a
	// first attempt is required to still carry it.
	if (
		pullRequest &&
		run.run_attempt === 1 &&
		! pullRequest.labels?.some(
			( /** @type {Record<string, any>} */ label ) =>
				label.name === 'docs-preview'
		)
	) {
		throw new Superseded( 'The docs-preview label was removed.' );
	}
	return {
		repository: options.repository,
		pullRequestNumber: pullRequest ? pullRequest.number : null,
		sourceRepository: pullRequest
			? pullRequest.head.repo.full_name
			: options.repository,
		sourceSha: pullRequest ? pullRequest.head.sha : run.head_sha,
		workflowRunId: run.id,
		workflowRunAttempt: run.run_attempt,
		runUrl: `https://github.com/${ options.repository }/actions/runs/${ run.id }`,
	};
}

/**
 * @param {Record<string, any>} api
 */
async function ensureRelease( api ) {
	const release = await api.getRelease();
	if ( release ) {
		return release;
	}
	try {
		return await api.createRelease();
	} catch ( error ) {
		// A pull request and a trunk publication can race to create the shared
		// release; the loser reads the winner's release.
		const created = await api.getRelease();
		if ( ! created ) {
			throw error;
		}
		return created;
	}
}

/**
 * @param {Record<string, any>} api
 * @param {number} pullRequestNumber
 * @param {string} body
 */
async function upsertComment( api, pullRequestNumber, body ) {
	const comment = await api.findPreviewComment( pullRequestNumber );
	if ( comment ) {
		try {
			await api.updateComment( comment.id, body );
			return;
		} catch ( error ) {
			// The comment was deleted between the two calls. Post a
			// replacement rather than report a publication that worked as a
			// failure.
			if ( /** @type {any} */ ( error )?.status !== 404 ) {
				throw error;
			}
		}
	}
	await api.createComment( pullRequestNumber, body );
}

/**
 * The label is the request to build, and this run has answered it. A push while
 * this run was publishing carried the label with it, so it queued a build of
 * its own, and that build is allowed to publish only while the label is still
 * there. The label therefore goes only while the head is still the commit this
 * run answered.
 *
 * @param {Record<string, any>} api
 * @param {Record<string, any>} context
 */
async function releaseRequestLabel( api, context ) {
	const pullRequest = await api.getPullRequest( context.pullRequestNumber );
	if ( pullRequest.head.sha === context.sourceSha ) {
		await api.removeLabel( context.pullRequestNumber );
	}
}

/**
 * @param {Record<string, any>} api
 * @param {Record<string, any>} context
 * @param {Record<string, any>} release
 * @param {Record<string, any>} published
 * @param {Set<string>} keep
 */
async function announceOnPullRequest( api, context, release, published, keep ) {
	// The comment first, so that no window has it naming an asset that is
	// already deleted, or the label gone with nothing announced.
	await upsertComment(
		api,
		context.pullRequestNumber,
		readyComment( published, context.runUrl )
	);
	// The preview is live once the comment names it. Deleting the superseded
	// assets and giving back the label are housekeeping, and a failure in
	// either must not replace that announcement with a failure report.
	try {
		const prefix = assetPrefix( context.pullRequestNumber );
		for ( const asset of await api.listReleaseAssets( release.id ) ) {
			if ( asset.name.startsWith( prefix ) && ! keep.has( asset.name ) ) {
				await api.deleteReleaseAsset( asset.id );
			}
		}
		await releaseRequestLabel( api, context );
	} catch ( error ) {
		process.stderr.write(
			`::warning::The preview is published, but tidying up after it failed: ${
				error instanceof Error ? error.message : String( error )
			}\n`
		);
	}
}

/**
 * Points the stable Blueprint at the new snapshot by committing it to the
 * pointer branch. The repository README links the raw URL of that file.
 *
 * @param {Record<string, any>} api
 * @param {Record<string, any>} context
 * @param {Record<string, any>} release
 * @param {Record<string, any>} published
 */
async function announceOnTrunk( api, context, release, published ) {
	const blueprint = createTrunkBlueprint( published );
	const previous = await api.getGitReference( TRUNK_POINTER_REF );
	const tree = await api.createGitTree(
		TRUNK_POINTER_ASSET,
		`${ JSON.stringify( blueprint, null, 2 ) }\n`
	);
	const commit = await api.createGitCommit(
		`Code Reference preview for ${ context.sourceSha }`,
		tree.sha,
		previous?.object?.sha || null
	);
	await api.setGitReference(
		TRUNK_POINTER_REF,
		commit.sha,
		Boolean( previous )
	);
	await pruneTrunkGenerations( api, release );
	return commit.sha;
}

/**
 * @param {Record<string, any>} api
 * @param {Record<string, any>} release
 */
async function pruneTrunkGenerations( api, release ) {
	/** @type {Map<string, {runId: number, runAttempt: number, assets: any[]}>} */
	const generations = new Map();
	for ( const asset of await api.listReleaseAssets( release.id ) ) {
		const match = asset.name.match( TRUNK_GENERATION );
		if ( ! match ) {
			continue;
		}
		const generation = generations.get( match[ 1 ] ) || {
			runId: Number( match[ 2 ] ),
			runAttempt: Number( match[ 3 ] ),
			assets: [],
		};
		generation.assets.push( asset );
		generations.set( match[ 1 ], generation );
	}
	// A warm CDN copy of the stable Blueprint can name the previous snapshot for
	// minutes after the pointer moves, so the two newest generations survive.
	const disposable = [ ...generations.values() ]
		.sort(
			( left, right ) =>
				right.runId - left.runId || right.runAttempt - left.runAttempt
		)
		.slice( 2 );
	for ( const generation of disposable ) {
		for ( const asset of generation.assets ) {
			await api.deleteReleaseAsset( asset.id );
		}
	}
}

/**
 * @param {Record<string, any>} api
 * @param {Record<string, any>} context
 * @param {Record<string, any>} handoff
 * @param {string} handoffDirectory
 */
async function publishCandidate( api, context, handoff, handoffDirectory ) {
	// The published name comes from this workflow run rather than from the
	// build, so one build cannot write over another pull request's assets.
	assertPublishableHandoff( handoff );
	const filename = snapshotAssetName( context );
	const bytes = await readFile(
		path.join( handoffDirectory, HANDOFF_SNAPSHOT )
	);
	assertSnapshotFits( bytes.byteLength );
	const release = await ensureRelease( api );
	const snapshotAsset = await api.uploadReleaseAsset(
		release.id,
		filename,
		bytes,
		'application/zip'
	);
	const published = createPublishedMetadata( handoff, context, {
		filename,
		bytes: bytes.byteLength,
		sha256: createHash( 'sha256' ).update( bytes ).digest( 'hex' ),
	} );
	const metadataAsset = await api.uploadReleaseAsset(
		release.id,
		metadataAssetName( filename ),
		Buffer.from( `${ JSON.stringify( published, null, 2 ) }\n` ),
		'application/json'
	);
	if ( context.pullRequestNumber === null ) {
		const pointerCommit = await announceOnTrunk(
			api,
			context,
			release,
			published
		);
		return { status: 'ready', published, pointerCommit };
	}
	await announceOnPullRequest(
		api,
		context,
		release,
		published,
		new Set( [ snapshotAsset.name, metadataAsset.name ] )
	);
	return { status: 'ready', published };
}

/**
 * The build skipped because this commit already has a published preview, so
 * only the comment has to catch up. The preview is looked up from the pull
 * request and commit of this run, so a handoff cannot name another one.
 *
 * @param {Record<string, any>} api
 * @param {Record<string, any>} context
 */
async function republishExisting( api, context ) {
	const release = await api.getRelease();
	const assets = release ? await api.listReleaseAssets( release.id ) : [];
	const snapshot = findLatestSnapshot(
		assets,
		assetPrefix( context.pullRequestNumber, context.sourceSha )
	);
	if ( ! snapshot ) {
		throw new Error( 'The reused preview is no longer published.' );
	}
	// findLatestSnapshot only returns a snapshot whose metadata asset exists.
	const metadataAsset = assets.find(
		( /** @type {Record<string, any>} */ asset ) =>
			asset.name === metadataAssetName( snapshot.name )
	);
	const published = await api.readAssetJson( metadataAsset );
	await announceOnPullRequest(
		api,
		context,
		release,
		published,
		new Set( [ snapshot.name, metadataAsset.name ] )
	);
	return { status: 'reused', published };
}

/**
 * Best effort: the caller rethrows the failure that brought it here, so a
 * problem reporting the failure must not replace it.
 *
 * @param {Record<string, any>} api
 * @param {Record<string, any>} context
 */
async function reportFailure( api, context ) {
	if ( context.pullRequestNumber === null ) {
		return;
	}
	try {
		const previous = await findLatestPublishedPreview(
			api,
			context.pullRequestNumber
		);
		await upsertComment(
			api,
			context.pullRequestNumber,
			failedComment( context, previous )
		);
		await releaseRequestLabel( api, context );
	} catch ( error ) {
		process.stderr.write(
			`::warning::Cannot report the failed preview: ${
				error instanceof Error ? error.message : String( error )
			}\n`
		);
	}
}

/**
 * @param {Record<string, any>} options
 * @returns {Promise<Record<string, any>>}
 */
export async function publishPreview( options ) {
	assertDeploymentEnabled( options.repository, options.stagingVariable );
	const api =
		options.api || new GitHubApi( options.repository, options.token );
	let context;
	try {
		context = await resolveContext( api, options );
	} catch ( error ) {
		if ( error instanceof Superseded ) {
			process.stdout.write( `${ error.message }\n` );
			return { status: 'superseded' };
		}
		throw error;
	}
	try {
		if ( options.artifactAvailable === false ) {
			throw new Error( 'The publisher handoff artifact is unavailable.' );
		}
		const handoff = JSON.parse(
			await readFile(
				path.join( options.handoffDirectory, HANDOFF_METADATA ),
				'utf8'
			)
		);
		if ( handoff.buildStatus === 'failed' ) {
			throw new Error(
				handoff.buildError || 'The preview build failed.'
			);
		}
		if ( handoff.validationStatus === 'failed' ) {
			await reportFailure( api, context );
			return { status: 'invalid' };
		}
		if ( handoff.handoffType === HANDOFF_REUSE ) {
			return await republishExisting( api, context );
		}
		return await publishCandidate(
			api,
			context,
			handoff,
			options.handoffDirectory
		);
	} catch ( error ) {
		await reportFailure( api, context );
		throw error;
	}
}

/**
 * Fork pull request jobs cannot read the base repository's Actions variables,
 * so the trusted publisher applies enforcement after it has reported the
 * invalid candidate and preserved the live link.
 *
 * @param {Record<string, any>} result
 * @param {string | undefined} enforcementVariable
 */
export function enforceValidation( result, enforcementVariable ) {
	if ( result.status !== 'invalid' ) {
		return result;
	}
	if ( enforcementVariable === 'true' ) {
		throw new Error(
			'Code Reference validation failed with DOCS_PREVIEW_ENFORCE enabled.'
		);
	}
	process.stdout.write(
		'::warning::Code Reference validation failed; the published preview still points at the previous build.\n'
	);
	return result;
}

async function main() {
	const [ mode, handoffDirectory = 'handoff' ] = process.argv.slice( 2 );
	if ( mode !== 'trunk' && mode !== 'pull-request' ) {
		throw new Error( 'The first argument must be trunk or pull-request.' );
	}
	const event = await readWorkflowEvent();
	const result = enforceValidation(
		await publishPreview( {
			repository: process.env.GITHUB_REPOSITORY,
			stagingVariable: process.env.DOCS_PREVIEW_STAGING,
			trunk: mode === 'trunk',
			triggerRunId: event.workflow_run.id,
			triggerRunAttempt: event.workflow_run.run_attempt,
			handoffDirectory: path.resolve( handoffDirectory ),
			artifactAvailable:
				process.env.HANDOFF_DOWNLOAD_RESULT === 'success',
			token: process.env.GITHUB_TOKEN,
		} ),
		process.env.DOCS_PREVIEW_ENFORCE
	);
	process.stdout.write( `Code Reference publisher: ${ result.status }.\n` );
}

if ( import.meta.url === pathToFileURL( process.argv[ 1 ] ).href ) {
	main().catch( ( error ) => {
		process.stderr.write( `${ error.stack || error }\n` );
		process.exitCode = 1;
	} );
}
