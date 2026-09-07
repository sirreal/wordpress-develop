#!/usr/bin/env node

import { mkdir, writeFile } from 'node:fs/promises';
import { fileURLToPath } from 'node:url';
import { parseArgs } from 'node:util';
import path from 'node:path';

import { ensureInvariantBase } from './lib/base.mjs';
import {
	readResolvedInputs,
	resolveBuildInputs,
	writeResolvedInputs,
} from './lib/inputs.mjs';
import { generateParserJson } from './lib/parser.mjs';
import { HANDOFF_METADATA, HANDOFF_SNAPSHOT } from './lib/preview.mjs';
import { packageSnapshot } from './lib/snapshot.mjs';
import { run } from './lib/process.mjs';
import { validateSnapshot } from './lib/validate.mjs';

const SCRIPT_ROOT = path.dirname( fileURLToPath( import.meta.url ) );
const REPOSITORY_ROOT = path.resolve( SCRIPT_ROOT, '../../..' );
const TOOLING_ROOT = path.join(
	REPOSITORY_ROOT,
	'.github/docs-playground-preview'
);

const ARGUMENTS = /** @type {const} */ ( {
	'source-repository': { type: 'string' },
	'source-sha': { type: 'string' },
	'run-url': { type: 'string' },
	'runner-image': { type: 'string' },
	'cache-root': { type: 'string' },
	'work-root': { type: 'string' },
	handoff: { type: 'string' },
	'inputs-output': { type: 'string' },
	'resolved-inputs': { type: 'string' },
	'resolve-only': { type: 'boolean' },
} );

/**
 * A local run has no workflow inputs, so it describes the checkout it runs in.
 */
async function gitIdentity() {
	const options = { cwd: REPOSITORY_ROOT, capture: true };
	const sha = await run( 'git', [ 'rev-parse', 'HEAD' ], options );
	const remote = await run(
		'git',
		[ 'config', '--get', 'remote.origin.url' ],
		options
	);
	return {
		sha: sha.stdout.trim(),
		repository: remote.stdout
			.trim()
			.replace( /^(?:git@github\.com:|https:\/\/github\.com\/)/, '' )
			.replace( /\.git$/, '' ),
	};
}

/**
 * @param {string[]} argv
 */
async function resolveOptions( argv ) {
	const { values } = parseArgs( { args: argv, options: ARGUMENTS } );
	const workspace = path.join(
		REPOSITORY_ROOT,
		'.cache/docs-playground-preview'
	);
	// Only the snapshot's provenance needs the source identity, so resolving the
	// cache identity never asks git for it.
	const resolveOnly = values[ 'resolve-only' ] === true;
	const inferred =
		resolveOnly ||
		( values[ 'source-sha' ] && values[ 'source-repository' ] )
			? null
			: await gitIdentity();
	const workRoot = path.resolve(
		values[ 'work-root' ] || path.join( workspace, 'work' )
	);
	// The build writes both handoff files; the publish job downloads the whole
	// directory and reads them by their fixed names.
	const handoff = path.resolve(
		values.handoff || path.join( workspace, 'output' )
	);
	return {
		resolveOnly,
		resolvedInputs: values[ 'resolved-inputs' ],
		enforce: process.env.DOCS_PREVIEW_ENFORCE === 'true',
		sourceSha: values[ 'source-sha' ] || inferred?.sha,
		sourceRepository: values[ 'source-repository' ] || inferred?.repository,
		runUrl: values[ 'run-url' ] || null,
		runnerImage:
			values[ 'runner-image' ] ||
			`${ process.platform }-${ process.arch }`,
		cacheRoot: path.resolve(
			values[ 'cache-root' ] || path.join( workspace, 'cache' )
		),
		workRoot,
		snapshot: path.join( handoff, HANDOFF_SNAPSHOT ),
		metadata: path.join( handoff, HANDOFF_METADATA ),
		inputsOutput: path.resolve(
			values[ 'inputs-output' ] ||
				path.join( workRoot, 'resolved-inputs.json' )
		),
	};
}

/**
 * The handoff contract with publish.mjs: `buildStatus` and `validationStatus`
 * decide what the publish job does, and the rest describes the snapshot it
 * publishes. The publish job takes identity from its own workflow run, not from
 * here, so this file names no repository, commit or pull request.
 *
 * @param {Record<string, any> | undefined} inputs
 * @param {Record<string, any>} result
 * @returns {Record<string, any>}
 */
