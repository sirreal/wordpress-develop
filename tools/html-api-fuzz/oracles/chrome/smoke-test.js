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

		const unsafeDocument = '<!doctype html><title>x</title>' +
			`<script>document.body.innerHTML='<p>EXECUTED</p>'</script><img src="http://127.0.0.1:${ probePort }/probe"><p>safe`;
		const fullDocument = await request( socketPath, {
			id: 2,
			command: 'render',
			htmlBase64: Buffer.from( unsafeDocument ).toString( 'base64' ),
			mode: 'full-document',
			context: 'body',
			maxNodes: 100,
		} );
		assert( 'ok' === fullDocument.status, fullDocument.error || 'Full-document render failed.' );
		assert( fullDocument.tree.startsWith( '<!DOCTYPE html>\n<html>\n' ), 'Full-document navigation did not expose document.childNodes.' );
		assert( fullDocument.tree.includes( '"safe"' ), 'Author script mutated the parsed full document.' );
		assert( ! fullDocument.tree.includes( '<p>\n      "EXECUTED"' ), 'Author script executed during full-document parsing.' );

		const [ svg, table ] = await Promise.all( [
			request( socketPath, {
				id: 3,
				command: 'render',
				htmlBase64: Buffer.from( '<circle viewbox="0 0 1 1"/>' ).toString( 'base64' ),
				mode: 'fragment-body',
				context: 'svg',
				maxNodes: 100,
			} ),
			request( socketPath, {
				id: 4,
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
		await new Promise( ( resolve ) => setTimeout( resolve, 100 ) );
		assert( 0 === networkRequests, 'Full-document parsing made an external network request.' );

		const exiting = waitForExit( child );
		const shutdown = await request( socketPath, { id: 5, command: 'shutdown' } );
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
