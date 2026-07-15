#!/usr/bin/env node
'use strict';

const fs = require( 'node:fs' );
const http = require( 'node:http' );
const net = require( 'node:net' );
const os = require( 'node:os' );
const path = require( 'node:path' );
const { spawn, spawnSync } = require( 'node:child_process' );

const SCRIPT = path.join( __dirname, 'chrome-tree-oracle.js' );

function assert( condition, message ) {
	if ( ! condition ) {
		throw new Error( message );
	}
}

function request( socketPath, payload ) {
	return new Promise( ( resolve, reject ) => {
		const socket = net.createConnection( socketPath );
		let response = '';
		const timer = setTimeout( () => {
			socket.destroy();
			reject( new Error( 'Timed out waiting for the Chrome oracle.' ) );
		}, 20000 );
		socket.once( 'error', ( error ) => {
			clearTimeout( timer );
			reject( error );
		} );
		socket.on( 'data', ( data ) => {
			response += data.toString( 'utf8' );
			const newline = response.indexOf( '\n' );
			if ( -1 === newline ) {
				return;
			}
			clearTimeout( timer );
			socket.end();
			try {
				resolve( JSON.parse( response.slice( 0, newline ) ) );
			} catch ( error ) {
				reject( error );
			}
		} );
		socket.once( 'connect', () => socket.write( `${ JSON.stringify( payload ) }\n` ) );
	} );
}

function waitForReady( child ) {
	return new Promise( ( resolve, reject ) => {
		let stdout = '';
		let stderr = '';
		const timer = setTimeout( () => reject( new Error( `Oracle daemon did not become ready. ${ stderr }` ) ), 10000 );
		child.stdout.on( 'data', ( data ) => {
			stdout += data.toString( 'utf8' );
			const newline = stdout.indexOf( '\n' );
			if ( -1 !== newline ) {
				clearTimeout( timer );
				resolve( JSON.parse( stdout.slice( 0, newline ) ) );
			}
		} );
		child.stderr.on( 'data', ( data ) => {
			stderr += data.toString( 'utf8' );
		} );
		child.once( 'exit', ( code, signal ) => {
			clearTimeout( timer );
			reject( new Error( `Oracle daemon exited before ready (code ${ code }, signal ${ signal }). ${ stderr }` ) );
		} );
	} );
}

function waitForExit( child ) {
	return new Promise( ( resolve, reject ) => {
		const timer = setTimeout( () => {
			child.kill( 'SIGTERM' );
			reject( new Error( 'Oracle daemon did not shut down.' ) );
		}, 10000 );
		child.once( 'exit', ( code, signal ) => {
			clearTimeout( timer );
			if ( 0 === code || 'SIGTERM' === signal ) {
				resolve();
			} else {
				reject( new Error( `Oracle daemon exited with code ${ code } and signal ${ signal }.` ) );
			}
		} );
	} );
}