function handoffMetadata( inputs, result ) {
	return {
		resolvedWordPressBeta: inputs?.wordpress || null,
		phpVersion: inputs?.dependencies.playground.phpVersion || null,
		dependencyManifestDigest: inputs?.dependencyDigest || null,
		...result,
	};
}

/**
 * @param {string} filename
 * @param {unknown} value
 */
async function writeJson( filename, value ) {
	await mkdir( path.dirname( filename ), { recursive: true } );
	await writeFile( filename, `${ JSON.stringify( value, null, 2 ) }\n` );
}

/**
 * @param {Record<string, any>} options
 */
async function build( options ) {
	let inputs;
	try {
		inputs = options.resolvedInputs
			? await readResolvedInputs( options.resolvedInputs )
			: await resolveBuildInputs( {
					repositoryRoot: REPOSITORY_ROOT,
					cacheRoot: options.cacheRoot,
					platform: process.platform,
					architecture: process.arch,
					runnerImage: options.runnerImage,
			  } );
		if ( options.resolveOnly ) {
			await writeResolvedInputs( inputs, options.inputsOutput );
			return { inputs, metadata: null };
		}

		const provenance = {
			sourceRepository: options.sourceRepository,
			sourceSha: options.sourceSha,
			generationTimestamp: new Date().toISOString(),
			runUrl: options.runUrl,
		};
		const playgroundCli = path.join(
			TOOLING_ROOT,
			'node_modules/.bin/wp-playground-cli'
		);
		await run(
			'npm',
			[ 'ci', '--ignore-scripts', '--no-audit', '--no-fund' ],
			{ cwd: TOOLING_ROOT, label: 'install pinned preview tools' }
		);
		await ensureInvariantBase( inputs, playgroundCli );
		const parser = await generateParserJson( {
			source: REPOSITORY_ROOT,
			stagedSource: path.join( options.workRoot, 'source' ),
			parser: path.join( inputs.cacheDirectory, 'parser' ),
			output: path.join( options.workRoot, 'reference.json' ),
			minimumSymbols: inputs.dependencies.validation.minimumSymbols,
		} );
		await packageSnapshot( inputs, {
			workDirectory: path.join( options.workRoot, 'final' ),
			output: options.snapshot,
			referenceJson: path.join( options.workRoot, 'reference.json' ),
			stagedSource: path.join( options.workRoot, 'source' ),
			playgroundCli,
			provenance,
		} );
		const validation = await validateSnapshot( inputs, {
			snapshot: options.snapshot,
			workDirectory: path.join( options.workRoot, 'validation' ),
			playgroundCli,
			provenance,
		} );
		const validationFailures = [
			...parser.failures,
			...validation.failures,
		];
		return {
			inputs,
			metadata: handoffMetadata( inputs, {
				buildStatus: 'success',
				validationStatus:
					validationFailures.length === 0 ? 'passed' : 'failed',
				validationFailures,
				generationTimestamp: provenance.generationTimestamp,
			} ),
		};
	} catch ( error ) {
		// The publish job reports the failure, so it needs a handoff even here.
		await writeJson(
			options.metadata,
			handoffMetadata( inputs, {
				buildStatus: 'failed',
				buildError:
					error instanceof Error ? error.message : String( error ),
			} )
		);
		throw error;
	}
}

async function main() {
	const options = await resolveOptions( process.argv.slice( 2 ) );
	const { inputs, metadata } = await build( options );
	if ( ! metadata ) {
		process.stdout.write(
			`Resolved WordPress ${ inputs.wordpress.version } and cache key ${ inputs.cacheKey }.\n`
		);
		return;
	}

	await writeJson( options.metadata, metadata );
	for ( const failure of metadata.validationFailures ) {
		process.stderr.write( `::warning::${ failure }\n` );
	}
	process.stdout.write( `${ JSON.stringify( metadata, null, 2 ) }\n` );
	if ( metadata.validationStatus === 'failed' && options.enforce ) {
		throw new Error( metadata.validationFailures.join( '\n' ) );
	}
}

main().catch( ( error ) => {
	process.stderr.write( `${ error.message }\n` );
	process.exitCode = 1;
} );
