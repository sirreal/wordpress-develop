import { copyFile, mkdir, rm, stat, writeFile } from 'node:fs/promises';
import { fileURLToPath, pathToFileURL } from 'node:url';
import path from 'node:path';

import { assertSnapshotFits } from './preview.mjs';

const PHP_ROOT = path.resolve(
	path.dirname( fileURLToPath( import.meta.url ) ),
	'../php'
);

/**
 * Builds a Playground snapshot with the pinned CLI. The caller supplies the
 * Blueprint, the output file and anything to mount; everything else is the same
 * for every snapshot this build produces.
 *
 * @param {Record<string, any>} inputs
 * @param {string} playgroundCli
 * @param {Record<string, any>} options
 */
export async function buildSnapshot( inputs, playgroundCli, options ) {
	const modulePath = path.resolve(
		path.dirname( playgroundCli ),
		'../@wp-playground/cli/index.js'
	);
	const { runCLI } = await import( pathToFileURL( modulePath ).href );
	await runCLI( {
		command: 'build-snapshot',
		php: inputs.dependencies.playground.phpVersion,
		wp: inputs.wordpress.version,
		// Every Blueprint here writes files bundled next to it.
		'blueprint-may-read-adjacent-files': true,
		// Parallel workers write the same snapshot filesystem, so builds use one.
		workers: 1,
		verbosity: 'normal',
		...options,
	} );
}

/**
 * @param {string | null} value
 */
function phpValue( value ) {
	return value === null
		? 'null'
		: `'${ value.replace( /\\/g, '\\\\' ).replace( /'/g, "\\'" ) }'`;
}

/**
 * Renders the provenance php/preview-runtime.php reads for its banner.
 *
 * @param {{sourceRepository: string, sourceSha: string, generationTimestamp: string, runUrl: string | null}} provenance
 */
function renderProvenance( provenance ) {
	return `<?php
/**
 * Provenance of this Code Reference preview, written by the preview build.
 */

return array(
	'repository' => ${ phpValue( provenance.sourceRepository ) },
	'sha'        => ${ phpValue( provenance.sourceSha ) },
	'generated'  => ${ phpValue( provenance.generationTimestamp ) },
	'runUrl'     => ${ phpValue( provenance.runUrl ) },
);
`;
}

/**
 * @param {Record<string, any>} inputs
 */
function finalBlueprint( inputs ) {
	return {
		$schema: inputs.dependencies.playground.blueprintSchema,
		meta: {
			title: 'WordPress Core Code Reference preview',
			author: 'WordPress',
			description:
				'Complete Core Code Reference imported into the cached site.',
		},
		preferredVersions: {
			php: inputs.dependencies.playground.phpVersion,
			wp: inputs.wordpress.version,
		},
		landingPage: '/reference/',
		login: false,
		features: { networking: false },
		extraLibraries: [ 'wp-cli' ],
		steps: [
			{
				step: 'unzip',
				zipFile: { resource: 'bundled', path: 'base.zip' },
				extractToPath: '/',
			},
			{ step: 'mkdir', path: '/tmp/docs-preview-version' },
			{ step: 'mkdir', path: '/tmp/docs-preview-version/wp-includes' },
			{
				step: 'writeFile',
				path: '/tmp/reference.json',
				data: { resource: 'bundled', path: 'reference.json' },
			},
			// The importer includes every file it records. Records for
			// wp-includes/version.php point at this empty stub instead of the
			// pull-request file, which is only ever read as text.
			{
				step: 'writeFile',
				path: '/tmp/docs-preview-version/wp-includes/version.php',
				data: { resource: 'bundled', path: 'safe-version.php' },
			},
			{
				step: 'wp-cli',
				command:
					'wp parser import /tmp/reference.json --quick --user=1',
			},
			{
				step: 'writeFile',
				path: '/tmp/docs-preview-complete-import.php',
				data: { resource: 'bundled', path: 'complete-import.php' },
			},
			{
				step: 'wp-cli',
				command: 'wp eval-file /tmp/docs-preview-complete-import.php',
			},
			{
				step: 'rmdir',
				path: '/wordpress/wp-content/plugins/phpdoc-parser',
			},
			{
				step: 'writeFile',
				path: '/wordpress/wp-content/docs-preview-provenance.php',
				data: { resource: 'bundled', path: 'provenance.php' },
			},
			{
				step: 'writeFile',
				path: '/wordpress/wp-content/mu-plugins/001-docs-preview-runtime.php',
				data: { resource: 'bundled', path: 'preview-runtime.php' },
			},
			{
				step: 'defineWpConfigConsts',
				method: 'rewrite-wp-config',
				consts: {
					DISABLE_WP_CRON: true,
					AUTOMATIC_UPDATER_DISABLED: true,
					WP_AUTO_UPDATE_CORE: false,
					DISALLOW_FILE_MODS: true,
				},
			},
		],
	};
}

/**
 * Imports the parsed Code Reference into the cached base and packages the
 * result as the snapshot the preview publishes.
 *
 * @param {Record<string, any>} inputs
 * @param {{workDirectory: string, output: string, referenceJson: string, stagedSource: string, playgroundCli: string, provenance: any}} options
 */
export async function packageSnapshot( inputs, options ) {
	const work = path.resolve( options.workDirectory );
	const output = path.resolve( options.output );
	await rm( work, { recursive: true, force: true } );
	await mkdir( work, { recursive: true } );
	await mkdir( path.dirname( output ), { recursive: true } );
	await copyFile(
		path.join( inputs.cacheDirectory, 'base.zip' ),
		path.join( work, 'base.zip' )
	);
	await copyFile(
		options.referenceJson,
		path.join( work, 'reference.json' )
	);
	for ( const script of [
		'complete-import.php',
		'preview-runtime.php',
		'safe-version.php',
	] ) {
		await copyFile(
			path.join( PHP_ROOT, script ),
			path.join( work, script )
		);
	}
	await writeFile(
		path.join( work, 'provenance.php' ),
		renderProvenance( options.provenance )
	);
	const blueprint = path.join( work, 'final-blueprint.json' );
	await writeFile(
		blueprint,
		`${ JSON.stringify( finalBlueprint( inputs ), null, 2 ) }\n`
	);
	await buildSnapshot( inputs, options.playgroundCli, {
		blueprint,
		mount: [
			{
				hostPath: path.resolve( options.stagedSource ),
				vfsPath: '/tmp/docs-preview-source',
			},
		],
		outfile: output,
	} );
	const snapshot = await stat( output );
	assertSnapshotFits( snapshot.size );
	process.stdout.write(
		`Packaged ${ path.basename( output ) } at ${ snapshot.size } bytes.\n`
	);
}
