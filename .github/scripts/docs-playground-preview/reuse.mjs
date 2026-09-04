#!/usr/bin/env node

// Runs in the build job. When the commit under build already has a published
// preview, it writes a handoff that tells the publisher to point the comment at
// the existing assets, and the build workflow skips the rest of the build.
//
// Every failure here is reported as "not reusable" so that a problem reading the
// release only costs a rebuild.

import { mkdir, writeFile } from 'node:fs/promises';
import { pathToFileURL } from 'node:url';
import path from 'node:path';

import { GitHubApi, readWorkflowEvent } from './lib/github.mjs';
import {
	HANDOFF_METADATA,
	HANDOFF_REUSE,
	assetPrefix,
	findLatestSnapshot,
} from './lib/preview.mjs';

/**
 * Hands the newest published snapshot for this pull request and commit to the
 * publisher and reports the reuse to the build workflow, which then skips the
 * build. Returns the reused asset name, or null when there is nothing to reuse.
 *
 * @param {Record<string, any>} api
 * @param {{number: number, head: {sha: string}}} pullRequest
 * @param {string} handoffDirectory
 * @returns {Promise<string | null>}
 */
export async function reusePublishedSnapshot(
	api,
	pullRequest,
	handoffDirectory
) {
	const release = await api.getRelease();
	const assets = release ? await api.listReleaseAssets( release.id ) : [];
	const snapshot = findLatestSnapshot(
		assets,
		assetPrefix( pullRequest.number, pullRequest.head.sha )
	);
	if ( ! snapshot ) {
		return null;
	}
	await mkdir( handoffDirectory, { recursive: true } );
	await writeFile(
		path.join( handoffDirectory, HANDOFF_METADATA ),
		`${ JSON.stringify( { handoffType: HANDOFF_REUSE }, null, 2 ) }\n`
	);
	// The build workflow skips the build when this is set. A run that reused
	// nothing, or failed before here, sets nothing and the build goes ahead.
	if ( process.env.GITHUB_OUTPUT ) {
		await writeFile( process.env.GITHUB_OUTPUT, 'reused=true\n', {
			flag: 'a',
		} );
	}
	return snapshot.name;
}

async function main() {
	const [ handoffDirectory = 'handoff' ] = process.argv.slice( 2 );
	const { pull_request: pullRequest } = await readWorkflowEvent();
	const assetName = await reusePublishedSnapshot(
		new GitHubApi(
			process.env.GITHUB_REPOSITORY,
			process.env.GITHUB_TOKEN
		),
		pullRequest,
		handoffDirectory
	);
	if ( assetName ) {
		process.stdout.write(
			`Reusing the published snapshot ${ assetName }.\n`
		);
	}
}

if ( import.meta.url === pathToFileURL( process.argv[ 1 ] ).href ) {
	main().catch( ( error ) => {
		process.stderr.write(
			`::warning::Same-SHA reuse check failed: ${ error.message }\n`
		);
	} );
}
