import {
	copyFile,
	mkdir,
	readdir,
	readFile,
	rm,
	writeFile,
} from 'node:fs/promises';
import { existsSync } from 'node:fs';
import path from 'node:path';

import { run } from './process.mjs';

// Where the importer reads source from inside the Playground filesystem.
const IMPORT_SOURCE_ROOT = '/tmp/docs-preview-source';
const SAFE_VERSION_ROOT = '/tmp/docs-preview-version';

// Bundled plugins and themes are not part of the Core Code Reference.
const EXCLUDED_SOURCE = [ 'wp-content/plugins', 'wp-content/themes' ];

/**
 * Copies the Core PHP the parser reads into its own tree. Only these files are
 * ever exposed to the parser and the importer, and none of them is executed.
 *
 * @param {string} candidate
 * @param {string} destination
 */
export async function stageCorePhp( candidate, destination ) {
	const root = path.resolve( candidate );
	const source = existsSync(
		path.join( root, 'src/wp-includes/version.php' )
	)
		? path.join( root, 'src' )
		: root;
	if ( ! existsSync( path.join( source, 'wp-includes/version.php' ) ) ) {
		throw new Error( `No WordPress source tree found beneath ${ root }.` );
	}
	await rm( destination, { recursive: true, force: true } );
	// Symbolic links are not files here, so none of them is ever staged.
	const entries = await readdir( source, {
		recursive: true,
		withFileTypes: true,
	} );
	let count = 0;
	for ( const entry of entries ) {
		const relative = path.relative(
			source,
			path.join( entry.parentPath, entry.name )
		);
		if (
			! entry.isFile() ||
			! relative.endsWith( '.php' ) ||
			EXCLUDED_SOURCE.some( ( excluded ) =>
				relative.startsWith( `${ excluded }/` )
			)
		) {
			continue;
		}
		const target = path.join( destination, relative );
		await mkdir( path.dirname( target ), { recursive: true } );
		await copyFile( path.join( source, relative ), target );
		count++;
	}
	return count;
}

/**
 * Counts what the parser produced and points every record at the source tree
 * the importer will read it from.
 *
 * @param {any[]} records
 * @param {Record<string, number>} minimumSymbols
 */
export function inspectParserRecords( records, minimumSymbols ) {
	/** @type {Record<string, number>} */
	const counts = {
		classes: 0,
		methods: 0,
		functions: 0,
		hooks: 0,
		filters: 0,
	};
	/** @param {any[]} [hooks] */
	const countHooks = ( hooks ) => {
		for ( const hook of hooks || [] ) {
			if ( String( hook.type ).startsWith( 'action' ) ) {
				counts.hooks++;
			} else {
				counts.filters++;
			}
		}
	};
	for ( const record of records ) {
		const functions = record.functions || [];
		const classes = record.classes || [];
		counts.functions += functions.length;
		counts.classes += classes.length;
		countHooks( record.hooks );
		for ( const item of functions ) {
			countHooks( item.hooks );
		}
		for ( const item of classes ) {
			const methods = item.methods || [];
			counts.methods += methods.length;
			for ( const method of methods ) {
				countHooks( method.hooks );
			}
		}
		// The importer includes the file it records, so the version file has to
		// resolve to the empty stub instead of the pull-request source.
		record.root =
			record.path === 'wp-includes/version.php'
				? SAFE_VERSION_ROOT
				: IMPORT_SOURCE_ROOT;
	}
	const failures = Object.entries( minimumSymbols )
		.filter( ( [ type, minimum ] ) => counts[ type ] < minimum )
		.map(
			( [ type, minimum ] ) =>
				`Parser produced ${ counts[ type ] } ${ type }; expected at least ${ minimum }.`
		);
	return { counts, failures };
}

/**
 * Parses the pull-request Core source into the JSON the importer consumes.
 *
 * @param {{source: string, stagedSource: string, parser: string, output: string, minimumSymbols: Record<string, number>}} options
 */
export async function generateParserJson( options ) {
	const files = await stageCorePhp( options.source, options.stagedSource );
	process.stdout.write( `Staged ${ files } Core PHP files for parsing.\n` );
	await mkdir( path.dirname( options.output ), { recursive: true } );
	await run(
		'php',
		[
			'-d',
			'memory_limit=4G',
			path.join( options.parser, 'generate-json-manually.php' ),
			'-d',
			options.stagedSource,
			'-o',
			options.output,
		],
		{ label: 'generate Code Reference parser JSON' }
	);
	const records = JSON.parse( await readFile( options.output, 'utf8' ) );
	const { counts, failures } = inspectParserRecords(
		records,
		options.minimumSymbols
	);
	await writeFile( options.output, `${ JSON.stringify( records ) }\n` );
	process.stdout.write(
		`Parsed ${ Object.entries( counts )
			.map( ( [ type, total ] ) => `${ total } ${ type }` )
			.join( ', ' ) }.\n`
	);
	return { failures };
}