async function main() {
	const version = spawnSync( process.execPath, [ SCRIPT, '--version' ], { encoding: 'utf8' } );
	const versionResult = JSON.parse( version.stdout.trim() );
	if ( false === versionResult.oracle?.available ) {
		process.stdout.write( `SKIP chrome-cdp-smoke: ${ versionResult.oracle.error }\n` );
		return;
	}

	let networkRequests = 0;
	const probeServer = http.createServer( ( _request, response ) => {
		networkRequests++;
		response.writeHead( 204 );
		response.end();
	} );
	await new Promise( ( resolve ) => probeServer.listen( 0, '127.0.0.1', resolve ) );
	const probePort = probeServer.address().port;
	const temporaryDirectory = fs.mkdtempSync( path.join( os.tmpdir(), 'html-api-fuzz-chrome-smoke-' ) );
	const socketPath = path.join( temporaryDirectory, 'oracle.sock' );
	const child = spawn( process.execPath, [ SCRIPT, '--serve', '--socket', socketPath ], {
		stdio: [ 'ignore', 'pipe', 'pipe' ],
	} );

	try {
		const ready = await waitForReady( child );
		assert( 'ready' === ready.status, 'Expected the socket daemon readiness record.' );
		const fragment = await request( socketPath, {
			id: 1,
			command: 'render',
			htmlBase64: Buffer.from( '<p a=1>x<!--c-->' ).toString( 'base64' ),
			mode: 'fragment-body',
			context: 'body',
			maxNodes: 100,
		} );
		assert( 'ok' === fragment.status, fragment.error || 'Body fragment render failed.' );
		assert( '<p>\n  a="1"\n  "x"\n  <!-- c -->\n\n' === fragment.tree, 'Unexpected canonical body fragment tree.' );
		assert( Buffer.from( fragment.treeBase64, 'base64' ).toString( 'utf8' ) === fragment.tree, 'treeBase64 does not encode tree.' );
		assert( 3 === fragment.nodeCount, 'Unexpected body fragment node count.' );

		const unsafeDocument = '<!doctype html><html><head><title>x</title></head>' +
			'<body onload="this.dataset.onloadRan=\'yes\';this.insertAdjacentHTML(\'beforeend\',\'<i id=author-executed></i>\')">' +
			'<script>document.body.innerHTML=\'<p>EXECUTED</p>\'</script>' +
			`<img src="http://127.0.0.1:${ probePort }/probe" onerror="document.body.dataset.errorRan='yes'">` +
			'<p>safe</p></body></html>';
		const fullDocument = await request( socketPath, {
			id: 2,
			command: 'render',
			htmlBase64: Buffer.from( unsafeDocument ).toString( 'base64' ),
			mode: 'full-document',
			context: 'body',
			maxNodes: 100,
			securityAuditDelayMs: 100,
		} );
		assert( 'ok' === fullDocument.status, fullDocument.error || 'Full-document render failed.' );
		assert( fullDocument.tree.startsWith( '<!DOCTYPE html>\n<html>\n' ), 'Full-document navigation did not expose document.childNodes.' );
		assert( fullDocument.tree.includes( '"safe"' ), 'Author script mutated the parsed full document.' );
		assert( ! fullDocument.tree.includes( '<p>\n      "EXECUTED"' ), 'Author script executed during full-document parsing.' );
		assert( ! fullDocument.tree.includes( 'data-onload-ran=' ), 'Author load handler executed after parsing.' );
		assert( ! fullDocument.tree.includes( 'data-error-ran=' ), 'Author error handler executed after parsing.' );
		assert( 'x' === fullDocument.securityAudit?.title, 'Author code changed the title during the delayed audit.' );
		assert( null === fullDocument.securityAudit?.onloadRan, 'Queued author load handler ran during the instrumentation window.' );
		assert( null === fullDocument.securityAudit?.errorRan, 'Queued author error handler ran during the instrumentation window.' );
		assert( false === fullDocument.securityAudit?.injectedNode, 'Queued author handler injected a node during the instrumentation window.' );

		const noscriptDocument = await request( socketPath, {
			id: 3,
			command: 'render',
			htmlBase64: Buffer.from( '<!doctype html><html><head><noscript><meta name=x></noscript></head><body><noscript><b>y</b></noscript></body></html>' ).toString( 'base64' ),
			mode: 'full-document',
			context: 'body',
			maxNodes: 100,
		} );
		assert( 'ok' === noscriptDocument.status, noscriptDocument.error || 'Noscript document render failed.' );
		assert(
			noscriptDocument.tree.includes( '    <noscript>\n      <meta>\n        name="x"\n  <body>\n    <noscript>\n      <b>\n        "y"\n' ),
			'Chrome document parser did not use scripting-disabled tree construction.'
		);

		const noscriptFragment = await request( socketPath, {
			id: 4,
			command: 'render',
			htmlBase64: Buffer.from( '<noscript><b>x</b></noscript>' ).toString( 'base64' ),
			mode: 'fragment-body',
			context: 'body',
			maxNodes: 100,
		} );
		assert( '<noscript>\n  <b>\n    "x"\n\n' === noscriptFragment.tree, 'Chrome fragment parser did not use an inert scripting-disabled document.' );

		const [ svg, table ] = await Promise.all( [
			request( socketPath, {
				id: 5,
				command: 'render',
				htmlBase64: Buffer.from( '<circle viewbox="0 0 1 1"/>' ).toString( 'base64' ),
				mode: 'fragment-body',
				context: 'svg',
				maxNodes: 100,
			} ),
			request( socketPath, {
				id: 6,
				command: 'render',
				htmlBase64: Buffer.from( '<td>x<td>y' ).toString( 'base64' ),
				mode: 'fragment-body',
				context: 'tr',
				maxNodes: 100,
			} ),
		] );
		assert( '<svg circle>\n  viewBox="0 0 1 1"\n\n' === svg.tree, 'SVG contextual fragment namespace or attribute adjustment is wrong.' );
		assert( '<td>\n  "x"\n<td>\n  "y"\n\n' === table.tree, 'Table contextual fragment parsing is wrong.' );
		assert( fragment.oracle.browserPid === fullDocument.oracle.browserPid, 'Chrome was not reused between sequential clients.' );
		assert( fragment.oracle.browserPid === svg.oracle.browserPid, 'Chrome was not reused between concurrent clients.' );
		assert( fragment.oracle.browserInstanceId === table.oracle.browserInstanceId, 'Browser instance changed during socket serving.' );

		const contextFixtures = [
			[ 'body', '<p>x', '<p>\n  "x"\n\n' ],
			[ 'div', '<p>x', '<p>\n  "x"\n\n' ],
			[ 'p', '<b>x', '<b>\n  "x"\n\n' ],
			[ 'td', '<b>x', '<b>\n  "x"\n\n' ],
			[ 'tr', '<td>x', '<td>\n  "x"\n\n' ],
			[ 'table', '<td>x', '<tbody>\n  <tr>\n    <td>\n      "x"\n\n' ],
			[ 'caption', '<b>x', '<b>\n  "x"\n\n' ],
			[ 'colgroup', '<col>', '<col>\n\n' ],
			[ 'select', '<option>x<option>y', '<option>\n  "x"\n<option>\n  "y"\n\n' ],
			[ 'option', 'x', '"x"\n\n' ],
			[ 'template', '<b>x', '<b>\n  "x"\n\n' ],
			[ 'title', '<b>&amp;', '"<b>&"\n\n' ],
			[ 'textarea', '<b>&amp;', '"<b>&"\n\n' ],
			[ 'script', '&amp;<b>', '"&amp;<b>"\n\n' ],
			[ 'style', '&amp;<b>', '"&amp;<b>"\n\n' ],
			[ 'svg', '<circle>', '<svg circle>\n\n' ],
			[ 'math', '<mi>x', '<math mi>\n  "x"\n\n' ],
		];
		const contextResults = await Promise.all( contextFixtures.map( ( [ context, html ], index ) => request( socketPath, {
			id: 10 + index,
			command: 'render',
			htmlBase64: Buffer.from( html ).toString( 'base64' ),
			mode: 'fragment-body',
			context,
			maxNodes: 100,
		} ) ) );
		for ( let index = 0; index < contextFixtures.length; index++ ) {
			const [ context, , expected ] = contextFixtures[ index ];
			const result = contextResults[ index ];
			assert( 'ok' === result.status, result.error || `${ context } context failed.` );
			assert( expected === result.tree, `${ context } contextual fragment tree is wrong: ${ JSON.stringify( result.tree ) }` );
		}
		await new Promise( ( resolve ) => setTimeout( resolve, 100 ) );
		assert( 0 === networkRequests, 'Full-document parsing made an external network request.' );

		const exiting = waitForExit( child );
		const shutdown = await request( socketPath, { id: 100, command: 'shutdown' } );
		assert( 'ok' === shutdown.status && true === shutdown.shutdown, 'Shutdown request failed.' );
		await exiting;
		assert( ! fs.existsSync( socketPath ), 'Oracle socket was not removed during shutdown.' );
	} finally {
		if ( null === child.exitCode && ! child.killed ) {
			child.kill( 'SIGTERM' );
		}
		await new Promise( ( resolve ) => probeServer.close( resolve ) );
		fs.rmSync( temporaryDirectory, { recursive: true, force: true } );
	}

	process.stdout.write( 'OK chrome-cdp-smoke\n' );
}

main().catch( ( error ) => {
	process.stderr.write( `FAIL chrome-cdp-smoke: ${ error.stack || error.message }\n` );
	process.exitCode = 1;
} );
